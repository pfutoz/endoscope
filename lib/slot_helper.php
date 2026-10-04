<?php
/**
 * lib/slot_helper.php - 枠数関連関数
 * 修正: 出勤例外を最優先に変更 (2026-08-12)
 * 修正: 曜日パターン取得を横持ち対応 (2026-08-14)
 * 修正: デバッグ用に実行SQLを記録する機能を追加 (2026-08-26)
 * 
 * 優先順位:
 *   1. 出勤例外 (m2_doctor_attendance) ← 最優先！
 *   2. 日別上書き (m2_doctor_daily_capacity_v2)
 *   3. 曜日パターン (m2_doctor_slot_pattern) ← 横持ち対応
 */

/**
 * 医師の出勤状況と枠数を取得
 * 
 * @param PDO $pdo
 * @param string $doctor_name
 * @param string $department_code
 * @param string $date_str
 * @param array|null &$debug_sql デバッグ用SQL記録配列（参照渡し）
 * @return array
 */
function getDoctorStatus($pdo, $doctor_name, $department_code, $date_str, &$debug_sql = null) {
    $day_of_week = (int)date('w', strtotime($date_str));
    
    // ★★★ 1. 出勤例外を最優先チェック ★★★
    $sql_attendance = "
        SELECT gif_slots, echo_slots, tcf_slots, pattern_name, reason 
        FROM m2_doctor_attendance 
        WHERE doctor_name = :doctor 
          AND department_code = :dept 
          AND target_date = :date
    ";
    $stmt = $pdo->prepare($sql_attendance);
    $stmt->execute([':doctor' => $doctor_name, ':dept' => $department_code, ':date' => $date_str]);
    $attendance = $stmt->fetch();
    
    // デバッグ用にSQLを記録
    if ($debug_sql !== null) {
        $debug_sql[] = [
            'type' => 'attendance',
            'sql' => "SELECT gif_slots, echo_slots, tcf_slots, pattern_name, reason FROM m2_doctor_attendance WHERE doctor_name = '$doctor_name' AND department_code = '$department_code' AND target_date = '$date_str'",
            'result' => $attendance ? 'hit' : 'miss',
            'data' => $attendance ?: null
        ];
    }
    
    if ($attendance) {
        $slots = [
            'GIF' => (int)($attendance['gif_slots'] ?? 0),
            'ECHO' => (int)($attendance['echo_slots'] ?? 0),
            'TCF' => (int)($attendance['tcf_slots'] ?? 0)
        ];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => true,
            'reason' => $attendance['reason'] ?? $attendance['pattern_name'] ?? '出勤例外',
            'is_attendance' => true
        ];
    }
    
    // ★★★ 2. 日別上書きをチェック ★★★
    $sql_daily = "
        SELECT gif_slots, echo_slots, tcf_slots, memo_jp 
        FROM m2_doctor_daily_capacity_v2 
        WHERE doctor_name = :doctor 
          AND department_code = :dept 
          AND target_date = :date
    ";
    $stmt = $pdo->prepare($sql_daily);
    $stmt->execute([':doctor' => $doctor_name, ':dept' => $department_code, ':date' => $date_str]);
    $daily = $stmt->fetch();
    
    // デバッグ用にSQLを記録
    if ($debug_sql !== null) {
        $debug_sql[] = [
            'type' => 'daily_capacity',
            'sql' => "SELECT gif_slots, echo_slots, tcf_slots, memo_jp FROM m2_doctor_daily_capacity_v2 WHERE doctor_name = '$doctor_name' AND department_code = '$department_code' AND target_date = '$date_str'",
            'result' => $daily ? 'hit' : 'miss',
            'data' => $daily ?: null
        ];
    }
    
    if ($daily) {
        $slots = [
            'GIF' => (int)($daily['gif_slots'] ?? 0),
            'ECHO' => (int)($daily['echo_slots'] ?? 0),
            'TCF' => (int)($daily['tcf_slots'] ?? 0)
        ];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => true,
            'reason' => $daily['memo_jp'] ?? '日別上書き',
            'is_attendance' => false
        ];
    }
    
    // ★★★ 3. 曜日パターンをチェック（横持ち対応） ★★★
    $sql_pattern = "
        SELECT 
            MAX(gif_slots) as gif_slots,
            MAX(echo_slots) as echo_slots,
            MAX(tcf_slots) as tcf_slots
        FROM m2_doctor_slot_pattern 
        WHERE doctor_name = :doctor 
          AND department_code = :dept 
          AND day_of_week = :dow 
          AND is_active = TRUE
    ";
    $stmt = $pdo->prepare($sql_pattern);
    $stmt->execute([':doctor' => $doctor_name, ':dept' => $department_code, ':dow' => $day_of_week]);
    $row = $stmt->fetch();
    
    // デバッグ用にSQLを記録
    if ($debug_sql !== null) {
        $debug_sql[] = [
            'type' => 'slot_pattern',
            'sql' => "SELECT MAX(gif_slots) as gif_slots, MAX(echo_slots) as echo_slots, MAX(tcf_slots) as tcf_slots FROM m2_doctor_slot_pattern WHERE doctor_name = '$doctor_name' AND department_code = '$department_code' AND day_of_week = $day_of_week AND is_active = TRUE",
            'result' => $row ? 'hit' : 'miss',
            'data' => $row ?: null
        ];
    }
    
    $slots = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0)
    ];
    
    return [
        'present' => array_sum($slots) > 0,
        'slots' => $slots,
        'is_exception' => false,
        'reason' => null,
        'is_attendance' => false
    ];
}

