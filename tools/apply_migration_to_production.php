<?php
// tools/apply_migration_to_production.php
/**
 * 本番データベース (192.168.1.16:5432/endoscope) に
 * オン資新設3カラム (has_diabetes, has_psychotropic, has_nsaids) を安全に適用するスクリプト
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$prodConfig = [
    'dsn'  => 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;',
    'user' => 'postgres',
    'pass' => 'postgres',
];

echo "=== 本番DB (192.168.1.16) スキーマ更新スクリプト ===" . PHP_EOL;

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

// 現在のカラム確認
$stmt = $pdo->query("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_name = 't_patient_medical_alert'
");
$existingCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "現在のカラム数: " . count($existingCols) . PHP_EOL;

$colsToAdd = [
    'has_diabetes'     => 'BOOLEAN NOT NULL DEFAULT FALSE',
    'has_psychotropic' => 'BOOLEAN NOT NULL DEFAULT FALSE',
    'has_nsaids'       => 'BOOLEAN NOT NULL DEFAULT FALSE'
];

$pdo->beginTransaction();

try {
    foreach ($colsToAdd as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $sql = "ALTER TABLE t_patient_medical_alert ADD COLUMN IF NOT EXISTS {$col} {$def}";
            $pdo->exec($sql);
            echo "  + 追加: {$col} ({$def})" . PHP_EOL;
        } else {
            echo "  = 既に存在: {$col}" . PHP_EOL;
        }
    }

    $pdo->commit();
    echo "✓ 本番DBマイグレーション完了 (コミット成功)" . PHP_EOL . PHP_EOL;

    // 更新後の確認
    $stmt = $pdo->query("
        SELECT column_name, data_type, column_default, is_nullable
        FROM information_schema.columns
        WHERE table_name = 't_patient_medical_alert'
          AND column_name IN ('has_antithrombotic', 'has_diabetes', 'has_psychotropic', 'has_nsaids', 'has_allergy', 'has_infection', 'has_cold_fever')
        ORDER BY ordinal_position
    ");
    $rows = $stmt->fetchAll();
    echo "【本番DBの全7リスクカラムの確認】" . PHP_EOL;
    foreach ($rows as $r) {
        printf("  - %-20s : %s (default: %s, null: %s)\n", $r['column_name'], $r['data_type'], $r['column_default'], $r['is_nullable']);
    }

} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ エラー発生 (ロールバックしました): " . $e->getMessage() . PHP_EOL;
    exit(1);
}
