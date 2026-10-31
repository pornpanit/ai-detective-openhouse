# CHANGELOG — AI Detective project

Every change to the system, newest first. Dates are when the work was done.

---

## 2026-10-31 — Session 8: full-dataset pipeline, pool v3, half-day unique, admin v5, test-flag

### Cleanup (disk headroom)
- Deleted 5,697 QA-failed images, `.opencode/node_modules`, root `node_modules`,
  old logs (~580MB freed; D: now 48+GB free).

### Pipeline v3 — everything processed
- All remaining FFHQ (67.5K) + GAN (37.5K) sampled/labeled/processed in 10
  chunks (~5 min/chunk): **99,725 processed-kept**, 88,915 pass QA v3.
  Note: the chunk sampler's default per-source fallback caused chunks to also
  draw other sources — net effect: the entire 120K dataset got sampled, which
  is exactly what we wanted.
- **QA v3** new rules (user request): monochrome drop (HSV saturation <25),
  strong face-turn drop (eye-nose asymmetry >0.65 ≈ ±20° yaw; roll >15°).
  Fail breakdown: multi-face 4,501 · turned 348 · off-center 91 ·
  monochrome 76 · rolled 21.
- **Pool v3: 4,432 + 12 practice** — real 1,000/gender, GAN 600/gender
  (ψ 300+300), sd15 596F/636M (SD supply-capped). Contact sheet regenerated.
  Spare archive: all 88,915 QA-passed images kept on the notebook.

### Half-day unique (image reuse policy)
- `claim_cell` now prefers never-used images per cell; silent 30-min window
  fallback when fresh supply is out — no exhaustion risk. Spec amended
  (stimulus-management: "Image reuse policy"); verify_plans updated to the
  stronger zero-overlap-while-fresh assertion + no-exhaustion check.

### Test-session flag (admin)
- `sessions.is_test` column (local + Azure). Flagged sessions hidden from
  board, LIVE meta, and research exports (api/export.php + export_research.py);
  fully visible in admin with 🧪 badge. Admin actions: "Hide all existing
  sessions" + per-row toggle. Event-day step replaces DB deletion.
- E2E: scripts/e2e_flag.py (flag → board/export hide → un-flag restore) ✓

### Admin v5 — full visual redesign
- Design-token system, header with LIVE pill, sectioned layout (Overview /
  Who joined / Detection performance / Response times & background / Sessions /
  Exports), polished empty states, responsive. All v4 panels kept.
- Fixed: str_pad(int) fatal under strict_types (joins-by-hour).

### Ship
- Deployed 387MB zip (app wipe-recreated). **Gotcha found & fixed**: Azure
  `images` table still held old pool rows → plans drew 404'd images. Rule:
  after any pool change, `DELETE FROM images` on Azure too. Both DBs now
  clean; local + prod E2E green (4,444 ingested, 48-image sessions serve).
- Docs: docs/DATASET_REPORT.md (full funnel numbers), docs/NEW_PC_SETUP.md,
  requirements.txt, README links + pool numbers updated.

---

## 2026-10-31 — Session 7, part 3: background preloading, 2AFC spacing, README rewrite

- **Preload moved to background**: all 51 trial images start downloading
  silently the moment the plan arrives (resumes included) — the loading gate
  before Investigation 1 now skips entirely when cached and only appears on
  genuinely slow connections, with story-flavored text carrying the real
  count ("Gathering case files… collecting the {n} faces…"). Fixes the
  confusing loader right after "Start Investigation 1".
- **2AFC spacing**: gap between the two faces widened to
  `clamp(16px, 4vw, 36px)` (+ row margins); face size caps adjusted to keep
  one-screen fit on small phones.
- **README rewritten** (English only): opens with the project pitch, player
  view, researcher view, and a plain structure map; operational guides and
  the maintainer tour preserved below.
- Cleanup: empty draft-era `public/api/data/` removed.
- Deployed + verified (features confirmed in served assets).

---

## 2026-10-31 — Session 7, part 2: custom web icon

