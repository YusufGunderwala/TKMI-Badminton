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

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;

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
        // 2. Ultra-lightweight check: Has the latest score event changed for this tournament?
        $currentMax = (int)db_retry(function($db) use ($tournamentId) {
            if ($tournamentId) {
                $stmt = $db->prepare('SELECT MAX(se.id) FROM score_events se JOIN matches m ON se.match_id = m.id WHERE m.tournament_id = ?');
                $stmt->execute([$tournamentId]);
                return $stmt->fetchColumn() ?: 0;
            } else {
                return $db->query('SELECT MAX(id) FROM score_events')->fetchColumn() ?: 0;
            }
        });

        // 3. If there is new data (or it's the very first loop), fetch the full live match state
        if ($currentMax > $lastEventId) {
            $lastEventId = $currentMax;

            // Fetch all IN PROGRESS matches
            $liveMatches = db_retry(function($db) use ($tournamentId) {
                $sql = "
                    SELECT m.id, m.tournament_id, m.score_a, m.score_b, m.games_a, m.games_b,
                           pa.display_name as player_a, pb.display_name as player_b,
                           ta.display_name as team_a, tb.display_name as team_b,
                           m.best_of, m.points_per_game, m.deuce_enabled, m.deuce_trigger, m.deuce_cap
                    FROM matches m
                    LEFT JOIN players pa ON m.participant_a_id = pa.id
                    LEFT JOIN players pb ON m.participant_b_id = pb.id
                    LEFT JOIN teams ta ON m.team_a_id = ta.id
                    LEFT JOIN teams tb ON m.team_b_id = tb.id
                    WHERE m.status = 'in_progress'
                ";
                if ($tournamentId) {
                    $sql .= " AND m.tournament_id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$tournamentId]);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                }
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
                // Add bool for deuce enabled
                $lm['deuce_enabled'] = (bool)$lm['deuce_enabled'];
            }
            unset($lm);

            // Broadcast payload
            $payload = [
                'timestamp' => date('H:i:s'),
                'matches' => $liveMatches
            ];

            echo "data: " . json_encode($payload) . "\n\n";
            ob_flush();
            flush();
        }

    } catch (Exception $e) {
        // Silently swallow DB transient errors; will retry next tick
    }

    // Ping every 15s to keep connection alive if no score changes
    if (time() % 15 === 0) {
        echo ": keep-alive\n\n";
        ob_flush();
        flush();
    }

    // Wait 1.5 seconds before checking again (low CPU usage)
    usleep(1500000); 
}
