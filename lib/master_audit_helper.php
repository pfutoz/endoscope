<?php
/**
 * 生成日時: 2026-09-29 14:31:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\master_audit_helper.php
 * ファイル名: master_audit_helper.php
 * 
 * マスタ変更監査ログ（Audit Log）＆ ワンクリック復元（Undo）ライブラリ
 * 修正履歴:
 *   - 2026-09-29: 新規作成。汎用マスタ（m_input_master）および検査枠マスタ（m2_doctor, m2_waku, m2_doctor_slot_pattern, m2_doctor_attendance）の変更差分記録、ワンクリックUndo（復元）、履歴一括削除に対応。
 */

/**
 * クライアント端末IPを取得
 */
function getMasterAuditClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if ($ip === '::1') {
        $ip = '127.0.0.1 (ローカル)';
    }
    return $ip;
}

/**
 * マスタ変更ログを記録
 */
function recordMasterAuditLog(
    PDO $pdo,
    string $master_type,       // 'input_master', 'doctor', 'waku', 'pattern', 'attendance'
    string $action_type,       // 'insert', 'update', 'delete', 'restore'
    string $category_or_table, // カテゴリ名 または テーブル名
    ?string $target_id,        // IDなど
    ?string $target_name,      // 画面表示用名称（アイテム名、医師名、日付等）
    ?array $old_data,          // 変更前データ
    ?array $new_data,          // 変更後データ
    string $diff_summary       // 差分サマリ（人間が読みやすい文字列）
): int {
    $stmt = $pdo->prepare("
        INSERT INTO t_master_audit_log (
            master_type, action_type, category_or_table, target_id, target_name,
            old_data, new_data, diff_summary, client_ip, is_undone, created_at
        ) VALUES (
            :m_type, :a_type, :cat_tbl, :t_id, :t_name,
            :old_d, :new_d, :diff, :ip, FALSE, CURRENT_TIMESTAMP
        )
        RETURNING log_id
    ");

    $stmt->execute([
        ':m_type'   => $master_type,
        ':a_type'   => $action_type,
        ':cat_tbl'  => $category_or_table,
        ':t_id'     => $target_id,
        ':t_name'   => $target_name,
        ':old_d'    => $old_data !== null ? json_encode($old_data, JSON_UNESCAPED_UNICODE) : null,
        ':new_d'    => $new_data !== null ? json_encode($new_data, JSON_UNESCAPED_UNICODE) : null,
        ':diff'     => $diff_summary,
        ':ip'       => getMasterAuditClientIp()
    ]);

    return (int)$stmt->fetchColumn();
}

/**
 * マスタ変更ログ一覧を取得（直近N件）
 */
function getMasterAuditLogs(PDO $pdo, ?string $master_group = null, int $limit = 20): array {
    $sql = "SELECT * FROM t_master_audit_log WHERE 1=1";
    $params = [];

    if ($master_group === 'input_master') {
        $sql .= " AND master_type = 'input_master'";
    } elseif ($master_group === 'slot_master') {
        $sql .= " AND master_type IN ('doctor', 'waku', 'pattern', 'attendance')";
    } elseif ($master_group !== null && $master_group !== '') {
        $sql .= " AND master_type = :m_type";
        $params[':m_type'] = $master_group;
    }

    $sql .= " ORDER BY log_id DESC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['old_data'] = !empty($r['old_data']) ? json_decode($r['old_data'], true) : null;
        $r['new_data'] = !empty($r['new_data']) ? json_decode($r['new_data'], true) : null;
    }

    return $rows;
}

/**
 * マスタ変更ログを指定グループで一括削除（クリア）
 */
function clearMasterAuditLogs(PDO $pdo, ?string $master_group = null): int {
    if ($master_group === 'input_master') {
        $stmt = $pdo->prepare("DELETE FROM t_master_audit_log WHERE master_type = 'input_master'");
        $stmt->execute();
        return $stmt->rowCount();
    } elseif ($master_group === 'slot_master') {
        $stmt = $pdo->prepare("DELETE FROM t_master_audit_log WHERE master_type IN ('doctor', 'waku', 'pattern', 'attendance')");
        $stmt->execute();
        return $stmt->rowCount();
    } else {
        $stmt = $pdo->prepare("DELETE FROM t_master_audit_log");
        $stmt->execute();
        return $stmt->rowCount();
    }
}

/**
 * マスタ変更ログのワンクリック復元（Undo）
 */
