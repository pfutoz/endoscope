<?php
/**
 * matrix_functions.php - マトリクス表示関連関数
 */

function getWakuList($pdo, $dept) {
    $stmt = $pdo->prepare("SELECT waku_name, group_code, display_order FROM m2_waku WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order ASC");
    $stmt->execute([':dept' => $dept]);
    return $stmt->fetchAll();
}

function getSlotAvailability($pdo, $dept, $date_str) {
    $stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order");
    $stmt->execute([':dept' => $dept]);
    $all_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $result = ['total' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0], 'doctors' => [], 'present_doctors' => []];
    foreach ($all_doctors as $doctor_name) {
        $status = getDoctorStatus($pdo, $doctor_name, $dept, $date_str);
        $result['doctors'][$doctor_name] = $status;
        if ($status['present']) {
            $result['present_doctors'][] = $doctor_name;
            foreach ($status['slots'] as $type => $count) { $result['total'][$type] += $count; }
        }
    }
    $stmt = $pdo->prepare("SELECT s.exam_type, COUNT(*) as used_count FROM t_endoscopy_schedule s WHERE s.department_code = :dept AND s.exam_date = :date AND s.is_canceled = FALSE GROUP BY s.exam_type");
    $stmt->execute([':dept' => $dept, ':date' => $date_str]);
    $used_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $used = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    foreach ($used_raw as $exam_type => $count) {
        $stmt = $pdo->prepare("SELECT slot_group FROM m_exam_type_group WHERE exam_type = :exam_type AND is_active = TRUE");
        $stmt->execute([':exam_type' => $exam_type]);
        $group_row = $stmt->fetch();
        $group = $group_row ? $group_row['slot_group'] : null;
        if ($group && isset($used[$group])) { $used[$group] += $count; }
    }
    $result['used'] = $used;
    $result['free'] = [];
    foreach (['GIF', 'ECHO', 'TCF'] as $type) {
        $result['free'][$type] = $result['total'][$type] - $used[$type];
    }
    $result['total_used'] = array_sum($used);
    $result['total_max'] = array_sum($result['total']);
    $result['total_free'] = $result['total_max'] - $result['total_used'];
    return $result;
}

function getDoctorStatus($pdo, $doctor_name, $dept, $date_str) {
    $day_of_week = (int)date('w', strtotime($date_str));
    $stmt = $pdo->prepare("SELECT gif_slots, echo_slots, tcf_slots, memo_jp FROM m2_doctor_daily_capacity_v2 WHERE doctor_name = :doctor AND department_code = :dept AND target_date = :date");
    $stmt->execute([':doctor' => $doctor_name, ':dept' => $dept, ':date' => $date_str]);
    $daily = $stmt->fetch();
    if ($daily) {
        return ['present' => true, 'slots' => ['GIF' => (int)$daily['gif_slots'], 'ECHO' => (int)$daily['echo_slots'], 'TCF' => (int)$daily['tcf_slots']], 'is_exception' => true, 'reason' => $daily['memo_jp']];
    }
    $stmt = $pdo->prepare("SELECT group_code, max_slots FROM m2_doctor_slot_pattern WHERE doctor_name = :doctor AND department_code = :dept AND day_of_week = :dow AND is_active = TRUE");
    $stmt->execute([':doctor' => $doctor_name, ':dept' => $dept, ':dow' => $day_of_week]);
    $rows = $stmt->fetchAll();
    $slots = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    foreach ($rows as $row) {
        if (isset($slots[$row['group_code']])) { $slots[$row['group_code']] = (int)$row['max_slots']; }
    }
    return ['present' => array_sum($slots) > 0, 'slots' => $slots, 'is_exception' => false, 'reason' => null];
}

function getPresentDoctorsForDate($pdo, $dept, $date_str) {
    $day_of_week = (int)date('w', strtotime($date_str));
    $stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order");
    $stmt->execute([':dept' => $dept]);
    $all_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $present_doctors = [];
    foreach ($all_doctors as $doctor_name) {
        $stmt = $pdo->prepare("SELECT SUM(max_slots) as total FROM m2_doctor_slot_pattern WHERE doctor_name = :doctor AND department_code = :dept AND day_of_week = :dow AND is_active = TRUE");
        $stmt->execute([':doctor' => $doctor_name, ':dept' => $dept, ':dow' => $day_of_week]);
        $row = $stmt->fetch();
        $has_default = ($row && (int)$row['total'] > 0);
        $stmt = $pdo->prepare("SELECT gif_slots, echo_slots, tcf_slots FROM m2_doctor_daily_capacity_v2 WHERE doctor_name = :doctor AND department_code = :dept AND target_date = :date");
        $stmt->execute([':doctor' => $doctor_name, ':dept' => $dept, ':date' => $date_str]);
        $daily = $stmt->fetch();
        if ($daily) {
            $has_slots = ((int)$daily['gif_slots'] > 0 || (int)$daily['echo_slots'] > 0 || (int)$daily['tcf_slots'] > 0);
        } else {
            $has_slots = $has_default;
        }
        if ($has_slots) { $present_doctors[] = $doctor_name; }
    }
    return $present_doctors;
}

