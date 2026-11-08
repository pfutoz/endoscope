<?php
/**
 * 内視鏡・エコー検査予定集計API
 * File: endoscope/api/today_count.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');

$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host={$host};dbname={$dbname};";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 2
    ]);

    $stmt = $pdo->prepare("
        SELECT department_code, count(*) as cnt 
        FROM t_endoscopy_schedule 
        WHERE exam_date = :d AND (is_canceled IS NOT TRUE)
        GROUP BY department_code
    ");
    $stmt->execute([':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $gastro = (int)($rows['GASTRO'] ?? 0);
    $proct  = (int)($rows['PROCT'] ?? 0);
    $total  = array_sum($rows);

    echo json_encode([
        'status'      => 'success',
        'date'        => $date,
        'total'       => $total,
        'gastro'      => $gastro,
        'proct'       => $proct,
        'departments' => [
            'gastro' => $gastro,
            'proct'  => $proct
        ],
        'text'        => "胃腸科{$gastro}件、肛門科{$proct}件"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}