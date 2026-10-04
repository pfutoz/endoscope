<?php
/**
 * 生成日時: 2026-09-07 12:00:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\action_schedule.php
 * ファイル名: action_schedule.php
 * 
 * 予約更新API
 * 
 * 機能:
 *   - insert: 新規予約登録
 *   - update: 予約更新（通常更新）
 *   - delete: 予約削除（論理削除）- ★ 独立分岐
 *   - restore: 削除済み予約の復活
 *   - move: ドラッグ＆ドロップ移動
 * 
 * 修正履歴:
 *   - 2026-08-18: delete 分岐を独立、アクション別バリデーション実装
 *   - 2026-09-07: room_number (マイトイレ), bowel_prep (下剤) の保存に対応
 *   - 2026-09-07: 仮予約時・必須未設定保存時のバリデーション緩和対応
 *   - 2026-10-04: is_admission（病棟連動入院予定）のINSERTバインドパラメータ漏れ修正、およびUPDATE時の保存・差分検知に対応
 */
ini_set('display_errors', "0");
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

// 💡 共通ロガーを読み込み
require_once 'logger.php';
writeLog('SCHEDULE', 'action_schedule.php 呼び出し検知', $_POST);

// 💡 共通ライブラリ・データベース接続モジュールを読み込み
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/audit_helper.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    writeLog('SCHEDULE', '致命的エラー: データベース接続失敗');
    echo json_encode(['success' => false, 'message' => "データベース接続失敗"]);
    exit;
}
writeLog('SCHEDULE', 'データベース接続成功');

// 💡 共通ガード関数で、insert時の古い schedule_id 混入を完全にシャットアウト
list($action_type, $schedule_id) = sanitizeActionTypeAndId(
    $_POST['action_type'] ?? $_POST['action'] ?? '', 
    $_POST['schedule_id'] ?? 0
);

writeLog('SCHEDULE', "アクション種別確定: action_type = '{$action_type}', schedule_id = {$schedule_id}");

// ============================================================
// 共通変数の受け取り
// ============================================================
$exam_date       = isset($_POST['exam_date']) ? trim($_POST['exam_date']) : '';
$scheduled_time  = isset($_POST['scheduled_time']) ? trim($_POST['scheduled_time']) : '';
$department_code = isset($_POST['department_code']) ? trim($_POST['department_code']) : 'PROCT';
$patient_id      = isset($_POST['patient_id']) && trim($_POST['patient_id']) !== '' ? trim($_POST['patient_id']) : null;
$patient_name    = isset($_POST['patient_name']) ? trim($_POST['patient_name']) : '';
$patient_kana    = isset($_POST['patient_kana']) ? trim($_POST['patient_kana']) : '';
$birthday        = isset($_POST['birthday']) && trim($_POST['birthday']) !== '' ? trim($_POST['birthday']) : null;
$doctor_name     = isset($_POST['doctor_name']) ? trim($_POST['doctor_name']) : '';
$exam_type       = isset($_POST['exam_type']) ? trim($_POST['exam_type']) : '';
$arrival_time    = isset($_POST['arrival_time']) && trim($_POST['arrival_time']) !== '' ? trim($_POST['arrival_time']) : null;
$exam_location   = isset($_POST['exam_location']) ? trim($_POST['exam_location']) : '';
$remarks         = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

// ★新設カラム（部屋番号・下剤）
$room_number     = isset($_POST['room_number']) ? trim($_POST['room_number']) : '';
$bowel_prep      = isset($_POST['bowel_prep']) ? trim($_POST['bowel_prep']) : '';

/**
 * 時間枠定義（m2_waku）から表示順序（waku_no）を取得
 */
