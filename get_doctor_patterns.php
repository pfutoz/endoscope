<?php
/**
 * 生成日時: 2026-08-14
 * get_doctor_patterns.php - 医師別パターン一覧取得API（横持ち対応）
 * 
 * パラメータ:
 *   - doctor: 医師名 (必須)
 *   - date: 日付 (YYYY-MM-DD) (任意)
 *   - dept: 診療科 (PROCT / GASTRO)
 * 
 * 戻り値:
 *   - success: boolean
 *   - patterns: パターン名の配列
 *   - message: エラーメッセージ (失敗時)
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

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
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'データベース接続失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// 2. パラメータ取得
// ============================================================
$doctor = isset($_GET['doctor']) ? trim($_GET['doctor']) : '';
$date = isset($_GET['date']) ? trim($_GET['date']) : '';
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : 'GASTRO';

// バリデーション
if (empty($doctor)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => '医師名が指定されていません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($dept, ['PROCT', 'GASTRO'])) {
    $dept = 'GASTRO';
}

// ============================================================
// 3. パターン一覧取得（横持ち対応）
// ============================================================
// 基本パターン：医師名と診療科で絞り込み
$sql = "SELECT DISTINCT pattern_name 
        FROM m2_doctor_slot_pattern 
        WHERE doctor_name = :doctor 
          AND department_code = :dept 
          AND is_active = TRUE
        ORDER BY pattern_name";

$stmt = $pdo->prepare($sql);
$stmt->execute([':doctor' => $doctor, ':dept' => $dept]);
$patterns = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 日付が指定されている場合、その日の曜日も考慮
if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $day_of_week = (int)date('w', strtotime($date));
    
    // 曜日別パターンも取得（基本パターンに追加）
    $sql2 = "SELECT DISTINCT pattern_name 
             FROM m2_doctor_slot_pattern 
             WHERE doctor_name = :doctor 
               AND department_code = :dept 
               AND day_of_week = :dow
               AND is_active = TRUE
             ORDER BY pattern_name";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute([':doctor' => $doctor, ':dept' => $dept, ':dow' => $day_of_week]);
    $day_patterns = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    
    // 重複を排除してマージ
    $patterns = array_unique(array_merge($patterns, $day_patterns));
    sort($patterns);
}

// パターンが空の場合、デフォルトで「通常」を追加
if (empty($patterns)) {
    $patterns = ['通常'];
}

// ============================================================
// 4. JSON出力
// ============================================================
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'success' => true,
    'patterns' => $patterns,
    'doctor' => $doctor,
    'dept' => $dept,
    'date' => $date
], JSON_UNESCAPED_UNICODE);
exit;