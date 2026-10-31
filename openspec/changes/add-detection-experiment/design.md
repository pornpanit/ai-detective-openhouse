# Design

## Context

Greenfield implementation; an existing PHP/jsPsych draft (currently outside this repo) will be adapted rather than rewritten from scratch where practical. Participants play on personal phones at an openhouse via a QR-code URL — variable devices, variable network, crowded Wi-Fi. Research measurements (accuracy, RT) constrain the game design: no per-trial feedback, no timeouts, no speed-based scoring. Reference requirements: specs in this change; research design: `design_v01_2026-10-29.md` (project root).

## Goals / Non-Goals

**Goals:**
- Mobile-first experiment that survives mid-session abandonment with zero data loss
- RT data uncontaminated by image loading or speed-accuracy incentives
- Clean separation: research data (anonymous) vs prize contact (separate store)
- Bilingual UI without doubling maintenance effort

**Non-Goals:**
- Desktop/tablet optimization beyond graceful degradation
- Real-time multiplayer or live leaderboard push updates (refresh-based board is enough)
- Researcher admin dashboard in this change (direct DB/CSV export suffices)
- Counterbalancing round order (fixed Round 1 → Round 2 is a locked research decision)

## Decisions

### D1: jsPsych timeline as the experiment engine
Keep jsPsych (already in the draft) for trial sequencing, timing, and randomization. Alternative: custom JS experiment framework — rejected: jsPsych provides validated RT measurement, preload plugin, and i18n-ready trial customization out of the box.

### D2: One PHP save endpoint, one row per trial
Single idempotent endpoint `POST /api/trial.php` keyed on `(session_id, round, trial_index)` with `INSERT ... ON DUPLICATE KEY UPDATE`. Session bootstrap endpoint creates the session and returns the random ID. Alternative: batch save at session end — rejected: dropouts at an openhouse are expected and must not lose trials (see data-capture spec).

### D3: MySQL schema — two research tables + one isolated contact table
- `sessions` (session_id PK, demographics, derived cohort, language, device metadata, consent nullable, completed, created_at)
- `trials` (id PK, session_id FK, round, trial_index, image_id, image_type, response, correct, rt_ms, created_at; UNIQUE(session_id, round, trial_index))
- `prize_contacts` in a **separate database** (different credentials) keyed by alias only — no key shared with `sessions`

Alternative: same DB with a view-level separation — rejected: isolation enforced by credentials, not convention.

### D4: RT integrity via per-session preload
Each session's trial plan (~46 images, ~5.5MB at 80–150KB each) is generated server-side at bootstrap; the client downloads and preloads exactly that session's set via the jsPsych preload plugin before Round 1 (loading indicator per stimulus-management spec), then runs trials from browser cache. There is no global client-side image manifest — the pool manifest lives on the server and drives sampling only. Alternative: preload the entire pool (original design) — rejected: the 420K dataset cannot be hosted, and even a 2–5K pool is ~10× a session's need.

### D5: Per-session server-side trial plan
The server generates the session's trial plan at session creation and stores it with the session: six-cell balanced sampling (Round 1: 6 Real-M, 6 Real-F, 3 GAN-M, 3 GAN-F, 3 Diff-M, 3 Diff-F; Round 2: 3 per cell), same-gender pairing within each 2AFC trial, randomized image order, and left/right placement. The client renders the plan; correctness is computed server-side on save. Alternative: client-side plan — rejected: client tampering/replay protection and reproducible analysis favor server-side plans.

### D6: i18n via string dictionary object
Single `strings.js` dictionary `{ en: {...}, th: {...} }` with a language key in the session; all UI text resolved through it. Alternative: per-language page trees — rejected: doubles maintenance and drift risk.

### D7: Alias + lightweight repeat detection
Leaderboard reads `sessions` (completed, consented) by alias + score. Repeat detection via a device fingerprint cookie (non-blocking, best-effort) recorded on the session for analysis filtering only.

### D8: Post-game consent flag
`sessions.consent` stays NULL until the post-game question; exports default to `consent = 1` only. Non-consented rows remain for game-integrity checks (leaderboard) but are excluded from research export (see data-capture spec).

