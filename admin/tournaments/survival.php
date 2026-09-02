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
        'name' => $p['display_name'] . ' (' . $p['its_id'] . ')',
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
    <div class="p-6 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
        <p class="text-sm text-gray-600 font-medium">
            Review and adjust the pairs. 
            <span class="text-red-500 font-bold ml-2" x-show="hasRematches">⚠️ Rematch Warning Detected!</span>
        </p>
        <button type="button" @click="shufflePath2()" class="btn-outline text-sm py-1.5 px-3">
            Auto-Suggest / Reshuffle
        </button>
    </div>

    <form action="" method="POST" id="survivalForm">
        <?= csrf_field() ?>
        <input type="hidden" name="pair_count" :value="matches.length">
        
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-12 gap-4 text-xs font-semibold text-gray-400 uppercase tracking-wide border-b pb-2">
                <div class="col-span-1 text-center">Match</div>
                <div class="col-span-5">Path 1 (Won R1, Lost R2)</div>
                <div class="col-span-1 text-center"></div>
                <div class="col-span-5">Path 2 (Lost R1, Won R2)</div>
            </div>

            <template x-for="(match, index) in matches" :key="index">
                <div class="grid grid-cols-12 gap-4 items-center p-3 rounded-lg border transition"
                     :class="match.isRematch ? 'bg-red-50 border-red-200' : 'bg-white border-transparent hover:bg-gray-50'">
                    
                    <div class="col-span-1 text-center font-bold text-gray-400" x-text="index + 1"></div>
                    
                    <!-- Path 1 (Fixed Left Side) -->
                    <div class="col-span-5">
                        <input type="hidden" :name="'pA_' + index" :value="match.pA">
                        <div class="font-medium text-tkmi-navy" x-text="players[match.pA].name"></div>
                    </div>
                    
                    <div class="col-span-1 text-center text-gray-400 text-xs font-bold">VS</div>
                    
                    <!-- Path 2 (Dropdown Right Side) -->
                    <div class="col-span-5">
                        <div x-data="{ 
                            open: false,
                            get selectedText() { return match.pB != '0' ? players[match.pB].name : '-- Select Opponent --' }
                        }" class="relative w-full">
                            <input type="hidden" :name="'pB_' + index" x-model="match.pB">
                            <button type="button" @click="open = !open" @click.away="open = false" 
                                    class="w-full flex items-center justify-between bg-white border text-sm font-bold rounded-lg p-2.5 transition-colors text-left"
                                    :class="match.isRematch ? 'border-red-400 focus:ring-red-400 bg-red-50 text-red-700' : 'border-slate-200 text-slate-700 hover:bg-slate-50'">
                                <span x-text="selectedText"></span>
                                <i class="ph-bold ph-caret-down text-slate-400 text-sm transition-transform duration-200" :class="open ? 'rotate-180' : ''"></i>
                            </button>

                            <div x-show="open" 
                                 x-transition.opacity.duration.200ms
                                 class="absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden z-50 max-h-48 overflow-y-auto custom-scrollbar"
                                 style="display: none;">
                                <button type="button" @click="match.pB = '0'; checkConflicts(); open = false;" class="w-full text-left px-3 py-2 text-sm font-bold text-slate-400 hover:bg-slate-50 border-b border-slate-50">-- Select Opponent --</button>
                                <template x-for="pB_id in path2" :key="pB_id">
                                    <button type="button" 
                                            @click="match.pB = pB_id; checkConflicts(); open = false;"
                                            class="w-full text-left px-3 py-2 text-sm font-bold hover:bg-slate-50 transition-colors border-b border-slate-50 last:border-0"
                                            :class="match.pB == pB_id ? 'bg-blue-50 text-blue-700' : 'text-slate-600'">
                                        <span x-text="players[pB_id].name"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                        
                        <div x-show="match.isRematch" class="text-red-500 text-xs font-bold mt-1.5 flex items-center gap-1">
                            <i class="ph-fill ph-warning-circle"></i> Rematch! Played in R1.
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="p-4 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
            <button type="button" @click="checkConflicts()" class="btn-outline text-sm" x-show="!isValid">
                Validate Pairs
            </button>
            <button type="submit" class="btn-navy text-sm" :disabled="!isValid" :class="!isValid ? 'opacity-50 cursor-not-allowed' : ''">
                Save Survival Bracket
            </button>
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
