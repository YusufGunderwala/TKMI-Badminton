-- ============================================================
-- TKMI Badminton Tournament Platform — PostgreSQL Performance Indexes
-- High-speed composite indexes for Supabase PostgreSQL
-- ============================================================

-- 1. Historical games lookup by match (queried on every match view / set breakdown)
CREATE INDEX IF NOT EXISTS idx_games_match_id ON games(match_id);

-- 2. Matches by tournament and status (SSE broadcast, live ticker, hub cards)
CREATE INDEX IF NOT EXISTS idx_matches_tourney_status ON matches(tournament_id, status);

-- 3. Matches recently ended (SSE broadcaster delta polling window)
CREATE INDEX IF NOT EXISTS idx_matches_ended_at ON matches(ended_at);

-- 4. Tournament roster lookups
CREATE INDEX IF NOT EXISTS idx_tourney_players_tourney ON tournament_players(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tourney_players_player ON tournament_players(player_id);

-- 5. Audit trail & live score event sequence tracking
CREATE INDEX IF NOT EXISTS idx_score_events_match_id ON score_events(match_id);
CREATE INDEX IF NOT EXISTS idx_score_events_match_seq ON score_events(match_id, sequence_no);

-- 6. Swiss qualifier records lookup
CREATE INDEX IF NOT EXISTS idx_ptr_tourney ON player_tournament_records(tournament_id);
