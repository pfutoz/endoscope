<?php
/**
 * progress_monitor.php のUIスリム化・統合改修検証スクリプト
 */

$url = 'http://localhost/endoscope/progress_monitor.php?date=2026-10-22&dept=GASTRO';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: {$http_code}\n";
if ($http_code !== 200) {
    echo "FAIL: HTTP Error {$http_code}\n";
    exit(1);
}

$checks = [
    // 1. 表示モードボタン（テーブル／タイルの2択。「圧縮」という言葉がない）
    'Mode Button Table exists' => strpos($html, 'id="btnModeTable"') !== false,
    'Mode Button Tile exists'  => strpos($html, 'id="btnModeTile"') !== false,
    'Old Standard Button removed' => strpos($html, 'id="btnModeStandard"') === false,
    'Old Compact Button removed'  => strpos($html, 'id="btnModeCompact"') === false,
    
    // 2. 自動更新コントロール（🔄 今すぐ更新アイコン）
    'Refresh Now Icon Button'  => strpos($html, 'id="btnRefreshNow"') !== false,
    'Refresh Select exists'    => strpos($html, 'id="refreshIntervalSelect"') !== false,
    
    // 3. その他メニューセレクトボックス（7ボタンの集約）
    'Header Menu Select exists' => strpos($html, 'id="headerMenuSelect"') !== false,
    'Menu option emergency'    => strpos($html, 'value="emergency"') !== false,
    'Menu option alert_scan'   => strpos($html, 'value="alert_scan"') !== false,
    'Menu option urgent_slot'  => strpos($html, 'value="urgent_slot"') !== false,
    'Menu option print'        => strpos($html, 'value="print"') !== false,
    'Menu option calendar'     => strpos($html, 'value="calendar"') !== false,
    'Menu option portal'       => strpos($html, 'value="portal"') !== false,
    
    // 4. 日付ステッパー内「今日へ」ボタン
    'Date stepper Today Link'  => strpos($html, 'class="date-btn-today"') !== false,
    
    // 5. 検査種別タブのリアルタイム件数バッジ
    'Exam Tab Badge ALL'       => strpos($html, 'id="badgeExamALL"') !== false,
    'Exam Tab Badge CF'        => strpos($html, 'id="badgeExamCF"') !== false,
    'Exam Tab Badge ENEMA'     => strpos($html, 'id="badgeExamENEMA"') !== false,
    'Exam Tab Badge GIF'       => strpos($html, 'id="badgeExamGIF"') !== false,
    
    // 6. サマリーバーの重複バッジ撤廃＆「6.説明」統合枠
    'Summary pill CF removed'  => strpos($html, 'id="sumCF"') === false,
    'Summary pill GIF removed' => strpos($html, 'id="sumGIF"') === false,
    'Summary explain total card' => strpos($html, 'id="cardExplainTotal"') !== false,
    'Summary explain sub badge'  => strpos($html, 'id="sumExpSub"') !== false,
    
    // 7. メインコンテナの初期クラス（デフォルト圧縮）
    'Main container default compact' => strpos($html, 'main-container view-mode-compact') !== false,
    
    // 8. JS関数の存在
    'JS handleHeaderMenu'      => strpos($html, 'function handleHeaderMenu') !== false,
    'JS switchViewMode'        => strpos($html, 'function switchViewMode') !== false,
    'JS applyViewMode'         => strpos($html, 'function applyViewMode') !== false,
    'JS renderSummary updated' => strpos($html, 'badgeExamALL') !== false,
];

$all_pass = true;
foreach ($checks as $title => $passed) {
    if ($passed) {
        echo "✅ PASS: {$title}\n";
    } else {
        echo "❌ FAIL: {$title}\n";
        $all_pass = false;
    }
}

if ($all_pass) {
    echo "\n🎉 すべてのチェックに合格しました！\n";
    exit(0);
} else {
    echo "\n⚠️ 一部のチェックに失敗しました。\n";
    exit(1);
}
