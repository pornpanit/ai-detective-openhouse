"""Local E2E: admin test-flag (hide from board + exports, keep in admin)."""
import http.cookiejar
import json
import subprocess
import urllib.request

BASE = "http://127.0.0.1:8090"
ADMIN_PW = "adminlocal"


def post_json(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    return json.load(urllib.request.urlopen(req))


def get_json(path):
    return json.load(urllib.request.urlopen(BASE + path))


# 1) two sessions: one to flag, one to keep
d1 = post_json("/api/session.php", {"lang": "en", "birth_year": 2000, "gender": "Male",
                                    "alias": "FlagMe99", "device": "flagE2E", "screen": "0x0",
                                    "repeat_tag": "rt_flag1"})
d2 = post_json("/api/session.php", {"lang": "en", "birth_year": 2000, "gender": "Female",
                                    "alias": "KeepMe99", "device": "flagE2E", "screen": "0x0",
                                    "repeat_tag": "rt_flag2"})
assert d1["ok"] and d2["ok"]
s1, s2 = d1["session_id"], d2["session_id"]

# 2) give both full playthroughs (perfect) + consent + finish so they hit the board
for d, sid in ((d1, s1), (d2, s2)):
    for i, t in enumerate(d["plan"]["round1"]):
        post_json("/api/trial.php", {"session_id": sid, "round": "1", "trial_index": i + 1,
                                     "image_id": t["ai"]["id"], "rt_ms": 1000})
    for i, t in enumerate(d["plan"]["round2"]):
        post_json("/api/trial.php", {"session_id": sid, "round": "2", "trial_index": i + 1,
                                     "image_id": t["id"], "response": 1 if t["type"] != "real" else 0,
                                     "rt_ms": 1000})
    post_json("/api/finalize.php", {"session_id": sid, "action": "consent", "consent": 1})
    post_json("/api/finalize.php", {"session_id": sid, "action": "survey",
                                    "f1": 0, "f2": None, "f3": 0, "f4": None,
                                    "f5": 0, "f6": 0, "f7": None, "f8": 3})
    post_json("/api/finalize.php", {"session_id": sid, "action": "finish"})

lb = get_json("/api/leaderboard.php")
aliases = [r["alias"] for r in lb["board"]]
assert "FlagMe99" in aliases and "KeepMe99" in aliases, aliases
assert lb["meta"]["total"] >= 2
print("1. both on board + meta:", lb["meta"])

# 3) admin-flag FlagMe99 (cookie login + POST toggle)
cj = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
op.open(BASE + "/admin.php", data=f"pw={ADMIN_PW}".encode())          # sets cookie
op.open(BASE + "/admin.php", data=f"action=toggle&session_id={s1}".encode())  # flag
html = op.open(BASE + "/admin.php").read().decode("utf-8")
assert "FlagMe99" in html and "test" in html
print("2. admin flags FlagMe99 (still listed with badge)")

# 4) board + meta exclude it
lb = get_json("/api/leaderboard.php")
aliases = [r["alias"] for r in lb["board"]]
assert "FlagMe99" not in aliases and "KeepMe99" in aliases, aliases
print("3. board hides FlagMe99; meta:", lb["meta"])

# 5) research export excludes it
q = subprocess.run(["tools/mariadb/bin/mysql.exe", "-P", "3307", "-u", "research_app",
                    "-presearch_dev_pw", "ai_detect_research", "-N", "-e",
                    "SELECT COUNT(*) FROM sessions WHERE consent=1 AND is_test=0 AND alias IN ('FlagMe99','KeepMe99');"],
                   capture_output=True, text=True)
assert q.stdout.strip() == "1", q.stdout
print("4. export filter keeps only KeepMe99")

# 6) un-flag restores
op.open(BASE + "/admin.php", data=f"action=toggle&session_id={s1}".encode())
lb = get_json("/api/leaderboard.php")
assert "FlagMe99" in [r["alias"] for r in lb["board"]]
print("5. un-flag restores board visibility")

# cleanup test sessions
q = subprocess.run(["tools/mariadb/bin/mysql.exe", "-P", "3307", "-u", "root",
                    "-prootlocal", "ai_detect_research", "-e",
                    f"DELETE FROM trials WHERE session_id IN ('{s1}','{s2}');"
                    f"DELETE FROM sessions WHERE session_id IN ('{s1}','{s2}');"],
                   capture_output=True, text=True)
assert q.returncode == 0, q.stderr
print("cleanup OK — FLAG E2E PASSED")
