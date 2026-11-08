<?php
/**
 * 印刷画面「空き枠を含む」OFF時の枠なし除外＆件数カウント検証
 */

// 1. 空き枠OFFのHTML取得
$html_no_empty = file_get_contents('http://localhost/endoscope/print_schedule_daily.php?dept=ALL&date=2026-10-26&show_empty=0');

// 2. 空き枠ONのHTML取得
$html_with_empty = file_get_contents('http://localhost/endoscope/print_schedule_daily.php?dept=ALL&date=2026-10-26&show_empty=1');

echo "=== print_schedule_daily.php 枠なし除外＆件数カウント検証 ===\n\n";

// テスト1: show_empty=0 の時に「※枠なし」が含まれないこと
$has_no_waku_when_off = (strpos($html_no_empty, '※枠なし') !== false);
echo ($has_no_waku_when_off ? "  ❌ [FAIL] " : "  ✅ [PASS] ") . "show_empty=0（チェックなし）で「※枠なし」が印刷一覧から除外されている\n";

// テスト2: show_empty=0 の時に「［空き枠］」が含まれないこと
$has_empty_when_off = (strpos($html_no_empty, '［空き枠］') !== false);
echo ($has_empty_when_off ? "  ❌ [FAIL] " : "  ✅ [PASS] ") . "show_empty=0（チェックなし）で「［空き枠］」が印刷一覧から除外されている\n";

// テスト3: show_empty=1 の時に「※枠なし」が表示されること
$has_no_waku_when_on = (strpos($html_with_empty, '※枠なし') !== false);
echo ($has_no_waku_when_on ? "  ✅ [PASS] " : "  ❌ [FAIL] ") . "show_empty=1（チェックあり）で「※枠なし」が表示される\n";

// テスト4: show_empty=1 の時に「［空き枠］」が表示されること
$has_empty_when_on = (strpos($html_with_empty, '［空き枠］') !== false);
echo ($has_empty_when_on ? "  ✅ [PASS] " : "  ❌ [FAIL] ") . "show_empty=1（チェックあり）で「［空き枠］」が表示される\n";

// テスト5: 件数表示の検証（show_empty=0 と show_empty=1 で件数カウントが実患者数で一致していること）
preg_match('/対象日:.*?（(\d+)\s*件）/s', $html_no_empty, $m1);
preg_match('/対象日:.*?（(\d+)\s*件）/s', $html_with_empty, $m2);

$cnt_off = isset($m1[1]) ? (int)$m1[1] : -1;
$cnt_on  = isset($m2[1]) ? (int)$m2[1] : -2;

echo "  ℹ️ ヘッダー件数表示: show_empty=0 ➔ {$cnt_off}件 / show_empty=1 ➔ {$cnt_on}件\n";
if ($cnt_off > 0 && $cnt_off === $cnt_on) {
    echo "  ✅ [PASS] 枠なしや空き枠があっても、件数表示は実患者予約数（{$cnt_off}件）で一貫している\n";
} else {
    echo "  ❌ [FAIL] 件数カウント不整合: off={$cnt_off}, on={$cnt_on}\n";
}

// テスト6: 手書きメモ幅の検証（34% & 60mm）
$has_width_34 = (strpos($html_no_empty, 'width: 34% !important;') !== false);
$has_min_width_60 = (strpos($html_no_empty, 'min-width: 60mm !important;') !== false);
echo ($has_width_34 && $has_min_width_60 ? "  ✅ [PASS] " : "  ❌ [FAIL] ") . "手書きメモ列の幅が最適化（width: 34%, min-width: 60mm）されている\n";

echo "\n=========================================\n";
