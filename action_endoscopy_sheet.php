<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\action_endoscopy_sheet.php
 * ファイル名: action_endoscopy_sheet.php
 * 概要: 内視鏡シート（endoscopy_sheet.php）DB永続化 ＆ 当日進捗モニター（progress_monitor.php）双方向データ同期API
 * 生成日時: 2026-11-08 16:26 JST
 * 修正履歴:
 *   - 2026-11-08: 【計画⑥ 新規作成】
 *       1. t_endoscopy_sheet へのキー単位差分マージ（Key-Level JSONB Merge）によるマルチ端末同時編集対応
 *       2. 内視鏡シート ⇔ 当日進捗モニター（t_today_progress / t_endoscopy_schedule / t_patient_medical_alert）の双方向リアルタイム同期
 *       3. 検査開始・終了・起床・説明時刻打刻および [OK / 回復 / 待合室] スタンプとホワイトボード7段階ステータスの自動進捗連動
 *       4. 医事課レセプト入力完了フラグ（is_billing_completed）および会計準備完了フラグ（is_ready_accounting）更新対応
 */

ini_set('display_errors', "0");
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/progress_db.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'データベース接続に失敗しました'], JSON_UNESCAPED_UNICODE);
    exit;
}

initProgressTables($pdo);

// リクエストパラメータの取得（GET / POST JSON / POST FormData 両対応）
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    if (empty($input)) {
        $input = $_POST;
    }
} else {
    $input = $_GET;
}

$action = isset($input['action']) ? trim((string)$input['action']) : 'get_state';
$schedule_id = isset($input['schedule_id']) ? (int)$input['schedule_id'] : 0;

