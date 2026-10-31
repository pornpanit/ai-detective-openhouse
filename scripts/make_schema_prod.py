"""Build schema_prod.sql from config/schema.sql:
- keeps databases + tables + indexes
- replaces the dev credential section with production users (@'%') using
  passwords from data/pipeline/deploy_credentials.json
"""
import json
from pathlib import Path

src = Path("config/schema.sql").read_text(encoding="utf-8")
creds = json.load(open("data/pipeline/deploy_credentials.json", encoding="utf-8"))

body = src.split("-- -------- credential isolation")[0]
body += f"""-- -------- production credentials (users reachable from Azure services) --------
CREATE USER IF NOT EXISTS 'research_app'@'%' IDENTIFIED BY '{creds["research_app_pw"]}';
CREATE USER IF NOT EXISTS 'prize_app'@'%' IDENTIFIED BY '{creds["prize_app_pw"]}';
GRANT ALL PRIVILEGES ON ai_detect_research.* TO 'research_app'@'%';
GRANT ALL PRIVILEGES ON ai_detect_prize.*    TO 'prize_app'@'%';
FLUSH PRIVILEGES;
"""
out = Path("data/pipeline/schema_prod.sql")
out.write_text(body, encoding="utf-8")
print(f"-> {out}")
