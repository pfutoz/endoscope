<?php
/**
 * tools/migrate_create_m2_waku_override.php
 * 
 * 生成日時: 2026-10-22
 * 修正履歴:
 *   - 2026-10-22: m2_waku_override テーブル作成マイグレーション適用スクリプト新設
 */

require_once __DIR__ . '/../lib/db.php';

echo "=== m2_waku_override テーブル作成マイグレーション (Local DB) ===" . PHP_EOL;

$pdo = getDbConnection();
if (!$pdo) {
    echo "❌ DB接続失敗" . PHP_EOL;
    exit(1);
}
echo "✓ Local DB接続成功" . PHP_EOL;

$sqlFile = __DIR__ . '/migration_20261022_create_m2_waku_override.sql';
if (!file_exists($sqlFile)) {
    echo "❌ SQLファイルが見つかりません: $sqlFile" . PHP_EOL;
    exit(1);
}

$sql = file_get_contents($sqlFile);

try {
    $pdo->exec($sql);
    echo "✓ マイグレーションSQL実行完了" . PHP_EOL;

    // テーブル確認
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'm2_waku_override'
        ORDER BY ordinal_position
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "【m2_waku_override テーブルカラム確認 (全" . count($columns) . "列)】" . PHP_EOL;
    foreach ($columns as $c) {
        printf("  - %-20s : %-15s (NULL:%s, DEFAULT:%s)\n",
            $c['column_name'],
            $c['data_type'],
            $c['is_nullable'],
            $c['column_default'] ?? 'none'
        );
    }

} catch (Exception $e) {
    echo "❌ マイグレーションエラー: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
