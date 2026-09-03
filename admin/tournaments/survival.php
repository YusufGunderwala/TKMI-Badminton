<?php
// ============================================================
// Tournaments - Survival Round Generation UI
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

if (!Matchmaker::isRoundComplete($id, ROUND_STAGE1_R2)) {
    flash_set('tournament_view', 'Cannot start Survival Round. Round 2 is not complete.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
$stmt->execute([$id, ROUND_STAGE1_SURVIVAL]);
if ($stmt->fetchColumn() > 0) {
    flash_set('tournament_view', 'Survival Round has already been generated.', 'info');
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;
}

$error = '';
$admin = currentAdmin();

// Prepare Survival Paths
$paths = Matchmaker::getSurvivalPaths($id);
$path1Ids = $paths['path1'];
$path2Ids = $paths['path2'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid security token.';
    } else {
        $pairCount = (int)($_POST['pair_count'] ?? 0);
        if ($pairCount <= 0) {
            $pairCount = count($path1Ids);
        }
        $pairs = [];
        for ($i = 0; $i < $pairCount; $i++) {
            $pA = (int)($_POST["pA_$i"] ?? 0);
            $pB = (int)($_POST["pB_$i"] ?? 0);
            if ($pA && $pB) {
                $pairs[] = [$pA, $pB];
            }
        }

        try {
            Matchmaker::saveSurvivalRound($id, $pairs, $admin['id']);
            AppCache::flush();
            flash_set('tournament_view', 'Survival Round generated successfully!', 'success');
            header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Map Player IDs to Names & Opponents
$playerData = [];
foreach (array_merge($path1Ids, $path2Ids) as $pid) {
    $p = getPlayer($pid);
    $playerData[$pid] = [
        'id' => $pid,
        'name' => $p['display_name'],
        'its' => $p['its_id'],
        'mohallah' => $p['mohallah'] ?? 'TKMI',
        'history' => Matchmaker::getPreviousOpponents($id, $pid)
    ];
}

$pageTitle = 'Survival Round Pairing: ' . $tournament['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $id ?>" class="text-gray-400 hover:text-tkmi-navy">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <h2 class="text-2xl font-bold text-gray-800">Survival Round</h2>
        </div>
        <p class="text-sm text-gray-500 ml-8">Pairing Path 1 (Won R1) vs Path 2 (Lost R1)</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="bg-red-50 text-red-700 p-4 rounded mb-6 text-sm border-l-4 border-red-500"><?= e($error) ?></div>
<?php endif; ?>

<div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden" x-data="survivalPairing()">
    <div class="p-4 sm:p-6 border-b border-slate-200 bg-slate-50/75 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h3 class="font-bold text-slate-800 text-sm">Survival Round Matchmaker</h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Review and adjust pairs.
                <span class="text-red-600 font-bold ml-1 inline-flex items-center gap-1" x-show="hasRematches">
                    <i class="ph-fill ph-warning"></i> Rematch Warning Detected!
                </span>
            </p>
        </div>
        <button type="button" @click="shufflePath2()" class="inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-md hover:shadow-blue-500/25 transition-all cursor-pointer">
            <i class="ph-bold ph-arrows-clockwise text-sm"></i>
            <span>Auto-Suggest / Reshuffle</span>
        </button>
    </div>

    <form action="" method="POST" id="survivalForm">
        <?= csrf_field() ?>
        <input type="hidden" name="pair_count" :value="matches.length">
        
        <div class="p-4 sm:p-6 space-y-5 bg-slate-100/50">
            <template x-for="(match, index) in matches" :key="index">
                <div class="relative bg-white rounded-2xl border shadow-sm transition-all duration-300 hover:shadow-md"
                     :class="match.isRematch ? 'border-red-300 ring-2 ring-red-500/20' : 'border-slate-200 hover:border-blue-300'">
                    
                    <!-- Top Status Bar -->
                    <div class="flex items-center justify-between px-4 py-2.5 border-b rounded-t-2xl"
                         :class="match.isRematch ? 'bg-red-50/80 border-red-100' : 'bg-slate-50 border-slate-100'">
                        <div class="flex items-center gap-2">
                            <span class="bg-[#0f2044] text-[#c9a84c] text-[10px] font-black tracking-widest uppercase px-2 py-0.5 rounded-sm">
                                Match <span x-text="index + 1"></span>
                            </span>
                        </div>
                        <div class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide"
                             :class="match.isRematch ? 'text-red-600' : 'text-emerald-600'">
                            <template x-if="match.isRematch">
                                <span class="flex items-center gap-1"><i class="ph-fill ph-warning-circle text-sm"></i> Rematch (Played in R1)</span>
                            </template>
                            <template x-if="!match.isRematch && match.pB != 0">
                                <span class="text-emerald-600/80 flex items-center gap-1"><i class="ph-fill ph-check-circle text-sm"></i> Clean Matchup</span>
                            </template>
                        </div>
                    </div>

                    <!-- Duel Arena -->
                    <div class="p-4 sm:p-5 flex flex-col md:flex-row items-stretch gap-4 sm:gap-6 relative">
                        
                        <!-- Left Fighter (Path 1) -->
                        <div class="flex-1 bg-slate-50 border border-slate-200 rounded-xl p-3 flex items-center gap-4 relative overflow-hidden shadow-xs">
                            <div class="absolute top-0 left-0 w-1.5 h-full bg-emerald-500"></div>
                            <input type="hidden" :name="'pA_' + index" :value="match.pA">
                            
                            <div class="w-12 h-12 sm:w-14 sm:h-14 bg-white rounded-full flex items-center justify-center flex-shrink-0 border-2 border-emerald-100 shadow-sm">
                                <i class="ph-fill ph-user text-2xl text-emerald-500"></i>
                            </div>
                            
                            <div class="flex-1 min-w-0 py-1">
                                <div class="text-[9px] sm:text-[10px] font-black uppercase tracking-wider text-emerald-600 mb-0.5">Path 1 (Won R1)</div>
                                <div class="text-sm sm:text-base font-bold text-slate-800 truncate" x-text="players[match.pA].name"></div>
                                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 mt-1 sm:mt-1.5">
                                    <span class="inline-flex items-center text-[9px] sm:text-[10px] font-bold text-slate-500 bg-slate-200/70 px-1.5 py-0.5 rounded" x-text="'ITS: ' + players[match.pA].its"></span>
                                    <span class="inline-flex items-center text-[9px] sm:text-[10px] font-bold text-slate-500 bg-slate-200/70 px-1.5 py-0.5 rounded" x-text="players[match.pA].mohallah"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Center VS Badge -->
                        <div class="flex-shrink-0 relative z-10 flex flex-col items-center justify-center py-2 md:py-0">
                            <div class="w-9 h-9 sm:w-11 sm:h-11 bg-white border-2 rounded-full flex items-center justify-center shadow-sm z-10 relative"
                                 :class="match.isRematch ? 'border-red-400 text-red-500' : 'border-slate-200 text-slate-400'">
                                <span class="font-black italic text-xs sm:text-sm">VS</span>
                            </div>
                            <div class="absolute h-full w-[2px] bg-slate-200 -z-10 top-0 hidden md:block"></div>
                            <div class="absolute w-full h-[2px] bg-slate-200 -z-10 left-0 block md:hidden"></div>
                        </div>

                        <!-- Right Fighter (Path 2) -->
                        <div x-data="{ dropdownOpen: false }" @click.away="dropdownOpen = false"
                             class="flex-1 bg-white border-2 rounded-xl p-3 flex items-center gap-4 relative group transition-colors shadow-xs cursor-pointer select-none"
                             :class="match.isRematch ? 'border-red-400 bg-red-50/40' : (dropdownOpen ? 'border-blue-500 ring-2 ring-blue-500/20' : 'border-blue-200 hover:border-blue-400')"
                             @click="dropdownOpen = !dropdownOpen">
                            
                            <div class="absolute top-0 right-0 w-1.5 h-full transition-colors"
                                 :class="match.isRematch ? 'bg-red-500' : 'bg-blue-500'"></div>
                            
                            <input type="hidden" :name="'pB_' + index" :value="match.pB">
                            
                            <!-- Custom Dropdown Icon -->
                            <div class="absolute inset-0 z-10 flex items-center justify-end px-4 pointer-events-none transition-opacity"
                                 :class="dropdownOpen ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'">
                                <div class="w-8 h-8 rounded-full bg-white shadow flex items-center justify-center border border-slate-100 transition-transform"
                                     :class="dropdownOpen ? 'rotate-180 text-blue-600 bg-blue-50' : 'text-blue-500'">
                                    <i class="ph-bold ph-caret-down text-lg"></i>
                                </div>
                            </div>

                            <!-- Custom Alpine Dropdown Menu -->
                            <div x-show="dropdownOpen" x-transition.opacity.duration.200ms
                                 class="absolute left-0 right-0 top-[calc(100%+8px)] bg-white border border-slate-200 rounded-xl shadow-2xl z-50 overflow-hidden"
                                 style="display: none;">
                                <div class="max-h-60 overflow-y-auto custom-scrollbar">
                                    <div @click="match.pB = 0; checkConflicts();"
                                         class="px-4 py-3 border-b border-slate-100 hover:bg-slate-50 text-sm font-bold text-slate-400 transition-colors">
                                        -- Select Path 2 Opponent --
                                    </div>
                                    <template x-for="p2Id in path2" :key="p2Id">
                                        <div @click.stop="match.pB = p2Id; checkConflicts(); dropdownOpen = false;"
                                             class="px-4 py-3 border-b border-slate-50 hover:bg-blue-50 cursor-pointer transition-colors flex items-center justify-between"
                                             :class="match.pB == p2Id ? 'bg-blue-50/60 border-l-2 border-l-blue-500' : 'border-l-2 border-l-transparent'">
                                            <div>
                                                <div class="text-sm font-bold text-slate-800" x-text="players[p2Id].name"></div>
                                                <div class="flex gap-2 mt-1">
                                                    <span class="text-[10px] font-bold text-slate-500 bg-white px-1.5 rounded border border-slate-100 shadow-sm" x-text="'ITS: ' + players[p2Id].its"></span>
                                                    <span class="text-[10px] font-bold text-slate-500 bg-white px-1.5 rounded border border-slate-100 shadow-sm" x-text="players[p2Id].mohallah"></span>
                                                </div>
                                            </div>
                                            <div x-show="match.pB == p2Id" class="text-blue-600">
                                                <i class="ph-bold ph-check-circle text-lg"></i>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full flex items-center justify-center flex-shrink-0 border-2 shadow-sm transition-colors relative z-0"
                                 :class="match.isRematch ? 'bg-red-50 border-red-200' : (match.pB != 0 ? 'bg-blue-50 border-blue-200' : 'bg-slate-50 border-slate-200 border-dashed')">
                                <i class="ph-fill ph-user text-2xl" 
                                   :class="match.isRematch ? 'text-red-400' : (match.pB != 0 ? 'text-blue-500' : 'text-slate-300')"></i>
                            </div>
                            
                            <div class="flex-1 min-w-0 pr-6 py-1 relative z-0">
                                <div class="text-[9px] sm:text-[10px] font-black uppercase tracking-wider mb-0.5 transition-colors"
                                     :class="match.isRematch ? 'text-red-600' : 'text-blue-600'">Path 2 (Lost R1)</div>
                                <div class="text-sm sm:text-base font-bold truncate transition-colors"
                                     :class="match.pB != 0 ? (match.isRematch ? 'text-red-900' : 'text-slate-800') : 'text-slate-400 italic'"
                                     x-text="match.pB != 0 ? players[match.pB].name : 'Tap to select fighter...'"></div>
                                
                                <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 mt-1 sm:mt-1.5" x-show="match.pB != 0">
                                    <span class="inline-flex items-center text-[9px] sm:text-[10px] font-bold px-1.5 py-0.5 rounded transition-colors"
                                          :class="match.isRematch ? 'bg-red-100 text-red-600' : 'bg-blue-50 text-blue-600'" 
                                          x-text="'ITS: ' + (match.pB != 0 ? players[match.pB].its : '')"></span>
                                    <span class="inline-flex items-center text-[9px] sm:text-[10px] font-bold px-1.5 py-0.5 rounded transition-colors"
                                          :class="match.isRematch ? 'bg-red-100 text-red-600' : 'bg-blue-50 text-blue-600'" 
                                          x-text="match.pB != 0 ? players[match.pB].mohallah : ''"></span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </template>
        </div>

        <div class="p-4 sm:p-6 border-t border-slate-200 bg-slate-50 flex flex-col sm:flex-row items-center justify-between gap-4">
            <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $id ?>" class="w-full sm:w-auto bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 font-bold py-3 px-6 rounded-xl transition text-center text-sm shadow-xs">
                Cancel
            </a>

            <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                <button type="button" @click="checkConflicts()" class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 bg-white hover:bg-slate-100 text-slate-700 font-bold text-sm px-5 py-3 rounded-xl border border-slate-200 shadow-xs transition cursor-pointer" x-show="!isValid">
                    <i class="ph-bold ph-check-circle text-base text-slate-500"></i>
                    <span>Validate Pairs</span>
                </button>
                <button type="submit" 
                        :disabled="!isValid" 
                        :class="!isValid ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#16306e] shadow-xl hover:shadow-blue-900/30'"
                        class="w-full sm:w-auto bg-[#0f2044] text-white font-black py-3.5 px-8 rounded-xl transition flex items-center justify-center gap-2.5 text-sm cursor-pointer border border-[#c9a84c]/30">
                    <i class="ph-bold ph-floppy-disk text-lg text-[#c9a84c]"></i>
                    <span>Save Survival Bracket</span>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const INITIAL_PATH1 = <?= json_encode($path1Ids) ?>;
const INITIAL_PATH2 = <?= json_encode($path2Ids) ?>;
const PLAYER_DATA   = <?= json_encode($playerData) ?>;

document.addEventListener('alpine:init', () => {
    Alpine.data('survivalPairing', () => ({
        path1: INITIAL_PATH1,
        path2: INITIAL_PATH2,
        players: PLAYER_DATA,
        matches: [],
        hasRematches: false,
        hasDuplicates: false,
        isValid: false,

        init() {
            // Setup initial 8 rows
            for (let i = 0; i < this.path1.length; i++) {
                this.matches.push({
                    pA: this.path1[i],
                    pB: this.path2[i] || 0,
                    isRematch: false
                });
            }
            this.checkConflicts();
            
            // Auto shuffle once on load if there are rematches
            if (this.hasRematches) {
                this.shufflePath2();
            }
        },

        shufflePath2() {
            // Simple random shuffle algorithm that avoids rematches
            let availableB = [...this.path2];
            
            // Try up to 100 times to find a perfect combination without rematches
            for (let attempt = 0; attempt < 100; attempt++) {
                let currentB = [...availableB];
                // Fisher-Yates shuffle
                for (let i = currentB.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [currentB[i], currentB[j]] = [currentB[j], currentB[i]];
                }
                
                // Test if this combination has any rematches
                let clean = true;
                for (let i = 0; i < this.matches.length; i++) {
                    const pA = this.matches[i].pA;
                    const pB = currentB[i];
                    if (this.players[pA].history.includes(parseInt(pB))) {
                        clean = false;
                        break;
                    }
                }
                
                if (clean) {
                    // Perfect match found! Apply it.
                    for (let i = 0; i < this.matches.length; i++) {
                        this.matches[i].pB = currentB[i];
                    }
                    this.checkConflicts();
                    return;
                }
            } // Close the attempt for loop
            
            // If we couldn't find a clean one, just apply the last attempt and let user fix
            this.checkConflicts();
        },

        checkConflicts() {
            this.hasRematches = false;
            this.hasDuplicates = false;
            let selectedB = [];
            
            this.matches.forEach(m => {
                m.isRematch = false;
                if (m.pB != 0) {
                    // Check Duplicate selection
                    if (selectedB.includes(m.pB)) {
                        this.hasDuplicates = true;
                    }
                    selectedB.push(m.pB);
                    
                    // Check Rematch
                    if (this.players[m.pA].history.includes(parseInt(m.pB))) {
                        m.isRematch = true;
                        this.hasRematches = true;
                    }
                }
            });
            
            this.isValid = !this.hasRematches && !this.hasDuplicates && selectedB.length === this.path1.length && !selectedB.includes("0") && this.path1.length > 0;
        }
    }));
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
