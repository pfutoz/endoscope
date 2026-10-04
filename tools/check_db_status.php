<?php
/**
 * tools/check_db_status.php
 * 生成日時: 2026-10-02 13:00
 * 修正履歴:
 *  - 2026-10-02: 開発環境(localhost)と本番環境(192.168.1.16)のendoscope DBスキーマおよび状態比較スクリプト作成
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$environments = [
    'local' => [
        'name' => '開発環境 (localhost)',
        'dsn'  => 'pgsql:host=localhost;port=5432;dbname=endoscope;',
        'user' => 'postgres',
        'pass' => 'postgres',
    ],
    'prod' => [
        'name' => '本番環境 (192.168.1.16)',
        'dsn'  => 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;',
        'user' => 'postgres',
        'pass' => 'postgres',
    ]
];

function getPdo($config) {
    try {
        $pdo = new PDO($config['dsn'], $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3
        ]);
        return $pdo;
    } catch (Exception $e) {
        return null;
    }
}

$pdos = [];
foreach ($environments as $key => $cfg) {
    echo "=== {$cfg['name']} への接続確認 ===" . PHP_EOL;
    $pdo = getPdo($cfg);
    if ($pdo) {
        echo "✓ 接続成功" . PHP_EOL;
        $pdos[$key] = $pdo;
    } else {
        echo "❌ 接続失敗" . PHP_EOL;
    }
    echo PHP_EOL;
}

if (!isset($pdos['prod'])) {
    echo "本番DBに接続できないため、詳細診断を中断します。" . PHP_EOL;
    exit(1);
}

// 共通関数: テーブル一覧取得 (public スキーマ)
function getTables($pdo) {
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
          AND table_type = 'BASE TABLE'
        ORDER BY table_name;
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// 共通関数: テーブルのカラム詳細取得
function getColumns($pdo, $tableName) {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, column_default, is_nullable, character_maximum_length
        FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = :t
        ORDER BY ordinal_position;
    ");
    $stmt->execute([':t' => $tableName]);
    $cols = [];
    foreach ($stmt->fetchAll() as $row) {
        $cols[$row['column_name']] = $row;
    }
    return $cols;
}

// 共通関数: 件数取得
function getRowCount($pdo, $tableName) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM \"{$tableName}\"");
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 'ERR';
    }
}

$localTables = isset($pdos['local']) ? getTables($pdos['local']) : [];
$prodTables = getTables($pdos['prod']);

echo "==========================================================" . PHP_EOL;
echo "1. テーブル一覧とレコード件数比較" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$allTables = array_unique(array_merge($localTables, $prodTables));
sort($allTables);

printf("%-35s | %-15s | %-15s\n", "テーブル名", "開発環境(件数)", "本番環境(件数)");
echo str_repeat("-", 70) . PHP_EOL;

$missingInProd = [];
$missingInLocal = [];

foreach ($allTables as $t) {
    $inLocal = in_array($t, $localTables, true);
    $inProd = in_array($t, $prodTables, true);

    $localCnt = $inLocal ? getRowCount($pdos['local'], $t) : '【未存在】';
    $prodCnt = $inProd ? getRowCount($pdos['prod'], $t) : '【未存在】';

    if (!$inProd) $missingInProd[] = $t;
    if (!$inLocal) $missingInLocal[] = $t;

    printf("%-35s | %-15s | %-15s\n", $t, (string)$localCnt, (string)$prodCnt);
}

echo PHP_EOL;
if (!empty($missingInProd)) {
    echo "⚠️ 本番に存在しないテーブル (開発のみ存在):\n";
    foreach ($missingInProd as $t) {
        echo "   - {$t}\n";
    }
    echo PHP_EOL;
}
if (!empty($missingInLocal)) {
    echo "ℹ️ 開発に存在しないテーブル (本番のみ存在):\n";
    foreach ($missingInLocal as $t) {
        echo "   - {$t}\n";
    }
    echo PHP_EOL;
}

echo "==========================================================" . PHP_EOL;
echo "2. テーブル構造（カラム差分）の比較" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$commonTables = array_intersect($localTables, $prodTables);
$hasColumnDiff = false;

foreach ($commonTables as $t) {
    $localCols = getColumns($pdos['local'], $t);
    $prodCols = getColumns($pdos['prod'], $t);

    $localColNames = array_keys($localCols);
    $prodColNames = array_keys($prodCols);

    $onlyInLocal = array_diff($localColNames, $prodColNames);
    $onlyInProd = array_diff($prodColNames, $localColNames);

    // カラム定義の違い（共通カラムについて）
    $defDiffs = [];
    $commonColNames = array_intersect($localColNames, $prodColNames);
    foreach ($commonColNames as $c) {
        $lc = $localCols[$c];
        $pc = $prodCols[$c];
        if ($lc['data_type'] !== $pc['data_type'] || $lc['is_nullable'] !== $pc['is_nullable']) {
            $defDiffs[] = "   * カラム {$c}: 開発({$lc['data_type']}, null:{$lc['is_nullable']}) vs 本番({$pc['data_type']}, null:{$pc['is_nullable']})";
        }
    }

    if (!empty($onlyInLocal) || !empty($onlyInProd) || !empty($defDiffs)) {
        $hasColumnDiff = true;
        echo "【テーブル: {$t}】に差分があります:" . PHP_EOL;
        if (!empty($onlyInLocal)) {
            echo "  ⚠️ 本番に未適用のカラム (開発環境のみ存在):" . PHP_EOL;
            foreach ($onlyInLocal as $c) {
                $colDef = $localCols[$c];
                echo "     + {$c} ({$colDef['data_type']}, default: " . ($colDef['column_default'] ?? 'NULL') . ", null: {$colDef['is_nullable']})" . PHP_EOL;
            }
        }
        if (!empty($onlyInProd)) {
            echo "  ℹ️ 開発に未適用のカラム (本番環境のみ存在):" . PHP_EOL;
            foreach ($onlyInProd as $c) {
                $colDef = $prodCols[$c];
                echo "     - {$c} ({$colDef['data_type']}, default: " . ($colDef['column_default'] ?? 'NULL') . ", null: {$colDef['is_nullable']})" . PHP_EOL;
            }
        }
        if (!empty($defDiffs)) {
            echo "  ⚠️ 型やNULL制約の相違:" . PHP_EOL;
            foreach ($defDiffs as $d) {
                echo "  {$d}" . PHP_EOL;
            }
        }
        echo PHP_EOL;
    }
}

if (!$hasColumnDiff) {
    echo "✓ 共通テーブルのカラム構造（カラム名・データ型・NULL制約）に差分はありませんでした。" . PHP_EOL;
}
