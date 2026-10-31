"""Static verification for the frontend build (task group 4 checks)."""
import csv
import pathlib
import re

src = pathlib.Path("public/strings.js").read_text(encoding="utf-8")
en_block = src[src.index("en: {"):src.index("th: {")]
th_block = src[src.index("th: {"):]

def keys(block):
    return set(re.findall(r'^\s{4}"?(\w+)"?\s*:', block, re.M))

en_k, th_k = keys(en_block), keys(th_block)
assert en_k == th_k, f"parity fail: TH missing {en_k - th_k}, EN missing {th_k - en_k}"
print(f"strings parity OK ({len(en_k)} keys)")

rows = list(csv.DictReader(open("public/assets/pool_manifest.csv", encoding="utf-8")))
missing = [r["file"] for r in rows if not (pathlib.Path("public/assets") / r["file"]).exists()]
assert not missing, f"missing {missing[:5]}"
print(f"assets OK ({len(rows)} manifest files on disk)")

js = pathlib.Path("public/experiment.js").read_text(encoding="utf-8")
checks = {
    "4.2 birth-year required": 'name="yob" required' in js,
    "4.2 no pre-game familiarity": "familiarity" not in js.split("// ---- Investigation 1")[0].split("surveyTrial")[0],
    "4.3 practice round saved": 'round: "practice"' in js,
    "4.4 preload before rounds": js.index("jsPsychPreload") < js.index("plan.round1.forEach"),
    "4.5 no timeout (no trial_duration)": "trial_duration" not in js,
    "4.5 no per-trial feedback": "correct" not in js.split("// ---- Investigation 1")[1].split("// ---- Investigation 2")[0],
    "4.6 binary yes/no": 'T("yes"), () => T("no")' in js,
    "4.7 retry + outbox": "outbox" in js and "attempts = 3" in js,
    "4.8 order score->consent->survey->prize->debrief":
        (lambda s: s.index("score.php") < s.index('"consent"') < s.index('"survey"') < s.index("prize_join") < s.index("debrief_h"))(js[js.index("STAGE 2"):]),
    "4.4b alias at signup (stage 1)": 'name="alias"' in js and js.index('name="alias"') < js.index("STAGE 2"),
    "4.4c alias uniqueness handled": "alias_taken" in js and "check-alias.php" in js,
    "4.9 theme strings used": 'T("title")' in js and 'T("score_line")' in js,
    "4.10 quit control in rounds": "showQuit" in js and "quitFlow" in js,
}
for name, ok in checks.items():
    print(("PASS " if ok else "FAIL ") + name)
    assert ok, name
print("ALL FRONTEND STATIC CHECKS PASSED")
