"""Generate a lazy-loading contact sheet of the current pool for eyeball review."""
import csv
from pathlib import Path

rows = list(csv.DictReader(open("data/pipeline/pool_manifest.csv", encoding="utf-8")))
cells = {}
for r in rows:
    cells.setdefault(r["role"], {}).setdefault(f"{r['source']}-{r['gender']}", []).append(r)

parts = [
    "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Pool contact sheet</title>",
    "<style>body{font-family:sans-serif;background:#222;color:#eee}h2{margin:24px 8px 4px}",
    "div.g{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:6px;padding:0 8px}",
    "figure{margin:0}img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px}",
    "figcaption{font-size:9px;color:#999;word-break:break-all}</style></head><body>",
]
for role in ("pool", "practice"):
    for cell in sorted(cells.get(role, {})):
        parts.append(f"<h2>{role} · {cell} · {len(cells[role][cell])} images</h2><div class='g'>")
        for r in cells[role][cell]:
            parts.append(f"<figure><img loading='lazy' src='../../public/assets/{r['file']}'>")
            parts.append(f"<figcaption>{r['image_id']}</figcaption></figure>")
        parts.append("</div>")
parts.append("</body></html>")

out = Path("data/export/contact_sheet.html")
out.write_text("".join(parts), encoding="utf-8")
print(f"contact sheet: {out} — {len(rows)} images")
