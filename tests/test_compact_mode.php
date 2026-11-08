<?php
/**
 * 縦圧縮モード（Compact Mode）マウスクリック可能ナビ検証スクリプト
 */

$ch = curl_init('http://localhost/endoscope/schedule_note.php?dept=PROCT&date=2026-10-26&show_empty=1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: {$http_code}\n";
if ($http_code !== 200) {
    echo "FAIL: HTTP Error {$http_code}\n";
    exit(1);
}

$checks = [
    // 縦圧縮モード基本
    'CSS compact-header-bar' => strpos($html, '.compact-header-bar') !== false,
    'CSS compact-kbd-btn'    => strpos($html, '.compact-kbd-btn') !== false,
    'HTML compactHeaderBar'  => strpos($html, 'id="compactHeaderBar"') !== false,
    
    // 圧縮時のマウスクリック可能ボタン群
    'Clickable ArrowLeft'    => strpos($html, 'title="前日へ移動 [←キー]"') !== false,
    'Clickable ArrowRight'   => strpos($html, 'title="翌日へ移動 [→キー]"') !== false,
    'Clickable Shift+Left'   => strpos($html, 'title="休診日をスキップして前診療日へ [Shift+←キー]"') !== false,
    'Clickable Shift+Right'  => strpos($html, 'title="休診日をスキップして次診療日へ [Shift+→キー]"') !== false,
    'Clickable ArrowUp'      => strpos($html, 'title="前週（-7日）へジャンプ [↑キー]"') !== false,
    'Clickable ArrowDown'    => strpos($html, 'title="翌週（+7日）へジャンプ [↓キー]"') !== false,
    'Clickable Today'        => strpos($html, 'title="今日へジャンプ [TまたはHomeキー]"') !== false,
    'Clickable Toggle'       => strpos($html, 'title="通常表示モードに戻す [Cキー]"') !== false,
    
    // アクションボタン
    'Compact bar prep btn'   => (strpos($html, 'compact-bar-btn-prep') !== false && strpos($html, 'openBulkPrepRoomModal') !== false),
    'Compact bar print btn'  => (strpos($html, 'compact-bar-btn-print') !== false && strpos($html, 'openPrintSchedule') !== false),

    // リストボックス（その他機能ドロップダウン）
    'More menu button'       => strpos($html, 'id="moreMenuBtn"') !== false,
    'More menu dropdown'     => strpos($html, 'id="moreMenuDropdown"') !== false,

    // 診療科切替（スリムバー2連タブ＆通常ツールバー）
    'Compact dept tabs container' => strpos($html, 'class="compact-dept-tabs"') !== false,
    'Compact dept PROCT active'   => strpos($html, 'compact-dept-tab active-proct') !== false,
    'Compact dept tab kbd [1]'    => strpos($html, '🟢 肛門 <kbd>1</kbd>') !== false,
    'Compact dept tab kbd [2]'    => strpos($html, '🔵 胃腸 <kbd>2</kbd>') !== false,
    'Toolbar dept tab kbd 1'      => strpos($html, '🟢 肛門科 <kbd class="nav-kbd"') !== false,
    'Toolbar dept tab kbd 2'      => strpos($html, '🔵 胃腸科 <kbd class="nav-kbd"') !== false,

    // JSナビゲーション＆キー操作
    'JS scheduleNavUrls.deptProct'  => strpos($html, 'deptProct:') !== false,
    'JS scheduleNavUrls.deptGastro' => strpos($html, 'deptGastro:') !== false,
    'JS keydown [1] key handling'   => strpos($html, "e.key === '1' || e.code === 'Numpad1'") !== false,
    'JS keydown [2] key handling'   => strpos($html, "e.key === '2' || e.code === 'Numpad2'") !== false,
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
    echo "\n🎉 ALL CHECKS PASSED!\n";
    exit(0);
} else {
    echo "\n❌ SOME CHECKS FAILED!\n";
    exit(1);
}
