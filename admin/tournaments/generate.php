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
        case 'lock_enrollment':
            $manifest = Matchmaker::generateStructureManifest($id);
            
            // Delete old configs if any
            db()->prepare('DELETE FROM round_configs WHERE tournament_id = ?')->execute([$id]);
            
            $insertStmt = db()->prepare('
                INSERT INTO round_configs 
                (tournament_id, round_key, round_label, best_of, points_per_game, deuce_enabled, deuce_trigger, deuce_cap, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            
            $sort = 1;
            if (isset($manifest['stage_1']['rounds'])) {
                foreach ($manifest['stage_1']['rounds'] as $r) {
                    $insertStmt->execute([$id, $r, ucwords(str_replace('_', ' ', $r)), 3, 11, 1, 10, 16, $sort++]);
                }
            }
            if (isset($manifest['stage_2']['rounds'])) {
                foreach ($manifest['stage_2']['rounds'] as $r) {
                    // Final is 21 pts (deuce 20-20, cap 26); all other Stage 2 rounds are 15 pts (deuce 14-14, cap 21)
                    $isFinal = ($r === 'stage2_final' || $r === 'final' || $r === ROUND_FINAL);
                    $pts = $isFinal ? 21 : 15;
                    $dt = $isFinal ? 20 : 14;
                    $dc = $isFinal ? 26 : 21;
                    $insertStmt->execute([$id, $r, ucwords(str_replace('_', ' ', $r)), 3, $pts, 1, $dt, $dc, $sort++]);
                }
            }
            
            flash_set('tournament_view', 'Enrollment locked and structure generated successfully!', 'success');
            break;

        case 'lock_rules':
            db()->prepare('UPDATE tournaments SET status = ? WHERE id = ?')->execute(['rules_locked', $id]);
            flash_set('tournament_view', 'Scoring Rules Locked! You can now generate matches.', 'success');
            break;

        case 'generate_r1':
            Matchmaker::generateSwissRound1($id, $admin['id']);
            flash_set('tournament_view', 'Round 1 matches generated successfully! Tournament is now LIVE.', 'success');
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
    $returnTo = $_POST['return_to'] ?? '';
    if ($returnTo === 'scoring') {
        header('Location: ' . BASE_URL . '/admin/scoring/index.php?tournament_id=' . $id);
    } else {
        header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    }
    exit;

} catch (Exception $e) {
    // If the matchmaker throws a controlled exception (e.g., "Round 1 not completed"), catch it
    flash_set('tournament_view', $e->getMessage(), 'error');
    $returnTo = $_POST['return_to'] ?? '';
    if ($returnTo === 'scoring') {
        header('Location: ' . BASE_URL . '/admin/scoring/index.php?tournament_id=' . $id);
    } else {
        header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    }
    exit;
}
