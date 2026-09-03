<?php
// ============================================================
// Tournaments - Matchmaking & Bracket Engine
// ============================================================

class Matchmaker {

    /**
     * Checks if a round is 100% completed.
     */
    public static function isRoundComplete(int $tournamentId, string $roundKey): bool {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN (?, ?, ?, ?, ?) THEN 1 ELSE 0 END) as completed
            FROM matches 
            WHERE tournament_id = ? AND round_key = ?
        ');
        $stmt->execute([MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED, MATCH_CANCELLED, MATCH_BYE, $tournamentId, $roundKey]);
        $row = $stmt->fetch();
        
        return (int)$row['total'] > 0 && (int)$row['total'] === (int)$row['completed'];
    }

    /**
     * Helper to get participant IDs regardless of Singles/Doubles
     */
    public static function getParticipants(int $tournamentId): array {
        $tourney = getTournament($tournamentId);
        $pdo = db();
        if ($tourney['match_type'] === 'doubles') {
            $stmt = $pdo->prepare('SELECT id FROM teams WHERE tournament_id = ?');
        } else {
            $stmt = $pdo->prepare('SELECT player_id FROM tournament_players WHERE tournament_id = ?');
        }
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Helper to get total number of participants
     */
    public static function getParticipantCount(int $tournamentId): int {
        return count(self::getParticipants($tournamentId));
    }

    /**
     * 1. Generate Structure Manifest (Phase 2)
     */
    public static function generateStructureManifest(int $tournamentId): array {
        $tourney = getTournament($tournamentId);
        $n = self::getParticipantCount($tournamentId);
        
        if ($tourney['format'] === 'swiss_knockout') {
            if ($n < 4) {
                throw new Exception("Swiss format requires at least 4 participants.");
            }
            
            // Expected Qualifiers K = N / 2
            $k = ceil($n / 2);
            
            // Bracket size B = smallest power of 2 >= K
            $b = 2;
            while ($b < $k) {
                $b *= 2;
            }
            
            $stage2_rounds = [];
            if ($b >= 32) $stage2_rounds[] = ROUND_R32;
            if ($b >= 16) $stage2_rounds[] = ROUND_R16;
            if ($b >= 8)  $stage2_rounds[] = ROUND_QF;
            if ($b >= 4)  $stage2_rounds[] = ROUND_SF;
            $stage2_rounds[] = ROUND_3RD_PLACE; // Assuming 3rd place is enabled by default
            $stage2_rounds[] = ROUND_FINAL;
            
            $manifest = [
                'participants' => $n,
                'stage_1' => [
                    'rounds' => [ROUND_STAGE1_R1, ROUND_STAGE1_R2, ROUND_STAGE1_SURVIVAL],
                    'expected_qualifiers' => $k
                ],
                'stage_2' => [
                    'qualifiers' => $k,
                    'bracket_size' => $b,
                    'byes' => $b - $k,
                    'has_third_place' => true,
                    'rounds' => $stage2_rounds
                ]
            ];
            
        } else if ($tourney['format'] === 'round_robin') {
            if ($n < 2) {
                throw new Exception("Round Robin requires at least 2 participants.");
            }
            $manifest = [
                'participants' => $n,
                'stage_1' => [
                    'rounds' => ['round_robin'],
                    'expected_qualifiers' => $n
                ],
                'stage_2' => []
            ];
        } else {
            $manifest = ['participants' => $n];
        }
        
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE tournaments SET structure_manifest = ?, status = ? WHERE id = ?');
        $stmt->execute([json_encode($manifest), 'structure_ready', $tournamentId]);
        AppCache::forget('tournament_' . $tournamentId);
        AppCache::flush();
        
        return $manifest;
    }

    /**
     * Helper: Initialize Player/Team Tournament Records (Generic)
     */
    private static function initRecords(PDO $pdo, int $tournamentId, array $participants): void {
        $insertRecord = $pdo->prepare('
            INSERT INTO player_tournament_records (tournament_id, player_id, wins, losses, tier, is_eliminated) 
            VALUES (?, ?, 0, 0, ?, false)
            ON CONFLICT (tournament_id, player_id) DO NOTHING
        ');
        foreach ($participants as $pid) {
            $insertRecord->execute([$tournamentId, $pid, TIER_ACTIVE]);
        }
    }
    
    /**
     * Helper: Update Participant Record (Win/Loss)
     */
    private static function updateRecord(PDO $pdo, int $tournamentId, int $participantId, bool $isWin): void {
        if ($isWin) {
            $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?')->execute([$tournamentId, $participantId]);
        } else {
            $pdo->prepare('UPDATE player_tournament_records SET losses = losses + 1 WHERE tournament_id = ? AND player_id = ?')->execute([$tournamentId, $participantId]);
        }
    }

    private static function updateParticipantRecord(PDO $pdo, int $tournamentId, int $entityId, bool $isWin, bool $isDoubles): void {
        if ($isDoubles) {
            $stmt = $pdo->prepare('SELECT player1_id, player2_id FROM teams WHERE id = ?');
            $stmt->execute([$entityId]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($team) {
                $col = $isWin ? 'wins = wins + 1' : 'losses = losses + 1';
                $pdo->prepare("UPDATE player_tournament_records SET {$col} WHERE tournament_id = ? AND player_id IN (?, ?)")
                    ->execute([$tournamentId, $team['player1_id'], $team['player2_id']]);
            }
        } else {
            self::updateRecord($pdo, $tournamentId, $entityId, $isWin);
        }
    }

    /**
     * Generates Stage 1 - Round 1
     */
    public static function generateSwissRound1(int $tournamentId, int $adminId): void {
        $pdo = db();
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');
        $manifest = json_decode($tourney['structure_manifest'], true);
        if (!$manifest) throw new Exception("Structure Manifest not found. Please generate structure first.");

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R1]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 1 already generated.");

        $participants = self::getParticipants($tournamentId);
        if (count($participants) < 4) throw new Exception("Need at least 4 participants.");

        try {
            $pdo->beginTransaction();

            self::initRecords($pdo, $tournamentId, $participants);

            // Random draw
            shuffle($participants);

            $byeParticipant = null;
            if (count($participants) % 2 !== 0) {
                $byeParticipant = array_pop($participants);
                self::updateRecord($pdo, $tournamentId, $byeParticipant, true); // BYE counts as a win
            }

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';

            $insertMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status)
                VALUES (?, ?, 'stage1', ?, ?, ?, ?)
            ");

            $matchNumber = 1;
            for ($i = 0; $i < count($participants); $i += 2) {
                $insertMatch->execute([
                    $tournamentId, ROUND_STAGE1_R1, $matchNumber++, $participants[$i], $participants[$i+1], MATCH_SCHEDULED
                ]);
            }
            
            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';
            if ($byeParticipant) {
                $pdo->prepare("
                    INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, {$colWin}, status)
                    VALUES (?, ?, 'stage1', ?, ?, NULL, ?, ?)
                ")->execute([$tournamentId, ROUND_STAGE1_R1, $matchNumber++, $byeParticipant, $byeParticipant, MATCH_BYE]);
            }

            // Status moves to live
            $pdo->prepare('UPDATE tournaments SET status = ? WHERE id = ?')->execute(['live', $tournamentId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "Matchmaker Error (Round 1): " . $e->getMessage() . "\n";
            error_log('Matchmaker Error (Round 1): ' . $e->getMessage());
            throw new Exception("Failed to generate Round 1: " . $e->getMessage());
        }
    }

    /**
     * Generates Stage 1 - Round 2
     */
    public static function generateSwissRound2(int $tournamentId, int $adminId): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_R1)) {
            throw new Exception("Round 1 is not fully completed yet.");
        }

        $pdo = db();
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R2]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 2 already exists.");

        try {
            $pdo->beginTransaction();

            if ($isDoubles) {
                $stmtWinners = $pdo->prepare("SELECT winner_team_id FROM matches WHERE tournament_id = ? AND round_key = ? AND winner_team_id IS NOT NULL");
                $stmtWinners->execute([$tournamentId, ROUND_STAGE1_R1]);
                $winners = $stmtWinners->fetchAll(PDO::FETCH_COLUMN);

                $stmtLosers = $pdo->prepare("SELECT loser_team_id FROM matches WHERE tournament_id = ? AND round_key = ? AND loser_team_id IS NOT NULL");
                $stmtLosers->execute([$tournamentId, ROUND_STAGE1_R1]);
                $losers = $stmtLosers->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmtWinners = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 0');
                $stmtWinners->execute([$tournamentId]);
                $winners = $stmtWinners->fetchAll(PDO::FETCH_COLUMN);

                $stmtLosers = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 0 AND losses = 1');
                $stmtLosers->execute([$tournamentId]);
                $losers = $stmtLosers->fetchAll(PDO::FETCH_COLUMN);
            }

            shuffle($winners);
            shuffle($losers);

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
            
            $insertMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status)
                VALUES (?, ?, 'stage1', ?, ?, ?, ?)
            ");
            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';
            $insertByeMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, {$colWin}, status)
                VALUES (?, ?, 'stage1', ?, ?, NULL, ?, ?)
            ");

            $matchNumber = 1;

            // Pair Winners (1-0 vs 1-0)
            if (count($winners) % 2 !== 0) {
                $byeWinner = array_pop($winners);
                self::updateParticipantRecord($pdo, $tournamentId, $byeWinner, true, $isDoubles);
                $insertByeMatch->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $byeWinner, $byeWinner, MATCH_BYE]);
            }
            for ($i = 0; $i < count($winners); $i += 2) {
                $insertMatch->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $winners[$i], $winners[$i + 1], MATCH_SCHEDULED]);
            }

            // Pair Losers (0-1 vs 0-1)
            if (count($losers) % 2 !== 0) {
                $byeLoser = array_pop($losers);
                self::updateParticipantRecord($pdo, $tournamentId, $byeLoser, true, $isDoubles);
                $insertByeMatch->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $byeLoser, $byeLoser, MATCH_BYE]);
            }
            for ($i = 0; $i < count($losers); $i += 2) {
                $insertMatch->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $losers[$i], $losers[$i + 1], MATCH_SCHEDULED]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Round 2): ' . $e->getMessage());
            throw new Exception("Failed to generate Round 2: " . $e->getMessage());
        }
    }
    
    /**
     * Survival Round (handles 3 or 4 arguments seamlessly)
     */
    public static function saveSurvivalRound(int $tournamentId, array $pairs, mixed $arg3 = null, mixed $arg4 = null): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_R2)) {
            throw new Exception("Round 2 is not fully completed yet.");
        }

        $byePlayerId = null;
        $adminId = 0;
        if ($arg4 === null) {
            $adminId = (int)$arg3;
        } else {
            $byePlayerId = $arg3 ? (int)$arg3 : null;
            $adminId = (int)$arg4;
        }

        $pdo = db();
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_SURVIVAL]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Survival Round already exists.");

        try {
            $pdo->beginTransaction();

            $pdo->prepare('UPDATE player_tournament_records SET tier = ? WHERE tournament_id = ? AND wins >= 2')->execute([TIER_1, $tournamentId]);
            $pdo->prepare('UPDATE player_tournament_records SET tier = ?, is_eliminated = TRUE WHERE tournament_id = ? AND losses >= 2')->execute([TIER_ELIMINATED, $tournamentId]);

            if ($isDoubles) {
                $stmtSurvival = $pdo->prepare("
                    SELECT w.winner_team_id FROM matches w 
                    JOIN matches l ON w.winner_team_id = l.loser_team_id 
                    WHERE w.tournament_id = ? AND w.round_key IN ('r1', 'r2') 
                      AND l.tournament_id = ? AND l.round_key IN ('r1', 'r2')
                    GROUP BY w.winner_team_id
                ");
                $stmtSurvival->execute([$tournamentId, $tournamentId]);
                $survivors = $stmtSurvival->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmtSurvival = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 1');
                $stmtSurvival->execute([$tournamentId]);
                $survivors = $stmtSurvival->fetchAll(PDO::FETCH_COLUMN);
            }

            shuffle($survivors);

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';
            $insertMatch = $pdo->prepare("INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status) VALUES (?, ?, 'stage1', ?, ?, ?, ?)");
            $insertByeMatch = $pdo->prepare("INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, {$colWin}, status) VALUES (?, ?, 'stage1', ?, ?, NULL, ?, ?)");

            $matchNumber = 1;
            if (count($survivors) % 2 !== 0) {
                if ($byePlayerId && in_array($byePlayerId, $survivors)) {
                    $survivors = array_values(array_diff($survivors, [$byePlayerId]));
                    $byeSurvivor = $byePlayerId;
                } else {
                    $byeSurvivor = array_pop($survivors);
                }
                
                self::updateParticipantRecord($pdo, $tournamentId, $byeSurvivor, true, $isDoubles);
                $insertByeMatch->execute([$tournamentId, ROUND_STAGE1_SURVIVAL, $matchNumber++, $byeSurvivor, $byeSurvivor, MATCH_BYE]);
            }
            
            if (!empty($pairs)) {
                foreach ($pairs as $pair) {
                    $pA = (int)($pair[0] ?? 0);
                    $pB = (int)($pair[1] ?? 0);
                    if ($pA && $pB && $pA !== $pB) {
                        $insertMatch->execute([$tournamentId, ROUND_STAGE1_SURVIVAL, $matchNumber++, $pA, $pB, MATCH_SCHEDULED]);
                    }
                }
            } else {
                for ($i = 0; $i < count($survivors); $i += 2) {
                    if (isset($survivors[$i + 1])) {
                        $insertMatch->execute([$tournamentId, ROUND_STAGE1_SURVIVAL, $matchNumber++, $survivors[$i], $survivors[$i + 1], MATCH_SCHEDULED]);
                    }
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Survival): ' . $e->getMessage());
            throw new Exception("Failed to generate Survival Round: " . $e->getMessage());
        }
    }

    /**
     * Generates Stage 2 Bracket & allocates seeds
     */
    public static function generateStage2Bracket(int $tournamentId, int $adminId): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_SURVIVAL)) {
            throw new Exception("Stage 1 (Survival) is not fully completed yet.");
        }

        $pdo = db();
        $tourney = getTournament($tournamentId);
        $manifest = json_decode($tourney['structure_manifest'], true);
        if (!$manifest || !isset($manifest['stage_2'])) throw new Exception("Manifest invalid or stage 2 missing.");

        $isDoubles = ($tourney['match_type'] === 'doubles');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND stage = ?');
        $stmt->execute([$tournamentId, 'stage2']);
        if ($stmt->fetchColumn() > 0) throw new Exception("Stage 2 already generated.");

        try {
            $pdo->beginTransaction();

            // 1. Process Survival Results
            $pdo->prepare('UPDATE player_tournament_records SET tier = ? WHERE tournament_id = ? AND wins = 2 AND tier = ?')
                ->execute([TIER_2, $tournamentId, TIER_ACTIVE]);
            $pdo->prepare('UPDATE player_tournament_records SET tier = ?, is_eliminated = TRUE WHERE tournament_id = ? AND losses >= 2 AND wins < 2')
                ->execute([TIER_ELIMINATED, $tournamentId]);

            // 2. Fetch T1 and T2
            if ($isDoubles) {
                $stmtT1 = $pdo->prepare("
                    SELECT winner_team_id FROM matches 
                    WHERE tournament_id = ? AND round_key IN ('r1', 'r2') AND winner_team_id IS NOT NULL 
                    GROUP BY winner_team_id HAVING COUNT(*) = 2
                ");
                $stmtT1->execute([$tournamentId]);
                $tier1 = $stmtT1->fetchAll(PDO::FETCH_COLUMN);

                $stmtT2 = $pdo->prepare("
                    SELECT winner_team_id FROM matches 
                    WHERE tournament_id = ? AND round_key = ? AND winner_team_id IS NOT NULL
                ");
                $stmtT2->execute([$tournamentId, ROUND_STAGE1_SURVIVAL]);
                $tier2 = $stmtT2->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $stmtT1 = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND tier = ?');
                $stmtT1->execute([$tournamentId, TIER_1]);
                $tier1 = $stmtT1->fetchAll(PDO::FETCH_COLUMN);

                $stmtT2 = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND tier = ?');
                $stmtT2->execute([$tournamentId, TIER_2]);
                $tier2 = $stmtT2->fetchAll(PDO::FETCH_COLUMN);
            }

            shuffle($tier1);
            shuffle($tier2);
            $allPlayers = array_merge($tier1, $tier2); // Seed 1 to K
            
            // FREEZE SEEDS in tournament_players (where seed column exists)
            if ($isDoubles) {
                $updateSeed = $pdo->prepare('UPDATE tournament_players SET seed = ? WHERE tournament_id = ? AND player_id IN (SELECT player1_id FROM teams WHERE id = ? UNION SELECT player2_id FROM teams WHERE id = ?)');
                foreach ($allPlayers as $idx => $tid) {
                    $updateSeed->execute([$idx + 1, $tournamentId, $tid, $tid]);
                }
            } else {
                $updateSeed = $pdo->prepare('UPDATE tournament_players SET seed = ? WHERE tournament_id = ? AND player_id = ?');
                foreach ($allPlayers as $idx => $pid) {
                    $updateSeed->execute([$idx + 1, $tournamentId, $pid]);
                }
            }

            $bracketSize = (int)$manifest['stage_2']['bracket_size'];
            $k = count($allPlayers);
            $byesToGive = $bracketSize - $k;

            $mapping = self::getStandardBracketMapping($bracketSize);
            
            $slots = array_fill(1, $bracketSize, null);
            foreach ($allPlayers as $idx => $pid) {
                $seedNum = $idx + 1;
                $slots[$seedNum] = $pid;
            }

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';

            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';

            $insertMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status)
                VALUES (?, ?, 'stage2', ?, ?, ?, ?) RETURNING id
            ");

            // Build Rounds bottom-up
            // Create Final
            $insertMatch->execute([$tournamentId, ROUND_FINAL, 1, null, null, MATCH_SCHEDULED]);
            $finalId = $insertMatch->fetchColumn();
            
            $thirdPlaceId = null;
            if (!empty($manifest['stage_2']['has_third_place'])) {
                $insertMatch->execute([$tournamentId, ROUND_3RD_PLACE, 1, null, null, MATCH_SCHEDULED]);
                $thirdPlaceId = $insertMatch->fetchColumn();
            }

            // Create remaining tree
            $currentLevelIds = [$finalId];
            $matchesAtLevel = 1;
            
            while ($matchesAtLevel < $bracketSize / 2) {
                $matchesAtLevel *= 2;
                $nextRoundName = '';
                if ($matchesAtLevel == 2) $nextRoundName = ROUND_SF;
                elseif ($matchesAtLevel == 4) $nextRoundName = ROUND_QF;
                elseif ($matchesAtLevel == 8) $nextRoundName = ROUND_R16;
                elseif ($matchesAtLevel == 16) $nextRoundName = ROUND_R32;
                
                $nextLevelIds = [];
                $matchNum = 1;
                foreach ($currentLevelIds as $parentMatchId) {
                    for ($c=0; $c<2; $c++) {
                        $insertMatch->execute([$tournamentId, $nextRoundName, $matchNum++, null, null, MATCH_SCHEDULED]);
                        $childId = $insertMatch->fetchColumn();
                        $nextLevelIds[] = $childId;
                        
                        $pdo->prepare('UPDATE matches SET next_match_id_winner = ? WHERE id = ?')->execute([$parentMatchId, $childId]);
                        
                        if ($nextRoundName === ROUND_SF && $thirdPlaceId) {
                            $pdo->prepare('UPDATE matches SET next_match_id_loser = ? WHERE id = ?')->execute([$thirdPlaceId, $childId]);
                        }
                    }
                }
                $currentLevelIds = $nextLevelIds;
            }

            // Finally fill the first round matches based on the standard mapping topology
            foreach ($currentLevelIds as $idx => $matchId) {
                $pair = $mapping[$idx]; 
                $pA = $slots[$pair[0]];
                $pB = $slots[$pair[1]];
                
                if (($pA && !$pB) || (!$pA && $pB)) {
                    $winnerId = $pA ? $pA : $pB;
                    // Real BYE state
                    $pdo->prepare("UPDATE matches SET {$colA}=?, {$colB}=?, {$colWin}=?, status=? WHERE id=?")
                        ->execute([$pA, $pB, $winnerId, MATCH_BYE, $matchId]);
                    
                    // Advance winner
                    $stmt = $pdo->prepare('SELECT next_match_id_winner FROM matches WHERE id=?');
                    $stmt->execute([$matchId]);
                    $nextId = $stmt->fetchColumn();
                    if ($nextId) {
                        $stmt = $pdo->prepare("SELECT {$colA}, {$colB} FROM matches WHERE id=?");
                        $stmt->execute([$nextId]);
                        $nextMatch = $stmt->fetch();
                        if (empty($nextMatch[$colA])) {
                            $pdo->prepare("UPDATE matches SET {$colA}=? WHERE id=?")->execute([$winnerId, $nextId]);
                        } else {
                            $pdo->prepare("UPDATE matches SET {$colB}=? WHERE id=?")->execute([$winnerId, $nextId]);
                        }
                    }
                } else {
                    $pdo->prepare("UPDATE matches SET {$colA}=?, {$colB}=? WHERE id=?")
                        ->execute([$pA, $pB, $matchId]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Stage 2): ' . $e->getMessage());
            throw new Exception("Failed to generate Stage 2: " . $e->getMessage());
        }
    }

    /**
     * Standard Bracket Mapping Generator
     */
    private static function getStandardBracketMapping(int $size): array {
        if ($size < 2) return [];
        $matches = [[1, 2]];
        for ($s = 4; $s <= $size; $s *= 2) {
            $next = [];
            foreach ($matches as $match) {
                $next[] = [$match[0], $s - $match[0] + 1];
                $next[] = [$s - $match[1] + 1, $match[1]];
            }
            $matches = $next;
        }
        return $matches;
    }
    
    /**
     * Gets previous opponents for a player in a tournament
     */
    public static function getPreviousOpponents(int $tournamentId, int $playerId): array {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT participant_a_id, participant_b_id, team_a_id, team_b_id 
            FROM matches 
            WHERE tournament_id = ? AND (participant_a_id = ? OR participant_b_id = ? OR team_a_id = ? OR team_b_id = ?)
        ');
        $stmt->execute([$tournamentId, $playerId, $playerId, $playerId, $playerId]);
        $opponents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['participant_a_id']) && (int)$row['participant_a_id'] !== $playerId) {
                $opponents[] = (int)$row['participant_a_id'];
            }
            if (!empty($row['participant_b_id']) && (int)$row['participant_b_id'] !== $playerId) {
                $opponents[] = (int)$row['participant_b_id'];
            }
            if (!empty($row['team_a_id']) && (int)$row['team_a_id'] !== $playerId) {
                $opponents[] = (int)$row['team_a_id'];
            }
            if (!empty($row['team_b_id']) && (int)$row['team_b_id'] !== $playerId) {
                $opponents[] = (int)$row['team_b_id'];
            }
        }
        return array_values(array_unique($opponents));
    }

    /**
     * Returns Path 1 (Won R1, Lost R2) and Path 2 (Lost R1, Won R2) for 1-1 players
     */
    public static function getSurvivalPaths(int $tournamentId): array {
        $pdo = db();
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';
        $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
        $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';

        if ($isDoubles) {
            $stmtSurvival = $pdo->prepare("
                SELECT w.winner_team_id FROM matches w 
                JOIN matches l ON w.winner_team_id = l.loser_team_id 
                WHERE w.tournament_id = ? AND w.round_key IN ('r1', 'r2') 
                  AND l.tournament_id = ? AND l.round_key IN ('r1', 'r2')
                GROUP BY w.winner_team_id
            ");
            $stmtSurvival->execute([$tournamentId, $tournamentId]);
            $tied = $stmtSurvival->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 1');
            $stmt->execute([$tournamentId]);
            $tied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $path1 = []; // Won R1
        $path2 = []; // Lost R1

        foreach ($tied as $pid) {
            $checkR1 = $pdo->prepare("SELECT {$colWin} FROM matches WHERE tournament_id = ? AND round_key = ? AND ({$colA} = ? OR {$colB} = ?)");
            $checkR1->execute([$tournamentId, ROUND_STAGE1_R1, $pid, $pid]);
            $r1Winner = $checkR1->fetchColumn();

            if ($r1Winner == $pid) {
                $path1[] = (int)$pid; // Won R1
            } else {
                $path2[] = (int)$pid; // Lost R1
            }
        }

        return ['path1' => $path1, 'path2' => $path2];
    }

    /**
     * Saves custom manual pairings for Round 1
     */
    public static function saveCustomRound1(int $tournamentId, array $pairs, ?int $byePlayerId, int $adminId): void {
        $pdo = db();
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R1]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 1 matches have already been created.");

        $participants = self::getParticipants($tournamentId);
        if (count($participants) < 2) throw new Exception("Need at least 2 participants.");

        try {
            $pdo->beginTransaction();

            self::initRecords($pdo, $tournamentId, $participants);

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';

            $insertMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status)
                VALUES (?, ?, 'stage1', ?, ?, ?, ?)
            ");

            $matchNumber = 1;
            foreach ($pairs as $pair) {
                $pA = (int)($pair[0] ?? 0);
                $pB = (int)($pair[1] ?? 0);
                if (!$pA || !$pB || $pA === $pB) {
                    throw new Exception("Invalid match pairing detected ($pA vs $pB).");
                }
                $insertMatch->execute([
                    $tournamentId, ROUND_STAGE1_R1, $matchNumber++, $pA, $pB, MATCH_SCHEDULED
                ]);
            }

            if ($byePlayerId) {
                self::updateParticipantRecord($pdo, $tournamentId, $byePlayerId, true, $isDoubles);
                $pdo->prepare("
                    INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, {$colWin}, status)
                    VALUES (?, ?, 'stage1', ?, ?, NULL, ?, ?)
                ")->execute([$tournamentId, ROUND_STAGE1_R1, $matchNumber++, $byePlayerId, $byePlayerId, MATCH_BYE]);
            }

            $pdo->prepare('UPDATE tournaments SET status = ? WHERE id = ?')->execute(['live', $tournamentId]);

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
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_R2]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Round 2 matches already exist.");

        try {
            $pdo->beginTransaction();

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';

            $insertMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status)
                VALUES (?, ?, 'stage1', ?, ?, ?, ?)
            ");

            $matchNumber = 1;
            foreach ($pairs as $pair) {
                $pA = (int)($pair[0] ?? 0);
                $pB = (int)($pair[1] ?? 0);
                if (!$pA || !$pB || $pA === $pB) {
                    throw new Exception("Invalid match pairing detected ($pA vs $pB).");
                }
                $insertMatch->execute([
                    $tournamentId, ROUND_STAGE1_R2, $matchNumber++, $pA, $pB, MATCH_SCHEDULED
                ]);
            }

            if ($byePlayerId) {
                self::updateParticipantRecord($pdo, $tournamentId, $byePlayerId, true, $isDoubles);
                $pdo->prepare("
                    INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, {$colWin}, status)
                    VALUES (?, ?, 'stage1', ?, ?, NULL, ?, ?)
                ")->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $byePlayerId, $byePlayerId, MATCH_BYE]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Custom R2): ' . $e->getMessage());
            throw new Exception("Failed to save custom Round 2: " . $e->getMessage());
        }
    }

    /**
     * Determines Podium Winners (1st, 2nd, 3rd, 4th)
     */
    public static function getPodiumWinners(int $tournamentId): array {
        $pdo = db();
        $tourney = getTournament($tournamentId);
        if (!$tourney) return ['is_finished' => false, 'champion' => null, 'runner_up' => null, 'third' => null, 'fourth' => null];
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $result = [
            'is_doubles'  => $isDoubles,
            'is_finished' => false,
            'champion'    => null,
            'runner_up'   => null,
            'third'       => null,
            'fourth'      => null,
        ];

        // 1. Check Final match
        $stmtFinal = $pdo->prepare("SELECT * FROM matches WHERE tournament_id = ? AND round_key = ? ORDER BY id DESC LIMIT 1");
        $stmtFinal->execute([$tournamentId, ROUND_FINAL]);
        $finalMatch = $stmtFinal->fetch(PDO::FETCH_ASSOC);

        if ($finalMatch && in_array($finalMatch['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
            $winnerId = $isDoubles ? $finalMatch['winner_team_id'] : $finalMatch['winner_player_id'];
            $loserId  = $isDoubles ? $finalMatch['loser_team_id'] : $finalMatch['loser_player_id'];

            $result['champion']  = $winnerId ? ($isDoubles ? getTeam((int)$winnerId) : getPlayer((int)$winnerId)) : null;
            $result['runner_up'] = $loserId ? ($isDoubles ? getTeam((int)$loserId) : getPlayer((int)$loserId)) : null;
            $result['is_finished'] = true;
        }

        // 2. Check 3rd Place match
        $stmt3rd = $pdo->prepare("SELECT * FROM matches WHERE tournament_id = ? AND round_key = ? ORDER BY id DESC LIMIT 1");
        $stmt3rd->execute([$tournamentId, ROUND_3RD_PLACE]);
        $thirdMatch = $stmt3rd->fetch(PDO::FETCH_ASSOC);

        if ($thirdMatch && in_array($thirdMatch['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
            $w3 = $isDoubles ? $thirdMatch['winner_team_id'] : $thirdMatch['winner_player_id'];
            $l3 = $isDoubles ? $thirdMatch['loser_team_id'] : $thirdMatch['loser_player_id'];

            $result['third']  = $w3 ? ($isDoubles ? getTeam((int)$w3) : getPlayer((int)$w3)) : null;
            $result['fourth'] = $l3 ? ($isDoubles ? getTeam((int)$l3) : getPlayer((int)$l3)) : null;
        }

        // 3. Fallback for Round Robin
        if ($tourney['format'] === 'round_robin') {
            $incomplete = (int)$pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND status NOT IN ('completed', 'walkover', 'retired', 'cancelled', 'bye')")
                ->execute([$tournamentId]);
            $count = (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $tournamentId AND status NOT IN ('completed', 'walkover', 'retired', 'cancelled', 'bye')")->fetchColumn();
            $totalMatches = (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE tournament_id = $tournamentId")->fetchColumn();

            if ($totalMatches > 0 && $count === 0) {
                $result['is_finished'] = true;
                // Query standings directly
                $standingsStmt = $pdo->prepare("
                    SELECT p.id, COUNT(m.id) as wins 
                    FROM tournament_players tp
                    JOIN players p ON tp.player_id = p.id
                    LEFT JOIN matches m ON m.tournament_id = tp.tournament_id AND m.winner_player_id = p.id
                    WHERE tp.tournament_id = ?
                    GROUP BY p.id
                    ORDER BY wins DESC
                    LIMIT 4
                ");
                $standingsStmt->execute([$tournamentId]);
                $top = $standingsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (isset($top[0])) $result['champion'] = getPlayer((int)$top[0]['id']);
                if (isset($top[1])) $result['runner_up'] = getPlayer((int)$top[1]['id']);
                if (isset($top[2])) $result['third'] = getPlayer((int)$top[2]['id']);
                if (isset($top[3])) $result['fourth'] = getPlayer((int)$top[3]['id']);
            }
        }

        return $result;
    }

    public static function generateRoundRobin(int $tournamentId, int $adminId): void {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Matches have already been generated for this tournament.");

        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        try {
            $pdo->beginTransaction();
            $participants = self::getParticipants($tournamentId);
            $n = count($participants);
            if ($n < 2) throw new Exception("Need at least 2 participants.");

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
            
            $insertMatch = $pdo->prepare("
                INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status)
                VALUES (?, ?, 'stage1', ?, ?, ?, ?)
            ");

            $matchNum = 1;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $insertMatch->execute([
                        $tournamentId, 'round_robin', $matchNum++, $participants[$i], $participants[$j], MATCH_SCHEDULED
                    ]);
                }
            }

            $pdo->prepare('UPDATE tournaments SET status = ? WHERE id = ?')->execute(['live', $tournamentId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Round Robin): ' . $e->getMessage());
            throw new Exception("Failed to generate league: " . $e->getMessage());
        }
    }
}
