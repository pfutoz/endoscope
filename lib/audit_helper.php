<?php
/**
 * lib/audit_helper.php
 * 予約操作監査ログ（Audit Log）＆ ワンクリック復元（Undo）ライブラリ
 * 
 * 機能:
 *   - recordScheduleAuditLog(): 予約の新規・更新・削除・移動・復元等のトランザクションを自動記録
 *   - undoScheduleAuditLog(): 指定した操作ログを1クリックで直前の状態へ完全復元
 *   - getRecentAuditLogs(): 直近の操作履歴リストを取得（画面表示用）
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/medical_alert_helper.php';


/**
 * クライアント端末情報を取得
 */
function getAuditClientInfo(): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '不明';
    if ($ip === '::1') {
        $ip = '127.0.0.1 (ローカル)';
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 簡易端末名判定
    $device = 'PC/端末';
    if (stripos($ua, 'iPad') !== false) {
        $device = 'iPad';
    } elseif (stripos($ua, 'iPhone') !== false) {
        $device = 'iPhone';
    } elseif (stripos($ua, 'Android') !== false) {
        $device = 'Android';
    } elseif (stripos($ua, 'Windows') !== false) {
        $device = 'Windows';
    } elseif (stripos($ua, 'Macintosh') !== false) {
        $device = 'Mac';
    }

    $operator = $_REQUEST['operator_name'] ?? $_SESSION['user_name'] ?? $_SESSION['operator'] ?? '';
    if (empty($operator)) {
        $operator = $ip;
    }

    return [
        'ip'        => $ip,
        'ua'        => $ua,
        'device'    => $device,
        'operator'  => $operator,
    ];
}

/**
 * 差分サマリと操作説明を自動生成
 */
