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
        $stmt->execute([$name, $gender, $matchType, $format, 'draft', $desc]);
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
                <label class="block text-sm font-bold text-slate-700 mb-2">Tournament Format <span class="text-red-500">*</span></label>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Option 1: Swiss + Knockout -->
                    <label class="relative flex cursor-pointer rounded-2xl border bg-white p-4 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-[#c9a84c] has-[:checked]:ring-2 has-[:checked]:ring-[#c9a84c] has-[:checked]:bg-[#c9a84c]/5 group">
                        <input type="radio" name="format" value="swiss_knockout" class="sr-only" checked required>
                        <div class="flex items-start gap-3 w-full">
                            <div class="w-10 h-10 rounded-xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center font-bold text-xl flex-shrink-0 shadow-sm mt-0.5">
                                <i class="ph-bold ph-strategy"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-black text-[#0f2044]">Swiss + Knockout</h4>
                                    <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[9px] font-black uppercase tracking-wider">Default</span>
                                </div>
                                <p class="text-slate-500 text-xs mt-1 leading-snug">
                                    Stage 1: Swiss Qualifiers with 2-loss elimination.<br>
                                    Stage 2: Single-elimination championship bracket.
                                </p>
                            </div>
                        </div>
                    </label>

                    <!-- Option 2: Custom Matchmaking + Knockout (Perfect for 26 players!) -->
                    <label class="relative flex cursor-pointer rounded-2xl border bg-white p-4 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-[#c9a84c] has-[:checked]:ring-2 has-[:checked]:ring-[#c9a84c] has-[:checked]:bg-[#c9a84c]/5 group">
                        <input type="radio" name="format" value="custom_knockout" class="sr-only" required>
                        <div class="flex items-start gap-3 w-full">
                            <div class="w-10 h-10 rounded-xl bg-purple-900 text-purple-200 flex items-center justify-center font-bold text-xl flex-shrink-0 shadow-sm mt-0.5">
                                <i class="ph-bold ph-hand-pointing"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-black text-[#0f2044]">Custom Matchmaking + Knockout</h4>
                                    <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 text-[9px] font-black uppercase tracking-wider">Flexible</span>
                                </div>
                                <p class="text-slate-500 text-xs mt-1 leading-snug">
                                    Admin creates custom rounds manually (no forced byes).<br>
                                    Live Leaderboard ranks all players &rarr; Qualify Top 16 to Knockout!
                                </p>
                            </div>
                        </div>
                    </label>

                    <!-- Option 3: Pools + Knockout -->
                    <label class="relative flex cursor-pointer rounded-2xl border bg-white p-4 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-[#c9a84c] has-[:checked]:ring-2 has-[:checked]:ring-[#c9a84c] has-[:checked]:bg-[#c9a84c]/5 group">
                        <input type="radio" name="format" value="pools_knockout" class="sr-only" required>
                        <div class="flex items-start gap-3 w-full">
                            <div class="w-10 h-10 rounded-xl bg-blue-900 text-blue-200 flex items-center justify-center font-bold text-xl flex-shrink-0 shadow-sm mt-0.5">
                                <i class="ph-bold ph-columns"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-black text-[#0f2044]">Pools + Knockout</h4>
                                    <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 text-[9px] font-black uppercase tracking-wider">Group Stage</span>
                                </div>
                                <p class="text-slate-500 text-xs mt-1 leading-snug">
                                    Divide players into group pools (A, B, C...).<br>
                                    Top players from each pool advance to Knockout.
                                </p>
                            </div>
                        </div>
                    </label>

                    <!-- Option 4: Round Robin -->
                    <label class="relative flex cursor-pointer rounded-2xl border bg-white p-4 shadow-sm hover:bg-slate-50 focus:outline-none transition-all has-[:checked]:border-[#c9a84c] has-[:checked]:ring-2 has-[:checked]:ring-[#c9a84c] has-[:checked]:bg-[#c9a84c]/5 group">
                        <input type="radio" name="format" value="round_robin" class="sr-only" required>
                        <div class="flex items-start gap-3 w-full">
                            <div class="w-10 h-10 rounded-xl bg-slate-800 text-[#c9a84c] flex items-center justify-center font-bold text-xl flex-shrink-0 shadow-sm mt-0.5">
                                <i class="ph-bold ph-arrows-clockwise"></i>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-black text-[#0f2044]">Round Robin (League)</h4>
                                    <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-[9px] font-black uppercase tracking-wider">Points Table</span>
                                </div>
                                <p class="text-slate-500 text-xs mt-1 leading-snug">
                                    Every player plays every other player.<br>
                                    Champion is decided purely by total points table ranking.
                                </p>
                            </div>
                        </div>
                    </label>
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
