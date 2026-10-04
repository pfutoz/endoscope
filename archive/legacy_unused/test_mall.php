<?php
// エラーをすべて画面に表示する設定
ini_set('display_errors', 1);
error_reporting(E_ALL);

$patient_no = isset($_POST['patient_no']) ? trim($_POST['patient_no']) : '';
$result_message = '';
$patient_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $patient_no !== '') {
    // MALL3 (SQL Server) 接続設定
    $serverName = "192.168.1.77"; 
    $dbName     = "his00";
    $uid        = "sa";
    $pwd        = "Kochi-cl";

    $result_message .= "【1】SQL Server への接続を試みます...<br>";
    
    try {
        $dsn = "sqlsrv:server=$serverName;Database=$dbName;TrustServerCertificate=1;";
        $conn = new PDO($dsn, $uid, $pwd, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $result_message .= "<span style='color:green; font-weight:bold;'>成功: 電子カルテ（MALL3）に無事接続できました！</span><br><br>";
        
        // 患者情報の検索
        $result_message .= "【2】M_Patient から 患者ID: {$patient_no} を検索します...<br>";
        $sql = "SELECT PatientName, BirthDay, Sex FROM M_Patient WHERE PatientNo = :patient_no";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':patient_no' => $patient_no]);
        $patient_data = $stmt->fetch();
        
        if ($patient_data) {
            $result_message .= "<span style='color:green; font-weight:bold;'>成功: 患者データが見つかりました。</span><br>";
        } else {
            $result_message .= "<span style='color:orange;'>警告: 接続は成功しましたが、M_Patient 内に ID {$patient_no} の患者がいません。</span><br>";
        }
        
    } catch (PDOException $e) {
        $result_message .= "<span style='color:red; font-weight:bold;'>接続失敗エラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</span><br>";
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MALL3 電子カルテ連携 テスト画面</title>
    <style>
        body { font-family: sans-serif; margin: 30px; background-color: #f0f4f8; }
        .box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccc; max-width: 600px; margin-bottom: 20px; }
        .log { background: #333; color: #fff; padding: 15px; font-family: monospace; border-radius: 5px; font-size: 13px; line-height: 1.5; }
        table { border-collapse: collapse; margin-top: 15px; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #e6f2ff; }
    </style>
</head>
<body>

<h2>MALL3（電子カルテ）疎通テスト</h2>
<div class="box">
    <form method="POST" action="">
        <label for="patient_no" style="font-weight:bold;">実在する患者ID（数値）：</label>
        <input type="text" id="patient_no" name="patient_no" value="<?=htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8')?>" required placeholder="例: 10001">
        <button type="submit">接続＆照会テスト実行</button>
    </form>
</div>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <h3>診断ログ</h3>
    <div class="log"><?=$result_message?></div>

    <?php if ($patient_data): ?>
        <h3>取得データ結果</h3>
        <div class="box">
            <table>
                <tr><th>項目</th><th>取得値</th></tr>
                <tr><td>患者氏名 (PatientName)</td><td><strong><?=htmlspecialchars($patient_data['PatientName'], ENT_QUOTES, 'UTF-8')?></strong></td></tr>
                <tr><td>生年月日 (BirthDay)</td><td><?=htmlspecialchars($patient_data['BirthDay'] ?? '未登録', ENT_QUOTES, 'UTF-8')?></td></tr>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>