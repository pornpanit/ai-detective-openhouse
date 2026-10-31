"""Six-cell balanced pool selector + manifest generator.

Cells = source (real / sd15 / gan) x gender (M / F). N per cell = min cell
availability (rounded down to a multiple of 2 so GAN cells split evenly across
psi=0.5 / psi=1). Also reserves a separate practice set (2 per cell).

Copies selected files to public/assets/faces/ and writes:
  data/pool_manifest.csv          (research record)
  public/assets/pool_manifest.csv (deployed manifest consumed by the API)

Columns: image_id, file, source, psi, gender, gender_conf, role, phash
"""
import argparse
import csv
import random
import shutil
from collections import defaultdict
from pathlib import Path

SEED = 20261029
PRACTICE_PER_CELL = 2


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--report", default="data/processed_report.csv")
    ap.add_argument("--assets", default="public/assets/faces")
    ap.add_argument("--out-research", default="data/pool_manifest.csv")
    ap.add_argument("--out-deploy", default="public/assets/pool_manifest.csv")
    args = ap.parse_args()

    with open(args.report, newline="", encoding="utf-8") as f:
        rows = [r for r in csv.DictReader(f) if r["status"].startswith("ok")]

    by_cell = defaultdict(list)
    for r in rows:
        by_cell[(r["source"], r["gender"])].append(r)

    counts = {k: len(v) for k, v in sorted(by_cell.items())}
    print("cell availability:", {f"{s}-{g}": c for (s, g), c in counts.items()})

    def usable(source, gender):
        avail = counts[(source, gender)] - PRACTICE_PER_CELL
        if source == "gan":
            per_psi = defaultdict(int)
            for r in by_cell[(source, gender)]:
                per_psi[r["psi"]] += 1
            avail = min(avail, 2 * (min(per_psi.values()) - PRACTICE_PER_CELL // 2))
        return avail

    n = min(usable(s, g) for (s, g) in counts)
    n -= n % 2
    print(f"selected N per cell = {n} (total pool {n * 6})")

    rng = random.Random(SEED)
    out_rows = []
    for (source, gender), items in sorted(by_cell.items()):
        rng.shuffle(items)
        if source == "gan":
            by_psi = defaultdict(list)
            for r in items:
                by_psi[r["psi"]].append(r)
            practice = [by_psi["0.5"].pop(0), by_psi["1"].pop(0)]
            half = n // 2
            pool = by_psi["0.5"][:half] + by_psi["1"][:half]
        else:
            practice, pool = items[:PRACTICE_PER_CELL], items[PRACTICE_PER_CELL:PRACTICE_PER_CELL + n]
        for role, group in (("practice", practice), ("pool", pool)):
            for r in group:
                out_rows.append({
                    "image_id": r["cand_id"], "file": f"faces/{r['cand_id']}.jpg",
                    "source": r["source"], "psi": r["psi"] or "", "gender": r["gender"],
                    "gender_conf": r["gender_conf"], "role": role, "phash": r["phash"],
                })

    assets = Path(args.assets)
    assets.mkdir(parents=True, exist_ok=True)
    for r in out_rows:
        shutil.copy2(Path("data/processed") / f"{r['image_id']}.jpg", assets / f"{r['image_id']}.jpg")

    fields = list(out_rows[0].keys())
    for out in (args.out_research, args.out_deploy):
        with open(out, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=fields)
            w.writeheader()
            w.writerows(out_rows)

    pool = [r for r in out_rows if r["role"] == "pool"]
    prac = [r for r in out_rows if r["role"] == "practice"]
    print("\nVERIFICATION:")
    tally = defaultdict(int)
    gan_psi = defaultdict(int)
    for r in pool:
        tally[(r["source"], r["gender"])] += 1
        if r["source"] == "gan":
            gan_psi[(r["gender"], r["psi"])] += 1
    for k in sorted(tally):
        print(f"  pool {k[0]:5s} {k[1]:7s} -> {tally[k]}")
    for k in sorted(gan_psi):
        print(f"  gan  {k[0]:7s} psi={k[1]:4s} -> {gan_psi[k]}")
    total_mb = sum((assets / f"{r['image_id']}.jpg").stat().st_size
                   for r in out_rows) / 1024 / 1024
    print(f"  practice reserved: {len(prac)} ({PRACTICE_PER_CELL}/cell)")
    print(f"  files copied: {len(out_rows)}, total {total_mb:.0f} MB (budget ~600MB)")
    print(f"-> {args.out_research} + {args.out_deploy}")


if __name__ == "__main__":
    main()
