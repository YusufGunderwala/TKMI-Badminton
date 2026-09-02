<?php
// ============================================================
// Live Scorer Engine
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

class Scorer {

    public static function getMatchData(int $matchId) {
        $pdo = db();
        
        // High-speed lean fetch for point calculations
        $stmt = $pdo->prepare('
            SELECT m.*, rc.best_of, rc.points_per_game, rc.deuce_enabled, rc.deuce_trigger, rc.deuce_cap
            FROM matches m
            JOIN round_configs rc ON m.tournament_id = rc.tournament_id AND m.round_key = rc.round_key
            WHERE m.id = ?
        ');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        
        if (!$match) throw new Exception("Match not found or configuration missing.");
        return $match;
    }

    public static function addPoint(int $matchId, string $player, int $adminId): array {
        $pdo = db();

        if (!in_array($player, ['A', 'B'])) {
            throw new Exception("Invalid player target.");
        }

        try {
            $pdo->beginTransaction();
            
            // Lock the row to prevent concurrent scoring race conditions
            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);
            
            $match = self::getMatchData($matchId);
            
            if ($match['status'] === MATCH_COMPLETED) {
                throw new Exception("Match is already completed.");
            }

            // Mark In Progress if scheduled
            if ($match['status'] === MATCH_SCHEDULED) {
                $pdo->prepare('UPDATE matches SET status = ?, started_at = NOW() WHERE id = ?')
                    ->execute([MATCH_IN_PROGRESS, $matchId]);
                $match['status'] = MATCH_IN_PROGRESS;
            }

            $scoreA = (int)$match['score_a'];
            $scoreB = (int)$match['score_b'];
            $gamesA = (int)$match['games_a'];
            $gamesB = (int)$match['games_b'];

            if ($player === 'A') $scoreA++; else $scoreB++;

            // Record audit trail
            $pdo->prepare('INSERT INTO score_events (match_id, action_type, player_a_score, player_b_score, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$matchId, "point_$player", $scoreA, $scoreB, $adminId]);

            // Check if game is won
            $gameWonBy = self::checkGameWin($scoreA, $scoreB, $match);
            $matchCompleted = false;
            $matchPointReached = false;

            if ($gameWonBy) {
                $tempGamesA = $gamesA + ($gameWonBy === 'A' ? 1 : 0);
                $tempGamesB = $gamesB + ($gameWonBy === 'B' ? 1 : 0);
                $gamesNeededToWin = ceil((int)$match['best_of'] / 2);

                if ($tempGamesA >= $gamesNeededToWin || $tempGamesB >= $gamesNeededToWin) {
                    // Deciding Match Point Reached! Keep points live for confirmation/undo
                    $matchPointReached = true;
                    $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ? WHERE id = ?')
                        ->execute([$scoreA, $scoreB, $matchId]);
                } else {
                    // Set Won in multi-set game -> Advance to next set
                    $gamesA = $tempGamesA;
                    $gamesB = $tempGamesB;

                    $pdo->prepare('INSERT INTO score_events (match_id, action_type, player_a_score, player_b_score, created_by) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$matchId, "game_$gameWonBy", $scoreA, $scoreB, $adminId]);

                    $gameNum = $gamesA + $gamesB;
                    $pdo->prepare('INSERT INTO games (match_id, game_number, score_a, score_b, winner_side, ended_at) VALUES (?, ?, ?, ?, ?, NOW())')
                        ->execute([$matchId, $gameNum, $scoreA, $scoreB, $gameWonBy]);

                    $scoreA = 0; $scoreB = 0;
                    $pdo->prepare('UPDATE matches SET score_a = 0, score_b = 0, games_a = ?, games_b = ? WHERE id = ?')
                        ->execute([$gamesA, $gamesB, $matchId]);
                }
            } else {
                // Just update live points
                $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ? WHERE id = ?')
                    ->execute([$scoreA, $scoreB, $matchId]);
            }

            $pdo->commit();

            return [
                'success'             => true,
                'score_a'             => $scoreA,
                'score_b'             => $scoreB,
                'games_a'             => $gamesA,
                'games_b'             => $gamesB,
                'is_completed'        => false,
                'match_point_reached' => $matchPointReached,
                'potential_winner'    => $gameWonBy,
                'event_type'          => $matchPointReached ? 'match_point' : ($gameWonBy ? 'game_win' : 'point'),
                'momentum_a'          => 50,
                'momentum_b'          => 50
            ];

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Scoring Error: ' . $e->getMessage());
            throw new Exception("Failed to update score: " . $e->getMessage());
        }
    }

    public static function undoPoint(int $matchId, string $player, int $adminId): array {
        $pdo = db();

        if (!in_array($player, ['A', 'B'])) {
            throw new Exception("Invalid player target.");
        }

        try {
            $pdo->beginTransaction();
            
            // Lock the row to prevent concurrent scoring race conditions
            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);
            
            $match = self::getMatchData($matchId);
            
            if ($match['status'] === MATCH_COMPLETED) {
                throw new Exception("Cannot undo. Match is already completed.");
            }
            
            $scoreA = (int)$match['score_a'];
            $scoreB = (int)$match['score_b'];

            if ($player === 'A' && $scoreA > 0) $scoreA--;
            elseif ($player === 'B' && $scoreB > 0) $scoreB--;
            else throw new Exception("Score is already at zero.");

            // Update scores
            $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ? WHERE id = ?')
                ->execute([$scoreA, $scoreB, $matchId]);

            // Log Undo
            $pdo->prepare('INSERT INTO score_events (match_id, action_type, player_a_score, player_b_score, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$matchId, "undo_$player", $scoreA, $scoreB, $adminId]);

            $pdo->commit();

            return [
                'success' => true,
                'score_a' => $scoreA,
                'score_b' => $scoreB
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception("Undo failed: " . $e->getMessage());
        }
    }

    public static function declareWalkover(int $matchId, string $winnerSide, string $reason, string $notes, int $adminId): void {
        $pdo = db();
        $match = self::getMatchData($matchId);
        
        if (in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
            throw new Exception("Match is already finalized.");
        }

        try {
            $pdo->beginTransaction();

            $gamesNeeded = ceil((int)$match['best_of'] / 2);
            $target = (int)$match['points_per_game'];
            $isDoubles = !empty($match['team_a_id']);
            
            if ($winnerSide === 'A') {
                $scoreA = $target; $scoreB = 0;
                $gamesA = $gamesNeeded; $gamesB = 0;
                $winnerId = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
                $loserId  = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
            } else {
                $scoreA = 0; $scoreB = $target;
                $gamesA = 0; $gamesB = $gamesNeeded;
                $winnerId = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
                $loserId  = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
            }

            // Log Walkover
            $pdo->prepare('INSERT INTO score_events (match_id, action_type, notes, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$matchId, 'walkover', "Reason: $reason. Notes: $notes", $adminId]);

            self::finalizeMatch($pdo, $match, $winnerId, $loserId, $scoreA, $scoreB, $gamesA, $gamesB, $isDoubles, MATCH_WALKOVER, $reason, $notes);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception("Walkover failed: " . $e->getMessage());
        }
    }

    public static function declareRetirement(int $matchId, string $winnerSide, string $reason, string $notes, int $adminId): void {
        $pdo = db();
        $match = self::getMatchData($matchId);
        
        if (in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
            throw new Exception("Match is already finalized.");
        }

        try {
            $pdo->beginTransaction();

            $scoreA = (int)$match['score_a'];
            $scoreB = (int)$match['score_b'];
            $gamesA = (int)$match['games_a'];
            $gamesB = (int)$match['games_b'];
            $isDoubles = !empty($match['team_a_id']);
            
            if ($winnerSide === 'A') {
                $winnerId = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
                $loserId  = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
            } else {
                $winnerId = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
                $loserId  = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
            }
            
            $gamesNeeded = ceil((int)$match['best_of'] / 2);
            if ($winnerSide === 'A') $gamesA = $gamesNeeded; else $gamesB = $gamesNeeded;

            $pdo->prepare('INSERT INTO score_events (match_id, action_type, notes, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$matchId, 'retired', "Reason: $reason. Notes: $notes", $adminId]);

            self::finalizeMatch($pdo, $match, $winnerId, $loserId, $scoreA, $scoreB, $gamesA, $gamesB, $isDoubles, MATCH_RETIRED, $reason, $notes);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception("Retirement failed: " . $e->getMessage());
        }
    }

    public static function finalizeMatchDirect(int $matchId, ?string $winnerSide = null, int $adminId = 0, ?int $clientScoreA = null, ?int $clientScoreB = null): array {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            // Lock row so concurrent point updates finish first
            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);

            $match = self::getMatchData($matchId);

            if (in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
                $pdo->commit();
                return [
                    'success'      => true,
                    'is_completed' => true,
                    'score_a'      => (int)$match['score_a'],
                    'score_b'      => (int)$match['score_b'],
                    'games_a'      => (int)$match['games_a'],
                    'games_b'      => (int)$match['games_b'],
                    'redirect_url' => BASE_URL . '/admin/scoring/index.php?tournament_id=' . $match['tournament_id']
                ];
            }

            $scoreA = (int)$match['score_a'];
            $scoreB = (int)$match['score_b'];
            $gamesA = (int)$match['games_a'];
            $gamesB = (int)$match['games_b'];
            $isDoubles = !empty($match['team_a_id']);
            $gamesNeeded = ceil((int)$match['best_of'] / 2);

            // Sync client score if client just scored winning point and it hasn't landed in DB
            if ($clientScoreA !== null && $clientScoreB !== null) {
                if ($clientScoreA > $scoreA || $clientScoreB > $scoreB) {
                    $scoreA = max($scoreA, $clientScoreA);
                    $scoreB = max($scoreB, $clientScoreB);
                    $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ? WHERE id = ?')
                        ->execute([$scoreA, $scoreB, $matchId]);
                }
            }

            // Determine winner based strictly on match rules
            $gameWonBy = self::checkGameWin($scoreA, $scoreB, $match);
            
            if ($gamesA >= $gamesNeeded) {
                $winnerSide = 'A';
            } elseif ($gamesB >= $gamesNeeded) {
                $winnerSide = 'B';
            } elseif ($gameWonBy) {
                if ($gameWonBy === 'A') $gamesA++;
                else $gamesB++;
                
                if ($gamesA >= $gamesNeeded) {
                    $winnerSide = 'A';
                } elseif ($gamesB >= $gamesNeeded) {
                    $winnerSide = 'B';
                } else {
                    throw new Exception("Cannot finalize match. Not enough games won to complete match.");
                }
            } elseif ($winnerSide && ($winnerSide === 'A' || $winnerSide === 'B')) {
                // If explicitly finalized by admin at match point
                if ($winnerSide === 'A') $gamesA = max($gamesA + 1, $gamesNeeded);
                else $gamesB = max($gamesB + 1, $gamesNeeded);
            } else {
                throw new Exception("Cannot finalize match. The current game has not reached the target score.");
            }

            if ($winnerSide === 'A') {
                $winnerId = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
                $loserId  = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
            } else {
                $winnerId = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
                $loserId  = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
            }

            // Save completed set into 'games' table if not already recorded
            $gameNum = $gamesA + $gamesB;
            $chkGame = $pdo->prepare('SELECT id FROM games WHERE match_id = ? AND game_number = ?');
            $chkGame->execute([$matchId, $gameNum]);
            if (!$chkGame->fetch()) {
                $pdo->prepare('INSERT INTO games (match_id, game_number, score_a, score_b, winner_side, ended_at) VALUES (?, ?, ?, ?, ?, NOW())')
                    ->execute([$matchId, $gameNum, $scoreA, $scoreB, $winnerSide]);
            }

            // Record audit event
            $pdo->prepare('INSERT INTO score_events (match_id, action_type, player_a_score, player_b_score, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$matchId, 'match_completed', $scoreA, $scoreB, "Winner: $winnerSide", $adminId]);

            self::finalizeMatch($pdo, $match, $winnerId, $loserId, $scoreA, $scoreB, $gamesA, $gamesB, $isDoubles, MATCH_COMPLETED);

            $pdo->commit();

            return [
                'success'      => true,
                'is_completed' => true,
                'score_a'      => $scoreA,
                'score_b'      => $scoreB,
                'games_a'      => $gamesA,
                'games_b'      => $gamesB,
                'winner_side'  => $winnerSide,
                'redirect_url' => BASE_URL . '/admin/scoring/index.php?tournament_id=' . $match['tournament_id']
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new Exception("Finalizing match failed: " . $e->getMessage());
        }
    }

    private static function checkGameWin(int $scoreA, int $scoreB, array $match): ?string {
        $target = (int)$match['points_per_game'];
        $deuceEnabled = (bool)$match['deuce_enabled'];
        $trigger = (int)$match['deuce_trigger'];
        $cap = (int)$match['deuce_cap'];

        if (!$deuceEnabled) {
            if ($scoreA >= $target) return 'A';
            if ($scoreB >= $target) return 'B';
            return null;
        }

        $leader = ($scoreA > $scoreB) ? 'A' : 'B';
        $maxScore = max($scoreA, $scoreB);
        $diff = abs($scoreA - $scoreB);

        if ($maxScore < $target) return null;
        if ($maxScore >= $cap) return $leader;
        if ($scoreA >= $trigger && $scoreB >= $trigger) {
            if ($diff >= 2) return $leader;
            return null;
        }
        if ($maxScore >= $target) return $leader;

        return null;
    }

    private static function finalizeMatch(PDO $pdo, array $match, int $winnerId, int $loserId, int $scoreA, int $scoreB, int $gamesA, int $gamesB, bool $isDoubles, string $status = MATCH_COMPLETED, ?string $walkoverReason = null, ?string $walkoverNotes = null): void {
        $matchId = $match['id'];
        
        // 1. Mark Match Complete and store Loser Fields
        if ($isDoubles) {
            $pdo->prepare('
                UPDATE matches 
                SET score_a = ?, score_b = ?, games_a = ?, games_b = ?, status = ?, winner_team_id = ?, loser_team_id = ?, walkover_reason = ?, walkover_notes = ?, ended_at = NOW() 
                WHERE id = ?
            ')->execute([$scoreA, $scoreB, $gamesA, $gamesB, $status, $winnerId, $loserId, $walkoverReason, $walkoverNotes, $matchId]);
        } else {
            $pdo->prepare('
                UPDATE matches 
                SET score_a = ?, score_b = ?, games_a = ?, games_b = ?, status = ?, winner_player_id = ?, loser_player_id = ?, walkover_reason = ?, walkover_notes = ?, ended_at = NOW() 
                WHERE id = ?
            ')->execute([$scoreA, $scoreB, $gamesA, $gamesB, $status, $winnerId, $loserId, $walkoverReason, $walkoverNotes, $matchId]);
        }

        // 2. Update Tournament Records (Swiss/Two-Loss Tracker)
        // Ensure we don't accidentally update 0 for teams if this table tracks players only.
        // Assuming player_tournament_records tracks individuals. For doubles, we should update both players.
        if ($match['stage'] === 'stage1') {
            if ($isDoubles) {
                $stmt = $pdo->prepare('SELECT player1_id, player2_id FROM teams WHERE id = ?');
                
                $stmt->execute([$winnerId]);
                $wTeam = $stmt->fetch();
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id IN (?, ?)')
                    ->execute([$match['tournament_id'], $wTeam['player1_id'], $wTeam['player2_id']]);
                    
                $stmt->execute([$loserId]);
                $lTeam = $stmt->fetch();
                $pdo->prepare('UPDATE player_tournament_records SET losses = losses + 1 WHERE tournament_id = ? AND player_id IN (?, ?)')
                    ->execute([$match['tournament_id'], $lTeam['player1_id'], $lTeam['player2_id']]);
            } else {
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$match['tournament_id'], $winnerId]);
                $pdo->prepare('UPDATE player_tournament_records SET losses = losses + 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$match['tournament_id'], $loserId]);
            }
        }

        // 3. Bracket Progression
        if ($match['next_match_id_winner']) {
            self::fillBracketSlot($pdo, $match['next_match_id_winner'], $winnerId, $isDoubles);
        }
        if ($match['next_match_id_loser']) {
            self::fillBracketSlot($pdo, $match['next_match_id_loser'], $loserId, $isDoubles);
        }
    }

    private static function fillBracketSlot(PDO $pdo, int $nextMatchId, int $entityId, bool $isDoubles): void {
        $stmt = $pdo->prepare('SELECT participant_a_id, participant_b_id, team_a_id, team_b_id FROM matches WHERE id = ?');
        $stmt->execute([$nextMatchId]);
        $nextMatch = $stmt->fetch();
        
        if (!$nextMatch) return;

        if ($isDoubles) {
            if (empty($nextMatch['team_a_id'])) {
                $pdo->prepare('UPDATE matches SET team_a_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
            } elseif (empty($nextMatch['team_b_id'])) {
                $pdo->prepare('UPDATE matches SET team_b_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
            }
        } else {
            if (empty($nextMatch['participant_a_id'])) {
                $pdo->prepare('UPDATE matches SET participant_a_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
            } elseif (empty($nextMatch['participant_b_id'])) {
                $pdo->prepare('UPDATE matches SET participant_b_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
            }
        }
    }
}
