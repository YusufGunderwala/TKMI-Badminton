<?php
// ============================================================
// Live Scoring Hub & Match Selector
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

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

    foreach ($matches as $m) {
        $isDoubles = !empty($m['team_a_id']);
        $m['display_a'] = $isDoubles ? ($m['ta_name'] ?: 'Team A') : ($m['pa_name'] ?: 'Player A');
        $m['display_b'] = $isDoubles ? ($m['tb_name'] ?: 'Team B') : ($m['pb_name'] ?: 'Player B');
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
                                    <div class="space-y-2 mb-5">
                                        <div class="flex items-center justify-between p-2.5 rounded-lg <?= ($isDone && $m['winner_player_id'] == $m['participant_a_id']) ? 'bg-emerald-50 text-emerald-900 font-black' : 'bg-slate-50 text-slate-800 font-bold' ?>">
                                            <span class="truncate pr-2 text-sm"><?= e($m['display_a']) ?></span>
                                            <span class="font-black text-base"><?= $m['games_a'] ?> <span class="text-xs text-slate-400 font-normal">(<?= $m['score_a'] ?>)</span></span>
                                        </div>
                                        <div class="flex items-center justify-between p-2.5 rounded-lg <?= ($isDone && $m['winner_player_id'] == $m['participant_b_id']) ? 'bg-emerald-50 text-emerald-900 font-black' : 'bg-slate-50 text-slate-800 font-bold' ?>">
                                            <span class="truncate pr-2 text-sm"><?= e($m['display_b']) ?></span>
                                            <span class="font-black text-base"><?= $m['games_b'] ?> <span class="text-xs text-slate-400 font-normal">(<?= $m['score_b'] ?>)</span></span>
                                        </div>
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
