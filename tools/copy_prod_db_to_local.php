<?php
/**
 * tools/copy_prod_db_to_local.php
 * 本番データベース (192.168.1.16:5432/endoscope) から開発ローカルDB (localhost:5432/endoscope) へ
 * 全データを安全に複製・同期するスクリプト
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$prodHost = '192.168.1.16';
$prodPort = '5432';
$prodDb   = 'endoscope';
$prodUser = 'postgres';
$prodPass = 'postgres';

$localHost = 'localhost';
$localPort = '5432';
$localDb   = 'endoscope';
$localUser = 'postgres';
$localPass = 'postgres';

$backupDir = __DIR__ . '/../backup';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// PostgreSQL コマンドのパス探索
function findPgBin($binName) {
    $candidates = [
        "C:\\Program Files\\PostgreSQL\\18\\bin\\{$binName}.exe",
        "C:\\Program Files\\PostgreSQL\\17\\bin\\{$binName}.exe",
        "C:\\Program Files\\PostgreSQL\\16\\bin\\{$binName}.exe",
        "C:\\Program Files\\PostgreSQL\\15\\bin\\{$binName}.exe",
        $binName
    ];
    foreach ($candidates as $cand) {
        if ($cand === $binName || file_exists($cand)) {
            return $cand;
        }
    }
    return $binName;
}

$pgDump    = findPgBin('pg_dump');
$pgRestore = findPgBin('pg_restore');
$psql      = findPgBin('psql');

echo "====================================================================\n";
echo "🏥 本番DB ➔ ローカルDB データ複製ツール\n";
echo "実行日時: " . date('Y-m-d H:i:s') . "\n";
echo "本番サーバー: {$prodHost}:{$prodPort}/{$prodDb}\n";
echo "ローカル環境: {$localHost}:{$localPort}/{$localDb}\n";
echo "使用ツール  : {$pgDump}\n";
echo "====================================================================\n\n";

putenv("PGPASSWORD={$prodPass}");

// ── 1. 接続確認 ──
echo "[STEP 1] データベース接続確認...\n";
try {
    $pdoProd = new PDO("pgsql:host={$prodHost};port={$prodPort};dbname={$prodDb};", $prodUser, $prodPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "  ✓ 本番DB接続OK\n";
} catch (Exception $e) {
    die("❌ 本番DBへの接続に失敗しました: " . $e->getMessage() . "\n");
}

try {
    $pdoLocal = new PDO("pgsql:host={$localHost};port={$localPort};dbname={$localDb};", $localUser, $localPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "  ✓ ローカルDB接続OK\n";
} catch (Exception $e) {
    die("❌ ローカルDBへの接続に失敗しました: " . $e->getMessage() . "\n");
}

$timestamp = date('Ymd_His');

// ── 2. 現在のローカルDBの安全バックアップ ──
echo "\n[STEP 2] 作業前ローカルDBのバックアップ退避...\n";
$localBackupDump = "{$backupDir}/local_endoscope_backup_{$timestamp}.dump";
$cmdBackupLocal = "\"{$pgDump}\" -h {$localHost} -p {$localPort} -U {$localUser} -d {$localDb} -F c -b -f \"{$localBackupDump}\" 2>&1";
exec($cmdBackupLocal, $outLocal, $rcLocal);
if ($rcLocal === 0 && file_exists($localBackupDump) && filesize($localBackupDump) > 0) {
    $mb = round(filesize($localBackupDump) / 1024 / 1024, 2);
    echo "  ✓ ローカルDB退避完了: {$localBackupDump} ({$mb} MB)\n";
} else {
    echo "  ⚠️ ローカルDB退避で警告またはエラーが発生しました (終了コード: {$rcLocal})\n";
    echo "     " . implode("\n     ", $outLocal) . "\n";
    // 続行可能か判断
}

// ── 3. 本番DBからのフルダンプ取得 ──
echo "\n[STEP 3] 本番DBからのフルダンプ取得...\n";
$prodDumpFile = "{$backupDir}/prod_endoscope_backup_{$timestamp}.dump";
$prodSqlFile  = "{$backupDir}/prod_endoscope_backup_{$timestamp}.sql";

// カスタムダンプ取得
$cmdProdDump = "\"{$pgDump}\" -h {$prodHost} -p {$prodPort} -U {$prodUser} -d {$prodDb} -F c -b -v -f \"{$prodDumpFile}\" 2>&1";
echo "  pg_dump実行中 (カスタム形式)... \n";
exec($cmdProdDump, $outDump, $rcDump);

if ($rcDump !== 0 || !file_exists($prodDumpFile) || filesize($prodDumpFile) === 0) {
    echo "❌ 本番DBのダンプ取得に失敗しました (終了コード: {$rcDump})\n";
    echo implode("\n", $outDump) . "\n";
    exit(1);
}
$dumpMb = round(filesize($prodDumpFile) / 1024 / 1024, 2);
echo "  ✓ 本番DBダンプ完了: {$prodDumpFile} ({$dumpMb} MB)\n";

// テキスト形式SQLも保存（可読性・監査用）
$cmdProdSql = "\"{$pgDump}\" -h {$prodHost} -p {$prodPort} -U {$prodUser} -d {$prodDb} -F p -b -f \"{$prodSqlFile}\" 2>&1";
exec($cmdProdSql, $outSql, $rcSql);
if ($rcSql === 0 && file_exists($prodSqlFile)) {
    $sqlMb = round(filesize($prodSqlFile) / 1024 / 1024, 2);
    echo "  ✓ 本番DB SQL出力完了: {$prodSqlFile} ({$sqlMb} MB)\n";
}

// ── 4. ローカルDBの再作成 ──
echo "\n[STEP 4] ローカルDB (endoscope) の初期化・再作成...\n";
$pdoLocal = null; // 切断
try {
    $pdoPostgres = new PDO("pgsql:host={$localHost};port={$localPort};dbname=postgres;", $localUser, $localPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // 既存セッションの強制切断
    $pdoPostgres->exec("
        SELECT pg_terminate_backend(pid) 
        FROM pg_stat_activity 
        WHERE datname = '{$localDb}' AND pid <> pg_backend_pid();
    ");
    
    // DB再作成
    $pdoPostgres->exec("DROP DATABASE IF EXISTS \"{$localDb}\";");
    $pdoPostgres->exec("CREATE DATABASE \"{$localDb}\" WITH OWNER = {$localUser} ENCODING = 'UTF8';");
    echo "  ✓ ローカルDB {$localDb} を再作成しました。\n";
} catch (Exception $e) {
    die("❌ ローカルDBの再作成に失敗しました: " . $e->getMessage() . "\n");
}

// ── 5. ローカルDBへのリストア ──
echo "\n[STEP 5] ローカルDBへの本番データリストア...\n";
putenv("PGPASSWORD={$localPass}");
$cmdRestore = "\"{$pgRestore}\" -h {$localHost} -p {$localPort} -U {$localUser} -d {$localDb} -v \"{$prodDumpFile}\" 2>&1";
exec($cmdRestore, $outRestore, $rcRestore);

// pg_restore はスキーマ差分警告等で rc=1 になることがあるため、テーブル存在と件数で成否判定
echo "  ✓ pg_restore 実行完了 (終了コード: {$rcRestore})\n";

// ── 6. 整合性検証（全テーブル件数比較） ──
echo "\n[STEP 6] 本番 ⇔ ローカル 整合性検証...\n";
$pdoProd = new PDO("pgsql:host={$prodHost};port={$prodPort};dbname={$prodDb};", $prodUser, $prodPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
$pdoLocal = new PDO("pgsql:host={$localHost};port={$localPort};dbname={$localDb};", $localUser, $localPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$getTablesSql = "
    SELECT tablename 
    FROM pg_tables 
    WHERE schemaname = 'public' 
    ORDER BY tablename
";

$prodTables = $pdoProd->query($getTablesSql)->fetchAll(PDO::FETCH_COLUMN);
$localTables = $pdoLocal->query($getTablesSql)->fetchAll(PDO::FETCH_COLUMN);

echo sprintf("%-42s | %-14s | %-14s | %s\n", "テーブル名", "本番件数", "ローカル件数", "判定");
echo str_repeat("-", 80) . "\n";

$allMatch = true;
$checkedCount = 0;

foreach ($prodTables as $tbl) {
    $pCnt = (int)$pdoProd->query("SELECT count(*) FROM \"{$tbl}\"")->fetchColumn();
    $inLocal = in_array($tbl, $localTables);
    if ($inLocal) {
        $lCnt = (int)$pdoLocal->query("SELECT count(*) FROM \"{$tbl}\"")->fetchColumn();
        $isMatch = ($pCnt === $lCnt);
        if (!$isMatch) $allMatch = false;
        $status = $isMatch ? "✓ 一致" : "❌ 不一致";
        echo sprintf("%-40s | %12d 件 | %12d 件 | %s\n", $tbl, $pCnt, $lCnt, $status);
    } else {
        $allMatch = false;
        echo sprintf("%-40s | %12d 件 | %14s | ❌ ローカルに存在せず\n", $tbl, $pCnt, "なし");
    }
    $checkedCount++;
}

// ローカルに余分なテーブルがあるか
$diffLocalOnly = array_diff($localTables, $prodTables);
if (!empty($diffLocalOnly)) {
    echo "\nℹ️ ローカルのみに存在するテーブル: " . implode(', ', $diffLocalOnly) . "\n";
}

echo "\n====================================================================\n";
if ($allMatch) {
    echo "🎉 成功: 本番DBの全 {$checkedCount} テーブルのデータがローカルDBに完全複製されました！\n";
    echo "本番バックアップ: {$prodDumpFile}\n";
    echo "作業前ローカル退避: {$localBackupDump}\n";
} else {
    echo "⚠️ 警告: 一部のテーブルで件数の不一致または欠損が検出されました。\n";
}
echo "====================================================================\n";
