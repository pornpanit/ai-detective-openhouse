# Tasks

## 1. Project Setup

- [x] 1.1 Scaffold web project (public/, api/, config) in the repo and copy the existing PHP/jsPsych draft in; verify the draft index page serves locally
- [x] 1.2 Add jsPsych and dependencies with pinned versions; verify a minimal jsPsych trial renders on a phone-sized viewport

## 2. Stimulus Pipeline

- [x] 2.1 Candidate sampler: random-sample ~2,500 images per source from D:\PROJECT\seniorProject\dataset (FFHQ real 70K; SD1.5 faces 10K; StyleGAN2 40K across ψ=0.5/ψ=1) and verify balanced candidate counts per source and per ψ config
- [x] 2.2 Automated gender labeling: run a local face-attribute classifier over candidates, drop low-confidence faces, and verify the labeled manifest has adequate male/female counts per source for six-cell selection
- [x] 2.3 Batch processing script (parallel Pillow: identical crop/resize/compress to ~80–150KB) plus QA screens (perceptual-hash dedup, corrupt-file filter) and verify sampled output meets the size budget with zero duplicates/corrupt files
- [x] 2.4 Six-cell balanced subset selector + pool manifest generator (2–5K, equal cells, GAN cells 50/50 ψ=0.5/ψ=1 mix) and verify equal cell counts within the ~600MB storage budget
- [x] 2.5 Assemble the separate practice image set (all six cells represented) and verify no overlap with the deployment pool (automated set-difference check)

## 3. Backend API

- [x] 3.1 Create MySQL schema: `sessions`, `trials` (with UNIQUE(session_id, round, trial_index)) and the separate-credential `prize_contacts` database; verify migrations run and unique constraint rejects a duplicate trial row
- [x] 3.2 Implement session bootstrap endpoint (random session id, demographics + cohort derivation, device metadata, trial plan generation: six-cell balanced sampling, same-gender 2AFC pairing, randomized order and placement, 30-minute window exclusion with `last_used_at` stamping) and verify a created session returns a valid plan matching round composition requirements
- [x] 3.3 Implement idempotent trial save endpoint (server-side correctness check, rt_ms, INSERT ... ON DUPLICATE KEY UPDATE) and verify a retried request stores exactly one row with correct values
- [x] 3.4 Implement consent, familiarity-survey, and completion update endpoints (consent settable from both natural end and quit path; completion flag distinguishes quitters); verify NULL consent by default, `consent=0` exclusion from research export, and consented-but-incomplete sessions included by default
- [x] 3.5 Implement /health.php endpoint (research + prize DB connectivity check, no data exposure); verify it returns OK when both DBs are reachable and a failure status when either is not

## 4. Frontend Experiment Flow

- [x] 4.1 Build the strings dictionary (TH/EN) and language-select landing page; verify switching language re-renders every screen string in the chosen language
- [x] 4.2 Implement demographics form (birth year required, gender; no familiarity items pre-game) with validation; verify submission blocks on missing birth year and stores the derived cohort on the session
- [x] 4.3 Implement instructions + 2–3 practice trials (no feedback, non-test images); verify practice responses are marked round=practice and excluded from scored analysis queries
- [x] 4.4 Implement full preload with loading indicator (jsPsych preload, manifest-driven) before Round 1; verify with throttled network that no scored trial starts before its image is cached
- [x] 4.5 Implement Round 1 2AFC timeline (two images, tap to choose AI, no feedback, no timeout); verify recorded RT reflects stimulus-onset-to-tap on a test run
- [x] 4.6 Implement Round 2 binary yes/no timeline (single image, two options only, no timeout); verify only yes/no responses exist in saved rows
- [x] 4.7 Implement AJAX per-trial save with retry-on-failure and duplicate suppression (data-capture spec); verify killing the connection mid-round leaves all completed trials stored
- [x] 4.8 Implement final score screen (accuracy-only computation), post-game consent question, five-item familiarity survey, and debrief screen (order: score → consent → survey → debrief; survey shown regardless of consent answer); verify score matches server-side correctness, consent and survey answers update the session, and the debrief discloses the fictional detective framing and states the actual research purpose
- [x] 4.9 Implement detective theme copy via the strings dictionary (briefing intro, Investigation round labels, "Top Detectives" leaderboard title, "database cleaned X%" end score) in TH/EN; verify all themed strings render in both languages and trial mechanics remain identical to spec (no gating, no per-trial feedback)
- [x] 4.10 Implement in-game quit control (visible during scored rounds, confirmation dialog, then consent question and short debrief without survey); verify quitting mid-round stores the consent answer, keeps saved trials, and marks the session incomplete

## 5. Leaderboard and Prize

- [x] 5.1 Implement alias capture (length cap, profanity filter) and leaderboard page (alias + accuracy-only score, refresh-based); verify no demographic or contact data appears in leaderboard responses
- [x] 5.2 Implement prize contact capture writing to the separate-credential store keyed by alias only; verify the research DB credentials cannot read the contact store and exports contain no contact data

## 6. Integration and Pilot

- [ ] 6.1 End-to-end test on multiple real phones (iOS + Android, different browsers): full session from QR-code URL to debrief with zero missing trials in the DB
- [ ] 6.2 Repeat-play and edge case checks: second playthrough on same device, abandonment mid-Round 1, network loss during save; verify all scenarios leave analyzable data per data-capture spec
- [ ] 6.3 Pilot with ~10 participants measuring real session duration; verify ~7–8 min typical and <15 min worst case (trial counts 12 + 24 are locked by the six-cell design — adjust only via spec change)
- [x] 6.4 Research export dry run: d′ computation inputs (hits/misses/false alarms/correct rejections per participant), consent filtering, device covariate fields; verify a clean CSV for analysis
- [x] 6.5 Concurrency no-duplication check: bootstrap two sessions within the 30-minute window and verify their plans share zero images; verify an image becomes eligible again after window expiry (clock-shifted test)
- [x] 6.6 Plan balance verification: for a batch of generated sessions, verify every Round 1 pair is same-gender and every session's image allocation matches the six-cell specification

## 7. Deployment

- [x] 7.1 Deploy to Azure App Service F1 (PHP runtime) with MySQL Flexible Server free tier: separate-credential research and prize databases, app settings for both connections, /assets images; verify the azurewebsites.net URL passes the same end-to-end phone test and /health.php returns OK
- [ ] 7.2 Generate and print the QR code for the production URL; verify it resolves on a phone camera from a printed sheet
- [ ] 7.3 Configure a 5-minute uptime monitor on /health.php (keep-warm + event-day status); verify alerts reach a booth helper's phone before the event and a pre-event warm-up ping is sent

## Workflow follow-up

- Confirm IRB coverage of post-game consent before openhouse (design doc §9); if not covered, add pre-game two-option consent as a spec update to experiment-flow
- Archive the change after pilot results are reviewed and open items in design doc §8 are resolved
