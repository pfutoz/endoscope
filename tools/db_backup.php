<?php
ini_set('display_errors', "1");
error_reporting(E_ALL);

// DB接続設定
$host = 'localhost';
$port = '5432';
$dbname = 'endoscope';
$user = 'postgres';
$password = 'postgres';

// リストア用のパスワード設定
$correct_pin = '2104';

$message = '';
$is_error = false;
$action = $_POST['action'] ?? '';
$input_pin = $_POST['pin'] ?? '';

// --- バックアップ処理（PHPによる直接SQL生成＆クライアントダウンロード） ---
if ($action === 'backup') {
    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        
        $sql_output = "-- 内視鏡室 業務管理システム データベースバックアップ\n";
        $sql_output .= "-- 生成日時: " . date('Y-m-d H:i:s') . "\n\n";
        
        $tables_stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($tables as $table) {
            $sql_output .= "-- テーブル: {$table}\n";
            
            // 各カラムのデータ型をメタデータから取得してboolean型を特定
            $col_stmt = $pdo->prepare("
                SELECT column_name, data_type 
                FROM information_schema.columns 
                WHERE table_name = :table AND table_schema = 'public'
            ");
            $col_stmt->execute([':table' => $table]);
            $col_types = $col_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['カラム名' => 'データ型']
            
            $rows_stmt = $pdo->query("SELECT * FROM \"{$table}\"");
            $rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $values = [];
                
                foreach ($row as $col_name => $val) {
                    if ($val === null) {
                        $values[] = 'NULL';
                    } elseif (isset($col_types[$col_name]) && $col_types[$col_name] === 'boolean') {
                        // boolean型カラムの判定：真とみなせる値以外はすべて明示的に FALSE に倒す
                        if ($val === true || $val === 't' || $val === 'true' || $val === 1 || $val === '1') {
                            $values[] = 'TRUE';
                        } else {
                            $values[] = 'FALSE';
                        }
                    } else {
                        $values[] = $pdo->quote($val);
                    }
                }
                
                $sql_output .= sprintf(
                    "INSERT INTO \"%s\" (%s) VALUES (%s);\n",
                    $table,
                    implode(', ', array_map(function($c){ return "\"{$c}\""; }, $columns)),
                    implode(', ', $values)
                );
            }
            $sql_output .= "\n";
        }
        
        $filename = 'onokai_db_backup_' . date('Ymd_His') . '.sql';
        
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql_output));
        echo $sql_output;
        exit;
        
    } catch (PDOException $e) {
        $message = "バックアップ生成エラー: " . $e->getMessage();
        $is_error = true;
    }
}

// --- リストア処理（PHP/PDOによる既存データクリア＆SQLファイル直接実行） ---
if ($action === 'restore') {
    if ($input_pin !== $correct_pin) {
        $message = "管理パスワードが違います。";
        $is_error = true;
    } elseif (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['backup_file']['tmp_name'];
        
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            
            $sql_content = file_get_contents($tmp_name);
            $queries = array_filter(array_map('trim', explode(';', $sql_content)));
            
            $pdo->beginTransaction();
            
            // 既存データのクリア（初期化）
            $tables_stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $pdo->exec("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
            }
            
            // SQLクエリを順次実行
            foreach ($queries as $query) {
                if (!empty($query) && strpos($query, '--') !== 0) {
                    $pdo->exec($query);
                }
            }
            $pdo->commit();
            
            $message = "データベースのリストア（復元）が正常に完了しました。";
            $is_error = false;
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "リストア中にエラーが発生しました：\n" . $e->getMessage();
            $is_error = true;
        }
    } else {
        $message = "バックアップファイルのアップロードに失敗しました。";
        $is_error = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DBバックアップ・リストア - 内視鏡室 業務管理システム</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 40px 20px; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 40px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); position: relative; }
        .home-icon-btn { position: fixed; top: 15px; right: 15px; z-index: 100; background-color: #2c3e50; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.2); text-decoration: none; }
        .home-icon-btn:hover { background-color: #34495e; transform: scale(1.05); }
        h2 { margin-top: 0; color: #2c3e50; border-bottom: 2px solid #34495e; padding-bottom: 12px; font-size: 24px; }
        h3 { color: #2980b9; margin-top: 30px; font-size: 16px; border-left: 4px solid #3498db; padding-left: 10px; background: #f8fafc; padding-top: 6px; padding-bottom: 6px; }
        .box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px; margin: 15px 0; }
        .warning-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 15px; color: #92400e; font-size: 13px; margin-bottom: 15px; }
        .pin-group { margin-bottom: 15px; background: #fef2f2; padding: 12px; border-radius: 4px; border: 1px solid #fecaca; }
        .pin-group label { font-size: 13px; font-weight: bold; display: block; margin-bottom: 4px; color: #991b1b; }
        .pin-group input { padding: 6px 10px; font-size: 16px; letter-spacing: 2px; width: 120px; border: 1px solid #f87171; border-radius: 4px; }
        button { padding: 10px 20px; color: #fff; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 14px; }
        .btn-backup { background: #3498db; }
        .btn-backup:hover { background: #2980b9; }
        .btn-restore { background: #e74c3c; }
        .btn-restore:hover { background: #c0392b; }
        .msg { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 12px; border-radius: 4px; color: #065f46; margin-bottom: 20px; font-size: 13px; font-weight: bold; white-space: pre-wrap; }
        .error-msg { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .back-link { display: inline-block; margin-bottom: 20px; font-size: 13px; text-decoration: none; color: #3498db; font-weight: bold; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<a href="index.php" class="home-icon-btn" title="システムメニューに戻る">🏠</a>

<div class="container">
    <a href="index.php" class="back-link">◀ システムメニューに戻る</a>
    <h2>データベース バックアップ ＆ リストア</h2>
    
    <p>予約データの保全や移行のためのバックアップ（誰でも取得可能）、およびリストア（要パスワード）を行います。</p>

    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error ? 'error-msg' : '' ?>"><?=$message?></div>
    <?php endif; ?>

    <!-- バックアップフォーム -->
    <div class="box">
        <h3>1. データベースのバックアップ取得</h3>
        <p>現在のデータベース（予約・マスタ情報）をSQLファイルとして直接生成し、手元のPCへダウンロードします。</p>
        <form method="POST">
            <button type="submit" name="action" value="backup" class="btn-backup">今すぐバックアップをダウンロード</button>
        </form>
    </div>

    <!-- リストアフォーム -->
    <div class="box">
        <h3>2. データベースのリストア（復元）</h3>
        <div class="warning-box">
            <strong>⚠️ ご注意：</strong> リストアを実行すると、既存のデータが一度初期化（クリア）されて上書きされます。実行前に必ず現在の最新バックアップを確保してください。
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="pin-group">
                <label for="pin">リストア用 管理パスワード：</label>
                <input type="password" id="pin" name="pin" maxlength="4" placeholder="4桁" required>
            </div>
            <div style="margin-bottom: 15px;">
                <label for="backup_file" style="font-size:13px; font-weight:bold; display:block; margin-bottom:6px;">バックアップファイル (.sql) を選択：</label>
                <input type="file" id="backup_file" name="backup_file" accept=".sql" required>
            </div>
            <button type="submit" name="action" value="restore" class="btn-restore" onclick="return confirm('本当にデータベースを初期化・復元しますか？');">アップロードして復元を実行</button>
        </form>
    </div>

    <div class="footer" style="text-align: center; font-size: 12px; color: #94a3b8; margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
        内視鏡室 業務管理システム DBメンテナンス | 医療法人小野会
    </div>
</div>

</body>
</html>