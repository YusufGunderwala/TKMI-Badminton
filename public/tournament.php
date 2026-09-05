<?php
// ============================================================
// Premium Public Viewer - World-Class Tournament Center
// TKMI Badminton Tournament Platform
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/matchmaker.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$initialTab = $_GET['tab'] ?? 'live'; // Default to 'live' if active matches exist, otherwise 'matches'

$tournament = getTournament($id);
if (!$tournament) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$pageTitle = $tournament['name'] . ' - TKMI Badminton Live Center';
$ogTitle = $tournament['name'] . ' | Live Scores & Standings';
$ogDesc = 'Follow live scores, court matchups, and standings for ' . $tournament['name'] . '.';

$pdo = db();

// 1. Fetch Active Platform-wide Sponsors
$sponsors = getActiveSponsors();

// 2. Fetch Standings & Player Records (Micro-cached for ultra-fast performance)
$standings = AppCache::remember('standings_' . $id, 2, function() use ($pdo, $id) {
    $stmt = $pdo->prepare('
        SELECT 
            p.id, p.full_name, p.display_name, p.mohallah, p.its_id, p.photo_path, p.gender,
            COUNT(m.id) as played,
            SUM(
                CASE 
                    WHEN m.winner_player_id = p.id THEN 1 
                    WHEN t.id IS NOT NULL AND m.winner_team_id = t.id THEN 1
                    ELSE 0 
                END
            ) as wins,
            SUM(
                CASE 
                    WHEN m.loser_player_id = p.id THEN 1 
                    WHEN t.id IS NOT NULL AND m.loser_team_id = t.id THEN 1
                    ELSE 0 
                END
            ) as losses,
            SUM(
                COALESCE((
                    SELECT SUM(
                        CASE 
                            WHEN m.participant_a_id = p.id OR (t.id IS NOT NULL AND m.team_a_id = t.id) THEN (g.score_a - g.score_b)
                            WHEN m.participant_b_id = p.id OR (t.id IS NOT NULL AND m.team_b_id = t.id) THEN (g.score_b - g.score_a)
                            ELSE 0 
                        END
                    ) FROM games g WHERE g.match_id = m.id
                ), 0)
                +
                CASE WHEN m.status IN (?, ?) THEN 
                    CASE 
                        WHEN m.participant_a_id = p.id OR (t.id IS NOT NULL AND m.team_a_id = t.id) THEN (m.score_a - m.score_b)
                        WHEN m.participant_b_id = p.id OR (t.id IS NOT NULL AND m.team_b_id = t.id) THEN (m.score_b - m.score_a)
                        ELSE 0 
                    END
                ELSE 0 END
            ) as net_points
        FROM tournament_players tp
        JOIN players p ON tp.player_id = p.id
        LEFT JOIN teams t ON t.tournament_id = tp.tournament_id AND (t.player1_id = p.id OR t.player2_id = p.id)
        LEFT JOIN matches m ON m.tournament_id = tp.tournament_id 
            AND m.status IN (?, ?, ?) 
            AND (
                m.participant_a_id = p.id OR m.participant_b_id = p.id OR 
                (t.id IS NOT NULL AND (m.team_a_id = t.id OR m.team_b_id = t.id))
            )
        WHERE tp.tournament_id = ?
        GROUP BY p.id, p.full_name, p.display_name, p.mohallah, p.its_id, p.photo_path, p.gender
        ORDER BY wins DESC, net_points DESC, p.display_name ASC
    ');
    $stmt->execute([MATCH_WALKOVER, MATCH_RETIRED, MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED, $id]);
    return $stmt->fetchAll();
});

// Player Rank Lookup Map
$playerRanks = [];
foreach ($standings as $index => $s) {
    $playerRanks[$s['id']] = [
        'rank'       => $index + 1,
        'wins'       => (int)$s['wins'],
        'losses'     => (int)$s['losses'],
        'net_points' => (int)$s['net_points'],
        'mohallah'   => $s['mohallah'],
        'photo_path' => $s['photo_path'],
        'full_name'  => $s['full_name'],
        'its_id'     => $s['its_id']
    ];
}

// 3. Fetch All Matches with Competitor Meta
$allMatches = AppCache::remember('matches_' . $id, 2, function() use ($pdo, $id) {
    $stmt = $pdo->prepare('
        SELECT m.*, 
               pa.full_name as pa_full, pa.display_name as pa_name, pa.mohallah as pa_mohallah, pa.photo_path as pa_photo, pa.its_id as pa_its,
               pb.full_name as pb_full, pb.display_name as pb_name, pb.mohallah as pb_mohallah, pb.photo_path as pb_photo, pb.its_id as pb_its,
               ta.display_name as ta_name, tb.display_name as tb_name
        FROM matches m
        LEFT JOIN players pa ON m.participant_a_id = pa.id
        LEFT JOIN players pb ON m.participant_b_id = pb.id
        LEFT JOIN teams ta ON m.team_a_id = ta.id
        LEFT JOIN teams tb ON m.team_b_id = tb.id
        WHERE m.tournament_id = ?
        ORDER BY 
            CASE m.stage WHEN \'stage1\' THEN 1 WHEN \'stage2\' THEN 2 ELSE 3 END,
            m.round_key ASC, m.match_number ASC
    ');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
});

// 4. Fetch Game / Set Breakdown for Matches
$matchGames = [];
if (!empty($allMatches)) {
    $allGames = AppCache::remember('games_' . $id, 2, function() use ($pdo, $id) {
        $stmt = $pdo->prepare('SELECT * FROM games WHERE match_id IN (SELECT id FROM matches WHERE tournament_id = ?) ORDER BY match_id, game_number ASC');
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    });
    foreach ($allGames as $g) {
        $matchGames[$g['match_id']][] = $g;
    }
}

// 5. Categorize Matches (Live, Past, Upcoming, Bracket)
$liveMatches = [];
$pastMatches = [];
$upcomingMatches = [];
$listMatchesByRound = [];
$bracket = ['r16' => [], 'qf' => [], 'sf' => [], 'final' => [], '3rd' => []];
$onCourtPlayerIds = [];

foreach ($allMatches as $m) {
    $m['display_a'] = $m['ta_name'] ?: ($m['pa_name'] ?: 'TBD');
    $m['display_b'] = $m['tb_name'] ?: ($m['pb_name'] ?: 'TBD');
    $m['games'] = $matchGames[$m['id']] ?? [];

    $isLive = in_array($m['status'], [MATCH_LIVE, 'in_progress', 'live']);
    $isCompleted = in_array($m['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED]);

    if ($isLive) {
        $liveMatches[] = $m;
        if (!empty($m['participant_a_id'])) $onCourtPlayerIds[] = (int)$m['participant_a_id'];
        if (!empty($m['participant_b_id'])) $onCourtPlayerIds[] = (int)$m['participant_b_id'];
    } elseif ($isCompleted) {
        $pastMatches[] = $m;
    } else {
        $upcomingMatches[] = $m;
    }

    if ($m['stage'] === 'stage2') {
        if ($m['round_key'] === ROUND_R16) $bracket['r16'][] = $m;
        if ($m['round_key'] === ROUND_QF) $bracket['qf'][] = $m;
        if ($m['round_key'] === ROUND_SF) $bracket['sf'][] = $m;
        if ($m['round_key'] === ROUND_FINAL) $bracket['final'][] = $m;
        if ($m['round_key'] === ROUND_3RD_PLACE) $bracket['3rd'][] = $m;
    }
    $listMatchesByRound[$m['round_key']][] = $m;
}

    $podium = Matchmaker::getPodiumWinners($id);

    // Determine default tab
    $allowedTabs = ['live', 'past', 'matches', 'standings', 'bracket'];
    $initialTab = isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs) 
        ? $_GET['tab'] 
        : (!empty($liveMatches) ? 'live' : ($podium['is_finished'] ? ($tournament['format'] === 'swiss_knockout' ? 'bracket' : 'standings') : 'matches'));

$shareUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$shareText = rawurlencode("🏸 Follow live scores & standings for {$tournament['name']} on TKMI Badminton: {$shareUrl}");

$ogTitle = $tournament['name'] . ' | TKMI Badminton Championship';
$ogDesc = count($liveMatches) > 0 
    ? count($liveMatches) . " match(es) LIVE on court now! Follow real-time scores, standings, and brackets."
    : (!empty($podium['is_finished']) ? "Tournament concluded! Champion: " . ($podium['champion']['name'] ?? 'Winner') . ". View full results & podium." : ($tournament['description'] ?: "Official championship tournament division of Toloba ul Kulliyaat il Muminoon. Follow live court action, qualifiers, and knockout brackets."));

include __DIR__ . '/../includes/header.php';
?><!-- Main Manager Wrapper for Tabs, Live SSE, and Sponsor Spotlight -->
<div x-data="tournamentPublicManager('<?= e($initialTab) ?>', <?= $id ?>)" class="w-full max-w-full overflow-x-hidden min-w-0">

<!-- ============================================================ -->
<!-- 1. PREMIUM GLASSMORPHISM SPONSOR MARQUEE                    -->
<!-- ============================================================ -->
<?php if (!empty($sponsors)): ?>
<div class="bg-[#080e1e] border-b border-white/5 py-3 relative z-30 overflow-hidden shadow-2xl">
    <!-- Ambient Glow behind marquee -->
    <div class="absolute inset-0 bg-gradient-to-r from-blue-900/10 via-[#c9a84c]/5 to-blue-900/10 opacity-60"></div>
    
    <div class="w-full mx-auto flex flex-col sm:flex-row items-center gap-4 sm:gap-6 px-4">
        
        <!-- Sponsor Badge -->
        <div class="flex items-center gap-2 text-[#c9a84c] text-[10px] sm:text-xs font-black uppercase tracking-widest flex-shrink-0 bg-[#c9a84c]/10 px-4 py-2 rounded-full border border-[#c9a84c]/20 shadow-inner z-10">
            <i class="ph-fill ph-handshake text-sm"></i>
            <span>Official Sponsors</span>
        </div>

        <!-- Seamless Infinite Marquee with Gradient Fade Mask -->
        <div class="flex-1 overflow-hidden relative group w-full" style="-webkit-mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent); mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent);">
            
            <div class="flex w-max items-center gap-4 animate-marquee hover:[animation-play-state:paused] py-1">
                <?php 
                // Double the array for seamless infinite scroll (CSS translates to -50%)
                $marqueeSponsors = array_merge($sponsors, $sponsors, $sponsors, $sponsors, $sponsors, $sponsors); 
                ?>
                <?php foreach ($marqueeSponsors as $s): 
                    $imgUrl = str_starts_with($s['image_path'], 'uploads/') ? BASE_URL . '/' . e($s['image_path']) : BASE_URL . '/uploads/sponsors/' . e($s['image_path']);
                ?>
                    <!-- 3D Glass Pill Sponsor Card -->
                    <div class="inline-flex items-center gap-3 px-1.5 py-1.5 pr-4 rounded-full bg-white/5 backdrop-blur-md border border-white/10 shadow-sm hover:bg-white/10 hover:border-white/20 transition-all duration-300 hover:scale-105 cursor-default hover:shadow-[#c9a84c]/20 hover:shadow-lg">
                        <div class="h-8 w-8 sm:h-9 sm:w-9 flex items-center justify-center bg-white rounded-full p-1.5 shadow-inner flex-shrink-0 border border-slate-200">
                            <img src="<?= $imgUrl ?>" alt="<?= e($s['name']) ?>" class="max-h-full max-w-full object-contain">
                        </div>
                        <span class="text-[10px] sm:text-xs font-black text-slate-300 tracking-wide whitespace-nowrap"><?= e($s['name']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- 2. MAIN TOURNAMENT ARENA & LIVE CENTER                      -->
<!-- ============================================================ -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 mb-20 relative z-10">

    <!-- Luxury Tournament Hero Header Bento -->
    <div class="bg-gradient-to-br from-[#0f2044] via-[#162d5d] to-[#0a1630] rounded-3xl p-6 sm:p-10 mb-8 border border-white/15 shadow-2xl text-white relative overflow-hidden">
        
        <!-- Ambient Decorative Glows -->
        <div class="absolute -top-24 -right-24 w-80 h-80 bg-[#c9a84c]/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-blue-500/15 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6 pb-8 border-b border-white/10">
            <div>
                <!-- Top Status & Format Badges -->
                <div class="flex items-center gap-3 flex-wrap mb-3">
                    <span class="px-3.5 py-1 rounded-full bg-white/10 border border-white/15 text-blue-200 text-xs font-black uppercase tracking-wider backdrop-blur-sm">
                        <?= e($tournament['gender']) ?> <?= ucfirst($tournament['match_type']) ?>
                    </span>

                    <span class="px-3.5 py-1 rounded-full bg-[#c9a84c]/20 border border-[#c9a84c]/40 text-[#c9a84c] text-xs font-black uppercase tracking-wider backdrop-blur-sm">
                        <?php 
                        if ($tournament['format'] === 'custom_knockout') echo 'Custom Matchmaking + Knockout';
                        elseif ($tournament['format'] === 'pools_knockout') echo 'Pools + Knockout';
                        elseif ($tournament['format'] === 'round_robin') echo 'Round Robin League';
                        else echo 'Swiss + Knockout';
                        ?>
                    </span>

                    <?php if (!empty($liveMatches)): ?>
                        <span class="px-3.5 py-1 rounded-full bg-red-500 text-white text-xs font-black uppercase tracking-wider shadow-lg shadow-red-500/40 animate-pulse flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-white animate-ping"></span> <?= count($liveMatches) ?> LIVE COURT NOW
                        </span>
                    <?php elseif ($tournament['status'] === 'completed'): ?>
                        <span class="px-3.5 py-1 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-xs font-black uppercase tracking-wider">
                            ✓ Tournament Concluded
                        </span>
                    <?php endif; ?>
                </div>

                <h1 class="text-3xl sm:text-5xl font-black font-display tracking-tight text-white mb-2"><?= e($tournament['name']) ?></h1>
                
                <?php if ($tournament['description']): ?>
                    <p class="text-slate-300 text-sm max-w-2xl font-medium leading-relaxed"><?= e($tournament['description']) ?></p>
                <?php else: ?>
                    <p class="text-slate-300 text-sm font-medium">Official Badminton Championship organized by Toloba ul Kulliyaat il Muminoon.</p>
                <?php endif; ?>
            </div>

            <!-- Stats Ticker Bento & WhatsApp Sharing -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
                <div class="flex items-center justify-around gap-4 sm:gap-6 bg-white/10 backdrop-blur-md p-4 sm:p-5 rounded-2xl border border-white/15 shadow-inner">
                    <div class="text-center px-2">
                        <div class="text-3xl sm:text-4xl font-black font-display text-white" data-ticker="<?= count($standings) ?>"><?= count($standings) ?></div>
                        <div class="text-[10px] text-blue-200 uppercase tracking-widest font-black mt-0.5">Players</div>
                    </div>
                    <div class="w-px h-10 bg-white/20"></div>
                    <div class="text-center px-2">
                        <div class="text-3xl sm:text-4xl font-black font-display text-[#c9a84c]" data-ticker="<?= count($allMatches) ?>"><?= count($allMatches) ?></div>
                        <div class="text-[10px] text-blue-200 uppercase tracking-widest font-black mt-0.5">Matches</div>
                    </div>
                </div>

                <!-- WhatsApp Share Button -->
                <a href="https://api.whatsapp.com/send?text=<?= $shareText ?>" 
                   target="_blank" 
                   class="bg-emerald-600 hover:bg-emerald-500 text-white font-black px-5 py-4 rounded-2xl shadow-lg hover:shadow-emerald-600/30 transition-all flex items-center justify-center gap-2 text-xs uppercase tracking-wider">
                    <i class="ph-fill ph-whatsapp-logo text-xl"></i>
                    <span>Share on WhatsApp</span>
                </a>
            </div>
        </div>

        <?php if ($podium['is_finished']): ?>
            <div class="mt-8 bg-gradient-to-br from-[#0f2044] via-[#16306e] to-[#0a152e] border-2 border-[#c9a84c] rounded-3xl p-6 sm:p-8 shadow-2xl relative overflow-hidden text-white">
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

                    <!-- 🏅 4th Place -->
                    <div class="bg-gradient-to-b from-cyan-900/25 to-cyan-950/40 border-2 border-cyan-400/60 rounded-2xl p-5 text-center flex flex-col items-center relative shadow-lg">
                        <div class="absolute -top-3.5 px-3 py-0.5 rounded-full bg-cyan-400 text-slate-950 font-black text-[11px] uppercase tracking-widest shadow-md flex items-center gap-1">
                            🏅 4th Place
                        </div>
                        <div class="w-16 h-16 rounded-full bg-cyan-400/20 border-2 border-cyan-400 flex items-center justify-center text-3xl text-cyan-300 mt-2 mb-3 shadow-inner">
                            <i class="ph-fill ph-medal"></i>
                        </div>
                        <h4 class="text-lg font-black text-white"><?= e($podium['fourth']['display_name'] ?? 'TBD') ?></h4>
                        <p class="text-xs text-cyan-200/80 font-bold mt-1"><?= e($podium['fourth']['mohallah'] ?? '') ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- 3. TOP-TIER VIEW MODE SELECTOR (SEGMENTED CONTROL)           -->
        <!-- ============================================================ -->
        <div class="pt-6 flex items-center gap-2 overflow-x-auto no-scrollbar pb-1">
            
            <!-- LIVE MATCHES TAB -->
            <button @click="tab = 'live'" 
                    :class="tab === 'live' ? 'bg-red-600 text-white shadow-lg shadow-red-600/30' : 'bg-white/10 text-slate-200 hover:bg-white/20 hover:text-white border border-white/10'" 
                    class="px-5 py-3 rounded-2xl font-black transition-all duration-200 whitespace-nowrap flex items-center gap-2 text-xs uppercase tracking-wider flex-shrink-0 cursor-pointer">
                <?php if (!empty($liveMatches)): ?>
                    <span class="w-2 h-2 rounded-full bg-white animate-ping"></span>
                <?php else: ?>
                    <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                <?php endif; ?>
                <span>Live Matches (<?= count($liveMatches) ?>)</span>
            </button>

            <!-- PAST MATCHES TAB -->
            <button @click="tab = 'past'" 
                    :class="tab === 'past' ? 'bg-[#c9a84c] text-[#0f2044] shadow-lg shadow-[#c9a84c]/30 font-black' : 'bg-white/10 text-slate-200 hover:bg-white/20 hover:text-white border border-white/10'" 
                    class="px-5 py-3 rounded-2xl font-bold transition-all duration-200 whitespace-nowrap flex items-center gap-2 text-xs uppercase tracking-wider flex-shrink-0 cursor-pointer">
                <i class="ph-bold ph-check-circle text-base"></i>
                <span>Past Matches (<?= count($pastMatches) ?>)</span>
            </button>

            <!-- ALL MATCHES TAB -->
            <button @click="tab = 'matches'" 
                    :class="tab === 'matches' ? 'bg-white text-[#0f2044] shadow-lg font-black' : 'bg-white/10 text-slate-200 hover:bg-white/20 hover:text-white border border-white/10'" 
                    class="px-5 py-3 rounded-2xl font-bold transition-all duration-200 whitespace-nowrap flex items-center gap-2 text-xs uppercase tracking-wider flex-shrink-0 cursor-pointer">
                <i class="ph-bold ph-swords text-base"></i>
                <span>All Fixtures (<?= count($allMatches) ?>)</span>
            </button>

            <!-- STANDINGS TAB -->
            <button @click="tab = 'standings'" 
                    :class="tab === 'standings' ? 'bg-white text-[#0f2044] shadow-lg font-black' : 'bg-white/10 text-slate-200 hover:bg-white/20 hover:text-white border border-white/10'" 
                    class="px-5 py-3 rounded-2xl font-bold transition-all duration-200 whitespace-nowrap flex items-center gap-2 text-xs uppercase tracking-wider flex-shrink-0 cursor-pointer">
                <i class="ph-bold ph-list-numbers text-base"></i>
                <span>Leaderboard Standings</span>
            </button>

            <!-- KNOCKOUT BRACKET TAB -->
            <?php if (in_array($tournament['format'], ['swiss_knockout', 'custom_knockout', 'pools_knockout'])): ?>
            <button @click="tab = 'bracket'" 
                    :class="tab === 'bracket' ? 'bg-white text-[#0f2044] shadow-lg font-black' : 'bg-white/10 text-slate-200 hover:bg-white/20 hover:text-white border border-white/10'" 
                    class="px-5 py-3 rounded-2xl font-bold transition-all duration-200 whitespace-nowrap flex items-center gap-2 text-xs uppercase tracking-wider flex-shrink-0 cursor-pointer">
                <i class="ph-bold ph-tree-structure text-base text-[#c9a84c]"></i>
                <span>Knockout Bracket</span>
            </button>
            <?php endif; ?>

        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB 1: LIVE ON-COURT MATCHES                                 -->
    <!-- ============================================================ -->
    <div x-show="tab === 'live'" x-cloak class="space-y-10">

        <div id="tournament-live-section" class="<?= empty($liveMatches) ? 'hidden' : '' ?>">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-3 border-b border-slate-200">
                <div class="flex items-center gap-3">
                    <span class="w-3.5 h-3.5 rounded-full bg-red-500 animate-ping"></span>
                    <h2 class="text-2xl font-black font-display text-[#0f2044]">Matches Currently On Court</h2>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8" id="tournament-live-grid">
                <?php foreach ($liveMatches as $m): 
                        $pA = $playerRanks[$m['participant_a_id']] ?? null;
                        $pB = $playerRanks[$m['participant_b_id']] ?? null;
                        $matchId = $m['id'];
                        $curMomA = 50;
                        $curMomB = 50;
                    ?>
                        <div class="bg-white rounded-3xl p-6 sm:p-7 border-2 border-red-400 ring-4 ring-red-50 shadow-2xl relative overflow-hidden flex flex-col justify-between group bg-badminton-court"
                             id="live-match-card-<?= $matchId ?>">
                            
                            <!-- Top Live Badge & Match Header -->
                            <div class="flex items-center justify-between mb-5 border-b border-slate-100 pb-3">
                                <div class="flex items-center gap-2">
                                    <span id="live-status-badge-<?= $matchId ?>" class="px-3 py-1 rounded-full bg-red-500 text-white text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 shadow-sm animate-pulse transition-all duration-500">
                                        <span class="w-1.5 h-1.5 rounded-full bg-white"></span> LIVE COURT
                                    </span>
                                    <span class="text-xs font-bold text-slate-400">Match #<?= $m['match_number'] ?> &bull; <?= getRoundLabel($m['round_key']) ?></span>
                                    <?php
                                    $isCurDeuce = !empty($m['deuce_enabled']) && (int)$m['score_a'] >= (int)$m['deuce_trigger'] && (int)$m['score_b'] >= (int)$m['deuce_trigger'];
                                    ?>
                                    <span id="deuce-badge-<?= $matchId ?>" class="<?= $isCurDeuce ? '' : 'hidden' ?> px-2.5 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-black uppercase tracking-wider shadow-sm animate-bounce">
                                        🔥 DEUCE
                                    </span>
                                </div>

                                <div class="flex items-center gap-2">
                                    <?php if (!empty($sponsors)): 
                                        $spCorner = $sponsors[$matchId % count($sponsors)];
                                        $spCornerUrl = str_starts_with($spCorner['image_path'], 'uploads/') ? BASE_URL . '/' . e($spCorner['image_path']) : BASE_URL . '/uploads/sponsors/' . e($spCorner['image_path']);
                                    ?>
                                        <div @click="openSponsor(<?= $matchId % count($sponsors) ?>)" 
                                             title="Match Sponsor: <?= e($spCorner['name']) ?>" 
                                             class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-amber-50/90 hover:bg-amber-100/90 border border-amber-200/80 text-[#997d2e] text-[9px] font-black uppercase tracking-wider shadow-2xs cursor-pointer transition-all">
                                            <i class="ph-fill ph-handshake text-xs text-[#c9a84c]"></i>
                                            <span>Sponsor</span>
                                            <img src="<?= $spCornerUrl ?>" alt="Sponsor" class="h-3.5 max-w-[48px] object-contain">
                                        </div>
                                    <?php endif; ?>
                                    <div id="live-game-num-<?= $matchId ?>" class="text-xs font-mono font-black text-red-600 bg-red-50 px-2.5 py-1 rounded-lg">
                                        Game <?= ((int)($m['games_a'] ?? 0) + (int)($m['games_b'] ?? 0)) + 1 ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Competitors Arena with Live Scores and Net Divider -->
                            <div class="space-y-3 my-2">
                                
                                <!-- Competitor A -->
                                <div class="p-4 rounded-2xl bg-slate-50/90 border border-slate-200 flex items-center justify-between transition-colors shadow-xs">
                                    <div class="flex items-center gap-3.5 min-w-0 pr-2">
                                        <!-- Avatar -->
                                        <div class="relative flex-shrink-0">
                                            <?php if (!empty($m['pa_photo'])): ?>
                                                <img src="<?= BASE_URL ?>/uploads/players/<?= e($m['pa_photo']) ?>" alt="Photo" class="w-12 h-12 rounded-2xl object-cover shadow-sm border-2 border-cyan-400">
                                            <?php else: ?>
                                                <div class="w-12 h-12 rounded-2xl bg-blue-100 text-blue-800 flex items-center justify-center font-black text-base shadow-inner border border-blue-200">
                                                    <?= strtoupper(substr($m['display_a'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Feathered Shuttlecock Server Indicator -->
                                            <div id="server-indicator-a-<?= $matchId ?>" 
                                                 class="<?= ($m['current_server'] ?? 'player_a') === 'player_a' ? '' : 'hidden' ?> absolute -bottom-1.5 -right-1.5 w-6 h-6 rounded-full bg-[#08152e] border border-cyan-400 flex items-center justify-center shadow-md p-0.5 animate-shuttle-pulse"
                                                 title="Serving Now">
                                                <svg viewBox="0 0 32 32" fill="none" class="w-full h-full transform rotate-45">
                                                    <path d="M12 24C12 26.2 13.8 28 16 28C18.2 28 20 26.2 20 24V22H12V24Z" fill="#F8FAFC" stroke="#C9A84C" stroke-width="1.2"/>
                                                    <rect x="12" y="20.5" width="8" height="2" fill="#06B6D4" rx="0.5"/>
                                                    <path d="M12 20.5L6 6.5C9 5 23 5 26 6.5L20 20.5H12Z" fill="white" stroke="#E2E8F0" stroke-width="0.8"/>
                                                    <line x1="12" y1="20.5" x2="9" y2="6.5" stroke="#C9A84C" stroke-width="0.7"/>
                                                    <line x1="16" y1="20.5" x2="16" y2="5.8" stroke="#C9A84C" stroke-width="0.8"/>
                                                    <line x1="20" y1="20.5" x2="23" y2="6.5" stroke="#C9A84C" stroke-width="0.7"/>
                                                </svg>
                                            </div>
                                        </div>
                                        
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <div class="font-black text-base text-slate-900 truncate"><?= e($m['display_a']) ?></div>
                                                <?php if ($pA): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#c9a84c]/20 text-[#997d2e] border border-[#c9a84c]/30 flex-shrink-0">
                                                        Rank #<?= $pA['rank'] ?> (<?= $pA['wins'] ?>W-<?= $pA['losses'] ?>L)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Score Box with Stringbed Pattern -->
                                    <div id="live-score-a-<?= $matchId ?>" 
                                         class="text-3xl font-black font-display text-slate-900 bg-white border border-slate-200 px-4 py-2 rounded-xl shadow-xs min-w-[52px] text-center transition-all duration-300 bg-racket-strings">
                                        <?= (int)$m['score_a'] ?>
                                    </div>
                                </div>

                                <!-- Across the Net Divider -->
                                <div class="badminton-net-divider-h rounded-full my-1 opacity-75"></div>

                                <!-- Competitor B -->
                                <div class="p-4 rounded-2xl bg-slate-50/90 border border-slate-200 flex items-center justify-between transition-colors shadow-xs">
                                    <div class="flex items-center gap-3.5 min-w-0 pr-2">
                                        <!-- Avatar -->
                                        <div class="relative flex-shrink-0">
                                            <?php if (!empty($m['pb_photo'])): ?>
                                                <img src="<?= BASE_URL ?>/uploads/players/<?= e($m['pb_photo']) ?>" alt="Photo" class="w-12 h-12 rounded-2xl object-cover shadow-sm border-2 border-amber-400">
                                            <?php else: ?>
                                                <div class="w-12 h-12 rounded-2xl bg-blue-100 text-blue-800 flex items-center justify-center font-black text-base shadow-inner border border-blue-200">
                                                    <?= strtoupper(substr($m['display_b'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>

                                            <!-- Feathered Shuttlecock Server Indicator -->
                                            <div id="server-indicator-b-<?= $matchId ?>" 
                                                 class="<?= ($m['current_server'] ?? '') === 'player_b' ? '' : 'hidden' ?> absolute -bottom-1.5 -right-1.5 w-6 h-6 rounded-full bg-[#1c1307] border border-amber-400 flex items-center justify-center shadow-md p-0.5 animate-shuttle-pulse"
                                                 title="Serving Now">
                                                <svg viewBox="0 0 32 32" fill="none" class="w-full h-full transform -rotate-45">
                                                    <path d="M12 24C12 26.2 13.8 28 16 28C18.2 28 20 26.2 20 24V22H12V24Z" fill="#F8FAFC" stroke="#C9A84C" stroke-width="1.2"/>
                                                    <rect x="12" y="20.5" width="8" height="2" fill="#D97706" rx="0.5"/>
                                                    <path d="M12 20.5L6 6.5C9 5 23 5 26 6.5L20 20.5H12Z" fill="white" stroke="#E2E8F0" stroke-width="0.8"/>
                                                    <line x1="12" y1="20.5" x2="9" y2="6.5" stroke="#C9A84C" stroke-width="0.7"/>
                                                    <line x1="16" y1="20.5" x2="16" y2="5.8" stroke="#C9A84C" stroke-width="0.8"/>
                                                    <line x1="20" y1="20.5" x2="23" y2="6.5" stroke="#C9A84C" stroke-width="0.7"/>
                                                </svg>
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <div class="font-black text-base text-slate-900 truncate"><?= e($m['display_b']) ?></div>
                                                <?php if ($pB): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-[#c9a84c]/20 text-[#997d2e] border border-[#c9a84c]/30 flex-shrink-0">
                                                        Rank #<?= $pB['rank'] ?> (<?= $pB['wins'] ?>W-<?= $pB['losses'] ?>L)
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Score Box with Stringbed Pattern -->
                                    <div id="live-score-b-<?= $matchId ?>" 
                                         class="text-3xl font-black font-display text-slate-900 bg-white border border-slate-200 px-4 py-2 rounded-xl shadow-xs min-w-[52px] text-center transition-all duration-300 bg-racket-strings">
                                        <?= (int)$m['score_b'] ?>
                                    </div>
                                </div>

                            </div>

                            <!-- ============================================================ -->
                            <!-- LIVE AI WIN PROBABILITY & MOMENTUM METER                    -->
                            <!-- ============================================================ -->
                            <div class="mt-4 pt-3 pb-2 border-t border-slate-100">
                                <div class="flex items-center justify-between text-[11px] font-black mb-1.5">
                                    <span class="text-blue-700 flex items-center gap-1">
                                        <i class="ph-fill ph-lightning text-[#c9a84c]"></i>
                                        <span><?= e($m['display_a']) ?></span>: <strong id="mom-val-a-<?= $matchId ?>"><?= $curMomA ?>%</strong>
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">AI Win Predictor</span>
                                    <span class="text-indigo-800 flex items-center gap-1">
                                        <strong id="mom-val-b-<?= $matchId ?>"><?= $curMomB ?>%</strong>: <span><?= e($m['display_b']) ?></span>
                                    </span>
                                </div>

                                <!-- Dynamic Neon Momentum Bar -->
                                <div class="w-full h-2.5 rounded-full bg-slate-200 overflow-hidden flex shadow-inner">
                                    <div id="mom-bar-a-<?= $matchId ?>" 
                                         class="h-full bg-gradient-to-r from-blue-600 to-cyan-500 transition-all duration-700" 
                                         style="width: <?= $curMomA ?>%"></div>
                                    <div id="mom-bar-b-<?= $matchId ?>" 
                                         class="h-full bg-gradient-to-r from-indigo-500 to-purple-600 transition-all duration-700" 
                                         style="width: <?= $curMomB ?>%"></div>
                                </div>
                            </div>

                            <!-- Set / Games Score History -->
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-bold text-slate-500">
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] uppercase tracking-wider text-slate-400">Games Won:</span>
                                    <span class="font-black text-slate-800">
                                        <span id="live-games-a-<?= $matchId ?>"><?= $m['games_a'] ?></span> 
                                        - 
                                        <span id="live-games-b-<?= $matchId ?>"><?= $m['games_b'] ?></span>
                                    </span>
                                </div>

                                <?php if (!empty($m['games'])): ?>
                                    <div class="flex items-center gap-1.5 font-mono text-[11px]">
                                        <?php foreach ($m['games'] as $g): ?>
                                            <span class="px-2 py-0.5 rounded bg-slate-100 border text-slate-600 font-bold">
                                                G<?= $g['game_number'] ?>: <?= $g['score_a'] ?>-<?= $g['score_b'] ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
            </div>
        </div>

        <!-- Case B: 0 Live Matches State -->
        <div id="tournament-no-live-placeholder" class="<?= !empty($liveMatches) ? 'hidden' : '' ?> bg-white rounded-3xl p-12 text-center max-w-2xl mx-auto border border-slate-200 shadow-sm">
            <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto mb-4 text-slate-400">
                <i class="ph-fill ph-hourglass-medium text-4xl text-[#c9a84c]"></i>
            </div>
            <h3 class="text-2xl font-black font-display text-[#0f2044] mb-2">No Matches Currently On Court</h3>
            <p class="text-slate-500 text-sm font-medium max-w-md mx-auto mb-6">All active games for this session have either concluded or the next round is being scheduled by the tournament desk.</p>
            
            <div class="flex items-center justify-center gap-3">
                <button @click="tab = 'matches'" class="px-5 py-2.5 rounded-xl bg-[#0f2044] hover:bg-blue-900 text-white font-bold text-xs uppercase tracking-wider transition shadow-md">
                    View Upcoming Fixtures &rarr;
                </button>
                <button @click="tab = 'standings'" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs uppercase tracking-wider transition">
                    View Leaderboard &rarr;
                </button>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- LIVE STANDINGS TABLE WITH ON-COURT ATHLETE HIGHLIGHTS        -->
        <!-- ============================================================ -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mt-10">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center font-bold shadow-xs">
                        <i class="ph-fill ph-trophy text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-[#0f2044] text-lg">Live Tournament Standings</h3>
                        <p class="text-xs text-slate-400 font-medium">Rankings dynamically updated after every completed match</p>
                    </div>
                </div>

                <?php if (!empty($onCourtPlayerIds)): ?>
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-red-50 border border-red-200 text-red-700 text-xs font-bold shadow-2xs">
                        <span class="w-2 h-2 rounded-full bg-red-500 animate-ping"></span>
                        <span>Highlighted rows are currently playing on court</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 text-slate-400 text-[10px] uppercase tracking-widest font-black border-b border-slate-100">
                            <th class="p-4 pl-6 w-16 text-center">Rank</th>
                            <th class="p-4">Player Details</th>
                            <th class="p-4">Mohallah</th>
                            <th class="p-4 text-center">Played</th>
                            <th class="p-4 text-center text-emerald-600">Wins</th>
                            <th class="p-4 text-center text-red-500">Losses</th>
                            <th class="p-4 text-center text-blue-600 pr-6">Net Points</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <?php foreach ($standings as $index => $s): 
                            $isOnCourt = in_array((int)$s['id'], $onCourtPlayerIds);
                            $rank = $index + 1;
                        ?>
                            <tr class="transition-colors <?= $isOnCourt ? 'bg-red-50/70 font-black border-l-4 border-l-red-500 shadow-inner' : 'hover:bg-slate-50/60' ?>">
                                <td class="p-4 pl-6 text-center font-black">
                                    <?php if ($rank === 1): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-[#f59e0b] via-[#d97706] to-[#b45309] text-white font-display font-black text-sm shadow-md shadow-amber-500/30 border border-amber-300 ring-2 ring-amber-100/80" title="Rank 1 - Gold">
                                            1
                                        </div>
                                    <?php elseif ($rank === 2): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-slate-400 via-slate-500 to-slate-600 text-white font-display font-black text-sm shadow-md shadow-slate-400/20 border border-slate-300 ring-2 ring-slate-100" title="Rank 2 - Silver">
                                            2
                                        </div>
                                    <?php elseif ($rank === 3): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-[#c87d55] via-[#a86038] to-[#8c4b27] text-white font-display font-black text-sm shadow-md shadow-amber-900/20 border border-amber-400/40 ring-2 ring-amber-50" title="Rank 3 - Bronze">
                                            3
                                        </div>
                                    <?php elseif ($rank <= 8): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-blue-50 text-blue-700 font-mono font-bold text-xs border border-blue-200/80 shadow-2xs" title="Stage 2 Qualifier Zone">
                                            <?= sprintf('%02d', $rank) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-slate-50 text-slate-400 font-mono font-medium text-xs border border-slate-200/60">
                                            <?= sprintf('%02d', $rank) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($s['photo_path'])): ?>
                                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($s['photo_path']) ?>" alt="Photo" class="w-9 h-9 rounded-xl object-cover shadow-xs border border-slate-200">
                                        <?php else: ?>
                                            <div class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-slate-500 font-black text-xs border border-slate-200">
                                                <?= strtoupper(substr($s['display_name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <div class="font-black text-slate-900 flex items-center gap-2">
                                                <span><?= e($s['full_name']) ?></span>
                                                <?php if ($isOnCourt): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-red-500 text-white animate-pulse">
                                                        ● ON COURT
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-400 font-mono whitespace-nowrap"><?= e($s['its_id']) ?> &bull; <?= e($s['display_name']) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="p-4 text-xs font-medium text-slate-600 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 bg-slate-100 px-2.5 py-1 rounded-lg">
                                        <i class="ph-fill ph-map-pin text-[#c9a84c]"></i> <?= e($s['mohallah']) ?>
                                    </span>
                                </td>

                                <td class="p-4 text-center font-bold text-slate-700"><?= $s['played'] ?></td>
                                <td class="p-4 text-center font-black text-emerald-600 text-base"><?= $s['wins'] ?></td>
                                <td class="p-4 text-center font-bold text-red-500"><?= $s['losses'] ?></td>
                                <td class="p-4 pr-6 text-center font-black text-blue-600">
                                    <?= $s['net_points'] > 0 ? '+'.$s['net_points'] : $s['net_points'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-4 bg-slate-50 text-xs text-slate-500 border-t border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="ph-fill ph-info text-blue-500"></i>
                    <span><strong>Tiebreaker hierarchy:</strong> 1. Total Wins &bull; 2. Net Point Difference &bull; 3. Head-to-Head Record</span>
                </div>
            </div>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- TAB 2: PAST / COMPLETED MATCHES (RESULTS & SCORES)           -->
    <!-- ============================================================ -->
    <div x-show="tab === 'past'" x-cloak class="space-y-6">
        
        <div class="flex items-center justify-between mb-6 pb-3 border-b border-slate-200">
            <h2 class="text-2xl font-black font-display text-[#0f2044]">Finished Matches & Final Results</h2>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-widest"><?= count($pastMatches) ?> Matches Completed</span>
        </div>

        <?php if (empty($pastMatches)): ?>
            <div class="bg-white rounded-3xl p-16 text-center max-w-xl mx-auto border border-dashed border-slate-200">
                <i class="ph-fill ph-clipboard-text text-4xl text-slate-300 mb-3"></i>
                <h3 class="text-xl font-bold text-slate-800">No matches completed yet</h3>
                <p class="text-slate-400 text-xs mt-1">Completed matches with final scores and winner cards will appear here as soon as scores are submitted.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($pastMatches as $m): 
                    $isDoubles = !empty($m['team_a_id']);
                    $winnerId = $isDoubles ? $m['winner_team_id'] : $m['winner_player_id'];
                    $paId = $isDoubles ? $m['team_a_id'] : $m['participant_a_id'];
                    $pbId = $isDoubles ? $m['team_b_id'] : $m['participant_b_id'];
                    $isA贏 = ($winnerId == $paId);
                    $isB贏 = ($winnerId == $pbId);
                ?>
                    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm hover:shadow-md transition-all flex flex-col justify-between">
                        
                        <div>
                            <!-- Header -->
                            <div class="flex items-center justify-between mb-4 border-b border-slate-100 pb-3">
                                <span class="text-xs font-bold text-slate-400">Match #<?= $m['match_number'] ?> &bull; <?= getRoundLabel($m['round_key']) ?></span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    ✓ FINAL
                                </span>
                            </div>

                            <!-- Competitors -->
                            <div class="space-y-3">
                                
                                <!-- Competitor A -->
                                <div class="p-3 rounded-2xl flex items-center justify-between <?= $isA贏 ? 'bg-emerald-50/80 border border-emerald-300 ring-2 ring-emerald-500/10' : 'bg-slate-50 border border-slate-100 opacity-80' ?>">
                                    <div class="flex items-center gap-3 min-w-0 pr-2">
                                        <div class="w-8 h-8 rounded-xl <?= $isA贏 ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-600' ?> flex items-center justify-center font-black text-xs flex-shrink-0">
                                            <?= $isA贏 ? '👑' : strtoupper(substr($m['display_a'], 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-black text-sm <?= $isA贏 ? 'text-emerald-950' : 'text-slate-700' ?> truncate"><?= e($m['display_a']) ?></div>
                                            <div class="text-[10px] text-slate-400 font-medium"><?= e($m['pa_mohallah'] ?: 'TKMI') ?></div>
                                        </div>
                                    </div>
                                    <span class="text-xl font-black font-display <?= $isA贏 ? 'text-emerald-700' : 'text-slate-400' ?>">
                                        <?= $m['games_a'] ?>
                                    </span>
                                </div>

                                <!-- Competitor B -->
                                <div class="p-3 rounded-2xl flex items-center justify-between <?= $isB贏 ? 'bg-emerald-50/80 border border-emerald-300 ring-2 ring-emerald-500/10' : 'bg-slate-50 border border-slate-100 opacity-80' ?>">
                                    <div class="flex items-center gap-3 min-w-0 pr-2">
                                        <div class="w-8 h-8 rounded-xl <?= $isB贏 ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-600' ?> flex items-center justify-center font-black text-xs flex-shrink-0">
                                            <?= $isB贏 ? '👑' : strtoupper(substr($m['display_b'], 0, 1)) ?>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-black text-sm <?= $isB贏 ? 'text-emerald-950' : 'text-slate-700' ?> truncate"><?= e($m['display_b']) ?></div>
                                            <div class="text-[10px] text-slate-400 font-medium"><?= e($m['pb_mohallah'] ?: 'TKMI') ?></div>
                                        </div>
                                    </div>
                                    <span class="text-xl font-black font-display <?= $isB贏 ? 'text-emerald-700' : 'text-slate-400' ?>">
                                        <?= $m['games_b'] ?>
                                    </span>
                                </div>

                            </div>
                        </div>

                        <!-- Match Set Breakdown -->
                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-bold text-slate-500">
                            <span class="text-[10px] text-slate-400 uppercase tracking-widest">Sets:</span>
                            <?php if (!empty($m['games'])): ?>
                                <div class="flex items-center gap-1 font-mono text-[11px]">
                                    <?php foreach ($m['games'] as $g): ?>
                                        <span class="px-2 py-0.5 rounded bg-slate-100 border text-slate-700">
                                            <?= $g['score_a'] ?>-<?= $g['score_b'] ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="font-mono"><?= $m['score_a'] ?> - <?= $m['score_b'] ?></span>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ============================================================ -->
    <!-- TAB 3: ALL FIXTURES (ROUND-BY-ROUND)                         -->
    <!-- ============================================================ -->
    <div x-show="tab === 'matches'" x-cloak class="space-y-12">
        <?php if (empty($listMatchesByRound)): ?>
            <div class="bg-white rounded-3xl p-16 text-center max-w-xl mx-auto border border-dashed border-slate-200">
                <i class="ph-fill ph-brackets-curly text-4xl text-slate-300 mb-3"></i>
                <h3 class="text-xl font-bold text-slate-800">Fixtures Pending</h3>
                <p class="text-slate-400 text-xs mt-1">Round fixtures will be published as soon as pairings are confirmed.</p>
            </div>
        <?php else: ?>
            <?php foreach ($listMatchesByRound as $roundKey => $matches): ?>
                <div x-data="{ open: true }" class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-8 transition-all">
                    <button type="button" 
                            @click="open = !open" 
                            class="w-full flex items-center justify-between p-5 sm:p-6 bg-slate-50/70 hover:bg-slate-100/80 transition-colors text-left cursor-pointer border-b border-transparent select-none group"
                            :class="{ 'border-slate-200': open }">
                        <div class="flex items-center gap-3">
                            <span class="w-8 h-8 rounded-xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center font-bold text-xs shadow-2xs">
                                <i class="ph-fill ph-swords"></i>
                            </span>
                            <h3 class="text-xl sm:text-2xl font-black font-display text-[#0f2044] group-hover:text-blue-900 transition-colors">
                                <?= getRoundLabel($roundKey) ?>
                            </h3>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-bold text-slate-500 bg-white px-3 py-1.5 rounded-xl border border-slate-200 shadow-2xs uppercase tracking-wider">
                                <?= count($matches) ?> Matches
                            </span>
                            <div class="w-8 h-8 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-slate-600 shadow-2xs transition-transform duration-200"
                                 :class="{ 'rotate-180': open }">
                                <i class="ph-bold ph-caret-down text-base"></i>
                            </div>
                        </div>
                    </button>
                    
                    <div x-show="open" x-transition.opacity.duration.200ms class="p-5 sm:p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($matches as $m): 
                            $isCompleted = in_array($m['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED]);
                            $isLive = in_array($m['status'], [MATCH_LIVE, 'in_progress']);
                            $isDoubles = !empty($m['team_a_id']);
                            $winnerId = $isDoubles ? $m['winner_team_id'] : $m['winner_player_id'];
                            $paId = $isDoubles ? $m['team_a_id'] : $m['participant_a_id'];
                            $pbId = $isDoubles ? $m['team_b_id'] : $m['participant_b_id'];
                        ?>
                            <div class="bg-white rounded-3xl p-5 border <?= $isLive ? 'border-red-400 ring-2 ring-red-100 shadow-lg' : 'border-slate-200 shadow-sm' ?> hover:shadow-md transition-all flex flex-col justify-between">
                                
                                <div>
                                    <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2">
                                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Match #<?= $m['match_number'] ?></span>
                                        
                                        <?php if ($isLive): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-red-50 text-red-600 text-[9px] font-black uppercase tracking-wider border border-red-200 animate-pulse flex items-center gap-1">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> LIVE
                                            </span>
                                        <?php elseif ($isCompleted): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[9px] font-black uppercase tracking-wider border border-emerald-200">
                                                FINAL
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[9px] font-bold uppercase tracking-wider">
                                                SCHEDULED
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Player A -->
                                    <div class="p-3 rounded-2xl mb-2 flex items-center justify-between <?= ($isCompleted && $winnerId == $paId) ? 'bg-emerald-50 text-emerald-950 font-black border border-emerald-200' : 'bg-slate-50 text-slate-700 font-bold' ?>">
                                        <span class="truncate pr-2 text-sm"><?= e($m['display_a']) ?></span>
                                        <span class="font-black text-lg <?= ($isCompleted && $winnerId == $paId) ? 'text-emerald-700' : 'text-slate-400' ?>">
                                            <?= $isLive ? $m['score_a'] : $m['games_a'] ?>
                                        </span>
                                    </div>

                                    <!-- Player B -->
                                    <div class="p-3 rounded-2xl flex items-center justify-between <?= ($isCompleted && $winnerId == $pbId) ? 'bg-emerald-50 text-emerald-950 font-black border border-emerald-200' : 'bg-slate-50 text-slate-700 font-bold' ?>">
                                        <span class="truncate pr-2 text-sm"><?= e($m['display_b']) ?></span>
                                        <span class="font-black text-lg <?= ($isCompleted && $winnerId == $pbId) ? 'text-emerald-700' : 'text-slate-400' ?>">
                                            <?= $isLive ? $m['score_b'] : $m['games_b'] ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 border-t border-slate-100 text-[10px] uppercase tracking-widest text-center font-bold text-slate-400">
                                    <?php 
                                    if ($isCompleted) {
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
                        <?php endforeach; ?>
                    </div>
                </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- TAB 4: LEADERBOARD STANDINGS (STANDALONE FULL VIEW)          -->
    <!-- ============================================================ -->
    <div x-show="tab === 'standings'" x-cloak class="space-y-6">
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <!-- Standings Header -->
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#0f2044] text-[#c9a84c] flex items-center justify-center font-bold shadow-xs">
                        <i class="ph-fill ph-trophy text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-[#0f2044] text-lg">Official Tournament Standings</h3>
                        <p class="text-xs text-slate-400 font-medium">Rankings across all qualifier and pool stages</p>
                    </div>
                </div>

                <div class="hidden sm:flex items-center gap-2 text-[11px] font-mono font-bold text-slate-500 bg-white px-3.5 py-2 rounded-xl border border-slate-200/80 shadow-2xs">
                    <i class="ph-bold ph-info text-[#c9a84c]"></i>
                    <span>Tiebreaker: 1. Total Wins &bull; 2. Net Points &bull; 3. Head-to-Head</span>
                </div>
            </div>

            <!-- Mobile Swipe Hint -->
            <div class="md:hidden px-4 py-2 bg-slate-50 border-b border-slate-100 flex items-center justify-center gap-1.5 text-[11px] font-bold text-slate-500">
                <i class="ph-bold ph-arrows-left-right text-xs text-[#c9a84c]"></i>
                <span>Swipe table horizontally to view full stats</span>
            </div>

            <!-- Full Width Standings Table -->
            <div class="overflow-x-auto w-full">
                <table class="w-full text-left border-collapse min-w-[700px]">
                    <thead>
                        <tr class="bg-slate-50/70 text-slate-400 text-[10px] uppercase tracking-widest font-black border-b border-slate-100">
                            <th class="p-4 pl-6 w-16 text-center">Rank</th>
                            <th class="p-4 min-w-[220px]">Player Details</th>
                            <th class="p-4 min-w-[140px]">Mohallah</th>
                            <th class="p-4 text-center w-20">Played</th>
                            <th class="p-4 text-center w-20 text-emerald-600 font-black">Wins</th>
                            <th class="p-4 text-center w-20 text-red-500 font-black">Losses</th>
                            <th class="p-4 text-center w-24 text-blue-600 pr-6 font-black">Net Points</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm">
                        <?php foreach ($standings as $index => $s): 
                            $isOnCourt = in_array((int)$s['id'], $onCourtPlayerIds);
                            $rank = $index + 1;
                        ?>
                            <tr class="transition-colors <?= $isOnCourt ? 'bg-red-50/70 font-black border-l-4 border-l-red-500 shadow-inner' : 'hover:bg-slate-50/60' ?>">
                                <td class="p-4 pl-6 text-center font-black">
                                    <?php if ($rank === 1): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-[#f59e0b] via-[#d97706] to-[#b45309] text-white font-display font-black text-sm shadow-md shadow-amber-500/30 border border-amber-300 ring-2 ring-amber-100/80" title="Rank 1 - Gold Champion">
                                            1
                                        </div>
                                    <?php elseif ($rank === 2): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-slate-400 via-slate-500 to-slate-600 text-white font-display font-black text-sm shadow-md shadow-slate-400/20 border border-slate-300 ring-2 ring-slate-100" title="Rank 2 - Silver Finalist">
                                            2
                                        </div>
                                    <?php elseif ($rank === 3): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-[#c87d55] via-[#a86038] to-[#8c4b27] text-white font-display font-black text-sm shadow-md shadow-amber-900/20 border border-amber-400/40 ring-2 ring-amber-50" title="Rank 3 - Bronze Podium">
                                            3
                                        </div>
                                    <?php elseif ($rank <= 8): ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-blue-50 text-blue-700 font-mono font-bold text-xs border border-blue-200/80 shadow-2xs" title="Stage 2 Qualifier Zone">
                                            <?= sprintf('%02d', $rank) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-slate-50 text-slate-400 font-mono font-medium text-xs border border-slate-200/60">
                                            <?= sprintf('%02d', $rank) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4">
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($s['photo_path'])): ?>
                                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($s['photo_path']) ?>" alt="Photo" class="w-9 h-9 rounded-xl object-cover shadow-xs border border-slate-200">
                                        <?php else: ?>
                                            <div class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-slate-500 font-black text-xs border border-slate-200">
                                                <?= strtoupper(substr($s['display_name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <div class="font-black text-slate-900 flex items-center gap-2">
                                                <span><?= e($s['full_name']) ?></span>
                                                <?php if ($isOnCourt): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-red-500 text-white animate-pulse">
                                                        ● ON COURT
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-400 font-mono whitespace-nowrap"><?= e($s['its_id']) ?> &bull; <?= e($s['display_name']) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="p-4 text-xs font-medium text-slate-600 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 bg-slate-100 px-2.5 py-1 rounded-lg">
                                        <i class="ph-fill ph-map-pin text-[#c9a84c]"></i> <?= e($s['mohallah']) ?>
                                    </span>
                                </td>

                                <td class="p-4 text-center font-bold text-slate-700"><?= $s['played'] ?></td>
                                <td class="p-4 text-center font-black text-emerald-600 text-base"><?= $s['wins'] ?></td>
                                <td class="p-4 text-center font-bold text-red-500"><?= $s['losses'] ?></td>
                                <td class="p-4 pr-6 text-center font-black text-blue-600">
                                    <?= $s['net_points'] > 0 ? '+'.$s['net_points'] : $s['net_points'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="p-4 bg-slate-50 text-xs text-slate-500 border-t border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="ph-fill ph-info text-blue-500"></i>
                    <span><strong>Tiebreaker hierarchy:</strong> 1. Total Wins &bull; 2. Net Point Difference &bull; 3. Head-to-Head Record</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- TAB 5: KNOCKOUT BRACKET (STAGE 2 TREE)                       -->
    <!-- ============================================================ -->
    <div x-show="tab === 'bracket'" x-cloak class="space-y-6">
        <?php 
        $hasAnyBracket = !empty($bracket['r16']) || !empty($bracket['qf']) || !empty($bracket['sf']) || !empty($bracket['final']);
        if (!$hasAnyBracket): 
        ?>
            <div class="bg-white rounded-3xl text-center py-20 p-8 border border-slate-200 shadow-sm max-w-xl mx-auto">
                <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4 text-slate-400">
                    <i class="ph-bold ph-lock-key text-3xl text-[#c9a84c]"></i>
                </div>
                <h3 class="text-2xl font-black font-display text-[#0f2044]">Knockout Stage Not Yet Unlocked</h3>
                <p class="text-slate-500 text-sm mt-1">Stage 2 Single Elimination bracket tree unlocks once preliminary qualifying rounds conclude.</p>
            </div>
        <?php else: ?>
            <!-- Mobile Swipe Hint -->
            <div class="md:hidden flex items-center justify-center gap-1.5 text-[11px] font-bold text-slate-500 bg-white border border-slate-200 py-2 px-4 rounded-2xl shadow-xs mx-auto mb-3 w-fit">
                <i class="ph-bold ph-arrows-left-right text-xs text-[#c9a84c]"></i>
                <span>Swipe left/right to browse tournament bracket</span>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-4 sm:p-6 overflow-x-auto custom-scrollbar">
                <div class="flex items-stretch gap-6 sm:gap-8 min-w-[950px] p-3 sm:p-4 bg-slate-50/60 rounded-2xl border border-slate-200/80">
                    
                    <!-- R16 -->
                    <?php if (!empty($bracket['r16'])): ?>
                    <div class="flex flex-col justify-around gap-6 w-64">
                        <h4 class="text-center font-black text-slate-400 text-[10px] tracking-widest uppercase mb-1">Round of 16</h4>
                        <?php foreach ($bracket['r16'] as $m): ?>
                            <div class="bg-white border border-slate-200 rounded-2xl p-3.5 shadow-sm hover:border-[#0f2044] transition-all">
                                <div class="text-[9px] text-slate-400 font-bold uppercase mb-1.5">M#<?= $m['match_number'] ?></div>
                                <div class="flex justify-between items-center mb-1.5 border-b border-slate-50 pb-1.5">
                                    <span class="text-xs font-black text-slate-800 truncate"><?= e($m['display_a']) ?></span>
                                    <span class="text-[#0f2044] font-black text-sm"><?= $m['games_a'] ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-xs font-bold text-slate-500 truncate"><?= e($m['display_b']) ?></span>
                                    <span class="text-slate-400 font-black text-sm"><?= $m['games_b'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- QF -->
                    <?php if (!empty($bracket['qf'])): ?>
                    <div class="flex flex-col justify-around gap-8 w-64">
                        <h4 class="text-center font-black text-slate-400 text-[10px] tracking-widest uppercase mb-1">Quarter Finals</h4>
                        <?php foreach ($bracket['qf'] as $m): ?>
                            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:border-[#0f2044] transition-all">
                                <div class="text-[9px] text-slate-400 font-bold uppercase mb-1.5">QF #<?= $m['match_number'] ?></div>
                                <div class="flex justify-between items-center mb-1.5 border-b border-slate-50 pb-1.5">
                                    <span class="text-xs font-black text-slate-800 truncate"><?= e($m['display_a']) ?></span>
                                    <span class="text-[#0f2044] font-black text-sm"><?= $m['games_a'] ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-xs font-bold text-slate-500 truncate"><?= e($m['display_b']) ?></span>
                                    <span class="text-slate-400 font-black text-sm"><?= $m['games_b'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- SF -->
                    <?php if (!empty($bracket['sf'])): ?>
                    <div class="flex flex-col justify-around gap-16 w-64">
                        <h4 class="text-center font-black text-slate-400 text-[10px] tracking-widest uppercase mb-1">Semi Finals</h4>
                        <?php foreach ($bracket['sf'] as $m): ?>
                            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:border-[#0f2044] transition-all">
                                <div class="text-[9px] text-slate-400 font-bold uppercase mb-1.5">SF #<?= $m['match_number'] ?></div>
                                <div class="flex justify-between items-center mb-1.5 border-b border-slate-50 pb-1.5">
                                    <span class="text-xs font-black text-slate-800 truncate"><?= e($m['display_a']) ?></span>
                                    <span class="text-[#0f2044] font-black text-sm"><?= $m['games_a'] ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-xs font-bold text-slate-600 truncate"><?= e($m['display_b']) ?></span>
                                    <span class="text-slate-400 font-black text-sm"><?= $m['games_b'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Final & 3rd Place -->
                    <?php if (!empty($bracket['final']) || !empty($bracket['3rd'])): ?>
                    <div class="flex flex-col justify-center gap-8 w-72 relative">
                        <?php if (!empty($bracket['final'])): ?>
                            <div>
                                <h4 class="text-center font-black text-[#c9a84c] text-xs tracking-widest uppercase mb-3 flex items-center justify-center gap-1.5">
                                    <i class="ph-fill ph-trophy text-amber-500 text-lg"></i> Grand Final
                                </h4>
                                <?php foreach ($bracket['final'] as $m): ?>
                                    <div class="bg-white border-2 border-[#c9a84c] rounded-3xl p-5 shadow-xl relative">
                                        <div class="flex justify-between items-center mb-2 border-b border-slate-100 pb-2">
                                            <span class="text-sm font-black text-slate-900 truncate"><?= e($m['display_a']) ?></span>
                                            <span class="text-[#c9a84c] font-black text-2xl"><?= $m['games_a'] ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm font-bold text-slate-600 truncate"><?= e($m['display_b']) ?></span>
                                            <span class="text-slate-400 font-black text-2xl"><?= $m['games_b'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($bracket['3rd'])): ?>
                            <div>
                                <h4 class="text-center font-black text-slate-500 text-[10px] tracking-widest uppercase mb-2 flex items-center justify-center gap-1">
                                    <i class="ph-fill ph-medal text-amber-700"></i> 3rd Place Match
                                </h4>
                                <?php foreach ($bracket['3rd'] as $m): ?>
                                    <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm relative">
                                        <div class="flex justify-between items-center mb-1.5 border-b border-slate-50 pb-1.5">
                                            <span class="text-xs font-bold text-slate-700 truncate"><?= e($m['display_a']) ?></span>
                                            <span class="text-[#0f2044] font-bold"><?= $m['games_a'] ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs font-bold text-slate-500 truncate"><?= e($m['display_b']) ?></span>
                                            <span class="text-slate-400 font-bold"><?= $m['games_b'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endif; ?>
    </div>

    </div>
</div>

<!-- Alpine Public View Manager with Live SSE -->
<script>
function tournamentPublicManager(defaultTab, tournamentId) {
    return {
        tab: defaultTab,
        
        init() {
            // 1. Connect to real-time Server-Sent Events (SSE) stream
            if (typeof EventSource !== 'undefined') {
                try {
                    const evtSource = new EventSource('<?= BASE_URL ?>/sse/live.php?tournament_id=' + tournamentId);
                    
                    evtSource.onmessage = (e) => {
                        try {
                            const data = JSON.parse(e.data);
                            const matches = data ? (data.matches || data.live_matches) : null;
                            if (matches) {
                                this.updateLiveScores(matches);
                            }
                        } catch (parseErr) {
                            console.error('SSE JSON Error:', parseErr);
                        }
                    };

                    evtSource.onerror = (err) => {
                        console.warn('SSE reconnecting...', err);
                    };
                } catch (err) {
                    console.log('SSE connection skipped:', err);
                }
            }

            // 2. High-speed HTTP fallback poller (every 2.5s) to guarantee zero lag under all network conditions
            setInterval(async () => {
                try {
                    const res = await fetch('<?= BASE_URL ?>/api/matches.php?tournament_id=' + tournamentId, { cache: 'no-store' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data && data.success && data.matches) {
                        this.updateLiveScores(data.matches);
                    }
                } catch(e) {}
            }, 2500);
        },

        updateLiveScores(liveMatches) {
            const liveGrid = document.getElementById('tournament-live-grid');
            const liveSection = document.getElementById('tournament-live-section');
            const noLivePlaceholder = document.getElementById('tournament-no-live-placeholder');

            const activeMatches = (liveMatches || []).filter(m => m.status === 'in_progress' || (m.is_completed && window._completedMatchCards && window._completedMatchCards[m.id]));

            if (activeMatches.length > 0) {
                if (liveSection) liveSection.classList.remove('hidden');
                if (noLivePlaceholder) noLivePlaceholder.classList.add('hidden');
            }

            liveMatches.forEach(m => {
                const matchId = m.id;
                let cardEl = document.getElementById('live-match-card-' + matchId);

                // If card does not exist in DOM yet (e.g. match started after page loaded), build and append it
                if (!cardEl && liveGrid && (m.status === 'in_progress' || m.status === 'live')) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = `
                        <div class="bg-white rounded-3xl p-6 sm:p-7 border-2 border-red-400 ring-4 ring-red-50 shadow-2xl relative overflow-hidden flex flex-col justify-between group bg-badminton-court transition-all duration-500"
                             id="live-match-card-${matchId}">
                            <div class="flex items-center justify-between mb-5 border-b border-slate-100 pb-3">
                                <div class="flex items-center gap-2">
                                    <span id="live-status-badge-${matchId}" class="px-3 py-1 rounded-full bg-red-500 text-white text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 shadow-sm animate-pulse transition-all duration-500">
                                        <span class="w-1.5 h-1.5 rounded-full bg-white"></span> LIVE COURT
                                    </span>
                                    <span class="text-xs font-bold text-slate-400">Match #${m.match_number || matchId}</span>
                                    <span id="deuce-badge-${matchId}" class="hidden px-2.5 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-black uppercase tracking-wider shadow-sm animate-bounce">
                                        🔥 DEUCE
                                    </span>
                                </div>
                                <div id="live-game-num-${matchId}" class="text-xs font-mono font-black text-red-600 bg-red-50 px-2.5 py-1 rounded-lg">
                                    Game ${(m.games_a || 0) + (m.games_b || 0) + 1}
                                </div>
                            </div>
                            <div class="space-y-3 my-2">
                                <div class="p-4 rounded-2xl bg-slate-50/90 border border-slate-200 flex items-center justify-between transition-colors shadow-xs">
                                    <div class="font-black text-base text-slate-900 truncate">${m.display_a || m.player_a || 'Player A'}</div>
                                    <div id="live-score-a-${matchId}" class="text-3xl font-black font-display text-slate-900 bg-white border border-slate-200 px-4 py-2 rounded-xl shadow-xs min-w-[52px] text-center transition-all duration-300 bg-racket-strings">${m.score_a || 0}</div>
                                </div>
                                <div class="badminton-net-divider-h rounded-full my-1 opacity-75"></div>
                                <div class="p-4 rounded-2xl bg-slate-50/90 border border-slate-200 flex items-center justify-between transition-colors shadow-xs">
                                    <div class="font-black text-base text-slate-900 truncate">${m.display_b || m.player_b || 'Player B'}</div>
                                    <div id="live-score-b-${matchId}" class="text-3xl font-black font-display text-slate-900 bg-white border border-slate-200 px-4 py-2 rounded-xl shadow-xs min-w-[52px] text-center transition-all duration-300 bg-racket-strings">${m.score_b || 0}</div>
                                </div>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-bold text-slate-500">
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] uppercase tracking-wider text-slate-400">Games Won:</span>
                                    <span class="font-black text-slate-800">
                                        <span id="live-games-a-${matchId}">${m.games_a || 0}</span> - <span id="live-games-b-${matchId}">${m.games_b || 0}</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                    cardEl = tempDiv.firstElementChild;
                    liveGrid.appendChild(cardEl);
                }

                const scoreAEl = document.getElementById('live-score-a-' + matchId);
                const scoreBEl = document.getElementById('live-score-b-' + matchId);
                const gameAEl = document.getElementById('live-games-a-' + matchId);
                const gameBEl = document.getElementById('live-games-b-' + matchId);
                const serverAEl = document.getElementById('server-indicator-a-' + matchId);
                const serverBEl = document.getElementById('server-indicator-b-' + matchId);
                const gameNumEl = document.getElementById('live-game-num-' + matchId);
                const momBarAEl = document.getElementById('momentum-bar-a-' + matchId);
                const momBarBEl = document.getElementById('momentum-bar-b-' + matchId);
                const momValAEl = document.getElementById('momentum-val-a-' + matchId);
                const momValBEl = document.getElementById('momentum-val-b-' + matchId);

                const scoreA = (m.score_a !== undefined) ? m.score_a : (m.game_score_a ?? 0);
                const scoreB = (m.score_b !== undefined) ? m.score_b : (m.game_score_b ?? 0);
                const gamesA = (m.games_a !== undefined) ? m.games_a : (m.player_a_games_won ?? 0);
                const gamesB = (m.games_b !== undefined) ? m.games_b : (m.player_b_games_won ?? 0);

                if (scoreAEl) scoreAEl.innerText = scoreA;
                if (scoreBEl) scoreBEl.innerText = scoreB;
                if (gameAEl) gameAEl.innerText = gamesA;
                if (gameBEl) gameBEl.innerText = gamesB;
                if (gameNumEl) gameNumEl.innerText = 'Game ' + (m.current_game_number || m.current_game || 1);

                const deuceEl = document.getElementById('deuce-badge-' + matchId);
                if (deuceEl) {
                    const isDeuce = m.deuce_enabled && parseInt(scoreA) >= parseInt(m.deuce_trigger) && parseInt(scoreB) >= parseInt(m.deuce_trigger);
                    if (isDeuce) {
                        deuceEl.classList.remove('hidden');
                    } else {
                        deuceEl.classList.add('hidden');
                    }
                }

                if (serverAEl) {
                    if (m.current_server === 'player_a') {
                        serverAEl.classList.remove('hidden');
                    } else {
                        serverAEl.classList.add('hidden');
                    }
                }

                if (serverBEl) {
                    if (m.current_server === 'player_b') {
                        serverBEl.classList.remove('hidden');
                    } else {
                        serverBEl.classList.add('hidden');
                    }
                }

                if (momValAEl && momValBEl) {
                    momValAEl.innerText = m.momentum_a + '%';
                    momValBEl.innerText = m.momentum_b + '%';
                    if (momBarAEl) momBarAEl.style.width = m.momentum_a + '%';
                    if (momBarBEl) momBarBEl.style.width = m.momentum_b + '%';
                }

                if (m.is_completed) {
                    if (gameNumEl) gameNumEl.innerText = 'Final';
                    if (deuceEl) deuceEl.classList.add('hidden');
                    if (serverAEl) serverAEl.classList.add('hidden');
                    if (serverBEl) serverBEl.classList.add('hidden');
                    
                    const winnerName = m.winner_name || (m.score_a > m.score_b ? (m.display_a || m.player_a) : (m.display_b || m.player_b));
                    const badgeEl = document.getElementById('live-status-badge-' + matchId);
                    if (badgeEl) {
                        badgeEl.innerHTML = `<i class="ph-fill ph-trophy text-amber-300 text-xs"></i> <span>WINNER: ${winnerName || 'FINAL'}</span>`;
                        badgeEl.className = 'px-3 py-1 rounded-full bg-gradient-to-r from-emerald-600 to-teal-600 text-white text-[10px] font-black uppercase tracking-wider flex items-center gap-1.5 shadow-md animate-bounce';
                    }

                    if (cardEl) {
                        cardEl.classList.remove('border-red-400', 'ring-red-50');
                        cardEl.classList.add('border-emerald-500', 'ring-emerald-100');
                    }

                    // Linger for 15 seconds before disappearing
                    if (!window._completedMatchCards) window._completedMatchCards = {};
                    if (!window._completedMatchCards[matchId]) {
                        window._completedMatchCards[matchId] = setTimeout(() => {
                            if (cardEl) {
                                cardEl.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
                                cardEl.style.opacity = '0';
                                cardEl.style.transform = 'scale(0.95)';
                                setTimeout(() => {
                                    cardEl.remove();
                                    if (liveGrid && liveGrid.children.length === 0) {
                                        if (liveSection) liveSection.classList.add('hidden');
                                        if (noLivePlaceholder) noLivePlaceholder.classList.remove('hidden');
                                    }
                                }, 800);
                            }
                        }, 15000);
                    }
                }
            });
        }
    };
}
</script>

<style>
@keyframes marquee {
    0% { transform: translateX(0%); }
    100% { transform: translateX(-50%); }
}
.animate-marquee {
    display: inline-flex;
    animation: marquee 25s linear infinite;
}
.animate-marquee:hover {
    animation-play-state: paused;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
