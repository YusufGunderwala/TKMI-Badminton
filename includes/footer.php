</main>

<?php 
$footerSponsors = function_exists('getActiveSponsors') ? getActiveSponsors() : [];
?>

<!-- ============================================================ -->
<!-- TKMI GLOBAL RESPONSIVE PUBLIC FOOTER WITH PLATFORM SPONSORS  -->
<!-- ============================================================ -->
<footer class="bg-[#0b1730] text-white border-t border-white/10 mt-auto relative z-20">
    


    <!-- Global Footer Sponsor Marquee -->
    <?php if (!empty($footerSponsors)): ?>
    <div class="border-b border-white/5 py-4 bg-[#081126] relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-blue-900/10 via-[#c9a84c]/5 to-blue-900/10 opacity-60"></div>
        <div class="w-full mx-auto flex flex-col sm:flex-row items-center gap-4 sm:gap-6 px-4">
            <div class="flex items-center gap-2 text-[#c9a84c] text-[10px] sm:text-xs font-black uppercase tracking-widest flex-shrink-0 bg-[#c9a84c]/10 px-4 py-2 rounded-full border border-[#c9a84c]/20 shadow-inner z-10">
                <i class="ph-fill ph-handshake text-sm"></i>
                <span>Supported By</span>
            </div>
            <div class="flex-1 overflow-hidden relative group w-full" style="-webkit-mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent); mask-image: linear-gradient(to right, transparent, black 10%, black 90%, transparent);">
                <div class="flex w-max items-center gap-4 animate-marquee hover:[animation-play-state:paused] py-1">
                    <?php 
                    $fMarquee = array_merge($footerSponsors, $footerSponsors, $footerSponsors, $footerSponsors, $footerSponsors, $footerSponsors); 
                    foreach ($fMarquee as $s): 
                        $imgUrl = str_starts_with($s['image_path'], 'uploads/') ? BASE_URL . '/' . e($s['image_path']) : BASE_URL . '/uploads/sponsors/' . e($s['image_path']);
                    ?>
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

    <!-- Main Footer Body -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <div class="flex flex-col md:flex-row items-center justify-between gap-6">
            
            <!-- Brand & Community Info -->
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl overflow-hidden shadow-md border border-white/20 bg-[#004d26] flex-shrink-0">
                    <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-cover">
                </div>
                <div>
                    <h3 class="text-lg font-black font-display tracking-tight text-white">TKMI <span class="text-[#c9a84c]">Badminton</span></h3>
                    <div class="text-[9px] uppercase tracking-widest text-slate-400 font-bold">Toloba ul Kulliyaat il Muminoon</div>
                </div>
            </div>

            <!-- Quick Navigation -->
            <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-xs text-slate-300 font-medium">
                <a href="<?= BASE_URL ?>/" class="hover:text-white transition">Home</a>
                <a href="<?= BASE_URL ?>/public/tournaments.php" class="hover:text-white transition">Tournaments</a>
                <a href="<?= BASE_URL ?>/#rules-section" class="hover:text-white transition">Tournament Rules</a>
                <?php if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()): ?>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="hover:text-[#c9a84c] transition font-bold">Admin Dashboard</a>
                <?php endif; ?>
            </div>

        </div>

        <!-- Copyright Line -->
        <div class="mt-8 pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-slate-400 text-center sm:text-left">
            <div>
                &copy; <?= date('Y') ?> <strong>Toloba ul Kulliyaat il Muminoon</strong>. All Rights Reserved.
            </div>
        </div>
    </div>
</footer>

<!-- Global JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>

</body>
</html>
