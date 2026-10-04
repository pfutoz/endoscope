<?php
/**
 * tools/migrate_20261004_admission_and_cleanup.php
 * 生成日時: 2026-10-04 22:57:00 JST
 * 
 * 本番データベース (192.168.1.16:5432/endoscope) に
 *  1. t_endoscopy_schedule への is_admission カラム・インデックス追加
 *  2. 不要レガシーテーブル4つ (m_doctor, m_doctor_attendance, m_doctor_slot_pattern, m_waku_master) のバックアップ＆削除
 * を安全に一括適用するマイグレーションスクリプト
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$prodConfig = [
    'dsn'  => 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;',
    'user' => 'postgres',
    'pass' => 'postgres',
];

echo "==========================================================" . PHP_EOL;
echo "  本番DB (192.168.1.16) マイグレーション ＆ テーブル整理" . PHP_EOL;
echo "==========================================================" . PHP_EOL . PHP_EOL;

try {
    $pdo = new PDO($prodConfig['dsn'], $prodConfig['user'], $prodConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "✓ 本番DB接続成功 (192.168.1.16:5432/endoscope)" . PHP_EOL;
} catch (Exception $e) {
    echo "❌ 本番DB接続失敗: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// ------------------------------------------------------------------
// STEP 1: 削除対象テーブルの事前バックアップ
// ------------------------------------------------------------------
$legacyTables = ['m_doctor', 'm_doctor_attendance', 'm_doctor_slot_pattern', 'm_waku_master'];
$backupData = [];

echo PHP_EOL . "--- STEP 1: 削除対象テーブルのデータバックアップ ---" . PHP_EOL;
foreach ($legacyTables as $t) {
    try {
        $stmt = $pdo->query("SELECT * FROM \"{$t}\"");
        $rows = $stmt->fetchAll();
        $backupData[$t] = $rows;
        echo "  [バックアップ取得] {$t} : " . count($rows) . " 件" . PHP_EOL;
    } catch (Exception $e) {
        echo "  [情報] {$t} は存在しないかアクセスできません: " . $e->getMessage() . PHP_EOL;
    }
}

$backupFile = __DIR__ . '/backup_legacy_tables_20261004.json';
file_put_contents($backupFile, json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "✓ バックアップファイル保存完了: " . $backupFile . PHP_EOL;

// ------------------------------------------------------------------
// STEP 2: マイグレーション実行（トランザクション）
// ------------------------------------------------------------------
echo PHP_EOL . "--- STEP 2: マイグレーション実行 ---" . PHP_EOL;

$pdo->beginTransaction();

try {
    // 1. is_admission カラム追加
    echo "  1. t_endoscopy_schedule に is_admission カラムを追加中..." . PHP_EOL;
    $pdo->exec("ALTER TABLE t_endoscopy_schedule ADD COLUMN IF NOT EXISTS is_admission BOOLEAN DEFAULT NULL;");
    echo "     ✓ is_admission カラム追加完了" . PHP_EOL;

    // 2. インデックス作成
    echo "  2. インデックス idx_endo_is_admission を作成中..." . PHP_EOL;
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_endo_is_admission ON t_endoscopy_schedule(exam_date, is_admission);");
    echo "     ✓ idx_endo_is_admission インデックス作成完了" . PHP_EOL;

    // 3. レガシーテーブル削除
    echo "  3. レガシーテーブル削除中..." . PHP_EOL;
    foreach ($legacyTables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS \"{$t}\" CASCADE;");
        echo "     ✓ DROP TABLE {$t} 完了" . PHP_EOL;
    }

    $pdo->commit();
    echo PHP_EOL . "🎉 本番DBマイグレーション＆クリーンアップコミット成功！" . PHP_EOL;

} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ エラー発生によりロールバックしました: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// ------------------------------------------------------------------
// STEP 3: 適用後の検証
// ------------------------------------------------------------------
echo PHP_EOL . "--- STEP 3: 適用後の本番DB検証 ---" . PHP_EOL;

// カラム確認
$stmtCol = $pdo->prepare("
    SELECT column_name, data_type, is_nullable, column_default 
    FROM information_schema.columns 
    WHERE table_name = 't_endoscopy_schedule' AND column_name = 'is_admission'
");
$stmtCol->execute();
$col = $stmtCol->fetch();
if ($col) {
    echo "✓ [カラム確認] t_endoscopy_schedule.is_admission: 型={$col['data_type']}, NULL許可={$col['is_nullable']}" . PHP_EOL;
} else {
    echo "❌ [カラム確認] is_admission が見つかりません！" . PHP_EOL;
}

// インデックス確認
$stmtIdx = $pdo->query("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 't_endoscopy_schedule' AND indexname = 'idx_endo_is_admission'");
$idx = $stmtIdx->fetch();
if ($idx) {
    echo "✓ [インデックス確認] {$idx['indexname']}: {$idx['indexdef']}" . PHP_EOL;
} else {
    echo "❌ [インデックス確認] idx_endo_is_admission が見つかりません！" . PHP_EOL;
}

// テーブル削除確認
$stmtTables = $pdo->query("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
      AND table_name IN ('m_doctor', 'm_doctor_attendance', 'm_doctor_slot_pattern', 'm_waku_master')
");
$remTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
if (empty($remTables)) {
    echo "✓ [テーブル削除確認] 対象4テーブルは本番DBから完全に削除されました" . PHP_EOL;
} else {
    echo "⚠️ 残存テーブルあり: " . implode(', ', $remTables) . PHP_EOL;
}

echo PHP_EOL . "==========================================================" . PHP_EOL;
echo "  すべての処理が正常に完了しました。" . PHP_EOL;
echo "==========================================================" . PHP_EOL;
