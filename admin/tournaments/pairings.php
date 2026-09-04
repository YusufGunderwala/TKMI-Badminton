<?php
// ============================================================
// Tournaments - Manual Pairing Studio (Round 1 & Round 2)
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/matchmaker.php';

requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$round = $_GET['round'] ?? 'r1';
$tournament = getTournament($id);

if (!$tournament) {
    flash_set('tournaments', 'Tournament not found.', 'error');
    header('Location: ' . BASE_URL . '/admin/tournaments/');
    exit;
}

$pdo = db();
$admin = currentAdmin();
$error = '';

// Check if round already generated
$roundKey = $round;
if ($tournament['format'] === FORMAT_SWISS_KNOCKOUT) {
    $roundKey = $round === 'r2' ? ROUND_STAGE1_R2 : ROUND_STAGE1_R1;
    if ($round === 'r2' && !Matchmaker::isRoundComplete($id, ROUND_STAGE1_R1)) {
        flash_set('tournament_view', 'Cannot start Round 2 manual pairing until Round 1 matches finish.', 'error');
        header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
        exit;
    }
}

$stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
$stmt->execute([$id, $roundKey]);
if ($stmt->fetchColumn() > 0) {
    flash_set('tournament_view', getRoundLabel($roundKey) . ' has already been generated.', 'info');
    header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
    exit;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid security token.';
    } else {
        $pairCount = (int)($_POST['pair_count'] ?? 0);
        $byePlayerId = !empty($_POST['bye_player_id']) ? (int)$_POST['bye_player_id'] : null;
        $pairs = [];
        $selectedPlayers = [];

        for ($i = 0; $i < $pairCount; $i++) {
            $pA = (int)($_POST["pA_$i"] ?? 0);
            $pB = (int)($_POST["pB_$i"] ?? 0);
            if ($pA && $pB) {
                $pairs[] = [$pA, $pB];
                $selectedPlayers[] = $pA;
                $selectedPlayers[] = $pB;
            }
        }

        // Basic duplicate check
        if (count($selectedPlayers) !== count(array_unique($selectedPlayers))) {
            $error = 'Duplicate player detected! A player cannot be assigned to more than one match.';
        } elseif (empty($pairs)) {
            $error = 'Please configure at least one match pair.';
        } else {
            try {
                if ($tournament['format'] === FORMAT_CUSTOM_KNOCKOUT) {
                    Matchmaker::saveCustomRound($id, $roundKey, $pairs, $byePlayerId, $admin['id']);
                    flash_set('tournament_view', getRoundLabel($roundKey) . ' manual pairings saved and fixtures generated!', 'success');
                } elseif ($round === 'r2') {
                    Matchmaker::saveCustomRound2($id, $pairs, $byePlayerId, $admin['id']);
                    flash_set('tournament_view', 'Round 2 manual pairings saved and fixtures generated!', 'success');
                } else {
                    Matchmaker::saveCustomRound1($id, $pairs, $byePlayerId, $admin['id']);
                    flash_set('tournament_view', 'Round 1 manual pairings saved and tournament is now LIVE!', 'success');
                }
                AppCache::flush();
                header('Location: ' . BASE_URL . '/admin/tournaments/view.php?id=' . $id);
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Prepare available players
$availablePlayers = [];
if ($tournament['format'] === FORMAT_SWISS_KNOCKOUT && $round === 'r2') {
    // Get winners and losers
    $stmtW = $pdo->prepare('SELECT p.*, r.wins, r.losses FROM players p JOIN player_tournament_records r ON p.id = r.player_id WHERE r.tournament_id = ? AND r.wins = 1 AND r.losses = 0');
    $stmtW->execute([$id]);
    $winners = $stmtW->fetchAll();

    $stmtL = $pdo->prepare('SELECT p.*, r.wins, r.losses FROM players p JOIN player_tournament_records r ON p.id = r.player_id WHERE r.tournament_id = ? AND r.wins = 0 AND r.losses = 1');
    $stmtL->execute([$id]);
    $losers = $stmtL->fetchAll();

    $availablePlayers = array_merge($winners, $losers);
} else {
    // Get all enrolled players
    $stmt = $pdo->prepare('SELECT p.* FROM players p JOIN tournament_players tp ON p.id = tp.player_id WHERE tp.tournament_id = ? ORDER BY p.display_name ASC');
    $stmt->execute([$id]);
    $availablePlayers = $stmt->fetchAll();
}

$playerData = [];
$allPlayerIds = [];
foreach ($availablePlayers as $p) {
    $allPlayerIds[] = (int)$p['id'];
    $playerData[$p['id']] = [
        'id' => (int)$p['id'],
        'name' => $p['display_name'],
        'full_name' => $p['full_name'],
        'its_id' => $p['its_id'],
        'mohallah' => $p['mohallah'] ?? '',
        'history' => Matchmaker::getPreviousOpponents($id, (int)$p['id'])
    ];
}

$pageTitle = 'Manual Pairing Studio: ' . $tournament['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $id ?>" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] flex items-center gap-1 mb-2 transition-colors">
            <i class="ph-bold ph-arrow-left"></i> Back to Tournament Details
        </a>
        <div class="flex items-center gap-3">
            <h2 class="text-3xl font-black font-display text-[#0f2044]">
                Manual Pairing Studio
            </h2>
            <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider bg-blue-100 text-blue-800">
                <?= getRoundLabel($roundKey) ?>
            </span>
        </div>
        <p class="text-slate-500 font-medium mt-1">
            <?= e($tournament['name']) ?> &bull; <?= count($availablePlayers) ?> Enrolled Participants
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div class="bg-red-50 text-red-700 p-4 rounded-2xl mb-6 text-sm border border-red-200 flex items-center gap-2">
        <i class="ph-fill ph-warning-circle text-red-500 text-lg"></i>
        <span class="font-bold"><?= e($error) ?></span>
    </div>
<?php endif; ?>

<!-- Interactive Alpine Controller -->
<div x-data="pairingStudio()" x-init="initStudio()" class="space-y-6">

    <!-- Top Action Bar & Unassigned Players Counter -->
    <div class="bg-white border border-slate-200 rounded-3xl p-6 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-5 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-lg">
                    <i class="ph-bold ph-users-three"></i>
                </div>
                <div>
                    <h3 class="font-black text-slate-900 text-base">Roster Assignment Status</h3>
                    <p class="text-xs text-slate-500">
                        <span x-text="assignedCount" class="font-bold text-blue-600"></span> of <span x-text="totalPlayers" class="font-bold text-slate-800"></span> players paired
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="addMatch()" class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-1.5 shadow-sm">
                    <i class="ph-bold ph-plus"></i> <span>Add Match</span>
                </button>
                <button type="button" @click="autoShuffle()" class="bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-1.5 shadow-sm">
                    <i class="ph-bold ph-shuffle"></i> <span>Auto-Suggest Pairs</span>
                </button>
                <button type="button" @click="clearAll()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold px-4 py-2.5 rounded-xl text-xs transition flex items-center gap-1.5">
                    <i class="ph-bold ph-eraser"></i> <span>Clear Pairs</span>
                </button>
            </div>
        </div>

        <!-- Unassigned Chips -->
        <div class="mt-4">
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">Unassigned Players</div>
            <div class="flex flex-wrap gap-2">
                <template x-for="p in unassignedPlayers" :key="p.id">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200/60 shadow-2xs">
                        <i class="ph-bold ph-user text-amber-600"></i>
                        <span x-text="p.name"></span>
                        <span class="text-[10px] text-amber-600 font-mono" x-text="'(' + p.its_id + ')'"></span>
                    </span>
                </template>
                <template x-if="unassignedPlayers.length === 0">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                        <i class="ph-bold ph-check-circle text-emerald-600"></i> All players have been successfully assigned!
                    </span>
                </template>
            </div>
        </div>
    </div>

    <!-- Warnings / Errors Notice -->
    <div x-show="hasDuplicates || hasSelfPlay || hasRematches" 
         class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-amber-900 text-xs font-bold flex items-start gap-2.5">
        <i class="ph-fill ph-warning text-amber-600 text-lg mt-0.5"></i>
        <div class="space-y-1">
            <div x-show="hasDuplicates">⚠️ <strong>Duplicate Selection:</strong> One or more players are chosen in multiple matches.</div>
            <div x-show="hasSelfPlay">⚠️ <strong>Self-Match:</strong> A match has the same player in both slots.</div>
            <div x-show="hasRematches">⚠️ <strong>Rematch Detected:</strong> Opponents in highlighted matches have played each other before.</div>
        </div>
    </div>

    <!-- Form for Submitting Matches -->
    <form action="" method="POST" id="pairingForm">
        <?= csrf_field() ?>
        <input type="hidden" name="pair_count" :value="matches.length">

        <!-- Matches Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <template x-for="(m, idx) in matches" :key="idx">
                <div class="bg-white border rounded-3xl p-5 shadow-sm transition-all"
                     :class="{
                         'border-red-300 bg-red-50/20': m.pA && m.pB && (m.pA === m.pB || isDuplicate(m.pA, idx) || isDuplicate(m.pB, idx)),
                         'border-amber-300 bg-amber-50/20': m.isRematch,
                         'border-slate-200': !(m.pA && m.pB && (m.pA === m.pB || isDuplicate(m.pA, idx) || isDuplicate(m.pB, idx))) && !m.isRematch
                     }">
                    
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-black uppercase tracking-wider text-slate-400" x-text="'Match #' + (idx + 1)"></span>
                        <div class="flex items-center gap-2">
                            <span x-show="m.isRematch" class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">Rematch</span>
                            <button type="button" @click="swapMatch(idx)" title="Swap Sides" class="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition">
                                <i class="ph-bold ph-arrows-left-right text-sm"></i>
                            </button>
                            <button type="button" @click="removeMatch(idx)" title="Remove Match" class="p-1 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition" x-show="matches.length > 1">
                                <i class="ph-bold ph-trash text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <!-- Player A Select -->
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Player A</label>
                            <div x-data="{ open: false }" @click.away="open = false" class="relative">
                                <input type="hidden" :name="'pA_' + idx" :value="m.pA">
                                <button type="button" @click="open = !open" 
                                        class="w-full flex items-center justify-between bg-white border border-slate-200 text-slate-800 text-xs sm:text-sm font-bold rounded-xl p-2.5 sm:p-3 shadow-sm hover:border-blue-400 transition-colors"
                                        :class="open ? 'border-blue-500 ring-2 ring-blue-500/20' : ''">
                                    <span class="truncate" :class="m.pA == 0 ? 'text-slate-400' : ''" x-text="m.pA != 0 ? (allPlayersList.find(p => p.id == m.pA)?.name || '') : '-- Select Player A --'"></span>
                                    <i class="ph-bold ph-caret-down text-slate-400 text-sm transition-transform" :class="open ? 'rotate-180 text-blue-500' : ''"></i>
                                </button>
                                <div x-show="open" x-transition.opacity.duration.200ms
                                     class="absolute left-0 right-0 top-[calc(100%+8px)] bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden"
                                     style="display: none;">
                                    <div class="max-h-60 overflow-y-auto custom-scrollbar">
                                        <div @click="m.pA = 0; checkConflicts(); open = false;" class="px-4 py-3 border-b border-slate-100 hover:bg-slate-50 cursor-pointer text-xs font-bold text-slate-400">
                                            -- Select Player A --
                                        </div>
                                        <template x-for="p in allPlayersList" :key="p.id">
                                            <div @click.stop="m.pA = p.id; checkConflicts(); open = false;"
                                                 class="px-4 py-2.5 border-b border-slate-50 hover:bg-blue-50 cursor-pointer transition-colors flex items-center justify-between"
                                                 :class="m.pA == p.id ? 'bg-blue-50/60 border-l-2 border-l-blue-500' : 'border-l-2 border-l-transparent'">
                                                <div>
                                                    <div class="text-sm font-bold text-slate-800" x-text="p.name"></div>
                                                    <div class="text-[10px] font-bold text-slate-500" x-text="'ITS: ' + p.its_id"></div>
                                                </div>
                                                <div x-show="m.pA == p.id" class="text-blue-600">
                                                    <i class="ph-bold ph-check-circle text-lg"></i>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-center">
                            <span class="text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded bg-slate-100 text-slate-400">VS</span>
                        </div>

                        <!-- Player B Select -->
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Player B</label>
                            <div x-data="{ open: false }" @click.away="open = false" class="relative">
                                <input type="hidden" :name="'pB_' + idx" :value="m.pB">
                                <button type="button" @click="open = !open" 
                                        class="w-full flex items-center justify-between bg-white border border-slate-200 text-slate-800 text-xs sm:text-sm font-bold rounded-xl p-2.5 sm:p-3 shadow-sm hover:border-blue-400 transition-colors"
                                        :class="open ? 'border-blue-500 ring-2 ring-blue-500/20' : ''">
                                    <span class="truncate" :class="m.pB == 0 ? 'text-slate-400' : ''" x-text="m.pB != 0 ? (allPlayersList.find(p => p.id == m.pB)?.name || '') : '-- Select Player B --'"></span>
                                    <i class="ph-bold ph-caret-down text-slate-400 text-sm transition-transform" :class="open ? 'rotate-180 text-blue-500' : ''"></i>
                                </button>
                                <div x-show="open" x-transition.opacity.duration.200ms
                                     class="absolute left-0 right-0 top-[calc(100%+8px)] bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden"
                                     style="display: none;">
                                    <div class="max-h-60 overflow-y-auto custom-scrollbar">
                                        <div @click="m.pB = 0; checkConflicts(); open = false;" class="px-4 py-3 border-b border-slate-100 hover:bg-slate-50 cursor-pointer text-xs font-bold text-slate-400">
                                            -- Select Player B --
                                        </div>
                                        <template x-for="p in allPlayersList" :key="p.id">
                                            <div @click.stop="m.pB = p.id; checkConflicts(); open = false;"
                                                 class="px-4 py-2.5 border-b border-slate-50 hover:bg-blue-50 cursor-pointer transition-colors flex items-center justify-between"
                                                 :class="m.pB == p.id ? 'bg-blue-50/60 border-l-2 border-l-blue-500' : 'border-l-2 border-l-transparent'">
                                                <div>
                                                    <div class="text-sm font-bold text-slate-800" x-text="p.name"></div>
                                                    <div class="text-[10px] font-bold text-slate-500" x-text="'ITS: ' + p.its_id"></div>
                                                </div>
                                                <div x-show="m.pB == p.id" class="text-blue-600">
                                                    <i class="ph-bold ph-check-circle text-lg"></i>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Optional BYE Recipient Selector (If odd player count) -->
        <template x-if="totalPlayers % 2 !== 0">
            <div class="mt-6 bg-purple-50/70 border border-purple-200 rounded-3xl p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-lg flex-shrink-0">
                        <i class="ph-bold ph-gift"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-black text-purple-900 text-sm">Automatic BYE Recipient (Odd Player Count)</h4>
                        <p class="text-xs text-purple-700/80 mt-0.5">Since there are <span x-text="totalPlayers"></span> players, 1 player receives an automatic BYE win for this round.</p>
                        
                        <div class="mt-3 max-w-md">
                            <div x-data="{ open: false }" @click.away="open = false" class="relative">
                                <input type="hidden" name="bye_player_id" :value="byePlayer">
                                <button type="button" @click="open = !open" 
                                        class="w-full flex items-center justify-between bg-white border border-purple-200 text-purple-900 text-xs sm:text-sm font-bold rounded-xl p-2.5 sm:p-3 shadow-sm hover:border-purple-400 transition-colors"
                                        :class="open ? 'border-purple-500 ring-2 ring-purple-500/20' : ''">
                                    <span class="truncate" :class="byePlayer == 0 ? 'text-purple-400' : ''" x-text="byePlayer != 0 ? (allPlayersList.find(p => p.id == byePlayer)?.name || '') : '-- Select Player for BYE --'"></span>
                                    <i class="ph-bold ph-caret-down text-purple-400 text-sm transition-transform" :class="open ? 'rotate-180 text-purple-500' : ''"></i>
                                </button>
                                <div x-show="open" x-transition.opacity.duration.200ms
                                     class="absolute left-0 right-0 top-[calc(100%+8px)] bg-white border border-slate-200 rounded-xl shadow-xl z-50 overflow-hidden"
                                     style="display: none;">
                                    <div class="max-h-60 overflow-y-auto custom-scrollbar">
                                        <div @click="byePlayer = 0; checkConflicts(); open = false;" class="px-4 py-3 border-b border-slate-100 hover:bg-slate-50 cursor-pointer text-xs font-bold text-slate-400">
                                            -- Select Player for BYE --
                                        </div>
                                        <template x-for="p in allPlayersList" :key="p.id">
                                            <div @click.stop="byePlayer = p.id; checkConflicts(); open = false;"
                                                 class="px-4 py-2.5 border-b border-slate-50 hover:bg-purple-50 cursor-pointer transition-colors flex items-center justify-between"
                                                 :class="byePlayer == p.id ? 'bg-purple-50/60 border-l-2 border-l-purple-500' : 'border-l-2 border-l-transparent'">
                                                <div>
                                                    <div class="text-sm font-bold text-slate-800" x-text="p.name"></div>
                                                    <div class="text-[10px] font-bold text-slate-500" x-text="'ITS: ' + p.its_id"></div>
                                                </div>
                                                <div x-show="byePlayer == p.id" class="text-purple-600">
                                                    <i class="ph-bold ph-check-circle text-lg"></i>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Submit Bar -->
        <div class="mt-8 pt-6 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
            <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $id ?>" class="w-full sm:w-auto bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 font-bold py-3 px-6 rounded-xl transition text-center text-sm">
                Cancel
            </a>

            <button type="submit" 
                    :disabled="!isValid" 
                    :class="!isValid ? 'opacity-50 cursor-not-allowed' : 'hover:bg-blue-900 shadow-md'"
                    class="w-full sm:w-auto bg-[#0f2044] text-white font-black py-3.5 px-8 rounded-xl transition flex items-center justify-center gap-2 text-sm">
                <i class="ph-bold ph-check-circle text-lg"></i>
                <span>Save & Launch <?= getRoundLabel($roundKey) ?></span>
            </button>
        </div>
    </form>
</div>

<script>
const ALL_PLAYERS_RAW = <?= json_encode($playerData) ?>;
const TOTAL_PLAYERS_COUNT = <?= count($availablePlayers) ?>;

function pairingStudio() {
    return {
        allPlayers: ALL_PLAYERS_RAW,
        totalPlayers: TOTAL_PLAYERS_COUNT,
        matches: [],
        byePlayer: 0,
        hasDuplicates: false,
        hasSelfPlay: false,
        hasRematches: false,
        isValid: false,

        get allPlayersList() {
            return Object.values(this.allPlayers);
        },

        get assignedCount() {
            let count = 0;
            this.matches.forEach(m => {
                if (m.pA) count++;
                if (m.pB) count++;
            });
            if (this.byePlayer) count++;
            return count;
        },

        get unassignedPlayers() {
            let assignedIds = new Set();
            this.matches.forEach(m => {
                if (m.pA) assignedIds.add(m.pA);
                if (m.pB) assignedIds.add(m.pB);
            });
            if (this.byePlayer) assignedIds.add(this.byePlayer);
            return this.allPlayersList.filter(p => !assignedIds.has(p.id));
        },

        initStudio() {
            const pairCount = Math.floor(this.totalPlayers / 2);
            this.matches = [];
            for (let i = 0; i < pairCount; i++) {
                this.matches.push({ pA: 0, pB: 0, isRematch: false });
            }
            this.autoShuffle();
        },

        addMatch() {
            this.matches.push({ pA: 0, pB: 0, isRematch: false });
            this.checkConflicts();
        },

        removeMatch(idx) {
            this.matches.splice(idx, 1);
            this.checkConflicts();
        },

        autoShuffle() {
            let pool = [...this.allPlayersList.map(p => p.id)];
            // Fisher-Yates shuffle
            for (let i = pool.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [pool[i], pool[j]] = [pool[j], pool[i]];
            }

            if (this.totalPlayers % 2 !== 0) {
                this.byePlayer = pool.pop();
            } else {
                this.byePlayer = 0;
            }

            for (let i = 0; i < this.matches.length; i++) {
                this.matches[i].pA = pool[i * 2] || 0;
                this.matches[i].pB = pool[i * 2 + 1] || 0;
            }
            this.checkConflicts();
        },

        clearAll() {
            this.byePlayer = 0;
            this.matches.forEach(m => {
                m.pA = 0;
                m.pB = 0;
                m.isRematch = false;
            });
            this.checkConflicts();
        },

        swapMatch(idx) {
            const temp = this.matches[idx].pA;
            this.matches[idx].pA = this.matches[idx].pB;
            this.matches[idx].pB = temp;
            this.checkConflicts();
        },

        isDuplicate(pid, currentIdx) {
            if (!pid) return false;
            let occurrences = 0;
            this.matches.forEach((m, idx) => {
                if (m.pA === pid) occurrences++;
                if (m.pB === pid) occurrences++;
            });
            if (this.byePlayer === pid) occurrences++;
            return occurrences > 1;
        },

        checkConflicts() {
            this.hasDuplicates = false;
            this.hasSelfPlay = false;
            this.hasRematches = false;
            let seen = new Set();
            let allFilled = true;

            if (this.totalPlayers % 2 !== 0 && !this.byePlayer) {
                allFilled = false;
            }

            if (this.byePlayer) {
                seen.add(this.byePlayer);
            }

            this.matches.forEach(m => {
                m.isRematch = false;
                if (!m.pA || !m.pB) {
                    allFilled = false;
                    return;
                }

                if (m.pA === m.pB) {
                    this.hasSelfPlay = true;
                }

                if (seen.has(m.pA) || seen.has(m.pB)) {
                    this.hasDuplicates = true;
                }
                seen.add(m.pA);
                seen.add(m.pB);

                // Rematch check
                const pAData = this.allPlayers[m.pA];
                if (pAData && pAData.history && pAData.history.includes(m.pB)) {
                    m.isRematch = true;
                    this.hasRematches = true;
                }
            });

            this.isValid = allFilled && !this.hasDuplicates && !this.hasSelfPlay;
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
