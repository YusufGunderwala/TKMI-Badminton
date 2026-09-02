<?php
// ============================================================
// Live Scoring API Endpoint
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scorer.php';

header('Content-Type: application/json');

// Session check
if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf($token)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$action = $_POST['action'] ?? '';
$matchId = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;
$admin = currentAdmin();
$pdo = db();

if (!$matchId || !$action) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

try {
    if ($action === 'add_a') {
        $result = Scorer::addPoint($matchId, 'A', $admin['id']);
        echo json_encode($result);
    } 
    elseif ($action === 'add_b') {
        $result = Scorer::addPoint($matchId, 'B', $admin['id']);
        echo json_encode($result);
    }
    elseif ($action === 'undo_a') {
        $result = Scorer::undoPoint($matchId, 'A', $admin['id']);
        echo json_encode($result);
    }
    elseif ($action === 'undo_b') {
        $result = Scorer::undoPoint($matchId, 'B', $admin['id']);
        echo json_encode($result);
    }
    elseif ($action === 'walkover') {
        $winnerSide = $_POST['winner_side'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!$winnerSide || !$reason) throw new Exception("Winner and Reason are required for Walkover.");
        
        Scorer::declareWalkover($matchId, $winnerSide, $reason, $notes, $admin['id']);
        echo json_encode(['success' => true, 'is_completed' => true]);
    }
    elseif ($action === 'finalize_match') {
        $winnerSide = $_POST['winner_side'] ?? '';
        $clientScoreA = isset($_POST['score_a']) ? (int)$_POST['score_a'] : null;
        $clientScoreB = isset($_POST['score_b']) ? (int)$_POST['score_b'] : null;
        $result = Scorer::finalizeMatchDirect($matchId, $winnerSide ?: null, $admin['id'], $clientScoreA, $clientScoreB);
        echo json_encode($result);
    }
    elseif ($action === 'retire') {
        $winnerSide = $_POST['winner_side'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!$winnerSide || !$reason) throw new Exception("Winner and Reason are required for Retirement.");
        
        Scorer::declareRetirement($matchId, $winnerSide, $reason, $notes, $admin['id']);
        echo json_encode(['success' => true, 'is_completed' => true]);
    }
    elseif ($action === 'update_rules') {
        $bestOf = (int)($_POST['best_of'] ?? 3);
        $points = (int)($_POST['points_per_game'] ?? 11);
        $deuce = isset($_POST['deuce_enabled']) ? (int)$_POST['deuce_enabled'] : 1;
        $trigger = (int)($_POST['deuce_trigger'] ?? ($points - 1));
        $cap = (int)($_POST['deuce_cap'] ?? ($points + 5));

        if (!in_array($bestOf, [1, 3, 5])) $bestOf = 3;
        if ($points < 5 || $points > 30) $points = 11;

        $stmt = $pdo->prepare('
            UPDATE round_configs 
            SET best_of = ?, points_per_game = ?, deuce_enabled = ?, deuce_trigger = ?, deuce_cap = ? 
            WHERE tournament_id = (SELECT tournament_id FROM matches WHERE id = ?) 
              AND round_key = (SELECT round_key FROM matches WHERE id = ?)
        ');
        $stmt->execute([$bestOf, $points, $deuce, $trigger, $cap, $matchId, $matchId]);
        AppCache::flush();

        echo json_encode([
            'success' => true,
            'best_of' => $bestOf,
            'points_per_game' => $points,
            'deuce_enabled' => (bool)$deuce,
            'deuce_trigger' => $trigger,
            'deuce_cap' => $cap,
            'games_needed' => ceil($bestOf / 2)
        ]);
        exit;
    }
    else {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
    AppCache::flush();
} catch (Exception $e) {
    $err = $e->getMessage();
    if (str_contains(strtolower($err), 'completed') || str_contains(strtolower($err), 'finalized')) {
        try {
            $m = Scorer::getMatchData($matchId);
            echo json_encode([
                'success'      => true,
                'is_completed' => true,
                'score_a'      => (int)$m['score_a'],
                'score_b'      => (int)$m['score_b'],
                'games_a'      => (int)$m['games_a'],
                'games_b'      => (int)$m['games_b']
            ]);
            exit;
        } catch (Exception $inner) {}
    }
    echo json_encode(['success' => false, 'error' => $err]);
}
exit;
