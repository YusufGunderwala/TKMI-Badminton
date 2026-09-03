<?php
// ============================================================
// Live Scorer Engine (Strict Tournament Grade)
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/cache.php';

class Scorer {

    public static function getMatchData(int $matchId) {
        $pdo = db();
        // Since rules are snapshotted in 'matches', we just read them.
        $stmt = $pdo->prepare('SELECT * FROM matches WHERE id = ?');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) throw new Exception("Match not found (ID: $matchId).");
        return $match;
    }

    public static function addPoint(int $matchId, string $player, int $adminId, string $requestId): array {
        $pdo = db();
        if (!in_array($player, ['A', 'B'])) {
            throw new Exception("Invalid player target.");
        }

        try {
            $pdo->beginTransaction();
            
            // Check for duplicate request early
            $stmt = $pdo->prepare('SELECT id FROM score_events WHERE match_id = ? AND request_id = ? AND is_undone = FALSE');
            $stmt->execute([$matchId, $requestId]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return self::getStateResponse(self::getMatchData($matchId));
            }

            // Lock match row
            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);
            $match = self::getMatchData($matchId);
            
            if (in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return self::getStateResponse($match);
            }

            if ($match['status'] === MATCH_SCHEDULED) {
                $pdo->prepare('UPDATE matches SET status = ?, started_at = NOW() WHERE id = ?')
                    ->execute([MATCH_IN_PROGRESS, $matchId]);
                $match['status'] = MATCH_IN_PROGRESS;
            }

            $prevScoreA = (int)$match['score_a'];
            $prevScoreB = (int)$match['score_b'];
            $prevGamesA = (int)$match['games_a'];
            $prevGamesB = (int)$match['games_b'];

            $newScoreA = $prevScoreA + ($player === 'A' ? 1 : 0);
            $newScoreB = $prevScoreB + ($player === 'B' ? 1 : 0);
            $newGamesA = $prevGamesA;
            $newGamesB = $prevGamesB;
            
            $gameCompleted = false;
            $matchCompleted = false;
            $gameId = null;

            // Check if game won
            $tempMatch = $match;
            $tempMatch['score_a'] = $newScoreA;
            $tempMatch['score_b'] = $newScoreB;
            $gameWonBy = self::checkGameWin($newScoreA, $newScoreB, $tempMatch);

            if ($gameWonBy) {
                $gameCompleted = true;
                $newGamesA += ($gameWonBy === 'A' ? 1 : 0);
                $newGamesB += ($gameWonBy === 'B' ? 1 : 0);

                $gamesNeededToWin = ceil((int)$match['best_of'] / 2);
                if ($newGamesA >= $gamesNeededToWin || $newGamesB >= $gamesNeededToWin) {
                    $matchCompleted = true;
                }
                
                // Record the game
                $gameNum = $newGamesA + $newGamesB;
                $stmtGame = $pdo->prepare('INSERT INTO games (match_id, game_number, score_a, score_b, winner_side, ended_at) VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id');
                $stmtGame->execute([$matchId, $gameNum, $newScoreA, $newScoreB, $gameWonBy]);
                $gameId = $stmtGame->fetchColumn();

                // If match not completed, reset points for next game
                if (!$matchCompleted) {
                    $newScoreA = 0;
                    $newScoreB = 0;
                }
            }

            // Get sequence_no
            $stmtSeq = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM score_events WHERE match_id = ?');
            $stmtSeq->execute([$matchId]);
            $sequenceNo = $stmtSeq->fetchColumn();

            // Insert single event
            $stmtEvent = $pdo->prepare("
                INSERT INTO score_events (
                    match_id, request_id, sequence_no, action_type, side,
                    previous_score_a, previous_score_b, previous_games_a, previous_games_b,
                    new_score_a, new_score_b, new_games_a, new_games_b,
                    game_completed, match_completed, game_id, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtEvent->execute([
                $matchId, $requestId, $sequenceNo, 'point', $player,
                $prevScoreA, $prevScoreB, $prevGamesA, $prevGamesB,
                $newScoreA, $newScoreB, $newGamesA, $newGamesB,
                $gameCompleted ? 1 : 0, $matchCompleted ? 1 : 0, $gameId, $adminId
            ]);

            // Update Match State
            $status = $matchCompleted ? MATCH_COMPLETED : $match['status'];
            $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ?, games_a = ?, games_b = ?, status = ? WHERE id = ?')
                ->execute([$newScoreA, $newScoreB, $newGamesA, $newGamesB, $status, $matchId]);
                
            $updatedMatch = self::getMatchData($matchId);

            if ($matchCompleted) {
                $isDoubles = !empty($match['team_a_id']);
                $winnerSide = ($newGamesA > $newGamesB) ? 'A' : 'B';
                $winnerId = ($winnerSide === 'A') ? ($isDoubles ? $match['team_a_id'] : $match['participant_a_id']) : ($isDoubles ? $match['team_b_id'] : $match['participant_b_id']);
                $loserId = ($winnerSide === 'A') ? ($isDoubles ? $match['team_b_id'] : $match['participant_b_id']) : ($isDoubles ? $match['team_a_id'] : $match['participant_a_id']);
                
                self::finalizeMatchRecords($pdo, $updatedMatch, $winnerId, $loserId, $newScoreA, $newScoreB, $newGamesA, $newGamesB, $isDoubles, MATCH_COMPLETED);
            }

            $pdo->commit();
            return self::getStateResponse($updatedMatch);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Scoring Error: ' . $e->getMessage());
            throw new Exception("Failed to update score: " . $e->getMessage());
        }
    }

    public static function undoLastAction(int $matchId, int $adminId, string $requestId): array {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            
            // Idempotency check for undo request itself
            $stmt = $pdo->prepare('SELECT id FROM score_events WHERE match_id = ? AND request_id = ? AND is_undone = FALSE');
            $stmt->execute([$matchId, $requestId]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return self::getStateResponse(self::getMatchData($matchId));
            }

            // Lock match
            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);
            $match = self::getMatchData($matchId);

            // Find last active event
            $stmt = $pdo->prepare("SELECT * FROM score_events WHERE match_id = ? AND is_undone = FALSE AND action_type NOT IN ('undo') ORDER BY sequence_no DESC LIMIT 1");
            $stmt->execute([$matchId]);
            $lastEvent = $stmt->fetch();
            
            if (!$lastEvent) {
                throw new Exception("No actions to undo.");
            }

            // If the match was completed by this event, we must reverse the progression records
            if ($lastEvent['match_completed']) {
                $isDoubles = !empty($match['team_a_id']);
                $winnerSide = ($lastEvent['new_games_a'] > $lastEvent['new_games_b']) ? 'A' : 'B';
                $winnerId = ($winnerSide === 'A') ? ($isDoubles ? $match['team_a_id'] : $match['participant_a_id']) : ($isDoubles ? $match['team_b_id'] : $match['participant_b_id']);
                $loserId = ($winnerSide === 'A') ? ($isDoubles ? $match['team_b_id'] : $match['participant_b_id']) : ($isDoubles ? $match['team_a_id'] : $match['participant_a_id']);
                
                self::reverseFinalizeMatchRecords($pdo, $match, $winnerId, $loserId, $isDoubles);
            }

            // If a game was completed, remove the game record
            if ($lastEvent['game_completed'] && $lastEvent['game_id']) {
                $pdo->prepare('DELETE FROM games WHERE id = ?')->execute([$lastEvent['game_id']]);
            }

            // Mark event undone
            $pdo->prepare('UPDATE score_events SET is_undone = TRUE, undone_at = NOW(), undone_by = ? WHERE id = ?')
                ->execute([$adminId, $lastEvent['id']]);

            // Add an undo log
            $stmtSeq = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM score_events WHERE match_id = ?');
            $stmtSeq->execute([$matchId]);
            $sequenceNo = $stmtSeq->fetchColumn();

            $stmtEvent = $pdo->prepare("
                INSERT INTO score_events (
                    match_id, request_id, sequence_no, action_type, side,
                    previous_score_a, previous_score_b, previous_games_a, previous_games_b,
                    new_score_a, new_score_b, new_games_a, new_games_b,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtEvent->execute([
                $matchId, $requestId, $sequenceNo, 'undo', $lastEvent['side'],
                $lastEvent['new_score_a'], $lastEvent['new_score_b'], $lastEvent['new_games_a'], $lastEvent['new_games_b'],
                $lastEvent['previous_score_a'], $lastEvent['previous_score_b'], $lastEvent['previous_games_a'], $lastEvent['previous_games_b'],
                $adminId
            ]);

            // Restore state
            $status = MATCH_IN_PROGRESS; // Revert to in progress
            
            $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ?, games_a = ?, games_b = ?, status = ?, winner_player_id = NULL, loser_player_id = NULL, winner_team_id = NULL, loser_team_id = NULL, walkover_reason = NULL, walkover_notes = NULL, ended_at = NULL WHERE id = ?')
                ->execute([$lastEvent['previous_score_a'], $lastEvent['previous_score_b'], $lastEvent['previous_games_a'], $lastEvent['previous_games_b'], $status, $matchId]);
            
            // Un-complete tournament just in case
            $pdo->prepare("UPDATE tournaments SET status = 'live' WHERE id = ?")->execute([$match['tournament_id']]);

            $pdo->commit();
            return self::getStateResponse(self::getMatchData($matchId));

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new Exception("Undo failed: " . $e->getMessage());
        }
    }

    public static function declareWalkover(int $matchId, string $winnerSide, string $reason, string $notes, int $adminId, string $requestId): array {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare('SELECT id FROM score_events WHERE match_id = ? AND request_id = ? AND is_undone = FALSE');
            $stmt->execute([$matchId, $requestId]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return self::getStateResponse(self::getMatchData($matchId));
            }

            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);
            $match = self::getMatchData($matchId);
            
            if (in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
                throw new Exception("Match is already finalized.");
            }

            $gamesNeeded = ceil((int)$match['best_of'] / 2);
            $target = (int)$match['points_per_game'];
            $isDoubles = !empty($match['team_a_id']);
            
            if ($winnerSide === 'A') {
                $newScoreA = $target; $newScoreB = 0;
                $newGamesA = $gamesNeeded; $newGamesB = 0;
                $winnerId = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
                $loserId  = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
            } else {
                $newScoreA = 0; $newScoreB = $target;
                $newGamesA = 0; $newGamesB = $gamesNeeded;
                $winnerId = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
                $loserId  = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
            }

            // Log event
            $stmtSeq = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM score_events WHERE match_id = ?');
            $stmtSeq->execute([$matchId]);
            $sequenceNo = $stmtSeq->fetchColumn();

            $stmtEvent = $pdo->prepare("
                INSERT INTO score_events (
                    match_id, request_id, sequence_no, action_type, side,
                    previous_score_a, previous_score_b, previous_games_a, previous_games_b,
                    new_score_a, new_score_b, new_games_a, new_games_b,
                    match_completed, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtEvent->execute([
                $matchId, $requestId, $sequenceNo, 'walkover', $winnerSide,
                $match['score_a'], $match['score_b'], $match['games_a'], $match['games_b'],
                $newScoreA, $newScoreB, $newGamesA, $newGamesB,
                1, $reason . ': ' . $notes, $adminId
            ]);

            self::finalizeMatchRecords($pdo, $match, $winnerId, $loserId, $newScoreA, $newScoreB, $newGamesA, $newGamesB, $isDoubles, MATCH_WALKOVER, $reason, $notes);

            $pdo->commit();
            return self::getStateResponse(self::getMatchData($matchId));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new Exception("Walkover failed: " . $e->getMessage());
        }
    }

    public static function declareRetirement(int $matchId, string $retiredSide, string $reason, string $notes, int $adminId, string $requestId): array {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare('SELECT id FROM score_events WHERE match_id = ? AND request_id = ? AND is_undone = FALSE');
            $stmt->execute([$matchId, $requestId]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return self::getStateResponse(self::getMatchData($matchId));
            }

            $pdo->prepare('SELECT id FROM matches WHERE id = ? FOR UPDATE')->execute([$matchId]);
            $match = self::getMatchData($matchId);
            
            if (in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED])) {
                throw new Exception("Match is already finalized.");
            }

            $gamesNeeded = ceil((int)$match['best_of'] / 2);
            $isDoubles = !empty($match['team_a_id']);
            
            if ($retiredSide === 'B') {
                $newGamesA = $gamesNeeded; 
                $newGamesB = $match['games_b'];
                $winnerId = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
                $loserId  = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
                $winnerSide = 'A';
            } else {
                $newGamesA = $match['games_a']; 
                $newGamesB = $gamesNeeded;
                $winnerId = $isDoubles ? $match['team_b_id'] : $match['participant_b_id'];
                $loserId  = $isDoubles ? $match['team_a_id'] : $match['participant_a_id'];
                $winnerSide = 'B';
            }

            // Log event
            $stmtSeq = $pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM score_events WHERE match_id = ?');
            $stmtSeq->execute([$matchId]);
            $sequenceNo = $stmtSeq->fetchColumn();

            $stmtEvent = $pdo->prepare("
                INSERT INTO score_events (
                    match_id, request_id, sequence_no, action_type, side,
                    previous_score_a, previous_score_b, previous_games_a, previous_games_b,
                    new_score_a, new_score_b, new_games_a, new_games_b,
                    match_completed, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtEvent->execute([
                $matchId, $requestId, $sequenceNo, 'retired', $retiredSide,
                $match['score_a'], $match['score_b'], $match['games_a'], $match['games_b'],
                $match['score_a'], $match['score_b'], $newGamesA, $newGamesB, 
                1, $reason . ': ' . $notes, $adminId
            ]);

            self::finalizeMatchRecords($pdo, $match, $winnerId, $loserId, $match['score_a'], $match['score_b'], $newGamesA, $newGamesB, $isDoubles, MATCH_RETIRED, $reason, $notes);

            $pdo->commit();
            return self::getStateResponse(self::getMatchData($matchId));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new Exception("Retirement failed: " . $e->getMessage());
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

    private static function finalizeMatchRecords(PDO $pdo, array $match, int $winnerId, int $loserId, int $scoreA, int $scoreB, int $gamesA, int $gamesB, bool $isDoubles, string $status, ?string $walkoverReason = null, ?string $walkoverNotes = null): void {
        $matchId = $match['id'];
        
        if ($isDoubles) {
            $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ?, games_a = ?, games_b = ?, status = ?, winner_team_id = ?, loser_team_id = ?, walkover_reason = ?, walkover_notes = ?, ended_at = NOW() WHERE id = ?')
                ->execute([$scoreA, $scoreB, $gamesA, $gamesB, $status, $winnerId, $loserId, $walkoverReason, $walkoverNotes, $matchId]);
        } else {
            $pdo->prepare('UPDATE matches SET score_a = ?, score_b = ?, games_a = ?, games_b = ?, status = ?, winner_player_id = ?, loser_player_id = ?, walkover_reason = ?, walkover_notes = ?, ended_at = NOW() WHERE id = ?')
                ->execute([$scoreA, $scoreB, $gamesA, $gamesB, $status, $winnerId, $loserId, $walkoverReason, $walkoverNotes, $matchId]);
        }

        if ($match['stage'] === 'stage1') {
            if ($isDoubles) {
                $stmt = $pdo->prepare('SELECT player1_id, player2_id FROM teams WHERE id = ?');
                $stmt->execute([$winnerId]); $wTeam = $stmt->fetch();
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id IN (?, ?)')
                    ->execute([$match['tournament_id'], $wTeam['player1_id'], $wTeam['player2_id']]);
                $stmt->execute([$loserId]); $lTeam = $stmt->fetch();
                $pdo->prepare('UPDATE player_tournament_records SET losses = losses + 1 WHERE tournament_id = ? AND player_id IN (?, ?)')
                    ->execute([$match['tournament_id'], $lTeam['player1_id'], $lTeam['player2_id']]);
            } else {
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins + 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$match['tournament_id'], $winnerId]);
                $pdo->prepare('UPDATE player_tournament_records SET losses = losses + 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$match['tournament_id'], $loserId]);
            }
        }

        if ($match['next_match_id_winner']) self::fillBracketSlot($pdo, $match['next_match_id_winner'], $winnerId, $isDoubles);
        if ($match['next_match_id_loser']) self::fillBracketSlot($pdo, $match['next_match_id_loser'], $loserId, $isDoubles);
        
        self::checkTournamentCompletion($pdo, $match['tournament_id']);
        AppCache::flush();
    }

    private static function reverseFinalizeMatchRecords(PDO $pdo, array $match, int $winnerId, int $loserId, bool $isDoubles): void {
        if ($match['stage'] === 'stage1') {
            if ($isDoubles) {
                $stmt = $pdo->prepare('SELECT player1_id, player2_id FROM teams WHERE id = ?');
                $stmt->execute([$winnerId]); $wTeam = $stmt->fetch();
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins - 1 WHERE tournament_id = ? AND player_id IN (?, ?)')
                    ->execute([$match['tournament_id'], $wTeam['player1_id'], $wTeam['player2_id']]);
                $stmt->execute([$loserId]); $lTeam = $stmt->fetch();
                $pdo->prepare('UPDATE player_tournament_records SET losses = losses - 1 WHERE tournament_id = ? AND player_id IN (?, ?)')
                    ->execute([$match['tournament_id'], $lTeam['player1_id'], $lTeam['player2_id']]);
            } else {
                $pdo->prepare('UPDATE player_tournament_records SET wins = wins - 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$match['tournament_id'], $winnerId]);
                $pdo->prepare('UPDATE player_tournament_records SET losses = losses - 1 WHERE tournament_id = ? AND player_id = ?')
                    ->execute([$match['tournament_id'], $loserId]);
            }
        }

        if ($match['next_match_id_winner']) self::emptyBracketSlot($pdo, $match['next_match_id_winner'], $winnerId, $isDoubles);
        if ($match['next_match_id_loser']) self::emptyBracketSlot($pdo, $match['next_match_id_loser'], $loserId, $isDoubles);
        AppCache::flush();
    }

    private static function fillBracketSlot(PDO $pdo, int $nextMatchId, int $entityId, bool $isDoubles): void {
        $stmt = $pdo->prepare('SELECT participant_a_id, participant_b_id, team_a_id, team_b_id FROM matches WHERE id = ?');
        $stmt->execute([$nextMatchId]); $nextMatch = $stmt->fetch();
        if (!$nextMatch) return;
        if ($isDoubles) {
            if (empty($nextMatch['team_a_id'])) $pdo->prepare('UPDATE matches SET team_a_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
            elseif (empty($nextMatch['team_b_id'])) $pdo->prepare('UPDATE matches SET team_b_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
        } else {
            if (empty($nextMatch['participant_a_id'])) $pdo->prepare('UPDATE matches SET participant_a_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
            elseif (empty($nextMatch['participant_b_id'])) $pdo->prepare('UPDATE matches SET participant_b_id = ? WHERE id = ?')->execute([$entityId, $nextMatchId]);
        }
    }
    
    private static function emptyBracketSlot(PDO $pdo, int $nextMatchId, int $entityId, bool $isDoubles): void {
        $colA = $isDoubles ? 'team_a_id' : 'participant_a_id';
        $colB = $isDoubles ? 'team_b_id' : 'participant_b_id';
        $pdo->prepare("UPDATE matches SET {$colA} = NULL WHERE id = ? AND {$colA} = ?")->execute([$nextMatchId, $entityId]);
        $pdo->prepare("UPDATE matches SET {$colB} = NULL WHERE id = ? AND {$colB} = ?")->execute([$nextMatchId, $entityId]);
    }
    
    private static function checkTournamentCompletion(PDO $pdo, int $tournamentId): void {
        $checkIncomplete = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND status NOT IN (?, ?, ?, ?, ?)");
        $checkIncomplete->execute([$tournamentId, MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED, MATCH_CANCELLED, MATCH_BYE]);
        if ((int)$checkIncomplete->fetchColumn() === 0) {
            $hasFinal = (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE tournament_id = {$tournamentId} AND round_key = '" . ROUND_FINAL . "'")->fetchColumn() > 0;
            $stmt = $pdo->prepare("SELECT format FROM tournaments WHERE id = ?");
            $stmt->execute([$tournamentId]);
            $format = $stmt->fetchColumn();
            if ($hasFinal || $format === 'round_robin') {
                $pdo->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?")->execute([$tournamentId]);
            }
        }
    }
    
    private static function getStateResponse(array $match): array {
        return [
            'success'      => true,
            'score_a'      => (int)$match['score_a'],
            'score_b'      => (int)$match['score_b'],
            'games_a'      => (int)$match['games_a'],
            'games_b'      => (int)$match['games_b'],
            'status'       => $match['status'],
            'is_completed' => in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED]),
            'redirect_url' => in_array($match['status'], [MATCH_COMPLETED, MATCH_WALKOVER, MATCH_RETIRED]) ? BASE_URL . '/admin/scoring/index.php?tournament_id=' . $match['tournament_id'] : null
        ];
    }
}
