<?php
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

$search_date = isset($_GET['date']) ? $_GET['date'] : '2026-07-01';
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内視鏡予約一覧（<?=$search_date?>）</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f9f9f9; position: relative; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #fff; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        .waku-header { font-weight: bold; background-color: #e6f2ff; }
        .canceled { text-decoration: line-through; color: #999; }
        .search-box { margin-bottom: 20px; padding: 10px; background: #eee; inline-block; }
        
        /* 右上固定のメニュー戻るアイコン用スタイル */
        .home-icon-btn {
            position: fixed;
            top: 15px;
            right: 15px;
            z-index: 100;
            background-color: #2c3e50;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: background-color 0.2s, transform 0.2s;
            text-decoration: none;
        }
        .home-icon-btn:hover {
            background-color: #34495e;
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<a href="index.php" class="home-icon-btn" title="システムメニューに戻る">🏠</a>

<h2>内視鏡検査 予定一覧</h2>

<div class="search-box">
    <form method="GET" action="">
        <label for="date">検査日を選択：</label>
        <input type="date" id="date" name="date" value="<?=htmlspecialchars($search_date, ENT_QUOTES, 'UTF-8')?>">
        <button type="submit">検索</button>
    </form>
</div>

<?php
$sql = "SELECT * FROM public.t_endoscopy_schedule  
        WHERE exam_date = :exam_date AND is_canceled = FALSE 
        ORDER BY waku_no ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([':exam_date' => $search_date]);
$schedules = $stmt->fetchAll();

if (empty($schedules)) {
    echo "<p>指定された日（" . htmlspecialchars($search_date, ENT_QUOTES, 'UTF-8') . "）の予約はありません。</p>";
} else {
    echo "<table>";
    echo "<thead>
            <tr>
                <th>枠No</th>
                <th>予定時刻</th>
                <th>来院時刻</th>
                <th>カルテID</th>
                <th>患者名</th>
                <th>種別</th>
                <th>担当医</th>
                <th>実施場所</th>
                <th>備考</th>
            </tr>
          </thead>
          <tbody>";

    foreach ($schedules as $row) {
        $tr_class = empty($row['patient_name']) ? 'style="background-color: #fff0f0;"' : '';
        
        echo "<tr {$tr_class}>";
        echo "<td>" . htmlspecialchars($row['waku_no'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['scheduled_time'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['arrival_time'] ?? '---', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['patient_id'] ?? '---', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['patient_name'] ?? '（空き枠/休止）', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['exam_type'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['doctor_name'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['exam_location'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
}
?>

</body>
</html>