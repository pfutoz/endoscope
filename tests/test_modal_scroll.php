<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\tests\test_modal_scroll.php
 * ファイル名: test_modal_scroll.php
 * 概要: モーダルのスクロール対応・見切れ防止CSS 単体テスト
 */

$file = __DIR__ . '/../waku_override_modal_inc.php';
$content = file_get_contents($file);

function assertTest($cond, $msg) {
    if ($cond) {
        echo "  [PASS] {$msg}\n";
    } else {
        echo "  [FAIL] {$msg}\n";
        exit(1);
    }
}

echo "=== モーダル スクロール・見切れ防止 CSS 検証 ===\n";

assertTest(strpos($content, '.wom-modal-container') !== false, "wom-modal-container が定義されていること");
assertTest(strpos($content, 'wom-body') !== false && strpos($content, 'overflow-y: auto !important;') !== false, "wom-body に overflow-y: auto !important が定義されていること");
assertTest(strpos($content, 'position: sticky;') !== false || strpos($content, 'position: sticky !important;') !== false, "wom-table th に sticky 固定が指定されていること");
assertTest(strpos($content, 'top: 8px !important;') !== false, "モーダルが画面最上部近く(top: 8px)に配置されていること");
assertTest(strpos($content, 'max-height: calc(100vh - 20px) !important;') !== false, "max-height が画面内に収まるよう定義されていること");
assertTest(strpos($content, 'wom-body::-webkit-scrollbar') !== false, "カスタムスクロールバーのスタイルが定義されていること");

echo "🎉 モーダルスクロールCSSテスト 全項目合格！\n";
