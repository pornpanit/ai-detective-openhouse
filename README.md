# 🕵️ AI Detective

**A web game that measures how well humans can tell AI-generated faces from real ones — across generations.**

Built for a university openhouse: visitors scan a QR code, play a 7–8 minute detective
game on their own phones, and every answer quietly becomes research data — response
time, accuracy, and demographics — for a study on AI-image detection.

🚀 **Play it**: https://face-detective.azurewebsites.net
📺 **Live leaderboard** (for the booth screen): https://face-detective.azurewebsites.net/live.html
🔒 Private research repo · Thai/English in-game · anonymous data · zero cost hosting (Azure free tiers)

---

## What happens when you play

1. Scan the QR code → pick your language (Thai/English)
2. Sign up: birth year, gender, and a unique **detective codename** (one play per person)
3. A 2-trial training case (no feedback, ever)
4. **Investigation 1** — 12 rounds of two faces: *tap the AI one*
5. **Investigation 2** — 24 single faces: *AI or Real?* (no time limit)
6. Your case report: "database cleaned X%" + the Top Detectives leaderboard
7. Consent question, an 8-question background survey, optional prize draw
8. Done — you can watch the live board update at the booth

## What the researcher gets

- **Per trial**: response, correctness (computed server-side, tamper-safe), response time
- **Per player**: generation cohort (Gen X / Millennial / Gen Z), device metadata,
  8-item AI-familiarity survey, consent status
- **Balanced stimuli by design**: every session sees 24 AI faces (12 StyleGAN2 +
  12 Stable Diffusion) and 24 real FFHQ faces, gender-balanced, same-gender pairs in
  Investigation 1, no image reuse between players within 30 minutes
- **Analysis-ready exports** (consented players only, quitters included as covariate):
  CSVs for sessions, trials, and d′ inputs — downloadable from the admin dashboard
- **Privacy by architecture**: research data is anonymous; prize contacts live in a
  separate database with separate credentials and can never be joined to it

## How the project is organized

| Folder | What it is |
|---|---|
| `public/` | The website — game (`experiment.js`, `strings.js`), API (`api/`, PHP), admin dashboard, LIVE board, face images |
| `scripts/` | The stimulus pipeline + every verification/E2E script (re-runnable) |
| `openspec/` | Behavior specs of the system (the source of truth for what it does) |
| `config/` | Database schema + local dev config |
| `docs in root` | `design_v01…md` (research design), `CHANGELOG.md` (every change, dated), this README |

## Running it

**Local** (portable stack included in `tools/`, no installs):

```powershell
# Window 1 — database
.\tools\mariadb\bin\mysqld.exe --datadir=D:\KMUTT\AIproject\tools\mariadb-data --port=3307 --bind-address=127.0.0.1 --console
# Window 2 — website
.\tools\php\php.exe -S 0.0.0.0:8090 -t public
```
→ http://127.0.0.1:8090 · Admin: `/admin.php` · Health: `/api/health.php`

**Production**: live on Azure App Service F1 + MySQL Flexible (free tiers), region
Southeast Asia. Credentials (local only, gitignored): `data/pipeline/deploy_credentials.json`.

**Redeploy after changing anything in `public/`**:

```powershell
python scripts\make_deploy_zip.py public deploy.zip     # python zip = correct entry separators
az webapp deploy --name face-detective --resource-group rg-face-detective --src-path deploy.zip --type zip
curl https://face-detective.azurewebsites.net/api/health.php   # must say {"ok":true}
```

**One-play lock**: active when app setting `TEST_MODE=0` (currently `1` = replay
allowed for testing). Event day:
`az webapp config appsettings set --name face-detective --resource-group rg-face-detective --settings TEST_MODE=0`

**Event-day prep (admin)**: open `/admin.php` → **"🧪 Hide all existing sessions"** —
flags every testing play as hidden: they vanish from the public board, LIVE stats,
and research exports, but stay listed in admin for review.

**Image reuse policy**: sessions prefer never-used images ("half-day unique" at
150–220 players/day) and silently fall back to a 30-minute no-reuse window when a
cell's fresh supply runs out — the pool can never exhaust mid-event.

## Rebuilding / extending the image pool

The pipeline turns any face dataset into the balanced game pool:

```
sample_candidates.py → label_gender.py → process_images.py → qa_pool.py → contact_sheet.py
     (pick N/source)      (auto gender)     (768px, 80–150KB)   (one centered face,  (eyeball
                                                                      six-cell rebuild)   review)
```

Current pool: **4,432 images** (real 1,000/gender · GAN 600/gender 50/50 ψ · SD 596/636)
+ 12 practice. Full provenance: **[docs/DATASET_REPORT.md](docs/DATASET_REPORT.md)** ·
New machine setup: **[docs/NEW_PC_SETUP.md](docs/NEW_PC_SETUP.md)**
Sources: FFHQ (real, 70K), Stable Diffusion 1.5 (10K), StyleGAN2 (40K) from the PreSocial dataset.
The database re-ingests `public/assets/pool_manifest.csv` automatically on the first session
(after a pool change, run `DELETE FROM images;` on Azure first — see CHANGELOG session 8).

## For future maintainers — the 5-minute tour

| Want to… | Do this |
|---|---|
| Change game text | `public/strings.js` — TH + EN keys, keep in sync (`python scripts\check_frontend.py` verifies) |
| Change trials/rules | `public/experiment.js` (flow) + `public/api/lib.php` (plan generation); specs in `openspec/…/specs/` are the source of truth |
| Add new stimulus data | Pipeline above — see `scripts/` docstrings; QA rules live in `qa_pool.py` |
| Get research data | Admin dashboard → CSV buttons, or `python scripts\export_research.py data\export` |
| Understand why something is the way it is | `CHANGELOG.md` (every decision + incident, dated) + `design_v01_2026-10-29.md` |

Architecture in one paragraph: the phone runs a jsPsych app that asks a PHP API for a
server-generated trial plan (six-cell balanced, 30-min no-reuse window), saves every
trial immediately (idempotent, retry + offline outbox), and finishes with consent →
survey → prize → debrief. Two databases: research (anonymous) and prize contacts
(separate credentials, never joined). Reload-safe: players resume where they stopped.

**Secrets**: `data/pipeline/deploy_credentials.json`, `scm_cred.json`, `git_remote.txt`
are gitignored — Azure credentials live there and in App Service settings. Never commit them.
