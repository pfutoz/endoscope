with open(r'c:\Apache24\htdocs\endoscope\print_schedule_daily.php', 'r', encoding='utf-8', errors='replace') as f:
    for i, line in enumerate(f, 1):
        if 'data-room' in line or 'schedule-row' in line or 'data-standard-idx' in line or 'roomFirst' in line:
            print(f"{i}: {line.strip()[:100]}")
