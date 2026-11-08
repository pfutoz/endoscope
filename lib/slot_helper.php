<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\slot_helper.php
 * ファイル名: slot_helper.php
 * 概要: 枠数・時間枠関連共通ヘルパー（時間枠世代管理 valid_from〜valid_to ＆ オーバーライド合成対応）
 * 更新日時: 2026-11-06
 * 
 * 修正履歴:
 *   - 2026-08-12: 出勤例外を最優先に変更
 *   - 2026-08-14: 曜日パターン取得を横持ち対応
 *   - 2026-08-26: デバッグ用に実行SQLを記録する機能を追加
 *   - 2026-10-22: 【Phase 1】時間枠マスタ柔軟化（日別・期間別オーバーライド構想）に対応。
 *                 新設関数:
 *                   - getEffectiveWakuList(): 特定日付の有効時間枠をインメモリマージ（0.001ms）で取得
 *                   - getEffectiveWakuMapForPeriod(): カレンダー等の一括プリフェッチ用期間マージ関数
 *                   - findEffectiveWaku(): 枠名・コードからの有効枠情報逆引き
 *   - 2026-10-22: 【Phase 2】画面組み込み共通関数を新設:
 *                   - getDailyReservationsWithEffectiveSlots(): カレンダー・当日印刷用の一体型予約・枠リスト取得関数
 *   - 2026-10-22: 【印刷・一覧互換強化】getDailyReservationsWithEffectiveSlots() において、show_empty=false（空き枠非表示）時であっても、枠休止（is_suspended=true）枠を従来の「※枠なし」書式（patient_name='※枠なし', patient_id='---', doctor_name='◆未定◆'）で常時抽出するよう改修
 *   - 2026-10-26: 【検査種別マスタ共通化】m_exam_type_group と連動したグループ解決共通関数（getExamTypeGroupMap, resolveExamGroup, getExamTypeBadgeInfo）を新設。各モジュールに分散していたハードコード判定を集約可能に。
 *   - 2026-11-06: 【時間枠世代管理対応】m2_waku の有効期間（valid_from 〜 valid_to）に対応。
 *                 getWakuList(), getEffectiveWakuList(), getEffectiveWakuMapForPeriod() にて対象日付・期間の世代を自動選択するよう拡張。
 *                 マスタメンテナンス向けに getWakuGenerations(), getWakuListForGeneration(), formatWakuGenerationPeriodLabel() を新設。
 *   - 2026-11-07: findEffectiveWaku() に $allow_prefix 引数を追加し、単一スロット照合時の誤プレフィックス一致（例: 14:30 と 14:30~15:30）を防止。
 * 
 * 優先順位 (医師枠数):
 *   1. 出勤例外 (m2_doctor_attendance) ← 最優先！
 *   2. 日別上書き (m2_doctor_daily_capacity_v2)
 *   3. 曜日パターン (m2_doctor_slot_pattern) ← 横持ち対応
 * 
 * 優先順位 (時間枠オーバーライド):
 *   1. 特定日オーバーライド (override_type = 'DATE') ← 最優先！
 *   2. 期間指定オーバーライド (override_type = 'PERIOD')
 *   3. 世代対応基本マスタ (m2_waku: target_date BETWEEN valid_from AND valid_to)
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

/**
 * 指定日付（省略時は当日）に有効な時間枠マスタ一覧を取得（有効枠のみ）
 * 
 * @param PDO $pdo
 * @param string $dept
 * @param string|null $target_date 対象日 ('YYYY-MM-DD')。省略時は当日
 * @return array
 */
function getWakuList($pdo, $dept, $target_date = null) {
    $eff_date = !empty($target_date) ? $target_date : date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT w.*, g.group_name 
        FROM m2_waku w
        LEFT JOIN m2_slot_group g ON w.group_code = g.group_code AND w.department_code = g.department_code
        WHERE w.department_code = :dept
          AND w.is_active = TRUE
          AND :target_date BETWEEN w.valid_from AND w.valid_to
        ORDER BY w.display_order ASC, w.id ASC
    ");
    $stmt->execute([':dept' => $dept, ':target_date' => $eff_date]);
    return $stmt->fetchAll();
}

/**
 * 診療科ごとの時間枠マスタ世代（valid_from 〜 valid_to のセット）一覧を取得
 * 
 * @param PDO $pdo
 * @param string $dept 診療科 ('GASTRO' / 'PROCT')
 * @return array 世代情報のリスト（valid_from 昇順）
 */
