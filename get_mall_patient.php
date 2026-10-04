<?php
// 予期せぬ文字出力を防ぐため画面表示はOFF、ログには全出力
ini_set('display_errors', 0); 
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// カルテID（PatientNo）の取得とバリデーション
$patient_no = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : '';

if ($patient_no === '' || !is_numeric($patient_no)) {
    echo json_encode(['success' => false, 'message' => 'IDが不正です']);
    exit;
}

// 1. MALL3 (SQL Server) への接続設定
$serverName = "192.168.1.77"; 
$dbName     = "his00";
$uid        = "sa";
$pwd        = "Kochi-cl";

try {
    $dsn = "sqlsrv:server=$serverName;Database=$dbName;TrustServerCertificate=1;ApplicationIntent=ReadOnly;";
    $conn = new PDO($dsn, $uid, $pwd, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $conn->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '電子カルテDB接続失敗']);
    exit;
}

try {
    // 2. 患者基本情報（PatientKanaを追加） ＋ 住所拡張情報の取得
    $sql = "SELECT 
                P.PatientName, 
                P.PatientKana,
                CONVERT(varchar(10), P.BirthDay, 120) AS BirthDayStr,
                P.Sex,
                A.add1,
                A.add2,
                A.Tel
            FROM M_Patient P
            LEFT JOIN M_PatientAdd A ON P.PatientID = A.PatientID
            WHERE P.PatientNo = :patient_no";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([':patient_no' => $patient_no]);
    $patient = $stmt->fetch();

    if (!$patient) {
        echo json_encode(['success' => false, 'message' => '該当患者なし']);
        exit;
    }

    // 3. add1 と add2 を結合して前後のスペースをTrimする
    $raw_address = ($patient['add1'] ?? '') . ($patient['add2'] ?? '');
    $clean_address = preg_replace('/^[  \s]+|[  \s]+$/u', '', $raw_address);

    // 性別の表記成形
    $gender = $patient['Sex'] !== null ? trim($patient['Sex']) : '';
    if ($gender === '1') $gender = '男性';
    if ($gender === '2') $gender = '女性';

    // 4. 綺麗なJSONのみを返却（patient_kana, telを新規追加）
    echo json_encode([
        'success'      => true,
        'patient_name' => trim($patient['PatientName']),
        'patient_kana' => isset($patient['PatientKana']) ? trim($patient['PatientKana']) : '',
        'birthday'     => !empty($patient['BirthDayStr']) ? $patient['BirthDayStr'] : '',
        'gender'       => $gender,
        'address'      => $clean_address,
        'tel'          => isset($patient['Tel']) ? trim($patient['Tel']) : ''
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'クエリ実行不具合']);
}