function getWakuDisplayOrderFromM2($pdo, $dept, $scheduled_time) {
    if (empty($scheduled_time)) return 1;
    $time_raw  = trim($scheduled_time);
    $time_half = mb_convert_kana($time_raw, 'a'); // ＃1 -> #1, 全角英数字半角化
    
    $stmt = $pdo->prepare("
        SELECT display_order 
        FROM m2_waku 
        WHERE department_code = :dept 
          AND (waku_name = :w1 OR waku_name = :w2)
          AND is_active = TRUE 
        LIMIT 1
    ");
    $stmt->execute([':dept' => $dept, ':w1' => $time_raw, ':w2' => $time_half]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        writeLog('SCHEDULE', "時間枠順序(waku_no)取得成功 (m2_waku): {$res['display_order']}");
        return (int)$res['display_order'];
    }
    writeLog('SCHEDULE', "警告: 時間枠マスター(m2_waku)が見つからないため waku_no=1 とします: {$scheduled_time}");
    return 1;
}

try {
    // ============================================================
    // 【分岐1】新規予約登録処理 (insert)
    // ============================================================
    if ($action_type === 'insert') {
        writeLog('SCHEDULE', '--- 分岐: 新規登録 (insert) 開始 ---');

        // ★★★ insert 用バリデーション ★★★
        $errors = [];
        if (empty($exam_date)) $errors[] = '検査日';
        if (empty($scheduled_time)) $errors[] = '時間枠';

        // 仮予約判定（患者名が空または「仮」が含まれる場合は必須バリデーションを緩和）
        $is_temp = ($patient_name === '' || mb_strpos($patient_name, '仮') !== false);
        if ($is_temp) {
            if ($patient_name === '') {
                $patient_name = '※仮予約';
                if (empty($patient_kana)) $patient_kana = 'カリヨヤク';
            }
        } else {
            // 通常予約時のみ厳密チェック
            if (empty($patient_name)) $errors[] = '患者氏名';
            if (empty($exam_type)) $errors[] = '検査種別';
            if (empty($exam_location)) $errors[] = '実施場所';
        }
        
        if (!empty($errors)) {
            $msg = '必須項目が入力されていません: ' . implode('、', $errors);
            writeLog('SCHEDULE', "バリデーションエラー: " . $msg);
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        $waku_no = getWakuDisplayOrderFromM2($pdo, $department_code, $scheduled_time);

        if ($patient_id) {
            writeLog('SCHEDULE', "MALLメモ同期試行: patient_id = {$patient_id}");
            syncAndSaveMallMemo($pdo, $patient_id);
        }

        $has_sed_param = isset($_POST['has_sedation']) ? (!empty($_POST['has_sedation']) ? 'true' : 'false') : null;
        $has_drive_param = isset($_POST['has_drive_caution']) ? (!empty($_POST['has_drive_caution']) ? 'true' : 'false') : null;
        $has_admission_param = isset($_POST['is_admission']) ? (!empty($_POST['is_admission']) ? 'true' : 'false') : null;

        $params = [
            ':exam_date'       => $exam_date,
            ':scheduled_time'  => $scheduled_time,
            ':waku_no'         => $waku_no,
            ':department_code' => $department_code,
            ':patient_id'      => $patient_id,
            ':patient_name'    => $patient_name,
            ':patient_kana'    => $patient_kana,
            ':birthday'        => $birthday,
            ':doctor_name'     => $doctor_name,
            ':exam_type'       => $exam_type,
            ':arrival_time'    => $arrival_time,
            ':exam_location'   => $exam_location,
            ':remarks'         => $remarks,
            ':room_number'     => $room_number,
            ':bowel_prep'      => $bowel_prep,
            ':has_sedation'    => $has_sed_param,
            ':has_drive'       => $has_drive_param,
            ':is_admission'    => $has_admission_param
        ];
        writeLog('SCHEDULE', 'INSERT実行パラメータ', $params);

        $sql = "INSERT INTO t_endoscopy_schedule (
                    exam_date, scheduled_time, waku_no, department_code, patient_id, patient_name, patient_kana, birthday,
                    doctor_name, exam_type, arrival_time, exam_location, remarks, room_number, bowel_prep,
                    has_sedation, has_drive_caution, is_admission, is_canceled, created_at, updated_at
                ) VALUES (
                    :exam_date, :scheduled_time, :waku_no, :department_code, :patient_id, :patient_name, :patient_kana, :birthday,
                    :doctor_name, :exam_type, :arrival_time, :exam_location, :remarks, :room_number, :bowel_prep,
                    :has_sedation, :has_drive, :is_admission, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $new_id = (int)$pdo->lastInsertId();
        writeLog('SCHEDULE', "INSERT成功: 採番された schedule_id = {$new_id}");

        // ★ 監査ログ記録
        $new_row = getScheduleRecordById($pdo, $new_id);
        $audit_log_id = recordScheduleAuditLog($pdo, 'insert', $new_id, null, $new_row, [
            'operator_name' => $_POST['operator_name'] ?? null
        ]);
        writeLog('SCHEDULE', "監査ログ記録完了: log_id = {$audit_log_id}");

        // ★ 医療安全アラート同期（備考更新抜け防止 ＆ 画面手動ON/OFF反映）
        syncMedicalAlertOnScheduleSave($pdo, $new_id, $patient_id, $remarks, $_POST);

        // ★ 進捗モニタ連動（当日進捗レコードが存在する場合のセデーション同期: 肛門科=PROPOFOL, 胃腸科=DIAZEPAM）
        if (isset($_POST['manual_alert_set'])) {
            $has_sed_str = !empty($_POST['has_sedation']) ? 'true' : 'false';
            $def_sed_type = ($department_code === 'PROCT') ? 'PROPOFOL' : 'DIAZEPAM';
            try {
                $up_prog = $pdo->prepare("
                    UPDATE t_today_progress 
                    SET has_sedation = :has_sed,
                        sedation_type = CASE WHEN :has_sed = 'true' AND (sedation_type = 'NONE' OR sedation_type IS NULL) THEN :def_type 
                                             WHEN :has_sed = 'false' THEN 'NONE' 
                                             ELSE sedation_type END,
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE schedule_id = :id
                ");
                $up_prog->execute([
                    ':has_sed' => $has_sed_str,
                    ':def_type' => $def_sed_type,
                    ':id' => (int)$new_id
                ]);
            } catch (Exception $e) {}
        }

        echo json_encode([
            'success'         => true, 
            'action'          => 'insert', 
            'schedule_id'     => $new_id,
            'exam_date'       => $exam_date,
            'scheduled_time'  => $scheduled_time,
            'department_code' => $department_code,
            'patient_id'      => $patient_id,
            'patient_name'    => $patient_name,
            'patient_kana'    => $patient_kana,
            'birthday'        => $birthday,
            'doctor_name'     => $doctor_name,
            'exam_type'       => $exam_type,
            'arrival_time'    => $arrival_time,
            'exam_location'   => $exam_location,
            'remarks'         => $remarks,
            'room_number'     => $room_number,
            'bowel_prep'      => $bowel_prep,
            'is_admission'    => $has_admission_param
        ]);
        exit;
    }

    // ============================================================
    // 【分岐2】予約更新処理 (update)
    // ============================================================
    if ($action_type === 'update') {
        writeLog('SCHEDULE', "--- 分岐: 通常更新 (update) 開始 --- schedule_id = {$schedule_id}");

        // ★★★ update 用バリデーション ★★★
        $errors = [];
        if ($schedule_id <= 0) $errors[] = '予約ID';
        if (empty($exam_date)) $errors[] = '検査日';
        if (empty($scheduled_time)) $errors[] = '時間枠';

        $is_temp = ($patient_name === '' || mb_strpos($patient_name, '仮') !== false);
        if ($is_temp) {
            if ($patient_name === '') {
                $patient_name = '※仮予約';
                if (empty($patient_kana)) $patient_kana = 'カリヨヤク';
            }
        } else {
            if (empty($patient_name)) $errors[] = '患者氏名';
            if (empty($exam_type)) $errors[] = '検査種別';
            if (empty($exam_location)) $errors[] = '実施場所';
        }
        
        if (!empty($errors)) {
            $msg = '必須項目が入力されていません: ' . implode('、', $errors);
            writeLog('SCHEDULE', "バリデーションエラー: " . $msg);
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

function normalizeRemarksForDiff(?string $text): string {
    if ($text === null) return '';
    $t = trim($text);
    if ($t === '<p><br></p>' || $t === '<p></p>' || $t === '<p><br/></p>') {
        return '';
    }
    return trim(preg_replace('/\r\n|\r|\n/', "\n", $t));
}

        $waku_no = getWakuDisplayOrderFromM2($pdo, $department_code, $scheduled_time);

        if ($patient_id) {
            writeLog('SCHEDULE', "更新に伴うMALLメモ同期試行: patient_id = {$patient_id}");
            syncAndSaveMallMemo($pdo, $patient_id);
        }

        // ★ 更新前レコード取得（監査ログ・差分検知用）
        $old_row = getScheduleRecordById($pdo, (int)$schedule_id);
        if (!$old_row) {
            writeLog('SCHEDULE', "エラー: schedule_id = {$schedule_id} が見つかりません");
            echo json_encode(['success' => false, 'message' => '指定された予約が見つかりません']);
            exit;
        }

        // 更新前のアラート状態取得
        $old_alerts_map = getMedicalAlertsMap($pdo, [(int)$schedule_id]);
        $old_alert = $old_alerts_map[(int)$schedule_id] ?? [
            'has_antithrombotic' => false,
            'has_diabetes'       => false,
            'has_psychotropic'   => false,
            'has_nsaids'         => false,
            'has_allergy'        => false,
            'has_infection'      => false,
            'has_cold_fever'     => false
        ];

        // 送信された新アラートフラグの判定
        $is_manual_alert = isset($_POST['manual_alert_set']) && (string)$_POST['manual_alert_set'] === '1';
        $new_alert = $is_manual_alert ? [
            'has_antithrombotic' => !empty($_POST['has_antithrombotic']),
            'has_diabetes'       => !empty($_POST['has_diabetes']),
            'has_psychotropic'   => !empty($_POST['has_psychotropic']),
            'has_nsaids'         => !empty($_POST['has_nsaids']),
            'has_allergy'        => !empty($_POST['has_allergy']),
            'has_infection'      => !empty($_POST['has_infection']),
            'has_cold_fever'     => !empty($_POST['has_cold_fever'])
        ] : $old_alert;

        // 差分チェック（予約項目 ＋ アラートフラグ）
        $is_diff = false;
        $compare_fields = [
            'exam_date'       => $exam_date,
            'scheduled_time'  => $scheduled_time,
            'department_code' => $department_code,
            'patient_id'      => (string)($patient_id ?? ''),
            'patient_name'    => $patient_name,
            'patient_kana'    => $patient_kana,
            'birthday'        => (string)($birthday ?? ''),
            'doctor_name'     => $doctor_name,
            'exam_type'       => $exam_type,
            'arrival_time'    => $arrival_time,
            'exam_location'   => $exam_location,
            'room_number'     => $room_number,
            'bowel_prep'      => $bowel_prep,
        ];

        foreach ($compare_fields as $f => $val) {
            $old_val = (string)($old_row[$f] ?? '');
            if (trim($old_val) !== trim($val)) {
                $is_diff = true;
                break;
            }
        }

        if (!$is_diff && (int)($old_row['waku_no'] ?? 0) !== (int)$waku_no) {
            $is_diff = true;
        }

        if (!$is_diff && normalizeRemarksForDiff($old_row['remarks'] ?? '') !== normalizeRemarksForDiff($remarks)) {
            $is_diff = true;
        }

        if (!$is_diff && $is_manual_alert) {
            foreach (['has_antithrombotic', 'has_diabetes', 'has_psychotropic', 'has_nsaids', 'has_allergy', 'has_infection', 'has_cold_fever'] as $af) {
                if (!empty($old_alert[$af]) !== !empty($new_alert[$af])) {
                    $is_diff = true;
                    break;
                }
            }
            if (!$is_diff && isset($_POST['has_sedation'])) {
                if (!empty($old_row['has_sedation']) !== !empty($_POST['has_sedation'])) {
                    $is_diff = true;
                }
            }
            if (!$is_diff && isset($_POST['has_drive_caution'])) {
                if (!empty($old_row['has_drive_caution']) !== !empty($_POST['has_drive_caution'])) {
                    $is_diff = true;
                }
            }
            if (!$is_diff) {
                $old_is_adm = !empty($old_row['is_admission']);
                $new_is_adm = !empty($_POST['is_admission']);
                if ($old_is_adm !== $new_is_adm) {
                    $is_diff = true;
                }
            }
        }

        // ★★★ 【完全一致・差分ゼロ】の場合：DB更新・監査ログ・医師通知をすべてスキップ ★★★
        if (!$is_diff) {
            writeLog('SCHEDULE', "無変更保存を検知: schedule_id = {$schedule_id} （DB更新・監査ログ・医師通知をすべてスキップ）");
            echo json_encode([
                'success'         => true, 
                'action'          => 'update', 
                'schedule_id'     => $schedule_id,
                'no_change'       => true,
                'message'         => '変更はありませんでした',
                'exam_date'       => $exam_date,
                'scheduled_time'  => $scheduled_time,
                'department_code' => $department_code,
                'patient_id'      => $patient_id,
                'patient_name'    => $patient_name,
                'patient_kana'    => $patient_kana,
                'birthday'        => $birthday,
                'doctor_name'     => $doctor_name,
                'exam_type'       => $exam_type,
                'arrival_time'    => $arrival_time,
                'exam_location'   => $exam_location,
                'remarks'         => $remarks,
                'room_number'     => $room_number,
                'bowel_prep'      => $bowel_prep,
                'is_admission'    => isset($old_row['is_admission']) && !empty($old_row['is_admission']) ? 'true' : 'false'
            ]);
            exit;
        }

        $has_sed_param = isset($_POST['has_sedation']) ? (!empty($_POST['has_sedation']) ? 'true' : 'false') : (isset($old_row['has_sedation']) && $old_row['has_sedation'] !== null ? (!empty($old_row['has_sedation']) ? 'true' : 'false') : null);
        $has_drive_param = isset($_POST['has_drive_caution']) ? (!empty($_POST['has_drive_caution']) ? 'true' : 'false') : (isset($old_row['has_drive_caution']) && $old_row['has_drive_caution'] !== null ? (!empty($old_row['has_drive_caution']) ? 'true' : 'false') : null);
        $has_admission_param = isset($_POST['manual_alert_set']) 
            ? (!empty($_POST['is_admission']) ? 'true' : 'false') 
            : (isset($_POST['is_admission']) ? (!empty($_POST['is_admission']) ? 'true' : 'false') : (isset($old_row['is_admission']) && $old_row['is_admission'] !== null ? (!empty($old_row['is_admission']) ? 'true' : 'false') : null));

        $params = [
            ':exam_date'       => $exam_date,
            ':scheduled_time'  => $scheduled_time,
            ':waku_no'         => $waku_no,
            ':department_code' => $department_code,
            ':patient_id'      => $patient_id,
            ':patient_name'    => $patient_name,
            ':patient_kana'    => $patient_kana,
            ':birthday'        => $birthday,
            ':doctor_name'     => $doctor_name,
            ':exam_type'       => $exam_type,
            ':arrival_time'    => $arrival_time,
            ':exam_location'   => $exam_location,
            ':remarks'         => $remarks,
            ':room_number'     => $room_number,
            ':bowel_prep'      => $bowel_prep,
            ':has_sedation'    => $has_sed_param,
            ':has_drive'       => $has_drive_param,
            ':is_admission'    => $has_admission_param,
            ':schedule_id'     => $schedule_id
        ];
        writeLog('SCHEDULE', 'UPDATE実行パラメータ', $params);

        $sql = "UPDATE t_endoscopy_schedule SET 
                    exam_date = :exam_date, scheduled_time = :scheduled_time, waku_no = :waku_no, department_code = :department_code, 
                    patient_id = :patient_id, patient_name = :patient_name, patient_kana = :patient_kana, birthday = :birthday,
                    doctor_name = :doctor_name, exam_type = :exam_type, arrival_time = :arrival_time,
                    exam_location = :exam_location, remarks = :remarks, room_number = :room_number, bowel_prep = :bowel_prep,
                    has_sedation = :has_sedation, has_drive_caution = :has_drive, is_admission = :is_admission,
                    updated_at = CURRENT_TIMESTAMP
                WHERE schedule_id = :schedule_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        writeLog('SCHEDULE', "UPDATE成功: schedule_id = {$schedule_id}");

        // ★ 医療安全アラート同期（備考更新抜け防止 ＆ 画面手動ON/OFF反映）
        syncMedicalAlertOnScheduleSave($pdo, (int)$schedule_id, $patient_id, $remarks, $_POST);

        // ★ 進捗モニタ連動（当日進捗レコードが存在する場合のセデーション同期: 肛門科=PROPOFOL, 胃腸科=DIAZEPAM）
        if (isset($_POST['manual_alert_set'])) {
            $has_sed_str = !empty($_POST['has_sedation']) ? 'true' : 'false';
            $def_sed_type = ($department_code === 'PROCT') ? 'PROPOFOL' : 'DIAZEPAM';
            try {
                $up_prog = $pdo->prepare("
                    UPDATE t_today_progress 
                    SET has_sedation = :has_sed,
                        sedation_type = CASE WHEN :has_sed = 'true' AND (sedation_type = 'NONE' OR sedation_type IS NULL) THEN :def_type 
                                             WHEN :has_sed = 'false' THEN 'NONE' 
                                             ELSE sedation_type END,
                        updated_at = CURRENT_TIMESTAMP 
                    WHERE schedule_id = :id
                ");
                $up_prog->execute([
                    ':has_sed' => $has_sed_str,
                    ':def_type' => $def_sed_type,
                    ':id' => (int)$schedule_id
                ]);
            } catch (Exception $e) {}
        }

        // ★ 監査ログ記録（予約データ ＋ アラートフラグを内包）
        $new_row = getScheduleRecordById($pdo, (int)$schedule_id);
        $new_alerts_map = getMedicalAlertsMap($pdo, [(int)$schedule_id]);
        $new_alert_data = $new_alerts_map[(int)$schedule_id] ?? [];

        $old_row['medical_alerts'] = $old_alert;
        if ($new_row) {
            $new_row['medical_alerts'] = $new_alert_data;
        }

        $audit_log_id = recordScheduleAuditLog($pdo, 'update', (int)$schedule_id, $old_row, $new_row, [
            'operator_name' => $_POST['operator_name'] ?? null
        ]);
        writeLog('SCHEDULE', "監査ログ記録完了: log_id = {$audit_log_id}");

        echo json_encode([
            'success'         => true, 
            'action'          => 'update', 
            'schedule_id'     => $schedule_id,
            'exam_date'       => $exam_date,
            'scheduled_time'  => $scheduled_time,
            'department_code' => $department_code,
            'patient_id'      => $patient_id,
            'patient_name'    => $patient_name,
            'patient_kana'    => $patient_kana,
            'birthday'        => $birthday,
            'doctor_name'     => $doctor_name,
            'exam_type'       => $exam_type,
            'arrival_time'    => $arrival_time,
            'exam_location'   => $exam_location,
            'remarks'         => $remarks,
            'room_number'     => $room_number,
            'bowel_prep'      => $bowel_prep,
            'is_admission'    => $has_admission_param
        ]);
        exit;
    }

    // ============================================================
    // 【分岐3】予約削除処理 (delete)
    // ============================================================
    if ($action_type === 'delete') {
        writeLog('SCHEDULE', "--- 分岐: 削除 (delete) 開始 --- schedule_id = {$schedule_id}");

        if ($schedule_id <= 0) {
            writeLog('SCHEDULE', "エラー: schedule_id が不正です");
            echo json_encode(['success' => false, 'message' => '予約IDが不正です']);
            exit;
        }

        // ★ 削除前レコード取得（監査ログ用）
        $old_row = getScheduleRecordById($pdo, (int)$schedule_id);
        $old_alerts_map = getMedicalAlertsMap($pdo, [(int)$schedule_id]);
        if (isset($old_alerts_map[(int)$schedule_id])) {
            $old_row['medical_alerts'] = $old_alerts_map[(int)$schedule_id];
        }

        // 削除理由（cancel_reason）の判定
        $cancel_reason = isset($_POST['cancel_reason']) ? trim((string)$_POST['cancel_reason']) : '';
        $sch_created_at = $old_row['created_at'] ?? null;
        $is_within_10min = false;

        if (!empty($sch_created_at)) {
            $created_ts = strtotime($sch_created_at);
            $diff_sec = time() - $created_ts;
            if ($diff_sec >= 0 && $diff_sec <= 600) {
                $is_within_10min = true;
            }
        }

        if (empty($cancel_reason)) {
            $cancel_reason = $is_within_10min ? 'auto_mistake' : 'other';
        }

        $reason_labels = [
            'patient_cancel' => '患者都合キャンセル',
            'reschedule'     => '日程変更・別日振替',
            'mistake'        => '入力誤り・重複',
            'auto_mistake'   => '誤登録・即時取消(10分以内)',
            'other'          => 'その他取消'
        ];
        $cancel_reason_label = $reason_labels[$cancel_reason] ?? '予約削除';

        $sql = "UPDATE t_endoscopy_schedule SET is_canceled = TRUE, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :schedule_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':schedule_id' => $schedule_id]);

        writeLog('SCHEDULE', "削除成功: schedule_id = {$schedule_id}, 理由: {$cancel_reason_label} ({$cancel_reason})");

        // ★ 監査ログ記録
        $new_row = getScheduleRecordById($pdo, (int)$schedule_id);
        if ($new_row) {
            $new_row['cancel_reason'] = $cancel_reason;
            $new_row['cancel_reason_label'] = $cancel_reason_label;
        }

        $audit_log_id = recordScheduleAuditLog($pdo, 'delete', (int)$schedule_id, $old_row, $new_row, [
            'operator_name' => $_POST['operator_name'] ?? null,
            'diff_summary'  => "予約削除 ({$cancel_reason_label})",
            'description'   => "{$old_row['patient_name']} 様の予約を削除 ({$cancel_reason_label})"
        ]);
        writeLog('SCHEDULE', "監査ログ記録完了: log_id = {$audit_log_id}");

        echo json_encode([
            'success'             => true, 
            'action'              => 'delete', 
            'schedule_id'         => $schedule_id, 
            'cancel_reason'       => $cancel_reason,
            'cancel_reason_label' => $cancel_reason_label,
            'log_id'              => $audit_log_id
        ]);
        exit;
    }

    // ============================================================
    // 【分岐4】予約復活処理 (restore)
    // ============================================================
    if ($action_type === 'restore') {
        writeLog('SCHEDULE', "--- 分岐: 予約復活 (restore) --- schedule_id = {$schedule_id}");

        if ($schedule_id <= 0) {
            writeLog('SCHEDULE', "エラー: schedule_id が不正です");
            echo json_encode(['success' => false, 'message' => '予約IDが不正です']);
            exit;
        }

        // ★ 復活前レコード取得（監査ログ用）
        $old_row = getScheduleRecordById($pdo, (int)$schedule_id);

        $sql = "UPDATE t_endoscopy_schedule SET is_canceled = FALSE, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :schedule_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':schedule_id' => $schedule_id]);

        writeLog('SCHEDULE', "予約復活成功: schedule_id = {$schedule_id}");

        // ★ 監査ログ記録
        $new_row = getScheduleRecordById($pdo, (int)$schedule_id);
        $audit_log_id = recordScheduleAuditLog($pdo, 'restore', (int)$schedule_id, $old_row, $new_row, [
            'operator_name' => $_POST['operator_name'] ?? null
        ]);
        writeLog('SCHEDULE', "監査ログ記録完了: log_id = {$audit_log_id}");

        echo json_encode(['success' => true, 'action' => 'restore', 'schedule_id' => $schedule_id, 'log_id' => $audit_log_id]);
        exit;
    }

    // ============================================================
    // 【分岐5】ドラッグ＆ドロップ移動 (move)
    // ============================================================
    if ($action_type === 'move') {
        writeLog('SCHEDULE', "--- 分岐: D&D移動 (move) --- schedule_id = {$schedule_id}, date = {$exam_date}, time = {$scheduled_time}");

        $errors = [];
        if ($schedule_id <= 0) $errors[] = '予約ID';
        if (empty($exam_date)) $errors[] = '検査日';
        if (empty($scheduled_time)) $errors[] = '時間枠';
        
        if (!empty($errors)) {
            $msg = '必須項目が入力されていません: ' . implode('、', $errors);
            writeLog('SCHEDULE', "バリデーションエラー: " . $msg);
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        // ★ 移動前レコード取得（監査ログ用）
        $old_row = getScheduleRecordById($pdo, (int)$schedule_id);

        $dept_chk = $pdo->prepare("SELECT department_code FROM t_endoscopy_schedule WHERE schedule_id = :id");
        $dept_chk->execute([':id' => $schedule_id]);
        $d_row = $dept_chk->fetch();
        $target_dept = $d_row ? $d_row['department_code'] : 'PROCT';

        $waku_no = getWakuDisplayOrderFromM2($pdo, $target_dept, $scheduled_time);

        $sql = "UPDATE t_endoscopy_schedule SET exam_date = :exam_date, scheduled_time = :scheduled_time, waku_no = :waku_no, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :schedule_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':exam_date' => $exam_date, ':scheduled_time' => $scheduled_time, ':waku_no' => $waku_no, ':schedule_id' => $schedule_id]);

        writeLog('SCHEDULE', "D&D移動成功: schedule_id = {$schedule_id}");

        // ★ 監査ログ記録
        $new_row = getScheduleRecordById($pdo, (int)$schedule_id);
        $audit_log_id = recordScheduleAuditLog($pdo, 'move', (int)$schedule_id, $old_row, $new_row, [
            'operator_name' => $_POST['operator_name'] ?? null
        ]);
        writeLog('SCHEDULE', "監査ログ記録完了: log_id = {$audit_log_id}");

        echo json_encode(['success' => true, 'action' => 'move', 'schedule_id' => $schedule_id, 'log_id' => $audit_log_id]);
        exit;
    }

    // ============================================================
    // 【分岐6】誤操作ワンクリック復元 (undo)
    // ============================================================
    if ($action_type === 'undo') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        $operator = $_POST['operator_name'] ?? null;
        writeLog('SCHEDULE', "--- 分岐: ワンクリック復元 (undo) --- log_id = {$log_id}");
        $undo_res = undoScheduleAuditLog($pdo, $log_id, $operator);
        echo json_encode($undo_res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    writeLog('SCHEDULE', "警告: 不明な action_type が指定されました -> '{$action_type}'");
    echo json_encode(['success' => false, 'message' => "不明なアクション指定です: " . $action_type]);
    exit;

} catch (PDOException $e) {
    writeLog('SCHEDULE', "PDO例外発生: " . $e->getMessage() . " [Code: " . $e->getCode() . "]");
    echo json_encode(['success' => false, 'message' => "クエリ実行エラー: " . $e->getMessage()]);
    exit;
}