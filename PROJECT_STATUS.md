# PROJECT STATUS — Handoff Document

**Date**: 2026-11-02
**Purpose**: Complete status for continuing work on a new PC
**Audience**: Project owner (Pornpanit) or any developer picking this up

---

## 1. What's Built and Working (Current State)

### Live System
- **URL**: https://face-detective.azurewebsites.net
- **Game**: Dark classified-terminal theme with stamps, sounds, confetti, shareable score card
- **Flow**: Signup (codename + demographics) → practice → Investigation 1 (12 × 2AFC) → Investigation 2 (24 × AI/Real) → consent → [survey if yes] → prize → score card
- **Quit flow**: QUIT → confirm → consent → [survey if yes] → exit page
- **Resume**: reload-safe — players continue where they left off
- **LIVE board**: https://face-detective.azurewebsites.net/live.html (for booth TV, press F for fullscreen)
- **Admin dashboard**: https://face-detective.azurewebsites.net/admin.php (password: `projectpass99`)
  - Separate R1/R2 sections, confusion matrix with precision/recall/F1
  - Speed-accuracy scatter (seconds), accuracy distribution (3 gen histograms)
  - Survey data panel, toggle hide/show sessions
- **CI/CD**: Push to GitHub main → auto-deploy to Azure (~4 min)

### Infrastructure
| Component | Details |
|---|---|
| Azure App Service | F1 (free tier), Linux, PHP 8.4, Southeast Asia |
| Azure MySQL Flexible | B1ms, ~$1-2/month from student credit |
| 2 Databases | `ai_detect_research` (anonymous) + `ai_detect_prize` (isolated credentials) |
| GitHub repo | pornpanit/ai-detective-openhouse (private) |
| Spending limit | **"On"** — Azure suspends services when credit runs out, NEVER charges card |
| Remaining credit | Check at portal.azure.com → Cost Management → Credit |

