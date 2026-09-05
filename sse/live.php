<?php
// ============================================================
// Server-Sent Events (SSE) - Live Score Broadcaster
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

// Prevent PHP from stopping script when client disconnects (we handle it manually via connection_aborted)
ignore_user_abort(true);

// Disable Apache gzip and PHP output buffering so packets stream instantly
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable buffering in Nginx

$pdo = db();

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : null;

// To prevent infinite zombie processes on the server, we run the loop for a maximum of 60 seconds.
// The JS client's EventSource will automatically reconnect instantly when this script finishes.
$startTime = time();
$maxExecutionTime = 60; 

// Initial state fingerprint
$lastFingerprint = null;

while (true) {
    // 1. Break if client disconnected or timeout reached
    if (connection_aborted() || (time() - $startTime) > $maxExecutionTime) {
        break;
    }

    try {
        // 2. Ultra-reliable state fingerprint: captures points, undos, game changes, and status shifts
        $currentFingerprint = db_retry(function($db) use ($tournamentId) {
            if ($tournamentId) {
                $stmt = $db->prepare('
                    SELECT 
                        (SELECT COALESCE(MAX(id), 0) FROM score_events WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)) as max_se,
                        (SELECT COUNT(id) FROM score_events WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)) as count_se,
                        COALESCE(SUM(score_a), 0) as sum_a,
                        COALESCE(SUM(score_b), 0) as sum_b,
                        COALESCE(SUM(games_a), 0) as sum_ga,
                        COALESCE(SUM(games_b), 0) as sum_gb,
                        COUNT(CASE WHEN status = \'in_progress\' THEN 1 END) as in_prog,
                        COUNT(CASE WHEN status IN (\'completed\',\'walkover\',\'retired\') THEN 1 END) as comp
                    FROM matches
                    WHERE tournament_id = ?
                ');
                $stmt->execute([$tournamentId, $tournamentId, $tournamentId]);
                $r = $stmt->fetch(PDO::FETCH_NUM);
                return $r ? implode(':', $r) : '';
            } else {
                $stmt = $db->query('
                    SELECT 
                        (SELECT COALESCE(MAX(id), 0) FROM score_events) as max_se,
                        (SELECT COUNT(id) FROM score_events) as count_se,
                        COALESCE(SUM(score_a), 0) as sum_a,
                        COALESCE(SUM(score_b), 0) as sum_b,
                        COALESCE(SUM(games_a), 0) as sum_ga,
                        COALESCE(SUM(games_b), 0) as sum_gb,
                        COUNT(CASE WHEN status = \'in_progress\' THEN 1 END) as in_prog,
                        COUNT(CASE WHEN status IN (\'completed\',\'walkover\',\'retired\') THEN 1 END) as comp
                    FROM matches
                ');
                $r = $stmt->fetch(PDO::FETCH_NUM);
                return $r ? implode(':', $r) : '';
            }
        });

        // 3. If fingerprint changed (or initial connection frame), broadcast immediately
        if ($currentFingerprint !== $lastFingerprint) {
            $lastFingerprint = $currentFingerprint;

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
                    $sql .= " AND m.tournament_id = ? ORDER BY (CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END), m.match_number ASC, m.id ASC";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$tournamentId]);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $sql .= " ORDER BY (CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END), m.tournament_id ASC, m.match_number ASC, m.id ASC";
                    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                }
            });

            // Fetch games breakdown and serving indicator for returned matches
            $matchIds = array_column($matchesRows, 'id');
            $gamesMap = [];
            $serverMap = [];
            if (!empty($matchIds)) {
                $in = implode(',', array_map('intval', $matchIds));
                $gRows = db_retry(function($db) use ($in) {
                    return $db->query("SELECT match_id, game_number, score_a, score_b, winner_side FROM games WHERE match_id IN ($in) ORDER BY match_id, game_number ASC")->fetchAll(PDO::FETCH_ASSOC);
                });
                foreach ($gRows as $gr) {
                    $gamesMap[$gr['match_id']][] = $gr;
                }

                $sRows = db_retry(function($db) use ($in) {
                    return $db->query("
                        SELECT DISTINCT ON (match_id) match_id, side
                        FROM score_events
                        WHERE match_id IN ($in) AND is_undone = FALSE AND side IS NOT NULL
                        ORDER BY match_id, sequence_no DESC, id DESC
                    ")->fetchAll(PDO::FETCH_ASSOC);
                });
                foreach ($sRows as $sr) {
                    $serverMap[$sr['match_id']] = ($sr['side'] === 'B') ? 'player_b' : 'player_a';
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
                $lm['current_server'] = $serverMap[$lm['id']] ?? 'player_a';
                $lm['deuce_enabled'] = (bool)$lm['deuce_enabled'];
                $lm['is_completed'] = in_array($lm['status'], ['completed', 'walkover', 'retired']);
                $lm['match_number'] = (int)($lm['match_number'] ?? 0);
                $lm['round_label'] = getRoundLabel($lm['round_key'] ?? '');

                // Winner determination
                $winnerSide = null;
                if (!empty($lm['winner_player_id'])) {
                    $winnerSide = ($lm['winner_player_id'] == $lm['participant_a_id']) ? 'A' : 'B';
                } elseif (!empty($lm['winner_team_id'])) {
                    $winnerSide = ($lm['winner_team_id'] == $lm['team_a_id']) ? 'A' : 'B';
                }
                $lm['winner_side'] = $winnerSide;
                $lm['winner_name'] = ($winnerSide === 'A') ? $lm['display_a'] : (($winnerSide === 'B') ? $lm['display_b'] : null);

                // Games history with winner details
                $games = $gamesMap[$lm['id']] ?? [];
                foreach ($games as &$g) {
                    if (empty($g['winner_side'])) {
                        $g['winner_side'] = ($g['score_a'] > $g['score_b']) ? 'A' : (($g['score_b'] > $g['score_a']) ? 'B' : null);
                    }
                    $g['winner_name'] = ($g['winner_side'] === 'A') ? $lm['display_a'] : (($g['winner_side'] === 'B') ? $lm['display_b'] : null);
                }
                unset($g);
                $lm['games'] = $games;
                $scoresArr = array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $games);
                $lm['score_breakdown'] = implode(', ', $scoresArr);

                // Real-time AI Win Predictor / Momentum Calculation
                $probs = calculateWinProbability(
                    $lm['game_score_a'],
                    $lm['game_score_b'],
                    $lm['player_a_games_won'],
                    $lm['player_b_games_won'],
                    (int)($lm['points_per_game'] ?? 11),
                    (int)($lm['best_of'] ?? 3),
                    $lm['is_completed'],
                    $lm['winner_side']
                );
                $lm['momentum_a'] = $probs['a'];
                $lm['momentum_b'] = $probs['b'];

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

    } catch (Throwable $e) {
        // Silently swallow DB transient errors; will retry next tick
    }

    // Ping every 15s to keep connection alive if no score changes
    if (time() % 15 === 0) {
        echo ": keep-alive\n\n";
        ob_flush();
        flush();
    }

    // Wait 250ms before checking again (low CPU usage, fast real-time response)
    usleep(250000); 
}

