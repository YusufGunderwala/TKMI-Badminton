<?php
// ============================================================
// Live Scoring API Endpoint (Strict Mode)
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scorer.php';

header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf($token)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$action = $_POST['action'] ?? '';
$matchId = isset($_POST['match_id']) ? (int)$_POST['match_id'] : 0;
$requestId = $_POST['request_id'] ?? '';
$admin = currentAdmin();
$pdo = db();

if (!$matchId || !$action || !$requestId) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters. request_id is required.']);
    exit;
}

try {
    if ($action === 'add_point') {
        $side = $_POST['side'] ?? '';
        if (!in_array($side, ['A', 'B'])) throw new Exception("Invalid side.");
        $result = Scorer::addPoint($matchId, $side, $admin['id'], $requestId);
        echo json_encode($result);
    }
    elseif ($action === 'undo_last') {
        $result = Scorer::undoLastAction($matchId, $admin['id'], $requestId);
        echo json_encode($result);
    }
    elseif ($action === 'declare_walkover') {
        $winnerSide = $_POST['winner_side'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!$winnerSide || !$reason) throw new Exception("Winner and Reason are required for Walkover.");
        $result = Scorer::declareWalkover($matchId, $winnerSide, $reason, $notes, $admin['id'], $requestId);
        echo json_encode($result);
    }
    elseif ($action === 'declare_retirement') {
        $retiredSide = $_POST['retired_side'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!$retiredSide || !$reason) throw new Exception("Retired Side and Reason are required for Retirement.");
        $result = Scorer::declareRetirement($matchId, $retiredSide, $reason, $notes, $admin['id'], $requestId);
        echo json_encode($result);
    }
    elseif ($action === 'confirm_completion') {
        // Idempotent finalization — called by client after optimistic match-win
        // to ensure the DB is properly marked completed even if the last point
        // API call failed at the network level.
        $result = Scorer::confirmCompletion($matchId, $admin['id'], $requestId);
        echo json_encode($result);
    }
    else {
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
