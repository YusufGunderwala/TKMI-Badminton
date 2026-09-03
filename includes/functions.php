<?php
// ============================================================
// TKMI Badminton Tournament Platform — Shared Utility Functions
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/cache.php';

require_once __DIR__ . '/auth.php';

// ---- Security -----------------------------------------------

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    sessionStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function generate_csrf(): string {
    return csrf_token();
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf(?string $token = null): bool {
    sessionStart();
    $check = $token ?? $_POST['csrf_token'] ?? '';
    return !empty($check) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $check);
}

// ---- JSON API Responses ------------------------------------

function json_success(array $data = [], string $message = 'OK'): never {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

function json_error(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ---- Flash Messages ----------------------------------------

function flash_set(string $key, string $message, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

function flash_get(string $key): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'][$key])) {
        $flash = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $flash;
    }
    return null;
}

function flash_html(string $key): string {
    $flash = flash_get($key);
    if (!$flash) return '';
    $type = $flash['type'];
    $colors = [
        'success' => 'bg-green-50 border-green-400 text-green-800',
        'error'   => 'bg-red-50 border-red-400 text-red-800',
        'warning' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
        'info'    => 'bg-blue-50 border-blue-400 text-blue-800',
    ];
    $cls = $colors[$type] ?? $colors['info'];
    return '<div class="border-l-4 p-4 mb-4 rounded ' . $cls . '">' . e($flash['message']) . '</div>';
}

// ---- Image Upload ------------------------------------------

function uploadImage(array $file, string $destDir, string $prefix = 'img'): string|false {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > MAX_UPLOAD_SIZE) return false;
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) return false;

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $prefix . '_' . uniqid() . '.' . strtolower($ext);
    $destPath = rtrim($destDir, '/') . '/' . $filename;

    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $destPath)) return false;

    return $filename;
}

function deleteUpload(string $filePath): void {
    if (file_exists($filePath)) unlink($filePath);
}

// ---- Sponsors ----------------------------------------------

function getActiveSponsors(): array {
    return AppCache::remember('active_sponsors', 60, function() {
        $stmt = db()->query('SELECT * FROM sponsors ORDER BY id ASC');
        return $stmt->fetchAll();
    });
}

// ---- Tournaments -------------------------------------------

