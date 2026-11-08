<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\get_mall_patient.php
 * ファイル名: get_mall_patient.php
 * 概要: MALL3電子カルテ患者属性＆最終受診情報取得API
 * 
 * 修正履歴:
 *   - 2026-10-30: 【最終受診情報連携】本日以前の最新受診日（last_visit_date）、診療科（last_dept_name）、担当医（last_doctor_name）、本日受診フラグ（is_today_visit）を追加
 */
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
                P.PatientID,
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

    // 4. 本日以前の直近受診履歴（最終受診日・診療科・担当医）
    $stmt_visit = $conn->prepare("
        SELECT TOP 1
            CONVERT(varchar(10), k.ConsultDate, 120) AS LastVisitDate,
            c.ConsultCtrlName AS DeptName,
            st.StaffName AS DoctorName
        FROM T_Karte k WITH (NOLOCK)
        LEFT JOIN M_ConsultCtrl c WITH (NOLOCK) ON k.ConsultCtrlID = c.ConsultCtrlID
        LEFT JOIN M_Staff st WITH (NOLOCK) ON k.StaffID = st.StaffID
        WHERE k.PatientID = :pid
          AND k.ConsultDate <= CONVERT(date, GETDATE())
        ORDER BY k.ConsultDate DESC, k.KarteID DESC
    ");
    $stmt_visit->execute([':pid' => $patient['PatientID']]);
    $last_visit = $stmt_visit->fetch();

    $last_visit_date = $last_visit['LastVisitDate'] ?? null;
    $last_dept_name  = !empty($last_visit['DeptName']) ? trim($last_visit['DeptName']) : null;
    $last_doctor     = !empty($last_visit['DoctorName']) ? trim($last_visit['DoctorName']) : null;
    $is_today_visit  = ($last_visit_date === date('Y-m-d'));

    // 5. JSON返却
    echo json_encode([
        'success'          => true,
        'patient_id'       => (int)$patient['PatientID'],
        'patient_name'     => trim($patient['PatientName']),
        'patient_kana'     => isset($patient['PatientKana']) ? trim($patient['PatientKana']) : '',
        'birthday'         => !empty($patient['BirthDayStr']) ? $patient['BirthDayStr'] : '',
        'gender'           => $gender,
        'address'          => $clean_address,
        'tel'              => isset($patient['Tel']) ? trim($patient['Tel']) : '',
        'last_visit_date'  => $last_visit_date,
        'last_dept_name'   => $last_dept_name,
        'last_doctor_name' => $last_doctor,
        'is_today_visit'   => $is_today_visit
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'クエリ実行不具合: ' . $e->getMessage()]);
}