<?php
/**
 * ファイル名: get_mall_soap.php
 * 概要: MALL3電子カルテ(SQL Server)からSOAP明細を取得するAPI
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

$patient_no  = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : '';
$target_date = isset($_GET['date']) ? trim($_GET['date']) : ''; // 任意指定（YYYY-MM-DD）

if ($patient_no === '' || !is_numeric($patient_no)) {
    echo json_encode(['success' => false, 'message' => 'IDが不正です'], JSON_UNESCAPED_UNICODE);
    exit;
}

// MALL3 (SQL Server) への安全接続設定
$serverName = "192.168.1.77"; 
$dbName     = "his00";
$uid        = "sa";
$pwd        = "Kochi-cl";

try {
    $dsn = "sqlsrv:server=$serverName;Database=$dbName;TrustServerCertificate=1;ApplicationIntent=ReadOnly;";
    $conn = new PDO($dsn, $uid, $pwd, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $conn->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '電子カルテDB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. 患者基本情報の取得
    $stmt_p = $conn->prepare("
        SELECT 
            PatientID, 
            PatientNo, 
            PatientName, 
            PatientKana, 
            Sex, 
            CONVERT(varchar(10), BirthDay, 120) AS BirthDayStr 
        FROM M_Patient 
        WHERE PatientNo = :patient_no
    ");
    $stmt_p->execute([':patient_no' => $patient_no]);
    $patient = $stmt_p->fetch();

    if (!$patient) {
        echo json_encode(['success' => false, 'message' => '該当患者なし'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $patient_id = (int)$patient['PatientID'];

    // 2. 受診履歴一覧（降順）を取得
    $stmt_history = $conn->prepare("
        SELECT DISTINCT 
            CONVERT(varchar(10), ConsultDate, 120) AS ConsultDateStr
        FROM T_Karte 
        WHERE PatientID = :patient_id 
        ORDER BY ConsultDateStr DESC
    ");
    $stmt_history->execute([':patient_id' => $patient_id]);
    $history_dates = $stmt_history->fetchAll(PDO::FETCH_COLUMN);

    if (empty($history_dates)) {
        echo json_encode([
            'success'       => true,
            'patient'       => [
                'patient_id'   => $patient['PatientID'],
                'patient_no'   => trim($patient['PatientNo']),
                'patient_name' => trim($patient['PatientName']),
                'patient_kana' => trim($patient['PatientKana'] ?? '')
            ],
            'current_date'  => '',
            'history_dates' => [],
            'records'       => [],
            'message'       => 'カルテ記録（受診歴）がありません。'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 指定日がない場合は直近の最新受診日
    if ($target_date === '' || !in_array($target_date, $history_dates, true)) {
        $target_date = $history_dates[0];
    }

    // 3. 対象日のSOAP明細を取得
    // ※ 同一(KarteID, KarteSNo, DataID)内で EditNo が最大の最新版のみを抽出
    $sql = "
        WITH LatestKarteS AS (
            SELECT 
                s.KarteSID,
                s.KarteID,
                s.KarteSNo,
                s.DataID,
                s.EditNo,
                s.Note,
                s.EditStaffID,
                s.EditDate,
                ROW_NUMBER() OVER (
                    PARTITION BY s.KarteID, s.KarteSNo, s.DataID 
                    ORDER BY s.EditNo DESC
                ) AS rn
            FROM T_KarteS s
            INNER JOIN T_Karte k ON s.KarteID = k.KarteID
            WHERE k.PatientID = :patient_id
              AND CAST(k.ConsultDate AS DATE) = CAST(:consult_date AS DATE)
        )
        SELECT 
            ls.KarteID,
            CONVERT(varchar(10), k.ConsultDate, 120) AS ConsultDate,
            ls.DataID,
            CASE ls.DataID 
                WHEN 1 THEN 'S' 
                WHEN 2 THEN 'O' 
                WHEN 3 THEN 'A' 
                WHEN 4 THEN 'P' 
                ELSE 'その他' 
            END AS SoapType,
            CONVERT(varchar(max), ls.Note) AS NoteText,
            CONVERT(varchar(19), ls.EditDate, 120) AS EditDateTime,
            st.StaffName AS DoctorName
        FROM LatestKarteS ls
        INNER JOIN T_Karte k ON ls.KarteID = k.KarteID
        LEFT JOIN M_Staff st ON ls.EditStaffID = st.StaffID
        WHERE ls.rn = 1
        ORDER BY ls.KarteID ASC, ls.KarteSNo ASC, ls.DataID ASC
    ";

    $stmt_soap = $conn->prepare($sql);
    $stmt_soap->execute([
        ':patient_id'   => $patient_id,
        ':consult_date' => $target_date
    ]);
    $raw_records = $stmt_soap->fetchAll();

    $records = [];
    foreach ($raw_records as $r) {
        $records[] = [
            'karte_id'      => (int)$r['KarteID'],
            'consult_date'  => $r['ConsultDate'],
            'soap_type'     => $r['SoapType'],
            'data_id'       => (int)$r['DataID'],
            'note'          => $r['NoteText'] !== null ? trim($r['NoteText']) : '',
            'edit_datetime' => $r['EditDateTime'] ?? '',
            'doctor_name'   => $r['DoctorName'] !== null ? trim($r['DoctorName']) : '不明'
        ];
    }

    echo json_encode([
        'success'       => true,
        'patient'       => [
            'patient_id'   => $patient['PatientID'],
            'patient_no'   => trim($patient['PatientNo']),
            'patient_name' => trim($patient['PatientName']),
            'patient_kana' => trim($patient['PatientKana'] ?? ''),
            'birthday'     => $patient['BirthDayStr'] ?? ''
        ],
        'current_date'  => $target_date,
        'history_dates' => $history_dates,
        'records'       => $records
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'クエリ実行不具合: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}