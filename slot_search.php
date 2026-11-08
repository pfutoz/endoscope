<?php
/**
 * ファイル名: slot_search.php
 * 設置パス: /endoscope/slot_search.php
 * 生成日時: 2026-08-28 01:25:00 JST
 * 
 * 空き枠検索画面
 * 修正: 2026-11-01 【カレンダー状況ホバープレビュー】直近空き枠カードの「📅 カレンダー表示」ボタンへのマウスホバー時、ダーク系モダンツールチップで当日の各科（胃腸科・肛門科）全医師消化/定員および全体枠集計・メモ・医師予定を即座にプレビュー表示する機能を追加
 * 修正: 2026-11-01 【条件指定検索パワーアップ】
 *   - 診療科「全科」対応（胃腸科・肛門科横断検索、医師科バッジ表示）
 *   - 高密度コンパクトカード表示モード追加（テーブル ⇔ カード切替トグル、localStorage永続化）
 *   - リアルタイムAjax自動検索（デバウンス250ms）＆検索ボタンPulse点滅アニメーション
 *   - コンパクトカード内の「📅 カレンダー」ボタンからの当日の枠状況ホバープレビュー連動
 * 修正: 2026-11-01 【初期表示時検索不具合解消】
 *   - 初期表示（mode=custom）時のSSR自動検索対応＆初回カレンダープレビューキャッシュ埋め込み
 *   - DOMContentLoaded時およびタブ切替時の確実な自動検索トリガー
 *   - 医師予定パース時の警告（Undefined array key 'title'）解消によるAjax構文エラー修正完了
 * 修正: 2026-08-28 ステップ1（getExamIconの重複排除・共通ライブラリ利用）
 * 修正: 2026-10-05 休診日（祝日・日曜・年末年始）判定時の出勤例外最優先ロジックの統一
 * 修正: 2026-10-27 直近空き枠速報の検索期間に「直近 60日間」「直近 90日間」を追加。選択期間に応じたタブ表示動的連動
 * 修正: 2026-10-26 検査種別グループ解決を共通関数 resolveExamGroup() / getExamTypeBadgeInfo() に一本化し、m_exam_type_group と連動
 * 修正: 2026-10-20 検索期間指定に特大Flatpickr（小野会カレンダー・公休日色分け）導入
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/calendar_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$debug_mode = false;
$debug_config_path = __DIR__ . '/debug_config.json';
if (file_exists($debug_config_path)) {
    $config_json = json_decode(file_get_contents($debug_config_path), true);
    if (isset($config_json['debug_mode'])) {
        $debug_mode = (bool)$config_json['debug_mode'];
    }
}

// ★ 表示モード判定（デフォルトは「直近速報モード」）
$search_mode = isset($_GET['mode']) ? (string)$_GET['mode'] : '';
if (empty($search_mode)) {
    $search_mode = (isset($_GET['search_clicked']) && (string)$_GET['search_clicked'] === '1') ? 'custom' : 'urgent';
}

$selected_dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'GASTRO';
if (!in_array($selected_dept, ['PROCT', 'GASTRO'], true)) { $selected_dept = 'GASTRO'; }

$selected_doctor_raw = isset($_GET['doctor']) && $_GET['doctor'] !== '' ? (string)$_GET['doctor'] : 'all';
$selected_doctors = explode(',', $selected_doctor_raw);
if (empty($selected_doctors) || (count($selected_doctors) === 1 && $selected_doctors[0] === '')) {
    $selected_doctors = ['all'];
}

$selected_exam_raw = isset($_GET['exam']) && $_GET['exam'] !== '' ? (string)$_GET['exam'] : 'all';
$selected_exams = explode(',', $selected_exam_raw);
if (empty($selected_exams) || (count($selected_exams) === 1 && $selected_exams[0] === '')) {
    $selected_exams = ['all'];
}

$weekdays_raw = isset($_GET['weekdays']) && $_GET['weekdays'] !== '' ? (string)$_GET['weekdays'] : 'all';
$weekdays = explode(',', $weekdays_raw);
if (empty($weekdays) || (count($weekdays) === 1 && $weekdays[0] === '')) {
    $weekdays = ['all'];
}

$selected_month_raw = isset($_GET['months']) && $_GET['months'] !== '' ? (string)$_GET['months'] : '';
$selected_months = $selected_month_raw !== '' ? explode(',', $selected_month_raw) : [];

$default_from = date('Y-m-d');
$default_to = date('Y-m-t', strtotime('+2 months'));
$date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? (string)$_GET['date_from'] : $default_from;
$date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? (string)$_GET['date_to'] : $default_to;

$is_month_mode = !empty($selected_months);
if ($is_month_mode) {
    $m_dates = [];
    foreach ($selected_months as $ym) {
        if (strpos($ym, '-') !== false) {
            list($y, $m) = explode('-', $ym);
        } else {
            $y = date('Y');
            $m = (int)$ym;
        }
        $m_dates[] = sprintf('%04d-%02d-01', $y, $m);
    }
    if (!empty($m_dates)) {
        sort($m_dates);
        $min_m = reset($m_dates);
        $max_m = end($m_dates);
        $date_from = $min_m;
        $date_to = date('Y-m-t', strtotime($max_m));
    }
}
$search_clicked = isset($_GET['search_clicked']) && (string)$_GET['search_clicked'] === '1';

$doctor_meta_map = [
    '誠吾' => ['dept' => 'PROCT', 'dept_name' => '肛門科', 'short' => '誠', 'cls' => 'doc-seigo', 'color' => '#059669', 'badge_cls' => 'bg-emerald', 'dept_tag_cls' => 'tag-proct'],
    '哲也' => ['dept' => 'GASTRO', 'dept_name' => '胃腸科', 'short' => '哲', 'cls' => 'doc-tetsuya', 'color' => '#4f46e5', 'badge_cls' => 'bg-cyan', 'dept_tag_cls' => 'tag-gastro'],
    '義智' => ['dept' => 'GASTRO', 'dept_name' => '胃腸科', 'short' => '義', 'cls' => 'doc-yoshitomo', 'color' => '#7c3aed', 'badge_cls' => 'bg-indigo', 'dept_tag_cls' => 'tag-gastro'],
    '慶太郎' => ['dept' => 'GASTRO', 'dept_name' => '胃腸科', 'short' => '慶', 'cls' => 'doc-keitaro', 'color' => '#d97706', 'badge_cls' => 'bg-rose', 'dept_tag_cls' => 'tag-gastro'],
];

$all_doctors = [];
foreach (['PROCT', 'GASTRO'] as $d) {
    $all_doctors[$d] = getDoctorsForDept($pdo, $d);
}
$doctors_list = $all_doctors[$selected_dept] ?? [];
$week_ja = ['日', '月', '火', '水', '木', '金', '土'];

$month_shortcuts = [];
$now_ts = time();
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime("+$i month", $now_ts);
    $month_shortcuts[] = [
        'key'   => date('Y-n', $ts),
        'month' => (int)date('n', $ts),
        'year'  => (int)date('Y', $ts)
    ];
}

// 条件指定モードの場合、初回表示（search_clicked未指定）でもデフォルト条件で初期検索を実行
$should_search = $search_clicked || ($search_mode === 'custom');

$results = [];
$result_count = 0;
$executed_sql = '';
$too_many_count = 0;
$initial_cal_previews = [];

if ($should_search) {
    $result = searchAvailableSlots(
        $pdo,
        $selected_dept,
        $selected_doctors,
        $selected_exams,
        $weekdays,
        $selected_months,
        $date_from,
        $date_to,
        $is_month_mode,
        $executed_sql
    );
    
    if (isset($result['error']) && $result['error'] === 'too_many') {
        $too_many_count = $result['count'];
        $results = [];
        $result_count = 0;
    } else {
        $results = $result;
        $result_count = count($results);
        
        // カレンダープレビュー用データを集計
        $unique_dates = array_values(array_unique(array_column($results, 'exam_date')));
        if (!empty($unique_dates)) {
            $initial_cal_previews = getCalendarPreviewsForDates($pdo, $unique_dates);
        }
    }
}

// ★ Ajaxリアルタイム検索リクエスト判定
if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    
    // 検索実行
    $ajax_sql = '';
    $ajax_result = searchAvailableSlots(
        $pdo,
        $selected_dept,
        $selected_doctors,
        $selected_exams,
        $weekdays,
        $selected_months,
        $date_from,
        $date_to,
        $is_month_mode,
        $ajax_sql
    );
    
    $is_too_many = false;
    $ajax_too_many_count = 0;
    $ajax_results = [];
    
    if (isset($ajax_result['error']) && $ajax_result['error'] === 'too_many') {
        $is_too_many = true;
        $ajax_too_many_count = $ajax_result['count'];
    } else {
        $ajax_results = $ajax_result;
    }
    
    // カレンダープレビュー用データを集計
    $unique_dates = array_values(array_unique(array_column($ajax_results, 'exam_date')));
    $cal_previews = [];
    if (!empty($unique_dates)) {
        $cal_previews = getCalendarPreviewsForDates($pdo, $unique_dates);
    }
    
    echo json_encode([
        'success' => true,
        'count' => count($ajax_results),
        'too_many' => $is_too_many,
        'too_many_count' => $ajax_too_many_count,
        'results' => $ajax_results,
        'calendar_previews' => $cal_previews,
        'dept' => $selected_dept,
        'executed_sql' => $debug_mode ? $ajax_sql : ''
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function searchAvailableSlots($pdo, $dept, $doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, &$executed_sql) {
    global $doctor_meta_map, $week_ja;
    $week_ja_map = ['日', '月', '火', '水', '木', '金', '土'];
    
    // 対象科の医師一覧
    $dept_doctors = getDoctorsForDept($pdo, $dept);
    $target_doctors = [];
    if (in_array('all', $doctors, true)) {
        $target_doctors = ['all'];
    } else {
        $target_doctors = array_values(array_intersect($doctors, $dept_doctors));
        if (empty($target_doctors)) {
            $executed_sql = "-- 対象科に一致する医師がありません";
            return [];
        }
    }
    
    $res = searchAvailableSlotsOneDept($pdo, $dept, $target_doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, $executed_sql);
    
    $dep_name = ($dept === 'GASTRO') ? '胃腸科' : '肛門科';
    $all_results = [];
    foreach ($res as $row) {
        $d_name = $row['doctor_name'];
        $meta = $doctor_meta_map[$d_name] ?? [
            'dept' => $dept,
            'dept_name' => $dep_name,
            'short' => mb_substr($d_name, 0, 1),
            'cls' => '',
            'color' => '#334155',
            'badge_cls' => 'bg-slate',
            'dept_tag_cls' => ($dept === 'GASTRO') ? 'tag-gastro' : 'tag-proct'
        ];
        $dow = (int)date('w', strtotime($row['exam_date']));
        $parts = explode('-', $row['exam_date']);
        $y = $parts[0] ?? date('Y');
        $m = (int)($parts[1] ?? date('m'));
        
        $all_results[] = [
            'exam_date' => $row['exam_date'],
            'year' => (int)$y,
            'month' => $m,
            'dow' => $dow,
            'dow_ja' => $week_ja_map[$dow] ?? '',
            'department_code' => $dept,
            'department_name' => $dep_name,
            'doctor_name' => $d_name,
            'doctor_short' => $meta['short'],
            'doctor_cls' => $meta['cls'],
            'doctor_color' => $meta['color'],
            'doctor_badge_cls' => $meta['badge_cls'],
            'dept_tag_cls' => $meta['dept_tag_cls'],
            'exam_type' => $row['exam_type'],
            'exam_cls' => getExamClass($row['exam_type']),
            'exam_icon' => getExamIcon($row['exam_type']),
            'free_slots' => (int)$row['free_slots'],
            'max_slots' => (int)$row['max_slots'],
            'used_slots' => (int)$row['max_slots'] - (int)$row['free_slots'],
            'free_color_cls' => getFreeColorClass((int)$row['free_slots']),
            'jump_url' => "month_schedule.php?dept=" . urlencode($dept) . "&year={$y}&month={$m}&date=" . urlencode($row['exam_date']) . "&display_mode=remain"
        ];
    }
    
    $total_count = count($all_results);
    if ($total_count > 300) {
        $executed_sql .= "
-- 空き枠検索結果が300件を超えました（{$total_count}件）";
        return ['error' => 'too_many', 'count' => $total_count];
    }
    
    usort($all_results, function($a, $b) {
        if ($a['exam_date'] !== $b['exam_date']) return strcmp($a['exam_date'], $b['exam_date']);
        if ($a['doctor_name'] !== $b['doctor_name']) return strcmp($a['doctor_name'], $b['doctor_name']);
        $order = ['GIF' => 0, 'エコー' => 1, 'TCF' => 2];
        return ($order[$a['exam_type']] ?? 99) - ($order[$b['exam_type']] ?? 99);
    });
    
    return $all_results;
}

function searchAvailableSlotsOneDept($pdo, $dept, $doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, &$executed_sql) {
    $dates = [];
    $today = date('Y-m-d');
    
    $stmt_closed_att = $pdo->prepare("
        SELECT DISTINCT target_date::text 
        FROM m2_doctor_attendance 
        WHERE department_code = :dept 
          AND (gif_slots > 0 OR echo_slots > 0 OR tcf_slots > 0)
    ");
    $stmt_closed_att->execute([':dept' => $dept]);
    $exception_dates = $stmt_closed_att->fetchAll(PDO::FETCH_COLUMN);
    $exception_date_set = array_flip($exception_dates);
    
    if ($is_month_mode && !empty($months)) {
        foreach ($months as $ym) {
            if (strpos($ym, '-') !== false) {
                list($y, $m) = explode('-', $ym);
            } else {
                $y = date('Y');
                $m = (int)$ym;
            }
            $start = strtotime(sprintf('%04d-%02d-01', $y, $m));
            $end = strtotime(date('Y-m-t', $start));
            
            while ($start <= $end) {
                $date_str = date('Y-m-d', $start);
                $dow = (int)date('w', $start);
                
                if (!in_array('all', $weekdays, true) && !in_array((string)$dow, $weekdays, true)) {
                    $start = strtotime('+1 day', $start);
                    continue;
                }
                if ($date_str >= $today) {
                    $day_check = check_onokai_calendar($date_str, null);
                    if (!$day_check['is_closed'] || isset($exception_date_set[$date_str])) {
                        $dates[] = $date_str;
                    }
                }
                $start = strtotime('+1 day', $start);
            }
        }
    } else {
        $start = ($date_from !== '') ? strtotime($date_from) : strtotime($today);
        $end = ($date_to !== '') ? strtotime($date_to) : strtotime('+2 months');
        
        while ($start <= $end) {
            $date_str = date('Y-m-d', $start);
            $dow = (int)date('w', $start);
            
            if (!in_array('all', $weekdays, true) && !in_array((string)$dow, $weekdays, true)) {
                $start = strtotime('+1 day', $start);
                continue;
            }
            if ($date_str >= $today) {
                $day_check = check_onokai_calendar($date_str, null);
                if (!$day_check['is_closed'] || isset($exception_date_set[$date_str])) {
                    $dates[] = $date_str;
                }
            }
            $start = strtotime('+1 day', $start);
        }
    }
    
    $dates = array_unique($dates);
    sort($dates);

    if (empty($dates)) {
        $executed_sql = "-- 対象日付がありません";
        return [];
    }
    
    $doctor_list = in_array('all', $doctors, true) ? getDoctorsForDept($pdo, $dept) : $doctors;
    if (empty($doctor_list)) {
        $executed_sql = "-- 対象医師がありません";
        return [];
    }
    
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $sql = "
        SELECT 
            exam_date::text AS exam_date,
            doctor_name,
            COALESCE(exam_type, '') AS exam_type,
            COUNT(*) AS cnt
        FROM t_endoscopy_schedule 
        WHERE department_code = ? 
          AND exam_date IN ($placeholders)
          AND is_canceled = FALSE
          AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
        GROUP BY exam_date, doctor_name, exam_type
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$dept], $dates));
    $reservation_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reservationMap = [];
    $total_reserved_count = 0;
    foreach ($reservation_rows as $r) {
        $d = (string)$r['exam_date'];
        $doc = (string)$r['doctor_name'];
        $etype = (string)$r['exam_type'];
        $cnt = (int)$r['cnt'];
        $total_reserved_count += $cnt;
        if (!isset($reservationMap[$d])) { $reservationMap[$d] = []; }
        if (!isset($reservationMap[$d][$doc])) { $reservationMap[$d][$doc] = []; }
        $reservationMap[$d][$doc][$etype] = $cnt;
    }

    $executed_sql = "-- 既存予約集計結果: " . $total_reserved_count . " 件
";
    $executed_sql .= $sql . "
-- 対象日: " . implode(', ', $dates);

    $min_date = reset($dates);
    $max_date = end($dates);

    $slot_cache = [
        'attendance' => [],
        'daily_capacity' => [],
        'patterns' => []
    ];

    $stmt_att = $pdo->prepare("
        SELECT doctor_name, target_date::text AS target_date, gif_slots, echo_slots, tcf_slots, reason, pattern_name
        FROM m2_doctor_attendance
        WHERE department_code = :dept
          AND target_date >= :start_date
          AND target_date <= :end_date
    ");
    $stmt_att->execute([':dept' => $dept, ':start_date' => $min_date, ':end_date' => $max_date]);
    while ($row = $stmt_att->fetch(PDO::FETCH_ASSOC)) {
        $slot_cache['attendance'][(string)$row['target_date']][(string)$row['doctor_name']] = [
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
    $stmt_cap->execute([':dept' => $dept, ':start_date' => $min_date, ':end_date' => $max_date]);
    while ($row = $stmt_cap->fetch(PDO::FETCH_ASSOC)) {
        $slot_cache['daily_capacity'][(string)$row['target_date']][(string)$row['doctor_name']] = [
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
        $slot_cache['patterns'][(int)$row['day_of_week']][(string)$row['doctor_name']] = [
            'GIF' => (int)($row['gif_slots'] ?? 0),
            'ECHO' => (int)($row['echo_slots'] ?? 0),
            'TCF' => (int)($row['tcf_slots'] ?? 0)
        ];
    }

    $results = [];
    $slot_types = ['GIF', 'ECHO', 'TCF'];
    
    foreach ($dates as $date_str) {
        $dow = (int)date('w', strtotime($date_str));
        $day_res = $reservationMap[$date_str] ?? [];

        foreach ($doctor_list as $doc) {
            $status = getDoctorStatusFromCacheMatrix($doc, $date_str, $dow, $slot_cache);
            if (!$status['present']) continue;
            
            $doc_res = $day_res[$doc] ?? [];

            foreach ($slot_types as $slot_type) {
                $display_type = ($slot_type === 'ECHO') ? 'エコー' : $slot_type;
                if (!in_array('all', $exams, true) && !in_array($display_type, $exams, true) && !in_array($slot_type, $exams, true)) {
                    continue;
                }
                
                $max_slots = $status['slots'][$slot_type] ?? 0;
                if ($max_slots <= 0) continue;
                
                $used = 0;
                foreach ($doc_res as $e_name => $cnt) {
                    if (resolveExamGroup((string)$e_name) === $slot_type) {
                        $used += (int)$cnt;
                    }
                }
                
                $free = $max_slots - $used;
                if ($free > 0) {
                    $results[] = [
                        'exam_date' => $date_str,
                        'doctor_name' => $doc,
                        'exam_type' => $display_type,
                        'free_slots' => $free,
                        'max_slots' => $max_slots
                    ];
                }
            }
        }
    }

    return $results;
}

function getExamClass($exam_type) {
    return getExamTypeBadgeInfo($exam_type)['cls'];
}

function getFreeColorClass($free) {
    if ($free <= 0) return 'color-red';
    if ($free <= 2) return 'color-yellow';
    return 'color-green';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 空き枠検索 | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font-sans);
            background: var(--color-bg);
            padding: 20px 16px;
            font-size: 13px;
            color: var(--color-text-main);
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
            gap: 12px;
        }
        .page-header h1 {
            font-size: 19px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav-links {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 13px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            line-height: 1.3;
        }
        .nav-btn.menu {
            background: #2c3e50;
            color: #ffffff;
        }
        .nav-btn.menu:hover { background: #34495e; }
        .nav-btn.calendar {
            background: #2563eb;
            color: #ffffff;
        }
        .nav-btn.calendar:hover { background: #1d4ed8; }
        .nav-btn.matrix {
            background: #0d9488;
            color: #ffffff;
        }
        .nav-btn.matrix:hover { background: #0f766e; }
        
        .filter-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .filter-row:last-of-type { border-bottom: none; }

        .group-label-btn {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            min-width: 84px;
            padding: 5px 8px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s;
        }
        .group-label-btn:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .group-label-btn .icon-toggle {
            font-size: 10px;
            color: #64748b;
            margin-left: 4px;
        }

        .btn-group-items {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .btn {
            padding: 5px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 20px;
            background: #ffffff;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            transition: all 0.15s;
        }
        .btn:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn.active { background: #2563eb; color: #ffffff; border-color: #2563eb; }
        .btn.active-all { background: #334155; color: #ffffff; border-color: #334155; }

        .date-input {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            width: 135px;
            background: #fff;
            transition: all 0.15s;
        }
        .date-input:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
            border-color: #cbd5e1;
        }

        .month-btn {
            font-size: 11px;
            padding: 4px 8px;
        }
        .month-btn.active {
            background: #8b5cf6;
            color: #ffffff;
            border-color: #8b5cf6;
        }

        .actions {
            display: flex;
            gap: 12px;
            padding-top: 14px;
            margin-top: 8px;
            border-top: 1px solid #e2e8f0;
        }
        .btn-search {
            padding: 7px 24px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-search:hover { background: #1d4ed8; }
        .btn-reset {
            padding: 7px 16px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-reset:hover { background: #e2e8f0; }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0 8px;
            border-top: 2px solid #e2e8f0;
            margin-top: 18px;
        }
        .result-header .count { font-size: 14px; font-weight: 700; }
        .result-header .count span { color: #2563eb; }

        .result-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
        }
        .result-table th {
            background: #f8fafc;
            padding: 9px 12px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #cbd5e1;
        }
        .result-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .result-table tr.clickable { cursor: pointer; }
        .result-table tr.clickable:hover { background: #f8fafc; }

        .exam-tag {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 11px;
        }
        .exam-tag.gif { background: #e0f2fe; color: #0369a1; }
        .exam-tag.echo { background: #f3e8ff; color: #7e22ce; }
        .exam-tag.tcf { background: #dcfce7; color: #15803d; }
        .exam-tag.other { background: #f1f5f9; color: #475569; }

        .free-slots { font-weight: 700; font-size: 14px; }
        .free-slots.color-green { color: #16a34a; }
        .free-slots.color-yellow { color: #ca8a04; }
        .free-slots.color-red { color: #dc2626; }

        .no-results { text-align: center; color: #64748b; padding: 40px 0; font-weight: 600; }
        
        .sql-box {
            background: #0f172a;
            color: #38bdf8;
            padding: 14px 16px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 11.5px;
            white-space: pre-wrap;
            margin-top: 18px;
            max-height: 350px;
            overflow: auto;
            border: 1px solid #334155;
        }
        .error-box {
            background: #fef2f2;
            border: 1px solid #f87171;
            border-radius: 8px;
            padding: 14px;
            margin-top: 18px;
            text-align: center;
            color: #991b1b;
        }

        /* ── 統合モード切替タブ ── */
        .search-mode-tabs-container {
            display: flex;
            gap: 8px;
            background: #f1f5f9;
            padding: 5px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .search-mode-tab {
            flex: 1;
            padding: 9px 16px;
            border: none;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            background: transparent;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.15s ease;
        }
        .search-mode-tab:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .search-mode-tab.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            border: 1px solid #cbd5e1;
        }
        .search-mode-tab.active#tabModeUrgent {
            color: #b45309;
            border-color: #f59e0b;
            background: linear-gradient(to bottom, #fffdfa 0%, #ffffff 100%);
        }
        .search-mode-tab.active#tabModeCustom {
            color: #1d4ed8;
            border-color: #3b82f6;
        }
        .tab-badge {
            background: linear-gradient(135deg, #f59e0b, #ea580c);
            color: #ffffff;
            font-size: 10px;
            font-weight: 900;
            padding: 2px 7px;
            border-radius: 9999px;
            letter-spacing: 0.02em;
        }

        /* ── 直近速報ビュー スタイル ── */
        .urgent-view-header {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .urgent-doc-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .urgent-doc-btn {
            padding: 5px 14px;
            border-radius: 20px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .urgent-doc-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
        .urgent-doc-btn.active {
            background: #1e293b;
            color: #ffffff;
            border-color: #1e293b;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .urgent-doc-btn.active.doc-seigo { background: #059669; border-color: #059669; }
        .urgent-doc-btn.active.doc-tetsuya { background: #4f46e5; border-color: #4f46e5; }
        .urgent-doc-btn.active.doc-yoshitomo { background: #7c3aed; border-color: #7c3aed; }
        .urgent-doc-btn.active.doc-keitaro { background: #d97706; border-color: #d97706; }
        .urgent-doc-badge {
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
            font-size: 10px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 10px;
        }
        .urgent-doc-btn.active .urgent-doc-badge {
            background: rgba(255, 255, 255, 0.3);
            color: #ffffff;
        }

        .urgent-controls-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .urgent-toggle-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            color: #b45309;
            background: #fef3c7;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #fde68a;
            user-select: none;
        }
        .urgent-toggle-label input { cursor: pointer; accent-color: #d97706; }

        /* カードグリッド */
        .urgent-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 14px;
        }
        .urgent-card {
            background: #ffffff;
            border-radius: 10px;
            border: 1.5px solid #e2e8f0;
            padding: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        .urgent-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.08);
            border-color: #94a3b8;
        }
        .urgent-card.is-cancel {
            border-color: #f59e0b;
            background: linear-gradient(to bottom, #fffdfa 0%, #ffffff 100%);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
        }
        .urgent-card.is-cancel::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #f59e0b, #ea580c);
        }

        .urgent-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f1f5f9;
        }
        .urgent-card-date {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .urgent-card-date-main { font-size: 15px; font-weight: 800; color: #0f172a; }
        .urgent-card-dow { font-size: 12px; font-weight: 800; padding: 1px 6px; border-radius: 4px; }
        .urgent-card-dow.dow-sat { background: #dbeafe; color: #1d4ed8; }
        .urgent-card-dow.dow-sun { background: #fee2e2; color: #dc2626; }
        .urgent-card-dow.dow-weekday { background: #f1f5f9; color: #475569; }

        .urgent-relative-badge { font-size: 10.5px; font-weight: 800; padding: 1px 7px; border-radius: 12px; background: #e2e8f0; color: #334155; }
        .urgent-relative-badge.today { background: #ef4444; color: #ffffff; }
        .urgent-relative-badge.tomorrow { background: #f97316; color: #ffffff; }
        .urgent-relative-badge.day2 { background: #f59e0b; color: #ffffff; }

        .urgent-card-doctor { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
        .urgent-doc-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12.5px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 6px;
            color: #ffffff;
        }
        .urgent-dept-tag {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .urgent-slot-meter-area { margin-bottom: 10px; }
        .urgent-meter-labels { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 4px; font-size: 11px; }
        .urgent-free-highlight { font-size: 15.5px; font-weight: 900; color: #059669; }
        .urgent-meter-track { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; display: flex; }
        .urgent-meter-fill { height: 100%; transition: width 0.3s ease; }

        .urgent-cancel-notice {
            background: #fffbeb;
            border: 1px dashed #f59e0b;
            border-radius: 6px;
            padding: 6px 9px;
            margin-bottom: 10px;
            font-size: 11px;
            color: #92400e;
            line-height: 1.35;
        }
        .urgent-cancel-title { font-weight: 800; color: #b45309; display: flex; align-items: center; gap: 4px; margin-bottom: 2px; }

        .urgent-card-actions { display: flex; gap: 6px; align-items: center; }
        .urgent-action-btn {
            flex: 1;
            padding: 7px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .urgent-btn-cal { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .urgent-btn-cal:hover { background: #e2e8f0; color: #0f172a; }
        .urgent-btn-book {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.3);
        }
        .urgent-btn-book:hover { background: linear-gradient(135deg, #1d4ed8, #1e40af); }

        /* ── カレンダープレビュー・フローティングツールチップ (Dark Modern Floating) ── */
        .cal-preview-tooltip {
            position: fixed;
            z-index: 99999;
            width: 320px;
            max-width: 92vw;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1.5px solid rgba(255, 255, 255, 0.16);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45), 0 4px 12px rgba(0, 0, 0, 0.25);
            color: #f8fafc;
            font-size: 12px;
            line-height: 1.45;
            pointer-events: none;
            opacity: 0;
            transform: translateY(6px) scale(0.98);
            transition: opacity 0.15s ease, transform 0.15s ease;
            display: none;
        }
        .cal-preview-tooltip.show {
            opacity: 1;
            transform: translateY(0) scale(1);
            display: block;
        }

        .cpt-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 6px;
            margin-bottom: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.14);
            gap: 6px;
        }
        .cpt-title {
            font-size: 13.5px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cpt-dow {
            font-size: 11px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .cpt-dow.sat { background: rgba(59, 130, 246, 0.35); color: #93c5fd; }
        .cpt-dow.sun { background: rgba(239, 68, 68, 0.35); color: #fca5a5; }
        .cpt-dow.weekday { background: rgba(255, 255, 255, 0.14); color: #e2e8f0; }

        .cpt-closed-badge {
            background: rgba(239, 68, 68, 0.25);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.45);
            font-size: 10px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
        }

        .cpt-dept-section {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 7px 9px;
            margin-bottom: 7px;
        }
        .cpt-dept-section:last-of-type {
            margin-bottom: 0;
        }
        .cpt-dept-title {
            font-size: 11px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 5px;
            padding-bottom: 3px;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.1);
        }
        .cpt-dept-name {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .cpt-dept-name.gastro { color: #93c5fd; }
        .cpt-dept-name.proct  { color: #6ee7b7; }

        .cpt-doc-row {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 2.5px 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.06);
        }
        .cpt-doc-row:last-of-type {
            border-bottom: none;
        }
        .cpt-doc-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 17px;
            height: 17px;
            min-width: 17px;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 800;
            color: #ffffff;
            flex-shrink: 0;
        }
        .cpt-doc-icon.bg-indigo  { background-color: #6366f1; }
        .cpt-doc-icon.bg-emerald { background-color: #10b981; }
        .cpt-doc-icon.bg-amber   { background-color: #f59e0b; }
        .cpt-doc-icon.bg-cyan    { background-color: #06b6d4; }
        .cpt-doc-icon.bg-purple  { background-color: #8b5cf6; }
        .cpt-doc-icon.bg-rose    { background-color: #f43f5e; }
        .cpt-doc-icon.bg-slate   { background-color: #64748b; }

        .cpt-slot-badges {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        .cpt-slot-pill {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-size: 10.5px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #f8fafc;
        }
        .cpt-slot-pill.is-full {
            background: rgba(59, 130, 246, 0.25);
            border-color: rgba(59, 130, 246, 0.5);
            color: #bfdbfe;
        }
        .cpt-slot-pill.is-over {
            background: rgba(239, 68, 68, 0.35);
            border-color: rgba(239, 68, 68, 0.6);
            color: #fca5a5;
            animation: cptPulse 1.2s infinite;
        }
        @keyframes cptPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.65; } }

        .cpt-overall-bar {
            margin-top: 4px;
            padding: 2.5px 6px;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 4px;
            font-size: 10px;
            font-weight: 800;
            color: #6ee7b7;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
        }
        .cpt-overall-bar .overall-label {
            opacity: 0.85;
        }

        .cpt-extras {
            margin-top: 7px;
            padding-top: 5px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .cpt-memo-item {
            font-size: 10.5px;
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #fde68a;
            padding: 2px 6px;
            border-radius: 4px;
            line-height: 1.35;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .cpt-event-item {
            font-size: 10.5px;
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: #fca5a5;
            padding: 2px 6px;
            border-radius: 4px;
            line-height: 1.35;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ── 検索ボタン フワフワ点滅アニメーション ── */
        @keyframes searchBtnPulse {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
            }
            50% {
                transform: scale(1.04);
                box-shadow: 0 0 0 10px rgba(37, 99, 235, 0);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0);
            }
        }
        .btn-search.pulse {
            animation: searchBtnPulse 1.6s infinite ease-in-out;
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            position: relative;
        }

        /* ── 医師ボタン内の診療科バッジ ── */
        .doc-dept-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            margin-left: 4px;
            vertical-align: middle;
        }
        .doc-dept-badge.proct {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .doc-dept-badge.gastro {
            background: #e0f2fe;
            color: #075985;
            border: 1px solid #bae6fd;
        }

        /* ── 表示切替トグル（テーブル ⇔ コンパクトカード） ── */
        .custom-view-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 3px;
            gap: 3px;
        }
        .custom-view-btn {
            border: none;
            background: transparent;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .custom-view-btn:hover {
            color: #0f172a;
            background: #e2e8f0;
        }
        .custom-view-btn.active {
            background: #ffffff;
            color: #1d4ed8;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* ── コンパクトカード用 スタイル ── */
        .compact-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(235px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .compact-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
            transition: all 0.15s ease;
            position: relative;
        }
        .compact-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            border-color: #94a3b8;
        }
        .compact-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 6px;
        }
        .compact-card-date {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 700;
            color: #1e293b;
        }
        .compact-card-dow {
            font-size: 11px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
        }
        .compact-card-dept {
            font-size: 10px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .compact-card-dept.gastro {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .compact-card-dept.proct {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .compact-card-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .compact-doc-info {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .compact-doc-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 12px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .compact-doc-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #1e293b;
        }

        .compact-slot-info {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
        }
        .compact-slot-count {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.1;
        }
        .compact-slot-max {
            font-size: 10.5px;
            color: #64748b;
            font-weight: 600;
        }

        .compact-card-footer {
            display: flex;
            gap: 6px;
            margin-top: 4px;
            padding-top: 6px;
            border-top: 1px solid #f8fafc;
        }
        .compact-btn {
            flex: 1;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            line-height: 1.2;
        }
        .compact-btn-cal {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #334155;
        }
        .compact-btn-cal:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .compact-btn-book {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .compact-btn-book:hover {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1e40af;
        }

        /* リアルタイム検索ローディング表示 */
        .custom-search-loading {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            color: #2563eb;
            font-weight: 700;
            font-size: 13px;
            background: #f8fafc;
            border-radius: 8px;
            margin-top: 12px;
            border: 1px dashed #cbd5e1;
        }
        .spinner-dot {
            width: 8px;
            height: 8px;
            background: #2563eb;
            border-radius: 50%;
            animation: spinnerDot 0.8s infinite alternate;
        }
        @keyframes spinnerDot {
            0% { transform: scale(0.6); opacity: 0.4; }
            100% { transform: scale(1.2); opacity: 1; }
        }
    </style>
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css?v=<?= filemtime(__DIR__ . '/assets/css/onokai_flatpickr.css') ?>">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>
</head>
<body>

<div class="container">

    <div class="page-header">
        <h1>🔍 空き枠検索</h1>
        <div class="nav-links">
            <a href="index.php" class="nav-btn menu">🏠 メニューへ</a>
            <a href="month_schedule.php?dept=<?= urlencode($selected_dept) ?>" class="nav-btn calendar">📅 月間カレンダーへ</a>
            <a href="month_matrix_v2.php?dept=<?= urlencode($selected_dept) ?>" class="nav-btn matrix">📊 予約マトリクス</a>
        </div>
    </div>

    <!-- ── 統合モード切替タブ ── -->
    <div class="search-mode-tabs-container">
        <button type="button" class="search-mode-tab <?= $search_mode === 'urgent' ? 'active' : '' ?>" id="tabModeUrgent" onclick="switchSearchMode('urgent')">
            <span id="urgentTabTitle">⚡ 直近空き枠速報（全科横断・30日大腸CF）</span>
            <span class="tab-badge" id="tabUrgentBadge">速報</span>
        </button>
        <button type="button" class="search-mode-tab <?= $search_mode === 'custom' ? 'active' : '' ?>" id="tabModeCustom" onclick="switchSearchMode('custom')">
            <span>🔍 条件指定検索（月・曜日・胃カメラ等）</span>
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         【モードA】⚡ 直近空き枠速報ビュー（デフォルト表示）
    ════════════════════════════════════════════════════════════ -->
    <div id="urgentViewContainer" style="display: <?= $search_mode === 'urgent' ? 'block' : 'none' ?>;">
        <div class="urgent-view-header">
            <div class="urgent-doc-tabs" id="urgentDoctorTabs">
                <button type="button" class="urgent-doc-btn active" data-doctor="all" onclick="filterUrgentByDoctor('all')">
                    👨‍⚕️ 全員 <span class="urgent-doc-badge" id="badgeUrgentAll">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="誠吾" onclick="filterUrgentByDoctor('誠吾')">
                    🟢 誠吾 <span class="urgent-dept-tag">肛門科</span> <span class="urgent-doc-badge" id="badgeUrgentSeigo">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="哲也" onclick="filterUrgentByDoctor('哲也')">
                    🔵 哲也 <span class="urgent-dept-tag">胃腸科</span> <span class="urgent-doc-badge" id="badgeUrgentTetsuya">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="義智" onclick="filterUrgentByDoctor('義智')">
                    🟣 義智 <span class="urgent-dept-tag">胃腸科</span> <span class="urgent-doc-badge" id="badgeUrgentYoshitomo">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="慶太郎" onclick="filterUrgentByDoctor('慶太郎')">
                    🟠 慶太郎 <span class="urgent-dept-tag">胃腸科</span> <span class="urgent-doc-badge" id="badgeUrgentKeitaro">0</span>
                </button>
            </div>

            <div class="urgent-controls-right">
                <label class="urgent-toggle-label" title="直近7日以内にキャンセル・移動が発生した枠のみを表示">
                    <input type="checkbox" id="urgentOnlyCancelToggle" onchange="handleUrgentCancelToggle()">
                    <span>⚡ キャンセル空きのみ</span>
                    <span class="urgent-doc-badge" id="badgeUrgentCancel" style="background:#f59e0b; color:#fff;">0</span>
                </label>
                <select id="urgentDaysSelect" onchange="handleUrgentDaysChange()" style="padding:5px 10px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; font-weight:bold; background:#fff; color:#334155;">
                    <option value="14">直近 14日間</option>
                    <option value="30" selected>直近 30日間（推奨）</option>
                    <option value="45">直近 45日間</option>
                    <option value="60">直近 60日間</option>
                    <option value="90">直近 90日間</option>
                </select>
                <button type="button" onclick="loadUrgentSlots(true)" class="btn" style="padding:5px 12px; font-size:12px; font-weight:700; background:#ffffff; color:#334155; border:1px solid #cbd5e1; border-radius:6px; cursor:pointer;">
                    🔄 最新に更新
                </button>
            </div>
        </div>

        <div id="urgentCardsBody">
            <div style="text-align:center; padding:50px 20px; color:#64748b;">
                <div style="font-size:28px; margin-bottom:8px;">⏳</div>
                <div style="font-size:14px; font-weight:bold;">大腸内視鏡の直近空き枠を計算しています...</div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         【モードB】🔍 条件指定検索ビュー
    ════════════════════════════════════════════════════════════ -->
    <div id="customViewContainer" style="display: <?= $search_mode === 'custom' ? 'block' : 'none' ?>;">
        <form method="GET" id="searchForm">
            <input type="hidden" name="mode" value="custom">
        <input type="hidden" name="search_clicked" id="searchClicked" value="0">

        <div class="filter-row">
            <span class="group-label-btn" style="cursor:default;" title="期間指定">
                期間 <span style="font-size:10px; color:#94a3b8;">(必須)</span>
            </span>
            <div class="btn-group-items">
                <input type="text" name="date_from" id="date_from" class="onokai-date-picker" 
                       value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off"
                       <?= $is_month_mode ? 'disabled' : '' ?>>
                <span style="font-weight: bold; color: #64748b; margin: 0 4px;">〜</span>
                <input type="text" name="date_to" id="date_to" class="onokai-date-picker" 
                       value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off"
                       <?= $is_month_mode ? 'disabled' : '' ?>>
                
                <span style="display:flex; gap:4px; margin-left:8px;">
                    <?php foreach ($month_shortcuts as $m): 
                        $val = $m['key'];
                        $is_act = in_array($val, $selected_months, true) || in_array((string)$m['month'], $selected_months, true);
                    ?>
                        <button type="button" class="btn month-btn <?= $is_act ? 'active' : '' ?>" 
                                data-month="<?= $val ?>" onclick="toggleMonth(this)">
                            <?= $m['month'] ?>月
                        </button>
                    <?php endforeach; ?>
                </span>
                <input type="hidden" name="months" id="monthsInput" value="<?= implode(',', $selected_months) ?>">
            </div>
        </div>

        <div class="filter-row">
            <span class="group-label-btn" style="cursor:default;">診療科</span>
            <div class="btn-group-items">
                <button type="button" class="btn btn-dept <?= $selected_dept === 'PROCT' ? 'active' : '' ?>" data-dept="PROCT">肛門科</button>
                <button type="button" class="btn btn-dept <?= $selected_dept === 'GASTRO' ? 'active' : '' ?>" data-dept="GASTRO">胃腸科</button>
                <input type="hidden" name="dept" id="deptInput" value="<?= $selected_dept ?>">
            </div>
        </div>

        <div class="filter-row" data-row-group="doctor">
            <button type="button" class="group-label-btn" onclick="toggleLabelGroup('doctor')" title="クリックで全選択/全解除">
                医師 <span class="icon-toggle">⇅</span>
            </button>
            <div class="btn-group-items" id="doctorContainer">
                <button type="button" class="btn <?= in_array('all', $selected_doctors, true) ? 'active-all' : '' ?>" data-group="doctor" data-value="all">全医師</button>
                <?php foreach ($doctors_list as $doc): ?>
                    <button type="button" class="btn <?= in_array($doc, $selected_doctors, true) ? 'active' : '' ?>" 
                            data-group="doctor" data-value="<?= htmlspecialchars((string)$doc, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string)$doc, ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="doctor" id="doctorInput" value="<?= implode(',', $selected_doctors) ?>">
        </div>

        <div class="filter-row" data-row-group="exam">
            <button type="button" class="group-label-btn" onclick="toggleLabelGroup('exam')" title="クリックで全選択/全解除">
                検査 <span class="icon-toggle">⇅</span>
            </button>
            <div class="btn-group-items" id="examContainer">
                <button type="button" class="btn <?= in_array('all', $selected_exams, true) ? 'active-all' : '' ?>" data-group="exam" data-value="all">全種別</button>
                <button type="button" class="btn <?= in_array('GIF', $selected_exams, true) ? 'active' : '' ?>" data-group="exam" data-value="GIF">GIF</button>
                <button type="button" class="btn <?= in_array('エコー', $selected_exams, true) ? 'active' : '' ?>" data-group="exam" data-value="エコー">エコー</button>
                <button type="button" class="btn <?= in_array('TCF', $selected_exams, true) ? 'active' : '' ?>" data-group="exam" data-value="TCF">TCF</button>
            </div>
            <input type="hidden" name="exam" id="examInput" value="<?= implode(',', $selected_exams) ?>">
        </div>

        <div class="filter-row" data-row-group="weekday">
            <button type="button" class="group-label-btn" onclick="toggleLabelGroup('weekday')" title="クリックで全選択/全解除">
                曜日 <span class="icon-toggle">⇅</span>
            </button>
            <div class="btn-group-items" id="weekdayContainer">
                <button type="button" class="btn <?= in_array('all', $weekdays, true) ? 'active-all' : '' ?>" data-group="weekday" data-value="all">全曜日</button>
                <?php foreach ($week_ja as $i => $label): ?>
                    <button type="button" class="btn <?= (in_array((string)$i, $weekdays, true) && !in_array('all', $weekdays, true)) ? 'active' : '' ?>" 
                            data-group="weekday" data-value="<?= $i ?>">
                        <?= $label ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="weekdays" id="weekdaysInput" value="<?= implode(',', $weekdays) ?>">
        </div>

        <div class="actions">
            <button type="submit" class="btn-search" id="btnSearchCustom">🔍 検索</button>
            <button type="button" class="btn-reset" onclick="resetForm()">↺ リセット</button>
        </div>
    </form>

    <!-- ローディング表示 -->
        <div id="customSearchLoading" class="custom-search-loading">
            <div class="spinner-dot"></div>
            <span>条件に一致する空き枠を検索しています...</span>
        </div>

        <div id="customTooManyBox" class="error-box" style="display: <?= $too_many_count > 0 ? 'block' : 'none' ?>;">
            <h3 id="customTooManyTitle">⚠️ 検索結果が300件を超えました（<span id="customTooManyNum"><?= number_format($too_many_count) ?></span> 件）</h3>
            <p>条件（医師・曜日・月など）を絞り込んで再検索してください。</p>
        </div>

        <div id="customResultSection" style="display: <?= ($should_search && $too_many_count === 0) ? 'block' : 'none' ?>;">
            <div class="result-header">
                <div class="count">📊 検索結果: <span id="customResultCount"><?= $result_count ?></span> 件</div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div class="custom-view-toggle" role="group" aria-label="表示形式切替">
                        <button type="button" class="custom-view-btn" id="btnViewTable" onclick="setCustomViewMode('table')" title="表形式で詳細確認">
                            📄 テーブル
                        </button>
                        <button type="button" class="custom-view-btn" id="btnViewCards" onclick="setCustomViewMode('cards')" title="高密度カード形式で一覧確認">
                            🗂️ コンパクトカード
                        </button>
                    </div>
                </div>
            </div>

            <!-- 0件表示 -->
            <div id="customNoResults" class="no-results" style="display: <?= ($should_search && $too_many_count === 0 && empty($results)) ? 'block' : 'none' ?>;">
                該当する空き枠がありませんでした。
            </div>

            <!-- テーブルコンテナ -->
            <div id="customTableContainer" style="display: none;">
                <table class="result-table" aria-label="空き枠検索結果一覧">
                    <thead>
                        <tr>
                            <th scope="col">日付</th>
                            <th scope="col">曜日</th>
                            <th scope="col">診療科</th>
                            <th scope="col">医師</th>
                            <th scope="col">検査種別</th>
                            <th scope="col">残枠 / 定員</th>
                        </tr>
                    </thead>
                    <tbody id="customTableBody">
                        <?php foreach ($results as $row): 
                            $exam = (string)($row['exam_type'] ?? '');
                            $date_str = (string)$row['exam_date'];
                            $dow = (int)date('w', strtotime($date_str));
                            $dep_name = (string)($row['department_name'] ?? '');
                            $dep_tag_cls = (string)($row['dept_tag_cls'] ?? 'tag-gastro');
                        ?>
                            <tr class="clickable" tabindex="0" role="button" onclick="goToSchedule('<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string)($row['department_code'] ?? 'GASTRO'), ENT_QUOTES, 'UTF-8') ?>')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); goToSchedule('<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string)($row['department_code'] ?? 'GASTRO'), ENT_QUOTES, 'UTF-8') ?>');}">
                                <td class="tabular-nums"><?= date('Y/m/d', strtotime($date_str)) ?></td>
                                <td><?= $week_ja[$dow] ?? '' ?></td>
                                <td><span class="urgent-dept-tag <?= $dep_tag_cls ?>"><?= htmlspecialchars($dep_name, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string)($row['doctor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="exam-tag <?= getExamClass($exam) ?>"><?= getExamIcon($exam) ?> <?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="tabular-nums">
                                    <span class="free-slots <?= getFreeColorClass((int)$row['free_slots']) ?>"><?= (int)$row['free_slots'] ?></span>
                                    <span style="color:#64748b; font-size:12px;"> / <?= (int)$row['max_slots'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- コンパクトカードコンテナ -->
            <div id="customCardsContainer" style="display: none;">
                <div class="compact-cards-grid" id="customCardsGrid">
                    <?php foreach ($results as $row): 
                        $exam = (string)($row['exam_type'] ?? '');
                        $date_str = (string)$row['exam_date'];
                        $dow = (int)date('w', strtotime($date_str));
                        $dow_ja_str = $week_ja[$dow] ?? '';
                        $dow_cls = ($dow === 6) ? 'dow-sat' : (($dow === 0) ? 'dow-sun' : 'dow-weekday');
                        $dep_code = (string)($row['department_code'] ?? 'GASTRO');
                        $dep_name = (string)($row['department_name'] ?? '胃腸科');
                        $dep_cls = ($dep_code === 'GASTRO') ? 'gastro' : 'proct';
                        $doc_name = (string)($row['doctor_name'] ?? '');
                        $doc_short = (string)($row['doctor_short'] ?? mb_substr($doc_name, 0, 1));
                        $doc_color = (string)($row['doctor_color'] ?? '#334155');
                        $free_cls = getFreeColorClass((int)$row['free_slots']);
                        $y = date('Y', strtotime($date_str));
                        $m = (int)date('m', strtotime($date_str));
                    ?>
                        <div class="compact-card">
                            <div class="compact-card-header">
                                <div class="compact-card-date">
                                    <span>📅 <?= date('Y/m/d', strtotime($date_str)) ?></span>
                                    <span class="compact-card-dow <?= $dow_cls ?>"><?= $dow_ja_str ?></span>
                                </div>
                                <span class="compact-card-dept <?= $dep_cls ?>"><?= htmlspecialchars($dep_name, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>

                            <div class="compact-card-body">
                                <div class="compact-doc-info">
                                    <span class="compact-doc-avatar" style="background: <?= $doc_color ?>;"><?= htmlspecialchars($doc_short, ENT_QUOTES, 'UTF-8') ?></span>
                                    <div>
                                        <div class="compact-doc-name"><?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?> 先生</div>
                                        <span class="exam-tag <?= getExamClass($exam) ?>" style="font-size:10px; padding:1px 6px;">
                                            <?= getExamIcon($exam) ?> <?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="compact-slot-info">
                                    <span class="compact-slot-count free-slots <?= $free_cls ?>">残 <?= (int)$row['free_slots'] ?> 枠</span>
                                    <span class="compact-slot-max">定員 <?= (int)$row['max_slots'] ?></span>
                                </div>
                            </div>

                            <div class="compact-card-footer">
                                <a href="month_schedule.php?dept=<?= urlencode($dep_code) ?>&year=<?= $y ?>&month=<?= $m ?>&date=<?= urlencode($date_str) ?>&display_mode=remain" 
                                   class="compact-btn compact-btn-cal urgent-btn-cal" 
                                   data-cal-preview-date="<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>" 
                                   title="当日の予約状況をプレビュー (ホバー)">
                                    📅 カレンダー
                                </a>
                                <button type="button" 
                                        onclick="jumpAndBookSlot('<?= htmlspecialchars($dep_code, ENT_QUOTES, 'UTF-8') ?>', <?= $y ?>, <?= $m ?>, '<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?>')" 
                                        class="compact-btn compact-btn-book" 
                                        title="新規予約登録へ">
                                    ➕ 予約
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($debug_mode && !empty($executed_sql)): ?>
            <div class="sql-box"><?= htmlspecialchars($executed_sql, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div><!-- /#customViewContainer -->
</div>

<!-- ── カレンダー状況ホバープレビュー用 ツールチップ ── -->
<div id="calPreviewTooltip" class="cal-preview-tooltip" role="tooltip" aria-hidden="true"></div>

<script>
const allDoctors = <?= json_encode($all_doctors) ?>;
const doctorMetaMap = <?= json_encode($doctor_meta_map) ?>;
let currentSearchMode = '<?= $search_mode ?>';
let urgentSlotCache = null;
let currentUrgentDoctor = 'all';

// 条件指定検索用状態
let calPreviewCache = <?= !empty($initial_cal_previews) ? json_encode($initial_cal_previews, JSON_UNESCAPED_UNICODE) : '{}' ?>;
let currentCustomViewMode = localStorage.getItem('endoscope_custom_view_mode') || 'table';
let autoSearchTimer = null;

document.addEventListener('DOMContentLoaded', function() {
    initButtons('doctor', '#doctorContainer');
    initButtons('exam', '#examContainer');
    initButtons('weekday', '#weekdayContainer');

    // 初期表示モード適用
    setCustomViewMode(currentCustomViewMode);

    if (currentSearchMode === 'urgent') {
        loadUrgentSlots(false);
    } else if (currentSearchMode === 'custom') {
        // 条件指定検索モードの場合、結果セクションが非表示なら自動検索
        const resultSection = document.getElementById('customResultSection');
        if (!resultSection || resultSection.style.display === 'none') {
            runCustomSearchAjax();
        }
    }
});

// ── モード切替（タブ） ──
function switchSearchMode(targetMode) {
    currentSearchMode = targetMode;
    const tabUrgent = document.getElementById('tabModeUrgent');
    const tabCustom = document.getElementById('tabModeCustom');
    const viewUrgent = document.getElementById('urgentViewContainer');
    const viewCustom = document.getElementById('customViewContainer');

    if (targetMode === 'urgent') {
        tabUrgent.classList.add('active');
        tabCustom.classList.remove('active');
        viewUrgent.style.display = 'block';
        viewCustom.style.display = 'none';

        if (!urgentSlotCache) {
            loadUrgentSlots(false);
        }
    } else {
        tabCustom.classList.add('active');
        tabUrgent.classList.remove('active');
        viewCustom.style.display = 'block';
        viewUrgent.style.display = 'none';

        // 条件指定検索タブ初回表示時、もし結果エリアが空なら自動検索
        const resultSection = document.getElementById('customResultSection');
        const tableRows = document.querySelectorAll('#customTableBody tr');
        const cardItems = document.querySelectorAll('#customCardsGrid .compact-card');
        if (!resultSection || resultSection.style.display === 'none' || (tableRows.length === 0 && cardItems.length === 0)) {
            runCustomSearchAjax();
        }
    }
}

// ── 表示モード切替（テーブル ⇔ コンパクトカード） ──
function setCustomViewMode(mode) {
    currentCustomViewMode = mode;
    localStorage.setItem('endoscope_custom_view_mode', mode);

    const btnTable = document.getElementById('btnViewTable');
    const btnCards = document.getElementById('btnViewCards');
    const containerTable = document.getElementById('customTableContainer');
    const containerCards = document.getElementById('customCardsContainer');
    const noResults = document.getElementById('customNoResults');

    const isNoResults = noResults && noResults.style.display === 'block';

    if (mode === 'cards') {
        if (btnCards) btnCards.classList.add('active');
        if (btnTable) btnTable.classList.remove('active');
        if (containerCards && !isNoResults) containerCards.style.display = 'block';
        if (containerTable) containerTable.style.display = 'none';
    } else {
        if (btnTable) btnTable.classList.add('active');
        if (btnCards) btnCards.classList.remove('active');
        if (containerTable && !isNoResults) containerTable.style.display = 'block';
        if (containerCards) containerCards.style.display = 'none';
    }
}

// ── 条件変更の検知 ＆ 検索ボタンPulse点滅 ＆ デバウンス自動検索 ──
function triggerConditionChange() {
    const btnSearch = document.getElementById('btnSearchCustom');
    if (btnSearch) {
        btnSearch.classList.add('pulse');
    }

    if (autoSearchTimer) {
        clearTimeout(autoSearchTimer);
    }
    // 250ms デバウンスで自動検索
    autoSearchTimer = setTimeout(() => {
        runCustomSearchAjax();
    }, 250);
}

// ── 条件指定 Ajaxリアルタイム検索 ──
async function runCustomSearchAjax() {
    const form = document.getElementById('searchForm');
    if (!form) return;

    const loading = document.getElementById('customSearchLoading');
    const resultSection = document.getElementById('customResultSection');
    const tooManyBox = document.getElementById('customTooManyBox');
    const noResults = document.getElementById('customNoResults');
    const btnSearch = document.getElementById('btnSearchCustom');

    const months = document.getElementById('monthsInput').value;
    const from = document.getElementById('date_from').value;
    const to = document.getElementById('date_to').value;
    if (!months && (!from || !to)) {
        return;
    }

    if (loading) loading.style.display = 'flex';
    if (resultSection && resultSection.style.display === 'block') {
        resultSection.style.opacity = '0.6';
    }

    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.set('ajax', '1');
    params.set('search_clicked', '1');

    try {
        const res = await fetch(`slot_search.php?${params.toString()}&_t=${Date.now()}`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || '検索エラーが発生しました');
        }

        // プレビューキャッシュのマージ
        if (data.calendar_previews) {
            Object.assign(calPreviewCache, data.calendar_previews);
        }

        if (data.too_many) {
            if (tooManyBox) {
                tooManyBox.style.display = 'block';
                const numSpan = document.getElementById('customTooManyNum');
                if (numSpan) numSpan.textContent = data.too_many_count.toLocaleString();
            }
            if (resultSection) resultSection.style.display = 'none';
        } else {
            if (tooManyBox) tooManyBox.style.display = 'none';
            if (resultSection) {
                resultSection.style.display = 'block';
                resultSection.style.opacity = '1';
            }

            const countSpan = document.getElementById('customResultCount');
            if (countSpan) countSpan.textContent = data.count;

            if (data.count === 0) {
                if (noResults) noResults.style.display = 'block';
                const tbl = document.getElementById('customTableContainer');
                const crd = document.getElementById('customCardsContainer');
                if (tbl) tbl.style.display = 'none';
                if (crd) crd.style.display = 'none';
            } else {
                if (noResults) noResults.style.display = 'none';
                renderCustomTable(data.results || []);
                renderCustomCards(data.results || []);
                setCustomViewMode(currentCustomViewMode);
            }
        }

        // URLパラメータ同期（リロード時再現用）
        params.delete('ajax');
        const newUrl = `${window.location.pathname}?${params.toString()}`;
        window.history.replaceState({}, '', newUrl);

    } catch (err) {
        console.error('runCustomSearchAjax error:', err);
    } finally {
        if (loading) loading.style.display = 'none';
        if (resultSection) resultSection.style.opacity = '1';
        if (btnSearch) btnSearch.classList.remove('pulse');
    }
}

// ── テーブル動的描画 ──
function renderCustomTable(results) {
    const tbody = document.getElementById('customTableBody');
    if (!tbody) return;

    if (results.length === 0) {
        tbody.innerHTML = '';
        return;
    }

    let html = '';
    results.forEach(r => {
        const depCls = r.department_code === 'GASTRO' ? 'tag-gastro' : 'tag-proct';
        html += `
            <tr class="clickable" tabindex="0" role="button" onclick="goToSchedule('${r.exam_date}', '${r.department_code}')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); goToSchedule('${r.exam_date}', '${r.department_code}');}">
                <td class="tabular-nums">${r.exam_date.replace(/-/g, '/')}</td>
                <td>${r.dow_ja}</td>
                <td><span class="urgent-dept-tag ${depCls}">${escapeHtml(r.department_name)}</span></td>
                <td>${escapeHtml(r.doctor_name)}</td>
                <td><span class="exam-tag ${r.exam_cls}">${r.exam_icon} ${escapeHtml(r.exam_type)}</span></td>
                <td class="tabular-nums">
                    <span class="free-slots ${r.free_color_cls}">${r.free_slots}</span>
                    <span style="color:#64748b; font-size:12px;"> / ${r.max_slots}</span>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

// ── コンパクトカード動的描画 ──
function renderCustomCards(results) {
    const grid = document.getElementById('customCardsGrid');
    if (!grid) return;

    if (results.length === 0) {
        grid.innerHTML = '';
        return;
    }

    let html = '';
    results.forEach(r => {
        let dowCls = 'dow-weekday';
        if (r.dow === 6) dowCls = 'dow-sat';
        else if (r.dow === 0) dowCls = 'dow-sun';

        const depCls = r.department_code === 'GASTRO' ? 'gastro' : 'proct';
        const docShort = r.doctor_short || r.doctor_name.charAt(0);
        const docColor = r.doctor_color || '#334155';

        html += `
            <div class="compact-card">
                <div class="compact-card-header">
                    <div class="compact-card-date">
                        <span>📅 ${r.exam_date.replace(/-/g, '/')}</span>
                        <span class="compact-card-dow ${dowCls}">${r.dow_ja}</span>
                    </div>
                    <span class="compact-card-dept ${depCls}">${escapeHtml(r.department_name)}</span>
                </div>

                <div class="compact-card-body">
                    <div class="compact-doc-info">
                        <span class="compact-doc-avatar" style="background: ${docColor};">${escapeHtml(docShort)}</span>
                        <div>
                            <div class="compact-doc-name">${escapeHtml(r.doctor_name)} 先生</div>
                            <span class="exam-tag ${r.exam_cls}" style="font-size:10px; padding:1px 6px;">
                                ${r.exam_icon} ${escapeHtml(r.exam_type)}
                            </span>
                        </div>
                    </div>

                    <div class="compact-slot-info">
                        <span class="compact-slot-count free-slots ${r.free_color_cls}">残 ${r.free_slots} 枠</span>
                        <span class="compact-slot-max">定員 ${r.max_slots}</span>
                    </div>
                </div>

                <div class="compact-card-footer">
                    <a href="${r.jump_url}" 
                       class="compact-btn compact-btn-cal urgent-btn-cal" 
                       data-cal-preview-date="${r.exam_date}" 
                       title="当日の予約状況をプレビュー (ホバー)">
                        📅 カレンダー
                    </a>
                    <button type="button" 
                            onclick="jumpAndBookSlot('${r.department_code}', ${r.year}, ${r.month}, '${r.exam_date}', '${escapeHtml(r.doctor_name)}')" 
                            class="compact-btn compact-btn-book" 
                            title="新規予約登録へ">
                        ➕ 予約
                    </button>
                </div>
            </div>
        `;
    });
    grid.innerHTML = html;
}

// ── 直近速報データ取得 ──
async function loadUrgentSlots(forceReload = false) {
    const body = document.getElementById('urgentCardsBody');
    const daysSelect = document.getElementById('urgentDaysSelect');
    const days = daysSelect ? daysSelect.value : 30;

    const tabTitle = document.getElementById('urgentTabTitle');
    if (tabTitle) {
        tabTitle.textContent = `⚡ 直近空き枠速報（全科横断・${days}日大腸CF）`;
    }

    if (!urgentSlotCache || forceReload) {
        if (body) {
            body.innerHTML = `
                <div style="text-align:center; padding:50px 20px; color:#64748b;">
                    <div style="font-size:28px; margin-bottom:8px;">⏳</div>
                    <div style="font-size:14px; font-weight:bold;">大腸内視鏡の直近空き枠を計算しています...</div>
                </div>
            `;
        }

        try {
            const res = await fetch(`action_slot_assistant.php?action=get_urgent_slots&days=${days}&_t=${Date.now()}`);
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || '空き枠取得エラー');
            }
            urgentSlotCache = data;
            if (data.calendar_previews) {
                Object.assign(calPreviewCache, data.calendar_previews);
            }
            updateUrgentBadges(data);
        } catch (err) {
            console.error('loadUrgentSlots error:', err);
            if (body) {
                body.innerHTML = `
                    <div style="text-align:center; padding:40px 20px; color:#dc2626;">
                        <div style="font-size:26px; margin-bottom:6px;">⚠️</div>
                        <div style="font-weight:bold;">空き枠データの取得に失敗しました</div>
                        <div style="font-size:12px; color:#64748b; margin-top:4px;">${escapeHtml(err.message)}</div>
                    </div>
                `;
            }
            return;
        }
    }

    renderUrgentSlotCards();
}

function updateUrgentBadges(data) {
    const summary = data.summary || {};
    const totalFree = summary.total_free || 0;
    const cancelFree = summary.total_cancel_free || 0;
    const byDoc = summary.by_doctor || {};

    const tabBadge = document.getElementById('tabUrgentBadge');
    if (tabBadge) {
        tabBadge.textContent = totalFree > 0 ? `${totalFree}枠` : '速報';
    }

    const bAll = document.getElementById('badgeUrgentAll');
    if (bAll) bAll.textContent = totalFree;

    const bCancel = document.getElementById('badgeUrgentCancel');
    if (bCancel) bCancel.textContent = cancelFree;

    const bSeigo = document.getElementById('badgeUrgentSeigo');
    if (bSeigo) bSeigo.textContent = byDoc['誠吾'] || 0;

    const bTetsuya = document.getElementById('badgeUrgentTetsuya');
    if (bTetsuya) bTetsuya.textContent = byDoc['哲也'] || 0;

    const bYoshitomo = document.getElementById('badgeUrgentYoshitomo');
    if (bYoshitomo) bYoshitomo.textContent = byDoc['義智'] || 0;

    const bKeitaro = document.getElementById('badgeUrgentKeitaro');
    if (bKeitaro) bKeitaro.textContent = byDoc['慶太郎'] || 0;
}

function filterUrgentByDoctor(doc) {
    currentUrgentDoctor = doc;
    document.querySelectorAll('#urgentDoctorTabs .urgent-doc-btn').forEach(btn => {
        if (btn.getAttribute('data-doctor') === doc) {
            btn.classList.add('active');
            if (doc === '誠吾') btn.classList.add('doc-seigo');
            else if (doc === '哲也') btn.classList.add('doc-tetsuya');
            else if (doc === '義智') btn.classList.add('doc-yoshitomo');
            else if (doc === '慶太郎') btn.classList.add('doc-keitaro');
        } else {
            btn.classList.remove('active', 'doc-seigo', 'doc-tetsuya', 'doc-yoshitomo', 'doc-keitaro');
        }
    });
    renderUrgentSlotCards();
}

function handleUrgentCancelToggle() {
    renderUrgentSlotCards();
}

function handleUrgentDaysChange() {
    loadUrgentSlots(true);
}

function renderUrgentSlotCards() {
    const body = document.getElementById('urgentCardsBody');
    if (!body || !urgentSlotCache) return;

    const onlyCancel = document.getElementById('urgentOnlyCancelToggle')?.checked || false;
    const allSlots = urgentSlotCache.slots || [];

    const filtered = allSlots.filter(s => {
        if (currentUrgentDoctor !== 'all' && s.doctor !== currentUrgentDoctor) {
            return false;
        }
        if (onlyCancel && !s.has_cancel) {
            return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        body.innerHTML = `
            <div style="text-align:center; padding:60px 20px; color:#64748b; background:#fff; border-radius:10px; border:1px dashed #cbd5e1; margin-top:10px;">
                <div style="font-size:36px; margin-bottom:12px;">📋</div>
                <div style="font-size:15px; font-weight:bold; color:#1e293b;">該当する大腸内視鏡の空き枠はありません</div>
                <div style="font-size:12.5px; margin-top:6px; color:#64748b;">
                    ${currentUrgentDoctor !== 'all' ? `担当医「${currentUrgentDoctor}」` : ''} 
                    ${onlyCancel ? 'キャンセル空き枠' : ''} の条件では現在すべて予約済です。
                </div>
                <button type="button" onclick="filterUrgentByDoctor('all'); document.getElementById('urgentOnlyCancelToggle').checked=false; renderUrgentSlotCards();" class="btn" style="margin-top:16px; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:6px 16px; border-radius:6px; font-weight:700; cursor:pointer;">
                    すべての空き枠を表示する
                </button>
            </div>
        `;
        return;
    }

    let html = '<div class="urgent-cards-grid">';
    filtered.forEach(s => {
        let dowCls = 'dow-weekday';
        if (s.dow === '土') dowCls = 'dow-sat';
        else if (s.dow === '日') dowCls = 'dow-sun';

        let relCls = '';
        if (s.day_diff === 0) relCls = 'today';
        else if (s.day_diff === 1) relCls = 'tomorrow';
        else if (s.day_diff === 2) relCls = 'day2';

        const pct = Math.min(100, Math.round((s.used / s.cap) * 100));

        html += `
        <div class="urgent-card ${s.has_cancel ? 'is-cancel' : ''}">
            <div>
                <div class="urgent-card-header">
                    <div class="urgent-card-date">
                        <span class="urgent-card-date-main">📅 ${s.date}</span>
                        <span class="urgent-card-dow ${dowCls}">${s.dow}</span>
                        <span class="urgent-relative-badge ${relCls}">${s.relative_label}</span>
                    </div>
                    <span class="urgent-dept-tag">${s.dept_name}</span>
                </div>

                <div class="urgent-card-doctor">
                    <span class="urgent-doc-badge-pill" style="background:${s.color};">
                        👨‍⚕️ ${s.doctor} 先生
                    </span>
                    ${s.has_cancel ? '<span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:10.5px; font-weight:800; padding:1px 7px; border-radius:4px;">⚡ キャンセル空き</span>' : ''}
                </div>

                <!-- 空きメーター -->
                <div class="urgent-slot-meter-area">
                    <div class="urgent-meter-labels">
                        <span>予約進捗 (使用: ${s.used} / 定員: ${s.cap})</span>
                        <span class="urgent-free-highlight">残 ${s.free} 枠</span>
                    </div>
                    <div class="urgent-meter-track">
                        <div class="urgent-meter-fill" style="width:${pct}%; background:${s.color};"></div>
                    </div>
                </div>

                <!-- キャンセル詳細 -->
                ${s.has_cancel && s.cancel_details && s.cancel_details.length > 0 ? `
                    <div class="urgent-cancel-notice">
                        <div class="urgent-cancel-title">
                            <span>⚡</span>
                            <span>${escapeHtml(s.cancel_details[0].reason_label || '直前キャンセル')} (${escapeHtml(s.cancel_details[0].time_ago)})</span>
                        </div>
                        <div>${escapeHtml(s.cancel_details[0].patient_name)} 様 (${escapeHtml(s.cancel_details[0].exam_type)}) の取消枠</div>
                    </div>
                ` : ''}
            </div>

            <!-- アクション -->
            <div class="urgent-card-actions">
                <a href="${s.jump_url}" class="urgent-action-btn urgent-btn-cal" data-cal-preview-date="${s.date}" title="該当日のカレンダー・予約一覧へ移動 (ホバーで当日の全科枠状況プレビュー)">
                    📅 カレンダー表示
                </a>
                <button type="button" onclick="jumpAndBookSlot('${s.dept}', ${s.year}, ${s.month}, '${s.date}', '${s.doctor}')" class="urgent-action-btn urgent-btn-book" title="この枠で新規予約登録を開始">
                    ➕ この枠で予約
                </button>
            </div>
        </div>
        `;
    });
    html += '</div>';

    body.innerHTML = html;
}

// ── カレンダー状況ホバープレビュー ツールチップ制御 ──
async function showCalPreviewTooltip(btn, dateStr) {
    const tooltip = document.getElementById('calPreviewTooltip');
    if (!tooltip) return;

    let dayData = calPreviewCache[dateStr];

    // キャッシュにない場合、オンデマンドで取得
    if (!dayData) {
        try {
            const res = await fetch(`action_slot_assistant.php?action=get_calendar_preview&date=${dateStr}&_t=${Date.now()}`);
            const data = await res.json();
            if (data.success && data.calendar_previews && data.calendar_previews[dateStr]) {
                dayData = data.calendar_previews[dateStr];
                calPreviewCache[dateStr] = dayData;
            }
        } catch (e) {
            console.error('Failed to fetch calendar preview on demand:', e);
        }
    }

    if (!dayData) return;

    tooltip.innerHTML = generateCalPreviewHTML(dayData);
    tooltip.classList.add('show');
    tooltip.setAttribute('aria-hidden', 'false');

    // 位置計算
    const rect = btn.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    const padding = 10;

    let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
    if (left < padding) left = padding;
    if (left + tooltipRect.width > window.innerWidth - padding) {
        left = window.innerWidth - tooltipRect.width - padding;
    }

    let top = rect.top - tooltipRect.height - 10;
    if (top < padding) {
        top = rect.bottom + 10;
    }

    tooltip.style.left = `${Math.round(left)}px`;
    tooltip.style.top = `${Math.round(top)}px`;
}

function hideCalPreviewTooltip() {
    const tooltip = document.getElementById('calPreviewTooltip');
    if (tooltip && tooltip.classList.contains('show')) {
        tooltip.classList.remove('show');
        tooltip.setAttribute('aria-hidden', 'true');
    }
}

function generateCalPreviewHTML(dayData) {
    let dowCls = 'weekday';
    if (dayData.dow === '土') dowCls = 'sat';
    else if (dayData.dow === '日') dowCls = 'sun';

    let closedBadge = '';
    if (dayData.is_closed) {
        const hName = dayData.holiday_name ? ` (${escapeHtml(dayData.holiday_name)})` : '';
        closedBadge = `<span class="cpt-closed-badge">⛔ 休診${hName}</span>`;
    }

    let html = `
        <div class="cpt-header">
            <span class="cpt-title">
                <span>📅 ${dayData.date}</span>
                <span class="cpt-dow ${dowCls}">${dayData.dow}</span>
            </span>
            ${closedBadge}
        </div>
    `;

    // 胃腸科と肛門科の2段表示
    const depts = dayData.departments || {};
    const deptOrder = ['GASTRO', 'PROCT'];

    deptOrder.forEach(depCode => {
        const depInfo = depts[depCode];
        if (!depInfo) return;

        const isGastro = depCode === 'GASTRO';
        const deptIcon = isGastro ? '🔵' : '🟢';
        const deptClass = isGastro ? 'gastro' : 'proct';

        html += `
        <div class="cpt-dept-section">
            <div class="cpt-dept-title">
                <span class="cpt-dept-name ${deptClass}">
                    <span>${deptIcon}</span>
                    <span>${escapeHtml(depInfo.name)}</span>
                </span>
            </div>
        `;

        const docs = depInfo.doctors || [];
        if (docs.length === 0) {
            html += `<div style="font-size:10.5px; color:#94a3b8; padding:3px 2px;">枠設定なし / 休診</div>`;
        } else {
            docs.forEach(d => {
                const sGif = d.slots.GIF;
                const sEcho = d.slots.ECHO;
                const sTcf = d.slots.TCF;

                let slotBadges = '';
                if (sGif && (sGif.max > 0 || sGif.used > 0)) {
                    let cls = '';
                    if (sGif.used > sGif.max) cls = 'is-over';
                    else if (sGif.used === sGif.max && sGif.max > 0) cls = 'is-full';
                    slotBadges += `<span class="cpt-slot-pill ${cls}">🔵${sGif.used}/${sGif.max}</span>`;
                }
                if (sEcho && (sEcho.max > 0 || sEcho.used > 0)) {
                    let cls = '';
                    if (sEcho.used > sEcho.max) cls = 'is-over';
                    else if (sEcho.used === sEcho.max && sEcho.max > 0) cls = 'is-full';
                    slotBadges += `<span class="cpt-slot-pill ${cls}">🟠${sEcho.used}/${sEcho.max}</span>`;
                }
                if (sTcf && (sTcf.max > 0 || sTcf.used > 0)) {
                    let cls = '';
                    if (sTcf.used > sTcf.max) cls = 'is-over';
                    else if (sTcf.used === sTcf.max && sTcf.max > 0) cls = 'is-full';
                    slotBadges += `<span class="cpt-slot-pill ${cls}">🟢${sTcf.used}/${sTcf.max}</span>`;
                }

                html += `
                <div class="cpt-doc-row">
                    <span class="cpt-doc-icon ${d.cls}" title="${escapeHtml(d.name)}">${escapeHtml(d.short)}</span>
                    <div class="cpt-slot-badges">
                        ${slotBadges || '<span style="font-size:10px; color:#94a3b8;">枠なし</span>'}
                    </div>
                </div>
                `;
            });

            // 全体集計バー
            const ov = depInfo.overall || {};
            const ovParts = [];
            if (ov.GIF && (ov.GIF.max > 0 || ov.GIF.used > 0)) {
                ovParts.push(`🔵${ov.GIF.used}/${ov.GIF.max}`);
            }
            if (ov.ECHO && (ov.ECHO.max > 0 || ov.ECHO.used > 0)) {
                ovParts.push(`🟠${ov.ECHO.used}/${ov.ECHO.max}`);
            }
            if (ov.TCF && (ov.TCF.max > 0 || ov.TCF.used > 0)) {
                ovParts.push(`🟢${ov.TCF.used}/${ov.TCF.max}`);
            }

            if (ovParts.length > 0) {
                html += `
                <div class="cpt-overall-bar">
                    <span class="overall-label">全体</span>
                    <span>${ovParts.join(' ')}</span>
                </div>
                `;
            }
        }

        html += `</div>`;
    });

    // 日別メモ＆医師予定
    const memos = dayData.memos || {};
    const events = dayData.doctor_events || [];
    const memoKeys = Object.keys(memos);

    if (memoKeys.length > 0 || events.length > 0) {
        html += `<div class="cpt-extras">`;
        memoKeys.forEach(mDep => {
            const mText = memos[mDep];
            if (mText && mText.trim() !== '') {
                const depTag = mDep === 'GASTRO' ? '胃腸科' : '肛門科';
                html += `<div class="cpt-memo-item"><span>📝</span> <span>[${depTag}] ${escapeHtml(mText)}</span></div>`;
            }
        });
        events.forEach(ev => {
            html += `<div class="cpt-event-item"><span>🙅🏼</span> <span>${escapeHtml(ev.label || ev.raw_title || '医師不在')}</span></div>`;
        });
        html += `</div>`;
    }

    return html;
}

// イベントリスナーの登録（マウスオーバー・アウト・スクロール・ESC）
document.addEventListener('mouseover', function(e) {
    const btn = e.target.closest('.urgent-btn-cal');
    if (btn && btn.dataset.calPreviewDate) {
        showCalPreviewTooltip(btn, btn.dataset.calPreviewDate);
    }
});

document.addEventListener('mouseout', function(e) {
    const btn = e.target.closest('.urgent-btn-cal');
    if (btn) {
        hideCalPreviewTooltip();
    }
});

window.addEventListener('scroll', hideCalPreviewTooltip, { passive: true });
window.addEventListener('resize', hideCalPreviewTooltip, { passive: true });
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideCalPreviewTooltip();
});

function jumpAndBookSlot(dept, year, month, dateStr, doctorName) {
    window.location.href = `month_schedule.php?dept=${encodeURIComponent(dept)}&year=${year}&month=${month}&date=${encodeURIComponent(dateStr)}&open_new=1&target_doctor=${encodeURIComponent(doctorName)}`;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toggleMonth(btn) {
    btn.classList.toggle('active');
    
    var selected = [];
    document.querySelectorAll('.month-btn.active').forEach(function(b) {
        selected.push(b.dataset.month);
    });
    
    document.getElementById('monthsInput').value = selected.join(',');
    
    var dateFrom = document.getElementById('date_from');
    var dateTo = document.getElementById('date_to');
    
    if (selected.length > 0) {
        selected.sort();
        var first = selected[0];
        var last = selected[selected.length - 1];
        
        var partsFirst = first.split('-');
        var y1 = partsFirst[0], m1 = ('0' + partsFirst[1]).slice(-2);
        dateFrom.value = y1 + '-' + m1 + '-01';
        
        var partsLast = last.split('-');
        var y2 = parseInt(partsLast[0], 10), m2 = parseInt(partsLast[1], 10);
        var lastDay = new Date(y2, m2, 0).getDate();
        dateTo.value = y2 + '-' + ('0' + m2).slice(-2) + '-' + ('0' + lastDay).slice(-2);
        
        dateFrom.disabled = true;
        dateTo.disabled = true;
        if (window.dateFromControl) {
            window.dateFromControl.setDate(dateFrom.value);
            window.dateFromControl.setDisabled(true);
        }
        if (window.dateToControl) {
            window.dateToControl.setDate(dateTo.value);
            window.dateToControl.setDisabled(true);
        }
    } else {
        dateFrom.disabled = false;
        dateTo.disabled = false;
        if (window.dateFromControl) window.dateFromControl.setDisabled(false);
        if (window.dateToControl) window.dateToControl.setDisabled(false);
    }

    triggerConditionChange();
}

// 診療科選択の切り替え
document.querySelectorAll('.btn-dept').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var dept = this.dataset.dept;
        document.querySelectorAll('.btn-dept').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.getElementById('deptInput').value = dept;
        
        var container = document.getElementById('doctorContainer');
        var html = '<button type="button" class="btn active-all" data-group="doctor" data-value="all">全医師</button>';
        (allDoctors[dept] || []).forEach(function(doc) {
            html += '<button type="button" class="btn" data-group="doctor" data-value="' + doc + '">' + doc + '</button>';
        });
        container.innerHTML = html;
        document.getElementById('doctorInput').value = 'all';
        initButtons('doctor', '#doctorContainer');

        triggerConditionChange();
    });
});

function initButtons(groupName, containerSelector) {
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    container.querySelectorAll('.btn[data-group="' + groupName + '"]').forEach(function(btn) {
        btn.onclick = function() {
            var val = this.dataset.value;
            var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
            var allBtn = container.querySelector('.btn[data-value="all"]');
            var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
            
            if (val === 'all') {
                // 「全医師/全種別/全曜日」ボタンをクリックした時
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
                triggerConditionChange();
                return;
            }
            
            // 個別ボタンをクリックした時
            if (allBtn.classList.contains('active-all')) {
                // 「全」選択状態から個別ボタンを押した場合、そのボタンだけを選択！
                allBtn.classList.remove('active-all');
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
            } else {
                // 個別選択中ならトグル（複数選択の追加/解除）
                this.classList.toggle('active');
            }
            
            var selected = [];
            itemBtns.forEach(function(b) {
                if (b.classList.contains('active')) selected.push(b.dataset.value);
            });
            
            if (selected.length === 0) {
                // すべて解除されて0人になった場合は自動で「全選択」に戻す
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else {
                // 1人以上選択されている場合はその選択を維持（※全員選ばれても勝手に解除しない！）
                allBtn.classList.remove('active-all');
                input.value = selected.join(',');
            }

            triggerConditionChange();
        };
    });
}

function toggleLabelGroup(groupName) {
    var containerSelector = '#' + (groupName === 'weekday' ? 'weekday' : groupName) + 'Container';
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
    var allBtn = container.querySelector('.btn[data-value="all"]');
    var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
    
    var isCurrentlyAll = allBtn.classList.contains('active-all');
    
    if (isCurrentlyAll) {
        // 「全」状態からトグル押下 ➔ 全員の個別ボタンをONにして複数選択状態にする
        allBtn.classList.remove('active-all');
        var selected = [];
        itemBtns.forEach(function(b) { 
            b.classList.add('active'); 
            selected.push(b.dataset.value);
        });
        input.value = selected.join(',');
    } else {
        // 個別選択中からトグル押下 ➔ 「全」に戻す
        itemBtns.forEach(function(b) { b.classList.remove('active'); });
        allBtn.classList.add('active-all');
        input.value = 'all';
    }

    triggerConditionChange();
}

// フォーム送信時はAjax検索を即時実行
document.getElementById('searchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (autoSearchTimer) clearTimeout(autoSearchTimer);
    runCustomSearchAjax();
});

function resetForm() {
    window.location.href = 'slot_search.php?mode=custom';
}

function goToSchedule(date, dept) {
    var d = dept || document.getElementById('deptInput').value || 'GASTRO';
    if (d === 'all') d = 'GASTRO';
    var parts = date.split('-');
    var url = 'month_schedule.php?dept=' + encodeURIComponent(d)
            + '&year=' + encodeURIComponent(parts[0])
            + '&month=' + encodeURIComponent(parseInt(parts[1], 10))
            + '&date=' + encodeURIComponent(date)
            + '&display_mode=remain';
    window.location.href = url;
}
</script>


<script>
window.dateFromControl = null;
window.dateToControl = null;

function initSlotSearchDatePicker() {
    if (window.OnokaiDatePicker) {
        // 🌟 小野会日付ピッカー（角丸・西暦/和暦切替・特大Flatpickr）を期間検索にバインド
        window.dateFromControl = OnokaiDatePicker.bindControl('#date_from', {
            onChange: function(dates, dateStr) {
                if (window.dateToControl && window.dateToControl.flatpickr) {
                    window.dateToControl.flatpickr.set('minDate', dateStr);
                }
                triggerConditionChange();
            }
        });

        window.dateToControl = OnokaiDatePicker.bindControl('#date_to', {
            minDate: document.getElementById('date_from').value || undefined
        ,
            onChange: function() {
                triggerConditionChange();
            }
        });
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSlotSearchDatePicker);
} else {
    initSlotSearchDatePicker();
}
</script>
</body>
</html>