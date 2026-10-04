<?php
/**
 * 生成日時: 2026-07-15 21:35:00
 * 共通関数・データベース接続モジュール
 */

// データベース接続を取得する共通関数
function getDbConnection() {
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
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

// 新規登録時に古い schedule_id の混入を完全にシャットアウトする共通ガード
function sanitizeActionTypeAndId($actionType, $scheduleId) {
    $action = trim($actionType);
    $id = (int)$scheduleId;
    
    if ($action === 'insert') {
        $id = 0;
    }
    return [$action, $id];
}

// 💡 予約保存・カルテID変更時に、MALL3から最新メモをスクレイピングして t_mall_memo へ保存する共通関数
function syncAndSaveMallMemo($pdo, $patientId) {
    if (empty($patientId) || $patientId === '---' || !is_numeric($patientId)) {
        return;
    }

    try {
        // 1. 基本情報APIから氏名・生年月日を取得[cite: 2]
        $patient_url = "http://localhost/endoscope/get_mall_patient.php?patient_no=" . $patientId;
        $patient_json = @file_get_contents($patient_url);
        $patient_name = '';
        $birth_date = null;

        if ($patient_json !== false) {
            $p_res = json_decode($patient_json, true);
            if (isset($p_res['success']) && $p_res['success']) {
                $patient_name = isset($p_res['patient_name']) ? trim($p_res['patient_name']) : '';
                $birth_date = !empty($p_res['birthday']) ? $p_res['birthday'] : null;
            }
        }

        // 2. メモ情報APIから3種の最新メモを取得[cite: 2]
        $api_url = "http://localhost/endoscope/get_mall_memos.php?patient_no=" . $patientId;
        $json_data = @file_get_contents($api_url);

        if ($json_data === false) {
            return;
        }

        $res = json_decode($json_data, true);
        if (!$res || !isset($res['success']) || !$res['success']) {
            return;
        }

        $memo_today   = null;
        $memo_karte   = null;
        $memo_patient = null;

        foreach ($res['memos'] as $memo) {
            $div_id = (int)$memo['div_id'];
            if ($div_id === 1)  $memo_today   = $memo['note'];
            if ($div_id === 2)  $memo_karte   = $memo['note'];
            if ($div_id === 3)  $memo_patient = $memo['note'];
        }

        // 3. PostgreSQL（public.t_mall_memo）へUPSERT保存[cite: 2]
        $sync_sql = "INSERT INTO public.t_mall_memo 
                        (patient_id, patient_name, birth_date, memo_today, memo_karte, memo_patient) 
                     VALUES 
                        (:pid, :pname, :birth, :today, :karte, :patient)
                     ON CONFLICT (patient_id) 
                     DO UPDATE SET 
                        patient_name = EXCLUDED.patient_name,
                        birth_date   = EXCLUDED.birth_date,
                        memo_today   = EXCLUDED.memo_today,
                        memo_karte   = EXCLUDED.memo_karte,
                        memo_patient = EXCLUDED.memo_patient";
                        
        $stmt = $pdo->prepare($sync_sql);
        $stmt->execute([
            ':pid'     => $patientId,
            ':pname'   => $patient_name,
            ':birth'   => $birth_date,
            ':today'   => $memo_today,
            ':karte'   => $memo_karte,
            ':patient' => $memo_patient
        ]);

    } catch (Exception $ex) {
        // メモ同期の例外が起きても予約データの保存自体は止めない
        error_log("Mall memo sync failed: " . $ex->getMessage());
    }
}


// ============================================================
// common_functions.php に追加する関数
// ============================================================

/**
 * 指定された日付の医師の出勤状態と使用パターンを取得
 * 
 * @param PDO $pdo
 * @param string $doctor_name
 * @param string $department_code
 * @param string $date_str (Y-m-d形式)
 * @return array {
 *   present: bool,        // 出勤しているか
 *   pattern_name: string, // 使用するパターン名（'通常' or '土曜特別' etc）
 *   reason: string|null,  // 理由（例外設定時のみ）
 *   slots: array          // そのパターンの枠数（slot_type => max_slots）
 * }
 */
function getDoctorStatus($pdo, $doctor_name, $department_code, $date_str) {
    $day_of_week = (int)date('w', strtotime($date_str));
    
    // 1. 例外レコードをチェック（特定日パターン）
    $stmt = $pdo->prepare("
        SELECT pattern_name, reason 
        FROM m_doctor_attendance 
        WHERE doctor_name = :doctor_name 
          AND department_code = :dept 
          AND target_date = :date
    ");
    $stmt->execute([
        ':doctor_name' => $doctor_name,
        ':dept' => $department_code,
        ':date' => $date_str
    ]);
    $exception = $stmt->fetch();
    
    $pattern_name = null;
    $reason = null;
    
    if ($exception) {
        // 例外あり → そのパターンを使用
        $pattern_name = $exception['pattern_name'];
        $reason = $exception['reason'];
    } else {
        // 例外なし → デフォルト（通常）パターン
        $pattern_name = '通常';
    }
    
    // 2. パターンの枠数を取得
    $stmt = $pdo->prepare("
        SELECT slot_type, max_slots
        FROM m_doctor_slot_pattern
        WHERE doctor_name = :doctor_name
          AND department_code = :dept
          AND pattern_name = :pattern
          AND day_of_week = :dow
          AND is_active = TRUE
    ");
    $stmt->execute([
        ':doctor_name' => $doctor_name,
        ':dept' => $department_code,
        ':pattern' => $pattern_name,
        ':dow' => $day_of_week
    ]);
    $rows = $stmt->fetchAll();
    
    $slots = [];
    foreach ($rows as $row) {
        $slots[$row['slot_type']] = (int)$row['max_slots'];
    }
    
    // パターンが存在しない = 休診
    if (empty($slots)) {
        return [
            'present' => false,
            'pattern_name' => $pattern_name,
            'reason' => $reason ?: 'この曜日のパターンが設定されていません',
            'slots' => []
        ];
    }
    
    // すべての枠が0の場合は休診扱い
    $total = array_sum($slots);
    if ($total === 0) {
        return [
            'present' => false,
            'pattern_name' => $pattern_name,
            'reason' => $reason ?: '休診（枠数0）',
            'slots' => $slots
        ];
    }
    
    return [
        'present' => true,
        'pattern_name' => $pattern_name,
        'reason' => $reason,
        'slots' => $slots
    ];
}

/**
 * 指定された診療科・日付の全医師の出勤状態を取得
 * 
 * @param PDO $pdo
 * @param string $department_code
 * @param string $date_str
 * @return array 医師ごとのステータス配列
 */
function getDoctorsStatus($pdo, $department_code, $date_str) {
    // その科に所属する全医師を取得
    $stmt = $pdo->prepare("
        SELECT doctor_name 
        FROM m_doctor 
        WHERE department_code = :dept AND is_active = TRUE
        ORDER BY display_order
    ");
    $stmt->execute([':dept' => $department_code]);
    $all_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $result = [];
    foreach ($all_doctors as $doctor) {
        $result[$doctor] = getDoctorStatus($pdo, $doctor, $department_code, $date_str);
    }
    
    return $result;
}

/**
 * 指定された診療科・日付の合計枠数を取得（種別別・医師別内訳含む）
 * 
 * @param PDO $pdo
 * @param string $department_code
 * @param string $date_str
 * @return array {
 *   total: [GIF=>int, ECHO=>int, TCF=>int],
 *   doctors: [doctor_name => [GIF=>int, ECHO=>int, TCF=>int]],
 *   present_doctors: [doctor_name, ...]
 * }
 */
function getTotalSlotsForDate($pdo, $department_code, $date_str) {
    $doctors_status = getDoctorsStatus($pdo, $department_code, $date_str);
    
    $result = [
        'total' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0],
        'doctors' => [],
        'present_doctors' => []
    ];
    
    foreach ($doctors_status as $doctor_name => $status) {
        $result['doctors'][$doctor_name] = [
            'present' => $status['present'],
            'pattern' => $status['pattern_name'],
            'reason' => $status['reason'],
            'slots' => $status['slots']
        ];
        
        if ($status['present']) {
            $result['present_doctors'][] = $doctor_name;
            foreach ($status['slots'] as $type => $count) {
                if (isset($result['total'][$type])) {
                    $result['total'][$type] += $count;
                }
            }
        }
    }
    
    return $result;
}

/**
 * 指定された日付の予約使用状況を取得（種別別）
 * 
 * @param PDO $pdo
 * @param string $department_code
 * @param string $date_str
 * @return array {
 *   used: [GIF=>int, ECHO=>int, TCF=>int],
 *   total: int,
 *   details: [exam_type => count, ...]
 * }
 */
function getUsedSlotsForDate($pdo, $department_code, $date_str) {
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
    
    // 種別グループにマッピング
    $used = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    foreach ($used_raw as $exam_type => $count) {
        $group = getSlotGroupForExamType($pdo, $exam_type);
        if ($group && isset($used[$group])) {
            $used[$group] += $count;
        }
    }
    
    return [
        'used' => $used,
        'total' => array_sum($used),
        'details' => $used_raw
    ];
}

/**
 * 検査種別からグループ名を取得
 */
function getSlotGroupForExamType($pdo, $exam_type) {
    static $cache = [];
    
    if (isset($cache[$exam_type])) {
        return $cache[$exam_type];
    }
    
    $stmt = $pdo->prepare("
        SELECT slot_group FROM m_exam_type_group 
        WHERE exam_type = :exam_type AND is_active = TRUE
    ");
    $stmt->execute([':exam_type' => $exam_type]);
    $row = $stmt->fetch();
    
    $result = $row ? $row['slot_group'] : null;
    $cache[$exam_type] = $result;
    return $result;
}

/**
 * 指定された日付の空き状況を取得（種別別）
 * 
 * @param PDO $pdo
 * @param string $department_code
 * @param string $date_str
 * @return array {
 *   max: [GIF=>int, ECHO=>int, TCF=>int],
 *   used: [GIF=>int, ECHO=>int, TCF=>int],
 *   free: [GIF=>int, ECHO=>int, TCF=>int],
 *   total: [max=>int, used=>int, free=>int],
 *   doctors: [...]
 * }
 */
function getSlotAvailability($pdo, $department_code, $date_str) {
    $max_slots = getTotalSlotsForDate($pdo, $department_code, $date_str);
    $used_slots = getUsedSlotsForDate($pdo, $department_code, $date_str);
    
    $result = [
        'max' => $max_slots['total'],
        'used' => $used_slots['used'],
        'free' => [],
        'total' => [
            'max' => array_sum($max_slots['total']),
            'used' => $used_slots['total'],
            'free' => array_sum($max_slots['total']) - $used_slots['total']
        ],
        'doctors' => $max_slots['doctors'],
        'present_doctors' => $max_slots['present_doctors']
    ];
    
    foreach (['GIF', 'ECHO', 'TCF'] as $type) {
        $result['free'][$type] = $max_slots['total'][$type] - $used_slots['used'][$type];
    }
    
    return $result;
}