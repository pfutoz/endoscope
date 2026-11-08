import psycopg2

conn = psycopg2.connect("host=127.0.0.1 port=5432 dbname=endoscope user=postgres password=postgres")
cur = conn.cursor()
cur.execute("SELECT schedule_id, patient_name, exam_date, department_code, is_canceled FROM t_endoscopy_schedule WHERE exam_date = '2026-10-22'")
rows = cur.fetchall()
print("2026-10-22 rows:", len(rows))
for r in rows:
    print(r)
