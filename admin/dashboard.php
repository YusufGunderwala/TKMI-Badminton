<?php
$pageTitle = 'Dashboard Overview';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../includes/functions.php';

$stats = getDashboardStats();
$liveMatches = getLiveMatches();
$upcomingMatches = getUpcomingMatches(null, 5);
$recentTournaments = array_slice(getAllTournaments(), 0, 5);
?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
    <div class="mb-6 bg-amber-50 border-2 border-amber-300/80 text-amber-900 px-5 py-4 rounded-2xl flex items-center gap-3.5 shadow-sm">
        <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0 text-amber-700 font-bold">
            <i class="ph-fill ph-lock-key text-xl"></i>
        </div>
        <div>
            <div class="font-bold text-sm">Restricted Access Area</div>
            <div class="text-xs text-amber-700">Super Admin privileges are required to manage admin accounts and security settings.</div>
        </div>
    </div>
<?php endif; ?>

<!-- Welcome Hero Banner -->
<div class="bg-[#0f2044] rounded-3xl p-5 sm:p-8 lg:p-10 text-white shadow-xl mb-6 sm:mb-10 relative overflow-hidden flex flex-col md:flex-row items-center justify-between gap-6 sm:gap-8 border border-white/10">
    <!-- Retro Grid Ambient Effect -->
    <div class="retro-grid retro-grid-dark opacity-15">
        <div class="retro-grid-plane"></div>
    </div>
    
    <div class="relative z-10">
        <h1 class="text-3xl md:text-4xl font-black font-display tracking-tight mb-2 text-white">Welcome, <?= e($adminUser['display_name']) ?></h1>
        <p class="text-blue-200/80 font-normal text-sm max-w-2xl">Manage tournaments, monitor live scores, and configure player pairings.</p>
    </div>
    
    <div class="relative z-10 flex-shrink-0 w-full md:w-auto">
        <a href="<?= BASE_URL ?>/admin/tournaments/create.php" class="gold-shimmer-btn w-full md:w-auto px-8 py-4 rounded-2xl font-black transition-all flex items-center justify-center gap-3 text-base shadow-xl hover-lift">
            <i class="ph-bold ph-plus text-xl"></i> New Tournament
        </a>
    </div>
</div>