### Current Pool
| Metric | Value |
|---|---|
| Total images | 4,432 (4,420 pool + 12 practice) |
| Per cell | ~737 per source×gender (6 cells) |
| Sources | FFHQ (real), StyleGAN2 (GAN), Stable Diffusion 1.5 (diffusion) |
| Image size | ~90KB avg, 768×768 JPEG |
| Deploy zip | ~387MB (fits F1's 1GB disk) |
| Unique sessions | ~90 before any image repeats |

### Key Credentials (also in `data/pipeline/deploy_credentials.json`)
| Item | Value |
|---|---|
| Admin password | `projectpass99` |
| MySQL host | face-detective-mysql.mysql.database.azure.com |
| Research DB user | `research_app` |
| Prize DB user | `prize_app` |
| Azure subscription | Azure for Students ($100 credit) |
| Service principal | github-actions-face-detective (for CI/CD) |

---

## 2. New Dataset Plan (Next Major Task)

### Dataset Composition (260,000 face images)
| Source | Models | Images | Resolution |
|---|---|---|---|
| Real — FFHQ | — | 70,000 | mixed |
| Diffusion | SD 1.5 | 10,000 | 512×512 |
| Diffusion | SD 2.1 | 10,000 | 768×768 |
| Diffusion | SD 3 Medium | 10,000 | 1024×1024 |
| Diffusion | SDXL Base 1.0 | 10,000 | 1024×1024 |
| Diffusion | FLUX.1 Dev | 10,000 | 1024×1024 |
| GAN | StyleGAN 1 | 40,000 | 1024×1024 |
| GAN | StyleGAN 2 | 40,000 | 1024×1024 |
| GAN | StyleGAN 3 | 40,000 | 1024×1024 |

### Design Decision (LOCKED)
- **3 groups**: Real / GAN-all / Diffusion-all → 6 cells (same as current)
- GAN cell samples equally across SG1 + SG2 + SG3
- Diffusion cell samples equally across all 5 SD/FLUX models
- More variety WITHIN each cell, not more cells

### Pool Target
| Metric | Current | Target (3×) |
|---|---|---|
| Pool size | 4,432 | **16,500** |
| Per cell | ~737 | ~2,750 |
| Unique sessions | ~90 | ~340 |
| Deploy size | ~387MB | ~1.5GB |
| Fits on F1 (1GB)? | ✅ | ❌ **Needs B1 or alternative** |

---

## 3. Hosting Decision (PENDING — Needed Before Event)

| Option | Disk | Max pool | Cost | Migration effort |
|---|---|---|---|---|
| **Azure F1 (current)** | 1GB | ~5,500 | $0 | None |
| **Azure B1** | 10GB | ~50,000 | ~$13/mo (from $100 credit) | One command |
| **Oracle Cloud Free** | 200GB | ~200,000 | $0 forever | 2-3 hours setup |
| ngrok (local PC) | PC disk | unlimited | $0 | Zero (but PC must stay on) |
| Cloudflare Workers | 10GB R2 | ~50,000 | $0 | 5-7 days rewrite |

### Recommendation
- **Short-term (event)**: Upgrade to Azure B1 — one command, covered by credit for ~7 months
- **Long-term (research)**: Consider Oracle Cloud Free after the event
- **Safety**: Azure spending limit is "On" — services suspend when credit runs out, never charge

### MySQL Stop/Start (save credit when not using)
```powershell
# STOP (after event, save credit)
az mysql flexible-server stop --name face-detective-mysql --resource-group rg-face-detective
# START (before testing/event)
az mysql flexible-server start --name face-detective-mysql --resource-group rg-face-detective
```

### Azure B1 Upgrade (when ready)
```powershell
az appservice plan update --name plan-face-detective --resource-group rg-face-detective --sku B1
```

---

## 4. New PC Setup (Quick Reference)

### Prerequisites
- Python 3.10+ → python.org (tick "Add to PATH")
- Git → git-scm.com
- Optional for local testing: XAMPP (or copy `tools/` from old PC)

### Get the Project
```powershell
git clone https://github.com/pornpanit/ai-detective-openhouse
cd ai-detective-openhouse
pip install -r requirements.txt
```

### Prepare the Dataset
Place the 260K dataset at any path (e.g., `D:\dataset\`), then create `sources.json`:
```json
[
  {"source": "real", "psi": "", "path": "D:/dataset/ffhq"},
  {"source": "gan", "psi": "sg1", "path": "D:/dataset/stylegan1"},
  {"source": "gan", "psi": "sg2", "path": "D:/dataset/stylegan2"},
  {"source": "gan", "psi": "sg3", "path": "D:/dataset/stylegan3"},
  {"source": "sd15", "psi": "", "path": "D:/dataset/sd15_faces"},
  {"source": "sd21", "psi": "", "path": "D:/dataset/sd21_faces"},
  {"source": "sd3", "psi": "", "path": "D:/dataset/sd3_faces"},
  {"source": "sdxl", "psi": "", "path": "D:/dataset/sdxl_faces"},
  {"source": "flux", "psi": "", "path": "D:/dataset/flux_faces"}
]
```

**Note**: The current code groups diffusion models into one "sd15" source. To use all 5 diffusion models, the pipeline `sources.json` needs them as separate sources, but the pool builder (`qa_pool.py`) groups them into "diffusion" cell. This may require minor code changes to `qa_pool.py` and `api/lib.php` to handle the new source names.

### Run the Pipeline (~10 hours for 260K images)
```powershell
# 1) Sample
python scripts\sample_candidates.py --out data\pipeline\candidates_new.csv --counts "real=70000,sd15=10000,sd21=10000,sd3=10000,sdxl=10000,flux=10000,gan=120000" --sources sources.json

# 2) Label (auto gender)
python scripts\label_gender.py --in data\pipeline\candidates_new.csv --out data\pipeline\candidates_new_labeled.csv --workers 12

# 3) Process (768px, 80-150KB)
python scripts\process_images.py --in data\pipeline\candidates_new_labeled.csv --report data\pipeline\processed_report_new.csv --workers 12

# 4) QA + pool rebuild
python scripts\qa_pool.py --cells "real=2750,gan=2750,sd15=2750"

# 5) Review pool
python scripts\contact_sheet.py
```

### Deploy
```powershell
# Via CI/CD (automatic on git push):
git add -A && git commit -m "update" && git push origin main

# Or manual:
python scripts\make_deploy_zip.py public deploy.zip
az webapp deploy --name face-detective --resource-group rg-face-detective --src-path deploy.zip --type zip

# Or wipe-recreate (clean slate):
python scripts\redeploy.py
```

### Azure Login (new PC)
```powershell
winget install Microsoft.AzureCLI
az login
```

### Local Testing
```powershell
# Window 1 — database
.\tools\mariadb\bin\mysqld.exe --datadir=D:\KMUTT\AIproject\tools\mariadb-data --port=3307 --bind-address=127.0.0.1 --console
# Window 2 — website
.\tools\php\php.exe -S 0.0.0.0:8090 -t public
```
→ http://127.0.0.1:8090

---

## 5. Open Items Checklist

| # | Item | Priority | Status |
|---|---|---|---|
| 1 | **IRB confirmation** for post-game consent | 🔴 Critical | Pending (user action) |
| 2 | **New dataset pipeline run** (260K faces) | 🔴 High | Not started |
| 3 | **Pool expansion** to 16,500 images | 🔴 High | Blocked by #2 |
| 4 | **Hosting upgrade** decision (B1 vs Oracle) | 🔴 High | Blocked by #3 |
| 5 | **Code changes** for multi-model diffusion sources | 🟡 Medium | Not started |
| 6 | **Documentation updates** (4 outdated files) | 🟡 Medium | Not started |
| 7 | **Test with consent=yes** + multiple players for admin charts | 🟡 Medium | Partially done |
| 8 | **Prize mechanics** (winner rule, timing) | 🟡 Medium | Pending |
| 9 | **UptimeRobot** monitoring setup | 🟢 Low | Not started |
| 10 | **QR code** print + scan test | 🟢 Low | QR generated, not printed |

---

## 6. Key Documents Reference

| Document | Location | Status |
|---|---|---|
| PROJECT_REPORT.md | root | ⚠️ Outdated (flow order changed) |
| README.md | root | ⚠️ Slightly outdated |
| design_v01_2026-10-29.md | root | ⚠️ Outdated (8-item survey, debrief removed) |
| CHANGELOG.md | root | ✅ Current (sessions 1-17) |
| docs/DATASET_REPORT.md | docs/ | ✅ Current |
| docs/NEW_PC_SETUP.md | docs/ | ⚠️ Needs multi-model update |
| PROJECT_STATUS.md | root | ✅ This document |
| OpenSpec specs | openspec/ | ⚠️ Needs update for debrief removal |

---

## 7. Technical Debt & Known Issues

| Issue | Impact | Fix needed |
|---|---|---|
| Incremental zip deploy sometimes doesn't update files | Stale experiment.js on server | Use `scripts/redeploy.py` (wipe-recreate) |
| PHP 8.4 `max()` TypeError with empty arrays | Admin dashboard 404 | Fixed with `array_merge()` pattern |
| jsPsych 7.3.4 append-without-clear bug | Trial stacking | Fixed with `on_trial_start` DOM clear |
| `post_trial_gap` for stamp visibility | 500ms delay between trials | Working as designed |
| Cache issues on desktop browsers | Old JS served | Fixed with no-cache meta tags + version strings |

---

*Generated 2026-11-02 by opencode session*
