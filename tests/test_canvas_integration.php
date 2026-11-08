<?php
/**
 * tests/test_canvas_integration.php
 * RS-Base 検査タイムライン連携 総合テスト
 */
require_once __DIR__ . '/../lib/canvas_helper.php';

$tests_passed = 0;
$tests_total = 0;

function assert_test($condition, $name) {
    global $tests_passed, $tests_total;
    $tests_total++;
    if ($condition) {
        $tests_passed++;
        echo "✅ PASS: {$name}\n";
    } else {
        echo "❌ FAIL: {$name}\n";
    }
}

echo "=== RS-Base 検査タイムライン連携 テスト開始 ===\n";

// 1. モダリティ自動判定テスト
assert_test(getCanvasModality('GIF') === 'ES', 'GIF ➔ ES');
assert_test(getCanvasModality('TCF') === 'ES', 'TCF ➔ ES');
assert_test(getCanvasModality('ポリペク') === 'ES', 'ポリペク ➔ ES');
assert_test(getCanvasModality('W') === 'ES', 'W ➔ ES');
assert_test(getCanvasModality('◆胃がん検診◆') === 'ES', '◆胃がん検診◆ ➔ ES');
assert_test(getCanvasModality('エコー') === 'US', 'エコー ➔ US');
assert_test(getCanvasModality('ECHO') === 'US', 'ECHO ➔ US');
assert_test(getCanvasModality('US') === 'US', 'US ➔ US');
assert_test(getCanvasModality('心電図') === 'ECG', '心電図 ➔ ECG');
assert_test(getCanvasModality('ECG') === 'ECG', 'ECG ➔ ECG');
assert_test(getCanvasModality('胸部XP') === 'XP', '胸部XP ➔ XP');

// 2. URL生成テスト
$url1 = getCanvasUrl('60934', 'GIF');
assert_test(strpos($url1, 'canvas_view.php?patient_no=60934&modality=ES&limit=5') !== false, 'URL生成 (GIF ➔ 60934/ES & limit=5)');

$url2 = getCanvasUrl('60934', 'エコー');
assert_test(strpos($url2, 'canvas_view.php?patient_no=60934&modality=US&limit=5') !== false, 'URL生成 (エコー ➔ 60934/US & limit=5)');

$url3 = getCanvasUrl('60934', 'ALL', '', 0);
assert_test(strpos($url3, 'canvas_view.php?patient_no=60934&modality=ALL') !== false && strpos($url3, 'limit=') === false, 'URL生成 (全件指定時はlimitなし)');

// 3. 空番ガードテスト
assert_test(getCanvasUrl('', 'GIF') === '#', '空番ガード');
assert_test(getCanvasUrl('---', 'GIF') === '#', 'ハイフン空番ガード');

// 4. ボタンHTML出力テスト
$btn_html = renderCanvasButtonHTML('60934', 'GIF');
assert_test(strpos($btn_html, 'target="_blank"') !== false, 'ボタンHTML: target="_blank" 付与');
assert_test(strpos($btn_html, '🗺️ 実績') !== false, 'ボタンHTML: ラベル出力');
assert_test(strpos($btn_html, 'canvas_view.php?patient_no=60934&amp;modality=ES&amp;limit=5') !== false, 'ボタンHTML: URL埋め込み (limit=5含む)');

echo "\n結果: {$tests_passed} / {$tests_total} 合格\n";
if ($tests_passed === $tests_total) {
    echo "🎉 ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "⚠️ SOME TESTS FAILED!\n";
    exit(1);
}
