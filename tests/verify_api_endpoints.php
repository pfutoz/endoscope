<?php
/**
 * 主要APIエンドポイントの健全性検証 (HTTP経由)
 */

$apis = [
    'api/get_overrides.php' => 'api/get_overrides.php?year=' . date('Y') . '&month=' . date('m') . '&dept=GASTRO',
    'action_exam_master.php (一覧取得)' => 'action_exam_master.php?action=list',
    'action_doctor_notification.php (医師一覧取得)' => 'action_doctor_notification.php?action=get_doctors',
    'action_doctor_notification.php (通知取得)' => 'action_doctor_notification.php?action=get_notifications&date=' . date('Y-m-d') . '&dept=GASTRO',
];

echo "=== 主要APIエンドポイント健全性検証 ===\n\n";

$passCount = 0;
$failCount = 0;

foreach ($apis as $label => $path) {
    $url = 'http://localhost/endoscope/' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);

    if ($httpCode !== 200) {
        echo sprintf("  ❌ [FAIL] %-45s (HTTPステータス: %s)\n", $label, $httpCode);
        $failCount++;
        continue;
    }

    $json = json_decode($response, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        echo sprintf("  ❌ [FAIL] %-45s (JSONパース失敗: %s)\n", $label, substr($response, 0, 100));
        $failCount++;
    } else {
        $countInfo = '';
        if (isset($json['data']) && is_array($json['data'])) {
            $countInfo = ' (件数: ' . count($json['data']) . '件)';
        } elseif (isset($json['doctors']) && is_array($json['doctors'])) {
            $countInfo = ' (医師数: ' . count($json['doctors']) . '名)';
        } elseif (isset($json['notifications']) && is_array($json['notifications'])) {
            $countInfo = ' (通知数: ' . count($json['notifications']) . '件)';
        }
        echo sprintf("  ✅ [PASS] %-45s (HTTP 200 / JSON正常%s)\n", $label, $countInfo);
        $passCount++;
    }
}

echo "\n=========================================\n";
echo "検証完了: 成功 {$passCount} API / 失敗 {$failCount} API\n";
