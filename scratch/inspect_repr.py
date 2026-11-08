import json

path = r"C:\Users\Owner\.gemini\antigravity-ide\brain\f69caebe-86ff-4d83-ae8b-818708989e0d\.system_generated\logs\transcript.jsonl"
with open(path, "rb") as f:
    for line in f:
        data = json.loads(line.decode('utf-8', errors='replace'))
        if data.get("source") == "USER_EXPLICIT" or data.get("type") == "USER_INPUT":
            c = data.get("content", "")
            print(f"Step {data.get('step_index')}:")
            print(repr(c))
