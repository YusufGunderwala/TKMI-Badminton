</main>

<?php 
$footerSponsors = function_exists('getActiveSponsors') ? getActiveSponsors() : [];
?>

<!-- ======================================================= -->
<!-- CLEAN MODERN LIGHTWEIGHT PLATFORM FOOTER                -->
<!-- ======================================================= -->
<footer class="bg-white border-t border-slate-200/80 text-slate-600 mt-auto pt-10 pb-16 relative z-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <?php if (!empty($footerSponsors)): ?>
        <!-- Global Official Sponsors Showcase -->
        <div class="mb-10 pb-8 border-b border-slate-100">
            <div class="flex items-center justify-between gap-4 mb-5">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-[#c9a84c]"></span>
                    <span class="text-[11px] font-black uppercase tracking-widest text-slate-400">Official Tournament Sponsors</span>
                </div>
                <span class="text-[10px] font-bold text-[#c9a84c] uppercase tracking-wider bg-amber-50 border border-amber-200/60 px-3 py-0.5 rounded-full">Community Partners</span>
            </div>
            
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                <?php foreach ($footerSponsors as $fs): 
                    $fsImg = str_starts_with($fs['image_path'], 'uploads/') ? BASE_URL . '/' . e($fs['image_path']) : BASE_URL . '/uploads/sponsors/' . e($fs['image_path']);
                ?>
                    <div class="bg-slate-50 hover:bg-white border border-slate-200/80 hover:border-[#c9a84c]/60 rounded-xl p-3.5 flex flex-col items-center justify-center gap-2 transition-all hover:shadow-md group">
                        <div class="h-10 w-full flex items-center justify-center">
                            <img src="<?= $fsImg ?>" alt="<?= e($fs['name']) ?>" class="max-h-full max-w-[90px] object-contain group-hover:scale-105 transition-transform">
                        </div>
                        <span class="text-[11px] font-bold text-slate-700 truncate max-w-full group-hover:text-[#0f2044]"><?= e($fs['name']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Top Section: Brand + Links + Admin -->
        <div class="flex flex-col md:flex-row items-center md:items-start justify-between gap-8 pb-8 border-b border-slate-100 text-center md:text-left">
            
            <!-- Left: Brand Identity -->
            <div class="space-y-2 max-w-sm">
                <div class="flex items-center justify-center md:justify-start gap-3">
                    <div class="w-11 h-11 rounded-xl overflow-hidden shadow-sm border border-[#c9a84c]/30 flex-shrink-0 bg-[#004d26]">
                        <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-cover">
                    </div>
                    <div>
                        <div class="text-[#0f2044] font-display font-black text-xl tracking-tight leading-none">TKMI BADMINTON</div>
                        <div class="text-[#997d2e] text-[9px] font-black uppercase tracking-widest mt-1">Toloba ul Kulliyaat il Muminoon &bull; Founded 1965</div>
                    </div>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed pt-1">
                    Official championship tournament management platform for TKMI. Built with real-time live court scoring, automated Swiss qualifiers, and single-elimination brackets.
                </p>
            </div>

            <!-- Center: Navigation Links -->
            <div class="flex flex-wrap items-center justify-center gap-6 text-xs font-bold text-slate-600">
                <a href="<?= BASE_URL ?>/" class="hover:text-[#0f2044] hover:underline transition-all flex items-center gap-1.5">
                    <i class="ph-bold ph-house text-sm text-[#c9a84c]"></i> Home Portal
                </a>
                <a href="<?= BASE_URL ?>/public/tournaments.php" class="hover:text-[#0f2044] hover:underline transition-all flex items-center gap-1.5">
                    <i class="ph-bold ph-medal text-sm text-[#c9a84c]"></i> Tournaments
                </a>
                <a href="<?= BASE_URL ?>/#rules-section" class="hover:text-[#0f2044] hover:underline transition-all flex items-center gap-1.5">
                    <i class="ph-bold ph-book-open-text text-sm text-[#c9a84c]"></i> Format & Rules
                </a>
            </div>

            <!-- Right: Admin Access -->
            <div>
                <a href="<?= BASE_URL ?>/admin/login.php" class="inline-flex items-center gap-2 bg-[#0f2044] hover:bg-blue-900 text-white font-bold text-xs uppercase tracking-wider px-5 py-2.5 rounded-xl shadow-sm hover:scale-105 transition-all">
                    <i class="ph-bold ph-lock-key text-[#c9a84c]"></i>
                    <span>Admin Portal</span>
                </a>
            </div>

        </div>

        <!-- Bottom Copyright Row -->
        <div class="pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500 font-medium">
            <div>
                &copy; <?= date('Y') ?> <strong class="text-slate-800 font-bold">Toloba ul Kulliyaat il Muminoon (TKMI)</strong>. All Rights Reserved.
            </div>
            <div class="text-[11px] font-mono text-slate-500 flex items-center gap-2">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Sports Edition &bull; Fast & Live</span>
            </div>
        </div>

    </div>
</footer>

<!-- Global JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>

</body>
</html>
