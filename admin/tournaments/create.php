<?php
// ============================================================
// Tournaments - Create
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$pageTitle = 'Create Tournament';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash_set('tournaments', 'Invalid security token.', 'error');
        header('Location: create.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $matchType = $_POST['match_type'] ?? '';
    $format = !empty($_POST['format']) ? trim($_POST['format']) : 'swiss_knockout';
    $desc = trim($_POST['description'] ?? '');

    if (empty($name) || empty($gender) || empty($matchType)) {
        flash_set('tournaments', 'Please fill in all required fields.', 'error');
        header('Location: create.php');
        exit;
    }

    try {
        $stmt = db()->prepare('
            INSERT INTO tournaments (name, gender, match_type, format, status, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $tId = db()->lastInsertId();
        AppCache::flush();
        flash_set('tournaments', 'Tournament created! Next, configure scoring.', 'success');
        header('Location: view.php?id=' . $tId);
        exit;
    } catch (PDOException $e) {
        flash_set('tournaments', 'Database error: ' . $e->getMessage(), 'error');
        header('Location: create.php');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8">
    <a href="<?= BASE_URL ?>/admin/tournaments/index.php" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
        <i class="ph-bold ph-arrow-left"></i> Back to Tournaments
    </a>
    <h2 class="text-3xl font-black font-display text-[#0f2044]">Create Tournament</h2>
</div>

<?= flash_html('tournaments') ?>

<div class="bg-white border border-slate-200 rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden max-w-3xl">
    <form action="create.php" method="POST" class="p-8">
        <?= csrf_field() ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <div class="col-span-full">
                <label class="block text-sm font-bold text-slate-700 mb-2">Tournament Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" required placeholder="e.g. TKMI Boys Singles 2026" class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors">
            </div>

            <div class="col-span-full md:col-span-1">
                <label class="block text-sm font-bold text-slate-700 mb-2">Division <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="relative flex cursor-pointer rounded-lg border bg-white p-3 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-blue-500 has-[:checked]:ring-1 has-[:checked]:ring-blue-500 has-[:checked]:bg-blue-50 group items-center">
                        <input type="radio" name="gender" value="Boys" class="sr-only" required>
                        <!-- Explicit Radio Circle -->
                        <div class="mr-3 flex-shrink-0 h-4 w-4 rounded-full border-2 border-slate-300 flex items-center justify-center group-has-[:checked]:border-blue-600 group-has-[:checked]:bg-blue-600 transition-colors">
                            <div class="h-1.5 w-1.5 rounded-full bg-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity"></div>
                        </div>
                        <span class="flex flex-1 items-center gap-2">
                            <i class="ph-bold ph-gender-male text-blue-500 text-lg group-has-[:checked]:text-blue-700"></i>
                            <span class="block text-sm font-bold text-slate-700 group-has-[:checked]:text-blue-700">Boys</span>
                        </span>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border bg-white p-3 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-pink-500 has-[:checked]:ring-1 has-[:checked]:ring-pink-500 has-[:checked]:bg-pink-50 group items-center">
                        <input type="radio" name="gender" value="Girls" class="sr-only" required>
                        <div class="mr-3 flex-shrink-0 h-4 w-4 rounded-full border-2 border-slate-300 flex items-center justify-center group-has-[:checked]:border-pink-600 group-has-[:checked]:bg-pink-600 transition-colors">
                            <div class="h-1.5 w-1.5 rounded-full bg-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity"></div>
                        </div>
                        <span class="flex flex-1 items-center gap-2">
                            <i class="ph-bold ph-gender-female text-pink-500 text-lg group-has-[:checked]:text-pink-700"></i>
                            <span class="block text-sm font-bold text-slate-700 group-has-[:checked]:text-pink-700">Girls</span>
                        </span>
                    </label>
                </div>
            </div>
            
            <div class="col-span-full md:col-span-1">
                <label class="block text-sm font-bold text-slate-700 mb-2">Match Type <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="relative flex cursor-pointer rounded-lg border bg-white p-3 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-[#c9a84c] has-[:checked]:ring-1 has-[:checked]:ring-[#c9a84c] has-[:checked]:bg-[#c9a84c]/10 group items-center">
                        <input type="radio" name="match_type" value="singles" class="sr-only" required>
                        <div class="mr-3 flex-shrink-0 h-4 w-4 rounded-full border-2 border-slate-300 flex items-center justify-center group-has-[:checked]:border-yellow-600 group-has-[:checked]:bg-yellow-600 transition-colors">
                            <div class="h-1.5 w-1.5 rounded-full bg-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity"></div>
                        </div>
                        <span class="flex flex-1 items-center gap-2">
                            <i class="ph-bold ph-user text-[#c9a84c] text-lg group-has-[:checked]:text-yellow-700"></i>
                            <span class="block text-sm font-bold text-slate-700 group-has-[:checked]:text-yellow-700">Singles (1v1)</span>
                        </span>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border bg-white p-3 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-[#c9a84c] has-[:checked]:ring-1 has-[:checked]:ring-[#c9a84c] has-[:checked]:bg-[#c9a84c]/10 group items-center">
                        <input type="radio" name="match_type" value="doubles" class="sr-only" required>
                        <div class="mr-3 flex-shrink-0 h-4 w-4 rounded-full border-2 border-slate-300 flex items-center justify-center group-has-[:checked]:border-yellow-600 group-has-[:checked]:bg-yellow-600 transition-colors">
                            <div class="h-1.5 w-1.5 rounded-full bg-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity"></div>
                        </div>
                        <span class="flex flex-1 items-center gap-2">
                            <i class="ph-bold ph-users text-[#c9a84c] text-lg group-has-[:checked]:text-yellow-700"></i>
                            <span class="block text-sm font-bold text-slate-700 group-has-[:checked]:text-yellow-700">Doubles (2v2)</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="col-span-full">
                <label class="block text-sm font-bold text-slate-700 mb-2">Tournament Format</label>
                <input type="hidden" name="format" value="swiss_knockout">
                
                <div class="bg-slate-50 border border-slate-200/80 rounded-2xl p-5 flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center font-bold text-2xl flex-shrink-0 shadow-md">
                        <i class="ph-bold ph-strategy"></i>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center gap-2.5">
                            <h4 class="text-base font-black text-[#0f2044]">Official Swiss + Knockout</h4>
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[10px] font-black uppercase tracking-wider">Dynamic Engine</span>
                        </div>
                        <p class="text-slate-600 text-xs mt-1.5 leading-relaxed font-medium">
                            <strong class="text-slate-800">Stage 1:</strong> 3 Swiss Qualifier rounds with 2-loss elimination and auto-pairing.<br>
                            <strong class="text-slate-800">Stage 2:</strong> Single-elimination championship bracket down to the 🏆 Final & 🥉 3rd Place match.<br>
                            <span class="text-blue-600 font-semibold mt-1 inline-block">✨ Dynamically adapts to any player count (24, 32, 16, etc.) with automatic seeding and BYEs.</span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-span-full">
                <label class="block text-sm font-bold text-slate-700 mb-2">Description / Notes (Optional)</label>
                <textarea name="description" rows="3" placeholder="Internal notes for this tournament..." class="w-full bg-slate-50 border border-slate-200 text-slate-800 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block p-3 transition-colors"></textarea>
            </div>
            
        </div>
        
        <div class="mt-8 pt-6 border-t border-slate-100 flex justify-end gap-3">
            <a href="<?= BASE_URL ?>/admin/tournaments/index.php" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold py-3 px-6 rounded-xl transition-all">
                Cancel
            </a>
            <button type="submit" class="bg-[#0f2044] hover:bg-blue-800 text-white font-bold py-3 px-8 rounded-xl shadow-md hover:shadow-lg transition-all flex items-center gap-2">
                <i class="ph-bold ph-arrow-right"></i> Next: Configure Scoring
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
