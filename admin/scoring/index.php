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
        ORDER BY 
            CASE m.status WHEN \'in_progress\' THEN 1 WHEN \'scheduled\' THEN 2 ELSE 3 END,
            CASE m.stage WHEN \'stage1\' THEN 1 WHEN \'stage2\' THEN 2 ELSE 3 END,
            m.round_key ASC, m.match_number ASC
    ');
    $stmt->execute([$tournamentId]);
    $matches = $stmt->fetchAll();

    // Fetch all games for this tournament to show full score history
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

    foreach ($matches as $m) {
        $isDoubles = !empty($m['team_a_id']);
        $m['display_a'] = $isDoubles ? ($m['ta_name'] ?: 'Team A') : ($m['pa_name'] ?: 'Player A');
        $m['display_b'] = $isDoubles ? ($m['tb_name'] ?: 'Team B') : ($m['pb_name'] ?: 'Player B');
        $m['games'] = $matchGames[$m['id']] ?? [];
        $groupedMatches[$m['round_key']][] = $m;
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
                    <span class="px-2 py-0.5 bg-red-50 text-red-600 text-[10px] font-black uppercase tracking-wider rounded border border-red-200 animate-pulse">● LIVE</span>
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
        <div class="space-y-10">
            <?php foreach ($groupedMatches as $roundKey => $rMatches): ?>
                <div>
                    <div class="flex items-center gap-3 mb-4 border-b border-slate-200 pb-3">
                        <span class="w-3 h-3 rounded-full bg-[#c9a84c]"></span>
                        <h3 class="text-lg font-black font-display text-[#0f2044] uppercase tracking-wider">
                            <?= getRoundLabel($roundKey) ?>
                        </h3>
                        <span class="text-xs font-bold text-slate-400 ml-auto"><?= count($rMatches) ?> matches</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        <?php foreach ($rMatches as $m): 
                            $isLive = $m['status'] === 'in_progress';
                            $isDone = in_array($m['status'], ['completed', 'walkover', 'retired']);
                            $isScheduled = $m['status'] === 'scheduled';
                        ?>
                            <div class="bg-white border <?= $isLive ? 'border-red-400 ring-2 ring-red-100 shadow-md' : 'border-slate-200 shadow-sm' ?> rounded-2xl p-5 flex flex-col justify-between hover:border-[#0f2044] transition-all">
                                <div>
                                    <div class="flex justify-between items-center mb-3">
                                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Match #<?= $m['match_number'] ?></span>
                                        <?php if ($isLive): ?>
                                            <span class="px-2 py-0.5 bg-red-500 text-white text-[10px] font-black rounded uppercase tracking-wider flex items-center gap-1 animate-pulse">
                                                <span>●</span> ON COURT
                                            </span>
                                        <?php elseif ($isDone): ?>
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded uppercase tracking-wider border border-emerald-200">
                                                <?= ucfirst($m['status']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-bold rounded uppercase tracking-wider">
                                                Scheduled
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Participants -->
                                    <div class="space-y-2 mb-4">
                                        <div class="flex items-center justify-between p-2.5 rounded-lg <?= ($isDone && $m['winner_player_id'] == $m['participant_a_id']) ? 'bg-emerald-50 text-emerald-900 font-black' : 'bg-slate-50 text-slate-800 font-bold' ?>">
                                            <span class="truncate pr-2 text-sm"><?= e($m['display_a']) ?></span>
                                            <span class="font-black text-base"><?= $isLive ? $m['score_a'] : $m['games_a'] ?></span>
                                        </div>
                                        <div class="flex items-center justify-between p-2.5 rounded-lg <?= ($isDone && $m['winner_player_id'] == $m['participant_b_id']) ? 'bg-emerald-50 text-emerald-900 font-black' : 'bg-slate-50 text-slate-800 font-bold' ?>">
                                            <span class="truncate pr-2 text-sm"><?= e($m['display_b']) ?></span>
                                            <span class="font-black text-base"><?= $isLive ? $m['score_b'] : $m['games_b'] ?></span>
                                        </div>
                                    </div>
                                    <div class="mb-4 text-[10px] uppercase tracking-widest text-center font-bold text-slate-400">
                                        <?php 
                                        if ($isDone) {
                                            if (!empty($m['games'])) {
                                                $scoreStrings = array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $m['games']);
                                                echo "Scores: <span class='text-slate-800 font-black'>" . implode(', ', $scoreStrings) . "</span>";
                                            } else {
                                                echo "Score: <span class='text-slate-800 font-black'>{$m['score_a']} - {$m['score_b']}</span>";
                                            }
                                        }
                                        elseif ($isLive) {
                                            echo "<span class='text-red-600 font-black animate-pulse'>Live Sets: {$m['games_a']} - {$m['games_b']}</span>";
                                        }
                                        else {
                                            echo "Upcoming match";
                                        }
                                        ?>
                                    </div>
                                </div>

                                <!-- Action Button -->
                                <?php if ($isLive): ?>
                                    <a href="<?= BASE_URL ?>/admin/scoring/console.php?id=<?= $m['id'] ?>" class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-2.5 px-4 rounded-xl text-center text-sm shadow-md transition flex items-center justify-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-white animate-ping"></span> Resume Live Scoring
                                    </a>
                                <?php elseif ($isScheduled): ?>
                                    <a href="<?= BASE_URL ?>/admin/scoring/console.php?id=<?= $m['id'] ?>" class="w-full bg-[#0f2044] hover:bg-blue-900 text-white font-bold py-2.5 px-4 rounded-xl text-center text-sm shadow-sm transition flex items-center justify-center gap-2">
                                        <i class="ph-bold ph-play"></i> Start Scoring
                                    </a>
                                <?php else: ?>
                                    <a href="<?= BASE_URL ?>/admin/scoring/console.php?id=<?= $m['id'] ?>" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-2.5 px-4 rounded-xl text-center text-sm transition flex items-center justify-center gap-2">
                                        <i class="ph-bold ph-eye"></i> View Result / Edit
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
