<?php
/**
 * schedule_note.php 日付バー大幅スリム化 ＆ ピッカー一本化検証スクリプト
 */

$test_dates = [
    '2026-11-01' => '日祝休診日',
    '2026-10-22' => '平日診療日'
];

$all_success = true;

foreach ($test_dates as $date => $label) {
    echo "=== テスト実行: {$date} ({$label}) ===\n";
    $url = "http://localhost/endoscope/schedule_note.php?dept=GASTRO&date={$date}&show_empty=1";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    echo "HTTP Code: {$http_code}\n";
    if ($http_code !== 200) {
        echo "❌ FAIL: HTTP Error {$http_code}\n";
        $all_success = false;
        continue;
    }

    $checks = [
        // 1. 重複していた日付タイトルが撤廃されていること
        'No duplicate date-title-main' => strpos($html, 'class="date-title-main"') === false,

        // 2. 中央のFlatpickrピッカーコントロールが存在すること
        'Picker customDateControl exists' => strpos($html, 'id="customDateControl"') !== false,
        'Picker text toggle exists'       => strpos($html, 'id="dateTextToggle"') !== false,
        'Picker calendar btn exists'      => strpos($html, 'id="calendarTriggerBtn"') !== false,

        // 3. 休診／診療日バッジが存在すること
        'Clinic status badge exists'      => strpos($html, 'class="clinic-status-badge') !== false,

        // 4. 今日へ / 本日 ボタンが存在すること
        'Today button/badge exists'       => strpos($html, 'btn-today') !== false,

        // 5. 左右のセグメントボタングループが存在すること
        'Segmented button group count'    => substr_count($html, 'class="date-nav-group date-nav-group-segmented"') === 2,

        // 6. 各種ナビゲーションボタンが欠損なく存在すること
        'Button navPrevWeek (-7d)'        => strpos($html, 'id="navPrevWeek"') !== false,
        'Button navPrevDay (-1d)'         => strpos($html, 'id="navPrevDay"') !== false,
        'Button navPrevClinic'            => strpos($html, 'id="navPrevClinic"') !== false,
        'Button navNextClinic'            => strpos($html, 'id="navNextClinic"') !== false,
        'Button navNextDay (+1d)'         => strpos($html, 'id="navNextDay"') !== false,
        'Button navNextWeek (+7d)'        => strpos($html, 'id="navNextWeek"') !== false,

        // 7. セグメント用CSS定義が存在すること
        'CSS segmented rules exist'       => strpos($html, '.date-nav-group.date-nav-group-segmented') !== false,
    ];

    foreach ($checks as $title => $passed) {
        if ($passed) {
            echo "  ✅ PASS: {$title}\n";
        } else {
            echo "  ❌ FAIL: {$title}\n";
            $all_success = false;
        }
    }
    echo "\n";
}

if ($all_success) {
    echo "🎉 全ての日付バー検証チェックに合格しました！\n";
    exit(0);
} else {
    echo "⚠️ 一部のチェックに失敗しました。\n";
    exit(1);
}
