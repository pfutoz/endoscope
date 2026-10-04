<?php
/**
 * 生成日時: 2026-08-31 13:25:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\patient_search.php
 * ファイル名: patient_search.php
 * 
 * 患者検索画面（MALL直接検索）
 * 修正: 2026-08-31
 *   - 右上のデバッグスイッチ（チェックボックス）を廃止
 *   - debug_config.json の debug_mode 設定と連動して検索SQL・デバッグ情報を表示
 *   - 親画面（modal_edit.php / month_matrix_v2.php）へのコールバック連携
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// デバッグ設定読み込み
$debug_mode = false;
$config_file = __DIR__ . '/debug_config.json';
if (file_exists($config_file)) {
    $conf = json_decode(file_get_contents($config_file), true);
    if (!empty($conf['debug_mode'])) {
        $debug_mode = true;
    }
}
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $debug_mode = true;
}

$serverName = "192.168.1.77";
$dbName     = "his00";
$uid        = "sa";
$pwd        = "Kochi-cl";

$patient_no = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : '';
$name_kana  = isset($_GET['name_kana']) ? trim($_GET['name_kana']) : '';
$address    = isset($_GET['address']) ? trim($_GET['address']) : '';
$tel        = isset($_GET['tel']) ? trim($_GET['tel']) : '';

$results = [];
$too_many = false;
$debug = [];

$has_keyword = ($patient_no !== '' || $name_kana !== '' || $address !== '' || $tel !== '');

if ($has_keyword) {
    try {
        $dsn = "sqlsrv:server=$serverName;Database=$dbName;TrustServerCertificate=1;ApplicationIntent=ReadOnly;";
        $conn = new PDO($dsn, $uid, $pwd, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $conn->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
        $debug['connection'] = 'OK';
        
        $conditions = [];
        $params = [];
        
        if ($patient_no !== '') {
            $conditions[] = "P.PatientNo = ?";
            $params[] = $patient_no;
        }
        
        if ($name_kana !== '') {
            $conditions[] = "(P.PatientName LIKE ? OR P.PatientKana LIKE ?)";
            $params[] = '%' . $name_kana . '%';
            $params[] = '%' . $name_kana . '%';
        }
        
        if ($address !== '') {
            $conditions[] = "(A.Add1 LIKE ? OR A.Add2 LIKE ?)";
            $params[] = '%' . $address . '%';
            $params[] = '%' . $address . '%';
        }
        
        if ($tel !== '') {
            $conditions[] = "A.Tel LIKE ?";
            $params[] = '%' . $tel . '%';
        }
        
        $where_clause = implode(' AND ', $conditions);
        $debug['conditions'] = $conditions;
        $debug['params'] = $params;
        
        $sql = "
            SELECT TOP 101
                P.PatientNo,
                P.PatientName,
                P.PatientKana,
                CONVERT(varchar(10), P.BirthDay, 120) AS BirthDayStr,
                ISNULL(A.Add1, '') + ISNULL(A.Add2, '') AS Address,
                A.Tel
            FROM M_Patient P
            LEFT JOIN (
                SELECT 
                    PatientID,
                    Add1,
                    Add2,
                    Tel,
                    ROW_NUMBER() OVER (PARTITION BY PatientID ORDER BY AddRank) AS rn
                FROM M_PatientAdd
                WHERE AddRank = 0
            ) A ON P.PatientID = A.PatientID AND A.rn = 1
            WHERE P.DisplayFlag = 1
              AND ({$where_clause})
            ORDER BY P.PatientKana ASC
        ";
        $debug['sql'] = $sql;
        
        $stmt = $conn->prepare($sql);
        
        foreach ($params as $index => $value) {
            $stmt->bindParam($index + 1, $params[$index]);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll();
        $debug['result_count'] = count($results);
        
        if (count($results) > 100) {
            $too_many = true;
            $results = array_slice($results, 0, 100);
        }
        
    } catch (PDOException $e) {
        $debug['error'] = $e->getMessage();
        $debug['error_code'] = $e->getCode();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>患者検索（MALL連携）</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            padding: 16px;
            background: #f4f6f9;
            margin: 0;
            font-size: 13px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 20px 24px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }
        .header-row h2 {
            margin: 0;
            font-size: 17px;
            color: #0f172a;
        }
        .debug-badge {
            background: #334155;
            color: #38bdf8;
            font-family: Consolas, monospace;
            font-size: 11px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .search-form { margin-bottom: 16px; }
        .search-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .search-row label {
            font-weight: bold;
            font-size: 12px;
            min-width: 80px;
            color: #334155;
        }
        .search-row input {
            flex: 1;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 13px;
        }
        .search-row input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.15);
        }
        .search-row input.id-input { max-width: 180px; flex: none; }
        .search-row input.tel-input { max-width: 200px; flex: none; }
        .search-actions {
            display: flex;
            gap: 8px;
            margin-top: 6px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .search-actions button {
            padding: 6px 20px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            transition: background 0.15s;
        }
        .search-actions button:hover { background: #1d4ed8; }
        .search-actions button.clear-btn { background: #64748b; }
        .search-actions button.clear-btn:hover { background: #475569; }
        .search-actions button.close-btn { background: #64748b; }
        .search-actions button.close-btn:hover { background: #475569; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            text-align: left;
        }
        th {
            background: #f8fafc;
            font-weight: bold;
            font-size: 11px;
            color: #475569;
        }
        tbody tr {
            cursor: pointer;
            transition: background 0.15s;
        }
        tbody tr:hover { background: #eff6ff; }
        
        .too-many {
            color: #dc2626;
            font-weight: bold;
            padding: 8px 12px;
            background: #fef2f2;
            border-radius: 4px;
            border: 1px solid #fca5a5;
            margin-bottom: 10px;
            font-size: 12px;
        }
        .no-result {
            color: #94a3b8;
            text-align: center;
            padding: 30px 20px;
        }
        .result-count {
            color: #64748b;
            font-size: 11px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .debug-box {
            background: #0f172a;
            color: #38bdf8;
            font-family: Consolas, Monaco, monospace;
            font-size: 11px;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            overflow: auto;
            max-height: 250px;
            white-space: pre-wrap;
            word-break: break-all;
            border: 1px solid #334155;
        }
        .debug-box .label { color: #fbbf24; font-weight: bold; }
        .debug-box .sql-text { color: #4ade80; }
        .hint {
            color: #94a3b8;
            font-size: 11px;
            margin-left: 90px;
            margin-top: -4px;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
<div class="container">
    
    <div class="header-row">
        <h2>🔍 患者検索（MALL連携）</h2>
        <?php if ($debug_mode): ?>
            <span class="debug-badge">🐛 DEBUG ON</span>
        <?php endif; ?>
    </div>
    
    <form method="GET" action="" class="search-form" id="searchForm">
        <?php if ($debug_mode): ?>
            <input type="hidden" name="debug" value="1">
        <?php endif; ?>
        <div class="search-row">
            <label for="patient_no">カルテNo</label>
            <input type="text" name="patient_no" id="patient_no" class="id-input"
                   value="<?=htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8')?>" 
                   placeholder="例: 50000（完全一致）">
        </div>
        <div class="search-row">
            <label for="name_kana">氏名／カナ</label>
            <input type="text" name="name_kana" id="name_kana"
                   value="<?=htmlspecialchars($name_kana, ENT_QUOTES, 'UTF-8')?>" 
                   placeholder="例: やまだ、ヤマダ（部分一致）">
        </div>
        <div class="search-row">
            <label for="address">住所</label>
            <input type="text" name="address" id="address"
                   value="<?=htmlspecialchars($address, ENT_QUOTES, 'UTF-8')?>" 
                   placeholder="例: 高知市（部分一致）">
        </div>
        <div class="search-row">
            <label for="tel">電話番号</label>
            <input type="text" name="tel" id="tel" class="tel-input"
                   value="<?=htmlspecialchars($tel, ENT_QUOTES, 'UTF-8')?>" 
                   placeholder="例: 088（部分一致）">
        </div>
        <div class="hint">※ 複数入力した場合は AND 条件で絞り込みます</div>
        
        <div class="search-actions">
            <button type="submit">🔍 検索</button>
            <button type="button" class="clear-btn" onclick="clearSearch()">クリア</button>
            <button type="button" class="close-btn" onclick="window.close();">閉じる</button>
        </div>
    </form>

    <!-- デバッグボックス（debug_config.json の debug_mode が true の場合に表示） -->
    <?php if ($debug_mode && !empty($debug) && $has_keyword): ?>
    <div class="debug-box" id="debugBox">
        <div class="label">■ 検索SQL & デバッグ情報</div>
        <div><span class="label">実行SQL:</span> <span class="sql-text"><?=htmlspecialchars($debug['sql'] ?? '', ENT_QUOTES, 'UTF-8')?></span></div>
        <div><span class="label">バインドパラメータ:</span> <?=htmlspecialchars(json_encode($debug['params'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8')?></div>
        <div><span class="label">取得件数:</span> <?=htmlspecialchars((string)($debug['result_count'] ?? 0), ENT_QUOTES, 'UTF-8')?></div>
        <?php if (!empty($debug['error'])): ?>
            <div style="color:#f87171;"><span class="label" style="color:#f87171;">エラー:</span> <?=htmlspecialchars($debug['error'], ENT_QUOTES, 'UTF-8')?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($has_keyword): ?>
        <?php if ($too_many): ?>
            <div class="too-many">
                ⚠️ 検索条件に該当する患者が <strong>100件以上</strong> あります。検索条件を絞り込んでください。
            </div>
        <?php endif; ?>
        
        <?php if (empty($results)): ?>
            <div class="no-result">該当する患者が見つかりませんでした</div>
        <?php else: ?>
            <div class="result-count">該当患者: <?=count($results)?>件</div>
            <table>
                <thead>
                    <tr>
                        <th style="width:12%;">カルテNo</th>
                        <th style="width:18%;">氏名</th>
                        <th style="width:15%;">カナ</th>
                        <th style="width:15%;">生年月日</th>
                        <th style="width:25%;">住所</th>
                        <th style="width:15%;">電話番号</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): 
                        $address_str = trim($row['Address'] ?? '');
                        $tel_str = trim($row['Tel'] ?? '');
                        if (empty($address_str)) $address_str = '---';
                        if (empty($tel_str)) $tel_str = '---';
                    ?>
                        <tr onclick="selectPatient('<?=htmlspecialchars($row['PatientNo'], ENT_QUOTES, 'UTF-8')?>', '<?=htmlspecialchars($row['PatientName'], ENT_QUOTES, 'UTF-8')?>', '<?=htmlspecialchars($row['BirthDayStr'] ?? '', ENT_QUOTES, 'UTF-8')?>', '<?=htmlspecialchars($row['PatientKana'] ?? '', ENT_QUOTES, 'UTF-8')?>')">
                            <td><strong><?=htmlspecialchars($row['PatientNo'], ENT_QUOTES, 'UTF-8')?></strong></td>
                            <td><?=htmlspecialchars($row['PatientName'], ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($row['PatientKana'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($row['BirthDayStr'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($address_str, ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($tel_str, ENT_QUOTES, 'UTF-8')?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <div class="no-result" style="padding:40px 20px; border:1px dashed #cbd5e1; border-radius:4px; background:#fff;">
            📝 検索条件を入力して「検索」をクリックしてください
        </div>
    <?php endif; ?>

</div>

<script>
function clearSearch() {
    document.getElementById('patient_no').value = '';
    document.getElementById('name_kana').value = '';
    document.getElementById('address').value = '';
    document.getElementById('tel').value = '';
}

function selectPatient(patientNo, patientName, birthday, kana) {
    if (window.opener && window.opener.selectPatientFromSearch) {
        window.opener.selectPatientFromSearch(patientNo, patientName, birthday, kana);
        window.close();
    } else {
        alert('元の画面が見つかりません。患者情報を手動で入力してください。');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const inputs = document.querySelectorAll('input[type="text"]');
    inputs.forEach(function(input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchForm').submit();
            }
        });
    });
});
</script>
</body>
</html>