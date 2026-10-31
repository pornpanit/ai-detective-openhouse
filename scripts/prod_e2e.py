"""Production end-to-end verification: bootstrap, plan, images, trial, resume state, cleanup."""
import json
import subprocess
import urllib.request
from collections import Counter
from concurrent.futures import ThreadPoolExecutor

BASE = "https://face-detective.azurewebsites.net"

body = json.dumps({"lang": "th", "birth_year": 1995, "gender": "Female",
                   "device": "ProdE2E6", "screen": "390x844", "repeat_tag": ""}).encode()
d = json.load(urllib.request.urlopen(urllib.request.Request(
    f"{BASE}/api/session.php", data=body, headers={"Content-Type": "application/json"})))
assert d["ok"], d
sid, pl = d["session_id"], d["plan"]
r2 = Counter(t["type"] for t in pl["round2"])
assert len(pl["round1"]) == 12 and len(pl["round2"]) == 24
assert r2["real"] == 12 and r2["gan"] == 6 and r2["diffusion"] == 6, r2
files = [f for t in pl["round1"] for f in (t["ai"]["file"], t["real"]["file"])] + [t["file"] for t in pl["round2"]]

def check(f):
    return urllib.request.urlopen(f"{BASE}/assets/" + f).status == 200

with ThreadPoolExecutor(8) as ex:
    assert all(list(ex.map(check, files)))
print(f"prod bootstrap OK: 12+24 plan, {len(files)} images all serve")

b = json.dumps({"session_id": sid, "round": "1", "trial_index": 1,
                "image_id": pl["round1"][0]["ai"]["id"], "rt_ms": 1200}).encode()
assert json.load(urllib.request.urlopen(urllib.request.Request(
    f"{BASE}/api/trial.php", data=b, headers={"Content-Type": "application/json"})))["ok"]
st = json.load(urllib.request.urlopen(f"{BASE}/api/state.php?session_id={sid}"))
assert st["saved"]["r1"] == [1] and not st["completed"] and st["plan"]["round2"][0]["id"]
print("prod resume state OK: saved r1 [1], plan returned")

c = json.load(open("data/pipeline/deploy_credentials.json"))
sql = f"DELETE FROM trials WHERE session_id='{sid}'; DELETE FROM sessions WHERE session_id='{sid}'; SELECT COUNT(*) AS sessions FROM sessions;"
q = subprocess.run(["tools/mariadb/bin/mysql.exe", "--host=face-detective-mysql.mysql.database.azure.com",
                    "--user=research_app", "--password=" + c["research_app_pw"],
                    "ai_detect_research", "-e", sql], capture_output=True, text=True)
assert q.returncode == 0, q.stderr
print("prod cleanup OK:", q.stdout.strip())
