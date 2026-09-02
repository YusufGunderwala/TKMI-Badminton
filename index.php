<?php
// ============================================================
// TKMI Badminton Tournament Platform — Official Portal
// Clean, World-Class Sports Interface
// ============================================================
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Live Scores & Tournament';
$ogTitle   = 'TKMI Badminton Championship — Official Portal';
$ogDesc    = 'Live badminton court scores, automated Swiss qualifiers, and knockout brackets.';

$homeData = AppCache::remember('home_portal_data', 3, function() {
    $liveMatches     = getLiveMatches();
    $upcomingMatches = empty($liveMatches) ? getUpcomingMatches(null, 6) : [];
    $liveTournaments = getLiveTournaments();
    $allTournaments  = array_filter(getAllTournaments(), fn($t) => $t['status'] !== 'draft');
    
    $pdo = db();
    $mCounts = $pdo->query("SELECT tournament_id, COUNT(*) as cnt FROM matches GROUP BY tournament_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pCounts = $pdo->query("SELECT tournament_id, COUNT(*) as cnt FROM tournament_players GROUP BY tournament_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($allTournaments as &$t) {
        $t['match_count'] = (int)($mCounts[$t['id']] ?? 0);
        $t['player_count'] = (int)($pCounts[$t['id']] ?? 0);
    }
    unset($t);

    return [
        'liveMatches'     => $liveMatches,
        'upcomingMatches' => $upcomingMatches,
        'liveTournaments' => $liveTournaments,
        'allTournaments'  => $allTournaments
    ];
});

$liveMatches     = $homeData['liveMatches'];
$upcomingMatches = $homeData['upcomingMatches'];
$liveTournaments = $homeData['liveTournaments'];
$allTournaments  = $homeData['allTournaments'];

include __DIR__ . '/includes/header.php';
?>

<!-- ======================================================= -->
<!-- 1. STADIUM HERO SECTION (Clean, Bold, Authentic)        -->
<!-- ======================================================= -->
<div class="relative w-full bg-[#080e1e] text-white pt-28 pb-20 border-b border-slate-800 -mt-24">
    
    <!-- Subtle Background Texture -->
    <div class="absolute inset-0 opacity-10 pointer-events-none" style="background-image: radial-gradient(#3b82f6 1px, transparent 1px); background-size: 28px 28px;"></div>
    <div class="absolute top-0 right-1/4 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-0 left-1/4 w-96 h-96 bg-[#c9a84c]/10 rounded-full blur-3xl pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        
        <div class="flex flex-col lg:flex-row items-center justify-between gap-12">
            
            <!-- Left: Hero Headline & Actions -->
            <div class="flex-1 text-center lg:text-left">
                
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 border border-white/10 text-[#c9a84c] text-xs font-black uppercase tracking-widest mb-6">
                    <span class="w-2 h-2 rounded-full bg-[#c9a84c] animate-pulse"></span>
                    TKMI Championship Platform
                </div>

                <h1 class="text-4xl sm:text-6xl lg:text-7xl font-black font-display tracking-tight text-white leading-none uppercase mb-6">
                    Badminton<br/>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#c9a84c] via-[#ecd599] to-[#ffffff]">Tournament</span><br/>
                    Portal
                </h1>

                <p class="text-slate-300 text-base sm:text-lg max-w-xl mx-auto lg:mx-0 font-normal leading-relaxed mb-8">
                    Live court scoring, automated Swiss qualifiers, and single-elimination knockout brackets built for the TKMI community.
                </p>

                <div class="flex flex-wrap items-center justify-center lg:justify-start gap-4">
                    <?php if (!empty($liveMatches)): ?>
                        <a href="#live-scoreboard" class="bg-[#c9a84c] hover:bg-[#b5943b] text-[#080e1e] font-black text-sm uppercase tracking-wider px-7 py-3.5 rounded-xl shadow-lg transition-all flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-red-600 animate-ping"></span>
                            <span>Live Scoreboard</span>
                            <i class="ph-bold ph-broadcast text-lg"></i>
                        </a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>/public/tournaments.php" class="bg-[#c9a84c] hover:bg-[#b5943b] text-[#080e1e] font-black text-sm uppercase tracking-wider px-7 py-3.5 rounded-xl shadow-lg transition-all flex items-center gap-2">
                            <span>Browse Tournaments</span>
                            <i class="ph-bold ph-arrow-right text-lg"></i>
                        </a>
                    <?php endif; ?>

                    <?php 
                    $bracketTargetUrl = !empty($liveTournaments) 
                        ? BASE_URL . '/public/tournament.php?id=' . $liveTournaments[0]['id'] . '&tab=bracket' 
                        : BASE_URL . '/public/tournaments.php';
                    ?>
                    <a href="<?= $bracketTargetUrl ?>" class="bg-white/10 hover:bg-white/20 text-white border border-white/20 font-bold text-sm uppercase tracking-wider px-7 py-3.5 rounded-xl transition-all flex items-center gap-2 shadow-sm">
                        <i class="ph-bold ph-tree-structure text-lg text-[#c9a84c]"></i>
                        <span>View Bracket</span>
                    </a>
                </div>

            </div>

            <!-- Right: Live Court / Featured Match Tile -->
            <div class="w-full max-w-md lg:max-w-lg">
                
                <div class="bg-slate-900/80 backdrop-blur-md rounded-2xl border border-slate-800 p-6 shadow-2xl">
                    
                    <div class="flex items-center justify-between mb-5 border-b border-slate-800 pb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></div>
                            <span class="text-xs font-black text-white uppercase tracking-wider">
                                <?= !empty($liveMatches) ? 'Court Match Live' : 'Active Tournament' ?>
                            </span>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest bg-slate-800 px-2.5 py-1 rounded">
                            <?= !empty($liveTournaments) ? e($liveTournaments[0]['gender']) . ' ' . ucfirst(e($liveTournaments[0]['match_type'])) : 'Swiss Format' ?>
                        </span>
                    </div>

                    <?php if (!empty($liveMatches)): 
                        $cur = $liveMatches[0];
                        $isDoubles = !empty($cur['team_a_id']);
                        $nameA = $isDoubles ? $cur['ta_name'] : $cur['pa_name'];
                        $nameB = $isDoubles ? $cur['tb_name'] : $cur['pb_name'];
                    ?>
                        <div class="space-y-4 mb-5">
                            <div class="text-center text-xs text-slate-400 font-bold uppercase tracking-wider">
                                <?= e($cur['tournament_name']) ?> &bull; <?= getRoundLabel($cur['round_key']) ?>
                            </div>
                            
                            <div class="bg-slate-950 rounded-xl p-5 border border-slate-800 flex items-center justify-between gap-4">
                                <div class="flex-1 text-right">
                                    <div class="font-black text-white text-base truncate"><?= e($nameA ?: 'Player A') ?></div>
                                    <div class="text-xs text-slate-400 font-bold mt-0.5">Games: <?= $cur['games_a'] ?></div>
                                </div>

                                <div class="bg-[#080e1e] text-[#c9a84c] px-4 py-2 rounded-lg font-black font-display text-2xl tracking-widest border border-slate-800 shadow-inner">
                                    <?= $cur['score_a'] ?> - <?= $cur['score_b'] ?>
                                </div>

                                <div class="flex-1 text-left">
                                    <div class="font-black text-white text-base truncate"><?= e($nameB ?: 'Player B') ?></div>
                                    <div class="text-xs text-slate-400 font-bold mt-0.5">Games: <?= $cur['games_b'] ?></div>
                                </div>
                            </div>
                        </div>

                        <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $cur['tournament_id'] ?>&tab=bracket" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider py-3 rounded-xl transition">
                            View Tournament Bracket &rarr;
                        </a>

                    <?php elseif (!empty($liveTournaments)): 
                        $t = $liveTournaments[0];
                    ?>
                        <div class="space-y-4 mb-6">
                            <h3 class="text-xl font-black text-white"><?= e($t['name']) ?></h3>
                            <p class="text-slate-400 text-sm leading-relaxed"><?= e($t['description'] ?: 'Official championship tournament. Follow standings, match outcomes, and elimination brackets.') ?></p>
                            
                            <div class="grid grid-cols-2 gap-3 pt-2">
                                <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 text-center">
                                    <div class="text-xs font-bold text-slate-400 uppercase">Qualifiers</div>
                                    <div class="text-sm font-black text-white mt-0.5">Swiss Stage 1</div>
                                </div>
                                <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 text-center">
                                    <div class="text-xs font-bold text-slate-400 uppercase">Finals</div>
                                    <div class="text-sm font-black text-[#c9a84c] mt-0.5">Knockout Stage 2</div>
                                </div>
                            </div>
                        </div>

                        <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $t['id'] ?>" class="block w-full text-center bg-[#080e1e] hover:bg-slate-800 border border-slate-700 text-[#c9a84c] font-black text-xs uppercase tracking-wider py-3 rounded-xl transition">
                            Open Tournament Hub &rarr;
                        </a>
                    <?php else: ?>
                        <div class="py-8 text-center text-slate-400 text-sm">
                            <i class="ph-bold ph-calendar-blank text-3xl mb-2 text-slate-500 inline-block"></i>
                            <p>No tournament currently active.</p>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- ======================================================= -->
<!-- 2. MAIN DASHBOARD: LIVE MATCHES & ON-DECK SCHEDULE      -->
<!-- ======================================================= -->
<div class="bg-slate-50 py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <?php if (!empty($liveMatches)): ?>
        <!-- Live Scoreboard -->
        <div id="live-scoreboard" class="mb-16 scroll-mt-28">
            <div class="flex items-center justify-between mb-6 pb-3 border-b border-slate-200">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 rounded-full bg-red-500 animate-ping"></span>
                    <h2 class="text-2xl font-black text-[#080e1e] uppercase tracking-tight">Active Live Matches</h2>
                </div>
                <span class="text-xs font-bold text-slate-500 bg-white border border-slate-200 px-3 py-1 rounded-md">
                    Real-time Score Feed
                </span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6" id="live-matches-container">
                <?php foreach ($liveMatches as $match): 
                    $isDoubles = !empty($match['team_a_id']);
                    $pAName = $isDoubles ? ($match['ta_name'] ?: 'Team A') : ($match['pa_name'] ?: 'Player A');
                    $pBName = $isDoubles ? ($match['tb_name'] ?: 'Team B') : ($match['pb_name'] ?: 'Player B');
                    $gamesToWin = 2;
                ?>
                    <div class="bg-white rounded-2xl shadow-md border border-slate-200 flex flex-col justify-between overflow-hidden hover-lift">
                        <!-- Live Header -->
                        <div class="bg-gradient-to-r from-red-600 to-red-700 text-white text-[10px] font-black uppercase tracking-widest text-center py-2 px-4 flex items-center justify-between shadow-inner">
                            <span class="flex items-center gap-1.5">
                                <span class="w-2 h-2 bg-white rounded-full animate-ping"></span>
                                <span>● LIVE ON COURT</span>
                            </span>
                            <span class="opacity-90 font-mono text-[9px]"><?= e($match['tournament_name'] ?? 'Tournament') ?></span>
                        </div>
                        
                        <div class="p-6 grid grid-cols-[1fr_auto_1fr] items-center gap-4 flex-1 bg-white">
                            <!-- Player A -->
                            <div class="text-center flex flex-col justify-center h-full">
                                <div class="font-black text-slate-800 text-base leading-snug line-clamp-2" title="<?= e($pAName) ?>"><?= e($pAName) ?></div>
                                <div class="flex gap-1.5 justify-center mt-2.5">
                                    <?php for ($i=0; $i<$gamesToWin; $i++): ?>
                                        <div class="w-3 h-3 rounded-full <?= ((int)$match['games_a'] > $i) ? 'bg-[#c9a84c] shadow-[0_0_8px_rgba(201,168,76,0.6)]' : 'bg-slate-200 border border-slate-300' ?>"></div>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <!-- Live Scores -->
                            <div class="flex items-center justify-center gap-3 bg-[#0f2044] text-white px-5 py-3 rounded-2xl shadow-lg border border-slate-700">
                                <div class="text-3xl font-black w-10 text-center font-display text-white" id="score_a_<?= $match['id'] ?>"><?= (int)$match['score_a'] ?></div>
                                <div class="text-[#c9a84c] font-black text-xl mb-0.5">:</div>
                                <div class="text-3xl font-black w-10 text-center font-display text-white" id="score_b_<?= $match['id'] ?>"><?= (int)$match['score_b'] ?></div>
                            </div>

                            <!-- Player B -->
                            <div class="text-center flex flex-col justify-center h-full">
                                <div class="font-black text-slate-800 text-base leading-snug line-clamp-2" title="<?= e($pBName) ?>"><?= e($pBName) ?></div>
                                <div class="flex gap-1.5 justify-center mt-2.5">
                                    <?php for ($i=0; $i<$gamesToWin; $i++): ?>
                                        <div class="w-3 h-3 rounded-full <?= ((int)$match['games_b'] > $i) ? 'bg-[#c9a84c] shadow-[0_0_8px_rgba(201,168,76,0.6)]' : 'bg-slate-200 border border-slate-300' ?>"></div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-slate-50 border-t border-slate-100 px-4 py-2 flex items-center justify-between text-[11px] font-bold text-slate-500">
                            <span class="uppercase tracking-wider"><?= getRoundLabel($match['round_key'] ?? 'r1') ?></span>
                            <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $match['tournament_id'] ?>" class="text-blue-600 font-bold hover:underline">View Arena &rarr;</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- On-Deck Matches (Upcoming) -->
        <div class="mb-16">
            <div class="flex items-center justify-between mb-6 pb-3 border-b border-slate-200">
                <h2 class="text-2xl font-black text-[#080e1e] uppercase tracking-tight flex items-center gap-2">
                    <i class="ph-bold ph-calendar-check text-[#c9a84c]"></i> On-Deck Matches
                </h2>
                <span class="text-xs font-bold text-slate-500">Upcoming on Court</span>
            </div>

            <?php if (empty($upcomingMatches)): ?>
                <div class="bg-white rounded-2xl p-10 text-center border border-dashed border-slate-200">
                    <i class="ph-fill ph-calendar-blank text-4xl text-slate-300 mb-2 inline-block"></i>
                    <h3 class="text-base font-bold text-slate-700">No Matches Currently On Deck</h3>
                    <p class="text-slate-400 text-xs mt-1">Next fixtures will be shown once the active round progresses.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    <?php foreach ($upcomingMatches as $um): 
                        $isDoubles = !empty($um['team_a_id']);
                        $pa = $isDoubles ? $um['ta_name'] : $um['pa_name'];
                        $pb = $isDoubles ? $um['tb_name'] : $um['pb_name'];
                    ?>
                        <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:border-[#080e1e] transition flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between mb-3 text-xs">
                                    <span class="font-bold text-slate-400 uppercase tracking-widest text-[10px]">Match #<?= $um['match_number'] ?></span>
                                    <span class="bg-slate-100 text-slate-700 font-bold px-2 py-0.5 rounded text-[10px] uppercase"><?= getRoundLabel($um['round_key']) ?></span>
                                </div>

                                <div class="space-y-2">
                                    <div class="p-3 bg-slate-50 rounded-xl font-bold text-slate-800 text-sm flex items-center justify-between">
                                        <span class="truncate"><?= e($pa ?: 'TBD') ?></span>
                                    </div>
                                    <div class="p-3 bg-slate-50 rounded-xl font-bold text-slate-800 text-sm flex items-center justify-between">
                                        <span class="truncate"><?= e($pb ?: 'TBD') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-bold text-slate-400">
                                <span><?= e($um['tournament_name']) ?></span>
                                <span class="text-blue-600 font-bold">Scheduled</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ======================================================= -->
        <!-- 3. TOURNAMENT SYSTEM & RULES (Clean, Step-by-Step)       -->
        <!-- ======================================================= -->

        <!-- ======================================================= -->
        <!-- 4. ACTIVE & UPCOMING TOURNAMENTS DIRECTORY              -->
        <!-- ======================================================= -->
        <div>
            <div class="flex items-center justify-between mb-6 pb-3 border-b border-slate-200">
                <h2 class="text-2xl font-black text-[#080e1e] uppercase tracking-tight">Tournament Directory</h2>
                <a href="<?= BASE_URL ?>/public/tournaments.php" class="text-xs font-bold text-blue-600 hover:underline">View All &rarr;</a>
            </div>

            <?php if (empty($allTournaments)): ?>
                <div class="bg-white rounded-2xl p-8 text-center border border-slate-200 text-slate-500 text-sm">
                    No tournaments available right now.
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($allTournaments as $tourney): 
                        $isLive = $tourney['status'] === 'live';
                        $mTotal = $tourney['match_count'] ?? 0;
                        $pTotal = $tourney['player_count'] ?? 0;
                    ?>
                        <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm hover:border-[#080e1e] transition flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <?php if ($isLive): ?>
                                        <span class="px-2.5 py-0.5 rounded bg-red-50 text-red-600 text-[10px] font-black uppercase tracking-wider border border-red-200 animate-pulse">
                                            ● LIVE
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-bold uppercase tracking-wider">
                                            <?= ucfirst($tourney['status']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="text-xs font-bold text-slate-400">
                                        <?= e($tourney['gender']) ?> <?= ucfirst(e($tourney['match_type'])) ?>
                                    </span>
                                </div>

                                <h3 class="text-xl font-black text-[#080e1e] mb-2"><?= e($tourney['name']) ?></h3>
                                <p class="text-slate-500 text-xs leading-relaxed mb-4 line-clamp-2"><?= e($tourney['description'] ?: 'Official TKMI tournament division.') ?></p>
                            </div>

                            <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span class="font-bold text-slate-500"><?= $pTotal ?> Players &bull; <?= $mTotal ?> Matches</span>
                                <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $tourney['id'] ?>" class="text-[#080e1e] hover:text-blue-600 font-black flex items-center gap-1">
                                    View Draw &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php if (!empty($liveMatches)): ?>
<!-- Establish SSE Connection for Live Updates -->
<script src="<?= BASE_URL ?>/assets/js/sse-client.js"></script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
