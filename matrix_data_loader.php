<?php
/**
 * 医療法人小野会 内視鏡予約システム V2 - データ読み込み・マッピング専門プロバイダ
 */

// 1. データベース接続設定 (内視鏡予約用 PostgreSQL)
$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

// 外部化した小野会専用カレンダーヘルパーを読み込み
require_once 'calendar_helper_onokai.php';

// 2. パラメーターの取得
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$dept  = isset($_GET['dept'])  ? trim($_GET['dept'])  : 'PROCT'; // 💡固定値判定（PROCT または GASTRO）
$show_canceled = isset($_GET['show_canceled']) && $_GET['show_canceled'] === '1';
$show_invalid  = isset($_GET['show_invalid'])  && $_GET['show_invalid'] === '1'; 
$selected_doctor = isset($_GET['doctor']) ? trim($_GET['doctor']) : ''; 

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = date('t', $first_day_timestamp);

$prev_month_ts = strtotime("-1 month", $first_day_timestamp);
$prev_year  = date('Y', $prev_month_ts); $prev_month = date('n', $prev_month_ts);
$next_month_ts = strtotime("+1 month", $first_day_timestamp);
$next_year  = date('Y', $next_month_ts); $next_month = date('n', $next_month_ts);

// 3. 💡【正規化】専用時間枠マスター（m2_waku）から現在の科の waku_name を自動ロード！
$waku_stmt = $pdo->prepare("
    SELECT waku_name 
    FROM m2_waku 
    WHERE department_code = :dept AND is_active = TRUE 
    ORDER BY display_order ASC
");
$waku_stmt->execute([':dept' => $dept]);
$waku_list = $waku_stmt->fetchAll(PDO::FETCH_COLUMN);

// 4. その他の選択肢を汎用マスタから取得
$doctors   = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'doctor' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$types     = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'exam_type' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$arrivals  = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'arrival_time' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$locations = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'exam_location' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);

// 5. 予約データ ＋ MALL同期メモのLEFT JOIN取得（s.patient_kanaおよびメモ3種を統合取得）[cite: 3, 4]
$data_sql = "SELECT 
                s.schedule_id, s.exam_date, s.scheduled_time, s.department_code, s.patient_id, s.patient_name, s.birthday,
                s.doctor_name, s.exam_type, s.arrival_time, s.exam_location, s.remarks, s.is_canceled, s.patient_kana,
                m.memo_today, m.memo_karte, m.memo_patient
             FROM t_endoscopy_schedule s
             LEFT JOIN t_mall_memo m ON s.patient_id = m.patient_id
             WHERE s.department_code = :dept";

if (!$show_canceled) { $data_sql .= " AND s.is_canceled = FALSE"; }
if ($selected_doctor !== '') { $data_sql .= " AND s.doctor_name = :selected_doctor"; }

$data_stmt = $pdo->prepare($data_sql);
$bind_params = [':dept' => $dept];
if ($selected_doctor !== '') { $bind_params[':selected_doctor'] = $selected_doctor; }
$data_stmt->execute($bind_params);
$raw_schedules = $data_stmt->fetchAll();

// 6. マトリクス構造の初期化とマッピング
$schedule_matrix = [];
for ($day = 1; $day <= $days_in_month; $day++) { $schedule_matrix[$day] = []; }
$schedule_matrix['UNKNOWN'] = [];

foreach ($raw_schedules as $row) {
    $exam_date = $row['exam_date'];
    $w_time    = $row['scheduled_time'];
    
    // 今ロードしたマスタ（waku_list）に登録されている正しい枠名文字列か厳密チェック
    $waku_key = (!empty($w_time) && in_array($w_time, $waku_list)) ? $w_time : 'MISMATCH';

    if (empty($exam_date) || !strtotime($exam_date)) {
        $schedule_matrix['UNKNOWN'][$waku_key][] = $row;
        continue;
    }
    $s_ts = strtotime($exam_date);
    if (date('Y-m', $s_ts) === sprintf('%04d-%02d', $year, $month)) {
        $d = (int)date('j', $s_ts);
        $schedule_matrix[$d][$waku_key][] = $row;
    }
}

// 年齢算出ヘルパー
if (!function_exists('calculateAgeOnExamDate')) {
    function calculateAgeOnExamDate($birthday, $examDate) {
        if (empty($birthday) || $birthday === '---' || $examDate === 'UNKNOWN') return '';
        try {
            $birth = new DateTime($birthday);
            $exam  = new DateTime($examDate);
            if ($birth > $exam) return '';
            return ' (' . $exam->diff($birth)->y . '歳)';
        } catch (Exception $e) { return ''; }
    }
}