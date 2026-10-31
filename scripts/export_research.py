"""Research export: consented sessions only (completed OR quitter).

Default rule per data-capture spec: consent = 1. Completion status is exported
as a covariate/filter column, never a default filter. Non-consented and
unanswered sessions are excluded.

Usage: python scripts/export_research.py [out.csv]
Writes: sessions + trials join; one row per trial; plus session-level summary
rows for d-prime inputs (hits/misses/FA/CR per participant).
"""
import csv
import subprocess
import sys
from pathlib import Path

MYSQL = Path("tools/mariadb/bin/mysql.exe")
CONN = ["-P", "3307", "-u", "research_app", "-presearch_dev_pw", "ai_detect_research",
        "--default-character-set=utf8mb4"]

SESSIONS_SQL = """
SELECT s.session_id, s.lang, s.birth_year, s.cohort, s.gender, s.device, s.screen,
       s.alias, s.familiarity_1, s.familiarity_2, s.familiarity_3, s.familiarity_4,
       s.familiarity_5, s.completed, s.created_at, s.finished_at
FROM sessions s
WHERE s.consent = 1 AND s.is_test = 0
ORDER BY s.created_at"""

TRIALS_SQL = """
SELECT t.session_id, t.round, t.trial_index, t.image_id, t.pair_image_id,
       t.image_type, t.response, t.correct, t.rt_ms, t.created_at
FROM trials t
JOIN sessions s ON s.session_id = t.session_id
WHERE s.consent = 1 AND s.is_test = 0 AND t.round IN ('1','2')
ORDER BY t.session_id, t.round, t.trial_index"""

DPRIME_SQL = """
SELECT t.session_id, t.round, t.image_type,
       SUM(t.correct) AS correct_n, COUNT(*) AS n
FROM trials t
JOIN sessions s ON s.session_id = t.session_id
WHERE s.consent = 1 AND s.is_test = 0 AND t.round IN ('1','2')
GROUP BY t.session_id, t.round, t.image_type
ORDER BY t.session_id, t.round, t.image_type"""


def q(sql: str):
    r = subprocess.run([str(MYSQL), *CONN, "-e", sql],
                       capture_output=True, text=True, encoding="utf-8")
    if r.returncode != 0:
        sys.exit(f"mysql failed: {r.stderr}")
    lines = [ln for ln in r.stdout.splitlines() if ln]
    if not lines:
        return []
    header = lines[0].split("\t")
    return [dict(zip(header, ln.split("\t"))) for ln in lines[1:]]


def dump(rows, path):
    if not rows:
        Path(path).write_text("", encoding="utf-8")
        return
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
        w.writeheader()
        w.writerows(rows)


def main():
    out_dir = Path(sys.argv[1] if len(sys.argv) > 1 else "data/export")
    out_dir.mkdir(parents=True, exist_ok=True)
    sessions, trials, dprime = q(SESSIONS_SQL), q(TRIALS_SQL), q(DPRIME_SQL)
    dump(sessions, out_dir / "sessions.csv")
    dump(trials, out_dir / "trials.csv")
    dump(dprime, out_dir / "dprime_inputs.csv")
    consented = len(sessions)
    print(f"exported: {consented} consented sessions, {len(trials)} scored trials, "
          f"{len(dprime)} d-prime rows -> {out_dir}/")


if __name__ == "__main__":
    main()
