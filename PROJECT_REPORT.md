# AI Detective — Project Report

A complete technical report of the AI Detective openhouse experiment system:
architecture, stack, structure, code walkthroughs, databases, and capabilities.

- **Live site**: https://face-detective.azurewebsites.net
- **Repository**: https://github.com/pornpanit/ai-detective-openhouse (private)
- **Companion docs**: `README.md` (quick start) · `CHANGELOG.md` (full history) ·
  `docs/DATASET_REPORT.md` (stimulus provenance) · `design_v01_2026-10-29.md` (research design)

---

## 1. Overview

AI Detective is a mobile web game that doubles as a research instrument. Visitors
at a university openhouse scan a QR code, play a 7–8 minute detective game on
their own phones, and every answer silently becomes research data — response
time, accuracy, and demographics — for a study of **how well different
generations (Gen X / Millennial / Gen Z) can distinguish AI-generated human
faces from real ones**.

The game presents two "investigations":

- **Investigation 1 (2AFC)** — 12 rounds of two side-by-side faces; tap the AI one
- **Investigation 2 (binary judgment)** — 24 single faces; answer *AI* or *Real*

Every session sees exactly **24 AI faces (12 StyleGAN2 + 12 Stable Diffusion) and
24 real FFHQ faces**, gender-balanced in a six-cell design (3 sources × 2
genders), with no image reuse between near-simultaneous players and a
"half-day unique" preference for never-used images.

---

## 2. System Diagrams

### 2.1 Architecture

```
                        ┌──────────────────────────────────────────────────┐
                        │            AZURE (Southeast Asia, $0)            │
                        │                                                  │
   Phones ──QR/URL────► │  App Service F1 (Linux, PHP 8.4)                 │
   Booth TV ──────────► │  ┌────────────────────────────────────────────┐  │
                        │  │ public/                                     │  │
                        │  │  index.html ──► experiment.js + strings.js │  │
                        │  │               (jsPsych 7.3.4 game, TH/EN)  │  │
                        │  │  live.html     (LIVE board for TV)         │  │
                        │  │  leaderboard.html · contact.html           │  │
                        │  │  admin.php     (research dashboard v5)     │  │
                        │  │  assets/faces/ (4,432 pool images, ~395MB) │  │
                        │  │  lib/ (jsPsych core+plugins, vendored)     │  │
                        │  └──────────────┬─────────────────────────────┘  │
                        │                 │ fetch (HTTPS)                   │
                        │  ┌──────────────▼─────────────────────────────┐  │
                        │  │ api/ (10 PHP endpoints)                    │  │
                        │  │  session · trial · finalize · state ·      │  │
                        │  │  score · check-alias · leaderboard ·       │  │
                        │  │  contact · export · health                 │  │
                        │  └───────┬───────────────────────┬────────────┘  │
                        │          │ research creds        │ prize creds   │
                        │  ┌───────▼──────────┐  ┌─────────▼────────────┐  │
                        │  │ MySQL Flexible    │  │ MySQL Flexible       │  │
                        │  │ ai_detect_research│  │ ai_detect_prize      │  │
                        │  │ sessions          │  │ prize_contacts       │  │
                        │  │ trials · images   │  │ (never joins         │  │
                        │  │                   │  │  research data)      │  │
                        │  └───────────────────┘  └──────────────────────┘  │
                        └──────────────────────────────────────────────────┘

   LOCAL (build machine, offline):
   dataset 120K faces → scripts/ pipeline → pool manifest → deployed assets
```

### 2.2 Player flow

```
URL → language (TH/EN)
    → saved session? ──► resume where left off / "already played" screen
    → signup: birth year · gender · unique codename (live availability check)
    → "Preparing case files" (server builds trial plan; retry on error)
    → training (2 trials, no feedback)
    → [images preload silently in background]
    → INVESTIGATION 1 (12 × tap-the-AI)  ── quit button → confirm →
    → INVESTIGATION 2 (24 × AI/Real)         consent → short debrief
    → score "DATABASE CLEANED X%"
    → consent (research use?) → 8-question survey (branching)
    → prize draw (Join/Skip, everyone) → debrief
```

### 2.3 One trial's data flow

