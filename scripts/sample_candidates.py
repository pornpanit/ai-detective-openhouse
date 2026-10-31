"""Candidate sampler: random-sample images per source from the PreSocial dataset.

Output: CSV manifest (cand_id, source, psi, path). Paths only; no files copied.

Defaults sample the original design (2500/source). Options allow future
datasets and top-ups:
  --counts "real=0,gan=0,sd15=7500"   per-source sample size (0 = skip)
  --exclude data/candidates.csv       never re-use ids already in this manifest
  --sources sources.json              custom source map [{"source","psi","path"},...]
"""
import argparse
import csv
import json
import random
from pathlib import Path

DATASET_ROOT = Path(r"D:\PROJECT\seniorProject\dataset")
SEED = 20261029
EXTS = {".png", ".jpg", ".jpeg", ".webp", ".bmp"}
PER_SOURCE = 2500

SOURCES = {
    "real": DATASET_ROOT / "PreSocial_Real_FFHQ" / "PreSocial" / "Real" / "FFHQ",
    "sd15": DATASET_ROOT / "PreSocial_Fake_StableDiffusion1.5" / "PreSocial" / "Fake"
            / "StableDiffusion1.5" / "faces",
    "gan": DATASET_ROOT / "PreSocial_Fake_StyleGAN2" / "PreSocial" / "Fake" / "StyleGAN2",
}


def list_images(folder: Path):
    return sorted(p for p in folder.rglob("*") if p.suffix.lower() in EXTS and p.is_file())


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="data/candidates.csv")
    ap.add_argument("--per-source", type=int, default=PER_SOURCE)
    ap.add_argument("--counts", default="", help='per-source sizes, e.g. "real=0,gan=0,sd15=7500"')
    ap.add_argument("--exclude", default="", help="CSV manifest whose cand_ids must not repeat")
    ap.add_argument("--sources", default="", help="JSON list of {source,psi,path} entries")
    args = ap.parse_args()

    counts = {}
    for part in filter(None, args.counts.split(",")):
        k, v = part.split("=")
        counts[k] = int(v)

    exclude = set()
    if args.exclude and Path(args.exclude).exists():
        with open(args.exclude, newline="", encoding="utf-8") as f:
            exclude = {r["cand_id"] for r in csv.DictReader(f)}

    sources = []
    if args.sources:
        for entry in json.loads(Path(args.sources).read_text(encoding="utf-8")):
            sources.append((entry["source"], entry.get("psi", ""), Path(entry["path"])))
    else:
        sources.append(("real", "", SOURCES["real"]))
        sources.append(("sd15", "", SOURCES["sd15"]))
        gan_root = SOURCES["gan"]
        for cfg in sorted(p for p in gan_root.iterdir() if p.is_dir() and p.name.startswith("conf-")):
            psi = "0.5" if "psi-0.5" in cfg.name else ("1" if "psi-1" in cfg.name else cfg.name)
            sources.append(("gan", psi, cfg))

    rng = random.Random(SEED)
    rows = []
    for source, psi, root in sources:
        want = counts.get(source if not psi else f"{source}_psi{psi}",
                          counts.get(source, args.per_source if not psi else args.per_source // 2))
        if want <= 0:
            print(f"{source} psi={psi or '-'}: skipped")
            continue
        imgs = list_images(root)
        pool = [p for p in imgs if f"{source}_{psi}_{p.stem}".replace("__", "_") not in exclude] \
            if psi else [p for p in imgs if f"{source}_{p.stem}" not in exclude]
        picked = rng.sample(pool, min(want, len(pool)))
        for p in picked:
            cid = f"{source}_{psi}_{p.stem}" if psi else f"{source}_{p.stem}"
            rows.append({"cand_id": cid, "source": source, "psi": psi, "path": str(p)})
        print(f"{source} psi={psi or '-'}: sampled {len(picked)} of {len(pool)} available")

    out = Path(args.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["cand_id", "source", "psi", "path"])
        w.writeheader()
        w.writerows(rows)

    from collections import Counter
    tally = Counter((r["source"], r["psi"] or "-") for r in rows)
    print("\nVERIFICATION counts:")
    for k in sorted(tally):
        print(f"  {k[0]:6s} psi={k[1]:4s} -> {tally[k]}")
    print(f"TOTAL: {len(rows)} -> {out}")


if __name__ == "__main__":
    main()
