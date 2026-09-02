<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/matchmaker.php';

requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tournament = getTournament($id);

if (!$tournament || $tournament['format'] !== 'pools_knockout') {
    flash_set('tournaments', 'Invalid tournament or format.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/');
    exit;
}

// Fetch enrolled players
$stmt = db()->prepare('
    SELECT p.id, p.full_name, p.its_id, tp.pool_name 
    FROM tournament_players tp 
    JOIN players p ON p.id = tp.player_id 
    WHERE tp.tournament_id = ? 
    ORDER BY p.full_name ASC
');
$stmt->execute([$id]);
$players = $stmt->fetchAll();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_pools') {
            $pools = $_POST['pools'] ?? [];
            try {
                db()->beginTransaction();
                $updateStmt = db()->prepare('UPDATE tournament_players SET pool_name = ? WHERE tournament_id = ? AND player_id = ?');
                foreach ($pools as $player_id => $pool_name) {
                    $pool_name = trim($pool_name);
                    $pool_name = $pool_name === '' ? null : strtoupper($pool_name);
                    $updateStmt->execute([$pool_name, $id, $player_id]);
                }
                db()->commit();
                flash_set('pools', 'Pool assignments saved successfully!', 'success');
                header("Location: pools.php?id=$id");
                exit;
            } catch (Exception $e) {
                db()->rollBack();
                $error = 'Failed to save pools: ' . $e->getMessage();
            }
        }
        elseif ($action === 'generate_matches') {
            // First check if any matches already exist
            $chk = db()->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = 'group_stage'");
            $chk->execute([$id]);
            $hasMatches = (int)$chk->fetchColumn() > 0;
            if ($hasMatches) {
                $error = 'Matches have already been generated for this tournament.';
            } else {
                // Group players by pool
                $groups = [];
                foreach ($players as $p) {
                    if (!empty($p['pool_name'])) {
                        $groups[$p['pool_name']][] = $p;
                    }
                }
                
                if (empty($groups)) {
                    $error = 'No players are assigned to any pools.';
                } else {
                    try {
                        db()->beginTransaction();
                        
                        // Ensure a "Group Stage" round config exists
                        $stmt = db()->prepare("SELECT id FROM round_configs WHERE tournament_id = ? AND round_key = 'group_stage'");
                        $stmt->execute([$id]);
                        $round_id = $stmt->fetchColumn();
                        if (!$round_id) {
                            $stmt = db()->prepare("INSERT INTO round_configs (tournament_id, round_key, round_label, best_of, points_per_game, deuce_enabled, deuce_trigger, deuce_cap, sort_order) 
                                                   VALUES (?, 'group_stage', 'Group Stage', 3, 15, TRUE, 14, 21, 10)");
                            $stmt->execute([$id]);
                            $round_id = db()->lastInsertId();
                        }

                        // Generate Round Robin matches for each pool
                        $matchCount = 0;
                        $insertMatch = db()->prepare("INSERT INTO matches (tournament_id, stage, round_key, match_number, participant_a_id, participant_b_id, status) VALUES (?, 'stage1', 'group_stage', ?, ?, ?, 'scheduled')");
                        
                        $matchNumber = 1;
                        foreach ($groups as $poolName => $poolPlayers) {
                            $n = count($poolPlayers);
                            for ($i = 0; $i < $n; $i++) {
                                for ($j = $i + 1; $j < $n; $j++) {
                                    $insertMatch->execute([
                                        $id, 
                                        $matchNumber++,
                                        $poolPlayers[$i]['id'],
                                        $poolPlayers[$j]['id']
                                    ]);
                                    $matchCount++;
                                }
                            }
                        }
                        
                        db()->commit();
                        AppCache::flush();
                        flash_set('pools', "$matchCount pool matches generated successfully!", 'success');
                        header("Location: ../scoring/index.php?tournament_id=$id");
                        exit;
                    } catch (Exception $e) {
                        db()->rollBack();
                        $error = 'Failed to generate matches: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Group players for display
$grouped = [];
$unassigned = [];
foreach ($players as $p) {
    if (empty($p['pool_name'])) {
        $unassigned[] = $p;
    } else {
        $grouped[$p['pool_name']][] = $p;
    }
}
ksort($grouped);

$pageTitle = 'Manage Pools: ' . $tournament['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $id ?>" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
            <i class="ph-bold ph-arrow-left"></i> Back to Settings
        </a>
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center text-purple-600 shadow-inner">
                <i class="ph-fill ph-columns text-2xl"></i>
            </div>
            <h2 class="text-3xl font-black font-display text-[#0f2044]">Manage Pools</h2>
        </div>
        <p class="text-sm font-bold text-slate-500 mt-1"><?= e($tournament['name']) ?> &bull; <?= count($players) ?> Enrolled Players</p>
    </div>
    
    <div class="flex items-center gap-3">
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="generate_matches">
            <button type="submit" class="bg-[#0f2044] hover:bg-blue-900 text-white font-bold py-3 px-6 rounded-xl shadow-md transition-all flex items-center gap-2">
                <i class="ph-bold ph-play"></i> Generate Matches
            </button>
        </form>
    </div>
</div>

<?= flash_html('pools') ?>
<?php if (isset($error)): ?>
    <div class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 text-sm border border-red-200 flex items-center gap-2">
        <i class="ph-fill ph-warning-circle text-red-500 text-lg"></i>
        <span class="font-bold"><?= e($error) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8 relative items-start">

    <!-- Assignment Form -->
    <div class="lg:col-span-1 sticky top-24">
        <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden flex flex-col max-h-[calc(100vh-120px)]">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                <h3 class="font-black text-[#0f2044] flex items-center gap-2">
                    <i class="ph-fill ph-users-three text-[#c9a84c]"></i>
                    Player Assignment
                </h3>
            </div>
            
            <form action="" method="POST" class="flex flex-col flex-1 overflow-hidden">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_pools">
                
                <div class="flex-1 overflow-y-auto p-2 custom-scrollbar bg-slate-50/30">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-white shadow-sm z-10 hidden">
                            <tr><th>Name</th><th>Pool</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($players as $p): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-800"><?= e($p['full_name']) ?></div>
                                        <div class="text-xs text-slate-400 font-bold"><?= e($p['its_id']) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <input type="text" name="pools[<?= $p['id'] ?>]" value="<?= e($p['pool_name']) ?>" 
                                               placeholder="e.g. A" maxlength="2"
                                               class="w-16 bg-white border border-slate-200 rounded-lg text-center font-black text-[#0f2044] uppercase focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-shadow">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="p-4 bg-white border-t border-slate-100">
                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-4 rounded-xl shadow-md transition-all flex items-center justify-center gap-2">
                        <i class="ph-bold ph-floppy-disk"></i> Save Assignments
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Preview Layout -->
    <div class="lg:col-span-2 space-y-6">
        <?php if (empty($grouped)): ?>
            <div class="text-center py-20 bg-slate-50 rounded-2xl border-2 border-dashed border-slate-200">
                <i class="ph-fill ph-empty text-6xl text-slate-300 mb-4 drop-shadow-sm"></i>
                <h4 class="font-black text-slate-700 text-xl">No Pools Configured</h4>
                <p class="text-slate-500 mt-2 max-w-md mx-auto">Use the sidebar on the left to assign letters (A, B, C...) to players. They will be grouped here automatically.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($grouped as $poolName => $poolPlayers): ?>
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden group hover:border-purple-300 transition-colors">
                        <div class="px-5 py-3 border-b border-slate-100 bg-purple-50/50 flex justify-between items-center">
                            <h3 class="font-black text-purple-900 flex items-center gap-2">
                                <span class="w-6 h-6 rounded bg-purple-600 text-white flex items-center justify-center text-sm shadow-sm"><?= e($poolName) ?></span>
                                Pool <?= e($poolName) ?>
                            </h3>
                            <span class="text-xs font-bold text-purple-600 bg-purple-100 px-2 py-1 rounded-md"><?= count($poolPlayers) ?> Players</span>
                        </div>
                        <ul class="divide-y divide-slate-50">
                            <?php foreach ($poolPlayers as $p): ?>
                                <li class="px-5 py-3 flex items-center justify-between hover:bg-slate-50">
                                    <span class="font-bold text-slate-700"><?= e($p['full_name']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