```
player taps face
  → jsPsych records rt (stimulus-onset → tap, ms)
  → POST api/trial.php {session_id, round, trial_index, image_id, rt_ms}
  → server looks up the session's stored plan → decides correct (1/0)
  → INSERT ... ON DUPLICATE KEY UPDATE (idempotent — retries never duplicate)
  → client retry ×3 on failure, else localStorage outbox (flushed on next load)
  → score.php aggregates SUM(correct) — the client NEVER judges correctness
```

---

## 3. Tech Stack & Tools

| Layer | Technology | Version | Why |
|---|---|---|---|
| Trial engine | jsPsych | 7.3.4 (vendored browser builds) | Validated RT measurement, preload plugin, timeline API; browser builds because npm ESM won't run from plain script tags on F1. **Known quirk:** 7.3.4 plugins append DOM without clearing — we clear `#jspsych-content` on every `on_trial_start` (proven by `scripts/jsdom_trial_test.js`) |
| Game UI | Vanilla JS/HTML/CSS | — | No framework/build step — F1 has no build pipeline; two-stage timeline (stage1 signup, stage2 game) with a shared `#game-root` container |
| i18n | `strings.js` dictionary | 90+ keys × TH/EN | Single source of copy incl. detective theme; `check_frontend.py` enforces key parity |
| Backend | PHP | 8.5 local / 8.4 Azure | Zero-dependency endpoints; matches cheapest Azure runtime |
| DB (local) | MariaDB | 11.4.4 (portable in `tools/`) | No system install needed; MySQL-compatible |
| DB (cloud) | Azure Database for MySQL Flexible | 8.0.21, B1ms | Free 750h/month grant; same SQL as local |
| Stimulus pipeline | Python | 3.10 | One-image-at-a-time streaming → survives 8GB notebooks; ~5 min per 7,500-image chunk |
| Face detection (QA) | YuNet (opencv_zoo ONNX) | 2023mar | Fast, landmark output (needed for pose checks) |
| Gender labeling | Levi-Hassner Caffe net via OpenCV DNN | — | Runs offline, no PyTorch; conf ≥0.70 cut (softmax is conservative — documented in methods) |
| Imaging | Pillow 12.3 + imagehash | — | 768×768 JPEG with quality-search to the 80–150KB band; pHash dedup |
| Hosting | Azure App Service F1 (Linux) | — | Free tier, HTTPS via `*.azurewebsites.net`; 1GB disk is the pool ceiling (4,432 images ≈ 395MB fits) |
| Monitoring | UptimeRobot (5-min ping on `/api/health.php`) | — | Also keeps F1 warm (no cold starts on event day) |
| Version control | Git + GitHub (private) | — | Secrets gitignored (`deploy_credentials.json`, `scm_cred.json`) |
| CLI tooling | Azure CLI, GitHub CLI | 2.91 / 2.103 | Full deploy from terminal; az CLI redacts publishing credentials — workaround documented in CHANGELOG session 4 |
| Spec management | OpenSpec | 1.15 | Behavior specs in `openspec/` are the source of truth for what the system does |
| Packaging | Python `zipfile` (NOT PowerShell Compress-Archive) | — | PowerShell zips use backslash entry separators → Linux extraction breaks (the deployment war story) |

---

## 4. Project Structure

