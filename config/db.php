<?php
// ============================================================
// TKMI Badminton Tournament Platform — Database Configuration
// PostgreSQL via Supabase (High-Performance Tuned)
// ============================================================

// --- Supabase PostgreSQL Connection (IPv4 Connection Pooler for Cloud & Local) ---
define('DB_HOST',     getenv('DB_HOST')     ?: 'aws-0-ap-northeast-1.pooler.supabase.com');
define('DB_PORT',     getenv('DB_PORT')     ?: '6543');
define('DB_NAME',     getenv('DB_NAME')     ?: 'postgres');
define('DB_USER',     getenv('DB_USER')     ?: 'postgres.zegsiotamieloewcudur');
define('DB_PASS',     getenv('DB_PASS')     ?: 'vQU.M$bd-asw@2N');
define('DB_SCHEMA',   getenv('DB_SCHEMA')   ?: 'public');
define('DB_URL',      getenv('DB_URL')      ?: '');

/**
 * Returns a reliable PDO connection instance with self-healing reconnection.
 */
function db(bool $forceReconnect = false): PDO {
    static $pdo = null;

    if ($forceReconnect) {
        $pdo = null;
    }

    if ($pdo === null) {
        if (DB_URL) {
            $parsed = parse_url(DB_URL);
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;options=\'--client_encoding=UTF8\'',
                $parsed['host'],
                $parsed['port'] ?? 5432,
                ltrim($parsed['path'] ?? '/postgres', '/')
            );
            $user = $parsed['user'] ?? DB_USER;
            $pass = $parsed['pass'] ?? DB_PASS;
        } else {
            $dsn  = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';connect_timeout=10;options=\'--client_encoding=UTF8\'';
            $user = DB_USER;
            $pass = DB_PASS;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false, // Fresh clean connection per request avoids stale cloud socket drops
            PDO::ATTR_TIMEOUT            => 10,
        ];

        $maxAttempts = 3;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
                break;
            } catch (PDOException $e) {
                error_log("DB Connection Attempt $attempt failed: " . $e->getMessage());
                if ($attempt >= $maxAttempts) {
                    die(json_encode(['error' => 'Database connection timeout. Please refresh.']));
                }
                usleep(200000); // Wait 200ms before retry
            }
        }
    }
    return $pdo;
}

/**
 * Safely executes a database callback with automatic reconnection and retry on transient drops.
 */
function db_retry(callable $callback) {
    try {
        return $callback(db());
    } catch (PDOException $e) {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'server closed') || str_contains($msg, 'gone away') || str_contains($msg, 'connection') || str_contains($msg, 'terminat') || str_contains($msg, 'broken pipe')) {
            error_log("Transient DB connection drop. Auto-reconnecting...");
            usleep(150000);
            return $callback(db(true));
        }
        throw $e;
    }
}
