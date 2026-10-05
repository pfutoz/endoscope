<?php
/**
 * ファイル名: slot_search.php
 * 設置パス: /endoscope/slot_search.php
 * 生成日時: 2026-08-28 01:25:00 JST
 * 
 * 空き枠検索画面
 * 修正: 2026-08-28 ステップ1（getExamIconの重複排除・共通ライブラリ利用）
 * 修正: 2026-10-05 休診日（祝日・日曜・年末年始）判定時の出勤例外最優先ロジックの統一
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

$all_doctors = [];
foreach (['PROCT', 'GASTRO'] as $dept) {
    $all_doctors[$dept] = getDoctorsForDept($pdo, $dept);
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

$results = [];
$result_count = 0;
$executed_sql = '';
$too_many_count = 0;

if ($search_clicked) {
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
    }
}

function searchAvailableSlots($pdo, $dept, $doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, &$executed_sql) {
    $dates = [];
    $today = date('Y-m-d');
    
    // ★ 出勤例外（m2_doctor_attendance）が登録されている日付を事前に取得（休診日でも出勤例外最優先）
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
                    // 原則休診日は除外するが、出勤例外が登録されている場合は対象とする
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
                // 原則休診日は除外するが、出勤例外が登録されている場合は対象とする
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
    
    // ★ 予約集計（カレンダー画面 month_schedule.php と完全準拠：枠なし除外 ＆ 医師・検査種別グループ集計）
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

    $executed_sql = "-- 既存予約集計結果: " . $total_reserved_count . " 件\n";
    $executed_sql .= $sql . "\n-- 対象日: " . implode(', ', $dates);

    // ★ 期間内の枠マスタ（出勤例外・日別上書き・曜日パターン）を一括プリフェッチ
    $min_date = reset($dates);
    $max_date = end($dates);

    $slot_cache = [
        'attendance' => [],
        'daily_capacity' => [],
        'patterns' => []
    ];

    // 1. 出勤例外一括取得
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

    // 2. 日別上書き一括取得
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

    // 3. 曜日パターン一括取得（★重要: MAXとGROUP BYで複数行による0上書き消失を完全に防止）
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

    $gif_group  = ['GIF', '◆胃がん検診◆', '胃がん検診'];
    $echo_group = ['ECHO', 'エコー'];
    $tcf_group  = ['TCF', 'W', 'ポリペク'];

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
                
                // カレンダー画面と同等のグループ合算で消化数を算出
                $used = 0;
                if ($slot_type === 'GIF') {
                    foreach ($gif_group as $g) { $used += (int)($doc_res[$g] ?? 0); }
                } elseif ($slot_type === 'ECHO') {
                    foreach ($echo_group as $e) { $used += (int)($doc_res[$e] ?? 0); }
                } elseif ($slot_type === 'TCF') {
                    foreach ($tcf_group as $t) { $used += (int)($doc_res[$t] ?? 0); }
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

    // ★ 空き枠件数の上限判定（空き枠自体が300件を超えた場合に絞り込み案内を表示）
    $total_free_count = count($results);
    if ($total_free_count > 300) {
        $executed_sql .= "\n-- 空き枠検索結果が300件を超えました（{$total_free_count}件）\n-- 条件を絞り込んでください";
        return ['error' => 'too_many', 'count' => $total_free_count];
    }
    
    usort($results, function($a, $b) {
        if ($a['exam_date'] !== $b['exam_date']) return strcmp($a['exam_date'], $b['exam_date']);
        if ($a['doctor_name'] !== $b['doctor_name']) return strcmp($a['doctor_name'], $b['doctor_name']);
        $order = ['GIF' => 0, 'エコー' => 1, 'TCF' => 2];
        return ($order[$a['exam_type']] ?? 99) - ($order[$b['exam_type']] ?? 99);
    });
    
    return $results;
}

function getExamClass($exam_type) {
    $map = ['GIF' => 'gif', 'エコー' => 'echo', 'TCF' => 'tcf'];
    return $map[$exam_type] ?? 'other';
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
    </style>
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
            <span>⚡ 直近空き枠速報（全科横断・30日大腸CF）</span>
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
                <input type="date" name="date_from" id="date_from" class="date-input" 
                       value="<?= htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8') ?>"
                       <?= $is_month_mode ? 'disabled' : '' ?>>
                <span>〜</span>
                <input type="date" name="date_to" id="date_to" class="date-input" 
                       value="<?= htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8') ?>"
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
            <button type="submit" class="btn-search">🔍 検索</button>
            <button type="button" class="btn-reset" onclick="resetForm()">↺ リセット</button>
        </div>
    </form>

    <?php if ($too_many_count > 0): ?>
        <div class="error-box">
            <h3>⚠️ 検索結果が300件を超えました（<?= number_format($too_many_count) ?> 件）</h3>
            <p>条件（医師・曜日・月など）を絞り込んで再検索してください。</p>
        </div>
    <?php endif; ?>

    <?php if ($search_clicked && $too_many_count === 0): ?>
        <div class="result-header">
            <div class="count">📊 検索結果: <span><?= $result_count ?></span> 件</div>
            <span style="font-size:12px; color:#64748b;">💡 行クリックで該当日の予約カレンダーへジャンプ</span>
        </div>

        <?php if (empty($results)): ?>
            <div class="no-results">該当する空き枠がありませんでした。</div>
        <?php else: ?>
            <table class="result-table" aria-label="空き枠検索結果一覧">
                <thead>
                    <tr>
                        <th scope="col">日付</th>
                        <th scope="col">曜日</th>
                        <th scope="col">医師</th>
                        <th scope="col">検査種別</th>
                        <th scope="col">残枠 / 定員</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): 
                        $exam = (string)($row['exam_type'] ?? '');
                        $date_str = (string)$row['exam_date'];
                        $dow = (int)date('w', strtotime($date_str));
                    ?>
                        <tr class="clickable" tabindex="0" role="button" aria-label="<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?> <?= $week_ja[$dow] ?? '' ?> <?= htmlspecialchars((string)($row['doctor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?> 残り<?= (int)$row['free_slots'] ?>枠 のカレンダーを開く" onclick="goToSchedule('<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); goToSchedule('<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>');}">
                            <td class="tabular-nums"><?= date('Y/m/d', strtotime($date_str)) ?></td>
                            <td><?= $week_ja[$dow] ?? '' ?></td>
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
        <?php endif; ?>

        <?php if ($debug_mode && !empty($executed_sql)): ?>
            <div class="sql-box"><?= htmlspecialchars($executed_sql, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    <?php endif; ?>
    </div><!-- /#customViewContainer -->
</div>

<script>
const allDoctors = <?= json_encode($all_doctors) ?>;
let currentSearchMode = '<?= $search_mode ?>';
let urgentSlotCache = null;
let currentUrgentDoctor = 'all';

document.addEventListener('DOMContentLoaded', function() {
    initButtons('doctor', '#doctorContainer');
    initButtons('exam', '#examContainer');
    initButtons('weekday', '#weekdayContainer');

    if (currentSearchMode === 'urgent') {
        loadUrgentSlots(false);
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
    }
}

// ── 直近速報データ取得 ──
async function loadUrgentSlots(forceReload = false) {
    const body = document.getElementById('urgentCardsBody');
    const daysSelect = document.getElementById('urgentDaysSelect');
    const days = daysSelect ? daysSelect.value : 30;

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
                <a href="${s.jump_url}" class="urgent-action-btn urgent-btn-cal" title="該当日のカレンダー・予約一覧へ移動">
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
    } else {
        dateFrom.disabled = false;
        dateTo.disabled = false;
    }
}

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
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
                return;
            }
            
            allBtn.classList.remove('active-all');
            this.classList.toggle('active');
            
            var selected = [];
            itemBtns.forEach(function(b) {
                if (b.classList.contains('active')) selected.push(b.dataset.value);
            });
            
            if (selected.length === 0 || selected.length === itemBtns.length) {
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else {
                input.value = selected.join(',');
            }
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
    
    var isCurrentlyActive = allBtn.classList.contains('active-all') || 
        Array.from(itemBtns).some(function(b) { return b.classList.contains('active'); });
    
    if (isCurrentlyActive) {
        allBtn.classList.remove('active-all');
        itemBtns.forEach(function(b) { b.classList.remove('active'); });
        input.value = '';
    } else {
        allBtn.classList.add('active-all');
        itemBtns.forEach(function(b) { b.classList.remove('active'); });
        input.value = 'all';
    }
}

document.getElementById('searchForm').addEventListener('submit', function(e) {
    var months = document.getElementById('monthsInput').value;
    var from = document.getElementById('date_from').value;
    var to = document.getElementById('date_to').value;
    
    if (!months && (!from || !to)) {
        alert('検索期間または月ショートカットを必ず指定してください。');
        e.preventDefault();
        return false;
    }
    
    if (!document.getElementById('doctorInput').value) {
        alert('医師を1名以上選択するか「全医師」にしてください。');
        e.preventDefault();
        return false;
    }
    if (!document.getElementById('examInput').value) {
        alert('検査種別を1つ以上選択するか「全種別」にしてください。');
        e.preventDefault();
        return false;
    }
    if (!document.getElementById('weekdaysInput').value) {
        alert('曜日を1日以上選択するか「全曜日」にしてください。');
        e.preventDefault();
        return false;
    }
    
    document.getElementById('searchClicked').value = '1';
});

function resetForm() {
    window.location.href = 'slot_search.php';
}

function goToSchedule(date) {
    var dept = document.getElementById('deptInput').value || 'GASTRO';
    var parts = date.split('-');
    var url = 'month_schedule.php?dept=' + encodeURIComponent(dept)
            + '&year=' + encodeURIComponent(parts[0])
            + '&month=' + encodeURIComponent(parseInt(parts[1], 10))
            + '&date=' + encodeURIComponent(date)
            + '&display_mode=remain';
    window.location.href = url;
}
</script>

</body>
</html>