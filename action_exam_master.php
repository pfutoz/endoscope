<?php
/**
 * 生成日時: 2026-10-26 10:05:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\action_exam_master.php
 * ファイル名: action_exam_master.php
 * 
 * 検査種別マスターメンテナンス アクション処理（監査ログ＆Undo対応）
 * 修正履歴:
 *   - 2026-10-26: 初版新設（m_exam_type_group 専用アクションプロバイダ）
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
// 1. 新規検査種別の追加 (insert)
// =========================================================================
if ($action === 'insert') {
    $exam_type     = isset($_POST['exam_type']) ? trim($_POST['exam_type']) : '';
    $slot_group    = isset($_POST['slot_group']) ? trim($_POST['slot_group']) : 'TCF';
    $display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 50;
    $is_selectable = !empty($_POST['is_selectable']);

    if (empty($exam_type)) {
        $errMsg = "検査種別名が未入力です。";
        header("Location: exam_master_maintenance.php?op_mode=add&error=1&msg=" . urlencode($errMsg));
        exit;
    }

    try {
        // 重複チェック
        $stmt_chk = $pdo->prepare("SELECT id FROM m_exam_type_group WHERE exam_type = :t");
        $stmt_chk->execute([':t' => $exam_type]);
        if ($stmt_chk->fetch()) {
            $errMsg = "検査種別「{$exam_type}」は既に登録されています。一覧から編集してください。";
            header("Location: exam_master_maintenance.php?op_mode=add&error=1&msg=" . urlencode($errMsg));
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO m_exam_type_group (exam_type, slot_group, display_order, is_selectable, is_active, created_at)
            VALUES (:t, :grp, :ord, :sel, TRUE, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            ':t'   => $exam_type,
            ':grp' => $slot_group,
            ':ord' => $display_order,
            ':sel' => $is_selectable ? 1 : 0
        ]);
        $new_id = (int)$stmt->fetchColumn();

        // 監査ログ
        recordMasterAuditLog(
            $pdo,
            'exam_type_group',
            'insert',
            'exam_type',
            (string)$new_id,
            $exam_type,
            null,
            [
                'id'            => $new_id,
                'exam_type'     => $exam_type,
                'slot_group'    => $slot_group,
                'display_order' => $display_order,
                'is_selectable' => $is_selectable,
                'is_active'     => true
            ],
            "新規検査種別を追加: 「{$exam_type}」(大分類: {$slot_group}, 表示順: {$display_order}, 予約選択肢: " . ($is_selectable ? '表示' : '非表示') . ")"
        );

        $msg = "検査種別「{$exam_type}」（大分類: {$slot_group}）を新設登録しました。";
        header("Location: exam_master_maintenance.php?op_mode=edit&msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        $errMsg = "追加エラー: " . $e->getMessage();
        header("Location: exam_master_maintenance.php?op_mode=add&error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 2. 一括更新 (bulk_update)
// =========================================================================
if ($action === 'bulk_update') {
    $items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [];

    try {
        $pdo->beginTransaction();

        $stmt_cur = $pdo->query("SELECT id, exam_type, slot_group, display_order, is_selectable, is_active FROM m_exam_type_group");
        $current_rows = [];
        foreach ($stmt_cur->fetchAll() as $r) {
            $current_rows[$r['id']] = $r;
        }

        $stmt_upd = $pdo->prepare("
            UPDATE m_exam_type_group 
            SET exam_type = :t, slot_group = :grp, display_order = :ord, is_selectable = :sel, is_active = :act 
            WHERE id = :id
        ");

        $updatedCount = 0;
        $diff_summaries = [];

        foreach ($items as $item) {
            $id            = isset($item['id']) ? (int)$item['id'] : 0;
            $exam_type     = isset($item['exam_type']) ? trim($item['exam_type']) : '';
            $slot_group    = isset($item['slot_group']) ? trim($item['slot_group']) : 'TCF';
            $display_order = isset($item['display_order']) ? (int)$item['display_order'] : 100;
            $is_selectable = !empty($item['is_selectable']);
            $is_active     = !empty($item['is_active']);

            if ($id > 0 && $exam_type !== '' && isset($current_rows[$id])) {
                $cur = $current_rows[$id];
                $has_change = (
                    $cur['exam_type'] !== $exam_type ||
                    $cur['slot_group'] !== $slot_group ||
                    (int)$cur['display_order'] !== $display_order ||
                    (bool)$cur['is_selectable'] !== $is_selectable ||
                    (bool)$cur['is_active'] !== $is_active
                );

                if ($has_change) {
                    $stmt_upd->execute([
                        ':t'   => $exam_type,
                        ':grp' => $slot_group,
                        ':ord' => $display_order,
                        ':sel' => $is_selectable ? 1 : 0,
                        ':act' => $is_active ? 1 : 0,
                        ':id'  => $id
                    ]);
                    $updatedCount++;
                    $diff_summaries[] = "「{$exam_type}」(ID:{$id}, グループ:{$slot_group}, 順:{$display_order})";
                }
            }
        }

        if ($updatedCount > 0) {
            recordMasterAuditLog(
                $pdo,
                'exam_type_group',
                'update',
                'exam_type',
                'multiple',
                "{$updatedCount}件の検査種別",
                $current_rows,
                $items,
                "検査種別マスタを一括更新 ({$updatedCount}件): " . implode(', ', array_slice($diff_summaries, 0, 3)) . ($updatedCount > 3 ? ' 等' : '')
            );
        }

        $pdo->commit();
        $msg = "検査種別マスタを {$updatedCount} 件更新しました。";
        header("Location: exam_master_maintenance.php?msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errMsg = "一括保存エラー: " . $e->getMessage();
        header("Location: exam_master_maintenance.php?error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 3. 個別削除 (delete)
// =========================================================================
if ($action === 'delete') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id <= 0) {
        header("Location: exam_master_maintenance.php");
        exit;
    }

    try {
        $stmt_cur = $pdo->prepare("SELECT * FROM m_exam_type_group WHERE id = :id");
        $stmt_cur->execute([':id' => $id]);
        $old_row = $stmt_cur->fetch();

        if ($old_row) {
            $stmt = $pdo->prepare("DELETE FROM m_exam_type_group WHERE id = :id");
            $stmt->execute([':id' => $id]);

            recordMasterAuditLog(
                $pdo,
                'exam_type_group',
                'delete',
                'exam_type',
                (string)$id,
                $old_row['exam_type'],
                $old_row,
                null,
                "検査種別を削除: 「{$old_row['exam_type']}」(ID: {$id}, グループ: {$old_row['slot_group']})"
            );

            $msg = "検査種別「{$old_row['exam_type']}」（ID: {$id}）を削除しました。";
        } else {
            $msg = "該当する検査種別が見つかりませんでした。";
        }

        header("Location: exam_master_maintenance.php?msg=" . urlencode($msg));
        exit;

    } catch (PDOException $e) {
        $errMsg = "削除エラー: " . $e->getMessage();
        header("Location: exam_master_maintenance.php?error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

// =========================================================================
// 4. ワンクリック復元 (undo)
// =========================================================================
if ($action === 'undo') {
    $log_id = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;

    if ($log_id <= 0) {
        header("Location: exam_master_maintenance.php");
        exit;
    }

    try {
        $pdo->beginTransaction();
        $restore_msg = undoMasterAuditLog($pdo, $log_id);
        $pdo->commit();

        $msg = "↩️ 復元完了: " . $restore_msg;
        header("Location: exam_master_maintenance.php?msg=" . urlencode($msg));
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errMsg = "復元エラー: " . $e->getMessage();
        header("Location: exam_master_maintenance.php?error=1&msg=" . urlencode($errMsg));
        exit;
    }
}

header("Location: exam_master_maintenance.php");
exit;
