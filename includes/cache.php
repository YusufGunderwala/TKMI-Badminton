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
                @mkdir(self::$cacheDir, 0777, true);
                @chmod(self::$cacheDir, 0777);
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
            $mtime = @filemtime($file);
            if ($mtime && (time() - $mtime) < $ttl) {
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
        @chmod($file, 0666);

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

    /**
     * Get path to live snapshot JSON file for a tournament.
     */
    public static function getLiveSnapshotPath(int $tournamentId = 0): string {
        $dir = self::getCacheDir();
        return $dir . '/live_' . $tournamentId . '.json';
    }

    /**
     * Write raw JSON payload directly to live snapshot file.
     */
    public static function writeLiveSnapshot(int $tournamentId, array $data): bool {
        $path = self::getLiveSnapshotPath($tournamentId);
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        $res = @file_put_contents($path, $encoded, LOCK_EX);
        if ($res !== false) {
            @chmod($path, 0666);
            return true;
        }
        return false;
    }

    /**
     * Generate fresh live snapshot from DB and write to disk cache immediately.
     */
    public static function generateLiveSnapshot(int $tournamentId = 0): array {
        require_once __DIR__ . '/../config/db.php';
        require_once __DIR__ . '/../includes/functions.php';

        $pdo = db();

        if ($tournamentId > 0) {
            $stmt = $pdo->prepare("
                SELECT m.id, m.tournament_id, m.round_key, m.stage, m.status, m.score_a, m.score_b, m.games_a, m.games_b,
                       m.winner_player_id, m.winner_team_id,
                       m.participant_a_id, m.participant_b_id,
                       m.team_a_id, m.team_b_id,
                       pa.display_name as player_a, pb.display_name as player_b,
                       ta.display_name as team_a, tb.display_name as team_b,
                       t.name as tournament_name,
                       m.best_of, m.points_per_game, m.deuce_enabled, m.deuce_trigger, m.deuce_cap,
                       m.walkover_reason, m.match_number
                FROM matches m
                JOIN tournaments t ON m.tournament_id = t.id
                LEFT JOIN players pa ON m.participant_a_id = pa.id
                LEFT JOIN players pb ON m.participant_b_id = pb.id
                LEFT JOIN teams ta ON m.team_a_id = ta.id
                LEFT JOIN teams tb ON m.team_b_id = tb.id
                WHERE m.tournament_id = ?
                ORDER BY m.id ASC
            ");
            $stmt->execute([$tournamentId]);
        } else {
            $stmt = $pdo->query("
                SELECT m.id, m.tournament_id, m.round_key, m.stage, m.status, m.score_a, m.score_b, m.games_a, m.games_b,
                       m.winner_player_id, m.winner_team_id,
                       m.participant_a_id, m.participant_b_id,
                       m.team_a_id, m.team_b_id,
                       pa.display_name as player_a, pb.display_name as player_b,
                       ta.display_name as team_a, tb.display_name as team_b,
                       t.name as tournament_name,
                       m.best_of, m.points_per_game, m.deuce_enabled, m.deuce_trigger, m.deuce_cap,
                       m.walkover_reason, m.match_number
                FROM matches m
                JOIN tournaments t ON m.tournament_id = t.id
                LEFT JOIN players pa ON m.participant_a_id = pa.id
                LEFT JOIN players pb ON m.participant_b_id = pb.id
                LEFT JOIN teams ta ON m.team_a_id = ta.id
                LEFT JOIN teams tb ON m.team_b_id = tb.id
                WHERE (m.status = 'in_progress' OR (m.status IN ('completed', 'walkover', 'retired') AND m.ended_at >= NOW() - INTERVAL '2 hours'))
                ORDER BY (CASE WHEN m.status = 'in_progress' THEN 0 ELSE 1 END), m.tournament_id ASC, m.match_number ASC, m.id ASC
            ");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch games for breakdown
        $matchIds = array_column($rows, 'id');
        $gamesMap = [];
        $serverMap = [];
        if (!empty($matchIds)) {
            $in = implode(',', array_map('intval', $matchIds));
            $gStmt = $pdo->query("SELECT match_id, game_number, score_a, score_b, winner_side FROM games WHERE match_id IN ($in) ORDER BY match_id, game_number ASC");
            foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $gamesMap[$g['match_id']][] = $g;
            }

            $sStmt = $pdo->query("
                SELECT DISTINCT ON (match_id) match_id, side
                FROM score_events
                WHERE match_id IN ($in) AND is_undone = FALSE AND side IS NOT NULL
                ORDER BY match_id, sequence_no DESC, id DESC
            ");
            foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                $serverMap[$sr['match_id']] = ($sr['side'] === 'B') ? 'player_b' : 'player_a';
            }
        }

        $matches = [];
        $liveMatches = [];
        $completedMatches = [];

        foreach ($rows as $m) {
            $displayA = $m['team_a'] ?: ($m['player_a'] ?: 'Player A');
            $displayB = $m['team_b'] ?: ($m['player_b'] ?: 'Player B');
            $isCompleted = in_array($m['status'], ['completed', 'walkover', 'retired']);

            $winnerSide = null;
            if (!empty($m['winner_player_id'])) {
                $winnerSide = ($m['winner_player_id'] == $m['participant_a_id']) ? 'A' : 'B';
            } elseif (!empty($m['winner_team_id'])) {
                $winnerSide = ($m['winner_team_id'] == $m['team_a_id']) ? 'A' : 'B';
            }

            $games = $gamesMap[$m['id']] ?? [];
            foreach ($games as &$g) {
                if (empty($g['winner_side'])) {
                    $g['winner_side'] = ($g['score_a'] > $g['score_b']) ? 'A' : (($g['score_b'] > $g['score_a']) ? 'B' : null);
                }
                $g['winner_name'] = ($g['winner_side'] === 'A') ? $displayA : (($g['winner_side'] === 'B') ? $displayB : null);
            }
            unset($g);
            $scoreBreakdown = implode(', ', array_map(fn($g) => $g['score_a'] . '-' . $g['score_b'], $games));

            $probs = calculateWinProbability(
                (int)$m['score_a'],
                (int)$m['score_b'],
                (int)$m['games_a'],
                (int)$m['games_b'],
                (int)($m['points_per_game'] ?? 11),
                (int)($m['best_of'] ?? 3),
                $isCompleted,
                $winnerSide
            );

            $item = [
                'id'                  => (int)$m['id'],
                'tournament_id'       => (int)$m['tournament_id'],
                'tournament_name'     => $m['tournament_name'] ?? '',
                'match_number'        => (int)($m['match_number'] ?? 0),
                'round_key'           => $m['round_key'],
                'round_label'         => getRoundLabel($m['round_key'] ?? ''),
                'status'              => $m['status'],
                'is_completed'        => $isCompleted,
                'score_a'             => (int)$m['score_a'],
                'score_b'             => (int)$m['score_b'],
                'games_a'             => (int)$m['games_a'],
                'games_b'             => (int)$m['games_b'],
                'game_score_a'        => (int)$m['score_a'],
                'game_score_b'        => (int)$m['score_b'],
                'player_a_games_won'  => (int)$m['games_a'],
                'player_b_games_won'  => (int)$m['games_b'],
                'display_a'           => $displayA,
                'display_b'           => $displayB,
                'player_a'            => $m['player_a'] ?? '',
                'player_b'            => $m['player_b'] ?? '',
                'best_of'             => (int)($m['best_of'] ?? 3),
                'points_per_game'     => (int)($m['points_per_game'] ?? 11),
                'deuce_enabled'       => (bool)$m['deuce_enabled'],
                'deuce_trigger'       => (int)$m['deuce_trigger'],
                'deuce_cap'           => (int)$m['deuce_cap'],
                'winner_side'         => $winnerSide,
                'winner_name'         => ($winnerSide === 'A') ? $displayA : (($winnerSide === 'B') ? $displayB : null),
                'games'               => $games,
                'score_breakdown'     => $scoreBreakdown,
                'momentum_a'          => $probs['a'],
                'momentum_b'          => $probs['b'],
                'current_server'      => $serverMap[$m['id']] ?? 'player_a',
                'walkover_reason'     => $m['walkover_reason'],
                'current_game_number' => ((int)$m['games_a'] + (int)$m['games_b']) + 1
            ];

            $matches[] = $item;
            if ($m['status'] === 'in_progress') {
                $liveMatches[] = $item;
            } elseif ($isCompleted) {
                $completedMatches[] = $item;
            }
        }

        $payload = [
            'success'           => true,
            'timestamp'         => time(),
            'matches'           => $matches,
            'live_matches'      => $liveMatches,
            'completed_matches' => $completedMatches
        ];

        self::writeLiveSnapshot($tournamentId, $payload);
        return $payload;
    }
}
