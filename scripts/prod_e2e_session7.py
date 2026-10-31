"""Production E2E for session 7 features + cleanup."""
import json
import subprocess
import urllib.error
import urllib.request

BASE = "https://face-detective.azurewebsites.net"


def post(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        return json.load(urllib.request.urlopen(req))
    except urllib.error.HTTPError as e:
        return json.load(e)


d = post("/api/session.php", {"lang": "th", "birth_year": 1999, "gender": "Male", "alias": "ProdSleuth7",
                              "device": "E2E7", "screen": "390x844", "repeat_tag": "rt_prod7"})
assert d["ok"], d
sid, plan = d["session_id"], d["plan"]
assert len(plan["round1"]) == 12 and len(plan["round2"]) == 24

d2 = post("/api/session.php", {"lang": "th", "birth_year": 1999, "gender": "Male", "alias": "ProdSleuth7",
                               "device": "x", "screen": "0x0", "repeat_tag": "rt_x"})
assert not d2["ok"] and d2["error"] == "alias_taken"

t = plan["round2"][0]
assert post("/api/trial.php", {"session_id": sid, "round": "2", "trial_index": 1, "image_id": t["id"],
                               "response": 1, "rt_ms": 900})["ok"]
assert post("/api/finalize.php", {"session_id": sid, "action": "survey",
                                  "f1": 1, "f2": 2, "f3": 1, "f4": 4, "f5": 0, "f6": 0,
                                  "f7": None, "f8": 5})["ok"]
assert post("/api/contact.php", {"alias": "ProdSleuth7", "contact": "0800000000"})["ok"]
st = json.load(urllib.request.urlopen(f"{BASE}/api/state.php?session_id={sid}"))
assert st["saved"]["r2"] == [1]
lb = json.load(urllib.request.urlopen(BASE + "/api/leaderboard.php"))
assert lb["ok"] and "meta" in lb
print("PROD E2E ALL PASSED — meta:", lb["meta"])

c = json.load(open("data/pipeline/deploy_credentials.json"))
jobs = [
    ("ai_detect_research", "research_app", c["research_app_pw"],
     f"DELETE FROM trials WHERE session_id='{sid}'; DELETE FROM sessions WHERE session_id='{sid}';"),
    ("ai_detect_prize", "prize_app", c["prize_app_pw"],
     "DELETE FROM prize_contacts WHERE alias='ProdSleuth7';"),
]
for db, user, pw, sql in jobs:
    q = subprocess.run(["tools/mariadb/bin/mysql.exe", "--host=face-detective-mysql.mysql.database.azure.com",
                        "--user", user, "--password=" + pw, db, "-e", sql],
                       capture_output=True, text=True)
    assert q.returncode == 0, q.stderr
print("prod cleanup OK")
