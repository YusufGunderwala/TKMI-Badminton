<?php
// ============================================================
// Tournaments - View & Configure
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tournament = getTournament($id);

if (!$tournament) {
    flash_set('tournaments', 'Tournament not found.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/');
    exit;
}

$pageTitle = 'Manage: ' . $tournament['name'];

// Seed Round Configs if missing
$stmt = db()->prepare('SELECT * FROM round_configs WHERE tournament_id = ? ORDER BY sort_order ASC');
$stmt->execute([$id]);
$rounds = $stmt->fetchAll();

if (empty($rounds)) {
    $insertStmt = db()->prepare('
        INSERT INTO round_configs 
        (tournament_id, round_key, round_label, best_of, points_per_game, deuce_enabled, deuce_trigger, deuce_cap, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    if ($tournament['format'] === FORMAT_SWISS_KNOCKOUT) {
        foreach (DEFAULT_ROUND_CONFIGS as $key => $cfg) {
            $insertStmt->execute([
                $id, 
                $key, 
                $cfg['label'], 
                $cfg['best_of'], 
                $cfg['points_per_game'], 
                $cfg['deuce_enabled'], 
                $cfg['deuce_trigger'], 
                $cfg['deuce_cap'], 
                $cfg['sort_order']
            ]);
        }
    } elseif ($tournament['format'] === 'pools_knockout') {
        $poolsConfigs = [
            ['group_stage', 'Group Stage (Pools)', 3, 15, 1, 14, 21, 1],
            ['qf', 'Quarter-Finals', 3, 15, 1, 14, 21, 2],
            ['sf', 'Semi-Finals', 3, 15, 1, 14, 21, 3],
            ['3rd_place', '3rd Place Match', 3, 15, 1, 14, 21, 4],
            ['final', 'Final', 3, 21, 1, 20, 26, 5]
        ];
        foreach ($poolsConfigs as $cfg) {
            $insertStmt->execute([$id, $cfg[0], $cfg[1], $cfg[2], $cfg[3], $cfg[4], $cfg[5], $cfg[6], $cfg[7]]);
        }
    } else {
        $insertStmt->execute([$id, 'round_robin', 'Round Robin', 3, 21, 1, 20, 30, 1]);
    }
    
    // re-fetch
    $stmt->execute([$id]);
    $rounds = $stmt->fetchAll();
}

// Handle Delete Tournament Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tournament') {
    if (verify_csrf()) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $tName = $tournament['name'] ?? 'Tournament';

            // Delete related records in cascade order
            $pdo->prepare('DELETE FROM score_events WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)')->execute([$id]);
            $pdo->prepare('DELETE FROM games WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?)')->execute([$id]);
            $pdo->prepare('DELETE FROM matches WHERE tournament_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM group_participants WHERE group_id IN (SELECT id FROM tournament_groups WHERE tournament_id = ?)')->execute([$id]);
            $pdo->prepare('DELETE FROM tournament_groups WHERE tournament_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM player_tournament_records WHERE tournament_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM tournament_players WHERE tournament_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM round_configs WHERE tournament_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM tournaments WHERE id = ?')->execute([$id]);

            $pdo->commit();
            AppCache::flush();
            flash_set('tournaments', "Tournament \"{$tName}\" was deleted successfully.", 'success');
            header('Location: ' . BASE_URL . '/admin/tournaments/index.php');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('tournament_view', 'Error deleting tournament: ' . $e->getMessage(), 'error');
        }
    }
}

// Calculate effective participant count to determine which bracket rounds to show
$participantCount = 0;
if ($tournament['match_type'] === 'doubles') {
    $participantCount = (int)db()->query("SELECT COUNT(*) FROM teams WHERE tournament_id = $id")->fetchColumn();
} else {
    $participantCount = (int)db()->query("SELECT COUNT(*) FROM tournament_players WHERE tournament_id = $id")->fetchColumn();
}
// Default to 32 (max) if 0 to show all rounds initially
$effCount = $participantCount > 0 ? $participantCount : 32;
$maxQualifiers = ceil($effCount / 2);
$bracketSize = 2;
while ($bracketSize < $maxQualifiers) {
    $bracketSize *= 2;
}

// Filter rounds dynamically
$filteredRounds = [];
foreach ($rounds as $r) {
    if ($r['round_key'] === ROUND_R16 && $bracketSize < 16) continue;
    if ($r['round_key'] === ROUND_QF && $bracketSize < 8) continue;
    if ($r['round_key'] === ROUND_SF && $bracketSize < 4) continue;
    $filteredRounds[] = $r;
}
$rounds = $filteredRounds;

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (verify_csrf()) {
        $new_status = $_POST['status'] ?? '';
        if (in_array($new_status, ['draft', 'ready', 'live', 'completed', 'archived'])) {
            $stmt = db()->prepare('UPDATE tournaments SET status = ? WHERE id = ?');
            $stmt->execute([$new_status, $id]);
            AppCache::flush();
            flash_set('tournament_view', 'Status updated successfully to ' . strtoupper($new_status), 'success');
            header('Location: view.php?id=' . $id);
            exit;
        }
    }
}

