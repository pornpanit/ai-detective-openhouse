# AI Detective — Openhouse Experiment Website

Mobile web game (jsPsych + PHP + MariaDB) measuring how well people detect
AI-generated faces, across generations. Detective theme; anonymous research
data; prize-draw contact stored separately.

## Folder map

```
D:\KMUTT\AIproject\
├── public\                  ← THE WEBSITE (everything the server serves)
│   ├── index.html           entry page (loads everything below)
│   ├── experiment.js        game flow (2 stages + quit + end screens)
│   ├── strings.js           TH/EN text incl. detective theme
│   ├── css\game.css         styling
│   ├── lib\                 jsPsych 7.3.4 (browser builds) + plugins
│   ├── api\                 PHP endpoints (session, trial, finalize,
│   │                          score, leaderboard, contact, health, lib.php)
│   ├── assets\faces\        ★ the image pool the game serves (3,828 files)
│   ├── assets\pool_manifest.csv   pool list the API ingests into the DB
│   ├── leaderboard.html     Top Detectives board
│   ├── contact.html         prize-draw contact (separate DB)
│   └── test\smoke.html      jsPsych sanity page
├── config\                  config.php (DB credentials) + schema.sql
├── scripts\                 all pipeline & verification code (see below)
├── data\
│   ├── pipeline\            manifests + labeling/processing/QA reports
│   ├── processed\           all processed 768x768 images (source of the pool)
│   ├── models\              YuNet + gender classifier files
│   ├── logs\                every pipeline run log + qa_v2_report.csv
│   └── export\              research export CSVs + contact_sheet.html
├── tools\                   portable php\ + mariadb\ (no system install)
├── openspec\                spec-driven change tracking (add-detection-experiment)
├── design_v01_2026-10-29.md research design document
├── README.md                this file
└── CHANGELOG.md             what changed, when, why — READ THIS
```

## Running locally (2 windows)

```powershell
# Window 1 — database (keep open)
.\tools\mariadb\bin\mysqld.exe --datadir=D:\KMUTT\AIproject\tools\mariadb-data --port=3307 --bind-address=127.0.0.1 --console

# Window 2 — website (keep open)
.\tools\php\php.exe -S 0.0.0.0:8090 -t public
```
- PC: http://127.0.0.1:8090 · Phone (same Wi-Fi): http://<PC-IP>:8090 (see `ipconfig`)
- Health check: http://127.0.0.1:8090/api/health.php → must say `{"ok":true}`
- **Admin dashboard**: http://127.0.0.1:8090/admin.php (password from
  `config\config.php` → `admin_pw`, default `adminlocal`; set `ADMIN_PW` env
  var in production). Charts + stats + recent sessions, auto-refresh 60s.
- **TV leaderboard** (booth screen): http://127.0.0.1:8090/tv.html — big mode,
  auto-refresh 30s.
- **Direct DB access** (research DB):
  `.\tools\mariadb\bin\mysql.exe -P 3307 -u research_app -presearch_dev_pw ai_detect_research`
  (prize DB: `-u prize_app -pprize_dev_pw ai_detect_prize`). Useful queries:
  `SELECT COUNT(*) FROM sessions;` · `SELECT * FROM trials ORDER BY id DESC LIMIT 10;`

## The stimulus pipeline (re-runnable for future datasets)

Order (each step reads the previous output):

| Step | Command | Output |
|---|---|---|
| 1. Sample candidates | `python scripts\sample_candidates.py --out data\pipeline\<name>.csv [--counts "src=N,..."] [--exclude <old.csv>] [--sources my.json]` | candidate path manifest |
| 2. Gender labeling | `python scripts\label_gender.py --in <candidates.csv> --out <labeled.csv> --workers 6` | labels + confidence |
| 3. Process to 768px/80–150KB + QA | `python scripts\process_images.py --in <labeled.csv> --report data\processed_report_<name>.csv --workers 6` | `data\processed\*.jpg` |
| 4. Pool rebuild (framing QA + 6-cell balance + assets sync) | `python scripts\qa_pool.py` | pool manifest + `public\assets\faces\` |
| 5. Contact sheet for eyeball review | `python scripts\contact_sheet.py` | `data\export\contact_sheet.html` |

For a NEW dataset: write `my_sources.json` (`[{"source":"mydata","psi":"","path":"D:\\images"}]`),
run steps 1–3 with it, then step 4 rebuilds from ALL `processed_report*.csv` files.
The DB re-ingests the manifest automatically on the next session start
(or force it: `DELETE FROM images;` in MySQL first).

## Production (Azure) — LIVE

- **URL**: https://face-detective.azurewebsites.net (admin: `/admin.php`)
- Resources: `rg-face-detective` / App Service F1 / MySQL Flexible B1ms (free
  grant, Southeast Asia) — Azure for Students subscription
- Credentials (local only, never share): `data\pipeline\deploy_credentials.json`
  (DB users + admin pw) · `data\pipeline\scm_cred.json` (Kudu)
- **Redeploy after changing anything in `public\`**:
  ```powershell
  # 1. stage site (excluding nothing) and zip WITH PYTHON (forward slashes!)
  Copy-Item public\* <stage> -Recurse -Force   # or edit public/ directly in a stage copy
  python scripts\make_deploy_zip.py <stage> deploy.zip
  # 2. deploy + verify
  az webapp deploy --name face-detective --resource-group rg-face-detective --src-path deploy.zip --type zip
  curl https://face-detective.azurewebsites.net/api/health.php
  ```
- QR code for printing: `data\export\qr_production.png`
- MySQL free grant ends after 12 months — **delete the server after the event**
  unless you keep using it (student credit covers it regardless).

## Verification scripts

- `python scripts\check_frontend.py` — static checks (strings parity, assets, flow rules)
- `python scripts\verify_plans.py 20` — bootstrap N sessions; 6-cell balance, same-gender pairs, 30-min no-overlap (needs servers running)
- `python scripts\export_research.py data\export` — consented research data → CSVs

## Current state (see CHANGELOG for history)

- **PRODUCTION LIVE**: https://face-detective.azurewebsites.net
- Pool: 2,004 images, 334 per cell (real/GAN/SD × M/F), GAN = ψ0.5+ψ1 50/50, +12 practice
- Trial plan: 12 × 2AFC (same-gender pairs) + 18 × yes/no
- OpenSpec tasks: 28/33 complete (remaining: real-phone E2E, pilot, QR print verify, uptime monitor)
