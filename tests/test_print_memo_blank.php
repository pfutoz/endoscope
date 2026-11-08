<?php
/**
 * 印刷画面 手書きメモ余白機能の単体HTML検証
 */

$html = file_get_contents('http://localhost/endoscope/print_schedule_daily.php?dept=GASTRO&date=' . date('Y-m-d'));

$tests = [
    'th 内の remarks-title-text 存在確認' => strpos($html, 'class="remarks-title-text"') !== false,
    'th 内の memo-title-text 存在確認' => strpos($html, 'class="memo-title-text"') !== false,
    '操作バーの labelRemarksToggle 存在確認' => strpos($html, 'id="labelRemarksToggle"') !== false,
    'CSSの body.hide-remarks td.col-remarks > * 非表示ルール存在確認' => strpos($html, 'body.hide-remarks td.col-remarks > *') !== false,
    'CSSの 1.5倍幅 (width: 42%) ルール存在確認' => strpos($html, 'width: 42% !important;') !== false,
    'CSSの 最小幅 (min-width: 75mm) ルール存在確認' => strpos($html, 'min-width: 75mm !important;') !== false,
    'CSSの 手書き行高 (height: 24px) ルール存在確認' => strpos($html, 'body.hide-remarks tr.schedule-row td') !== false,
    'JSの toggleRemarksColumn 連動ロジック存在確認' => strpos($html, "lbl.textContent = show ? '📝 備考を印刷' : '✏️ 手書きメモ余白';") !== false,
];

echo "=== print_schedule_daily.php 手書きメモ余白機能 HTML検証 ===\n\n";
$allPass = true;
foreach ($tests as $desc => $passed) {
    echo ($passed ? '  ✅ [PASS] ' : '  ❌ [FAIL] ') . $desc . "\n";
    if (!$passed) $allPass = false;
}

echo "\n" . ($allPass ? "🎉 全テスト合格！\n" : "⚠️ 一部不合格\n");
