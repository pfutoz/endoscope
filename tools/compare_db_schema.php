<?php
// tools/compare_db_schema.php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== ローカルDB vs 本番DB (192.168.1.16) スキーマ比較検証 ===" . PHP_EOL . PHP_EOL;

// 接続設定
$localConfig = [
    'dsn'  => 'pgsql:host=localhost;port=5432;dbname=endoscope;',
    'user' => 'postgres',
    'pass' => 'postgres',
    'name' => 'ローカルDB (localhost)'
];

$prodConfig = [
    'dsn'  => 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;',
    'user' => 'postgres',
    'pass' => 'postgres',
    'name' => '本番DB (192.168.1.16)'
];

try {
    $localPdo = new PDO($localConfig['dsn'], $localConfig['user'], $localConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✓ ローカルDB接続成功" . PHP_EOL;
} catch (Exception $e) {
    echo "❌ ローカルDB接続失敗: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

try {
    $prodPdo = new PDO($prodConfig['dsn'], $prodConfig['user'], $prodConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✓ 本番DB接続成功" . PHP_EOL . PHP_EOL;
} catch (Exception $e) {
    echo "❌ 本番DB接続失敗: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 1. テーブル一覧の取得
function getTables(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
          AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$localTables = getTables($localPdo);
$prodTables  = getTables($prodPdo);

echo "【1. テーブル一覧の比較】" . PHP_EOL;
echo "ローカルテーブル数: " . count($localTables) . " / 本番テーブル数: " . count($prodTables) . PHP_EOL;

$onlyInLocal = array_diff($localTables, $prodTables);
$onlyInProd  = array_diff($prodTables, $localTables);

if (empty($onlyInLocal) && empty($onlyInProd)) {
    echo "  ✓ テーブル一覧は完全一致しています (" . count($localTables) . " テーブル)" . PHP_EOL;
} else {
    if (!empty($onlyInLocal)) {
        echo "  ⚠️ ローカルにのみ存在: " . implode(', ', $onlyInLocal) . PHP_EOL;
    }
    if (!empty($onlyInProd)) {
        echo "  ⚠️ 本番にのみ存在: " . implode(', ', $onlyInProd) . PHP_EOL;
    }
}
echo PHP_EOL;

// 2. カラム構造の取得と詳細比較
function getTableColumns(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT 
            table_name,
            column_name,
            data_type,
            character_maximum_length,
            is_nullable,
            column_default
        FROM information_schema.columns
        WHERE table_schema = 'public'
        ORDER BY table_name, ordinal_position
    ");
    $cols = [];
    while ($row = $stmt->fetch()) {
        $t = $row['table_name'];
        $c = $row['column_name'];
        $cols[$t][$c] = [
            'type'     => $row['data_type'],
            'length'   => $row['character_maximum_length'],
            'nullable' => $row['is_nullable'],
            'default'  => $row['column_default']
        ];
    }
    return $cols;
}

$localCols = getTableColumns($localPdo);
$prodCols  = getTableColumns($prodPdo);

echo "【2. カラム構造・型・制約の比較】" . PHP_EOL;

$allCommonTables = array_intersect($localTables, $prodTables);
$diffCount = 0;
$missingInProdCols = [];
$missingInLocalCols = [];
$typeDiffCols = [];

foreach ($allCommonTables as $table) {
    $lCols = $localCols[$table] ?? [];
    $pCols = $prodCols[$table] ?? [];

    $lColNames = array_keys($lCols);
    $pColNames = array_keys($pCols);

    // ローカルにあって本番にないカラム
    $onlyL = array_diff($lColNames, $pColNames);
    if (!empty($onlyL)) {
        foreach ($onlyL as $c) {
            $missingInProdCols[] = "{$table}.{$c} ({$lCols[$c]['type']})";
            $diffCount++;
        }
    }

    // 本番にあってローカルにないカラム
    $onlyP = array_diff($pColNames, $lColNames);
    if (!empty($onlyP)) {
        foreach ($onlyP as $c) {
            $missingInLocalCols[] = "{$table}.{$c} ({$pCols[$c]['type']})";
            $diffCount++;
        }
    }

    // 共通カラムの型・デフォルト値比較
    $commonC = array_intersect($lColNames, $pColNames);
    foreach ($commonC as $c) {
        $lc = $lCols[$c];
        $pc = $pCols[$c];

        // 型の比較
        if ($lc['type'] !== $pc['type']) {
            $typeDiffCols[] = "{$table}.{$c}: Local({$lc['type']}) vs Prod({$pc['type']})";
            $diffCount++;
        }
    }
}

if ($diffCount === 0) {
    echo "  ✓ 全カラム定義が完全一致しています！" . PHP_EOL;
} else {
    echo "  ⚠️ カラム差異が {$diffCount} 箇所検出されました:" . PHP_EOL;
    if (!empty($missingInProdCols)) {
        echo "  ・本番DBに不足しているカラム (ローカルのみ):" . PHP_EOL;
        foreach ($missingInProdCols as $item) {
            echo "      - {$item}" . PHP_EOL;
        }
    }
    if (!empty($missingInLocalCols)) {
        echo "  ・ローカルDBに不足しているカラム (本番のみ):" . PHP_EOL;
        foreach ($missingInLocalCols as $item) {
            echo "      - {$item}" . PHP_EOL;
        }
    }
    if (!empty($typeDiffCols)) {
        echo "  ・型定義の差異:" . PHP_EOL;
        foreach ($typeDiffCols as $item) {
            echo "      - {$item}" . PHP_EOL;
        }
    }
}
echo PHP_EOL;

// 3. 特に注目の t_patient_medical_alert の詳細確認
echo "【3. 注目テーブル: t_patient_medical_alert の詳細カラム比較】" . PHP_EOL;
if (isset($localCols['t_patient_medical_alert']) && isset($prodCols['t_patient_medical_alert'])) {
    $l = $localCols['t_patient_medical_alert'];
    $p = $prodCols['t_patient_medical_alert'];
    $allKeys = array_unique(array_merge(array_keys($l), array_keys($p)));
    foreach ($allKeys as $k) {
        $lHas = isset($l[$k]) ? "あり ({$l[$k]['type']})" : "❌ なし";
        $pHas = isset($p[$k]) ? "あり ({$p[$k]['type']})" : "❌ なし";
        $mark = (isset($l[$k]) && isset($p[$k])) ? "✓" : "⚠️ 差異あり";
        printf("  %-25s : Local=%-18s | Prod=%-18s [%s]\n", $k, $lHas, $pHas, $mark);
    }
}
echo PHP_EOL;

// 4. t_schedule_audit_log / t_doctor_notification_status / t_endoscopy_schedule の確認
echo "【4. 主要テーブルのレコード件数確認】" . PHP_EOL;
$checkTables = ['t_endoscopy_schedule', 't_patient_medical_alert', 't_schedule_audit_log', 'm2_doctor', 'm2_waku'];
foreach ($checkTables as $t) {
    if (in_array($t, $localTables) && in_array($t, $prodTables)) {
        $lCount = $localPdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $pCount = $prodPdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "  ・{$t}: Local={$lCount}件 | Prod={$pCount}件" . PHP_EOL;
    }
}
