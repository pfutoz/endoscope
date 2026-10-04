<?php
/**
 * 生成日時: 2026-08-05
 * 患者サジェストAPI
 * GET: keyword
 * 戻り値: JSON
 */
header('Content-Type: application/json; charset=UTF-8');

// ============================================================
// 1. データベース接続
// ============================================================
$host = 'localhost';
$dbname = 'endoscope';
$user = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode([]);
    exit;
}

// ============================================================
// 2. パラメータ取得
// ============================================================
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
if (strlen($keyword) < 1) {
    echo json_encode([]);
    exit;
}

$search_term = '%' . $keyword . '%';

// ============================================================
// 3. サジェストデータ取得
// ============================================================
$is_numeric = is_numeric($keyword);

if ($is_numeric) {
    // 数値の場合はカルテIDで検索（前方一致）
    $sql = "
        SELECT 
            patient_id,
            patient_name,
            birthday,
            department_code,
            MAX(exam_date) AS last_exam_date
        FROM t_endoscopy_schedule
        WHERE patient_id::text LIKE :keyword
          AND patient_name IS NOT NULL
          AND patient_name != ''
          AND is_canceled = FALSE
        GROUP BY patient_id, patient_name, birthday, department_code
        ORDER BY last_exam_date DESC
        LIMIT 20
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':keyword' => $search_term]);
} else {
    // 文字列の場合は患者名で検索（部分一致）
    $sql = "
        SELECT 
            patient_id,
            patient_name,
            birthday,
            department_code,
            MAX(exam_date) AS last_exam_date
        FROM t_endoscopy_schedule
        WHERE patient_name ILIKE :keyword
          AND patient_name IS NOT NULL
          AND patient_name != ''
          AND is_canceled = FALSE
        GROUP BY patient_id, patient_name, birthday, department_code
        ORDER BY last_exam_date DESC
        LIMIT 20
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':keyword' => $search_term]);
}

$results = $stmt->fetchAll();

// ============================================================
// 4. レスポンス整形
// ============================================================
foreach ($results as &$row) {
    if ($row['birthday']) {
        $row['birthday'] = date('Y/m/d', strtotime($row['birthday']));
    }
    $row['dept_label'] = ($row['department_code'] === 'PROCT') ? '肛門科' : '胃腸科';
}

echo json_encode($results);