- Replaced the placeholder emoji favicon with the project's own `icon.png`
  (user-supplied 1024px artwork, resized to 256px / 40KB at
  `public/assets/icon.png`) on every page. Old `favicon.svg` removed from
  repo (a stale copy lingers unreferenced on the server after incremental
  deploy). Deployed + verified.

---

## 2026-10-31 — Session 7: signup codenames, one-play lock, survey v2, prize flow, live board, admin v4

### Player-facing
- **Detective codename at signup** (after birth year + gender): required, 3–16
  chars with visible errors, live availability check (`api/check-alias.php`),
  DB-enforced unique (`sessions.uq_alias`) — race-safe; a taken name at
  bootstrap shows a rename screen. Old end-screen alias flow removed.
- **One playthrough per browser** (cookie `repeat_tag`) outside TEST_MODE;
  returning browsers get the already-played screen. TEST_MODE=1 app setting
  while testing — **event day: set TEST_MODE=0** (documented in README).
- **Investigation 2 + practice buttons → "AI / Real"** (was yes/no phrasing).
- **Survey v2** — the researcher's 8-item list: 4 yes/no items with live
  conditional frequency follow-ups (Q2/Q4/Q7 appear only on Yes), work/study
  item, 1–5 self-rated familiarity (Q8). Server validates branch consistency
  (follow-up answered without Yes → rejected). Schema: familiarity_1..8
  (replaced 1..5) on local + Azure.
- **Prize draw as its own screen** between survey and debrief — codename
  read-only, contact field, Join/Skip; open to everyone regardless of consent;
  quit path intentionally stays short. `contact.html` kept for late joiners.
- **Favicon** 🕵️ on every page.

### Live board (replaces tv.html)
- `live.html`: fixed the "undefined%" bug (field name pct→percent), live
  clock, pulsing LIVE badge, champion spotlight card, ranks 2–10 two-column
  grid, stats strip (today/total/finished/best via new leaderboard `meta`
  block), scan-to-play QR corner, F-key fullscreen, re-renders only on change.
  Old `tv.html` removed from the server (app wipe-recreated once).

### Admin v4
- New panels: joins-by-hour, conversion funnel, where-players-quit, RT
  distribution per round, accuracy by self-rated familiarity (Q8), accuracy
  by AI-image exposure (Q2), cohort × type matrix, R2 response bias by cohort.
- **CSV download buttons** (sessions/trials/dprime) via admin-gated
  `api/export.php`.

### Ops
- DB migration applied to local + Azure (sessions/trials recreated — the 4
  test sessions on production were wiped for the NOT NULL alias schema).
- Deployed + verified: prod E2E covers signup, duplicate alias, survey
  consistency, prize contact, resume state, leaderboard meta (`e2e` scripts
  in `scripts/`).

---

## 2026-10-31 — Session 6: stacking root-caused & fixed, dead quit button, cleanup

- **THE stacking bug, finally root-caused**: jsPsych **7.3.4 core never clears
  the display between trials and the plugins append** — verified empirically
  against the *official* CDN files with `scripts/jsdom_trial_test.js`
  ("TRIAL-ONEATRIAL-TWOB" accumulation). Why it bit us hard: our CSS made the
  accumulation visible as scrolling screens. Fix: dedicated `#game-root`
  display element + `on_trial_start` that clears `#jspsych-content` — each
  trial now fully replaces the previous one (jsdom test proves it). This also
  fixes the "previous screen shows under Begin training" boundary case.
- **Dead quit button root-caused**: the quit handler referenced a `jsPsych`
  variable that no longer exists in the two-stage design → silent
  ReferenceError. Now calls the tracked current instance (`S.jp`).
- **Cleanup**: removed debug/junk files from repo AND deployed site (public
  test page, deploy/git logs, run.sh, downloaded official-build copies,
  pubcred/pubprofile, mysql_create log). App wipe-recreated once so the
  server wwwroot is pristine (only inert Azure placeholder hostingstart.html
  remains). Secrets still gitignored. Deployed + verified (bootstrap, resume
  state, images, junk URLs now 404).
- Production DB: user's abandoned test sessions purged (resume system now
  prevents this class of rows).

---

