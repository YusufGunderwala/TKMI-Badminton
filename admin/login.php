<?php
// ============================================================
// Ultra-Premium Immersive Admin Login
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in? Go to dashboard
if (isAdminLoggedIn()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$error = '';

// Rate Limiting / Brute-Force Lockout Protection
sessionStart();
$ipKey = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? 'local');
$lockoutTime = 15 * 60; // 15 minutes lockout
$maxAttempts = 5;

$attempts = $_SESSION[$ipKey] ?? ['count' => 0, 'first_attempt' => time(), 'locked_until' => 0];

// Check if currently locked out
if (!empty($attempts['locked_until']) && time() < $attempts['locked_until']) {
    $remainingMinutes = ceil(($attempts['locked_until'] - time()) / 60);
    $error = "Too many failed attempts. Access temporarily restricted for {$remainingMinutes} minute(s).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$username || !$password) {
            $error = 'Please enter both username and password.';
        } else {
            $admin = attemptLogin($username, $password);
            if ($admin) {
                // Reset failed attempts on success
                unset($_SESSION[$ipKey]);
                loginAdmin($admin);
                $next = $_GET['next'] ?? (BASE_URL . '/admin/dashboard.php');
                header('Location: ' . $next);
                exit;
            } else {
                // Increment failed attempts
                $attempts['count']++;
                if ($attempts['count'] >= $maxAttempts) {
                    $attempts['locked_until'] = time() + $lockoutTime;
                    $_SESSION[$ipKey] = $attempts;
                    $error = "Too many failed attempts. Access temporarily locked for 15 minutes.";
                } else {
                    $_SESSION[$ipKey] = $attempts;
                    $remaining = $maxAttempts - $attempts['count'];
                    $error = "Invalid username or password. ({$remaining} attempt(s) remaining)";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Portal | TKMI Badminton</title>
  <link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/favicon.png">
  <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/favicon.png">
  <link rel="shortcut icon" href="<?= BASE_URL ?>/assets/favicon.png">

  <!-- Open Graph / WhatsApp Preview Banner -->
  <meta property="og:site_name" content="TKMI Badminton">
  <meta property="og:title" content="Admin Portal | TKMI Badminton">
  <meta property="og:description" content="Official TKMI Badminton Tournament Administration Portal — Live Scoring & Operations">
  <meta property="og:image" content="<?= BASE_URL ?>/assets/img/og-banner.jpg">
  <meta property="og:image:secure_url" content="<?= BASE_URL ?>/assets/img/og-banner.jpg">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="675">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= BASE_URL ?>/admin/login.php">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Admin Portal | TKMI Badminton">
  <meta name="twitter:description" content="Official TKMI Badminton Tournament Administration Portal — Live Scoring & Operations">
  <meta name="twitter:image" content="<?= BASE_URL ?>/assets/img/og-banner.jpg">
  
  <script src="https://cdn.tailwindcss.com"></script>
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
  
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;700;900&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  
  <style>
      /* Custom Animations for Login */
      .slide-left {
          animation: slideLeft 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
      }
      .fade-up-slow {
          animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
      }
      @keyframes slideLeft { 
          0% { opacity: 0; transform: translateX(25px); } 
          100% { opacity: 1; transform: translateX(0); } 
      }
      @keyframes fadeUp { 
          0% { opacity: 0; transform: translateY(15px); } 
          100% { opacity: 1; transform: translateY(0); } 
      }
      
      .delay-100 { animation-delay: 80ms; }
      .delay-200 { animation-delay: 140ms; }
      .delay-300 { animation-delay: 200ms; }
  </style>
</head>

<body class="h-screen w-screen overflow-hidden bg-black font-sans text-white">

    <!-- Breathtaking 3D Badminton Court Background (Pure CSS) -->
    <div class="absolute inset-0 z-0 bg-[#0a152d] overflow-hidden flex items-center justify-center perspective-[1200px]">
        <!-- Glowing abstract orbs for depth -->
        <div class="absolute -top-[20%] -left-[10%] w-[60%] h-[60%] rounded-full bg-blue-600/20 blur-[120px] animate-[pulse_10s_ease-in-out_infinite_alternate]"></div>
        <div class="absolute top-[40%] left-[30%] w-[40%] h-[40%] rounded-full bg-[#c9a84c]/10 blur-[100px] animate-[pulse_15s_ease-in-out_infinite_alternate-reverse]"></div>
        
        <!-- 3D Court Floor -->
        <div class="absolute w-[800px] h-[1200px] border-[3px] border-white/10 shadow-[0_0_80px_rgba(201,168,76,0.1)] rounded-sm"
             style="transform: rotateX(60deg) rotateZ(35deg) translateY(-50px) translateX(-100px); transform-style: preserve-3d;">
            
            <div class="absolute inset-0 bg-blue-900/5 backdrop-blur-sm"></div>
            <!-- Center Line -->
            <div class="absolute top-0 bottom-0 left-1/2 w-[3px] bg-white/20 -translate-x-1/2"></div>
            <!-- The Net (Glowing White) -->
            <div class="absolute top-1/2 left-[-20px] right-[-20px] h-[6px] bg-white/60 -translate-y-1/2 shadow-[0_0_20px_rgba(255,255,255,0.6)] z-10 flex items-center justify-center">
                <div class="absolute inset-0 opacity-30" style="background-image: linear-gradient(90deg, transparent 50%, #0a152d 50%); background-size: 8px 100%;"></div>
            </div>
            <!-- Short Service Lines -->
            <div class="absolute top-[35%] left-0 right-0 h-[3px] bg-white/10"></div>
            <div class="absolute bottom-[35%] left-0 right-0 h-[3px] bg-white/10"></div>
            <!-- Side & Long Lines -->
            <div class="absolute top-0 bottom-0 left-[8%] w-[3px] bg-white/10"></div>
            <div class="absolute top-0 bottom-0 right-[8%] w-[3px] bg-white/10"></div>
            <div class="absolute top-[8%] left-0 right-0 h-[3px] bg-white/10"></div>
            <div class="absolute bottom-[8%] left-0 right-0 h-[3px] bg-white/10"></div>
            <div class="absolute top-0 left-0 right-0 h-[3px] bg-white/20"></div>
            <div class="absolute bottom-0 left-0 right-0 h-[3px] bg-white/20"></div>
        </div>
             
        <!-- Complex Overlay to blend into the panel -->
        <div class="absolute inset-0 bg-gradient-to-r from-black/40 via-[#0f2044]/60 to-[#0f2044]/95"></div>
    </div>

    <!-- Main Layout -->
    <div class="relative z-10 flex h-full w-full">
        
        <!-- Left Side: Cinematic Branding -->
        <div class="hidden lg:flex flex-col justify-end p-20 w-[55%] fade-up-slow">
            <div class="w-24 h-24 rounded-2xl overflow-hidden shadow-[0_0_40px_rgba(201,168,76,0.3)] mb-8 border-2 border-[#c9a84c]/50 bg-[#004d26] p-2 flex items-center justify-center">
                <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-contain">
            </div>
            
            <h1 class="text-7xl font-black font-display leading-[1.05] tracking-tighter mb-6 text-white drop-shadow-2xl">
                Control the <br>
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-[#c9a84c]">Tournament.</span>
            </h1>
            
            <p class="text-blue-200/70 text-lg leading-relaxed max-w-lg font-medium">
                Welcome to the TKMI Badminton Platform. Orchestrate draws, score matches live, and manage the entire event with absolute precision.
            </p>
            
            <div class="mt-16 text-xs font-bold tracking-widest uppercase text-white/30">
                Toloba ul Kulliyaat il Muminoon &copy; <?= date('Y') ?>
            </div>
        </div>

        <!-- Right Side: Ultra-Premium Glass Login Panel -->
        <div class="w-full lg:w-[45%] h-full bg-[#0f2044]/60 backdrop-blur-3xl border-l border-white/10 flex flex-col justify-center px-8 sm:px-16 lg:px-24 shadow-2xl relative">
            
            <!-- Mobile Logo -->
            <div class="lg:hidden mb-12 flex justify-center slide-left">
                <div class="w-20 h-20 rounded-2xl overflow-hidden border-2 border-[#c9a84c]/50 shadow-[0_0_40px_rgba(201,168,76,0.3)] bg-[#004d26] p-2 flex items-center justify-center">
                    <img src="<?= BASE_URL ?>/assets/assets/Logo.png" alt="Toloba Logo" class="w-full h-full object-contain">
                </div>
            </div>

            <div class="slide-left delay-100 mb-10">
                <h2 class="text-4xl font-black font-display text-white mb-3 tracking-tight">Admin Portal</h2>
                <p class="text-blue-200/60 font-medium text-sm">Sign in to securely access the tournament dashboard.</p>
            </div>

            <?php if ($error): ?>
              <div class="slide-left bg-red-500/10 border border-red-500/30 text-red-400 px-5 py-4 rounded-xl mb-8 text-sm font-bold flex items-center gap-3 backdrop-blur-md shadow-lg shadow-red-900/20">
                <i class="ph-fill ph-warning-circle text-xl"></i> <?= e($error) ?>
              </div>
            <?php endif; ?>

            <form id="loginForm" name="loginForm" method="POST" action="<?= BASE_URL ?>/admin/login.php" class="space-y-6 relative z-20">
              <?= csrf_field() ?>

              <div class="slide-left delay-200">
                <label class="block text-[10px] uppercase tracking-widest font-bold text-blue-300/70 mb-2 pl-1" for="username">Username</label>
                <div class="relative group">
                    <div class="absolute inset-0 bg-blue-500/20 rounded-xl blur-md opacity-0 group-focus-within:opacity-100 transition-opacity duration-500 pointer-events-none"></div>
                    
                    <!-- Left Icon Badge -->
                    <div class="absolute left-3.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-blue-500/20 text-[#c9a84c] flex items-center justify-center z-20 pointer-events-none border border-blue-400/30 group-focus-within:border-[#c9a84c] group-focus-within:bg-[#c9a84c]/20 transition-colors shadow-xs">
                        <i class="ph-bold ph-user text-base"></i>
                    </div>
                    
                    <input type="text" 
                           id="username" 
                           name="username" 
                           value="<?= e($_POST['username'] ?? '') ?>" 
                           required 
                           autocomplete="username"
                           autocapitalize="none"
                           spellcheck="false"
                           class="relative z-10 w-full bg-black/30 border border-white/15 rounded-xl pl-14 pr-5 py-4 text-white text-sm placeholder-blue-200/40 focus:outline-none focus:border-[#c9a84c]/60 focus:bg-black/50 transition-all backdrop-blur-sm shadow-inner"
                           placeholder="Enter your username">
                </div>
              </div>

              <div class="slide-left delay-300">
                <label class="block text-[10px] uppercase tracking-widest font-bold text-blue-300/70 mb-2 pl-1" for="password">Password</label>
                <div class="relative group">
                  <div class="absolute inset-0 bg-blue-500/20 rounded-xl blur-md opacity-0 group-focus-within:opacity-100 transition-opacity duration-500 pointer-events-none"></div>
                  
                  <!-- Left Lock Icon Badge -->
                  <div class="absolute left-3.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-blue-500/20 text-[#c9a84c] flex items-center justify-center z-20 pointer-events-none border border-blue-400/30 group-focus-within:border-[#c9a84c] group-focus-within:bg-[#c9a84c]/20 transition-colors shadow-xs">
                      <i class="ph-bold ph-lock-key text-base"></i>
                  </div>
                  
                  <input type="password" 
                         id="password" 
                         name="password" 
                         required 
                         autocomplete="current-password"
                         class="relative z-10 w-full bg-black/30 border border-white/15 rounded-xl pl-14 pr-12 py-4 text-white text-sm placeholder-blue-200/40 focus:outline-none focus:border-[#c9a84c]/60 focus:bg-black/50 transition-all backdrop-blur-sm shadow-inner"
                         placeholder="Enter your password">
                         
                  <!-- Right Eye Toggle Button -->
                  <button type="button" 
                          onclick="togglePassword()" 
                          class="absolute right-3.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 text-slate-300 hover:text-white flex items-center justify-center transition-colors z-20 cursor-pointer"
                          title="Show / Hide Password">
                    <i class="ph-bold ph-eye text-base" id="eyeIcon"></i>
                  </button>
                </div>
              </div>

              <div class="slide-left delay-[400ms] pt-4">
                  <button type="submit" id="loginSubmitBtn" class="w-full relative overflow-hidden bg-blue-600 hover:bg-blue-500 text-white px-8 py-4 rounded-xl font-bold transition-all duration-300 flex items-center justify-center gap-3 shadow-[0_0_30px_rgba(37,99,235,0.3)] hover:shadow-[0_0_50px_rgba(37,99,235,0.6)] group border border-blue-400/50 cursor-pointer">
                    <span class="relative z-10 text-base">Sign In to Command Center</span>
                    <i class="ph-bold ph-arrow-right text-xl relative z-10 group-hover:translate-x-1 transition-transform"></i>
                    <!-- Shine effect -->
                    <div class="absolute top-0 -left-[100%] w-1/2 h-full bg-gradient-to-r from-transparent via-white/20 to-transparent skew-x-[-20deg] group-hover:animate-[shine_1s_ease-in-out] pointer-events-none"></div>
                  </button>
              </div>
            </form>

            <div class="mt-12 text-center slide-left delay-[500ms]">
              <a href="<?= BASE_URL ?>/" class="inline-flex items-center justify-center gap-2 text-sm font-bold text-blue-200/50 hover:text-white transition-colors border border-white/5 bg-black/10 px-6 py-3 rounded-full hover:bg-white/5">
                <i class="ph-bold ph-arrow-left"></i> Return to Public Site
              </a>
            </div>
            
        </div>
    </div>

    <style>
        @keyframes shine {
            100% { left: 200%; }
        }
    </style>

    <script>
      function togglePassword() {
        const input = document.getElementById('password');
        const icon = document.getElementById('eyeIcon');
        if (input.type === 'password') {
          input.type = 'text';
          if (icon) {
            icon.classList.remove('ph-eye');
            icon.classList.add('ph-eye-slash');
          }
        } else {
          input.type = 'password';
          if (icon) {
            icon.classList.remove('ph-eye-slash');
            icon.classList.add('ph-eye');
          }
        }
      }
    </script>
</body>
</html>
