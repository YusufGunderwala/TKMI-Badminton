<?php
// ============================================================
// Sponsors - List
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Manage Sponsors';

// Handle Deletion
if (isset($_POST['delete_id'])) {
    if (!verify_csrf()) {
        flash_set('sponsor', 'Invalid security token.', 'error');
    } else {
        $id = (int) $_POST['delete_id'];
        // Get image path to delete file
        $stmt = db()->prepare('SELECT image_path FROM sponsors WHERE id = ?');
        $stmt->execute([$id]);
        $sponsor = $stmt->fetch();
        if ($sponsor) {
            deleteUpload(__DIR__ . '/../../uploads/sponsors/' . $sponsor['image_path']);
            db()->prepare('DELETE FROM sponsors WHERE id = ?')->execute([$id]);
            AppCache::flush();
            flash_set('sponsor', 'Sponsor deleted successfully.', 'success');
        }
    }
    header('Location: index.php');
    exit;
}

// Fetch all sponsors
$sponsors = db()->query('SELECT * FROM sponsors ORDER BY created_at DESC')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

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
        <h2 class="text-2xl sm:text-3xl font-black font-display text-[#0f2044]">Sponsors Management</h2>
        <p class="text-xs sm:text-sm text-slate-500 font-medium mt-1">Platform-wide sponsors shown in footers and live scoreboards.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/sponsors/add.php" class="bg-[#c9a84c] hover:bg-[#b0923e] text-[#0f2044] px-5 sm:px-6 py-2.5 rounded-xl font-black transition shadow-md flex items-center gap-2 text-xs sm:text-sm">
        <i class="ph-bold ph-plus-circle text-base sm:text-lg"></i> Add Sponsor
    </a>
</div>

<?= flash_html('sponsor') ?>

<div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[550px]">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase tracking-widest text-slate-400 font-bold">
                    <th class="p-5 pl-8 w-24">Logo</th>
                    <th class="p-5">Sponsor Name</th>
                    <th class="p-5">Added On</th>
                    <th class="p-5 pr-8 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($sponsors)): ?>
                <tr>
                    <td colspan="4" class="p-12 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-50 text-slate-300 mb-4">
                            <i class="ph-fill ph-handshake text-3xl"></i>
                        </div>
                        <p class="text-slate-500 font-medium">No sponsors added yet.</p>
                        <a href="<?= BASE_URL ?>/admin/sponsors/add.php" class="text-blue-600 font-bold mt-2 inline-block">Add your first sponsor &rarr;</a>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($sponsors as $s): ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="p-5 pl-8">
                            <div class="w-16 h-16 bg-white border border-slate-200 rounded-xl p-2 shadow-sm flex items-center justify-center">
                                <img src="<?= BASE_URL ?>/uploads/sponsors/<?= e($s['image_path']) ?>" alt="Logo" class="max-w-full max-h-full object-contain">
                            </div>
                        </td>
                        <td class="p-5">
                            <div class="font-bold text-slate-800 text-lg"><?= e($s['name']) ?></div>
                        </td>
                        <td class="p-5">
                            <div class="text-sm font-medium text-slate-500">
                                <?= date('M j, Y', strtotime($s['created_at'])) ?>
                            </div>
                        </td>
                        <td class="p-5 pr-8 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="<?= BASE_URL ?>/admin/sponsors/edit.php?id=<?= $s['id'] ?>" 
                                   class="inline-flex items-center justify-center px-3.5 py-2 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-colors font-bold text-sm shadow-sm gap-1.5"
                                   title="Edit Sponsor">
                                    <i class="ph-bold ph-pencil-simple text-base"></i> Edit
                                </a>
                                <button type="button" 
                                        @click="openDelete(<?= $s['id'] ?>, '<?= addslashes(e($s['name'])) ?>')"
                                        class="inline-flex items-center justify-center px-3.5 py-2 rounded-xl bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors font-bold text-sm shadow-sm gap-1.5"
                                        title="Delete Sponsor">
                                    <i class="ph-bold ph-trash text-base"></i> Delete
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
            <i class="ph-bold ph-trash text-3xl animate-bounce"></i>
        </div>

        <h3 class="text-2xl font-black font-display text-slate-900 text-center mb-2">Delete Sponsor?</h3>
        <p class="text-slate-500 text-sm text-center leading-relaxed mb-6">
            Are you sure you want to remove <strong class="text-slate-900" x-text="deleteName"></strong>? Their logo will be removed from all public footers and scoreboards.
        </p>

        <form action="index.php" method="POST" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="delete_id" :value="deleteId">

            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 px-6 rounded-xl shadow-lg shadow-red-600/30 transition flex items-center justify-center gap-2 text-sm">
                <i class="ph-bold ph-trash text-lg"></i>
                <span>Yes, Delete Sponsor</span>
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
