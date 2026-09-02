<?php
// ============================================================
// TKMI Badminton Tournament Platform — High-Speed Micro-Cache Engine
// Provides sub-millisecond in-memory and local disk caching for WAN queries.
// Auto-invalidates instantly on any data modification.
// ============================================================

class AppCache {
    private static array $memoryCache = [];
    private static ?string $cacheDir = null;

    private static function getCacheDir(): string {
        if (self::$cacheDir === null) {
            self::$cacheDir = __DIR__ . '/../cache';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }
        return self::$cacheDir;
    }

    /**
     * Retrieve an item from cache, or execute the callback and store the result.
     * @param string $key Unique cache key
     * @param int $ttl Time to live in seconds (default: 30s)
     * @param callable $callback Function returning fresh data
     */
    public static function remember(string $key, int $ttl, callable $callback) {
        // 1. In-memory per-request cache (0.00ms)
        if (array_key_exists($key, self::$memoryCache)) {
            return self::$memoryCache[$key];
        }

        // 2. High-speed disk cache (0.1ms)
        $dir = self::getCacheDir();
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $file = $dir . '/' . $safeKey . '.cache';

        if (file_exists($file)) {
            $mtime = filemtime($file);
            if ((time() - $mtime) < $ttl) {
                $content = @file_get_contents($file);
                if ($content !== false) {
                    $data = @unserialize($content);
                    if ($data !== false || $content === serialize(false)) {
                        self::$memoryCache[$key] = $data;
                        return $data;
                    }
                }
            }
        }

        // 3. Cache miss: Execute callback
        $freshData = $callback();
        self::$memoryCache[$key] = $freshData;

        // Save to fast disk
        @file_put_contents($file, serialize($freshData), LOCK_EX);

        return $freshData;
    }

    /**
     * Invalidate a specific cache key.
     */
    public static function forget(string $key): void {
        unset(self::$memoryCache[$key]);
        $dir = self::getCacheDir();
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $file = $dir . '/' . $safeKey . '.cache';
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Flush all application cache (called on any write / mutation).
     */
    public static function flush(): void {
        self::$memoryCache = [];
        $dir = self::getCacheDir();
        if (is_dir($dir)) {
            $files = glob($dir . '/*.cache');
            if ($files) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }
    }
}