```
AIproject/
├── PROJECT_REPORT.md            ← this file
├── README.md                    quick start + maintainer tour
├── CHANGELOG.md                 every change, dated, with incidents
├── design_v01_2026-10-29.md     research design (all locked decisions)
├── requirements.txt             pipeline Python deps
├── .gitignore                   excludes tools/, data/processed|models|logs|export, secrets
│
├── public/                      ← THE WEBSITE (docroot)
│   ├── index.html               entry page: loads lib/, strings.js, experiment.js
│   ├── experiment.js            the entire game (two-stage jsPsych timeline,
│   │                            resume system, quit flow, background preloader)
│   ├── strings.js               TH/EN dictionary incl. detective theme
│   ├── css/game.css             mobile-first, one-trial-per-screen, responsive
│   ├── live.html                LIVE board for the booth TV (clock, champion
│   │                            card, stats strip, QR corner, 10s polling)
│   ├── leaderboard.html         personal leaderboard (10s polling)
│   ├── contact.html             late prize-draw entry (standalone)
│   ├── admin.php                research dashboard v5 (see §7)
│   ├── tv.html                  redirect stub → live.html (legacy URL)
│   ├── lib/                     jsPsych 7.3.4 core + 5 plugins (browser builds)
│   ├── assets/faces/            4,444 game images (pool + practice)
│   ├── assets/pool_manifest.csv what the DB ingests
│   ├── assets/qr.png            scan-to-play QR (also on live.html)
│   ├── assets/icon.png          site favicon
│   └── api/                     10 PHP endpoints (see §5)
│       ├── lib.php              shared: config loader, DB connections, plan
│   │                            generator (six-cell sampling, unique-preferred
│   │                            claim_cell), cohort derivation, manifest ingest
│       ├── session.php          POST create session (+alias, one-play lock)
│       ├── trial.php            POST save one trial (idempotent, server-side
│   │                            correctness)
│       ├── finalize.php         POST consent / survey f1..f8 / finish / quit
│       ├── state.php            GET saved progress + plan (resume support)
│       ├── score.php            GET authoritative accuracy for end screen
│       ├── check-alias.php      GET codename availability (live check)
│       ├── leaderboard.php      GET top-20 + meta (excludes test sessions)
│       ├── contact.php          POST prize contact → prize DB only
│       ├── export.php           GET CSV downloads (admin cookie-gated)
│       └── health.php           GET both-DB connectivity (for UptimeRobot)
│
├── config/
│   ├── schema.sql               full DDL: both databases, all tables, grants
│   └── config.php               local dev credentials (env vars override)
│
├── scripts/                     pipeline + verification (all re-runnable)
│   ├── sample_candidates.py     random-sample per source (--counts/--exclude/--sources)
│   ├── label_gender.py          YuNet + gender net labeling (multiprocessing)
│   ├── process_images.py        crop→768px→80–150KB + pHash/corrupt QA
│   ├── qa_pool.py               QA v3 (monochrome/turn rules) + pool rebuild
│   │                            (--cells "real=1000,gan=600,sd15=636")
│   ├── run_pipeline_all.py      master chunked runner (resumable)
│   ├── contact_sheet.py         eyeball-review grid of the whole pool
│   ├── make_schema_prod.py      builds Azure-safe schema (users @'%')
│   ├── make_deploy_zip.py       python zip (forward slashes — see §3)
│   ├── check_frontend.py        static checks: strings parity, flow rules
│   ├── verify_plans.py          bootstrap N sessions: six-cell balance,
│   │                            same-gender pairs, zero-overlap-while-fresh
│   ├── jsdom_trial_test.js      proves the jsPsych clear-fix (regression test)
│   ├── e2e_session7.py          local E2E: alias/uniqueness/survey/contact
│   ├── e2e_flag.py              local E2E: admin test-flag lifecycle
│   ├── local_sanity.py          bootstrap + all-session-images-serve (local)
│   ├── prod_sanity.py           same, against production
│   ├── prod_e2e.py / prod_e2e_session7.py   production end-to-end suites
│   ├── export_research.py       consented, non-test CSV export
│   └── validate_reports.py      integrity of all processed_report*.csv
│
├── docs/
│   ├── DATASET_REPORT.md        full stimulus funnel with numbers
│   └── NEW_PC_SETUP.md          fresh-machine setup + pipeline guide
│
├── data/                        (gitignored except manifests in pipeline/)
│   ├── pipeline/                candidates/labeled/reports, pool manifests,
│   │                            schema_prod, qa summaries, credentials (SECRETS)
│   ├── processed/               94K processed images (spare archive)
│   ├── models/                  YuNet + gender net
│   ├── logs/                    pipeline + QA reports
│   └── export/                  research CSVs, contact sheet, QR
│
├── tools/                       (gitignored) portable PHP 8.5 + MariaDB 11.4
├── openspec/                    behavior specs (source of truth)
└── .opencode/                   OpenSpec workflow skills
```

---

## 5. Code Walkthroughs (the four mechanisms that matter)

### 5.1 A player taps a face — timing purity + idempotency

