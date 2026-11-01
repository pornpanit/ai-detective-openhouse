"""Wipe-recreate Azure app + deploy + verify."""
import json
import subprocess
import urllib.request

AZ = ["az"]

def run(cmd, **kw):
    r = subprocess.run(cmd, shell=True, capture_output=True, text=True, **kw)
    if r.returncode != 0:
        print(f"FAILED: {cmd}\n{r.stderr[-300:]}")
        sys.exit(1)
    return r.stdout

import sys
# 1. delete + recreate
run("az webapp delete --name face-detective --resource-group rg-face-detective --keep-empty-plan --output none")
print("app deleted")
run("az webapp create --name face-detective --resource-group rg-face-detective --plan plan-face-detective --runtime PHP:8.4 --output none")
print("app recreated")

# 2. settings
c = json.load(open("data/pipeline/deploy_credentials.json"))
settings = [
    f"RESEARCH_DB_HOST=face-detective-mysql.mysql.database.azure.com",
    "RESEARCH_DB_PORT=3306", "RESEARCH_DB_NAME=ai_detect_research",
    "RESEARCH_DB_USER=research_app", f"RESEARCH_DB_PASS={c['research_app_pw']}",
    "PRIZE_DB_HOST=face-detective-mysql.mysql.database.azure.com",
    "PRIZE_DB_PORT=3306", "PRIZE_DB_NAME=ai_detect_prize",
    "PRIZE_DB_USER=prize_app", f"PRIZE_DB_PASS={c['prize_app_pw']}",
    "ADMIN_PW=projectpass99", "WINDOW_MINUTES=30", "TEST_MODE=1",
]
run("az webapp config appsettings set --name face-detective --resource-group rg-face-detective --settings " +
    " ".join(settings) + " --output none")
print("settings configured")

# 3. deploy
r = subprocess.run("az webapp deploy --name face-detective --resource-group rg-face-detective --src-path deploy.zip --type zip --output json",
                   shell=True, capture_output=True, text=True, timeout=1200)
print("deployed" if r.returncode == 0 else "deploy FAILED")

# 4. verify
import time
time.sleep(30)
health = urllib.request.urlopen("https://face-detective.azurewebsites.net/api/health.php").read().decode()
print(f"health: {health}")
js = urllib.request.urlopen("https://face-detective.azurewebsites.net/experiment.js").read().decode()
print(f"faceFlash calls: {js.count('faceFlash(')}")
print(f"stampOverlay calls: {js.count('stampOverlay(')}")
print(f"survey-submit in quit: {'survey-submit' in js}")
print(f"quitFlow present: {'async function quitFlow' in js}")
