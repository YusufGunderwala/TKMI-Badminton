<?php
// ============================================================
// TKMI Stadium Live Scoring Console — Next-Gen Esports Edition
// ============================================================
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireLogin();

$matchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tourneyId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

if (!$matchId && $tourneyId) {
    header('Location: ' . BASE_URL . '/admin/scoring/index.php?tournament_id=' . $tourneyId);
    exit;
}

$pdo = db();
$sponsors = getActiveSponsors();

// Fetch Match Data + Player Credentials
$stmt = $pdo->prepare('
    SELECT m.*, 
           pa.display_name as pa_name, pa.full_name as pa_full, pa.mohallah as pa_mohallah, pa.photo_path as pa_photo, pa.its_id as pa_its,
           pb.display_name as pb_name, pb.full_name as pb_full, pb.mohallah as pb_mohallah, pb.photo_path as pb_photo, pb.its_id as pb_its,
           ta.display_name as ta_name, tb.display_name as tb_name,
           t.name as tourney_name, t.match_type, t.gender,
           rc.best_of, rc.points_per_game, rc.deuce_enabled, rc.deuce_trigger, rc.deuce_cap
    FROM matches m
    JOIN tournaments t ON m.tournament_id = t.id
    JOIN round_configs rc ON m.tournament_id = rc.tournament_id AND m.round_key = rc.round_key
    LEFT JOIN players pa ON m.participant_a_id = pa.id
    LEFT JOIN players pb ON m.participant_b_id = pb.id
    LEFT JOIN teams ta ON m.team_a_id = ta.id
    LEFT JOIN teams tb ON m.team_b_id = tb.id
    WHERE m.id = ?
');
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    header('Location: ' . BASE_URL . '/admin/scoring/index.php');
    exit;
}

$isDoubles = !empty($match['team_a_id']);
$pA_display = $isDoubles ? ($match['ta_name'] ?: 'Team A') : ($match['pa_name'] ?: 'Player A');
$pB_display = $isDoubles ? ($match['tb_name'] ?: 'Team B') : ($match['pb_name'] ?: 'Player B');

$serverWinnerSide = '';
if (!empty($match['winner_player_id'])) {
    $serverWinnerSide = ($match['winner_player_id'] == $match['participant_a_id']) ? 'A' : 'B';
} elseif (!empty($match['winner_team_id'])) {
    $serverWinnerSide = ($match['winner_team_id'] == $match['team_a_id']) ? 'A' : 'B';
}