## 2026-10-31 — Session 5: UX overhaul, equal AI/real balance, resume, GitHub

### Game flow (from user's phone-test feedback)
- **Full Thai rewrite** of every string (native-level); participant gender now
  exactly 3 options (Female / Male / Non-binary).
- **One trial per screen**: `body.trial-mode` locks the viewport (100dvh
  flex-centered) during trials — no scrolling, image always in the same
  position; responsive breakpoints (768/1200px) for tablet + desktop on all
  pages.
- **Quit redesigned** as a jsPsych-native confirm trial — the interrupted trial
  is discarded (never saved, no RT contamination); fixed dead popup buttons.
- **Reload/resume system**: browser context + new `api/state.php` → "Welcome
  back, continue?" resumes at the first unanswered trial; completed players
  see "already played" + play-again. Abandoned test sessions purged from
  local + production (per user approval).
- **Live leaderboards**: `leaderboard.html` + `tv.html` now poll every 10s
  with an "updated" timestamp.

### Research design change: equal real/AI counts
- **Round 2 → 24 trials** (12 AI = 6 GAN + 6 diffusion, 12 real): session
  totals 24 AI + 24 real, exactly equal, six-cell parity intact.
  Specs (`stimulus-management`, `experiment-flow` 7–8 min) + design doc updated.
- **Capacity bug found & fixed by verify_plans**: R2=24 doubles real-image
  draw (12/session) → the 334/cell pool 503-exhausted at ~27 sessions/30-min
  window. Pool enlarged to **636/cell (3,816 + 12 practice)** → 53
  sessions/window. 334MB python-zip deployed fine (backslash, not size, was
  the original zip-deploy killer).

### Admin dashboard v3
- Joins (cohort/gender/language) now **donut charts** (pure CSS); session
  length + accuracy stay bars.
- Round & type section rebuilt with correct semantics: R1 = "% of AI faces
  spotted" (no real row by design); R2 = hit rate (gan/diff) + correct
  rejection rate (real) — with an explainer box.

### Version control
- `.gitignore` (tools, node_modules, big data dirs, **secrets**); initial
  commit (3,916 files incl. face images per user choice); **private repo
  pushed: github.com/pornpanit/ai-detective-openhouse**; README gained the
  future-maintainer guide.

---

## 2026-10-31 — Session 4: PRODUCTION DEPLOYED (Azure)

**The game is live at https://face-detective.azurewebsites.net** (Azure for
Students subscription, $0 within free tiers).

### Resources created
- `rg-face-detective` (Southeast Asia) · App Service plan F1 (free, Linux)
- Web app `face-detective` (PHP 8.4) · MySQL Flexible Server
  `face-detective-mysql` (B1ms Burstable — free 750 h/month grant;
  `require_secure_transport=OFF`, traffic stays inside Azure)
- Databases `ai_detect_research` + `ai_detect_prize` with isolated users
  (same design as local); all credentials as encrypted app settings.
  Local copies of credentials: `data/pipeline/deploy_credentials.json` +
  `scm_cred.json` (never commit/share).

### Deployment war story (root causes found & fixed)
1. **334MB zip rejected** by OneDeploy → shrunk pool to spec floor
   **2,004 images (334/cell) + 12 practice** = 176MB zip. `qa_pool.py` gained
   `--n-per-cell` + a safety abort; incident note: an intermediate run read
   reports from the old path and emptied assets — recovered from
   `data/processed`, no loss.
2. **Git deploy** filled the 1GB F1 disk (repo 340MB + extraction) → abandoned.
3. **THE actual bug**: PowerShell 5.1 `Compress-Archive` writes backslash zip
   entry separators → Kudu's Linux rsync failed on every subdirectory file
   (`wwwroot\css\game.css: Invalid argument`). Fixed by
   `scripts/make_deploy_zip.py` (python zipfile, forward slashes).
4. SCM basic auth disabled by default on new apps — enabled via
   `basicPublishingCredentialsPolicies`; az CLI redacts credentials in output
   (fetched via `az rest` + PowerShell instead).