function generateAuditSummary(string $action_type, ?array $old_row, ?array $new_row, ?string $custom_desc = null): array {
    if (!empty($custom_desc)) {
        return ['description' => $custom_desc, 'diff' => $custom_desc];
    }

    $patient_name = $new_row['patient_name'] ?? $old_row['patient_name'] ?? '患者未指定';
    $patient_id   = $new_row['patient_id'] ?? $old_row['patient_id'] ?? '';
    $p_display    = $patient_id !== '' ? "【{$patient_id}】{$patient_name}" : $patient_name;

    switch ($action_type) {
        case 'move':
            $old_d = $old_row['exam_date'] ?? '';
            $old_t = $old_row['scheduled_time'] ?? '';
            $new_d = $new_row['exam_date'] ?? '';
            $new_t = $new_row['scheduled_time'] ?? '';
            $desc = "{$p_display} 様の予約を移動: {$old_d} {$old_t} ➔ {$new_d} {$new_t}";
            $diff = "日時変更: {$old_d} {$old_t} ➔ {$new_d} {$new_t}";
            return ['description' => $desc, 'diff' => $diff];

        case 'delete':
            $d = $old_row['exam_date'] ?? '';
            $t = $old_row['scheduled_time'] ?? '';
            $desc = "{$p_display} 様の予約を削除 ({$d} {$t})";
            $diff = "予約削除 (論理削除)";
            return ['description' => $desc, 'diff' => $diff];

        case 'restore':
            $d = $new_row['exam_date'] ?? $old_row['exam_date'] ?? '';
            $t = $new_row['scheduled_time'] ?? $old_row['scheduled_time'] ?? '';
            $desc = "{$p_display} 様の削除済み予約を復活 ({$d} {$t})";
            $diff = "予約復活 (削除取り消し)";
            return ['description' => $desc, 'diff' => $diff];

        case 'insert':
            $d = $new_row['exam_date'] ?? '';
            $t = $new_row['scheduled_time'] ?? '';
            $doc = $new_row['doctor_name'] ?? '';
            $exam = $new_row['exam_type'] ?? '';
            $desc = "{$p_display} 様の新規予約登録: {$d} {$t} (担当: {$doc}, {$exam})";
            $diff = "新規予約作成: {$d} {$t}";
            return ['description' => $desc, 'diff' => $diff];

        case 'update':
            $changes = [];
            if ($old_row && $new_row) {
                if (($old_row['exam_date'] ?? '') !== ($new_row['exam_date'] ?? '')) {
                    $changes[] = "検査日: {$old_row['exam_date']} ➔ {$new_row['exam_date']}";
                }
                if (($old_row['scheduled_time'] ?? '') !== ($new_row['scheduled_time'] ?? '')) {
                    $changes[] = "時間枠: {$old_row['scheduled_time']} ➔ {$new_row['scheduled_time']}";
                }
                if (($old_row['doctor_name'] ?? '') !== ($new_row['doctor_name'] ?? '')) {
                    $changes[] = "医師: {$old_row['doctor_name']} ➔ {$new_row['doctor_name']}";
                }
                if (($old_row['exam_type'] ?? '') !== ($new_row['exam_type'] ?? '')) {
                    $changes[] = "検査種別: {$old_row['exam_type']} ➔ {$new_row['exam_type']}";
                }
                if (($old_row['patient_id'] ?? '') !== ($new_row['patient_id'] ?? '') || ($old_row['patient_name'] ?? '') !== ($new_row['patient_name'] ?? '')) {
                    $changes[] = "患者: " . ($old_row['patient_name'] ?? '') . " ➔ " . ($new_row['patient_name'] ?? '');
                }
                if (($old_row['room_number'] ?? '') !== ($new_row['room_number'] ?? '')) {
                    $changes[] = "部屋: " . ($old_row['room_number'] ?: '未指定') . " ➔ " . ($new_row['room_number'] ?: '未指定');
                }
                if (($old_row['bowel_prep'] ?? '') !== ($new_row['bowel_prep'] ?? '')) {
                    $changes[] = "下剤: " . ($old_row['bowel_prep'] ?: '未指定') . " ➔ " . ($new_row['bowel_prep'] ?: '未指定');
                }
                if (($old_row['arrival_time'] ?? '') !== ($new_row['arrival_time'] ?? '')) {
                    $changes[] = "来院: " . ($old_row['arrival_time'] ?: '-') . " ➔ " . ($new_row['arrival_time'] ?: '-');
                }
                if (($old_row['remarks'] ?? '') !== ($new_row['remarks'] ?? '')) {
                    $changes[] = "備考内容を変更";
                }

                // ★ 医療安全アラートフラグの差分検知
                $alert_labels = [
                    'has_antithrombotic' => '💊抗血栓薬',
                    'has_diabetes'       => '💉糖尿病薬',
                    'has_psychotropic'   => '🧠向精神薬',
                    'has_nsaids'         => '💊NSAIDs',
                    'has_allergy'        => '⚠️局麻注意',
                    'has_infection'      => '🔴感染症注意',
                    'has_cold_fever'     => '😷風邪発熱'
                ];
                $old_ma = $old_row['medical_alerts'] ?? null;
                $new_ma = $new_row['medical_alerts'] ?? null;
                if (is_array($old_ma) && is_array($new_ma)) {
                    foreach ($alert_labels as $key => $label) {
                        $ov = !empty($old_ma[$key]);
                        $nv = !empty($new_ma[$key]);
                        if ($ov !== $nv) {
                            $changes[] = "{$label}: " . ($nv ? 'ON' : 'OFF');
                        }
                    }
                }
            }

            if (empty($changes)) {
                $changes[] = "予約情報を更新";
            }
            $diff = implode('、', $changes);
            $d = $new_row['exam_date'] ?? $old_row['exam_date'] ?? '';
            $t = $new_row['scheduled_time'] ?? $old_row['scheduled_time'] ?? '';
            $desc = "{$p_display} 様の予約内容を変更 ({$d} {$t}): {$diff}";
            return ['description' => $desc, 'diff' => $diff];

        case 'undo':
            $desc = "{$p_display} 様の直前の操作を取り消し（元に戻す）";
            $diff = "ワンクリック復元 (Undo)";
            return ['description' => $desc, 'diff' => $diff];

        default:
            $desc = "{$p_display} 様の予約操作 ({$action_type})";
            $diff = $action_type;
            return ['description' => $desc, 'diff' => $diff];
    }
}

/**
 * 予約監査ログを記録
 */
