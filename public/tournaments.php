<?php
// ============================================================
// Public Tournaments List Page
// Next-Gen UI: Spotlight Cards, Retro Badges & Smooth Hover
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'All Tournaments';
$ogTitle = 'TKMI Badminton | Tournaments Directory';
$ogDesc = 'Browse all active, upcoming, and past TKMI Badminton tournaments.';

// Fetch all non-draft tournaments
$tournaments = array_filter(getAllTournaments(), fn($t) => $t['status'] !== 'draft');

include __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 flex-1 w-full">
    
    <div class="mb-12">
        <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-blue-50 border border-blue-200 text-blue-700 font-bold text-xs uppercase tracking-widest mb-3">
            <i class="ph-bold ph-trophy"></i> Official Events Directory
        </div>
        <h1 class="text-4xl md:text-5xl font-black font-display text-[#0f2044] tracking-tight">Tournaments Explorer</h1>
        <p class="text-slate-500 mt-2 text-base max-w-2xl font-medium">Follow championship brackets, player progress, and match results across all TKMI divisions.</p>
    </div>

    <?php if (empty($tournaments)): ?>
        <!-- Clean Empty State -->
        <div class="bg-white rounded-3xl p-16 text-center max-w-2xl mx-auto border border-dashed border-slate-300 shadow-sm">
            <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-300">
                <i class="ph-fill ph-trophy text-4xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-[#0f2044] font-display mb-2">No Tournaments Published Yet</h2>
            <p class="text-slate-500 mb-8 text-sm">Tournaments will appear here as soon as they are launched by the tournament directors.</p>
            <a href="<?= BASE_URL ?>/" class="inline-flex items-center gap-2 bg-[#0f2044] hover:bg-blue-900 text-white font-bold px-6 py-3 rounded-xl transition shadow-md">
                <i class="ph-bold ph-house"></i> Return to Home
            </a>
        </div>
    <?php else: ?>
        <!-- Tournaments Grid with Unlumen Spotlight Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($tournaments as $t): 
                $mCount = (int)db()->query("SELECT COUNT(*) FROM matches WHERE tournament_id = {$t['id']}")->fetchColumn();
                $pCount = (int)db()->query("SELECT COUNT(*) FROM tournament_players WHERE tournament_id = {$t['id']}")->fetchColumn();
                $isLive = $t['status'] === 'live';
            ?>
                <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $t['id'] ?>" class="spotlight-card bg-white rounded-2xl p-7 border <?= $isLive ? 'border-red-300 shadow-md ring-1 ring-red-100' : 'border-slate-200 shadow-sm' ?> hover-lift flex flex-col justify-between group transition-all">
                    <div>
                        <div class="flex justify-between items-start mb-4">
                            <?php if ($isLive): ?>
                                <span class="px-3 py-1 bg-red-500 text-white text-[10px] font-black rounded-full uppercase tracking-widest flex items-center gap-1.5 shadow-sm animate-pulse">
                                    <span>●</span> LIVE NOW
                                </span>
                            <?php elseif ($t['status'] === 'completed'): ?>
                                <span class="px-3 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded-full uppercase tracking-widest border border-emerald-200">
                                    Finished
                                </span>
                            <?php else: ?>
                                <span class="px-3 py-1 bg-slate-100 text-slate-600 text-[10px] font-bold rounded-full uppercase tracking-widest border border-slate-200">
                                    <?= ucfirst($t['status']) ?>
                                </span>
                            <?php endif; ?>

                            <span class="text-xs font-bold text-slate-400 bg-slate-50 px-2.5 py-1 rounded-lg border border-slate-100">
                                <?= $t['format'] === 'swiss_knockout' ? 'Swiss + KO' : 'Round Robin' ?>
                            </span>
                        </div>

                        <h3 class="text-2xl font-black font-display text-[#0f2044] group-hover:text-blue-600 transition-colors leading-tight mb-2">
                            <?= e($t['name']) ?>
                        </h3>
                        
                        <p class="text-sm text-slate-500 mb-6 font-medium line-clamp-2">
                            <?= e($t['description'] ?: 'Official tournament division organized by Toloba ul Kulliyaat il Muminoon.') ?>
                        </p>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-4 flex items-center justify-between text-xs font-bold text-slate-500">
                        <div class="flex items-center gap-3">
                            <span class="flex items-center gap-1 text-slate-600"><i class="ph-bold ph-users text-[#c9a84c]"></i> <?= $pCount ?: '24+' ?> Players</span>
                            <span class="flex items-center gap-1 text-slate-600"><i class="ph-bold ph-brackets-curly text-blue-500"></i> <?= $mCount ?> Matches</span>
                        </div>
                        <span class="text-[#0f2044] group-hover:translate-x-1 transition-transform flex items-center gap-1 font-black">
                            View <i class="ph-bold ph-arrow-right"></i>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