$csrf = csrf_token();
$bestOf = (int)$match['best_of'];
$gamesToWin = ceil($bestOf / 2);
?>
<!DOCTYPE html>
<html lang="en" class="h-full select-none">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>⚡ Live Arena: <?= e($pA_display) ?> vs <?= e($pB_display) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="<?= BASE_URL ?>/assets/vendor/alpine.min.js"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="<?= BASE_URL ?>/assets/vendor/magic-ui/confetti.min.js"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magic-ui.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        tkmi: {
                            navy: '#060e22',
                            deep: '#030814',
                            gold: '#c9a84c',
                            goldLight: '#ffd978',
                            cyan: '#00d2ff',
                            purple: '#9d4edd'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        display: ['Outfit', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace']
                    }
                }
            }
        }
    </script>
    
    <style>
        body { overscroll-behavior-y: contain; }
        .tap-target { touch-action: manipulation; }
        
        /* 3D Glass & Neon Bloom Animations */
        @keyframes pulseGlow {
            0%, 100% { opacity: 0.6; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.03); }
        }
        .neon-glow-cyan {
            box-shadow: 0 0 35px rgba(0, 210, 255, 0.4), inset 0 0 20px rgba(0, 210, 255, 0.2);
        }
        .neon-glow-gold {
            box-shadow: 0 0 35px rgba(201, 168, 76, 0.4), inset 0 0 20px rgba(201, 168, 76, 0.2);
        }
        .score-digit {
            font-variant-numeric: tabular-nums;
            text-shadow: 0 0 40px currentColor;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-[#0c1c3d] via-[#122856] to-[#0a1733] text-white min-h-screen lg:h-screen flex flex-col overflow-x-hidden font-sans relative" 
      x-data="scorerArenaApp()"
      @keydown.window="handleKeyboardShortcuts($event)">

    <!-- Tactical Badminton Court Grid & Stadium Lines (Background) -->
    <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden opacity-25">
        <!-- SVG Full Court Badminton Geometry & Tactical Markings -->
        <svg class="w-full h-full text-white/30" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" viewBox="0 0 1340 610">
            <defs>
                <!-- Court Grid Pattern -->
                <pattern id="courtGrid" width="40" height="40" patternUnits="userSpaceOnUse">
                    <path d="M 40 0 L 0 0 0 40" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="1"/>
                </pattern>
                <!-- Net Texture -->
                <pattern id="netMesh" width="10" height="10" patternUnits="userSpaceOnUse">
                    <line x1="0" y1="0" x2="10" y2="10" stroke="rgba(255,255,255,0.25)" stroke-width="0.8"/>
                    <line x1="10" y1="0" x2="0" y2="10" stroke="rgba(255,255,255,0.25)" stroke-width="0.8"/>
                </pattern>
            </defs>

            <!-- Background Micro-Grid -->
            <rect width="100%" height="100%" fill="url(#courtGrid)" />

            <!-- Court Perimeter (Doubles Outer Boundary) -->
            <rect x="50" y="30" width="1240" height="550" fill="none" stroke="currentColor" stroke-width="2.5" />

            <!-- Singles Side Tramlines -->
            <line x1="50" y1="72" x2="1290" y2="72" stroke="currentColor" stroke-width="1.8" stroke-dasharray="8 4" />
            <line x1="50" y1="538" x2="1290" y2="538" stroke="currentColor" stroke-width="1.8" stroke-dasharray="8 4" />

            <!-- Doubles Long Service Lines -->
            <line x1="120" y1="30" x2="120" y2="580" stroke="currentColor" stroke-width="1.8" />
            <line x1="1220" y1="30" x2="1220" y2="580" stroke="currentColor" stroke-width="1.8" />

            <!-- Short Service Lines -->
            <line x1="472" y1="30" x2="472" y2="580" stroke="currentColor" stroke-width="2" />
            <line x1="868" y1="30" x2="868" y2="580" stroke="currentColor" stroke-width="2" />

            <!-- Center Service Lines -->
            <line x1="50" y1="305" x2="472" y2="305" stroke="currentColor" stroke-width="2" />
            <line x1="868" y1="305" x2="1290" y2="305" stroke="currentColor" stroke-width="2" />

            <!-- Center Net Line & Net Mesh Division -->
            <rect x="665" y="20" width="10" height="570" fill="url(#netMesh)" stroke="rgba(201,168,76,0.6)" stroke-width="2"/>
            <line x1="670" y1="10" x2="670" y2="600" stroke="#c9a84c" stroke-width="3" stroke-dasharray="12 6" />

            <!-- Left Court Side Marker: COURT A -->
            <text x="260" y="320" fill="rgba(6,182,212,0.12)" font-size="80" font-family="Outfit, sans-serif" font-weight="900" letter-spacing="16" text-anchor="middle">COURT A</text>

            <!-- Right Court Side Marker: COURT B -->
            <text x="1080" y="320" fill="rgba(201,168,76,0.12)" font-size="80" font-family="Outfit, sans-serif" font-weight="900" letter-spacing="16" text-anchor="middle">COURT B</text>
        </svg>
    </div>

    <!-- Ambient Stadium Lighting -->
    <div class="fixed top-0 left-1/4 w-96 h-96 bg-blue-500/15 rounded-full blur-[140px] pointer-events-none"></div>
    <div class="fixed bottom-0 right-1/4 w-96 h-96 bg-[#c9a84c]/15 rounded-full blur-[140px] pointer-events-none"></div>

    <!-- ============================================================ -->
    <!-- TOP STADIUM CONTROL BAR & HUD                                -->
    <!-- ============================================================ -->
    <header class="bg-[#0f234f]/90 backdrop-blur-xl border-b border-white/15 px-3 sm:px-6 py-3 flex items-center justify-between z-30 shrink-0 shadow-lg relative">
        
        <!-- Left: Back Navigation & Match Meta -->
        <div class="flex items-center gap-2.5 sm:gap-4">
            <a href="<?= BASE_URL ?>/admin/scoring/index.php?tournament_id=<?= $match['tournament_id'] ?>" 
               class="p-2 sm:px-3 sm:py-2 rounded-xl bg-white/10 hover:bg-white/20 text-slate-200 hover:text-white transition flex items-center gap-1.5 text-xs font-bold border border-white/15 shadow-sm">
                <i class="ph-bold ph-arrow-left text-sm"></i>
                <span class="hidden sm:inline">Court Fixtures</span>
            </a>

            <div>
                <div class="text-[10px] sm:text-xs font-mono font-black text-[#c9a84c] uppercase tracking-wider flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-red-500 animate-ping"></span>
                    <span>LIVE COURT</span>
                    <span class="text-white/40">&bull;</span>
                    <span class="truncate max-w-[120px] sm:max-w-none"><?= e($match['tourney_name']) ?></span>
                </div>
                <div class="text-xs sm:text-sm font-black text-white flex items-center gap-1.5 mt-0.5">
                    <span><?= getRoundLabel($match['round_key']) ?></span>
                    <span class="text-white/40">&bull;</span>
                    <span class="text-slate-300">Match #<?= $match['match_number'] ?></span>
                </div>
            </div>
        </div>

        <!-- Center: Dynamic Game Status / Deuce Capsule & Quick Format Switcher -->
        <div class="flex items-center gap-2">
            <!-- Normal Play Capsule (Clickable for Format Modal) -->
            <button type="button" 
                    @click="showFormatModal = true" 
                    x-show="!isDeuce" 
                    class="flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-1.5 rounded-full bg-white/10 hover:bg-white/20 border border-white/15 hover:border-[#c9a84c]/50 text-[11px] sm:text-xs font-bold shadow-inner transition cursor-pointer group"
                    title="Click to change Match Format (1, 3, 5 sets or point target)">
                <span class="text-slate-200" x-text="bestOf === 1 ? '1 Single Game' : ('Game ' + (games_a + games_b + 1) + '/' + bestOf)"></span>
                <span class="text-white/40">&bull;</span>
                <span class="text-[#c9a84c] font-black" x-text="pointsPerGame + ' Pts'"></span>
                <template x-if="deuceEnabled">
                    <span class="hidden sm:inline text-slate-300 font-normal" x-text="'• Deuce @ ' + deuceTrigger"></span>
                </template>
                <i class="ph-bold ph-gear-fine text-xs text-[#c9a84c] opacity-60 group-hover:opacity-100 transition-opacity ml-1"></i>
            </button>

            <!-- Flaming Deuce Capsule -->
            <div x-show="isDeuce" 
                 x-cloak
                 class="flex items-center gap-2 px-3.5 sm:px-5 py-1.5 rounded-full bg-gradient-to-r from-red-600 via-amber-500 to-red-600 text-white font-black text-xs uppercase tracking-wider shadow-[0_0_25px_rgba(239,68,68,0.7)] border border-amber-300 animate-pulse">
                <i class="ph-fill ph-fire text-base text-yellow-200"></i>
                <span>DEUCE ACTIVE</span>
            </div>
        </div>

        <!-- Right: Quick Options & Format -->
        <div class="flex items-center gap-2">
            <!-- Match Format Settings Modal Trigger -->
            <button type="button" 
                    @click="showFormatModal = true" 
                    class="p-2 sm:px-3 sm:py-2 rounded-xl bg-white/10 hover:bg-white/20 text-slate-200 hover:text-white border border-white/15 transition flex items-center gap-1 font-bold text-xs cursor-pointer shadow-sm"
                    title="Configure Sets (1, 3, 5) & Points">
                <i class="ph-bold ph-sliders text-sm text-[#ffd978]"></i>
                <span class="hidden sm:inline">Rules</span>
            </button>

            <!-- Fullscreen Toggle -->
            <button type="button" 
                    @click="toggleFullScreen()" 
                    class="p-2 sm:p-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-slate-200 hover:text-white border border-white/15 transition cursor-pointer"
                    title="Toggle Fullscreen">
                <i class="ph-bold ph-corners-out text-sm sm:text-base"></i>
            </button>

            <!-- Match Options (Walkover/Retire) -->
            <button type="button" 
                    @click="showOptions = true" 
                    class="p-2 sm:px-3 sm:py-2 rounded-xl bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/40 transition flex items-center gap-1 font-bold text-xs cursor-pointer"
                    title="Match Options">
                <i class="ph-bold ph-dots-three-vertical text-base"></i>
                <span class="hidden sm:inline">Options</span>
            </button>
        </div>

    </header>

    <!-- ============================================================ -->
    <!-- MAIN LUMINOUS STADIUM SPLIT SCORING ARENA                    -->
    <!-- ============================================================ -->
    <main class="flex-1 grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 p-3 sm:p-5 lg:p-6 relative z-10 overflow-y-auto lg:overflow-hidden" x-show="!isCompleted">
        
        <!-- Pre-Match Quick Match Format Selector Bar (Active Before Serving) -->
        <div x-show="score_a === 0 && score_b === 0 && games_a === 0 && games_b === 0 && !isCompleted" 
             x-cloak
             class="col-span-1 lg:col-span-2 -mb-2 z-20">
            <div class="bg-gradient-to-r from-[#0f2452]/95 via-[#16306e]/95 to-[#0f2452]/95 border border-[#c9a84c]/50 rounded-2xl p-3 sm:p-4 shadow-xl flex flex-col md:flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-300 to-[#c9a84c] text-[#080e1e] flex items-center justify-center font-black shadow-md flex-shrink-0">
                        <i class="ph-bold ph-sliders-horizontal text-lg"></i>
                    </div>
                    <div>
                        <div class="text-xs font-black text-white uppercase tracking-wider flex items-center gap-2 flex-wrap">
                            <span>Pre-Match Format</span>
                            <span class="px-2 py-0.2 rounded-full bg-[#c9a84c]/20 text-[#ffd978] text-[10px] font-mono">Choose Sets for this Game</span>
                        </div>
                        <div class="text-[11px] text-blue-200/80">Select match length before serving point #1:</div>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap justify-center">
                    <!-- Sets Selector (1, 3, 5) -->
                    <div class="flex items-center bg-black/40 p-1 rounded-xl border border-white/15">
                        <button type="button" 
                                @click="setFormat(1, pointsPerGame)" 
                                :class="bestOf === 1 ? 'bg-[#c9a84c] text-[#080e1e] font-black shadow-md' : 'text-slate-300 hover:text-white'"
                                class="px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1">
                            <i class="ph-bold ph-lightning"></i> 1 Set
                        </button>
                        <button type="button" 
                                @click="setFormat(3, pointsPerGame)" 
                                :class="bestOf === 3 ? 'bg-[#c9a84c] text-[#080e1e] font-black shadow-md' : 'text-slate-300 hover:text-white'"
                                class="px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1">
                            <i class="ph-bold ph-trophy"></i> Best of 3
                        </button>
                        <button type="button" 
                                @click="setFormat(5, pointsPerGame)" 
                                :class="bestOf === 5 ? 'bg-[#c9a84c] text-[#080e1e] font-black shadow-md' : 'text-slate-300 hover:text-white'"
                                class="px-3 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1">
                            <i class="ph-bold ph-crown"></i> Best of 5
                        </button>
                    </div>

                    <!-- Points Selector (11, 15, 21) -->
                    <div class="flex items-center bg-black/40 p-1 rounded-xl border border-white/15">
                        <button type="button" 
                                @click="setFormat(bestOf, 11)" 
                                :class="pointsPerGame === 11 ? 'bg-cyan-500 text-[#080e1e] font-black shadow-md' : 'text-slate-300 hover:text-white'"
                                class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer">
                            11 Pts
                        </button>
                        <button type="button" 
                                @click="setFormat(bestOf, 15)" 
                                :class="pointsPerGame === 15 ? 'bg-cyan-500 text-[#080e1e] font-black shadow-md' : 'text-slate-300 hover:text-white'"
                                class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer">
                            15 Pts
                        </button>
                        <button type="button" 
                                @click="setFormat(bestOf, 21)" 
                                :class="pointsPerGame === 21 ? 'bg-cyan-500 text-[#080e1e] font-black shadow-md' : 'text-slate-300 hover:text-white'"
                                class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition cursor-pointer">
                            21 Pts
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Live Match Point / Deciding Point Reached Alert Banner -->
        <div x-show="isMatchPointReached && !isCompleted" x-cloak class="col-span-1 lg:col-span-2 -mb-2 z-20">
            <div class="w-full bg-gradient-to-r from-emerald-950/95 via-[#0f3d26]/95 to-emerald-950/95 border-2 border-emerald-400 rounded-2xl py-3 px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3 shadow-[0_0_35px_rgba(16,185,129,0.35)] backdrop-blur-xl animate-pulse">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-400 to-teal-600 text-slate-950 flex items-center justify-center font-black shadow-lg flex-shrink-0">
                        <i class="ph-fill ph-trophy text-2xl text-yellow-100"></i>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm font-black text-emerald-300 tracking-wider flex items-center gap-2 flex-wrap">
                            <span>🏆 MATCH POINT REACHED</span>
                            <span class="text-white text-xs font-bold">(Winner: <span x-text="getMatchWinnerName()"></span>)</span>
                        </div>
                        <div class="text-[11px] text-emerald-200/80 font-medium mt-0.5">
                            Winning target score attained. Finalize result or Undo if misclicked:
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button type="button" 
                            @click="showConfirmCompleteModal = true" 
                            class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-400 to-teal-400 hover:from-emerald-300 hover:to-teal-300 text-slate-950 text-xs font-black uppercase tracking-wider shadow-lg transition flex items-center gap-1.5 cursor-pointer">
                        <i class="ph-bold ph-check-circle text-base"></i> Finalize Match
                    </button>
                </div>
            </div>
        </div>

        <!-- Live Deuce Banner Alert -->
        <div x-show="!isMatchPointReached && isDeuce" x-cloak class="col-span-1 lg:col-span-2 -mb-2 z-20">
            <div class="w-full bg-gradient-to-r from-red-950/90 via-amber-950/90 to-red-950/90 border-2 border-amber-400 rounded-2xl py-3 px-4 sm:px-6 flex items-center justify-between shadow-[0_0_35px_rgba(245,158,11,0.35)] backdrop-blur-xl animate-pulse">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-red-500 text-slate-950 flex items-center justify-center font-black shadow-lg">
                        <i class="ph-fill ph-fire text-2xl text-yellow-100"></i>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm font-black text-amber-300 tracking-wider flex items-center gap-2 flex-wrap">
                            <span>🔥 DEUCE IN PLAY</span>
                            <span class="text-white text-xs font-bold" x-show="score_a === score_b">(TIED AT <span x-text="score_a"></span>)</span>
                            <span class="text-cyan-300 text-xs font-bold" x-show="score_a > score_b">(+1 ADVANTAGE <?= e($pA_display) ?>)</span>
                            <span class="text-yellow-300 text-xs font-bold" x-show="score_b > score_a">(+1 ADVANTAGE <?= e($pB_display) ?>)</span>
                        </div>
                        <div class="text-[11px] text-amber-200/80 font-medium mt-0.5">
                            Must win by 2 consecutive points &bull; Sudden-Death Cap at <span x-text="deuceCap"></span> pts
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <span class="px-3.5 py-1 rounded-xl bg-amber-400 text-[#0a1630] text-[11px] font-black uppercase tracking-widest shadow-md">
                        Cap: <span x-text="deuceCap"></span> Pts
                    </span>
                </div>
            </div>
        </div>

        <!-- Live Game Point Banner Alert -->
        <div x-show="!isDeuce && isGamePoint" x-cloak class="col-span-1 lg:col-span-2 -mb-2 z-20">
            <div class="w-full bg-gradient-to-r from-cyan-950/90 via-blue-900/90 to-cyan-950/90 border-2 border-cyan-400 rounded-2xl py-2.5 px-4 sm:px-6 flex items-center justify-between shadow-[0_0_30px_rgba(6,182,212,0.35)] backdrop-blur-xl">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-cyan-400 text-slate-950 flex items-center justify-center font-black shadow-md">
                        <i class="ph-fill ph-lightning text-xl text-yellow-400"></i>
                    </div>
                    <div class="text-xs sm:text-sm font-black text-cyan-200 tracking-wider flex items-center gap-2">
                        <span>⚡ GAME POINT</span>
                        <span class="text-white text-xs font-bold">FOR <span x-text="gamePointLeader() === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>'"></span></span>
                    </div>
                </div>
                <div>
                    <span class="px-3 py-1 rounded-xl bg-cyan-400 text-[#0a1630] text-[10px] font-black uppercase tracking-widest shadow-md"
                          x-text="bestOf === 1 ? 'Match Decider' : ('Set ' + (games_a + games_b + 1) + ' Decider')">
                    </span>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- PLAYER A ARENA (Luminous Sapphire & Cyan Court)              -->
        <!-- ============================================================ -->
        <section class="flex flex-col justify-between bg-gradient-to-b from-[#18356c]/90 via-[#122854]/90 to-[#0c1d42]/95 backdrop-blur-xl border-2 border-cyan-400/40 rounded-3xl p-4 sm:p-6 lg:p-8 shadow-2xl relative overflow-hidden group">
            
            <!-- Ambient Blue Glow -->
            <div class="absolute -top-24 -left-24 w-72 h-72 bg-cyan-400/15 rounded-full blur-3xl pointer-events-none group-hover:bg-cyan-400/25 transition-all"></div>

            <!-- Player A Header Capsule -->
            <div class="relative z-10 bg-white/10 backdrop-blur-md rounded-2xl p-3.5 sm:p-4 border border-white/20 flex items-center justify-between shadow-md">
                <div class="flex items-center gap-3 min-w-0 pr-2">
                    <!-- Avatar -->
                    <div class="relative flex-shrink-0">
                        <?php if (!empty($match['pa_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($match['pa_photo']) ?>" alt="Photo" class="w-12 h-12 rounded-2xl object-cover border-2 border-cyan-400 shadow-md">
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-700 text-white font-black text-lg flex items-center justify-center border-2 border-cyan-300 shadow-md">
                                <?= strtoupper(substr($pA_display, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Serving Indicator Badge -->
                        <button type="button" 
                                @click="server = 'A'"
                                :class="server === 'A' ? 'opacity-100 scale-100' : 'opacity-0 scale-75'"
                                class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-cyan-400 text-black flex items-center justify-center text-[10px] font-black shadow-lg transition-all"
                                title="Serving">
                            <i class="ph-fill ph-check"></i>
                        </button>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-base sm:text-xl font-black font-display text-white truncate tracking-tight"><?= e($pA_display) ?></h2>
                            <button type="button" 
                                    @click="server = 'A'" 
                                    :class="server === 'A' ? 'bg-cyan-400 text-[#0a1630] font-black border-cyan-300' : 'bg-white/10 text-cyan-200 border-white/20 hover:bg-white/20'"
                                    class="px-2.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border transition flex items-center gap-1 cursor-pointer">
                                <i class="ph-fill ph-lightning text-xs"></i>
                                <span x-text="server === 'A' ? 'SERVING' : 'SET SERVE'"></span>
                            </button>
                        </div>

                        <div class="text-[11px] text-slate-300 font-medium flex items-center gap-1.5 mt-0.5">
                            <span class="text-cyan-300 font-bold"><?= e($match['pa_mohallah'] ?: 'TKMI') ?></span>
                            <?php if (!empty($match['pa_its'])): ?>
                                <span>&bull;</span>
                                <span class="font-mono text-slate-300">ITS: <?= e($match['pa_its']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sets Won LED Lamps -->
                <div class="flex flex-col items-end flex-shrink-0">
                    <span class="text-[9px] font-black uppercase tracking-widest text-cyan-300 mb-1">SETS WON</span>
                    <div class="flex items-center gap-1.5 bg-black/40 px-2.5 py-1.5 rounded-xl border border-white/20 shadow-inner">
                        <?php for($i=0; $i<$gamesToWin; $i++): ?>
                            <div class="w-3.5 h-3.5 rounded-full border transition-all duration-300 flex items-center justify-center" 
                                 :class="games_a > <?= $i ?> ? 'bg-cyan-400 border-cyan-200 shadow-[0_0_12px_rgba(6,182,212,1)] scale-110' : 'border-slate-600 bg-slate-800/60'">
                                <div class="w-1.5 h-1.5 rounded-full bg-white" x-show="games_a > <?= $i ?>"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Giant Luminous Score Digit & Tap Target -->
            <div class="my-auto flex flex-col items-center justify-center py-6 sm:py-10 relative z-10">
                <button type="button" 
                        @click="addPoint('A')"
                        class="tap-target group/btn w-full flex flex-col items-center justify-center p-4 sm:p-6 rounded-3xl hover:bg-cyan-500/10 transition-all duration-200 active:scale-95 cursor-pointer relative"
                        title="Click or press [A] to add point for <?= e($pA_display) ?>">
                    
                    <!-- Glow Aura Behind Score -->
                    <div class="absolute inset-0 bg-cyan-400/15 rounded-full blur-3xl opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none"></div>

                    <div id="scoreDigitA" 
                         class="text-[6.5rem] sm:text-[8.5rem] lg:text-[10.5rem] font-black font-display leading-none tracking-tighter text-white drop-shadow-[0_0_35px_rgba(6,182,212,0.6)] transition-transform duration-200"
                         x-text="score_a">
                        0
                    </div>

                    <!-- Massive Glowing Power Trigger -->
                    <div class="mt-4 w-full max-w-xs sm:max-w-sm py-4 px-6 rounded-2xl bg-gradient-to-r from-cyan-400 via-blue-500 to-indigo-600 hover:from-cyan-300 hover:to-blue-400 text-white font-black text-sm uppercase tracking-widest shadow-[0_10px_35px_rgba(6,182,212,0.5)] border border-cyan-200/50 group-hover/btn:scale-105 transition-all flex items-center justify-center gap-2">
                        <i class="ph-bold ph-plus-circle text-xl"></i>
                        <span>+ POINT A</span>
                        <kbd class="hidden lg:inline px-1.5 py-0.5 rounded bg-black/30 text-[9px] font-mono border border-white/20 ml-1">A</kbd>
                    </div>
                </button>
            </div>

            <!-- Player A Footer: Quick Undo & Micro-actions -->
            <div class="relative z-10 flex items-center justify-between pt-3 border-t border-white/15">
                <button type="button" 
                        @click="undoPoint('A')" 
                        :disabled="score_a === 0 || isProcessing"
                        :class="score_a === 0 ? 'opacity-30 cursor-not-allowed' : 'hover:bg-red-500/30 hover:text-white active:scale-95 cursor-pointer'"
                        class="px-4 py-2 rounded-xl bg-white/10 border border-white/20 text-slate-200 text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
                    <i class="ph-bold ph-arrow-counter-clockwise"></i>
                    <span>Undo Point</span>
                    <kbd class="hidden lg:inline px-1 py-0.2 rounded bg-black/40 text-[9px] font-mono text-slate-300">Z</kbd>
                </button>

                <div class="text-[10px] font-mono text-cyan-300 font-bold">
                    Court A &bull; Blue Side
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- PLAYER B ARENA (Luminous Royal Amber & Gold Court)           -->
        <!-- ============================================================ -->
        <section class="flex flex-col justify-between bg-gradient-to-b from-[#382713]/90 via-[#291c0c]/90 to-[#1c1307]/95 backdrop-blur-xl border-2 border-[#c9a84c]/50 rounded-3xl p-4 sm:p-6 lg:p-8 shadow-2xl relative overflow-hidden group">
            
            <!-- Ambient Gold Glow -->
            <div class="absolute -top-24 -right-24 w-72 h-72 bg-[#c9a84c]/15 rounded-full blur-3xl pointer-events-none group-hover:bg-[#c9a84c]/25 transition-all"></div>

            <!-- Player B Header Capsule -->
            <div class="relative z-10 bg-white/10 backdrop-blur-md rounded-2xl p-3.5 sm:p-4 border border-white/20 flex items-center justify-between shadow-md">
                <div class="flex items-center gap-3 min-w-0 pr-2">
                    <!-- Avatar -->
                    <div class="relative flex-shrink-0">
                        <?php if (!empty($match['pb_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($match['pb_photo']) ?>" alt="Photo" class="w-12 h-12 rounded-2xl object-cover border-2 border-[#c9a84c] shadow-md">
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-[#c9a84c] to-amber-700 text-[#080e1e] font-black text-lg flex items-center justify-center border-2 border-[#ffd978] shadow-md">
                                <?= strtoupper(substr($pB_display, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Serving Indicator Badge -->
                        <button type="button" 
                                @click="server = 'B'"
                                :class="server === 'B' ? 'opacity-100 scale-100' : 'opacity-0 scale-75'"
                                class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-[#c9a84c] text-black flex items-center justify-center text-[10px] font-black shadow-lg transition-all"
                                title="Serving">
                            <i class="ph-fill ph-check"></i>
                        </button>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-base sm:text-xl font-black font-display text-white truncate tracking-tight"><?= e($pB_display) ?></h2>
                            <button type="button" 
                                    @click="server = 'B'" 
                                    :class="server === 'B' ? 'bg-[#c9a84c] text-[#080e1e] font-black border-amber-300' : 'bg-white/10 text-amber-200 border-white/20 hover:bg-white/20'"
                                    class="px-2.5 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border transition flex items-center gap-1 cursor-pointer">
                                <i class="ph-fill ph-lightning text-xs"></i>
                                <span x-text="server === 'B' ? 'SERVING' : 'SET SERVE'"></span>
                            </button>
                        </div>

                        <div class="text-[11px] text-slate-300 font-medium flex items-center gap-1.5 mt-0.5">
                            <span class="text-[#c9a84c] font-bold"><?= e($match['pb_mohallah'] ?: 'TKMI') ?></span>
                            <?php if (!empty($match['pb_its'])): ?>
                                <span>&bull;</span>
                                <span class="font-mono text-slate-300">ITS: <?= e($match['pb_its']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sets Won LED Lamps -->
                <div class="flex flex-col items-end flex-shrink-0">
                    <span class="text-[9px] font-black uppercase tracking-widest text-[#c9a84c] mb-1">SETS WON</span>
                    <div class="flex items-center gap-1.5 bg-black/40 px-2.5 py-1.5 rounded-xl border border-white/20 shadow-inner">
                        <?php for($i=0; $i<$gamesToWin; $i++): ?>
                            <div class="w-3.5 h-3.5 rounded-full border transition-all duration-300 flex items-center justify-center" 
                                 :class="games_b > <?= $i ?> ? 'bg-[#c9a84c] border-amber-200 shadow-[0_0_12px_rgba(201,168,76,1)] scale-110' : 'border-slate-600 bg-slate-800/60'">
                                <div class="w-1.5 h-1.5 rounded-full bg-white" x-show="games_b > <?= $i ?>"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Giant Luminous Score Digit & Tap Target -->
            <div class="my-auto flex flex-col items-center justify-center py-6 sm:py-10 relative z-10">
                <button type="button" 
                        @click="addPoint('B')"
                        class="tap-target group/btn w-full flex flex-col items-center justify-center p-4 sm:p-6 rounded-3xl hover:bg-amber-500/10 transition-all duration-200 active:scale-95 cursor-pointer relative"
                        title="Click or press [L] to add point for <?= e($pB_display) ?>">
                    
                    <!-- Glow Aura Behind Score -->
                    <div class="absolute inset-0 bg-[#c9a84c]/15 rounded-full blur-3xl opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none"></div>

                    <div id="scoreDigitB" 
                         class="text-[6.5rem] sm:text-[8.5rem] lg:text-[10.5rem] font-black font-display leading-none tracking-tighter text-white drop-shadow-[0_0_35px_rgba(201,168,76,0.6)] transition-transform duration-200"
                         x-text="score_b">
                        0
                    </div>

                    <!-- Massive Glowing Power Trigger -->
                    <div class="mt-4 w-full max-w-xs sm:max-w-sm py-4 px-6 rounded-2xl bg-gradient-to-r from-amber-400 via-[#c9a84c] to-yellow-500 hover:from-amber-300 hover:to-yellow-400 text-[#080e1e] font-black text-sm uppercase tracking-widest shadow-[0_10px_35px_rgba(201,168,76,0.5)] border border-amber-200/60 group-hover/btn:scale-105 transition-all flex items-center justify-center gap-2">
                        <i class="ph-bold ph-plus-circle text-xl"></i>
                        <span>+ POINT B</span>
                        <kbd class="hidden lg:inline px-1.5 py-0.5 rounded bg-black/20 text-[9px] font-mono border border-black/20 ml-1">L</kbd>
                    </div>
                </button>
            </div>

            <!-- Player B Footer: Quick Undo & Micro-actions -->
            <div class="relative z-10 flex items-center justify-between pt-3 border-t border-white/15">
                <button type="button" 
                        @click="undoPoint('B')" 
                        :disabled="score_b === 0 || isProcessing"
                        :class="score_b === 0 ? 'opacity-30 cursor-not-allowed' : 'hover:bg-red-500/30 hover:text-white active:scale-95 cursor-pointer'"
                        class="px-4 py-2 rounded-xl bg-white/10 border border-white/20 text-slate-200 text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
                    <i class="ph-bold ph-arrow-counter-clockwise"></i>
                    <span>Undo Point</span>
                    <kbd class="hidden lg:inline px-1 py-0.2 rounded bg-black/40 text-[9px] font-mono text-slate-300">M</kbd>
                </button>

                <div class="text-[10px] font-mono text-[#c9a84c] font-bold">
                    Court B &bull; Gold Side
                </div>
            </div>
        </section>

    </main>

    <!-- ============================================================ -->
    <!-- EPIC CHAMPIONSHIP MATCH COMPLETED / VICTORY OVERLAY          -->
    <!-- ============================================================ -->
    <div class="flex-1 flex flex-col items-center justify-center p-4 sm:p-8 bg-gradient-to-br from-[#0e214d] via-[#152e69] to-[#0a1733] text-center z-40 relative overflow-y-auto" 
         x-show="isCompleted" 
         style="display: none;">
        
        <!-- Festive Victory Aura & Beams -->
        <div class="absolute -top-20 left-1/2 -translate-x-1/2 w-[600px] h-[600px] bg-gradient-to-b from-[#c9a84c]/25 via-blue-500/20 to-transparent rounded-full blur-[140px] pointer-events-none"></div>

        <div class="max-w-2xl w-full bg-[#0f234f]/95 backdrop-blur-2xl border-2 border-[#c9a84c]/60 rounded-3xl p-6 sm:p-10 shadow-[0_20px_70px_rgba(0,0,0,0.6)] relative z-10 my-auto">
            
            <!-- Floating Crown / Trophy Badge -->
            <div class="relative inline-block mb-3">
                <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-3xl bg-gradient-to-br from-amber-300 via-[#c9a84c] to-amber-700 flex items-center justify-center text-[#080e1e] shadow-[0_0_40px_rgba(201,168,76,0.5)] mx-auto border-2 border-white/40 transform hover:scale-105 transition-transform">
                    <i class="ph-fill ph-trophy text-4xl sm:text-5xl drop-shadow-md"></i>
                </div>
                <div class="absolute -bottom-2.5 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-emerald-500 text-white font-black text-[9px] uppercase tracking-widest shadow-md whitespace-nowrap">
                    ✓ Official Result
                </div>
            </div>

            <!-- Victory Headline -->
            <div class="mt-4 mb-6">
                <div class="inline-flex items-center gap-1.5 text-xs font-mono font-black text-[#c9a84c] uppercase tracking-widest mb-1">
                    <i class="ph-fill ph-crown text-amber-400"></i>
                    <span>MATCH VICTORY</span>
                </div>
                
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black font-display text-white tracking-tight leading-tight">
                    <span x-text="getWinnerSide() === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>'"></span> 
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-[#ffd978] via-[#c9a84c] to-amber-500">Wins!</span>
                </h2>
                
                <p class="text-xs sm:text-sm text-blue-200/80 mt-1 font-medium">
                    Match scores and bracket advances have been locked and broadcast live.
                </p>
            </div>

            <!-- Head-to-Head Final Match Scorecard -->
            <div class="bg-black/30 border border-white/15 rounded-2xl p-4 sm:p-6 mb-8 grid grid-cols-3 items-center gap-2 sm:gap-4 shadow-inner">
                
                <!-- Player A Card -->
                <div class="flex flex-col items-center text-center p-2 rounded-xl"
                     :class="getWinnerSide() === 'A' ? 'bg-cyan-500/10 border border-cyan-400/30' : 'opacity-70'">
                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-700 text-white font-black text-lg sm:text-xl flex items-center justify-center border-2 border-cyan-300 shadow-md mb-2">
                        <?= strtoupper(substr($pA_display, 0, 1)) ?>
                    </div>
                    <div class="text-xs sm:text-sm font-black text-white truncate max-w-full"><?= e($pA_display) ?></div>
                    <div class="text-[10px] text-cyan-300 font-bold"><?= e($match['pa_mohallah'] ?: 'TKMI') ?></div>
                    <div class="mt-2 px-2.5 py-0.5 rounded-full bg-cyan-400/20 text-cyan-200 text-[10px] font-black" x-show="getWinnerSide() === 'A'">🏆 WINNER</div>
                </div>

                <!-- Center Score Pill -->
                <div class="flex flex-col items-center justify-center">
                    <div class="px-4 sm:px-6 py-2.5 sm:py-3.5 rounded-2xl bg-white/10 border border-white/20 shadow-lg backdrop-blur-md">
                        <div class="text-2xl sm:text-4xl font-black font-display text-white tracking-widest" x-text="getDisplaySets()"></div>
                        <div class="text-[8px] sm:text-[9px] font-mono font-bold uppercase tracking-widest text-[#ffd978] mt-0.5">SETS WON</div>
                    </div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mt-2">
                        Match #<?= $match['match_number'] ?> &bull; <?= getRoundLabel($match['round_key']) ?>
                    </div>
                </div>

                <!-- Player B Card -->
                <div class="flex flex-col items-center text-center p-2 rounded-xl"
                     :class="getWinnerSide() === 'B' ? 'bg-amber-500/10 border border-amber-400/30' : 'opacity-70'">
                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-gradient-to-br from-[#c9a84c] to-amber-700 text-[#080e1e] font-black text-lg sm:text-xl flex items-center justify-center border-2 border-[#ffd978] shadow-md mb-2">
                        <?= strtoupper(substr($pB_display, 0, 1)) ?>
                    </div>
                    <div class="text-xs sm:text-sm font-black text-white truncate max-w-full"><?= e($pB_display) ?></div>
                    <div class="text-[10px] text-[#ffd978] font-bold"><?= e($match['pb_mohallah'] ?: 'TKMI') ?></div>
                    <div class="mt-2 px-2.5 py-0.5 rounded-full bg-amber-400/20 text-amber-200 text-[10px] font-black" x-show="getWinnerSide() === 'B'">🏆 WINNER</div>
                </div>

            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="<?= BASE_URL ?>/admin/scoring/index.php?tournament_id=<?= $match['tournament_id'] ?>" 
                   class="bg-gradient-to-r from-amber-400 via-[#c9a84c] to-amber-500 hover:from-amber-300 hover:to-amber-400 text-[#080e1e] font-black py-4 px-8 rounded-2xl shadow-xl hover:shadow-amber-500/30 transition-all flex items-center justify-center gap-2 text-sm uppercase tracking-wider cursor-pointer">
                    <i class="ph-bold ph-arrow-right text-lg"></i>
                    <span>Next Match On Court</span>
                </a>
                <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $match['tournament_id'] ?>&tab=bracket" 
                   target="_blank"
                   class="bg-white/10 hover:bg-white/20 text-white font-bold py-4 px-6 rounded-2xl border border-white/20 transition-all text-sm flex items-center justify-center gap-2 cursor-pointer shadow-md">
                    <i class="ph-bold ph-tree-structure text-lg text-[#c9a84c]"></i>
                    <span>View Tournament Bracket</span>
                </a>
            </div>

        </div>
    </div>

    <!-- ============================================================ -->
    <!-- BOTTOM KEYBOARD SHORTCUTS HINT BAR                           -->
    <!-- ============================================================ -->
    <footer class="bg-[#0b1b3d]/95 backdrop-blur-md border-t border-white/15 px-4 py-2.5 flex items-center justify-between text-[11px] text-slate-300 shrink-0 z-20">
        <div class="flex items-center gap-4 flex-wrap">
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 text-cyan-300 font-mono font-bold">A</kbd> or <kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">←</kbd> Point A</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 text-[#ffd978] font-mono font-bold">L</kbd> or <kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">→</kbd> Point B</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">Z</kbd> Undo A</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">M</kbd> Undo B</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">S</kbd> Switch Serve</span>
        </div>
    </footer>

    <!-- ============================================================ -->
    <!-- MATCH OPTIONS MODAL (Walkover / Retirement)                  -->
    <!-- ============================================================ -->
    <div x-show="showOptions" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 bg-black/85 backdrop-blur-md flex items-center justify-center z-50 p-4" 
         style="display: none;">
        
        <div class="bg-[#10234a] border-2 border-white/25 rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl relative" @click.away="showOptions = false">
            <h3 class="text-xl font-black font-display text-white mb-4 flex items-center gap-2">
                <i class="ph-bold ph-shield-warning text-red-400"></i>
                <span>Match Outcome Override</span>
            </h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Action Type</label>
                    <select x-model="modalAction" class="w-full bg-[#0b1b3d] border border-white/20 text-white rounded-xl p-3 text-sm focus:outline-none focus:border-blue-400 font-bold">
                        <option value="walkover">Walkover (No Show / Rule violation)</option>
                        <option value="retire">Retirement (Injury / Withdrawal)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Who Wins?</label>
                    <select x-model="modalWinner" class="w-full bg-[#0b1b3d] border border-white/20 text-white rounded-xl p-3 text-sm focus:outline-none focus:border-blue-400 font-bold">
                        <option value="">-- Select Winner --</option>
                        <option value="A"><?= e($pA_display) ?></option>
                        <option value="B"><?= e($pB_display) ?></option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Reason</label>
                    <select x-model="modalReason" class="w-full bg-[#0b1b3d] border border-white/20 text-white rounded-xl p-3 text-sm focus:outline-none focus:border-blue-400 font-bold">
                        <option value="">-- Select Reason --</option>
                        <?php foreach (['No Show (Did not report in 5 min)', 'Late Arrival', 'Injury', 'Illness', 'Disqualified (Misconduct)', 'Other'] as $r): ?>
                            <option value="<?= $r ?>"><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Notes (Optional)</label>
                    <input type="text" x-model="modalNotes" class="w-full bg-[#0b1b3d] border border-white/20 rounded-xl p-3 text-sm text-white placeholder-slate-400 focus:outline-none focus:border-blue-400" placeholder="e.g. twisted ankle in set 2">
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/15">
                <button type="button" @click="showOptions = false" class="px-5 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-slate-200 text-xs font-bold transition cursor-pointer">Cancel</button>
                <button type="button" @click="submitOptions()" class="px-6 py-2.5 bg-red-600 hover:bg-red-500 text-white rounded-xl font-black text-xs uppercase tracking-wider transition shadow-lg cursor-pointer" :disabled="!modalWinner || !modalReason" :class="(!modalWinner || !modalReason) ? 'opacity-50 cursor-not-allowed' : ''">
                    Confirm Outcome
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- DYNAMIC MATCH FORMAT & RULES MODAL                           -->
    <!-- ============================================================ -->
    <div x-show="showFormatModal" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 bg-black/85 backdrop-blur-md flex items-center justify-center z-50 p-4" 
         style="display: none;">
        
        <div class="bg-[#10234a] border-2 border-[#c9a84c]/50 rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl relative" @click.away="showFormatModal = false">
            <div class="flex items-center justify-between mb-6 pb-3 border-b border-white/15">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-[#c9a84c] text-[#080e1e] flex items-center justify-center font-black shadow-md">
                        <i class="ph-bold ph-sliders text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-black font-display text-white">Dynamic Match Rules</h3>
                        <div class="text-xs text-blue-200">Configure sets and target points for this game</div>
                    </div>
                </div>
                <button type="button" @click="showFormatModal = false" class="text-slate-400 hover:text-white p-2 rounded-xl bg-white/5 hover:bg-white/10 transition cursor-pointer">
                    <i class="ph-bold ph-x text-lg"></i>
                </button>
            </div>
            
            <div class="space-y-6">
                <!-- 1. Number of Sets (1, 3, 5) -->
                <div>
                    <label class="block text-xs font-black text-slate-300 uppercase tracking-wider mb-2">Match Length (Games)</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" 
                                @click="modalBestOf = 1" 
                                :class="modalBestOf === 1 ? 'bg-[#c9a84c] text-[#080e1e] font-black border-[#ffd978] shadow-lg' : 'bg-[#0b1b3d] text-slate-300 border-white/15 hover:bg-white/10'"
                                class="p-3.5 rounded-2xl border transition text-center flex flex-col items-center gap-1 cursor-pointer">
                            <i class="ph-bold ph-lightning text-lg"></i>
                            <span class="text-xs font-black">1 Single Game</span>
                            <span class="text-[9px] opacity-75">Quick Match</span>
                        </button>
                        <button type="button" 
                                @click="modalBestOf = 3" 
                                :class="modalBestOf === 3 ? 'bg-[#c9a84c] text-[#080e1e] font-black border-[#ffd978] shadow-lg' : 'bg-[#0b1b3d] text-slate-300 border-white/15 hover:bg-white/10'"
                                class="p-3.5 rounded-2xl border transition text-center flex flex-col items-center gap-1 cursor-pointer">
                            <i class="ph-bold ph-trophy text-lg"></i>
                            <span class="text-xs font-black">Best of 3</span>
                            <span class="text-[9px] opacity-75">First to 2 Games</span>
                        </button>
                        <button type="button" 
                                @click="modalBestOf = 5" 
                                :class="modalBestOf === 5 ? 'bg-[#c9a84c] text-[#080e1e] font-black border-[#ffd978] shadow-lg' : 'bg-[#0b1b3d] text-slate-300 border-white/15 hover:bg-white/10'"
                                class="p-3.5 rounded-2xl border transition text-center flex flex-col items-center gap-1 cursor-pointer">
                            <i class="ph-bold ph-crown text-lg"></i>
                            <span class="text-xs font-black">Best of 5</span>
                            <span class="text-[9px] opacity-75">First to 3 Games</span>
                        </button>
                    </div>
                </div>

                <!-- 2. Target Points per Set (11, 15, 21) -->
                <div>
                    <label class="block text-xs font-black text-slate-300 uppercase tracking-wider mb-2">Points Per Game (Target)</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" 
                                @click="modalPoints = 11" 
                                :class="modalPoints === 11 ? 'bg-cyan-500 text-[#080e1e] font-black border-cyan-300 shadow-md' : 'bg-[#0b1b3d] text-slate-300 border-white/15 hover:bg-white/10'"
                                class="py-2.5 rounded-xl border transition text-xs font-bold cursor-pointer">
                            11 Points
                        </button>
                        <button type="button" 
                                @click="modalPoints = 15" 
                                :class="modalPoints === 15 ? 'bg-cyan-500 text-[#080e1e] font-black border-cyan-300 shadow-md' : 'bg-[#0b1b3d] text-slate-300 border-white/15 hover:bg-white/10'"
                                class="py-2.5 rounded-xl border transition text-xs font-bold cursor-pointer">
                            15 Points
                        </button>
                        <button type="button" 
                                @click="modalPoints = 21" 
                                :class="modalPoints === 21 ? 'bg-cyan-500 text-[#080e1e] font-black border-cyan-300 shadow-md' : 'bg-[#0b1b3d] text-slate-300 border-white/15 hover:bg-white/10'"
                                class="py-2.5 rounded-xl border transition text-xs font-bold cursor-pointer">
                            21 Points
                        </button>
                    </div>
                </div>

                <!-- 3. Deuce Rule -->
                <div class="bg-black/30 p-4 rounded-2xl border border-white/15 flex items-center justify-between">
                    <div>
                        <div class="text-xs font-black text-white">Deuce Advantage</div>
                        <div class="text-[10px] text-slate-300 mt-0.5">Win by 2 consecutive points</div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" x-model="modalDeuce" class="sr-only peer">
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#c9a84c]"></div>
                    </label>
                </div>
            </div>

            <div class="mt-8 flex justify-end gap-3 pt-4 border-t border-white/15">
                <button type="button" @click="showFormatModal = false" class="px-5 py-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-slate-200 text-xs font-bold transition cursor-pointer">Cancel</button>
                <button type="button" @click="applyFormatModal()" class="px-6 py-2.5 bg-gradient-to-r from-amber-400 to-[#c9a84c] text-[#080e1e] rounded-xl font-black text-xs uppercase tracking-wider transition shadow-lg cursor-pointer">
                    Apply & Save Rules
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MATCH POINT COMPLETION CONFIRMATION PROMPT MODAL            -->
    <!-- ============================================================ -->
    <div x-show="showConfirmCompleteModal" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 bg-black/85 backdrop-blur-md flex items-center justify-center z-50 p-4" 
         style="display: none;">
        
        <div class="bg-[#10234a] border-2 border-emerald-400/80 rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-[0_0_50px_rgba(16,185,129,0.35)] relative text-center" @click.away="showConfirmCompleteModal = false">
            <!-- Icon -->
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-400 via-teal-500 to-emerald-700 text-slate-950 flex items-center justify-center font-black text-3xl shadow-xl mx-auto mb-4 border border-white/30">
                <i class="ph-fill ph-trophy text-yellow-100"></i>
            </div>

            <h3 class="text-2xl font-black font-display text-white mb-1">
                Match Point Reached!
            </h3>
            <p class="text-xs sm:text-sm text-emerald-200/90 font-medium mb-5">
                Did <strong class="text-white text-base" x-text="getMatchWinnerName()"></strong> win this match?
            </p>

            <!-- Score Summary Card -->
            <div class="bg-black/40 border border-white/15 rounded-2xl p-3.5 mb-6 flex items-center justify-around">
                <div class="text-center">
                    <div class="text-xs text-slate-300 font-bold"><?= e($pA_display) ?></div>
                    <div class="text-2xl font-black text-cyan-300 font-display" x-text="score_a"></div>
                </div>
                <div class="text-xs font-black text-slate-500 uppercase tracking-wider">VS</div>
                <div class="text-center">
                    <div class="text-xs text-slate-300 font-bold"><?= e($pB_display) ?></div>
                    <div class="text-2xl font-black text-yellow-300 font-display" x-text="score_b"></div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button type="button" 
                        @click="showConfirmCompleteModal = false" 
                        class="w-full py-3 px-4 rounded-xl bg-white/10 hover:bg-white/20 text-slate-200 hover:text-white text-xs font-bold transition flex items-center justify-center gap-1.5 cursor-pointer border border-white/15">
                    <i class="ph-bold ph-arrow-u-up-left"></i> No, Keep / Undo
                </button>
                <button type="button" 
                        @click="confirmFinalizeMatch()" 
                        class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-slate-950 text-xs font-black uppercase tracking-wider shadow-lg transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <i class="ph-bold ph-check"></i> Yes, Finalize Match
                </button>
            </div>
        </div>
    </div>

    <!-- Floating Toast Notification -->
    <div x-show="showToast" 
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         class="fixed bottom-14 left-1/2 -translate-x-1/2 z-50 bg-[#0f2044] text-[#c9a84c] px-6 py-3 rounded-2xl shadow-2xl flex items-center gap-3 border border-[#c9a84c]/50 font-black text-sm"
         style="display: none;">
        <i class="ph-bold ph-bell-ringing text-lg"></i>
        <span x-text="toastMsg"></span>
    </div>

    <!-- ============================================================ -->
    <!-- ALPINE ARENA CONTROLLER SCRIPT                               -->
    <!-- ============================================================ -->
    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('scorerArenaApp', () => ({
            score_a: <?= (int)$match['score_a'] ?>,
            score_b: <?= (int)$match['score_b'] ?>,
            games_a: <?= (int)$match['games_a'] ?>,
            games_b: <?= (int)$match['games_b'] ?>,
            isCompleted: <?= in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED]) ? 'true' : 'false' ?>,
            serverWinnerSide: '<?= $serverWinnerSide ?>',
            isProcessing: false,
            server: 'A',

            // Dynamic Match Format State
            bestOf: <?= (int)$match['best_of'] ?>,
            pointsPerGame: <?= (int)$match['points_per_game'] ?>,
            deuceEnabled: <?= $match['deuce_enabled'] ? 'true' : 'false' ?>,
            deuceTrigger: <?= (int)$match['deuce_trigger'] ?>,
            deuceCap: <?= (int)$match['deuce_cap'] ?>,
            gamesNeeded: <?= $gamesToWin ?>,

            // Format Modal Form State
            showFormatModal: false,
            modalBestOf: <?= (int)$match['best_of'] ?>,
            modalPoints: <?= (int)$match['points_per_game'] ?>,
            modalDeuce: <?= $match['deuce_enabled'] ? 'true' : 'false' ?>,

            // Match Point Confirmation Modal State
            showConfirmCompleteModal: false,

            async setFormat(newBestOf, newPoints, newDeuce = null) {
                this.bestOf = newBestOf;
                this.pointsPerGame = newPoints;
                this.gamesNeeded = Math.ceil(newBestOf / 2);
                this.deuceTrigger = newPoints - 1;
                this.deuceCap = newPoints + 5;
                if (newDeuce !== null) this.deuceEnabled = newDeuce;

                try {
                    let fd = new FormData();
                    fd.append('match_id', <?= $matchId ?>);
                    fd.append('action', 'update_rules');
                    fd.append('best_of', this.bestOf);
                    fd.append('points_per_game', this.pointsPerGame);
                    fd.append('deuce_enabled', this.deuceEnabled ? 1 : 0);
                    fd.append('deuce_trigger', this.deuceTrigger);
                    fd.append('deuce_cap', this.deuceCap);
                    fd.append('csrf_token', '<?= $csrf ?>');

                    const response = await fetch('<?= BASE_URL ?>/api/score.php', { method: 'POST', body: fd });
                    const data = await response.json();
                    if (data.success) {
                        this.notify("Format updated: " + (this.bestOf === 1 ? "1 Single Game" : ("Best of " + this.bestOf)) + " (" + this.pointsPerGame + " Pts)");
                    }
                } catch (e) {
                    this.notify("Rules updated for this game.");
                }
            },

            async applyFormatModal() {
                await this.setFormat(this.modalBestOf, this.modalPoints, this.modalDeuce);
                this.showFormatModal = false;
            },

            getWinnerSide() {
                if (this.games_a > this.games_b) return 'A';
                if (this.games_b > this.games_a) return 'B';
                if (this.serverWinnerSide) return this.serverWinnerSide;
                return (this.score_a >= this.score_b) ? 'A' : 'B';
            },

            getMatchWinnerName() {
                const side = (this.score_a > this.score_b) ? 'A' : 'B';
                return side === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>';
            },

            getDisplaySets() {
                if (this.games_a > 0 || this.games_b > 0) {
                    return this.games_a + ' - ' + this.games_b;
                }
                if (this.isCompleted) {
                    return this.getWinnerSide() === 'A' ? (this.gamesNeeded + ' - 0') : ('0 - ' + this.gamesNeeded);
                }
                return '0 - 0';
            },

            get isMatchPointReached() {
                if (this.isCompleted) return false;
                const target = this.pointsPerGame;
                const cap = this.deuceCap;
                const trigger = this.deuceTrigger;
                const sA = this.score_a;
                const sB = this.score_b;

                let won = null;
                if (!this.deuceEnabled) {
                    if (sA >= target) won = 'A';
                    else if (sB >= target) won = 'B';
                } else {
                    const maxS = Math.max(sA, sB);
                    const diff = Math.abs(sA - sB);
                    if (maxS >= cap) won = (sA > sB) ? 'A' : 'B';
                    else if (sA >= trigger && sB >= trigger) {
                        if (diff >= 2) won = (sA > sB) ? 'A' : 'B';
                    } else if (maxS >= target) {
                        won = (sA > sB) ? 'A' : 'B';
                    }
                }

                if (!won) return false;
                const tempA = this.games_a + (won === 'A' ? 1 : 0);
                const tempB = this.games_b + (won === 'B' ? 1 : 0);
                return (tempA >= this.gamesNeeded || tempB >= this.gamesNeeded);
            },

            get isDeuce() {
                return this.deuceEnabled && this.score_a >= this.deuceTrigger && this.score_b >= this.deuceTrigger && !this.isCompleted;
            },

            get isGamePoint() {
                if (this.isCompleted || this.isMatchPointReached) return false;
                const target = this.pointsPerGame;
                const cap = this.deuceCap;
                const trigger = this.deuceTrigger;

                if (this.isDeuce) {
                    const diff = Math.abs(this.score_a - this.score_b);
                    const maxS = Math.max(this.score_a, this.score_b);
                    return diff === 1 || maxS === (cap - 1);
                }
                return (this.score_a === target - 1 && this.score_b < target - 1) ||
                       (this.score_b === target - 1 && this.score_a < target - 1);
            },

            gamePointLeader() {
                return (this.score_a > this.score_b) ? 'A' : 'B';
            },

            async confirmFinalizeMatch() {
                this.showConfirmCompleteModal = false;
                const winnerSide = (this.score_a > this.score_b) ? 'A' : 'B';
                
                try {
                    let fd = new FormData();
                    fd.append('match_id', <?= $matchId ?>);
                    fd.append('action', 'finalize_match');
                    fd.append('winner_side', winnerSide);
                    fd.append('csrf_token', '<?= $csrf ?>');

                    const response = await fetch('<?= BASE_URL ?>/api/score.php', { method: 'POST', body: fd });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.isCompleted = true;
                        this.games_a = data.games_a;
                        this.games_b = data.games_b;
                        this.actionQueue = [];
                        if (typeof fireConfetti === 'function') {
                            fireConfetti('fireworks');
                        }
                    } else {
                        this.notify("Error: " + (data.error || "Failed to finalize match."));
                    }
                } catch (e) {
                    this.notify("Network error while finalizing match.");
                }
            },
            
            // Toast notification
            toastMsg: '',
            showToast: false,
            notify(msg) {
                this.toastMsg = msg;
                this.showToast = true;
                setTimeout(() => { this.showToast = false; }, 3500);
            },
            
            // Modal state
            showOptions: false,
            modalAction: 'walkover',
            modalWinner: '',
            modalReason: '',
            modalNotes: '',

            toggleFullScreen() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(() => {});
                } else {
                    document.exitFullscreen().catch(() => {});
                }
            },

            handleKeyboardShortcuts(e) {
                if (this.isCompleted || this.showOptions) return;
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;

                const key = e.key.toLowerCase();
                if (key === 'a' || e.key === 'ArrowLeft') {
                    this.addPoint('A');
                } else if (key === 'l' || e.key === 'ArrowRight') {
                    this.addPoint('B');
                } else if (key === 'z') {
                    this.undoPoint('A');
                } else if (key === 'm') {
                    this.undoPoint('B');
                } else if (key === 's') {
                    this.server = this.server === 'A' ? 'B' : 'A';
                    this.notify("Serving switched to Player " + this.server);
                } else if (key === 'f') {
                    this.toggleFullScreen();
                }
            },

            // Non-blocking asynchronous queue for instant responsive scoring
            actionQueue: [],
            isQueueRunning: false,

            queueAction(action) {
                this.actionQueue.push(action);
                this.processNextAction();
            },

            async processNextAction() {
                if (this.isQueueRunning || this.actionQueue.length === 0) return;
                this.isQueueRunning = true;
                const nextAction = this.actionQueue.shift();

                try {
                    let fd = new FormData();
                    fd.append('match_id', <?= $matchId ?>);
                    fd.append('action', nextAction);
                    fd.append('csrf_token', '<?= $csrf ?>');

                    const response = await fetch('<?= BASE_URL ?>/api/score.php', {
                        method: 'POST',
                        body: fd
                    });
                    
                    const data = await response.json();
                    if (data.success) {
                        // Always sync state from server
                        this.games_a = data.games_a;
                        this.games_b = data.games_b;

                        if (data.is_completed) {
                            this.isCompleted = true;
                            this.actionQueue = [];
                            if (typeof fireConfetti === 'function') {
                                fireConfetti('fireworks');
                            }
                        } else if (data.match_point_reached) {
                            this.showConfirmCompleteModal = true;
                        }

                        if (this.isCompleted || data.event_type === 'game_win' || this.actionQueue.length === 0) {
                            this.score_a = data.score_a;
                            this.score_b = data.score_b;
                            if (this.isCompleted || data.event_type === 'game_win') {
                                this.actionQueue = []; // Clear queue on game win/match complete
                            }
                        }
                    } else {
                        // If error occurred (e.g. match already completed), sync canonical state
                        if (data.is_completed || (data.error && data.error.includes('completed'))) {
                            this.isCompleted = true;
                            this.actionQueue = [];
                        }
                    }
                } catch (e) {
                    // Fail gracefully in background
                } finally {
                    this.isQueueRunning = false;
                    if (this.actionQueue.length > 0) {
                        this.processNextAction();
                    }
                }
            },

            addPoint(player) {
                if (this.isCompleted) return;
                
                const target = this.pointsPerGame;
                const deuce = this.deuceEnabled;
                const cap = this.deuceCap;
                const trigger = this.deuceTrigger;
                const gamesNeeded = this.gamesNeeded;

                // Zero-Latency Instant UI Increment
                if (player === 'A') {
                    this.score_a++;
                    this.server = 'A';
                    const el = document.getElementById('scoreDigitA');
                    if (el) { el.classList.add('scale-110'); setTimeout(() => el.classList.remove('scale-110'), 140); }
                } else {
                    this.score_b++;
                    this.server = 'B';
                    const el = document.getElementById('scoreDigitB');
                    if (el) { el.classList.add('scale-110'); setTimeout(() => el.classList.remove('scale-110'), 140); }
                }

                // Check Game Win condition immediately on client
                const sA = this.score_a;
                const sB = this.score_b;
                let won = null;

                if (!deuce) {
                    if (sA >= target) won = 'A';
                    else if (sB >= target) won = 'B';
                } else {
                    const maxS = Math.max(sA, sB);
                    const diff = Math.abs(sA - sB);
                    if (maxS >= cap) {
                        won = (sA > sB) ? 'A' : 'B';
                    } else if (sA >= trigger && sB >= trigger) {
                        if (diff >= 2) won = (sA > sB) ? 'A' : 'B';
                    } else if (maxS >= target) {
                        won = (sA > sB) ? 'A' : 'B';
                    }
                }

                if (won) {
                    const tempA = this.games_a + (won === 'A' ? 1 : 0);
                    const tempB = this.games_b + (won === 'B' ? 1 : 0);
                    
                    if (tempA >= gamesNeeded || tempB >= gamesNeeded) {
                        // Deciding match point reached! Ask the scorer for confirmation
                        this.showConfirmCompleteModal = true;
                    } else {
                        // Regular set won in multi-set game -> Advance to next set
                        if (won === 'A') this.games_a++; else this.games_b++;
                        this.notify("Game won by Player " + won + "! Starting next set...");
                        this.score_a = 0;
                        this.score_b = 0;
                    }
                }
                
                this.queueAction('add_' + player.toLowerCase());
            },

            undoPoint(player) {
                if (this.isCompleted) return;
                this.showConfirmCompleteModal = false; // Dismiss prompt on undo
                
                // Instant Undo UI Update
                if (player === 'A' && this.score_a > 0) {
                    this.score_a--;
                    const el = document.getElementById('scoreDigitA');
                    if (el) { el.classList.add('scale-95'); setTimeout(() => el.classList.remove('scale-95'), 140); }
                } else if (player === 'B' && this.score_b > 0) {
                    this.score_b--;
                    const el = document.getElementById('scoreDigitB');
                    if (el) { el.classList.add('scale-95'); setTimeout(() => el.classList.remove('scale-95'), 140); }
                } else {
                    return;
                }

                this.queueAction('undo_' + player.toLowerCase());
            },

            async submitOptions() {
                if (!this.modalWinner || !this.modalReason) return;

                this.isProcessing = true;
                
                try {
                    let fd = new FormData();
                    fd.append('match_id', <?= $matchId ?>);
                    fd.append('action', this.modalAction);
                    fd.append('winner_side', this.modalWinner);
                    fd.append('reason', this.modalReason);
                    fd.append('notes', this.modalNotes);
                    fd.append('csrf_token', '<?= $csrf ?>');

                    const response = await fetch('<?= BASE_URL ?>/api/score.php', { method: 'POST', body: fd });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.isCompleted = true;
                        this.showOptions = false;
                        if (typeof fireConfetti === 'function') {
                            fireConfetti('fireworks');
                        }
                    } else {
                        this.notify("Error: " + (data.error || "Failed to record match outcome."));
                    }
                } catch (e) {
                    this.notify("Network error while finalizing match.");
                } finally {
                    this.isProcessing = false;
                }
            }
        }));
    });
    </script>
</body>
</html>