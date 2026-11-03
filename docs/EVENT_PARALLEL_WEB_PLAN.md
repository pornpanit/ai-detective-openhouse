# EVENT PARALLEL WEB — Build & Deploy Runbook (Lab PC)

> **Who executes this:** an AI coding agent (or developer) on the **lab server PC**.
> **What it builds:** a self-contained parallel instance of the AI Detective game for
> the university openhouse, served from the lab PC through Cloudflare Tunnel (primary)
> and ngrok (backup), with its own SQLite database and a new 8,100-face dataset.
> **Target load:** 200++ concurrent players on their own phones via QR code.
> **Golden rule:** the original `public/`, MariaDB stack, and Azure site are NEVER
> modified. Everything you build is new files in new folders. Zero exceptions.

---

## 0. Placeholders to fill before starting

| Placeholder | Meaning | Example |
|---|---|---|
| `<DOMAIN>` | your own domain added to Cloudflare (free plan) | `mydomain.com` → game at `play.mydomain.com` |
| `<EVENT_DATE>` | first day of the openhouse (for analysis filter) | `2026-11-20` |
| `<260K_PATH>` | path to the 260K source dataset on this PC | `D:\dataset\260k` |
| `<SEED>` | fixed random seed for dataset sampling | `20261103` |
| `<ADMIN_PW>` | admin dashboard password for the event instance | any strong string |

Also decide the local port (default `8090`) and the repo root (default
`D:\ai-detective-openhouse` — from `git clone`).

---

## 1. Architecture (what you are building)

```
     Player phones (200++ concurrent)
        │ QR #1 (big)
        ▼
   play.<DOMAIN>            ← Cloudflare edge: TLS, caches 8,100 face images
        │ QR #2 (small, "if not loading")
        ▼                                     ngrok static domain (backup door)
   cloudflared (Windows service)  ←── idle ── ngrok http --domain=<static>.ngrok-free.app
        │
        ▼  localhost:8090
   LAB PC
   ├─ php -S 0.0.0.0:8090 -t public_event router.php   (PHP_CLI_SERVER_WORKERS=32)
   ├─ data\event.sqlite       (research DB, WAL mode)
   ├─ data\event_prize.sqlite (prize contacts, isolated file)
   └─ <260K_PATH> ──► scripts\build_event_pool.py ──► public_event\assets\faces\ (8,100 jpgs)
                                              └────► public_event\assets\pool_manifest.csv
```

- **Both tunnels point at the same local site** — same DB, same one-play lock. Backup
  switching is instant (players just scan the other QR).
- **Azure site stays live** (`face-detective.azurewebsites.net`) as the last-resort
  fallback QR. It shares nothing with this instance.

### Why these choices (context, do not change)
- **Cloudflare Tunnel over ngrok (primary):** ngrok free = 40 connections/min +
  1 GB/month + browser warning page — a QR rush would trip it instantly. Cloudflare
  Tunnel is free, uncapped, and edge-caches the face images so the PC's upload link
  is not the bottleneck.
- **SQLite over MariaDB:** removes a whole server process, has no connection limit,
  and the write load is trivial (worst case ~40 small INSERTs/sec). One file =
  trivial backup. Data is fully isolated from both the project MariaDB and Azure.
- **ngrok free backup:** free tier includes ONE static domain (stable URL). No edge
  caching and 1 GB/month — acceptable for an emergency door only.

---

## 2. Phase A — Lab PC prerequisites

### A1. Get the repo
```powershell
git clone https://github.com/pornpanit/ai-detective-openhouse D:\ai-detective-openhouse
cd D:\ai-detective-openhouse
pip install -r requirements.txt
```

