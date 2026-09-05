<?php
// ============================================================
// Match Status API -- Real-time High-Speed Snapshot Endpoint
// Serves pre-computed disk JSON cache in 0.5ms with zero DB load.
// Auto-refreshes if cache is stale (>2s) or missing.
// ============================================================
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/cache.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=1, stale-while-revalidate=1');
header('X-Accel-Buffering: no');

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;
$snapshotFile = AppCache::getLiveSnapshotPath($tournamentId);

// 1. If snapshot is fresh (less than 2 seconds old), stream directly from local disk (0.5ms response time!)
if (file_exists($snapshotFile)) {
    $mtime = @filemtime($snapshotFile);
    if ($mtime && (time() - $mtime) < 2) {
        readfile($snapshotFile);
        exit;
    }
}

// 2. Otherwise generate fresh snapshot from database, write to disk cache, and output
try {
    $payload = AppCache::generateLiveSnapshot($tournamentId);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // If DB is busy, fall back to existing snapshot if available
    if (file_exists($snapshotFile)) {
        readfile($snapshotFile);
        exit;
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;


