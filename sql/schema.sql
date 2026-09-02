-- ============================================================
-- TKMI Badminton Tournament Platform — PostgreSQL Schema (Final)
-- For use with Supabase (Paste exactly into Supabase SQL Editor)
-- ============================================================

-- ---- Drop existing tables to ensure a clean slate if re-running ----
DROP TABLE IF EXISTS score_events CASCADE;
DROP TABLE IF EXISTS games CASCADE;
DROP TABLE IF EXISTS matches CASCADE;
DROP TABLE IF EXISTS player_tournament_records CASCADE;
DROP TABLE IF EXISTS group_participants CASCADE;
DROP TABLE IF EXISTS tournament_groups CASCADE;
DROP TABLE IF EXISTS tournament_players CASCADE;
DROP TABLE IF EXISTS teams CASCADE;
DROP TABLE IF EXISTS round_configs CASCADE;
DROP TABLE IF EXISTS tournaments CASCADE;
DROP TABLE IF EXISTS players CASCADE;
DROP TABLE IF EXISTS sponsors CASCADE;
DROP TABLE IF EXISTS admins CASCADE;

-- ---- Custom ENUM types ------------------------------------
DROP TYPE IF EXISTS gender_type CASCADE;
DROP TYPE IF EXISTS match_type_enum CASCADE;
DROP TYPE IF EXISTS format_type CASCADE;
DROP TYPE IF EXISTS tourney_status CASCADE;
DROP TYPE IF EXISTS match_status CASCADE;
DROP TYPE IF EXISTS stage_type CASCADE;

CREATE TYPE gender_type      AS ENUM ('Boys', 'Girls', 'Mixed');
CREATE TYPE match_type_enum  AS ENUM ('singles', 'doubles');
CREATE TYPE format_type      AS ENUM ('swiss_knockout', 'round_robin', 'pools_knockout');
CREATE TYPE tourney_status   AS ENUM ('draft', 'ready', 'live', 'completed', 'archived');
CREATE TYPE match_status     AS ENUM ('scheduled', 'in_progress', 'completed', 'walkover', 'retired', 'cancelled');
CREATE TYPE stage_type       AS ENUM ('stage1', 'stage2');

