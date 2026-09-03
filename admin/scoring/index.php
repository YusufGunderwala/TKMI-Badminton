<?php
// ============================================================
// Live Scoring Hub & Match Selector
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/matchmaker.php';

requireLogin();

$pdo = db();
$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

$liveTournaments = getLiveTournaments();

// If no tournament specified but there is only 1 live tournament, auto-select it
if (!$tournamentId && count($liveTournaments) === 1) {
    $tournamentId = (int)$liveTournaments[0]['id'];
}

$selectedTourney = $tournamentId ? getTournament($tournamentId) : null;
$matches = [];
$groupedMatches = [];

if ($selectedTourney) {
    // 1. Fetch all tournament matches in creation order
    $stmt = $pdo->prepare('
        SELECT m.*, 
               pa.display_name as pa_name, pb.display_name as pb_name,
               ta.display_name as ta_name, tb.display_name as tb_name
        FROM matches m
        LEFT JOIN players pa ON m.participant_a_id = pa.id
        LEFT JOIN players pb ON m.participant_b_id = pb.id
        LEFT JOIN teams ta ON m.team_a_id = ta.id
        LEFT JOIN teams tb ON m.team_b_id = tb.id
        WHERE m.tournament_id = ?
        ORDER BY m.id ASC
    ');
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll();

    // 2. Fetch all games for this tournament to show full score history
    $gamesStmt = $pdo->prepare('
        SELECT g.* 
        FROM games g
        JOIN matches m ON g.match_id = m.id
        WHERE m.tournament_id = ?
        ORDER BY g.match_id, g.game_number
    ');
    $gamesStmt->execute([$tournamentId]);
    $allGames = $gamesStmt->fetchAll();
    $matchGames = [];
    foreach ($allGames as $g) {
        $matchGames[$g['match_id']][] = $g;
    }

    // 3. Build Feeder / Dependency relationships for bracket progression
    $incomingFeeders = [];
    foreach ($matches as $src) {
        if (!empty($src['next_match_id_winner'])) {
            $incomingFeeders[$src['next_match_id_winner']]['winner'][] = $src;
        }
        if (!empty($src['next_match_id_loser'])) {
            $incomingFeeders[$src['next_match_id_loser']]['loser'][] = $src;
        }
    }

    // 4. Enrich each match with dependency labels and readiness
    $liveMatchCount = 0;
    foreach ($matches as &$m) {
        $isDoubles = !empty($m['team_a_id']);
        $hasParticipantA = $isDoubles ? !empty($m['team_a_id']) : !empty($m['participant_a_id']);
        $hasParticipantB = $isDoubles ? !empty($m['team_b_id']) : !empty($m['participant_b_id']);
        $m['is_ready'] = $hasParticipantA && $hasParticipantB;

        if ($m['status'] === 'in_progress') {
            $liveMatchCount++;
        }

        // Slot A
        if ($hasParticipantA) {
            $m['display_a'] = $isDoubles ? $m['ta_name'] : $m['pa_name'];
            $m['is_pending_a'] = false;
        } else {
            $m['is_pending_a'] = true;
            if ($m['round_key'] === '3rd' || $m['round_key'] === '3rd_place') {
                $f = $incomingFeeders[$m['id']]['loser'][0] ?? null;
                $m['display_a'] = $f ? 'Loser of ' . getRoundLabel($f['round_key']) . ' #' . $f['match_number'] : 'Semi Final #1 Loser';
            } else {
                $f = $incomingFeeders[$m['id']]['winner'][0] ?? null;
                $m['display_a'] = $f ? 'Winner of ' . getRoundLabel($f['round_key']) . ' #' . $f['match_number'] : 'Previous Match Winner';
            }
        }

        // Slot B
        if ($hasParticipantB) {
            $m['display_b'] = $isDoubles ? $m['tb_name'] : $m['pb_name'];
            $m['is_pending_b'] = false;
        } else {
            $m['is_pending_b'] = true;
            if ($m['round_key'] === '3rd' || $m['round_key'] === '3rd_place') {
                $f = $incomingFeeders[$m['id']]['loser'][1] ?? null;
                $m['display_b'] = $f ? 'Loser of ' . getRoundLabel($f['round_key']) . ' #' . $f['match_number'] : 'Semi Final #2 Loser';
            } else {
                $f = $incomingFeeders[$m['id']]['winner'][1] ?? null;
                $m['display_b'] = $f ? 'Winner of ' . getRoundLabel($f['round_key']) . ' #' . $f['match_number'] : 'Previous Match Winner';
            }
        }

        $m['games'] = $matchGames[$m['id']] ?? [];
    }
    unset($m);

    // 5. Group into Stage 1 and Stage 2 with chronological ordering
    $roundWeights = [
        ROUND_STAGE1_R1       => 1,
        ROUND_STAGE1_R2       => 2,
        ROUND_STAGE1_SURVIVAL => 3,
        ROUND_R16             => 4,
        ROUND_QF              => 5,
        ROUND_SF              => 6,
        ROUND_3RD_PLACE       => 7,
        '3rd'                 => 7,
        ROUND_FINAL           => 8,
    ];

    $stage1Grouped = [];
    $stage2Grouped = [];
    $otherGrouped = [];

    foreach ($matches as $m) {
        $rk = $m['round_key'];
        if (in_array($rk, [ROUND_STAGE1_R1, ROUND_STAGE1_R2, ROUND_STAGE1_SURVIVAL])) {
            $stage1Grouped[$rk][] = $m;
        } elseif (in_array($rk, [ROUND_R16, ROUND_QF, ROUND_SF, ROUND_3RD_PLACE, '3rd', ROUND_FINAL])) {
            $stage2Grouped[$rk][] = $m;
        } else {
            $otherGrouped[$rk][] = $m;
        }
    }

    uksort($stage1Grouped, fn($a, $b) => ($roundWeights[$a] ?? 99) <=> ($roundWeights[$b] ?? 99));
    uksort($stage2Grouped, fn($a, $b) => ($roundWeights[$a] ?? 99) <=> ($roundWeights[$b] ?? 99));

    // Backward-compat for single groupedMatches
    $groupedMatches = array_merge($stage1Grouped, $stage2Grouped, $otherGrouped);

    // 6. Fetch Stage 1 Swiss Qualification Records (if applicable)
    $tier1Qualifiers = [];
    $tier2Qualifiers = [];
    $eliminatedCount = 0;

    if ($selectedTourney['format'] === 'swiss_knockout') {
        $recStmt = $pdo->prepare('
            SELECT p.id, p.display_name, p.mohallah, ptr.wins, ptr.losses
            FROM player_tournament_records ptr
            JOIN players p ON ptr.player_id = p.id
            WHERE ptr.tournament_id = ?
            ORDER BY ptr.wins DESC, ptr.losses ASC, p.display_name ASC
        ');
        $recStmt->execute([$tournamentId]);
        $allRecords = $recStmt->fetchAll();
        foreach ($allRecords as $r) {
            if ($r['wins'] === 2 && $r['losses'] === 0) {
                $tier1Qualifiers[] = $r;
            } elseif ($r['wins'] === 2 && $r['losses'] === 1) {
                $tier2Qualifiers[] = $r;
            } elseif ($r['losses'] >= 2) {
                $eliminatedCount++;
            }
        }
    }
}

$pageTitle = 'Live Scoring Hub' . ($selectedTourney ? ': ' . $selectedTourney['name'] : '');
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="text-slate-400 hover:text-[#0f2044] transition-colors">
                <i class="ph-bold ph-arrow-left text-lg"></i>
            </a>
            <h2 class="text-3xl font-black font-display text-[#0f2044]">Live Scoring Hub</h2>
        </div>
        <p class="text-slate-500 font-medium ml-7">Select an active match to control live court scoring in real-time.</p>
    </div>

    <?php if ($selectedTourney && count($liveTournaments) > 1): ?>
        <a href="<?= BASE_URL ?>/admin/scoring/index.php" class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:border-[#0f2044] font-bold px-4 py-2.5 rounded-xl text-sm transition shadow-sm">
            <i class="ph-bold ph-arrows-left-right"></i> Switch Tournament
        </a>
    <?php endif; ?>
</div>

<?= flash_html('scoring') ?>

<?php if (!$selectedTourney): ?>
    <!-- Tournament Selection View -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (empty($liveTournaments)): ?>
            <div class="col-span-full bg-white border border-slate-200 rounded-2xl p-16 text-center shadow-sm">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-slate-50 text-slate-300 mb-6">
                    <i class="ph-fill ph-monitor-play text-4xl"></i>
                </div>
                <h3 class="text-2xl font-bold font-display text-slate-800 mb-2">No Active Tournaments</h3>
                <p class="text-slate-500 max-w-md mx-auto mb-6">You must create and start a tournament before live court scoring is available.</p>
                <a href="<?= BASE_URL ?>/admin/tournaments/index.php" class="inline-flex items-center gap-2 bg-[#0f2044] hover:bg-blue-900 text-white font-bold px-6 py-3 rounded-xl shadow-md transition">
                    <i class="ph-bold ph-trophy"></i> Go to Tournaments
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($liveTournaments as $t): 
                $mCount = (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE tournament_id = {$t['id']}")->fetchColumn();
                $liveCount = (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE tournament_id = {$t['id']} AND status = 'in_progress'")->fetchColumn();
            ?>
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:border-blue-400 transition-all flex flex-col group">
                    <div class="flex justify-between items-start mb-4">
                        <span class="px-2.5 py-1 bg-red-50 text-red-600 rounded text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 border border-red-100">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span> LIVE
                        </span>
                        <span class="text-xs font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded">
                            <?= e($t['gender']) ?> <?= ucfirst(e($t['match_type'])) ?>
                        </span>
                    </div>
                    
                    <h3 class="text-2xl font-black font-display text-[#0f2044] mb-1 group-hover:text-blue-600 transition-colors"><?= e($t['name']) ?></h3>
                    <p class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-6"><?= $mCount ?> Matches • <?= $liveCount ?> Live on Court</p>
                    
                    <a href="<?= BASE_URL ?>/admin/scoring/index.php?tournament_id=<?= $t['id'] ?>" class="mt-auto w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold py-3 px-4 rounded-xl text-center transition-all shadow-md flex items-center justify-center gap-2">
                        <span>Select Tournament</span> <i class="ph-bold ph-arrow-right"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- Matches in Tournament View -->
    <div class="bg-white border border-slate-200 rounded-2xl p-6 mb-8 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl shadow-inner font-bold">
                🏸
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-2xl font-black font-display text-[#0f2044]"><?= e($selectedTourney['name']) ?></h3>
                    <?php if ($liveMatchCount > 0): ?>
                        <span class="px-2.5 py-0.5 bg-red-500 text-white text-[10px] font-black uppercase tracking-wider rounded-full flex items-center gap-1 shadow-xs animate-pulse">
                            <span>●</span> <?= $liveMatchCount ?> ON COURT
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-0.5 bg-blue-50 text-blue-700 text-[10px] font-bold uppercase tracking-wider rounded-full border border-blue-200">
                            Active Tournament
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs font-bold text-slate-400 mt-0.5"><?= e($selectedTourney['gender']) ?> <?= ucfirst(e($selectedTourney['match_type'])) ?> &bull; <?= count($matches) ?> total matches</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $selectedTourney['id'] ?>" class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2.5 rounded-xl text-sm transition">
                <i class="ph-bold ph-gear"></i> Tournament Settings
            </a>
            <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $selectedTourney['id'] ?>" target="_blank" class="inline-flex items-center gap-2 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-4 py-2.5 rounded-xl text-sm transition">
                <i class="ph-bold ph-arrow-square-out"></i> Public View
            </a>
        </div>
    </div>

    <?php
    $tId = (int)$selectedTourney['id'];
    $isSwiss = ($selectedTourney['format'] === 'swiss_knockout');
    
    if ($isSwiss):
        $hasR1 = isset($groupedMatches[ROUND_STAGE1_R1]);
        $hasR2 = isset($groupedMatches[ROUND_STAGE1_R2]);
        $hasSurvival = isset($groupedMatches[ROUND_STAGE1_SURVIVAL]);
        $hasStage2 = false;
        foreach (['r16', 'qf', 'sf', 'final', '3rd_place'] as $s2k) {
            if (isset($groupedMatches[$s2k])) { $hasStage2 = true; break; }
        }

        $r1Done = $hasR1 && Matchmaker::isRoundComplete($tId, ROUND_STAGE1_R1);
        $r2Done = $hasR2 && Matchmaker::isRoundComplete($tId, ROUND_STAGE1_R2);
        $survivalDone = $hasSurvival && Matchmaker::isRoundComplete($tId, ROUND_STAGE1_SURVIVAL);
    ?>

    <?php if ($r1Done && !$hasR2): ?>
        <!-- Round 1 Complete Action Banner -->
        <div class="bg-gradient-to-r from-[#0f2044] via-[#16306e] to-[#0f2044] border-2 border-[#c9a84c] rounded-3xl p-6 sm:p-7 mb-8 shadow-2xl flex flex-col md:flex-row items-center justify-between gap-5 relative overflow-hidden">
            <div class="flex items-center gap-4 relative z-10">
                <div class="w-14 h-14 rounded-2xl bg-[#c9a84c] text-[#080e1e] flex items-center justify-center font-black text-2xl shadow-lg flex-shrink-0 animate-bounce">
                    <i class="ph-fill ph-trophy"></i>
                </div>
                <div>
                    <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-[#c9a84c]/20 text-[#ffd978] text-[10px] font-black uppercase tracking-widest mb-1 border border-[#c9a84c]/30">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#c9a84c] animate-ping"></span>
                        ROUND 1 COMPLETED
                    </div>
                    <h3 class="text-xl sm:text-2xl font-black text-white font-display">All Round 1 Matches Finished!</h3>
                    <p class="text-xs sm:text-sm text-blue-200/90 mt-0.5">Winners (1-0) and Losers (0-1) are ready. Generate Round 2 to continue tournament play.</p>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-wrap relative z-10 w-full md:w-auto justify-end flex-shrink-0">
                <form action="<?= BASE_URL ?>/admin/tournaments/generate.php" method="POST" class="inline w-full sm:w-auto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tournament_id" value="<?= $tId ?>">
                    <input type="hidden" name="action" value="generate_r2">
                    <input type="hidden" name="return_to" value="scoring">
                    <button type="submit" class="w-full sm:w-auto bg-[#c9a84c] hover:bg-amber-400 text-[#080e1e] font-black px-6 py-3.5 rounded-2xl shadow-xl transition flex items-center justify-center gap-2 text-sm cursor-pointer">
                        <i class="ph-bold ph-lightning text-lg"></i> Auto-Generate Round 2
                    </button>
                </form>
                <a href="<?= BASE_URL ?>/admin/tournaments/pairings.php?id=<?= $tId ?>&round=r2" class="w-full sm:w-auto bg-white/10 hover:bg-white/20 text-white border border-white/20 font-bold px-5 py-3.5 rounded-2xl transition text-sm flex items-center justify-center gap-2 text-center">
                    <i class="ph-bold ph-hand-pointing text-base text-[#c9a84c]"></i> Manual Setup
                </a>
            </div>
        </div>

    <?php elseif ($r2Done && !$hasSurvival): ?>
        <!-- Round 2 Complete Action Banner -->
        <div class="bg-gradient-to-r from-[#0f2044] via-[#16306e] to-[#0f2044] border-2 border-cyan-400 rounded-3xl p-6 sm:p-7 mb-8 shadow-2xl flex flex-col md:flex-row items-center justify-between gap-5 relative overflow-hidden">
            <div class="flex items-center gap-4 relative z-10">
                <div class="w-14 h-14 rounded-2xl bg-cyan-400 text-[#080e1e] flex items-center justify-center font-black text-2xl shadow-lg flex-shrink-0 animate-bounce">
                    <i class="ph-fill ph-shield-check"></i>
                </div>
                <div>
                    <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-cyan-400/20 text-cyan-200 text-[10px] font-black uppercase tracking-widest mb-1 border border-cyan-400/30">
                        <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-ping"></span>
                        ROUND 2 COMPLETED
                    </div>
                    <h3 class="text-xl sm:text-2xl font-black text-white font-display">Round 2 Finished!</h3>
                    <p class="text-xs sm:text-sm text-blue-200/90 mt-0.5">2-0 players qualified for Stage 2. 1-1 players must battle in the Survival Round.</p>
                </div>
            </div>
            <div class="flex items-center gap-3 relative z-10 w-full md:w-auto justify-end flex-shrink-0">
                <a href="<?= BASE_URL ?>/admin/tournaments/survival.php?id=<?= $tId ?>" class="w-full sm:w-auto bg-cyan-400 hover:bg-cyan-300 text-[#080e1e] font-black px-6 py-3.5 rounded-2xl shadow-xl transition flex items-center justify-center gap-2 text-sm text-center">
                    <i class="ph-bold ph-strategy text-lg"></i> Configure Survival Round (1-1)
                </a>
            </div>
        </div>

    <?php elseif ($survivalDone && !$hasStage2): ?>
        <!-- Survival Round Complete Action Banner -->
        <div class="bg-gradient-to-r from-[#0f2044] via-[#16306e] to-[#0f2044] border-2 border-emerald-400 rounded-3xl p-6 sm:p-7 mb-8 shadow-2xl flex flex-col md:flex-row items-center justify-between gap-5 relative overflow-hidden">
            <div class="flex items-center gap-4 relative z-10">
                <div class="w-14 h-14 rounded-2xl bg-emerald-400 text-[#080e1e] flex items-center justify-center font-black text-2xl shadow-lg flex-shrink-0 animate-bounce">
                    <i class="ph-fill ph-crown"></i>
                </div>
                <div>
                    <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-emerald-400/20 text-emerald-200 text-[10px] font-black uppercase tracking-widest mb-1 border border-emerald-400/30">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span>
                        STAGE 1 QUALIFIERS COMPLETE
                    </div>
                    <h3 class="text-xl sm:text-2xl font-black text-white font-display">All 16 Qualifiers Determined!</h3>
                    <p class="text-xs sm:text-sm text-blue-200/90 mt-0.5">Tier 1 &amp; Tier 2 qualifiers are ready for the Stage 2 Knockout Bracket.</p>
                </div>
            </div>
            <div class="flex items-center gap-3 relative z-10 w-full md:w-auto justify-end flex-shrink-0">
                <form action="<?= BASE_URL ?>/admin/tournaments/generate.php" method="POST" class="inline w-full sm:w-auto">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tournament_id" value="<?= $tId ?>">
                    <input type="hidden" name="action" value="generate_stage2">
                    <input type="hidden" name="return_to" value="scoring">
                    <button type="submit" class="w-full sm:w-auto bg-emerald-400 hover:bg-emerald-300 text-[#080e1e] font-black px-6 py-3.5 rounded-2xl shadow-xl transition flex items-center justify-center gap-2 text-sm cursor-pointer">
                        <i class="ph-bold ph-trophy text-lg"></i> Generate Stage 2 Knockouts
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    $podium = Matchmaker::getPodiumWinners($tId);
    if ($podium['is_finished']):
    ?>
        <div class="bg-gradient-to-br from-[#0f2044] via-[#16306e] to-[#0a152e] border-2 border-[#c9a84c] rounded-3xl p-6 sm:p-8 mb-8 shadow-2xl relative overflow-hidden text-white">
            <div class="absolute -right-10 -bottom-10 opacity-10 pointer-events-none">
                <i class="ph-fill ph-trophy text-[220px] text-[#c9a84c]"></i>
            </div>
            
            <div class="text-center max-w-xl mx-auto mb-8 relative z-10">
                <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-[#c9a84c]/20 text-[#ffd978] text-xs font-black uppercase tracking-widest border border-[#c9a84c]/40 mb-3">
                    <i class="ph-fill ph-crown text-base text-[#c9a84c]"></i> TOURNAMENT COMPLETED &bull; PODIUM OF HONOR
                </div>
                <h2 class="text-3xl sm:text-4xl font-black font-display text-white tracking-tight">Official Tournament Results</h2>
                <p class="text-xs sm:text-sm text-blue-200/90 mt-1">Honoring the champions of TKMI Badminton</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 relative z-10">
                <!-- 🥇 1st Place (Champion) -->
                <div class="bg-gradient-to-b from-amber-500/20 to-amber-950/40 border-2 border-amber-400/70 rounded-2xl p-5 text-center flex flex-col items-center relative shadow-lg">
                    <div class="absolute -top-3.5 px-3 py-0.5 rounded-full bg-amber-400 text-slate-900 font-black text-[11px] uppercase tracking-widest shadow-md flex items-center gap-1">
                        🥇 Champion
                    </div>
                    <div class="w-16 h-16 rounded-full bg-amber-400/20 border-2 border-amber-400 flex items-center justify-center text-3xl text-amber-300 mt-2 mb-3 shadow-inner">
                        <i class="ph-fill ph-trophy"></i>
                    </div>
                    <h4 class="text-lg font-black text-white"><?= e($podium['champion']['display_name'] ?? 'TBD') ?></h4>
                    <p class="text-xs text-amber-200/80 font-bold mt-1"><?= e($podium['champion']['mohallah'] ?? '') ?></p>
                </div>

                <!-- 🥈 2nd Place (Runner-Up) -->
                <div class="bg-gradient-to-b from-slate-400/20 to-slate-900/40 border-2 border-slate-300/70 rounded-2xl p-5 text-center flex flex-col items-center relative shadow-lg">
                    <div class="absolute -top-3.5 px-3 py-0.5 rounded-full bg-slate-300 text-slate-900 font-black text-[11px] uppercase tracking-widest shadow-md flex items-center gap-1">
                        🥈 2nd Place
                    </div>
                    <div class="w-16 h-16 rounded-full bg-slate-300/20 border-2 border-slate-300 flex items-center justify-center text-3xl text-slate-200 mt-2 mb-3 shadow-inner">
                        <i class="ph-fill ph-medal"></i>
                    </div>
                    <h4 class="text-lg font-black text-white"><?= e($podium['runner_up']['display_name'] ?? 'TBD') ?></h4>
                    <p class="text-xs text-slate-300/80 font-bold mt-1"><?= e($podium['runner_up']['mohallah'] ?? '') ?></p>
                </div>

                <!-- 🥉 3rd Place (Bronze) -->
                <div class="bg-gradient-to-b from-orange-700/20 to-orange-950/40 border-2 border-orange-500/70 rounded-2xl p-5 text-center flex flex-col items-center relative shadow-lg">
                    <div class="absolute -top-3.5 px-3 py-0.5 rounded-full bg-orange-400 text-slate-900 font-black text-[11px] uppercase tracking-widest shadow-md flex items-center gap-1">
                        🥉 3rd Place
                    </div>
                    <div class="w-16 h-16 rounded-full bg-orange-500/20 border-2 border-orange-400 flex items-center justify-center text-3xl text-orange-300 mt-2 mb-3 shadow-inner">
                        <i class="ph-fill ph-medal"></i>
                    </div>
                    <h4 class="text-lg font-black text-white"><?= e($podium['third']['display_name'] ?? 'TBD') ?></h4>
                    <p class="text-xs text-orange-200/80 font-bold mt-1"><?= e($podium['third']['mohallah'] ?? '') ?></p>
                </div>

                <!-- 🎖️ 4th Place -->
                <div class="bg-white/5 border border-white/15 rounded-2xl p-5 text-center flex flex-col items-center relative shadow-lg">
                    <div class="absolute -top-3.5 px-3 py-0.5 rounded-full bg-white/20 text-slate-200 font-black text-[11px] uppercase tracking-widest shadow-md flex items-center gap-1">
                        4th Place
                    </div>
                    <div class="w-16 h-16 rounded-full bg-white/10 border border-white/20 flex items-center justify-center text-3xl text-slate-300 mt-2 mb-3 shadow-inner">
                        <i class="ph-bold ph-award"></i>
                    </div>
                    <h4 class="text-lg font-black text-white"><?= e($podium['fourth']['display_name'] ?? 'TBD') ?></h4>
                    <p class="text-xs text-slate-400 font-bold mt-1"><?= e($podium['fourth']['mohallah'] ?? '') ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($matches)): ?>
        <div class="bg-white border border-slate-200 rounded-2xl p-16 text-center shadow-sm">
            <i class="ph-fill ph-calendar-x text-5xl text-slate-300 mb-4 inline-block"></i>
            <h3 class="text-xl font-bold text-slate-700 mb-2">No Matches Generated Yet</h3>
            <p class="text-slate-500 max-w-md mx-auto mb-6">Generate matches from the tournament management screen to start scoring.</p>
            <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $selectedTourney['id'] ?>" class="inline-flex items-center gap-2 bg-[#0f2044] hover:bg-blue-900 text-white font-bold px-6 py-3 rounded-xl shadow-md transition">
                <i class="ph-bold ph-brackets-curly"></i> Manage & Generate
            </a>
        </div>
    <?php else: ?>
        <?php
        // Helper closure to render a single round section consistently
        $renderRoundSection = function($roundKey, $rMatches) {
        ?>
            <div class="mb-10">
                <div class="flex items-center gap-3 mb-4 border-b border-slate-200 pb-3">
                    <span class="w-3 h-3 rounded-full bg-[#c9a84c]"></span>
                    <h4 class="text-base font-black font-display text-[#0f2044] uppercase tracking-wider">
                        <?= getRoundLabel($roundKey) ?>
                    </h4>
                    <span class="text-xs font-bold text-slate-400 ml-auto"><?= count($rMatches) ?> matches</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <?php foreach ($rMatches as $m): 
                        $isLive = $m['status'] === 'in_progress';
                        $isDone = in_array($m['status'], ['completed', 'walkover', 'retired']);
                        $isScheduled = $m['status'] === 'scheduled';
                        $isReady = $m['is_ready'] && $isScheduled;
                        $isPending = !$m['is_ready'] && $isScheduled;

                        $cardBorder = 'border-slate-200 shadow-sm';
                        if ($isLive) {
                            $cardBorder = 'border-red-400 ring-2 ring-red-100 shadow-md';
                        } elseif ($isPending) {
                            $cardBorder = 'border-slate-200 bg-slate-50/60 shadow-2xs';
                        }
                    ?>
                        <div class="bg-white border <?= $cardBorder ?> rounded-2xl p-5 flex flex-col justify-between hover:border-[#0f2044] transition-all">
                            <div>
                                <div class="flex justify-between items-center mb-3">
                                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Match #<?= $m['match_number'] ?></span>
                                    <?php if ($isLive): ?>
                                        <span class="px-2 py-0.5 bg-red-500 text-white text-[10px] font-black rounded uppercase tracking-wider flex items-center gap-1 animate-pulse">
                                            <span>●</span> ON COURT
                                        </span>
                                    <?php elseif ($isDone): ?>
                                        <?php if ($m['status'] === 'walkover'): ?>
                                            <span class="px-2 py-0.5 bg-amber-50 text-amber-800 text-[10px] font-black rounded uppercase tracking-wider border border-amber-200">
                                                ⚡ WALKOVER
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded uppercase tracking-wider border border-emerald-200">
                                                <?= ucfirst($m['status']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($isReady): ?>
                                        <span class="px-2 py-0.5 bg-blue-50 text-blue-700 text-[10px] font-bold rounded uppercase tracking-wider border border-blue-200">
                                            READY TO PLAY
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 bg-slate-100 text-slate-400 text-[10px] font-bold rounded uppercase tracking-wider flex items-center gap-1">
                                            <i class="ph-bold ph-lock"></i> LOCKED
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Participants -->
                                <div class="space-y-2 mb-4">
                                    <div class="flex items-center justify-between p-2.5 rounded-lg <?= ($isDone && $m['winner_player_id'] == $m['participant_a_id']) ? 'bg-emerald-50 text-emerald-900 font-black' : ($m['is_pending_a'] ? 'bg-slate-100/70 text-slate-400 font-medium italic border border-dashed border-slate-200' : 'bg-slate-50 text-slate-800 font-bold') ?>">
                                        <span class="truncate pr-2 text-sm flex items-center gap-1.5">
                                            <?php if ($m['is_pending_a']): ?>
                                                <i class="ph-bold ph-arrow-elbow-down-right text-xs text-slate-400 flex-shrink-0"></i>
                                            <?php endif; ?>
                                            <?= e($m['display_a']) ?>
                                        </span>
                                        <span class="font-black text-base <?= $m['is_pending_a'] ? 'text-slate-300' : '' ?>">
                                            <?= $isLive ? $m['score_a'] : ($isDone ? $m['games_a'] : '-') ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between p-2.5 rounded-lg <?= ($isDone && $m['winner_player_id'] == $m['participant_b_id']) ? 'bg-emerald-50 text-emerald-900 font-black' : ($m['is_pending_b'] ? 'bg-slate-100/70 text-slate-400 font-medium italic border border-dashed border-slate-200' : 'bg-slate-50 text-slate-800 font-bold') ?>">
                                        <span class="truncate pr-2 text-sm flex items-center gap-1.5">
                                            <?php if ($m['is_pending_b']): ?>
                                                <i class="ph-bold ph-arrow-elbow-down-right text-xs text-slate-400 flex-shrink-0"></i>
                                            <?php endif; ?>
                                            <?= e($m['display_b']) ?>
                                        </span>
                                        <span class="font-black text-base <?= $m['is_pending_b'] ? 'text-slate-300' : '' ?>">
                                            <?= $isLive ? $m['score_b'] : ($isDone ? $m['games_b'] : '-') ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Score / Details Subtext -->
                                <div class="mb-4 text-[11px] text-center font-bold text-slate-500">
                                    <?php 
                                    if ($isDone) {
                                        if ($m['status'] === 'walkover') {
                                            echo "<span class='text-amber-700 font-black'>Awarded 2 - 0" . (!empty($m['walkover_reason']) ? " (" . e($m['walkover_reason']) . ")" : "") . "</span>";
                                        } else {
                                            $hasNonZeroGames = false;
                                            if (!empty($m['games'])) {
                                                foreach ($m['games'] as $g) {
                                                    if ($g['score_a'] > 0 || $g['score_b'] > 0) {
                                                        $hasNonZeroGames = true; break;
                                                    }
                                                }
                                            }
                                            if ($hasNonZeroGames) {
                                                $scoreStrings = array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $m['games']);
                                                echo "Games: <span class='text-slate-800 font-black'>{$m['games_a']} - {$m['games_b']}</span> <span class='text-slate-400 font-normal'>(" . implode(', ', $scoreStrings) . ")</span>";
                                            } else {
                                                echo "Final Result: <span class='text-slate-800 font-black'>{$m['games_a']} - {$m['games_b']}</span>";
                                            }
                                        }
                                    }
                                    elseif ($isLive) {
                                        echo "<span class='text-red-600 font-black animate-pulse'>Live Sets: {$m['games_a']} - {$m['games_b']} (Score: {$m['score_a']} - {$m['score_b']})</span>";
                                    }
                                    elseif ($isReady) {
                                        echo "<span class='text-blue-600 font-medium'>Ready to Play &bull; Best of {$m['best_of']}</span>";
                                    }
                                    else {
                                        echo "<span class='text-slate-400 font-medium italic flex items-center justify-center gap-1'><i class='ph-bold ph-hourglass-simple'></i> Waiting for qualifiers</span>";
                                    }
                                    ?>
                                </div>
                            </div>

                            <!-- Action Button -->
                            <?php if ($isLive): ?>
                                <a href="<?= BASE_URL ?>/admin/scoring/console.php?id=<?= $m['id'] ?>" class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-2.5 px-4 rounded-xl text-center text-sm shadow-md transition flex items-center justify-center gap-2">
                                    <span class="w-2 h-2 rounded-full bg-white animate-ping"></span> Resume Live Scoring
                                </a>
                            <?php elseif ($isReady): ?>
                                <a href="<?= BASE_URL ?>/admin/scoring/console.php?id=<?= $m['id'] ?>" class="w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold py-2.5 px-4 rounded-xl text-center text-sm shadow-sm transition flex items-center justify-center gap-2">
                                    <i class="ph-bold ph-play"></i> Start Scoring
                                </a>
                            <?php elseif ($isPending): ?>
                                <button disabled class="w-full bg-slate-100 text-slate-400 font-bold py-2.5 px-4 rounded-xl text-center text-xs cursor-not-allowed flex items-center justify-center gap-1.5 border border-slate-200">
                                    <i class="ph-bold ph-lock"></i>
                                    <span>Locked &bull; Waiting for Qualifiers</span>
                                </button>
                            <?php else: ?>
                                <a href="<?= BASE_URL ?>/admin/scoring/console.php?id=<?= $m['id'] ?>" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 px-4 rounded-xl text-center text-sm transition flex items-center justify-center gap-2">
                                    <i class="ph-bold ph-eye"></i> View Result / Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php
        };
        ?>

        <div class="space-y-12">
            <!-- STAGE 1: SWISS QUALIFIER -->
            <?php if (!empty($stage1Grouped)): ?>
                <div>
                    <div class="flex items-center gap-3 border-b-2 border-slate-200 pb-3 mb-6">
                        <span class="px-3 py-1 bg-slate-900 text-[#c9a84c] rounded-xl text-xs font-black uppercase tracking-wider">Stage 1</span>
                        <h3 class="text-xl font-black font-display text-[#0f2044]">Swiss Qualifier Rounds</h3>
                        <span class="text-xs font-bold text-slate-400 ml-auto hidden sm:inline">Two-Loss Elimination Rule Active</span>
                    </div>

                    <?php foreach ($stage1Grouped as $rk => $rMatches): ?>
                        <?php $renderRoundSection($rk, $rMatches); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- STAGE 1 -> STAGE 2 QUALIFICATION SUMMARY BANNER -->
            <?php if (!empty($stage2Grouped) && (!empty($tier1Qualifiers) || !empty($tier2Qualifiers))): ?>
                <div class="bg-gradient-to-br from-[#0f2044] via-[#16306e] to-[#0a152e] rounded-3xl p-6 sm:p-7 border-2 border-[#c9a84c]/50 shadow-xl text-white relative overflow-hidden">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
                        <div>
                            <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-[#c9a84c]/20 text-[#ffd978] text-[11px] font-black uppercase tracking-widest border border-[#c9a84c]/30 mb-2">
                                <i class="ph-fill ph-shield-check text-sm text-[#c9a84c]"></i>
                                STAGE 1 QUALIFIERS DETERMINED &bull; <?= count($tier1Qualifiers) + count($tier2Qualifiers) ?> ADVANCED
                            </div>
                            <h3 class="text-2xl font-black font-display text-white">Knockout Stage Progression</h3>
                            <p class="text-xs sm:text-sm text-blue-200/90 mt-1 max-w-2xl">
                                Players who secured 2 wins in Stage 1 have advanced to Stage 2 Single Elimination. Stage 2 begins at Quarter Finals for 8 qualifiers.
                            </p>
                        </div>
                        
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="px-4 py-2.5 rounded-2xl bg-white/10 border border-white/15 text-center min-w-[100px]">
                                <div class="text-[10px] uppercase font-black tracking-wider text-amber-300">Tier 1 (2-0)</div>
                                <div class="text-lg font-black font-display text-white mt-0.5"><?= count($tier1Qualifiers) ?> Qualified</div>
                            </div>
                            <div class="px-4 py-2.5 rounded-2xl bg-white/10 border border-white/15 text-center min-w-[100px]">
                                <div class="text-[10px] uppercase font-black tracking-wider text-cyan-300">Survival (2-1)</div>
                                <div class="text-lg font-black font-display text-white mt-0.5"><?= count($tier2Qualifiers) ?> Qualified</div>
                            </div>
                            <div class="px-4 py-2.5 rounded-2xl bg-white/10 border border-white/15 text-center min-w-[100px]">
                                <div class="text-[10px] uppercase font-black tracking-wider text-red-300">Eliminated</div>
                                <div class="text-lg font-black font-display text-slate-300 mt-0.5"><?= $eliminatedCount ?> Players</div>
                            </div>
                        </div>
                    </div>

                    <!-- Qualified Player Names -->
                    <div class="mt-6 pt-5 border-t border-white/10 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div>
                            <div class="text-[10px] uppercase font-black tracking-widest text-[#ffd978] mb-2 flex items-center gap-1.5">
                                <i class="ph-fill ph-crown"></i> Tier 1 Champions (2-0):
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($tier1Qualifiers as $q): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-amber-400/20 text-amber-200 border border-amber-400/30 font-bold"><?= e($q['display_name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase font-black tracking-widest text-cyan-300 mb-2 flex items-center gap-1.5">
                                <i class="ph-fill ph-shield"></i> Survival Qualifiers (2-1):
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($tier2Qualifiers as $q): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-cyan-400/20 text-cyan-200 border border-cyan-400/30 font-bold"><?= e($q['display_name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- STAGE 2: SINGLE ELIMINATION KNOCKOUT -->
            <?php if (!empty($stage2Grouped)): ?>
                <div>
                    <div class="flex items-center gap-3 border-b-2 border-slate-200 pb-3 mb-6">
                        <span class="px-3 py-1 bg-[#c9a84c] text-slate-900 rounded-xl text-xs font-black uppercase tracking-wider">Stage 2</span>
                        <h3 class="text-xl font-black font-display text-[#0f2044]">Single Elimination Knockout</h3>
                        <span class="text-xs font-bold text-slate-400 ml-auto hidden sm:inline">Pure Single Elimination &bull; Lose Once = Eliminated</span>
                    </div>

                    <?php foreach ($stage2Grouped as $rk => $rMatches): ?>
                        <?php $renderRoundSection($rk, $rMatches); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- OTHER FORMATS (e.g. Round Robin / Pools) -->
            <?php if (!empty($otherGrouped)): ?>
                <div>
                    <?php foreach ($otherGrouped as $rk => $rMatches): ?>
                        <?php $renderRoundSection($rk, $rMatches); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
