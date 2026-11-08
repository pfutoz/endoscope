<?php
$_GET = ['category' => 'exam_type', 'op_mode' => 'edit'];
ob_start();
include __DIR__ . '/../master_maintenance.php';
$html = ob_get_clean();

echo "Rendered length: " . strlen($html) . " bytes" . PHP_EOL;

if (strpos($html, '大分類グループ') !== false) {
    echo "✓ 大分類グループ列が表示されています！" . PHP_EOL;
}
if (strpos($html, '予約選択肢') !== false) {
    echo "✓ 予約選択肢列が表示されています！" . PHP_EOL;
}
if (strpos($html, 'TCF') !== false && strpos($html, 'GIF') !== false) {
    echo "✓ 検査種別一覧が正しく表示されています！" . PHP_EOL;
}
