<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\action_waku_override.php
 * ファイル名: action_waku_override.php
 * 概要: 時間枠日別・期間別オーバーライド（m2_waku_override）AJAX API コントローラ
 * 生成日時: 2026-10-22 13:55
 * 修正履歴:
 *   - 2026-10-22: 【Phase 3 時間枠簡単設定ポップアップ対応】
 *       1. get_day_slots: 特定日の有効時間枠・休止状態・オーバーライド詳細一覧取得
 *       2. set_slot_suspend: 特定枠の休止設定／解除（理由・ワンクリック対応）
 *       3. add_extra_slot: 臨時枠（base_waku_id = NULL）の追加
 *       4. save_period_override: 期間指定オーバーライドの登録
 *       5. delete_override: オーバーライドの削除（解除）
 *       6. list_overrides: 当月・期間内のオーバーライド一覧取得
 *       7. get_suspend_reasons / add_suspend_reason / delete_suspend_reason: 理由マスタ連携
 *       8. save_day_overrides_batch: 当日枠の下書き一括コミット（トランザクション対応）
 *       9. 監査ログ（recordScheduleAuditLog）との連携
 *   - 2026-10-22: 【枠名称の一時的修正（日別オーバーライド）対応】
 *       1. save_day_overrides_batch にて表示枠名称（display_waku_name）の一括変更・復帰に対応
 *       2. 既存臨時枠の名称・理由変更にも対応
 *       3. 監査ログに枠名変更の差分要約を詳細記録
 *   - 2026-10-22: 【予約あり枠の休止防止 ＆ 予約者情報連携対応】
 *       1. get_day_slots にて各枠の確定予約情報（患者名・カルテID・種別・医師名）を取得・付与
 *       2. set_slot_suspend および save_day_overrides_batch にて予約が存在する枠の休止を弾くバリデーションガードを実装
 *   - 2026-10-23: 【予約あり臨時枠の削除防止ガード】確定患者予約が入っている臨時枠・オーバーライド枠の削除（delete_override / save_day_overrides_batch）を拒絶し、予約の迷子化を根本防止
 *   - 2026-10-22: 【時間枠操作のUndo復元対応】
 *       1. 監査ログ記録時のメタデータ（department_code, target_date, display_waku_name 等）を拡充しワンクリック復元（Undo）と完全連携
 *   - 2026-11-06: 【時間枠世代管理対応】指定日付（:date BETWEEN valid_from AND valid_to）の世代の基本枠マスタを参照するよう改修。
 *   - 2026-11-07: getSlotReservations() の単一スロット照合で $allow_prefix=false を指定し誤一致を防止。get_day_slots のレスポンスに day_reservations を追加し modal_edit.php の時間枠リストボックスでの患者名表示および重複チェックと連携。
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/audit_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'データベース接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('getSlotReservations')) {
    /**
     * 対象の時間枠に確定予約（患者予約）が存在するか判定し、予約詳細リストを返す
     *
     * @param PDO $pdo
     * @param string $dept
     * @param string $date
     * @param string $waku_name
     * @param string|null $original_name
     * @param array|null $cached_reservations 事前取得済み予約配列
     * @return array 予約レコードの配列（空なら予約なし）
     */
    function getSlotReservations($pdo, $dept, $date, $waku_name, $original_name = null, $cached_reservations = null) {
        if ($cached_reservations === null) {
            $stmt = $pdo->prepare("
                SELECT schedule_id, scheduled_time, patient_id, patient_name, exam_type, doctor_name, exam_location, remarks
                FROM t_endoscopy_schedule
                WHERE department_code = :dept
                  AND exam_date = :date
                  AND is_canceled = FALSE
                ORDER BY schedule_id ASC
            ");
            $stmt->execute([':dept' => $dept, ':date' => $date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $cached_reservations;
        }

        $matched = [];
        $w1 = (string)$waku_name;
        $w2 = $original_name ? (string)$original_name : $w1;
        $dummy_slot = ['waku_name' => $w1, 'original_name' => $w2];

        foreach ($rows as $r) {
            $p_name = trim((string)($r['patient_name'] ?? ''));
            $rem = trim((string)($r['remarks'] ?? ''));
            if ($p_name === '※枠なし' || strpos($rem, '＊＊＊🚫枠なし') !== false) {
                continue;
            }

            $r_time = (string)($r['scheduled_time'] ?? '');
            $is_match = ($r_time === $w1 || $r_time === $w2);
            if (!$is_match && function_exists('findEffectiveWaku')) {
                $m = findEffectiveWaku([$dummy_slot], $r_time, false);
                if ($m !== null) {
                    $is_match = true;
                }
            }

            if ($is_match) {
                $matched[] = [
                    'schedule_id'   => (int)$r['schedule_id'],
                    'patient_id'    => (string)$r['patient_id'],
                    'patient_name'  => $p_name,
                    'exam_type'     => (string)($r['exam_type'] ?? ''),
                    'doctor_name'   => (string)($r['doctor_name'] ?? ''),
                    'exam_location' => (string)($r['exam_location'] ?? '')
                ];
            }
        }
        return $matched;
    }
}

$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

try {
    switch ($action) {
        // ----------------------------------------------------
        // 1. 指定日付の有効時間枠一覧およびオーバーライド情報取得
        // ----------------------------------------------------
        case 'get_day_slots':
            $dept = isset($_REQUEST['dept']) ? trim((string)$_REQUEST['dept']) : 'PROCT';
            $date = isset($_REQUEST['date']) ? trim((string)$_REQUEST['date']) : date('Y-m-d');

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('パラメータが不正です (dept, date)');
            }

            // 基本枠マスタ（対象日付の世代のみ抽出）
            $stmt_base = $pdo->prepare("
                SELECT id, department_code, group_code, waku_code, waku_name, display_order, valid_from, valid_to
                FROM m2_waku
                WHERE department_code = :dept
                  AND is_active = TRUE
                  AND :date BETWEEN valid_from AND valid_to
                ORDER BY display_order ASC, id ASC
            ");
            $stmt_base->execute([':dept' => $dept, ':date' => $date]);
            $base_slots = $stmt_base->fetchAll(PDO::FETCH_ASSOC);

            // 当該日に関係するオーバーライド一覧（特定日 + 期間）
            $stmt_ov = $pdo->prepare("
                SELECT 
                    o.override_id,
                    o.department_code,
                    o.base_waku_id,
                    o.override_type,
                    o.target_date::text AS target_date,
                    o.start_date::text AS start_date,
                    o.end_date::text AS end_date,
                    o.display_waku_name,
                    o.group_code,
                    o.time_start,
                    o.time_end,
                    o.display_order,
                    o.is_suspended,
                    o.reason,
                    o.created_at,
                    o.updated_at,
                    w.waku_name AS base_waku_name
                FROM m2_waku_override o
                LEFT JOIN m2_waku w ON o.base_waku_id = w.id
                WHERE o.department_code = :dept
                  AND (
                      (o.override_type = 'DATE' AND o.target_date = :date)
                      OR
                      (o.override_type = 'PERIOD' AND o.start_date <= :date AND o.end_date >= :date)
                  )
                ORDER BY o.override_type ASC, o.override_id ASC
            ");
            $stmt_ov->execute([':dept' => $dept, ':date' => $date]);
            $applied_overrides = $stmt_ov->fetchAll(PDO::FETCH_ASSOC);

            // 有効枠一覧（合成結果）
            $effective_slots = getEffectiveWakuList($pdo, $dept, $date);

            // 当該日の確定予約データを取得（患者予約のみ。※枠なし等のダミーは除外）
            $stmt_res = $pdo->prepare("
                SELECT schedule_id, scheduled_time, patient_id, patient_name, exam_type, doctor_name, exam_location, remarks
                FROM t_endoscopy_schedule
                WHERE department_code = :dept
                  AND exam_date = :date
                  AND is_canceled = FALSE
                ORDER BY schedule_id ASC
            ");
            $stmt_res->execute([':dept' => $dept, ':date' => $date]);
            $day_reservations = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

            // 各有効枠に予約情報をマッピング
            foreach ($effective_slots as &$slot) {
                $w_name = (string)$slot['waku_name'];
                $orig_name = (string)($slot['original_name'] ?? $w_name);

                $matched_patients = getSlotReservations($pdo, $dept, $date, $w_name, $orig_name, $day_reservations);

                $slot['has_reservation'] = !empty($matched_patients);
                $slot['reserved_count']  = count($matched_patients);
                $slot['reservations']    = $matched_patients;
            }
            unset($slot);

            // ダミー（枠なし）を除外した当日予約リスト（旧世代・未定義枠の患者名表示・重複チェック用）
            $valid_day_reservations = [];
            foreach ($day_reservations as $dr) {
                $pn = trim((string)($dr['patient_name'] ?? ''));
                $rm = trim((string)($dr['remarks'] ?? ''));
                if ($pn === '※枠なし' || strpos($rm, '＊＊＊🚫枠なし') !== false) {
                    continue;
                }
                $valid_day_reservations[] = [
                    'schedule_id'    => (int)$dr['schedule_id'],
                    'scheduled_time' => (string)($dr['scheduled_time'] ?? ''),
                    'patient_id'     => (string)($dr['patient_id'] ?? ''),
                    'patient_name'   => $pn,
                    'exam_type'      => (string)($dr['exam_type'] ?? ''),
                    'doctor_name'    => (string)($dr['doctor_name'] ?? ''),
                    'exam_location'  => (string)($dr['exam_location'] ?? '')
                ];
            }

            echo json_encode([
                'success'           => true,
                'dept'              => $dept,
                'date'              => $date,
                'base_slots'        => $base_slots,
                'effective_slots'   => $effective_slots,
                'applied_overrides' => $applied_overrides,
                'day_reservations'  => $valid_day_reservations
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 2. 特定枠の休止設定／解除（特定日 DATE オーバーライド）
        // ----------------------------------------------------
        case 'set_slot_suspend':
            $dept         = isset($_REQUEST['dept']) ? trim((string)$_REQUEST['dept']) : 'PROCT';
            $date         = isset($_REQUEST['date']) ? trim((string)$_REQUEST['date']) : '';
            $base_waku_id   = isset($_REQUEST['base_waku_id']) ? (int)$_REQUEST['base_waku_id'] : 0;
            $base_waku_name = isset($_REQUEST['base_waku_name']) ? trim((string)$_REQUEST['base_waku_name']) : '';
            $is_suspended   = isset($_REQUEST['is_suspended']) && ($_REQUEST['is_suspended'] === '1' || $_REQUEST['is_suspended'] === 'true' || $_REQUEST['is_suspended'] === true);
            $reason         = isset($_REQUEST['reason']) ? trim((string)$_REQUEST['reason']) : '';

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('パラメータが不正です (dept, date)');
            }

            // 基本枠情報を取得（IDまたは枠名から解決）
            $base_waku = null;
            if ($base_waku_id > 0) {
                $stmt_bw = $pdo->prepare("SELECT * FROM m2_waku WHERE id = :id AND department_code = :dept AND is_active = TRUE");
                $stmt_bw->execute([':id' => $base_waku_id, ':dept' => $dept]);
                $base_waku = $stmt_bw->fetch(PDO::FETCH_ASSOC);
            }
            if (!$base_waku && !empty($base_waku_name)) {
                $stmt_bw2 = $pdo->prepare("SELECT * FROM m2_waku WHERE waku_name = :name AND department_code = :dept AND is_active = TRUE AND :date BETWEEN valid_from AND valid_to LIMIT 1");
                $stmt_bw2->execute([':name' => $base_waku_name, ':dept' => $dept, ':date' => $date]);
                $base_waku = $stmt_bw2->fetch(PDO::FETCH_ASSOC);
                if ($base_waku) {
                    $base_waku_id = (int)$base_waku['id'];
                }
            }

            if (!$base_waku) {
                throw new Exception("対象の基本枠マスタが存在しません (ID: {$base_waku_id}, 枠名: {$base_waku_name})");
            }

            // 確定予約が入っている枠は休止不可
            if ($is_suspended) {
                $resList = getSlotReservations($pdo, $dept, $date, $base_waku['waku_name']);
                if (!empty($resList)) {
                    $pNames = array_map(function($p) { return $p['patient_name'] . ' 様'; }, array_slice($resList, 0, 2));
                    $pStr = implode('、', $pNames) . (count($resList) > 2 ? ' 他' : '');
                    throw new Exception("時間枠「{$base_waku['waku_name']}」には既に確定予約（{$pStr}）が入っているため、休止できません。");
                }
            }

            $pdo->beginTransaction();

            // 既存の当日特定日オーバーライドを確認
            $stmt_check = $pdo->prepare("
                SELECT * FROM m2_waku_override
                WHERE department_code = :dept
                  AND base_waku_id = :base_id
                  AND override_type = 'DATE'
                  AND target_date = :date
            ");
            $stmt_check->execute([':dept' => $dept, ':base_id' => $base_waku_id, ':date' => $date]);
            $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($is_suspended) {
                    // 休止更新
                    $stmt_upd = $pdo->prepare("
                        UPDATE m2_waku_override
                        SET is_suspended = TRUE,
                            reason = :reason,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE override_id = :id
                    ");
                    $stmt_upd->execute([':reason' => $reason, ':id' => $existing['override_id']]);
                    $override_id = (int)$existing['override_id'];
                } else {
                    // 休止解除：表示名変更など他のオーバーライド要素がなければ削除、あれば is_suspended=FALSE
                    if ($existing['display_waku_name'] === $base_waku['waku_name'] && $existing['display_order'] == $base_waku['display_order']) {
                        $stmt_del = $pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :id");
                        $stmt_del->execute([':id' => $existing['override_id']]);
                        $override_id = null;
                    } else {
                        $stmt_upd = $pdo->prepare("
                            UPDATE m2_waku_override
                            SET is_suspended = FALSE,
                                reason = NULL,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE override_id = :id
                        ");
                        $stmt_upd->execute([':id' => $existing['override_id']]);
                        $override_id = (int)$existing['override_id'];
                    }
                }
            } else {
                if ($is_suspended) {
                    // 新規休止オーバーライド登録
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO m2_waku_override (
                            department_code, base_waku_id, override_type, target_date,
                            display_waku_name, group_code, display_order, is_suspended, reason,
                            created_at, updated_at
                        ) VALUES (
                            :dept, :base_id, 'DATE', :date,
                            :name, :group, :order, TRUE, :reason,
                            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                        ) RETURNING override_id
                    ");
                    $stmt_ins->execute([
                        ':dept'    => $dept,
                        ':base_id' => $base_waku_id,
                        ':date'    => $date,
                        ':name'    => $base_waku['waku_name'],
                        ':group'   => $base_waku['group_code'],
                        ':order'   => $base_waku['display_order'],
                        ':reason'  => $reason
                    ]);
                    $override_id = (int)$stmt_ins->fetchColumn();
                } else {
                    // 既に休止されていないので何もしない
                    $override_id = null;
                }
            }

            // 監査ログ
            $log_desc = $is_suspended
                ? "【枠休止設定】{$date} {$dept} {$base_waku['waku_name']} を休止に設定 (理由: {$reason})"
                : "【枠休止解除】{$date} {$dept} {$base_waku['waku_name']} の休止を解除";
            recordScheduleAuditLog($pdo, 'update', 0, $existing ?: null, [
                'override_id'       => $override_id,
                'department_code'   => $dept,
                'target_date'       => $date,
                'base_waku_id'      => $base_waku_id,
                'display_waku_name' => $base_waku['waku_name'],
                'is_suspended'      => $is_suspended,
                'reason'            => $reason
            ], [
                'department_code' => $dept,
                'description'     => $log_desc,
                'diff_summary'    => $is_suspended ? "枠休止登録" : "枠休止解除"
            ]);

            $pdo->commit();

            echo json_encode([
                'success'      => true,
                'override_id'  => $override_id,
                'is_suspended' => $is_suspended,
                'reason'       => $reason,
                'message'      => $is_suspended ? "時間枠 {$base_waku['waku_name']} を休止に設定しました" : "時間枠 {$base_waku['waku_name']} の休止を解除しました"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 3. 臨時枠（増枠）の追加（特定日 DATE, base_waku_id = NULL）
        // ----------------------------------------------------
        case 'add_extra_slot':
            $dept         = isset($_REQUEST['dept']) ? trim((string)$_REQUEST['dept']) : 'PROCT';
            $date         = isset($_REQUEST['date']) ? trim((string)$_REQUEST['date']) : '';
            $display_name = isset($_REQUEST['display_waku_name']) ? trim((string)$_REQUEST['display_waku_name']) : '';
            $group_code   = isset($_REQUEST['group_code']) ? trim((string)$_REQUEST['group_code']) : 'GIF';
            $reason       = isset($_REQUEST['reason']) ? trim((string)$_REQUEST['reason']) : '臨時増枠';
            $display_order = isset($_REQUEST['display_order']) && is_numeric($_REQUEST['display_order']) ? (int)$_REQUEST['display_order'] : 99;

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || empty($display_name)) {
                throw new Exception('パラメータが不正です (dept, date, display_waku_name)');
            }
            if (!in_array($group_code, ['GIF', 'ECHO', 'TCF'], true)) {
                $group_code = 'GIF';
            }

            $pdo->beginTransaction();

            $stmt_ins = $pdo->prepare("
                INSERT INTO m2_waku_override (
                    department_code, base_waku_id, override_type, target_date,
                    display_waku_name, group_code, display_order, is_suspended, reason,
                    created_at, updated_at
                ) VALUES (
                    :dept, NULL, 'DATE', :date,
                    :name, :group, :order, FALSE, :reason,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) RETURNING override_id
            ");
            $stmt_ins->execute([
                ':dept'   => $dept,
                ':date'   => $date,
                ':name'   => $display_name,
                ':group'  => $group_code,
                ':order'  => $display_order,
                ':reason' => $reason
            ]);
            $override_id = (int)$stmt_ins->fetchColumn();

            recordScheduleAuditLog($pdo, 'insert', 0, null, [
                'override_id'       => $override_id,
                'department_code'   => $dept,
                'target_date'       => $date,
                'display_waku_name' => $display_name,
                'group_code'        => $group_code
            ], [
                'department_code' => $dept,
                'description'     => "【臨時枠追加】{$date} {$dept} に臨時枠 {$display_name} ({$group_code}) を追加",
                'diff_summary'    => "臨時枠追加"
            ]);

            $pdo->commit();

            echo json_encode([
                'success'           => true,
                'override_id'       => $override_id,
                'display_waku_name' => $display_name,
                'message'           => "臨時枠「{$display_name}」を追加しました"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 3.5. 単一日の枠名称変更・オーバーライド保存（ギア ⚙️ 連動）
        // ----------------------------------------------------
        case 'save_override':
            $dept         = isset($_REQUEST['dept']) ? trim((string)$_REQUEST['dept']) : 'PROCT';
            $date         = isset($_REQUEST['date']) ? trim((string)$_REQUEST['date']) : '';
            $base_waku_id   = isset($_REQUEST['base_waku_id']) ? (int)$_REQUEST['base_waku_id'] : 0;
            $base_waku_name = isset($_REQUEST['base_waku_name']) ? trim((string)$_REQUEST['base_waku_name']) : '';
            $display_name   = isset($_REQUEST['display_waku_name']) ? trim((string)$_REQUEST['display_waku_name']) : '';
            $is_suspended   = isset($_REQUEST['is_suspended']) && ($_REQUEST['is_suspended'] === '1' || $_REQUEST['is_suspended'] === 'true' || $_REQUEST['is_suspended'] === true);
            $reason         = isset($_REQUEST['reason']) ? trim((string)$_REQUEST['reason']) : '';

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('パラメータが不正です (dept, date)');
            }

            // 基本枠マスタ取得（IDまたは枠名から柔軟に自動解決）
            $bw = null;
            if ($base_waku_id > 0) {
                $stmt_bw = $pdo->prepare("SELECT * FROM m2_waku WHERE id = :id AND department_code = :dept AND is_active = TRUE");
                $stmt_bw->execute([':id' => $base_waku_id, ':dept' => $dept]);
                $bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
            }
            if (!$bw && !empty($base_waku_name)) {
                $stmt_bw2 = $pdo->prepare("SELECT * FROM m2_waku WHERE waku_name = :name AND department_code = :dept AND is_active = TRUE AND :date BETWEEN valid_from AND valid_to LIMIT 1");
                $stmt_bw2->execute([':name' => $base_waku_name, ':dept' => $dept, ':date' => $date]);
                $bw = $stmt_bw2->fetch(PDO::FETCH_ASSOC);
                if ($bw) {
                    $base_waku_id = (int)$bw['id'];
                }
            }

            if (!$bw) {
                throw new Exception("対象の基本枠マスタが見つかりません (ID: {$base_waku_id}, 枠名: {$base_waku_name})");
            }

            if (empty($display_name)) {
                $display_name = $bw['waku_name'];
            }

            $pdo->beginTransaction();

            $stmt_chk = $pdo->prepare("
                SELECT * FROM m2_waku_override
                WHERE department_code = :dept
                  AND target_date = :date
                  AND base_waku_id = :base_id
                  AND override_type = 'DATE'
                  AND is_active = TRUE
                LIMIT 1
            ");
            $stmt_chk->execute([':dept' => $dept, ':date' => $date, ':base_id' => $base_waku_id]);
            $existing = $stmt_chk->fetch(PDO::FETCH_ASSOC);

            // 基本枠名と同じかつ休止でない場合は、オーバーライドを削除して基本枠に戻す
            if ($display_name === $bw['waku_name'] && !$is_suspended) {
                if ($existing) {
                    $stmt_del = $pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :id");
                    $stmt_del->execute([':id' => $existing['override_id']]);
                }
                $override_id = null;
                $log_action = 'delete';
                $log_desc = "【枠名称リセット】{$date} {$dept} {$bw['waku_name']} の名称を基本枠名に戻しました";
            } else {
                if ($existing) {
                    $stmt_upd = $pdo->prepare("
                        UPDATE m2_waku_override SET
                            display_waku_name = :name,
                            is_suspended = :suspended,
                            reason = :reason,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE override_id = :id
                    ");
                    $stmt_upd->execute([
                        ':name'      => $display_name,
                        ':suspended' => $is_suspended ? 'true' : 'false',
                        ':reason'    => $reason,
                        ':id'        => $existing['override_id']
                    ]);
                    $override_id = (int)$existing['override_id'];
                    $log_action = 'update';
                    $log_desc = "【枠名称変更】{$date} {$dept} {$bw['waku_name']} ➔ 「{$display_name}」に名称変更";
                } else {
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO m2_waku_override (
                            department_code, base_waku_id, override_type, target_date,
                            display_waku_name, group_code, display_order, is_suspended, reason,
                            created_at, updated_at
                        ) VALUES (
                            :dept, :base_id, 'DATE', :date,
                            :name, :group, :order, :suspended, :reason,
                            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                        ) RETURNING override_id
                    ");
                    $stmt_ins->execute([
                        ':dept'      => $dept,
                        ':base_id'   => $base_waku_id,
                        ':date'      => $date,
                        ':name'      => $display_name,
                        ':group'     => $bw['group_code'],
                        ':order'     => $bw['display_order'],
                        ':suspended' => $is_suspended ? 'true' : 'false',
                        ':reason'    => $reason
                    ]);
                    $override_id = (int)$stmt_ins->fetchColumn();
                    $log_action = 'insert';
                    $log_desc = "【枠名称変更】{$date} {$dept} {$bw['waku_name']} ➔ 「{$display_name}」に名称変更";
                }
            }

            // ★ 予約レコード（t_endoscopy_schedule）の scheduled_time を新枠名に自動同期
            // 旧枠名候補: 基本枠名、直前のオーバーライド表示名、クライアントから渡された枠名
            $old_waku_candidates = array_unique(array_filter([
                $bw['waku_name'],
                $base_waku_name,
                $existing ? (string)$existing['display_waku_name'] : null
            ]));

            if (!empty($old_waku_candidates)) {
                $placeholders = implode(',', array_fill(0, count($old_waku_candidates), '?'));
                $sql_sync = "
                    UPDATE t_endoscopy_schedule
                    SET scheduled_time = ?, waku_id = ?, waku_no = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE department_code = ?
                      AND exam_date = ?
                      AND (
                          waku_id = ?
                          OR (
                              (waku_id IS NULL OR waku_id = 0)
                              AND (scheduled_time IN ($placeholders) OR waku_no = ?)
                          )
                      )
                ";
                $sync_params = array_merge(
                    [$display_name, $base_waku_id, $bw['display_order'], $dept, $date, $base_waku_id],
                    array_values($old_waku_candidates),
                    [$bw['display_order']]
                );
                $stmt_sync = $pdo->prepare($sql_sync);
                $stmt_sync->execute($sync_params);
            }

            recordScheduleAuditLog($pdo, $log_action, 0, $existing ?: null, [
                'override_id'       => $override_id,
                'department_code'   => $dept,
                'target_date'       => $date,
                'base_waku_id'      => $base_waku_id,
                'display_waku_name' => $display_name
            ], [
                'department_code' => $dept,
                'description'     => $log_desc,
                'diff_summary'    => "枠名称変更"
            ]);

            $pdo->commit();

            echo json_encode([
                'success'           => true,
                'override_id'       => $override_id,
                'display_waku_name' => $display_name,
                'message'           => "時間枠の表示名称を「{$display_name}」に保存しました"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 4. 期間指定オーバーライドの登録（PERIOD）
        // ----------------------------------------------------
        case 'save_period_override':
            if (!isPeriodOverrideAllowed($pdo)) {
                throw new Exception('現在、期間指定設定機能は許可されていません。（マスタメンテナンスで有効化できます）');
            }

            $dept         = isset($_REQUEST['dept']) ? trim((string)$_REQUEST['dept']) : 'PROCT';
            $start_date   = isset($_REQUEST['start_date']) ? trim((string)$_REQUEST['start_date']) : '';
            $end_date     = isset($_REQUEST['end_date']) ? trim((string)$_REQUEST['end_date']) : '';
            $base_waku_id = isset($_REQUEST['base_waku_id']) && is_numeric($_REQUEST['base_waku_id']) && (int)$_REQUEST['base_waku_id'] > 0 ? (int)$_REQUEST['base_waku_id'] : null;
            $display_name = isset($_REQUEST['display_waku_name']) ? trim((string)$_REQUEST['display_waku_name']) : '';
            $group_code   = isset($_REQUEST['group_code']) ? trim((string)$_REQUEST['group_code']) : 'GIF';
            $is_suspended = isset($_REQUEST['is_suspended']) && ($_REQUEST['is_suspended'] === '1' || $_REQUEST['is_suspended'] === 'true' || $_REQUEST['is_suspended'] === true);
            $reason       = isset($_REQUEST['reason']) ? trim((string)$_REQUEST['reason']) : '';
            $display_order = isset($_REQUEST['display_order']) && is_numeric($_REQUEST['display_order']) ? (int)$_REQUEST['display_order'] : null;

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) || $start_date > $end_date) {
                throw new Exception('期間パラメータが不正です (dept, start_date, end_date)');
            }

            if ($base_waku_id !== null) {
                $stmt_bw = $pdo->prepare("SELECT * FROM m2_waku WHERE id = :id");
                $stmt_bw->execute([':id' => $base_waku_id]);
                $bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
                if ($bw) {
                    if (empty($display_name)) { $display_name = $bw['waku_name']; }
                    if (empty($group_code)) { $group_code = $bw['group_code']; }
                    if ($display_order === null) { $display_order = (int)$bw['display_order']; }
                }
            }

            if (empty($display_name)) {
                throw new Exception('枠名称を指定してください');
            }

            $pdo->beginTransaction();

            // ★ 単一変更の一括塗りつぶし（バルク展開）方式
            // 指定期間の全日付について単一日（DATE）レコードをUPSERT（個別調整・⭕復帰が超簡単）
            $curr_ts = strtotime($start_date);
            $end_ts = strtotime($end_date);
            $created_count = 0;
            $last_override_id = 0;

            $stmt_chk = $pdo->prepare("
                SELECT override_id FROM m2_waku_override
                WHERE department_code = :dept
                  AND target_date = :t_date
                  AND base_waku_id = :base_id
                  AND is_active = TRUE
                LIMIT 1
            ");

            $stmt_upd = $pdo->prepare("
                UPDATE m2_waku_override SET
                    display_waku_name = :name,
                    group_code = :group,
                    display_order = :order,
                    is_suspended = :suspended,
                    reason = :reason,
                    updated_at = CURRENT_TIMESTAMP
                WHERE override_id = :id
            ");

            $stmt_ins = $pdo->prepare("
                INSERT INTO m2_waku_override (
                    department_code, base_waku_id, override_type, target_date,
                    display_waku_name, group_code, display_order, is_suspended, reason,
                    created_at, updated_at
                ) VALUES (
                    :dept, :base_id, 'DATE', :t_date,
                    :name, :group, :order, :suspended, :reason,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) RETURNING override_id
            ");

            while ($curr_ts <= $end_ts) {
                $d_str = date('Y-m-d', $curr_ts);

                $stmt_chk->execute([
                    ':dept'    => $dept,
                    ':t_date'  => $d_str,
                    ':base_id' => $base_waku_id
                ]);
                $existing_id = $stmt_chk->fetchColumn();

                if ($existing_id) {
                    $stmt_upd->execute([
                        ':name'      => $display_name,
                        ':group'     => $group_code,
                        ':order'     => $display_order,
                        ':suspended' => $is_suspended ? 'true' : 'false',
                        ':reason'    => $reason,
                        ':id'        => $existing_id
                    ]);
                    $last_override_id = (int)$existing_id;
                } else {
                    $stmt_ins->execute([
                        ':dept'      => $dept,
                        ':base_id'   => $base_waku_id,
                        ':t_date'    => $d_str,
                        ':name'      => $display_name,
                        ':group'     => $group_code,
                        ':order'     => $display_order,
                        ':suspended' => $is_suspended ? 'true' : 'false',
                        ':reason'    => $reason
                    ]);
                    $last_override_id = (int)$stmt_ins->fetchColumn();
                }

                $created_count++;
                $curr_ts = strtotime('+1 day', $curr_ts);
            }

            recordScheduleAuditLog($pdo, 'insert', 0, null, [
                'override_id'       => $last_override_id,
                'department_code'   => $dept,
                'start_date'        => $start_date,
                'end_date'          => $end_date,
                'display_waku_name' => $display_name,
                'days_count'        => $created_count
            ], [
                'department_code' => $dept,
                'description'     => "【期間一括塗りつぶし登録】{$dept} {$start_date}〜{$end_date} ({$created_count}日分): {$display_name} (" . ($is_suspended ? '休止' : '変更') . ")",
                'diff_summary'    => "期間一括塗りつぶし登録（{$created_count}日分）"
            ]);

            $pdo->commit();

            echo json_encode([
                'success'     => true,
                'override_id' => $last_override_id,
                'days_count'  => $created_count,
                'message'     => "期間指定の単日設定（{$start_date}〜{$end_date}、計{$created_count}日分）を一括塗りつぶし登録しました"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 5. オーバーライドの削除（解除・基本マスタ復帰）
        // ----------------------------------------------------
        case 'delete_override':
            $override_id = isset($_REQUEST['override_id']) ? (int)$_REQUEST['override_id'] : 0;
            if ($override_id <= 0) {
                throw new Exception('有効な override_id を指定してください');
            }

            $pdo->beginTransaction();

            $stmt_get = $pdo->prepare("SELECT * FROM m2_waku_override WHERE override_id = :id");
            $stmt_get->execute([':id' => $override_id]);
            $target = $stmt_get->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new Exception('指定されたオーバーライド設定が見つかりません');
            }

            // ★ 確定予約保護ガード（迷子予約防止）
            $chk_wname = (string)$target['display_waku_name'];
            $chk_dept  = (string)$target['department_code'];
            $chk_ov_type = (string)$target['override_type'];

            if ($chk_ov_type === 'DATE') {
                $chk_date = (string)$target['target_date'];
                $stmt_chk_res = $pdo->prepare("
                    SELECT patient_name 
                    FROM t_endoscopy_schedule 
                    WHERE department_code = :dept 
                      AND exam_date = :date 
                      AND scheduled_time = :wname 
                      AND is_canceled = FALSE
                      AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
                ");
                $stmt_chk_res->execute([':dept' => $chk_dept, ':date' => $chk_date, ':wname' => $chk_wname]);
                $has_patients = $stmt_chk_res->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($has_patients)) {
                    $pNames = array_map(function($p) { return $p . ' 様'; }, array_slice($has_patients, 0, 2));
                    $pStr = implode('、', $pNames) . (count($has_patients) > 2 ? ' 他' : '');
                    throw new Exception("時間枠「{$chk_wname}」には既に患者の確定予約（{$pStr}）が入っているため、枠を解除・削除できません。先に予約を別の枠へ移動するかキャンセルしてください。");
                }
            } elseif ($chk_ov_type === 'PERIOD' && empty($target['base_waku_id'])) {
                // 期間の臨時枠の場合
                $stmt_chk_res = $pdo->prepare("
                    SELECT exam_date, patient_name 
                    FROM t_endoscopy_schedule 
                    WHERE department_code = :dept 
                      AND exam_date BETWEEN :start AND :end
                      AND scheduled_time = :wname 
                      AND is_canceled = FALSE
                      AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
                    LIMIT 3
                ");
                $stmt_chk_res->execute([':dept' => $chk_dept, ':start' => $target['start_date'], ':end' => $target['end_date'], ':wname' => $chk_wname]);
                $has_patients = $stmt_chk_res->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($has_patients)) {
                    $pStr = implode('、', array_map(function($p) { return $p['exam_date'] . ' ' . $p['patient_name'] . ' 様'; }, $has_patients));
                    throw new Exception("期間臨時枠「{$chk_wname}」には期間内に既に確定予約（{$pStr}）が入っているため、枠を解除・削除できません。");
                }
            }

            $stmt_del = $pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :id");
            $stmt_del->execute([':id' => $override_id]);

            recordScheduleAuditLog($pdo, 'delete', 0, $target, null, [
                'department_code' => $target['department_code'] ?? $dept,
                'description'     => "【オーバーライド解除】ID {$override_id} ({$target['display_waku_name']}, {$target['override_type']}) を削除",
                'diff_summary'    => "オーバーライド解除"
            ]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "オーバーライド設定（ID: {$override_id}）を解除しました"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 6. 設定済みオーバーライド一覧取得（当月・期間フィルター）
        // ----------------------------------------------------
        case 'list_overrides':
            $dept  = isset($_REQUEST['dept']) ? trim((string)$_REQUEST['dept']) : 'PROCT';
            $year  = isset($_REQUEST['year']) && is_numeric($_REQUEST['year']) ? (int)$_REQUEST['year'] : (int)date('Y');
            $month = isset($_REQUEST['month']) && is_numeric($_REQUEST['month']) ? (int)$_REQUEST['month'] : (int)date('n');

            $m_start = sprintf('%04d-%02d-01', $year, $month);
            $m_end   = date('Y-m-t', strtotime($m_start));

            $sql = "
                SELECT 
                    o.override_id,
                    o.department_code,
                    o.base_waku_id,
                    o.override_type,
                    o.target_date::text AS target_date,
                    o.start_date::text AS start_date,
                    o.end_date::text AS end_date,
                    o.display_waku_name,
                    o.group_code,
                    o.time_start,
                    o.time_end,
                    o.display_order,
                    o.is_suspended,
                    o.reason,
                    o.created_at,
                    o.updated_at,
                    w.waku_name AS base_waku_name
                FROM m2_waku_override o
                LEFT JOIN m2_waku w ON o.base_waku_id = w.id
                WHERE o.department_code = :dept
                  AND (
                      (o.override_type = 'DATE' AND o.target_date >= :start AND o.target_date <= :end)
                      OR
                      (o.override_type = 'PERIOD' AND o.start_date <= :end AND o.end_date >= :start)
                  )
                ORDER BY 
                    COALESCE(o.target_date, o.start_date) ASC,
                    o.display_order ASC,
                    o.override_id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':dept' => $dept, ':start' => $m_start, ':end' => $m_end]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'dept'    => $dept,
                'year'    => $year,
                'month'   => $month,
                'count'   => count($list),
                'list'    => $list
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 7. 休止理由プリセット一覧の取得 (m_input_master 連携)
        // ----------------------------------------------------
        case 'get_suspend_reasons':
            $stmt = $pdo->prepare("
                SELECT master_id, item_value AS reason_text, display_order
                FROM m_input_master
                WHERE category = 'waku_suspend_reason'
                ORDER BY display_order ASC, master_id ASC
            ");
            $stmt->execute();
            $reasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 過去の利用履歴（直近・上位）からもサジェスト候補を補完
            $stmt_hist = $pdo->query("
                SELECT DISTINCT reason
                FROM m2_waku_override
                WHERE is_suspended = TRUE AND reason IS NOT NULL AND TRIM(reason) != ''
                ORDER BY reason ASC
                LIMIT 20
            ");
            $hist_reasons = $stmt_hist->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success'      => true,
                'reasons'      => $reasons,
                'hist_reasons' => $hist_reasons
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 8. 休止理由プリセットの新規追加 (m_input_master 連携)
        // ----------------------------------------------------
        case 'add_suspend_reason':
            $new_reason = trim((string)($_POST['reason_text'] ?? ''));
            if ($new_reason === '') {
                throw new Exception('理由テキストが空です');
            }

            // 重複チェック
            $stmt_chk = $pdo->prepare("SELECT master_id FROM m_input_master WHERE category = 'waku_suspend_reason' AND item_value = :val");
            $stmt_chk->execute([':val' => $new_reason]);
            $existing_id = $stmt_chk->fetchColumn();
            if ($existing_id) {
                echo json_encode([
                    'success'   => true,
                    'master_id' => (int)$existing_id,
                    'reason'    => $new_reason,
                    'message'   => '既に登録されています'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $stmt_max = $pdo->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM m_input_master WHERE category = 'waku_suspend_reason'");
            $next_order = (int)$stmt_max->fetchColumn();

            $stmt_ins = $pdo->prepare("
                INSERT INTO m_input_master (category, item_value, display_order, created_at)
                VALUES ('waku_suspend_reason', :val, :disp, CURRENT_TIMESTAMP)
                RETURNING master_id
            ");
            $stmt_ins->execute([':val' => $new_reason, ':disp' => $next_order]);
            $new_id = (int)$stmt_ins->fetchColumn();

            echo json_encode([
                'success'   => true,
                'master_id' => $new_id,
                'reason'    => $new_reason
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 9. 休止理由プリセットの削除 (m_input_master 連携)
        // ----------------------------------------------------
        case 'delete_suspend_reason':
            $del_id = (int)($_POST['master_id'] ?? 0);
            if ($del_id <= 0) {
                throw new Exception('削除対象IDが不正です');
            }

            $stmt_del = $pdo->prepare("DELETE FROM m_input_master WHERE master_id = :id AND category = 'waku_suspend_reason'");
            $stmt_del->execute([':id' => $del_id]);

            echo json_encode([
                'success'   => true,
                'master_id' => $del_id
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ----------------------------------------------------
        // 10. 当日時間枠オーバーライドの一括保存（下書きバッチコミット）
        // ----------------------------------------------------
        case 'save_day_overrides_batch':
            $dept = isset($_POST['dept']) ? trim((string)$_POST['dept']) : '';
            $date = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
            $raw_changes = $_POST['changes'] ?? '';

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new Exception('パラメータが不正です (dept, date)');
            }

            $changes = is_array($raw_changes) ? $raw_changes : json_decode($raw_changes, true);
            if (!is_array($changes)) {
                throw new Exception('変更データ形式が不正です');
            }

            $pdo->beginTransaction();

            // 当該日の確定予約データを取得（休止ガード用キャッシュ）
            $stmt_res = $pdo->prepare("
                SELECT schedule_id, scheduled_time, patient_id, patient_name, exam_type, doctor_name, exam_location, remarks
                FROM t_endoscopy_schedule
                WHERE department_code = :dept
                  AND exam_date = :date
                  AND is_canceled = FALSE
                ORDER BY schedule_id ASC
            ");
            $stmt_res->execute([':dept' => $dept, ':date' => $date]);
            $batch_reservations = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

            $updated_count = 0;
            $deleted_count = 0;
            $inserted_count = 0;

            // A. 枠名変更・休止状態・理由の変更適用 (items: array of { base_waku_id, display_waku_name, is_suspended, reason })
            if (!empty($changes['slots']) && is_array($changes['slots'])) {
                foreach ($changes['slots'] as $slot_change) {
                    $b_id = (int)($slot_change['base_waku_id'] ?? 0);
                    $is_susp = !empty($slot_change['is_suspended']);
                    $reason = trim((string)($slot_change['reason'] ?? ''));

                    if ($b_id <= 0) continue;

                    // 基本枠マスタ情報を取得
                    $stmt_binfo = $pdo->prepare("SELECT waku_name, group_code, display_order FROM m2_waku WHERE id = :bid");
                    $stmt_binfo->execute([':bid' => $b_id]);
                    $b_info = $stmt_binfo->fetch(PDO::FETCH_ASSOC);
                    if (!$b_info) continue;

                    $base_name = (string)$b_info['waku_name'];
                    $b_grp     = (string)$b_info['group_code'];
                    $b_ord     = (int)$b_info['display_order'];

                    $new_waku_name = isset($slot_change['display_waku_name']) && trim((string)$slot_change['display_waku_name']) !== ''
                        ? trim((string)$slot_change['display_waku_name'])
                        : $base_name;
                    $is_name_changed = ($new_waku_name !== $base_name);

                    // 確定予約が入っている枠は休止不可
                    if ($is_susp) {
                        $resList = getSlotReservations($pdo, $dept, $date, $new_waku_name, $base_name, $batch_reservations);
                        if (!empty($resList)) {
                            $pNames = array_map(function($p) { return $p['patient_name'] . ' 様'; }, array_slice($resList, 0, 2));
                            $pStr = implode('、', $pNames) . (count($resList) > 2 ? ' 他' : '');
                            throw new Exception("時間枠「{$new_waku_name}」には既に確定予約（{$pStr}）が入っているため、休止できません。");
                        }
                    }

                    // 当該日の既存 DATE オーバーライドを確認
                    $stmt_cur = $pdo->prepare("
                        SELECT * FROM m2_waku_override
                        WHERE department_code = :dept
                          AND target_date = :date
                          AND base_waku_id = :base_id
                          AND override_type = 'DATE'
                    ");
                    $stmt_cur->execute([':dept' => $dept, ':date' => $date, ':base_id' => $b_id]);
                    $existing = $stmt_cur->fetch(PDO::FETCH_ASSOC);

                    // オーバーライドが必要な条件: 休止中 OR 枠名変更あり（休止解除かつ枠名同一なら基本枠へ完全復帰）
                    $needs_override = ($is_susp || $is_name_changed);

                    if ($needs_override) {
                        $final_reason = $is_susp ? ($reason !== '' ? $reason : '休止') : ($reason !== '' ? $reason : null);
                        if ($existing) {
                            $stmt_upd = $pdo->prepare("
                                UPDATE m2_waku_override
                                SET display_waku_name = :wname,
                                    is_suspended = :is_susp,
                                    reason = :reason,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE override_id = :oid
                            ");
                            $stmt_upd->execute([
                                ':wname'   => $new_waku_name,
                                ':is_susp' => $is_susp ? 1 : 0,
                                ':reason'  => $final_reason,
                                ':oid'     => $existing['override_id']
                            ]);
                            $desc = "【枠設定更新】{$date} 枠ID:{$b_id} (表示名: {$new_waku_name}" . ($is_susp ? ", 休止: {$final_reason}" : "") . ")";
                            recordScheduleAuditLog($pdo, 'update', 0, $existing, [
                                'override_id'       => $existing['override_id'],
                                'department_code'   => $dept,
                                'target_date'       => $date,
                                'base_waku_id'      => $b_id,
                                'display_waku_name' => $new_waku_name,
                                'is_suspended'      => $is_susp,
                                'reason'            => $final_reason
                            ], [
                                'department_code' => $dept,
                                'description'     => $desc,
                                'diff_summary'    => "枠日別オーバーライド一括更新"
                            ]);
                            $updated_count++;
                        } else {
                            $stmt_ins = $pdo->prepare("
                                INSERT INTO m2_waku_override (
                                    department_code, base_waku_id, override_type, target_date,
                                    display_waku_name, group_code, display_order,
                                    is_suspended, reason, created_at, updated_at
                                ) VALUES (
                                    :dept, :base_id, 'DATE', :date,
                                    :name, :grp, :ord,
                                    :is_susp, :reason, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                                )
                            ");
                            $stmt_ins->execute([
                                ':dept'    => $dept,
                                ':base_id' => $b_id,
                                ':date'    => $date,
                                ':name'    => $new_waku_name,
                                ':grp'     => $b_grp,
                                ':ord'     => $b_ord,
                                ':is_susp' => $is_susp ? 1 : 0,
                                ':reason'  => $final_reason
                            ]);
                            $desc = "【枠設定追加】{$date} 枠ID:{$b_id} (表示名: {$new_waku_name}" . ($is_susp ? ", 休止: {$final_reason}" : "") . ")";
                            recordScheduleAuditLog($pdo, 'insert', 0, null, [
                                'dept'              => $dept,
                                'department_code'   => $dept,
                                'date'              => $date,
                                'target_date'       => $date,
                                'base_id'           => $b_id,
                                'display_waku_name' => $new_waku_name,
                                'is_suspended'      => $is_susp,
                                'reason'            => $final_reason
                            ], [
                                'department_code' => $dept,
                                'description'     => $desc,
                                'diff_summary'    => "枠日別オーバーライド一括登録"
                            ]);
                            $inserted_count++;
                        }
                    } else {
                        // 通常枠に完全復帰（枠名も元通りで休止も解除された場合、既存のDATEオーバーライドを削除）
                        if ($existing) {
                            $stmt_del = $pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :oid");
                            $stmt_del->execute([':oid' => $existing['override_id']]);
                            recordScheduleAuditLog($pdo, 'delete', 0, $existing, null, [
                                'department_code' => $dept,
                                'description'     => "【枠設定解除】{$date} 枠ID:{$b_id} (基本枠復帰)",
                                'diff_summary'    => "枠日別オーバーライド一括解除"
                            ]);
                            $deleted_count++;
                        }
                    }

                    // ★ 予約テーブル（t_endoscopy_schedule）の scheduled_time を新枠名に同期
                    $old_candidates = array_unique(array_filter([
                        $base_name,
                        $existing ? (string)$existing['display_waku_name'] : null
                    ]));
                    if (!empty($old_candidates)) {
                        $ph = implode(',', array_fill(0, count($old_candidates), '?'));
                        $stmt_b_sync = $pdo->prepare("
                            UPDATE t_endoscopy_schedule
                            SET scheduled_time = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE department_code = ? AND exam_date = ? AND scheduled_time IN ($ph)
                        ");
                        $stmt_b_sync->execute(array_merge([$new_waku_name, $dept, $date], array_values($old_candidates)));
                    }
                }
            }

            // A-2. 既存臨時枠（base_waku_id IS NULL）の更新 (updated_extra_slots: array of { override_id, waku_name, reason })
            if (!empty($changes['updated_extra_slots']) && is_array($changes['updated_extra_slots'])) {
                foreach ($changes['updated_extra_slots'] as $up_extra) {
                    $o_id = (int)($up_extra['override_id'] ?? 0);
                    $w_name = trim((string)($up_extra['waku_name'] ?? ''));
                    $r_text = trim((string)($up_extra['reason'] ?? ''));
                    if ($o_id <= 0 || $w_name === '') continue;

                    $stmt_cur_ex = $pdo->prepare("SELECT * FROM m2_waku_override WHERE override_id = :oid AND department_code = :dept AND target_date = :date");
                    $stmt_cur_ex->execute([':oid' => $o_id, ':dept' => $dept, ':date' => $date]);
                    $cur_ex = $stmt_cur_ex->fetch(PDO::FETCH_ASSOC);
                    if ($cur_ex) {
                        $stmt_up_ex = $pdo->prepare("
                            UPDATE m2_waku_override
                            SET display_waku_name = :name,
                                reason = :reason,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE override_id = :oid
                        ");
                        $stmt_up_ex->execute([':name' => $w_name, ':reason' => ($r_text !== '' ? $r_text : $cur_ex['reason']), ':oid' => $o_id]);
                        recordScheduleAuditLog($pdo, 'update', 0, $cur_ex, [
                            'override_id'       => $o_id,
                            'department_code'   => $dept,
                            'target_date'       => $date,
                            'display_waku_name' => $w_name,
                            'reason'            => $r_text
                        ], [
                            'department_code' => $dept,
                            'description'     => "【臨時枠名称更新】{$date} 臨時枠ID:{$o_id} ➔ {$w_name}",
                            'diff_summary'    => "臨時枠更新"
                        ]);
                        $updated_count++;
                    }
                }
            }

            // B. 新規臨時枠の追加 (extra_slots: array of { waku_name, group_code, reason })
            if (!empty($changes['new_extra_slots']) && is_array($changes['new_extra_slots'])) {
                foreach ($changes['new_extra_slots'] as $extra) {
                    $w_name = trim((string)($extra['waku_name'] ?? ''));
                    $g_code = trim((string)($extra['group_code'] ?? 'TCF'));
                    $r_text = trim((string)($extra['reason'] ?? '臨時増枠'));

                    if ($w_name === '') continue;

                    // 表示順の算出（当該日世代の基本枠最大値+1）
                    $stmt_ord = $pdo->prepare("
                        SELECT COALESCE(MAX(display_order), 90) + 1
                        FROM m2_waku
                        WHERE department_code = :dept
                          AND :date BETWEEN valid_from AND valid_to
                    ");
                    $stmt_ord->execute([':dept' => $dept, ':date' => $date]);
                    $next_ord = (int)$stmt_ord->fetchColumn();

                    $stmt_ins_ex = $pdo->prepare("
                        INSERT INTO m2_waku_override (
                            department_code, base_waku_id, override_type, target_date,
                            display_waku_name, group_code, display_order,
                            is_suspended, reason, created_at, updated_at
                        ) VALUES (
                            :dept, NULL, 'DATE', :date,
                            :name, :group, :ord,
                            FALSE, :reason, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                        )
                    ");
                    $stmt_ins_ex->execute([
                        ':dept'   => $dept,
                        ':date'   => $date,
                        ':name'   => $w_name,
                        ':group'  => $g_code,
                        ':ord'    => $next_ord,
                        ':reason' => $r_text
                    ]);
                    recordScheduleAuditLog($pdo, 'insert', 0, null, [
                        'dept'              => $dept,
                        'department_code'   => $dept,
                        'date'              => $date,
                        'target_date'       => $date,
                        'name'              => $w_name,
                        'display_waku_name' => $w_name,
                        'group_code'        => $g_code
                    ], [
                        'department_code' => $dept,
                        'description'     => "【臨時枠一括追加】{$date} {$w_name} ({$g_code})",
                        'diff_summary'    => "臨時枠一括追加"
                    ]);
                    $inserted_count++;
                }
            }

            // C. 削除指定されたオーバーライド (deleted_override_ids: array of int)
            if (!empty($changes['deleted_override_ids']) && is_array($changes['deleted_override_ids'])) {
                foreach ($changes['deleted_override_ids'] as $del_oid) {
                    $del_oid = (int)$del_oid;
                    if ($del_oid <= 0) continue;

                    $stmt_exist = $pdo->prepare("SELECT * FROM m2_waku_override WHERE override_id = :oid");
                    $stmt_exist->execute([':oid' => $del_oid]);
                    $old_row = $stmt_exist->fetch(PDO::FETCH_ASSOC);

                    if ($old_row) {
                        // ★ 確定予約保護ガード（迷子予約防止）
                        // 削除対象枠（臨時枠や特定日枠）に確定予約が入っている場合は削除禁止
                        $chk_wname = (string)$old_row['display_waku_name'];
                        $chk_dept  = (string)($old_row['department_code'] ?? $dept);
                        $chk_date  = (string)($old_row['target_date'] ?? $date);

                        $stmt_chk_res = $pdo->prepare("
                            SELECT patient_name 
                            FROM t_endoscopy_schedule 
                            WHERE department_code = :dept 
                              AND exam_date = :date 
                              AND scheduled_time = :wname 
                              AND is_canceled = FALSE
                              AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
                        ");
                        $stmt_chk_res->execute([':dept' => $chk_dept, ':date' => $chk_date, ':wname' => $chk_wname]);
                        $has_patients = $stmt_chk_res->fetchAll(PDO::FETCH_COLUMN);

                        if (!empty($has_patients)) {
                            $pNames = array_map(function($p) { return $p . ' 様'; }, array_slice($has_patients, 0, 2));
                            $pStr = implode('、', $pNames) . (count($has_patients) > 2 ? ' 他' : '');
                            throw new Exception("時間枠「{$chk_wname}」には既に患者の確定予約（{$pStr}）が入っているため、枠を削除できません。先に予約を別の枠へ移動するかキャンセルしてください。");
                        }

                        $stmt_del_ex = $pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :oid");
                        $stmt_del_ex->execute([':oid' => $del_oid]);
                        recordScheduleAuditLog($pdo, 'delete', 0, $old_row, null, [
                            'department_code' => $old_row['department_code'] ?? $dept,
                            'description'     => "【オーバーライド一括削除】ID:{$del_oid}",
                            'diff_summary'    => "オーバーライド一括解除"
                        ]);
                        $deleted_count++;
                    }
                }
            }

            $pdo->commit();

            echo json_encode([
                'success'        => true,
                'updated_count'  => $updated_count,
                'deleted_count'  => $deleted_count,
                'inserted_count' => $inserted_count,
                'message'        => '設定を保存しました'
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("未知のアクションです: {$action}");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
