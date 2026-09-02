<?php
// ============================================================
// Tournaments - Match Generation API
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/matchmaker.php';

requireLogin();

$id = isset($_POST['tournament_id']) ? (int)$_POST['tournament_id'] : 0;
$action = $_POST['action'] ?? '';
$admin = currentAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash_set('tournament_view', 'Invalid request or security token.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;
}

if (!$id || !$action) {
    flash_set('tournament_view', 'Missing required parameters.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;
}

try {
    switch ($action) {
        case 'generate_r1':
            Matchmaker::generateSwissRound1($id, $admin['id']);
            flash_set('tournament_view', 'Round 1 matches generated successfully!', 'success');
            break;
            
        case 'generate_r2':
            Matchmaker::generateSwissRound2($id, $admin['id']);
            flash_set('tournament_view', 'Round 2 matches generated successfully!', 'success');
            break;
            
        case 'generate_stage2':
            Matchmaker::generateStage2Bracket($id, $admin['id']);
            flash_set('tournament_view', 'Stage 2 Knockout Bracket generated successfully! Let the finals begin.', 'success');
            break;
            
        case 'generate_league':
            Matchmaker::generateRoundRobin($id, $admin['id']);
            flash_set('tournament_view', 'Round Robin league matches generated successfully!', 'success');
            break;
            
        default:
            json_error('Unknown generation action.');
    }
    
    AppCache::flush();
    // Redirect back to view page on success
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;

} catch (Exception $e) {
    // If the matchmaker throws a controlled exception (e.g., "Round 1 not completed"), catch it
    flash_set('tournament_view', $e->getMessage(), 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;
}