function recordScheduleAuditLog(
    PDO $pdo,
    string $action_type,
    int $schedule_id,
    ?array $old_row = null,
    ?array $new_row = null,
    array $options = []
): ?int {
    try {
        $client = getAuditClientInfo();
        $operator_name = $options['operator_name'] ?? $client['operator'];
        $client_ip     = $options['client_ip'] ?? $client['ip'];
        $client_ua     = $client['ua'];

        $summary = generateAuditSummary($action_type, $old_row, $new_row, $options['description'] ?? null);
        $description  = $summary['description'];
        $diff_summary = $options['diff_summary'] ?? $summary['diff'];

        $dept = $new_row['department_code'] ?? $old_row['department_code'] ?? $options['department_code'] ?? null;
        $pid  = $new_row['patient_id'] ?? $old_row['patient_id'] ?? null;
        $pname = $new_row['patient_name'] ?? $old_row['patient_name'] ?? null;
        $pkana = $new_row['patient_kana'] ?? $old_row['patient_kana'] ?? null;

        $old_date = $old_row['exam_date'] ?? null;
        $old_time = $old_row['scheduled_time'] ?? null;
        $old_waku = isset($old_row['waku_no']) ? (int)$old_row['waku_no'] : null;

        $new_date = $new_row['exam_date'] ?? null;
        $new_time = $new_row['scheduled_time'] ?? null;
        $new_waku = isset($new_row['waku_no']) ? (int)$new_row['waku_no'] : null;

        $old_json = $old_row !== null ? json_encode($old_row, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new_row !== null ? json_encode($new_row, JSON_UNESCAPED_UNICODE) : null;

        $sql = "INSERT INTO t_schedule_audit_log (
                    schedule_id, action_type, department_code, patient_id, patient_name, patient_kana,
                    old_exam_date, old_scheduled_time, old_waku_no,
                    new_exam_date, new_scheduled_time, new_waku_no,
                    old_data, new_data, diff_summary, client_ip, client_ua, operator_name, description,
                    is_undone, created_at
                ) VALUES (
                    :schedule_id, :action_type, :department_code, :patient_id, :patient_name, :patient_kana,
                    :old_exam_date, :old_scheduled_time, :old_waku_no,
                    :new_exam_date, :new_scheduled_time, :new_waku_no,
                    :old_data, :new_data, :diff_summary, :client_ip, :client_ua, :operator_name, :description,
                    FALSE, CURRENT_TIMESTAMP
                ) RETURNING log_id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':schedule_id'       => $schedule_id,
            ':action_type'       => $action_type,
            ':department_code'   => $dept,
            ':patient_id'        => $pid,
            ':patient_name'      => $pname,
            ':patient_kana'      => $pkana,
            ':old_exam_date'     => $old_date,
            ':old_scheduled_time'=> $old_time,
            ':old_waku_no'       => $old_waku,
            ':new_exam_date'     => $new_date,
            ':new_scheduled_time'=> $new_time,
            ':new_waku_no'       => $new_waku,
            ':old_data'          => $old_json,
            ':new_data'          => $new_json,
            ':diff_summary'      => $diff_summary,
            ':client_ip'         => $client_ip,
            ':client_ua'         => $client_ua,
            ':operator_name'     => $operator_name,
            ':description'       => $description,
        ]);

        $log_id = (int)$stmt->fetchColumn();
        return $log_id;
    } catch (Exception $e) {
        // ログ記録の例外で本体の処理を殺さないよう、エラーロガーに出力してnullを返す
        if (function_exists('writeLog')) {
            writeLog('AUDIT', '監査ログ記録失敗: ' . $e->getMessage());
        } else {
            error_log('監査ログ記録失敗: ' . $e->getMessage());
        }
        return null;
    }
}

/**
 * 単一予約の現在レコードを取得
 */