function getSlotAvailability($pdo, $department_code, $date_str) {
    $stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order");
    $stmt->execute([':dept' => $department_code]);
    $all_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $result = [
        'total' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0],
        'doctors' => [],
        'present_doctors' => []
    ];
    
    foreach ($all_doctors as $doctor_name) {
        $status = getDoctorStatus($pdo, $doctor_name, $department_code, $date_str);
        $result['doctors'][$doctor_name] = $status;
        if ($status['present']) {
            $result['present_doctors'][] = $doctor_name;
            foreach ($status['slots'] as $type => $count) {
                $result['total'][$type] += $count;
            }
        }
    }
    
    // 使用状況を取得
    $stmt = $pdo->prepare("
        SELECT s.exam_type, COUNT(*) as used_count 
        FROM t_endoscopy_schedule s 
        WHERE s.department_code = :dept 
          AND s.exam_date = :date 
          AND s.is_canceled = FALSE 
        GROUP BY s.exam_type
    ");
    $stmt->execute([':dept' => $department_code, ':date' => $date_str]);
    $used_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $used = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    $tcf_group = ['TCF', 'W', 'ポリペク'];
    
    foreach ($used_raw as $exam_type => $count) {
        if (in_array($exam_type, $tcf_group)) {
            $used['TCF'] += $count;
        } else {
            $stmt = $pdo->prepare("SELECT slot_group FROM m_exam_type_group WHERE exam_type = :exam_type AND is_active = TRUE");
            $stmt->execute([':exam_type' => $exam_type]);
            $group_row = $stmt->fetch();
            $group = $group_row ? $group_row['slot_group'] : null;
            if ($group && isset($used[$group])) {
                $used[$group] += $count;
            }
        }
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

function getWakuList($pdo, $dept) {
    $stmt = $pdo->prepare("
        SELECT waku_name, group_code, display_order 
        FROM m2_waku 
        WHERE department_code = :dept AND is_active = TRUE 
        ORDER BY display_order ASC
    ");
    $stmt->execute([':dept' => $dept]);
    return $stmt->fetchAll();
}

function getDoctorsForDept($pdo, $dept) {
    $stmt = $pdo->prepare("
        SELECT doctor_name 
        FROM m2_doctor 
        WHERE department_code = :dept AND is_active = TRUE 
        ORDER BY display_order
    ");
    $stmt->execute([':dept' => $dept]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}