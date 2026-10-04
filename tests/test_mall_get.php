<?php
/**
 * 生成日時: 2026-08-31 13:05:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\test_mall_get.php
 * ファイル名: test_mall_get.php
 * 
 * MALL3 患者データ & メモ取得 単体テストスクリプト
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("❌ データベース接続失敗");
}

$patient_id = isset($_GET['patient_id']) ? trim((string)$_GET['patient_id']) : '74819';
$patient_data = null;
$memo_data = null;
$error_log = [];

if ($patient_id !== '') {
    // 1. 患者基本情報の取得テスト (テーブル候補を順次確認)
    $candidate_tables = ['v_mall_patient', 't_mall_patient', 'm_patient', 't_patient_master'];
    $found_table = null;

    foreach ($candidate_tables as $tbl) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$tbl} WHERE patient_id = :id LIMIT 1");
            $stmt->execute([':id' => $patient_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $patient_data = $row;
                $found_table = $tbl;
                break;
            }
        } catch (PDOException $e) {
            $error_log[] = "テーブル [{$tbl}] 照会スキップ/エラー: " . $e->getMessage();
        }
    }

    // 2. MALL同期メモの取得テスト
    try {
        $stmt_m = $pdo->prepare("SELECT * FROM t_mall_memo WHERE patient_id = :id LIMIT 1");
        $stmt_m->execute([':id' => $patient_id]);
        $memo_data = $stmt_m->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_log[] = "t_mall_memo 取得エラー: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MALL3 データ取得テスト</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f8fafc; color: #1e293b; }
        .box { background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; margin-bottom: 16px; }
        h2 { margin-top: 0; font-size: 16px; color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; }
        pre { background: #0f172a; color: #38bdf8; padding: 12px; border-radius: 4px; overflow-x: auto; }
        .success { color: #16a34a; font-weight: bold; }
        .fail { color: #dc2626; font-weight: bold; }
        input[type="text"] { padding: 6px 10px; font-size: 14px; width: 160px; }
        button { padding: 6px 14px; font-size: 14px; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<div class="box">
    <h2>🔍 MALL3 患者情報取得テストフォーム</h2>
    <form method="GET" action="test_mall_get.php">
        <label>カルテID（患者番号）: </label>
        <input type="text" name="patient_id" value="<?= htmlspecialchars($patient_id, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">取得実行</button>
    </form>
</div>

<div class="box">
    <h2>1. 患者基本情報 照会結果</h2>
    <?php if ($patient_data): ?>
        <p class="success">✅ 取得成功 (参照テーブル/ビュー: <?= htmlspecialchars($found_table, ENT_QUOTES, 'UTF-8') ?>)</p>
        <pre><?= htmlspecialchars(print_r($patient_data, true), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php else: ?>
        <p class="fail">❌ 患者データが見つかりませんでした (patient_id: <?= htmlspecialchars($patient_id, ENT_QUOTES, 'UTF-8') ?>)</p>
    <?php endif; ?>
</div>

<div class="box">
    <h2>2. MALL同期メモ (t_mall_memo) 照会結果</h2>
    <?php if ($memo_data): ?>
        <p class="success">✅ 取得成功</p>
        <pre><?= htmlspecialchars(print_r($memo_data, true), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php else: ?>
        <p class="fail">❌ メモデータが存在しないか取得できませんでした</p>
    <?php endif; ?>
</div>

<?php if (!empty($error_log)): ?>
<div class="box" style="border-color: #fca5a5; background: #fef2f2;">
    <h2 style="color: #991b1b;">⚠️ 内部エラー・スキップログ</h2>
    <pre style="background: #450a0a; color: #fca5a5;"><?= htmlspecialchars(implode("\n", $error_log), ENT_QUOTES, 'UTF-8') ?></pre>
</div>
<?php endif; ?>

</body>
</html>