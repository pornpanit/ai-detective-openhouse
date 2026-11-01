# CHANGELOG — AI Detective project

Every change to the system, newest first. Dates are when the work was done.

---

## 2026-11-01 — Session 16: GitHub → Azure CI/CD pipeline

### Auto-deploy from GitHub push to Azure
- **GitHub Actions workflow** (`.github/workflows/deploy.yml`): on push to
  `main` (when `public/**` changes), automatically:
  1. Checkout code
  2. Build deploy.zip with Python (correct forward-slash separators)
  3. Login to Azure via service principal (`AZURE_CREDENTIALS` secret)
  4. `az webapp deploy --type zip`
  5. Health check (3 retries with auto-restart fallback)
  6. Logout
- **Service principal**: `github-actions-face-detective` (contributor role,
  scoped to rg-face-detective only)
- **Trigger conditions**: `public/**` or `scripts/make_deploy_zip.py` changes;
  also manual via GitHub UI ("Run workflow") or `gh workflow run`
- **Deploy time**: ~4 minutes
- **Test verified**: manual trigger → 3m 59s → health check passed ✓
- README updates on non-code files do NOT trigger a deploy

### What this changes
- **Before**: manual `python make_deploy_zip.py` + `az webapp deploy` (or
  wipe-recreate via `scripts/redeploy.py`)
- **After**: `git push` → auto-deploy in ~4 min
- **Emergency fallback**: `scripts/redeploy.py` still works for wipe-recreate

---

## 2026-10-31 — Session 15: Investigation 1 visual flash (replaces text stamp), quit flow survey