-- ------------------------------------------------------------
-- Table: admins
-- ------------------------------------------------------------
CREATE TABLE admins (
  id             SERIAL PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  display_name   VARCHAR(100) NOT NULL,
  is_super_admin BOOLEAN      NOT NULL DEFAULT FALSE,
  created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ------------------------------------------------------------
-- Table: players
-- ------------------------------------------------------------
CREATE TABLE players (
  id           SERIAL PRIMARY KEY,
  its_id       VARCHAR(8)   NOT NULL UNIQUE,
  full_name    VARCHAR(150) NOT NULL,
  display_name VARCHAR(80)  NOT NULL,
  mohallah     VARCHAR(150) NOT NULL,
  gender       VARCHAR(10)  NOT NULL CHECK (gender IN ('Boys','Girls')),
  whatsapp     VARCHAR(20)  NOT NULL,
  photo_path   VARCHAR(255),
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ------------------------------------------------------------
-- Table: tournaments
-- ------------------------------------------------------------
CREATE TABLE tournaments (
  id           SERIAL PRIMARY KEY,
  name         VARCHAR(200) NOT NULL,
  gender       gender_type  NOT NULL,
  match_type   match_type_enum NOT NULL DEFAULT 'singles',
  format       format_type  NOT NULL,
  status       tourney_status NOT NULL DEFAULT 'draft',
  description  TEXT,
  created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Auto-update updated_at for tournaments
CREATE OR REPLACE FUNCTION update_updated_at()
RETURNS TRIGGER AS $$
BEGIN NEW.updated_at = NOW(); RETURN NEW; END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER tournaments_updated_at
  BEFORE UPDATE ON tournaments
  FOR EACH ROW EXECUTE FUNCTION update_updated_at();

-- ------------------------------------------------------------
-- Table: round_configs
-- ------------------------------------------------------------
CREATE TABLE round_configs (
  id              SERIAL PRIMARY KEY,
  tournament_id   INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  round_key       VARCHAR(20)  NOT NULL,
  round_label     VARCHAR(50)  NOT NULL,
  best_of         SMALLINT     NOT NULL DEFAULT 3,
  points_per_game SMALLINT     NOT NULL DEFAULT 11,
  deuce_enabled   BOOLEAN      NOT NULL DEFAULT TRUE,
  deuce_trigger   SMALLINT     NOT NULL DEFAULT 10,
  deuce_cap       SMALLINT     NOT NULL DEFAULT 16,
  sort_order      SMALLINT     NOT NULL DEFAULT 0,
  UNIQUE (tournament_id, round_key)
);

-- ------------------------------------------------------------
-- Table: tournament_players
-- ------------------------------------------------------------
CREATE TABLE tournament_players (
  id            SERIAL PRIMARY KEY,
  tournament_id INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  player_id     INTEGER      NOT NULL REFERENCES players(id)     ON DELETE RESTRICT,
  pool_name     VARCHAR(10)  DEFAULT NULL,
  seed          SMALLINT,
  registered_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  UNIQUE (tournament_id, player_id)
);

-- ------------------------------------------------------------
-- Table: teams (For Doubles)
-- ------------------------------------------------------------
CREATE TABLE teams (
  id            SERIAL PRIMARY KEY,
  tournament_id INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  player1_id    INTEGER      NOT NULL REFERENCES players(id)     ON DELETE RESTRICT,
  player2_id    INTEGER      NOT NULL REFERENCES players(id)     ON DELETE RESTRICT,
  display_name  VARCHAR(150) NOT NULL,
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
  
  -- Prevent "Player A + Player A" and enforce canonical order "Player A < Player B" to prevent duplicates
  CONSTRAINT check_team_players_order CHECK (player1_id < player2_id),
  UNIQUE (tournament_id, player1_id, player2_id)
);

-- ------------------------------------------------------------
-- Table: player_tournament_records (Swiss Tracking)
-- ------------------------------------------------------------
CREATE TABLE player_tournament_records (
  id            SERIAL PRIMARY KEY,
  tournament_id INTEGER      NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  player_id     INTEGER      NOT NULL REFERENCES players(id)     ON DELETE RESTRICT,
  wins          SMALLINT     NOT NULL DEFAULT 0 CHECK (wins >= 0),
  losses        SMALLINT     NOT NULL DEFAULT 0 CHECK (losses BETWEEN 0 AND 2),
  tier          SMALLINT     NOT NULL DEFAULT 0,
  is_eliminated BOOLEAN      NOT NULL DEFAULT FALSE,
  UNIQUE (tournament_id, player_id)
);

-- ------------------------------------------------------------
-- Table: tournament_groups (Round Robin)
-- ------------------------------------------------------------
CREATE TABLE tournament_groups (
  id            SERIAL PRIMARY KEY,
  tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  name          VARCHAR(50) NOT NULL,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (tournament_id, name)
);

-- ------------------------------------------------------------
-- Table: group_participants (Round Robin)
-- ------------------------------------------------------------
CREATE TABLE group_participants (
  id            SERIAL PRIMARY KEY,
  group_id      INTEGER NOT NULL REFERENCES tournament_groups(id) ON DELETE CASCADE,
  tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  player_id     INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  team_id       INTEGER REFERENCES teams(id) ON DELETE RESTRICT,
  
  -- Must be either a player or a team, but not both
  CONSTRAINT check_group_participant_type CHECK (
    (player_id IS NOT NULL AND team_id IS NULL) OR 
    (player_id IS NULL AND team_id IS NOT NULL)
  ),
  
  -- Prevent being in the same group twice
  UNIQUE (group_id, player_id),
  UNIQUE (group_id, team_id),
  
  -- Prevent being in multiple groups across the same tournament
  UNIQUE (tournament_id, player_id),
  UNIQUE (tournament_id, team_id)
);

-- ------------------------------------------------------------
-- Table: matches (The Core Engine)
-- ------------------------------------------------------------
CREATE TABLE matches (
  id              SERIAL PRIMARY KEY,
  tournament_id   INTEGER       NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
  round_key       VARCHAR(20)   NOT NULL,
  stage           stage_type    NOT NULL DEFAULT 'stage1',
  match_number    SMALLINT      NOT NULL DEFAULT 1,
  
  -- Participants
  participant_a_id INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  participant_b_id INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  team_a_id        INTEGER REFERENCES teams(id) ON DELETE RESTRICT,
  team_b_id        INTEGER REFERENCES teams(id) ON DELETE RESTRICT,
  
  -- Live Scoring State
  status           match_status NOT NULL DEFAULT 'scheduled',
  score_a          SMALLINT     NOT NULL DEFAULT 0 CHECK (score_a >= 0),
  score_b          SMALLINT     NOT NULL DEFAULT 0 CHECK (score_b >= 0),
  games_a          SMALLINT     NOT NULL DEFAULT 0 CHECK (games_a >= 0),
  games_b          SMALLINT     NOT NULL DEFAULT 0 CHECK (games_b >= 0),
  
  -- Results
  winner_player_id INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  winner_team_id   INTEGER REFERENCES teams(id)   ON DELETE RESTRICT,
  loser_player_id  INTEGER REFERENCES players(id) ON DELETE RESTRICT,
  loser_team_id    INTEGER REFERENCES teams(id)   ON DELETE RESTRICT,
  walkover_reason  VARCHAR(100),
  walkover_notes   TEXT,
  
  -- Bracket Progression Pointers
  next_match_id_winner INTEGER REFERENCES matches(id) ON DELETE SET NULL,
  next_match_id_loser  INTEGER REFERENCES matches(id) ON DELETE SET NULL,
  
  -- Timestamps
  started_at    TIMESTAMPTZ,
  ended_at      TIMESTAMPTZ,
  
  -- Constraints
  -- 1. Ensure Player A != Player B and Team A != Team B
  CONSTRAINT check_singles_not_same CHECK (participant_a_id IS NULL OR participant_b_id IS NULL OR participant_a_id <> participant_b_id),
  CONSTRAINT check_doubles_not_same CHECK (team_a_id IS NULL OR team_b_id IS NULL OR team_a_id <> team_b_id),
  
  -- 2. Ensure Match is purely Singles OR purely Doubles (no mixed columns)
  CONSTRAINT check_match_type_exclusivity CHECK (
    ((team_a_id IS NULL AND team_b_id IS NULL))
    OR 
    ((participant_a_id IS NULL AND participant_b_id IS NULL))
  )
);

-- ------------------------------------------------------------
-- Table: games (Historical Set Scores)
-- ------------------------------------------------------------
CREATE TABLE games (
  id          SERIAL PRIMARY KEY,
  match_id    INTEGER NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
  game_number SMALLINT NOT NULL,
  score_a     SMALLINT NOT NULL DEFAULT 0 CHECK (score_a >= 0),
  score_b     SMALLINT NOT NULL DEFAULT 0 CHECK (score_b >= 0),
  winner_side CHAR(1) CHECK (winner_side IN ('A', 'B')),
  ended_at    TIMESTAMPTZ DEFAULT NOW(),
  UNIQUE (match_id, game_number)
);

-- ------------------------------------------------------------
-- Table: score_events (Audit Trail)
-- ------------------------------------------------------------
CREATE TABLE score_events (
  id             SERIAL PRIMARY KEY,
  match_id       INTEGER      NOT NULL REFERENCES matches(id)  ON DELETE CASCADE,
  action_type    VARCHAR(50)  NOT NULL, -- 'point_a', 'point_b', 'undo_a', 'game_a', 'walkover', 'retired'
  player_a_score SMALLINT     DEFAULT 0 CHECK (player_a_score >= 0),
  player_b_score SMALLINT     DEFAULT 0 CHECK (player_b_score >= 0),
  notes          TEXT,
  created_by     INTEGER      REFERENCES admins(id) ON DELETE SET NULL,
  created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ------------------------------------------------------------
-- Table: sponsors (Platform-wide)
-- ------------------------------------------------------------
CREATE TABLE sponsors (
  id            SERIAL PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  image_path    VARCHAR(255) NOT NULL,
  created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- ------------------------------------------------------------
-- Indexes for Performance
-- ------------------------------------------------------------
CREATE INDEX idx_matches_tournament ON matches(tournament_id);
CREATE INDEX idx_matches_status     ON matches(status);
CREATE INDEX idx_matches_round      ON matches(tournament_id, round_key);
CREATE INDEX idx_score_events_match ON score_events(match_id);

-- ------------------------------------------------------------
-- Default Super Admin
-- ------------------------------------------------------------
-- !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
-- WARNING: CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN
-- DO NOT LEAVE THIS DEFAULT PASSWORD IN PRODUCTION
-- Username: superadmin
-- Password: Admin@TKMI2025
-- !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
INSERT INTO admins (username, password_hash, display_name, is_super_admin)
VALUES (
  'superadmin',
  '$2y$10$oY7tJ11jH1k0c3O0H30TGe4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'TKMI Admin',
  TRUE
);
