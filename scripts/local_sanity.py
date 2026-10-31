"""Local sanity after pool v3 rebuild: bootstrap, plan, images serve, cleanup."""
import concurrent.futures
import json
import subprocess
import urllib.request

d = json.load(urllib.request.urlopen(urllib.request.Request(
    "http://127.0.0.1:8090/api/session.php",
    data=json.dumps({"lang": "en", "birth_year": 2000, "gender": "Male", "alias": "PoolV3OK",
                     "device": "sanity", "screen": "0x0", "repeat_tag": ""}).encode(),
    headers={"Content-Type": "application/json"})))
assert d["ok"], d
pl = d["plan"]
assert len(pl["round1"]) == 12 and len(pl["round2"]) == 24
files = [f for t in pl["round1"] for f in (t["ai"]["file"], t["real"]["file"])] + [t["file"] for t in pl["round2"]]


def check(f):
    return urllib.request.urlopen("http://127.0.0.1:8090/assets/" + f).status == 200


with concurrent.futures.ThreadPoolExecutor(8) as ex:
    assert all(list(ex.map(check, files)))
print(f"pool v3 sanity OK — {len(files)} session images serve")

q = subprocess.run(["tools/mariadb/bin/mysql.exe", "-P", "3307", "-u", "research_app",
                    "-presearch_dev_pw", "ai_detect_research", "-N", "-e",
                    "SELECT COUNT(*) FROM images;"], capture_output=True, text=True)
print("images ingested:", q.stdout.strip())

sid = d["session_id"]
sql = "DELETE FROM trials WHERE session_id='%s'; DELETE FROM sessions WHERE session_id='%s';" % (sid, sid)
q = subprocess.run(["tools/mariadb/bin/mysql.exe", "-P", "3307", "-u", "root",
                    "-prootlocal", "ai_detect_research", "-e", sql],
                   capture_output=True, text=True)
assert q.returncode == 0, q.stderr
print("cleaned")