The client only reports *which image* was chosen and *when*. It never computes
correctness — the plan (which image is AI) lives server-side only.

```js
// experiment.js — Round-1 trial (2AFC). RT is plugin-measured:
// stimulus onset → tap. The quit-aborted trial is simply not saved.
on_finish: (d) => {
  hideQuit();
  if (S.quitRequest) return;                  // interrupted → discard (RT-safe)
  const chosen = t.ai_side === "left"
    ? (d.response === 0 ? t.ai.id : t.real.id) : ...;
  saveTrial({ session_id: S.session, round: "1", trial_index: i + 1,
              image_id: chosen, rt_ms: Math.round(d.rt) });
}
```

```php
// api/trial.php — correctness from the STORED plan, then idempotent write.
// Retries (flaky venue Wi-Fi) hit the same UNIQUE key → exactly one row.
$ins = $db->prepare(
  "INSERT INTO trials (...) VALUES (...)
   ON DUPLICATE KEY UPDATE
     image_id=VALUES(image_id), ..., rt_ms=VALUES(rt_ms)");
```

Why it matters: a retrying POST can never fabricate duplicates, and a tampered
client still cannot mark itself correct.

### 5.2 Session bootstrap — unique-preferred image claiming (race-safe)

```php
// api/lib.php — claim_cell(). Two rules in one query:
// 1) prefer NEVER-USED images ("half-day unique")
// 2) fall back to the 30-minute window when fresh supply runs out
$pick = $db->prepare(
  "SELECT image_id, file, psi FROM images
   WHERE source = ? AND gender = ? AND role = 'pool'
     AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 30 MINUTE)
   ORDER BY (last_used_at IS NULL) DESC, RAND() LIMIT 8");
$own = $db->prepare(
  "UPDATE images SET last_used_at = NOW()
   WHERE image_id = ? AND (last_used_at IS NULL OR last_used_at < ...)");
// loop: pick → guarded UPDATE → rowCount()==1 means WE own it; else try next.
```

The guarded UPDATE is the race-safety: two bootstraps at the same instant
compete for the same stamp; only one UPDATE wins per image, the loser re-picks.
`generate_plan()` builds the six-cell allocation on top: per gender 6 real +
3 GAN + 3 SD in each round, GAN ψ split 50/50, same-gender 2AFC pairs,
randomized sides/order.

### 5.3 Reload/resume — every trial already on the server

Because §5.1 saves each trial instantly, resume is just *asking what's done*:

```js
// experiment.js startup — saved session in localStorage?
const st = await fetch(`${API}/state.php?session_id=${ctx.sid}`);
// state.php returns { completed, plan, saved: {practice:[], r1:[], r2:[]} }
// → "Welcome back" → stage2 skips done.* indices and resumes.
```

### 5.4 Admin test-flag — one column, three filters, zero deletions

```sql
-- flagging is just: UPDATE sessions SET is_test = 1 ...
-- then every consumer adds: WHERE is_test = 0
```

- `leaderboard.php` board + LIVE meta
- `export.php` / `export_research.py` research CSVs
- `admin.php` **deliberately does not filter** — flagged rows show with a 🧪
  badge so you can review testing plays without ever deleting data.

---

## 6. Databases

**Two databases, one server, two credential sets — isolation by architecture,
not convention.** Research data is anonymous; prize contacts are personally
identifying. There is no shared key between them (the alias is a chosen
codename, not an identifier), and each app credential can only reach its own
database — verified by attempting cross-reads (denied).

Why MySQL/MariaDB: the workload is tiny relational writes (a few thousand rows
per event), needs UNIQUE constraints and upserts (`ON DUPLICATE KEY UPDATE`),
and both local (portable MariaDB, no install) and cloud (free Azure tier) run
identical SQL.

### 6.1 `ai_detect_research` — sessions