### A2. Portable PHP (or fresh download)
Either copy `tools\php\` from the original notebook, or download the latest
**PHP 8.4+ Windows x64 NTS zip** (windows.php.net) to `tools\php\`. Then:

1. Copy `tools\php\php.ini-development` → `tools\php\php.ini`
2. In `php.ini`, enable (uncomment or add):
   ```ini
   extension=pdo_sqlite
   extension=sqlite3
   extension=pdo_mysql      ; keep if you ever run the original stack
   memory_limit=256M
   ```
3. Verify:
   ```powershell
   & tools\php\php.exe -m | Select-String sqlite
   # must list: pdo_sqlite, sqlite3
   ```

### A3. MariaDB is NOT needed
Do not copy/install MariaDB. The parallel web uses SQLite only. (The original
MariaDB stack in `tools\` stays for the dev flow on the original notebook only.)

### A4. Install tunnels
- **cloudflared**: `winget install Cloudflare.cloudflared` (or download the .exe)
- **ngrok**: signup at ngrok.com, note your authtoken, claim your ONE free
  **static domain** (e.g. `aidetective.ngrok-free.app`) in the ngrok dashboard.

### A5. Domain on Cloudflare
1. Buy a domain (any registrar, ~$10/yr).
2. Cloudflare dashboard → Add domain (free plan) → set the nameservers your
   registrar offers. Wait for activation.

### A6. Windows settings for a server PC
- Settings → Power: never sleep. Display off is fine.
- BIOS: enable "Restore on AC Power Loss" (auto-boot after outage).
- Time: Settings → verify timezone is **(UTC+7) Bangkok** and clock syncs
  (`w32tm /resync`). The analysis exclusion relies on correct local timestamps.
- Note the machine's admin rights (needed to install the cloudflared service).

---

## 3. Phase B — Build `public_event\` (the parallel site)

> Copy, then port. **Never edit anything in `public\`.**

### B1. Copy
```powershell
# from repo root
robocopy public public_event /E
Remove-Item public_event\assets\faces -Recurse -Force     # old 4,444 faces
Remove-Item public_event\assets\pool_manifest.csv -Force  # rebuilt in Phase C
Remove-Item public_event\assets\qr.png -Force             # regenerate in Phase D
```

### B2. New file `config\schema.sqlite.sql` — complete DDL

```sql
-- AI Detective event instance — SQLite schema
-- vs MariaDB schema.sql: ENUMs -> CHECK, LONGTEXT -> TEXT,
-- + model column (images, trials), + viewport columns (sessions), + idx_image.

PRAGMA journal_mode = WAL;

