<?php
// ============================================================
// Match Status API -- Real-time / Fallback Poller
// Returns current DB state for live/tournament matches.
// Publicly accessible for live scoring widgets.
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

try {
    $pdo = db();

    if ($tournamentId) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.tournament_id, m.round_key, m.stage, m.status, m.score_a, m.score_b, m.games_a, m.games_b,
                   m.winner_player_id, m.winner_team_id,
                   m.participant_a_id, m.participant_b_id,
                   m.team_a_id, m.team_b_id,
                   pa.display_name as player_a, pb.display_name as player_b,
                   ta.display_name as team_a, tb.display_name as team_b,
                   t.name as tournament_name,
                   m.best_of, m.points_per_game, m.deuce_enabled, m.deuce_trigger, m.deuce_cap,
                   m.walkover_reason
            FROM matches m
            JOIN tournaments t ON m.tournament_id = t.id
            LEFT JOIN players pa ON m.participant_a_id = pa.id
            LEFT JOIN players pb ON m.participant_b_id = pb.id
            LEFT JOIN teams ta ON m.team_a_id = ta.id
            LEFT JOIN teams tb ON m.team_b_id = tb.id
            WHERE m.tournament_id = ?
            ORDER BY m.id ASC
        ");
        $stmt->execute([$tournamentId]);
    } else {
        $stmt = $pdo->query("
            SELECT m.id, m.tournament_id, m.round_key, m.stage, m.status, m.score_a, m.score_b, m.games_a, m.games_b,
                   m.winner_player_id, m.winner_team_id,
                   m.participant_a_id, m.participant_b_id,
                   m.team_a_id, m.team_b_id,
                   pa.display_name as player_a, pb.display_name as player_b,
                   ta.display_name as team_a, tb.display_name as team_b,
                   t.name as tournament_name,
                   m.best_of, m.points_per_game, m.deuce_enabled, m.deuce_trigger, m.deuce_cap,
                   m.walkover_reason
            FROM matches m
            JOIN tournaments t ON m.tournament_id = t.id
            LEFT JOIN players pa ON m.participant_a_id = pa.id
            LEFT JOIN players pb ON m.participant_b_id = pb.id
            LEFT JOIN teams ta ON m.team_a_id = ta.id
            LEFT JOIN teams tb ON m.team_b_id = tb.id
            WHERE (m.status = 'in_progress' OR (m.status IN ('completed', 'walkover', 'retired') AND m.ended_at >= NOW() - INTERVAL '2 hours'))
            ORDER BY (CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END), m.tournament_id ASC, m.match_number ASC, m.id ASC
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch games for breakdown
    $matchIds = array_column($rows, 'id');
    $gamesMap = [];
    if (!empty($matchIds)) {
        $in = implode(',', array_map('intval', $matchIds));
        $gStmt = $pdo->query("SELECT match_id, game_number, score_a, score_b, winner_side FROM games WHERE match_id IN ($in) ORDER BY match_id, game_number ASC");
        foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $gamesMap[$g['match_id']][] = $g;
        }
    }

    $matches = [];
    $liveMatches = [];
    foreach ($rows as $m) {
        $isDoubles = !empty($m['team_a_id']);
        $displayA = $m['team_a'] ?: ($m['player_a'] ?: 'Player A');
        $displayB = $m['team_b'] ?: ($m['player_b'] ?: 'Player B');
        $isCompleted = in_array($m['status'], ['completed', 'walkover', 'retired']);

        $winnerSide = null;
        if (!empty($m['winner_player_id'])) {
            $winnerSide = ($m['winner_player_id'] == $m['participant_a_id']) ? 'A' : 'B';
        } elseif (!empty($m['winner_team_id'])) {
            $winnerSide = ($m['winner_team_id'] == $m['team_a_id']) ? 'A' : 'B';
        }

        $games = $gamesMap[$m['id']] ?? [];
        $scoreBreakdown = implode(', ', array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $games));

        $item = [
            'id'                  => (int)$m['id'],
            'tournament_id'       => (int)$m['tournament_id'],
            'tournament_name'     => $m['tournament_name'] ?? '',
            'round_key'           => $m['round_key'],
            'status'              => $m['status'],
            'is_completed'        => $isCompleted,
            'score_a'             => (int)$m['score_a'],
            'score_b'             => (int)$m['score_b'],
            'games_a'             => (int)$m['games_a'],
            'games_b'             => (int)$m['games_b'],
            'game_score_a'        => (int)$m['score_a'],
            'game_score_b'        => (int)$m['score_b'],
            'player_a_games_won'  => (int)$m['games_a'],
            'player_b_games_won'  => (int)$m['games_b'],
            'display_a'           => $displayA,
            'display_b'           => $displayB,
            'player_a'            => $m['player_a'] ?? '',
            'player_b'            => $m['player_b'] ?? '',
            'best_of'             => (int)$m['best_of'],
            'points_per_game'     => (int)$m['points_per_game'],
            'deuce_enabled'       => (bool)$m['deuce_enabled'],
            'deuce_trigger'       => (int)$m['deuce_trigger'],
            'deuce_cap'           => (int)$m['deuce_cap'],
            'winner_side'         => $winnerSide,
            'score_breakdown'     => $scoreBreakdown,
            'walkover_reason'     => $m['walkover_reason'] ?? null,
            'current_game_number' => ((int)$m['games_a'] + (int)$m['games_b']) + 1,
        ];

        $matches[] = $item;
        if ($m['status'] === 'in_progress') {
            $liveMatches[] = $item;
        }
    }

    echo json_encode(['success' => true, 'matches' => $matches, 'live_matches' => $liveMatches]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

