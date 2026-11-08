<?php
/**
 * 本番最新データを取り込んだローカル環境での全主要画面レンダリング検証 (HTTP経由)
 */

$today = date('Y-m-d');
$month = date('m');
$year = date('Y');

$screens = [
    'index.php' => 'index.php',
    'month_matrix_v2.php (胃腸科)' => 'month_matrix_v2.php?dept=GASTRO&year=' . $year . '&month=' . $month,
    'month_matrix_v2.php (肛門科)' => 'month_matrix_v2.php?dept=PROCT&year=' . $year . '&month=' . $month,
    'month_schedule.php (胃腸科)' => 'month_schedule.php?dept=GASTRO&year=' . $year . '&month=' . $month,
    'month_schedule.php (肛門科)' => 'month_schedule.php?dept=PROCT&year=' . $year . '&month=' . $month,
    'schedule_note.php (胃腸科)' => 'schedule_note.php?dept=GASTRO&date=' . $today,
    'schedule_note.php (肛門科)' => 'schedule_note.php?dept=PROCT&date=' . $today,
    'print_schedule_daily.php (印刷)' => 'print_schedule_daily.php?dept=GASTRO&date=' . $today,
    'progress_monitor.php (進捗)' => 'progress_monitor.php?dept=GASTRO&date=' . $today,
    'slot_search.php (空き枠検索)' => 'slot_search.php?dept=GASTRO',
    'stats.php (検査数集計)' => 'stats.php?year=' . $year . '&month=' . $month,
    'slot_master_maintenance.php (医師)' => 'slot_master_maintenance.php?tab=doctor&dept=GASTRO',
    'slot_master_maintenance.php (枠)' => 'slot_master_maintenance.php?tab=waku&dept=GASTRO',
    'slot_master_maintenance.php (パターン)' => 'slot_master_maintenance.php?tab=pattern&dept=GASTRO&doctor=%E5%93%B2%E4%B9%9F',
    'slot_master_maintenance.php (出勤例外)' => 'slot_master_maintenance.php?tab=attendance&dept=GASTRO&year=' . $year,
    'exam_master_maintenance.php (検査種別)' => 'exam_master_maintenance.php',
    'master_maintenance.php (exam_type案内)' => 'master_maintenance.php?category=exam_type',
    'master_maintenance.php (doctor案内)' => 'master_maintenance.php?category=doctor',
    'master_maintenance.php (マイトイレ部屋)' => 'master_maintenance.php?category=toilet_room',
    'furiwake_daymemo.php (日別メモ科振分)' => 'furiwake_daymemo.php',
];

echo "=== 本番最新データ (2,284件) による全主要画面レンダリング検証 (HTTP経由) ===\n\n";

$passCount = 0;
$failCount = 0;

$errorPatterns = [
    '/<b>(?:Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:\s*/i',
    '/(?:^|\n)\s*(?:Fatal error|Parse error|Warning|Notice|Deprecated):\s*/i',
    '/Uncaught\s+(?:Error|Exception|PDOException)/i',
    '/SQLSTATE\[[0-9A-Z]+\]/i',
    '/Call to undefined (?:function|method)/i'
];

foreach ($screens as $label => $path) {
    $url = 'http://localhost/endoscope/' . $path;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($httpCode !== 200) {
        echo sprintf("  ❌ [FAIL] %-42s (HTTPステータス: %s, cURLエラー: %s)\n", $label, $httpCode, $curlErr);
        $failCount++;
        continue;
    }

    $foundErrors = [];
    foreach ($errorPatterns as $pattern) {
        if (preg_match($pattern, $html, $matches)) {
            $foundErrors[] = trim($matches[0]);
        }
    }

    if (!empty($foundErrors)) {
        echo sprintf("  ❌ [FAIL] %-42s (エラー検知: %s)\n", $label, implode(', ', $foundErrors));
        $failCount++;
    } elseif (strlen($html) < 200) {
        echo sprintf("  ❌ [FAIL] %-42s (出力バイト数が極小: %d bytes)\n", $label, strlen($html));
        $failCount++;
    } else {
        echo sprintf("  ✅ [PASS] %-42s (HTTP 200 / %7d bytes / エラーなし)\n", $label, strlen($html));
        $passCount++;
    }
}

echo "\n=========================================\n";
echo "検証完了: 成功 {$passCount} 画面 / 失敗 {$failCount} 画面\n";
if ($failCount === 0) {
    echo "🎉 全 " . count($screens) . " 画面が本番最新データ（2,284件）で 100% 正常に動作しました！\n";
}
