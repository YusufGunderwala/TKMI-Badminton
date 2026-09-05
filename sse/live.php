<?php
// ============================================================
// Server-Sent Events (SSE) - Live Score Broadcaster
// ============================================================
// Disable error display so notices/warnings never corrupt the text/event-stream protocol
@ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/cache.php';

// Prevent PHP from stopping script when client disconnects (we handle it manually via connection_aborted)
ignore_user_abort(true);

// Disable Apache gzip and PHP output buffering so packets stream instantly
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

// Set SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Instruct client EventSource to retry after 1.5s if connection drops
echo "retry: 1500\n\n";
while (ob_get_level() > 0) { @ob_end_flush(); }
@flush();

$tournamentId = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;
$snapshotFile = AppCache::getLiveSnapshotPath($tournamentId);

// Ensure snapshot exists
if (!file_exists($snapshotFile)) {
    try {
        AppCache::generateLiveSnapshot($tournamentId);
    } catch (Throwable $e) {}
}

// Clean run for maximum 25s so Apache worker threads cycle cleanly and never leak
$startTime = time();
$maxExecutionTime = 25; 
$lastMtime = -1;

while (true) {
    // 1. Break if client disconnected or 25s cycle reached
    if (connection_aborted() || (time() - $startTime) > $maxExecutionTime) {
        break;
    }

    clearstatcache(true, $snapshotFile);
    $currentMtime = file_exists($snapshotFile) ? @filemtime($snapshotFile) : 0;

    // 2. If snapshot modified (or initial frame), stream event immediately
    if ($currentMtime !== $lastMtime) {
        $lastMtime = $currentMtime;
        if (file_exists($snapshotFile)) {
            $content = @file_get_contents($snapshotFile);
            if (!empty($content)) {
                echo "data: " . $content . "\n\n";
                while (ob_get_level() > 0) { @ob_end_flush(); }
                @flush();
            }
        }
    }

    // 3. Ping keepalive every 10s to prevent router timeouts
    if (time() % 10 === 0) {
        echo ": keep-alive\n\n";
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    // Sleep 300ms (sub-second reaction to score updates, zero DB/CPU overhead)
    usleep(300000);
}
exit;