function getWakuGenerations(PDO $pdo, string $dept): array {
    $stmt = $pdo->prepare("
        SELECT 
            valid_from,
            valid_to,
            COUNT(*) AS total_count,
            SUM(CASE WHEN is_active = TRUE THEN 1 ELSE 0 END) AS active_count
        FROM m2_waku
        WHERE department_code = :dept
        GROUP BY valid_from, valid_to
        ORDER BY valid_from ASC, valid_to ASC
    ");
    $stmt->execute([':dept' => $dept]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = date('Y-m-d');
    $result = [];
    foreach ($rows as $idx => $r) {
        $vf = (string)$r['valid_from'];
        $vt = (string)$r['valid_to'];
        $result[] = [
            'gen_index'    => $idx + 1,
            'valid_from'   => $vf,
            'valid_to'     => $vt,
            'total_count'  => (int)$r['total_count'],
            'active_count' => (int)$r['active_count'],
            'is_current'   => ($vf <= $today && $today <= $vt),
            'label'        => formatWakuGenerationPeriodLabel($vf, $vt),
        ];
    }
    return $result;
}

/**
 * 特定世代（valid_from, valid_to）の時間枠マスタ一覧を取得（マスタメンテ用：非アクティブ含む全件）
 * 
 * @param PDO $pdo
 * @param string $dept 診療科 ('GASTRO' / 'PROCT')
 * @param string $valid_from 開始日 ('YYYY-MM-DD')
 * @param string $valid_to 終了日 ('YYYY-MM-DD')
 * @return array
 */
function getWakuListForGeneration(PDO $pdo, string $dept, string $valid_from, string $valid_to): array {
    $stmt = $pdo->prepare("
        SELECT w.*, g.group_name 
        FROM m2_waku w
        LEFT JOIN m2_slot_group g ON w.group_code = g.group_code AND w.department_code = g.department_code
        WHERE w.department_code = :dept
          AND w.valid_from = :vfrom
          AND w.valid_to = :vto
        ORDER BY w.display_order ASC, w.id ASC
    ");
    $stmt->execute([
        ':dept'  => $dept,
        ':vfrom' => $valid_from,
        ':vto'   => $valid_to,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 世代期間（valid_from, valid_to）を画面表示用ラベルに整形
 * 例: '1900-01-01' ~ '2026-12-31' ➔ '〜 2026.12.31'
 *     '2027-01-01' ~ '9999-12-31' ➔ '2027.1.1 〜 9999.9.9'
 * 
 * @param string $valid_from
 * @param string $valid_to
 * @return string
 */
function formatWakuGenerationPeriodLabel(string $valid_from, string $valid_to): string {
    $from_str = '';
    if ($valid_from !== '' && $valid_from !== '1900-01-01') {
        $ts = strtotime($valid_from);
        $from_str = $ts ? date('Y.n.j', $ts) : $valid_from;
    }

    $to_str = '9999.9.9';
    if ($valid_to !== '' && $valid_to !== '9999-12-31') {
        $ts = strtotime($valid_to);
        $to_str = $ts ? date('Y.n.j', $ts) : $valid_to;
    }

    if ($from_str === '' && $to_str === '9999.9.9') {
        return '全期間（〜 9999.9.9）';
    }
    if ($from_str === '') {
        return '〜 ' . $to_str;
    }
    return $from_str . ' 〜 ' . $to_str;
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

/**
 * 特定日付における有効時間枠一覧をインメモリマージ（0.001ms）で取得
 * 
 * 世代対応基本マスタ（m2_waku: target_date BETWEEN valid_from AND valid_to）を読み込み、
 * 日別・期間別オーバーライド（m2_waku_override）で差分上書き・臨時追加・休止（🚫）を合成して返却する。
 * 
 * @param PDO $pdo
 * @param string $dept 診療科 ('GASTRO' / 'PROCT')
 * @param string|null $target_date 対象日付 ('YYYY-MM-DD')。null の場合は当日世代の静的基本マスタのみ返却
 * @return array 有効枠のリスト（display_order 昇順）
 */
function getEffectiveWakuList($pdo, $dept, $target_date = null) {
    $eff_date = !empty($target_date) ? $target_date : date('Y-m-d');

    // 1. 当該日付の世代に対応する静的基本マスタ（m2_waku）を取得
    $stmt = $pdo->prepare("
        SELECT 
            id,
            department_code,
            group_code,
            waku_code,
            waku_name,
            display_order,
            is_active,
            valid_from,
            valid_to
        FROM m2_waku
        WHERE department_code = :dept
          AND is_active = TRUE
          AND :eff_date BETWEEN valid_from AND valid_to
        ORDER BY display_order ASC, id ASC
    ");
    $stmt->execute([':dept' => $dept, ':eff_date' => $eff_date]);
    $base_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 日付指定がなければ静的マスタを基本フォーマットで返却
    if (empty($target_date)) {
        $result = [];
        foreach ($base_rows as $row) {
            $result[] = [
                'waku_id'         => (int)$row['id'],
                'base_waku_id'    => (int)$row['id'],
                'override_id'     => null,
                'waku_name'       => (string)$row['waku_name'],
                'original_name'   => (string)$row['waku_name'],
                'group_code'      => (string)$row['group_code'],
                'department_code' => (string)$row['department_code'],
                'display_order'   => (int)$row['display_order'],
                'valid_from'      => (string)($row['valid_from'] ?? '1900-01-01'),
                'valid_to'        => (string)($row['valid_to'] ?? '9999-12-31'),
                'is_suspended'    => false,
                'is_override'     => false,
                'override_type'   => null,
                'reason'          => null,
                'time_start'      => null,
                'time_end'        => null,
            ];
        }
        return $result;
    }

    // 2. 当該日付に有効なオーバーライド差分を取得（DATE 優先、PERIOD 次点）
    $sql_override = "
        SELECT 
            override_id,
            department_code,
            base_waku_id,
            override_type,
            target_date,
            start_date,
            end_date,
            display_waku_name,
            group_code,
            time_start,
            time_end,
            display_order,
            is_suspended,
            reason
        FROM m2_waku_override
        WHERE department_code = :dept
          AND is_active = TRUE
          AND (
              (override_type = 'DATE' AND target_date = :target_date)
              OR
              (override_type = 'PERIOD' AND :target_date BETWEEN start_date AND end_date)
          )
        ORDER BY 
            CASE WHEN override_type = 'DATE' THEN 1 ELSE 2 END,
            override_id ASC
    ";
    $stmt_override = $pdo->prepare($sql_override);
    $stmt_override->execute([
        ':dept'        => $dept,
        ':target_date' => $target_date
    ]);
    $overrides = $stmt_override->fetchAll(PDO::FETCH_ASSOC);

    // 3. インメモリマージ（PHP側で合成）
    $merged_slots = [];
    foreach ($base_rows as $b) {
        $merged_slots[$b['id']] = [
            'waku_id'         => (int)$b['id'],
            'base_waku_id'    => (int)$b['id'],
            'override_id'     => null,
            'waku_name'       => (string)$b['waku_name'],
            'original_name'   => (string)$b['waku_name'],
            'group_code'      => (string)$b['group_code'],
            'department_code' => (string)$b['department_code'],
            'display_order'   => (int)$b['display_order'],
            'valid_from'      => (string)($b['valid_from'] ?? '1900-01-01'),
            'valid_to'        => (string)($b['valid_to'] ?? '9999-12-31'),
            'is_suspended'    => false,
            'is_override'     => false,
            'override_type'   => null,
            'reason'          => null,
            'time_start'      => null,
            'time_end'        => null,
        ];
    }

    $extra_slots = [];
    $applied_base_ids = [];

    foreach ($overrides as $ov) {
        $base_id = $ov['base_waku_id'] !== null ? (int)$ov['base_waku_id'] : null;

        if ($base_id !== null && isset($merged_slots[$base_id])) {
            // 既に優先度の高いオーバーライド（DATE）が当たっている場合はスキップ
            if (isset($applied_base_ids[$base_id])) {
                continue;
            }
            $applied_base_ids[$base_id] = true;

            // 基本枠を上書き
            $merged_slots[$base_id]['override_id']   = (int)$ov['override_id'];
            $merged_slots[$base_id]['waku_name']     = (string)$ov['display_waku_name'];
            $merged_slots[$base_id]['group_code']    = (string)$ov['group_code'];
            $merged_slots[$base_id]['is_suspended']  = (bool)$ov['is_suspended'];
            $merged_slots[$base_id]['is_override']   = true;
            $merged_slots[$base_id]['override_type'] = (string)$ov['override_type'];
            $merged_slots[$base_id]['reason']        = $ov['reason'];
            if ($ov['display_order'] !== null) {
                $merged_slots[$base_id]['display_order'] = (int)$ov['display_order'];
            }
            if ($ov['time_start'] !== null) {
                $merged_slots[$base_id]['time_start'] = $ov['time_start'];
            }
            if ($ov['time_end'] !== null) {
                $merged_slots[$base_id]['time_end'] = $ov['time_end'];
            }
        } elseif ($base_id === null) {
            // base_waku_id が NULL の場合は新規臨時枠として追加
            $extra_slots[] = [
                'waku_id'         => null,
                'base_waku_id'    => null,
                'override_id'     => (int)$ov['override_id'],
                'waku_name'       => (string)$ov['display_waku_name'],
                'original_name'   => (string)$ov['display_waku_name'],
                'group_code'      => (string)$ov['group_code'],
                'department_code' => (string)$ov['department_code'],
                'display_order'   => $ov['display_order'] !== null ? (int)$ov['display_order'] : 999,
                'valid_from'      => (string)($ov['start_date'] ?? $target_date),
                'valid_to'        => (string)($ov['end_date'] ?? $target_date),
                'is_suspended'    => (bool)$ov['is_suspended'],
                'is_override'     => true,
                'override_type'   => (string)$ov['override_type'],
                'reason'          => $ov['reason'],
                'time_start'      => $ov['time_start'],
                'time_end'        => $ov['time_end'],
            ];
        }
    }

    $final_list = array_merge(array_values($merged_slots), $extra_slots);

    // display_order 昇順でソート
    usort($final_list, function($a, $b) {
        if ($a['display_order'] === $b['display_order']) {
            return ($a['waku_id'] ?? 9999) <=> ($b['waku_id'] ?? 9999);
        }
        return $a['display_order'] <=> $b['display_order'];
    });

    return $final_list;
}

/**
 * 期間内の有効時間枠を一括プリフェッチし、日付ごとのマップとして返却（カレンダー・マトリクス用）
 * 画面全体のDBアクセスはたった1回。描画ループ内はメモリ上の参照（O(1)）で完結。
 * 期間途中に世代境界日（例: 2026-12-31 ➔ 2027-01-01）が含まれる場合も日付ごとに適切な世代を自動適用する。
 * 
 * @param PDO $pdo
 * @param string $dept 診療科 ('GASTRO' / 'PROCT')
 * @param string $start_date 開始日 ('YYYY-MM-DD')
 * @param string $end_date 終了日 ('YYYY-MM-DD')
 * @return array 日付 ('YYYY-MM-DD') => 有効枠配列
 */
function getEffectiveWakuMapForPeriod($pdo, $dept, $start_date, $end_date) {
    // 1. 対象期間に重なる静的基本マスタ（m2_waku）を一括取得
    $stmt = $pdo->prepare("
        SELECT 
            id,
            department_code,
            group_code,
            waku_code,
            waku_name,
            display_order,
            is_active,
            valid_from,
            valid_to
        FROM m2_waku
        WHERE department_code = :dept
          AND is_active = TRUE
          AND valid_from <= :end_date
          AND valid_to >= :start_date
        ORDER BY display_order ASC, id ASC
    ");
    $stmt->execute([
        ':dept'       => $dept,
        ':start_date' => $start_date,
        ':end_date'   => $end_date,
    ]);
    $base_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. 期間内の全オーバーライドを1クエリでプリフェッチ
    $sql_override = "
        SELECT 
            override_id,
            department_code,
            base_waku_id,
            override_type,
            target_date,
            start_date,
            end_date,
            display_waku_name,
            group_code,
            time_start,
            time_end,
            display_order,
            is_suspended,
            reason
        FROM m2_waku_override
        WHERE department_code = :dept
          AND is_active = TRUE
          AND (
              (override_type = 'DATE' AND target_date BETWEEN :start_date AND :end_date)
              OR
              (override_type = 'PERIOD' AND start_date <= :end_date AND end_date >= :start_date)
          )
        ORDER BY 
            CASE WHEN override_type = 'DATE' THEN 1 ELSE 2 END,
            override_id ASC
    ";
    $stmt_override = $pdo->prepare($sql_override);
    $stmt_override->execute([
        ':dept'       => $dept,
        ':start_date' => $start_date,
        ':end_date'   => $end_date
    ]);
    $all_overrides = $stmt_override->fetchAll(PDO::FETCH_ASSOC);

    // 基本マスタのテンプレート配列（世代期間情報付き）
    $all_templates = [];
    foreach ($base_rows as $b) {
        $all_templates[] = [
            'waku_id'         => (int)$b['id'],
            'base_waku_id'    => (int)$b['id'],
            'override_id'     => null,
            'waku_name'       => (string)$b['waku_name'],
            'original_name'   => (string)$b['waku_name'],
            'group_code'      => (string)$b['group_code'],
            'department_code' => (string)$b['department_code'],
            'display_order'   => (int)$b['display_order'],
            'valid_from'      => (string)($b['valid_from'] ?? '1900-01-01'),
            'valid_to'        => (string)($b['valid_to'] ?? '9999-12-31'),
            'is_suspended'    => false,
            'is_override'     => false,
            'override_type'   => null,
            'reason'          => null,
            'time_start'      => null,
            'time_end'        => null,
        ];
    }

    $result_map = [];
    $curr_ts = strtotime($start_date);
    $end_ts  = strtotime($end_date);

    while ($curr_ts <= $end_ts) {
        $d_str = date('Y-m-d', $curr_ts);

        // 当該日付に有効な世代の基本枠テンプレートを抽出
        $day_base_template = [];
        foreach ($all_templates as $tpl) {
            if ($tpl['valid_from'] <= $d_str && $d_str <= $tpl['valid_to']) {
                $day_base_template[] = $tpl;
            }
        }

        // 当該日付に関係するオーバーライドを抽出
        $day_overrides = [];
        foreach ($all_overrides as $ov) {
            if ($ov['override_type'] === 'DATE' && $ov['target_date'] === $d_str) {
                $day_overrides[] = $ov;
            } elseif ($ov['override_type'] === 'PERIOD' && $ov['start_date'] <= $d_str && $ov['end_date'] >= $d_str) {
                $day_overrides[] = $ov;
            }
        }

        if (empty($day_overrides)) {
            $result_map[$d_str] = $day_base_template;
        } else {
            // 当該日専用にインメモリマージ
            $merged = [];
            foreach ($day_base_template as $tpl) {
                $merged[$tpl['base_waku_id']] = $tpl;
            }
            $extra = [];
            $applied = [];

            foreach ($day_overrides as $ov) {
                $base_id = $ov['base_waku_id'] !== null ? (int)$ov['base_waku_id'] : null;
                if ($base_id !== null && isset($merged[$base_id])) {
                    if (isset($applied[$base_id])) {
                        continue;
                    }
                    $applied[$base_id] = true;
                    $merged[$base_id]['override_id']   = (int)$ov['override_id'];
                    $merged[$base_id]['waku_name']     = (string)$ov['display_waku_name'];
                    $merged[$base_id]['group_code']    = (string)$ov['group_code'];
                    $merged[$base_id]['is_suspended']  = (bool)$ov['is_suspended'];
                    $merged[$base_id]['is_override']   = true;
                    $merged[$base_id]['override_type'] = (string)$ov['override_type'];
                    $merged[$base_id]['reason']        = $ov['reason'];
                    if ($ov['display_order'] !== null) {
                        $merged[$base_id]['display_order'] = (int)$ov['display_order'];
                    }
                    if ($ov['time_start'] !== null) {
                        $merged[$base_id]['time_start'] = $ov['time_start'];
                    }
                    if ($ov['time_end'] !== null) {
                        $merged[$base_id]['time_end'] = $ov['time_end'];
                    }
                } elseif ($base_id === null) {
                    $extra[] = [
                        'waku_id'         => null,
                        'base_waku_id'    => null,
                        'override_id'     => (int)$ov['override_id'],
                        'waku_name'       => (string)$ov['display_waku_name'],
                        'original_name'   => (string)$ov['display_waku_name'],
                        'group_code'      => (string)$ov['group_code'],
                        'department_code' => (string)$ov['department_code'],
                        'display_order'   => $ov['display_order'] !== null ? (int)$ov['display_order'] : 999,
                        'valid_from'      => (string)($ov['start_date'] ?? $d_str),
                        'valid_to'        => (string)($ov['end_date'] ?? $d_str),
                        'is_suspended'    => (bool)$ov['is_suspended'],
                        'is_override'     => true,
                        'override_type'   => (string)$ov['override_type'],
                        'reason'          => $ov['reason'],
                        'time_start'      => $ov['time_start'],
                        'time_end'        => $ov['time_end'],
                    ];
                }
            }

            $day_list = array_merge(array_values($merged), $extra);
            usort($day_list, function($a, $b) {
                if ($a['display_order'] === $b['display_order']) {
                    return ($a['waku_id'] ?? 9999) <=> ($b['waku_id'] ?? 9999);
                }
                return $a['display_order'] <=> $b['display_order'];
            });
            $result_map[$d_str] = $day_list;
        }

        $curr_ts = strtotime("+1 day", $curr_ts);
    }

    return $result_map;
}

/**
 * 枠名から有効枠情報を検索する（全角・半角・空白自動吸収）
 * 
 * @param array $effective_waku_list getEffectiveWakuList() の戻り値
 * @param string $waku_name 検索対象の枠名
 * @param bool $allow_prefix 前方一致（第3段階）を許可するか（単一スロット判定時は false を指定して誤一致を防止）
 * @return array|null 該当枠情報、見つからない場合は null
 */
function findEffectiveWaku(array $effective_waku_list, string $waku_name, bool $allow_prefix = false): ?array {
    $raw_target = trim($waku_name);
    if ($raw_target === '') return null;

    $normalized_target = trim(mb_convert_kana($raw_target, 'a'));
    $compact_target = preg_replace('/\s+/u', '', $normalized_target);
    
    // 第1段階: 完全一致（現在の枠名 or 基本枠名、空白揺れも吸収）
    foreach ($effective_waku_list as $waku) {
        $cur_norm = trim(mb_convert_kana($waku['waku_name'], 'a'));
        $orig_norm = trim(mb_convert_kana($waku['original_name'] ?? '', 'a'));
        $cur_compact = preg_replace('/\s+/u', '', $cur_norm);
        $orig_compact = preg_replace('/\s+/u', '', $orig_norm);
        if ($cur_norm === $normalized_target || $orig_norm === $normalized_target ||
            ($compact_target !== '' && ($cur_compact === $compact_target || $orig_compact === $compact_target))) {
            return $waku;
        }
    }

    // 第2段階: 括弧や補足を省いたベース名一致（例: GIF1(特) ➔ GIF1）
    $clean_target = trim(preg_replace('/[\(（\[［【].*?[\)）\]］】]/u', '', $normalized_target));
    if ($clean_target !== '' && $clean_target !== $normalized_target) {
        foreach ($effective_waku_list as $waku) {
            $cur_norm = trim(mb_convert_kana($waku['waku_name'], 'a'));
            $orig_norm = trim(mb_convert_kana($waku['original_name'] ?? '', 'a'));
            $clean_cur = trim(preg_replace('/[\(（\[［【].*?[\)）\]］】]/u', '', $cur_norm));
            $clean_orig = trim(preg_replace('/[\(（\[［【].*?[\)）\]］】]/u', '', $orig_norm));

            if ($clean_target === $clean_orig || $clean_target === $clean_cur) {
                return $waku;
            }
        }
    }

    // 第3段階: 前方一致（基本枠名とのプレフィックス一致）※$allow_prefix が true の場合のみ
    if ($allow_prefix) {
        foreach ($effective_waku_list as $waku) {
            $orig_norm = trim(mb_convert_kana($waku['original_name'] ?? '', 'a'));
            if ($orig_norm !== '' && (stripos($normalized_target, $orig_norm) === 0 || stripos($orig_norm, $normalized_target) === 0)) {
                return $waku;
            }
        }
    }

    return null;
}

/**
 * 特定日付の予約データと有効時間枠を合成し、一覧表示用配列として取得
 * （カレンダー当日詳細一覧・A4印刷等で共通利用）
 * 
 * 有効時間枠（オーバーライド・休止枠反映済）をベースとし、
 * 予約データとのマッチング、空枠行の自動生成、迷子予約の検出をインメモリで実施。
 * 
 * @param PDO $pdo
 * @param string $dept 診療科 ('GASTRO' / 'PROCT' / 'ALL')
 * @param string $date_str 対象日付 ('YYYY-MM-DD')
 * @param string $filter_doctor 医師絞り込み ('all' または医師名)
 * @param string $filter_exam 検査種別絞り込み ('all' または検査種別)
 * @param bool $show_empty 空枠行を含めるか (true / false)
 * @return array 予約・空枠行の配列
 */
function getDailyReservationsWithEffectiveSlots(
    PDO $pdo,
    string $dept,
    string $date_str,
    string $filter_doctor = 'all',
    string $filter_exam = 'all',
    bool $show_empty = true
): array {
    // 1. 対象科の有効時間枠を取得
    $effective_slots = [];
    if ($dept === 'ALL') {
        $slots_proct = getEffectiveWakuList($pdo, 'PROCT', $date_str);
        $slots_gastro = getEffectiveWakuList($pdo, 'GASTRO', $date_str);
        $effective_slots = array_merge($slots_proct, $slots_gastro);
    } else {
        $effective_slots = getEffectiveWakuList($pdo, $dept, $date_str);
    }

    // 2. 予約データを取得
    $sql = "
        SELECT 
            s.schedule_id,
            s.exam_date,
            s.scheduled_time,
            s.waku_no,
            s.waku_id,
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
            s.is_vip,
            s.is_canceled,
            s.booking_status,
            s.department_code,
            s.created_at,
            s.updated_at
        FROM t_endoscopy_schedule s
        WHERE s.exam_date = :date
          AND s.is_canceled = FALSE
    ";
    $params = [':date' => $date_str];

    if ($dept !== 'ALL') {
        $sql .= " AND s.department_code = :dept";
        $params[':dept'] = $dept;
    }
    if ($filter_doctor !== 'all' && $filter_doctor !== 'undecided') {
        $sql .= " AND s.doctor_name = :doctor";
        $params[':doctor'] = $filter_doctor;
    }
    if ($filter_exam !== 'all') {
        $sql .= " AND s.exam_type = :exam";
        $params[':exam'] = $filter_exam;
    }

    $sql .= " ORDER BY s.department_code ASC, s.schedule_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. 予約データと有効枠をマッピング（同名枠の均等分散 ＆ 枠ID・表示順優先マッチング）
    $dept_slots = [];
    foreach ($effective_slots as $slot) {
        $d = $slot['department_code'];
        $dept_slots[$d][] = $slot;
    }

    $matched_reservations = [];
    $mismatch_rows = [];
    $slot_usage_counts = []; // $slot_usage_counts[dept][slot_key] = 割当件数

    foreach ($reservations as $res) {
        $r_dept = (string)($res['department_code'] ?? '');
        $r_time = (string)($res['scheduled_time'] ?? '');
        $r_waku_no = isset($res['waku_no']) && $res['waku_no'] !== null && $res['waku_no'] !== '' ? (int)$res['waku_no'] : null;
        $r_waku_id = isset($res['waku_id']) && $res['waku_id'] !== null && $res['waku_id'] !== '' ? (int)$res['waku_id'] : null;
        $available_slots_for_dept = $dept_slots[$r_dept] ?? [];

        $matched_slot = null;

        // 【優先度1: waku_id または base_waku_id の一致（かつ枠名一致）】
        // ※plan.html 計画⑦準拠: 予約の scheduled_time（枠名文字列）を唯一の正（Single Truth）とし、
        //   マスタ改変によるサイレントすり替わり事故を防ぐため、枠名文字列（表示名または基本枠名）が一致する場合のみ割り当てる
        if ($r_waku_id !== null && $r_waku_id > 0) {
            $target_norm = trim(mb_convert_kana($r_time, 'a'));
            $target_compact = preg_replace('/\s+/u', '', $target_norm);
            foreach ($available_slots_for_dept as $s) {
                if (($s['base_waku_id'] ?? null) == $r_waku_id || ($s['waku_id'] ?? null) == $r_waku_id) {
                    $s_cur = trim(mb_convert_kana($s['waku_name'], 'a'));
                    $s_orig = trim(mb_convert_kana($s['original_name'] ?? '', 'a'));
                    $s_cur_compact = preg_replace('/\s+/u', '', $s_cur);
                    $s_orig_compact = preg_replace('/\s+/u', '', $s_orig);
                    if ($s_cur === $target_norm || $s_orig === $target_norm ||
                        ($target_compact !== '' && ($s_cur_compact === $target_compact || $s_orig_compact === $target_compact))) {
                        $matched_slot = $s;
                        break;
                    }
                }
            }
        }

        // 【優先度2: waku_no (display_order) 一致 かつ 枠名（表示名 or 基本名）一致】
        if ($matched_slot === null && $r_waku_no !== null && $r_waku_no > 0) {
            foreach ($available_slots_for_dept as $s) {
                if ((int)$s['display_order'] === $r_waku_no) {
                    $s_cur = trim(mb_convert_kana($s['waku_name'], 'a'));
                    $s_orig = trim(mb_convert_kana($s['original_name'] ?? '', 'a'));
                    $target_norm = trim(mb_convert_kana($r_time, 'a'));
                    if ($s_cur === $target_norm || $s_orig === $target_norm) {
                        $matched_slot = $s;
                        break;
                    }
                }
            }
        }

        // 【優先度3: 同名枠が複数存在する場合、まだ予約が割り当てられていない空きスロットを優先】
        if ($matched_slot === null) {
            foreach ($available_slots_for_dept as $s) {
                $s_key = $s['base_waku_id'] ?? ('ov_' . $s['override_id']);
                if (empty($slot_usage_counts[$r_dept][$s_key])) {
                    if (findEffectiveWaku([$s], $r_time, false) !== null) {
                        $matched_slot = $s;
                        break;
                    }
                }
            }
        }

        // 【優先度4: それでも未決定の場合は従来通りマッチする任意のスロット】
        if ($matched_slot === null) {
            $matched_slot = findEffectiveWaku($available_slots_for_dept, $r_time);
        }

        if ($matched_slot !== null) {
            $slot_key = $matched_slot['base_waku_id'] ?? ('ov_' . $matched_slot['override_id']);
            $slot_usage_counts[$r_dept][$slot_key] = ($slot_usage_counts[$r_dept][$slot_key] ?? 0) + 1;
            $row_data = $res;
            $row_data['waku_no'] = (int)$matched_slot['display_order'];
            $row_data['waku_display_order'] = (int)$matched_slot['display_order'];
            $row_data['group_code'] = (string)$matched_slot['group_code'];
            $row_data['is_empty'] = false;
            $row_data['is_mismatch'] = false;
            $row_data['is_suspended'] = (bool)$matched_slot['is_suspended'];
            $row_data['is_override'] = (bool)$matched_slot['is_override'];
            $row_data['override_reason'] = $matched_slot['reason'];
            $row_data['slot_waku_name'] = $matched_slot['waku_name'];
            $row_data['base_waku_id'] = $matched_slot['base_waku_id'] ?? null;
            $row_data['waku_id'] = $matched_slot['waku_id'] ?? ($matched_slot['base_waku_id'] ?? null);
            $row_data['original_name'] = $matched_slot['original_name'] ?? $matched_slot['waku_name'];

            $slot_key = $matched_slot['base_waku_id'] ?? ('ov_' . $matched_slot['override_id']);
            $matched_reservations[$r_dept][$slot_key][] = $row_data;
        } else {
            $row_data = $res;
            $row_data['waku_no'] = -1;
            $row_data['waku_display_order'] = 9999;
            $row_data['group_code'] = 'MISMATCH';
            $row_data['is_empty'] = false;
            $row_data['is_mismatch'] = true;
            $row_data['is_suspended'] = false;
            $row_data['is_override'] = false;
            $row_data['override_reason'] = null;
            $row_data['slot_waku_name'] = $r_time;
            $row_data['base_waku_id'] = null;
            $row_data['waku_id'] = null;
            $row_data['original_name'] = $r_time;
            $mismatch_rows[] = $row_data;
        }
    }

    // 4. 空枠行の生成・結合
    $slot_rows = [];

    foreach ($effective_slots as $slot) {
        $s_dept = $slot['department_code'];
        $slot_key = $slot['base_waku_id'] ?? ('ov_' . $slot['override_id']);

        if (!empty($matched_reservations[$s_dept][$slot_key])) {
            foreach ($matched_reservations[$s_dept][$slot_key] as $m_row) {
                $slot_rows[] = $m_row;
            }
        } elseif ($show_empty || !empty($slot['is_suspended'])) {
            $is_susp = !empty($slot['is_suspended']);
            $reason_label = !empty($slot['reason']) ? trim((string)$slot['reason']) : '';

            // 枠なし休止枠の備考テキスト（ゴミ記号を付与せず理由のみを保持）
            $remarks_text = $is_susp ? $reason_label : '';

            // 枠のグループコード等から検査種別（TCF, GIF, ECHO 等）を特定
            $grp = (string)($slot['group_code'] ?? 'OTHER');
            $default_exam = ($grp === 'TCF' || $grp === 'W' || $grp === 'ポリペク') ? 'TCF' : (($grp === 'GIF') ? 'GIF' : (($grp === 'ECHO') ? 'ECHO' : $grp));

            $slot_rows[] = [
                'schedule_id'        => 0,
                'exam_date'          => $date_str,
                'scheduled_time'     => (string)$slot['waku_name'],
                'waku_no'            => (int)$slot['display_order'],
                'waku_display_order' => (int)$slot['display_order'],
                'group_code'         => $grp,
                'patient_id'         => $is_susp ? '---' : '',
                'patient_name'       => $is_susp ? '※枠なし' : '',
                'patient_kana'       => '',
                'birthday'           => '',
                'doctor_name'        => $is_susp ? '◆未定◆' : '',
                'exam_type'          => $is_susp ? $default_exam : '',
                'arrival_time'       => $is_susp ? '---' : '',
                'exam_location'      => '',
                'remarks'            => $remarks_text,
                'room_number'        => $is_susp ? '-' : '',
                'bowel_prep'         => $is_susp ? '-' : '',
                'is_canceled'        => false,
                'booking_status'     => '',
                'department_code'    => $s_dept,
                'created_at'         => '',
                'updated_at'         => '',
                'is_empty'           => !$is_susp, // ★ 休止枠は空き枠ではない（is_empty = false）
                'is_mismatch'        => false,
                'is_suspended'       => $is_susp,
                'is_no_waku'         => $is_susp,
                'is_override'        => (bool)$slot['is_override'],
                'override_reason'    => $slot['reason'],
                'slot_waku_name'     => (string)$slot['waku_name'],
                'base_waku_id'       => $slot['base_waku_id'] ?? null,
                'waku_id'            => $slot['waku_id'] ?? ($slot['base_waku_id'] ?? null),
                'original_name'      => $slot['original_name'] ?? $slot['waku_name'],
            ];
        }
    }

    // 5. 結合（迷子先頭、科順・枠順・schedule_id順）
    return array_merge($mismatch_rows, $slot_rows);
}

/**
 * 期間指定オーバーライド機能がシステムで許可されているか判定
 * （デフォルトは false: 現場は単一日のみの安心安全シンプル運用）
 * 
 * @param PDO $pdo
 * @return bool
 */
function isPeriodOverrideAllowed($pdo): bool {
    try {
        $stmt = $pdo->prepare("SELECT item_value FROM m_input_master WHERE category = 'system_config' AND item_value LIKE 'allow_period_override:%' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['item_value'])) {
            $parts = explode(':', $row['item_value'], 2);
            return isset($parts[1]) && ($parts[1] === '1' || $parts[1] === 'true');
        }
    } catch (Exception $e) {
        // エラー時は安全側に倒して false
    }
    return false;
}

/**
 * 期間指定オーバーライド機能の利用許可を設定（マスタメンテ用）
 * 
 * @param PDO $pdo
 * @param bool $allowed
 * @return bool
 */
function setPeriodOverrideAllowed($pdo, bool $allowed): bool {
    try {
        $val = 'allow_period_override:' . ($allowed ? '1' : '0');
        $stmt = $pdo->prepare("SELECT master_id FROM m_input_master WHERE category = 'system_config' AND item_value LIKE 'allow_period_override:%' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $upd = $pdo->prepare("UPDATE m_input_master SET item_value = :val WHERE master_id = :id");
            return $upd->execute([':val' => $val, ':id' => $row['master_id']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO m_input_master (category, item_value, display_order, created_at) VALUES ('system_config', :val, 1, CURRENT_TIMESTAMP)");
            return $ins->execute([':val' => $val]);
        }
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 枠なし・休止枠の備考欄を綺麗にバッジ化して「＊＊＊🚫枠なし🚫＊＊＊」と表示する
 * 
 * @param string|null $remarks
 * @param string|null $reason
 * @return string
 */
function cleanNoWakuRemarksHTML(?string $remarks, ?string $reason = null): string {
    $text = trim((string)$remarks);
    if ($text === '') {
        $text = trim((string)$reason);
    }
    // HTMLタグ除去
    $text = trim(strip_tags($text));
    // 「新・枠休止オーバーライドへ移行済」などのシステム移行注記を除去
    $text = preg_replace('/\[新・枠休止オーバーライドへ移行済\]/u', '', $text);
    
    // 「＊＊＊🚫枠なし［...］＊＊＊」などの枠なし外枠から内側の理由を取り出す
    $extracted_reason = '';
    if (preg_match('/[＊*]*[🚫]*枠なし[＊*🚫]*[\[［](.*?)[\]］][＊*🚫]*/u', $text, $m)) {
        $extracted_reason = trim($m[1]);
    }
    // 単体の「＊＊＊枠なし＊＊＊」「※枠なし」「休止」「枠なし」などを除去
    $clean_reason = preg_replace('/[＊*]*[🚫]*枠なし[＊*🚫]*/u', '', $text);
    $clean_reason = preg_replace('/^[\s　・:：\-\[\]［］]+|[\s　・:：\-\[\]［］]+$/u', '', $clean_reason);
    $clean_reason = trim($clean_reason);

    $final_reason = '';
    if ($extracted_reason !== '' && $extracted_reason !== '＊＊＊枠なし＊＊＊' && $extracted_reason !== '枠なし') {
        $final_reason = $extracted_reason;
    } elseif ($clean_reason !== '' && $clean_reason !== '休止' && $clean_reason !== '※枠なし' && $clean_reason !== '※仮予約' && $clean_reason !== '指定なし') {
        $final_reason = $clean_reason;
    }

    if ($final_reason !== '') {
        return '<span style="background-color:#fee2e2; color:#dc2626; padding:2px 6px; border-radius:3px; font-weight:bold;">＊＊＊🚫枠なし［' . htmlspecialchars($final_reason, ENT_QUOTES, 'UTF-8') . '］＊＊＊</span>';
    }
    return '<span style="background-color:#fee2e2; color:#dc2626; padding:2px 6px; border-radius:3px; font-weight:bold;">＊＊＊🚫枠なし🚫＊＊＊</span>';
}

/**
 * 予約画面等のプルダウン用検査種別一覧を取得（m_exam_type_group から取得、静的キャッシュ付き）
 * 
 * @param PDO|null $pdo
 * @return string[] 検査種別名の配列（表示順・TCF, 浣腸CF, GIF, W, ポリペク, エコー, ◆胃がん検診◆ 等）
 */
function getExamTypeOptions(?PDO $pdo = null): array {
    static $options_cache = null;
    if ($options_cache !== null) {
        return $options_cache;
    }

    $default_options = ['TCF', '浣腸CF', 'GIF', 'W', 'ポリペク', 'エコー', '◆胃がん検診◆'];
    $db = $pdo ?? ($GLOBALS['pdo'] ?? null);

    if ($db instanceof PDO) {
        try {
            $stmt = $db->query("
                SELECT exam_type 
                FROM m_exam_type_group 
                WHERE is_active = true AND is_selectable = true 
                ORDER BY display_order ASC, id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($rows)) {
                $options_cache = $rows;
                return $options_cache;
            }
        } catch (Exception $e) {
            // DBエラーまたは未移行環境時はフォールバック
        }
    }

    $options_cache = $default_options;
    return $options_cache;
}

/**
 * 検査種別グループマップ（m_exam_type_group）を取得（プロセス内静的キャッシュ付き）
 * 
 * @param PDO|null $pdo
 * @return array<string, string> exam_type => slot_group ('GIF', 'TCF', 'ECHO', etc.)
 */
function getExamTypeGroupMap(?PDO $pdo = null): array {
    static $group_map = null;
    if ($group_map !== null) {
        return $group_map;
    }

    $default_map = [
        'GIF'        => 'GIF',
        '胃がん検診'  => 'GIF',
        '◆胃がん検診◆' => 'GIF',
        'TCF'        => 'TCF',
        'W'          => 'TCF',
        'ポリペク'    => 'TCF',
        '浣腸CF'     => 'TCF',
        '浣腸F'      => 'TCF',
        '術前F'      => 'TCF',
        '前日F'      => 'TCF',
        '◆◆◆'       => 'TCF',
        'エコー'      => 'ECHO',
        'ECHO'       => 'ECHO',
    ];

    $db = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if ($db instanceof PDO) {
        try {
            $stmt = $db->query("SELECT exam_type, slot_group FROM m_exam_type_group WHERE is_active = true");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                foreach ($rows as $r) {
                    $etype = trim((string)($r['exam_type'] ?? ''));
                    $grp   = trim((string)($r['slot_group'] ?? ''));
                    if ($etype !== '' && $grp !== '') {
                        $default_map[$etype] = $grp;
                    }
                }
            }
        } catch (Exception $e) {
            // DBエラー時はデフォルトマップを使用
        }
    }

    $group_map = $default_map;
    return $group_map;
}

/**
 * 検査種別名から大分類グループ（GIF / TCF / ECHO / OTHER）を解決
 * 
 * @param string|null $exam_type 検査種別名（例: 'GIF', 'TCF', '浣腸CF', 'エコー' 等）
 * @param PDO|null $pdo
 * @return string グループコード ('GIF', 'TCF', 'ECHO', または 'OTHER')
 */
function resolveExamGroup(?string $exam_type, ?PDO $pdo = null): string {
    $raw = trim((string)($exam_type ?? ''));
    if ($raw === '') {
        return 'OTHER';
    }

    $map = getExamTypeGroupMap($pdo);
    if (isset($map[$raw])) {
        return $map[$raw];
    }

    // 大文字小文字を吸収してチェック
    $upper = strtoupper($raw);
    foreach ($map as $k => $v) {
        if (strtoupper($k) === $upper) {
            return $v;
        }
    }

    // 部分一致推測（未登録値へのセーフガード）
    if (mb_strpos($raw, 'GIF') !== false || mb_strpos($raw, '胃') !== false) {
        return 'GIF';
    }
    if (mb_strpos($raw, 'エコー') !== false || mb_strpos($raw, 'ECHO') !== false || mb_strpos($raw, 'US') !== false) {
        return 'ECHO';
    }
    if (mb_strpos($raw, 'TCF') !== false || mb_strpos($raw, 'CF') !== false || mb_strpos($raw, '大腸') !== false || mb_strpos($raw, 'ポリ') !== false) {
        return 'TCF';
    }

    return 'OTHER';
}

/**
 * 検査種別に応じたバッジ情報（CSSクラス名、グループ、表示色など）を取得
 * 
 * @param string|null $exam_type
 * @param PDO|null $pdo
 * @return array{group: string, cls: string, label: string}
 */
function getExamTypeBadgeInfo(?string $exam_type, ?PDO $pdo = null): array {
    $group = resolveExamGroup($exam_type, $pdo);
    $cls_map = [
        'GIF'   => 'gif',
        'TCF'   => 'tcf',
        'ECHO'  => 'echo',
        'OTHER' => 'other',
    ];
    $cls = $cls_map[$group] ?? 'other';
    return [
        'group' => $group,
        'cls'   => $cls,
        'label' => (string)($exam_type ?? ''),
    ];
}

/**
 * 特定のグループ（'GIF', 'TCF', 'ECHO' 等）に属する検査種別名のリストを取得
 * 
 * @param string $group_code 'GIF', 'TCF', 'ECHO'
 * @param PDO|null $pdo
 * @return string[]
 */
function getExamTypesByGroup(string $group_code, ?PDO $pdo = null): array {
    $map = getExamTypeGroupMap($pdo);
    $types = [];
    foreach ($map as $etype => $grp) {
        if ($grp === $group_code) {
            $types[] = $etype;
        }
    }
    return array_values(array_unique($types));
}