function calculateAge($birthday, $examDate) {
    if (empty($birthday) || $birthday === '---') return null;
    try {
        $birth = new DateTime($birthday);
        $exam = new DateTime($examDate);
        if ($birth > $exam) return null;
        return $exam->diff($birth)->y;
    } catch (Exception $e) { return null; }
}

function getAgeBadgeHTML($age) {
    if ($age === null) return '';
    if ($age < 65) { $style = "background-color: #e0f2fe; color: #0369a1;"; }
    else if ($age >= 65 && $age <= 74) { $style = "background-color: #fef08a; color: #713f12; font-weight: bold;"; }
    else if ($age >= 75 && $age <= 85) { $style = "background-color: #fca5a5; color: #991b1b; font-weight: bold;"; }
    else { $style = "background-color: #ef4444; color: #ffffff; font-weight: bold;"; }
    return "<span class='age-badge' style='display:inline-block; padding:1px 5px; font-size:10px; border-radius:4px; line-height:1.1; {$style}'>{$age}歳</span>";
}

function getExamBadgeHTML($exam_type) {
    $style = "padding:1px 6px; border-radius:12px; font-size:10px; background-color:#e2e8f0; color:#475569;";
    if ($exam_type === 'GIF') { $style = "padding:1px 6px; border-radius:12px; font-size:10px; background-color:#0ea5e9; color:#fff; font-weight:bold;"; }
    else if ($exam_type === 'TCF') { $style = "padding:1px 6px; border-radius:12px; font-size:10px; background-color:#10b981; color:#fff; font-weight:bold;"; }
    else if ($exam_type === 'ポリペク') { $style = "padding:1px 6px; border-radius:12px; font-size:10px; background-color:#8b5cf6; color:#fff; font-weight:bold;"; }
    else if ($exam_type === 'W') { $style = "padding:1px 6px; border-radius:12px; font-size:10px; background-color:#22c55e; color:#fff; font-weight:bold;"; }
    return "<span class='exam-badge' style='{$style}'>" . htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8') . "</span>";
}

function convertToWareki($birthday) {
    if (empty($birthday) || $birthday === '---') return '---';
    try {
        $date = new DateTime($birthday);
        $y = (int)$date->format('Y'); $m = (int)$date->format('n'); $d = (int)$date->format('j');
        if ($y >= 2019) { $era = '令和'; $year = $y - 2018; }
        else if ($y >= 1989) { $era = '平成'; $year = $y - 1988; }
        else if ($y >= 1926) { $era = '昭和'; $year = $y - 1925; }
        else if ($y >= 1912) { $era = '大正'; $year = $y - 1911; }
        else { $era = '明治'; $year = $y - 1867; }
        if ($year == 1) { return $era . '元年' . $m . '月' . $d . '日'; }
        return $era . $year . '年' . $m . '月' . $d . '日';
    } catch (Exception $e) { return $birthday; }
}