function getScheduleRecordById(PDO $pdo, int $schedule_id): ?array {
    if ($schedule_id <= 0) return null;
    $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id");
    $stmt->execute([':id' => $schedule_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 誤操作ワンクリック復元（Undo）処理
 * 
 * @param PDO $pdo
 * @param int $log_id 復元対象の操作ログID
 * @param string|null $operator_name 実行者名
 * @return array ['success' => bool, 'message' => string, 'schedule_id' => int, ...]
 */
function undoScheduleAuditLog(PDO $pdo, int $log_id, ?string $operator_name = null): array {
    if ($log_id <= 0) {
        return ['success' => false, 'message' => '無効なログIDです'];
    }

    $in_transaction = $pdo->inTransaction();
    if (!$in_transaction) {
        $pdo->beginTransaction();
    }

    try {
        // ログを取得
        $stmt = $pdo->prepare("SELECT * FROM t_schedule_audit_log WHERE log_id = :id FOR UPDATE");
        $stmt->execute([':id' => $log_id]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            if (!$in_transaction) $pdo->rollBack();
            return ['success' => false, 'message' => '指定された操作履歴が見つかりません'];
        }

        if (!empty($log['is_undone']) && ($log['is_undone'] === true || $log['is_undone'] === 't' || $log['is_undone'] === 1)) {
            if (!$in_transaction) $pdo->rollBack();
            $undone_time = $log['undone_at'] ? date('m/d H:i', strtotime($log['undone_at'])) : '';
            return ['success' => false, 'message' => "この操作はすでに復元済みです ({$undone_time})"];
        }

        $action_type = $log['action_type'];
        $schedule_id = (int)$log['schedule_id'];
        $old_data    = !empty($log['old_data']) ? json_decode($log['old_data'], true) : null;
        $new_data    = !empty($log['new_data']) ? json_decode($log['new_data'], true) : null;

        // 現在の予約状態を取得
        $curr_stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id FOR UPDATE");
        $curr_stmt->execute([':id' => $schedule_id]);
        $current_row = $curr_stmt->fetch(PDO::FETCH_ASSOC);

        $patient_display = !empty($log['patient_name']) ? "【{$log['patient_id']}】{$log['patient_name']} 様" : "予約(ID:{$schedule_id})";

        // アクション種別に応じた復元処理
        switch ($action_type) {
            case 'move':
                // 移動前の日時・枠に戻す
                if (!$current_row) {
                    if (!$in_transaction) $pdo->rollBack();
                    return ['success' => false, 'message' => '復元対象の予約レコードが存在しません'];
                }
                $target_date = $log['old_exam_date'] ?? ($old_data['exam_date'] ?? null);
                $target_waku = $log['old_scheduled_time'] ?? ($old_data['scheduled_time'] ?? null);
                $target_waku_no = $log['old_waku_no'] ?? ($old_data['waku_no'] ?? 1);

                if (empty($target_date) || empty($target_waku)) {
                    if (!$in_transaction) $pdo->rollBack();
                    return ['success' => false, 'message' => '変更前の日時データが記録されていないため復元できません'];
                }

                $upd = $pdo->prepare("UPDATE t_endoscopy_schedule 
                                      SET exam_date = :exam_date, scheduled_time = :scheduled_time, waku_no = :waku_no, updated_at = CURRENT_TIMESTAMP 
                                      WHERE schedule_id = :id");
                $upd->execute([
                    ':exam_date'       => $target_date,
                    ':scheduled_time'  => $target_waku,
                    ':waku_no'         => $target_waku_no,
                    ':id'              => $schedule_id
                ]);

                $res_msg = "{$patient_display} の移動を取り消し、元の枠（{$target_date} {$target_waku}）へ復元しました";
                break;

            case 'delete':
                // 削除を取り消し、復活させる（論理削除解除）
                if (!$current_row) {
                    // もし物理削除されていた場合でも old_data があれば INSERT して復活
                    if ($old_data) {
                        $cols = ['exam_date', 'scheduled_time', 'waku_no', 'department_code', 'patient_id', 'patient_name', 'patient_kana', 'birthday', 'doctor_name', 'exam_type', 'arrival_time', 'exam_location', 'remarks', 'room_number', 'bowel_prep'];
                        $ins_cols = [];
                        $ins_vals = [];
                        $ins_params = [];
                        foreach ($cols as $col) {
                            if (array_key_exists($col, $old_data)) {
                                $ins_cols[] = $col;
                                $ins_vals[] = ":{$col}";
                                $ins_params[":{$col}"] = $old_data[$col];
                            }
                        }
                        $ins_cols[] = 'is_canceled';
                        $ins_vals[] = 'FALSE';
                        $ins_cols[] = 'schedule_id';
                        $ins_vals[] = ':sid';
                        $ins_params[':sid'] = $schedule_id;

                        $sql = "INSERT INTO t_endoscopy_schedule (" . implode(',', $ins_cols) . ", created_at, updated_at) VALUES (" . implode(',', $ins_vals) . ", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
                        $ins = $pdo->prepare($sql);
                        $ins->execute($ins_params);
                    } else {
                        if (!$in_transaction) $pdo->rollBack();
                        return ['success' => false, 'message' => '予約データが見つからず復元できません'];
                    }
                } else {
                    // 論理削除されていた場合は is_canceled = FALSE に戻す
                    // さらに old_data の情報があれば上書き復元して確実に戻す
                    $upd_sql = "UPDATE t_endoscopy_schedule SET is_canceled = FALSE, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :id";
                    $pdo->prepare($upd_sql)->execute([':id' => $schedule_id]);
                }

                // ★ 削除前の医療安全アラート状態を復元
                if (isset($old_data['medical_alerts']) && is_array($old_data['medical_alerts'])) {
                    restoreMedicalAlertFromAudit($pdo, $schedule_id, $old_data['medical_alerts'], $operator_name);
                }

                $d = $log['old_exam_date'] ?? ($old_data['exam_date'] ?? '');
                $t = $log['old_scheduled_time'] ?? ($old_data['scheduled_time'] ?? '');
                $res_msg = "誤って削除された {$patient_display} の予約（{$d} {$t}）を完全復元しました";
                break;

            case 'update':
                // 更新前の全カラムデータへ巻き戻す
                if (!$current_row || !$old_data) {
                    if (!$in_transaction) $pdo->rollBack();
                    return ['success' => false, 'message' => '更新前のバックアップデータが見つからないため復元できません'];
                }

                $fields = [
                    'exam_date', 'scheduled_time', 'waku_no', 'department_code', 'patient_id',
                    'patient_name', 'patient_kana', 'birthday', 'doctor_name', 'exam_type',
                    'arrival_time', 'exam_location', 'remarks', 'room_number', 'bowel_prep', 'is_canceled'
                ];
                $set_parts = [];
                $params = [':id' => $schedule_id];

                foreach ($fields as $f) {
                    if (array_key_exists($f, $old_data)) {
                        $set_parts[] = "{$f} = :{$f}";
                        $val = $old_data[$f];
                        // boolean 変換
                        if ($f === 'is_canceled') {
                            $val = ($val === true || $val === 't' || $val === 1 || $val === '1') ? 'TRUE' : 'FALSE';
                            $set_parts[count($set_parts) - 1] = "{$f} = {$val}";
                        } else {
                            $params[":{$f}"] = $val;
                        }
                    }
                }
                $set_parts[] = "updated_at = CURRENT_TIMESTAMP";

                $upd_sql = "UPDATE t_endoscopy_schedule SET " . implode(', ', $set_parts) . " WHERE schedule_id = :id";
                $upd = $pdo->prepare($upd_sql);
                $upd->execute($params);

                // ★ 更新前の医療安全アラート状態を復元
                if (isset($old_data['medical_alerts']) && is_array($old_data['medical_alerts'])) {
                    restoreMedicalAlertFromAudit($pdo, $schedule_id, $old_data['medical_alerts'], $operator_name);
                }

                $res_msg = "{$patient_display} の変更内容を取り消し、変更前の状態へ復元しました";
                break;

            case 'insert':
                // 新規登録の取り消し（論理削除）
                if ($current_row) {
                    $pdo->prepare("UPDATE t_endoscopy_schedule SET is_canceled = TRUE, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :id")
                        ->execute([':id' => $schedule_id]);
                }
                $res_msg = "新規登録された {$patient_display} の予約を取り消しました";
                break;

            case 'restore':
                // 復活の取り消し（再度論理削除）
                if ($current_row) {
                    $pdo->prepare("UPDATE t_endoscopy_schedule SET is_canceled = TRUE, updated_at = CURRENT_TIMESTAMP WHERE schedule_id = :id")
                        ->execute([':id' => $schedule_id]);
                }
                $res_msg = "復活操作を取り消しました";
                break;

            default:
                if (!$in_transaction) $pdo->rollBack();
                return ['success' => false, 'message' => "未対応のアクション種別です: {$action_type}"];
        }

        // 対象ログを復元済みにマーク
        $upd_log = $pdo->prepare("UPDATE t_schedule_audit_log 
                                  SET is_undone = TRUE, undone_at = CURRENT_TIMESTAMP 
                                  WHERE log_id = :id");
        $upd_log->execute([':id' => $log_id]);

        // 最新の予約状態を取得
        $restored_row = getScheduleRecordById($pdo, $schedule_id);

        // Undo操作自体も監査ログとして記録
        $client = getAuditClientInfo();
        $op = $operator_name ?: $client['operator'];
        recordScheduleAuditLog(
            $pdo,
            'undo',
            $schedule_id,
            $current_row,
            $restored_row,
            [
                'operator_name' => $op,
                'description'   => "【操作復元】ログ#{$log_id} ({$action_type}) を取り消し直前状態へ復元: {$res_msg}",
                'diff_summary'  => "ログ#{$log_id}の取り消し復元",
                'department_code' => $log['department_code'] ?? null
            ]
        );

        if (!$in_transaction) {
            $pdo->commit();
        }

        return [
            'success'       => true,
            'message'       => $res_msg,
            'schedule_id'   => $schedule_id,
            'action_type'   => $action_type,
            'log_id'        => $log_id,
            'restored_date' => $restored_row['exam_date'] ?? null,
            'restored_time' => $restored_row['scheduled_time'] ?? null,
            'department_code' => $restored_row['department_code'] ?? ($log['department_code'] ?? 'PROCT')
        ];

    } catch (Exception $e) {
        if (!$in_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'message' => '復元処理中にエラーが発生しました: ' . $e->getMessage()
        ];
    }
}

/**
 * 1年以上経過した古い監査ログをパージ（削除）
 * @param PDO $pdo
 * @param int $days 保持日数（デフォルト365日 = 1年）
 * @return int 削除されたレコード件数
 */
function purgeOldAuditLogs(PDO $pdo, int $days = 365): int {
    try {
        $stmt = $pdo->prepare("DELETE FROM t_schedule_audit_log WHERE created_at < CURRENT_TIMESTAMP - (:days || ' days')::INTERVAL");
        $stmt->execute([':days' => $days]);
        $count = $stmt->rowCount();

        // 関連する通知消込の orphan レコードも安全にクリーンアップ
        try {
            $pdo->exec("DELETE FROM t_doctor_notification_read WHERE log_id NOT IN (SELECT log_id FROM t_schedule_audit_log)");
        } catch (Exception $e) {
            // 無視
        }

        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 直近の操作履歴を取得（未復元・有効な操作のみを常に表示）
 */
function getRecentAuditLogs(PDO $pdo, int $limit = 40, ?string $dept = null, ?int $schedule_id = null): array {
    // 💡 バックグラウンド自動ローテーション（1/20の確率で1年以上前のログをパージ）
    if (mt_rand(1, 20) === 1) {
        purgeOldAuditLogs($pdo, 365);
    }

    // ★ 常に「まだUndoされていない（今Undoできる）操作」だけを対象とする
    $where = [
        "l.is_undone = FALSE",
        "l.action_type != 'undo'"
    ];
    $params = [':limit' => $limit];

    if (!empty($dept)) {
        $where[] = "l.department_code = :dept";
        $params[':dept'] = $dept;
    }
    if ($schedule_id !== null && $schedule_id > 0) {
        $where[] = "l.schedule_id = :sid";
        $params[':sid'] = $schedule_id;
    }

    $where_sql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

    $sql = "
        SELECT 
            l.log_id,
            l.schedule_id,
            l.action_type,
            l.department_code,
            l.patient_id,
            l.patient_name,
            l.patient_kana,
            l.old_exam_date,
            l.old_scheduled_time,
            l.new_exam_date,
            l.new_scheduled_time,
            l.diff_summary,
            l.client_ip,
            l.operator_name,
            l.description,
            l.is_undone,
            l.undone_at,
            l.created_at,
            s.is_canceled AS current_is_canceled,
            s.exam_date AS current_exam_date,
            s.scheduled_time AS current_scheduled_time
        FROM t_schedule_audit_log l
        LEFT JOIN t_endoscopy_schedule s ON l.schedule_id = s.schedule_id
        {$where_sql}
        ORDER BY l.log_id DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $now = new DateTime();
    $result = [];

    foreach ($rows as $r) {
        $created_dt = new DateTime($r['created_at']);
        $diff_sec = $now->getTimestamp() - $created_dt->getTimestamp();

        // 相対時間フォーマット
        if ($diff_sec < 60) {
            $time_relative = "たった今";
        } elseif ($diff_sec < 3600) {
            $time_relative = floor($diff_sec / 60) . "分前";
        } elseif ($diff_sec < 86400 && $created_dt->format('Y-m-d') === $now->format('Y-m-d')) {
            $time_relative = "今日 " . $created_dt->format('H:i');
        } elseif ($diff_sec < 172800 && $created_dt->format('Y-m-d') === (clone $now)->modify('-1 day')->format('Y-m-d')) {
            $time_relative = "昨日 " . $created_dt->format('H:i');
        } else {
            $time_relative = $created_dt->format('m/d H:i');
        }

        $action_label = '';
        $action_badge = '';
        $icon = '';

        switch ($r['action_type']) {
            case 'move':
                $action_label = '枠移動';
                $action_badge = 'badge-move';
                $icon = '🚚';
                break;
            case 'delete':
                $action_label = '予約削除';
                $action_badge = 'badge-delete';
                $icon = '🗑️';
                break;
            case 'update':
                $action_label = '内容変更';
                $action_badge = 'badge-update';
                $icon = '✏️';
                break;
            case 'insert':
                $action_label = '新規予約';
                $action_badge = 'badge-insert';
                $icon = '➕';
                break;
            case 'restore':
                $action_label = '予約復活';
                $action_badge = 'badge-restore';
                $icon = '⭕';
                break;
            case 'undo':
                $action_label = '復元(Undo)';
                $action_badge = 'badge-undo';
                $icon = '↩️';
                break;
            default:
                $action_label = $r['action_type'];
                $action_badge = 'badge-other';
                $icon = '📋';
                break;
        }

        $can_undo = false;
        $is_undone = ($r['is_undone'] === true || $r['is_undone'] === 't' || $r['is_undone'] === 1 || $r['is_undone'] === '1');
        
        // undo 自体でなく、かつまだ復元されていない場合は Undo 可能
        if (!$is_undone && $r['action_type'] !== 'undo') {
            $can_undo = true;
        }

        $dept_name = ($r['department_code'] === 'PROCT') ? '肛門科' : (($r['department_code'] === 'GASTRO') ? '胃腸科' : '-');

        $result[] = [
            'log_id'          => (int)$r['log_id'],
            'schedule_id'     => (int)$r['schedule_id'],
            'action_type'     => $r['action_type'],
            'action_label'    => $action_label,
            'action_badge'    => $action_badge,
            'icon'            => $icon,
            'department_code' => $r['department_code'],
            'department_name' => $dept_name,
            'patient_id'      => $r['patient_id'] ?? '',
            'patient_name'    => $r['patient_name'] ?? '患者名未指定',
            'patient_kana'    => $r['patient_kana'] ?? '',
            'old_exam_date'   => $r['old_exam_date'],
            'old_scheduled_time' => $r['old_scheduled_time'],
            'new_exam_date'   => $r['new_exam_date'],
            'new_scheduled_time' => $r['new_scheduled_time'],
            'diff_summary'    => $r['diff_summary'],
            'description'     => $r['description'],
            'client_ip'       => $r['client_ip'],
            'operator_name'   => $r['operator_name'],
            'is_undone'       => $is_undone,
            'undone_at'       => $r['undone_at'],
            'can_undo'        => $can_undo,
            'created_at'      => $created_dt->format('Y-m-d H:i:s'),
            'time_relative'   => $time_relative,
            'current_is_canceled' => $r['current_is_canceled'] !== null ? (bool)$r['current_is_canceled'] : null,
            'current_exam_date'   => $r['current_exam_date'],
            'current_scheduled_time' => $r['current_scheduled_time']
        ];
    }

    return $result;
}
