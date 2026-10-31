# Setting up a new PC for this project

Everything needed to run the stimulus pipeline and redeploy the game from a
fresh machine (Windows; the same steps work on macOS/Linux with path changes).

## 1. Install the basics

- **Python 3.10+** (python.org — tick "Add to PATH")
- **Git** (git-scm.com)
- Optional for local serving: none needed — the portable PHP + MariaDB stack
  lives in `tools/`, which is gitignored, so a fresh clone does NOT include it.
  Local site testing on a new PC requires installing XAMPP (or copying `tools/`
  from the original notebook). For deployment-only work you don't need PHP.

## 2. Get the project

```powershell
git clone https://github.com/pornpanit/ai-detective-openhouse
cd ai-detective-openhouse
pip install -r requirements.txt
```

The clone already contains the full website (`public/`, including the current
face pool) — enough to deploy. Intermediates (`data/processed`, models, logs)
are gitignored by design; rebuild them from the dataset if needed.

## 3. Point the pipeline at a face dataset

The dataset lives outside the repo (e.g. `D:\PROJECT\seniorProject\dataset`).
Either keep that path (default in `scripts/sample_candidates.py`) or create
`my_sources.json`:

```json
[
  {"source": "real", "psi": "", "path": "D:/dataset/real"},
  {"source": "gan",  "psi": "0.5", "path": "D:/dataset/gan/psi05"},
  {"source": "gan",  "psi": "1",   "path": "D:/dataset/gan/psi1"}
]
```

## 4. Run the pipeline (add more images)

```powershell
# 1) sample new candidates (never reuse ids: --exclude against all previous)
python scripts\sample_candidates.py --out data\pipeline\candidates_new.csv `
    --counts "real=7500" --exclude data\pipeline\sampled_all.csv --sources my_sources.json

# 2) auto gender-label
python scripts\label_gender.py --in data\pipeline\candidates_new.csv `
    --out data\pipeline\candidates_new_labeled.csv --workers 6

# 3) process to 768px / 80-150KB (reports stack — qa_pool reads them all)
python scripts\process_images.py --in data\pipeline\candidates_new_labeled.csv `
    --report data\pipeline\processed_report_new.csv --workers 6

# 4) QA v3 + pool rebuild (per-source targets per gender cell)
python scripts\qa_pool.py --cells "real=1000,gan=600,sd15=636"

# 5) eyeball the result
python scripts\contact_sheet.py     # -> data/export/contact_sheet.html
```

QA v3 rules (applied to every image, uniformly): exactly one face (YuNet ≥0.60),
face box 25–90% of frame, near-centered (≤15%), not monochrome (mean HSV
saturation ≥25), not strongly turned (eye-nose asymmetry ≤0.65 ≈ ±20° yaw,
roll ≤15° — slight turn is fine).

`data/models/` (YuNet + gender net, gitignored) must be re-downloaded once —
URLs are in `scripts/label_gender.py` / `qa_pool.py` headers.

## 5. Deploy the updated pool / site

```powershell
python scripts\make_deploy_zip.py public deploy.zip   # python zip = correct separators!
az webapp deploy --name face-detective --resource-group rg-face-detective `
    --src-path deploy.zip --type zip
curl https://face-detective.azurewebsites.net/api/health.php   # must be {"ok":true}
```

The database re-ingests `public/assets/pool_manifest.csv` automatically on the
next session (or force it: `DELETE FROM images;` first).
Keep the total deployed pool under ~500MB (F1's 1GB disk is the ceiling).

## 6. Azure login on a new PC

```powershell
winget install Microsoft.AzureCLI
az login
```

Credentials live on the original notebook in `data/pipeline/deploy_credentials.json`
(gitignored). Azure CLI redacts publishing credentials — see CHANGELOG Session 4
for the workaround used for SCM access.
