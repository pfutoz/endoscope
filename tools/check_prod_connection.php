<?php
try {
    $pdoP = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    echo "SUCCESS: 本番DB (192.168.1.16) への接続に成功しました。\n";
    $cnt = $pdoP->query("SELECT count(*) FROM t_endoscopy_schedule WHERE is_canceled = FALSE")->fetchColumn();
    echo "本番の有効予約件数: {$cnt} 件\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
