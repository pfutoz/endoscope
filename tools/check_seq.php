<?php
/**
 * tools/check_seq.php
 * 生成日時: 2026-10-02 13:04
 * 修正履歴:
 *  - 2026-10-02: 本番DBのシーケンス確認
 */

$pdoP = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

echo "=== 本番DBのシーケンス値 ===" . PHP_EOL;
$seqs = $pdoP->query("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($seqs as $s) {
    $val = $pdoP->query("SELECT last_value, is_called FROM \"{$s}\"")->fetch();
    echo sprintf("  %-35s : last_value=%s, is_called=%s\n", $s, $val['last_value'], $val['is_called'] ? 'true' : 'false');
}
