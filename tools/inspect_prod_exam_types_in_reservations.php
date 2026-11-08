<?php
/**
 * tools/inspect_prod_exam_types_in_reservations.php
 * 本番予約データ(t_endoscopy_schedule)で使われているexam_typeの分布を調査
 */
$pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== 本番予約 (t_endoscopy_schedule) 内の exam_type 分布 ===" . PHP_EOL;
$stmt = $pdoProd->query("
    SELECT exam_type, count(*) as cnt 
    FROM t_endoscopy_schedule 
    GROUP BY exam_type 
    ORDER BY cnt DESC
");
foreach ($stmt->fetchAll() as $r) {
    printf("  - %-20s : %5d 件\n", $r['exam_type'] ?: '(空文字/NULL)', $r['cnt']);
}
