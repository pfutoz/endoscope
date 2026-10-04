<?php
ini_set('display_errors', "1");
error_reporting(E_ALL);
set_time_limit(600); // タイムアウト対策（10分）

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

// 2. 表示・更新対象とする「年月」の取得（初期値は現在の年・月）
$current_year  = (int)date('Y');
$current_month = (int)date('n');

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : $current_year;
$month = isset($_GET['month']) ? (int)$_GET['month'] : $current_month;

// 実行フラグの確認（ボタンが押された時だけ同期を走らせる）
$execute = isset($_GET['execute']) && $_GET['execute'] === '1';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>電子カルテ情報一括同期システム</title>
    <style>
        body { font-family: sans-serif; margin: 30px; background-color: #f4f6f9; color: #333; }
        .card { background: #fff; padding: 25px; border-radius: 6px; border: 1px solid #ccc; max-width: 750px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        h2 { margin-top: 0; color: #2c3e50; display: flex; align-items: center; gap: 8px; }
        .control-group { display: flex; gap: 10px; align-items: center; background: #f8f9fa; padding: 15px; border: 1px solid #ddd; border-radius: 4px; margin-top: 15px; }
        select { padding: 6px 12px; font-size: 14px; border-radius: 4px; border: 1px solid #ccc; font-weight: bold; }
        .btn { background-color: #007bff; color: white; border: none; padding: 7px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 14px; }
        .btn:hover { background-color: #0069d9; }
        .btn-run { background-color: #e67e22; }
        .btn-run:hover { background-color: #d35400; }
        .btn-secondary { background-color: #6c757d; text-decoration: none; padding: 7px 16px; color: white; border-radius: 4px; font-size: 14px; font-weight: bold; }
        .btn-secondary:hover { background-color: #5a6268; }
        .log-area { background: #fff; padding: 20px; border-radius: 6px; border: 1px solid #ccc; max-width: 750px; height: 400px; overflow-y: auto; font-family: monospace; font-size: 13px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
        .success-line { color: green; margin: 3px 0; }
        .error-line { color: red; margin: 3px 0; font-weight: bold; }
    </style>
</head>
<body>

<div class="card">
    <h2>🔄 電子カルテ (MALL3) 情報一括同期システム</h2>
    <p style="font-size: 13px; color: #666; margin: 0; line-height: 1.4;">
        システム内に登録されている「患者ID」のみを正として、電子カルテ側から最新の氏名・生年月日・各種レイヤーメモを1ヶ月分まるごと自動巡回して一括アップデートします。
    </p>
    
    <form action="" method="GET">
        <div class="control-group">
            <strong>📅 対象年月の指定：</strong>
            
            <select name="year">
                <?php for($y = $current_year - 2; $y <= $current_year + 2; $y++): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?> 年</option>
                <?php endfor; ?>
            </select>
            
            <select name="month">
                <?php for($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= $m ?> 月</option>
                <?php endfor; ?>
            </select>
            
            <input type="hidden" name="execute" value="1">
            <button type="submit" class="btn btn-run">⚡ 選択した月の同期を実行する</button>
            <a href="month_matrix.php?year=<?= $year ?>&month=<?= $month ?>" class="btn-secondary">➔ マトリクスに戻る</a>
        </div>
    </form>
</div>

<?php if ($execute): ?>
    <div class="log-area">
        <?php
        echo "<div>[情報] {$year}年{$month}月の有効な患者IDを抽出しています...</div>";
        flush();

        // 指定された年月の有効な patient_id を重複なく抽出
        $sql_patients = "SELECT DISTINCT patient_id 
                         FROM t_endoscopy_schedule 
                         WHERE EXTRACT(YEAR FROM exam_date) = :year 
                           AND EXTRACT(MONTH FROM exam_date) = :month 
                           AND patient_id IS NOT NULL 
                           AND patient_id != ''
                           AND is_canceled = FALSE";

        $stmt_patients = $pdo->prepare($sql_patients);
        $stmt_patients->execute([':year' => $year, ':month' => $month]);
        $patient_ids = $stmt_patients->fetchAll(PDO::FETCH_COLUMN);

        if (empty($patient_ids)) {
            echo "<div class='error-line'>➔ [終了] 指定された年月には、有効な患者IDデータが存在しませんでした。</div>";
            echo "</div>";
            exit;
        }

        echo "<div>[確認] 同期対象の総患者数: " . count($patient_ids) . " 名。順次通信を開始します。</div>";
        echo "<hr style='border-style: dashed; border-color: #ccc;'>";
        flush();

        $success_count = 0;

        foreach ($patient_ids as $pid) {
            // A) 基本情報（氏名・生年月日）の取得
            $patient_url = "http://localhost/endoscope/get_mall_patient.php?patient_no=" . urlencode($pid);
            $patient_res = @file_get_contents($patient_url);
            $patient_data = json_decode($patient_res, true);
            
            $p_name = null;
            $b_day = null;
            
            if ($patient_data && isset($patient_data['success']) && $patient_data['success'] === true) {
                $p_name = $patient_data['patient_name'] ?? null;
                $b_day  = $patient_data['birthday'] ?? null;
            }
            
            // B) 電子カルテメモのリアルタイム同期をキック
            $memo_url = "http://localhost/endoscope/sync_mall_memo.php?patient_id=" . urlencode($pid);
            @file_get_contents($memo_url); // sync_mall_memo.php内部で t_mall_memo へ自動UPSERTされます

            // C) 取得した正確な「氏名」「生年月日」を予約テーブルへ書き戻し
            if (!empty($p_name)) {
                $update_sql = "UPDATE t_endoscopy_schedule 
                               SET patient_name = :p_name, 
                                   birthday = :b_day
                               WHERE patient_id = :pid 
                                 AND EXTRACT(YEAR FROM exam_date) = :year 
                                 AND EXTRACT(MONTH FROM exam_date) = :month";
                                 
                $up_stmt = $pdo->prepare($update_sql);
                $up_stmt->execute([
                    ':p_name' => $p_name,
                    ':b_day'  => !empty($b_day) ? substr(trim($b_day), 0, 10) : null,
                    ':pid'    => $pid,
                    ':year'   => $year,
                    ':month'  => $month
                ]);
                
                echo "<div class='success-line'>📌 ID: {$pid} ➔ 同期成功: <strong>" . htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8') . "</strong> 様 (" . ($b_day ? substr(trim($b_day),0,10) : '生年月日なし') . ")</div>";
                $success_count++;
            } else {
                echo "<div class='error-line'>❌ ID: {$pid} ➔ 電子カルテ側に該当する基本情報が存在しません。</div>";
            }
            
            flush(); // 1件ごとに処理状況をスクロール領域へシュッとリアルタイム出力
        }

        echo "<hr style='border-style: dashed; border-color: #ccc;'>";
        echo "<div style='font-weight: bold; color: #007bff;'>[完了] 全ての処理が正常に終了しました。同期成功: {$success_count} / " . count($patient_ids) . " 名</div>";
        ?>
    </div>
<?php endif; ?>

</body>
</html>