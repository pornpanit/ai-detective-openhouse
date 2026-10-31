"""Master pipeline runner: processes ALL remaining dataset images in chunks.

Chunks: 9 x FFHQ 7,500 + 5 x GAN 7,500 (psi 3750+3750) = 105,000 images.
Resumable: a chunk is skipped if its processed_report already exists.
Progress log: data/logs/pipeline_master.log
"""
import csv
import subprocess
import sys
from pathlib import Path

CHUNKS = [("real", 7500)] * 9 + [("gan", 7500)] * 5
LOG = Path("data/logs/pipeline_master.log")


def log(msg):
    line = f"[{__import__('datetime').datetime.now():%H:%M:%S}] {msg}"
    print(line, flush=True)
    with open(LOG, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def run(cmd):
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        log(f"FAILED: {' '.join(cmd)}\n{r.stdout[-800:]}\n{r.stderr[-800:]}")
        sys.exit(1)
    return r.stdout


def ids_in(path):
    with open(path, newline="", encoding="utf-8") as f:
        return {r["cand_id"] for r in csv.DictReader(f)}


def main():
    for i, (src, n) in enumerate(CHUNKS, 1):
        tag = f"c{i:02d}_{src}"
        report = Path(f"data/pipeline/processed_report_{tag}.csv")
        if report.exists():
            log(f"chunk {i}/14 ({src}) — report exists, skipping")
            continue

        # union of all ever-sampled ids = exclude set
        all_ids = set()
        for c in Path("data/pipeline").glob("candidates*.csv"):
            all_ids |= ids_in(c)
        excl = Path("data/pipeline/sampled_all.csv")
        with open(excl, "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(["cand_id"])
            for cid in all_ids:
                w.writerow([cid])

        cand = f"data/pipeline/candidates_{tag}.csv"
        lab = f"data/pipeline/candidates_{tag}_labeled.csv"
        log(f"chunk {i}/14 ({src}, n={n}) — sampling")
        run([sys.executable, "scripts/sample_candidates.py", "--out", cand,
             "--counts", f"{src}={n}", "--exclude", str(excl)])
        log(f"chunk {i}/14 — labeling")
        run([sys.executable, "scripts/label_gender.py", "--in", cand, "--out", lab, "--workers", "6"])
        log(f"chunk {i}/14 — processing")
        run([sys.executable, "scripts/process_images.py", "--in", lab, "--report", str(report), "--workers", "6"])
        kept = sum(1 for _ in open(report, encoding="utf-8")) - 1
        log(f"chunk {i}/14 DONE — {kept} images processed & kept")
    log("ALL CHUNKS COMPLETE")


if __name__ == "__main__":
    main()
