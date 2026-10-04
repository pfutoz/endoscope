<?php
// C:\Apache24\htdocs\endoscope\sync_mall_memo.php
header('Content-Type: application/json; charset=UTF-8');

$patient_id = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';

if ($patient_id === '' || !is_numeric($patient_id)) {
    echo json_encode(['success' => false, 'message' => 'カルテIDが不正です']);
    exit;
}

// 1. ローカルの PostgreSQL 接続設定
$pg_host   = 'localhost';
$pg_dbname = 'endoscope'; 
$pg_user   = 'postgres';
$pg_pass   = 'postgres';

try {
    $dsn = "pgsql:host=$pg_host;dbname=$pg_dbname;";
    $pdo = new PDO($dsn, $pg_user, $pg_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'ローカルDB接続失敗']);
    exit;
}

// 2. 基本情報APIから氏名（patient_name）を取得
$patient_url = "http://localhost/endoscope/get_mall_patient.php?patient_no=" . $patient_id;
$patient_json = @file_get_contents($patient_url);
$patient_name = '';
$birth_date = null;

if ($patient_json !== false) {
    $p_res = json_decode($patient_json, true);
    if (isset($p_res['success']) && $p_res['success']) {
        $patient_name = isset($p_res['patient_name']) ? trim($p_res['patient_name']) : '';
        $birth_date = !empty($p_res['birthday']) ? $p_res['birthday'] : null;
    }
}

// 3. メモ情報APIから3種の最新メモを取得
$api_url = "http://localhost/endoscope/get_mall_memos.php?patient_no=" . $patient_id;
$json_data = @file_get_contents($api_url);

if ($json_data === false) {
    echo json_encode(['success' => false, 'message' => 'メモ抽出APIとの通信に失敗しました']);
    exit;
}

$res = json_decode($json_data, true);
if (!$res || !isset($res['success']) || !$res['success']) {
    echo json_encode(['success' => false, 'message' => '電子カルテからのデータ抽出に失敗しました']);
    exit;
}

$memo_today   = null;
$memo_karte   = null;
$memo_patient = null;

foreach ($res['memos'] as $memo) {
    $div_id = (int)$memo['div_id'];
    if ($div_id === 1)  $memo_today   = $memo['note'];
    if ($div_id === 2)  $memo_karte   = $memo['note'];
    if ($div_id === 3)  $memo_patient = $memo['note'];
}

try {
    // 4. PostgreSQL（t_mall_memo）へUPSERT（patient_name や birth_date も含めて保存）
    // カラム名が「birth_date」か「birthday」かは、エラー詳細の型や既存構造（birth_date）に合わせて設定しています
    $sync_sql = "INSERT INTO public.t_mall_memo 
                    (patient_id, patient_name, birth_date, memo_today, memo_karte, memo_patient) 
                 VALUES 
                    (:pid, :pname, :birth, :today, :karte, :patient)
                 ON CONFLICT (patient_id) 
                 DO UPDATE SET 
                    patient_name = EXCLUDED.patient_name,
                    birth_date   = EXCLUDED.birth_date,
                    memo_today   = EXCLUDED.memo_today,
                    memo_karte   = EXCLUDED.memo_karte,
                    memo_patient = EXCLUDED.memo_patient";
                    
    $stmt = $pdo->prepare($sync_sql);
    $stmt->execute([
        ':pid'     => $patient_id,
        ':pname'   => $patient_name,
        ':birth'   => $birth_date,
        ':today'   => $memo_today,
        ':karte'   => $memo_karte,
        ':patient' => $memo_patient
    ]);

    echo json_encode([
        'success'      => true,
        'memo_today'   => $memo_today,
        'memo_karte'   => $memo_karte,
        'memo_patient' => $memo_patient
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'ローカルDBへの保存に失敗しました: ' . $e->getMessage()]);
}