// Handle Scoring Rules Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_rules') {
    if (verify_csrf()) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $updateStmt = $pdo->prepare('
                UPDATE round_configs 
                SET best_of = ?, points_per_game = ?, deuce_trigger = ?, deuce_cap = ? 
                WHERE id = ? AND tournament_id = ?
            ');
            
            foreach ($_POST['rounds'] as $round_id => $data) {
                $bestOf = (int)($data['best_of'] ?? 3);
                $pts = (int)$data['points'];
                $dt  = (int)$data['deuce_trigger'];
                $dc  = (int)$data['deuce_cap'];
                $rid = (int)$round_id;
                
                if (!in_array($bestOf, [1, 3, 5])) $bestOf = 3;
                if ($dc < $dt) $dc = $dt + 1;

                $updateStmt->execute([$bestOf, $pts, $dt, $dc, $rid, $id]);
            }
            $pdo->commit();
            AppCache::flush();
            flash_set('tournament_view', 'Scoring rules updated successfully.', 'success');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $error = 'Failed to update scoring rules.';
        }
    }
}

include __DIR__ . '/../includes/header.php';

// Format Badge UI
$formatBadgeColor = 'bg-blue-100 text-blue-800';
$formatIcon = 'ph-strategy';
$formatText = 'Swiss + Knockout (Dynamic)';

// Helper for status colors
function getStatusTailwind($status) {
    switch ($status) {
        case 'draft': return 'bg-slate-100 text-slate-700';
        case 'ready': return 'bg-yellow-100 text-yellow-800 border border-yellow-200';
        case 'live': return 'bg-red-100 text-red-700 border border-red-200 font-bold';
        case 'completed': return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
        case 'archived': return 'bg-slate-800 text-slate-300';
        default: return 'bg-slate-100 text-slate-700';
    }
}
?>

<div x-data="{ showDeleteModal: false }">

<div class="mb-8 flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <a href="<?= BASE_URL ?>/admin/tournaments/index.php" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
            <i class="ph-bold ph-arrow-left"></i> Back to Tournaments
        </a>
        <div class="flex items-center gap-4">
            <h2 class="text-3xl font-black font-display text-[#0f2044]"><?= e($tournament['name']) ?></h2>
            <span class="px-3 py-1 text-xs uppercase tracking-widest rounded-full <?= getStatusTailwind($tournament['status']) ?>">
                <?= e($tournament['status']) ?>
            </span>
        </div>
        <div class="flex items-center gap-3 mt-3">
            <span class="inline-flex items-center gap-1 text-sm font-bold text-slate-600 bg-white border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm">
                <i class="ph-bold ph-gender-<?= strtolower($tournament['gender']) === 'boys' ? 'male text-blue-500' : 'female text-pink-500' ?>"></i>
                <?= e($tournament['gender']) ?>
            </span>
            <span class="inline-flex items-center gap-1 text-sm font-bold text-slate-600 bg-white border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm">
                <i class="ph-bold <?= strtolower($tournament['match_type']) === 'singles' ? 'ph-user' : 'ph-users' ?> text-[#c9a84c]"></i>
                <?= ucfirst($tournament['match_type']) ?>
            </span>
            <span class="inline-flex items-center gap-1 text-sm font-bold bg-white border border-slate-200 px-3 py-1.5 rounded-lg shadow-sm">
                <i class="ph-bold <?= $formatIcon ?> text-slate-400"></i>
                <span class="text-slate-700"><?= $formatText ?></span>
            </span>
        </div>
    </div>
    
    <div class="flex items-center gap-3">
        <form action="" method="POST" class="inline flex items-center" id="statusForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            
            <div x-data="{ open: false, value: '<?= e($tournament['status']) ?>' }" class="relative">
                <input type="hidden" name="status" x-model="value">
                
                <div class="bg-white border border-slate-200 p-1 rounded-xl shadow-sm flex items-center">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 pr-2">State</span>
                    <button type="button" @click="open = !open" @click.away="open = false" 
                            class="flex items-center gap-2 text-sm font-bold bg-slate-50 hover:bg-slate-100 border border-slate-100 rounded-lg text-[#0f2044] py-1.5 px-3 cursor-pointer transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                        <span x-text="value === 'live' ? 'LIVE' : value.charAt(0).toUpperCase() + value.slice(1)"></span>
                        <i class="ph-bold ph-caret-down text-slate-400 text-xs transition-transform duration-200" :class="open ? 'rotate-180' : ''"></i>
                    </button>
                </div>

                <div x-show="open" 
                     x-transition.opacity.duration.200ms
                     class="absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-xl shadow-[0_10px_40px_rgba(0,0,0,0.08)] overflow-hidden z-50"
                     style="display: none;">
                    
                    <?php
                    $statuses = [
                        'draft' => ['icon' => 'ph-pencil', 'label' => 'Draft', 'color' => 'text-slate-500'],
                        'ready' => ['icon' => 'ph-check-circle', 'label' => 'Ready', 'color' => 'text-yellow-500'],
                        'live' => ['icon' => 'ph-broadcast', 'label' => 'LIVE', 'color' => 'text-red-500'],
                        'completed' => ['icon' => 'ph-flag-checkered', 'label' => 'Completed', 'color' => 'text-emerald-500'],
                        'archived' => ['icon' => 'ph-archive', 'label' => 'Archived', 'color' => 'text-slate-700']
                    ];
                    foreach ($statuses as $sKey => $sVal):
                    ?>
                        <button type="button" 
                                @click="value = '<?= $sKey ?>'; open = false; document.getElementById('statusForm').submit();"
                                class="w-full text-left px-4 py-3 text-sm font-bold flex items-center gap-3 hover:bg-slate-50 transition-colors <?= $tournament['status'] === $sKey ? 'bg-slate-50 text-[#0f2044]' : 'text-slate-600' ?>">
                            <i class="ph-bold <?= $sVal['icon'] ?> <?= $sVal['color'] ?> text-lg"></i>
                            <?= $sVal['label'] ?>
                            <?php if ($tournament['status'] === $sKey): ?>
                                <i class="ph-bold ph-check text-blue-500 ml-auto"></i>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>

        <!-- Custom Modal Delete Trigger -->
        <button type="button" 
                @click="showDeleteModal = true"
                class="bg-red-50 hover:bg-red-600 hover:text-white text-red-600 border border-red-200 px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm" 
                title="Delete Tournament">
            <i class="ph-bold ph-trash text-sm"></i> <span>Delete</span>
        </button>
    </div>
