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
        // 2. Ultra-lightweight check: Has score event or match status changed?
        $currentMax = (int)db_retry(function($db) use ($tournamentId) {
            if ($tournamentId) {
                $stmt = $db->prepare('SELECT COALESCE(MAX(se.id), 0) FROM score_events se JOIN matches m ON se.match_id = m.id WHERE m.tournament_id = ?');
                $stmt->execute([$tournamentId]);
                $maxSe = (int)$stmt->fetchColumn();

                $stmt2 = $db->prepare("SELECT COALESCE(MAX(EXTRACT(EPOCH FROM COALESCE(ended_at, started_at, NOW())))::int, 0) FROM matches WHERE tournament_id = ?");
                $stmt2->execute([$tournamentId]);
                $maxMatch = (int)$stmt2->fetchColumn();

                return $maxSe + $maxMatch;
            } else {
                $maxSe = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM score_events')->fetchColumn();
                $maxMatch = (int)$db->query("SELECT COALESCE(MAX(EXTRACT(EPOCH FROM COALESCE(ended_at, started_at, NOW())))::int, 0) FROM matches")->fetchColumn();
                return $maxSe + $maxMatch;
            }
        });

        // 3. If there is new data (or it's the very first loop), fetch active and recently updated matches
        if ($currentMax > $lastEventId) {
            $lastEventId = $currentMax;

            $matchesRows = db_retry(function($db) use ($tournamentId) {
                $sql = "
                    SELECT m.id, m.tournament_id, m.round_key, m.stage, m.status, m.score_a, m.score_b, m.games_a, m.games_b,
                           m.winner_player_id, m.winner_team_id, m.loser_player_id, m.loser_team_id,
                           m.participant_a_id, m.participant_b_id, m.team_a_id, m.team_b_id,
                           pa.display_name as player_a, pb.display_name as player_b,
                           ta.display_name as team_a, tb.display_name as team_b,
                           m.best_of, m.points_per_game, m.deuce_enabled, m.deuce_trigger, m.deuce_cap,
                           m.started_at, m.ended_at
                    FROM matches m
                    LEFT JOIN players pa ON m.participant_a_id = pa.id
                    LEFT JOIN players pb ON m.participant_b_id = pb.id
                    LEFT JOIN teams ta ON m.team_a_id = ta.id
                    LEFT JOIN teams tb ON m.team_b_id = tb.id
                    WHERE (m.status = 'in_progress' OR (m.status IN ('completed', 'walkover', 'retired') AND m.ended_at >= NOW() - INTERVAL '2 hours'))
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

            // Fetch games breakdown for returned matches
            $matchIds = array_column($matchesRows, 'id');
            $gamesMap = [];
            if (!empty($matchIds)) {
                $in = implode(',', array_map('intval', $matchIds));
                $gRows = db_retry(function($db) use ($in) {
                    return $db->query("SELECT match_id, game_number, score_a, score_b, winner_side FROM games WHERE match_id IN ($in) ORDER BY match_id, game_number ASC")->fetchAll(PDO::FETCH_ASSOC);
                });
                foreach ($gRows as $gr) {
                    $gamesMap[$gr['match_id']][] = $gr;
                }
            }

            $liveMatches = [];
            $completedMatches = [];

            foreach ($matchesRows as &$lm) {
                $isDoubles = !empty($lm['team_a_id']);
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
                $lm['deuce_enabled'] = (bool)$lm['deuce_enabled'];
                $lm['is_completed'] = in_array($lm['status'], ['completed', 'walkover', 'retired']);

                // Winner determination
                $winnerSide = null;
                if (!empty($lm['winner_player_id'])) {
                    $winnerSide = ($lm['winner_player_id'] == $lm['participant_a_id']) ? 'A' : 'B';
                } elseif (!empty($lm['winner_team_id'])) {
                    $winnerSide = ($lm['winner_team_id'] == $lm['team_a_id']) ? 'A' : 'B';
                }
                $lm['winner_side'] = $winnerSide;
                $lm['winner_name'] = ($winnerSide === 'A') ? $lm['display_a'] : (($winnerSide === 'B') ? $lm['display_b'] : null);

                // Games history
                $games = $gamesMap[$lm['id']] ?? [];
                $lm['games'] = $games;
                $scoresArr = array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $games);
                $lm['score_breakdown'] = implode(', ', $scoresArr);

                if ($lm['status'] === 'in_progress') {
                    $liveMatches[] = $lm;
                } else {
                    $completedMatches[] = $lm;
                }
            }
            unset($lm);

            // Broadcast payload
            $payload = [
                'timestamp' => date('H:i:s'),
                'matches' => $matchesRows,
                'live_matches' => $liveMatches,
                'completed_matches' => $completedMatches
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

    // Wait 500ms before checking again (low CPU usage, fast real-time response)
    usleep(500000); 
}
