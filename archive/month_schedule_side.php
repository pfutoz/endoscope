<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\month_schedule_side.php
 * ファイル名: month_schedule_side.php
 * 
 * 内視鏡予約 ダッシュボード（左右2カラム・リサイズ拡張版）
 * 修正: 
 *   - 左ペイン（カレンダー側）の最大幅制限を緩和（最大900pxまで拡張）し、スプリット幅に応じて広く拡大できるように調整
 *   - カレンダーテーブルが左ペインの幅に完全追従してレイアウトが伸縮するように最適化
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 
        (string)($_SESSION['ui_state']['schedule_dept'] ?? 'PROCT');

$year = isset($_GET['year']) ? (int)$_GET['year'] : 
        (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr((string)$_SESSION['ui_state']['schedule_month'], 0, 4) : (int)date('Y'));
$month = isset($_GET['month']) ? (int)$_GET['month'] : 
         (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr((string)$_SESSION['ui_state']['schedule_month'], 5, 2) : (int)date('n'));

$selected_date = isset($_GET['date']) && is_string($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_doctor = isset($_GET['doctor']) && is_string($_GET['doctor']) ? trim($_GET['doctor']) : 'all';
$filter_exam   = isset($_GET['exam']) && is_string($_GET['exam']) ? trim($_GET['exam']) : 'all';
$sheet_single  = isset($_GET['sheet_single']) ? (int)$_GET['sheet_single'] : 0;
$display_mode  = isset($_GET['display_mode']) && is_string($_GET['display_mode']) ? $_GET['display_mode'] : 'use_max';

$show_empty = isset($_GET['show_empty']) ? ((string)$_GET['show_empty'] === '1') : true;

// ★ 枠なし設定・解除のDB更新処理
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $target_waku = $_GET['waku'] ?? '';
    $target_sid = (int)($_GET['sid'] ?? 0);
    
    if ($action === 'set_no_waku' && !empty($target_waku)) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO t_endoscopy_schedule 
                (department_code, exam_date, scheduled_time, waku_no, exam_type, exam_location, doctor_name, patient_name, remarks, is_canceled, created_at)
                SELECT 
                    :dept, 
                    :date, 
                    :waku, 
                    COALESCE(w.display_order, 1), 
                    COALESCE(w.group_code, 'GIF'), 
                    '指定なし',
                    '◆未定◆', 
                    '※枠なし', 
                    '＊＊＊🚫枠なし🚫＊＊＊', 
                    FALSE, 
                    CURRENT_TIMESTAMP
                FROM (SELECT 1) AS dummy
                LEFT JOIN m2_waku w 
                  ON w.department_code = :dept2 
                  AND w.waku_name = :waku2 
                  AND w.is_active = TRUE
            ");
            $ins->execute([
                ':dept'  => $dept,
                ':date'  => $selected_date,
                ':waku'  => $target_waku,
                ':dept2' => $dept,
                ':waku2' => $target_waku
            ]);
            header("Location: month_schedule_side.php?dept={$dept}&year={$year}&month={$month}&date={$selected_date}");
            exit;
        } catch (PDOException $e) {
            die("枠なし登録SQLエラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    } elseif ($action === 'revert_waku' && $target_sid > 0) {
        try {
            $del = $pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :sid AND department_code = :dept");
            $del->execute([':sid' => $target_sid, ':dept' => $dept]);
            header("Location: month_schedule_side.php?dept={$dept}&year={$year}&month={$month}&date={$selected_date}");
            exit;
        } catch (PDOException $e) {
            die("枠なし解除SQLエラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
}

if (!in_array($dept, ['PROCT', 'GASTRO'], true)) { $dept = 'PROCT'; }
if (!in_array($display_mode, ['use_max', 'remain', 'count'], true)) { $display_mode = 'use_max'; }

if (!isset($_SESSION['ui_state']) || !is_array($_SESSION['ui_state'])) {
    $_SESSION['ui_state'] = [];
}
$_SESSION['ui_state']['schedule_month'] = sprintf('%04d-%02d', $year, $month);
$_SESSION['ui_state']['schedule_dept'] = $dept;

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = (int)date('t', $first_day_timestamp);
$start_weekday = (int)date('w', $first_day_timestamp);

$start_date_month = sprintf('%04d-%02d-01', $year, $month);
$end_date_month   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$prev_month_ts = strtotime("-1 month", $first_day_timestamp);
$prev_year = (int)date('Y', $prev_month_ts);
$prev_month = (int)date('n', $prev_month_ts);
$next_month_ts = strtotime("+1 month", $first_day_timestamp);
$next_year = (int)date('Y', $next_month_ts);
$next_month = (int)date('n', $next_month_ts);

$quick_months = [];
$current_ts = strtotime(date('Y-m-01'));
for ($m_idx = 0; $m_idx < 12; $m_idx++) {
    $target_ts = strtotime("+$m_idx month", $current_ts);
    $q_year = (int)date('Y', $target_ts);
    $q_month = (int)date('n', $target_ts);
    $quick_months[] = [
        'year' => $q_year,
        'month' => $q_month,
        'is_active' => ($q_year === $year && $q_month === $month),
        'is_new_year' => ($q_month === 1 && $m_idx > 0)
    ];
}

$doctors_list = getDoctorsForDept($pdo, $dept);
if (!is_array($doctors_list)) { $doctors_list = []; }

function getMasterItems(PDO $pdo, string $category, array $default_items = []): array {
    try {
        $stmt = $pdo->prepare("
            SELECT item_value 
            FROM m_input_master 
            WHERE category = :cat 
            ORDER BY display_order ASC, master_id ASC
        ");
        $stmt->execute([':cat' => $category]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return (!empty($rows)) ? $rows : $default_items;
    } catch (Exception $e) {
        return $default_items;
    }
}
$exam_types_master = getMasterItems($pdo, 'exam_type', ['GIF', 'エコー', 'TCF', 'ポリペク', 'W', '◆胃がん検診◆']);

// 1. 月間確定予約数
$stmt_pref = $pdo->prepare("
    SELECT 
        exam_date::text AS exam_date,
        COALESCE(doctor_name, '') AS doctor_name,
        COALESCE(exam_type, '') AS exam_type,
        COUNT(*) AS cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date >= :start_date
      AND exam_date <= :end_date
      AND is_canceled = FALSE
      AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
    GROUP BY exam_date, doctor_name, exam_type
");
$stmt_pref->execute([
    ':dept' => $dept,
    ':start_date' => $start_date_month,
    ':end_date' => $end_date_month
]);

$month_reserved_cache = [];
while ($row = $stmt_pref->fetch(PDO::FETCH_ASSOC)) {
    $d = (string)$row['exam_date'];
    $doc = (string)$row['doctor_name'];
    $etype = (string)$row['exam_type'];
    $cnt = (int)$row['cnt'];
    if (!isset($month_reserved_cache[$d])) { $month_reserved_cache[$d] = []; }
    if (!isset($month_reserved_cache[$d][$doc])) { $month_reserved_cache[$d][$doc] = []; }
    $month_reserved_cache[$d][$doc][$etype] = $cnt;
}

// 2. 月間未割当予約データ
$stmt_unassigned = $pdo->prepare("
    SELECT 
        exam_date::text AS exam_date,
        COALESCE(exam_type, 'その他') AS exam_type,
        COUNT(*) AS cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date >= :start_date
      AND exam_date <= :end_date
      AND is_canceled = FALSE
      AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
      AND (scheduled_time IS NULL OR scheduled_time = '' OR scheduled_time = 'MISMATCH')
    GROUP BY exam_date, exam_type
");
$stmt_unassigned->execute([
    ':dept' => $dept,
    ':start_date' => $start_date_month,
    ':end_date' => $end_date_month
]);

$month_unassigned_cache = [];
while ($row = $stmt_unassigned->fetch(PDO::FETCH_ASSOC)) {
    $d = (string)$row['exam_date'];
    $etype = (string)($row['exam_type'] ?? 'その他');
    if (!isset($month_unassigned_cache[$d])) { $month_unassigned_cache[$d] = []; }
    $month_unassigned_cache[$d][$etype] = (int)$row['cnt'];
}

// 3. 枠マスター群プリフェッチ
$slot_master_cache = [
    'attendance' => [],
    'daily_capacity' => [],
    'patterns' => [],
    'waku_map' => []
];

$stmt_att = $pdo->prepare("
    SELECT doctor_name, target_date::text AS target_date, gif_slots, echo_slots, tcf_slots, reason, pattern_name
    FROM m2_doctor_attendance
    WHERE department_code = :dept
      AND target_date >= :start_date
      AND target_date <= :end_date
");
$stmt_att->execute([':dept' => $dept, ':start_date' => $start_date_month, ':end_date' => $end_date_month]);
while ($row = $stmt_att->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['attendance'][(string)$row['target_date']][(string)$row['doctor_name']] = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0),
        'reason' => (string)($row['reason'] ?? $row['pattern_name'] ?? '出勤例外')
    ];
}

$stmt_cap = $pdo->prepare("
    SELECT doctor_name, target_date::text AS target_date, gif_slots, echo_slots, tcf_slots, memo_jp
    FROM m2_doctor_daily_capacity_v2
    WHERE department_code = :dept
      AND target_date >= :start_date
      AND target_date <= :end_date
");
$stmt_cap->execute([':dept' => $dept, ':start_date' => $start_date_month, ':end_date' => $end_date_month]);
while ($row = $stmt_cap->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['daily_capacity'][(string)$row['target_date']][(string)$row['doctor_name']] = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0),
        'reason' => (string)($row['memo_jp'] ?? '日別上書き')
    ];
}

$stmt_pat = $pdo->prepare("
    SELECT 
        doctor_name, 
        day_of_week, 
        MAX(gif_slots) as gif_slots, 
        MAX(echo_slots) as echo_slots, 
        MAX(tcf_slots) as tcf_slots
    FROM m2_doctor_slot_pattern
    WHERE department_code = :dept
      AND is_active = TRUE
    GROUP BY doctor_name, day_of_week
");
$stmt_pat->execute([':dept' => $dept]);
while ($row = $stmt_pat->fetch(PDO::FETCH_ASSOC)) {
    $w_idx = (int)$row['day_of_week'];
    $slot_master_cache['patterns'][$w_idx][(string)$row['doctor_name']] = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0)
    ];
}

$stmt_waku = $pdo->prepare("
    SELECT waku_name, group_code
    FROM m2_waku
    WHERE department_code = :dept
      AND is_active = TRUE
    ORDER BY display_order ASC
");
$stmt_waku->execute([':dept' => $dept]);
while ($row = $stmt_waku->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['waku_map'][(string)$row['waku_name']] = (string)$row['group_code'];
}

function getDoctorBadgeHTML(string $doctor_name): string {
    $name = trim($doctor_name);
    if ($name === '' || $name === '◆未定◆') {
        return '<span class="doc-icon-badge default" title="担当未定">医</span>';
    }
    $char = mb_substr($name, 0, 1, 'UTF-8');
    $color_map = [
        '義' => 'bg-indigo',
        '二' => 'bg-emerald',
        '誠' => 'bg-amber',
        '哲' => 'bg-cyan',
        '太' => 'bg-rose',
    ];
    $cls = $color_map[$char] ?? 'bg-slate';
    return '<span class="doc-icon-badge ' . $cls . '" title="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($char, ENT_QUOTES, 'UTF-8') . '</span>';
}

function getDoctorStatusFromCache(string $doctor_name, string $date_str, int $w, array $master_cache): array {
    if (isset($master_cache['attendance'][$date_str][$doctor_name])) {
        $data = $master_cache['attendance'][$date_str][$doctor_name];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => true,
            'reason' => $data['reason'],
            'is_attendance' => true
        ];
    }
    if (isset($master_cache['daily_capacity'][$date_str][$doctor_name])) {
        $data = $master_cache['daily_capacity'][$date_str][$doctor_name];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => true,
            'reason' => $data['reason'],
            'is_attendance' => false
        ];
    }
    if (isset($master_cache['patterns'][$w][$doctor_name])) {
        $slots = $master_cache['patterns'][$w][$doctor_name];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => false,
            'reason' => null,
            'is_attendance' => false
        ];
    }
    return [
        'present' => false,
        'slots' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0],
        'is_exception' => false,
        'reason' => null,
        'is_attendance' => false
    ];
}

function getDoctorCountsFast(array $doctors, string $date_str, array $cache): array {
    $counts = [];
    $day_cache = $cache[$date_str] ?? [];
    foreach ($doctors as $doctor) {
        $doc_cache = $day_cache[$doctor] ?? [];
        $counts[$doctor] = is_array($doc_cache) ? array_sum($doc_cache) : 0;
    }
    return $counts;
}

function getDayDataFast(string $dept, string $date_str, int $w, array $doctors, array $reserved_cache, array $master_cache, string $filter_doctor = 'all', string $filter_exam = 'all'): array {
    $result = [];
    $total_used = 0;
    $total_max = 0;
    $gif_group = ['GIF', '◆胃がん検診◆', '胃がん検診'];
    $echo_group = ['ECHO', 'エコー'];
    $tcf_group = ['TCF', 'W', 'ポリペク'];
    
    $day_cache = $reserved_cache[$date_str] ?? [];

    foreach ($doctors as $doctor_name) {
        $status = getDoctorStatusFromCache($doctor_name, $date_str, $w, $master_cache);
        if ($filter_doctor !== 'all' && $filter_doctor !== $doctor_name) { continue; }
        
        $doc_exams = $day_cache[$doctor_name] ?? [];
        
        $used_gif = 0;
        foreach ($gif_group as $g_exam) { $used_gif += (int)($doc_exams[$g_exam] ?? 0); }
        $used_echo = 0;
        foreach ($echo_group as $e_exam) { $used_echo += (int)($doc_exams[$e_exam] ?? 0); }
        $used_tcf = 0;
        foreach ($tcf_group as $t_exam) { $used_tcf += (int)($doc_exams[$t_exam] ?? 0); }
        
        $has_reservation = ($used_gif > 0 || $used_echo > 0 || $used_tcf > 0);
        
        if (!$status['present'] && !$has_reservation) {
            continue;
        }
        
        $doctor_data = ['name' => $doctor_name, 'slots' => []];
        $has_any_slot = false;
        
        if ($filter_exam === 'all' || $filter_exam === 'GIF') {
            $max = (int)($status['slots']['GIF'] ?? 0);
            if ($max > 0 || $used_gif > 0) {
                $doctor_data['slots']['GIF'] = ['used' => $used_gif, 'max' => $max];
                $total_used += $used_gif;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($filter_exam === 'all' || $filter_exam === 'ECHO') {
            $max = (int)($status['slots']['ECHO'] ?? 0);
            if ($max > 0 || $used_echo > 0) {
                $doctor_data['slots']['ECHO'] = ['used' => $used_echo, 'max' => $max];
                $total_used += $used_echo;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($filter_exam === 'all' || $filter_exam === 'TCF') {
            $max = (int)($status['slots']['TCF'] ?? 0);
            if ($max > 0 || $used_tcf > 0) {
                $doctor_data['slots']['TCF'] = ['used' => $used_tcf, 'max' => $max];
                $total_used += $used_tcf;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($has_any_slot) {
            $result[] = $doctor_data;
        }
    }
    
    return ['doctors' => $result, 'total_used' => $total_used, 'total_max' => $total_max];
}

/**
 * MALL3 RTF構文変換
 */
function parseRtfToHtml(?string $rtf): string {
    if ($rtf === null || $rtf === '') return '';
    $text = trim($rtf);

    if (strpos($text, '{\\rtf') !== 0) {
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    $color_map = [0 => 'inherit'];
    if (preg_match('/{\\\\colortbl\\s*;?(.*?)}/s', $text, $m_col)) {
        $defs = explode(';', $m_col[1]);
        $idx = 1;
        foreach ($defs as $d) {
            $d = trim($d);
            if ($d === '') continue;
            if (preg_match('/\\\\red(\d+)\\\\green(\d+)\\\\blue(\d+)/', $d, $rgb)) {
                $color_map[$idx] = "rgb({$rgb[1]},{$rgb[2]},{$rgb[3]})";
                $idx++;
            }
        }
    }

    $text = preg_replace('/{\\\\\\*?\\\\fonttbl.*?}/s', '', $text);
    $text = preg_replace('/{\\\\\\*?\\\\colortbl.*?}/s', '', $text);
    $text = preg_replace('/{\\\\\\*?\\\\stylesheet.*?}/s', '', $text);
    $text = preg_replace('/{\\\\\\*?\\\\info.*?}/s', '', $text);

    $text = preg_replace('/^.*?\\\\viewkind\d+\\\\uc\d+/s', '', $text);
    $text = preg_replace('/^.*?\\\\pard/s', '', $text);

    $tokens = preg_split('/(\\\\[a-zA-Z0-9_-]+(?:\\s+)?|\\\\\'[0-9a-fA-F]{2}|\\\\par|\\\\tab|[{}]|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $html = '';
    $is_bold = false;
    $current_color = 'inherit';
    $sjis_buffer = '';

    $flush_sjis = function() use (&$sjis_buffer, &$html, &$is_bold, &$current_color) {
        if ($sjis_buffer === '') return;
        $utf8_str = mb_convert_encoding($sjis_buffer, 'UTF-8', 'CP932,SJIS,UTF-8');
        $escaped = htmlspecialchars($utf8_str, ENT_QUOTES, 'UTF-8');

        $style = '';
        if ($current_color !== 'inherit') $style .= "color: {$current_color};";
        if ($is_bold) $style .= "font-weight: bold;";

        if ($style !== '') {
            $html .= "<span style=\"{$style}\">{$escaped}</span>";
        } else {
            $html .= $escaped;
        }
        $sjis_buffer = '';
    };

    foreach ($tokens as $token) {
        if (preg_match('/^\\\\\'([0-9a-fA-F]{2})$/', $token, $m_hex)) {
            $sjis_buffer .= chr(hexdec($m_hex[1]));
            continue;
        }

        $flush_sjis();

        $lower_tok = strtolower(trim($token));

        if ($lower_tok === '\\par' || $token === "\n" || $token === "\r") {
            $html .= "<br>\n";
        } elseif ($lower_tok === '\\tab') {
            $html .= " &nbsp; &nbsp; ";
        } elseif ($lower_tok === '\\b') {
            $is_bold = true;
        } elseif ($lower_tok === '\\b0') {
            $is_bold = false;
        } elseif (preg_match('/^\\\\cf(\d+)$/', $lower_tok, $m_cf)) {
            $c_idx = (int)$m_cf[1];
            $current_color = $color_map[$c_idx] ?? 'inherit';
        } elseif ($token === '{' || $token === '}') {
            continue;
        } elseif (strpos($token, '\\') === 0) {
            continue;
        } else {
            $style = '';
            if ($current_color !== 'inherit') $style .= "color: {$current_color};";
            if ($is_bold) $style .= "font-weight: bold;";

            $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
            if ($style !== '') {
                $html .= "<span style=\"{$style}\">{$escaped}</span>";
            } else {
                $html .= $escaped;
            }
        }
    }

    $flush_sjis();
    return preg_replace('/(?:<br>\s*)+$/i', '', trim($html));
}

function renderRemarksRtfHtml(?string $raw): string {
    if ($raw === null || $raw === '') return '';
    $trimmed = trim($raw);
    
    // 枠なし専用デザイン
    if ($trimmed === '＊＊＊🚫枠なし🚫＊＊＊') {
        return '<span style="background-color:#fee2e2; color:#dc2626; padding:2px 6px; border-radius:3px; font-weight:bold;">＊＊＊🚫枠なし🚫＊＊＊</span>';
    }
    
    if (strpos($trimmed, '{\\rtf') === 0) {
        return '<div class="rich-remarks">' . parseRtfToHtml($trimmed) . '</div>';
    }
    
    if (preg_match('/<(?:p|br|strong|b|em|span|ul|ol|li)[^>]*>/i', $trimmed)) {
        $allowed = '<p><br><strong><b><em><span><ul><ol><li>';
        return '<div class="rich-remarks">' . strip_tags($trimmed, $allowed) . '</div>';
    }
    
    return '<div class="plain-remarks">' . nl2br(htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')) . '</div>';
}

function getDailyReservationsWithMismatch(PDO $pdo, string $dept, string $date_str, string $filter_doctor = 'all', string $filter_exam = 'all', bool $show_empty = true): array {
    if ($show_empty) {
        $sql = "
        WITH 
        all_slots AS (
            SELECT 
                waku_name,
                display_order,
                group_code
            FROM m2_waku
            WHERE department_code = :dept 
              AND is_active = TRUE
        ),
        reserved_data AS (
            SELECT 
                s.schedule_id,
                s.exam_date,
                s.scheduled_time,
                s.waku_no,
                s.patient_id,
                s.patient_name,
                s.patient_kana,
                s.birthday,
                s.doctor_name,
                s.exam_type,
                s.arrival_time,
                s.exam_location,
                s.remarks,
                s.room_number,
                s.bowel_prep,
                s.is_canceled,
                s.booking_status,
                s.department_code,
                w.display_order AS waku_display_order,
                w.group_code,
                CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
                FALSE AS is_empty
            FROM t_endoscopy_schedule s
            LEFT JOIN m2_waku w 
                ON s.department_code = w.department_code 
                AND s.scheduled_time = w.waku_name
                AND w.is_active = TRUE
            WHERE s.department_code = :dept 
              AND s.exam_date = :date 
              AND s.is_canceled = FALSE
        ";
        if ($filter_doctor !== 'all') { $sql .= " AND s.doctor_name = :doctor"; }
        if ($filter_exam !== 'all') { $sql .= " AND s.exam_type = :exam"; }
        $sql .= "
        )
        SELECT 
            COALESCE(r.scheduled_time, 'MISMATCH') AS scheduled_time,
            -1 AS waku_no,
            COALESCE(r.group_code, 'MISMATCH') AS group_code,
            COALESCE(r.patient_id, '') AS patient_id,
            COALESCE(r.patient_name, '') AS patient_name,
            COALESCE(r.patient_kana, '') AS patient_kana,
            COALESCE(r.birthday::text, '') AS birthday,
            COALESCE(r.doctor_name, '') AS doctor_name,
            COALESCE(r.exam_type, '') AS exam_type,
            COALESCE(r.arrival_time, '') AS arrival_time,
            COALESCE(r.exam_location, '') AS exam_location,
            COALESCE(r.remarks, '') AS remarks,
            COALESCE(r.room_number, '') AS room_number,
            COALESCE(r.bowel_prep, '') AS bowel_prep,
            COALESCE(r.schedule_id, 0) AS schedule_id,
            FALSE AS is_empty,
            TRUE AS is_mismatch,
            COALESCE(r.department_code, :dept) AS department_code
        FROM reserved_data r
        WHERE r.is_mismatch = TRUE

        UNION ALL

        SELECT 
            a.waku_name AS scheduled_time,
            a.display_order AS waku_no,
            a.group_code,
            COALESCE(r.patient_id, '') AS patient_id,
            COALESCE(r.patient_name, '') AS patient_name,
            COALESCE(r.patient_kana, '') AS patient_kana,
            COALESCE(r.birthday::text, '') AS birthday,
            COALESCE(r.doctor_name, '') AS doctor_name,
            COALESCE(r.exam_type, '') AS exam_type,
            COALESCE(r.arrival_time, '') AS arrival_time,
            COALESCE(r.exam_location, '') AS exam_location,
            COALESCE(r.remarks, '') AS remarks,
            COALESCE(r.room_number, '') AS room_number,
            COALESCE(r.bowel_prep, '') AS bowel_prep,
            COALESCE(r.schedule_id, 0) AS schedule_id,
            CASE WHEN r.schedule_id IS NULL THEN TRUE ELSE FALSE END AS is_empty,
            FALSE AS is_mismatch,
            COALESCE(r.department_code, :dept) AS department_code
        FROM all_slots a
        LEFT JOIN reserved_data r ON a.waku_name = r.scheduled_time
        ORDER BY is_mismatch DESC, waku_no ASC, schedule_id ASC;
        ";
    } else {
        $sql = "
        SELECT 
            s.*,
            w.display_order AS waku_display_order,
            COALESCE(w.group_code, 'MISMATCH') AS group_code,
            CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
            FALSE AS is_empty
        FROM t_endoscopy_schedule s
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
        WHERE s.department_code = :dept 
          AND s.exam_date = :date 
          AND s.is_canceled = FALSE
        ";
        if ($filter_doctor !== 'all') { $sql .= " AND s.doctor_name = :doctor"; }
        if ($filter_exam !== 'all') { $sql .= " AND s.exam_type = :exam"; }
        $sql .= " ORDER BY is_mismatch DESC, COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC";
    }

    $stmt = $pdo->prepare($sql);
    $params = [':dept' => $dept, ':date' => $date_str];
    if ($filter_doctor !== 'all') { $params[':doctor'] = $filter_doctor; }
    if ($filter_exam !== 'all') { $params[':exam'] = $filter_exam; }
    $stmt->execute($params);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($res) ? $res : [];
}

$dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';

if ($sheet_single > 0) {
    $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id AND department_code = :dept AND is_canceled = FALSE");
    $stmt->execute([':id' => $sheet_single, ':dept' => $dept]);
    $p = $stmt->fetch();
    
    if (!$p) { die("指定された予約が見つかりません。"); }
    
    $exam = (string)($p['exam_type'] ?? '');
    if ($exam === 'エコー' || $exam === 'ECHO') {
        die("エコーはシート印刷の対象外です。");
    }
    
    $exam_date = (string)($p['exam_date'] ?? '');
    $b_day = (string)($p['birthday'] ?? '');
    $age_str = calculateAge($b_day, $exam_date);
    $is_proct = ($p['department_code'] === 'PROCT');
    $wareki = convertToWareki($b_day);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>内視鏡検査シート</title>
        <style>
            @page { size: A4 portrait; margin: 10mm 12mm; }
            body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10pt; color: #000; background: #fff; }
            .sheet-page { padding-bottom: 10mm; }
            .sheet-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 8px; }
            .clinic-title { font-size: 14pt; font-weight: bold; }
            .dept-checkboxes { font-size: 10pt; font-weight: bold; }
            .sheet-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 9pt; }
            .sheet-table th, .sheet-table td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
            .sheet-table th { background: #f2f2f2; text-align: center; font-weight: bold; }
            .highlight-yellow { background: #ffff00 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .section-title { background: #e6e6e6; font-weight: bold; padding: 3px 8px; border: 1px solid #000; border-bottom: none; font-size: 9pt; margin-top: 6px; }
            .footer-line { display: flex; justify-content: space-between; font-size: 7pt; color: #555; margin-top: 5px; }
        </style>
    </head>
    <body>
        <div class="sheet-page">
            <div class="sheet-header">
                <div class="clinic-title">おの肛門科胃腸科内視鏡クリニック</div>
                <div class="dept-checkboxes">[ <?= $is_proct ? '☑' : '□' ?> ] 肛門科 &nbsp;&nbsp;&nbsp; [ <?= !$is_proct ? '☑' : '□' ?> ] 胃腸科</div>
            </div>
            <table class="sheet-table">
                <tr>
                    <th style="width:12%;">カルテ番号</th>
                    <td style="width:23%; font-weight:bold; font-size:12pt; text-align:center;" class="highlight-yellow"><?= htmlspecialchars((string)($p['patient_id'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td>
                    <th style="width:10%;">氏名</th>
                    <td style="width:35%; font-weight:bold; font-size:13pt;" class="highlight-yellow"><?= htmlspecialchars((string)($p['patient_name'] ?? '（氏名未登録）'), ENT_QUOTES, 'UTF-8') ?></td>
                    <th style="width:8%;">性別</th>
                    <td style="width:12%; text-align:center;"><?= htmlspecialchars((string)($p['gender'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>生年月日</th>
                    <td style="text-align:center;"><?= htmlspecialchars($wareki, ENT_QUOTES, 'UTF-8') ?></td>
                    <th>年齢</th>
                    <td style="text-align:center; font-weight:bold;"><?= $age_str ?></td>
                    <th>検査日</th>
                    <td style="text-align:center; font-weight:bold;"><?= htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><td style="width:50%;"><strong>付添：</strong>無 ・ 有（     ）<br><strong>迎え・タクシー・車：</strong>（      ）</td>
                    <td style="width:50%;"><strong>荷物：</strong>無 ・ 有（     ）<br><strong>内服：</strong>無 ・ 有（     ）<br><strong>TVセット・入院セット：</strong> 特( ) 個( ) 大( )</td></tr>
            </table>
            <div class="section-title">■ 前処置・下剤チェック欄</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><th style="width:35%;">薬剤名 / 処置</th><th style="width:15%;">数量・規格</th><th>実施状況・特記事項</th></tr>
                <tr><td>グリセリン浣腸液 50%</td><td style="text-align:center;">60ml / 120ml</td><td><span class="highlight-yellow" style="padding:2px 10px;">[ ] 実施 &nbsp;&nbsp;&nbsp; 時間：(  :  )</span></td></tr>
                <tr><td>高圧浣腸 グリセリン</td><td style="text-align:center;">100ml</td><td>[ ] 実施</td></tr>
                <tr><td>マグコロール散 68%</td><td style="text-align:center;">50g / 100g / 150g</td><td>服薬状況：(            )</td></tr>
                <tr><td>ニフレック / モビプレップ</td><td style="text-align:center;">1袋 / 2L</td><td>排便状況：(            )</td></tr>
            </table>
            <div class="section-title">■ 問診・安全確認事項</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><td style="width:50%;">・排便回数：昨日（ ）・今日（ ） 最終（   ）<br>・便秘： 無 ・ 有 / 難聴： 無 ・ 有<br>・感染症： HB（ ）・HC（ ）・RPR（ ）□未</td>
                    <td style="width:50%;">・抗凝固薬： □無 □有 （休薬： / ～ ）<br>・糖尿病薬： □無 □有 （休薬： / ～ ）<br>・インスリン：□無 □有 （休薬： / ～ ）<br>・シャント・乳腺手術：□無 □有（右・左）</td></tr>
                <tr><td colspan="2"><strong>当日内服・血圧：</strong> 血圧： 済 ・ 未 &nbsp; BP（   /   ） &nbsp;&nbsp; <strong>その他特記事項：</strong> <?= htmlspecialchars((string)($p['remarks'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            </table>
            <div class="section-title">■ 検査・薬剤投与記録（当日）</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><th style="width:35%;">項目</th><th style="width:20%; text-align:center;">予定 / 数量</th><th>実施記録・サイン</th></tr>
                <tr><td>検査種別</td><td style="text-align:center; font-weight:bold;" class="highlight-yellow"><?= htmlspecialchars((string)($p['exam_type'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td><td>場所: <strong><?= htmlspecialchars((string)($p['exam_location'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></strong> / 担当医: <strong><?= htmlspecialchars((string)($p['doctor_name'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></strong></td></tr>
                <tr><td>プロフォール 1％</td><td style="text-align:center;"> mg</td><td></td></tr>
                <tr><td>ジアゼパム 10mg</td><td style="text-align:center;"> ml</td><td></td></tr>
                <tr><td>ブスコパン 20mg / グルカゴン</td><td style="text-align:center;"> 1ml / 1V</td><td>採血：あり ・ なし / アル綿禁：[ ]</td></tr>
            </table>
            <div class="section-title">■ 所見・メモ・特記事項</div>
            <div style="border:1px solid #000; height:30mm; padding:4px; font-size:8pt;">
                <?php if (!empty($p['remarks'])): ?>予約時備考: <?= renderRemarksRtfHtml((string)$p['remarks']) ?><?php endif; ?>
            </div>
            <div class="footer-line">
                <div>スケジュールID: #<?= (int)$p['schedule_id'] ?> (枠: <?= htmlspecialchars((string)($p['scheduled_time'] ?? '---'), ENT_QUOTES, 'UTF-8') ?>)</div>
                <div>おの肛門科胃腸科内視鏡クリニック 診療記録シート</div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$sel_ts = strtotime($selected_date);
$sel_w = (int)date('w', $sel_ts);

$w_map = ['日', '月', '火', '水', '木', '金', '土'];
$day_of_week_str = '(' . $w_map[$sel_w] . ')';

$sel_year = (int)date('Y', $sel_ts);
$sel_month = (int)date('n', $sel_ts);
$is_same_month = ($sel_year === $year && $sel_month === $month);

if ($is_same_month) {
    $day_data = getDayDataFast($dept, $selected_date, $sel_w, $doctors_list, $month_reserved_cache, $slot_master_cache, $filter_doctor, $filter_exam);
    $doctor_counts = getDoctorCountsFast($doctors_list, $selected_date, $month_reserved_cache);
    $list_data = getDailyReservationsWithMismatch($pdo, $dept, $selected_date, 'all', 'all', $show_empty);
} else {
    $day_data = ['doctors' => [], 'total_used' => 0, 'total_max' => 0];
    $doctor_counts = [];
    $list_data = [];
}

$room_counts = [];
foreach ($list_data as $r_item) {
    if (empty($r_item['is_empty'])) {
        $rm = trim((string)($r_item['room_number'] ?? ''));
        if ($rm !== '' && $rm !== '未指定' && $rm !== '-') {
            $room_counts[$rm] = ($room_counts[$rm] ?? 0) + 1;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内視鏡予約 ダッシュボード (リサイズ対応横並び)</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 6px 8px;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 8px 10px;
        }

        .top-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
            background: #ffffff;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        .top-left-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .top-title-area { display: flex; align-items: center; gap: 6px; }
        .top-title-area h2 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; letter-spacing: -0.02em; }
        .header-badge { font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 9999px; background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .dept-tabs-inline { display: inline-flex; gap: 2px; background: #e2e8f0; padding: 2px; border-radius: 5px; }
        .dept-tab-inline { padding: 2px 10px; text-decoration: none; font-weight: 700; border-radius: 3px; color: #64748b; font-size: 11px; transition: all 0.2s; }
        .dept-tab-inline.active-PROCT { background: #3b82f6; color: #ffffff; }
        .dept-tab-inline.active-GASTRO { background: #10b981; color: #ffffff; }

        .header-nav { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
        .nav-btn { display: inline-flex; align-items: center; gap: 3px; padding: 4px 10px; border-radius: 5px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid transparent; cursor: pointer; }
        .nav-btn-home { background-color: #f8fafc; color: #475569; border-color: #cbd5e1; }
        .nav-btn-home:hover { background-color: #f1f5f9; color: #1e293b; border-color: #94a3b8; }
        .nav-btn-matrix { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #ffffff; }
        .nav-btn-matrix:hover { background: linear-gradient(135deg, #1d4ed8, #1e40af); }
        .nav-btn-search { background: linear-gradient(135deg, #0d9488, #0f766e); color: #ffffff; }
        .nav-btn-search:hover { background: linear-gradient(135deg, #0f766e, #115e59); }

        .filters-compact {
            background: #ffffff;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .filter-row-inline { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .filter-group-inline { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .filter-group-inline .filter-label { font-weight: 700; font-size: 11px; color: #475569; }
        .filter-toggle {
            padding: 2px 8px; border: 1.5px solid #e2e8f0; border-radius: 10px; background: #fff; color: #475569; font-size: 10.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; text-decoration: none;
        }
        .filter-toggle:hover { background: #f1f5f9; }
        .filter-toggle.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .filter-toggle.active-all { background: #1e293b; color: #fff; border-color: #1e293b; }
        .filter-toggle .badge { background: #e2e8f0; color: #475569; font-size: 8.5px; padding: 0 4px; border-radius: 6px; }
        .filter-toggle.active .badge, .filter-toggle.active-all .badge { background: rgba(255,255,255,0.25); color: #fff; }

        .display-mode-toggle { display: flex; gap: 2px; background: #f1f5f9; border-radius: 5px; padding: 2px; border: 1px solid #e2e8f0; }
        .display-mode-toggle .mode-btn { padding: 2px 8px; border: none; border-radius: 3px; font-size: 10.5px; font-weight: 700; cursor: pointer; background: transparent; color: #64748b; text-decoration: none; }
        .display-mode-toggle .mode-btn:hover { background: #e2e8f0; }
        .display-mode-toggle .mode-btn.active { background: #3b82f6; color: #fff; }

        /* ==========================================================
         * 💡 左右分割 ＆ ドラッグリサイズ設定（最大幅制限を900pxに拡張）
         * ========================================================== */
        .workspace-split {
            display: flex;
            align-items: stretch;
            width: 100%;
            position: relative;
        }
        .calendar-column {
            width: 440px; /* 初期幅 */
            min-width: 360px;
            max-width: 900px; /* 💡 制限を大幅に緩和して広く広げられるように変更 */
            flex-shrink: 0;
        }
        .resizer {
            width: 8px;
            cursor: col-resize;
            background-color: #e2e8f0;
            border-radius: 4px;
            margin: 0 4px;
            transition: background-color 0.2s;
            position: relative;
            flex-shrink: 0;
        }
        .resizer:hover, .resizer.resizing {
            background-color: #3b82f6;
        }
        .list-column {
            flex: 1;
            min-width: 0;
        }

        .calendar-wrapper {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            overflow-x: auto;
            transition: all 0.3s ease;
        }
        .calendar-wrapper.collapsed { display: none; }
        .cal-toggle-bar { display: flex; justify-content: flex-end; margin-bottom: 6px; }
        .cal-toggle-btn {
            background: #f1f5f9; border: 1px solid #cbd5e1; color: #334155; padding: 3px 10px; border-radius: 5px; font-size: 11px; font-weight: 700; cursor: pointer; transition: background 0.15s; display: inline-flex; align-items: center; gap: 3px;
        }
        .cal-toggle-btn:hover { background: #e2e8f0; }

        .cal-nav-bar {
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9;
        }
        .cal-primary-controls { display: flex; align-items: center; gap: 6px; }
        .cal-title { font-size: 15px; font-weight: 800; color: #0f172a; white-space: nowrap; }
        .cal-btn-group { display: inline-flex; border: 1px solid #cbd5e1; border-radius: 5px; overflow: hidden; background: #f8fafc; }
        .cal-nav-arrow { padding: 2px 6px; background: #ffffff; color: #334155; text-decoration: none; font-size: 10.5px; font-weight: bold; border: none; cursor: pointer; }
        .cal-nav-arrow:hover { background: #e2e8f0; }
        .cal-nav-today { padding: 2px 8px; background: #ffffff; color: #2563eb; text-decoration: none; font-size: 10.5px; font-weight: 700; border-left: 1px solid #cbd5e1; border-right: 1px solid #cbd5e1; display: inline-flex; align-items: center; gap: 2px; }
        .cal-nav-today:hover { background: #eff6ff; }

        .cal-quick-months { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; background: #f8fafc; padding: 2px 4px; border-radius: 5px; border: 1px solid #e2e8f0; }
        .quick-month-label { font-size: 9.5px; font-weight: 700; color: #64748b; margin-right: 1px; white-space: nowrap; }
        .month-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 20px; padding: 0 3px; border-radius: 3px; font-size: 9.5px; font-weight: 600; text-decoration: none; color: #475569; background: #ffffff; border: 1px solid #cbd5e1; }
        .month-pill:hover { background: #f1f5f9; border-color: #94a3b8; color: #0f172a; }
        .month-pill.active { background: #2563eb; border-color: #2563eb; color: #ffffff; font-weight: 700; }
        .month-pill.year-edge { border-left: 1.5px solid #94a3b8; }

        .cal-table { width: 100%; margin: 0; table-layout: fixed; border-collapse: collapse; border: 1.5px solid #cbd5e1; }
        .cal-table th:first-child, .cal-table td:first-child { width: 7.5% !important; }
        .cal-table th:not(:first-child), .cal-table td:not(:first-child) { width: 15.4% !important; }

        .cal-table th, .cal-table td { box-sizing: border-box; border: 1px solid #cbd5e1; text-align: center; vertical-align: top; padding: 2px 3px; }
        .cal-table th { background: #f8fafc; font-weight: 800; font-size: 10.5px; padding: 3px; color: #0f172a; }
        .cal-table .day-cell { cursor: pointer; min-height: 72px; height: 82px; overflow: hidden; background: #ffffff; }
        .cal-table .day-cell:hover { background: #f8fafc; }
        .cal-table .day-cell.has-available { background-color: #fefce8 !important; }
        .cal-table .day-cell.past { background-color: #f1f5f9 !important; opacity: 0.85; color: #64748b; cursor: not-allowed; }
        .cal-table .day-cell.closed { background-color: #fff1f2 !important; color: #e11d48; }
        .cal-table .day-cell.today { background-color: #fef9c3 !important; }
        .cal-table .day-cell.selected { outline: 3px double #1d4ed8 !important; outline-offset: -2px; position: relative; z-index: 10; }
        .cal-table .day-cell.has-w-over { background-color: #ffcc80 !important; border: 2.5px solid #e65100 !important; }
        .cal-table .day-cell.has-total-negative { border: 2px solid #dc2626 !important; }
        .cal-table .day-cell.has-total-exact { border: 2px solid #2563eb !important; }
        .cal-table .day-cell .day-number { font-weight: 800; font-size: 12px; display: block; margin-bottom: 1px; color: #0f172a; }

        .date-num-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 19px; height: 19px; box-sizing: border-box; }
        .date-num-badge.has-exception { width: 20px; height: 20px; border: 1.5px solid #2563eb; background-color: #eff6ff; color: #1d4ed8 !important; border-radius: 50%; font-weight: 800; }

        .cal-table .day-cell .day-slots { line-height: 1.3; text-align: left; padding: 1px; max-height: 48px; overflow-y: auto; }
        .cal-table .day-cell .doctor-slot-row { display: flex; align-items: center; gap: 3px; margin-top: 1px; padding: 0.5px 0; border-bottom: 1px dashed #e2e8f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .doc-icon-badge { display: inline-flex; align-items: center; justify-content: center; width: 15px; height: 15px; min-width: 15px; border-radius: 50%; font-size: 9px; font-weight: 800; color: #ffffff; line-height: 1; flex-shrink: 0; }
        .doc-icon-badge.bg-indigo  { background-color: #4f46e5; }
        .doc-icon-badge.bg-emerald { background-color: #059669; }
        .doc-icon-badge.bg-amber   { background-color: #d97706; }
        .doc-icon-badge.bg-cyan    { background-color: #0891b2; }
        .doc-icon-badge.bg-rose    { background-color: #e11d48; }
        .doc-icon-badge.bg-slate   { background-color: #475569; }

        .cal-table .day-cell .slot-item { display: inline-flex; align-items: center; gap: 1px; font-size: 9px; font-weight: 800; color: #0f172a; margin-right: 1px; }
        @keyframes floatScale { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .cal-table .day-cell .slot-item.negative { background-color: #fee2e2; border: 1px solid #ef4444; color: #dc2626 !important; border-radius: 2px; padding: 0 2px; animation: floatScale 1.2s ease-in-out infinite; }
        .cal-table .day-cell .slot-item.exact { background-color: #eff6ff; border: 1px solid #3b82f6; color: #1d4ed8 !important; border-radius: 2px; padding: 0 2px; }
        .cal-table .day-cell .slot-item.full { color: #dc2626 !important; }

        .cal-table .day-cell .day-available { font-size: 8px; font-weight: 800; margin-top: 2px; text-align: center; padding: 0.5px 3px; border-radius: 3px; background: #f1f5f9; color: #0f172a; }
        .cal-table .day-cell .day-available.avail-green { color: #15803d; background: #dcfce7; border: 1px solid #bbf7d0; }
        .cal-table .day-cell .empty-cell-text { font-size: 9px; font-weight: bold; color: #94a3b8; padding-top: 18px; }

        .list-wrapper { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; }
        .list-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9; gap: 6px; }
        .list-title { font-weight: 700; font-size: 12.5px; color: #0f172a; }
        .list-actions { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
        
        .list-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 11.5px; }
        .list-table th, .list-table td { border: 1px solid #e2e8f0; padding: 4px 6px; text-align: left; overflow: hidden; word-break: break-all; }
        .list-table th { background: #f8fafc; font-weight: 700; font-size: 10.5px; color: #475569; }
        .list-table tr:hover { background: #f8fafc; }
        .list-table .empty-msg { text-align: center; color: #94a3b8; padding: 15px; }
        .list-table tr.mismatch-row { background-color: #fef2f2 !important; border-left: 3px solid #dc2626 !important; }

        .exam-badge { display: inline-block; padding: 1px 5px; border-radius: 8px; font-weight: bold; font-size: 9.5px; }
        .exam-badge.gif { background: #0ea5e9; color: #fff; }
        .exam-badge.echo { background: #8b5cf6; color: #fff; }
        .exam-badge.tcf { background: #10b981; color: #fff; }
        .exam-badge.other { background: #e2e8f0; color: #475569; }

        .room-badge { display: inline-block; padding: 1px 4px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 3px; font-size: 10px; font-weight: 700; white-space: nowrap; }
        .room-badge.room-duplicate { background: #fee2e2 !important; color: #dc2626 !important; border: 1.5px solid #ef4444 !important; font-weight: 800 !important; }

        .prep-badge { display: inline-block; padding: 1px 4px; border-radius: 3px; font-size: 9px; font-weight: 800; color: #ffffff; white-space: nowrap; }
        .prep-badge.prep-mobi { background-color: #2563eb; }
        .prep-badge.prep-magp { background-color: #ea580c; }
        .prep-badge.prep-nif  { background-color: #16a34a; }

        .age-box { display: inline-block; color: #ffffff !important; font-size: 9px; font-weight: 800; padding: 1px 3px; border-radius: 3px; margin-left: 3px; vertical-align: middle; white-space: nowrap; }
        .age-box.age-senior { background-color: #ea580c; }
        .age-box.age-under  { background-color: #2563eb; }

        .rich-remarks { font-size: 10.5px; line-height: 1.3; color: #334155; word-break: break-all; }
        .rich-remarks p { margin: 0; padding: 0; }
        .plain-remarks { margin: 0; padding: 0; font-size: 10.5px; color: #475569; word-break: break-all; }

        .sheet-btn-small { display: inline-block; padding: 2px 5px; background: #8b5cf6; color: #fff; border-radius: 3px; text-decoration: none; font-size: 8.5px; font-weight: bold; border: none; cursor: pointer; }
        .sheet-btn-small:hover { background: #7c3aed; }
        .sheet-btn-small.followup { background: #8b5cf6; margin-left: 2px; }

        .slot-filter-label { cursor: pointer; padding: 2px 5px; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #fff; font-size: 10.5px; display: inline-flex; align-items: center; gap: 2px; user-select: none; }
        .slot-filter-label input[type="checkbox"] { margin: 0; cursor: pointer; width: 11px; height: 11px; }
        .color-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; }
        .color-dot.gif { background: #93c5fd; }
        .color-dot.echo { background: #f0abfc; }
        .color-dot.tcf { background: #86efac; }

        .empty-slot-label { cursor: pointer; padding: 2px 6px; border-radius: 4px; border: 1.5px solid #8b5cf6; background: #f5f3ff; color: #6d28d9; font-size: 10.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 3px; user-select: none; }
        .empty-slot-label input[type="checkbox"] { margin: 0; cursor: pointer; width: 11px; height: 11px; accent-color: #8b5cf6; }

        .summary-btn-modern { display: inline-flex; align-items: center; gap: 2px; background: #2563eb; color: #fff; padding: 3px 6px; border-radius: 4px; text-decoration: none; font-size: 10.5px; font-weight: 600; border: none; cursor: pointer; }
        .summary-btn-modern:hover { background: #1d4ed8; }

        .print-btn-modern { display: inline-flex; align-items: center; gap: 2px; background: #10b981; color: #fff; padding: 3px 6px; border-radius: 4px; text-decoration: none; font-size: 10.5px; font-weight: 600; border: none; cursor: pointer; }
        .print-btn-modern:hover { background: #059669; }

        .icon-btn { text-decoration: none; font-size: 13px; cursor: pointer; display: inline-block; width: 20px; text-align: center; line-height: 1.2; }
        .icon-btn:hover { transform: scale(1.15); }
        .icon-btn.add { color: #22c55e; }
        .icon-btn.edit { color: #2563eb; }
        .icon-btn.disabled { color: #cbd5e1; cursor: default; pointer-events: none; }

        @media (max-width: 1100px) {
            .workspace-split { flex-direction: column; }
            .calendar-column { width: 100% !important; max-width: 100%; min-width: 100%; }
            .resizer { display: none; }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="top-toolbar">
        <div class="top-left-group">
            <div class="top-title-area">
                <h2>📅 内視鏡予約 ダッシュボード</h2>
                <span class="header-badge">予約管理・枠振分 (リサイズ対応横並び)</span>
            </div>
            <div class="dept-tabs-inline">
                <a href="?dept=PROCT&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab-inline <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛門科</a>
                <a href="?dept=GASTRO&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab-inline <?=$dept==='GASTRO'?'active-GASTRO':''?>">🔵 胃腸科</a>
            </div>
        </div>
        <div class="header-nav">
            <a href="index.php" class="nav-btn nav-btn-home"><span>🏠</span> メニュー</a>
            <a href="month_matrix_v2.php?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>" class="nav-btn nav-btn-matrix"><span>📊</span> マトリクス</a>
            <a href="slot_search.php?dept=<?=$dept?>" class="nav-btn nav-btn-search"><span>🔍</span> 空き枠検索</a>
        </div>
    </div>

    <div class="filters-compact">
        <div class="filter-row-inline">
            <div class="filter-group-inline">
                <span class="filter-label">👨‍⚕️ 医師:</span>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=all&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor==='all'?'active-all':''?>">全医師</a>
                <?php foreach ($doctors_list as $doc): 
                    $doc_str = (string)$doc;
                ?>
                    <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($doc_str)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor===$doc_str?'active':''?>">
                        <?=htmlspecialchars($doc_str, ENT_QUOTES, 'UTF-8')?> <span class="badge"><?=(int)($doctor_counts[$doc_str] ?? 0)?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="filter-group-inline" style="margin-left: 6px;">
                <span class="filter-label">🔬 種別:</span>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=all&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='all'?'active-all':''?>">全種別</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=GIF&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='GIF'?'active':''?>">🔵 GIF</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=ECHO&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='ECHO'?'active':''?>">🟠 エコー</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=TCF&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='TCF'?'active':''?>">🟢 TCF</a>
            </div>
        </div>

        <div class="filter-group-inline">
            <span class="filter-label">📊 表示:</span>
            <div class="display-mode-toggle">
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=use_max&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='use_max'?'active':''?>">📊 使用/最大</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=remain&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='remain'?'active':''?>">📉 残数</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=$filter_exam?>&display_mode=count&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='count'?'active':''?>">🔢 検査数</a>
            </div>
        </div>
    </div>

    <!-- ==========================================================
     * 💡 左右分割 ＆ ドラッグリサイズ対応ワークスペース
     * ========================================================== -->
    <div class="workspace-split" id="workspaceSplit">

        <!-- 左カラム：カレンダー部分 -->
        <div class="calendar-column" id="calendarColumn">
            <div class="cal-toggle-bar">
                <button type="button" class="cal-toggle-btn" onclick="toggleCalendar()">🔼 カレンダー格納</button>
            </div>

            <div class="calendar-wrapper" id="calendarWrapper">
                <div class="cal-nav-bar">
                    <div class="cal-primary-controls">
                        <span class="cal-title"><?=$year?>年<?=$month?>月</span>
                        <div class="cal-btn-group">
                            <a href="?dept=<?=$dept?>&year=<?=$prev_year?>&month=<?=$prev_month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-arrow" title="前月">◀</a>
                            <a href="?dept=<?=$dept?>&year=<?=date('Y')?>&month=<?=date('n')?>&date=<?=date('Y-m-d')?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-today">📅今日</a>
                            <a href="?dept=<?=$dept?>&year=<?=$next_year?>&month=<?=$next_month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-arrow" title="次月">▶</a>
                        </div>
                    </div>

                    <div class="cal-quick-months">
                        <span class="quick-month-label">月:</span>
                        <?php foreach ($quick_months as $qm): ?>
                            <?php 
                                $pill_class = 'month-pill' . ($qm['is_active'] ? ' active' : '') . ($qm['is_new_year'] ? ' year-edge' : '');
                                $pill_label = $qm['is_new_year'] ? ("'" . substr((string)$qm['year'], 2) . '/' . $qm['month']) : $qm['month'];
                            ?>
                            <a href="?dept=<?=$dept?>&year=<?=$qm['year']?>&month=<?=$qm['month']?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" 
                               class="<?=$pill_class?>" 
                               title="<?=$qm['year']?>年<?=$qm['month']?>月">
                                <?=$pill_label?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <table class="cal-table">
                    <thead>
                        <tr>
                            <th style="color:#ef4444;">日</th>
                            <th>月</th>
                            <th>火</th>
                            <th>水</th>
                            <th style="color:#1e40af;">木</th>
                            <th>金</th>
                            <th>土</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                        <?php
                        for ($i = 0; $i < $start_weekday; $i++) {
                            echo "<td style='background:#fafafa;'></td>";
                        }

                        $today_str = date('Y-m-d');
                        for ($day = 1; $day <= $days_in_month; $day++) {
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                            $ts = strtotime($date_str);
                            $w = (int)date('w', $ts);
                            $day_check = check_onokai_calendar($date_str, null);
                            $is_closed = $day_check['is_closed'];
                            $is_today = ($date_str === $today_str);
                            $is_selected = ($date_str === $selected_date);
                            $is_past = ($date_str < $today_str);

                            $has_exception = !empty($slot_master_cache['attendance'][$date_str]) || !empty($slot_master_cache['daily_capacity'][$date_str]);

                            $has_reservation_on_closed = false;
                            if ($is_closed && isset($month_reserved_cache[$date_str]) && is_array($month_reserved_cache[$date_str])) {
                                $count = 0;
                                foreach ($month_reserved_cache[$date_str] as $doc_counts) {
                                    $count += is_array($doc_counts) ? array_sum($doc_counts) : 0;
                                }
                                $has_reservation_on_closed = ($count > 0);
                            }

                            $w_count_total = 0;
                            if ($dept === 'PROCT' && isset($month_reserved_cache[$date_str]) && is_array($month_reserved_cache[$date_str])) {
                                foreach ($month_reserved_cache[$date_str] as $doc_exams) {
                                    if (is_array($doc_exams)) {
                                        $w_count_total += (int)($doc_exams['W'] ?? 0);
                                    }
                                }
                            }
                            $is_w_over = ($dept === 'PROCT' && !$is_closed && $w_count_total >= 3);

                            $day_data_cell = getDayDataFast($dept, $date_str, $w, $doctors_list, $month_reserved_cache, $slot_master_cache, $filter_doctor, $filter_exam);
                            
                            if (!$is_closed) {
                                if ($day_data_cell['total_max'] > 0) {
                                    $has_available = ($day_data_cell['total_used'] < $day_data_cell['total_max']);
                                }
                            }

                            $tot_used_check = 0;
                            $tot_max_check = 0;
                            foreach ($day_data_cell['doctors'] as $d) {
                                foreach (['GIF', 'ECHO', 'TCF'] as $ex) {
                                    if (isset($d['slots'][$ex])) {
                                        $tot_used_check += (int)$d['slots'][$ex]['used'];
                                        $tot_max_check += (int)$d['slots'][$ex]['max'];
                                    }
                                }
                            }
                            $has_total_negative = (!$is_closed && $tot_max_check > 0 && $tot_used_check > $tot_max_check);
                            $has_total_exact = (!$is_closed && $tot_max_check > 0 && $tot_used_check === $tot_max_check && !$has_total_negative);

                            $cell_class = 'day-cell';
                            if ($is_selected) $cell_class .= ' selected';
                            if ($is_today) $cell_class .= ' today';
                            if ($is_closed) $cell_class .= ' closed';
                            if ($is_past) $cell_class .= ' past';
                            if ($is_w_over) $cell_class .= ' has-w-over';
                            
                            if ($has_total_negative) {
                                $cell_class .= ' has-total-negative';
                            } elseif ($has_total_exact) {
                                $cell_class .= ' has-total-exact';
                            }

                            if (isset($has_available) && $has_available && !$is_past && !$is_closed) $cell_class .= ' has-available';
                            if ($has_reservation_on_closed) {
                                $cell_class .= ' has-reservation-on-closed';
                            }

                            $unassigned_html = isset($month_unassigned_cache[$date_str]) ? renderUnassignedBadgeHTML($month_unassigned_cache[$date_str]) : '';

                            $has_data = $day_data_cell['total_max'] > 0 || !empty($day_data_cell['doctors']);
                            $avail_class = 'avail-closed';
                            $avail_text = '';
                            if (!$is_closed && $has_data && $display_mode !== 'count') {
                                $total_gif_used = 0; $total_gif_max = 0;
                                $total_echo_used = 0; $total_echo_max = 0;
                                $total_tcf_used = 0; $total_tcf_max = 0;
                                foreach ($day_data_cell['doctors'] as $d) {
                                    if (isset($d['slots']['GIF'])) {
                                        $total_gif_used += (int)$d['slots']['GIF']['used'];
                                        $total_gif_max += (int)$d['slots']['GIF']['max'];
                                    }
                                    if (isset($d['slots']['ECHO'])) {
                                        $total_echo_used += (int)$d['slots']['ECHO']['used'];
                                        $total_echo_max += (int)$d['slots']['ECHO']['max'];
                                    }
                                    if (isset($d['slots']['TCF'])) {
                                        $total_tcf_used += (int)$d['slots']['TCF']['used'];
                                        $total_tcf_max += (int)$d['slots']['TCF']['max'];
                                    }
                                }
                                
                                $doctor_count = count($day_data_cell['doctors']);
                                if ($doctor_count >= 2) {
                                    $overall_parts = [];
                                    if ($display_mode === 'use_max') {
                                        if ($total_gif_max > 0 || $total_gif_used > 0) {
                                            $overall_parts[] = '🔵' . $total_gif_used . '/' . $total_gif_max;
                                        }
                                        if ($total_echo_max > 0 || $total_echo_used > 0) {
                                            $overall_parts[] = '🟠' . $total_echo_used . '/' . $total_echo_max;
                                        }
                                        if ($total_tcf_max > 0 || $total_tcf_used > 0) {
                                            $overall_parts[] = '🟢' . $total_tcf_used . '/' . $total_tcf_max;
                                        }
                                    } else {
                                        if ($total_gif_max > 0 || $total_gif_used > 0) {
                                            $remain = $total_gif_max - $total_gif_used;
                                            $overall_parts[] = '🔵' . $remain;
                                        }
                                        if ($total_echo_max > 0 || $total_echo_used > 0) {
                                            $remain = $total_echo_max - $total_echo_used;
                                            $overall_parts[] = '🟠' . $remain;
                                        }
                                        if ($total_tcf_max > 0 || $total_tcf_used > 0) {
                                            $remain = $total_tcf_max - $total_tcf_used;
                                            $overall_parts[] = '🟢' . $remain;
                                        }
                                    }
                                    $avail_text = '全体 ' . implode(' ', $overall_parts);
                                    $avail_class = 'avail-green';
                                }
                            } elseif (!$is_closed && $has_data && $display_mode === 'count') {
                                $day_exams_total = [];
                                if (isset($month_reserved_cache[$date_str]) && is_array($month_reserved_cache[$date_str])) {
                                    foreach ($month_reserved_cache[$date_str] as $doc_name => $doc_exams) {
                                        if ($filter_doctor !== 'all' && $filter_doctor !== $doc_name) continue;
                                        if (is_array($doc_exams)) {
                                            foreach ($exam_types_master as $etype) {
                                                $c = (int)($doc_exams[$etype] ?? 0);
                                                if ($c > 0) {
                                                    $day_exams_total[$etype] = ($day_exams_total[$etype] ?? 0) + $c;
                                                }
                                            }
                                        }
                                    }
                                }
                                if (!empty($day_exams_total)) {
                                    $parts = [];
                                    foreach ($day_exams_total as $et => $c) {
                                        $parts[] = "{$et}:{$c}";
                                    }
                                    $avail_text = '合計 ' . implode(' ', $parts);
                                    $avail_class = 'avail-green';
                                }
                            }

                            $show_empty_val = $show_empty ? '1' : '0';
                            $filter_doc_enc = urlencode($filter_doctor);
                            $filter_exam_enc = urlencode($filter_exam);
                            echo "<td class='{$cell_class}' onclick=\"location.href='?dept={$dept}&year={$year}&month={$month}&date={$date_str}&doctor={$filter_doc_enc}&exam={$filter_exam_enc}&display_mode={$display_mode}&show_empty={$show_empty_val}'\">";
                            
                            $day_style = '';
                            if (!$is_closed) {
                                if ($w === 4) {
                                    $day_style = 'color: #1e40af;';
                                }
                            }

                            $exception_badge_class = $has_exception ? 'date-num-badge has-exception' : 'date-num-badge';
                            $exception_title = $has_exception ? ' title="出勤例外設定あり"' : '';
                            echo "<div class='day-number' style='{$day_style}'><span class='{$exception_badge_class}'{$exception_title}>{$day}</span></div>";

                            if ($is_closed) {
                                echo "<div class='day-available avail-closed'>⛔休</div>";
                            } elseif ($has_data) {
                                echo "<div class='day-slots'>";
                                foreach ($day_data_cell['doctors'] as $d) {
                                    $doc_name = (string)$d['name'];
                                    
                                    if ($display_mode === 'count') {
                                        $doc_exams = $month_reserved_cache[$date_str][$doc_name] ?? [];
                                        $has_any_cnt = false;
                                        foreach ($exam_types_master as $etype) {
                                            if ((int)($doc_exams[$etype] ?? 0) > 0) {
                                                $has_any_cnt = true;
                                                break;
                                            }
                                        }
                                        if (!$has_any_cnt) continue;
                                    }

                                    echo "<div class='doctor-slot-row'>";
                                    echo getDoctorBadgeHTML($doc_name);

                                    if ($display_mode === 'count') {
                                        $doc_exams = $month_reserved_cache[$date_str][$doc_name] ?? [];
                                        foreach ($exam_types_master as $etype) {
                                            if ($filter_exam !== 'all' && $filter_exam !== $etype) continue;
                                            $cnt = (int)($doc_exams[$etype] ?? 0);
                                            if ($cnt > 0) {
                                                echo "<span class='slot-item' style='background:#f1f5f9; padding:0 2px; border-radius:2px; margin-right:1px;'>{$etype}:{$cnt}</span>";
                                            }
                                        }
                                    } else {
                                        foreach (['GIF', 'ECHO', 'TCF'] as $exam) {
                                            if ($filter_exam !== 'all' && $filter_exam !== $exam) continue;
                                            if (isset($d['slots'][$exam])) {
                                                $used = (int)$d['slots'][$exam]['used'];
                                                $max = (int)$d['slots'][$exam]['max'];
                                                
                                                if ($max > 0 || $used > 0) {
                                                    $is_negative = ($used > $max);
                                                    $is_exact = ($used === $max && $max > 0);
                                                    $exam_icon = getExamIcon($exam);

                                                    if ($display_mode === 'use_max') {
                                                        $val_text = "{$used}/{$max}";
                                                        if ($is_negative) {
                                                            echo "<span class='slot-item negative'>{$exam_icon}{$val_text}</span>";
                                                        } elseif ($is_exact) {
                                                            echo "<span class='slot-item exact'>{$exam_icon}{$val_text}</span>";
                                                        } else {
                                                            echo "<span class='slot-item'>{$exam_icon}{$val_text}</span>";
                                                        }
                                                    } else {
                                                        $remain = $max - $used;
                                                        if ($is_negative) {
                                                            echo "<span class='slot-item negative'>{$exam_icon}{$remain}</span>";
                                                        } else {
                                                            $cls = ($remain === 0) ? 'full' : '';
                                                            echo "<span class='slot-item {$cls}'>{$exam_icon}{$remain}</span>";
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    echo "</div>";
                                }
                                echo "</div>";
                                
                                if ($is_w_over) {
                                    echo "<div style='font-size:8px; font-weight:bold; color:#fff; background:#ea580c; border-radius:2px; padding:0.5px 2px; margin-top:1px; text-align:center;'>⚠️ W超過:{$w_count_total}</div>";
                                } elseif (!empty($avail_text)) {
                                    echo "<div class='day-available {$avail_class}'>{$avail_text}</div>";
                                }

                                if (!empty($unassigned_html)) {
                                    echo $unassigned_html;
                                }
                            } else {
                                echo "<div class='empty-cell-text'>-</div>";
                            }

                            echo "</td>";

                            if ($w == 6) {
                                echo "</tr><tr>";
                            }
                        }

                        while ($start_weekday + $days_in_month <= 35 || ($start_weekday + $days_in_month) % 7 != 0) {
                            echo "<td style='background:#fafafa;'></td>";
                            $start_weekday++;
                        }
                        ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 💡 中央リサイザー（ドラッグバー） -->
        <div class="resizer" id="dragResizer" title="ドラッグして左右の幅を調整"></div>

        <!-- 右カラム：予約一覧部分 -->
        <div class="list-column">
            <div class="list-wrapper">
                <div class="list-header">
                    <div class="list-title">
                        <?php if ($is_same_month): ?>
                            📋 <?=date('Y年m月d日', strtotime($selected_date))?><?=$day_of_week_str?> の予約
                            <span style="font-size:10.5px; color:#64748b; font-weight:normal; margin-left:6px;">
                                (<?=$day_data['total_used']?>/<?=$day_data['total_max']?>枠)
                            </span>
                        <?php else: ?>
                            📋 予約一覧
                        <?php endif; ?>
                    </div>
                    <div class="list-actions">
                        <label class="empty-slot-label">
                            <input type="checkbox" id="show_empty" <?= $show_empty ? 'checked' : '' ?> onchange="toggleEmptySlots(this.checked)">
                            ⬜ 空き枠
                        </label>
                        
                        <label class="slot-filter-label" style="border-color:#93c5fd;">
                            <input type="checkbox" id="show_gif" checked onchange="applySlotFilter()">
                            <span class="color-dot gif"></span> GIF
                        </label>
                        <label class="slot-filter-label" style="border-color:#f0abfc;">
                            <input type="checkbox" id="show_echo" checked onchange="applySlotFilter()">
                            <span class="color-dot echo"></span> エコー
                        </label>
                        <label class="slot-filter-label" style="border-color:#86efac;">
                            <input type="checkbox" id="show_tcf" checked onchange="applySlotFilter()">
                            <span class="color-dot tcf"></span> TCF
                        </label>
                        
                        <button type="button" onclick="openBulkPrepRoomModal('<?= $dept ?>', '<?= $selected_date ?>')" class="summary-btn-modern" style="background:#0d9488;" title="部屋・下剤一括">
                            🚪 部屋一括
                        </button>
                        <button type="button" onclick="openSlotSummary('<?= $dept ?>', '<?= $selected_date ?>')" class="summary-btn-modern" title="枠・供給サマリ">
                            📊 サマリ
                        </button>
                        <button type="button" onclick="openPrintSchedule('<?= $dept ?>', '<?= $selected_date ?>')" class="print-btn-modern" title="一覧印刷">
                            🖨️ 印刷
                        </button>
                    </div>
                </div>

                <?php if (!$is_same_month): ?>
                    <div class="empty-msg" style="background:#f8fafc; color:#475569; font-weight:bold; padding:25px; font-size:12px; border:1px dashed #cbd5e1; border-radius:5px;">
                        📅 カレンダー側で日付を選択してください
                    </div>
                <?php elseif (empty($list_data)): ?>
                    <div class="empty-msg">この日の予約はありません</div>
                <?php else: ?>
                    <table class="list-table" id="reservationTable">
                        <thead>
                            <tr>
                                <th style="width:24px; text-align:center;" title="新規追加">➕</th>
                                <th style="width:26px; text-align:center;" title="枠なし設定">🚫</th>
                                <th style="width:24px; text-align:center;" title="編集">✏️</th>
                                <th style="width:75px;">時間枠</th>
                                <th style="width:55px;">ID</th>
                                <th style="width:110px;">患者名</th>
                                <th style="width:68px; text-align:center;">種別</th>
                                <th style="width:42px; text-align:center;">部屋</th>
                                <th style="width:42px; text-align:center;">下剤</th>
                                <th>備考</th>
                                <th style="width:34px; text-align:center;">シート</th>
                                <th style="width:45px; text-align:center;">F/U</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($list_data as $row): 
                            $exam = (string)($row['exam_type'] ?? '');
                            $cls = 'other';
                            $row_exam_category = 'OTHER';

                            if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') {
                                $cls = 'gif';
                                $row_exam_category = 'GIF';
                            } elseif ($exam === 'ECHO' || $exam === 'エコー') {
                                $cls = 'echo';
                                $row_exam_category = 'ECHO';
                            } elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') {
                                $cls = 'tcf';
                                $row_exam_category = 'TCF';
                            }
                            
                            $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
                            $is_mismatch = isset($row['is_mismatch']) && $row['is_mismatch'] === true;
                            $group_code = (string)($row['group_code'] ?? 'OTHER');
                            $schedule_id = (int)($row['schedule_id'] ?? 0);
                            $waku = (string)($row['scheduled_time'] ?? '');
                            $remarks_str = (string)($row['remarks'] ?? '');
                            
                            $is_no_waku = (!$is_empty && trim($remarks_str) === '＊＊＊🚫枠なし🚫＊＊＊');

                            $filter_category = $row_exam_category !== 'OTHER' ? $row_exam_category : $group_code;
                            $can_sheet = ($exam !== 'エコー' && $exam !== 'ECHO' && !$is_empty && !$is_no_waku);
                            
                            $time_display = $waku;
                            if ($is_mismatch) {
                                $time_display = '🚨 迷子';
                            } elseif ($waku === 'MISMATCH' || empty($waku)) {
                                $time_display = '🚨 未設定';
                            }
                            
                            $fu_url = '#';
                            $fu_label = '登録';
                            $fu_class = 'followup';
                            $fu_title = 'F/U登録';

                            $p_id_str = (string)($row['patient_id'] ?? '');
                            if (!$is_empty && !$is_no_waku && !empty($p_id_str)) {
                                $fu_params = '';
                                $fu_params .= 'patient_id=' . urlencode($p_id_str);
                                $fu_params .= '&patient_name=' . urlencode((string)($row['patient_name'] ?? ''));
                                $fu_params .= '&patient_kana=' . urlencode((string)($row['patient_kana'] ?? ''));
                                $fu_params .= '&birthday=' . urlencode((string)($row['birthday'] ?? ''));
                                $fu_params .= '&department_code=' . urlencode($dept);
                                $fu_params .= '&doctor_name=' . urlencode((string)($row['doctor_name'] ?? ''));
                                $fu_params .= '&exam_types=' . urlencode($exam);
                                
                                $fu_url = '/endoscope/followup/edit.php?' . $fu_params;
                            }
                            
                            $modal_date = $selected_date;
                            $modal_waku = $waku;
                            $modal_dept = $dept;
                            
                            $row_class = '';
                            if ($is_mismatch) { $row_class = 'mismatch-row'; }

                            $age_int = null;
                            $age_cls = 'age-under';
                            $b_wareki = '';
                            if (!$is_empty && !$is_no_waku && !empty($row['birthday'])) {
                                $b_date_clean = substr(trim((string)$row['birthday']), 0, 10);
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_date_clean)) {
                                    $b_dt = new DateTime($b_date_clean);
                                    $t_dt = new DateTime($selected_date);
                                    $age_int = $b_dt->diff($t_dt)->y;
                                    $age_cls = ($age_int >= 70) ? 'age-senior' : 'age-under';
                                    $b_wareki = convertToWareki($b_date_clean);
                                }
                            }

                            $room_val = trim((string)($row['room_number'] ?? ''));
                            $is_room_duplicate = false;
                            if (!$is_empty && !$is_no_waku && $room_val !== '' && $room_val !== '未指定' && $room_val !== '-') {
                                if (isset($room_counts[$room_val]) && $room_counts[$room_val] > 1) {
                                    $is_room_duplicate = true;
                                }
                            }

                            $prep_val = trim((string)($row['bowel_prep'] ?? ''));
                            $prep_cls = '';
                            if ($prep_val === 'モビ') { $prep_cls = 'prep-mobi'; }
                            elseif ($prep_val === 'マグP') { $prep_cls = 'prep-magp'; }
                            elseif ($prep_val === 'ニフ') { $prep_cls = 'prep-nif'; }
                        ?>
                            <tr data-group="<?= htmlspecialchars($filter_category, ENT_QUOTES, 'UTF-8') ?>" 
                                data-reserved="<?= ($is_empty || $is_no_waku) ? 'false' : 'true' ?>"
                                class="<?= $row_class ?>"
                                <?= ($is_empty && !$is_mismatch) ? 'style="background-color:#faf5ff;"' : '' ?>>
                                
                                <td style="text-align:center; white-space:nowrap;">
                                    <?php if (!$is_no_waku && !$is_mismatch): ?>
                                        <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', 0, '<?= $modal_dept ?>')" class="icon-btn add" title="追加">➕</a>
                                    <?php else: ?>
                                        <span class="icon-btn disabled">-</span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:center; white-space:nowrap;">
                                    <?php if ($is_empty): ?>
                                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&action=set_no_waku&waku=<?=urlencode($waku)?>" class="icon-btn" title="枠なし設定" style="color:#dc2626; font-weight:bold;">🚫</a>
                                    <?php elseif ($is_no_waku): ?>
                                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&action=revert_waku&sid=<?=$schedule_id?>" class="icon-btn" title="通常枠復帰" style="color:#2563eb; font-weight:bold;">⭕</a>
                                    <?php else: ?>
                                        <span class="icon-btn disabled">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="text-align:center;">
                                    <?php if (!$is_empty && !$is_no_waku && $schedule_id > 0): ?>
                                    <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', <?= $schedule_id ?>, '<?= $modal_dept ?>')" class="icon-btn edit" title="編集">✏️</a>
                                    <?php else: ?>
                                    <span class="icon-btn disabled">-</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if ($is_no_waku): ?>
                                        <span style="color:#dc2626; text-decoration:line-through; font-weight:bold;"><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <strong><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php endif; ?>
                                </td>
                                
                                <td style="font-size:10px;"><?= ($is_empty || $is_no_waku) ? '' : htmlspecialchars((string)($row['patient_id'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td>
                                
                                <td>
                                    <?php if ($is_empty): ?>
                                        <span style="color:#8b5cf6; font-size:10px; font-weight:600;">［空き枠］</span>
                                    <?php elseif ($is_no_waku): ?>
                                        <span style="color:#dc2626; font-size:10px; font-weight:bold;">［枠なし設定済］</span>
                                    <?php else: ?>
                                        <div style="line-height: 1.2;">
                                            <div style="display:flex; align-items:center; gap:2px;">
                                                <strong><?= htmlspecialchars((string)$row['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php if ($age_int !== null): ?>
                                                    <span class="age-box <?= $age_cls ?>"><?= $age_int ?>歳</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:center;">
                                    <?php if (!$is_empty && !$is_no_waku): ?>
                                        <div style="display:inline-flex; align-items:center; gap:1px; justify-content:center;">
                                            <?= getDoctorBadgeHTML((string)($row['doctor_name'] ?? '')) ?>
                                            <span class="exam-badge <?=$cls?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:center;">
                                    <?php if (!$is_empty && !$is_no_waku && !empty($room_val) && $room_val !== '未指定'): ?>
                                        <span class="room-badge <?= $is_room_duplicate ? 'room-duplicate' : '' ?>">
                                            <?= $is_room_duplicate ? '⚠️' : '' ?><?= htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:center;">
                                    <?php if (!$is_empty && !$is_no_waku && !empty($prep_val) && $prep_val !== '未指定'): ?>
                                        <span class="prep-badge <?= $prep_cls ?>"><?= htmlspecialchars($prep_val, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= renderRemarksRtfHtml($remarks_str) ?>
                                </td>
                                
                                <td style="text-align:center;">
                                    <?php if ($can_sheet): ?>
                                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>&sheet_single=<?=$row['schedule_id']?>" target="_blank" class="sheet-btn-small" title="印刷">📋</a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (!$is_empty && !$is_no_waku && !empty($p_id_str)): ?>
                                        <a href="<?= $fu_url ?>" target="_blank" class="sheet-btn-small <?= $fu_class ?>" title="F/U">登録</a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<script>
// 💡 マウスドラッグによる左右ペインの幅調整処理（最大900pxまで拡張可能）
document.addEventListener('DOMContentLoaded', function() {
    const resizer = document.getElementById('dragResizer');
    const calendarColumn = document.getElementById('calendarColumn');
    
    const savedWidth = localStorage.getItem('calendar_column_width');
    if (savedWidth) {
        calendarColumn.style.width = savedWidth + 'px';
    }

    if (resizer && calendarColumn) {
        let x = 0;
        let w = 0;

        const mousedownHandler = function(e) {
            x = e.clientX;
            w = calendarColumn.getBoundingClientRect().width;
            
            document.addEventListener('mousemove', mousemoveHandler);
            document.addEventListener('mouseup', mouseupHandler);
            resizer.classList.add('resizing');
            document.body.style.userSelect = 'none';
        };

        const mousemoveHandler = function(e) {
            const dx = e.clientX - x;
            const newWidth = w + dx;
            
            // 最小幅360px、最大幅900pxに制限
            if (newWidth >= 360 && newWidth <= 900) {
                calendarColumn.style.width = newWidth + 'px';
            }
        };

        const mouseupHandler = function() {
            document.removeEventListener('mousemove', mousemoveHandler);
            document.removeEventListener('mouseup', mouseupHandler);
            resizer.classList.remove('resizing');
            document.body.style.userSelect = '';
            
            const currentWidth = calendarColumn.getBoundingClientRect().width;
            localStorage.setItem('calendar_column_width', Math.round(currentWidth));
        };

        resizer.addEventListener('mousedown', mousedownHandler);
    }

    if (localStorage.getItem('calendar_collapsed') === 'true') {
        const wrapper = document.getElementById('calendarWrapper');
        const btn = document.querySelector('.cal-toggle-btn');
        if (wrapper && btn) {
            wrapper.classList.add('collapsed');
            btn.textContent = '🔽 カレンダー展開';
        }
    }

    applySlotFilter();
});

function toggleCalendar() {
    const wrapper = document.getElementById('calendarWrapper');
    const btn = document.querySelector('.cal-toggle-btn');
    if (wrapper.classList.contains('collapsed')) {
        wrapper.classList.remove('collapsed');
        btn.textContent = '🔼 カレンダー格納';
        localStorage.setItem('calendar_collapsed', 'false');
    } else {
        wrapper.classList.add('collapsed');
        btn.textContent = '🔽 カレンダー展開';
        localStorage.setItem('calendar_collapsed', 'true');
    }
}

function applySlotFilter() {
    const showGifEl = document.getElementById('show_gif');
    const showEchoEl = document.getElementById('show_echo');
    const showTcfEl = document.getElementById('show_tcf');
    const showEmpty = document.getElementById('show_empty');

    const showGif = showGifEl ? showGifEl.checked : true;
    const showEcho = showEchoEl ? showEchoEl.checked : true;
    const showTcf = showTcfEl ? showTcfEl.checked : true;
    const showEmptyChecked = showEmpty ? showEmpty.checked : true;
    
    document.querySelectorAll('#reservationTable tbody tr').forEach(function(tr) {
        const isReserved = tr.dataset.reserved === 'true';
        const group = tr.dataset.group || 'OTHER';
        
        if (tr.classList.contains('mismatch-row')) {
            tr.style.display = '';
            return;
        }

        if (!isReserved && !showEmptyChecked) {
            tr.style.display = 'none';
            return;
        }

        let show = false;
        if (group === 'GIF') {
            show = showGif;
        } else if (group === 'ECHO') {
            show = showEcho;
        } else if (group === 'TCF') {
            show = showTcf;
        } else {
            show = (showGif && showEcho && showTcf);
        }

        tr.style.display = show ? '' : 'none';
    });
}

function toggleEmptySlots(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_empty', checked ? '1' : '0');
    window.location.href = url.toString();
}

function openModal(date, waku, scheduleId, dept) {
    var url = 'modal_edit.php';
    url += '?date=' + encodeURIComponent(date);
    url += '&waku=' + encodeURIComponent(waku);
    url += '&dept=' + encodeURIComponent(dept);
    if (scheduleId && scheduleId > 0) {
        url += '&schedule_id=' + encodeURIComponent(scheduleId);
    }
    window.open(url, '_blank', 'width=1000,height=800,scrollbars=yes,resizable=yes');
}

function openSlotSummary(dept, date) {
    var url = 'daily_slot_summary.php';
    url += '?dept=' + encodeURIComponent(dept);
    url += '&date=' + encodeURIComponent(date);
    window.open(url, 'daily_slot_summary', 'width=1100,height=750,scrollbars=yes,resizable=yes');
}

function openBulkPrepRoomModal(dept, date) {
    var url = 'bulk_edit_prep_room.php?dept=' + encodeURIComponent(dept) + '&date=' + encodeURIComponent(date);
    window.open(url, 'bulk_prep_room', 'width=900,height=750,scrollbars=yes,resizable=yes');
}

function openPrintSchedule(dept, date) {
    const showGifEl = document.getElementById('show_gif');
    const showEchoEl = document.getElementById('show_echo');
    const showTcfEl = document.getElementById('show_tcf');
    const showEmpty = document.getElementById('show_empty');

    const selectedExams = [];
    if (showGifEl && showGifEl.checked) selectedExams.push('GIF');
    if (showEchoEl && showEchoEl.checked) selectedExams.push('ECHO');
    if (showTcfEl && showTcfEl.checked) selectedExams.push('TCF');

    let examParam = 'all';
    if (selectedExams.length > 0 && selectedExams.length < 3) {
        examParam = selectedExams.join(',');
    }

    const showEmptyVal = (showEmpty && showEmpty.checked) ? '1' : '0';
    
    const url = 'print_schedule_daily.php?dept=' + encodeURIComponent(dept) 
              + '&date=' + encodeURIComponent(date) 
              + '&exam=' + encodeURIComponent(examParam) 
              + '&show_empty=' + showEmptyVal;

    window.open(url, '_blank');
}

window.addEventListener('message', function(event) {
    if (event.data && (event.data === 'reservation_updated' || event.data.type === 'reservation_updated')) {
        window.location.reload();
    }
});

window.addEventListener('focus', function() {
    if (sessionStorage.getItem('need_reload') === '1') {
        sessionStorage.removeItem('need_reload');
        window.location.reload();
    }
});
</script>

</body>
</html>