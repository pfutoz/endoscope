<?php
/**
 * get_progress_data.php
 * 当日進捗ステータスモニタ用 リアルタイムデータ取得API (JSON)
 * 生成日時: 2026-09-28 19:05:00 JST
 * 修正履歴:
 *   - 2026-09-29: 五十音インデックス・クイック頭文字フィルター対応
 *       1. SELECTクエリに s.patient_kana を追加し、カナ頭文字絞り込みに対応
 *   - 2026-09-28: 新検査種別「浣腸CF」対応
 *       1. 検査種別フィルターに独立タブ 'ENEMA' を追加 (ALL / CF / ENEMA / GIF)
 *       2. 浣腸CF (exam_type: '浣腸CF' / '浣腸F') の前処置スキップ (is_prep_skipped = true) 自動化
 *       3. サマリー集計に enema_count を追加、患者データに is_enema フラグを追加
 *   - 2026-09-26: エコー完全除外、GIF前処置スキップ、セデーション有無管理、検査種別フィルター対応
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/progress_db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'DB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

// テーブル自動生成・初期化の保証
initProgressTables($pdo);

$date_str = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? $_GET['date'] : date('Y-m-d');
$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'ALL';
if (!in_array($dept, ['ALL', 'PROCT', 'GASTRO'], true)) {
    $dept = 'ALL';
}

$exam_filter = isset($_GET['exam_filter']) ? trim((string)$_GET['exam_filter']) : 'ALL';
if (!in_array($exam_filter, ['ALL', 'CF', 'ENEMA', 'GIF'], true)) {
    $exam_filter = 'ALL';
}

$dept_condition = "1=1";
if ($dept !== 'ALL') {
    $dept_condition = "s.department_code = " . $pdo->quote($dept);
}

// エコーはホワイトボード不要のため完全除外！
$exam_condition = "s.exam_type NOT IN ('ECHO', 'エコー')";
if ($exam_filter === 'CF') {
    $exam_condition .= " AND s.exam_type IN ('TCF', 'ポリペク', 'W')";
} elseif ($exam_filter === 'ENEMA') {
    $exam_condition .= " AND s.exam_type IN ('浣腸CF', '浣腸F')";
} elseif ($exam_filter === 'GIF') {
    $exam_condition .= " AND s.exam_type IN ('GIF', '◆胃がん検診◆', '胃がん検診')";
}

// ★ 当日進捗ステータスモニターでは空き枠・枠なしは表示不要（患者がいる予約のみ表示）
$patient_condition = "
    s.patient_name IS NOT NULL 
    AND TRIM(s.patient_name) != '' 
    AND s.patient_name NOT LIKE '%枠なし%' 
    AND s.patient_name != '※枠なし' 
    AND s.patient_name != '空き' 
    AND s.patient_name != '空き枠'
    AND (s.remarks IS NULL OR (s.remarks != '＊＊＊🚫枠なし🚫＊＊＊' AND s.remarks NOT LIKE '%枠なし%'))
";

try {
    // 1. マスタ一覧の取得
    $statuses = $pdo->query("SELECT * FROM m_progress_status WHERE is_active = TRUE ORDER BY step_order ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 2. マイトイレ部屋一覧の取得 (m_input_master)
    $stmt_toilet = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'toilet_room' ORDER BY display_order ASC, master_id ASC");
    $toilet_rooms = $stmt_toilet->fetchAll(PDO::FETCH_COLUMN);

    // 3. 当日の予約データ取得（エコー除外・枠なし除外・実患者のみ・検査種別フィルター適用）
    $sql_schedule = "
        SELECT 
            s.schedule_id,
            s.exam_date,
            s.scheduled_time,
            s.department_code,
            s.patient_id,
            s.patient_name,
            s.patient_kana,
            s.birthday,
            s.doctor_name,
            s.exam_type,
            s.arrival_time AS sched_arrival_time,
            s.room_number AS sched_room_number,
            s.bowel_prep,
            s.remarks,
            s.has_sedation AS sched_has_sedation,
            s.has_drive_caution AS sched_has_drive_caution,
            COALESCE(w.display_order, 999) AS waku_display_order
        FROM t_endoscopy_schedule s
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
        WHERE s.exam_date = :date
          AND s.is_canceled = FALSE
          AND {$dept_condition}
          AND {$exam_condition}
          AND {$patient_condition}
        ORDER BY waku_display_order ASC, s.scheduled_time ASC, s.schedule_id ASC
    ";
    $stmt = $pdo->prepare($sql_schedule);
    $stmt->execute([':date' => $date_str]);
    $schedule_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. t_today_progress に未登録の予約があれば一括作成（GIF判定・セデーション自動判定）
    $now_timestamp = date('Y-m-d H:i:s');
    $check_stmt = $pdo->prepare("SELECT schedule_id, is_prep_skipped, has_sedation, sedation_type FROM t_today_progress WHERE schedule_id = :id");
    
    $ins_prog_stmt = $pdo->prepare("
        INSERT INTO t_today_progress (
            schedule_id, exam_date, current_status_id, is_arrived, arrival_time,
            toilet_room, prep_med_status, scope_cleaning_status, has_biopsy, biopsy_count,
            biopsy_sites, biopsy_verified, is_explain_waiting, has_sedation, sedation_type,
            is_prep_skipped, updated_at
        ) VALUES (
            :schedule_id, :exam_date, 1, :is_arrived, :arrival_time,
            :toilet_room, 'NONE', 'NONE', FALSE, 0,
            '', FALSE, FALSE, :has_sedation, :sedation_type,
            :is_prep_skipped, :updated_at
        )
    ");

    $up_sed_stmt = $pdo->prepare("
        UPDATE t_today_progress 
        SET is_prep_skipped = :is_prep, has_sedation = :has_sed, sedation_type = :sed_type 
        WHERE schedule_id = :id
    ");

    foreach ($schedule_rows as $row) {
        $sid = (int)$row['schedule_id'];
        $check_stmt->execute([':id' => $sid]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        $is_gif = in_array($row['exam_type'], ['GIF', '◆胃がん検診◆', '胃がん検診'], true);
        $is_enema = in_array($row['exam_type'], ['浣腸CF', '浣腸F'], true);
        $is_prep_skipped = ($is_gif || $is_enema); // 胃カメラおよび浣腸CFは前処置スキップ

        // 鎮静判定（s.has_sedation が明示指定されていれば最優先、なければカルテ備考から自動判定）
        $is_echo = ($row['exam_type'] === 'エコー' || mb_strpos((string)$row['exam_type'], 'エコー') !== false);
        $remarks_text = strip_tags($row['remarks'] ?? '');

        if ($row['sched_has_sedation'] !== null) {
            $has_sedation = !empty($row['sched_has_sedation']);
            $sedation_type = $has_sedation ? (($row['department_code'] === 'PROCT') ? 'PROPOFOL' : 'DIAZEPAM') : 'NONE';
        } else {
            $has_sedation = !$is_echo;
            $sedation_type = $is_echo ? 'NONE' : (($row['department_code'] === 'PROCT') ? 'PROPOFOL' : 'DIAZEPAM');

            if ($is_echo) {
                $has_sedation = false;
                $sedation_type = 'NONE';
            } elseif (mb_strpos($remarks_text, '注なし') !== false || mb_strpos($remarks_text, '注射なし') !== false || mb_strpos($remarks_text, '鎮静なし') !== false) {
                $has_sedation = false;
                $sedation_type = 'NONE';
            } elseif (mb_strpos($remarks_text, 'プロポ') !== false) {
                $has_sedation = true;
                $sedation_type = 'PROPOFOL';
            } elseif (mb_strpos($remarks_text, 'ジアゼ') !== false || mb_strpos($remarks_text, 'セルシン') !== false || mb_strpos($remarks_text, 'ホリゾン') !== false) {
                $has_sedation = true;
                $sedation_type = 'DIAZEPAM';
            } elseif (mb_strpos($remarks_text, 'ドルミ') !== false || mb_strpos($remarks_text, 'ミダゾ') !== false) {
                $has_sedation = true;
                $sedation_type = 'MIDAZOLAM';
            } elseif (mb_strpos($remarks_text, '注あり') !== false || mb_strpos($remarks_text, '注射あり') !== false || mb_strpos($remarks_text, '鎮静あり') !== false) {
                $has_sedation = true;
            }

            // 胃がん検診は市町村契約により原則鎮静剤不使用（明示的に注あり・鎮静あり等の記載がない限りOFF）
            $is_gastric_screening = ($row['exam_type'] === '◆胃がん検診◆' || $row['exam_type'] === '胃がん検診' || mb_strpos((string)$row['exam_type'], '胃がん') !== false);
            if ($is_gastric_screening && mb_strpos($remarks_text, '注あり') === false && mb_strpos($remarks_text, '注射あり') === false && mb_strpos($remarks_text, '鎮静あり') === false) {
                $has_sedation = false;
                $sedation_type = 'NONE';
            }
        }

        if (!$existing) {
            $has_arr = !empty($row['sched_arrival_time']);
            $ins_prog_stmt->execute([
                ':schedule_id' => $sid,
                ':exam_date' => $date_str,
                ':is_arrived' => $has_arr ? 1 : 0,
                ':arrival_time' => $row['sched_arrival_time'] ?? null,
                ':toilet_room' => ($is_gif || $is_enema) ? null : ($row['sched_room_number'] ?? null),
                ':has_sedation' => $has_sedation ? 1 : 0,
                ':sedation_type' => $sedation_type,
                ':is_prep_skipped' => $is_prep_skipped ? 1 : 0,
                ':updated_at' => $now_timestamp
            ]);
        } else {
            // 既存レコードでも、予約の has_sedation が明示指定された場合または未判定の場合は補正
            if ($row['sched_has_sedation'] !== null || $existing['has_sedation'] === null || $existing['sedation_type'] === 'NONE') {
                $up_sed_stmt->execute([
                    ':is_prep' => $is_prep_skipped ? 'true' : 'false',
                    ':has_sed' => $has_sedation ? 'true' : 'false',
                    ':sed_type' => $sedation_type,
                    ':id' => $sid
                ]);
            }
        }
    }

    // 5. 当日進捗テーブルと結合して完全な患者リストを生成
    $sql_joined = "
        SELECT 
            s.schedule_id,
            s.exam_date,
            s.scheduled_time,
            s.department_code,
            s.patient_id,
            s.patient_name,
            s.patient_kana,
            s.birthday,
            s.doctor_name,
            s.exam_type,
            s.bowel_prep,
            s.remarks,
            s.has_sedation AS sched_has_sedation,
            s.has_drive_caution AS sched_has_drive_caution,
            COALESCE(w.display_order, 999) AS waku_display_order,
            p.current_status_id,
            p.is_arrived,
            COALESCE(p.arrival_time, s.arrival_time) AS arrival_time,
            COALESCE(p.toilet_room, s.room_number) AS toilet_room,
            p.prep_start_time,
            p.prep_done_time,
            p.wait_start_time,
            COALESCE(p.is_exam_ready, FALSE) AS is_exam_ready,
            p.exam_start_time,
            p.exam_end_time,
            p.recovery_start_time,
            p.recovery_end_time,
            p.recovery_target_minutes,
            p.is_explain_waiting,
            p.explain_start_time,
            p.done_time,
            p.prep_med_status,
            p.scope_cleaning_status,
            p.scope_cleaning_ordered_at,
            p.scope_cleaning_done_at,
            p.has_biopsy,
            p.biopsy_count,
            p.biopsy_sites,
            p.biopsy_verified,
            p.biopsy_verified_by,
            p.biopsy_verified_at,
            COALESCE(p.has_sedation, TRUE) AS has_sedation,
            COALESCE(p.sedation_type, 'NONE') AS sedation_type,
            COALESCE(p.is_prep_skipped, FALSE) AS is_prep_skipped,
            p.progress_memo,
            p.updated_at AS progress_updated_at
        FROM t_endoscopy_schedule s
        JOIN t_today_progress p ON s.schedule_id = p.schedule_id
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
        WHERE s.exam_date = :date
          AND s.is_canceled = FALSE
          AND {$dept_condition}
          AND {$exam_condition}
          AND {$patient_condition}
        ORDER BY waku_display_order ASC, s.scheduled_time ASC, s.schedule_id ASC
    ";
    $stmt_patients = $pdo->prepare($sql_joined);
    $stmt_patients->execute([':date' => $date_str]);
    $raw_patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);

    // 医療安全アラート一括取得
    $all_sched_ids = [];
    foreach ($raw_patients as $rp) {
        $sid = (int)($rp['schedule_id'] ?? 0);
        if ($sid > 0) $all_sched_ids[] = $sid;
    }
    $medical_alerts_map = !empty($all_sched_ids) ? getMedicalAlertsMap($pdo, $all_sched_ids) : [];

    $patients = [];
    $summary = [
        'total' => count($raw_patients),
        'status_counts' => [
            1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0
        ],
        'unverified_biopsy_count' => 0, // 検体未照合アラート
        'explain_waiting_count' => 0,   // 説明待ち数
        'scope_cleaning_pending_count' => 0, // 洗浄指示中数
        'alert_anti_pending_count' => 0, // 抗血栓薬 未確認数
        'alert_allergy_count' => 0,      // アレルギー患者数
        'alert_infection_count' => 0,    // 感染症患者数
        'alert_cold_count' => 0,         // 風邪・発熱患者数
        'cf_count' => 0,
        'enema_count' => 0,
        'gif_count' => 0,
        'sedation_count' => 0,
        'no_sedation_count' => 0,
        'waiting_count' => 0,
        'exam_ready_count' => 0
    ];

    $now = new DateTime();

    foreach ($raw_patients as $p) {
        $sid = (int)$p['schedule_id'];
        $alert_info = $medical_alerts_map[$sid] ?? null;
        if ($alert_info) {
            if (!empty($alert_info['has_antithrombotic']) && empty($alert_info['antithrombotic_verified'])) {
                $summary['alert_anti_pending_count']++;
            }
            if (!empty($alert_info['has_allergy'])) {
                $summary['alert_allergy_count']++;
            }
            if (!empty($alert_info['has_infection'])) {
                $summary['alert_infection_count']++;
            }
            if (!empty($alert_info['has_cold_fever'])) {
                $summary['alert_cold_count']++;
            }
        }

        $st_id = (int)$p['current_status_id'];
        if (isset($summary['status_counts'][$st_id])) {
            $summary['status_counts'][$st_id]++;
        }

        if ($st_id === 3) {
            if (!empty($p['is_exam_ready'])) {
                $summary['exam_ready_count']++;
            } else {
                $summary['waiting_count']++;
            }
        }

        $is_gif = in_array($p['exam_type'], ['GIF', '◆胃がん検診◆', '胃がん検診'], true);
        $is_enema = in_array($p['exam_type'], ['浣腸CF', '浣腸F'], true);
        if ($is_gif) {
            $summary['gif_count']++;
        } elseif ($is_enema) {
            $summary['enema_count']++;
        } else {
            $summary['cf_count']++;
        }

        if ($p['has_sedation']) {
            $summary['sedation_count']++;
        } else {
            $summary['no_sedation_count']++;
        }

        // 年齢計算
        $age = calculateAge($p['birthday'], $p['exam_date']);

        // リカバリ経過分数の計算
        $rec_elapsed = null;
        if (!empty($p['recovery_start_time'])) {
            $rec_start = new DateTime($p['recovery_start_time']);
            $diff = $now->diff($rec_start);
            $rec_elapsed = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
            if ($now < $rec_start) {
                $rec_elapsed = 0;
            }
        }

        // 待機経過分数の計算 (ステータス3の待機起点)
        $wait_elapsed = null;
        if (!empty($p['wait_start_time'])) {
            $w_start = new DateTime($p['wait_start_time']);
            $diff_w = $now->diff($w_start);
            $wait_elapsed = ($diff_w->days * 24 * 60) + ($diff_w->h * 60) + $diff_w->i;
            if ($now < $w_start) {
                $wait_elapsed = 0;
            }
        }

        // 検体未照合チェック
        $is_unverified_biopsy = ($p['has_biopsy'] && !$p['biopsy_verified']);
        if ($is_unverified_biopsy) {
            $summary['unverified_biopsy_count']++;
        }

        // 説明待ちチェック
        if ($p['is_explain_waiting']) {
            $summary['explain_waiting_count']++;
        }

        // スコープ洗浄指示中チェック
        if ($p['scope_cleaning_status'] === 'ORDERED') {
            $summary['scope_cleaning_pending_count']++;
        }

        $p['age'] = $age;
        $p['recovery_elapsed_minutes'] = $rec_elapsed;
        $p['wait_elapsed_minutes'] = $wait_elapsed;
        $p['is_exam_ready'] = !empty($p['is_exam_ready']);
        $p['is_unverified_biopsy'] = $is_unverified_biopsy;
        $p['is_gif'] = $is_gif;
        $p['is_enema'] = $is_enema;
        $p['medical_alert'] = $alert_info;

        $patients[] = $p;
    }

    echo json_encode([
        'success' => true,
        'date' => $date_str,
        'dept' => $dept,
        'exam_filter' => $exam_filter,
        'server_time' => date('H:i:s'),
        'summary' => $summary,
        'statuses' => $statuses,
        'toilet_rooms' => $toilet_rooms,
        'patients' => $patients
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'データ取得エラー: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
