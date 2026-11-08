import json

path = r"C:\Users\Owner\.gemini\antigravity-ide\brain\f69caebe-86ff-4d83-ae8b-818708989e0d\.system_generated\logs\transcript.jsonl"
with open(path, "r", encoding="utf-8") as f:
    for line in f:
        data = json.loads(line)
        if data.get("source") == "USER_EXPLICIT" or data.get("type") == "USER_INPUT":
            content = data.get("content", "").strip()
            # Try re-encoding if mojibake
            try:
                # sometimes content was decoded as utf-8 but original was cp932 bytes
                fixed = content.encode("cp932", errors="ignore").decode("cp932")
            except:
                fixed = content
            print(f"=== STEP {data.get('step_index')} ===")
            print(content)
