<?php
/**
 * tools/verify_production_http.php
 * 本番環境(192.168.1.16)のエンドポイント疎通確認
 */

$urls = [
    'http://192.168.1.16/endoscope/index.php',
    'http://192.168.1.16/endoscope/schedule_note.php?dept=PROCT',
    'http://192.168.1.16/endoscope/schedule_note.php?dept=GASTRO',
    'http://192.168.1.16/endoscope/slot_master_maintenance.php',
    'http://192.168.1.16/endoscope/slot_search.php',
    'http://192.168.1.16/endoscope/stats.php',
    'http://192.168.1.16/endoscope/furiwake_daymemo.php',
    'http://192.168.1.16/endoscope/followup/index.php',
    'http://192.168.1.16/endoscope/print_schedule_daily.php',
    'http://192.168.1.16/endoscope/month_schedule.php',
    'http://192.168.1.16/endoscope/month_schedule.php?doctor=undecided',
    'http://192.168.1.16/endoscope/bulk_edit_prep_room.php',
    'http://192.168.1.16/endoscope/assets/css/month_schedule.css',
    'http://192.168.1.16/endoscope/assets/css/onokai_flatpickr.css',
    'http://192.168.1.16/endoscope/assets/js/onokai_flatpickr.js',
    'http://192.168.1.16/endoscope/assets/vendor/flatpickr/flatpickr.min.js',
    'http://192.168.1.16/endoscope/仕様書/2026-10-20works.html',
    'http://192.168.1.16/endoscope/仕様書/2026-10-21works.html',
    'http://192.168.1.16/endoscope/仕様書/specdoc.html'
];

echo "=== 本番環境 (192.168.1.16) HTTP 疎通テスト ===" . PHP_EOL;

foreach ($urls as $url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);
    
    $headers = @get_headers($url, false, $context);
    $status = $headers ? $headers[0] : 'FAILED (接続タイムアウトまたはエラー)';
    
    // HTML本文の一部を取得してFatalエラーやParseエラーがないか検証
    $body = @file_get_contents($url, false, $context);
    $hasPhpError = false;
    $errorSnippet = '';
    if ($body !== false) {
        if (preg_match('/(Fatal error|Parse error):/i', $body, $m)) {
            $hasPhpError = true;
            $errorSnippet = substr(strip_tags($body), 0, 100);
        }
    }

    if (strpos($status, '200') !== false && !$hasPhpError) {
        echo "✓ [OK 200] " . $url . PHP_EOL;
    } else {
        echo "❌ [" . $status . "] " . $url . ($hasPhpError ? " (PHP Error: {$errorSnippet})" : "") . PHP_EOL;
    }
}
