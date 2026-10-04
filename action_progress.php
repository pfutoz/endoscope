<?php
/**
 * action_progress.php
 * 当日進捗ステータスモニタ用 更新・巻き戻し(Undo) API
 * 
 * 生成日時: 2026-09-09 20:30:00 JST
 * 修正履歴:
 *   - 2026-09-28: リカバリ目標時間の個別自由調整機能 (update_recovery_target) を追加
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/progress_db.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'error' => 'DB接続失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

initProgressTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POSTリクエストのみ受け付けます'], JSON_UNESCAPED_UNICODE);
    exit;
}

// JSONまたはPOSTパラメータ取得
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = isset($input['action']) ? trim((string)$input['action']) : '';
$schedule_id = isset($input['schedule_id']) ? (int)$input['schedule_id'] : 0;
$operator_name = isset($input['operator_name']) ? trim((string)$input['operator_name']) : 'スタッフ';
$operator_role = isset($input['operator_role']) ? trim((string)$input['operator_role']) : 'staff';

if ($schedule_id <= 0) {
    echo json_encode(['success' => false, 'error' => '無効な予約IDです'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 現在の進捗レコード取得
    $stmt = $pdo->prepare("SELECT * FROM t_today_progress WHERE schedule_id = :id");
    $stmt->execute([':id' => $schedule_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        // レコードがない場合は新規作成
        $sched_stmt = $pdo->prepare("SELECT exam_date, room_number, arrival_time FROM t_endoscopy_schedule WHERE schedule_id = :id");
        $sched_stmt->execute([':id' => $schedule_id]);
        $sched = $sched_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sched) {
            echo json_encode(['success' => false, 'error' => '予約データが見つかりません'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO t_today_progress (schedule_id, exam_date, current_status_id, toilet_room, arrival_time, is_arrived)
            VALUES (:id, :date, 1, :room, :arr, :is_arr)
        ");
        $has_arr = !empty($sched['arrival_time']);
        $ins->execute([
            ':id' => $schedule_id,
            ':date' => $sched['exam_date'],
            ':room' => $sched['room_number'] ?? null,
            ':arr' => $sched['arrival_time'] ?? null,
            ':is_arr' => $has_arr ? 1 : 0
        ]);

        $stmt->execute([':id' => $schedule_id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $exam_date = $current['exam_date'];
    $current_status_id = (int)$current['current_status_id'];
    $now_time_hm = date('H:i');
    $now_timestamp = date('Y-m-d H:i:s');

    switch ($action) {
        // =================================================================
        // ステータス進捗 (FORWARD / DIRECT)
        // =================================================================
        case 'update_status':
            $to_status_id = isset($input['to_status_id']) ? (int)$input['to_status_id'] : 0;
            if ($to_status_id < 1 || $to_status_id > 7) {
                throw new Exception("無効なステータスIDです: {$to_status_id}");
            }

            // 【GIF前処置スキップ】前処置なし(is_prep_skipped)の場合、1➔2をスキップして直接3(検査待ち)へ
            if ($to_status_id === 2 && !empty($current['is_prep_skipped'])) {
                $to_status_id = 3;
            }

            // 【セデーションなしスキップ】鎮静なし(!has_sedation)の場合、4➔5(リカバリ)をスキップして直接6(説明)へ
            if ($to_status_id === 5 && empty($current['has_sedation'])) {
                $to_status_id = 6;
            }

            $update_fields = [
                'current_status_id = :to_status_id',
                'updated_at = :updated_at'
            ];
            $params = [
                ':to_status_id' => $to_status_id,
                ':updated_at' => $now_timestamp,
                ':schedule_id' => $schedule_id
            ];

            // タイムスタンプと業務連動
            if ($to_status_id === 1) { // 来院・受付済
                $update_fields[] = 'is_arrived = TRUE';
                if (empty($current['arrival_time'])) {
                    $update_fields[] = 'arrival_time = :arr_time';
                    $params[':arr_time'] = $now_time_hm;
                    // t_endoscopy_schedule にも連動
                    $pdo->prepare("UPDATE t_endoscopy_schedule SET arrival_time = :arr WHERE schedule_id = :id AND arrival_time IS NULL")
                        ->execute([':arr' => $now_time_hm, ':id' => $schedule_id]);
                }
            } elseif ($to_status_id === 2) { // 前処置準備 (マイトイレ案内)
                if (empty($current['prep_start_time'])) {
                    $update_fields[] = 'prep_start_time = :prep_start';
                    $params[':prep_start'] = $now_time_hm;
                }
            } elseif ($to_status_id === 3) { // 前処置完了 (検査待ち)
                if (empty($current['prep_done_time'])) {
                    $update_fields[] = 'prep_done_time = :prep_done';
                    $params[':prep_done'] = $now_time_hm;
                }
                if (empty($current['wait_start_time'])) {
                    $update_fields[] = 'wait_start_time = :wait_start';
                    $params[':wait_start'] = $now_timestamp;
                }
                $update_fields[] = 'is_exam_ready = FALSE';
            } elseif ($to_status_id === 4) { // 検査中
                if (empty($current['exam_start_time'])) {
                    $update_fields[] = 'exam_start_time = :exam_start';
                    $params[':exam_start'] = $now_time_hm;
                }
            } elseif ($to_status_id === 5) { // リカバリ中
                $update_fields[] = 'exam_end_time = :exam_end';
                $params[':exam_end'] = $now_time_hm;
                
                $update_fields[] = 'recovery_start_time = :rec_start';
                $params[':rec_start'] = $now_timestamp;

                // スコープ洗浄指示を自動発行
                if ($current['scope_cleaning_status'] === 'NONE' || empty($current['scope_cleaning_status'])) {
                    $update_fields[] = "scope_cleaning_status = 'ORDERED'";
                    $update_fields[] = "scope_cleaning_ordered_at = :clean_ordered";
                    $params[':clean_ordered'] = $now_timestamp;
                }
            } elseif ($to_status_id === 6) { // 説明中
                $update_fields[] = 'recovery_end_time = :rec_end';
                $params[':rec_end'] = $now_timestamp;

                $update_fields[] = 'explain_start_time = :exp_start';
                $params[':exp_start'] = $now_time_hm;

                $update_fields[] = 'is_explain_waiting = FALSE';
            } elseif ($to_status_id === 7) { // 帰宅・完了
                $update_fields[] = 'done_time = :done_time';
                $params[':done_time'] = $now_time_hm;
            }

            $sql_up = "UPDATE t_today_progress SET " . implode(', ', $update_fields) . " WHERE schedule_id = :schedule_id";
            $pdo->prepare($sql_up)->execute($params);

            // 履歴ログ記録
            $pdo->prepare("
                INSERT INTO t_progress_status_log (
                    schedule_id, exam_date, from_status_id, to_status_id,
                    action_type, operator_name, operator_role, comment
                ) VALUES (
                    :sid, :date, :from_st, :to_st,
                    'FORWARD', :op_name, :op_role, :comment
                )
            ")->execute([
                ':sid' => $schedule_id,
                ':date' => $exam_date,
                ':from_st' => $current_status_id,
                ':to_st' => $to_status_id,
                ':op_name' => $operator_name,
                ':op_role' => $operator_role,
                ':comment' => "ステータス更新: {$current_status_id} ➔ {$to_status_id}"
            ]);

            echo json_encode(['success' => true, 'current_status_id' => $to_status_id]);
            break;

        // =================================================================
        // ステータス巻き戻し (UNDO / REVERT)
        // =================================================================
        case 'revert_status':
            $to_status_id = isset($input['to_status_id']) ? (int)$input['to_status_id'] : 0;
            $revert_reason = isset($input['revert_reason']) ? trim((string)$input['revert_reason']) : '誤操作による取り消し';

            if ($to_status_id < 1 || $to_status_id >= $current_status_id) {
                // デフォルトは1つ前
                $to_status_id = max(1, $current_status_id - 1);
            }

            $extra_revert = "";
            if ($to_status_id < 3) {
                $extra_revert = ", wait_start_time = NULL, is_exam_ready = FALSE";
            }

            $sql_revert = "
                UPDATE t_today_progress 
                SET current_status_id = :to_st, updated_at = :updated_at {$extra_revert}
                WHERE schedule_id = :id
            ";
            $pdo->prepare($sql_revert)->execute([
                ':to_st' => $to_status_id,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            // 履歴ログ記録 (REVERT)
            $pdo->prepare("
                INSERT INTO t_progress_status_log (
                    schedule_id, exam_date, from_status_id, to_status_id,
                    action_type, revert_reason, operator_name, operator_role, comment
                ) VALUES (
                    :sid, :date, :from_st, :to_st,
                    'REVERT', :reason, :op_name, :op_role, :comment
                )
            ")->execute([
                ':sid' => $schedule_id,
                ':date' => $exam_date,
                ':from_st' => $current_status_id,
                ':to_st' => $to_status_id,
                ':reason' => $revert_reason,
                ':op_name' => $operator_name,
                ':op_role' => $operator_role,
                ':comment' => "ステータス差し戻し: {$current_status_id} ➔ {$to_status_id} (理由: {$revert_reason})"
            ]);

            echo json_encode(['success' => true, 'current_status_id' => $to_status_id]);
            break;

        // =================================================================
        // 受付時刻の入力 / 打刻
        // =================================================================
        case 'update_arrival':
            $arrival_time = isset($input['arrival_time']) ? trim((string)$input['arrival_time']) : '';
            if (empty($arrival_time)) {
                $arrival_time = $now_time_hm;
            }
            $is_arrived = true;

            $pdo->prepare("
                UPDATE t_today_progress 
                SET arrival_time = :arr, is_arrived = :is_arr, updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':arr' => $arrival_time,
                ':is_arr' => 1,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            // t_endoscopy_schedule にも反映
            $pdo->prepare("
                UPDATE t_endoscopy_schedule 
                SET arrival_time = :arr 
                WHERE schedule_id = :id
            ")->execute([
                ':arr' => $arrival_time,
                ':id' => $schedule_id
            ]);

            echo json_encode(['success' => true, 'arrival_time' => $arrival_time]);
            break;

        // =================================================================
        // マイトイレ（前処置室）変更
        // =================================================================
        case 'update_toilet_room':
            $toilet_room = isset($input['toilet_room']) ? trim((string)$input['toilet_room']) : '';

            $pdo->prepare("
                UPDATE t_today_progress 
                SET toilet_room = :room, updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':room' => $toilet_room,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            // t_endoscopy_schedule にも連動反映
            $pdo->prepare("
                UPDATE t_endoscopy_schedule 
                SET room_number = :room 
                WHERE schedule_id = :id
            ")->execute([
                ':room' => $toilet_room,
                ':id' => $schedule_id
            ]);

            echo json_encode(['success' => true, 'toilet_room' => $toilet_room]);
            break;

        // =================================================================
        // 下剤作成状況更新
        // =================================================================
        case 'update_prep_med':
            $prep_med_status = isset($input['prep_med_status']) ? trim((string)$input['prep_med_status']) : 'NONE';
            $valid_med_statuses = ['NONE', 'PREPARED', 'DELIVERED'];
            if (!in_array($prep_med_status, $valid_med_statuses, true)) {
                $prep_med_status = 'NONE';
            }

            $pdo->prepare("
                UPDATE t_today_progress 
                SET prep_med_status = :status, updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':status' => $prep_med_status,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode(['success' => true, 'prep_med_status' => $prep_med_status]);
            break;

        // =================================================================
        // スコープ洗浄状況更新
        // =================================================================
        case 'update_scope_cleaning':
            $cleaning_status = isset($input['scope_cleaning_status']) ? trim((string)$input['scope_cleaning_status']) : 'NONE';
            $valid_clean = ['NONE', 'ORDERED', 'CLEANING', 'DONE'];
            if (!in_array($cleaning_status, $valid_clean, true)) {
                $cleaning_status = 'NONE';
            }

            $clean_done_at = ($cleaning_status === 'DONE') ? $now_timestamp : null;

            $pdo->prepare("
                UPDATE t_today_progress 
                SET scope_cleaning_status = :status,
                    scope_cleaning_done_at = COALESCE(:done_at, scope_cleaning_done_at),
                    updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':status' => $cleaning_status,
                ':done_at' => $clean_done_at,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode(['success' => true, 'scope_cleaning_status' => $cleaning_status]);
            break;

        // =================================================================
        // 組織採取・病理検体 照合＆登録
        // =================================================================
        case 'update_biopsy':
            $has_biopsy = !empty($input['has_biopsy']);
            $biopsy_count = isset($input['biopsy_count']) ? (int)$input['biopsy_count'] : 0;
            $biopsy_sites = isset($input['biopsy_sites']) ? trim((string)$input['biopsy_sites']) : '';
            $biopsy_verified = !empty($input['biopsy_verified']);

            $verified_by = $biopsy_verified ? $operator_name : null;
            $verified_at = $biopsy_verified ? $now_timestamp : null;

            $pdo->prepare("
                UPDATE t_today_progress 
                SET has_biopsy = :has_b,
                    biopsy_count = :b_cnt,
                    biopsy_sites = :b_sites,
                    biopsy_verified = :b_ver,
                    biopsy_verified_by = :b_by,
                    biopsy_verified_at = :b_at,
                    updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':has_b' => $has_biopsy ? 1 : 0,
                ':b_cnt' => $biopsy_count,
                ':b_sites' => $biopsy_sites,
                ':b_ver' => $biopsy_verified ? 1 : 0,
                ':b_by' => $verified_by,
                ':b_at' => $verified_at,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode([
                'success' => true,
                'has_biopsy' => $has_biopsy,
                'biopsy_count' => $biopsy_count,
                'biopsy_sites' => $biopsy_sites,
                'biopsy_verified' => $biopsy_verified
            ]);
            break;

        // =================================================================
        // 説明待ちトグル
        // =================================================================
        case 'toggle_explain_waiting':
            $is_explain_waiting = !empty($input['is_explain_waiting']);

            $pdo->prepare("
                UPDATE t_today_progress 
                SET is_explain_waiting = :exp, updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':exp' => $is_explain_waiting ? 1 : 0,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode(['success' => true, 'is_explain_waiting' => $is_explain_waiting]);
            break;

        // =================================================================
        // セデーション (鎮静剤) 有無・種別切替
        // =================================================================
        case 'toggle_sedation':
            $has_sedation = !empty($input['has_sedation']);
            $sedation_type = isset($input['sedation_type']) ? trim((string)$input['sedation_type']) : ($has_sedation ? 'PROPOFOL' : 'NONE');
            $target_minutes = ($sedation_type === 'PROPOFOL') ? 20 : 30;

            $pdo->prepare("
                UPDATE t_today_progress 
                SET has_sedation = :has_sed,
                    sedation_type = :sed_type,
                    recovery_target_minutes = :target_min,
                    updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':has_sed' => $has_sedation ? 1 : 0,
                ':sed_type' => $sedation_type,
                ':target_min' => $target_minutes,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode([
                'success' => true,
                'has_sedation' => $has_sedation,
                'sedation_type' => $sedation_type,
                'recovery_target_minutes' => $target_minutes
            ]);
            break;

        // =================================================================
        // メモ保存
        // =================================================================
        case 'update_memo':
            $memo = isset($input['progress_memo']) ? trim((string)$input['progress_memo']) : '';

            $pdo->prepare("
                UPDATE t_today_progress 
                SET progress_memo = :memo, updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':memo' => $memo,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode(['success' => true, 'progress_memo' => $memo]);
            break;

        // =================================================================
        // リカバリ目標時間の個別調整
        // =================================================================
        case 'update_recovery_target':
            $target_minutes = isset($input['recovery_target_minutes']) ? (int)$input['recovery_target_minutes'] : 0;
            if ($target_minutes < 5 || $target_minutes > 300) {
                throw new Exception("リカバリ時間は5分〜300分の間で指定してください。");
            }

            $pdo->prepare("
                UPDATE t_today_progress 
                SET recovery_target_minutes = :target_min,
                    updated_at = :updated_at 
                WHERE schedule_id = :id
            ")->execute([
                ':target_min' => $target_minutes,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ]);

            echo json_encode([
                'success' => true,
                'schedule_id' => $schedule_id,
                'recovery_target_minutes' => $target_minutes
            ]);
            break;

        // =================================================================
        // 検査準備完了トグル（待機中 ⇔ 準備完了・医師呼出OK）
        // =================================================================
        case 'toggle_exam_ready':
            $is_ready = isset($input['is_ready']) ? (!empty($input['is_ready']) ? 1 : 0) : ($current['is_exam_ready'] ? 0 : 1);
            $up_fields = ['is_exam_ready = :is_ready', 'updated_at = :updated_at'];
            $up_params = [
                ':is_ready' => $is_ready,
                ':updated_at' => $now_timestamp,
                ':id' => $schedule_id
            ];
            // 待機開始時刻が未記録なら起点として現在時刻をセット
            if (empty($current['wait_start_time'])) {
                $up_fields[] = 'wait_start_time = :wait_start';
                $up_params[':wait_start'] = $now_timestamp;
            }
            $sql_ready = "UPDATE t_today_progress SET " . implode(', ', $up_fields) . " WHERE schedule_id = :id";
            $pdo->prepare($sql_ready)->execute($up_params);

            // 履歴ログ記録
            $ready_str = $is_ready ? '準備完了(医師呼出OK)' : '待機中(空き待ち)';
            $pdo->prepare("
                INSERT INTO t_progress_status_log (
                    schedule_id, exam_date, from_status_id, to_status_id,
                    action_type, operator_name, operator_role, comment
                ) VALUES (
                    :sid, :date, :from_st, :to_st,
                    'UPDATE_STATUS', :op_name, :op_role, :comment
                )
            ")->execute([
                ':sid' => $schedule_id,
                ':date' => $exam_date,
                ':from_st' => $current_status_id,
                ':to_st' => $current_status_id,
                ':op_name' => $operator_name,
                ':op_role' => $operator_role,
                ':comment' => "検査準備状態切替: {$ready_str}"
            ]);

            echo json_encode([
                'success' => true,
                'schedule_id' => $schedule_id,
                'is_exam_ready' => (bool)$is_ready
            ]);
            break;

        default:
            throw new Exception("未対応のアクションです: {$action}");
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => '処理失敗: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
