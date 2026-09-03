<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Protect admin routes
requireLogin();
$adminUser = currentAdmin();

// Determine current page for active nav state
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin Dashboard') ?> | TKMI Admin</title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/favicon.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/favicon.png">
    <link rel="shortcut icon" href="<?= BASE_URL ?>/assets/favicon.png">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;700;900&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Outfit', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <script defer src="https://unpkg.com/@phosphor-icons/web"></script>
    <script defer src="<?= BASE_URL ?>/assets/vendor/alpine.min.js"></script>
    <script defer src="<?= BASE_URL ?>/assets/vendor/magic-ui/confetti.min.js"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magic-ui.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/ui-bundle.css">
    <script src="<?= BASE_URL ?>/assets/vendor/ui-bundle.js" defer></script>
    <script src="<?= BASE_URL ?>/assets/vendor/magic-ui/magic-ui.js" defer></script>
    <script src="<?= BASE_URL ?>/assets/vendor/retro-ui/retro-ui.js" defer></script>
    <script src="<?= BASE_URL ?>/assets/vendor/smooth-ui/smooth-ui.js" defer></script>
    <script src="<?= BASE_URL ?>/assets/vendor/unlumen-ui/unlumen-ui.js" defer></script>
</head>
<body x-data="{ mobileNavOpen: false }" class="bg-[#f0f4f8] font-sans text-slate-800 h-screen overflow-hidden flex selection:bg-blue-600 selection:text-white relative w-full max-w-full">

    <!-- Mobile Drawer Backdrop -->
    <div x-show="mobileNavOpen" 
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="mobileNavOpen = false" 
         class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs z-40 lg:hidden"
         style="display: none;"></div>

    <!-- Premium Sidebar (Drawer on mobile/tablet, Fixed on desktop) -->
    <aside class="fixed lg:relative inset-y-0 left-0 w-[280px] bg-[#0f2044] text-white flex flex-col h-full flex-shrink-0 z-50 shadow-2xl transition-transform duration-300 ease-in-out lg:translate-x-0"
           :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        
        <!-- Branding -->
        <div class="px-6 py-5 sm:py-6 flex items-center justify-between border-b border-white/10 relative">
            <div class="absolute inset-0 bg-gradient-to-r from-[#c9a84c]/5 to-transparent opacity-50"></div>
            <div class="flex items-center gap-3.5 relative z-10">
                <div class="w-10 h-10 rounded-xl overflow-hidden shadow-lg border border-white/20 flex-shrink-0 bg-[#004d26]">
                    <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-cover">
                </div>
                <div>
                    <h1 class="text-xl font-black font-display tracking-tight text-white leading-tight">TKMI <span class="text-[#c9a84c]">Badminton</span></h1>
                    <div class="text-[9px] uppercase tracking-[0.2em] text-slate-400 font-bold mt-0.5">Tournament Edition</div>
                </div>
            </div>
            <!-- Mobile Close Drawer Button -->
            <button type="button" @click="mobileNavOpen = false" class="lg:hidden text-white/60 hover:text-white p-1.5 rounded-lg hover:bg-white/10 transition">
                <i class="ph-bold ph-x text-lg"></i>
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-6 px-4 space-y-1 custom-scrollbar">
            <?php
            $navItems = [
                ['path' => '/admin/dashboard.php', 'icon' => 'ph-squares-four', 'label' => 'Dashboard'],
                ['path' => '/admin/tournaments/', 'icon' => 'ph-trophy', 'label' => 'Tournaments'],
                ['path' => '/admin/scoring/', 'icon' => 'ph-monitor-play', 'label' => 'Live Scoring'],
                ['path' => '/admin/players/', 'icon' => 'ph-users', 'label' => 'Player Database'],
                ['path' => '/admin/sponsors/', 'icon' => 'ph-handshake', 'label' => 'Sponsors'],
            ];

            // Only Super Admins can manage other Admin Accounts
            if (isSuperAdmin()) {
                $navItems[] = ['path' => '/admin/admins/', 'icon' => 'ph-shield-star', 'label' => 'Admins'];
            }

            foreach ($navItems as $item):
                // Loose match for active states
                $isActive = strpos($currentPath, $item['path']) !== false;
                $activeClasses = $isActive 
                    ? 'bg-white/10 text-white shadow-inner border border-white/10' 
                    : 'text-white/60 hover:text-white hover:bg-white/5 border border-transparent';
                $iconActiveClasses = $isActive ? 'text-[#c9a84c] ph-fill' : 'text-white/40 group-hover:text-white/80 ph-bold';
            ?>
                <a href="<?= BASE_URL . $item['path'] ?>" 
                   class="group flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold transition-all <?= $activeClasses ?>">
                    <i class="<?= $item['icon'] ?> text-xl transition-colors <?= $iconActiveClasses ?>"></i>
                    <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Admin Profile -->
        <div class="p-4 border-t border-white/5 bg-black/20">
            <div class="flex items-center justify-between px-2">
                <div class="flex flex-col">
                    <div class="flex items-center gap-1.5 mb-1">
                        <?php if (isSuperAdmin()): ?>
                            <span class="text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-full bg-[#c9a84c]/20 text-[#c9a84c] border border-[#c9a84c]/40">
                                ⭐ Super Admin
                            </span>
                        <?php else: ?>
                            <span class="text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-white/10 text-slate-300">
                                👤 Tournament Admin
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="text-sm font-bold text-white truncate max-w-[170px]"><?= e($adminUser['display_name'] ?? $adminUser['username'] ?? 'Admin') ?></span>
                </div>
                <a href="<?= BASE_URL ?>/admin/logout.php" class="text-white/40 hover:text-red-400 transition-colors p-2 rounded-lg hover:bg-white/5" title="Logout">
                    <i class="ph-bold ph-sign-out text-lg"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content Wrapper -->
    <div class="flex-1 flex flex-col relative min-w-0 max-w-full overflow-hidden">
        
        <!-- Topbar -->
        <header class="h-16 sm:h-20 bg-white/90 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-4 sm:px-6 lg:px-10 flex-shrink-0 z-30 shadow-xs">
            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                <!-- Mobile Drawer Toggle Button -->
                <button type="button" 
                        @click="mobileNavOpen = !mobileNavOpen" 
                        class="lg:hidden p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-[#0f2044] transition flex items-center justify-center focus:outline-none shrink-0" 
                        title="Toggle Menu">
                    <i class="ph-bold ph-list text-xl"></i>
                </button>

                <h2 class="text-base sm:text-xl font-black text-slate-900 font-display truncate">
                    <?= htmlspecialchars($pageTitle ?? 'Admin Dashboard') ?>
                </h2>
            </div>
            
            <div class="flex items-center gap-2 sm:gap-4 shrink-0">
                <a href="<?= BASE_URL ?>/" target="_blank" class="flex items-center gap-1.5 sm:gap-2 text-xs sm:text-sm font-bold text-white bg-[#0f2044] hover:bg-blue-900 transition-all px-3.5 sm:px-6 py-2 sm:py-2.5 rounded-full shadow-md hover:shadow-lg">
                    <span class="hidden sm:inline">View Public Site</span>
                    <span class="sm:hidden">Public</span>
                    <i class="ph-bold ph-arrow-square-out text-sm sm:text-base"></i>
                </a>
            </div>
        </header>
        
        <!-- Scrollable Content Area -->
        <main class="flex-1 overflow-y-auto overflow-x-hidden p-3 sm:p-6 lg:p-8 relative z-0 min-w-0 max-w-full">
