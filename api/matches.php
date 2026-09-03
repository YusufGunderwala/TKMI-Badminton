<?php
// ============================================================
// Match Status API -- Hub Fallback Poller
// Returns current DB state for all matches in a tournament.
// Used by the scoring hub as a fallback when SSE misses an update.
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;
if (!$tournamentId) {
    echo json_encode(['success' => false, 'error' => 'Missing tournament_id']);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT m.id, m.status, m.score_a, m.score_b, m.games_a, m.games_b,
               m.winner_player_id, m.winner_team_id,
               m.participant_a_id, m.participant_b_id,
               m.team_a_id, m.team_b_id,
               m.walkover_reason
        FROM matches m
        WHERE m.tournament_id = ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([$tournamentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $gStmt = $pdo->prepare("
        SELECT g.match_id, g.score_a, g.score_b
        FROM games g
        JOIN matches m ON g.match_id = m.id
        WHERE m.tournament_id = ?
        ORDER BY g.match_id, g.game_number ASC
    ");
    $gStmt->execute([$tournamentId]);
    $gamesMap = [];
    foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $gamesMap[$g['match_id']][] = $g;
    }

    $matches = [];
    foreach ($rows as $m) {
        $isCompleted = in_array($m['status'], ['completed', 'walkover', 'retired']);
        $winnerSide = null;
        if (!empty($m['winner_player_id'])) {
            $winnerSide = ($m['winner_player_id'] == $m['participant_a_id']) ? 'A' : 'B';
        } elseif (!empty($m['winner_team_id'])) {
            $winnerSide = ($m['winner_team_id'] == $m['team_a_id']) ? 'A' : 'B';
        }
        $games = $gamesMap[$m['id']] ?? [];
        $scoreBreakdown = implode(', ', array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $games));

        $matches[] = [
            'id'              => (int)$m['id'],
            'status'          => $m['status'],
            'is_completed'    => $isCompleted,
            'score_a'         => (int)$m['score_a'],
            'score_b'         => (int)$m['score_b'],
            'games_a'         => (int)$m['games_a'],
            'games_b'         => (int)$m['games_b'],
            'winner_side'     => $winnerSide,
            'score_breakdown' => $scoreBreakdown,
            'walkover_reason' => $m['walkover_reason'] ?? null,
        ];
    }

    echo json_encode(['success' => true, 'matches' => $matches]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
