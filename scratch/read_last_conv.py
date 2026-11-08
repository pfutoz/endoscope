import json

path = r"C:\Users\Owner\.gemini\antigravity-ide\brain\f69caebe-86ff-4d83-ae8b-818708989e0d\.system_generated\logs\transcript.jsonl"
with open(path, "r", encoding="utf-8") as f:
    for line in f:
        data = json.loads(line)
        if data.get("source") == "USER_EXPLICIT" or data.get("type") == "USER_INPUT":
            content = data.get("content", "").strip()
            print(f"[{data.get('step_index')}] {content}")
