<?php
// ============================================================
// TKMI Badminton Tournament Platform — 404 Not Found Page
// ============================================================
http_response_code(404);
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = '404 - Page Not Found | ' . APP_NAME;
include __DIR__ . '/includes/header.php';
?>

<div class="flex-1 flex items-center justify-center py-20 px-4 sm:px-6 lg:px-8 relative z-10">
    <div class="max-w-xl w-full text-center">
        <!-- 3D Glass Badminton Graphic -->
        <div class="relative w-36 h-36 mx-auto mb-8 flex items-center justify-center">
            <div class="absolute inset-0 bg-[#c9a84c]/20 rounded-full blur-2xl animate-pulse"></div>
            <div class="relative w-28 h-28 rounded-3xl bg-gradient-to-br from-[#0f2044] via-[#162d5d] to-[#0a1630] border border-[#c9a84c]/30 shadow-2xl flex items-center justify-center text-5xl text-[#c9a84c]">
                🏸
            </div>
            <!-- Shuttlecock Icon Badge -->
            <div class="absolute -bottom-2 -right-2 px-3 py-1 rounded-full bg-red-600 text-white font-black text-xs uppercase tracking-widest shadow-lg border border-white/20 animate-bounce">
                OUT!
            </div>
        </div>

        <span class="px-4 py-1.5 rounded-full bg-white/10 text-[#c9a84c] border border-[#c9a84c]/30 text-xs font-black uppercase tracking-widest inline-block mb-4">
            Error 404 — Shuttle Out of Court
        </span>

        <h1 class="text-4xl sm:text-6xl font-black font-display text-white tracking-tight mb-4">
            Page Not Found
        </h1>

        <p class="text-slate-300 text-base sm:text-lg font-medium leading-relaxed max-w-md mx-auto mb-8">
            The match fixture or page you are looking for has been moved, completed, or does not exist on the court.
        </p>

        <!-- Navigation Buttons -->
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
            <a href="<?= BASE_URL ?>/" 
               class="w-full sm:w-auto px-8 py-3.5 rounded-2xl bg-gradient-to-r from-[#c9a84c] to-[#e8c870] hover:from-[#d8b75b] hover:to-[#f0d07e] text-[#080e1e] font-black text-sm uppercase tracking-wider shadow-xl shadow-[#c9a84c]/20 transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
                <i class="ph-bold ph-house text-lg"></i>
                <span>Back to Home</span>
            </a>

            <a href="<?= BASE_URL ?>/public/tournaments.php" 
               class="w-full sm:w-auto px-8 py-3.5 rounded-2xl bg-white/10 hover:bg-white/15 text-white border border-white/20 font-bold text-sm uppercase tracking-wider transition-all flex items-center justify-center gap-2 backdrop-blur-md">
                <i class="ph-bold ph-medal text-lg text-[#c9a84c]"></i>
                <span>Browse Tournaments</span>
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
