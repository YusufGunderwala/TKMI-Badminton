<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> | TKMI Badminton</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/assets/Logo.png">

  <!-- OG Meta Tags -->
  <meta property="og:title"       content="<?= htmlspecialchars($ogTitle ?? APP_NAME) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDesc  ?? 'Official TKMI Badminton Tournament Platform') ?>">
  <meta property="og:image"       content="<?= BASE_URL ?>/assets/img/og-cover.jpg">
  <meta property="og:url"         content="<?= BASE_URL ?>">
  <meta property="og:type"        content="website">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;700;900&display=swap" rel="stylesheet">

  <!-- Tailwind CSS via CDN -->
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            tkmi: {
              navy:   '#0f2044', 
              navylt: '#1a3260', 
              gold:   '#c9a84c', 
              goldlt: '#e8c97a', 
              light:  '#f4f7f6',
            }
          },
          fontFamily: {
            sans: ['Inter', 'sans-serif'],
            display: ['Outfit', 'sans-serif'],
          }
        }
      }
    }
  </script>

  <!-- Phosphor Icons -->
  <script defer src="https://unpkg.com/@phosphor-icons/web"></script>

  <!-- Local Alpine.js Engine -->
  <script defer src="<?= BASE_URL ?>/assets/vendor/alpine.min.js"></script>

  <!-- Local Canvas Confetti Engine (Magic UI) -->
  <script defer src="<?= BASE_URL ?>/assets/vendor/magic-ui/confetti.min.js"></script>

  <!-- Next-Gen UI Master Stylesheet Bundle (Magic UI, Retro UI, Smooth UI, Unlumen) -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magic-ui.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/ui-bundle.css">

  <!-- Next-Gen UI Master Scripts -->
  <script src="<?= BASE_URL ?>/assets/vendor/ui-bundle.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/vendor/magic-ui/magic-ui.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/vendor/retro-ui/retro-ui.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/vendor/smooth-ui/smooth-ui.js" defer></script>
  <script src="<?= BASE_URL ?>/assets/vendor/unlumen-ui/unlumen-ui.js" defer></script>

  <style>
    [x-cloak] { display: none !important; }
  </style>
</head>

<body class="min-h-screen flex flex-col bg-[#f8fafc] text-slate-800 antialiased selection:bg-blue-600 selection:text-white">

<!-- ======================================================= -->
<!-- ULTRA-PREMIUM FLOATING CAPSULE NAVIGATION (Ref Design)   -->
<!-- ======================================================= -->
<header class="fixed top-0 left-0 right-0 z-50 px-4 sm:px-6 lg:px-8 pt-3 sm:pt-4 pointer-events-none">
  <nav class="max-w-5xl mx-auto bg-[#0f2044]/85 backdrop-blur-xl border border-white/15 rounded-full px-5 sm:px-7 py-2.5 shadow-[0_10px_35px_rgba(0,0,0,0.35)] flex items-center justify-between pointer-events-auto transition-all duration-300">

      <!-- Logo + Brand -->
      <a href="<?= BASE_URL ?>/" class="flex items-center gap-3 group">
        <div class="w-10 h-10 rounded-full overflow-hidden border-2 border-[#c9a84c]/60 shadow-md transition-transform group-hover:scale-105 flex-shrink-0 bg-[#004d26]">
          <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-cover">
        </div>
        <div>
          <div class="text-white font-display font-black text-lg tracking-tight leading-none group-hover:text-[#c9a84c] transition-colors">TKMI BADMINTON</div>
        </div>
      </a>

      <!-- Desktop Nav Links -->
      <div class="hidden md:flex items-center gap-6">
        <a href="<?= BASE_URL ?>/" class="text-xs font-bold uppercase tracking-wider text-slate-300 hover:text-white transition-colors flex items-center gap-1.5">
          <i class="ph-bold ph-house text-sm"></i> Home
        </a>
        <a href="<?= BASE_URL ?>/public/tournaments.php" class="text-xs font-bold uppercase tracking-wider text-slate-300 hover:text-white transition-colors flex items-center gap-1.5">
          <i class="ph-bold ph-medal text-sm"></i> Tournaments
        </a>
        <a href="<?= BASE_URL ?>/#rules-section" class="text-xs font-bold uppercase tracking-wider text-slate-300 hover:text-white transition-colors flex items-center gap-1.5">
          <i class="ph-bold ph-book-open-text text-sm"></i> Format
        </a>
      </div>

      <!-- Action Button -->
      <div class="flex items-center gap-3">
        <a href="<?= BASE_URL ?>/admin/login.php" class="gold-shimmer-btn text-xs font-black uppercase tracking-wider px-5 py-2 rounded-full shadow-md transition-all flex items-center gap-2 hover-lift">
          <i class="ph-bold ph-shield-check text-sm"></i> <span>Command Center</span>
        </a>

        <!-- Mobile Menu Toggle -->
        <div class="md:hidden" x-data="{ open: false }">
          <button @click="open = !open" class="text-white p-2 rounded-full bg-white/10 hover:bg-white/20 focus:outline-none transition">
            <i class="ph-bold ph-list text-lg" x-show="!open"></i>
            <i class="ph-bold ph-x text-lg" x-show="open" style="display: none;"></i>
          </button>
          
          <!-- Mobile Dropdown -->
          <div x-show="open" @click.away="open = false" x-transition.opacity.duration.200ms class="absolute top-16 left-4 right-4 bg-[#0f2044] border border-white/15 rounded-2xl shadow-2xl p-4 space-y-2 z-50">
            <a href="<?= BASE_URL ?>/" class="block text-white py-2.5 px-4 rounded-xl hover:bg-white/10 font-bold text-sm flex items-center gap-3"><i class="ph-bold ph-house text-lg text-[#c9a84c]"></i> Home</a>
            <a href="<?= BASE_URL ?>/public/tournaments.php" class="block text-white py-2.5 px-4 rounded-xl hover:bg-white/10 font-bold text-sm flex items-center gap-3"><i class="ph-bold ph-medal text-lg text-[#c9a84c]"></i> Tournaments</a>
            <a href="<?= BASE_URL ?>/#rules-section" class="block text-white py-2.5 px-4 rounded-xl hover:bg-white/10 font-bold text-sm flex items-center gap-3"><i class="ph-bold ph-book-open-text text-lg text-[#c9a84c]"></i> Format & Rules</a>
            <a href="<?= BASE_URL ?>/admin/login.php" class="block mt-3 text-center bg-[#c9a84c] text-[#0f2044] font-black py-2.5 rounded-xl text-sm"><i class="ph-bold ph-lock-key"></i> Admin Login</a>
          </div>
        </div>
      </div>

  </nav>
</header>

<!-- Main content container -->
<main class="flex-1 flex flex-col relative z-0 pt-24">
