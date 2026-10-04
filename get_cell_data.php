<?php
/**
 * 生成日時: 2026-08-05
 * 指定日・指定枠の予約データをJSONで返すAPI
 * month_matrix_v2.php のセル更新（リロードなし）用
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// ============================================================
// 1. 共通ライブラリ読み込み
// ============================================================
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';

// ============================================================
// 2. パラメータ取得
// ============================================================
$date = isset($_GET['date']) ? $_GET['date'] : '';
$waku = isset($_GET['waku']) ? $_GET['waku'] : '';
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : 'PROCT';

// バリデーション
if (empty($date) || empty($waku)) {
	header('Content-Type: application/json');
	echo json_encode(['error' => 'date and waku are required'], JSON_UNESCAPED_UNICODE);
	exit;
}

// 日付形式チェック（YYYY-MM-DD）
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
	header('Content-Type: application/json');
	echo json_encode(['error' => 'invalid date format'], JSON_UNESCAPED_UNICODE);
	exit;
}

if (!in_array($dept, ['PROCT', 'GASTRO'])) {
	$dept = 'PROCT';
}

// ============================================================
// 3. DB接続
// ============================================================
$pdo = getDbConnection();
if (!$pdo) {
	header('Content-Type: application/json');
	echo json_encode(['error' => 'database connection failed'], JSON_UNESCAPED_UNICODE);
	exit;
}

// ============================================================
// 4. データ取得（scheduled_time は前方一致で検索）
// ============================================================
// scheduled_time は「GIF12:00」「エコー9:00」「TCF13:30~14:30」のような形式
// そのため、waku_name（例：GIF）で前方一致検索を行う
$waku_pattern = $waku . '%';

$sql = "SELECT 
			s.schedule_id, s.exam_date, s.scheduled_time, s.department_code,
			s.patient_id, s.patient_name, s.patient_kana, s.birthday,
			s.doctor_name, s.exam_type, s.arrival_time, s.exam_location,
			s.remarks, s.is_canceled, s.booking_status,
			m.memo_today, m.memo_karte, m.memo_patient
		FROM t_endoscopy_schedule s
		LEFT JOIN t_mall_memo m ON s.patient_id = m.patient_id
		WHERE s.department_code = :dept
		  AND s.exam_date = :date
		  AND s.scheduled_time LIKE :waku_pattern
		  AND s.is_canceled = FALSE
		ORDER BY s.schedule_id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([':dept' => $dept, ':date' => $date, ':waku_pattern' => $waku_pattern]);
$rows = $stmt->fetchAll();

// ============================================================
// 5. JSON出力
// ============================================================
header('Content-Type: application/json; charset=utf-8');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
exit;