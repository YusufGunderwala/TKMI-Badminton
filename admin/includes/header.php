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
<body class="bg-[#f0f4f8] font-sans text-slate-800 h-screen overflow-hidden flex selection:bg-blue-600 selection:text-white">

    <!-- Premium Sidebar -->
    <aside class="w-[280px] bg-[#0f2044] text-white flex flex-col h-full flex-shrink-0 relative z-20 shadow-2xl">
        
        <!-- Branding -->
        <div class="px-6 py-6 flex items-center justify-between border-b border-white/10 relative">
            <div class="absolute inset-0 bg-gradient-to-r from-[#c9a84c]/5 to-transparent opacity-50"></div>
            <div class="flex items-center gap-3.5 relative z-10">
                <div class="w-10 h-10 rounded-xl overflow-hidden shadow-lg border border-white/20 flex-shrink-0 bg-[#004d26]">
                    <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-cover">
                </div>
                <div>
                    <h1 class="text-xl font-black font-display tracking-tight text-white leading-tight">TKMI <span class="text-[#c9a84c]">Command</span></h1>
                    <div class="text-[9px] uppercase tracking-[0.2em] text-slate-400 font-bold mt-0.5">Tournament Edition</div>
                </div>
            </div>
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
    <div class="flex-1 flex flex-col relative min-w-0">
        
        <!-- Topbar -->
        <header class="h-20 bg-white/80 backdrop-blur-md border-b border-slate-200 flex items-center justify-between px-10 flex-shrink-0 z-10">
            <div class="flex items-center gap-4">
                <h2 class="text-xl font-black text-slate-800 font-display">
                    <?= htmlspecialchars($pageTitle ?? 'Admin Dashboard') ?>
                </h2>
            </div>
            
            <div class="flex items-center gap-4">
                <a href="<?= BASE_URL ?>/" target="_blank" class="flex items-center gap-2 text-sm font-bold text-white bg-[#0f2044] hover:bg-blue-700 transition-colors px-6 py-2.5 rounded-full shadow-md">
                    View Public Site <i class="ph-bold ph-arrow-square-out text-lg"></i>
                </a>
            </div>
        </header>
        
        <!-- Scrollable Content Area -->
        <main class="flex-1 overflow-y-auto p-10 relative z-0">
