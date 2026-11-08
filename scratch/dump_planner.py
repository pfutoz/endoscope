import json

path = r"C:\Users\Owner\.gemini\antigravity-ide\brain\f69caebe-86ff-4d83-ae8b-818708989e0d\.system_generated\logs\transcript.jsonl"
out_path = r"c:\Apache24\htdocs\endoscope\scratch\assistant_responses.txt"

with open(path, "r", encoding="utf-8") as f, open(out_path, "w", encoding="utf-8") as out:
    for line in f:
        data = json.loads(line)
        if data.get("type") == "PLANNER_RESPONSE":
            idx = data.get("step_index")
            c = data.get("content", "")
            if c:
                out.write(f"=== STEP {idx} ===\n{c}\n\n")
