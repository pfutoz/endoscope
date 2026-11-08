with open('T:/endoscope/print_schedule_daily.php', 'r', encoding='utf-8', errors='replace') as f:
    content = f.read()

with open('scratch/prod_print_schedule.txt', 'w', encoding='utf-8') as out:
    out.write(content)
print("Dumped prod print_schedule_daily.php")