<!-- Key Metrics Grid with Unlumen Spotlight Cards & Number Tickers -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
    <?php
    $statCards = [
        ['title' => 'Tournaments', 'val' => $stats['total_tournaments'], 'icon' => 'ph-medal', 'color' => 'blue'],
        ['title' => 'Live Matches', 'val' => $stats['live_matches'], 'icon' => 'ph-broadcast', 'color' => 'red'],
        ['title' => 'Registered Players', 'val' => $stats['total_players'], 'icon' => 'ph-users', 'color' => 'emerald'],
        ['title' => 'Admin Accounts', 'val' => $stats['total_admins'], 'icon' => 'ph-shield-check', 'color' => 'amber']
    ];
    
    foreach ($statCards as $card): 
        $color = $card['color'];
        $bgMap = [
            'blue' => 'bg-blue-50 text-blue-600', 
            'red' => 'bg-red-50 text-red-600', 
            'emerald' => 'bg-emerald-50 text-emerald-600', 
            'amber' => 'bg-amber-50 text-amber-600'
        ];
    ?>
    <div class="spotlight-card bg-white rounded-2xl border border-slate-200 p-4 sm:p-6 shadow-sm flex items-center gap-3.5 sm:gap-5 hover-lift transition-all">
        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-xl sm:rounded-2xl flex items-center justify-center <?= $bgMap[$color] ?> transition-transform group-hover:scale-110 shadow-inner flex-shrink-0">
            <i class="ph-fill <?= $card['icon'] ?> text-2xl sm:text-3xl"></i>
        </div>
        <div>
            <div class="text-[10px] sm:text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-1"><?= $card['title'] ?></div>
            <div class="text-2xl sm:text-4xl font-black font-display text-[#0f2044] leading-none tracking-tighter" data-ticker="<?= $card['val'] ?>"><?= $card['val'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Live & Upcoming Sections -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
    
    <!-- Live Matches Panel -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-[0_4px_20px_rgba(0,0,0,0.03)] flex flex-col h-[420px] overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="text-lg font-black font-display text-[#0f2044] flex items-center gap-3">
                <div class="relative flex items-center justify-center w-3 h-3">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75 animate-ping"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
                </div>
                Live Scoring
            </h3>
            <a href="<?= BASE_URL ?>/admin/scoring/index.php" class="text-sm font-bold text-blue-600 hover:text-blue-800 flex items-center gap-1 bg-blue-50 px-3 py-1.5 rounded-lg transition-colors">
                Open Scorer <i class="ph-bold ph-arrow-right"></i>
            </a>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 custom-scrollbar">
            <?php if (empty($liveMatches)): ?>
                <div class="h-full flex flex-col items-center justify-center text-center">
                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-4 text-slate-300">
                        <i class="ph-fill ph-broadcast text-4xl"></i>
                    </div>
                    <h4 class="text-lg font-bold text-slate-700 font-display mb-1">No Active Courts</h4>
                    <p class="text-slate-500 text-sm">Matches will appear here once scoring begins.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach($liveMatches as $lm): 
                        $isDoubles = !empty($lm['team_a_id']);
                        $pa = $isDoubles ? $lm['ta_name'] : $lm['pa_name'];
                        $pb = $isDoubles ? $lm['tb_name'] : $lm['pb_name'];
                    ?>
                        <div class="border border-slate-200 rounded-xl p-4 flex items-center justify-between bg-white hover:border-blue-300 transition-colors">
                            <div class="flex-1">
                                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2"><?= e($lm['tournament_name']) ?></div>
                                <div class="flex items-center gap-4">
                                    <div class="font-bold text-slate-800 w-1/3 truncate text-right"><?= e($pa) ?></div>
                                    <div class="bg-[#0f2044] text-white px-4 py-1.5 rounded-lg font-black font-display text-lg tracking-wider shadow-inner">
                                        <?= $lm['score_a'] ?> - <?= $lm['score_b'] ?>
                                    </div>
                                    <div class="font-bold text-slate-800 w-1/3 truncate"><?= e($pb) ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming Matches Panel -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-[0_4px_20px_rgba(0,0,0,0.03)] flex flex-col h-[420px] overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="text-lg font-black font-display text-[#0f2044] flex items-center gap-3">
                <i class="ph-fill ph-calendar-check text-blue-500 text-xl"></i> Upcoming Schedule
            </h3>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 custom-scrollbar">
            <?php if (empty($upcomingMatches)): ?>
                <div class="h-full flex flex-col items-center justify-center text-center">
                    <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mb-4 text-slate-300">
                        <i class="ph-fill ph-calendar-blank text-4xl"></i>
                    </div>
                    <h4 class="text-lg font-bold text-slate-700 font-display mb-1">Schedule Clear</h4>
                    <p class="text-slate-500 text-sm">No upcoming matches are currently queued.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach($upcomingMatches as $um): 
                        $isDoubles = !empty($um['team_a_id']);
                        $pa = $isDoubles ? $um['ta_name'] : $um['pa_name'];
                        $pb = $isDoubles ? $um['tb_name'] : $um['pb_name'];
                    ?>
                        <div class="border border-slate-100 rounded-xl p-4 flex flex-col bg-slate-50">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest"><?= e($um['tournament_name']) ?></span>
                                <span class="bg-blue-100 text-blue-700 text-[10px] font-bold px-2 py-0.5 rounded uppercase"><?= getRoundLabel($um['round_key']) ?></span>
                            </div>
                            <div class="flex items-center justify-between gap-4 font-bold text-slate-700 text-sm">
                                <span class="truncate flex-1 <?= empty($pa) ? 'text-slate-400 italic' : '' ?>"><?= e($pa ?: 'TBD') ?></span>
                                <span class="text-xs text-slate-400">VS</span>
                                <span class="truncate flex-1 text-right <?= empty($pb) ? 'text-slate-400 italic' : '' ?>"><?= e($pb ?: 'TBD') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Recent Tournaments Table -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-[0_4px_20px_rgba(0,0,0,0.03)] overflow-hidden">
    <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
        <h3 class="text-lg font-black font-display text-[#0f2044] flex items-center gap-3">
            <i class="ph-fill ph-trophy text-[#c9a84c] text-xl"></i> Recent Tournaments
        </h3>
        <a href="<?= BASE_URL ?>/admin/tournaments/index.php" class="text-sm font-bold text-slate-500 hover:text-[#0f2044] transition-colors">View All Directory &rarr;</a>
    </div>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-white border-b border-slate-200 text-[10px] uppercase tracking-widest text-slate-400 font-bold">
                    <th class="p-4 pl-6">Tournament Name</th>
                    <th class="p-4">Division</th>
                    <th class="p-4">Format</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 pr-6 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($recentTournaments)): ?>
                    <tr>
                        <td colspan="5" class="p-8 text-center text-slate-500">
                            No tournaments have been created yet. <a href="<?= BASE_URL ?>/admin/tournaments/create.php" class="text-blue-600 font-bold">Create your first one</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($recentTournaments as $t): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="p-4 pl-6 font-bold text-slate-800"><?= e($t['name']) ?></td>
                            <td class="p-4 text-sm text-slate-600">
                                <div class="flex items-center gap-1.5"><i class="ph-bold ph-users"></i> <?= e($t['gender']) ?> <?= ucfirst(e($t['match_type'])) ?></div>
                            </td>
                            <td class="p-4 text-sm text-slate-600">
                                <?= $t['format'] === 'swiss_knockout' ? 'Swiss + Knockout' : 'Round Robin' ?>
                            </td>
                            <td class="p-4">
                                <span class="px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider
                                    <?= $t['status'] === 'live' ? 'bg-red-50 text-red-600 border border-red-100' : '' ?>
                                    <?= $t['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : '' ?>
                                    <?= in_array($t['status'], ['draft','ready']) ? 'bg-blue-50 text-blue-600 border border-blue-100' : '' ?>
                                ">
                                    <?= ucfirst($t['status']) ?>
                                </span>
                            </td>
                            <td class="p-4 pr-6 text-right">
                                <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $t['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-500 hover:bg-[#0f2044] hover:text-white transition-colors">
                                    <i class="ph-bold ph-caret-right"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
