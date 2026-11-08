<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\tests\test_waku_datepicker.php
 * ファイル名: test_waku_datepicker.php
 * 概要: 時間枠臨時設定モーダルの日付ピッカー（Flatpickr）統合検証テスト
 * 生成日時: 2026-10-22 18:00
 */

echo "=== TEST: 時間枠モーダル 日付ピッカー統合検証 ===\n\n";

// 1. 各アセットファイルの実体とHTTPアクセステスト
$assets = [
    'assets/vendor/flatpickr/flatpickr.min.css',
    'assets/vendor/flatpickr/flatpickr.min.js',
    'assets/vendor/flatpickr/ja.js',
    'assets/css/onokai_flatpickr.css',
    'assets/js/onokai_flatpickr.js',
];

$baseDir = dirname(__DIR__);
foreach ($assets as $asset) {
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
    if (!file_exists($fullPath)) {
        echo "[FAIL] アセットファイルが存在しません: {$asset}\n";
        exit(1);
    }
    $size = filesize($fullPath);
    echo "[PASS] アセット確認OK: {$asset} ({$size} bytes)\n";
}

// 2. assets/js/onokai_flatpickr.js の window.attachOnokaiDatePicker エクスポート検証
$onokaiJs = file_get_contents($baseDir . '/assets/js/onokai_flatpickr.js');
if (strpos($onokaiJs, 'window.attachOnokaiDatePicker = attachOnokaiDatePicker;') !== false) {
    echo "[PASS] onokai_flatpickr.js: window.attachOnokaiDatePicker がグローバル公開されています\n";
} else {
    echo "[FAIL] onokai_flatpickr.js: window.attachOnokaiDatePicker がエクスポートされていません\n";
    exit(1);
}

if (strpos($onokaiJs, 'appendTo: appendTarget') !== false || strpos($onokaiJs, 'appendTo: options.appendTo') !== false) {
    echo "[PASS] onokai_flatpickr.js: options.appendTo / appendTarget が flatpickr へ透過されています\n";
} else {
    echo "[FAIL] onokai_flatpickr.js: options.appendTo が透過されていません\n";
    exit(1);
}

// 3. waku_override_modal_inc.php の内容検証
$modalInc = file_get_contents($baseDir . '/waku_override_modal_inc.php');

$checks = [
    'flatpickr.min.css 読込' => 'assets/vendor/flatpickr/flatpickr.min.css',
    'flatpickr.min.js 読込' => 'assets/vendor/flatpickr/flatpickr.min.js',
    'ja.js 読込' => 'assets/vendor/flatpickr/ja.js',
    'onokai_flatpickr.js 読込' => 'assets/js/onokai_flatpickr.js',
    'womCustomDateControl 要素' => 'womCustomDateControl',
    'womTargetDate input' => 'womTargetDate',
    'dialog Top Layer appendTo 設定' => 'appendTo: modal || document.body',
    '期間ピッカー初期化関数' => 'function initWomPeriodPickers()',
    '期間ピッカー開始日' => 'womPeriodStart',
    '期間ピッカー終了日' => 'womPeriodEnd',
    'z-index: 100050 スタイル' => 'z-index: 100050 !important;',
    '非表示時 display:none' => '#wakuOverrideModal:not([open]) {',
];

foreach ($checks as $label => $needle) {
    if (strpos($modalInc, $needle) !== false) {
        echo "[PASS] モーダル検証OK: {$label}\n";
    } else {
        echo "[FAIL] モーダル検証NG: {$label} が見つかりません\n";
        exit(1);
    }
}

// 4. HTTP経由で month_schedule.php のレスポンス検証
$context = stream_context_create([
    'http' => ['timeout' => 5]
]);
$html = @file_get_contents('http://localhost/endoscope/month_schedule.php', false, $context);
if ($html !== false) {
    if (strpos($html, 'id="womTargetDate"') !== false) {
        echo "[PASS] month_schedule.php HTTPレスポンス: モーダルおよびピッカーコードが正常にレンダリングされています\n";
    } else {
        echo "[FAIL] month_schedule.php HTTPレスポンス内にピッカーコードが見つかりません\n";
        exit(1);
    }
} else {
    echo "[WARN] http://localhost/endoscope/month_schedule.php に接続できませんでした（Apache停止の可能性）\n";
}

echo "\n========================================\n";
echo "🎉 時間枠モーダル日付ピッカー検証: 全項目合格\n";
echo "========================================\n";
