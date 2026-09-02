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

            $stmtWinners = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 0');
            $stmtWinners->execute([$tournamentId]);
            $winners = $stmtWinners->fetchAll(PDO::FETCH_COLUMN);

            $stmtLosers = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 0 AND losses = 1');
            $stmtLosers->execute([$tournamentId]);
            $losers = $stmtLosers->fetchAll(PDO::FETCH_COLUMN);

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

            // Pair Winners
            if (count($winners) % 2 !== 0) {
                $byeWinner = array_pop($winners);
                self::updateRecord($pdo, $tournamentId, $byeWinner, true);
                $insertByeMatch->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $byeWinner, $byeWinner, MATCH_BYE]);
            }
            for ($i = 0; $i < count($winners); $i += 2) {
                $insertMatch->execute([$tournamentId, ROUND_STAGE1_R2, $matchNumber++, $winners[$i], $winners[$i + 1], MATCH_SCHEDULED]);
            }

            // Pair Losers
            if (count($losers) % 2 !== 0) {
                $byeLoser = array_pop($losers);
                self::updateRecord($pdo, $tournamentId, $byeLoser, true);
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
     * Survival Round
     */
    public static function saveSurvivalRound(int $tournamentId, array $pairs, ?int $byePlayerId, int $adminId): void {
        if (!self::isRoundComplete($tournamentId, ROUND_STAGE1_R2)) {
            throw new Exception("Round 2 is not fully completed yet.");
        }

        $pdo = db();
        $tourney = getTournament($tournamentId);
        $isDoubles = ($tourney['match_type'] === 'doubles');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND round_key = ?');
        $stmt->execute([$tournamentId, ROUND_STAGE1_SURVIVAL]);
        if ($stmt->fetchColumn() > 0) throw new Exception("Survival already exists.");

        try {
            $pdo->beginTransaction();

            $pdo->prepare('UPDATE player_tournament_records SET tier = ? WHERE tournament_id = ? AND wins >= 2')->execute([TIER_1, $tournamentId]);
            $pdo->prepare('UPDATE player_tournament_records SET tier = ?, is_eliminated = TRUE WHERE tournament_id = ? AND losses >= 2')->execute([TIER_ELIMINATED, $tournamentId]);

            $stmtSurvival = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND wins = 1 AND losses = 1');
            $stmtSurvival->execute([$tournamentId]);
            $survivors = $stmtSurvival->fetchAll(PDO::FETCH_COLUMN);

            shuffle($survivors);

            $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
            $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
            $colWin = $isDoubles ? 'winner_team_id' : 'winner_player_id';
            $insertMatch = $pdo->prepare("INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, status) VALUES (?, ?, 'stage1', ?, ?, ?, ?)");
            $insertByeMatch = $pdo->prepare("INSERT INTO matches (tournament_id, round_key, stage, match_number, {$colA}, {$colB}, {$colWin}, status) VALUES (?, ?, 'stage1', ?, ?, NULL, ?, ?)");

            $matchNumber = 1;
            if (count($survivors) % 2 !== 0) {
                // If a manual bye is specified, extract it. Otherwise auto array_pop
                if ($byePlayerId && in_array($byePlayerId, $survivors)) {
                    $survivors = array_diff($survivors, [$byePlayerId]);
                    $survivors = array_values($survivors);
                    $byeSurvivor = $byePlayerId;
                } else {
                    $byeSurvivor = array_pop($survivors);
                }
                
                self::updateRecord($pdo, $tournamentId, $byeSurvivor, true);
                $insertByeMatch->execute([$tournamentId, ROUND_STAGE1_SURVIVAL, $matchNumber++, $byeSurvivor, $byeSurvivor, MATCH_BYE]);
            }
            
            if (!empty($pairs)) {
                foreach ($pairs as $pair) {
                    $insertMatch->execute([$tournamentId, ROUND_STAGE1_SURVIVAL, $matchNumber++, $pair[0], $pair[1], MATCH_SCHEDULED]);
                }
            } else {
                for ($i = 0; $i < count($survivors); $i += 2) {
                    $insertMatch->execute([$tournamentId, ROUND_STAGE1_SURVIVAL, $matchNumber++, $survivors[$i], $survivors[$i + 1], MATCH_SCHEDULED]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Matchmaker Error (Survival): ' . $e->getMessage());
            throw new Exception("Failed to generate Survival: " . $e->getMessage());
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
            $stmtT1 = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND tier = ?');
            $stmtT1->execute([$tournamentId, TIER_1]);
            $tier1 = $stmtT1->fetchAll(PDO::FETCH_COLUMN);

            $stmtT2 = $pdo->prepare('SELECT player_id FROM player_tournament_records WHERE tournament_id = ? AND tier = ?');
            $stmtT2->execute([$tournamentId, TIER_2]);
            $tier2 = $stmtT2->fetchAll(PDO::FETCH_COLUMN);

            shuffle($tier1);
            shuffle($tier2);
            $allPlayers = array_merge($tier1, $tier2); // Seed 1 to K
            
            // FREEZE SEEDS
            $updateSeed = $pdo->prepare('UPDATE player_tournament_records SET seed = ? WHERE tournament_id = ? AND player_id = ?');
            foreach ($allPlayers as $idx => $pid) {
                $updateSeed->execute([$idx + 1, $tournamentId, $pid]);
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
    
    // Kept to prevent crashing other parts of the admin if they explicitly call saveCustomRound1
    public static function saveCustomRound1(int $tournamentId, array $pairs, ?int $byePlayerId, int $adminId): void {
        throw new Exception("Custom pairing not updated for new Universal engine yet.");
    }
    public static function saveCustomRound2(int $tournamentId, array $pairs, ?int $byePlayerId, int $adminId): void {
        throw new Exception("Custom pairing not updated for new Universal engine yet.");
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
