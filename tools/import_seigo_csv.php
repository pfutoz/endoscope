<?php
ini_set('display_errors', "1");
error_reporting(E_ALL);

// 1. データベース接続設定
$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

$message = "";
$error_message = "";

// 2. アップロードされたCSVファイルのデータ処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        
        $tmp_file = $_FILES['csv_file']['tmp_name'];
        
        // WindowsのExcel書き出し(CP932/SJIS)に対応するため読み込み
        $fp = fopen($tmp_file, 'r');
        
        $row_count = 0;
        $insert_count = 0;
        
        // CSVの横軸の列インデックスと時間枠名（scheduled_time）のマッピング
        // ※waku_no 制約エラー対策として、枠ごとに連番用の数値を定義しておきます
        $waku_mapping = [
            2  => ["name" => "12:30",       "no" => 1],
            5  => ["name" => "13:00",       "no" => 2],
            8  => ["name" => "14:30",       "no" => 3],
            11 => ["name" => "15:00",       "no" => 4],
            14 => ["name" => "14:30-15:30", "no" => 5],
            17 => ["name" => "＃1",          "no" => 6],
            20 => ["name" => "＃2",          "no" => 7]
        ];
        
        // トランザクション処理を開始
        $pdo->beginTransaction();
        
        try {
            // 【PHP8対応バグ修正】推奨警告(Deprecated)を回避するため、第3引数(separator)・第4引数(enclosure)・第5引数(escape)を明示的にデフォルト値で渡します
            while (($row = fgetcsv($fp, 0, ",", '"', "\\")) !== FALSE) {
                $row_count++;
                
                // 最初の4行（ヘッダーや注意書き）は読み飛ばす
                if ($row_count <= 4) continue;
                
                // 1列目（インデックス0）から日付を取得
                if (empty($row[0])) continue;
                $exam_date = trim($row[0]);
                
                // 有効な日付形式（/が含まれている）かチェック
                if (strpos($exam_date, '/') === FALSE) continue;
                
                // 各時間枠の列をチェックしてIDがあればインサート
                foreach ($waku_mapping as $col_idx => $waku_info) {
                    if (!isset($row[$col_idx])) continue;
                    
                    $waku_name = $waku_info["name"];
                    $waku_no   = $waku_info["no"]; // 制約エラーを回避する枠番号
                    
                    $patient_id = trim($row[$col_idx]);
                    
                    // Excel特有の「.0」浮動小数のゴミをトリミング
                    $patient_id = preg_replace('/\.0$/', '', $patient_id);
                    
                    // IDが純粋な数字（カルテID）である場合のみ登録対象とする
                    if (!empty($patient_id) && ctype_digit($patient_id)) {
                        
                        // 既存の同一枠データを物理削除して重複を防ぐ
                        $delete_sql = "DELETE FROM t_endoscopy_schedule WHERE exam_date = :exam_date AND scheduled_time = :waku_name AND doctor_name = '小野誠吾'";
                        $del_stmt = $pdo->prepare($delete_sql);
                        $del_stmt->execute([
                            ':exam_date' => $exam_date,
                            ':waku_name' => $waku_name
                        ]);
                        
                        // 【DB制約バグ修正】列 waku_no の非NULL制約違反を回避するため、マッピングした枠番番号（$waku_no）をSQLに確実に追加します
                        $insert_sql = "INSERT INTO t_endoscopy_schedule (
                                            exam_date, scheduled_time, waku_no, patient_id, patient_name, 
                                            doctor_name, exam_type, exam_location, is_canceled
                                       ) VALUES (
                                            :exam_date, :waku_name, :waku_no, :patient_id, '（未取得）', 
                                            '小野誠吾', 'TCF', '内視鏡室', FALSE
                                       )";
                                       
                        $ins_stmt = $pdo->prepare($insert_sql);
                        $ins_stmt->execute([
                            ':exam_date'   => $exam_date,
                            ':waku_name'   => $waku_name,
                            ':waku_no'     => $waku_no, // ここに値を流し込みます
                            ':patient_id'  => $patient_id
                        ]);
                        
                        $insert_count++;
                    }
                }
            }
            
            $pdo->commit();
            fclose($fp);
            
            $message = "インポート処理が正常に完了しました！ 登録件数: {$insert_count} 件";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            fclose($fp);
            $error_message = "処理中にエラーが発生したため中断しました: " . $e->getMessage();
        }
    } else {
        $error_message = "ファイルのアップロードに失敗しました。";
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>予約データ一括インポート</title>
    <style>
        body { font-family: sans-serif; margin: 30px; background-color: #f4f6f9; color: #333; }
        .card { background: #fff; padding: 25px; border-radius: 6px; border: 1px solid #ccc; max-width: 600px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; color: #2c3e50; }
        .form-group { margin-top: 20px; margin-bottom: 20px; }
        .btn { background-color: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 14px; }
        .btn:hover { background-color: #218838; }
        .btn-secondary { background-color: #007bff; text-decoration: none; padding: 8px 16px; color: white; border-radius: 4px; font-size: 14px; font-weight: bold; display: inline-block; margin-top: 15px; }
        .alert { padding: 12px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

<div class="card">
    <h2>誠吾Dr 検査予約状況 CSVインポート</h2>
    <p style="font-size: 13px; color: #666; line-height: 1.4;">
        ローカルにある「検査予約状況のCSVファイル」を選択し、「取り込む」ボタンをクリックしてください。<br>
        ※自動的に 担当医師:小野誠吾 / 検査種別:TCF / 各枠番号 としてシステムへ一括登録されます。
    </p>
    
    <?php if ($message !== ""): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <a href="month_matrix.php?year=2026&month=7" class="btn-secondary">➔ 横型マトリクス画面で確認する</a>
    <?php endif; ?>
    
    <?php if ($error_message !== ""): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data" style="margin-top: 25px;">
        <div class="form-group">
            <label style="font-weight: bold; display: block; margin-bottom: 8px;">CSVファイルを選択：</label>
            <input type="file" name="csv_file" accept=".csv" required style="font-size: 14px;">
        </div>
        <button type="submit" class="btn">⚡ このファイルを取り込む</button>
    </form>
</div>

</body>
</html>