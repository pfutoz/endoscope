<?php
/**
 * 生成日時: 2026-08-14
 * get_pattern_details.php - パターン詳細取得API（横持ち対応）
 * 
 * パラメータ:
 *   - doctor: 医師名 (必須)
 *   - pattern: パターン名 (必須)
 *   - date: 日付 (YYYY-MM-DD) (任意)
 *   - dept: 診療科 (PROCT / GASTRO)
 * 
 * 戻り値:
 *   - success: boolean
 *   - data: { gif_slots, echo_slots, tcf_slots, pattern_name, total }
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
$pattern = isset($_GET['pattern']) ? trim($_GET['pattern']) : '';
$date = isset($_GET['date']) ? trim($_GET['date']) : '';
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : 'GASTRO';

// バリデーション
if (empty($doctor)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => '医師名が指定されていません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($pattern)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'パターン名が指定されていません'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($dept, ['PROCT', 'GASTRO'])) {
    $dept = 'GASTRO';
}

// ============================================================
// 3. パターン詳細取得（横持ち対応）
// ============================================================
$result = [
    'gif_slots' => 0,
    'echo_slots' => 0,
    'tcf_slots' => 0,
    'pattern_name' => $pattern,
    'total' => 0
];

// 曜日を決定（日付が指定されている場合）
$day_of_week = null;
if (!empty($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $day_of_week = (int)date('w', strtotime($date));
}

// ★★★ 横持ちで取得 ★★★
if ($day_of_week !== null) {
    // 特定曜日のパターンを優先
    $sql = "SELECT 
                MAX(CASE WHEN group_code = 'GIF' THEN max_slots ELSE 0 END) as gif_slots,
                MAX(CASE WHEN group_code = 'ECHO' THEN max_slots ELSE 0 END) as echo_slots,
                MAX(CASE WHEN group_code = 'TCF' THEN max_slots ELSE 0 END) as tcf_slots
            FROM m2_doctor_slot_pattern 
            WHERE doctor_name = :doctor 
              AND department_code = :dept 
              AND pattern_name = :pattern 
              AND day_of_week = :dow
              AND is_active = TRUE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':doctor' => $doctor,
        ':dept' => $dept,
        ':pattern' => $pattern,
        ':dow' => $day_of_week
    ]);
    $row = $stmt->fetch();
    
    if ($row && ($row['gif_slots'] > 0 || $row['echo_slots'] > 0 || $row['tcf_slots'] > 0)) {
        $result['gif_slots'] = (int)$row['gif_slots'];
        $result['echo_slots'] = (int)$row['echo_slots'];
        $result['tcf_slots'] = (int)$row['tcf_slots'];
        $result['total'] = $result['gif_slots'] + $result['echo_slots'] + $result['tcf_slots'];
    } else {
        // 特定曜日のパターンがない場合、全曜日のパターンを取得
        $sql = "SELECT 
                    MAX(CASE WHEN group_code = 'GIF' THEN max_slots ELSE 0 END) as gif_slots,
                    MAX(CASE WHEN group_code = 'ECHO' THEN max_slots ELSE 0 END) as echo_slots,
                    MAX(CASE WHEN group_code = 'TCF' THEN max_slots ELSE 0 END) as tcf_slots
                FROM m2_doctor_slot_pattern 
                WHERE doctor_name = :doctor 
                  AND department_code = :dept 
                  AND pattern_name = :pattern 
                  AND is_active = TRUE";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':doctor' => $doctor,
            ':dept' => $dept,
            ':pattern' => $pattern
        ]);
        $row = $stmt->fetch();
        if ($row) {
            $result['gif_slots'] = (int)$row['gif_slots'];
            $result['echo_slots'] = (int)$row['echo_slots'];
            $result['tcf_slots'] = (int)$row['tcf_slots'];
            $result['total'] = $result['gif_slots'] + $result['echo_slots'] + $result['tcf_slots'];
        }
    }
} else {
    // 日付が指定されていない場合（全曜日対象）
    $sql = "SELECT 
                MAX(CASE WHEN group_code = 'GIF' THEN max_slots ELSE 0 END) as gif_slots,
                MAX(CASE WHEN group_code = 'ECHO' THEN max_slots ELSE 0 END) as echo_slots,
                MAX(CASE WHEN group_code = 'TCF' THEN max_slots ELSE 0 END) as tcf_slots
            FROM m2_doctor_slot_pattern 
            WHERE doctor_name = :doctor 
              AND department_code = :dept 
              AND pattern_name = :pattern 
              AND is_active = TRUE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':doctor' => $doctor,
        ':dept' => $dept,
        ':pattern' => $pattern
    ]);
    $row = $stmt->fetch();
    if ($row) {
        $result['gif_slots'] = (int)$row['gif_slots'];
        $result['echo_slots'] = (int)$row['echo_slots'];
        $result['tcf_slots'] = (int)$row['tcf_slots'];
        $result['total'] = $result['gif_slots'] + $result['echo_slots'] + $result['tcf_slots'];
    }
}

// ============================================================
// 4. JSON出力
// ============================================================
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'success' => true,
    'data' => $result,
    'doctor' => $doctor,
    'pattern' => $pattern,
    'date' => $date,
    'dept' => $dept
], JSON_UNESCAPED_UNICODE);
exit;