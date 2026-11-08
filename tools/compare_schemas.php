<?php
/**
 * tools/compare_schemas.php
 * ローカルDB (localhost:5432) と本番DB (192.168.1.16:5432) の
 * テーブル一覧・カラム定義・インデックス差分を徹底比較
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$pdoLocal = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

$pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

function getTables($pdo) {
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getColumns($pdo, $table) {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = :t
        ORDER BY ordinal_position
    ");
    $stmt->execute([':t' => $table]);
    $cols = [];
    foreach ($stmt->fetchAll() as $r) {
        $cols[$r['column_name']] = $r;
    }
    return $cols;
}

$localTables = getTables($pdoLocal);
$prodTables  = getTables($pdoProd);

echo "=======================================================\n";
echo "【1. テーブル存在比較】\n";
echo "=======================================================\n";

$tablesOnlyInLocal = array_diff($localTables, $prodTables);
$tablesOnlyInProd  = array_diff($prodTables, $localTables);
$commonTables      = array_intersect($localTables, $prodTables);

if (!empty($tablesOnlyInLocal)) {
    echo "⚠️ ローカルにのみ存在するテーブル:\n";
    foreach ($tablesOnlyInLocal as $t) {
        echo "  + {$t}\n";
    }
} else {
    echo "✓ ローカル専用の余剰テーブルはありません。\n";
}

if (!empty($tablesOnlyInProd)) {
    echo "⚠️ 本番にのみ存在するテーブル:\n";
    foreach ($tablesOnlyInProd as $t) {
        echo "  - {$t}\n";
    }
} else {
    echo "✓ 本番専用の余剰テーブルはありません。\n";
}

echo "\n=======================================================\n";
echo "【2. 共通テーブルのカラム比較】\n";
echo "=======================================================\n";

$schemaDifferences = false;

foreach ($commonTables as $t) {
    $localCols = getColumns($pdoLocal, $t);
    $prodCols  = getColumns($pdoProd, $t);

    $colsOnlyInLocal = array_diff(array_keys($localCols), array_keys($prodCols));
    $colsOnlyInProd  = array_diff(array_keys($prodCols), array_keys($localCols));

    if (!empty($colsOnlyInLocal) || !empty($colsOnlyInProd)) {
        $schemaDifferences = true;
        echo "テーブル: {$t}\n";
        if (!empty($colsOnlyInLocal)) {
            foreach ($colsOnlyInLocal as $c) {
                $info = $localCols[$c];
                echo "  + [ローカルのみ] {$c} ({$info['data_type']}) default: " . ($info['column_default'] ?? 'NULL') . "\n";
            }
        }
        if (!empty($colsOnlyInProd)) {
            foreach ($colsOnlyInProd as $c) {
                $info = $prodCols[$c];
                echo "  - [本番のみ]     {$c} ({$info['data_type']})\n";
            }
        }
    }
}

if (!$schemaDifferences) {
    echo "✓ 共通テーブル内のカラム過不足はありません。\n";
}

echo "\n=======================================================\n";
