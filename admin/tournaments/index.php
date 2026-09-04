<?php
// ============================================================
// Tournaments - List & Management (with Custom Modal Delete)
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pdo = db();

// Handle Delete Tournament Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tournament') {
    if (verify_csrf()) {
        $tourneyId = (int)($_POST['tournament_id'] ?? 0);
        if ($tourneyId > 0) {
            try {
                $pdo->beginTransaction();

                // Fetch tournament name for flash message
                $stmt = $pdo->prepare('SELECT name FROM tournaments WHERE id = ?');
                $stmt->execute([$tourneyId]);
                $tName = $stmt->fetchColumn() ?: 'Tournament';

                // Delete the tournament. ON DELETE CASCADE handles related records instantly.
                $pdo->prepare('DELETE FROM tournaments WHERE id = ?')->execute([$tourneyId]);

                $pdo->commit();
                flash_set('tournaments', "Tournament \"{$tName}\" has been deleted successfully.", 'success');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash_set('tournaments', 'Error deleting tournament: ' . $e->getMessage(), 'error');
            }
        }
    }
    header('Location: ' . BASE_URL . '/admin/tournaments/index.php');
    exit;
}

$pageTitle = 'Manage Tournaments';
$tournaments = getAllTournaments();

include __DIR__ . '/../includes/header.php';
?>

<!-- Alpine Container with Custom Modal Controller -->
<div x-data="{
    showDeleteModal: false,
    deleteId: null,
    deleteName: '',
    openDelete(id, name) {
        this.deleteId = id;
        this.deleteName = name;
        this.showDeleteModal = true;
    }
}">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6 sm:mb-8">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-800 font-display">Tournaments</h1>
            <p class="text-xs sm:text-sm text-slate-500 font-medium mt-1">Manage active tournaments, view brackets, or create new divisions.</p>
        </div>
        <a href="<?= BASE_URL ?>/admin/tournaments/create.php" class="bg-[#c9a84c] hover:bg-[#b0923e] text-[#0f2044] px-5 sm:px-6 py-2.5 rounded-xl font-black transition shadow-md flex items-center gap-2 text-sm sm:text-base">
            <i class="ph-bold ph-plus-circle text-lg"></i> New Tournament
        </a>
    </div>

    <?= flash_html('tournaments') ?>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[640px]">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase tracking-widest text-slate-400 font-bold">
                        <th class="p-5 pl-8">Tournament Name</th>
                        <th class="p-5">Division</th>
                        <th class="p-5">Format</th>
                        <th class="p-5">Status</th>
                        <th class="p-5 pr-8 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($tournaments)): ?>
                    <tr>
                        <td colspan="5" class="p-12 text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-50 text-slate-300 mb-4">
                                <i class="ph-fill ph-trophy text-3xl"></i>
                            </div>
                            <p class="text-slate-500 font-medium">No tournaments created yet.</p>
                            <a href="<?= BASE_URL ?>/admin/tournaments/create.php" class="text-blue-600 font-bold mt-2 inline-block">Create your first one &rarr;</a>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($tournaments as $t): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="p-5 pl-8">
                                <div class="font-bold text-slate-800 text-lg"><?= e($t['name']) ?></div>
                                <div class="text-xs text-slate-400 mt-0.5">Created <?= timeAgo($t['created_at']) ?></div>
                            </td>
                            <td class="p-5">
                                <div class="flex items-center gap-1.5 text-slate-700 font-medium">
                                    <i class="ph-bold ph-users text-[#c9a84c]"></i> <?= e($t['gender']) ?> <?= ucfirst(e($t['match_type'])) ?>
                                </div>
                            </td>
                            <td class="p-5 text-sm">
                                <?php if ($t['format'] === 'custom_knockout'): ?>
                                    <span class="inline-flex items-center gap-1.5 font-bold text-purple-700">
                                        <i class="ph-bold ph-hand-pointing text-purple-600"></i> Custom + Knockout
                                    </span>
                                <?php elseif ($t['format'] === 'pools_knockout'): ?>
                                    <span class="inline-flex items-center gap-1.5 font-bold text-blue-700">
                                        <i class="ph-bold ph-columns text-blue-600"></i> Pools + Knockout
                                    </span>
                                <?php elseif ($t['format'] === 'round_robin'): ?>
                                    <span class="inline-flex items-center gap-1.5 font-bold text-slate-700">
                                        <i class="ph-bold ph-arrows-clockwise text-[#c9a84c]"></i> Round Robin
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 font-bold text-slate-700">
                                        <i class="ph-bold ph-strategy text-blue-600"></i> Swiss + Knockout
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="p-5">
                                <span class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider
                                    <?= $t['status'] === 'live' ? 'bg-red-50 text-red-600 border border-red-100' : '' ?>
                                    <?= $t['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : '' ?>
                                    <?= in_array($t['status'], ['draft', 'enrollment_locked', 'structure_ready', 'rules_locked', 'ready']) ? 'bg-blue-50 text-blue-600 border border-blue-100' : '' ?>
                                    <?= $t['status'] === 'archived' ? 'bg-slate-100 text-slate-600 border border-slate-200' : '' ?>
                                ">
                                    <?= str_replace('_', ' ', strtoupper($t['status'])) ?>
                                </span>
                            </td>
                            <td class="p-5 pr-8 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $t['id'] ?>" class="inline-flex items-center justify-center px-4 py-2 rounded-xl bg-blue-50 text-blue-700 hover:bg-[#0f2044] hover:text-white transition-colors font-bold text-sm shadow-sm">
                                        Manage
                                    </a>
                                    
                                    <!-- High-End Custom Modal Delete Trigger -->
                                    <button type="button" 
                                            @click="openDelete(<?= $t['id'] ?>, '<?= addslashes(e($t['name'])) ?>')"
                                            class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors font-bold text-sm shadow-sm" 
                                            title="Delete Tournament">
                                        <i class="ph-bold ph-trash text-base"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                Are you sure you want to permanently delete <strong class="text-slate-900" x-text="deleteName"></strong>? This will remove all associated matches, brackets, player scores, and draws.
            </p>

            <form action="" method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_tournament">
                <input type="hidden" name="tournament_id" :value="deleteId">

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

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
