"""Stimulus QA v3 + full pool rebuild.

Framing QA on every processed image (the 768x768 file players see):
  - exactly 1 face (YuNet, score >= 0.60)
  - face box 25%..90% of frame, face center within 15% of frame center
  - NOT monochrome/grayscale (mean HSV saturation >= 25)
  - face not strongly turned: eye-landmark yaw asymmetry ratio <= 0.65
    (~ +/-20 deg; slight turn OK) and eye-line roll <= 15 deg

Gender confidence: the labeling pass already cut at 0.70 — no second filter.

Pool rebuild from QA-passing images with PER-SOURCE cell targets:
  --cells "real=1000,gan=600,sd15=636"   (per gender cell; falls back to supply)
GAN cells 50/50 psi mix, 2 practice per cell, manifest + assets synced.
Outputs: data/logs/qa_v3_report.csv, data/pipeline/qa_v3_summary.txt,
         data/pipeline/pool_manifest.csv + public/assets/pool_manifest.csv
"""
import csv
import random
import shutil
import sys
from collections import defaultdict
from multiprocessing import Pool
from pathlib import Path

import cv2
import numpy as np

MODELS = Path("data/models")
YUNET = str(MODELS / "face_detection_yunet.onnx")
FACE_THR = 0.60
MIN_AREA, MAX_AREA = 0.25, 0.90
MAX_OFFSET = 0.15
MIN_SATURATION = 25.0          # mean HSV saturation — below = monochrome/washed out
MAX_TURN_RATIO = 0.65          # eye-nose asymmetry (~ +/-20 deg yaw; slight turn OK)
MAX_ROLL_DEG = 15.0
PRACTICE_PER_CELL = 2
SEED = 20261031

_detector = None


def _init():
    global _detector
    _detector = cv2.FaceDetectorYN.create(YUNET, "", (320, 320), score_threshold=FACE_THR)


def framing_ok(path: str):
    """Return (ok, reason) for a processed image under QA v3 rules."""
    global _detector
    if _detector is None:
        _detector = cv2.FaceDetectorYN.create(YUNET, "", (320, 320), score_threshold=FACE_THR)
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        return False, "unreadable"
    # monochrome / grayscale filter
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    if float(hsv[:, :, 1].mean()) < MIN_SATURATION:
        return False, "monochrome"
    h, w = img.shape[:2]
    scale = 640.0 / max(h, w)
    small = cv2.resize(img, (int(w * scale), int(h * scale))) if scale < 1.0 else img
    _detector.setInputSize((small.shape[1], small.shape[0]))
    _, faces = _detector.detect(small)
    if faces is None or len(faces) == 0:
        return False, "no_face"
    if len(faces) > 1:
        return False, "multi_face"
    f = faces[0]
    x, y, fw, fh = f[0], f[1], f[2], f[3]
    H, W = small.shape[:2]
    area = (fw * fh) / (W * H)
    offx = abs((x + fw / 2) - W / 2) / W
    offy = abs((y + fh / 2) - H / 2) / H
    if area < MIN_AREA:
        return False, f"face_too_small({area:.2f})"
    if area > MAX_AREA:
        return False, f"face_too_large({area:.2f})"
    if offx > MAX_OFFSET or offy > MAX_OFFSET:
        return False, f"off_center({offx:.2f},{offy:.2f})"
    # pose from the 5 landmarks: right eye, left eye, nose tip, mouth R, mouth L
    if len(f) >= 15:
        rx, ry = f[4], f[5]      # right eye
        lx, ly = f[6], f[7]      # left eye
        nx, ny = f[8], f[9]      # nose tip
        dR = abs(nx - rx) + 0.3 * abs(ny - ry)
        dL = abs(lx - nx) + 0.3 * abs(ly - ny)
        if dR + dL > 1:
            ratio = abs(dL - dR) / (dL + dR)
            if ratio > MAX_TURN_RATIO:
                return False, f"turned({ratio:.2f})"
        roll = abs(float(np.degrees(np.arctan2(ly - ry, (lx - rx) if lx != rx else 1e-6))))
        if MAX_ROLL_DEG < roll < 180 - MAX_ROLL_DEG:
            return False, f"rolled({roll:.0f}deg)"
    return True, "ok"


def _check(row):
    ok, reason = framing_ok(str(Path("data/processed") / f"{row['cand_id']}.jpg"))
    return row, ok, reason