if ($schedule_id <= 0) {
    echo json_encode(['success' => false, 'error' => '無効なスケジュールIDです'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 排他選択ペア・グループの定義
 */
function getExclusiveCheckGroups(): array {
    return [
        ['dept_proct', 'dept_gastro'],
        ['anti_no', 'anti_yes'],
        ['anti_stop_no', 'anti_stop_yes'],
        ['dm_no', 'dm_yes'],
        ['dm_stop_no', 'dm_stop_yes'],
        ['ins_no', 'ins_yes'],
        ['ins_stop_no', 'ins_stop_yes'],
        ['shunt_no', 'shunt_yes'],
    ];
}

function getExclusiveCircleGroups(): array {
    return [
        ['sex_m', 'sex_f'],
        ['attendant_no', 'attendant_yes'],
        ['bag_no', 'bag_yes'],
        ['med_no', 'med_yes'],
        ['constip_no', 'constip_yes'],
        ['deaf_no', 'deaf_yes'],
        ['bp_med_done', 'bp_med_yet'],
        ['pethi_norm', 'pethi_weak'],
        ['blood_draw_yes', 'blood_draw_no'],
        ['stamp_ok', 'stamp_recovery', 'stamp_waiting'],
    ];
}

/**
 * プレーンテキスト備考をQuill互換HTMLへ変換
 */
function convertPlainRemarksToHtml(string $text): string {
    $text = preg_replace("/\r\n|\r/", "\n", trim($text));
    if ($text === '') {
        return '';
    }
    $lines = explode("\n", $text);
    $htmlParts = [];
    foreach ($lines as $line) {
        $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        $htmlParts[] = '<p>' . ($escaped !== '' ? $escaped : '<br>') . '</p>';
    }
    return implode('', $htmlParts);
}

/**
 * HTML備考からプレーンテキストへ変換
 */
function cleanHtmlRemarksToPlain(string $raw): string {
    if ($raw === '') return '';
    $clean = preg_replace('/<\/p\s*>|<br\s*\/?>|<\/li\s*>|<\/div\s*>/i', "\n", $raw);
    $clean = strip_tags((string)$clean);
    $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
    $clean = preg_replace("/\r\n|\r/", "\n", $clean);
    $clean = preg_replace("/\n{3,}/", "\n\n", trim((string)$clean));
    return $clean;
}

try {
    // 1. 予約基本データの取得
    $stmt_sched = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id");
    $stmt_sched->execute([':id' => $schedule_id]);
    $sched = $stmt_sched->fetch(PDO::FETCH_ASSOC);
    if (!$sched) {
        echo json_encode(['success' => false, 'error' => '指定された予約データが見つかりません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $exam_date  = (string)($sched['exam_date'] ?? date('Y-m-d'));
    $patient_id = trim((string)($sched['patient_id'] ?? ''));
    $now_hm     = date('H:i');
    $now_ts     = date('Y-m-d H:i:s');
    $client_ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // 2. t_today_progress の取得（未作成なら初期作成）
    $stmt_prog = $pdo->prepare("SELECT * FROM t_today_progress WHERE schedule_id = :id");
    $stmt_prog->execute([':id' => $schedule_id]);
    $progress = $stmt_prog->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        $has_arr = !empty($sched['arrival_time']);
        $ins_prog = $pdo->prepare("
            INSERT INTO t_today_progress (
                schedule_id, exam_date, current_status_id, is_arrived, arrival_time, toilet_room, has_sedation, updated_at
            ) VALUES (
                :id, :date, 1, :is_arr, :arr, :room, :has_sed, :updated_at
            )
        ");
        $ins_prog->execute([
            ':id'         => $schedule_id,
            ':date'       => $exam_date,
            ':is_arr'     => $has_arr ? 1 : 0,
            ':arr'        => $sched['arrival_time'] ?? null,
            ':room'       => $sched['room_number'] ?? null,
            ':has_sed'    => ($sched['has_sedation'] !== null) ? ($sched['has_sedation'] ? 1 : 0) : 1,
            ':updated_at' => $now_ts,
        ]);
        $stmt_prog->execute([':id' => $schedule_id]);
        $progress = $stmt_prog->fetch(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // アクション分岐
    // =========================================================================
    switch ($action) {
        // ---------------------------------------------------------------------
        // シート状態・進捗メタ情報の取得 (get_state)
        // ---------------------------------------------------------------------
        case 'get_state':
            $stmt_sheet = $pdo->prepare("SELECT * FROM t_endoscopy_sheet WHERE schedule_id = :id");
            $stmt_sheet->execute([':id' => $schedule_id]);
            $sheet_row = $stmt_sheet->fetch(PDO::FETCH_ASSOC);

            $alerts_map = getMedicalAlertsMap($pdo, [$schedule_id]);
            $alert_info = $alerts_map[$schedule_id] ?? [
                'has_antithrombotic'      => false,
                'antithrombotic_verified' => false,
                'has_diabetes'            => false,
                'has_allergy'             => false,
                'has_infection'           => false,
                'has_cold_fever'          => false,
            ];

            $state = ['checks' => [], 'circles' => [], 'inputs' => []];
            if ($sheet_row && !empty($sheet_row['sheet_state_json'])) {
                $decoded = json_decode((string)$sheet_row['sheet_state_json'], true);
                if (is_array($decoded)) {
                    $state['checks']  = isset($decoded['checks']) && is_array($decoded['checks']) ? $decoded['checks'] : [];
                    $state['circles'] = isset($decoded['circles']) && is_array($decoded['circles']) ? $decoded['circles'] : [];
                    $state['inputs']  = isset($decoded['inputs']) && is_array($decoded['inputs']) ? $decoded['inputs'] : [];
                }
            }

            // 進捗テーブル（t_today_progress）の時刻が記録済みでシート側が空欄なら補完反映
            if (!empty($progress['exam_start_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$progress['exam_start_time'], $m)) {
                if (empty($state['inputs']['t_start_h']) && empty($state['inputs']['t_start_m'])) {
                    $state['inputs']['t_start_h'] = sprintf('%02d', (int)$m[1]);
                    $state['inputs']['t_start_m'] = sprintf('%02d', (int)$m[2]);
                }
            }
            if (!empty($progress['exam_end_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$progress['exam_end_time'], $m)) {
                if (empty($state['inputs']['t_end_h']) && empty($state['inputs']['t_end_m'])) {
                    $state['inputs']['t_end_h'] = sprintf('%02d', (int)$m[1]);
                    $state['inputs']['t_end_m'] = sprintf('%02d', (int)$m[2]);
                }
            }
            if (!empty($progress['recovery_end_time'])) {
                $wake_hm = date('H:i', strtotime((string)$progress['recovery_end_time']));
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $wake_hm, $m)) {
                    if (empty($state['inputs']['t_wake_h']) && empty($state['inputs']['t_wake_m'])) {
                        $state['inputs']['t_wake_h'] = sprintf('%02d', (int)$m[1]);
                        $state['inputs']['t_wake_m'] = sprintf('%02d', (int)$m[2]);
                    }
                }
            }
            if (!empty($progress['explain_start_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$progress['explain_start_time'], $m)) {
                if (empty($state['inputs']['t_exp_h']) && empty($state['inputs']['t_exp_m'])) {
                    $state['inputs']['t_exp_h'] = sprintf('%02d', (int)$m[1]);
                    $state['inputs']['t_exp_m'] = sprintf('%02d', (int)$m[2]);
                }
            }

            // 備考の最新補完
            $latest_plain_remarks = cleanHtmlRemarksToPlain((string)($sched['remarks'] ?? ''));
            if (!isset($state['inputs']['remarks_memo']) || $state['inputs']['remarks_memo'] === '') {
                if ($latest_plain_remarks !== '') {
                    $state['inputs']['remarks_memo'] = $latest_plain_remarks;
                }
            }

            $arrival_time = trim((string)($progress['arrival_time'] ?? $sched['arrival_time'] ?? ''));
            $room_number  = trim((string)($progress['toilet_room'] ?? $sched['room_number'] ?? ''));

            echo json_encode([
                'success'       => true,
                'has_db_record' => (bool)$sheet_row,
                'schedule_id'   => $schedule_id,
                'state'         => $state,
                'meta'          => [
                    'arrival_time'            => $arrival_time,
                    'room_number'             => $room_number,
                    'current_status_id'       => (int)($progress['current_status_id'] ?? 1),
                    'has_sedation'            => !empty($progress['has_sedation']),
                    'sedation_type'           => (string)($progress['sedation_type'] ?? 'NONE'),
                    'has_biopsy'              => !empty($progress['has_biopsy']),
                    'biopsy_count'            => (int)($progress['biopsy_count'] ?? 0),
                    'biopsy_sites'            => (string)($progress['biopsy_sites'] ?? ''),
                    'biopsy_verified'         => !empty($progress['biopsy_verified']),
                    'is_ready_accounting'     => $sheet_row ? !empty($sheet_row['is_ready_accounting']) : !empty($state['checks']['go_accounting']),
                    'is_billing_completed'    => $sheet_row ? !empty($sheet_row['is_billing_completed']) : false,
                    'billing_completed_at'    => ($sheet_row && !empty($sheet_row['billing_completed_at'])) ? date('H:i', strtotime((string)$sheet_row['billing_completed_at'])) : '',
                    'antithrombotic_verified' => !empty($alert_info['antithrombotic_verified']),
                    'has_antithrombotic'      => !empty($alert_info['has_antithrombotic']),
                    'updated_at'              => $sheet_row ? (string)$sheet_row['updated_at'] : '',
                    'updated_time_str'        => ($sheet_row && !empty($sheet_row['updated_at'])) ? date('H:i:s', strtotime((string)$sheet_row['updated_at'])) : '',
                ],
                'summary' => buildSheetSummaryForMonitor($sheet_row),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ---------------------------------------------------------------------
        // シート状態の差分マージ保存 ＆ 双方向同期 (save_state)
        // ---------------------------------------------------------------------
        case 'save_state':
            $pdo->beginTransaction();

            $stmt_sheet = $pdo->prepare("SELECT * FROM t_endoscopy_sheet WHERE schedule_id = :id FOR UPDATE");
            $stmt_sheet->execute([':id' => $schedule_id]);
            $sheet_row = $stmt_sheet->fetch(PDO::FETCH_ASSOC);

            $state = ['checks' => [], 'circles' => [], 'inputs' => []];
            if ($sheet_row && !empty($sheet_row['sheet_state_json'])) {
                $decoded = json_decode((string)$sheet_row['sheet_state_json'], true);
                if (is_array($decoded)) {
                    $state['checks']  = isset($decoded['checks']) && is_array($decoded['checks']) ? $decoded['checks'] : [];
                    $state['circles'] = isset($decoded['circles']) && is_array($decoded['circles']) ? $decoded['circles'] : [];
                    $state['inputs']  = isset($decoded['inputs']) && is_array($decoded['inputs']) ? $decoded['inputs'] : [];
                }
            }

            // 初回保存時またはフル同期時にベース状態をマージ
            $full_state = isset($input['full_state']) ? $input['full_state'] : null;
            if (is_string($full_state)) {
                $full_state = json_decode($full_state, true);
            }
            if (!$sheet_row && is_array($full_state)) {
                if (isset($full_state['checks']) && is_array($full_state['checks'])) {
                    $state['checks'] = array_merge($state['checks'], $full_state['checks']);
                }
                if (isset($full_state['circles']) && is_array($full_state['circles'])) {
                    $state['circles'] = array_merge($state['circles'], $full_state['circles']);
                }
                if (isset($full_state['inputs']) && is_array($full_state['inputs'])) {
                    $state['inputs'] = array_merge($state['inputs'], $full_state['inputs']);
                }
            }

            // キー単位の差分取得 (delta_checks / delta_circles / delta_inputs)
            $delta_checks  = isset($input['delta_checks']) ? $input['delta_checks'] : [];
            $delta_circles = isset($input['delta_circles']) ? $input['delta_circles'] : [];
            $delta_inputs  = isset($input['delta_inputs']) ? $input['delta_inputs'] : [];
            if (is_string($delta_checks))  $delta_checks  = json_decode($delta_checks, true) ?: [];
            if (is_string($delta_circles)) $delta_circles = json_decode($delta_circles, true) ?: [];
            if (is_string($delta_inputs))  $delta_inputs  = json_decode($delta_inputs, true) ?: [];

            // 排他チェックグループの調整
            $ex_check_groups = getExclusiveCheckGroups();
            foreach ($delta_checks as $k => $v) {
                $bool_v = !empty($v);
                $state['checks'][(string)$k] = $bool_v;
                if ($bool_v) {
                    foreach ($ex_check_groups as $grp) {
                        if (in_array((string)$k, $grp, true)) {
                            foreach ($grp as $other_k) {
                                if ($other_k !== (string)$k) {
                                    $state['checks'][$other_k] = false;
                                }
                            }
                        }
                    }
                }
            }

            // 排他丸囲み・楕円スタンプグループの調整
            $ex_circle_groups = getExclusiveCircleGroups();
            foreach ($delta_circles as $k => $v) {
                $bool_v = !empty($v);
                $state['circles'][(string)$k] = $bool_v;
                if ($bool_v) {
                    foreach ($ex_circle_groups as $grp) {
                        if (in_array((string)$k, $grp, true)) {
                            foreach ($grp as $other_k) {
                                if ($other_k !== (string)$k) {
                                    $state['circles'][$other_k] = false;
                                }
                            }
                        }
                    }
                }
            }

            // テキスト入力の差分マージ
            foreach ($delta_inputs as $k => $v) {
                $state['inputs'][(string)$k] = (string)$v;
            }

            // -----------------------------------------------------------------
            // 構造化カラム値の算出
            // -----------------------------------------------------------------
            $bp_sys_raw = trim((string)($state['inputs']['bp_sys'] ?? ''));
            $bp_dia_raw = trim((string)($state['inputs']['bp_dia'] ?? ''));
            $bp_sys = (is_numeric($bp_sys_raw) && (int)$bp_sys_raw > 0 && (int)$bp_sys_raw <= 400) ? (int)$bp_sys_raw : null;
            $bp_dia = (is_numeric($bp_dia_raw) && (int)$bp_dia_raw > 0 && (int)$bp_dia_raw <= 400) ? (int)$bp_dia_raw : null;

            $t_start_h = trim((string)($state['inputs']['t_start_h'] ?? ''));
            $t_start_m = trim((string)($state['inputs']['t_start_m'] ?? ''));
            $exam_start_time = (is_numeric($t_start_h) && is_numeric($t_start_m))
                ? sprintf('%02d:%02d', max(0, min(23, (int)$t_start_h)), max(0, min(59, (int)$t_start_m)))
                : null;

            $t_end_h = trim((string)($state['inputs']['t_end_h'] ?? ''));
            $t_end_m = trim((string)($state['inputs']['t_end_m'] ?? ''));
            $exam_end_time = (is_numeric($t_end_h) && is_numeric($t_end_m))
                ? sprintf('%02d:%02d', max(0, min(23, (int)$t_end_h)), max(0, min(59, (int)$t_end_m)))
                : null;

            $t_wake_h = trim((string)($state['inputs']['t_wake_h'] ?? ''));
            $t_wake_m = trim((string)($state['inputs']['t_wake_m'] ?? ''));
            $wake_time = (is_numeric($t_wake_h) && is_numeric($t_wake_m))
                ? sprintf('%02d:%02d', max(0, min(23, (int)$t_wake_h)), max(0, min(59, (int)$t_wake_m)))
                : null;

            $t_exp_h = trim((string)($state['inputs']['t_exp_h'] ?? ''));
            $t_exp_m = trim((string)($state['inputs']['t_exp_m'] ?? ''));
            $explain_time = (is_numeric($t_exp_h) && is_numeric($t_exp_m))
                ? sprintf('%02d:%02d', max(0, min(23, (int)$t_exp_h)), max(0, min(59, (int)$t_exp_m)))
                : null;

            $post_destination = null;
            if (!empty($state['circles']['stamp_recovery'])) {
                $post_destination = 'RECOVERY';
            } elseif (!empty($state['circles']['stamp_waiting'])) {
                $post_destination = 'WAITING';
            } elseif (!empty($state['circles']['stamp_ok'])) {
                $post_destination = 'OK';
            }

            $doctor_sign = trim((string)($state['inputs']['doctor_sign'] ?? ''));
            $is_ready_accounting = !empty($state['checks']['go_accounting']);

            // -----------------------------------------------------------------
            // 双方向同期: 内視鏡シート ➔ t_today_progress / t_endoscopy_schedule / t_patient_medical_alert
            // -----------------------------------------------------------------
            $prog_updates = [];
            $prog_params  = [':schedule_id' => $schedule_id];
            $current_status_id = (int)($progress['current_status_id'] ?? 1);
            $new_status_id     = $current_status_id;
            $status_log_reason = '';

            // ① 来院時刻の更新（シート上から来院時刻打刻・編集された場合）
            if (isset($input['arrival_time'])) {
                $new_arr = trim((string)$input['arrival_time']);
                if ($new_arr !== '') {
                    $prog_updates[] = 'arrival_time = :arrival_time';
                    $prog_updates[] = 'is_arrived = TRUE';
                    $prog_params[':arrival_time'] = $new_arr;
                    $pdo->prepare("UPDATE t_endoscopy_schedule SET arrival_time = :arr WHERE schedule_id = :id")
                        ->execute([':arr' => $new_arr, ':id' => $schedule_id]);
                }
            }

            // ④ 医療安全・抗血栓薬休薬確認（問診欄の抗凝固薬チェックとホワイトボード「💊休薬確認済✔」の同期）
            if (array_key_exists('anti_stop_yes', $delta_checks) || array_key_exists('anti_stop_no', $delta_checks) || array_key_exists('anti_no', $delta_checks)) {
                $is_anti_confirmed = !empty($state['checks']['anti_stop_yes']) || !empty($state['checks']['anti_stop_no']) || !empty($state['checks']['anti_no']);
                $pdo->prepare("
                    INSERT INTO t_patient_medical_alert (
                        schedule_id, exam_date, antithrombotic_verified, verified_by, updated_at
                    ) VALUES (
                        :id, :date, :ver, :vby, CURRENT_TIMESTAMP
                    )
                    ON CONFLICT (schedule_id) DO UPDATE SET
                        antithrombotic_verified = EXCLUDED.antithrombotic_verified,
                        verified_by = EXCLUDED.verified_by,
                        updated_at = CURRENT_TIMESTAMP
                ")->execute([
                    ':id'   => $schedule_id,
                    ':date' => $exam_date,
                    ':ver'  => $is_anti_confirmed ? 'TRUE' : 'FALSE',
                    ':vby'  => $is_anti_confirmed ? '内視鏡シート問診' : null,
                ]);
            }

            // ⑤ 鎮静剤（セデーション）種別・有無の同期
            $sedation_keys_touched = array_key_exists('drug_propofol', $delta_checks)
                || array_key_exists('drug_diazepam', $delta_checks)
                || array_key_exists('drug_pethilorfan', $delta_checks)
                || array_key_exists('drug_propofol_mg', $delta_inputs)
                || array_key_exists('drug_diazepam_ml', $delta_inputs)
                || array_key_exists('drug_pethi_ml', $delta_inputs);

            $effective_has_sedation = !empty($progress['has_sedation']);
            if ($sedation_keys_touched) {
                $use_propofol = !empty($state['checks']['drug_propofol']) || trim((string)($state['inputs']['drug_propofol_mg'] ?? '')) !== '';
                $use_diazepam = !empty($state['checks']['drug_diazepam']) || trim((string)($state['inputs']['drug_diazepam_ml'] ?? '')) !== '';
                $use_pethi    = !empty($state['checks']['drug_pethilorfan']) || trim((string)($state['inputs']['drug_pethi_ml'] ?? '')) !== '';

                if ($use_propofol) {
                    $effective_has_sedation = true;
                    $prog_updates[] = "has_sedation = TRUE";
                    $prog_updates[] = "sedation_type = 'PROPOFOL'";
                    $prog_updates[] = "recovery_target_minutes = 20";
                    $pdo->prepare("UPDATE t_endoscopy_schedule SET has_sedation = TRUE WHERE schedule_id = :id")
                        ->execute([':id' => $schedule_id]);
                } elseif ($use_diazepam || $use_pethi) {
                    $effective_has_sedation = true;
                    $prog_updates[] = "has_sedation = TRUE";
                    $prog_updates[] = "sedation_type = 'DIAZEPAM'";
                    $prog_updates[] = "recovery_target_minutes = 30";
                    $pdo->prepare("UPDATE t_endoscopy_schedule SET has_sedation = TRUE WHERE schedule_id = :id")
                        ->execute([':id' => $schedule_id]);
                }
            }

            // ⑥ 検査開始・終了・起床・説明時刻 ＆ ステータス自動進捗
            $start_touched = array_key_exists('t_start_h', $delta_inputs) || array_key_exists('t_start_m', $delta_inputs);
            $end_touched   = array_key_exists('t_end_h', $delta_inputs)   || array_key_exists('t_end_m', $delta_inputs);
            $wake_touched  = array_key_exists('t_wake_h', $delta_inputs)  || array_key_exists('t_wake_m', $delta_inputs);
            $exp_touched   = array_key_exists('t_exp_h', $delta_inputs)   || array_key_exists('t_exp_m', $delta_inputs);

            if ($start_touched && $exam_start_time !== null) {
                $prog_updates[] = 'exam_start_time = :exam_start_time';
                $prog_params[':exam_start_time'] = $exam_start_time;
                $prog_updates[] = 'is_exam_ready = FALSE';
                if ($new_status_id < 4) {
                    $new_status_id = 4; // 4. 検査中
                    $status_log_reason = "内視鏡シートで検査開始時刻 ({$exam_start_time}) を記録";
                }
            }

            if ($end_touched && $exam_end_time !== null) {
                $prog_updates[] = 'exam_end_time = :exam_end_time';
                $prog_params[':exam_end_time'] = $exam_end_time;

                // スコープ洗浄指示を自動発行
                if (empty($progress['scope_cleaning_status']) || $progress['scope_cleaning_status'] === 'NONE') {
                    $prog_updates[] = "scope_cleaning_status = 'ORDERED'";
                    $prog_updates[] = "scope_cleaning_ordered_at = :clean_ordered";
                    $prog_params[':clean_ordered'] = $now_ts;
                }

                if ($new_status_id <= 4) {
                    if ($effective_has_sedation && $post_destination !== 'WAITING' && $post_destination !== 'OK') {
                        $new_status_id = 5; // 5. リカバリ中
                        $prog_updates[] = 'recovery_start_time = COALESCE(recovery_start_time, :rec_start)';
                        $prog_params[':rec_start'] = $now_ts;
                        if ($post_destination === null) {
                            $post_destination = 'RECOVERY';
                            $state['circles']['stamp_recovery'] = true;
                        }
                        $status_log_reason = "内視鏡シートで検査終了時刻 ({$exam_end_time}) を記録 ➔ リカバリ開始";
                    } else {
                        $new_status_id = 6; // 6. 説明
                        $prog_updates[] = 'is_explain_waiting = TRUE';
                        if ($post_destination === null) {
                            $post_destination = 'WAITING';
                            $state['circles']['stamp_waiting'] = true;
                        }
                        $status_log_reason = "内視鏡シートで検査終了時刻 ({$exam_end_time}) を記録 ➔ 説明待ち";
                    }
                }
            }

            if ($wake_touched && $wake_time !== null) {
                $prog_updates[] = 'recovery_end_time = COALESCE(recovery_end_time, :rec_end)';
                $prog_params[':rec_end'] = $now_ts;
                if ($new_status_id === 5) {
                    $new_status_id = 6;
                    $prog_updates[] = 'is_explain_waiting = TRUE';
                    $status_log_reason = "内視鏡シートで起床時刻 ({$wake_time}) を記録 ➔ 説明待ち";
                }
            }

            if ($exp_touched && $explain_time !== null) {
                $prog_updates[] = 'explain_start_time = :explain_start_time';
                $prog_params[':explain_start_time'] = $explain_time;
                $prog_updates[] = 'is_explain_waiting = FALSE';
                if ($new_status_id < 6) {
                    $new_status_id = 6;
                    $status_log_reason = "内視鏡シートで説明時刻 ({$explain_time}) を記録 ➔ 説明中";
                }
            }

            // ⑧ 検査後動線スタンプ（[回復] / [待合室] / [OK]）および説明・会計チェック連動
            if (!empty($delta_circles['stamp_recovery'])) {
                if ($exam_end_time === null) {
                    $exam_end_time = $now_hm;
                    $parts = explode(':', $now_hm);
                    $state['inputs']['t_end_h'] = $parts[0];
                    $state['inputs']['t_end_m'] = $parts[1];
                    $prog_updates[] = 'exam_end_time = COALESCE(exam_end_time, :stamp_end_hm)';
                    $prog_params[':stamp_end_hm'] = $now_hm;
                }
                if (empty($progress['scope_cleaning_status']) || $progress['scope_cleaning_status'] === 'NONE') {
                    $prog_updates[] = "scope_cleaning_status = 'ORDERED'";
                    $prog_updates[] = "scope_cleaning_ordered_at = COALESCE(scope_cleaning_ordered_at, :stamp_clean_ts)";
                    $prog_params[':stamp_clean_ts'] = $now_ts;
                }
                if ($new_status_id <= 4) {
                    $new_status_id = 5;
                    $prog_updates[] = 'recovery_start_time = COALESCE(recovery_start_time, :stamp_rec_ts)';
                    $prog_params[':stamp_rec_ts'] = $now_ts;
                    $status_log_reason = '内視鏡シートで [回復] スタンプ押下 ➔ リカバリ中';
                }
            } elseif (!empty($delta_circles['stamp_waiting']) || !empty($delta_circles['stamp_ok'])) {
                $prog_updates[] = 'is_explain_waiting = TRUE';
                if ($new_status_id <= 5) {
                    $new_status_id = 6;
                    $prog_updates[] = 'recovery_end_time = COALESCE(recovery_end_time, :stamp_wake_ts)';
                    $prog_params[':stamp_wake_ts'] = $now_ts;
                    $stamp_label = !empty($delta_circles['stamp_ok']) ? '[OK]' : '[待合室]';
                    $status_log_reason = "内視鏡シートで {$stamp_label} スタンプ押下 ➔ 説明待ち";
                }
            }

            if (!empty($delta_checks['post_gif_exp']) || !empty($delta_checks['post_cf_exp'])) {
                $prog_updates[] = 'is_explain_waiting = FALSE';
                $prog_updates[] = 'explain_start_time = COALESCE(explain_start_time, :post_exp_hm)';
                $prog_params[':post_exp_hm'] = $now_hm;
                if ($new_status_id < 6) {
                    $new_status_id = 6;
                    $status_log_reason = '内視鏡シートでカメラ説明チェック ➔ 説明';
                }
            }

            if (!empty($delta_checks['go_accounting'])) {
                $prog_updates[] = 'is_explain_waiting = FALSE';
                if ($new_status_id < 6) {
                    $new_status_id = 6;
                    $status_log_reason = '内視鏡シートで [□会計へ] チェック';
                }
            }

            // ⑦ 生検・ポリペク部位 ＆ 病理検体照合（t_today_progress.has_biopsy / biopsy_sites / biopsy_count）同期
            $biopsy_touched = false;
            foreach (array_keys($delta_checks) as $ck) {
                if (strpos((string)$ck, 'cf_biopsy') === 0 || strpos((string)$ck, 'cf_poly') === 0 || strpos((string)$ck, 'gif_biopsy') === 0 || $ck === 'gif_poly') {
                    $biopsy_touched = true;
                    break;
                }
            }
            if (!$biopsy_touched) {
                foreach (array_keys($delta_circles) as $ck) {
                    if (strpos((string)$ck, 'cf_biopsy_') === 0 || strpos((string)$ck, 'cf_poly_') === 0 || strpos((string)$ck, 'gif_biopsy_') === 0) {
                        $biopsy_touched = true;
                        break;
                    }
                }
            }

            if ($biopsy_touched) {
                $cf_sites = ['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸'];
                $cf_poly_u2_sites = ['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸', '回腸'];
                $gif_sites = ['胃', '食道', '十二指腸'];

                $collected_sites = [];
                foreach ($cf_sites as $idx => $s_name) {
                    if (!empty($state['circles']['cf_biopsy_' . $idx])) {
                        $collected_sites[$s_name] = $s_name . ':1';
                        $state['checks']['cf_biopsy'] = true;
                    }
                }
                foreach ($cf_poly_u2_sites as $idx => $s_name) {
                    if (!empty($state['circles']['cf_poly_u2_' . $idx])) {
                        $collected_sites[$s_name] = $s_name . ':1';
                        $state['checks']['cf_poly_u2'] = true;
                    }
                }
                foreach ($cf_sites as $idx => $s_name) {
                    if (!empty($state['circles']['cf_poly_o2_' . $idx])) {
                        $collected_sites[$s_name] = $s_name . ':1';
                        $state['checks']['cf_poly_o2'] = true;
                    }
                }
                foreach ($gif_sites as $idx => $s_name) {
                    if (!empty($state['circles']['gif_biopsy_' . $idx])) {
                        $collected_sites[$s_name] = $s_name . ':1';
                        $state['checks']['gif_biopsy'] = true;
                    }
                }
                if (!empty($state['checks']['gif_poly']) && !isset($collected_sites['胃'])) {
                    $collected_sites['胃'] = '胃(ポリペク):1';
                }

                $sheet_has_biopsy = !empty($collected_sites)
                    || !empty($state['checks']['cf_biopsy'])
                    || !empty($state['checks']['cf_poly_u2'])
                    || !empty($state['checks']['cf_poly_o2'])
                    || !empty($state['checks']['gif_biopsy'])
                    || !empty($state['checks']['gif_poly']);

                if ($sheet_has_biopsy) {
                    $sites_str = implode(', ', array_values($collected_sites));
                    $cnt = max(1, count($collected_sites), (int)($progress['biopsy_count'] ?? 0));
                    if (count($collected_sites) > 0) {
                        $cnt = count($collected_sites);
                    }
                    $prog_updates[] = 'has_biopsy = TRUE';
                    $prog_updates[] = 'biopsy_count = :biopsy_count';
                    $prog_params[':biopsy_count'] = $cnt;
                    if ($sites_str !== '') {
                        $prog_updates[] = 'biopsy_sites = :biopsy_sites';
                        $prog_params[':biopsy_sites'] = $sites_str;
                    }
                } elseif (empty($progress['biopsy_verified'])) {
                    $prog_updates[] = 'has_biopsy = FALSE';
                    $prog_updates[] = 'biopsy_count = 0';
                    $prog_updates[] = "biopsy_sites = ''";
                }
            }

            // ⑨ 予約備考（remarks_memo）の双方向同期
            if (array_key_exists('remarks_memo', $delta_inputs)) {
                $new_plain_remarks = (string)$delta_inputs['remarks_memo'];
                $new_html_remarks  = convertPlainRemarksToHtml($new_plain_remarks);
                $pdo->prepare("UPDATE t_endoscopy_schedule SET remarks = :remarks, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :id")
                    ->execute([':remarks' => $new_html_remarks, ':id' => $schedule_id]);
            }

            // ステータス変更があれば反映＆ログ記録
            if ($new_status_id !== $current_status_id) {
                $prog_updates[] = 'current_status_id = :new_status_id';
                $prog_params[':new_status_id'] = $new_status_id;

                $pdo->prepare("
                    INSERT INTO t_progress_status_log (
                        schedule_id, exam_date, from_status_id, to_status_id,
                        action_type, operator_name, operator_role, comment
                    ) VALUES (
                        :sid, :date, :from_st, :to_st,
                        'SHEET_SYNC', '内視鏡シート', 'nurse', :comment
                    )
                ")->execute([
                    ':sid'     => $schedule_id,
                    ':date'    => $exam_date,
                    ':from_st' => $current_status_id,
                    ':to_st'   => $new_status_id,
                    ':comment' => $status_log_reason !== '' ? $status_log_reason : "内視鏡シート連動: {$current_status_id} ➔ {$new_status_id}",
                ]);
            }

            if (!empty($prog_updates)) {
                $prog_updates[] = 'updated_at = :prog_updated_at';
                $prog_params[':prog_updated_at'] = $now_ts;
                // 同一カラムへの複数回代入（PostgreSQLエラー）を防止するためカラム名単位で後勝ち重複排除
                $deduped_updates = [];
                foreach ($prog_updates as $expr) {
                    $col_name = trim( explode('=', $expr, 2)[0] );
                    $deduped_updates[$col_name] = $expr;
                }
                $sql_prog = "UPDATE t_today_progress SET " . implode(', ', array_values($deduped_updates)) . " WHERE schedule_id = :schedule_id";
                $pdo->prepare($sql_prog)->execute($prog_params);
            }

            // -----------------------------------------------------------------
            // t_endoscopy_sheet への UPSERT
            // -----------------------------------------------------------------
            $json_str = json_encode($state, JSON_UNESCAPED_UNICODE);
            $upsert = $pdo->prepare("
                INSERT INTO t_endoscopy_sheet (
                    schedule_id, patient_id, exam_date,
                    bp_sys, bp_dia,
                    exam_start_time, exam_end_time, wake_time, explain_time,
                    post_destination, doctor_sign, is_ready_accounting,
                    sheet_state_json, updated_at, updated_by_ip
                ) VALUES (
                    :schedule_id, :patient_id, :exam_date,
                    :bp_sys, :bp_dia,
                    :exam_start_time, :exam_end_time, :wake_time, :explain_time,
                    :post_destination, :doctor_sign, :is_ready_accounting,
                    :sheet_state_json, CURRENT_TIMESTAMP, :updated_by_ip
                )
                ON CONFLICT (schedule_id) DO UPDATE SET
                    patient_id          = EXCLUDED.patient_id,
                    exam_date           = EXCLUDED.exam_date,
                    bp_sys              = EXCLUDED.bp_sys,
                    bp_dia              = EXCLUDED.bp_dia,
                    exam_start_time     = EXCLUDED.exam_start_time,
                    exam_end_time       = EXCLUDED.exam_end_time,
                    wake_time           = EXCLUDED.wake_time,
                    explain_time        = EXCLUDED.explain_time,
                    post_destination    = EXCLUDED.post_destination,
                    doctor_sign         = EXCLUDED.doctor_sign,
                    is_ready_accounting = EXCLUDED.is_ready_accounting,
                    sheet_state_json    = EXCLUDED.sheet_state_json,
                    updated_at          = CURRENT_TIMESTAMP,
                    updated_by_ip       = EXCLUDED.updated_by_ip
            ");
            $upsert->execute([
                ':schedule_id'         => $schedule_id,
                ':patient_id'          => $patient_id,
                ':exam_date'           => $exam_date,
                ':bp_sys'              => $bp_sys,
                ':bp_dia'              => $bp_dia,
                ':exam_start_time'     => $exam_start_time,
                ':exam_end_time'       => $exam_end_time,
                ':wake_time'           => $wake_time,
                ':explain_time'        => $explain_time,
                ':post_destination'    => $post_destination,
                ':doctor_sign'         => $doctor_sign !== '' ? $doctor_sign : null,
                ':is_ready_accounting' => $is_ready_accounting ? 1 : 0,
                ':sheet_state_json'    => $json_str,
                ':updated_by_ip'       => $client_ip,
            ]);

            $pdo->commit();

            // 最新シート行を再取得してサマリ返却
            $stmt_after = $pdo->prepare("SELECT * FROM t_endoscopy_sheet WHERE schedule_id = :id");
            $stmt_after->execute([':id' => $schedule_id]);
            $after_row = $stmt_after->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'           => true,
                'schedule_id'       => $schedule_id,
                'current_status_id' => $new_status_id,
                'status_changed'    => ($new_status_id !== $current_status_id),
                'state'             => $state,
                'updated_time_str'  => date('H:i:s'),
                'summary'           => buildSheetSummaryForMonitor($after_row),
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ---------------------------------------------------------------------
        // 医事・レセプト入力完了トグル (toggle_billing)
        // ---------------------------------------------------------------------
        case 'toggle_billing':
            $is_completed = !empty($input['is_billing_completed']);
            $operator_name = isset($input['operator_name']) ? trim((string)$input['operator_name']) : '医事スタッフ';
            $advance_done  = !empty($input['advance_to_done']);

            $pdo->beginTransaction();

            $stmt_sheet = $pdo->prepare("SELECT * FROM t_endoscopy_sheet WHERE schedule_id = :id FOR UPDATE");
            $stmt_sheet->execute([':id' => $schedule_id]);
            $sheet_row = $stmt_sheet->fetch(PDO::FETCH_ASSOC);

            if (!$sheet_row) {
                $ins = $pdo->prepare("
                    INSERT INTO t_endoscopy_sheet (
                        schedule_id, patient_id, exam_date,
                        is_billing_completed, billing_completed_at, billing_completed_by,
                        sheet_state_json, updated_at, updated_by_ip
                    ) VALUES (
                        :id, :pid, :date,
                        :comp, :comp_at, :comp_by,
                        '{}'::jsonb, CURRENT_TIMESTAMP, :ip
                    )
                ");
                $ins->execute([
                    ':id'      => $schedule_id,
                    ':pid'     => $patient_id,
                    ':date'    => $exam_date,
                    ':comp'    => $is_completed ? 1 : 0,
                    ':comp_at' => $is_completed ? $now_ts : null,
                    ':comp_by' => $is_completed ? $operator_name : null,
                    ':ip'      => $client_ip,
                ]);
            } else {
                $up = $pdo->prepare("
                    UPDATE t_endoscopy_sheet
                    SET is_billing_completed = :comp,
                        billing_completed_at = :comp_at,
                        billing_completed_by = :comp_by,
                        updated_at = CURRENT_TIMESTAMP,
                        updated_by_ip = :ip
                    WHERE schedule_id = :id
                ");
                $up->execute([
                    ':comp'    => $is_completed ? 1 : 0,
                    ':comp_at' => $is_completed ? $now_ts : null,
                    ':comp_by' => $is_completed ? $operator_name : null,
                    ':ip'      => $client_ip,
                    ':id'      => $schedule_id,
                ]);
            }

            if ($is_completed && $advance_done && (int)$progress['current_status_id'] < 7) {
                $pdo->prepare("
                    UPDATE t_today_progress
                    SET current_status_id = 7,
                        done_time = COALESCE(done_time, :done_hm),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE schedule_id = :id
                ")->execute([
                    ':done_hm' => $now_hm,
                    ':id'      => $schedule_id,
                ]);
            }

            $pdo->commit();

            echo json_encode([
                'success'              => true,
                'schedule_id'          => $schedule_id,
                'is_billing_completed' => $is_completed,
                'billing_completed_at' => $is_completed ? date('H:i') : '',
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ---------------------------------------------------------------------
        // シート初期化リセット (reset_sheet)
        // ---------------------------------------------------------------------
        case 'reset_sheet':
            $pdo->prepare("DELETE FROM t_endoscopy_sheet WHERE schedule_id = :id")
                ->execute([':id' => $schedule_id]);

            echo json_encode([
                'success'     => true,
                'schedule_id' => $schedule_id,
                'message'     => '内視鏡シートの入力データを初期状態にリセットしました',
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'error' => '未対応のアクションです: ' . $action], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error'   => 'シート同期処理エラー: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
