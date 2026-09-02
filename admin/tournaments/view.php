<?php
// ============================================================
// Tournaments - View & Configure
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/matchmaker.php';

requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tournament = getTournament($id);

if (!$tournament) {
    flash_set('tournaments', 'Tournament not found.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/');
    exit;
}

$pageTitle = 'Manage: ' . $tournament['name'];


// Handle Delete Tournament Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tournament') {
    if (verify_csrf()) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $tName = $tournament['name'] ?? 'Tournament';

            // Delete the tournament. ON DELETE CASCADE will instantly wipe all related records.
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


$manifest = json_decode($tournament['structure_manifest'] ?? '{}', true);
// (Round configs are now generated explicitly during Lock Enrollment step)

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (verify_csrf()) {
        $new_status = $_POST['status'] ?? '';
        if (in_array($new_status, ['draft', 'enrollment_locked', 'structure_ready', 'rules_locked', 'ready', 'live', 'completed', 'archived'])) {
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
        case 'enrollment_locked': return 'bg-blue-100 text-blue-700 border border-blue-200';
        case 'structure_ready': return 'bg-indigo-100 text-indigo-700 border border-indigo-200';
        case 'rules_locked': return 'bg-violet-100 text-violet-700 border border-violet-200';
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
                <?= str_replace('_', ' ', strtoupper($tournament['status'])) ?>
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
                
                <?php
                    $statuses = [
                        'draft' => ['icon' => 'ph-pencil', 'label' => 'Draft', 'color' => 'text-slate-500'],
                        'enrollment_locked' => ['icon' => 'ph-lock-key', 'label' => 'Enrollment Locked', 'color' => 'text-slate-600'],
                        'structure_ready' => ['icon' => 'ph-tree-structure', 'label' => 'Structure Ready', 'color' => 'text-blue-500'],
                        'rules_locked' => ['icon' => 'ph-lock-key', 'label' => 'Rules Locked', 'color' => 'text-slate-600'],
                        'ready' => ['icon' => 'ph-check-circle', 'label' => 'Ready to Start', 'color' => 'text-yellow-500'],
                        'live' => ['icon' => 'ph-broadcast', 'label' => 'LIVE', 'color' => 'text-red-500'],
                        'completed' => ['icon' => 'ph-flag-checkered', 'label' => 'Completed', 'color' => 'text-emerald-500'],
                        'archived' => ['icon' => 'ph-archive', 'label' => 'Archived', 'color' => 'text-slate-700']
                    ];
                    $currentLabel = isset($statuses[$tournament['status']]) ? $statuses[$tournament['status']]['label'] : ucfirst($tournament['status']);
                ?>
                <div class="bg-white border border-slate-200 p-1 rounded-xl shadow-sm flex items-center">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest pl-3 pr-2">State</span>
                    <button type="button" @click="open = !open" @click.away="open = false" 
                            class="flex items-center gap-2 text-sm font-bold bg-slate-50 hover:bg-slate-100 border border-slate-100 rounded-lg text-[#0f2044] py-1.5 px-3 cursor-pointer transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                        <span><?= e($currentLabel) ?></span>
                        <i class="ph-bold ph-caret-down text-slate-400 text-xs transition-transform duration-200" :class="open ? 'rotate-180' : ''"></i>
                    </button>
                </div>

                <div x-show="open" 
                     x-transition.opacity.duration.200ms
                     class="absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-xl shadow-[0_10px_40px_rgba(0,0,0,0.08)] overflow-hidden z-50"
                     style="display: none;">
                    
                    <?php
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
        
        <?php if (in_array($tournament['status'], ['draft', 'enrollment_locked'])): ?>
            <div class="bg-white border border-slate-200 rounded-3xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] p-10 text-center flex flex-col items-center">
                <div class="w-16 h-16 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center mb-4">
                    <i class="ph-fill ph-lock-key text-3xl"></i>
                </div>
                <h3 class="font-black text-2xl text-[#0f2044] tracking-tight mb-2">Scoring Rules are Locked</h3>
                <p class="text-slate-500 text-sm max-w-lg mb-8 leading-relaxed">
                    Because this tournament is dynamic, the system doesn't know how many rounds to create until you finish adding players. Follow these steps to unlock the scoring rules:
                </p>
                
                <div class="flex flex-col gap-3 text-left w-full max-w-md">
                    <div class="flex items-start gap-4 p-4 rounded-2xl bg-blue-50/50 border border-blue-100">
                        <div class="w-8 h-8 rounded-full bg-blue-600 text-white font-black flex items-center justify-center flex-shrink-0 text-sm shadow-md">1</div>
                        <div>
                            <h4 class="font-bold text-blue-950 text-sm">Enroll Participants</h4>
                            <p class="text-blue-800/70 text-xs mt-0.5">Click "Manage Players" (or Teams) on the right to add your roster.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-4 p-4 rounded-2xl bg-blue-50/50 border border-blue-100">
                        <div class="w-8 h-8 rounded-full bg-blue-600 text-white font-black flex items-center justify-center flex-shrink-0 text-sm shadow-md">2</div>
                        <div>
                            <h4 class="font-bold text-blue-950 text-sm">Lock Enrollment</h4>
                            <p class="text-blue-800/70 text-xs mt-0.5">Click the dark blue "Lock Enrollment" button on the right to generate the math.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-100 opacity-60 grayscale">
                        <div class="w-8 h-8 rounded-full bg-slate-300 text-slate-600 font-black flex items-center justify-center flex-shrink-0 text-sm">3</div>
                        <div>
                            <h4 class="font-bold text-slate-700 text-sm">Configure Rules</h4>
                            <p class="text-slate-500 text-xs mt-0.5">This section will unlock automatically for you to set points-per-game.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: 
            $stmtRounds = db()->prepare('SELECT * FROM round_configs WHERE tournament_id = ? ORDER BY id ASC');
            $stmtRounds->execute([$id]);
            $rounds = $stmtRounds->fetchAll();
        ?>
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
                                <th class="px-4 py-4 font-bold text-center">Match Format</th>
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
                                                class="w-32 mx-auto block bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 py-2 px-3 cursor-pointer" 
                                                <?= $tournament['status'] !== 'structure_ready' ? 'disabled' : '' ?>>
                                            <option value="1" <?= $r['best_of'] == 1 ? 'selected' : '' ?>>Best of 1</option>
                                            <option value="3" <?= $r['best_of'] == 3 ? 'selected' : '' ?>>Best of 3</option>
                                            <option value="5" <?= $r['best_of'] == 5 ? 'selected' : '' ?>>Best of 5</option>
                                        </select>
                                    </td>
                                    <td class="px-4 py-4">
                                        <input type="number" name="rounds[<?= $r['id'] ?>][points]" value="<?= $r['points_per_game'] ?>" 
                                               class="w-20 mx-auto block bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center py-2 px-3" min="5" max="40" required <?= $tournament['status'] !== 'structure_ready' ? 'disabled' : '' ?>>
                                    </td>
                                    <td class="px-4 py-4">
                                        <input type="number" name="rounds[<?= $r['id'] ?>][deuce_trigger]" value="<?= $r['deuce_trigger'] ?>" 
                                               class="w-20 mx-auto block bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center py-2 px-3" min="5" max="40" required <?= $tournament['status'] !== 'structure_ready' ? 'disabled' : '' ?>>
                                    </td>
                                    <td class="px-4 py-4">
                                        <input type="number" name="rounds[<?= $r['id'] ?>][deuce_cap]" value="<?= $r['deuce_cap'] ?>" 
                                               class="w-20 mx-auto block bg-slate-50 border border-slate-200 text-slate-800 text-sm font-bold rounded-lg focus:ring-blue-500 focus:border-blue-500 text-center py-2 px-3" min="5" max="40" required <?= $tournament['status'] !== 'structure_ready' ? 'disabled' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <?php if ($tournament['status'] === 'structure_ready'): ?>
                    <div class="px-6 py-5 border-t border-slate-100 bg-slate-50/80 flex justify-end gap-3">
                        <button type="submit" class="bg-blue-100 hover:bg-blue-200 text-blue-800 font-bold py-2.5 px-6 rounded-xl shadow-sm transition-all flex items-center gap-2">
                            <i class="ph-bold ph-floppy-disk"></i> Save Rules
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($manifest) && isset($manifest['participants'])): ?>
        <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                <h3 class="font-black text-[#0f2044] flex items-center gap-2">
                    <i class="ph-fill ph-git-branch text-[#c9a84c] text-xl"></i>
                    Tournament Architecture Map
                </h3>
            </div>
            <div class="p-8 overflow-x-auto">
                <div class="flex flex-col items-center min-w-[500px]">
                    
                    <!-- Root -->
                    <div class="bg-slate-800 text-white font-black px-6 py-3 rounded-2xl shadow-lg border border-slate-700 text-center relative z-10 w-64">
                        <div class="text-xs uppercase tracking-widest text-slate-400 mb-1">Starting Roster</div>
                        <?= $manifest['participants'] ?> Participants
                    </div>
                    
                    <?php if (isset($manifest['stage_1'])): 
                        // Map Mathematics
                        $n = $manifest['participants'];
                        
                        // Round 1
                        $r1_matches = floor($n / 2);
                        $r1_byes = $n % 2;
                        
                        // Round 2
                        $w_pool = $r1_matches + $r1_byes; // Winners + BYE recipient
                        $l_pool = $r1_matches;            // Losers
                        
                        $r2_w_matches = floor($w_pool / 2);
                        $r2_w_byes = $w_pool % 2;
                        
                        $r2_l_matches = floor($l_pool / 2);
                        $r2_l_byes = $l_pool % 2;
                        
                        // Survival
                        // 1-1 players = losers of R2 Winners Pool + winners of R2 Losers Pool + byes of R2 Losers pool
                        $s_pool = $r2_w_matches + ($r2_l_matches + $r2_l_byes);
                        $s_matches = floor($s_pool / 2);
                        $s_byes = $s_pool % 2;
                        
                        // Qualifiers output text for paths
                        $out_2_0 = $r2_w_matches + $r2_w_byes;
                        $out_0_2 = $r2_l_matches;
                        $out_2_1 = $s_matches + $s_byes;
                        $out_1_2 = $s_matches;
                    ?>
                        <!-- Down arrow -->
                        <div class="h-8 w-px bg-slate-300 border-l-2 border-dashed border-slate-300 -my-0.5"></div>
                        
                        <!-- Stage 1 Block -->
                        <div class="bg-blue-50 border border-blue-200 rounded-3xl p-6 w-full max-w-2xl text-center relative mx-auto">
                            <h4 class="font-black text-blue-900 mb-6 uppercase tracking-wider text-sm flex justify-center items-center gap-2">
                                <i class="ph-bold ph-strategy text-blue-500"></i> Stage 1: Swiss Qualifiers
                            </h4>
                            
                            <!-- Round 1 -->
                            <div class="flex flex-col items-center mb-0 relative z-10">
                                <div class="text-[10px] font-black tracking-widest text-blue-400 mb-1">ROUND 1</div>
                                <div class="bg-white border border-blue-200 rounded-xl px-6 py-2 text-center shadow-sm">
                                    <div class="text-sm font-bold text-blue-900"><?= $n ?> Participants Enter</div>
                                    <div class="text-[10px] text-blue-500 uppercase tracking-widest mt-0.5 font-bold">
                                        <?= $r1_matches ?> Matches <?= $r1_byes > 0 ? '+ ' . $r1_byes . ' BYE' : '' ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Split to R2 -->
                            <div class="flex w-full max-w-sm mx-auto h-8 relative">
                                <div class="w-1/2 border-b-2 border-l-2 border-blue-200 rounded-bl-xl h-full translate-x-1/2 relative">
                                    <span class="absolute bottom-1 -left-12 text-[9px] font-bold text-emerald-600 bg-blue-50 px-1"><?= $w_pool ?> Players (1-0)</span>
                                </div>
                                <div class="w-1/2 border-b-2 border-r-2 border-blue-200 rounded-br-xl h-full -translate-x-1/2 relative">
                                    <span class="absolute bottom-1 -right-12 text-[9px] font-bold text-red-600 bg-blue-50 px-1"><?= $l_pool ?> Players (0-1)</span>
                                </div>
                            </div>
                            
                            <!-- Round 2 -->
                            <div class="flex justify-between max-w-lg mx-auto relative pt-2">
                                <div class="absolute left-1/2 top-0 -translate-x-1/2 -translate-y-4 text-[10px] font-black tracking-widest text-blue-400 bg-blue-50 px-2 z-10">ROUND 2</div>
                                
                                <!-- Winners Path -->
                                <div class="flex flex-col items-center w-1/2 px-3 relative z-10">
                                    <span class="text-[10px] uppercase tracking-wider font-bold text-emerald-600 mb-1">1-0 Winners Group</span>
                                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2 w-full text-center shadow-sm">
                                        <div class="text-xs font-bold text-emerald-800"><?= $w_pool ?> Players</div>
                                        <div class="text-[9px] text-emerald-600 uppercase tracking-widest mt-0.5 font-bold">
                                            <?= $r2_w_matches ?> Matches <?= $r2_w_byes > 0 ? '+ ' . $r2_w_byes . ' BYE' : '' ?>
                                        </div>
                                    </div>
                                    <!-- Split to R3 -->
                                    <div class="flex w-full h-10 relative">
                                        <div class="w-1/2 border-l-2 border-emerald-200 h-full border-dashed mt-0"></div>
                                        <div class="w-1/2 border-r-2 border-b-2 border-blue-200 rounded-br-xl h-6 mt-0 relative">
                                            <span class="absolute top-1 -right-12 text-[9px] font-bold text-blue-500 bg-blue-50 px-1 text-right leading-tight whitespace-nowrap"><?= $r2_w_matches ?> Players<br>are (1-1)</span>
                                        </div>
                                        <span class="absolute top-4 left-0 -translate-x-6 text-[9px] font-bold text-emerald-600 bg-blue-50 px-1 text-center leading-tight">
                                            <?= $out_2_0 ?> Players<br>2-0 (Tier 1)
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Losers Path -->
                                <div class="flex flex-col items-center w-1/2 px-3 relative z-10">
                                    <span class="text-[10px] uppercase tracking-wider font-bold text-red-600 mb-1">0-1 Losers Group</span>
                                    <div class="bg-red-50 border border-red-200 rounded-xl px-3 py-2 w-full text-center shadow-sm">
                                        <div class="text-xs font-bold text-red-800"><?= $l_pool ?> Players</div>
                                        <div class="text-[9px] text-red-600 uppercase tracking-widest mt-0.5 font-bold">
                                            <?= $r2_l_matches ?> Matches <?= $r2_l_byes > 0 ? '+ ' . $r2_l_byes . ' BYE' : '' ?>
                                        </div>
                                    </div>
                                    <!-- Split to R3 -->
                                    <div class="flex w-full h-10 relative">
                                        <div class="w-1/2 border-l-2 border-b-2 border-blue-200 rounded-bl-xl h-6 mt-0 relative">
                                            <span class="absolute top-1 -left-12 text-[9px] font-bold text-blue-500 bg-blue-50 px-1 text-left leading-tight whitespace-nowrap"><?= $r2_l_matches + $r2_l_byes ?> Players<br>are (1-1)</span>
                                        </div>
                                        <div class="w-1/2 border-r-2 border-red-200 h-full border-dashed mt-0"></div>
                                        <span class="absolute top-4 right-0 translate-x-10 text-[9px] font-bold text-red-500 bg-blue-50 px-1 text-center leading-tight">
                                            <?= $out_0_2 ?> Players<br>0-2 (Eliminated)
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Survival Merge -->
                            <div class="flex flex-col items-center -mt-4 relative z-10">
                                <div class="w-px h-4 bg-blue-300 mb-1"></div>
                                <div class="text-[10px] font-black tracking-widest text-blue-500 mb-1">SURVIVAL ROUND</div>
                                <div class="bg-white border-2 border-blue-400 rounded-xl px-6 py-2 text-center shadow-md min-w-[200px]">
                                    <div class="text-sm font-bold text-blue-900"><?= $s_pool ?> Players Enter</div>
                                    <div class="text-[10px] text-blue-500 uppercase tracking-widest mt-0.5 font-bold">
                                        <?= $s_matches ?> Matches <?= $s_byes > 0 ? '+ ' . $s_byes . ' BYE' : '' ?>
                                    </div>
                                </div>
                                <!-- Split to End -->
                                <div class="flex w-full max-w-sm h-10 relative">
                                    <div class="w-1/2 border-l-2 border-emerald-200 h-full border-dashed"></div>
                                    <div class="w-1/2 border-r-2 border-red-200 h-full border-dashed"></div>
                                    <span class="absolute top-4 left-1/4 -translate-x-8 text-[9px] font-bold text-emerald-600 bg-blue-50 px-1 text-center leading-tight">
                                        <?= $out_2_1 ?> Players<br>2-1 (Tier 2)
                                    </span>
                                    <span class="absolute top-4 right-1/4 translate-x-8 text-[9px] font-bold text-red-500 bg-blue-50 px-1 text-center leading-tight">
                                        <?= $out_1_2 ?> Players<br>1-2 (Eliminated)
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Down arrow to Stage 2 -->
                        <div class="h-10 w-px bg-slate-300 border-l-2 border-dashed border-slate-300 -my-0.5 relative">
                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white px-2 py-0.5 text-xs font-bold text-slate-500 border border-slate-200 rounded-full whitespace-nowrap z-10 shadow-sm">
                                <?= $manifest['stage_1']['expected_qualifiers'] ?> Advance
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($manifest['stage_2'])): ?>
                        <!-- Stage 2 Block -->
                        <div class="bg-[#c9a84c]/10 border border-[#c9a84c]/30 rounded-3xl p-6 w-full max-w-2xl text-center">
                            <h4 class="font-black text-[#8a722f] mb-2 uppercase tracking-wider text-sm flex justify-center items-center gap-2">
                                <i class="ph-bold ph-target text-[#c9a84c]"></i> Stage 2: Single Elimination
                            </h4>
                            
                            <p class="text-xs text-[#8a722f]/80 font-bold mb-6 flex items-center justify-center gap-2">
                                <?= $manifest['stage_2']['bracket_size'] ?>-Player Bracket 
                                <?php if ($manifest['stage_2']['byes'] > 0): ?>
                                    <span class="bg-[#c9a84c]/20 px-2 py-0.5 rounded-full text-[#8a722f]"><?= $manifest['stage_2']['byes'] ?> Byes</span>
                                <?php endif; ?>
                            </p>
                            
                            <div class="flex flex-col items-center gap-3">
                                <?php 
                                    $s2_matches = [
                                        'r32' => '16 Matches',
                                        'r16' => '8 Matches',
                                        'qf' => '4 Matches',
                                        'sf' => '2 Matches',
                                        '3rd_place' => '1 Match (Bronze)',
                                        'final' => '1 Match (Gold)'
                                    ];
                                    
                                    // Filter out 3rd_place and final for linear rendering
                                    $linear_rounds = array_diff($manifest['stage_2']['rounds'], ['3rd_place', 'final']);
                                    $has_3rd = in_array('3rd_place', $manifest['stage_2']['rounds']);
                                    $has_final = in_array('final', $manifest['stage_2']['rounds']);
                                    
                                    foreach ($linear_rounds as $roundKey): 
                                        $matchText = $s2_matches[$roundKey] ?? '';
                                ?>
                                    <div class="flex flex-col items-center w-full max-w-xs relative z-10">
                                        <div class="bg-white border border-[#c9a84c]/30 rounded-xl px-4 py-2.5 text-sm font-bold text-[#8a722f] shadow-sm w-full flex justify-between items-center">
                                            <span><?= strtoupper(str_replace('_', ' ', $roundKey)) ?></span>
                                            <span class="text-[10px] text-[#c9a84c] uppercase tracking-wider font-black"><?= $matchText ?></span>
                                        </div>
                                    </div>
                                    <?php if (end($linear_rounds) !== $roundKey || $has_final): ?>
                                        <div class="h-6 w-px border-l-2 border-[#c9a84c]/30 border-dashed"></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <?php if ($has_final): ?>
                                    <?php if ($has_3rd): ?>
                                        <!-- Split for Final and 3rd Place -->
                                        <div class="flex w-full max-w-sm h-6 relative -mt-3">
                                            <div class="w-1/2 border-t-2 border-l-2 border-[#c9a84c]/30 rounded-tl-xl h-full border-dashed"></div>
                                            <div class="w-1/2 border-t-2 border-r-2 border-[#c9a84c]/30 rounded-tr-xl h-full border-dashed"></div>
                                        </div>
                                        <div class="flex justify-between w-full max-w-md mx-auto gap-4">
                                            <!-- 3rd Place -->
                                            <div class="w-1/2 flex flex-col items-center">
                                                <span class="text-[10px] uppercase font-bold text-[#8a722f] mb-1 tracking-wider">SF Losers</span>
                                                <div class="bg-orange-50 border border-orange-200 rounded-xl px-4 py-2.5 text-sm font-bold text-orange-800 shadow-sm w-full flex flex-col items-center">
                                                    <span>3RD PLACE</span>
                                                    <span class="text-[10px] text-orange-600/80 uppercase tracking-wider font-black mt-0.5"><?= $s2_matches['3rd_place'] ?></span>
                                                </div>
                                            </div>
                                            <!-- Final -->
                                            <div class="w-1/2 flex flex-col items-center">
                                                <span class="text-[10px] uppercase font-bold text-[#8a722f] mb-1 tracking-wider">SF Winners</span>
                                                <div class="bg-yellow-50 border border-yellow-300 rounded-xl px-4 py-2.5 text-sm font-bold text-yellow-800 shadow-sm w-full flex flex-col items-center">
                                                    <span>FINAL</span>
                                                    <span class="text-[10px] text-yellow-600/80 uppercase tracking-wider font-black mt-0.5"><?= $s2_matches['final'] ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Only Final -->
                                        <div class="flex flex-col items-center w-full max-w-xs relative z-10">
                                            <span class="text-[10px] uppercase font-bold text-[#8a722f] mb-1 tracking-wider">SF Winners</span>
                                            <div class="bg-yellow-50 border border-yellow-300 rounded-xl px-4 py-2.5 text-sm font-bold text-yellow-800 shadow-sm w-full flex flex-col items-center">
                                                <span>FINAL</span>
                                                <span class="text-[10px] text-yellow-600/80 uppercase tracking-wider font-black mt-0.5"><?= $s2_matches['final'] ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Down arrow to Champion -->
                        <div class="flex w-full max-w-md mx-auto relative h-8">
                            <?php if ($has_3rd): ?>
                                <div class="w-1/2 border-r-2 border-dashed border-[#c9a84c]/40 h-full invisible"></div>
                                <div class="w-1/2 border-l-2 border-dashed border-slate-300 h-full translate-x-1/4"></div>
                            <?php else: ?>
                                <div class="w-1/2 border-r-2 border-dashed border-slate-300 h-full"></div>
                                <div class="w-1/2"></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="bg-gradient-to-br from-yellow-400 to-yellow-600 text-white font-black px-8 py-4 rounded-2xl shadow-xl shadow-yellow-500/20 text-center relative z-10 w-64 border border-yellow-300 <?= $has_3rd ? 'translate-x-14' : '' ?>">
                            <i class="ph-fill ph-trophy text-3xl mb-1 text-yellow-100 drop-shadow-md"></i>
                            <div class="text-lg tracking-wide uppercase">Champion</div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
        <?php endif; ?>

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
        <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_8px_30px_rgba(0,0,0,0.04)] overflow-hidden relative group">
            <div class="absolute inset-0 bg-gradient-to-br from-blue-50/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"></div>
            
            <div class="p-6 relative z-10">
                <div class="flex items-center gap-4 mb-6">
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#0f2044] to-[#1e3a8a] text-white flex items-center justify-center shadow-lg shadow-blue-900/20">
                        <i class="ph-fill ph-magic-wand text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-xl text-slate-900 tracking-tight">Tournament Engine</h3>
                        <p class="text-sm font-medium text-slate-500">Manage structure & matchups</p>
                    </div>
                </div>

                <?php 
                if ($tournament['format'] === FORMAT_SWISS_KNOCKOUT) {
                    $tStatus = $tournament['status'];
                    
                    if ($tStatus === 'draft') {
                        echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-5 text-blue-900 text-xs font-bold flex items-center gap-3">';
                        echo '<i class="ph-fill ph-info text-blue-500 text-2xl flex-shrink-0"></i>';
                        echo '<div><div class="font-black text-sm">Draft Mode</div><div class="text-blue-700/80 mt-0.5">Enroll players and teams.</div></div>';
                        echo '</div>';
                        
                        echo '<form action="generate.php" method="POST">';
                        echo csrf_field();
                        echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                        echo '<input type="hidden" name="action" value="lock_enrollment">';
                        echo '<button type="submit" class="w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold p-3.5 rounded-2xl shadow-xl transition-all duration-150 text-sm flex items-center justify-center gap-2">';
                        echo '<i class="ph-bold ph-lock-key text-lg text-[#c9a84c]"></i>';
                        echo '<span>Lock Enrollment & Generate Structure</span>';
                        echo '</button>';
                        echo '</form>';
                        
                    } elseif ($tStatus === 'enrollment_locked') {
                        // Should theoretically not stay in this state long, but just in case
                        echo '<form action="generate.php" method="POST">';
                        echo csrf_field();
                        echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                        echo '<input type="hidden" name="action" value="lock_enrollment">';
                        echo '<button type="submit" class="w-full bg-[#c9a84c] text-[#0f2044] font-bold p-3.5 rounded-2xl shadow-xl transition-all text-sm flex items-center justify-center gap-2">';
                        echo '<span>Generate Tournament Math</span>';
                        echo '</button>';
                        echo '</form>';
                        
                    } elseif ($tStatus === 'structure_ready') {
                        echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-5 text-blue-900 text-xs font-bold flex items-center gap-3">';
                        echo '<i class="ph-fill ph-info text-blue-500 text-2xl flex-shrink-0"></i>';
                        echo '<div><div class="font-black text-sm">Structure Generated</div><div class="text-blue-700/80 mt-0.5">Please review the scoring rules on the left, then lock them to proceed.</div></div>';
                        echo '</div>';
                        
                        echo '<form action="generate.php" method="POST">';
                        echo csrf_field();
                        echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                        echo '<input type="hidden" name="action" value="lock_rules">';
                        echo '<button type="submit" class="w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold p-3.5 rounded-2xl shadow-xl transition-all duration-150 text-sm flex items-center justify-center gap-2">';
                        echo '<i class="ph-bold ph-lock-key text-lg text-[#c9a84c]"></i>';
                        echo '<span>Lock Scoring Rules</span>';
                        echo '</button>';
                        echo '</form>';
                        
                    } elseif ($tStatus === 'rules_locked') {
                        echo '<div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-5 text-emerald-900 text-xs font-bold flex items-center gap-3">';
                        echo '<i class="ph-fill ph-check-circle text-emerald-500 text-2xl flex-shrink-0"></i>';
                        echo '<div><div class="font-black text-sm">Ready to Start</div><div class="text-emerald-700/80 mt-0.5">Rules and players are locked. Ready to draw Round 1.</div></div>';
                        echo '</div>';
                        
                        echo '<form action="generate.php" method="POST" class="mb-3">';
                        echo csrf_field();
                        echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                        echo '<input type="hidden" name="action" value="generate_r1">';
                        echo '<button type="submit" class="w-full bg-gradient-to-r from-[#0f2044] to-[#1e3a8a] text-white font-black p-4 rounded-2xl shadow-xl transition-all text-sm flex items-center justify-center gap-2">';
                        echo '<i class="ph-bold ph-shuffle text-xl text-[#c9a84c]"></i>';
                        echo '<span>Auto-Generate Round 1 Matches</span>';
                        echo '</button>';
                        echo '</form>';

                        echo '<a href="pairings.php?id='.$id.'&round=r1" class="w-full block text-center bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold p-3 rounded-2xl shadow-sm transition-all text-sm flex items-center justify-center gap-2">';
                        echo '<i class="ph-bold ph-hand-pointing"></i>';
                        echo '<span>Manual Match Setup</span>';
                        echo '</a>';
                        
                    } elseif ($tStatus === 'live') {
                        // Dynamic engine: Check completion status based on structure manifest
                        $hasR2 = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND round_key = '" . ROUND_STAGE1_R2 . "'")->fetchColumn() > 0;
                        $hasSurvival = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND round_key = '" . ROUND_STAGE1_SURVIVAL . "'")->fetchColumn() > 0;
                        $hasStage2 = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $id AND stage = 'stage2'")->fetchColumn() > 0;
                        
                        if (!$hasR2) {
                            $r1Done = Matchmaker::isRoundComplete($id, ROUND_STAGE1_R1);
                            if ($r1Done) {
                                echo '<form action="generate.php" method="POST" class="mb-3">';
                                echo csrf_field();
                                echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                                echo '<input type="hidden" name="action" value="generate_r2">';
                                echo '<button type="submit" class="w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold p-4 rounded-2xl shadow-xl transition-all text-sm flex items-center justify-center gap-2">';
                                echo '<span>Auto-Generate Round 2 (1-0 vs 1-0, 0-1 vs 0-1)</span>';
                                echo '</button>';
                                echo '</form>';

                                echo '<a href="pairings.php?id='.$id.'&round=r2" class="w-full block text-center bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 font-bold p-3 rounded-2xl shadow-sm transition-all text-sm flex items-center justify-center gap-2">';
                                echo '<i class="ph-bold ph-hand-pointing"></i>';
                                echo '<span>Manual Match Setup (R2)</span>';
                                echo '</a>';
                            } else {
                                echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4 text-blue-900 text-xs font-bold text-center">';
                                echo 'Round 1 Matches in Progress';
                                echo '</div>';
                            }
                        } elseif (!$hasSurvival) {
                            $r2Done = Matchmaker::isRoundComplete($id, ROUND_STAGE1_R2);
                            if ($r2Done) {
                                echo '<a href="survival.php?id='.$id.'" class="block text-center w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold p-4 rounded-2xl shadow-xl transition-all text-sm">';
                                echo 'Configure Survival Round (1-1 players)';
                                echo '</a>';
                            } else {
                                echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4 text-blue-900 text-xs font-bold text-center">';
                                echo 'Round 2 Matches in Progress';
                                echo '</div>';
                            }
                        } elseif (!$hasStage2) {
                            $survDone = Matchmaker::isRoundComplete($id, ROUND_STAGE1_SURVIVAL);
                            if ($survDone) {
                                echo '<form action="generate.php" method="POST">';
                                echo csrf_field();
                                echo '<input type="hidden" name="tournament_id" value="'.$id.'">';
                                echo '<input type="hidden" name="action" value="generate_stage2">';
                                echo '<button type="submit" class="w-full bg-gradient-to-r from-slate-900 to-black text-white font-bold p-4 rounded-2xl shadow-xl transition-all text-sm flex items-center justify-center gap-2">';
                                echo '<i class="ph-bold ph-trophy text-[#c9a84c]"></i> Generate Stage 2 Knockouts';
                                echo '</button>';
                                echo '</form>';
                            } else {
                                echo '<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-4 text-blue-900 text-xs font-bold text-center">';
                                echo 'Survival Matches in Progress';
                                echo '</div>';
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
                } // End of else for swiss_knockout
                ?>
            </div>
        </div>

    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
