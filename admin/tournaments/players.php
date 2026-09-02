<?php
// ============================================================
// Tournaments - Ultra-Fast Player Enrollment & Multi-Select Studio
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

$pdo = db();

// Cannot modify players if tournament enrollment is locked or started
$hasMatches = ($tournament['status'] !== 'draft');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hasMatches) {
    if (!verify_csrf()) {
        flash_set('tournament_players', 'Invalid security token.', 'error');
    } else {
        $action = $_POST['action'] ?? '';
        
        // 1. Single Player Enrollment
        if ($action === 'enroll') {
            $player_id = (int)($_POST['player_id'] ?? 0);
            $p = getPlayer($player_id);
            if ($p && ($tournament['gender'] === 'Mixed' || $p['gender'] === $tournament['gender'])) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO tournament_players (tournament_id, player_id) VALUES (?, ?)');
                    $stmt->execute([$id, $player_id]);
                    flash_set('tournament_players', "Player \"{$p['display_name']}\" enrolled successfully.", 'success');
                } catch (PDOException $e) {
                    flash_set('tournament_players', 'Player is already enrolled.', 'error');
                }
            } else {
                flash_set('tournament_players', 'Invalid player or gender mismatch.', 'error');
            }
        } 
        // 2. Bulk Multi-Select Enrollment
        elseif ($action === 'enroll_bulk') {
            $player_ids = $_POST['player_ids'] ?? [];
            if (!empty($player_ids) && is_array($player_ids)) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO tournament_players (tournament_id, player_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                    $count = 0;
                    foreach ($player_ids as $pid) {
                        $pid = (int)$pid;
                        if ($pid > 0) {
                            $p = getPlayer($pid);
                            if ($p && ($tournament['gender'] === 'Mixed' || $p['gender'] === $tournament['gender'])) {
                                $stmt->execute([$id, $pid]);
                                if ($stmt->rowCount() > 0) $count++;
                            }
                        }
                    }
                    $pdo->commit();
                    flash_set('tournament_players', "Successfully enrolled $count player(s).", 'success');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash_set('tournament_players', 'Error enrolling players: ' . $e->getMessage(), 'error');
                }
            } else {
                flash_set('tournament_players', 'No players were selected.', 'error');
            }
        }
        // 3. 1-Click Auto Enroll All Available
        elseif ($action === 'enroll_all_available') {
            $availQuery = '
                SELECT p.id FROM players p 
                WHERE p.id NOT IN (SELECT player_id FROM tournament_players WHERE tournament_id = ?)
            ';
            $availParams = [$id];
            if ($tournament['gender'] !== 'Mixed') {
                $availQuery .= ' AND p.gender = ?';
                $availParams[] = $tournament['gender'];
            }
            $availQuery .= ' ORDER BY p.full_name ASC LIMIT 32';
            $stmt = $pdo->prepare($availQuery);
            $stmt->execute($availParams);
            $pids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($pids)) {
                $pdo->beginTransaction();
                try {
                    $insertStmt = $pdo->prepare('INSERT INTO tournament_players (tournament_id, player_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
                    $count = 0;
                    foreach ($pids as $pid) {
                        $insertStmt->execute([$id, (int)$pid]);
                        $count++;
                    }
                    $pdo->commit();
                    flash_set('tournament_players', "Successfully auto-enrolled all {$count} available players!", 'success');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    flash_set('tournament_players', 'Error auto-enrolling players: ' . $e->getMessage(), 'error');
                }
            } else {
                flash_set('tournament_players', 'No eligible available players found.', 'error');
            }
        }
        // 4. Remove Single Player
        elseif ($action === 'remove') {
            $player_id = (int)($_POST['player_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM tournament_players WHERE tournament_id = ? AND player_id = ?');
            $stmt->execute([$id, $player_id]);
            flash_set('tournament_players', 'Player removed from tournament roster.', 'info');
        }
        // 5. Clear Entire Roster
        elseif ($action === 'clear_all') {
            $stmt = $pdo->prepare('DELETE FROM tournament_players WHERE tournament_id = ?');
            $stmt->execute([$id]);
            flash_set('tournament_players', 'Tournament roster cleared.', 'info');
        }
        
        AppCache::flush();
        header("Location: players.php?id=$id");
        exit;
    }
}

