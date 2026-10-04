<?php
ini_set('display_errors', "1");
error_reporting(E_ALL);

$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$dept = $_POST['department_code'] ?? $_GET['dept'] ?? 'PROCT';

// ----------------------------------------
// 1. 修正モード：時間枠の一括アップデート
// ----------------------------------------
if ($action === 'bulk_update' && isset($_POST['waku'])) {
    try {
        $pdo->beginTransaction();
        
        $sql = "UPDATE m_waku_master 
                SET waku_name = :waku_name, display_order = :display_order 
                WHERE waku_id = :waku_id AND department_code = :dept";
        $stmt = $pdo->prepare($sql);
        
        foreach ($_POST['waku'] as $waku_id => $data) {
            $stmt->execute([
                ':waku_name'     => trim($data['waku_name']),
                ':display_order' => (int)$data['display_order'],
                ':waku_id'       => (int)$data['id'],
                ':dept'          => $dept
            ]);
        }
        
        $pdo->commit();
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=edit&msg=" . urlencode("時間枠の修正内容をすべて正常に更新しました。") . "&error=0");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=edit&msg=" . urlencode("更新エラー: " . $e->getMessage()) . "&error=1");
        exit;
    }
}

// ----------------------------------------
// 2. 追加モード：新規時間枠の登録 (絶対重複しないMAX + 1 強制制御 / Postgres準拠版)
// ----------------------------------------
if ($action === 'insert_waku') {
    $waku_code = isset($_POST['waku_code']) ? trim($_POST['waku_code']) : '';
    $waku_name = isset($_POST['waku_name']) ? trim($_POST['waku_name']) : '';
    $display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
    
    if (empty($waku_code) || empty($waku_name)) {
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=add&msg=" . urlencode("必要な項目を入力してください。") . "&error=1");
        exit;
    }
    
    try {
        $pdo->beginTransaction();

        // 💡【PostgreSQL完全準拠】集約関数を使わずに、全体の最大IDレコードをロックして取得
        $lock_sql = "SELECT waku_id FROM m_waku_master ORDER BY waku_id DESC LIMIT 1 FOR UPDATE";
        $stmt_lock = $pdo->query($lock_sql);
        $row = $stmt_lock->fetch();
        
        $max_id = $row ? (int)$row['waku_id'] : 0;
        $new_id = $max_id + 1;

        // INSERTの実行
        $sql = "INSERT INTO m_waku_master (waku_id, department_code, waku_code, waku_name, display_order) 
                VALUES (:waku_id, :dept, :waku_code, :waku_name, :display_order)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':waku_id'       => $new_id,
            ':dept'          => $dept,
            ':waku_code'     => $waku_code,
            ':waku_name'     => $waku_name,
            ':display_order' => $display_order
        ]);
        
        $pdo->commit();
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=add&msg=" . urlencode("新しい時間枠「{$waku_name}」をID: {$new_id} で正常に追加しました。") . "&error=0");
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=add&msg=" . urlencode("追加エラー: " . $e->getMessage()) . "&error=1");
        exit;
    }
}

// ----------------------------------------
// 3. 削除処理
// ----------------------------------------
if ($action === 'single_delete') {
    $waku_id = isset($_GET['waku_id']) ? (int)$_GET['waku_id'] : 0;
    
    if ($waku_id <= 0) {
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=edit&msg=" . urlencode("無効なIDです。") . "&error=1");
        exit;
    }
    
    try {
        $sql = "DELETE FROM m_waku_master WHERE waku_id = :waku_id AND department_code = :dept";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':waku_id' => $waku_id,
            ':dept'    => $dept
        ]);
        
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=edit&msg=" . urlencode("ID: {$waku_id} の時間枠を削除しました。") . "&error=0");
        exit;
        
    } catch (PDOException $e) {
        header("Location: waku_maintenance.php?dept=" . urlencode($dept) . "&op_mode=edit&msg=" . urlencode("削除エラー: " . $e->getMessage()) . "&error=1");
        exit;
    }
}

header("Location: waku_maintenance.php?dept=" . urlencode($dept));
exit;