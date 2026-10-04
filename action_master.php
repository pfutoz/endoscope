<?php
/**
 * 内視鏡予約管理システム
 * 汎用マスタメンテナンス アクション処理（シーケンス一括修復・監査ログ＆Undo対応版）
 * 生成日時: 2026-09-29 14:32:00 JST
 * 修正履歴:
 *   - 2026-09-01: シーケンス一括修復（fix_sequence）および操作履歴クリーンアップ対応
 *   - 2026-09-29: マスタ変更監査ログ（t_master_audit_log）への自動差分記録、ワンクリックUndo（undo_master）、履歴一括削除（clear_audit_logs）を実装
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host={$host};dbname={$dbname};";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

require_once __DIR__ . '/lib/master_audit_helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// =========================================================================
// 1. 全主要テーブル対象の自動採番シーケンス一括修復処理
// =========================================================================
if ($action === 'fix_sequence') {
    $targets = [
        ['table' => 't_endoscopy_schedule',         'col' => 'schedule_id'],
        ['table' => 'm_input_master',              'col' => 'master_id'],
        ['table' => 'm2_waku',                     'col' => 'id'],
        ['table' => 'm2_doctor_slot_pattern',      'col' => 'id'],
        ['table' => 'm2_doctor_attendance',        'col' => 'id'],
        ['table' => 'm2_doctor_daily_capacity_v2', 'col' => 'id'],
        ['table' => 't_daily_memo',                'col' => 'id'],
    ];

    try {
        $repaired = [];

        foreach ($targets as $t) {
            $table = $t['table'];
            $col   = $t['col'];

            // テーブル存在チェック
            $tblCheck = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :tbl");
            $tblCheck->execute([':tbl' => $table]);
            if (!$tblCheck->fetch()) {
                continue;
            }

            // PostgreSQLのシーケンス名を取得
            $seqStmt = $pdo->prepare("SELECT pg_get_serial_sequence(:tbl, :col)");
            $seqStmt->execute([':tbl' => $table, ':col' => $col]);
            $seqName = $seqStmt->fetchColumn();

            if (!empty($seqName)) {
                // 現在の最大IDを取得
                $maxStmt = $pdo->query("SELECT COALESCE(MAX(\"{$col}\"), 0) AS max_id FROM \"{$table}\"");
                $maxId = (int)$maxStmt->fetchColumn();
                $nextVal = $maxId + 1;

                // 次回採番値を (MAX + 1) に同期
                $setStmt = $pdo->prepare("SELECT setval(:seq, :next_val, false)");
                $setStmt->execute([
                    ':seq'      => $seqName,
                    ':next_val' => $nextVal
                ]);

                $repaired[] = "{$table} (次回: {$nextVal})";
            }
        }

        $msg = "全テーブルのIDシーケンスを正常に同期・修復しました [" . implode(', ', $repaired) . "]";
        header("Location: master_maintenance.php?msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        $errMsg = "シーケンス修復中にエラーが発生しました: " . $e->getMessage();
        header("Location: master_maintenance.php?error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 1-2. 操作履歴（Audit Log）1年以上経過データの安全クリーンアップ
// =========================================================================
if ($action === 'purge_old_audit_logs') {
    require_once __DIR__ . '/lib/audit_helper.php';

    try {
        $deleted_count = purgeOldAuditLogs($pdo, 365);
        $msg = "1年以上経過した古い操作履歴を安全にクリーンアップしました（削除件数: {$deleted_count} 件）";
        header("Location: master_maintenance.php?msg=" . urlencode($msg));
        exit;
    } catch (Exception $e) {
        $errMsg = "操作履歴のクリーンアップ中にエラーが発生しました: " . $e->getMessage();
        header("Location: master_maintenance.php?error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 2. 汎用マスタ 新規アイテム追加
// =========================================================================
if ($action === 'insert_item') {
    $category      = isset($_POST['category']) ? trim($_POST['category']) : '';
    $item_value    = isset($_POST['item_value']) ? trim($_POST['item_value']) : '';
    $display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;

    if (empty($category) || empty($item_value)) {
        $errMsg = "カテゴリ名または表示名が未入力です。";
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=add&error=1&msg=" . urlencode($errMsg));
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO m_input_master (category, item_value, display_order)
            VALUES (:category, :item_value, :display_order)
            RETURNING master_id
        ");
        $stmt->execute([
            ':category'      => $category,
            ':item_value'    => $item_value,
            ':display_order' => $display_order
        ]);
        $new_master_id = (int)$stmt->fetchColumn();

        // 監査ログ記録
        recordMasterAuditLog(
            $pdo,
            'input_master',
            'insert',
            $category,
            (string)$new_master_id,
            $item_value,
            null,
            [
                'master_id'     => $new_master_id,
                'category'      => $category,
                'item_value'    => $item_value,
                'display_order' => $display_order
            ],
            "「{$category}」に新規追加: 「{$item_value}」(表示順: {$display_order})"
        );

        $msg = "「{$item_value}」を「{$category}」カテゴリに新規追加しました。";
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=edit&msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        $errMsg = "アイテム追加エラー: " . $e->getMessage();
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=add&error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 3. 汎用マスタ 一括更新
// =========================================================================
if ($action === 'bulk_update') {
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $items    = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];

    if (empty($category)) {
        header("Location: master_maintenance.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 更新前の状態を取得
        $stmt_cur = $pdo->prepare("SELECT master_id, item_value, display_order FROM m_input_master WHERE category = :cat");
        $stmt_cur->execute([':cat' => $category]);
        $current_rows = [];
        foreach ($stmt_cur->fetchAll() as $r) {
            $current_rows[$r['master_id']] = $r;
        }

        $stmt = $pdo->prepare("
            UPDATE m_input_master 
            SET item_value = :item_value, display_order = :display_order 
            WHERE master_id = :master_id AND category = :category
        ");

        $updatedCount = 0;
        $diff_details = [];
        $old_data = [];
        $new_data = [];

        foreach ($items as $item) {
            $master_id     = isset($item['id']) ? (int)$item['id'] : 0;
            $item_value    = isset($item['item_value']) ? trim($item['item_value']) : '';
            $display_order = isset($item['display_order']) ? (int)$item['display_order'] : 0;

            if ($master_id > 0 && $item_value !== '' && isset($current_rows[$master_id])) {
                $cur = $current_rows[$master_id];
                $has_change = ($cur['item_value'] !== $item_value || (int)$cur['display_order'] !== $display_order);

                if ($has_change) {
                    $stmt->execute([
                        ':item_value'    => $item_value,
                        ':display_order' => $display_order,
                        ':master_id'     => $master_id,
                        ':category'      => $category
                    ]);
                    $updatedCount++;

                    $old_data[] = $cur;
                    $new_data[] = [
                        'master_id'     => $master_id,
                        'category'      => $category,
                        'item_value'    => $item_value,
                        'display_order' => $display_order
                    ];

                    $change_parts = [];
                    if ($cur['item_value'] !== $item_value) {
                        $change_parts[] = "値:「{$cur['item_value']}」➔「{$item_value}」";
                    }
                    if ((int)$cur['display_order'] !== $display_order) {
                        $change_parts[] = "表示順:{$cur['display_order']}➔{$display_order}";
                    }
                    $diff_details[] = "ID:{$master_id} (" . implode(', ', $change_parts) . ")";
                }
            }
        }

        // 差分がある場合のみログ記録
        if ($updatedCount > 0) {
            recordMasterAuditLog(
                $pdo,
                'input_master',
                'update',
                $category,
                null,
                "一括更新 ({$updatedCount}件)",
                $old_data,
                $new_data,
                "「{$category}」一括更新: " . implode('; ', $diff_details)
            );
        }

        $pdo->commit();
        $msg = "「{$category}」カテゴリのアイテム（計 {$updatedCount} 件）を一括更新しました。";
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=edit&msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errMsg = "一括保存エラー: " . $e->getMessage();
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=edit&error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 4. 汎用マスタ 個別削除
// =========================================================================
if ($action === 'single_delete') {
    $master_id = isset($_GET['master_id']) ? (int)$_GET['master_id'] : 0;
    $category  = isset($_GET['category']) ? trim($_GET['category']) : '';

    if ($master_id <= 0) {
        header("Location: master_maintenance.php?category=" . urlencode($category));
        exit;
    }

    try {
        // 削除前レコードを取得
        $stmt_cur = $pdo->prepare("SELECT * FROM m_input_master WHERE master_id = :id");
        $stmt_cur->execute([':id' => $master_id]);
        $old_row = $stmt_cur->fetch();

        $stmt = $pdo->prepare("DELETE FROM m_input_master WHERE master_id = :master_id");
        $stmt->execute([':master_id' => $master_id]);

        if ($old_row) {
            recordMasterAuditLog(
                $pdo,
                'input_master',
                'delete',
                $category,
                (string)$master_id,
                $old_row['item_value'],
                $old_row,
                null,
                "「{$category}」から削除: 「{$old_row['item_value']}」(ID: {$master_id}, 表示順: {$old_row['display_order']})"
            );
        }

        $msg = "アイテム（ID: {$master_id}）を削除しました。";
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=edit&msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        $errMsg = "削除エラー: " . $e->getMessage();
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&op_mode=edit&error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 5. 汎用マスタ ワンクリック復元（Undo）
// =========================================================================
if ($action === 'undo_master') {
    $log_id   = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';

    if ($log_id <= 0) {
        header("Location: master_maintenance.php?category=" . urlencode($category));
        exit;
    }

    try {
        $pdo->beginTransaction();
        $restore_msg = undoMasterAuditLog($pdo, $log_id);
        $pdo->commit();

        $msg = "↩️ 復元完了: " . $restore_msg;
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&msg=" . urlencode($msg));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errMsg = "復元エラー: " . $e->getMessage();
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 6. 汎用マスタ 変更履歴一括削除（クリア）
// =========================================================================
if ($action === 'clear_audit_logs') {
    $category = isset($_POST['category']) ? trim($_POST['category']) : (isset($_GET['category']) ? trim($_GET['category']) : '');

    try {
        $deleted_count = clearMasterAuditLogs($pdo, 'input_master');
        $msg = "汎用マスタの変更履歴を一括削除しました（計 {$deleted_count} 件クリア）。";
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&msg=" . urlencode($msg));
        exit;

    } catch (Exception $e) {
        $errMsg = "履歴クリアエラー: " . $e->getMessage();
        header("Location: master_maintenance.php?category=" . urlencode($category) . "&error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// アクション不一致時のデフォルトリダイレクト
header("Location: master_maintenance.php");
exit;