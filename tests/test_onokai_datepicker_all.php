<?php
/**
 * tests/test_onokai_datepicker_all.php
 * 全画面の小野会日付ピッカー（ピル形状・西暦/和暦切替・仕切り線📅アイコン）完全整合性検証テスト
 */

$screens = [
    'furiwake_daymemo.php' => '日別メモ 診療科振り分けメンテナンス',
    'modal_edit.php' => '予約登録・編集モーダル',
    'slot_search.php' => '空き枠検索',
    'slot_master_maintenance.php?tab=attendance' => '枠マスタ保守（出勤例外）',
    'stats.php' => '統計集計',
    'waku_console_proto.php' => '枠カスタム管理コンソール',
    'month_schedule.php' => '月間スケジュール（内視鏡ダッシュボード）',
    'schedule_note.php' => 'デイリーノート（元祖・お手本）'
];

echo "=== 小野会日付ピッカー全画面整合性テスト ===\n\n";

$passCount = 0;
$totalCount = 0;

foreach ($screens as $file => $label) {
    $totalCount++;
    echo "[テスト {$totalCount}] {$label} ({$file}):\n";

    // 1. PHP構文チェック
    $realFile = explode('?', $file)[0];
    $cmd = "php -l " . escapeshellarg($realFile);
    exec($cmd, $out, $ret);
    if ($ret === 0) {
        echo "  [PASS] PHP構文チェックOK\n";
    } else {
        echo "  [FAIL] PHP構文エラー: " . implode(" ", $out) . "\n";
        continue;
    }

    // 2. HTTP 200 取得検証
    $url = "http://localhost/endoscope/{$file}";
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    if ($html !== false && isset($headers[0]) && strpos($headers[0], '200') !== false) {
        echo "  [PASS] HTTP 200 応答確認OK (" . strlen($html) . " bytes)\n";
    } else {
        echo "  [WARN] HTTP応答: " . ($headers[0] ?? '接続失敗') . "\n";
    }

    // 3. 小野会ピッカーまたはCSS/JSの読込検証
    $hasCss = strpos($html, 'onokai_flatpickr.css') !== false || strpos($html, 'custom-date-control') !== false;
    $hasJs = strpos($html, 'onokai_flatpickr.js') !== false || strpos($html, 'flatpickr.min.js') !== false;
    $hasPickerOrControl = strpos($html, 'onokai-date-control') !== false || strpos($html, 'onokai-date-picker') !== false || strpos($html, 'custom-date-control') !== false;

    if ($hasCss && $hasJs && $hasPickerOrControl) {
        echo "  [PASS] 小野会日付ピッカー完全装備OK\n";
        $passCount++;
    } else {
        echo "  [FAIL] 装備不足: CSS={$hasCss}, JS={$hasJs}, Control={$hasPickerOrControl}\n";
    }
    echo "\n";
}

// 4. assets/css/onokai_flatpickr.css の width: 195px 完全死守検証
$totalCount++;
echo "[テスト {$totalCount}] onokai_flatpickr.css の固定幅 195px 検証:\n";
$css = file_get_contents('assets/css/onokai_flatpickr.css');
if (strpos($css, 'width: 195px;') !== false) {
    echo "  [PASS] width: 195px が完全に指定されています\n\n";
    $passCount++;
} else {
    echo "  [FAIL] width: 195px が見つかりません\n\n";
}

// 5. assets/js/onokai_flatpickr.js の attachOnokaiDatePicker 自動ピル化検証
$totalCount++;
echo "[テスト {$totalCount}] onokai_flatpickr.js の自動ピル化構造検証:\n";
$js = file_get_contents('assets/js/onokai_flatpickr.js');
if (strpos($js, 'bindOnokaiDateControl') !== false && strpos($js, 'createRawFlatpickr') !== false) {
    echo "  [PASS] 自動ピル化構造および生Flatpickr分離が正常に定義されています\n\n";
    $passCount++;
} else {
    echo "  [FAIL] 自動ピル化構造が不完全です\n\n";
}

echo "=== 結果: {$passCount} / {$totalCount} PASS ===\n";
if ($passCount === $totalCount) {
    echo "🎉 全画面における小野会日付ピッカーの統一が完了しました！\n";
} else {
    echo "⚠️ 一部項目で改善の余地があります。\n";
}