### Verified live (production)
`/api/health.php` ok · bootstrap returns valid 12+18 plan · all 42 session
images serve · trial save + server-side correctness + score 1/1 · TV
leaderboard + admin gate (401) · test data cleaned afterwards.
QR code generated: `data/export/qr_production.png` (print + phone-scan verify
pending — task 7.2).

---

## 2026-10-31 — Session 3, part 4: required survey, dashboard charts, TV leaderboard

- **Reverted** the extra consent question added in part 3 (per user: not what
  was meant). The **existing 5-item familiarity survey is now required**
  (all selects `required`, hint text updated; spec + design doc amended —
  answers NULL only on abandonment). `consent_extra` column/case removed
  everywhere.
- **Admin dashboard v2** (`admin.php`): CSS bar charts — sessions by
  generation cohort / participant gender / language, session-length
  distribution (bucketed, mean + median), accuracy by cohort, accuracy by
  round×type. Added an inline explainer for "round & image type"
  (R1 = pick-the-AI pair, R2 = yes/no single; real = FFHQ, gan = StyleGAN2,
  diffusion = SD1.5). Auto-refreshes every 60s.
- **TV leaderboard** (`tv.html`): big-screen dark theme with medals for top 3,
  bilingual title, auto-refresh 30s, designed for the booth TV. Linked from
  `leaderboard.html` ("TV mode").

---

## 2026-10-31 — Session 3, part 3: trial positioning, consent question, admin dashboard

- **Fixed trial scrolling**: every trial now scrolls to top on start
  (`on_trial_start` on both jsPsych instances) and image sizes tightened
  (single 66vw/310px max, pair 42vw/200px max) so each trial renders at the
  same screen position without residual scroll.
- **Required question after consent = yes**: new conditional screen
  (required text answer, TH/EN — edit keys `extra_q_h` / `extra_q_label` /
  `extra_q_ph` in `strings.js`). Stored in new `sessions.consent_extra`
  column (schema.sql + ALTER applied); saved via finalize action
  `consent_extra`, rejected unless consent=1. Skipped entirely on quit path.
- **Admin dashboard** (`public/admin.php`, was a design non-goal — added on
  request): password-gated (config `admin_pw`, default `adminlocal`,
  override with `ADMIN_PW` env in production). Read-only: session/consent/
  completion counters, per-cohort accuracy + RT (consented only), per
  round×type accuracy, 20 most recent sessions. Prize DB never exposed.

---

## 2026-10-31 — Session 3, part 2: 2AFC rendering fix

- **Investigation 1 showed "?" instead of images and path-text buttons**: dropped
  the `image-button-response` plugin entirely (its `%src%` templating +
  canvas/DOM mixing misbehaved on phones). Both pair trials (practice +
  Investigation 1) now use `html-button-response` with a custom `button_html`
  function embedding the real image URL — all trials are DOM-rendered now,
  which also fixes **Investigation 2 screens stacking on top of each other**
  (previous trials not being cleared).
- 2AFC layout: flex-centered side-by-side pair — both faces exactly
  equidistant from the middle (`#jspsych-html-button-response-stripe` +
  `button.pair-btn` in `game.css`).

---

## 2026-10-31 — Session 3: phone-testing fixes, stimulus QA v2, cleanup

### Fixed (found during first real phone test)
- **White screen on phone**: `public\lib\plugin-*.js` had been copied from npm
  `dist/index.js` (CommonJS builds — define no browser globals). Replaced with
  `dist/index.browser.js` builds. jsPsych core was already correct.
- **Stuck "Continue" on profile page**: session bootstrap ran inside the
  demographics form's async finish handler; jsPsych 7.3.4 didn't await it, so
  the next stage crashed silently. Restructured: form only collects values →
  visible "Preparing case files…" screen does the bootstrap with a Retry
  button and visible TH/EN errors (`experiment.js`, new strings in `strings.js`).

### Stimulus QA v2 (user report: multi-face / badly framed images in pool)
- New `scripts/qa_pool.py`: framing QA on every processed image — exactly one
  face (YuNet ≥0.60), face area 25–90% of frame, center offset ≤15%.
