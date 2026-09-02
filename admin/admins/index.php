<?php
// ============================================================
// Admins - Manage Accounts
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireSuperAdmin(); // Only super admins should manage other admins

$pageTitle = 'Manage Admins';
$currentAdminUser = currentAdmin();
$pdo = db();

// Handle Admin Actions (Create, Delete, Update Password)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('admins', 'Invalid security token.', 'error');
        header('Location: index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_admin') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $displayName = trim($_POST['display_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $isSuper = isset($_POST['is_super_admin']) ? 1 : 0;

        if (empty($username) || empty($displayName) || empty($password)) {
            flash_set('admins', 'All fields are required.', 'error');
        } elseif (strlen($password) < 6) {
            flash_set('admins', 'Password must be at least 6 characters.', 'error');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash, display_name, is_super_admin) VALUES (?, ?, ?, ?)');
                $stmt->execute([$username, $hash, $displayName, $isSuper]);
                flash_set('admins', "Admin account '$displayName' created successfully!", 'success');
            } catch (PDOException $e) {
                if ($e->getCode() == 23505) {
                    flash_set('admins', "An admin with username '$username' already exists.", 'error');
                } else {
                    flash_set('admins', 'Database error: ' . $e->getMessage(), 'error');
                }
            }
        }
    }
    elseif ($action === 'delete_admin') {
        $targetId = (int)($_POST['admin_id'] ?? 0);
        if ($targetId === $currentAdminUser['id']) {
            flash_set('admins', 'You cannot delete your own account.', 'error');
        } else {
            $stmt = $pdo->prepare('DELETE FROM admins WHERE id = ?');
            $stmt->execute([$targetId]);
            flash_set('admins', 'Admin account deleted.', 'success');
        }
    }
    elseif ($action === 'change_password') {
        $targetId = (int)($_POST['admin_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 6) {
            flash_set('admins', 'Password must be at least 6 characters.', 'error');
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $targetId]);
            flash_set('admins', 'Password updated successfully!', 'success');
        }
    }

    header('Location: index.php');
    exit;
}

