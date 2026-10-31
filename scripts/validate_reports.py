"""Validate all processed_report*.csv files + count images on disk."""
import csv
import glob
from pathlib import Path

total = 0
for rep in sorted(glob.glob("data/pipeline/processed_report*.csv")):
    with open(rep, encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    ok = sum(1 for r in rows if r["status"].startswith("ok"))
    total += ok
    print(f"{Path(rep).name}: {len(rows)} rows, {ok} kept")
print("TOTAL kept rows in reports:", total)
print("files on disk:", len(list(Path("data/processed").glob("*.jpg"))))