function getTournament(int $id): ?array {
    return AppCache::remember('tournament_' . $id, 20, function() use ($id) {
        $stmt = db()->prepare('SELECT * FROM tournaments WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    });
}

function getAllTournaments(string $status = ''): array {
    $cacheKey = 'all_tournaments_' . ($status ?: 'all');
    return AppCache::remember($cacheKey, 15, function() use ($status) {
        if ($status) {
            $stmt = db()->prepare('SELECT * FROM tournaments WHERE status = ? ORDER BY created_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = db()->query('SELECT * FROM tournaments ORDER BY created_at DESC');
        }
        return $stmt->fetchAll();
    });
}

function getLiveTournaments(): array {
    return getAllTournaments(STATUS_LIVE);
}

// ---- Matches -----------------------------------------------

function getLiveMatches(?int $tournamentId = null): array {
    $sql = 'SELECT m.*, 
                   pa.display_name as pa_name, pb.display_name as pb_name,
                   ta.display_name as ta_name, tb.display_name as tb_name,
                   t.name AS tournament_name, t.gender, t.match_type
            FROM matches m 
            JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN players pa ON m.participant_a_id = pa.id
            LEFT JOIN players pb ON m.participant_b_id = pb.id
            LEFT JOIN teams ta ON m.team_a_id = ta.id
            LEFT JOIN teams tb ON m.team_b_id = tb.id
            WHERE m.status = ? ' . ($tournamentId ? 'AND m.tournament_id = ? ' : '') . '
            ORDER BY m.started_at DESC';
    $stmt = db()->prepare($sql);
    $params = [MATCH_LIVE];
    if ($tournamentId) $params[] = $tournamentId;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getUpcomingMatches(?int $tournamentId = null, int $limit = 5): array {
    $sql = 'SELECT m.*, 
                   pa.display_name as pa_name, pb.display_name as pb_name,
                   ta.display_name as ta_name, tb.display_name as tb_name,
                   t.name AS tournament_name, t.gender, t.match_type
            FROM matches m 
            JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN players pa ON m.participant_a_id = pa.id
            LEFT JOIN players pb ON m.participant_b_id = pb.id
            LEFT JOIN teams ta ON m.team_a_id = ta.id
            LEFT JOIN teams tb ON m.team_b_id = tb.id
            WHERE m.status = ? ' . ($tournamentId ? 'AND m.tournament_id = ? ' : '') . '
            ORDER BY m.match_number ASC LIMIT ?';
    $stmt = db()->prepare($sql);
    $params = [MATCH_SCHEDULED];
    if ($tournamentId) $params[] = $tournamentId;
    $params[] = $limit;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---- Players -----------------------------------------------

function getPlayer(int $id): ?array {
    return AppCache::remember('player_' . $id, 60, function() use ($id) {
        $stmt = db()->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    });
}

function getTeam(int $id): ?array {
    return AppCache::remember('team_' . $id, 60, function() use ($id) {
        $stmt = db()->prepare('
            SELECT t.*, 
                   p1.display_name as p1_name, p1.full_name as p1_full_name, p1.its_id as p1_its, p1.photo_path as p1_photo, p1.mohallah as p1_mohallah,
                   p2.display_name as p2_name, p2.full_name as p2_full_name, p2.its_id as p2_its, p2.photo_path as p2_photo, p2.mohallah as p2_mohallah
            FROM teams t
            LEFT JOIN players p1 ON t.player1_id = p1.id
            LEFT JOIN players p2 ON t.player2_id = p2.id
            WHERE t.id = ?
        ');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    });
}

function getParticipantName(array $match): array {
    // Fast path: check if names were pre-joined in SQL
    if (!empty($match['pa_name']) || !empty($match['pb_name'])) {
        return [
            'a' => e($match['pa_name'] ?? 'TBD'),
            'b' => e($match['pb_name'] ?? 'TBD')
        ];
    }
    
    $names = ['a' => 'TBD', 'b' => 'TBD'];
    if (!empty($match['participant_a_id'])) {
        $p = getPlayer((int) $match['participant_a_id']);
        $names['a'] = $p ? e($p['display_name']) : 'Unknown';
    }
    if (!empty($match['participant_b_id'])) {
        $p = getPlayer((int) $match['participant_b_id']);
        $names['b'] = $p ? e($p['display_name']) : 'Unknown';
    }
    return $names;
}

// ---- Round Labels ------------------------------------------

function getRoundLabel(string $roundKey): string {
    $labels = [
        'r1'         => 'Round 1',
        'r2'         => 'Round 2',
        'survival'   => 'Survival Round',
        'r16'        => 'Round of 16',
        'qf'         => 'Quarter Finals',
        'sf'         => 'Semi Finals',
        '3rd_place'  => '3rd Place Match',
        'final'      => 'Final',
    ];
    return $labels[$roundKey] ?? ucfirst($roundKey);
}

// ---- Format Helpers ----------------------------------------

function formatScore(array $games): string {
    $parts = [];
    foreach ($games as $g) {
        if ($g['status'] !== 'pending') {
            $parts[] = $g['score_a'] . '-' . $g['score_b'];
        }
    }
    return implode(', ', $parts) ?: '0-0';
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    return date('d M', strtotime($datetime));
}

function statusBadge(string $status): string {
    $badges = [
        'draft'     => '<span class="badge badge-gray">Draft</span>',
        'ready'     => '<span class="badge badge-blue">Ready</span>',
        'live'      => '<span class="badge badge-red animate-pulse">● LIVE</span>',
        'completed' => '<span class="badge badge-green">Completed</span>',
        'archived'  => '<span class="badge badge-gray">Archived</span>',
        'scheduled' => '<span class="badge badge-blue">Scheduled</span>',
        'walkover'  => '<span class="badge badge-yellow">Walkover</span>',
        'retired'   => '<span class="badge badge-yellow">Retired</span>',
        'cancelled' => '<span class="badge badge-gray">Cancelled</span>',
    ];
    return $badges[$status] ?? '<span class="badge badge-gray">' . e($status) . '</span>';
}

// ---- Dashboard Stats (Consolidated Single Round-Trip Query with Cache) -

function getDashboardStats(): array {
    return AppCache::remember('dashboard_stats', 8, function() {
        try {
            $row = db()->query("
                SELECT 
                    (SELECT COUNT(*) FROM tournaments) AS total_tournaments,
                    (SELECT COUNT(*) FROM tournaments WHERE status = 'live') AS live_tournaments,
                    (SELECT COUNT(*) FROM players) AS total_players,
                    (SELECT COUNT(*) FROM matches WHERE status = 'in_progress') AS live_matches,
                    (SELECT COUNT(*) FROM matches WHERE status = 'completed') AS total_matches,
                    (SELECT COUNT(*) FROM admins) AS total_admins
            ")->fetch();

            return [
                'total_tournaments' => (int)($row['total_tournaments'] ?? 0),
                'live_tournaments'  => (int)($row['live_tournaments'] ?? 0),
                'total_players'     => (int)($row['total_players'] ?? 0),
                'live_matches'      => (int)($row['live_matches'] ?? 0),
                'total_matches'     => (int)($row['total_matches'] ?? 0),
                'total_admins'      => (int)($row['total_admins'] ?? 0),
            ];
        } catch (Exception $e) {
            return [
                'total_tournaments' => 0,
                'live_tournaments'  => 0,
                'total_players'     => 0,
                'live_matches'      => 0,
                'total_matches'     => 0,
                'total_admins'      => 0,
            ];
        }
    });
}

// Ensure Matchmaker engine is available throughout the entire platform
require_once __DIR__ . '/matchmaker.php';