// Fetch enrolled players
$stmt = $pdo->prepare('
    SELECT p.*, tp.registered_at 
    FROM tournament_players tp 
    JOIN players p ON p.id = tp.player_id 
    WHERE tp.tournament_id = ? 
    ORDER BY p.full_name ASC
');
$stmt->execute([$id]);
$enrolled = $stmt->fetchAll();
$enrolledCount = count($enrolled);
$enrolledIds = array_column($enrolled, 'id');

// Fetch available players to enroll
$availQuery = 'SELECT id, its_id, full_name, display_name, mohallah, photo_path, gender FROM players ';
$availParams = [];
if ($tournament['gender'] !== 'Mixed') {
    $availQuery .= 'WHERE gender = ? ';
    $availParams[] = $tournament['gender'];
}
$availQuery .= 'ORDER BY full_name ASC';
$stmt = $pdo->prepare($availQuery);
$stmt->execute($availParams);
$allEligible = $stmt->fetchAll();

// Filter out already enrolled
$available = array_values(array_filter($allEligible, fn($p) => !in_array($p['id'], $enrolledIds)));

$pageTitle = 'Enroll Players: ' . $tournament['name'];
include __DIR__ . '/../includes/header.php';
?>

<!-- Alpine Master State for Fast Player Selection & Studio -->
<div x-data="rosterEnrollmentManager()" class="space-y-6">

    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $id ?>" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
                <i class="ph-bold ph-arrow-left"></i> Back to Tournament Dashboard
            </a>
            <div class="flex items-center gap-3">
                <h2 class="text-3xl font-black font-display text-[#0f2044]">Tournament Roster</h2>
                <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-slate-100 text-slate-700 border border-slate-200">
                    <?= e($tournament['gender']) ?>
                </span>
            </div>
            <p class="text-sm font-bold text-slate-500 mt-1"><?= e($tournament['name']) ?></p>
        </div>

        <?php if (!$hasMatches && count($available) > 0): ?>
            <!-- FAST BULK ACTION BUTTONS -->
            <div class="flex items-center gap-2.5 flex-wrap">
                <button type="button" 
                        @click="openBulkStudio = true"
                        class="bg-[#0f2044] hover:bg-blue-900 text-white font-black px-4 py-2.5 rounded-xl shadow-md hover:shadow-lg transition-all flex items-center gap-2 text-sm">
                    <i class="ph-bold ph-squares-four text-[#c9a84c] text-lg"></i>
                    <span>⚡ Quick Multi-Select Studio</span>
                </button>

                <form action="" method="POST" class="inline" @submit="isProcessing = true">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enroll_all_available">
                    <button type="submit" class="bg-[#c9a84c] hover:bg-[#b0923e] text-[#0f2044] font-black px-4 py-2.5 rounded-xl shadow-md hover:shadow-lg transition-all flex items-center gap-2 text-sm">
                        <i class="ph-bold ph-lightning text-lg"></i>
                        <span>Auto-Enroll All (<?= count($available) ?>)</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?= flash_html('tournament_players') ?>

    <?php if ($hasMatches): ?>
        <div class="bg-yellow-50 text-yellow-800 p-4 rounded-2xl mb-6 text-sm border border-yellow-200 flex items-center gap-3 shadow-xs">
            <i class="ph-fill ph-lock-key text-yellow-500 text-2xl flex-shrink-0"></i>
            <div>
                <strong class="font-black text-base">Tournament Roster Locked</strong>
                <p class="text-yellow-700/90 text-xs mt-0.5">Fixtures and matches have already been generated for this tournament. Roster modifications are disabled.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- ======================================================= -->
        <!-- LEFT: ENROLLED ROSTER TABLE (2 COLS)                    -->
        <!-- ======================================================= -->
        <div class="lg:col-span-2">
            <div class="bg-white border border-slate-200 rounded-3xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                            <i class="ph-fill ph-users-three text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-[#0f2044]">Enrolled Roster</h3>
                            <p class="text-[11px] text-slate-400 font-bold uppercase tracking-wider">Tournament participants</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-xs font-black tracking-widest px-3.5 py-1.5 rounded-full <?= $enrolledCount >= 24 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' ?> border border-slate-200">
                            <?= $enrolledCount ?> Players Enrolled
                        </span>
                        
                        <?php if (!$hasMatches && $enrolledCount > 0): ?>
                            <form action="" method="POST" class="inline" @submit.prevent="if(confirm('Are you sure you want to remove ALL players from this tournament?')) $el.submit()">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="clear_all">
                                <button type="submit" class="text-xs font-bold text-red-500 hover:text-red-700 hover:bg-red-50 px-2.5 py-1 rounded-lg transition" title="Clear Roster">
                                    Clear All
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-widest text-slate-400 border-b border-slate-100 bg-white">
                                <th class="px-6 py-3.5 font-bold">#</th>
                                <th class="px-4 py-3.5 font-bold">Player</th>
                                <th class="px-4 py-3.5 font-bold">ITS ID</th>
                                <th class="px-4 py-3.5 font-bold">Mohallah</th>
                                <th class="px-6 py-3.5 font-bold text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if (empty($enrolled)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-16 text-center">
                                        <div class="flex flex-col items-center justify-center text-slate-400 max-w-sm mx-auto">
                                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-3">
                                                <i class="ph-fill ph-user-plus text-3xl"></i>
                                            </div>
                                            <p class="font-black text-base text-slate-700">No players enrolled yet</p>
                                            <p class="text-xs text-slate-400 mt-1">Use the quick selector on the right or click "Quick Multi-Select Studio" to enroll your roster in seconds.</p>
                                            
                                            <?php if (count($available) > 0): ?>
                                                <button type="button" 
                                                        @click="openBulkStudio = true"
                                                        class="mt-4 bg-[#0f2044] hover:bg-blue-900 text-white font-bold px-4 py-2 rounded-xl text-xs flex items-center gap-1.5 shadow-sm transition">
                                                    <i class="ph-bold ph-squares-four text-[#c9a84c]"></i> Open Multi-Select Studio
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($enrolled as $idx => $p): ?>
                                    <tr class="hover:bg-slate-50/70 transition-colors">
                                        <td class="px-6 py-3.5 text-xs font-mono font-bold text-slate-400">
                                            <?= $idx + 1 ?>
                                        </td>
                                        <td class="px-4 py-3.5 font-bold text-slate-800">
                                            <div class="flex items-center gap-3">
                                                <?php if (!empty($p['photo_path'])): ?>
                                                    <img src="<?= BASE_URL ?>/uploads/players/<?= e($p['photo_path']) ?>" alt="Photo" class="w-8 h-8 rounded-full object-cover shadow-xs border border-slate-200">
                                                <?php else: ?>
                                                    <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 border border-slate-200 text-xs font-bold">
                                                        <?= strtoupper(substr($p['display_name'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="font-black text-slate-900 leading-tight"><?= e($p['full_name']) ?></div>
                                                    <div class="text-[11px] text-slate-400 font-medium"><?= e($p['display_name']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3.5 font-mono text-xs font-bold text-slate-600">
                                            <?= e($p['its_id']) ?>
                                        </td>
                                        <td class="px-4 py-3.5">
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-600 bg-slate-100 px-2.5 py-0.5 rounded-md">
                                                <i class="ph-fill ph-map-pin text-[#c9a84c] text-[10px]"></i> <?= e($p['mohallah']) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-3.5 text-right">
                                            <?php if (!$hasMatches): ?>
                                                <form action="" method="POST" class="inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="remove">
                                                    <input type="hidden" name="player_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Remove Player">
                                                        <i class="ph-bold ph-trash text-sm"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-slate-300 text-xs font-bold"><i class="ph-fill ph-lock-key"></i></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================================================= -->
        <!-- RIGHT: ULTRA-FAST INSTANT SELECTOR & SEARCH             -->
        <!-- ======================================================= -->
        <div>
            <?php if (!$hasMatches): ?>
                <div class="bg-white border border-slate-200 rounded-3xl shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-visible sticky top-24">
                    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                        <h3 class="font-black text-[#0f2044] flex items-center gap-2">
                            <i class="ph-bold ph-lightning text-[#c9a84c] text-lg"></i>
                            Instant Selector
                        </h3>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                            <?= count($available) ?> Available
                        </span>
                    </div>
                    
                    <div class="p-6">
                        <?php if (empty($available)): ?>
                            <div class="text-center py-8">
                                <i class="ph-fill ph-check-circle text-5xl text-emerald-500 mb-3 drop-shadow-sm"></i>
                                <h4 class="font-black text-[#0f2044] text-lg">All Players Enrolled!</h4>
                                <p class="text-xs font-bold text-slate-400 mt-1">Every eligible player in the directory has been enrolled in this tournament.</p>
                            </div>
                        <?php else: ?>
                            
                            <!-- Search & Fast Keyboard Picker -->
                            <div class="space-y-4">
                                <div class="relative">
                                    <label class="block text-xs font-black text-slate-700 uppercase tracking-wider mb-2">Search & Pick Player</label>
                                    <div class="relative">
                                        <i class="ph-bold ph-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                        <input type="text" 
                                               x-model="quickSearch" 
                                               @keydown.down.prevent="navigateDown()"
                                               @keydown.up.prevent="navigateUp()"
                                               @keydown.enter.prevent="enrollHighlighted()"
                                               placeholder="Type name, ITS, or mohallah..." 
                                               class="w-full bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 pl-10 pr-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    </div>
                                    <p class="text-[10px] text-slate-400 mt-1.5 font-medium flex items-center gap-1">
                                        <i class="ph-bold ph-keyboard"></i> Use &uarr;&darr; arrow keys + <kbd class="px-1 py-0.5 bg-slate-100 border rounded text-[9px] font-mono">Enter</kbd> to add instantly
                                    </p>
                                </div>

                                <!-- Live Filtered Scroll List with Instant 1-Click (+) Button -->
                                <div class="border border-slate-200 rounded-2xl overflow-hidden bg-slate-50/50 max-h-72 overflow-y-auto custom-scrollbar divide-y divide-slate-100">
                                    <template x-for="(p, index) in filteredAvailable" :key="p.id">
                                        <div class="p-3 flex items-center justify-between transition-colors"
                                             :class="highlightIndex === index ? 'bg-blue-50 border-l-4 border-blue-600' : 'hover:bg-white'">
                                            <div class="flex items-center gap-2.5 min-w-0 pr-2">
                                                <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-600 flex items-center justify-center font-black text-[11px] flex-shrink-0">
                                                    <span x-text="p.display_name.charAt(0)"></span>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="font-bold text-xs text-slate-900 truncate" x-text="p.full_name"></div>
                                                    <div class="text-[10px] text-slate-400 font-mono font-medium flex items-center gap-1.5">
                                                        <span x-text="p.its_id"></span>
                                                        <span>&bull;</span>
                                                        <span class="text-slate-500 font-sans" x-text="p.mohallah"></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <form action="" method="POST" class="flex-shrink-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="enroll">
                                                <input type="hidden" name="player_id" :value="p.id">
                                                <button type="submit" 
                                                        class="bg-[#0f2044] hover:bg-blue-800 text-white text-xs font-bold px-2.5 py-1.5 rounded-lg flex items-center gap-1 transition shadow-xs">
                                                    <i class="ph-bold ph-plus text-xs text-[#c9a84c]"></i>
                                                    <span>Add</span>
                                                </button>
                                            </form>
                                        </div>
                                    </template>
                                    <div x-show="filteredAvailable.length === 0" class="p-6 text-center text-xs font-bold text-slate-400">
                                        No matching available players found.
                                    </div>
                                </div>

                                <!-- Studio Banner Callout -->
                                <button type="button" 
                                        @click="openBulkStudio = true"
                                        class="w-full bg-gradient-to-r from-[#0f2044] to-[#1e3a8a] text-white p-3.5 rounded-2xl shadow-md hover:shadow-lg transition-all flex items-center justify-between text-left group">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-xl bg-white/10 flex items-center justify-center text-[#c9a84c]">
                                            <i class="ph-bold ph-squares-four text-lg"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs font-black leading-tight text-white">Multi-Select Studio</div>
                                            <div class="text-[10px] text-slate-300 font-medium">Select 16, 24, or 32 with 1 click</div>
                                        </div>
                                    </div>
                                    <i class="ph-bold ph-arrow-right text-[#c9a84c] group-hover:translate-x-1 transition-transform"></i>
                                </button>
                            </div>

                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ======================================================= -->
    <!-- HIGH-SPEED MULTI-SELECT ROSTER STUDIO MODAL             -->
    <!-- ======================================================= -->
    <div x-show="openBulkStudio" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/70 backdrop-blur-md flex items-center justify-center p-4 md:p-6"
         style="display: none;"
         @keydown.escape.window="openBulkStudio = false">
        
        <div class="bg-white rounded-3xl max-w-4xl w-full max-h-[90vh] flex flex-col shadow-2xl border border-slate-100 relative overflow-hidden"
             @click.away="openBulkStudio = false">
            
            <!-- Modal Header -->
            <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/70">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center shadow-md">
                        <i class="ph-bold ph-squares-four text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-black font-display text-[#0f2044]">Multi-Select Roster Studio</h3>
                        <p class="text-xs text-slate-500 font-medium">Select multiple players and enroll them in a single batch.</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" @click="openBulkStudio = false" class="w-9 h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 hover:text-slate-700 flex items-center justify-center transition">
                        <i class="ph-bold ph-x text-lg"></i>
                    </button>
                </div>
            </div>

            <!-- Toolbar & Fast Presets -->
            <div class="px-6 py-4 border-b border-slate-100 bg-white flex flex-col md:flex-row items-center justify-between gap-3">
                <div class="relative w-full md:w-80">
                    <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" 
                           x-model="studioSearch" 
                           placeholder="Filter by name, ITS, or mohallah..." 
                           class="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-800 pl-9 pr-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                </div>

                <!-- Quick Presets -->
                <div class="flex items-center gap-1.5 flex-wrap w-full md:w-auto justify-end">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 mr-1">Presets:</span>
                    <button type="button" @click="selectAll()" class="text-xs font-bold px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                        Select All (<span x-text="availableList.length"></span>)
                    </button>
                    <button type="button" @click="selectPreset(16)" class="text-xs font-bold px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 transition">
                        Top 16
                    </button>
                    <button type="button" @click="selectPreset(24)" class="text-xs font-bold px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 transition">
                        Top 24
                    </button>
                    <button type="button" @click="selectPreset(32)" class="text-xs font-bold px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 transition">
                        Top 32
                    </button>
                    <button type="button" @click="selectedIds = []" class="text-xs font-bold px-2.5 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 transition">
                        Deselect
                    </button>
                </div>
            </div>

            <!-- Scrollable Interactive Player Card Grid -->
            <form action="" method="POST" id="bulkEnrollForm" class="flex flex-col flex-1 overflow-hidden" @submit="isProcessing = true">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="enroll_bulk">

                <div class="p-6 overflow-y-auto custom-scrollbar flex-1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <template x-for="p in filteredStudioList" :key="p.id">
                            <label class="relative flex items-center gap-3 p-3 rounded-2xl border-2 cursor-pointer transition-all duration-150 select-none group"
                                   :class="selectedIds.includes(p.id) ? 'border-blue-600 bg-blue-50/70 shadow-sm ring-2 ring-blue-500/20' : 'border-slate-100 bg-slate-50/50 hover:border-slate-300 hover:bg-white'">
                                
                                <input type="checkbox" 
                                       name="player_ids[]" 
                                       :value="p.id" 
                                       x-model="selectedIds" 
                                       class="sr-only">

                                <!-- Custom Checkbox Circle -->
                                <div class="w-5 h-5 rounded-lg border-2 flex items-center justify-center flex-shrink-0 transition-colors"
                                     :class="selectedIds.includes(p.id) ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white group-hover:border-slate-400'">
                                    <i class="ph-bold ph-check text-xs" x-show="selectedIds.includes(p.id)"></i>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="font-black text-xs text-slate-900 truncate" x-text="p.full_name"></div>
                                    <div class="text-[10px] text-slate-400 font-mono flex items-center gap-1.5 mt-0.5">
                                        <span class="font-bold text-blue-600" x-text="p.its_id"></span>
                                        <span>&bull;</span>
                                        <span class="truncate text-slate-500 font-sans" x-text="p.mohallah"></span>
                                    </div>
                                </div>
                            </label>
                        </template>
                    </div>

                    <div x-show="filteredStudioList.length === 0" class="text-center py-12 text-slate-400">
                        <i class="ph-fill ph-magnifying-glass text-3xl mb-2"></i>
                        <p class="text-sm font-bold">No players match "<span x-text="studioSearch"></span>"</p>
                    </div>
                </div>

                <!-- Modal Sticky Action Footer -->
                <div class="p-5 border-t border-slate-100 bg-slate-50 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs font-bold text-slate-600">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-600 animate-pulse"></span>
                        <span><strong class="text-slate-900 text-sm" x-text="selectedIds.length"></strong> players selected</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="button" @click="openBulkStudio = false" class="px-4 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-100 transition">
                            Cancel
                        </button>
                        <button type="submit" 
                                :disabled="selectedIds.length === 0"
                                :class="selectedIds.length === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-900 shadow-md hover:shadow-lg'"
                                class="bg-[#0f2044] text-white text-xs font-black px-6 py-2.5 rounded-xl transition flex items-center gap-2">
                            <i class="ph-bold ph-user-plus text-[#c9a84c] text-sm"></i>
                            <span>Enroll (<span x-text="selectedIds.length"></span>) Players Now</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function rosterEnrollmentManager() {
    const availableRaw = <?= json_encode($available) ?>;
    return {
        openBulkStudio: false,
        quickSearch: '',
        studioSearch: '',
        highlightIndex: 0,
        availableList: availableRaw,
        selectedIds: [],
        isProcessing: false,

        get filteredAvailable() {
            if (!this.quickSearch.trim()) return this.availableList;
            const q = this.quickSearch.toLowerCase();
            return this.availableList.filter(p => 
                p.full_name.toLowerCase().includes(q) ||
                p.its_id.includes(q) ||
                p.mohallah.toLowerCase().includes(q)
            );
        },

        get filteredStudioList() {
            if (!this.studioSearch.trim()) return this.availableList;
            const q = this.studioSearch.toLowerCase();
            return this.availableList.filter(p => 
                p.full_name.toLowerCase().includes(q) ||
                p.its_id.includes(q) ||
                p.mohallah.toLowerCase().includes(q)
            );
        },

        selectAll() {
            this.selectedIds = this.availableList.map(p => p.id);
        },

        selectPreset(count) {
            this.selectedIds = this.availableList.slice(0, count).map(p => p.id);
        },

        navigateDown() {
            if (this.highlightIndex < this.filteredAvailable.length - 1) {
                this.highlightIndex++;
            }
        },

        navigateUp() {
            if (this.highlightIndex > 0) {
                this.highlightIndex--;
            }
        },

        enrollHighlighted() {
            if (this.filteredAvailable.length > 0 && this.highlightIndex >= 0) {
                const target = this.filteredAvailable[this.highlightIndex];
                if (target) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="enroll">
                        <input type="hidden" name="player_id" value="${target.id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
