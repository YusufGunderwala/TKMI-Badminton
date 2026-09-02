</main>

<?php 
$footerSponsors = function_exists('getActiveSponsors') ? getActiveSponsors() : [];
?>

<!-- ============================================================ -->
<!-- TKMI GLOBAL RESPONSIVE PUBLIC FOOTER WITH PLATFORM SPONSORS  -->
<!-- ============================================================ -->
<footer class="bg-[#0b1730] text-white border-t border-white/10 mt-auto relative z-20">
    
    <!-- Sponsor Showcase (Flat list, equal size - GEMINI.md Rule) -->
    <?php if (!empty($footerSponsors)): ?>
        <div class="border-b border-white/10 bg-black/25 py-6 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-4">
                    <div class="flex items-center gap-2 text-center sm:text-left">
                        <i class="ph-fill ph-handshake text-[#c9a84c] text-lg"></i>
                        <span class="text-xs uppercase tracking-[0.2em] font-black text-[#c9a84c]">Official Tournament Sponsors</span>
                    </div>
                    <span class="text-[11px] text-slate-400 font-medium">Proudly supporting community youth athletics</span>
                </div>
                
                <!-- Flat Logo Grid / Flex (All equal size) -->
                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-4 sm:gap-6">
                    <?php foreach ($footerSponsors as $sp): 
                        $spImg = str_starts_with($sp['image_path'], 'uploads/') 
                            ? BASE_URL . '/' . e($sp['image_path']) 
                            : BASE_URL . '/uploads/sponsors/' . e($sp['image_path']);
                    ?>
                        <div class="bg-white/5 hover:bg-white/10 border border-white/10 rounded-2xl p-3 sm:p-4 flex items-center justify-center transition-all duration-300 hover:scale-105 shadow-sm group" title="<?= e($sp['name']) ?>">
                            <img src="<?= $spImg ?>" 
                                 alt="<?= e($sp['name']) ?>" 
                                 class="h-8 sm:h-10 max-w-[120px] sm:max-w-[140px] object-contain filter grayscale group-hover:grayscale-0 opacity-80 group-hover:opacity-100 transition-all duration-300">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Footer Body -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-12">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
            
            <!-- Brand & Community Info -->
            <div class="text-center md:text-left space-y-3">
                <div class="flex items-center justify-center md:justify-start gap-3">
                    <div class="w-10 h-10 rounded-xl overflow-hidden shadow-md border border-white/20 bg-[#004d26] flex-shrink-0">
                        <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-cover">
                    </div>
                    <div>
                        <h3 class="text-lg font-black font-display tracking-tight text-white">TKMI <span class="text-[#c9a84c]">Badminton</span></h3>
                        <div class="text-[9px] uppercase tracking-widest text-slate-400 font-bold">Toloba ul Kulliyaat il Muminoon</div>
                    </div>
                </div>
                <p class="text-xs text-slate-400 max-w-sm mx-auto md:mx-0 leading-relaxed">
                    Official Badminton Tournament Management Platform for the Dawoodi Bohra community. Founded in 1965 to foster brotherhood, physical fitness, and athletic excellence.
                </p>
            </div>

            <!-- Quick Navigation -->
            <div class="text-center md:text-left">
                <h4 class="text-xs uppercase tracking-widest font-black text-[#c9a84c] mb-3">Quick Navigation</h4>
                <div class="flex flex-wrap justify-center md:justify-start gap-x-6 gap-y-2 text-xs text-slate-300 font-medium">
                    <a href="<?= BASE_URL ?>/" class="hover:text-white transition">Home</a>
                    <a href="<?= BASE_URL ?>/public/tournaments.php" class="hover:text-white transition">Tournaments</a>
                    <a href="<?= BASE_URL ?>/#rules-section" class="hover:text-white transition">Tournament Rules</a>
                    <a href="<?= BASE_URL ?>/admin/login.php" class="hover:text-[#c9a84c] transition">Admin Portal</a>
                </div>
            </div>

            <!-- Bohra Community Badge -->
            <div class="text-center md:text-right space-y-2">
                <div class="inline-block px-4 py-2 rounded-2xl bg-white/5 border border-white/10 text-[11px] text-slate-300 font-mono">
                    <span class="text-[#c9a84c] font-black">TKMI</span> &bull; 1385H – <?= date('Y') ?>
                </div>
                <p class="text-[11px] text-slate-400">
                    Built with dignity and elegance for community sport.
                </p>
            </div>

        </div>

        <!-- Copyright Line -->
        <div class="mt-8 pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-slate-400 text-center sm:text-left">
            <div>
                &copy; <?= date('Y') ?> <strong>Toloba ul Kulliyaat il Muminoon</strong>. All Rights Reserved.
            </div>
            <div class="flex items-center gap-4">
                <span>Swiss + Single Elimination System</span>
                <span>&bull;</span>
                <span class="text-[#c9a84c]">v2.5 Pro Responsive</span>
            </div>
        </div>
    </div>
</footer>

<!-- Global JS -->
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>

</body>
</html>
