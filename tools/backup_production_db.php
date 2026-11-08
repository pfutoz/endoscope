<?php
/**
 * tools/backup_production_db.php
 * 本番データベース (192.168.1.16:5432/endoscope) の完全バックアップ実行スクリプト
 */
$backupDir = __DIR__ . '/../backup';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$timestamp = date('Ymd_His');
$backupFile = $backupDir . "/prod_endoscope_backup_{$timestamp}.sql";

putenv('PGPASSWORD=postgres');

$pgDumpPath = 'C:\\Program Files\\PostgreSQL\\18\\bin\\pg_dump.exe';
if (!file_exists($pgDumpPath)) {
    // パス自動探索
    $pgDumpPath = 'pg_dump';
}

$cmd = "\"{$pgDumpPath}\" -h 192.168.1.16 -p 5432 -U postgres -d endoscope -F p -b -v -f \"{$backupFile}\" 2>&1";

echo "=== 本番データベース (192.168.1.16) バックアップ開始 ===" . PHP_EOL;
echo "実行コマンド: {$cmd}" . PHP_EOL;

exec($cmd, $output, $returnCode);

if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
    $sizeMB = round(filesize($backupFile) / 1024 / 1024, 2);
    echo "✓ 本番DBバックアップ完了！" . PHP_EOL;
    echo "  保存先: {$backupFile}" . PHP_EOL;
    echo "  ファイルサイズ: {$sizeMB} MB (" . filesize($backupFile) . " bytes)" . PHP_EOL;
} else {
    echo "❌ バックアップ失敗 (終了コード: {$returnCode})" . PHP_EOL;
    echo implode(PHP_EOL, $output) . PHP_EOL;
    exit(1);
}
