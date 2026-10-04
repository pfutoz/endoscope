<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\test_mall_images.php
 * ファイル名: test_mall_images.php
 * 概要: 患者No.81622のカルテ添付画像（ファイルサーバー上のPNG）表示テストプログラム
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

$patient_no = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : '81622';

// 1. MALL3 SQL Server 接続設定
$serverName = "192.168.1.77";
$dbName     = "his00";
$uid        = "sa";
$pwd        = "Kochi-cl";

// 2. 画像ファイルサーバー設定
$image_dir  = "\\\\192.168.1.77\\ImageMall3\\Image";
$net_user   = "Administrator";
$net_pass   = "Kochi-cl";

// Windowsネットワーク共有認証の接続確認・実行
$auth_cmd = "net use \\\\192.168.1.77\\ImageMall3 {$net_pass} /USER:{$net_user} 2>&1";
@exec($auth_cmd, $net_output, $net_ret);

$db_error = '';
$patient = null;
$karte_list = [];
$found_images = [];

try {
    $dsn = "sqlsrv:server={$serverName};Database={$dbName};TrustServerCertificate=1;ApplicationIntent=ReadOnly;";
    $conn = new PDO($dsn, $uid, $pwd, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $conn->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");

    // 患者情報取得
    $stmt_p = $conn->prepare("
        SELECT PatientID, PatientNo, PatientName, PatientKana, CONVERT(varchar(10), BirthDay, 120) AS BirthDayStr 
        FROM M_Patient 
        WHERE PatientNo = :patient_no
    ");
    $stmt_p->execute([':patient_no' => $patient_no]);
    $patient = $stmt_p->fetch();

    if ($patient) {
        $patient_id = (int)$patient['PatientID'];

        // 患者に紐づく全KarteIDを取得
        $stmt_k = $conn->prepare("
            SELECT KarteID, CONVERT(varchar(10), ConsultDate, 120) AS ConsultDateStr
            FROM T_Karte
            WHERE PatientID = :pid
            ORDER BY ConsultDate DESC, KarteID DESC
        ");
        $stmt_k->execute([':pid' => $patient_id]);
        $karte_list = $stmt_k->fetchAll();

        // 各KarteIDに対してファイルサーバーをglob検索
        if (is_dir($image_dir)) {
            foreach ($karte_list as $k) {
                $kid = $k['KarteID'];
                $cdate = $k['ConsultDateStr'];
                
                // パターン: {KarteID}_*.png
                $pattern = $image_dir . "\\" . $kid . "_*.png";
                $files = glob($pattern);

                if (!empty($files)) {
                    foreach ($files as $file_path) {
                        $file_name = basename($file_path);
                        $file_size = filesize($file_path);
                        $file_time = date("Y-m-d H:i:s", filemtime($file_path));

                        // Webブラウザ表示用にBase64変換
                        $raw_data = file_get_contents($file_path);
                        $base64 = 'data:image/png;base64,' . base64_encode($raw_data);

                        $found_images[] = [
                            'karte_id'     => $kid,
                            'consult_date' => $cdate,
                            'file_name'    => $file_name,
                            'file_size'    => round($file_size / 1024, 1) . ' KB',
                            'file_time'    => $file_time,
                            'src'          => $base64
                        ];
                    }
                }
            }
        } else {
            $db_error = "画像共有フォルダ ({$image_dir}) にアクセスできません。アクセス権またはネットワーク接続を確認してください。";
        }
    }
} catch (PDOException $e) {
    $db_error = 'DB接続エラー: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>MALLカルテ添付画像 テスト表示 - <?= htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background-color: #f1f5f9; color: #1e293b; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 24px; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        h2 { margin-top: 0; border-bottom: 2px solid #0284c7; padding-bottom: 8px; color: #0f172a; font-size: 18px; }
        
        .search-box { display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 16px; }
        .input-text { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; width: 140px; font-weight: bold; }
        .btn { padding: 6px 14px; border: none; border-radius: 4px; background: #0284c7; color: #fff; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #0369a1; }

        .patient-header { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; display: flex; gap: 16px; align-items: center; }
        .patient-name { font-size: 16px; font-weight: bold; color: #1d4ed8; }

        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 16px; font-weight: bold; }

        /* 画像ギャラリー表示 */
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; margin-top: 14px; }
        .image-card { border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
        .image-header { background: #f8fafc; padding: 6px 10px; font-size: 11px; font-weight: bold; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; color: #475569; }
        .image-wrapper { padding: 10px; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 220px; }
        .image-wrapper img { max-width: 100%; max-height: 280px; height: auto; border: 1px solid #e2e8f0; border-radius: 4px; background: #fff; cursor: pointer; transition: transform 0.15s; }
        .image-wrapper img:hover { transform: scale(1.02); }
        .image-footer { padding: 6px 10px; font-size: 10.5px; color: #64748b; border-top: 1px solid #e2e8f0; background: #fff; }
    </style>
</head>
<body>

<div class="container">
    <h2>🖼️ MALL電子カルテ 添付画像テスト表示</h2>

    <form method="GET" action="test_mall_images.php" class="search-box">
        <label style="font-weight:bold;">カルテ番号 (PatientNo):</label>
        <input type="text" name="patient_no" value="<?= htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8') ?>" class="input-text" required>
        <button type="submit" class="btn">🔍 検索・画像表示</button>
        <span style="font-size:11px; color:#64748b;">(※デフォルト: 81622)</span>
    </form>

    <?php if (!empty($db_error)): ?>
        <div class="alert-error"><?= htmlspecialchars($db_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($patient): ?>
        <div class="patient-header">
            <div>カルテNo: <strong><?= htmlspecialchars($patient['PatientNo'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="patient-name"><?= htmlspecialchars($patient['PatientName'], ENT_QUOTES, 'UTF-8') ?> 様</div>
            <div>(<?= htmlspecialchars($patient['PatientKana'] ?? '', ENT_QUOTES, 'UTF-8') ?>)</div>
            <div>生年月日: <?= htmlspecialchars($patient['BirthDayStr'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
            <div style="margin-left:auto; font-size:11px; color:#64748b;">カルテ件数: <?= count($karte_list) ?>件</div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:15px; color:#0f172a;">
                📷 検出された添付画像: <span style="color:#0284c7; font-weight:bold;"><?= count($found_images) ?></span> 枚
            </h3>
            <span style="font-size:11px; color:#64748b;">参照元: \\192.168.1.77\ImageMall3\Image</span>
        </div>

        <?php if (empty($found_images)): ?>
            <p style="text-align:center; padding:40px; color:#94a3b8; border:1px dashed #cbd5e1; border-radius:6px; margin-top:14px;">
                この患者のカルテIDに一致する画像ファイルは見つかりませんでした。
            </p>
        <?php else: ?>
            <div class="image-grid">
                <?php foreach ($found_images as $img): ?>
                    <div class="image-card">
                        <div class="image-header">
                            <span>📅 受診日: <?= htmlspecialchars($img['consult_date'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span>KarteID: #<?= (int)$img['karte_id'] ?></span>
                        </div>
                        <div class="image-wrapper">
                            <a href="<?= $img['src'] ?>" target="_blank" title="クリックで別タブ原寸表示">
                                <img src="<?= $img['src'] ?>" alt="<?= htmlspecialchars($img['file_name'], ENT_QUOTES, 'UTF-8') ?>">
                            </a>
                        </div>
                        <div class="image-footer">
                            <div>📄 <strong><?= htmlspecialchars($img['file_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                            <div style="display:flex; justify-content:space-between; margin-top:2px;">
                                <span>容量: <?= $img['file_size'] ?></span>
                                <span>更新: <?= $img['file_time'] ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif (empty($db_error)): ?>
        <p style="text-align:center; padding:30px; color:#94a3b8;">該当する患者が見つかりませんでした。</p>
    <?php endif; ?>
</div>

</body>
</html>