</div>

<!-- ======================================================= -->
<!-- CUSTOM HIGH-END DELETE CONFIRMATION MODAL               -->
<!-- ======================================================= -->
<div x-show="showDeleteModal" 
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4"
     style="display: none;"
     @keydown.escape.window="showDeleteModal = false">
    
    <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl border border-slate-100 relative"
         @click.away="showDeleteModal = false"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95">
        
        <div class="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mx-auto mb-5 border border-red-100 shadow-inner">
            <i class="ph-bold ph-warning-octagon text-3xl animate-bounce"></i>
        </div>

        <h3 class="text-2xl font-black font-display text-slate-900 text-center mb-2">Delete Tournament?</h3>
        <p class="text-slate-500 text-sm text-center leading-relaxed mb-6">
            Are you sure you want to delete <strong class="text-slate-900"><?= e($tournament['name']) ?></strong>? This action is permanent and will delete all associated matches, brackets, player scores, and draws.
        </p>

        <form action="" method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_tournament">

            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 px-6 rounded-xl shadow-lg shadow-red-600/30 transition flex items-center justify-center gap-2 text-sm">
                <i class="ph-bold ph-trash text-lg"></i>
                <span>Yes, Delete Tournament</span>
            </button>

            <button type="button" 
                    @click="showDeleteModal = false" 
                    class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 px-6 rounded-xl transition text-sm">
                Cancel
            </button>
        </form>
    </div>
</div>