function undoMasterAuditLog(PDO $pdo, int $log_id): string {
    $stmt = $pdo->prepare("SELECT * FROM t_master_audit_log WHERE log_id = :id FOR UPDATE");
    $stmt->execute([':id' => $log_id]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        throw new Exception("指定された履歴レコード (ID: {$log_id}) が見つかりません。");
    }

    if (!empty($log['is_undone'])) {
        throw new Exception("この操作は既に復元（取り消し）済みです。");
    }

    $master_type = $log['master_type'];
    $action_type = $log['action_type'];
    $old_data    = !empty($log['old_data']) ? json_decode($log['old_data'], true) : null;
    $new_data    = !empty($log['new_data']) ? json_decode($log['new_data'], true) : null;
    $restore_msg = "";

    // ============================================================
    // 1. 汎用マスタ (input_master) の復元
    // ============================================================
    if ($master_type === 'input_master') {
        if ($action_type === 'insert') {
            // 新規追加を取り消す ➔ 追加されたレコードを削除
            $m_id = (int)($log['target_id'] ?? 0);
            if ($m_id > 0) {
                $del = $pdo->prepare("DELETE FROM m_input_master WHERE master_id = :id");
                $del->execute([':id' => $m_id]);
            }
            $restore_msg = "追加を取り消しました（アイテムID: {$m_id} を削除）";

        } elseif ($action_type === 'delete') {
            // 削除を取り消す ➔ 削除されたレコードを再挿入
            if ($old_data && isset($old_data['master_id'], $old_data['category'], $old_data['item_value'])) {
                // ID重複チェック
                $chk = $pdo->prepare("SELECT COUNT(*) FROM m_input_master WHERE master_id = :id");
                $chk->execute([':id' => $old_data['master_id']]);
                if ($chk->fetchColumn() > 0) {
                    // IDが既に使われている場合は新規採番
                    $ins = $pdo->prepare("
                        INSERT INTO m_input_master (category, item_value, display_order)
                        VALUES (:cat, :val, :disp)
                    ");
                    $ins->execute([
                        ':cat'  => $old_data['category'],
                        ':val'  => $old_data['item_value'],
                        ':disp' => (int)($old_data['display_order'] ?? 0)
                    ]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO m_input_master (master_id, category, item_value, display_order)
                        VALUES (:id, :cat, :val, :disp)
                    ");
                    $ins->execute([
                        ':id'   => $old_data['master_id'],
                        ':cat'  => $old_data['category'],
                        ':val'  => $old_data['item_value'],
                        ':disp' => (int)($old_data['display_order'] ?? 0)
                    ]);
                }
                $restore_msg = "削除を取り消しました（アイテム「{$old_data['item_value']}」を復元）";
            }

        } elseif ($action_type === 'update') {
            // 一括更新を取り消す ➔ old_data の値に戻す
            if (is_array($old_data)) {
                $upd = $pdo->prepare("
                    UPDATE m_input_master 
                    SET item_value = :val, display_order = :disp
                    WHERE master_id = :id
                ");
                foreach ($old_data as $row) {
                    if (isset($row['master_id'])) {
                        $upd->execute([
                            ':val'  => $row['item_value'],
                            ':disp' => (int)($row['display_order'] ?? 0),
                            ':id'   => $row['master_id']
                        ]);
                    }
                }
                $restore_msg = "一括更新を取り消しました（計 " . count($old_data) . " 件を変更前の状態に復元）";
            }
        }

    // ============================================================
    // 2. 医師マスタ (doctor) の復元
    // ============================================================
    } elseif ($master_type === 'doctor') {
        if ($action_type === 'insert') {
            $doc_id = (int)$log['target_id'];
            $del = $pdo->prepare("DELETE FROM m2_doctor WHERE id = :id");
            $del->execute([':id' => $doc_id]);
            $restore_msg = "医師追加を取り消しました（{$log['target_name']} を削除）";

        } elseif ($action_type === 'delete') {
            if ($old_data && isset($old_data['doctor_name'], $old_data['department_code'])) {
                $ins = $pdo->prepare("
                    INSERT INTO m2_doctor (doctor_name, department_code, display_order, is_active, created_at, updated_at)
                    VALUES (:name, :dept, :disp, :act, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $ins->execute([
                    ':name' => $old_data['doctor_name'],
                    ':dept' => $old_data['department_code'],
                    ':disp' => (int)($old_data['display_order'] ?? 0),
                    ':act'  => !empty($old_data['is_active']) ? 'TRUE' : 'FALSE'
                ]);
                $restore_msg = "医師削除を取り消しました（{$old_data['doctor_name']} を復元）";
            }

        } elseif ($action_type === 'update') {
            if (is_array($old_data)) {
                $upd = $pdo->prepare("
                    UPDATE m2_doctor 
                    SET department_code = :dept, display_order = :disp, is_active = :act, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                foreach ($old_data as $row) {
                    $upd->execute([
                        ':dept' => $row['department_code'],
                        ':disp' => (int)($row['display_order'] ?? 0),
                        ':act'  => !empty($row['is_active']) ? 'TRUE' : 'FALSE',
                        ':id'   => (int)$row['id']
                    ]);
                }
                $restore_msg = "医師設定更新を取り消しました（変更前の状態に復元）";
            }
        }

    // ============================================================
    // 3. 時間枠マスタ (waku) の復元
    // ============================================================
    } elseif ($master_type === 'waku') {
        if ($action_type === 'insert') {
            $w_id = (int)$log['target_id'];
            $del = $pdo->prepare("DELETE FROM m2_waku WHERE id = :id");
            $del->execute([':id' => $w_id]);
            $restore_msg = "時間枠追加を取り消しました（枠ID: {$w_id} を削除）";

        } elseif ($action_type === 'delete') {
            if ($old_data && isset($old_data['waku_name'])) {
                $ins = $pdo->prepare("
                    INSERT INTO m2_waku (department_code, group_code, waku_code, waku_name, display_order, is_active, created_at, updated_at)
                    VALUES (:dept, :grp, :code, :name, :disp, :act, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $ins->execute([
                    ':dept' => $old_data['department_code'] ?? 'GASTRO',
                    ':grp'  => $old_data['group_code'] ?? 'OTHER',
                    ':code' => $old_data['waku_code'] ?? '',
                    ':name' => $old_data['waku_name'],
                    ':disp' => (int)($old_data['display_order'] ?? 0),
                    ':act'  => !empty($old_data['is_active']) ? 'TRUE' : 'FALSE'
                ]);
                $restore_msg = "時間枠削除を取り消しました（「{$old_data['waku_name']}」を復元）";
            }

        } elseif ($action_type === 'update') {
            if (is_array($old_data)) {
                $upd = $pdo->prepare("
                    UPDATE m2_waku 
                    SET group_code = :grp, waku_name = :name, display_order = :disp, is_active = :act, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                foreach ($old_data as $row) {
                    $upd->execute([
                        ':grp'  => $row['group_code'],
                        ':name' => $row['waku_name'],
                        ':disp' => (int)($row['display_order'] ?? 0),
                        ':act'  => !empty($row['is_active']) ? 'TRUE' : 'FALSE',
                        ':id'   => (int)$row['id']
                    ]);
                }
                $restore_msg = "時間枠更新を取り消しました（変更前の状態に復元）";
            }
        }

    // ============================================================
    // 4. 枠数パターン (pattern) の復元
    // ============================================================
    } elseif ($master_type === 'pattern') {
        if ($action_type === 'insert') {
            if ($new_data && isset($new_data['doctor_name'], $new_data['pattern_name'], $new_data['department_code'], $new_data['day_of_week'])) {
                $del = $pdo->prepare("
                    DELETE FROM m2_doctor_slot_pattern
                    WHERE doctor_name = :doc AND department_code = :dept AND pattern_name = :pat AND day_of_week = :dow
                ");
                $del->execute([
                    ':doc'  => $new_data['doctor_name'],
                    ':dept' => $new_data['department_code'],
                    ':pat'  => $new_data['pattern_name'],
                    ':dow'  => (int)$new_data['day_of_week']
                ]);
                $restore_msg = "パターン追加を取り消しました（{$new_data['pattern_name']} を削除）";
            }

        } elseif ($action_type === 'delete' || $action_type === 'update') {
            // updateまたはdeleteの場合、old_data のレコード群を再投入
            if (is_array($old_data)) {
                $stmt_del = $pdo->prepare("
                    DELETE FROM m2_doctor_slot_pattern
                    WHERE doctor_name = :doc AND department_code = :dept AND pattern_name = :pat AND day_of_week = :dow
                ");
                $stmt_ins = $pdo->prepare("
                    INSERT INTO m2_doctor_slot_pattern (
                        doctor_name, department_code, pattern_name, day_of_week, group_code, max_slots, gif_slots, echo_slots, tcf_slots, is_active, created_at, updated_at
                    ) VALUES (
                        :doc, :dept, :pat, :dow, :grp, :max, :gif, :echo, :tcf, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");

                // まず該当パターン・曜日を削除
                foreach ($old_data as $row) {
                    $stmt_del->execute([
                        ':doc'  => $row['doctor_name'],
                        ':dept' => $row['department_code'],
                        ':pat'  => $row['pattern_name'],
                        ':dow'  => (int)$row['day_of_week']
                    ]);
                }

                // 復元
                foreach ($old_data as $row) {
                    $stmt_ins->execute([
                        ':doc'  => $row['doctor_name'],
                        ':dept' => $row['department_code'],
                        ':pat'  => $row['pattern_name'],
                        ':dow'  => (int)$row['day_of_week'],
                        ':grp'  => $row['group_code'],
                        ':max'  => (int)$row['max_slots'],
                        ':gif'  => (int)($row['gif_slots'] ?? $row['max_slots']),
                        ':echo' => (int)($row['echo_slots'] ?? 0),
                        ':tcf'  => (int)($row['tcf_slots'] ?? 0),
                    ]);
                }
                $restore_msg = "枠数パターンの変更を取り消し、変更前の状態に復元しました。";
            }
        }

    // ============================================================
    // 5. 出勤例外 (attendance) の復元
    // ============================================================
    } elseif ($master_type === 'attendance') {
        if ($action_type === 'insert') {
            $att_id = (int)$log['target_id'];
            $del = $pdo->prepare("DELETE FROM m2_doctor_attendance WHERE id = :id");
            $del->execute([':id' => $att_id]);
            $restore_msg = "出勤例外追加を取り消しました（{$log['target_name']} を削除）";

        } elseif ($action_type === 'delete') {
            if ($old_data && isset($old_data['doctor_name'], $old_data['target_date'])) {
                $ins = $pdo->prepare("
                    INSERT INTO m2_doctor_attendance (
                        doctor_name, department_code, target_date, pattern_name, reason,
                        gif_slots, echo_slots, tcf_slots, created_at, updated_at
                    ) VALUES (
                        :doc, :dept, :date, :pattern, :reason,
                        :gif, :echo, :tcf, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $ins->execute([
                    ':doc'     => $old_data['doctor_name'],
                    ':dept'    => $old_data['department_code'] ?? 'GASTRO',
                    ':date'    => $old_data['target_date'],
                    ':pattern' => $old_data['pattern_name'] ?? '',
                    ':reason'  => $old_data['reason'] ?? '',
                    ':gif'     => (int)($old_data['gif_slots'] ?? 0),
                    ':echo'    => (int)($old_data['echo_slots'] ?? 0),
                    ':tcf'     => (int)($old_data['tcf_slots'] ?? 0)
                ]);
                $restore_msg = "出勤例外削除を取り消しました（{$old_data['target_date']} {$old_data['doctor_name']} を復元）";
            }

        } elseif ($action_type === 'update') {
            if (is_array($old_data)) {
                $upd = $pdo->prepare("
                    UPDATE m2_doctor_attendance
                    SET pattern_name = :pattern, reason = :reason, gif_slots = :gif, echo_slots = :echo, tcf_slots = :tcf, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                foreach ($old_data as $row) {
                    $upd->execute([
                        ':pattern' => $row['pattern_name'] ?? '',
                        ':reason'  => $row['reason'] ?? '',
                        ':gif'     => (int)($row['gif_slots'] ?? 0),
                        ':echo'    => (int)($row['echo_slots'] ?? 0),
                        ':tcf'     => (int)($row['tcf_slots'] ?? 0),
                        ':id'      => (int)$row['id']
                    ]);
                }
                $restore_msg = "出勤例外の更新を取り消し、変更前の状態に復元しました。";
            }
        }
    }

    if (empty($restore_msg)) {
        $restore_msg = "操作（ID: {$log_id}）の取り消し・復元を完了しました。";
    }

    // 元ログを復元済みにマーク
    $stmt_undone = $pdo->prepare("
        UPDATE t_master_audit_log 
        SET is_undone = TRUE, undone_at = CURRENT_TIMESTAMP
        WHERE log_id = :id
    ");
    $stmt_undone->execute([':id' => $log_id]);

    // 復元操作自体を新たな監査ログとして記録
    recordMasterAuditLog(
        $pdo,
        $master_type,
        'restore',
        $log['category_or_table'],
        (string)$log_id,
        $log['target_name'],
        $new_data,
        $old_data,
        "【復元実行】履歴ID #{$log_id} の操作を取り消し復元: {$restore_msg}"
    );

    return $restore_msg;
}
