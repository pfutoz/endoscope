import re

path = r'c:\Apache24\htdocs\endoscope\slot_search.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. ファイルヘッダーの更新
old_header_pat = r'(\* 修正: 2026-11-01 【カレンダー状況ホバープレビュー】[^\n]+\n)'
new_header = r'''\1 * 修正: 2026-11-01 【条件指定検索パワーアップ】
 *   - 診療科「全科」対応（胃腸科・肛門科横断検索、医師科バッジ表示）
 *   - 高密度コンパクトカード表示モード追加（テーブル ⇔ カード切替トグル、localStorage永続化）
 *   - リアルタイムAjax自動検索（デバウンス250ms）＆検索ボタンPulse点滅アニメーション
 *   - コンパクトカード内の「📅 カレンダー」ボタンからの当日の枠状況ホバープレビュー連動
'''
content = re.sub(old_header_pat, new_header, content)

# 2. パラメータ受け取りと Ajax エンドポイントの追加、および searchAvailableSlots の更新
old_php_logic_pat = r'(\$selected_dept = isset\(\$_GET\[\'dept\'\]\)[^\n]+\n)(if \(!in_array\(\$selected_dept, \[\'PROCT\', \'GASTRO\'\], true\)\) \{ \$selected_dept = \'GASTRO\'; \})'

new_php_dept = r'''$selected_dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'GASTRO';
if (!in_array($selected_dept, ['PROCT', 'GASTRO', 'all'], true)) { $selected_dept = 'GASTRO'; }'''

content = re.sub(old_php_logic_pat, new_php_dept, content)

# 3. all_doctors と doctor_meta_map の整備
old_doctors_pat = r'(\$all_doctors = \[\];\s*foreach \(\[\'PROCT\', \'GASTRO\'\] as \$dept\) \{\s*\$all_doctors\[\$dept\] = getDoctorsForDept\(\$pdo, \$dept\);\s*\}\s*\$doctors_list = \$all_doctors\[\$selected_dept\] \?\? \[\];)'

new_doctors = r'''$doctor_meta_map = [
    '誠吾' => ['dept' => 'PROCT', 'dept_name' => '肛門科', 'short' => '誠', 'cls' => 'doc-seigo', 'color' => '#059669', 'badge_cls' => 'bg-emerald', 'dept_tag_cls' => 'tag-proct'],
    '哲也' => ['dept' => 'GASTRO', 'dept_name' => '胃腸科', 'short' => '哲', 'cls' => 'doc-tetsuya', 'color' => '#4f46e5', 'badge_cls' => 'bg-cyan', 'dept_tag_cls' => 'tag-gastro'],
    '義智' => ['dept' => 'GASTRO', 'dept_name' => '胃腸科', 'short' => '義', 'cls' => 'doc-yoshitomo', 'color' => '#7c3aed', 'badge_cls' => 'bg-indigo', 'dept_tag_cls' => 'tag-gastro'],
    '慶太郎' => ['dept' => 'GASTRO', 'dept_name' => '胃腸科', 'short' => '慶', 'cls' => 'doc-keitaro', 'color' => '#d97706', 'badge_cls' => 'bg-rose', 'dept_tag_cls' => 'tag-gastro'],
];

$all_doctors = [];
foreach (['PROCT', 'GASTRO'] as $d) {
    $all_doctors[$d] = getDoctorsForDept($pdo, $d);
}
$all_doctors['all'] = ['誠吾', '哲也', '義智', '慶太郎'];
$doctors_list = $all_doctors[$selected_dept] ?? [];'''

content = re.sub(old_doctors_pat, new_doctors, content)

# 4. Ajaxエンドポイントの追加（if ($search_clicked) の後）
old_search_exec_pat = r'(if \(\$search_clicked\) \{.*?\$result_count = count\(\$results\);\s*\}\s*\})'

new_search_exec = r'''\1

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
}'''

content = re.sub(old_search_exec_pat, new_search_exec, content, flags=re.DOTALL)

# 5. searchAvailableSlots 関数とその補助関数の刷新
old_func_pat = r'(function searchAvailableSlots\(\$pdo, \$dept, \$doctors, \$exams, \$weekdays, \$months, \$date_from, \$date_to, \$is_month_mode, &\$executed_sql\) \{.*?return \$results;\s*\})'

new_func = r'''function searchAvailableSlots($pdo, $dept, $doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, &$executed_sql) {
    global $doctor_meta_map, $week_ja;
    $week_ja_map = ['日', '月', '火', '水', '木', '金', '土'];
    
    $depts = ($dept === 'all') ? ['GASTRO', 'PROCT'] : [$dept];
    $all_results = [];
    $total_sql = '';
    
    foreach ($depts as $d_code) {
        $dept_doctors = getDoctorsForDept($pdo, $d_code);
        $target_doctors = [];
        if (in_array('all', $doctors, true)) {
            $target_doctors = ['all'];
        } else {
            $target_doctors = array_values(array_intersect($doctors, $dept_doctors));
            if (empty($target_doctors)) {
                continue;
            }
        }
        
        $sub_sql = '';
        $res = searchAvailableSlotsOneDept($pdo, $d_code, $target_doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, $sub_sql);
        $total_sql .= "\n-- [{$d_code}]\n" . $sub_sql;
        
        $dep_name = ($d_code === 'GASTRO') ? '胃腸科' : '肛門科';
        foreach ($res as $row) {
            $d_name = $row['doctor_name'];
            $meta = $doctor_meta_map[$d_name] ?? [
                'dept' => $d_code,
                'dept_name' => $dep_name,
                'short' => mb_substr($d_name, 0, 1),
                'cls' => '',
                'color' => '#334155',
                'badge_cls' => 'bg-slate',
                'dept_tag_cls' => ($d_code === 'GASTRO') ? 'tag-gastro' : 'tag-proct'
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
                'department_code' => $d_code,
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
                'jump_url' => "month_schedule.php?dept=" . urlencode($d_code) . "&year={$y}&month={$m}&date=" . urlencode($row['exam_date']) . "&display_mode=remain"
            ];
        }
    }
    
    $executed_sql = $total_sql;
    $total_count = count($all_results);
    if ($total_count > 300) {
        $executed_sql .= "\n-- 空き枠検索結果が300件を超えました（{$total_count}件）";
        return ['error' => 'too_many', 'count' => $total_count];
    }
    
    usort($all_results, function($a, $b) {
        if ($a['exam_date'] !== $b['exam_date']) return strcmp($a['exam_date'], $b['exam_date']);
        if ($a['department_code'] !== $b['department_code']) return strcmp($a['department_code'], $b['department_code']);
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

    $executed_sql = "-- 既存予約集計結果: " . $total_reserved_count . " 件\n";
    $executed_sql .= $sql . "\n-- 対象日: " . implode(', ', $dates);

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
}'''

content = re.sub(old_func_pat, new_func, content, flags=re.DOTALL)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Backend update completed successfully!")
