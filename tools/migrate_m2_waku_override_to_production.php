<?php
/**
 * tools/migrate_m2_waku_override_to_production.php
 * 本番データベース (192.168.1.16:5432/endoscope) に
 * m2_waku_override テーブルおよびインデックスを安全に適用するスクリプト
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$prodConfig = [
    'dsn'  => 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;',
    'user' => 'postgres',
    'pass' => 'postgres',
];

echo "=== 本番DB (192.168.1.16) m2_waku_override テーブル新設マイグレーション ===" . PHP_EOL;

try {
    $pdo = new PDO($prodConfig['dsn'], $prodConfig['user'], $prodConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✓ 本番DB接続成功 (192.168.1.16:5432/endoscope)" . PHP_EOL;
} catch (Exception $e) {
    echo "❌ 本番DB接続失敗: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 既存テーブル確認
$stmt = $pdo->query("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' AND table_name = 'm2_waku_override'
");
$exists = $stmt->fetch();

if ($exists) {
    echo "ℹ️ m2_waku_override テーブルは既に本番DBに存在します。" . PHP_EOL;
} else {
    echo "m2_waku_override テーブルを作成します..." . PHP_EOL;
    $sqlFile = __DIR__ . '/migration_20261022_create_m2_waku_override.sql';
    if (!file_exists($sqlFile)) {
        die("❌ SQLファイルが存在しません: $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);

    try {
        $pdo->exec($sql);
        echo "✓ マイグレーションSQL実行完了 (テーブル・インデックス作成成功)" . PHP_EOL;
    } catch (Exception $e) {
        die("❌ マイグレーションエラー: " . $e->getMessage() . "\n");
    }
}

// カラム確認
$stmt = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_name = 'm2_waku_override'
    ORDER BY ordinal_position
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo PHP_EOL . "【本番DB m2_waku_override テーブルカラム確認 (全" . count($columns) . "列)】" . PHP_EOL;
foreach ($columns as $c) {
    printf("  - %-20s : %-15s (NULL:%s, DEFAULT:%s)\n",
        $c['column_name'],
        $c['data_type'],
        $c['is_nullable'],
        $c['column_default'] ?? 'none'
    );
}

echo PHP_EOL . "=== 本番DBマイグレーション完了 ===" . PHP_EOL;
