"""Batch plan verification against the six-cell specification.

6.6: N bootstraps -> every Round 1 pair same-gender (checked against the pool
manifest, not the plan's own label); per-session allocations exact.
6.5: consecutive bootstraps within the 30-min window share zero images; after
clock-shifting all stamps to -31 min (window expiry), reuse becomes permitted.

Usage: python scripts/verify_plans.py [N]
"""
import csv
import json
import subprocess
import sys
import urllib.request
from collections import Counter
from pathlib import Path

API = "http://127.0.0.1:8090/api/session.php"
MYSQL = Path("tools/mariadb/bin/mysql.exe")


def bootstrap(n=50):
    plans = []
    for i in range(n):
        body = json.dumps({"lang": "en", "birth_year": 2000, "gender": "x",
                           "device": "verify", "screen": "0x0", "repeat_tag": ""}).encode()
        r = urllib.request.urlopen(urllib.request.Request(API, data=body,
                                   headers={"Content-Type": "application/json"}))
        d = json.load(r)
        assert d["ok"], d
        plans.append(d)
        print(f"  bootstrapped {i + 1}/{n}", end="\r", flush=True)
    print()
    return plans


def main():
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 50
    manifest = {r["image_id"]: r for r in csv.DictReader(open("public/assets/pool_manifest.csv", encoding="utf-8"))}

    plans = bootstrap(n)
    ids = []
    ok_pairs = ok_r1 = ok_r2 = 0
    CELL2 = {("real", "Male"): 6, ("real", "Female"): 6,
             ("gan", "Male"): 3, ("gan", "Female"): 3,
             ("sd15", "Male"): 3, ("sd15", "Female"): 3}
    for d in plans:
        pl = d["plan"]
        # ---- pair gender from manifest truth ----
        pair_ok = all(manifest[t["ai"]["id"]]["gender"] == manifest[t["real"]["id"]]["gender"] for t in pl["round1"])
        # ---- allocations ----
        g = Counter(t["gender"] for t in pl["round1"])
        ty = Counter((t["gender"], t["ai"]["type"]) for t in pl["round1"])
        r2 = Counter(t["type"] for t in pl["round2"])
        r1_ok = (len(pl["round1"]) == 12 and g["Male"] == 6 and g["Female"] == 6
                 and all(ty[(gd, k)] == 3 for gd in ("Male", "Female") for k in ("gan", "diffusion")))
        # ---- R2: 24 trials, 12 AI (6 gan + 6 diff) + 12 real, per-cell allocation ----
        cell2 = Counter((manifest[t["id"]]["source"], manifest[t["id"]]["gender"]) for t in pl["round2"])
        r2_ok = (len(pl["round2"]) == 24 and r2["gan"] == 6 and r2["diffusion"] == 6 and r2["real"] == 12
                 and all(cell2[k] == v for k, v in CELL2.items()))
        ok_pairs += pair_ok
        ok_r1 += r1_ok
        ok_r2 += r2_ok
        ids.append({t["ai"]["id"] for t in pl["round1"]} | {t["real"]["id"] for t in pl["round1"]}
                   | {t["id"] for t in pl["round2"]})

    print(f"6.6 same-gender pairs: {ok_pairs}/{n} | r1 allocation: {ok_r1}/{n} | r2 allocation: {ok_r2}/{n}")
    assert ok_pairs == n and ok_r1 == n and ok_r2 == n

    # ---- 6.5 zero overlap within window (pairwise over all sessions) ----
    overlaps = [(i, j, len(ids[i] & ids[j])) for i in range(n) for j in range(i + 1, n) if ids[i] & ids[j]]
    print(f"6.5 within-window overlaps: {len(overlaps)} (must be 0)")
    assert not overlaps

    # ---- 6.5 window expiry: shift every stamp to -31 min, reuse becomes allowed ----
    q = subprocess.run([str(MYSQL), "-P", "3307", "-u", "research_app", "-presearch_dev_pw",
                        "ai_detect_research", "-e",
                        "UPDATE images SET last_used_at = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE last_used_at IS NOT NULL;"],
                       capture_output=True, text=True)
    assert q.returncode == 0, q.stderr
    fresh = bootstrap(30)
    base_union = set().union(*ids)
    reused = any({t["id"] for t in d["plan"]["round2"]} & base_union for d in fresh)
    print(f"6.5 after expiry, reuse observed: {reused} (expected True)")
    assert reused
    print("PLAN VERIFICATION PASSED (6.5 + 6.6)")


if __name__ == "__main__":
    main()
