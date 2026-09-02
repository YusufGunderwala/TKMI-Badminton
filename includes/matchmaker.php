<?php
// ============================================================
// Matchmaker Engine — Core logic for Tournament Brackets
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

class Matchmaker {

    /**
     * Checks if a tournament is ready to generate Round 1.
     */
    public static function canGenerateRound1(int $tournamentId): bool {
        $pdo = db();
        // Check if R1 matches already exist
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R1]);
        if ($stmt->fetchColumn() > 0) return false;

        // Need at least 4 players enrolled
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_players WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        return (int)$stmt->fetchColumn() >= 4;
    }

    /**
     * Generates Stage 1 - Round 1 (Random Draw — supports any player count)
     * If odd number of players, one random player gets a BYE (free win).
     */
    public static function generateSwissRound1(int $tournamentId, int $adminId): void {
        if (!self::canGenerateRound1($tournamentId)) {
            throw new Exception("Cannot generate Round 1. Ensure at least 4 players are enrolled and R1 doesn't already exist.");
        }

        $pdo = db();
        
        try {
            $pdo->beginTransaction();

            // 1. Get all enrolled players
            $stmt = $pdo->prepare('SELECT player_id FROM tournament_players WHERE tournament_id = ?');
            $stmt->execute([$tournamentId]);
            $players = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $totalPlayers = count($players);

            // 2. Initialize player tournament records (wins=0, losses=0)
            $insertRecord = $pdo->prepare('
                INSERT INTO player_tournament_records (tournament_id, player_id, wins, losses, tier, is_eliminated) 
                VALUES (?, ?, 0, 0, ?, false)
                ON CONFLICT (tournament_id, player_id) DO NOTHING
            ');
            foreach ($players as $pid) {
                $insertRecord->execute([$tournamentId, $pid, TIER_ACTIVE]);
            }

            // 3. Shuffle players securely for random draw
            shuffle($players);

            // 4. Handle BYE if odd number of players
            $byePlayer = null;
            if ($totalPlayers % 2 !== 0) {
                $byePlayer = array_pop($players);
                // Give BYE player an automatic win
                $updateRecord = $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?');
                $updateRecord->execute([$tournamentId, $byePlayer]);
            }

            // 5. Create matches for paired players
            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, participant_a_id, participant_b_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $matchNumber = 1;
            $pairedCount = count($players);
            for ($i = 0; $i < $pairedCount; $i += 2) {
                $pA = $players[$i];
                $pB = $players[$i + 1];
                
                $insertMatch->execute([
                    $tournamentId, 
                    ROUND_STAGE1_R1, 
                    'stage1',
                    $matchNumber, 
                    $pA, 
                    $pB, 
                    MATCH_SCHEDULED
                ]);
                $matchNumber++;
            }

            // 6. Update tournament status to LIVE
            $updateTourney = $pdo->prepare('UPDATE tournaments SET status = ? WHERE id = ?');
            $updateTourney->execute([STATUS_LIVE, $tournamentId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Round 1): ' . $e->getMessage());
            throw new Exception("Failed to generate Round 1. Database error.");
        }
    }

    /**
     * Checks if a round is 100% completed.
     */
    public static function isRoundComplete(int $tournamentId, string $roundKey): bool {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM matches 
            WHERE tournament_id = ? AND round_key = ? AND status NOT IN (?, ?, ?)
        ');
        $stmt->execute([$tournamentId, $roundKey, MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED]);
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * Generates Stage 1 - Round 2 (Winners vs Winners, Losers vs Losers)
     * Dynamic — handles any player count from Round 1.
     */
    public static function generateSwissRound2(int $tournamentId, int $adminId): void {
        // Must ensure R1 is done
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_R1)) {
            throw new Exception("Round 1 is not fully completed yet.");
        }

        $pdo = db();
        // Ensure R2 doesn't exist
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R2]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 2 already exists.");

        try {
            $pdo->beginTransaction();

            // Fetch Winners (1-0)
            $stmtWinners = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 0');
            $stmtWinners->execute([$tournamentId]);
            $winners = $stmtWinners->fetchAll(PDO::FETCH_COLUMN);

            // Fetch Losers (0-1)
            $stmtLosers = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 0 AND losses = 1');
            $stmtLosers->execute([$tournamentId]);
            $losers = $stmtLosers->fetchAll(PDO::FETCH_COLUMN);

            shuffle($winners);
            shuffle($losers);

            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, participant_a_id, participant_b_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $updateRecord = $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?');

            $matchNumber = 1;

            // Pair Winners (handle BYE if odd)
            if (count($winners) % 2 !== 0) {
                $byeWinner = array_pop($winners);
                $updateRecord->execute([$tournamentId, $byeWinner]);
            }
            for ($i = 0; $i < count($winners); $i += 2) {
                $insertMatch->execute([$tournamentId, ROUND_STAGE1_R2, 'stage1', $matchNumber++, $winners[$i], $winners[$i + 1], MATCH_SCHEDULED]);
            }

            // Pair Losers (handle BYE if odd)
            if (count($losers) % 2 !== 0) {
                $byeLoser = array_pop($losers);
                $updateRecord->execute([$tournamentId, $byeLoser]);
            }
            for ($i = 0; $i < count($losers); $i += 2) {
                $insertMatch->execute([$tournamentId, ROUND_STAGE1_R2, 'stage1', $matchNumber++, $losers[$i], $losers[$i + 1], MATCH_SCHEDULED]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Round 2): ' . $e->getMessage());
            throw new Exception("Failed to generate Round 2: " . $e->getMessage());
        }
    }

    /**
     * Saves custom manual pairings for Round 1
     */
    public static function saveCustomRound1(int $tournamentId, array $pairs, ?int $byePlayerId, int $adminId): void {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R1]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 1 already exists.");

        $stmt = $pdo->prepare('SELECT player_id FROM tournament_players WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        $enrolled = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($enrolled) < 4) {
            throw new Exception("Need at least 4 players enrolled.");
        }

        try {
            $pdo->beginTransaction();

            // Initialize player tournament records
            $insertRecord = $pdo->prepare('
                INSERT INTO player_tournament_records (tournament_id, player_id, wins, losses, tier, is_eliminated) 
                VALUES (?, ?, 0, 0, ?, false)
                ON CONFLICT (tournament_id, player_id) DO NOTHING
            ');
            foreach ($enrolled as $pid) {
                $insertRecord->execute([$tournamentId, $pid, TIER_ACTIVE]);
            }

            // If BYE player provided, give 1 win
            if ($byePlayerId && in_array($byePlayerId, $enrolled)) {
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$tournamentId, $byePlayerId]);
            }

            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, participant_a_id, participant_b_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $matchNumber = 1;
            foreach ($pairs as $pair) {
                $pA = (int)$pair[0];
                $pB = (int)$pair[1];
                if (!$pA || !$pB || $pA === $pB) {
                    throw new Exception("Invalid match pairing detected ($pA vs $pB).");
                }
                $insertMatch->execute([
                    $tournamentId,
                    ROUND_STAGE1_R1,
                    'stage1',
                    $matchNumber++,
                    $pA,
                    $pB,
                    MATCH_SCHEDULED
                ]);
            }

            // Update tournament status to LIVE
            $pdo->prepare('UPDATE tournaments SET status = ? WHERE id = ?')
                ->execute([STATUS_LIVE, $tournamentId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Custom R1): ' . $e->getMessage());
            throw new Exception("Failed to save custom Round 1: " . $e->getMessage());
        }
    }

    /**
     * Saves custom manual pairings for Round 2
     */
    public static function saveCustomRound2(int $tournamentId, array $pairs, ?int $byePlayerId, int $adminId): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_R1)) {
            throw new Exception("Round 1 is not fully completed yet.");
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R2]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 2 already exists.");

        try {
            $pdo->beginTransaction();

            if ($byePlayerId) {
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$tournamentId, $byePlayerId]);
            }

            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, participant_a_id, participant_b_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $matchNumber = 1;
            foreach ($pairs as $pair) {
                $pA = (int)$pair[0];
                $pB = (int)$pair[1];
                if (!$pA || !$pB || $pA === $pB) {
                    throw new Exception("Invalid match pairing detected.");
                }
                $insertMatch->execute([
                    $tournamentId,
                    ROUND_STAGE1_R2,
                    'stage1',
                    $matchNumber++,
                    $pA,
                    $pB,
                    MATCH_SCHEDULED
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Custom R2): ' . $e->getMessage());
            throw new Exception("Failed to save custom Round 2: " . $e->getMessage());
        }
    }

    /**
     * Gets previous opponents for a player in a tournament
     */
    public static function getPreviousOpponents(int $tournamentId, int $playerId): array {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT participant_a_id, participant_b_id 
            FROM matches 
            WHERE tournament_id = ? AND (participant_a_id = ? OR participant_b_id = ?)
        ');
        $stmt->execute([$tournamentId, $playerId, $playerId]);
        $opponents = [];
        foreach ($stmt->fetchAll() as $row) {
            if ($row['participant_a_id'] && $row['participant_a_id'] != $playerId) $opponents[] = $row['participant_a_id'];
            if ($row['participant_b_id'] && $row['participant_b_id'] != $playerId) $opponents[] = $row['participant_b_id'];
        }
        return $opponents;
    }

    /**
     * Returns Path 1 (Won R1) and Path 2 (Lost R1) for 1-1 players
     */
    public static function getSurvivalPaths(int $tournamentId): array {
        $pdo = db();
        
        // Get all 1-1 players
        $stmt = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 1');
        $stmt->execute([$tournamentId]);
        $tiedPlayers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $path1 = [];
        $path2 = [];

        foreach ($tiedPlayers as $pid) {
            // Did they win in R1?
            $checkR1 = $pdo->prepare('SELECT winner_player_id FROM matches WHERE tournament_id = ? AND round_key = ? AND (participant_a_id = ? OR participant_b_id = ?)');
            $checkR1->execute([$tournamentId, ROUND_STAGE1_R1, $pid, $pid]);
            $r1Winner = $checkR1->fetchColumn();

            if ($r1Winner == $pid) {
                $path1[] = $pid; // Won R1, so must have lost R2
            } else {
                $path2[] = $pid; // Lost R1, so must have won R2
            }
        }

        return ['path1' => $path1, 'path2' => $path2];
    }

    /**
     * Saves the Survival Round pairs from the admin manual override UI
     */
    public static function saveSurvivalRound(int $tournamentId, array $pairs, int $adminId): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_R2)) {
            throw new Exception("Round 2 is not complete.");
        }
        
        $pdo = db();
        // Ensure Survival doesn't exist
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_SURVIVAL]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Survival Round already exists.");

        try {
            $pdo->beginTransaction();

            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, participant_a_id, participant_b_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $matchNumber = 1;
            foreach ($pairs as $pair) {
                $pA = (int)$pair[0];
                $pB = (int)$pair[1];
                if (!$pA || !$pB || $pA === $pB) throw new Exception("Invalid player pairing detected.");

                $insertMatch->execute([
                    $tournamentId, 
                    ROUND_STAGE1_SURVIVAL, 
                    'stage1',
                    $matchNumber++, 
                    $pA, 
                    $pB, 
                    MATCH_SCHEDULED
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Survival): ' . $e->getMessage());
            throw new Exception("Failed to save Survival Round: " . $e->getMessage());
        }
    }

    /**
     * Generates Stage 2 Knockout Bracket (R16 -> QF -> SF -> Final & 3rd Place)
     * Pairs Tier 1 (2-0) vs Tier 2 (2-1).
     */
    /**
     * Generates Stage 2 Knockout Bracket (R16 -> QF -> SF -> Final & 3rd Place)
     * Dynamically adapts to any number of qualifiers (e.g. 12 from 24-player tournament, 16 from 32, 8 from 16).
     */
    public static function generateStage2Bracket(int $tournamentId, int $adminId): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_SURVIVAL)) {
            throw new Exception("Survival Round is not complete.");
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key IN (?, ?)');
        $stmt->execute([$tournamentId, ROUND_R16, ROUND_QF]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Stage 2 already generated.");

        try {
            $pdo->beginTransaction();

            // Get Tier 1 (2-0 from R2)
            $stmtT1 = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 2 AND losses = 0');
            $stmtT1->execute([$tournamentId]);
            $tier1 = $stmtT1->fetchAll(PDO::FETCH_COLUMN);

            // Get Tier 2 (2-1 from Survival - basically anyone with 2 wins and 1 loss)
            $stmtT2 = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 2 AND losses = 1');
            $stmtT2->execute([$tournamentId]);
            $tier2 = $stmtT2->fetchAll(PDO::FETCH_COLUMN);

            $t1Count = count($tier1);
            $t2Count = count($tier2);
            $totalQualifiers = $t1Count + $t2Count;

            if ($totalQualifiers < 2) {
                throw new Exception("Not enough players qualified for Stage 2 ($totalQualifiers found).");
            }

            // Update tiers in records
            $updateTier = $pdo->prepare('UPDATE player_tournament_records SET tier = ? WHERE tournament_id = ? AND player_id = ?');
            foreach ($tier1 as $p) $updateTier->execute([TIER_ONE, $tournamentId, $p]);
            foreach ($tier2 as $p) $updateTier->execute([TIER_TWO, $tournamentId, $p]);
            
            // Mark eliminated (losses >= 2 and wins < 2)
            $pdo->prepare('UPDATE player_tournament_records SET tier = ?, is_eliminated = TRUE WHERE tournament_id = ? AND losses >= 2 AND wins < 2')
                ->execute([TIER_ELIMINATED, $tournamentId]);

            // Shuffle arrays for random drawing within tiers
            shuffle($tier1);
            shuffle($tier2);

            // Placeholder match insert
            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, participant_a_id, participant_b_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id
            ');

            // 1. Create Final and 3rd Place (empty)
            $insertMatch->execute([$tournamentId, ROUND_FINAL, 'stage2', 1, null, null, MATCH_SCHEDULED]);
            $finalId = $insertMatch->fetchColumn();
            $insertMatch->execute([$tournamentId, ROUND_3RD_PLACE, 'stage2', 1, null, null, MATCH_SCHEDULED]);
            $thirdPlaceId = $insertMatch->fetchColumn();

            // 2. Create SF (empty, pointing to Final/3rd)
            $sfIds = [];
            for ($i = 1; $i <= 2; $i++) {
                $insertMatch->execute([$tournamentId, ROUND_SF, 'stage2', $i, null, null, MATCH_SCHEDULED]);
                $sfIds[] = $insertMatch->fetchColumn();
            }
            $pdo->prepare('UPDATE matches SET next_match_id_winner = ?, next_match_id_loser = ? WHERE id = ?')->execute([$finalId, $thirdPlaceId, $sfIds[0]]);
            $pdo->prepare('UPDATE matches SET next_match_id_winner = ?, next_match_id_loser = ? WHERE id = ?')->execute([$finalId, $thirdPlaceId, $sfIds[1]]);

            // Case A: 14 to 16+ Qualifiers (standard 32-player format)
            if ($totalQualifiers >= 14) {
                // 3. Create 4 QF (pointing to SF)
                $qfIds = [];
                for ($i = 1; $i <= 4; $i++) {
                    $insertMatch->execute([$tournamentId, ROUND_QF, 'stage2', $i, null, null, MATCH_SCHEDULED]);
                    $qfIds[] = $insertMatch->fetchColumn();
                }
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[0], $qfIds[0]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[0], $qfIds[1]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[1], $qfIds[2]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[1], $qfIds[3]]);

                // 4. Create R16 (8 matches)
                $r16Matches = [];
                for ($i = 0; $i < 8; $i++) {
                    $pA = $tier1[$i] ?? null;
                    $pB = $tier2[$i] ?? null;
                    $insertMatch->execute([$tournamentId, ROUND_R16, 'stage2', $i + 1, $pA, $pB, MATCH_SCHEDULED]);
                    $r16Matches[] = $insertMatch->fetchColumn();
                }
                // Link R16 to QF
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[0], $r16Matches[0]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[0], $r16Matches[1]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[1], $r16Matches[2]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[1], $r16Matches[3]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[2], $r16Matches[4]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[2], $r16Matches[5]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[3], $r16Matches[6]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfIds[3], $r16Matches[7]]);
            }
            // Case B: 9 to 13 Qualifiers (e.g. 12 qualifiers from 24-player tournament: 6 Tier 1, 6 Tier 2)
            elseif ($totalQualifiers >= 9) {
                // Top 4 Tier 1 get BYEs straight into QF slots
                $byes = array_splice($tier1, 0, 4);
                
                // Remaining players play preliminary R16
                $r16Pool = array_merge($tier1, $tier2);
                shuffle($r16Pool);
                
                $qfIds = [];
                for ($i = 0; $i < 4; $i++) {
                    $insertMatch->execute([$tournamentId, ROUND_QF, 'stage2', $i + 1, $byes[$i] ?? null, null, MATCH_SCHEDULED]);
                    $qfIds[] = $insertMatch->fetchColumn();
                }
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[0], $qfIds[0]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[0], $qfIds[1]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[1], $qfIds[2]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[1], $qfIds[3]]);

                // Create R16 matches for the remaining players
                $r16MatchNum = 1;
                for ($i = 0; $i < count($r16Pool); $i += 2) {
                    $pA = $r16Pool[$i] ?? null;
                    $pB = $r16Pool[$i + 1] ?? null;
                    $insertMatch->execute([$tournamentId, ROUND_R16, 'stage2', $r16MatchNum, $pA, $pB, MATCH_SCHEDULED]);
                    $r16MatchId = $insertMatch->fetchColumn();
                    
                    // Link winner to QF slot (fill empty participant_b_id)
                    $qfTarget = $qfIds[($r16MatchNum - 1) % 4];
                    $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$qfTarget, $r16MatchId]);
                    $r16MatchNum++;
                }
            }
            // Case C: 5 to 8 Qualifiers (e.g. 16-player format)
            elseif ($totalQualifiers >= 5) {
                // Starts at QF directly
                $allQualifiers = array_merge($tier1, $tier2);
                $qfIds = [];
                $matchNum = 1;
                for ($i = 0; $i < 8; $i += 2) {
                    $pA = $allQualifiers[$i] ?? null;
                    $pB = $allQualifiers[$i + 1] ?? null;
                    $insertMatch->execute([$tournamentId, ROUND_QF, 'stage2', $matchNum++, $pA, $pB, MATCH_SCHEDULED]);
                    $qfIds[] = $insertMatch->fetchColumn();
                }
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[0], $qfIds[0]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[0], $qfIds[1]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[1], $qfIds[2]]);
                $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$sfIds[1], $qfIds[3]]);
            }
            // Case D: 4 or fewer qualifiers (starts directly at Semi-Finals)
            else {
                $allQualifiers = array_merge($tier1, $tier2);
                $pdo->prepare('UPDATE matches SET participant_a_id = ?, participant_b_id = ? WHERE id = ?')
                    ->execute([$allQualifiers[0] ?? null, $allQualifiers[1] ?? null, $sfIds[0]]);
                $pdo->prepare('UPDATE matches SET participant_a_id = ?, participant_b_id = ? WHERE id = ?')
                    ->execute([$allQualifiers[2] ?? null, $allQualifiers[3] ?? null, $sfIds[1]]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Stage 2): ' . $e->getMessage());
            throw new Exception("Failed to generate Stage 2: " . $e->getMessage());
        }
    }

    /**
     * Generates Round Robin fixtures (all players/teams play each other)
     */
    public static function generateRoundRobin(int $tournamentId, int $adminId): void {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Matches have already been generated for this tournament.");
        }

        $tourney = getTournament($tournamentId);
        if (!$tourney) throw new Exception("Tournament not found.");

        $isDoubles = ($tourney['match_type'] === 'doubles');

        try {
            $pdo->beginTransaction();

            if ($isDoubles) {
                $stmt = $pdo->prepare('SELECT id FROM teams WHERE tournament_id = ?');
                $stmt->execute([$tournamentId]);
                $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmt = $pdo->prepare('SELECT player_id FROM tournament_players WHERE tournament_id = ?');
                $stmt->execute([$tournamentId]);
                $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $n = count($participants);
            if ($n < 2) {
                throw new Exception("Need at least 2 participants to generate a league. Currently have $n.");
            }

            $insertMatch = $pdo->prepare('
                INSERT INTO matches (tournament_id, round_key, stage, match_number, ' . ($isDoubles ? 'team_a_id, team_b_id' : 'participant_a_id, participant_b_id') . ', status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            $matchNum = 1;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $insertMatch->execute([
                        $tournamentId,
                        'round_robin',
                        'stage1',
                        $matchNum++,
                        $participants[$i],
                        $participants[$j],
                        MATCH_SCHEDULED
                    ]);
                }
            }

            $updateTourney = $pdo->prepare('UPDATE tournaments SET status = ? WHERE id = ?');
            $updateTourney->execute([STATUS_LIVE, $tournamentId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Round Robin): ' . $e->getMessage());
            throw new Exception("Failed to generate league: " . $e->getMessage());
        }
    }
}
