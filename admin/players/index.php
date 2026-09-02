<?php
// ============================================================
// Players - List & Management (with Custom Modal Delete)
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pdo = db();

// Handle Delete Player
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_player') {
    if (verify_csrf()) {
        $playerId = (int)($_POST['player_id'] ?? 0);
        if ($playerId > 0) {
            try {
                $pdo->beginTransaction();
                $pNameStmt = $pdo->prepare('SELECT display_name FROM players WHERE id = ?');
                $pNameStmt->execute([$playerId]);
                $pName = $pNameStmt->fetchColumn() ?: 'Player';

                // Remove matches / tournament links
                $pdo->prepare('DELETE FROM score_events WHERE match_id IN (SELECT id FROM matches WHERE participant_a_id = ? OR participant_b_id = ?)')->execute([$playerId, $playerId]);
                $pdo->prepare('DELETE FROM games WHERE match_id IN (SELECT id FROM matches WHERE participant_a_id = ? OR participant_b_id = ?)')->execute([$playerId, $playerId]);
                $pdo->prepare('DELETE FROM matches WHERE participant_a_id = ? OR participant_b_id = ?')->execute([$playerId, $playerId]);
                $pdo->prepare('DELETE FROM player_tournament_records WHERE player_id = ?')->execute([$playerId]);
                $pdo->prepare('DELETE FROM tournament_players WHERE player_id = ?')->execute([$playerId]);
                $pdo->prepare('DELETE FROM group_participants WHERE player_id = ?')->execute([$playerId]);
                $pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$playerId]);

                $pdo->commit();
                AppCache::flush();
                flash_set('players', "Player \"{$pName}\" deleted successfully.", 'success');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash_set('players', 'Error deleting player: ' . $e->getMessage(), 'error');
            }
        }
    }
    header('Location: ' . BASE_URL . '/admin/players/index.php');
    exit;
}

$pageTitle = 'Manage Players';

// Fetch all players
$stmt = $pdo->prepare('SELECT * FROM players ORDER BY created_at DESC');
$stmt->execute();
$players = $stmt->fetchAll();

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

    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 sm:mb-8 gap-4">
        <div>
            <h2 class="text-2xl sm:text-3xl font-black font-display text-[#0f2044]">Players Directory</h2>
            <p class="text-xs sm:text-sm text-slate-500 font-medium mt-1">Manage all registered players in the TKMI community.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5 sm:gap-3 w-full sm:w-auto">
            <a href="<?= BASE_URL ?>/admin/players/import.php" class="bg-white border-2 border-slate-200 text-slate-700 hover:border-[#c9a84c] hover:text-[#c9a84c] px-3.5 sm:px-4 py-2 sm:py-2.5 rounded-xl font-bold transition flex items-center gap-2 text-xs sm:text-sm">
                <i class="ph-bold ph-upload-simple text-base sm:text-lg"></i> Bulk Import
            </a>
            <a href="<?= BASE_URL ?>/admin/players/add.php" class="bg-[#c9a84c] hover:bg-[#b0923e] text-[#0f2044] px-4 sm:px-6 py-2 sm:py-2.5 rounded-xl font-black transition shadow-md flex items-center gap-2 text-xs sm:text-sm">
                <i class="ph-bold ph-user-plus text-base sm:text-lg"></i> Add Player
            </a>
        </div>
    </div>

    <?= flash_html('players') ?>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase tracking-widest text-slate-400 font-bold">
                        <th class="p-5 pl-8 w-16">Photo</th>
                        <th class="p-5">ITS ID</th>
                        <th class="p-5">Name & Display</th>
                        <th class="p-5">Mohallah</th>
                        <th class="p-5">Contact</th>
                        <th class="p-5 pr-8 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($players)): ?>
                    <tr>
                        <td colspan="6" class="p-12 text-center">
                            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-50 text-slate-300 mb-4">
                                <i class="ph-fill ph-users text-3xl"></i>
                            </div>
                            <p class="text-slate-500 font-medium">No players registered yet.</p>
                            <a href="<?= BASE_URL ?>/admin/players/add.php" class="text-blue-600 font-bold mt-2 inline-block">Register the first player &rarr;</a>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($players as $p): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="p-5 pl-8">
                                <?php if (!empty($p['photo_path'])): ?>
                                    <img src="<?= BASE_URL ?>/uploads/players/<?= e($p['photo_path']) ?>" alt="Photo" class="w-10 h-10 rounded-full object-cover shadow-sm border border-slate-200">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 border border-slate-200">
                                        <i class="ph-fill ph-user text-xl"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="p-5 font-mono text-sm font-bold text-slate-600">
                                <?= e($p['its_id']) ?>
                            </td>
                            <td class="p-5">
                                <div class="font-bold text-slate-800 text-base"><?= e($p['full_name']) ?></div>
                                <div class="text-xs text-slate-500 font-medium mt-0.5 border border-slate-200 bg-slate-50 rounded px-2 py-0.5 inline-block">
                                    <?= e($p['display_name']) ?>
                                </div>
                            </td>
                            <td class="p-5">
                                <div class="text-sm text-slate-700 font-bold flex items-center gap-1.5">
                                    <i class="ph-fill ph-map-pin text-[#c9a84c]"></i> <?= e($p['mohallah']) ?>
                                </div>
                            </td>
                            <td class="p-5">
                                <div class="text-sm font-medium text-slate-600 flex items-center gap-1.5">
                                    <i class="ph-fill ph-whatsapp-logo text-emerald-500"></i> <?= e($p['whatsapp']) ?>
                                </div>
                            </td>
                            <td class="p-5 pr-8 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="<?= BASE_URL ?>/admin/players/edit.php?id=<?= $p['id'] ?>" 
                                       class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-colors font-bold text-sm shadow-sm" 
                                       title="Edit Player Details & Photo">
                                        <i class="ph-bold ph-pencil-simple text-base"></i>
                                    </a>
                                    <button type="button" 
                                            @click="openDelete(<?= $p['id'] ?>, '<?= addslashes(e($p['display_name'])) ?>')"
                                            class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors font-bold text-sm shadow-sm" 
                                            title="Delete Player">
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
                <i class="ph-bold ph-user-minus text-3xl animate-bounce"></i>
            </div>

            <h3 class="text-2xl font-black font-display text-slate-900 text-center mb-2">Delete Player?</h3>
            <p class="text-slate-500 text-sm text-center leading-relaxed mb-6">
                Are you sure you want to permanently remove player <strong class="text-slate-900" x-text="deleteName"></strong>? This will remove the player and their tournament records.
            </p>

            <form action="" method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_player">
                <input type="hidden" name="player_id" :value="deleteId">

                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 px-6 rounded-xl shadow-lg shadow-red-600/30 transition flex items-center justify-center gap-2 text-sm">
                    <i class="ph-bold ph-trash text-lg"></i>
                    <span>Yes, Delete Player</span>
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
