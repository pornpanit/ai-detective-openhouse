"""Build deploy.zip with forward-slash entry names (PowerShell Compress-Archive
writes backslashes, which Kudu's Linux rsync cannot handle)."""
import sys
import zipfile
from pathlib import Path

src = Path(sys.argv[1] if len(sys.argv) > 1 else "deploy_stage")
out = Path(sys.argv[2] if len(sys.argv) > 2 else "deploy.zip")
if out.exists():
    out.unlink()
count = 0
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as z:
    for p in sorted(src.rglob("*")):
        if p.is_file():
            z.write(p, p.relative_to(src).as_posix())
            count += 1
mb = out.stat().st_size / 1024 / 1024
print(f"zip: {count} files, {mb:.1f} MB -> {out}")
with zipfile.ZipFile(out) as z:
    names = z.namelist()
    assert not any("\\" in n for n in names), "backslash in entry names!"
    assert "css/game.css" in names and "api/health.php" in names
print("entry-name check OK (forward slashes, key files present)")
