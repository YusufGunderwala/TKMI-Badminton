<?php
// ============================================================
// TKMI Badminton Tournament Platform — App Constants
// ============================================================

// --- App Identity ---
define('APP_NAME',    'TKMI Badminton Tournament');
define('APP_SHORT',   'TKMI');
define('APP_TAGLINE', 'Toloba ul Kulliyaat il Muminoon');
define('APP_VERSION', '1.0.0');

// --- Base Paths ---
define('BASE_PATH', dirname(__DIR__));

// Detect protocol, host, and subdirectory for dynamic deployment (Local XAMPP vs Railway/Production)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isSubdir = str_contains($_SERVER['REQUEST_URI'] ?? '', '/Badminton');
$defaultBaseUrl = $protocol . '://' . $host . ($isSubdir ? '/Badminton' : '');
define('BASE_URL', getenv('BASE_URL') ?: $defaultBaseUrl);

// --- Upload Directories ---
define('UPLOAD_PATH',          BASE_PATH . '/uploads');
define('UPLOAD_PLAYERS_PATH',  BASE_PATH . '/uploads/players');
define('UPLOAD_SPONSORS_PATH', BASE_PATH . '/uploads/sponsors');
define('UPLOAD_URL',           BASE_URL  . '/uploads');
define('UPLOAD_PLAYERS_URL',   BASE_URL  . '/uploads/players');
define('UPLOAD_SPONSORS_URL',  BASE_URL  . '/uploads/sponsors');

// --- Upload Limits ---
define('MAX_UPLOAD_SIZE',  5 * 1024 * 1024); // 5 MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

// --- Tournament Formats ---
define('FORMAT_SWISS_KNOCKOUT', 'swiss_knockout');
define('FORMAT_ROUND_ROBIN',    'round_robin');

// --- Tournament Statuses ---
define('STATUS_DRAFT',     'draft');
define('STATUS_READY',     'ready');
define('STATUS_LIVE',      'live');
define('STATUS_COMPLETED', 'completed');
define('STATUS_ARCHIVED',  'archived');

// --- Match Statuses ---
define('MATCH_SCHEDULED',   'scheduled');
define('MATCH_LIVE',        'in_progress');
define('MATCH_IN_PROGRESS', 'in_progress');
define('MATCH_COMPLETED',   'completed');
define('MATCH_WALKOVER',    'walkover');
define('MATCH_RETIRED',     'retired');
define('MATCH_CANCELLED',   'cancelled');
define('MATCH_BYE',         'bye');

// --- Round Keys (Swiss + Knockout) ---
define('ROUND_STAGE1_R1',       'r1');
define('ROUND_STAGE1_R2',       'r2');
define('ROUND_STAGE1_SURVIVAL', 'survival');
define('ROUND_R16',             'r16');
define('ROUND_QF',              'qf');
define('ROUND_SF',              'sf');
define('ROUND_3RD_PLACE',       '3rd_place');
define('ROUND_FINAL',           'final');

// --- Default Swiss+Knockout Scoring Rules ---
define('DEFAULT_ROUND_CONFIGS', [
    ROUND_STAGE1_R1       => ['label' => 'Stage 1 – Round 1',       'best_of' => 3, 'points_per_game' => 11, 'deuce_enabled' => 1, 'deuce_trigger' => 10, 'deuce_cap' => 16, 'sort_order' => 1],
    ROUND_STAGE1_R2       => ['label' => 'Stage 1 – Round 2',       'best_of' => 3, 'points_per_game' => 11, 'deuce_enabled' => 1, 'deuce_trigger' => 10, 'deuce_cap' => 16, 'sort_order' => 2],
    ROUND_STAGE1_SURVIVAL => ['label' => 'Stage 1 – Survival Round','best_of' => 3, 'points_per_game' => 11, 'deuce_enabled' => 1, 'deuce_trigger' => 10, 'deuce_cap' => 16, 'sort_order' => 3],
    ROUND_R16             => ['label' => 'Round of 16',              'best_of' => 3, 'points_per_game' => 15, 'deuce_enabled' => 1, 'deuce_trigger' => 14, 'deuce_cap' => 21, 'sort_order' => 4],
    ROUND_QF              => ['label' => 'Quarter Finals',           'best_of' => 3, 'points_per_game' => 15, 'deuce_enabled' => 1, 'deuce_trigger' => 14, 'deuce_cap' => 21, 'sort_order' => 5],
    ROUND_SF              => ['label' => 'Semi Finals',              'best_of' => 3, 'points_per_game' => 15, 'deuce_enabled' => 1, 'deuce_trigger' => 14, 'deuce_cap' => 21, 'sort_order' => 6],
    ROUND_3RD_PLACE       => ['label' => '3rd Place Match',          'best_of' => 3, 'points_per_game' => 15, 'deuce_enabled' => 1, 'deuce_trigger' => 14, 'deuce_cap' => 21, 'sort_order' => 7],
    ROUND_FINAL           => ['label' => 'Final',                    'best_of' => 3, 'points_per_game' => 21, 'deuce_enabled' => 1, 'deuce_trigger' => 20, 'deuce_cap' => 26, 'sort_order' => 8],
]);

// --- Standing Points ---
define('POINTS_WIN',      2);
define('POINTS_LOSS',     0);
define('POINTS_WALKOVER', 2);
define('POINTS_CANCEL',   0);

// --- Walkover Reasons ---
define('WALKOVER_REASONS', [
    'no_show'    => 'Player did not report within 5 minutes',
    'withdrawal' => 'Player withdrew before the match',
    'injured'    => 'Player injured / unable to play',
    'disqualified' => 'Player disqualified (misconduct)',
    'other'      => 'Other reason (see notes)',
]);

// --- Swiss Tier Codes ---
define('TIER_ACTIVE',      0); // Still competing in Stage 1
define('TIER_ONE',         1); // 2-0 → Qualified to R16 as Tier 1
define('TIER_TWO',         2); // 2-1 → Qualified to R16 as Tier 2
define('TIER_ELIMINATED',  9); // 0-2 or 1-2 → Out

// --- Session ---
define('SESSION_ADMIN_KEY', 'tkmi_admin');
define('SESSION_TIMEOUT',   3600 * 8); // 8 hours

// --- Pagination ---
define('PER_PAGE', 20);

// --- SSE ---
define('SSE_RETRY_MS', 2000); // Client reconnect interval