### Investigation 1 stamp: text → visual flash
- **Replaced** the "SUSPECT MARKED" text stamp with a **golden border glow + scale animation** on the selected face. No text = no text can be wrong. The user had persistent issues with the text stamp showing "REAL" despite correct server code (likely a deep caching issue).
- Investigation 1 + practice pair: `faceFlash(btn)` — visual only, zero correctness info.
- Investigation 2 + practice single: unchanged — text stamps "AI"/"REAL" (player's explicit button choice, safe).

### Quit flow: survey added after consent=yes
- When a player quits and selects **"Yes — use my data"**: the 8-question survey renders
  (same branching logic Q2/Q4/Q7 as the normal flow). After submit → exit page.
- When **"No"**: skips survey → exit page directly (unchanged).
- Survey rendered via DOM manipulation (quit path bypasses jsPsych timeline).

### Deployment
- Incremental zip deploy AGAIN failed to update experiment.js → **wipe-recreate** performed.
- Created `scripts/redeploy.py` for reliable wipe-recreate + verify.
- All verified: `faceFlash` calls 3, `survey-submit` in quit flow ✓, `quitFlow` present ✓.

---

## 2026-10-31 — Session 14: admin v6 — separate R1/R2, confusion matrix, scatter, survey, toggle fix

### Admin dashboard v6 (major restructure)
- **Separate Investigation 1 / Investigation 2 sections** with round-specific
  metrics, RT histograms, and accuracy-by-cohort charts.
- **Investigation 1**: AI faces spotted by type (gan/diffusion only — "real"
  rows excluded by design since they represent wrong answers in 2AFC).
- **Investigation 2**: hit rate + correct rejection rate + response bias +
  confusion matrix (TP/FN/FP/TN with counts and percentages, colored cells).
- **Speed–accuracy scatter plot**: each dot = 1 participant, colored by
  generation cohort (Gen X red, Millennial blue, Gen Z green). Shows
  speed-accuracy tradeoff.
- **Accuracy distribution histogram**: grouped bars by generation
  (0–40%, 40–60%, 60–80%, 80–100% bins).
- **Survey data panel**: 8 questions with aggregated response distributions
  (percentage bars per option, using familiarity_1..8 from consented sessions).
- **Toggle fix**: replaced nested `<form>` inside table cells with JavaScript
  `fetch()` call to `admin.php?api=1` — both hide AND un-hide now work
  reliably across all browsers.
- **Test data cleanup**: 12 test sessions deleted permanently
  (kept "Test" and "test2" only; also kept "Saranpat" [flagged] and
  "Baitoey"/"incognito" [real players]).

### Verified on production
- All 9 features confirmed via authenticated page fetch.
- PHP lint clean. No game code changes — admin only.

---

## 2026-10-31 — Session 13: CRITICAL — endExperiment() function-as-text bug fixed

### Root cause of "raw HTML tags" after quitting
- `jsPsych.endExperiment(quitFlow)` passed the **function object** as the
  `end_message` parameter. jsPsych 7 does `DOM_target.innerHTML = end_message`
  which calls `quitFlow.toString()` — displaying the **JavaScript source code**
  as raw text on screen. This is exactly what the user saw.
- **Fix**: `jsPsych.endExperiment()` (no args) followed by a separate
  `quitFlow()` call. The quit flow now renders properly.

### Stamp still showing "REAL" — stale server file
- The incremental zip deploy was NOT updating `experiment.js` on the server
  (same root cause as earlier stale-file issues). **Wipe-recreate** of the
  Azure app resolved this — all files now verified fresh on production.
- Confirmed on production: `stampOverlay("filed")` at lines 640, 713;
  `stamp_filed: "SUSPECT MARKED"` in strings.

### Deployed
- Wipe-recreate + fresh zip deploy + endExperiment fix deployed together.
- Verified: `endExperiment()` with no args (1 occurrence),
  `endExperiment(quitFlow)` (0 occurrences — the bug is gone).

---

## 2026-10-31 — Session 12: stamp text, quit simplification, cache fix, docs

### Stamp text changed
- Investigation 1 + practice pair stamp: "FILED" → **"SUSPECT MARKED"** /
  **"ระบุตัวแล้ว"** — better detective-theme fit, still neutral (never reveals
  correctness).
- Investigation 2 stamps unchanged: "AI"/"REAL" (player's explicit choice).

### Quit flow simplified
- Button text: "ABORT" → **"QUIT"** / **"ออก"**
- Flow: quit → confirm → consent question → simple exit page with leaderboard
  link (no debrief paragraphs — those are for finishers). Old `quitFlow`
  simplified accordingly.

### Cache fix (root cause of "stamp still shows REAL")
- `no-cache` meta tags added to `index.html` (Cache-Control, Pragma, Expires)
  — forces browsers to always re-download the HTML, which then loads the
  versioned JS/CSS. Version strings bumped `?v=11` → `?v=12`.
- Root cause explained: the HTML file was cached, so it loaded OLD script
  URLs without version strings → browser served OLD cached JS. The meta tags
  prevent HTML caching; version strings handle JS/CSS.

---

## 2026-10-31 — Session 11: practice stamp fix, prize-before-score, cache-busting

- **Practice pair stamp fixed**: the practice trial (running before
  Investigation 1) still showed "AI"/"REAL" based on which face was tapped
  — same ground-truth leak as the Investigation 1 bug fixed in session 10.
  Now shows neutral "FILED" / "บันทึกแล้ว" (2 occurrences of
  `stampOverlay("filed")` confirmed in deployed code: practice + Investigation 1).
- **Flow reorder**: prize offer moved BEFORE score card. New order:
  `consent → [survey if yes] → prize offer → score card (confetti + share) → debrief`.
  The score card is now the final climactic reward after all logistics.
- **Cache-busting**: `?v=11` version strings added to `experiment.js`,
  `strings.js`, `game.css` in `index.html` — fixes desktop browsers serving
  stale cached copies (the "no sound/stamp/transition on computer" issue).
- **Pair composition verified** from production data: 3 sessions sampled,
  every pair = exactly 1 AI + 1 real, totals = 12 AI + 12 real per session.

---

## 2026-10-31 — Session 10: stamp leak fix, flow reorder, longer sounds, admin password

### ⚠ Critical fix: Investigation 1 stamp leaked ground truth
- The 2AFC stamp showed "AI" or "REAL" based on which face was tapped —
  **this revealed whether the player chose correctly** (per-trial feedback,
  violating the no-feedback spec). Fixed: Investigation 1 stamps now show a
  neutral **"FILED" / "บันทึกแล้ว"** (zero correctness information).
  Investigation 2 stamps ("AI"/"REAL") are safe — they echo the player's
  explicit button choice. Static check added: `S9 R1 stamp is neutral`.
- Verified on production: `stampOverlay("filed")` in served JS.

### Flow reorder: consent → [survey] → score card → prize → debrief
- Score card (count-up, confetti, canvas, share/save) moved AFTER consent
  and survey — the reward comes after completing research questions.
- Survey is now **conditional**: only shown when consent = yes
  (`conditional_function: () => S.consentVal === 1`).
- Non-consented players skip the survey but still see the full score card
  (user decision: "score card for all").
- `finish` action moved from survey's on_finish to score card's on_load
  (always fires regardless of consent answer).

### Typewriter text fix
- `.typewriter` CSS: removed `white-space: nowrap` (was truncating long
  paragraphs on mobile). Replaced with a wrap-friendly fade-in animation.

### Richer sounds (all longer and more satisfying)
- Typewriter: 10 clicks over 0.8s (was 5/0.3s)
- Camera shutter: 150ms + reverb tail (was 80ms)
- Whoosh: 0.8s sweep with rise-fall envelope (was 0.4s)
- Stamp thunk: deeper impact + low echo tail
- Fanfare: extended to 6-note ascending phrase

### Admin password
- Changed from auto-generated to `projectpass99` (Azure app setting, instant).
- `deploy_credentials.json` updated.

### Verification
- 25 static checks pass (including 4 new checks for stamp neutrality,
  survey conditional, flow order, and finish-action placement)
- Production verified: password login ✓, flow order in served JS ✓,
  stamp fix confirmed ✓

---

## 2026-10-31 — Session 9: immersive detective edition (stamps, sounds, confetti, score card, live alerts)

### Visual: dark classified-terminal theme
- **Full CSS overhaul**: near-black background with amber/gold phosphor accents,
  subtle scanline overlay, CRT vignette, monospace terminal text for labels,
  "TOP SECRET" / "CLASSIFIED" stamps on intro cards, gold accent line on cards.
- **Selection stamp**: when a player taps a face or answers, a rubber-stamp
  graphic ("AI" or "REAL" — the player's CHOICE, never correctness) slams
  over the screen with rotation + scale animation + thunk sound.
  RT-safe: fires in `on_finish` via `post_trial_gap: 500ms`.
- **Suspect headers**: each trial shows `📂 SUSPECT #07 — IDENTITY UNVERIFIED`
  in monospace above the stimulus, with a pulsing red dot.
- **Round-intro reveals**: "⚠ INTERCEPTED" / "🚨 ALERT" classified stamps +
  typewriter text animation on the narrative briefings.
- **Score count-up**: 0→X% with easing + gold glow → confetti burst (80
  particles, canvas, zero dependencies).

### Audio: WebAudio synthesized sounds (zero files)
- Camera **shutter click** on every face selection (post-response, RT-safe)
- **Typewriter** clicks during briefing text reveals
- **Whoosh** on round transitions
- **Stamp thunk** when the selection stamp lands
- **Fanfare** (major arpeggio) on score reveal
- **Confetti pops** during the celebration
- **Mute toggle** 🔊/🔇 — bottom-left corner, persisted in localStorage,
  gain 0.15 (subtle). All sounds fire post-response or between rounds —
  **never at stimulus onset**.

### Shareable score card (canvas)
- 1080×1080 canvas: dark terminal background, corner brackets, scanlines,
  detective emoji, codename, count-up percentage, rank stars + title
  (Chief Detective / Master / First Class / Rookie / Trainee), date,
  scan-to-play QR corner. Bilingual (TH/EN).
- **Save as picture** — canvas→PNG download (works everywhere)
- **Share** — Web Share API with PNG file (native iOS/Android sheet);
  falls back to save on desktop.

### Narrative re-write
- New briefing: "A rogue AI syndicate has infiltrated the facility's
  identity database with synthetic faces…"
- Round 1: "INTERCEPTED: 12 paired identity passes…"
- Round 2: "ALERT: 24 unverified identities detected…"
- Prize screen: "📋 SUBMIT EVIDENCE FOR REWARD" — framed as submitting
  a detective bounty.
- All debrief/disclosure requirements unchanged.

### LIVE board enhancements
- **New-#1 alert banner**: animated slide-down "🚨 NEW TOP DETECTIVE:
  [codename] cleared X%!" when the champion changes between polls
- **Booth victory fanfare**: opt-in 🔊 toggle on the board (plays a
  dramatic arpeggio when a new #1 appears)
- Rank-change pulse on rows

### Verification
- 21 static checks pass (including new RT-safety check: stamps in
  on_finish only, never on_load)
- Production E2E green (48 images serve, score card + stamps + sounds
  confirmed in served assets)
- `check_frontend.py` updated for the new code structure

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