| Column | Type | Purpose |
|---|---|---|
| session_id | CHAR(24) PK | random hex, created at signup |
| lang | ENUM('th','en') | UI language |
| birth_year / cohort | SMALLINT / ENUM | cohort derived server-side (genx/millennial/genz/other) |
| gender | VARCHAR(16) | F / M / Non-binary |
| device / screen | VARCHAR | auto-captured covariates |
| plan | LONGTEXT (JSON) | the server-generated trial plan — basis of all correctness |
| alias | VARCHAR(24) **UNIQUE** | codename; uniqueness enforced by the DB itself (race-safe) |
| familiarity_1..8 | TINYINT NULL | survey v2: yes/no=1/0 · freq=0–4 · self-rating=1–5 (branch-consistency validated) |
| consent | TINYINT NULL | NULL until answered; exports default to consent=1 only |
| completed | TINYINT | 1=natural finish; quitters keep their trials |
| is_test | TINYINT | admin flag → hidden from board/exports, kept in admin |
| repeat_tag | VARCHAR(64) + INDEX | cookie id; one-play lock when TEST_MODE=0 |
| created_at / finished_at | DATETIME | session-length analytics |

### 6.2 `ai_detect_research` — trials

| Column | Purpose |
|---|---|
| session_id FK | → sessions |
| round ENUM('practice','1','2') | practice rows excluded from analysis queries |
| trial_index + **UNIQUE(session_id, round, trial_index)** | the idempotency key of §5.1 |
| image_id / pair_image_id | chosen image; other image of the 2AFC pair |
| image_type ENUM('real','gan','diffusion') | analysis dimension |
| response TINYINT | 1=AI chosen / 2: "AI" answered |
| correct TINYINT | computed server-side only |
| rt_ms INT | stimulus-onset → tap |
| created_at | per-trial timestamp |

### 6.3 `ai_detect_research` — images (the pool)

`image_id` PK · file · source · psi (GAN truncation) · gender · gender_conf ·
role(pool/practice) · **last_used_at** (NULL = never used → unique-preferred
ordering; stale = window-eligible) · INDEX(source, gender, role, last_used_at)
for the claim query. Auto-ingested from `pool_manifest.csv` on first bootstrap.

### 6.4 `ai_detect_prize` — prize_contacts

`alias` UNIQUE · contact · created_at. Written by `contact.php` using the
prize credential. That credential **cannot read ai_detect_research**, and the
research credential cannot read this table — the isolation is real, not
promised.

---

## 7. Results, Capabilities & Pages

### What the system does

- Runs a bilingual (TH/EN), mobile-first, reload-safe, one-play-per-browser
  experiment with server-authoritative scoring and race-safe unique codenames
- Serves balanced stimulus plans (24 AI + 24 real per session, six-cell design,
  same-gender pairs, half-day-unique reuse policy with window fallback)
- Captures per-trial RT/accuracy + demographics + 8-item branching survey,
  consent-gated, with quitters retained as analyzable data
- Provides live event tooling: LIVE TV board, leaderboard, admin dashboard
  (pies, funnels, RT histograms, quit map, response bias, familiarity-vs-
  accuracy, CSV downloads), test-session flagging, health endpoint

### Every accessible page

| URL | What | Who |
|---|---|---|
| `/` | the game | players |
| `/live.html` | LIVE board (press F for fullscreen) | booth TV |
| `/leaderboard.html` | leaderboard | players |
| `/contact.html` | late prize entry | players |
| `/admin.php` | research dashboard (password) | researcher |
| `/api/health.php` | `{"ok":true}` monitor | UptimeRobot |
| `/api/export.php?type=…` | CSV downloads (admin cookie) | researcher |

### Verification summary

36-session plan verification (six-cell allocation, same-gender pairs,
zero-overlap-while-fresh) · E2E suites local+production (alias uniqueness,
survey branch consistency, prize isolation, resume state, flag lifecycle) ·
jsdom regression test for the DOM-clear fix · dataset funnel fully reported in
`docs/DATASET_REPORT.md` (99,725 processed → 88,915 QA-passed → 4,444 deployed).

### Known limitations (engineering honesty)

- F1's 1GB disk caps the deployed pool (~500MB practical ceiling)
- SD1.5 faces fully consumed; sd15-Female cell capped at 596 by QA supply
- One-play lock is cookie-based (private browsing / second browser evades it)
- Gender labels are automated — classifier bias documented in methods
- Fixed round order (R1→R2) is a locked research trade-off, stated as limitation

---

*Report generated 2026-10-31 · see CHANGELOG.md for the full decision log.*
