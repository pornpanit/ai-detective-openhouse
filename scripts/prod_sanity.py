"""Production sanity after pool v3 deploy: bootstrap, images serve, cleanup."""
import concurrent.futures
import json
import subprocess
import urllib.request

BASE = "https://face-detective.azurewebsites.net"

body = json.dumps({"lang": "th", "birth_year": 1998, "gender": "Female", "alias": "V3ProdOK",
                   "device": "prodv3", "screen": "390x844", "repeat_tag": "rt_v3"}).encode()
d = json.load(urllib.request.urlopen(urllib.request.Request(
    BASE + "/api/session.php", data=body, headers={"Content-Type": "application/json"})))
assert d["ok"], d
sid, pl = d["session_id"], d["plan"]
assert len(pl["round1"]) == 12 and len(pl["round2"]) == 24
files = [f for t in pl["round1"] for f in (t["ai"]["file"], t["real"]["file"])] + [t["file"] for t in pl["round2"]]


def check(f):
    return urllib.request.urlopen(BASE + "/assets/" + f).status == 200


with concurrent.futures.ThreadPoolExecutor(8) as ex:
    assert all(list(ex.map(check, files)))
print(f"PROD pool v3 OK — {len(files)} images serve")

lb = json.load(urllib.request.urlopen(BASE + "/api/leaderboard.php"))
assert lb["ok"] and "meta" in lb
c = json.load(open("data/pipeline/deploy_credentials.json"))
sql = "DELETE FROM trials WHERE session_id='%s'; DELETE FROM sessions WHERE session_id='%s';" % (sid, sid)
q = subprocess.run(["tools/mariadb/bin/mysql.exe", "--host=face-detective-mysql.mysql.database.azure.com",
                    "--user", "research_app", "--password=" + c["research_app_pw"],
                    "ai_detect_research", "-e", sql], capture_output=True, text=True)
assert q.returncode == 0, q.stderr
print("prod cleanup OK")
