"""Batch processing + QA for labeled candidates.

Every image gets IDENTICAL processing: center-square crop -> 640x640 LANCZOS
-> JPEG encoded to the 80-150KB band (quality search 85..55).

QA screens: PIL verify (corrupt filter) and perceptual-hash duplicate removal
(distance 0 on the processed image; first occurrence kept).

Input : data/candidates_labeled.csv (status=ok rows only)
Output: data/processed/*.jpg  +  data/processed_report.csv
"""
import argparse
import csv
import io
from multiprocessing import Pool
from pathlib import Path

import imagehash
from PIL import Image

SIZE = 768
MIN_KB, MAX_KB = 80, 150
QUALITIES = [60, 65, 70, 75, 80, 85, 90, 95]  # ascending: pick closest to band


def load_rows(inp):
    with open(inp, newline="", encoding="utf-8") as f:
        return [r for r in csv.DictReader(f) if r["status"] == "ok"]


def process_one(row):
    out_dir = Path("data/processed")
    out = out_dir / f"{row['cand_id']}.jpg"
    rec = {k: row[k] for k in ("cand_id", "source", "psi", "gender", "gender_conf")}
    try:
        img = Image.open(row["path"])
        img.verify()  # corrupt filter (first pass)
        img = Image.open(row["path"]).convert("RGB")
    except Exception:
        rec.update(out="", size_kb=0, phash="", status="corrupt")
        return rec
    w, h = img.size
    side = min(w, h)
    left, top = (w - side) // 2, (h - side) // 2
    img = img.crop((left, top, left + side, top + side)).resize((SIZE, SIZE), Image.LANCZOS)

    best_q, best_blob, best_dist = None, None, None
    for q in QUALITIES:
        buf = io.BytesIO()
        img.save(buf, "JPEG", quality=q, optimize=True)
        blob = buf.getvalue()
        kb = len(blob) / 1024
        if kb < MIN_KB:
            dist = MIN_KB - kb          # undersized
        elif kb > MAX_KB:
            dist = kb - MAX_KB          # oversized
        else:
            dist = 0                    # in band
        if best_dist is None or dist < best_dist:
            best_q, best_blob, best_dist = q, blob, dist
        if dist == 0:
            break
        if kb > MAX_KB:
            break                       # higher q only grows; stop ascending
    chosen, blob, kb = best_q, best_blob, round(len(best_blob) / 1024, 1)

    h64 = str(imagehash.phash(img))
    out_dir.mkdir(parents=True, exist_ok=True)
    with open(out, "wb") as f:
        f.write(blob)
    flag = "size_out_of_band" if not (MIN_KB <= kb <= MAX_KB) else ""
    rec.update(out=str(out), size_kb=kb, phash=h64,
               status=f"ok{';' + flag if flag else ''}")
    return rec


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="inp", default="data/candidates_labeled.csv")
    ap.add_argument("--report", default="data/processed_report.csv")
    ap.add_argument("--workers", type=int, default=6)
    args = ap.parse_args()

    rows = load_rows(args.inp)
    print(f"processing {len(rows)} kept candidates ...", flush=True)
    with Pool(args.workers) as pool:
        recs, done = [], 0
        for r in pool.imap_unordered(process_one, rows, chunksize=50):
            recs.append(r)
            done += 1
            if done % 1000 == 0:
                print(f"  {done}/{len(rows)}", flush=True)

    seen, dups, corrupt = {}, 0, 0
    for r in sorted(recs, key=lambda x: x["cand_id"]):
        if r["status"] == "corrupt":
            corrupt += 1
            continue
        if r["phash"] in seen:
            r["status"] = "dup"
            Path(r["out"]).unlink(missing_ok=True)
            dups += 1
        else:
            seen[r["phash"]] = r["cand_id"]

    fields = ["cand_id", "source", "psi", "gender", "gender_conf", "out", "size_kb", "phash", "status"]
    with open(args.report, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(recs)

    okd = [r for r in recs if r["status"].startswith("ok")]
    sizes = sorted(r["size_kb"] for r in okd)
    in_band = sum(1 for r in okd if MIN_KB <= r["size_kb"] <= MAX_KB)
    print("\nVERIFICATION:")
    print(f"  ok={len(okd)}  dup={dups}  corrupt={corrupt}")
    if sizes:
        print(f"  size KB: min={sizes[0]} median={sizes[len(sizes)//2]} max={sizes[-1]}"
              f"  in_band[{MIN_KB}-{MAX_KB}]={in_band}/{len(okd)}")
    print(f"-> {args.report}")


if __name__ == "__main__":
    main()