<?= flash_html('tournament_view') ?>
<?php if (!empty($error)): ?>
    <div class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 text-sm border border-red-200 flex items-center gap-2">
        <i class="ph-fill ph-warning-circle text-red-500 text-lg"></i>
        <span class="font-bold"><?= e($error) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left Column: Settings -->
    <div class="lg:col-span-2 space-y-6">
        
        <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-black text-[#0f2044] flex items-center gap-2">
                    <i class="ph-fill ph-faders text-[#c9a84c] text-xl"></i>
                    Scoring Rules (Per Round)
                </h3>
                <span class="text-xs font-bold uppercase tracking-wider text-blue-700 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200">Dynamic Rules</span>
            </div>
            <div class="p-0 overflow-x-auto">
                <form action="view.php?id=<?= $id ?>" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_rules">
                    
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead>
                            <tr class="text-xs uppercase tracking-widest text-slate-400 border-b border-slate-100 bg-white">
                                <th class="px-6 py-4 font-bold text-left">Round</th>
                                <th class="px-4 py-4 font-bold text-center">Match Format (Games)</th>
                                <th class="px-4 py-4 font-bold text-center">Pts/Game</th>
                                <th class="px-4 py-4 font-bold text-center">Deuce At</th>
                                <th class="px-4 py-4 font-bold text-center">Score Cap</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($rounds as $r): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="px-6 py-4 font-bold text-slate-700">
                                        <?= e($r['round_label']) ?>
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        <select name="rounds[<?= $r['id'] ?>][best_of]" 
                                                class="w-full min-w-[200px] bg-slate-50 border border-slate-200 text-slate-800 text-xs font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 py-2 px-3">
                                            <option value="1" <?= (int)$r['best_of'] === 1 ? 'selected' : '' ?>>⚡ 1 Single Game</option>
                                            <option value="3" <?= (int)$r['best_of'] === 3 ? 'selected' : '' ?>>🏸 Best of 3 Games (First to 2)</option>
                                            <option value="5" <?= (int)$r['best_of'] === 5 ? 'selected' : '' ?>>🏆 Best of 5 Games (First to 3)</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-4">
                                        <input type="number" name="rounds[<?= $r['id'] ?>][points]" value="<?= $r['points_per_game'] ?>" 
                                               class="w-20 mx-auto block bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center py-2 px-3" min="5" max="40" required>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-center justify-center gap-2">
                                            <input type="number" name="rounds[<?= $r['id'] ?>][deuce_trigger]" value="<?= $r['deuce_trigger'] ?>" 
                                                   class="w-20 bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center py-2 px-3" min="5" max="40" required>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <input type="number" name="rounds[<?= $r['id'] ?>][deuce_cap]" value="<?= $r['deuce_cap'] ?>" 
                                               class="w-20 mx-auto block bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center py-2 px-3" min="5" max="40" required>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="px-6 py-5 border-t border-slate-100 bg-slate-50/80 flex justify-end">
                        <button type="submit" class="bg-[#0f2044] hover:bg-blue-900 text-white font-bold py-2.5 px-6 rounded-xl shadow-md transition-all flex items-center gap-2">
                            <i class="ph-bold ph-floppy-disk"></i> Save Rules
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- Right Column: Stats & Actions -->
    <div class="space-y-6">
        
        <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden text-center relative overflow-hidden">
            <!-- decorative bg -->
            <div class="absolute -top-10 -right-10 opacity-5">
                <i class="ph-fill ph-users text-[150px] text-slate-900"></i>
            </div>
            
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex justify-center items-center">
                <h3 class="font-black text-[#0f2044] flex items-center gap-2 relative z-10">
                    <i class="ph-bold ph-users text-blue-500 text-xl"></i>
                    Registered Players
                </h3>
            </div>
            <div class="px-6 py-10 relative z-10">
                <?php
                $pCount = db()->query("SELECT COUNT(*) FROM tournament_players WHERE tournament_id = $id")->fetchColumn();
                ?>
                <div class="text-6xl font-black text-[#0f2044] mb-2 tracking-tighter">
                    <?= $pCount ?>
                </div>
                <div class="text-slate-400 font-bold uppercase tracking-widest text-xs mb-8">Players Enrolled</div>
                
                <a href="players.php?id=<?= $id ?>" class="w-full text-center block bg-white border-2 border-slate-200 hover:border-[#0f2044] hover:text-[#0f2044] text-slate-600 font-bold py-3 px-4 rounded-xl transition-all mb-2">
                    Manage Players
                </a>
                
                <?php if ($tournament['match_type'] === 'doubles'): ?>
                    <?php $tCount = db()->query("SELECT COUNT(*) FROM teams WHERE tournament_id = $id")->fetchColumn(); ?>
                    <a href="teams.php?id=<?= $id ?>" class="w-full text-center block bg-gradient-to-r from-[#0f2044] to-[#1e3a8a] hover:from-[#1a365d] hover:to-[#2563eb] text-[#c9a84c] font-bold py-3 px-4 rounded-xl shadow-lg transition-all mt-3 border border-transparent flex items-center justify-center gap-2">
                        <i class="ph-bold ph-users-three text-lg"></i> Manage Teams (<?= $tCount ?>)
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ======================================================= -->
        <!-- LUXURY MATCH GENERATION & PAIRINGS CARD                 -->
        <!-- ======================================================= -->
        <div class="bg-white border border-slate-200 rounded-3xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-[#0f2044] to-[#1e3a8a] text-[#c9a84c] flex items-center justify-center shadow-md">
                        <i class="ph-fill ph-shuffle text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-[#0f2044]">Match Generation</h3>
                        <p class="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Draws & Pairings Engine</p>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <?php
                require_once __DIR__ . '/../../includes/matchmaker.php';
                $pCount = (int)db()->query("SELECT COUNT(*) FROM tournament_players WHERE tournament_id = $id")->fetchColumn();
                
                if ($tournament['format'] === 'pools_knockout') {
                    echo '<div class="text-center py-6 bg-purple-50/60 rounded-2xl border border-purple-100 mb-5 p-4">';
                    echo '<div class="w-12 h-12 rounded-2xl bg-purple-100 text-purple-600 flex items-center justify-center mx-auto mb-2.5 shadow-xs"><i class="ph-fill ph-columns text-2xl"></i></div>';
                    echo '<h4 class="font-black text-purple-950 text-base">Group Stage (Pools)</h4>';
                    echo '<p class="text-xs font-medium text-purple-700/80 mt-1">Manage pool allocations and generate round-robin fixtures.</p>';
                    echo '</div>';
                    echo '<a href="pools.php?id='.$id.'" class="group relative w-full bg-gradient-to-r from-purple-700 to-indigo-800 hover:from-purple-800 hover:to-indigo-900 text-white p-4 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-between text-left">';
                    echo '<div class="flex items-center gap-3.5"><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center text-purple-200"><i class="ph-bold ph-columns text-xl"></i></div><div><div class="font-black text-sm text-white">Manage Pools Studio</div><div class="text-[11px] text-purple-200 font-medium">Assign players & generate group draws</div></div></div>';
                    echo '<i class="ph-bold ph-arrow-right text-purple-200 group-hover:translate-x-1 transition-transform"></i>';
                    echo '</a>';
                }
                elseif ($tournament['format'] === 'round_robin') {
                    $hasLeagueMatches = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id")->fetchColumn() > 0;
                    if ($hasLeagueMatches) {
                        echo '<div class="text-center py-6 bg-emerald-50/60 rounded-2xl border border-emerald-100 mb-5 p-4">';
                        echo '<div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-2.5 shadow-xs"><i class="ph-fill ph-check-circle text-2xl"></i></div>';
                        echo '<h4 class="font-black text-emerald-950 text-base">League Fixtures Active</h4>';
                        echo '<p class="text-xs font-medium text-emerald-700/80 mt-1">All round-robin fixtures are generated and live.</p>';
                        echo '</div>';
                        echo '<a href="'.BASE_URL.'/admin/scoring/index.php?tournament_id='.$id.'" class="group w-full bg-gradient-to-r from-emerald-600 to-teal-700 hover:from-emerald-700 hover:to-teal-800 text-white p-4 rounded-2xl shadow-lg hover:shadow-xl transition-all flex items-center justify-between text-left">';
                        echo '<div class="flex items-center gap-3.5"><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center text-emerald-200"><i class="ph-bold ph-broadcast text-xl"></i></div><div><div class="font-black text-sm text-white">Score League Matches</div><div class="text-[11px] text-emerald-100 font-medium">Live scorekeeper console & court desk</div></div></div>';
                        echo '<i class="ph-bold ph-arrow-right text-emerald-200 group-hover:translate-x-1 transition-transform"></i>';
                        echo '</a>';
                    } else {
                        echo '<div class="text-center py-6 bg-emerald-50/60 rounded-2xl border border-emerald-100 mb-5 p-4">';
                        echo '<div class="w-12 h-12 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-2.5 shadow-xs"><i class="ph-fill ph-table text-2xl"></i></div>';
                        echo '<h4 class="font-black text-emerald-950 text-base">Round Robin League</h4>';
                        echo '<p class="text-xs font-medium text-emerald-700/80 mt-1">Every player/team plays against all other participants.</p>';
                        echo '</div>';
                        if ($pCount >= 2) {
                            echo '<form action="generate.php" method="POST">';
                            echo csrf_field();
                            echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                            echo '<input type="hidden" name="action" value="generate_league">';
                            echo '<button type="submit" class="group w-full bg-gradient-to-r from-emerald-600 to-teal-700 hover:from-emerald-700 hover:to-teal-800 text-white p-4 rounded-2xl shadow-lg hover:shadow-xl transition-all flex items-center justify-between text-left">';
                            echo '<div class="flex items-center gap-3.5"><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center text-emerald-200"><i class="ph-bold ph-lightning text-xl"></i></div><div><div class="font-black text-sm text-white">Generate League Fixtures</div><div class="text-[11px] text-emerald-100 font-medium">Auto-generate all round robin fixtures</div></div></div>';
                            echo '<i class="ph-bold ph-arrow-right text-emerald-200 group-hover:translate-x-1 transition-transform"></i>';
                            echo '</button>';
                            echo '</form>';
                        } else {
                            echo '<div class="bg-red-50 border border-red-200 rounded-2xl p-4 text-red-700 text-xs font-bold text-center">Enroll at least 2 participants to generate fixtures.</div>';
                        }
                    }
                }
                else {
                    $hasR1 = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND round_key = '" . ROUND_STAGE1_R1 . "'")->fetchColumn() > 0;
                    $hasR2 = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND round_key = '" . ROUND_STAGE1_R2 . "'")->fetchColumn() > 0;
                    
                    if (!$hasR1) {
                        if ($pCount >= 4) {
                            echo '<div class="bg-emerald-50/80 border border-emerald-200 rounded-2xl p-4 mb-5 text-emerald-900 text-xs font-bold flex items-center gap-3 shadow-xs">';
                            echo '<div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0"><i class="ph-fill ph-check-circle text-lg"></i></div>';
                            echo '<div><div class="font-black text-emerald-950 text-sm">'.$pCount.' players enrolled</div><div class="text-emerald-700/80 font-medium mt-0.5">Ready to pair Round 1!' . ($pCount % 2 !== 0 ? ' (1 player gets a BYE)' : '') . '</div></div>';
                            echo '</div>';
                            
                            echo '<div class="space-y-3">';
                            // LUXURY PRIMARY BUTTON: MANUAL PAIRING STUDIO
                            echo '<a href="pairings.php?id='.$id.'&round=r1" class="group relative w-full bg-gradient-to-r from-[#0f2044] to-[#1e3a8a] hover:from-[#0a1630] hover:to-[#172e6e] text-white p-4 rounded-2xl shadow-lg hover:shadow-2xl border border-white/10 hover:border-[#c9a84c]/50 transition-all duration-200 flex items-center justify-between text-left hover:-translate-y-0.5">';
                            echo '<div class="flex items-center gap-3.5">';
                            echo '<div class="w-11 h-11 rounded-xl bg-[#c9a84c]/20 border border-[#c9a84c]/30 flex items-center justify-center text-[#c9a84c] shadow-inner group-hover:scale-105 transition-transform"><i class="ph-bold ph-sliders-horizontal text-xl"></i></div>';
                            echo '<div>';
                            echo '<div class="font-black text-sm text-white tracking-tight flex items-center gap-2">Custom Manual Pairing Studio <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-[#c9a84c] text-[#0f2044]">Pro</span></div>';
                            echo '<div class="text-[11px] text-blue-200/80 font-medium mt-0.5">Drag-and-drop custom seeds & matchups</div>';
                            echo '</div>';
                            echo '</div>';
                            echo '<i class="ph-bold ph-arrow-right text-[#c9a84c] group-hover:translate-x-1.5 transition-transform"></i>';
                            echo '</a>';

                            // LUXURY SECONDARY BUTTON: 1-CLICK RANDOM DRAW
                            echo '<form action="generate.php" method="POST">';
                            echo csrf_field();
                            echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                            echo '<input type="hidden" name="action" value="generate_r1">';
                            echo '<button type="submit" class="w-full bg-slate-50 hover:bg-white text-slate-700 hover:text-slate-900 font-bold p-3.5 rounded-2xl border-2 border-slate-200 hover:border-slate-300 transition-all duration-150 text-xs flex items-center justify-center gap-2 shadow-xs group">';
                            echo '<i class="ph-bold ph-shuffle text-slate-400 group-hover:text-blue-600 transition-colors text-base"></i>';
                            echo '<span class="font-black">Quick 1-Click Random Draw</span>';
                            echo '</button>';
                            echo '</form>';
                            echo '</div>';
                        } else {
                            echo '<div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-4 text-red-700 text-xs font-bold flex items-center gap-3">';
                            echo '<i class="ph-fill ph-warning-circle text-red-500 text-2xl flex-shrink-0"></i>';
                            echo '<div><div class="font-black text-sm">Need at least 4 players</div><div class="text-red-600/80 mt-0.5">Currently enrolled: '.$pCount.' players</div></div>';
                            echo '</div>';
                            echo '<button class="w-full bg-slate-100 text-slate-400 font-bold py-3.5 px-4 rounded-2xl cursor-not-allowed text-xs" disabled>Generate Round 1</button>';
                        }
                    } 
                    elseif (!$hasR2) {
                        $r1Done = Matchmaker::isRoundComplete($id, ROUND_STAGE1_R1);
                        if ($r1Done) {
                            echo '<div class="bg-emerald-50/80 border border-emerald-200 rounded-2xl p-4 mb-5 text-emerald-900 text-xs font-bold flex items-center gap-3 shadow-xs">';
                            echo '<div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0"><i class="ph-fill ph-check-circle text-lg"></i></div>';
                            echo '<div><div class="font-black text-emerald-950 text-sm">Round 1 Finished!</div><div class="text-emerald-700/80 font-medium mt-0.5">Winners (1-0) and Losers (0-1) bracket ready</div></div>';
                            echo '</div>';
                            
                            echo '<div class="space-y-3">';
                            echo '<a href="pairings.php?id='.$id.'&round=r2" class="group relative w-full bg-gradient-to-r from-[#0f2044] to-[#1e3a8a] hover:from-[#0a1630] hover:to-[#172e6e] text-white p-4 rounded-2xl shadow-lg hover:shadow-2xl border border-white/10 hover:border-[#c9a84c]/50 transition-all duration-200 flex items-center justify-between text-left hover:-translate-y-0.5">';
                            echo '<div class="flex items-center gap-3.5">';
                            echo '<div class="w-11 h-11 rounded-xl bg-[#c9a84c]/20 border border-[#c9a84c]/30 flex items-center justify-center text-[#c9a84c] shadow-inner group-hover:scale-105 transition-transform"><i class="ph-bold ph-sliders-horizontal text-xl"></i></div>';
                            echo '<div>';
                            echo '<div class="font-black text-sm text-white tracking-tight flex items-center gap-2">Custom Manual Pairing Studio <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-[#c9a84c] text-[#0f2044]">Round 2</span></div>';
                            echo '<div class="text-[11px] text-blue-200/80 font-medium mt-0.5">Pair 1-0 vs 1-0 and 0-1 vs 0-1 players</div>';
                            echo '</div>';
                            echo '</div>';
                            echo '<i class="ph-bold ph-arrow-right text-[#c9a84c] group-hover:translate-x-1.5 transition-transform"></i>';
                            echo '</a>';

                            echo '<form action="generate.php" method="POST">';
                            echo csrf_field();
                            echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                            echo '<input type="hidden" name="action" value="generate_r2">';
                            echo '<button type="submit" class="w-full bg-slate-50 hover:bg-white text-slate-700 hover:text-slate-900 font-bold p-3.5 rounded-2xl border-2 border-slate-200 hover:border-slate-300 transition-all duration-150 text-xs flex items-center justify-center gap-2 shadow-xs group">';
                            echo '<i class="ph-bold ph-shuffle text-slate-400 group-hover:text-blue-600 transition-colors text-base"></i>';
                            echo '<span class="font-black">Quick Auto-Pair (W vs W, L vs L)</span>';
                            echo '</button>';
                            echo '</form>';
                            echo '</div>';
                        } else {
                            echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4 text-blue-900 text-xs font-bold flex items-center gap-3">';
                            echo '<i class="ph-fill ph-hourglass-medium text-blue-500 text-2xl flex-shrink-0 animate-spin"></i>';
                            echo '<div><div class="font-black text-sm">Round 1 in Progress</div><div class="text-blue-700/80 mt-0.5">Round 2 pairing unlocks when all matches finish</div></div>';
                            echo '</div>';
                            echo '<button class="w-full bg-slate-100 text-slate-400 font-bold py-3.5 px-4 rounded-2xl cursor-not-allowed text-xs" disabled>Pair Round 2</button>';
                        }
                    }
                    else {
                        $hasSurvival = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND round_key = '" . ROUND_STAGE1_SURVIVAL . "'")->fetchColumn() > 0;
                        if (!$hasSurvival) {
                            $r2Done = Matchmaker::isRoundComplete($id, ROUND_STAGE1_R2);
                            if ($r2Done) {
                                echo '<div class="bg-emerald-50/80 border border-emerald-200 rounded-2xl p-4 mb-5 text-emerald-900 text-xs font-bold flex items-center gap-3 shadow-xs">';
                                echo '<div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0"><i class="ph-fill ph-check-circle text-lg"></i></div>';
                                echo '<div><div class="font-black text-emerald-950 text-sm">Round 2 Finished!</div><div class="text-emerald-700/80 font-medium mt-0.5">Time to pair the 1-1 survival players</div></div>';
                                echo '</div>';
                                
                                echo '<a href="survival.php?id='.$id.'" class="group relative w-full bg-gradient-to-r from-[#c9a84c] to-[#b0923e] hover:from-[#b0923e] hover:to-[#997d2e] text-[#0f2044] p-4 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-200 flex items-center justify-between text-left hover:-translate-y-0.5 font-black">';
                                echo '<div class="flex items-center gap-3.5">';
                                echo '<div class="w-11 h-11 rounded-xl bg-white/30 flex items-center justify-center text-[#0f2044] shadow-inner"><i class="ph-bold ph-shield-check text-xl"></i></div>';
                                echo '<div>';
                                echo '<div class="text-sm font-black tracking-tight">Configure Survival Round</div>';
                                echo '<div class="text-[11px] text-[#0f2044]/80 font-bold mt-0.5">Cross-path pairing studio (16 &times; 1-1 players)</div>';
                                echo '</div>';
                                echo '</div>';
                                echo '<i class="ph-bold ph-arrow-right text-[#0f2044] group-hover:translate-x-1.5 transition-transform"></i>';
                                echo '</a>';
                            } else {
                                echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4 text-blue-900 text-xs font-bold flex items-center gap-3">';
                                echo '<i class="ph-fill ph-hourglass-medium text-blue-500 text-2xl flex-shrink-0"></i>';
                                echo '<div><div class="font-black text-sm">Round 2 in Progress</div><div class="text-blue-700/80 mt-0.5">Survival Round unlocks after matches complete</div></div>';
                                echo '</div>';
                                echo '<button class="w-full bg-slate-100 text-slate-400 font-bold py-3.5 px-4 rounded-2xl cursor-not-allowed text-xs" disabled>Configure Survival Round</button>';
                            }
                        } else {
                            $hasStage2 = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND stage = 'stage2'")->fetchColumn() > 0;
                            if (!$hasStage2) {
                                $survDone = Matchmaker::isRoundComplete($id, ROUND_STAGE1_SURVIVAL);
                                if ($survDone) {
                                    echo '<div class="bg-emerald-50/80 border border-emerald-200 rounded-2xl p-4 mb-5 text-emerald-900 text-xs font-bold flex items-center gap-3 shadow-xs">';
                                    echo '<div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center flex-shrink-0"><i class="ph-fill ph-check-circle text-lg"></i></div>';
                                    echo '<div><div class="font-black text-emerald-950 text-sm">Qualifiers Complete!</div><div class="text-emerald-700/80 font-medium mt-0.5">Top 16 players ready for Stage 2 Knockouts</div></div>';
                                    echo '</div>';
                                    
                                    echo '<form action="generate.php" method="POST">';
                                    echo csrf_field();
                                    echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                                    echo '<input type="hidden" name="action" value="generate_stage2">';
                                    echo '<button type="submit" class="group relative w-full bg-gradient-to-r from-slate-900 via-[#0f2044] to-slate-900 hover:from-black hover:to-black text-white p-4 rounded-2xl shadow-xl hover:shadow-2xl transition-all duration-200 flex items-center justify-between text-left hover:-translate-y-0.5 border border-white/10">';
                                    echo '<div class="flex items-center gap-3.5">';
                                    echo '<div class="w-11 h-11 rounded-xl bg-[#c9a84c]/20 border border-[#c9a84c]/30 flex items-center justify-center text-[#c9a84c] shadow-inner"><i class="ph-bold ph-trophy text-xl"></i></div>';
                                    echo '<div>';
                                    echo '<div class="font-black text-sm text-white tracking-tight flex items-center gap-2">Generate Stage 2 Knockout Bracket <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-[#c9a84c] text-[#0f2044]">Finals</span></div>';
                                    echo '<div class="text-[11px] text-slate-300 font-medium mt-0.5">R16, QF, SF, 3rd Place & Grand Final</div>';
                                    echo '</div>';
                                    echo '</div>';
                                    echo '<i class="ph-bold ph-arrow-right text-[#c9a84c] group-hover:translate-x-1.5 transition-transform"></i>';
                                    echo '</button>';
                                    echo '</form>';
                                } else {
                                    echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4 text-blue-900 text-xs font-bold flex items-center gap-3">';
                                    echo '<i class="ph-fill ph-hourglass-medium text-blue-500 text-2xl flex-shrink-0"></i>';
                                    echo '<div><div class="font-black text-sm">Survival Round Active</div><div class="text-blue-700/80 mt-0.5">Stage 2 unlocks after all survival matches finish</div></div>';
                                    echo '</div>';
                                    echo '<button class="w-full bg-slate-100 text-slate-400 font-bold py-3.5 px-4 rounded-2xl cursor-not-allowed text-xs" disabled>Generate Stage 2 Bracket</button>';
                                }
                            } else {
                                echo '<div class="text-center py-6 bg-slate-50 rounded-2xl border border-slate-200 mb-4">';
                                echo '<div class="w-14 h-14 rounded-2xl bg-[#c9a84c]/15 text-[#c9a84c] flex items-center justify-center mx-auto mb-2 shadow-inner"><i class="ph-fill ph-trophy text-3xl"></i></div>';
                                echo '<h4 class="font-black text-[#0f2044] text-lg">Knockout Brackets Active</h4>';
                                echo '<p class="text-xs font-medium text-slate-500 mt-1">Stage 2 Single-Elimination fixtures are live.</p>';
                                echo '</div>';
                                echo '<a href="'.BASE_URL.'/admin/scoring/index.php?tournament_id='.$id.'" class="group w-full bg-[#0f2044] hover:bg-blue-900 text-white font-black p-3.5 rounded-2xl shadow-md hover:shadow-xl transition-all flex items-center justify-between text-xs">';
                                echo '<div class="flex items-center gap-2.5"><i class="ph-bold ph-broadcast text-base text-[#c9a84c]"></i><span>Score Stage 2 Matches</span></div>';
                                echo '<i class="ph-bold ph-arrow-right text-[#c9a84c] group-hover:translate-x-1 transition-transform"></i>';
                                echo '</a>';
                            }
                        }
                    }
                } // End of else for swiss_knockout
                ?>
            </div>
        </div>

    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
