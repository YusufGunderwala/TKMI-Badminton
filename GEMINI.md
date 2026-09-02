# TKMI Badminton Tournament — Project Rules & Locked Decisions

This project is a **Badminton Tournament Management Platform** built for
**Toloba ul Kulliyaat il Muminoon (TKMI)** — a student/youth committee under
the **Dawoodi Bohra community**.

All decisions below are FINAL and must be respected in all code, schema, and UI
generated for this project. Do NOT second-guess or override these without
explicit user instruction.

---

## Tech Stack (LOCKED)
- **Backend:** PHP (PDO + PostgreSQL)
- **Database:** PostgreSQL via **Supabase** (free tier)
- **Frontend:** Tailwind CSS + Vanilla JS / Alpine.js
- **Real-time:** Server-Sent Events (SSE)
- **Hosting:** **Railway.app** (PHP app) + **Supabase** (DB) — both free tier
- **Local Dev:** XAMPP with PostgreSQL PDO extension enabled

---

## Player Fields (LOCKED)
Every player record must contain exactly these fields:

| Field | Required |
|---|---|
| Full Name | ✅ Required |
| Display Name | ✅ Required (shown on scoreboard) |
| ITS ID | ✅ Required (8-digit Dawoodi Bohra unique ID) |
| Mohallah | ✅ Required |
| Gender | ✅ Required (Boys / Girls) |
| WhatsApp Contact | ✅ Required (Admin-only, never shown publicly) |
| Player Photo | ⬜ Optional (fallback avatar if missing) |

- No "Category" field (Toloba/Shabaab/Senior) — intentionally excluded.
- Player registration is **Admin-only** — no public self-registration.

---

## Tournament Format (LOCKED)

### Two Modes Supported:
1. **Swiss + Knockout** (Primary — used for TKMI Singles/Doubles events)
2. **Round Robin / Points Table** (Secondary — configurable)

### Swiss + Knockout Format (32 Players):

**Stage 1 — Swiss Qualifier:**
- Round 1: All 32 play (16 matches). Winners (1-0) vs Winners next; Losers (0-1) vs Losers next.
- Round 2: W vs W (8 matches) + L vs L (8 matches).
  - Result: 2-0 → Tier 1 (8 advance), 1-1 → Survival Round (16), 0-2 → Eliminated (8)
- Round 3 (Survival Round): 16 × 1-1 players play (8 matches, no rematches).
  - Result: 2-1 → Tier 2 (8 advance), 1-2 → Eliminated (8)
- **Two-Loss Rule applies ONLY in Stage 1.**

**Stage 2 — Single Elimination Knockout (16 players):**
- R16: Tier 1 (2-0) vs Tier 2 (2-1) — 8 matches
- QF: 4 matches
- SF: 2 matches
- 3rd Place Match: 2 SF losers play for Bronze 🥉
- Final: 1 match → 🏆 Champion
- **Stage 2 is pure Single Elimination — lose once = eliminated, regardless of Stage 1 loss count.**

**Positions Awarded:** 🥇 1st, 🥈 2nd, 🥉 3rd, 4th

---

## Match Types (LOCKED)
- **Both Singles AND Doubles** are supported in V1.
- Doubles: A "pair/team" of 2 players is treated as one unit.

---

## Scoring Rules Per Round (LOCKED)

| Round | Points/Game | Deuce Triggers | Cap |
|---|---|---|---|
| Stage 1 (R1, R2, Survival) | **11 pts** | 10-10 | First to **16** |
| Stage 2 R16, QF, SF | **15 pts** | 14-14 | First to **21** |
| 3rd Place Match | **15 pts** | 14-14 | First to **21** |
| **Final** | **21 pts** | 20-20 | First to **26** |

- Match format: **Best of 3 games** (first to win 2 games wins the match).
- Deuce rule: win by 2 consecutive points, or first to reach the cap wins.
- Scoring is configured **per round**, not globally per tournament.

---

## Survival Round Pairing (LOCKED)
- Method: **Admin Manual with Auto-suggest**
- Auto-suggest uses cross-path logic (Path 1 vs Path 2 players)
- System blocks rematches (warns admin if attempted)
- Admin can override any pair manually

---

## Simultaneous Matches (LOCKED)
- Multiple matches can be LIVE at the same time.
- No court assignment/numbering system needed.
- Each Admin account handles their own live match independently.

---

## User Roles (LOCKED)
| Role | Access |
|---|---|
| **Admin** (multiple accounts supported) | Full access — create/manage tournaments, players, scoring |
| **Public Viewer** | View only — no login required |

- Only 2 roles. No separate Scorekeeper role.
- Super Admin can create additional Admin accounts.

---

## Points Table — Round Robin Mode (LOCKED)

| Result | Standing Points | Score Recorded |
|---|---|---|
| Win | 2 pts | Actual score |
| Loss | 0 pts | Actual score |
| Walkover Win | 2 pts | Round's point target - 0 per game |
| Walkover Loss | 0 pts | 0 - Round's point target per game |
| Retired | 2 pts to winner | Score at time of retirement |
| Cancelled | 0 pts each | No score |

- Walkover reason: **Required** (dropdown + optional notes field)
- Tiebreaker order: Wins → Net Point Difference → Head-to-Head
- Point difference tracked per game and cumulative across all matches.

---

## Sponsors (LOCKED)
- Sponsors are **platform-wide** — same sponsors shown across all tournaments.
- **No tier system** — flat list of logos, all displayed at equal size.
- Sponsor logos appear in:
  - Footer of all public-facing pages
  - Corner of the live scoreboard screen
- Admin can add, remove, and update sponsor logos via the Admin Panel.
- Sponsor data: Name + Logo Image (uploaded by Admin).

---

## Community Context (IMPORTANT)
- This is a **Dawoodi Bohra community** platform — design and tone must be
  respectful, clean, and elegant. No flashy/gambling-style UI.
- ITS ID is the unique Bohra community identifier — treat it as a primary key equivalent.
- Mohallah = community locality/neighbourhood (Bohra-specific term, use as-is).
- The organising committee is **TKMI (Toloba ul Kulliyaat il Muminoon)** — founded 1965.
- Players/audience are community members — WhatsApp link sharing is a primary
  distribution channel; ensure OG meta tags are present on all public pages.