function renderPatientBox($data, $date_str, $prefix = "", $simple_mode = false) {
    $is_canceled = isset($data['is_canceled']) && ($data['is_canceled'] === true || $data['is_canceled'] === 1 || $data['is_canceled'] === 't');
    $is_temporary = (strpos($data['patient_name'] ?? '', '※仮予約') !== false);
    $booking_status = $data['booking_status'] ?? 'normal';
    
    $box_class = 'patient-box';
    if ($is_canceled) { $box_class .= ' is-canceled-box'; }
    elseif ($is_temporary) { $box_class .= ' is-temporary-box'; }
    elseif ($booking_status === 'duplicate') { $box_class .= ' duplicate'; }
    elseif ($booking_status === 'out_of_slot') { $box_class .= ' out-of-slot'; }
    
    $color_map = ['GIF' => '#e0ffff', 'ポリペク' => '#dda0dd', 'TCF' => '#e2f0d9', 'W' => '#c5e0b4'];
    $exam_type = $data['exam_type'] ?? '';
    $custom_bg = (!$is_canceled && !$is_temporary && isset($color_map[$exam_type])) ? "background-color: {$color_map[$exam_type]};" : '';
    
    $draggable = $is_canceled ? 'false' : 'true';
    $tooltip = "【検査】" . ($data['exam_type'] ?? '') . "\n【医師】" . ($data['doctor_name'] ?? '') . "\n【ヨミ】" . ($data['patient_kana'] ?? '未登録') . "\n【備考】\n" . ($data['remarks'] ?? '');
    $age = calculateAge($data['birthday'] ?? null, $date_str);
    $age_badge = getAgeBadgeHTML($age);
    $wareki = convertToWareki($data['birthday'] ?? '');
    $exam_badge = getExamBadgeHTML($exam_type);
    $link_title = "通常クリックで編集・削除\n[Ctrl + クリック] で画像管理サーバを別窓起動";
    
    echo "<div class='{$box_class}' style='{$custom_bg}' draggable='{$draggable}' title='" . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . "' data-schedule-id='" . htmlspecialchars($data['schedule_id'] ?? '', ENT_QUOTES, 'UTF-8') . "' data-json='" . htmlspecialchars(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . "' ondragstart='dragStart(this, event)'>";
    
    if ($booking_status === 'duplicate') { echo "<span class='dup-badge' style='display:inline-block; background:#dc2626; color:#fff; font-size:7px; font-weight:bold; padding:0 4px; border-radius:2px; margin-right:2px;'>⛔重複</span>"; }
    elseif ($booking_status === 'out_of_slot') { echo "<span class='oos-badge' style='display:inline-block; background:#f59e0b; color:#fff; font-size:7px; font-weight:bold; padding:0 4px; border-radius:2px; margin-right:2px;'>⚠️枠外</span>"; }
    
    if ($simple_mode) {
        // シンプル表示（1行）
        echo "<div class='pid-row' style='display:flex; justify-content:space-between; align-items:center;'>";
        echo "  <span style='font-weight:800; color:#1e293b; font-size:9px;'>ID:" . htmlspecialchars($data['patient_id'] ?? '', ENT_QUOTES, 'UTF-8') . "</span>";
        echo "  <span class='pname' style='font-weight:bold; font-size:10px; cursor:pointer; flex:1; margin:0 6px;' onclick='openEditModalWithCtrlCheck(this, event)'>";
        echo "    <span class='real-pname'>" . htmlspecialchars($data['patient_name'] ?? '', ENT_QUOTES, 'UTF-8') . "</span>";
        echo "    <span class='masked-pname' style='display:none; color:#777; font-style:italic;'>●●●●</span>";
        echo "  </span>";
        echo "  " . $exam_badge;
        echo "</div>";
    } else {
        // 詳細表示
        echo "<div class='pid-row' style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px dotted rgba(0,0,0,0.15); padding-bottom:2px; margin-bottom:3px;'>";
        echo "  <span style='font-weight:800; color:#1e293b; font-size:9px;'>ID:" . htmlspecialchars($data['patient_id'] ?? '', ENT_QUOTES, 'UTF-8') . ($is_canceled ? ' [削除]' : '') . "</span>";
        echo "  " . $age_badge;
        echo "</div>";
        echo "<div class='pname-row' style='font-weight:bold; font-size:10px; cursor:pointer;' onclick='openEditModalWithCtrlCheck(this, event)'>";
        echo $prefix;
        echo "<span class='real-pname'>" . htmlspecialchars($data['patient_name'] ?? '', ENT_QUOTES, 'UTF-8') . "</span>";
        echo "<span class='masked-pname' style='display:none; color:#777; font-style:italic;'>●●●●</span>";
        echo "</div>";
        echo "<div class='pinfo-row' style='display:flex; justify-content:space-between; align-items:center; margin-top:3px; padding-top:2px;'>";
        echo "  <span class='pbirthday' style='font-size:9px; color:#64748b;'>" . htmlspecialchars($wareki, ENT_QUOTES, 'UTF-8') . "</span>";
        echo "  " . $exam_badge;
        echo "</div>";
    }
    echo "</div>";
}

function renderDateCell($date_str, $dept, $slot_info, $week_ja, $simple_mode = false) {
    $ts = strtotime($date_str);
    $day = date('j', $ts);
    $w = date('w', $ts);
    $day_check = check_onokai_calendar($date_str, null);
    $is_closed = $day_check['is_closed'];
    $is_today = ($date_str === date('Y-m-d'));
    $is_past = ($date_str < date('Y-m-d'));
    
    $cell_class = 'fixed-date-col';
    if ($is_today) $cell_class .= ' today';
    if ($is_closed) $cell_class .= ' closed';
    if ($is_past) $cell_class .= ' past';
    
    $has_exception = false;
    foreach ($slot_info['doctors'] as $doc) {
        if ($doc['is_exception']) { $has_exception = true; break; }
    }
    
    echo "<td class='{$cell_class}' style='" . ($is_today ? 'background-color:#ffffe0 !important;' : ($is_past ? 'background-color:#f1f5f9 !important; color:#94a3b8 !important;' : '')) . "' data-date='{$date_str}' data-dept='{$dept}' onclick='openDateDetailModal(\"{$date_str}\", \"{$dept}\")'>";
    echo "<div class='date-display' style='display:flex; justify-content:space-between; align-items:center; font-weight:bold; font-size:11px;'>";
    echo "  <span class='day-number' style='font-size:12px;'>" . ($is_past ? '' : '') . "{$day}日({$week_ja[$w]})</span>";
    if ($is_closed) { echo "  <span class='status-icon is-closed' style='color:#ef4444;'>⛔</span>"; }
    elseif ($has_exception) { echo "  <span class='status-icon has-exception' style='color:#f59e0b;'>🔵</span>"; }
    echo "</div>";
    
    if ($is_closed) {
        echo "<div class='closed-label' style='font-size:9px; font-weight:bold; color:#ef4444;'>" . htmlspecialchars($day_check['reason'] ?? '休診', ENT_QUOTES, 'UTF-8') . "</div>";
    } else {
        $doc_info = [];
        foreach ($slot_info['doctors'] as $name => $info) {
            $doc_info[] = $info['present'] ? "<span class='present' style='color:#22c55e;'>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</span>" : "<span class='absent' style='color:#ef4444; text-decoration:line-through;'>" . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</span>";
        }
        echo "<div class='doctor-info' style='font-size:8px; color:#555; margin:1px 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>" . implode(' ', $doc_info) . "</div>";
        
        echo "<div class='slot-detail' style='font-size:8px; line-height:1.2; margin:1px 0; text-align:left; padding-left:2px;'>";
        $type_labels = ['GIF' => 'GIF', 'ECHO' => 'エコー', 'TCF' => 'TCF'];
        $type_colors = ['GIF' => '#dbeafe', 'ECHO' => '#fce7f3', 'TCF' => '#d1fae5'];
        foreach (['GIF', 'ECHO', 'TCF'] as $type) {
            $max = $slot_info['total'][$type];
            $used = $slot_info['used'][$type];
            if ($max === 0) continue;
            $free = $max - $used;
            $status_class = ($free <= 0) ? 'full' : (($free <= 2) ? 'warning' : 'ok');
            $bg = $type_colors[$type] ?? '#f1f5f9';
            echo "<span class='slot-item {$status_class}' style='display:inline-block; margin-right:2px; padding:0 3px; border-radius:2px; font-weight:bold; font-size:8px; background:{$bg};'>";
            echo "<span class='label' style='font-weight:normal; color:#888;'>{$type_labels[$type]}</span> {$used}/{$max}";
            echo "</span>";
        }
        echo "</div>";
        
        echo "<div class='total-info' style='font-size:9px; font-weight:bold; border-top:1px solid #e5e7eb; padding-top:2px; margin-top:2px; display:flex; justify-content:space-between;'>";
        echo "  <span>合計</span>";
        echo "  <span>{$slot_info['total_used']}/{$slot_info['total_max']}";
        if ($slot_info['total_free'] > 0) { echo " <span class='total-free' style='color:#22c55e;'>(空き{$slot_info['total_free']})</span>"; }
        elseif ($slot_info['total_max'] > 0) { echo " <span class='total-full' style='color:#ef4444;'>(満席)</span>"; }
        echo "  </span>";
        echo "</div>";
        
        $reasons = [];
        foreach ($slot_info['doctors'] as $name => $info) {
            if ($info['is_exception'] && $info['reason']) { $reasons[] = "{$name}: {$info['reason']}"; }
        }
        if (!empty($reasons)) {
            echo "<div class='exception-reason' style='font-size:7px; color:#f59e0b; background:#fffbeb; padding:0 4px; border-radius:2px; display:inline-block; margin-top:1px; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;' title='" . htmlspecialchars(implode(' | ', $reasons), ENT_QUOTES, 'UTF-8') . "'>";
            echo "⚠️ " . htmlspecialchars($reasons[0], ENT_QUOTES, 'UTF-8');
            if (count($reasons) > 1) echo " 他" . (count($reasons)-1) . "件";
            echo "</div>";
        }
    }
    echo "</td>";
}
?>