- **Incident**: first QA run used gender_conf ≥0.80 — the gender model's
  softmax rarely exceeds that (2015-era, uncalibrated), so ALL 4,284 images
  were rejected and the assets folder was wiped. Recovered fully (all
  intermediates intact) and rewrote as a full rebuild; gender filter stays at
  the original labeling cut (0.70), no second filter.
- Framing QA pass rates exposed a real SD1.5 bottleneck (~15–31% pass) →
  processed the remaining 7,500 SD faces (`candidates_sd15_extra*`, report
  `processed_report_extra.csv`).
- **Pool rebuilt: 3,816 images — 636/cell, GAN ψ 318+318 per gender, 12
  practice.** Old 4,284-pool manifest kept as
  `data/pipeline/pool_manifest_v1_4284.csv`. DB `images` table reset (re-ingests
  automatically).
- `scripts/contact_sheet.py` → `data/export/contact_sheet.html` (lazy-loading
  grid of the whole pool for eyeball review).

### Pipeline made re-runnable for future datasets
- `sample_candidates.py` parameterized: `--counts`, `--exclude`, `--sources`
  (JSON source map). See README pipeline table for the full recipe.

### Cleanup & docs
- Deleted stale draft files: `public/api/save_data.php`, `public/images/`.
- Reorganized `data/`: `pipeline/` (manifests+reports), `logs/` (all run logs
  + QA report), `models/`, `export/`, `processed/`.
- Added `README.md` (folder map + run commands + pipeline guide) and this
  `CHANGELOG.md`.

---

## 2026-10-30 — Session 2: full build (backend, frontend, verification)

- Portable stack installed into `tools/`: PHP 8.5.12 NTS (checksum-verified),
  MariaDB 11.4.4; `config/schema.sql` — research DB (images/sessions/trials,
  UNIQUE(session,round,trial_index)) + prize DB, separate credentials
  (cross-DB reads verified denied).
- Pipeline v1: sampled 7,500 candidates → auto gender-labeled (YuNet +
  Levi-Hassner net, conf ≥0.70; 5,997 kept) → processed 768×768 JPEG 80–150KB
  (all in band, 0 dup/corrupt) → six-cell pool 4,272 @712/cell + 12 practice
  (373MB). *(Superseded 10-31 by QA v2.)*
- API: session bootstrap (six-cell sampling, same-gender 2AFC pairing,
  randomized sides, 30-min `last_used_at` window), idempotent trial save
  (server-side correctness), finalize (consent/survey/finish/quit/alias with
  profanity filter), score, leaderboard, contact, health.
- Frontend rewrite: TH/EN strings dictionary (66 keys), landing→demographics
  →practice→preload→12×2AFC→18×yes/no→score→alias→consent→5-item survey
  →debrief; quit control with consent capture; per-trial AJAX save with
  retry + localStorage outbox; detective theme.
- Verification passed: 50-session plan balance (same-gender 50/50, exact
  allocations), zero overlap within 30-min window + expiry re-eligibility,
  export dry run (consent filter, quitters included, practice excluded),
  perfect-play score 30/30 on leaderboard.
- OpenSpec `add-detection-experiment`: tasks 1.x–5.x + 6.4–6.6 completed
  (27/33). Local DB cleaned to pristine state.

---

## 2026-10-29 — Session 1: design + specs + pipeline v1 plan

- `design_v01_2026-10-29.md`: research design locked — mixed design (image
  type within × generation between), d′ + RT, post-game consent, quit-with-
  consent, no feedback/no timeout, accuracy-only score, TH/EN, ~6–7 min.
- OpenSpec initialized; change `add-detection-experiment` (proposal, 4 spec
  deltas, design D1–D13, tasks) — validated strict.
- Dataset verified: FFHQ 70K real / SD1.5 faces 10K / StyleGAN2 40K (ψ0.5+ψ1).
  Locked: GAN = ψ mix 50/50; six-cell = source × gender; same-gender pairing;
  trial counts 12 + 18; automated gender labeling; curated pool (2–5K,
  80–150KB); 30-min no-reuse window; Azure F1 + MySQL Flexible free tier;
  detective theme as pure strings layer.
