"""Automated gender labeling of sampled candidates.

Detector: opencv_zoo YuNet (ONNX)  |  Classifier: Levi-Hassner gender Caffe net.

Keeps a candidate when: face detected (score >= FACE_THR) and gender
confidence >= CONF_THR. Everything else is dropped with a reason.

Input : data/candidates.csv
Output: data/candidates_labeled.csv (+ gender, gender_conf, face_score, status)
"""
import argparse
import csv
import sys
from multiprocessing import Pool
from pathlib import Path

import cv2
import numpy as np

MODELS = Path("data/models")
YUNET = str(MODELS / "face_detection_yunet.onnx")
GENDER_PROTO = str(MODELS / "gender_deploy.prototxt")
GENDER_MODEL = str(MODELS / "gender_net.caffemodel")
GENDER_LABELS = ["Male", "Female"]
MEAN = (78.4263377603, 87.7689143744, 114.8958477465)  # BGR means for this net
FACE_THR = 0.60
CONF_THR = 0.70

_detector = None
_gender_net = None


def _init_nets():
    global _detector, _gender_net
    _detector = cv2.FaceDetectorYN.create(YUNET, "", (320, 320), score_threshold=FACE_THR)
    _gender_net = cv2.dnn.readNetFromCaffe(GENDER_PROTO, GENDER_MODEL)


def label_one(row):
    path = row["path"]
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        row.update(gender="", gender_conf=0.0, face_score=0.0, status="unreadable")
        return row
    h, w = img.shape[:2]
    scale = 640.0 / max(h, w)
    if scale < 1.0:
        small = cv2.resize(img, (int(w * scale), int(h * scale)))
    else:
        small = img
    _detector.setInputSize((small.shape[1], small.shape[0]))
    _, faces = _detector.detect(small)
    if faces is None or len(faces) == 0:
        row.update(gender="", gender_conf=0.0, face_score=0.0, status="no_face")
        return row
    best = max(faces, key=lambda f: f[-1])
    x, y, fw, fh, score = best[0], best[1], best[2], best[3], best[-1]
    inv = 1.0 / scale
    x, y, fw, fh = int(x * inv), int(y * inv), int(fw * inv), int(fh * inv)
    # expand 15% margin, clamp
    mx, my = int(0.075 * fw), int(0.075 * fh)
    x0, y0 = max(0, x - mx), max(0, y - my)
    x1, y1 = min(w, x + fw + mx), min(h, y + fh + my)
    if x1 - x0 < 20 or y1 - y0 < 20:
        row.update(gender="", gender_conf=0.0, face_score=float(score), status="tiny_face")
        return row
    face = img[y0:y1, x0:x1]
    blob = cv2.dnn.blobFromImage(face, 1.0, (227, 227), MEAN, swapRB=False, crop=False)
    _gender_net.setInput(blob)
    logits = _gender_net.forward()[0]
    probs = np.exp(logits - logits.max())
    probs = probs / probs.sum()
    idx = int(probs.argmax())
    conf = float(probs[idx])
    if conf < CONF_THR:
        row.update(gender="", gender_conf=round(conf, 4), face_score=round(float(score), 4),
                   status="low_conf")
        return row
    row.update(gender=GENDER_LABELS[idx], gender_conf=round(conf, 4),
               face_score=round(float(score), 4), status="ok")
    return row


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="inp", default="data/candidates.csv")
    ap.add_argument("--out", default="data/candidates_labeled.csv")
    ap.add_argument("--workers", type=int, default=8)
    args = ap.parse_args()

    with open(args.inp, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    print(f"labeling {len(rows)} candidates ...", flush=True)

    with Pool(args.workers, initializer=_init_nets) as pool:
        out_rows, done = [], 0
        for r in pool.imap_unordered(label_one, rows, chunksize=50):
            out_rows.append(r)
            done += 1
            if done % 1000 == 0:
                print(f"  {done}/{len(rows)}", flush=True)

    out_rows.sort(key=lambda r: (r["source"], r["psi"], r["cand_id"]))
    fields = list(rows[0].keys()) + ["gender", "gender_conf", "face_score", "status"]
    with open(args.out, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(out_rows)

    print("\nVERIFICATION per source/psi/gender (status=ok only):")
    kept = [r for r in out_rows if r["status"] == "ok"]
    tally = {}
    for r in kept:
        key = (r["source"], r["psi"] or "-", r["gender"])
        tally[key] = tally.get(key, 0) + 1
    for k in sorted(tally):
        print(f"  {k[0]:6s} psi={k[1]:4s} {k[2]:7s} -> {tally[k]}")
    drops = {}
    for r in out_rows:
        if r["status"] != "ok":
            drops[r["status"]] = drops.get(r["status"], 0) + 1
    print(f"kept {len(kept)} / {len(out_rows)}; drops: {drops}")
    print(f"-> {args.out}")


if __name__ == "__main__":
    sys.exit(main())