$stmt = $pdo->query('SELECT id, username, display_name, is_super_admin, created_at FROM admins ORDER BY id ASC');
$admins = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div x-data="{ 
    showAddModal: false, 
    showPassModal: false, 
    showDeleteModal: false,
    passAdminId: 0, 
    passAdminName: '',
    deleteAdminId: 0,
    deleteAdminName: ''
}">

    <div class="flex flex-col md:flex-row items-center justify-between mb-8 gap-4">
        <div>
            <h1 class="text-3xl font-black text-[#0f2044] tracking-tight flex items-center gap-3">
                <i class="ph-fill ph-shield-check text-[#c9a84c]"></i> Manage Administrators
            </h1>
            <p class="text-slate-500 font-medium mt-1">Manage users who have access to the TKMI Badminton Platform.</p>
        </div>
        <button @click="showAddModal = true" class="bg-[#c9a84c] hover:bg-[#b0923e] text-[#0f2044] px-6 py-2.5 rounded-xl font-black transition shadow-md flex items-center gap-2">
            <i class="ph-bold ph-user-plus text-lg"></i> Add New Admin
        </button>
    </div>

    <?= flash_html('admins') ?>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-[10px] uppercase tracking-widest text-slate-400 font-bold">
                        <th class="p-5 pl-8">Admin Name</th>
                        <th class="p-5">Username</th>
                        <th class="p-5">Role</th>
                        <th class="p-5">Created</th>
                        <th class="p-5 pr-8 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($admins as $a): ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="p-5 pl-8">
                            <div class="font-bold text-slate-800 text-base"><?= e($a['display_name']) ?></div>
                        </td>
                        <td class="p-5 font-mono text-sm text-slate-600 font-bold">
                            <?= e($a['username']) ?>
                        </td>
                        <td class="p-5">
                            <?php if ($a['is_super_admin']): ?>
                                <span class="px-2.5 py-1 rounded bg-[#c9a84c]/20 text-[#0f2044] text-[10px] font-black uppercase tracking-widest border border-[#c9a84c]/30">Super Admin</span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 rounded bg-slate-100 text-slate-500 text-[10px] font-bold uppercase tracking-widest border border-slate-200">Admin</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-5 text-xs text-slate-400 font-medium">
                            <?= date('M j, Y', strtotime($a['created_at'])) ?>
                        </td>
                        <td class="p-5 pr-8 text-right space-x-2">
                            <button @click="passAdminId = <?= $a['id'] ?>; passAdminName = '<?= e($a['display_name']) ?>'; showPassModal = true;" title="Change Password" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-600 hover:bg-amber-100 hover:text-amber-700 transition-colors">
                                <i class="ph-bold ph-key"></i>
                            </button>
                            <?php if ($a['id'] !== $currentAdminUser['id']): ?>
                                <button type="button" 
                                        @click="deleteAdminId = <?= $a['id'] ?>; deleteAdminName = '<?= addslashes(e($a['display_name'])) ?>'; showDeleteModal = true;" 
                                        title="Delete Admin" 
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors">
                                    <i class="ph-bold ph-trash"></i>
                                </button>
                            <?php else: ?>
                                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">You</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Add New Admin -->
    <div x-show="showAddModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl border border-slate-200" @click.away="showAddModal = false">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-black font-display text-[#0f2044]">Add New Admin</h3>
                <button @click="showAddModal = false" class="text-slate-400 hover:text-slate-600">
                    <i class="ph-bold ph-x text-xl"></i>
                </button>
            </div>
            
            <form action="index.php" method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_admin">
                
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Full Name / Display Name</label>
                    <div class="relative">
                        <i class="ph-bold ph-identification-card absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none"></i>
                        <input type="text" name="display_name" required placeholder="e.g. Ali Asgar" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl pl-10 pr-3 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Username (Login ID)</label>
                    <div class="relative">
                        <i class="ph-bold ph-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none"></i>
                        <input type="text" name="username" required placeholder="e.g. aliasgar" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl pl-10 pr-3 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Password</label>
                    <div class="relative">
                        <i class="ph-bold ph-lock-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none"></i>
                        <input type="password" name="password" required minlength="6" placeholder="Min 6 characters" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl pl-10 pr-3 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="pt-2">
                    <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 hover:bg-slate-50 transition">
                        <input type="checkbox" name="is_super_admin" value="1" class="w-4 h-4 text-blue-600 rounded">
                        <div>
                            <span class="block text-sm font-bold text-slate-800">Grant Super Admin Privileges</span>
                            <span class="block text-xs text-slate-400">Can manage other admin accounts and full platform settings.</span>
                        </div>
                    </label>
                </div>

                <div class="pt-4 flex justify-end gap-3 border-t border-slate-100">
                    <button type="button" @click="showAddModal = false" class="px-5 py-2.5 text-slate-500 font-bold hover:text-slate-700 text-sm">Cancel</button>
                    <button type="submit" class="bg-[#0f2044] hover:bg-blue-900 text-white font-bold px-6 py-2.5 rounded-xl shadow-md transition text-sm">Create Admin</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Change Password -->
    <div x-show="showPassModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl border border-slate-200" @click.away="showPassModal = false">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-black font-display text-[#0f2044]">Reset Password</h3>
                <button @click="showPassModal = false" class="text-slate-400 hover:text-slate-600">
                    <i class="ph-bold ph-x text-xl"></i>
                </button>
            </div>
            
            <p class="text-sm text-slate-500 mb-4">Set a new password for <strong class="text-slate-800" x-text="passAdminName"></strong>.</p>
            
            <form action="index.php" method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="admin_id" :value="passAdminId">
                
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">New Password</label>
                    <div class="relative">
                        <i class="ph-bold ph-lock-key absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none"></i>
                        <input type="password" name="new_password" required minlength="6" placeholder="Min 6 characters" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-xl pl-10 pr-3 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="pt-4 flex justify-end gap-3 border-t border-slate-100">
                    <button type="button" @click="showPassModal = false" class="px-5 py-2.5 text-slate-500 font-bold hover:text-slate-700 text-sm">Cancel</button>
                    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold px-6 py-2.5 rounded-xl shadow-md transition text-sm">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Delete Admin Confirmation -->
    <div x-show="showDeleteModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-2xl border border-slate-200" @click.away="showDeleteModal = false">
            <div class="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mx-auto mb-5 border border-red-100 shadow-inner">
                <i class="ph-bold ph-warning-octagon text-3xl"></i>
            </div>
            <h3 class="text-2xl font-black font-display text-slate-900 text-center mb-2">Delete Admin Account?</h3>
            <p class="text-slate-500 text-sm text-center leading-relaxed mb-6">
                Are you sure you want to remove <strong class="text-slate-900" x-text="deleteAdminName"></strong>? They will immediately lose access to the tournament management system.
            </p>
            <form action="index.php" method="POST" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_admin">
                <input type="hidden" name="admin_id" :value="deleteAdminId">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3.5 px-6 rounded-xl shadow-lg shadow-red-600/30 transition text-sm">
                    Yes, Delete Admin Account
                </button>
                <button type="button" @click="showDeleteModal = false" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-3 px-6 rounded-xl transition text-sm">
                    Cancel
                </button>
            </form>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
