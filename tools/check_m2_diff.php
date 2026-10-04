<?php
/**
 * tools/check_m2_diff.php
 * 生成日時: 2026-10-02 13:03
 * 修正履歴:
 *  - 2026-10-02: m2_系マスタの差分確認
 */

$pdoL = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdoP = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$tables = ['m2_doctor', 'm2_slot_group', 'm2_waku', 'm2_doctor_attendance', 'm2_doctor_slot_pattern'];

foreach ($tables as $tbl) {
    echo "=== {$tbl} ===" . PHP_EOL;
    $l = $pdoL->query("SELECT * FROM {$tbl}")->fetchAll();
    $p = $pdoP->query("SELECT * FROM {$tbl}")->fetchAll();
    echo "  件数: 開発=" . count($l) . ", 本番=" . count($p) . PHP_EOL;
    
    // 主キー名取得
    $pk = array_keys($l[0])[0];
    $lMap = array_column($l, null, $pk);
    $pMap = array_column($p, null, $pk);
    
    $onlyL = array_diff_key($lMap, $pMap);
    $onlyP = array_diff_key($pMap, $lMap);
    if (!empty($onlyL)) {
        echo "  [開発のみ]: " . implode(', ', array_keys($onlyL)) . PHP_EOL;
    }
    if (!empty($onlyP)) {
        echo "  [本番のみ]: " . implode(', ', array_keys($onlyP)) . PHP_EOL;
    }
}
