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
           t.name as tourney_name, t.match_type, t.gender
    FROM matches m
    JOIN tournaments t ON m.tournament_id = t.id
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

// Fetch finished games for this match
$gamesStmt = $pdo->prepare('SELECT game_number, score_a, score_b, winner_side FROM games WHERE match_id = ? ORDER BY game_number ASC');
$gamesStmt->execute([$matchId]);
$completedGames = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

$isDoubles = !empty($match['team_a_id']);
$hasParticipantA = $isDoubles ? !empty($match['team_a_id']) : !empty($match['participant_a_id']);
$hasParticipantB = $isDoubles ? !empty($match['team_b_id']) : !empty($match['participant_b_id']);

if (!$hasParticipantA || !$hasParticipantB) {
    flash_set('scoring', 'This match cannot be scored yet because its participants have not qualified from previous rounds.', 'error');
    header('Location: ' . BASE_URL . '/admin/scoring/index.php?tournament_id=' . $match['tournament_id']);
    exit;
}
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

        <!-- Center: Dynamic Game Status / Deuce Capsule -->
        <div class="flex items-center gap-2">
            <!-- Normal Play Capsule -->
            <div 
                    x-show="!isDeuce" 
                    class="flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-1.5 rounded-full bg-white/10 border border-white/15 text-[11px] sm:text-xs font-bold shadow-inner"
                    title="Match Rules">
                <i class="ph-fill ph-lock-key text-xs text-slate-400"></i>
                <span class="text-slate-200 hidden xs:inline" x-text="bestOf === 1 ? '1 Single Game' : ('Game ' + (games_a + games_b + 1) + '/' + bestOf)"></span>
                <span class="text-slate-200 xs:hidden" x-text="bestOf === 1 ? '1 Game' : ('G' + (games_a + games_b + 1))"></span>
                <span class="text-white/40">&bull;</span>
                <span class="text-[#c9a84c] font-black" x-text="pointsPerGame + 'P'"></span>
                <template x-if="deuceEnabled">
                    <span class="hidden md:inline text-slate-300 font-normal" x-text="'• Deuce @ ' + deuceTrigger"></span>
                </template>
            </div>

            <!-- Flaming Deuce Capsule -->
            <div x-show="isDeuce" 
                 x-cloak
                 class="flex items-center gap-2 px-3.5 sm:px-5 py-1.5 rounded-full bg-gradient-to-r from-red-600 via-amber-500 to-red-600 text-white font-black text-xs uppercase tracking-wider shadow-[0_0_25px_rgba(239,68,68,0.7)] border border-amber-300 animate-pulse">
                <i class="ph-fill ph-fire text-base text-yellow-200"></i>
                <span>DEUCE ACTIVE</span>
            </div>

            <!-- Live Cloud Sync Status Pill -->
            <div class="hidden xs:flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-mono border transition-all duration-200"
                 :class="isSyncing ? 'bg-cyan-500/20 border-cyan-400 text-cyan-200 shadow-[0_0_15px_rgba(6,182,212,0.4)]' : 'bg-white/5 border-white/10 text-emerald-400/90'">
                <span class="w-1.5 h-1.5 rounded-full" :class="isSyncing ? 'bg-cyan-400 animate-ping' : 'bg-emerald-400'"></span>
                <span x-text="isSyncing ? 'SYNCING...' : 'CLOUD SYNCED'"></span>
            </div>
        </div>

        <!-- Right: Quick Options -->
        <div class="flex items-center gap-2">
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
    <main class="flex-1 min-h-0 grid grid-cols-2 gap-2 sm:gap-4 lg:gap-6 p-2 sm:p-4 lg:p-5 relative z-10 overflow-hidden" x-show="!isCompleted">

        <!-- Live Deuce Banner Alert -->
        <div x-show="isDeuce" x-cloak class="col-span-2 -mb-1 sm:-mb-2 z-20">
            <div class="w-full bg-gradient-to-r from-red-950/90 via-amber-950/90 to-red-950/90 border-2 border-amber-400 rounded-xl sm:rounded-2xl py-2 sm:py-3 px-3 sm:px-6 flex items-center justify-between shadow-[0_0_35px_rgba(245,158,11,0.35)] backdrop-blur-xl animate-pulse">
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-xl bg-gradient-to-br from-amber-400 to-red-500 text-slate-950 flex items-center justify-center font-black shadow-lg">
                        <i class="ph-fill ph-fire text-lg sm:text-2xl text-yellow-100"></i>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm font-black text-amber-300 tracking-wider flex items-center gap-2 flex-wrap">
                            <span>🔥 DEUCE IN PLAY</span>
                            <span class="text-white text-xs font-bold" x-show="score_a === score_b">(TIED AT <span x-text="score_a"></span>)</span>
                            <span class="text-cyan-300 text-xs font-bold" x-show="score_a > score_b">(+1 ADV <?= e($pA_display) ?>)</span>
                            <span class="text-yellow-300 text-xs font-bold" x-show="score_b > score_a">(+1 ADV <?= e($pB_display) ?>)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Game Point Banner Alert -->
        <div x-show="!isDeuce && isGamePoint" x-cloak class="col-span-2 -mb-1 sm:-mb-2 z-20">
            <div class="w-full bg-gradient-to-r from-cyan-950/90 via-blue-900/90 to-cyan-950/90 border-2 border-cyan-400 rounded-xl sm:rounded-2xl py-2 sm:py-2.5 px-3 sm:px-6 flex items-center justify-between shadow-[0_0_30px_rgba(6,182,212,0.35)] backdrop-blur-xl">
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-xl bg-cyan-400 text-slate-950 flex items-center justify-center font-black shadow-md">
                        <i class="ph-fill ph-lightning text-lg sm:text-xl text-yellow-400"></i>
                    </div>
                    <div class="text-xs sm:text-sm font-black text-cyan-200 tracking-wider flex items-center gap-2">
                        <span>⚡ GAME POINT</span>
                        <span class="text-white text-xs font-bold truncate">FOR <span x-text="gamePointLeader() === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>'"></span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- PLAYER A ARENA (Luminous Sapphire & Cyan Court)              -->
        <!-- ============================================================ -->
        <section class="flex flex-col justify-between bg-gradient-to-b from-[#18356c]/90 via-[#122854]/90 to-[#0c1d42]/95 backdrop-blur-xl border-2 border-cyan-400/40 rounded-2xl sm:rounded-3xl p-2.5 sm:p-4 lg:p-5 shadow-2xl relative overflow-hidden group min-h-0">
            
            <!-- Ambient Blue Glow -->
            <div class="absolute -top-24 -left-24 w-72 h-72 bg-cyan-400/15 rounded-full blur-3xl pointer-events-none group-hover:bg-cyan-400/25 transition-all"></div>

            <!-- Player A Header Capsule -->
            <div class="relative z-10 bg-white/10 backdrop-blur-md rounded-xl sm:rounded-2xl p-2 sm:p-3 border border-white/20 flex items-center justify-between shadow-md">
                <div class="flex items-center gap-2 sm:gap-3 min-w-0 pr-1 sm:pr-2">
                    <!-- Avatar -->
                    <div class="relative flex-shrink-0">
                        <?php if (!empty($match['pa_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($match['pa_photo']) ?>" alt="Photo" class="w-8 h-8 sm:w-11 sm:h-11 rounded-xl sm:rounded-2xl object-cover border-2 border-cyan-400 shadow-md">
                        <?php else: ?>
                            <div class="w-8 h-8 sm:w-11 sm:h-11 rounded-xl sm:rounded-2xl bg-gradient-to-br from-cyan-500 to-blue-700 text-white font-black text-xs sm:text-base flex items-center justify-center border-2 border-cyan-300 shadow-md">
                                <?= strtoupper(substr($pA_display, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Serving Indicator Badge -->
                        <button type="button" 
                                @click="server = 'A'"
                                :class="server === 'A' ? 'opacity-100 scale-100' : 'opacity-0 scale-75'"
                                class="absolute -bottom-1 -right-1 w-4 h-4 sm:w-5 sm:h-5 rounded-full bg-cyan-400 text-black flex items-center justify-center text-[9px] sm:text-[10px] font-black shadow-lg transition-all"
                                title="Serving">
                            <i class="ph-fill ph-check"></i>
                        </button>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <h2 class="text-xs sm:text-base lg:text-lg 2xl:text-xl font-black font-display text-white truncate tracking-tight"><?= e($pA_display) ?></h2>
                            <button type="button" 
                                    @click="server = 'A'" 
                                    :class="server === 'A' ? 'bg-cyan-400 text-[#0a1630] font-black border-cyan-300' : 'bg-white/10 text-cyan-200 border-white/20 hover:bg-white/20'"
                                    class="hidden sm:flex px-2 py-0.5 rounded-full text-[8px] sm:text-[9px] font-bold uppercase tracking-wider border transition items-center gap-1 cursor-pointer">
                                <i class="ph-fill ph-lightning text-xs"></i>
                                <span x-text="server === 'A' ? 'SERVING' : 'GAME SERVE'"></span>
                            </button>
                        </div>

                        <div class="text-[9px] sm:text-[11px] text-slate-300 font-medium flex items-center gap-1 sm:gap-1.5 mt-0.5 truncate">
                            <span class="text-cyan-300 font-bold"><?= e($match['pa_mohallah'] ?: 'TKMI') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Games Won LED Lamps -->
                <div class="flex flex-col items-end flex-shrink-0">
                    <span class="text-[8px] sm:text-[9px] font-black uppercase tracking-widest text-cyan-300 mb-0.5 sm:mb-1">GAMES</span>
                    <div class="flex items-center gap-1 sm:gap-1.5 bg-black/40 px-1.5 sm:px-2.5 py-1 sm:py-1.5 rounded-lg sm:rounded-xl border border-white/20 shadow-inner">
                        <?php for($i=0; $i<$gamesToWin; $i++): ?>
                            <div class="w-2.5 h-2.5 sm:w-3.5 sm:h-3.5 rounded-full border transition-all duration-300 flex items-center justify-center" 
                                 :class="games_a > <?= $i ?> ? 'bg-cyan-400 border-cyan-200 shadow-[0_0_12px_rgba(6,182,212,1)] scale-110' : 'border-slate-600 bg-slate-800/60'">
                                <div class="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full bg-white" x-show="games_a > <?= $i ?>"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Giant Luminous Score Digit & Tap Target -->
            <div class="my-auto flex-1 min-h-0 flex flex-col items-center justify-center py-1 sm:py-2 relative z-10">
                <button type="button" 
                        @click="addPoint('A')"
                        class="tap-target group/btn w-full flex flex-col items-center justify-center p-1 sm:p-3 rounded-2xl sm:rounded-3xl hover:bg-cyan-500/10 transition-all duration-200 active:scale-95 cursor-pointer relative"
                        title="Click or press [A] to add point for <?= e($pA_display) ?>">
                    
                    <!-- Glow Aura Behind Score -->
                    <div class="absolute inset-0 bg-cyan-400/15 rounded-full blur-3xl opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none"></div>

                    <div id="scoreDigitA" 
                         class="text-6xl sm:text-7xl md:text-8xl lg:text-[7.5rem] 2xl:text-[10rem] font-black font-display leading-none tracking-tighter text-white drop-shadow-[0_0_35px_rgba(6,182,212,0.6)] transition-transform duration-200 select-none"
                         x-text="score_a">
                        0
                    </div>

                    <!-- Massive Glowing Power Trigger -->
                    <div class="mt-1.5 sm:mt-3 w-full max-w-xs sm:max-w-sm py-2 sm:py-2.5 lg:py-3 px-3 sm:px-5 rounded-xl sm:rounded-2xl bg-gradient-to-r from-cyan-400 via-blue-500 to-indigo-600 hover:from-cyan-300 hover:to-blue-400 text-white font-black text-xs sm:text-sm uppercase tracking-wider sm:tracking-widest shadow-[0_10px_35px_rgba(6,182,212,0.5)] border border-cyan-200/50 group-hover/btn:scale-105 transition-all flex items-center justify-center gap-1.5 sm:gap-2">
                        <i class="ph-bold ph-plus-circle text-base sm:text-xl"></i>
                        <span>+ POINT A</span>
                        <kbd class="hidden lg:inline px-1.5 py-0.5 rounded bg-black/30 text-[9px] font-mono border border-white/20 ml-1">A</kbd>
                    </div>
                </button>
            </div>

            <!-- Player A Footer: Quick Undo & Micro-actions -->
            <div class="relative z-10 flex items-center justify-between pt-2 border-t border-white/15">
                <button type="button" 
                        @click="undoLast()" 
                        :disabled="score_a === 0 && score_b === 0 && games_a === 0 && games_b === 0"
                        :class="(score_a === 0 && score_b === 0 && games_a === 0 && games_b === 0) ? 'opacity-30 cursor-not-allowed' : 'hover:bg-red-500/30 hover:text-white active:scale-95 cursor-pointer'"
                        class="px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl bg-white/10 border border-white/20 text-slate-200 text-[10px] sm:text-xs font-bold transition flex items-center gap-1 sm:gap-1.5 shadow-sm">
                    <i class="ph-bold ph-arrow-counter-clockwise"></i>
                    <span>Undo</span>
                    <kbd class="hidden lg:inline px-1 py-0.2 rounded bg-black/40 text-[9px] font-mono text-slate-300">Z</kbd>
                </button>

                <div class="text-[9px] sm:text-[10px] font-mono text-cyan-300 font-bold">
                    Court A &bull; Blue Side
                </div>
            </div>
        </section>

        <!-- ============================================================ -->
        <!-- PLAYER B ARENA (Luminous Royal Amber & Gold Court)           -->
        <!-- ============================================================ -->
        <section class="flex flex-col justify-between bg-gradient-to-b from-[#382713]/90 via-[#291c0c]/90 to-[#1c1307]/95 backdrop-blur-xl border-2 border-[#c9a84c]/50 rounded-2xl sm:rounded-3xl p-2.5 sm:p-4 lg:p-5 shadow-2xl relative overflow-hidden group min-h-0">
            
            <!-- Ambient Gold Glow -->
            <div class="absolute -top-24 -right-24 w-72 h-72 bg-[#c9a84c]/15 rounded-full blur-3xl pointer-events-none group-hover:bg-[#c9a84c]/25 transition-all"></div>

            <!-- Player B Header Capsule -->
            <div class="relative z-10 bg-white/10 backdrop-blur-md rounded-xl sm:rounded-2xl p-2 sm:p-3 border border-white/20 flex items-center justify-between shadow-md">
                <div class="flex items-center gap-2 sm:gap-3 min-w-0 pr-1 sm:pr-2">
                    <!-- Avatar -->
                    <div class="relative flex-shrink-0">
                        <?php if (!empty($match['pb_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($match['pb_photo']) ?>" alt="Photo" class="w-8 h-8 sm:w-11 sm:h-11 rounded-xl sm:rounded-2xl object-cover border-2 border-[#c9a84c] shadow-md">
                        <?php else: ?>
                            <div class="w-8 h-8 sm:w-11 sm:h-11 rounded-xl sm:rounded-2xl bg-gradient-to-br from-[#c9a84c] to-amber-700 text-[#080e1e] font-black text-xs sm:text-base flex items-center justify-center border-2 border-[#ffd978] shadow-md">
                                <?= strtoupper(substr($pB_display, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Serving Indicator Badge -->
                        <button type="button" 
                                @click="server = 'B'"
                                :class="server === 'B' ? 'opacity-100 scale-100' : 'opacity-0 scale-75'"
                                class="absolute -bottom-1 -right-1 w-4 h-4 sm:w-5 sm:h-5 rounded-full bg-[#c9a84c] text-black flex items-center justify-center text-[9px] sm:text-[10px] font-black shadow-lg transition-all"
                                title="Serving">
                            <i class="ph-fill ph-check"></i>
                        </button>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <h2 class="text-xs sm:text-base lg:text-lg 2xl:text-xl font-black font-display text-white truncate tracking-tight"><?= e($pB_display) ?></h2>
                            <button type="button" 
                                    @click="server = 'B'" 
                                    :class="server === 'B' ? 'bg-[#c9a84c] text-[#080e1e] font-black border-amber-300' : 'bg-white/10 text-amber-200 border-white/20 hover:bg-white/20'"
                                    class="hidden sm:flex px-2 py-0.5 rounded-full text-[8px] sm:text-[9px] font-bold uppercase tracking-wider border transition items-center gap-1 cursor-pointer">
                                <i class="ph-fill ph-lightning text-xs"></i>
                                <span x-text="server === 'B' ? 'SERVING' : 'GAME SERVE'"></span>
                            </button>
                        </div>

                        <div class="text-[9px] sm:text-[11px] text-slate-300 font-medium flex items-center gap-1 sm:gap-1.5 mt-0.5 truncate">
                            <span class="text-[#c9a84c] font-bold"><?= e($match['pb_mohallah'] ?: 'TKMI') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Games Won LED Lamps -->
                <div class="flex flex-col items-end flex-shrink-0">
                    <span class="text-[8px] sm:text-[9px] font-black uppercase tracking-widest text-[#c9a84c] mb-0.5 sm:mb-1">GAMES</span>
                    <div class="flex items-center gap-1 sm:gap-1.5 bg-black/40 px-1.5 sm:px-2.5 py-1 sm:py-1.5 rounded-lg sm:rounded-xl border border-white/20 shadow-inner">
                        <?php for($i=0; $i<$gamesToWin; $i++): ?>
                            <div class="w-2.5 h-2.5 sm:w-3.5 sm:h-3.5 rounded-full border transition-all duration-300 flex items-center justify-center" 
                                 :class="games_b > <?= $i ?> ? 'bg-[#c9a84c] border-amber-200 shadow-[0_0_12px_rgba(201,168,76,1)] scale-110' : 'border-slate-600 bg-slate-800/60'">
                                <div class="w-1 h-1 sm:w-1.5 sm:h-1.5 rounded-full bg-white" x-show="games_b > <?= $i ?>"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Giant Luminous Score Digit & Tap Target -->
            <div class="my-auto flex-1 min-h-0 flex flex-col items-center justify-center py-1 sm:py-2 relative z-10">
                <button type="button" 
                        @click="addPoint('B')"
                        class="tap-target group/btn w-full flex flex-col items-center justify-center p-1 sm:p-3 rounded-2xl sm:rounded-3xl hover:bg-amber-500/10 transition-all duration-200 active:scale-95 cursor-pointer relative"
                        title="Click or press [L] to add point for <?= e($pB_display) ?>">
                    
                    <!-- Glow Aura Behind Score -->
                    <div class="absolute inset-0 bg-[#c9a84c]/15 rounded-full blur-3xl opacity-0 group-hover/btn:opacity-100 transition-opacity pointer-events-none"></div>

                    <div id="scoreDigitB" 
                         class="text-6xl sm:text-7xl md:text-8xl lg:text-[7.5rem] 2xl:text-[10rem] font-black font-display leading-none tracking-tighter text-white drop-shadow-[0_0_35px_rgba(201,168,76,0.6)] transition-transform duration-200 select-none"
                         x-text="score_b">
                        0
                    </div>

                    <!-- Massive Glowing Power Trigger -->
                    <div class="mt-1.5 sm:mt-3 w-full max-w-xs sm:max-w-sm py-2 sm:py-2.5 lg:py-3 px-3 sm:px-5 rounded-xl sm:rounded-2xl bg-gradient-to-r from-amber-400 via-[#c9a84c] to-yellow-500 hover:from-amber-300 hover:to-yellow-400 text-[#080e1e] font-black text-xs sm:text-sm uppercase tracking-wider sm:tracking-widest shadow-[0_10px_35px_rgba(201,168,76,0.5)] border border-amber-200/60 group-hover/btn:scale-105 transition-all flex items-center justify-center gap-1.5 sm:gap-2">
                        <i class="ph-bold ph-plus-circle text-base sm:text-xl"></i>
                        <span>+ POINT B</span>
                        <kbd class="hidden lg:inline px-1.5 py-0.5 rounded bg-black/20 text-[9px] font-mono border border-black/20 ml-1">L</kbd>
                    </div>
                </button>
            </div>

            <!-- Player B Footer: Quick Undo & Micro-actions -->
            <div class="relative z-10 flex items-center justify-between pt-2 border-t border-white/15">
                <button type="button" 
                        @click="undoLast()" 
                        :disabled="score_a === 0 && score_b === 0 && games_a === 0 && games_b === 0"
                        :class="(score_a === 0 && score_b === 0 && games_a === 0 && games_b === 0) ? 'opacity-30 cursor-not-allowed' : 'hover:bg-red-500/30 hover:text-white active:scale-95 cursor-pointer'"
                        class="px-2.5 sm:px-4 py-1.5 sm:py-2 rounded-xl bg-white/10 border border-white/20 text-slate-200 text-[10px] sm:text-xs font-bold transition flex items-center gap-1 sm:gap-1.5 shadow-sm">
                    <i class="ph-bold ph-arrow-counter-clockwise"></i>
                    <span>Undo</span>
                    <kbd class="hidden lg:inline px-1 py-0.2 rounded bg-black/40 text-[9px] font-mono text-slate-300">M</kbd>
                </button>

                <div class="text-[9px] sm:text-[10px] font-mono text-[#c9a84c] font-bold">
                    Court B &bull; Gold Side
                </div>
            </div>
        </section>

    </main>

    <!-- ============================================================ -->
    <!-- GAME TRANSITION MODAL (BETWEEN GAMES IN BEST-OF-3/5)         -->
    <!-- ============================================================ -->
    <div x-show="showGameTransitionModal" 
         x-cloak
         class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center z-50 p-4">
        
        <div class="bg-[#0d1f42]/97 backdrop-blur-2xl border border-amber-400/30 rounded-3xl max-w-sm w-full p-6 sm:p-8 shadow-[0_20px_60px_rgba(0,0,0,0.7)] relative text-center">
            
            <!-- Ambient glow -->
            <div class="absolute -top-16 left-1/2 -translate-x-1/2 w-64 h-32 bg-amber-400/15 rounded-full blur-3xl pointer-events-none"></div>

            <!-- Trophy Badge -->
            <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br from-amber-300 via-[#c9a84c] to-amber-600 text-slate-950 flex items-center justify-center mx-auto mb-3 shadow-[0_0_25px_rgba(201,168,76,0.30)] border-2 border-amber-200/50 relative z-10">
                <i class="ph-fill ph-trophy text-2xl sm:text-3xl text-slate-950 drop-shadow"></i>
            </div>

            <!-- Game Number Badge -->
            <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-mono font-bold text-[10px] uppercase tracking-widest mb-3 relative z-10">
                <i class="ph-bold ph-check-circle text-xs"></i>
                <span>Game <span x-text="transitionFinishedGameNum"></span> Complete</span>
            </div>

            <h3 class="text-2xl sm:text-3xl font-black font-display text-white mb-1 relative z-10">
                <span x-text="transitionWinnerName"></span> <span class="text-amber-400">Wins!</span>
            </h3>
            
            <p class="text-xs sm:text-sm text-slate-400 mb-5 font-medium relative z-10">
                Game score: <span class="text-slate-200 font-bold font-mono" x-text="transitionPrevScoreA + ' – ' + transitionPrevScoreB"></span>
            </p>

            <!-- Current Games Standing -->
            <div class="bg-black/35 border border-white/10 rounded-2xl p-4 mb-5 relative z-10">
                <div class="text-[10px] font-mono text-slate-500 uppercase tracking-widest mb-3">Match Standings</div>
                <div class="grid grid-cols-3 items-center gap-2">
                    <!-- Player A -->
                    <div class="flex flex-col items-center">
                        <div class="text-[11px] font-bold text-slate-300 truncate max-w-full mb-1"><?= e($pA_display) ?></div>
                        <div class="text-3xl font-black font-mono"
                             :class="transitionGamesA > transitionGamesB ? 'text-amber-400' : 'text-slate-300'"
                             x-text="transitionGamesA"></div>
                    </div>
                    <!-- Divider -->
                    <div class="text-slate-600 font-bold text-2xl font-mono">–</div>
                    <!-- Player B -->
                    <div class="flex flex-col items-center">
                        <div class="text-[11px] font-bold text-slate-300 truncate max-w-full mb-1"><?= e($pB_display) ?></div>
                        <div class="text-3xl font-black font-mono"
                             :class="transitionGamesB > transitionGamesA ? 'text-amber-400' : 'text-slate-300'"
                             x-text="transitionGamesB"></div>
                    </div>
                </div>
                <div class="text-[10px] text-slate-600 font-mono uppercase tracking-wider mt-2">Games Won</div>
            </div>

            <!-- Start Next Game Button -->
            <button type="button" 
                    @click="startNextGame()" 
                    class="w-full bg-gradient-to-r from-amber-400 via-[#c9a84c] to-amber-500 hover:from-amber-300 hover:to-amber-400 text-slate-950 font-black py-3 px-6 rounded-xl shadow-lg hover:shadow-amber-500/20 transition-all flex items-center justify-center gap-2 text-xs uppercase tracking-wider cursor-pointer relative z-10">
                <i class="ph-bold ph-play text-sm"></i>
                <span>Start Game <span x-text="transitionNextGameNum"></span></span>
                <i class="ph-bold ph-arrow-right text-sm"></i>
            </button>
        </div>
    </div>


    <!-- ============================================================ -->
    <!-- EPIC CHAMPIONSHIP MATCH COMPLETED / VICTORY OVERLAY          -->
    <!-- ============================================================ -->
    <div class="flex-1 flex flex-col items-center justify-center p-4 sm:p-8 bg-gradient-to-br from-[#060e22] via-[#0b1a3d] to-[#040a18] text-center z-40 relative overflow-y-auto" 
         x-show="isCompleted" 
         x-cloak>
        
        <!-- Subtle Ambient Warm Glow -->
        <div class="absolute -top-24 left-1/2 -translate-x-1/2 w-[550px] h-[350px] bg-gradient-to-b from-amber-400/20 via-blue-600/10 to-transparent rounded-full blur-[100px] pointer-events-none"></div>

        <div class="max-w-2xl w-full bg-[#0d1f42]/95 backdrop-blur-2xl border border-amber-400/35 rounded-3xl p-6 sm:p-8 shadow-[0_25px_80px_rgba(0,0,0,0.7)] relative z-10 my-auto">
            
            <!-- Floating Trophy Medallion -->
            <div class="inline-flex flex-col items-center mb-2">
                <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-gradient-to-br from-amber-300 via-[#c9a84c] to-amber-600 flex items-center justify-center text-[#080e1e] shadow-[0_0_35px_rgba(201,168,76,0.35)] border-2 border-amber-200/60 mb-2 transform hover:scale-105 transition-transform">
                    <i class="ph-fill ph-trophy text-3xl sm:text-4xl text-slate-950 drop-shadow"></i>
                </div>
                <div class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[10px] font-black uppercase tracking-widest">
                    <i class="ph-bold ph-check-circle"></i> Official Result
                </div>
            </div>

            <!-- Victory Headline -->
            <div class="mt-2 mb-6">
                <div class="inline-flex items-center gap-1.5 text-[11px] font-mono font-bold text-amber-300/90 uppercase tracking-widest mb-1">
                    <i class="ph-fill ph-crown text-amber-400"></i>
                    <span>Match Victory</span>
                </div>
                
                <h2 class="text-3xl sm:text-4xl font-black font-display text-white tracking-tight leading-tight">
                    <span x-text="getWinnerName()"></span> 
                    <span class="text-amber-400">Wins!</span>
                </h2>
                
                <p class="text-xs sm:text-sm text-slate-300/80 mt-1 font-medium">
                    Scores verified and bracket advances locked.
                </p>
            </div>

            <!-- Head-to-Head Final Match Scorecard -->
            <div class="bg-black/35 border border-white/10 rounded-2xl p-4 sm:p-5 mb-6 grid grid-cols-3 items-center gap-3 sm:gap-4 shadow-inner">
                
                <!-- Player A Card -->
                <div class="rounded-2xl p-3 sm:p-4 flex flex-col items-center text-center transition-all"
                     :class="getWinnerSide() === 'A' 
                        ? 'bg-amber-400/10 border-2 border-amber-400/50 shadow-[0_0_20px_rgba(201,168,76,0.15)] ring-1 ring-amber-400/20' 
                        : 'bg-white/[0.03] border border-white/10 opacity-75'">
                    
                    <!-- Avatar -->
                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full flex items-center justify-center font-black text-lg sm:text-xl border-2 mb-2 shadow-md overflow-hidden"
                         :class="getWinnerSide() === 'A' ? 'border-amber-400 bg-gradient-to-br from-amber-400 to-amber-600 text-slate-950' : 'border-slate-500/40 bg-slate-800 text-slate-200'">
                        <?php if (!empty($match['pa_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($match['pa_photo']) ?>" alt="<?= e($pA_display) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= strtoupper(substr($pA_display, 0, 1)) ?>
                        <?php endif; ?>
                    </div>

                    <div class="text-xs sm:text-sm font-black text-white truncate max-w-full"><?= e($pA_display) ?></div>
                    <div class="text-[10px] text-slate-400 font-medium truncate max-w-full"><?= e($match['pa_mohallah'] ?: 'TKMI') ?></div>
                    
                    <div class="mt-2" x-show="getWinnerSide() === 'A'">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-400/25 text-amber-200 text-[10px] font-black uppercase tracking-wider border border-amber-400/30">
                            <i class="ph-fill ph-crown text-xs"></i> Winner
                        </span>
                    </div>
                    <div class="mt-2" x-show="getWinnerSide() !== 'A'">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white/5 text-slate-400 text-[10px] font-semibold uppercase tracking-wider border border-white/5">
                            Runner-Up
                        </span>
                    </div>
                </div>

                <!-- Center Score Pill -->
                <div class="flex flex-col items-center justify-center">
                    <div class="w-full py-3 sm:py-3.5 rounded-2xl bg-black/50 border border-white/10 shadow-lg backdrop-blur-md">
                        <div class="text-2xl sm:text-4xl font-black font-mono text-white tracking-widest" x-text="getDisplayGames()"></div>
                        <div class="text-[9px] font-bold uppercase tracking-widest text-amber-300/90 mt-0.5">Games Won</div>
                        <div class="mt-1.5 text-[11px] font-mono text-slate-300 font-semibold" x-show="getSetsBreakdown()" x-text="getSetsBreakdown()"></div>
                    </div>
                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider mt-2.5">
                        Match #<?= $match['match_number'] ?> &bull; <?= getRoundLabel($match['round_key']) ?>
                    </div>
                </div>

                <!-- Player B Card -->
                <div class="rounded-2xl p-3 sm:p-4 flex flex-col items-center text-center transition-all"
                     :class="getWinnerSide() === 'B' 
                        ? 'bg-amber-400/10 border-2 border-amber-400/50 shadow-[0_0_20px_rgba(201,168,76,0.15)] ring-1 ring-amber-400/20' 
                        : 'bg-white/[0.03] border border-white/10 opacity-75'">
                    
                    <!-- Avatar -->
                    <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-full flex items-center justify-center font-black text-lg sm:text-xl border-2 mb-2 shadow-md overflow-hidden"
                         :class="getWinnerSide() === 'B' ? 'border-amber-400 bg-gradient-to-br from-amber-400 to-amber-600 text-slate-950' : 'border-slate-500/40 bg-slate-800 text-slate-200'">
                        <?php if (!empty($match['pb_photo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/players/<?= e($match['pb_photo']) ?>" alt="<?= e($pB_display) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= strtoupper(substr($pB_display, 0, 1)) ?>
                        <?php endif; ?>
                    </div>

                    <div class="text-xs sm:text-sm font-black text-white truncate max-w-full"><?= e($pB_display) ?></div>
                    <div class="text-[10px] text-slate-400 font-medium truncate max-w-full"><?= e($match['pb_mohallah'] ?: 'TKMI') ?></div>
                    
                    <div class="mt-2" x-show="getWinnerSide() === 'B'">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-400/25 text-amber-200 text-[10px] font-black uppercase tracking-wider border border-amber-400/30">
                            <i class="ph-fill ph-crown text-xs"></i> Winner
                        </span>
                    </div>
                    <div class="mt-2" x-show="getWinnerSide() !== 'B'">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white/5 text-slate-400 text-[10px] font-semibold uppercase tracking-wider border border-white/5">
                            Runner-Up
                        </span>
                    </div>
                </div>

            </div>

            <!-- Action Buttons -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                <a href="<?= BASE_URL ?>/admin/scoring/index.php?tournament_id=<?= $match['tournament_id'] ?>" 
                   class="bg-gradient-to-r from-amber-400 via-[#c9a84c] to-amber-500 hover:from-amber-300 hover:to-amber-400 text-slate-950 font-black py-3 px-4 rounded-xl shadow-lg hover:shadow-amber-500/20 transition-all flex items-center justify-center gap-2 text-xs uppercase tracking-wider cursor-pointer">
                    <i class="ph-bold ph-calendar-check text-base"></i>
                    <span>Tournament Hub</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/tournaments/view.php?id=<?= $match['tournament_id'] ?>" 
                   class="bg-white/10 hover:bg-white/15 text-white font-bold py-3 px-4 rounded-xl border border-white/15 transition-all text-xs flex items-center justify-center gap-2 cursor-pointer shadow-sm">
                    <i class="ph-bold ph-chart-line text-base text-amber-400"></i>
                    <span>Dashboard</span>
                </a>
                <a href="<?= BASE_URL ?>/public/tournament.php?id=<?= $match['tournament_id'] ?>&tab=bracket" 
                   target="_blank"
                   class="bg-white/10 hover:bg-white/15 text-white font-bold py-3 px-4 rounded-xl border border-white/15 transition-all text-xs flex items-center justify-center gap-2 cursor-pointer shadow-sm">
                    <i class="ph-bold ph-tree-structure text-base text-cyan-400"></i>
                    <span>Public Bracket</span>
                </a>
            </div>

        </div>
    </div>

    <!-- ============================================================ -->
    <!-- BOTTOM KEYBOARD SHORTCUTS HINT BAR                           -->
    <!-- ============================================================ -->
    <footer class="bg-[#0b1b3d]/95 backdrop-blur-md border-t border-white/15 px-4 py-2.5 flex items-center justify-between text-[11px] text-slate-300 shrink-0 z-20 gap-4">
        <div class="flex items-center gap-4 flex-wrap">
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 text-cyan-300 font-mono font-bold">A</kbd> or <kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">←</kbd> Point A</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 text-[#ffd978] font-mono font-bold">L</kbd> or <kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">→</kbd> Point B</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">Z</kbd> Undo A</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">M</kbd> Undo B</span>
            <span class="flex items-center gap-1"><kbd class="px-1.5 py-0.5 rounded bg-white/15 border border-white/20 font-mono text-white">S</kbd> Switch Serve</span>
        </div>

        <?php if (!empty($sponsors)): ?>
            <div class="hidden md:flex items-center gap-2 pl-4 border-l border-white/15 shrink-0">
                <span class="text-[9px] font-mono uppercase tracking-widest text-[#ffd978]/90 font-bold">Sponsors:</span>
                <div class="flex items-center gap-2">
                    <?php foreach ($sponsors as $sp): ?>
                        <div class="bg-white/95 rounded px-1.5 py-0.5 shadow-sm border border-white/30 flex items-center" title="<?= e($sp['name']) ?>">
                            <img src="<?= UPLOAD_SPONSORS_URL ?>/<?= e($sp['image_path']) ?>" 
                                 alt="<?= e($sp['name']) ?>" 
                                 class="h-4.5 max-h-5 w-auto max-w-[65px] object-contain">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
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
                    <input type="text" x-model="modalNotes" class="w-full bg-[#0b1b3d] border border-white/20 rounded-xl p-3 text-sm text-white placeholder-slate-400 focus:outline-none focus:border-blue-400" placeholder="e.g. twisted ankle in game 2">
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
            server: 'A', // Informational only

            bestOf: <?= (int)$match['best_of'] ?>,
            pointsPerGame: <?= (int)$match['points_per_game'] ?>,
            deuceEnabled: <?= $match['deuce_enabled'] ? 'true' : 'false' ?>,
            deuceTrigger: <?= (int)$match['deuce_trigger'] ?>,
            deuceCap: <?= (int)$match['deuce_cap'] ?>,
            gamesNeeded: <?= $gamesToWin ?>,

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
            modalAction: 'declare_walkover',
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
                } else if (key === 'z' || key === 'm' || key === 'u') {
                    this.undoLast();
                } else if (key === 's') {
                    this.server = this.server === 'A' ? 'B' : 'A';
                    this.notify("Serving switched to Player " + this.server);
                } else if (key === 'f') {
                    this.toggleFullScreen();
                }
            },

            // Live Cloud Queue & Sync State
            isSyncing: false,
            actionQueue: [],
            isQueueRunning: false,
            lastTapTime: 0,

            // Game Transition Modal State (Between Games)
            showGameTransitionModal: false,
            transitionWinnerName: '',
            transitionGameWonBy: '',
            transitionPrevScoreA: 0,
            transitionPrevScoreB: 0,
            transitionFinishedGameNum: 1,
            transitionNextGameNum: 2,
            transitionGamesA: <?= (int)$match['games_a'] ?>,
            transitionGamesB: <?= (int)$match['games_b'] ?>,
            completedGames: <?= json_encode($completedGames ?: []) ?>,

            triggerGameWon(wonBy, scoreA, scoreB, finishedGameNum, nextGamesA, nextGamesB) {
                this.transitionGameWonBy = wonBy;
                this.transitionWinnerName = (wonBy === 'A') ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>';
                this.transitionPrevScoreA = scoreA;
                this.transitionPrevScoreB = scoreB;
                this.transitionFinishedGameNum = finishedGameNum;
                this.transitionNextGameNum = finishedGameNum + 1;
                this.transitionGamesA = nextGamesA !== undefined ? nextGamesA : this.games_a;
                this.transitionGamesB = nextGamesB !== undefined ? nextGamesB : this.games_b;
                
                // Track finished game score
                const exists = this.completedGames.some(g => g.game_number === finishedGameNum);
                if (!exists) {
                    this.completedGames.push({ game_number: finishedGameNum, score_a: scoreA, score_b: scoreB, winner_side: wonBy });
                }

                this.showGameTransitionModal = true;
                if (typeof fireConfetti === 'function') {
                    try { fireConfetti('stars'); } catch(e) {}
                }
            },

            startNextGame() {
                this.showGameTransitionModal = false;
                this.score_a = 0;
                this.score_b = 0;
                if (this.transitionGamesA !== undefined) this.games_a = Math.max(this.games_a, this.transitionGamesA);
                if (this.transitionGamesB !== undefined) this.games_b = Math.max(this.games_b, this.transitionGamesB);
                this.notify("Starting Game " + (this.games_a + this.games_b + 1) + " of " + this.bestOf);
            },

            checkWinCondition(scoreA, scoreB) {
                const target = this.pointsPerGame;
                const deuce = this.deuceEnabled;
                const trigger = this.deuceTrigger;
                const cap = this.deuceCap;

                if (!deuce) {
                    if (scoreA >= target) return 'A';
                    if (scoreB >= target) return 'B';
                    return null;
                }

                const maxS = Math.max(scoreA, scoreB);
                const diff = Math.abs(scoreA - scoreB);
                const leader = (scoreA > scoreB) ? 'A' : 'B';

                if (maxS < target) return null;
                if (maxS >= cap) return leader;
                if (scoreA >= trigger && scoreB >= trigger) {
                    if (diff >= 2) return leader;
                    return null;
                }
                if (maxS >= target) return leader;
                return null;
            },

            generateUUID() {
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
            },

            addPoint(player) {
                if (this.isCompleted) return;

                // Micro-throttle: ignore accidental capacitive multi-touch bounce (<130ms)
                const now = Date.now();
                if (now - this.lastTapTime < 130) return;
                this.lastTapTime = now;

                // 1. Instant Zero-Latency Optimistic UI Increment
                if (player === 'A') {
                    this.score_a++;
                    const el = document.getElementById('scoreDigitA');
                    if (el) {
                        el.classList.remove('scale-110');
                        void el.offsetWidth;
                        el.classList.add('scale-110');
                        setTimeout(() => el.classList.remove('scale-110'), 140);
                    }
                } else {
                    this.score_b++;
                    const el = document.getElementById('scoreDigitB');
                    if (el) {
                        el.classList.remove('scale-110');
                        void el.offsetWidth;
                        el.classList.add('scale-110');
                        setTimeout(() => el.classList.remove('scale-110'), 140);
                    }
                }

                // 2. Check Win Condition Optimistically
                const gameWinner = this.checkWinCondition(this.score_a, this.score_b);
                if (gameWinner) {
                    const tempGamesA = this.games_a + (gameWinner === 'A' ? 1 : 0);
                    const tempGamesB = this.games_b + (gameWinner === 'B' ? 1 : 0);

                    if (tempGamesA >= this.gamesNeeded || tempGamesB >= this.gamesNeeded) {
                        // MATCH COMPLETE!
                        this.games_a = tempGamesA;
                        this.games_b = tempGamesB;
                        this.isCompleted = true;
                        this.serverWinnerSide = gameWinner;
                        
                        const finalGameNum = this.games_a + this.games_b;
                        const exists = this.completedGames.some(g => g.game_number === finalGameNum);
                        if (!exists) {
                            this.completedGames.push({
                                game_number: finalGameNum,
                                score_a: this.score_a,
                                score_b: this.score_b,
                                winner_side: gameWinner
                            });
                        }

                        this.notify("🏆 Match Won by " + (gameWinner === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>') + "!");
                        if (typeof fireConfetti === 'function') {
                            try { fireConfetti('fireworks'); } catch(e) {}
                        }
                    } else {
                        // GAME COMPLETE (Transition to Next Game)!
                        const sA = this.score_a;
                        const sB = this.score_b;
                        const finishedGameNum = this.games_a + this.games_b + 1;
                        this.games_a = tempGamesA;
                        this.games_b = tempGamesB;
                        this.triggerGameWon(gameWinner, sA, sB, finishedGameNum, tempGamesA, tempGamesB);
                    }
                }

                // 3. Tactile Haptic Buzz on Mobile / Tablet
                if (navigator.vibrate) {
                    try { navigator.vibrate(25); } catch(e) {}
                }

                // 4. Queue Action for Atomic Cloud Persistence
                this.queueAction('add_point', { side: player });
            },

            undoLast() {
                // If game transition modal is open, undo closes it
                if (this.showGameTransitionModal) {
                    this.showGameTransitionModal = false;
                }
                if (this.isCompleted) {
                    this.isCompleted = false;
                }

                const now = Date.now();
                if (now - this.lastTapTime < 130) return;
                this.lastTapTime = now;

                // Animate tactile undo
                const elA = document.getElementById('scoreDigitA');
                const elB = document.getElementById('scoreDigitB');
                if (elA) { elA.classList.add('scale-95'); setTimeout(() => elA.classList.remove('scale-95'), 140); }
                if (elB) { elB.classList.add('scale-95'); setTimeout(() => elB.classList.remove('scale-95'), 140); }

                if (navigator.vibrate) {
                    try { navigator.vibrate(15); } catch(e) {}
                }

                this.queueAction('undo_last');
            },

            undoPoint(player) {
                this.undoLast();
            },

            queueAction(actionName, extraData = {}) {
                const reqId = this.generateUUID();
                this.actionQueue.push({ actionName, extraData, reqId });
                this.processQueue();
            },

            async processQueue() {
                if (this.isQueueRunning || this.actionQueue.length === 0) return;
                this.isQueueRunning = true;
                this.isSyncing = true;

                const item = this.actionQueue.shift();

                try {
                    let fd = new FormData();
                    fd.append('match_id', <?= $matchId ?>);
                    fd.append('action', item.actionName);
                    fd.append('request_id', item.reqId);
                    fd.append('csrf_token', '<?= $csrf ?>');

                    for (const [key, value] of Object.entries(item.extraData)) {
                        fd.append(key, value);
                    }

                    const response = await fetch('<?= BASE_URL ?>/api/score.php', { method: 'POST', body: fd });
                    const data = await response.json();

                    if (data.success) {
                        // Match completed -> Finalize immediately
                        if (data.is_completed) {
                            this.isCompleted = true;
                            this.actionQueue = [];
                            this.score_a = data.score_a;
                            this.score_b = data.score_b;
                            this.games_a = data.games_a;
                            this.games_b = data.games_b;
                            this.showGameTransitionModal = false;
                            if (typeof fireConfetti === 'function') {
                                try { fireConfetti('fireworks'); } catch(e) {}
                            }
                            this.notify("🏆 Match Finished! Official Result Confirmed.");
                            return;
                        }

                        // Reconcile games won if game finished on server (prevent in-flight older requests from clobbering higher games count)
                        if (item.actionName === 'undo_last') {
                            this.games_a = data.games_a;
                            this.games_b = data.games_b;
                        } else {
                            this.games_a = Math.max(this.games_a, data.games_a);
                            this.games_b = Math.max(this.games_b, data.games_b);
                        }
                        this.transitionGamesA = this.games_a;
                        this.transitionGamesB = this.games_b;

                        // Reconcile points if queue is empty and modal is closed
                        if (this.actionQueue.length === 0 && !this.showGameTransitionModal) {
                            this.score_a = data.score_a;
                            this.score_b = data.score_b;
                        }
                    } else {
                        // Server rejected action or match already completed
                        if (data.error && data.error.includes("already completed")) {
                            this.isCompleted = true;
                            this.actionQueue = [];
                            return;
                        }
                        this.notify("Error: " + (data.error || "Action failed"));
                        if (data.score_a !== undefined) {
                            this.score_a = data.score_a;
                            this.score_b = data.score_b;
                            this.games_a = data.games_a;
                            this.games_b = data.games_b;
                        }
                    }
                } catch (e) {
                    this.notify("Network issue. Reconnecting...");
                } finally {
                    this.isQueueRunning = false;
                    if (this.actionQueue.length > 0) {
                        this.processQueue();
                    } else {
                        this.isSyncing = false;
                    }
                }
            },

            async submitOptions() {
                if (!this.modalWinner || !this.modalReason) return;
                
                let extra = {
                    reason: this.modalReason,
                    notes: this.modalNotes
                };
                
                if (this.modalAction === 'declare_walkover') {
                    extra.winner_side = this.modalWinner;
                } else if (this.modalAction === 'declare_retirement') {
                    extra.retired_side = (this.modalWinner === 'A') ? 'B' : 'A';
                }

                this.queueAction(this.modalAction, extra);
                this.showOptions = false;
            },

            getWinnerSide() {
                if (this.games_a > this.games_b) return 'A';
                if (this.games_b > this.games_a) return 'B';
                if (this.serverWinnerSide) return this.serverWinnerSide;
                return (this.score_a >= this.score_b) ? 'A' : 'B';
            },

            getWinnerName() {
                return this.getWinnerSide() === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>';
            },

            getRunnerUpName() {
                return this.getWinnerSide() === 'A' ? '<?= e($pB_display) ?>' : '<?= e($pA_display) ?>';
            },

            getSetsBreakdown() {
                if (!this.completedGames || this.completedGames.length === 0) return '';
                return this.completedGames.map(g => `${g.score_a}-${g.score_b}`).join(', ');
            },

            getMatchWinnerName() {
                const side = (this.score_a > this.score_b) ? 'A' : 'B';
                return side === 'A' ? '<?= e($pA_display) ?>' : '<?= e($pB_display) ?>';
            },

            getDisplayGames() {
                if (this.games_a > 0 || this.games_b > 0) {
                    return this.games_a + ' - ' + this.games_b;
                }
                if (this.isCompleted) {
                    return this.getWinnerSide() === 'A' ? (this.gamesNeeded + ' - 0') : ('0 - ' + this.gamesNeeded);
                }
                return '0 - 0';
            },

            get isDeuce() {
                return this.deuceEnabled && this.score_a >= this.deuceTrigger && this.score_b >= this.deuceTrigger && !this.isCompleted;
            },

            get isGamePoint() {
                if (this.isCompleted) return false;
                const target = this.pointsPerGame;
                const cap = this.deuceCap;

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
            }
        }));
    });
    </script>
</body>
</html>
