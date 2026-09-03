<?php
// ============================================================
// Server-Sent Events (SSE) - Live Score Broadcaster
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

// Prevent PHP from stopping script when client disconnects (we handle it manually via connection_aborted)
ignore_user_abort(true);

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable buffering in Nginx

$pdo = db();

// To prevent infinite zombie processes on the server, we run the loop for a maximum of 60 seconds.
// The JS client's EventSource will automatically reconnect instantly when this script finishes.
$startTime = time();
$maxExecutionTime = 60; 

// Initial state
$lastEventId = -1;

while (true) {
    // 1. Break if client disconnected or timeout reached
    if (connection_aborted() || (time() - $startTime) > $maxExecutionTime) {
        break;
    }

    try {
        // 2. Ultra-lightweight check: Has the latest score event changed?
        $currentMax = (int)db_retry(function($db) {
            return $db->query('SELECT MAX(id) FROM score_events')->fetchColumn();
        });

        // 3. If there is new data (or it's the very first loop), fetch the full live match state
        if ($currentMax > $lastEventId) {
            $lastEventId = $currentMax;

            // Fetch all IN PROGRESS matches
            $liveMatches = db_retry(function($db) {
                return $db->query("
                    SELECT m.id, m.tournament_id, m.score_a, m.score_b, m.games_a, m.games_b,
                           pa.display_name as player_a, pb.display_name as player_b,
                           ta.display_name as team_a, tb.display_name as team_b,
                           COALESCE(rc.best_of, 
                               CASE WHEN m.round_key IN ('final', 'bronze') THEN 3 ELSE 3 END
                           ) as best_of,
                           COALESCE(rc.points_per_game, 
                               CASE 
                                   WHEN m.round_key = 'final' THEN 21 
                                   WHEN m.round_key IN ('r16', 'qf', 'sf', 'bronze') THEN 15 
                                   ELSE 11 
                               END
                           ) as points_per_game,
                           COALESCE(rc.deuce_enabled, true) as deuce_enabled,
                           COALESCE(rc.deuce_trigger, 
                               CASE 
                                   WHEN m.round_key = 'final' THEN 20 
                                   WHEN m.round_key IN ('r16', 'qf', 'sf', 'bronze') THEN 14 
                                   ELSE 10 
                               END
                           ) as deuce_trigger,
                           COALESCE(rc.deuce_cap, 
                               CASE 
                                   WHEN m.round_key = 'final' THEN 26 
                                   WHEN m.round_key IN ('r16', 'qf', 'sf', 'bronze') THEN 21 
                                   ELSE 16 
                               END
                           ) as deuce_cap
                    FROM matches m
                    LEFT JOIN round_configs rc ON m.tournament_id = rc.tournament_id AND m.round_key = rc.round_key
                    LEFT JOIN players pa ON m.participant_a_id = pa.id
                    LEFT JOIN players pb ON m.participant_b_id = pb.id
                    LEFT JOIN teams ta ON m.team_a_id = ta.id
                    LEFT JOIN teams tb ON m.team_b_id = tb.id
                    WHERE m.status = 'in_progress'
                ")->fetchAll(PDO::FETCH_ASSOC);
            });

            foreach ($liveMatches as &$lm) {
                $lm['display_a'] = $lm['team_a'] ?: ($lm['player_a'] ?: 'Player A');
                $lm['display_b'] = $lm['team_b'] ?: ($lm['player_b'] ?: 'Player B');
                $lm['game_score_a'] = (int)$lm['score_a'];
                $lm['game_score_b'] = (int)$lm['score_b'];
                $lm['player_a_games_won'] = (int)$lm['games_a'];
                $lm['player_b_games_won'] = (int)$lm['games_b'];
                $lm['current_game_number'] = ((int)($lm['games_a'] ?? 0) + (int)($lm['games_b'] ?? 0)) + 1;
                $lm['current_game'] = $lm['current_game_number'];
                $lm['momentum_a'] = 50;
                $lm['momentum_b'] = 50;
            }
            unset($lm);

            // Broadcast payload
            $payload = [
                'timestamp' => date('H:i:s'),
                'live_matches' => $liveMatches
            ];

            echo "data: " . json_encode($payload) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    } catch (Exception $e) {
        // Log silently and continue without dying
        error_log('SSE broadcast error: ' . $e->getMessage());
    }

    // 4. Sleep 1.5 seconds for instant sports responsiveness
    usleep(1500000);
}