### D9: Azure free-tier deployment (App Service F1 + MySQL Flexible Server free)
App Service F1 hosts the PHP API, static assets, and the curated 2–5K image pool (~600MB at 80–150KB each — within F1's 1GB disk) on `*.azurewebsites.net` (HTTPS included — no cert setup for the QR link). MySQL Flexible Server free tier hosts the research database and the prize-contact database as two separately credentialed databases. F1 constraints: 60 CPU-min/day and no Always On (cold starts) — mitigated by featherweight endpoints, a pre-event warm-up ping, and a 5-minute uptime monitor that doubles as keep-warm. A 30-player arrival burst ≈ 180MB of image traffic — acceptable for one event. Alternative: Blob Storage for images — rejected: the subset fits F1 directly; cheap VPS — rejected: cost and maintenance; university hosting — rejected: unverified HTTPS/load guarantees.

### D10: Detective theme as a pure strings layer
The detective narrative (briefing copy, "Investigation" round labels, "Top Detectives" leaderboard title, "database cleaned X%" end-score presentation) lives entirely in the TH/EN strings dictionary. No trial mechanics, feedback, scoring, or capture logic changes; the debrief discloses the framing and states the real research purpose. Alternative: theme-aware components — rejected: risks leaking behavioral differences into the locked research design.

### D11: Rolling-window image reuse exclusion
Image metadata carries `last_used_at`; session bootstrap samples only images unused within the previous 30 minutes and stamps its draws as used. This guarantees near-simultaneous players never see the same images while allowing later reuse. With a 2–5K pool and ~48 images per session, the window excludes at most a few hundred images — eligibility never runs dry. Alternative: global never-repeat — rejected: unbounded state and pool-exhaustion edge cases for zero measurable benefit.

### D12: Local dataset pipeline (sample → auto-label → process → select)
Dataset facts: 70K real FFHQ, 10K SD1.5 faces (faces category only), 40K StyleGAN2 (20K ψ=0.5 + 20K ψ=1; GAN cells draw equally from both configs). Pipeline (Python, local): random-sample ~2,500 candidates per source → automated gender labeling (face-attribute classifier, low-confidence faces dropped) → perceptual-hash dedup → corrupt-file filter → identical crop/resize/compress to ~80–150KB → six-cell balanced selection of the 2–5K deployment pool (equal cells, GAN cells 50/50 ψ mix) → pool manifest export. Labels come from one instrument across all sources (documented in methods); Azure never touches the raw dataset.

### D13: Quit path and end-of-game survey
A persistent quit control (confirm → consent question → short debrief, no survey) guarantees every exit captures consent; quitter sessions keep already-saved trials and are marked incomplete. The five-item familiarity survey (draft-derived) runs only at natural game end, after the consent question, shown regardless of the consent answer, stored as nullable session fields — this also removes pre-game familiarity priming. Export rule: consented sessions are included whether complete or not; completion status is a covariate/filter option, not a default filter.

## Risks / Trade-offs

- [Crowded venue Wi-Fi degrades preload] → compress images hard, host close to venue or on CDN, loading indicator with retry; preload before demographics screens so form time masks download time
- [F1 free tier limits (60 CPU-min/day, cold starts from idle)] → featherweight endpoints, pre-event warm-up ping, 5-minute uptime monitor as keep-warm; watch the quota blade during the event
- [Retroactive consent not covered by IRB protocol] → confirm before deployment; fallback is a pre-game two-option consent (design doc §9) — one extra screen, spec change only in experiment-flow
- [Gen X underrecruitment] → analysis fallback: age as continuous covariate (design doc §7); recruitment prompt at venue
- [Device RT variance confounds cohort comparison] → device metadata covariates recorded (data-capture spec); accuracy is primary DV
- [Fixed round order confounds 2AFC vs yes/no comparison] → accepted research trade-off (locked decision); document as limitation
- [Alias impersonation/offensive aliases] → alias length cap + basic profanity filter; leaderboard is refresh-only

## Migration Plan

1. Deploy stimulus pipeline output (processed images + manifest) and DB schema
2. Deploy to Azure App Service F1 + MySQL Flexible Server (two separately credentialed DBs); smoke-test the azurewebsites.net QR link on multiple devices
3. Pilot run (~10 participants) validating timing, saves, and session length before openhouse
4. Rollback: site is stateless per session; revert deploy, research data unaffected

## Open Questions

- Exact trial counts (working: 10 × 2AFC, 24 single) — confirm after pilot timing
- Prize mechanics detail (end-of-day vs per-round) — affects only contact-capture screen copy
