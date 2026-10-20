<?php
/**
 * tools/verify_production_http.php
 * 本番環境(192.168.1.16)のエンドポイント疎通確認
 */

$urls = [
    'http://192.168.1.16/endoscope/furiwake_daymemo.php',
    'http://192.168.1.16/endoscope/index.php',
    'http://192.168.1.16/endoscope/month_schedule.php?dept=PROCT',
    'http://192.168.1.16/endoscope/month_schedule.php?dept=GASTRO',
    'http://192.168.1.16/endoscope/schedule_note.php?dept=PROCT',
    'http://192.168.1.16/endoscope/仕様書/2026-10-9works.html',
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