def main():
    import argparse
    ap = argparse.ArgumentParser()
    ap.add_argument("--n-per-cell", type=int, default=0,
                    help="force equal pool size per cell (legacy single value)")
    ap.add_argument("--cells", default="",
                    help='per-source targets per gender cell, e.g. "real=1000,gan=600,sd15=636"')
    args = ap.parse_args()
    targets = {}
    for part in filter(None, args.cells.split(",")):
        k, v = part.split("=")
        targets[k.strip()] = int(v)

    reports = sorted(set(Path("data/pipeline").glob("processed_report*.csv"))
                     | set(Path("data").glob("processed_report*.csv")))
    candidates = []
    for rep in reports:
        with open(rep, newline="", encoding="utf-8") as f:
            candidates.extend(r for r in csv.DictReader(f) if r["status"].startswith("ok"))
    seen = set()
    candidates = [r for r in candidates
                  if r["cand_id"] not in seen and not seen.add(r["cand_id"])]
    if len(candidates) < 1000:
        raise SystemExit(f"ABORT: only {len(candidates)} candidate images found — refusing to rebuild an empty pool")
    print(f"framing-QA v3 on {len(candidates)} processed images "
          f"(from {len(reports)} reports) ...", flush=True)
    with Pool(6, initializer=_init) as pool:
        results = pool.map(_check, candidates, chunksize=50)

    report, passed = [], defaultdict(list)
    for row, ok, reason in results:
        cell = f"{row['source']}-{row['gender']}"
        if ok:
            passed[(row["source"], row["gender"])].append(row)
            report.append({"image_id": row["cand_id"], "cell": cell, "verdict": "pass", "reason": "ok"})
        else:
            report.append({"image_id": row["cand_id"], "cell": cell, "verdict": "fail", "reason": reason})

    # capacity per (source, gender) cell
    caps = {}
    for (src, g), rows in sorted(passed.items()):
        avail = len(rows) - PRACTICE_PER_CELL
        if src == "gan":
            per_psi = defaultdict(int)
            for r in rows:
                per_psi[r["psi"]] += 1
            avail = min(avail, 2 * (min(per_psi.values()) - PRACTICE_PER_CELL // 2))
        caps[(src, g)] = max(avail, 0)
    print("QA-pass capacity per cell:", {f"{k[0]}-{k[1]}": v for k, v in caps.items()})

    # per-source target (fallback: min capacity across all cells, legacy mode)
    def n_for(src):
        if args.n_per_cell > 0:
            return args.n_per_cell
        if src in targets:
            return targets[src]
        return min(caps.values())

    chosen = {}
    for (src, g), cap in caps.items():
        n = min(n_for(src), cap)
        n -= n % 2
        chosen[(src, g)] = n
    total_pool = sum(chosen.values())
    print("pool per cell:", {f"{k[0]}-{k[1]}": v for k, v in chosen.items()},
          f"-> total {total_pool}")
    if total_pool < 2000:
        print(f"ABORT: pool {total_pool} below spec floor 2000", file=sys.stderr)
        return 1

    rng = random.Random(SEED)
    final_rows = []
    for (src, g), rows in sorted(passed.items()):
        n = chosen[(src, g)]
        rng.shuffle(rows)
        if src == "gan":
            by_psi = defaultdict(list)
            for r in rows:
                by_psi[r["psi"]].append(r)
            practice = [by_psi["0.5"].pop(0), by_psi["1"].pop(0)]
            half = n // 2
            pool_rows = by_psi["0.5"][:half] + by_psi["1"][:half]
        else:
            practice = rows[:PRACTICE_PER_CELL]
            pool_rows = rows[PRACTICE_PER_CELL:PRACTICE_PER_CELL + n]
        for role, group in (("practice", practice), ("pool", pool_rows)):
            for r in group:
                final_rows.append({"image_id": r["cand_id"], "file": f"faces/{r['cand_id']}.jpg",
                                   "source": r["source"], "psi": r["psi"], "gender": r["gender"],
                                   "gender_conf": r["gender_conf"], "role": role, "phash": r["phash"]})

    fields = ["image_id", "file", "source", "psi", "gender", "gender_conf", "role", "phash"]
    for out in ("data/pipeline/pool_manifest.csv", "public/assets/pool_manifest.csv"):
        Path(out).parent.mkdir(parents=True, exist_ok=True)
        with open(out, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=fields)
            w.writeheader()
            w.writerows(final_rows)

    # sync assets: copy wanted, remove anything else
    assets = Path("public/assets/faces")
    assets.mkdir(parents=True, exist_ok=True)
    wanted = set()
    for r in final_rows:
        dst = assets / f"{r['image_id']}.jpg"
        if not dst.exists():
            shutil.copy2(Path("data/processed") / f"{r['image_id']}.jpg", dst)
        wanted.add(dst.name)
    removed = 0
    for f in assets.glob("*.jpg"):
        if f.name not in wanted:
            f.unlink()
            removed += 1

    Path("data/logs").mkdir(parents=True, exist_ok=True)
    with open("data/logs/qa_v3_report.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["image_id", "cell", "verdict", "reason"])
        w.writeheader()
        w.writerows(report)
    tally, psi, fails = defaultdict(int), defaultdict(int), defaultdict(int)
    for r in report:
        if r["verdict"] == "fail":
            fails[r["reason"].split("(")[0]] += 1
    for r in final_rows:
        if r["role"] == "pool":
            tally[(r["source"], r["gender"])] += 1
            if r["source"] == "gan":
                psi[(r["gender"], r["psi"])] += 1
    lines = ["QA v3 summary (full rebuild, per-source targets)", "----------------------------------------------",
             f"processed={len(candidates)} framing_pass={sum(len(v) for v in passed.values())} "
             f"fail={len(candidates) - sum(len(v) for v in passed.values())}",
             f"fail reasons: {dict(sorted(fails.items(), key=lambda kv: -kv[1]))}",
             f"pool total {total_pool} + practice {len(final_rows) - total_pool}"]
    for k in sorted(tally):
        lines.append(f"  pool {k[0]:5s} {k[1]:7s} -> {tally[k]}")
    for k in sorted(psi):
        lines.append(f"  gan  {k[0]:7s} psi={k[1]:4s} -> {psi[k]}")
    lines.append(f"assets_removed={removed} files_in_assets={len(wanted)}")
    Path("data/pipeline/qa_v3_summary.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\n".join(lines))
    return 0


if __name__ == "__main__":
    sys.exit(main())
