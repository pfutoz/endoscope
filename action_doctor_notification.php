<?php
/**
 * action_doctor_notification.php
 * 医師向け予約変更・新規・キャンセル通知API
 * 
 * - get_notifications : 未読通知一覧取得（30日以内、検査日有効、医師別）
 * - mark_read         : 個別消込
 * - mark_all_read     : 一括消込（カーソル更新＆個別クリア）
 */

ini_set('display_errors', "0");
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'データベース接続失敗']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'get_notifications';
$doctor_name = trim((string)($_POST['doctor_name'] ?? $_GET['doctor_name'] ?? ''));

// 30日以上前の古い消込データを自動クリーンアップ（低頻度でも十分だが軽量なので常時実行）
try {
    $pdo->exec("DELETE FROM t_doctor_notification_read WHERE read_at < CURRENT_DATE - INTERVAL '30 days'");
} catch (Exception $e) {
    // ログ記録なしで無視
}

if ($action === 'get_notifications') {
    try {
        $params = [];
        $where_clauses = [
            "created_at >= CURRENT_TIMESTAMP - INTERVAL '30 days'",
            "(new_exam_date IS NULL OR new_exam_date >= CURRENT_DATE)"
        ];

        // ★ 医師指定がある場合（例: 誠吾）
        if ($doctor_name !== '' && $doctor_name !== 'ALL') {
            $params[':doc'] = $doctor_name;
            // 新担当が該当医師、または旧担当が該当医師（担当から外れた）、または担当医未定
            $where_clauses[] = "(
                new_data->>'doctor_name' = :doc 
                OR old_data->>'doctor_name' = :doc 
                OR COALESCE(new_data->>'doctor_name', old_data->>'doctor_name', '') = ''
            )";
            
            // 一括消込済み（該当医師またはALL）以降に絞り込み
            $where_clauses[] = "log_id > COALESCE((SELECT last_cleared_log_id FROM t_doctor_notification_status WHERE doctor_name = :doc), 0)";
            $where_clauses[] = "log_id > COALESCE((SELECT last_cleared_log_id FROM t_doctor_notification_status WHERE doctor_name = 'ALL'), 0)";

            // 個別消込済み（該当医師またはALL）を除外
            $where_clauses[] = "log_id NOT IN (SELECT log_id FROM t_doctor_notification_read WHERE doctor_name = :doc OR doctor_name = 'ALL')";
        } else {
            // ★ 全医師の場合（推奨案A：主たる担当医またはALLによって消込済みのものを除外）
            // ① ALLとして一括消込されたID以降に絞り込み
            $where_clauses[] = "log_id > COALESCE((SELECT last_cleared_log_id FROM t_doctor_notification_status WHERE doctor_name = 'ALL'), 0)";

            // ② 各レコードの主たる担当医（新担当、いなければ旧担当）が一括消込したlog_id以下であれば除外
            $where_clauses[] = "NOT EXISTS (
                SELECT 1 FROM t_doctor_notification_status s
                WHERE s.doctor_name = COALESCE(NULLIF(t_schedule_audit_log.new_data->>'doctor_name', ''), NULLIF(t_schedule_audit_log.old_data->>'doctor_name', ''))
                  AND t_schedule_audit_log.log_id <= s.last_cleared_log_id
            )";

            // ③ 各レコードの主たる担当医またはALLによって個別消込されているレコードを除外
            $where_clauses[] = "NOT EXISTS (
                SELECT 1 FROM t_doctor_notification_read r
                WHERE (
                    r.doctor_name = COALESCE(NULLIF(t_schedule_audit_log.new_data->>'doctor_name', ''), NULLIF(t_schedule_audit_log.old_data->>'doctor_name', ''))
                    OR r.doctor_name = 'ALL'
                )
                AND r.log_id = t_schedule_audit_log.log_id
            )";
        }

        $where_sql = implode(' AND ', $where_clauses);
        $sql = "
            SELECT 
                log_id,
                schedule_id,
                action_type,
                department_code,
                patient_id,
                patient_name,
                patient_kana,
                old_exam_date,
                old_scheduled_time,
                old_waku_no,
                new_exam_date,
                new_scheduled_time,
                new_waku_no,
                old_data->>'doctor_name' as old_doctor,
                new_data->>'doctor_name' as new_doctor,
                old_data->>'exam_type' as old_exam_type,
                new_data->>'exam_type' as new_exam_type,
                diff_summary,
                client_ip,
                operator_name,
                description,
                created_at
            FROM t_schedule_audit_log
            WHERE {$where_sql}
            ORDER BY log_id DESC
            LIMIT 50
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $formatted = [];
        $max_log_id = 0;

        foreach ($rows as $r) {
            $lid = (int)$r['log_id'];
            if ($lid > $max_log_id) {
                $max_log_id = $lid;
            }

            $c_time = strtotime($r['created_at']);
            $diff_sec = $now - $c_time;
            if ($diff_sec < 60) {
                $time_ago = 'たった今';
            } elseif ($diff_sec < 3600) {
                $time_ago = floor($diff_sec / 60) . '分前';
            } elseif ($diff_sec < 86400 && date('Y-m-d', $c_time) === date('Y-m-d', $now)) {
                $time_ago = '本日 ' . date('H:i', $c_time);
            } elseif ($diff_sec < 172800 && date('Y-m-d', $c_time) === date('Y-m-d', strtotime('-1 day', $now))) {
                $time_ago = '昨日 ' . date('H:i', $c_time);
            } else {
                $time_ago = date('m/d H:i', $c_time);
            }

            // 操作種別ラベル
            $action_label = '変更';
            $action_badge = 'badge-warning';
            $action_icon = '📝';
            if ($r['action_type'] === 'insert') {
                $action_label = '新規予約';
                $action_badge = 'badge-success';
                $action_icon = '🆕';
            } elseif ($r['action_type'] === 'delete') {
                $action_label = '予約削除';
                $action_badge = 'badge-danger';
                $action_icon = '🗑️';
            } elseif ($r['action_type'] === 'move') {
                $action_label = '日時移動';
                $action_badge = 'badge-primary';
                $action_icon = '↔️';
            } elseif ($r['action_type'] === 'undo') {
                $action_label = '復元(Undo)';
                $action_badge = 'badge-purple';
                $action_icon = '↩️';
            }

            // 担当医変更の特記判定
            $old_d = trim((string)($r['old_doctor'] ?? ''));
            $new_d = trim((string)($r['new_doctor'] ?? ''));
            if ($old_d !== '' && $new_d !== '' && $old_d !== $new_d) {
                $action_label = '担当変更 (' . $old_d . '➔' . $new_d . ')';
                $action_badge = 'badge-warning';
                $action_icon  = '🔄';
            } elseif ($old_d === '' && $new_d !== '' && $r['action_type'] === 'update') {
                $action_label = '担当決定 (' . $new_d . ')';
                $action_icon  = '👨‍⚕️';
            }

            // 検査日・時間の整形
            $target_date = $r['new_exam_date'] ?: $r['old_exam_date'];
            $target_time = $r['new_scheduled_time'] ?: $r['old_scheduled_time'];
            $target_exam = $r['new_exam_type'] ?: $r['old_exam_type'];
            $target_doc  = $new_d ?: $old_d;

            $formatted[] = [
                'log_id'             => $lid,
                'schedule_id'        => (int)$r['schedule_id'],
                'action_type'        => $r['action_type'],
                'action_label'       => $action_label,
                'action_badge'       => $action_badge,
                'action_icon'        => $action_icon,
                'department_code'    => $r['department_code'],
                'patient_id'         => $r['patient_id'],
                'patient_name'       => $r['patient_name'],
                'target_date'        => $target_date,
                'target_time'        => $target_time,
                'target_exam'        => $target_exam,
                'target_doc'         => $target_doc,
                'old_doctor'         => $old_d,
                'new_doctor'         => $new_d,
                'diff_summary'       => $r['diff_summary'],
                'operator_name'      => $r['operator_name'] ?: 'スタッフ',
                'time_ago'           => $time_ago,
                'created_at_fmt'     => date('Y/m/d H:i:s', $c_time)
            ];
        }

        echo json_encode([
            'success'       => true,
            'doctor_name'   => $doctor_name,
            'unread_count'  => count($formatted),
            'max_log_id'    => $max_log_id,
            'notifications' => $formatted
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => '通知取得エラー: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'mark_read') {
    $log_id = isset($_POST['log_id']) ? (int)$_POST['log_id'] : 0;
    if ($log_id <= 0) {
        echo json_encode(['success' => false, 'message' => '不正なパラメータ']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 対象ログの担当医を取得
        $stmtLog = $pdo->prepare("
            SELECT old_data->>'doctor_name' as old_doc, new_data->>'doctor_name' as new_doc 
            FROM t_schedule_audit_log 
            WHERE log_id = :lid
        ");
        $stmtLog->execute([':lid' => $log_id]);
        $logRow = $stmtLog->fetch(PDO::FETCH_ASSOC);

        $targetDocs = [];
        if ($doctor_name !== '' && $doctor_name !== 'ALL') {
            $targetDocs[] = $doctor_name;
        } else {
            // 全医師ビューで消した場合は、ALL およびその通知の担当医すべてに消込を反映
            $targetDocs[] = 'ALL';
            if ($logRow) {
                if (!empty($logRow['new_doc'])) $targetDocs[] = trim($logRow['new_doc']);
                if (!empty($logRow['old_doc'])) $targetDocs[] = trim($logRow['old_doc']);
            }
        }
        $targetDocs = array_unique(array_filter($targetDocs));

        $stmtIns = $pdo->prepare("
            INSERT INTO t_doctor_notification_read (log_id, doctor_name)
            VALUES (:lid, :doc)
            ON CONFLICT (log_id, doctor_name) DO NOTHING
        ");
        foreach ($targetDocs as $d) {
            $stmtIns->execute([':lid' => $log_id, ':doc' => $d]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '消込エラー: ' . $e->getMessage()]);
        exit;
    }
}

if ($action === 'mark_all_read') {
    $max_log_id = isset($_POST['max_log_id']) ? (int)$_POST['max_log_id'] : 0;
    if ($max_log_id <= 0) {
        echo json_encode(['success' => false, 'message' => '不正なパラメータ']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $doctorsToClear = [];
        if ($doctor_name === '' || $doctor_name === 'ALL') {
            // 全医師ビューでの一括消込は、全登録医師＋ALLを一括で最新IDへ更新！
            $stmtDocs = $pdo->query("SELECT DISTINCT item_value FROM m_input_master WHERE category = 'doctor'");
            $docList = $stmtDocs->fetchAll(PDO::FETCH_COLUMN);
            $doctorsToClear = array_merge(['ALL', '誠吾', '哲也', '義智', '慶太郎'], $docList);
            $doctorsToClear = array_unique(array_filter($doctorsToClear));
        } else {
            $doctorsToClear = [$doctor_name];
        }

        $stmtStatus = $pdo->prepare("
            INSERT INTO t_doctor_notification_status (doctor_name, last_cleared_log_id, last_cleared_at)
            VALUES (:doc, :max_id, CURRENT_TIMESTAMP)
            ON CONFLICT (doctor_name) DO UPDATE 
            SET last_cleared_log_id = GREATEST(t_doctor_notification_status.last_cleared_log_id, EXCLUDED.last_cleared_log_id),
                last_cleared_at = CURRENT_TIMESTAMP
        ");

        $delStmt = $pdo->prepare("
            DELETE FROM t_doctor_notification_read
            WHERE doctor_name = :doc AND log_id <= :max_id
        ");

        foreach ($doctorsToClear as $d) {
            $stmtStatus->execute([':doc' => $d, ':max_id' => $max_log_id]);
            $delStmt->execute([':doc' => $d, ':max_id' => $max_log_id]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '一括消込エラー: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => '未知のアクション']);
