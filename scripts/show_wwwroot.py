"""Inspect the Azure wwwroot via Kudu vfs listing (data/pipeline/wwwroot_list.json)."""
import json
import sys

items = json.load(open("data/pipeline/wwwroot_list.json", encoding="utf-8-sig"))
for i in items:
    print(f"{i['mime'][:24]:26s} {i['size']:>9} {i['name']}")
print(f"total: {len(items)}")