-- data/event.sqlite (research)
CREATE TABLE IF NOT EXISTS images (
  image_id     TEXT PRIMARY KEY,
  file         TEXT NOT NULL,
  source       TEXT NOT NULL CHECK (source IN ('real','gan','diffusion')),
  model        TEXT NOT NULL CHECK (model IN ('sg1','sg2','sg3','sd15','sd21','sd3','sdxl','flux','ffhq')),
  psi          TEXT NOT NULL DEFAULT '',
  gender       TEXT NOT NULL CHECK (gender IN ('Male','Female')),
  gender_conf  REAL NOT NULL DEFAULT 0,
  phash        TEXT NOT NULL DEFAULT '',
  role         TEXT NOT NULL DEFAULT 'pool' CHECK (role IN ('pool','practice')),
  last_used_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_cell  ON images (source, gender, role, last_used_at);
CREATE INDEX IF NOT EXISTS idx_model ON images (model, gender, role, last_used_at);

CREATE TABLE IF NOT EXISTS sessions (
  session_id     TEXT PRIMARY KEY,
  lang           TEXT NOT NULL CHECK (lang IN ('th','en')),
  birth_year     INTEGER NOT NULL,
  cohort         TEXT NOT NULL CHECK (cohort IN ('genx','millennial','genz','other')),
  gender         TEXT NOT NULL DEFAULT '',
  device         TEXT NOT NULL DEFAULT '',
  screen         TEXT NOT NULL DEFAULT '',
  viewport_start TEXT NOT NULL DEFAULT '',   -- dynamic: "390x784@3"
  viewport_end   TEXT NULL,                  -- dynamic: captured at finish/quit
  plan           TEXT NOT NULL,
  alias          TEXT NOT NULL,
  familiarity_1  INTEGER NULL,
  familiarity_2  INTEGER NULL,
  familiarity_3  INTEGER NULL,
  familiarity_4  INTEGER NULL,
  familiarity_5  INTEGER NULL,
  familiarity_6  INTEGER NULL,
  familiarity_7  INTEGER NULL,
  familiarity_8  INTEGER NULL,
  consent        INTEGER NULL,
  completed      INTEGER NOT NULL DEFAULT 0,
  is_test        INTEGER NOT NULL DEFAULT 0,
  repeat_tag     TEXT NOT NULL DEFAULT '',
  created_at     TEXT NOT NULL,
  finished_at    TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_alias   ON sessions (alias);
CREATE INDEX        IF NOT EXISTS idx_repeat ON sessions (repeat_tag);

CREATE TABLE IF NOT EXISTS trials (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id    TEXT NOT NULL REFERENCES sessions(session_id),
  round         TEXT NOT NULL CHECK (round IN ('practice','1','2')),
  trial_index   INTEGER NOT NULL,
  image_id      TEXT NOT NULL,
  pair_image_id TEXT NULL,
  image_type    TEXT NOT NULL CHECK (image_type IN ('real','gan','diffusion')),
  model         TEXT NOT NULL CHECK (model IN ('sg1','sg2','sg3','sd15','sd21','sd3','sdxl','flux','ffhq')),
  response      INTEGER NOT NULL,
  correct       INTEGER NOT NULL,
  rt_ms         INTEGER NOT NULL,
  created_at    TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_trial ON trials (session_id, round, trial_index);
CREATE INDEX        IF NOT EXISTS idx_image ON trials (image_id);

-- data/event_prize.sqlite (isolated prize contacts)
CREATE TABLE IF NOT EXISTS prize_contacts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  alias      TEXT NOT NULL,
  contact    TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_alias ON prize_contacts (alias);
```

Notes:
- `source` is now the 3-level category (`real`/`gan`/`diffusion`); the specific
  generator lives in `model`. The old schema's `sd15` as a source value is gone —
  `sd15` is a `model` under `source='diffusion'`.
- All timestamps are Bangkok local time strings `YYYY-MM-DD HH:MM:SS`
  (= `datetime('now','+7 hours')` in SQLite, which is UTC-based).

### B3. New file `config\config_event.php`

```php
<?php
// Parallel (event) instance configuration — SQLite.
return [
    'research' => [
        'dsn'  => 'sqlite:' . dirname(__DIR__, 2) . '/data/event.sqlite',
        'user' => null, 'pass' => null,
    ],
    'prize' => [
        'dsn'  => 'sqlite:' . dirname(__DIR__, 2) . '/data/event_prize.sqlite',
        'user' => null, 'pass' => null,
    ],
    'manifest' => dirname(__DIR__) . '/public_event/assets/pool_manifest.csv',
    'window_minutes' => 30,
    'admin_pw' => getenv('ADMIN_PW') ?: '<ADMIN_PW>',
];
```

### B4. Port `public_event\api\lib.php` to SQLite

1. **Config loading**: keep the same `cfg()` fallback chain; make
   `dirname(__DIR__,2) . '/config/config_event.php'` the first candidate (adjust the
   loop so the event config wins; simplest: hard-require the event config).
2. **DB helpers** — replace both PDO factories:
   ```php
   function db_research(): PDO {
       static $pdo = null;
       if ($pdo !== null) return $pdo;
       $pdo = new PDO(cfg()['research']['dsn'], null, null, [
           PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
           PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
       ]);
       $pdo->exec("PRAGMA busy_timeout = 5000");
       $pdo->exec("PRAGMA synchronous = NORMAL");
       $pdo->exec("PRAGMA foreign_keys = ON");
       return $pdo;
   }
   ```
   Same for `db_prize()` (prize dsn). `journal_mode=WAL` is set once at schema
   creation; busy_timeout per connection.
   **Persistent connection** (static) matters here: `php -S` workers live for many
   requests; reopening SQLite each request wastes time. Do the same in every API
   file that currently calls `new PDO` indirectly — they all go through these two
   helpers.
3. **Delete** both `SET time_zone` lines (`lib.php:31`, `lib.php:41`).
4. **Bangkok "now"** helper, used everywhere below:
   ```php
   function now_bkk(): string { return (new DateTime('now', new DateTimeZone('+07:00')))->format('Y-m-d H:i:s'); }
   function bkk_minus(int $minutes): string {
       return (new DateTime('now', new DateTimeZone('+07:00')))->modify("-$minutes minutes")->format('Y-m-d H:i:s');
   }
   ```

### B5. The 14 SQL port fixes (exact list)

| # | File | MySQL | SQLite replacement |
|---|---|---|---|
| 1 | lib.php ~31 | `SET time_zone = '+07:00'` | delete |
| 2 | lib.php ~41 | `SET time_zone = '+07:00'` | delete |
| 3 | lib.php ~72 | `INSERT IGNORE INTO images ...` | `INSERT OR IGNORE INTO images ...` |
| 4 | lib.php ~94 | `last_used_at < NOW() - INTERVAL $win MINUTE` | `last_used_at < ?` with param `bkk_minus($win)` |
| 5 | lib.php ~95 | `ORDER BY (last_used_at IS NULL) DESC, RAND() LIMIT 8` | `ORDER BY (last_used_at IS NULL) DESC, RANDOM() LIMIT 8` |
| 6 | lib.php ~97-98 | `SET last_used_at = NOW() WHERE ... < NOW() - INTERVAL` | `SET last_used_at = ?` + `WHERE ... < ?` (two params, `now_bkk()`, `bkk_minus($win)`) |
| 7 | session.php ~37 | `VALUES (...,NOW())` | `VALUES (...,?)` param `now_bkk()` |
| 8 | trial.php ~74 | `VALUES (...,NOW())` | `VALUES (...,?)` param `now_bkk()` |
| 9 | trial.php ~75 | `ON DUPLICATE KEY UPDATE col=VALUES(col)...` | `ON CONFLICT(session_id, round, trial_index) DO UPDATE SET image_id=excluded.image_id, pair_image_id=excluded.pair_image_id, image_type=excluded.image_type, model=excluded.model, response=excluded.response, correct=excluded.correct, rt_ms=excluded.rt_ms` |
| 10 | contact.php ~19 | `VALUES (?,?,NOW())` | `VALUES (?,?,?)` param `now_bkk()` |
| 11 | contact.php ~20 | `ON DUPLICATE KEY UPDATE contact = VALUES(contact)` | `ON CONFLICT(alias) DO UPDATE SET contact = excluded.contact` |
| 12 | finalize.php ~60 | `finished_at = NOW()` | `finished_at = ?` param `now_bkk()` |
| 13 | finalize.php ~64 | `finished_at = NOW()` | `finished_at = ?` param `now_bkk()` |
| 14 | (all files) | any leftover backtick-quoted identifiers / MySQL cast | standard SQLite syntax |

After porting, grep to prove zero MySQL-isms remain:
```powershell
Select-String -Path public_event\api\*.php -Pattern "NOW\(\)|RAND\(\)|INSERT IGNORE|ON DUPLICATE|VALUES\(|INTERVAL|time_zone"
# must return nothing (VALUES( only as 'excluded.' references / literal SQL VALUES keyword is fine to audit by eye)
```

### B6. Model-aware `generate_plan()` rework (lib.php)

Current code claims by `source` with `sd15` hardcoded. Rework:

1. `claim_cell()` gains a `string $model` parameter; the WHERE clause becomes
   `source = ? AND model = ? AND gender = ?` (pass the 3-level source AND the model).
   Keep the guarded-UPDATE claim (race-safe; SQLite serializes writers anyway).
   Add `model` to the SELECT so callers can pass it through.
2. Stratified allocation — replace the hardcoded cells in `generate_plan()`:

   | Round | Per gender | Models to cycle through |
   |---|---|---|
   | Round 1 (12 pairs: 6M + 6F) | 6 real + 3 GAN + 3 diffusion | real→`ffhq`; GAN cycles `sg1,sg2,sg3`; diffusion cycles `sd15,sd21,sd3,sdxl,flux` |
   | Round 2 (24 singles: per gender 6 real + 3 GAN + 3 diffusion) | same | same cycling, continue rotation |
   | Practice | 1 gan-M, 1 real-M, 1 diffusion-F | any one model each (fixed choice OK) |

   Cycling rule: within one player's plan, no model repeats until all models in
   its family have been used (GAN: 6 slots/cycle of 3; diffusion: 12 slots/cycle
   of 5 — shuffle each cycle for order). Every plan item records both
   `type` (real/gan/diffusion) and `model`.
3. **trials insert** (trial.php): the plan items now carry `model`; store it in the
   new `trials.model` column (from the resolved plan item, never client input —
   same trust model as `correct`).
4. **Practice images**: extend the practice SELECT to also return `model`; pick
   practice rows per the table above. `build_event_pool.py` (Phase C) MUST include
   `role='practice'` images: at least 1 gan-Male, 1 real-Male, 1 diffusion-Female
   (2–3 spares each is better).

### B7. Dynamic screen capture

1. `public_event\experiment.js` — session signup payload (~line 658) becomes:
   ```js
   device: navigator.userAgent.slice(0, 250),
   screen: `${screen.width}x${screen.height}`,
   viewport_start: `${window.innerWidth}x${window.innerHeight}@${window.devicePixelRatio || 1}`,
   ```
2. Same file, **both** finalize calls (`action:'finish'` AND `action:'quit'`) add:
   ```js
   viewport_end: `${window.innerWidth}x${window.innerHeight}@${window.devicePixelRatio || 1}`,
   ```
3. `session.php`: accept and validate before insert:
   ```php
   $vp = (string) ($in['viewport_start'] ?? '');
   if (!preg_match('/^\d{1,4}x\d{1,4}@\d(\.\d+)?$/', $vp)) $vp = '';
   ```
   Insert into the new column (empty string if invalid — game continues regardless).
4. `finalize.php` `finish` and `quit` branches: validate the same way, then
   `SET ..., viewport_end = COALESCE(nullif(?,''), viewport_end)` so a retry never
   overwrites the first capture.
5. `export.php`: include `viewport_start`, `viewport_end` in the sessions CSV.
6. **Cache-bust**: `public_event\index.html` — bump `?v=12` → `?v=13` on
   `experiment.js`, `strings.js`, `game.css`. Without this, phones that played
   during testing keep the OLD JavaScript from browser cache.

### B8. New file `public_event\router.php` (cache headers for Cloudflare)

`php -S` does not send cache headers on static files; without them Cloudflare
will not cache. Create:

```php
<?php
// Router for: php -S 0.0.0.0:8090 -t public_event router.php  (run from repo root)
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;
if ($uri === '/') { header('Cache-Control: public, max-age=300'); return false; } // index.html
if (is_file($file)) {
    header('Cache-Control: ' . (preg_match('#^/assets/faces/#', $uri)
        ? 'public, max-age=86400, immutable'   // faces: cache hard at the edge
        : 'public, max-age=300'));             // everything else: 5 min
    return false;                              // let the built-in server serve it
}
http_response_code(404);
echo 'not found';
```

Notes: `.php` files under `api/` never hit the `is_file` early-return path with
headers (php -S executes them); the router only decorates static assets.

### B9. Health & admin sanity port

- `api\health.php`: must open BOTH SQLite files and return `{"ok":true}`.
- `admin.php`: check its queries for the same MySQL-isms (it exports
  sessions/trials CSVs — add the new columns there too, B7.5) and its CSV download
  must read from SQLite. Verify "hide all existing sessions" still works
  (`UPDATE sessions SET is_test=1`).
- `check-alias.php`, `leaderboard.php`, `score.php`, `state.php`: port any
  remaining MySQL-isms (mostly none — plain SELECTs).

### B10. `.gitignore` additions (repo root)

```
public_event/assets/faces/
data/event*.sqlite*
```

---

## 4. Phase C — Dataset: `scripts\build_event_pool.py`

**Goal:** sample and process 8,100 images from `<260K_PATH>` into
`public_event\assets\faces\` + write `public_event\assets\pool_manifest.csv`.

### C1. Allocation (exact numbers)

| Family | Model | Pool per model | ×3 multiplier | Gender split |
|---|---|---|---|---|
| Real | `ffhq` | 900 | **2,700** | 50/50 M/F |
| GAN | `sg1` `sg2` `sg3` | 300 each (900 total) | **2,700** (900 each) | 50/50 per model |
| Diffusion | `sd15` `sd21` `sd3` `sdxl` `flux` | 180 each (900 total) | **2,700** (540 each) | 50/50 per model |
| **Total** | 9 models | 2,700 | **8,100** | |

Plus **practice rows**: 2–3 per required cell (gan-Male, real-Male, diffusion-Female),
`role='practice'`.

### C2. Implementation spec

1. **Input map** `event_sources.json` (new file, same shape as `my_sources.json`):
   ```json
   [
     {"source":"real","model":"ffhq","path":"<260K_PATH>/real"},
     {"source":"gan","model":"sg1","path":"<260K_PATH>/gan/sg1"},
     {"source":"gan","model":"sg2","path":"<260K_PATH>/gan/sg2"},
     {"source":"gan","model":"sg3","path":"<260K_PATH>/gan/sg3"},
     {"source":"diffusion","model":"sd15","path":"<260K_PATH>/diffusion/sd15"},
     {"source":"diffusion","model":"sd21","path":"<260K_PATH>/diffusion/sd21"},
     {"source":"diffusion","model":"sd3","path":"<260K_PATH>/diffusion/sd3"},
     {"source":"diffusion","model":"sdxl","path":"<260K_PATH>/diffusion/sdxl"},
     {"source":"diffusion","model":"flux","path":"<260K_PATH>/diffusion/flux"}
   ]
   ```
   Adjust paths to how the 260K set is actually organized — the script must fail
   loudly if a path is missing.
2. **Reuse the existing pipeline scripts** (they are re-runnable and tested):
   `sample_candidates.py` (extend for `model` + per-model counts + `--seed <SEED>`,
   `--exclude` against every previously-sampled id list) → `label_gender.py` →
   `process_images.py` (768px, 80–150 KB) → `qa_pool.py` (v3 rules — extend cells
   for per-model targets, backfill per model+gender if QA rejects drop a cell
   below target).
3. **IDs**: `{model}_{seq:05d}` (e.g. `sg2_00123`) — globally unique, never reusing
   old-pool ids.
4. **Outputs**:
   - `public_event\assets\faces\{model}_{seq}.jpg`
   - `public_event\assets\pool_manifest.csv` header:
     `image_id,file,source,model,psi,gender,gender_conf,role,phash`
     (`psi` only meaningful for SG models; `''` otherwise)
5. **DB ingest**: automatic on first session via `ensure_images_ingested()` —
   verify it inserts 8,100 rows + practice rows.

### C3. Acceptance for this phase
```powershell
# count files vs manifest rows vs DB rows — all three must agree
(Get-ChildItem public_event\assets\faces -File).Count          # 8,100 + practice
(Import-Csv public_event\assets\pool_manifest.csv).Count
```
Per model+gender cell counts match the table above (±QA backfill).

---

## 5. Phase D — Tunnels

### D1. Cloudflare named tunnel (primary)
```powershell
cloudflared tunnel login                     # browser auth
cloudflared tunnel create ai-detective       # writes <TUNNEL_ID>.json creds
cloudflared tunnel route dns ai-detective play.<DOMAIN>
```
`%USERPROFILE%\.cloudflared\config.yml`:
```yaml
tunnel: <TUNNEL_ID>
credentials-file: C:\Users\<you>\.cloudflared\<TUNNEL_ID>.json
ingress:
  - hostname: play.<DOMAIN>
    service: http://localhost:8090
  - service: http_status:404
```
Install as a service (auto-start on boot):
```powershell
cloudflared service install
Start-Service cloudflared
```
**Cache rules** (Cloudflare dashboard → your domain → Caching → Rules):
1. `URI Path starts with /assets/faces` → Cache Everything, Edge TTL 1 day
2. `URI Path starts with /api/` → Bypass cache

### D2. ngrok static domain (backup)
```powershell
ngrok config add-authtoken <NGROK_TOKEN>
```
Run (the launcher starts it; keep it idle from event start):
```powershell
ngrok http --domain=<STATIC>.ngrok-free.app 8090
```

### D3. QR codes
Generate two PNGs into `public_event\assets\` (any offline QR lib/site — the URL
is all that matters):
- `qr_primary.png` → `https://play.<DOMAIN>` (big print)
- `qr_backup.png`  → `https://<STATIC>.ngrok-free.app` (small print, "if it doesn't load")

---

## 6. Phase E — Launcher, prewarm, verification

### E1. New file `scripts\start_event.ps1`
Responsibilities (write it to do all of this):
1. `$env:PHP_CLI_SERVER_WORKERS = "32"` (before php starts — this is the
   single most important line: `php -S` is single-threaded by default)
2. `$env:ADMIN_PW = "<ADMIN_PW>"` and `$env:TEST_MODE = "0"` for event day
   (omit/comment during testing so replays are allowed)
3. Start: `& tools\php\php.exe -S 0.0.0.0:8090 -t public_event router.php`
   (check port 8090 is free first; use `0.0.0.0` so tunnels reach it)
4. Verify `cloudflared` service running; start ngrok (hidden window)
5. Health-check BOTH URLs until `{"ok":true}`:
   `https://play.<DOMAIN>/api/health.php` and `https://<STATIC>.ngrok-free.app/api/health.php`
6. Print both URLs + reminder to run `prewarm_cache.py`

### E2. New file `scripts\prewarm_cache.py`
Reads `pool_manifest.csv`, GETs every face through `https://play.<DOMAIN>`
(8–16 threads), prints total + how many returned `cf-cache-status: HIT|MISS`.
Run it ~30–60 min before doors open so the first ~1–1.5 GB of image bytes is
pushed to the edge BEFORE players arrive (not during the rush).

### E3. Verification (all must pass before the event)

| # | Check | Pass criterion |
|---|---|---|
| V1 | `curl https://play.<DOMAIN>/api/health.php` | `{"ok":true}` |
| V2 | Same via ngrok URL | `{"ok":true}` |
| V3 | Full playthrough on a real phone (Cloudflare URL) | signup → both investigations → case report → leaderboard → consent → survey → prize contact all work |
| V4 | Second fetch of a face URL | response header `cf-cache-status: HIT` |
| V5 | Load test: ~100 concurrent GETs of faces + health via the tunnel (any script; e.g. Python threads) | no 5xx, p95 < 1.5 s |
| V6 | Load test writes: 10 parallel playthroughs via API | 10 sessions, 380 trial rows, no `database is locked` errors |
| V7 | Admin `/admin.php` export | CSVs include `model`, `viewport_start`, `viewport_end` |
| V8 | One-play lock with `TEST_MODE=0`: replay same browser | rejected `already_played` (409) |
| V9 | Rotate phone during play, finish | `viewport_end` != `viewport_start` in DB |
| V10 | `data\event.sqlite` copied while server runs (WAL) | copy opens cleanly with `sqlite3` CLI |
| V11 | Greps from B5/B10 | zero MySQL-isms; faces not tracked by git |

After V6/V8 test plays: admin → "hide all existing sessions" (marks them
`is_test=1`) — test data stays in the file, excluded later by date.

---

## 7. Phase F — Event day runbook & afterwards

**Before doors (60 min)**
1. Boot PC → `start_event.ps1` → confirm V1/V2 green
2. Run `prewarm_cache.py` fully (V4 shows HITs)
3. Verify PC clock/timezone (Bangkok, synced) — analysis trusts timestamps
4. Both QRs visible at booth (primary big, backup small)
5. `TEST_MODE=0` (launcher does it) + test sessions hidden
6. USB stick ready; every ~30 min copy `data\event*.sqlite*` (include `-wal`/`-shm`)
   — safe to copy live under WAL (V10)

**During**: watch the LIVE board (`/live.html`) — a stall signals tunnel trouble;
flip attention to the backup QR if the primary is dead. Last resort: Azure QR.

**After the event**
1. Final SQLite copy to USB + a safe location
2. Admin export CSVs (consented players only, quitters included)
3. **Analysis filter**: everything from `created_at >= '<EVENT_DATE> 00:00:00'`
   (and `is_test = 0`); earlier rows are testing — kept, never deleted
4. Optional: stop tunnels, `Set-Service cloudflared -StartupType Manual` if the
   lab PC is repurposed

**Capacity notes for confidence**: ~15 KB of data per player; even 2,000 players
≈ 50 MB — SQLite storage is a non-issue. Real limits are PHP workers (32 set) and
the PC's upload link (mitigated by Cloudflare edge cache + prewarm).

---

## 8. Phase G — Commit the built system back to git (provenance)

```powershell
git add public_event config/schema.sqlite.sql config/config_event.php `
        scripts/build_event_pool.py scripts/prewarm_cache.py scripts/start_event.ps1 `
        scripts/event_sources.json .gitignore docs/EVENT_PARALLEL_WEB_PLAN.md
git status        # faces/*.jpg and event*.sqlite MUST NOT be listed (B10)
git commit -m "Event parallel web: public_event (SQLite) + dataset builder + tunnel scripts"
git tag event-<YEAR>
git push origin main --tags
```
Pushing `public_event/**` does **not** trigger the Azure CI (its trigger paths are
`public/**` and `scripts/make_deploy_zip.py` only — verified in
`.github/workflows/deploy.yml`).

---

## 9. Acceptance checklist (overall)

- [ ] Phase A: prerequisites installed, `php -m` shows pdo_sqlite
- [ ] Phase B: `public_event` runs locally (`http://127.0.0.1:8090`), zero MySQL-isms
- [ ] Phase C: 8,100 + practice faces; manifest/DB counts agree
- [ ] Phase D: both public URLs healthy; faces cache at edge; QRs generated
- [ ] Phase E: V1–V11 all green
- [ ] Phase F materials: both QRs printed, USB ready, runbook at booth
- [ ] Phase G: committed + tagged + pushed; original `public/` untouched
      (`git diff main -- public/` empty vs origin before your work)

**Rollback / fallback at any moment:** the Azure site
(`https://face-detective.azurewebsites.net`) is independent and always on —
point the booth QR at it if the lab PC fails entirely.
