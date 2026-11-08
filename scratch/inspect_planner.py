import json

path = r"C:\Users\Owner\.gemini\antigravity-ide\brain\f69caebe-86ff-4d83-ae8b-818708989e0d\.system_generated\logs\transcript.jsonl"
with open(path, "r", encoding="utf-8") as f:
    for line in f:
        data = json.loads(line)
        if data.get("type") == "PLANNER_RESPONSE":
            idx = data.get("step_index")
            c = data.get("content", "")
            if c:
                first_line = c.split("\n")[0]
                print(f"[{idx}] {first_line[:120]}")
