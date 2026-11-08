<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\api_toggle_no_waku.php
 * ファイル名: api_toggle_no_waku.php
 * 概要: 枠なし（休止）設定／通常枠復帰 非同期トグルAPI
 * 生成日時: 2026-10-23 21:20
 * 修正履歴:
 *   - 2026-10-23: 新規作成。month_schedule.php および schedule_note.php の 🚫⭕ 操作時に
 *                 画面フルリロードを発生させず、非同期(Ajax/Fetch)で瞬時に枠なし設定/復帰を行い、
 *                 監査ログ記録および最新枠集計サマリーをJSON返却する。
 *   - 2026-11-06: 時間枠世代管理（valid_from 〜 valid_to）に対応。対象日付の世代を優先して基本枠情報を解決。
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/audit_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';

/**
 * 枠なし設定 / 通常枠復帰のコアロジック関数
 * 
 * @param PDO $pdo
 * @param array $params
 * @return array
 * @throws Exception
 */
function toggleNoWakuRecord(PDO $pdo, array $params): array {
    $dept = trim((string)($params['dept'] ?? 'PROCT'));
    if (!in_array($dept, ['PROCT', 'GASTRO'], true)) {
        $dept = 'PROCT';
    }

    $selected_date = trim((string)($params['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
        throw new Exception('無効な日付形式です (YYYY-MM-DD)。');
    }

    $action = trim((string)($params['action'] ?? ''));
    $target_waku = trim((string)($params['waku'] ?? ''));
    $target_sid = (int)($params['sid'] ?? 0);
    $target_base_id = (int)($params['base_id'] ?? 0);
    $target_orig_name = trim((string)($params['orig_name'] ?? ''));

    if ($action !== 'set_no_waku' && $action !== 'revert_waku') {
        throw new Exception('無効なアクションです。set_no_waku または revert_waku を指定してください。');
    }

    $result_sid = 0;
    $message = '';

    if ($action === 'set_no_waku') {
        if (empty($target_waku) && $target_base_id <= 0) {
            throw new Exception('対象の枠情報（waku または base_id）が不足しています。');
        }

        // 基本枠マスタ情報を特定（base_id 優先、なければ orig_name または waku）
        $bw = null;
        if ($target_base_id > 0) {
            $stmt_bw = $pdo->prepare("SELECT id, display_order, group_code, waku_name FROM m2_waku WHERE id = :id AND department_code = :dept AND is_active = TRUE");
            $stmt_bw->execute([':id' => $target_base_id, ':dept' => $dept]);
            $bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bw && !empty($target_orig_name)) {
            $stmt_bw = $pdo->prepare("SELECT id, display_order, group_code, waku_name FROM m2_waku WHERE waku_name = :name AND department_code = :dept AND is_active = TRUE AND :date BETWEEN valid_from AND valid_to LIMIT 1");
            $stmt_bw->execute([':name' => $target_orig_name, ':dept' => $dept, ':date' => $selected_date]);
            $bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bw && !empty($target_waku)) {
            $stmt_bw = $pdo->prepare("SELECT id, display_order, group_code, waku_name FROM m2_waku WHERE waku_name = :name AND department_code = :dept AND is_active = TRUE AND :date BETWEEN valid_from AND valid_to LIMIT 1");
            $stmt_bw->execute([':name' => $target_waku, ':dept' => $dept, ':date' => $selected_date]);
            $bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
        }

        $bw_id    = $bw ? (int)$bw['id'] : ($target_base_id > 0 ? $target_base_id : null);
        $bw_order = $bw ? (int)$bw['display_order'] : 1;
        $bw_group = $bw ? (string)$bw['group_code'] : 'GIF';
        $bw_display_name = !empty($target_waku) ? $target_waku : ($bw ? $bw['waku_name'] : 'GIF1');

        // 二重登録防止ガード
        $already_exists = false;
        $existing_sid = 0;
        if ($bw_id !== null && $bw_id > 0) {
            $stmt_dup = $pdo->prepare("
                SELECT schedule_id FROM t_endoscopy_schedule 
                WHERE department_code = :dept 
                  AND exam_date = :date 
                  AND is_canceled = FALSE 
                  AND (patient_name = '※枠なし' OR remarks LIKE '%＊＊＊🚫枠なし%')
                  AND (waku_id = :bid OR waku_no = :ord)
                LIMIT 1
            ");
            $stmt_dup->execute([':dept' => $dept, ':date' => $selected_date, ':bid' => $bw_id, ':ord' => $bw_order]);
            $existing_sid = (int)$stmt_dup->fetchColumn();
            if ($existing_sid > 0) {
                $already_exists = true;
                $result_sid = $existing_sid;
            }
        }

        if (!$already_exists) {
            $ins = $pdo->prepare("
                INSERT INTO t_endoscopy_schedule 
                (department_code, exam_date, scheduled_time, waku_no, waku_id, exam_type, exam_location, doctor_name, patient_name, remarks, is_canceled, created_at, updated_at)
                VALUES
                (:dept, :date, :waku, :waku_no, :waku_id, :exam_type, '指定なし', '◆未定◆', '※枠なし', '＊＊＊🚫枠なし🚫＊＊＊', FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $ins->execute([
                ':dept'      => $dept,
                ':date'      => $selected_date,
                ':waku'      => $bw_display_name,
                ':waku_no'   => $bw_order,
                ':waku_id'   => $bw_id,
                ':exam_type' => $bw_group
            ]);
            $result_sid = (int)$pdo->lastInsertId();
            if ($result_sid > 0) {
                $new_row = getScheduleRecordById($pdo, $result_sid);
                recordScheduleAuditLog($pdo, 'insert', $result_sid, null, $new_row, [
                    'description'  => "【枠なし設定】{$selected_date} {$bw_display_name} を枠なし（予約不可）に設定",
                    'diff_summary' => "枠なしブロック設定"
                ]);
            }
        }

        $message = "{$bw_display_name} を枠なし（休止）に設定しました。";

    } elseif ($action === 'revert_waku') {
        // 通常枠復帰処理
        if ($target_sid > 0) {
            $old_row = getScheduleRecordById($pdo, $target_sid);
            $del = $pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :sid AND department_code = :dept");
            $del->execute([':sid' => $target_sid, ':dept' => $dept]);
            if ($old_row) {
                recordScheduleAuditLog($pdo, 'delete', $target_sid, $old_row, null, [
                    'description'  => "【枠なし解除】{$old_row['exam_date']} {$old_row['scheduled_time']} を通常枠に復帰",
                    'diff_summary' => "枠なし解除・通常枠復帰"
                ]);
            }
        }

        // オーバーライド休止設定の安全解除（base_id 優先）
        if ($target_base_id > 0) {
            $del_ov = $pdo->prepare("
                DELETE FROM m2_waku_override
                WHERE department_code = :dept
                  AND target_date = :date
                  AND base_waku_id = :base_id
                  AND is_suspended = TRUE
            ");
            $del_ov->execute([':dept' => $dept, ':date' => $selected_date, ':base_id' => $target_base_id]);
        } elseif (!empty($target_waku)) {
            $del_ov = $pdo->prepare("
                DELETE FROM m2_waku_override
                WHERE department_code = :dept
                  AND target_date = :date
                  AND display_waku_name = :waku
                  AND is_suspended = TRUE
            ");
            $del_ov->execute([':dept' => $dept, ':date' => $selected_date, ':waku' => $target_waku]);
        }

        // 旧方式の t_endoscopy_schedule 枠なしレコード（sid未指定時も念のため一括掃除）
        if (!empty($target_waku)) {
            $del_legacy = $pdo->prepare("
                DELETE FROM t_endoscopy_schedule 
                WHERE department_code = :dept 
                  AND exam_date = :date 
                  AND scheduled_time = :waku 
                  AND (patient_name = '※枠なし' OR remarks LIKE '%枠なし%' OR remarks LIKE '%休止%')
            ");
            $del_legacy->execute([':dept' => $dept, ':date' => $selected_date, ':waku' => $target_waku]);
        }

        $result_sid = 0;
        $disp_name = !empty($target_waku) ? $target_waku : ($target_orig_name ?: '時間枠');
        $message = "{$disp_name} を通常枠に復帰しました。";
    }

    // 当日の最新枠集計サマリーを算出
    $daily_rows = getDailyReservationsWithEffectiveSlots($pdo, $dept, $selected_date);
    $total_slots = count($daily_rows);
    $used_slots = 0;
    $no_waku_slots = 0;
    $empty_slots = 0;

    foreach ($daily_rows as $dr) {
        $is_empty = isset($dr['is_empty']) && $dr['is_empty'] === true;
        $p_name_raw = trim((string)($dr['patient_name'] ?? ''));
        $is_real_patient = (!$is_empty && $p_name_raw !== '' && $p_name_raw !== '※枠なし' && $p_name_raw !== '※仮予約' && $p_name_raw !== '指定なし');

        if ($is_real_patient) {
            $is_no_w = false;
        } else {
            $is_suspended_override = !empty($dr['is_suspended']);
            $is_legacy_no_waku = (!$is_empty && (
                $p_name_raw === '※枠なし' ||
                mb_strpos($remarks, '枠なし') !== false ||
                mb_strpos($remarks, '休止') !== false
            ));
            $is_no_w = ($is_suspended_override || $is_legacy_no_waku || !empty($dr['is_no_waku']));
        }

        if ($is_no_w) {
            $no_waku_slots++;
        } elseif ($is_empty) {
            $empty_slots++;
        } else {
            $used_slots++;
        }
    }

    return [
        'success'      => true,
        'action'       => $action,
        'schedule_id'  => $result_sid,
        'dept'         => $dept,
        'date'         => $selected_date,
        'waku'         => $target_waku,
        'base_id'      => $target_base_id,
        'orig_name'    => $target_orig_name,
        'message'      => $message,
        'stats'        => [
            'total_slots'   => $total_slots,
            'used_slots'    => $used_slots,
            'no_waku_slots' => $no_waku_slots,
            'empty_slots'   => $empty_slots,
        ]
    ];
}

// 直接 HTTP リクエストされた場合に JSON を出力
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    try {
        $pdo = getDbConnection();
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            throw new Exception('データベース接続が確立されていません。');
        }

        $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        $raw_json = file_get_contents('php://input');
        if (!empty($raw_json)) {
            $json_data = json_decode($raw_json, true);
            if (is_array($json_data)) {
                $input = array_merge($input, $json_data);
            }
        }

        $res = toggleNoWakuRecord($pdo, $input);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
