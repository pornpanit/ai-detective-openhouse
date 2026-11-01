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

# find investigation sections (works with any comment style)
i1 = js.find("round1.forEach")
i2 = js.find("round2.forEach")
r1_section = js[i1:i2] if i1 > 0 and i2 > i1 else js[i1:i1+2000] if i1 > 0 else ""

# find stage-2 section for order checks
stage2 = js[js.find("stage2("):] if "stage2(" in js else js

checks = {
    "4.2 birth-year required": 'name="yob" required' in js,
    "4.2 no pre-game familiarity": "familiarity" not in js[:js.find("round1.forEach") if js.find("round1.forEach") > 0 else 10000],
    "4.3 practice round saved": 'round: "practice"' in js,
    "4.4 preload before rounds": js.index("jsPsychPreload") < js.index("round1.forEach") if "jsPsychPreload" in js and "round1.forEach" in js else False,
    "4.5 no timeout (no trial_duration)": "trial_duration" not in js,
    "4.5 no per-trial feedback (R1)": "stampOverlay" not in js[js.find("round1.forEach"):js.find("round2.forEach")] if js.find("round1.forEach") > 0 and js.find("round2.forEach") > 0 else True,
    "4.6 binary AI/Real": 'T("yes")' in js and 'T("no")' in js,
    "4.7 retry + outbox": "outbox" in js and "attempts = 3" in js,
    "4.8 order consent->survey->prize->score->debrief":
        (lambda s: s.index('"consent"') < s.index('"survey"') < s.index("prize_join") < s.index("score.php") < s.index("debrief_h"))(stage2),
    "4.4b alias at signup (stage 1)": 'name="alias"' in js,
    "4.4c alias uniqueness": "alias_taken" in js and "check-alias.php" in js,
    "4.9 theme strings used": 'T("title")' in js and 'T("score_line")' in js,
    "4.10 quit control": "showQuit" in js and "quitFlow" in js,
    "S1 stamps present": "stampOverlay" in js and "SoundFX" in js,
    "S1 suspect headers": "suspectHeader" in js,
    "S1 score card canvas": "score-card-canvas" in js and "renderScoreCard" in js,
    "S1 confetti": "confettiBurst" in js,
    "S1 count-up": "countUp" in js,
    "S1 mute toggle": "mute-btn" in js and "SoundFX" in js,
    "S1 post_trial_gap": "post_trial_gap" in js,
    "S1 no RT contamination (stamps in on_finish only)":
        all("stampOverlay" not in line for line in js.split("\n") if "on_load" in line and "trialMode" not in line),
    "S9 R1 uses faceFlash (visual only, no text stamp)":
        "faceFlash" in js and "stampOverlay" not in js[js.find("round1.forEach"):js.find("round2.forEach")] if js.find("round1.forEach") > 0 and js.find("round2.forEach") > 0 else True,
    "S9 R2 stamp shows player choice (AI/REAL)":
        'stampOverlay(d.response === 0 ? "ai" : "real")' in js[js.find("round2.forEach"):] if js.find("round2.forEach") > 0 else False,
    "S9 survey conditional on consent":
        'conditional_function: () => S.consentVal === 1' in js,
    "S9 finish action in score card (always fires)":
        'action: "finish"' in js,
}
for name, ok in checks.items():
    print(("PASS " if ok else "FAIL ") + name)
    assert ok, name
print("ALL FRONTEND STATIC CHECKS PASSED")
