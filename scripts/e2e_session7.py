"""Local E2E for session 7 features: signup alias, uniqueness, one-play (test mode),
survey v2 validation, prize contact, resume state."""
import json
import urllib.error
import urllib.request

BASE = "http://127.0.0.1:8090"


def post(path, body):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json"})
    try:
        return json.load(urllib.request.urlopen(req))
    except urllib.error.HTTPError as e:
        return json.load(e)


def get(path):
    return json.load(urllib.request.urlopen(BASE + path, cache_off := None) if False else urllib.request.urlopen(BASE + path))


# 1) bootstrap with alias
d = post("/api/session.php", {"lang": "th", "birth_year": 2000, "gender": "Female", "alias": "E2ESleuth",
                              "device": "E2E7", "screen": "0x0", "repeat_tag": "rt_e2e_1"})
assert d["ok"], d
sid, plan = d["session_id"], d["plan"]
assert len(plan["round1"]) == 12 and len(plan["round2"]) == 24
print("1. bootstrap with alias OK")

# 2) duplicate alias rejected
d2 = post("/api/session.php", {"lang": "th", "birth_year": 2000, "gender": "Female", "alias": "E2ESleuth",
                               "device": "x", "screen": "0x0", "repeat_tag": "rt_e2e_2"})
assert not d2["ok"] and d2["error"] == "alias_taken", d2
print("2. duplicate alias -> alias_taken OK")

# 3) check-alias endpoint
c1 = get("/api/check-alias.php?alias=E2ESleuth")
c2 = get("/api/check-alias.php?alias=FreeName9")
assert c1["available"] is False and c2["available"] is True
print("3. check-alias OK")

# 4) short alias rejected at signup
d3 = post("/api/session.php", {"lang": "en", "birth_year": 2000, "gender": "Male", "alias": "ab",
                               "device": "x", "screen": "0x0", "repeat_tag": ""})
assert not d3["ok"] and d3["error"] == "alias", d3
print("4. short alias rejected OK")

# 5) survey v2: valid + consistent
t = plan["round2"][0]
post("/api/trial.php", {"session_id": sid, "round": "2", "trial_index": 1, "image_id": t["id"],
                        "response": 1, "rt_ms": 900})
r = post("/api/finalize.php", {"session_id": sid, "action": "survey",
                               "f1": 1, "f2": 2, "f3": 0, "f4": None, "f5": 1, "f6": 1, "f7": 0, "f8": 4})
assert r["ok"], r
r = post("/api/finalize.php", {"session_id": sid, "action": "survey",
                               "f1": 0, "f2": None, "f3": 0, "f4": None, "f5": 0, "f6": 0, "f7": None, "f8": 1})
assert r["ok"], r
print("5. survey v2 valid OK")

# 6) inconsistent survey rejected (f2 answered while f1 = No)
r = post("/api/finalize.php", {"session_id": sid, "action": "survey",
                               "f1": 0, "f2": 3, "f3": 0, "f4": None, "f5": 0, "f6": 0, "f7": None, "f8": 1})
assert not r["ok"] and "inconsistent" in str(r.get("error", "")), r
print("6. survey inconsistency rejected OK")

# 7) prize contact
r = post("/api/contact.php", {"alias": "E2ESleuth", "contact": "0899123456"})
assert r["ok"], r
print("7. prize contact OK")

# 8) resume state reflects saved trial
st = get(f"/api/state.php?session_id={sid}")
assert st["saved"]["r2"] == [1]
print("8. resume state OK")

# 9) leaderboard meta
lb = get("/api/leaderboard.php")
assert lb["ok"] and "meta" in lb and lb["meta"]["total"] >= 1
print("9. leaderboard meta OK:", lb["meta"])

# cleanup test rows (research db)
import subprocess, sys
c = json.load(open("data/pipeline/deploy_credentials.json"))
q = subprocess.run(["tools/mariadb/bin/mysql.exe", "-P", "3307", "-u", "research_app",
                    "-presearch_dev_pw", "ai_detect_research", "-e",
                    f"DELETE FROM trials WHERE session_id='{sid}'; DELETE FROM sessions WHERE session_id='{sid}';"],
                   capture_output=True, text=True)
assert q.returncode == 0, q.stderr
print("cleanup OK — ALL E2E PASSED")
