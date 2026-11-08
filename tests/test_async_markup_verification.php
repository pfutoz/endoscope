<?php
/**
 * test_async_markup_verification.php
 * 
 * month_schedule.php および schedule_note.php の
 * 非同期トグル関連 HTML マークアップと属性の自動検証テスト
 * 
 * 【作成日時】
 * 2026-10-23
 */

echo "=== Markup Verification: Async Toggle Markup & Script Includes ===\n";

// 1. month_schedule.php のソースチェック
$month_src = file_get_contents(__DIR__ . '/../month_schedule.php');

assert(strpos($month_src, 'assets/js/toggle_no_waku.js') !== false, "month_schedule.php must include toggle_no_waku.js");
echo "[PASS] month_schedule.php includes toggle_no_waku.js\n";

assert(strpos($month_src, 'btn-toggle-no-waku') !== false, "month_schedule.php must render btn-toggle-no-waku");
assert(strpos($month_src, 'toggleNoWakuAsync') !== false, "month_schedule.php must bind toggleNoWakuAsync");
echo "[PASS] month_schedule.php binds toggleNoWakuAsync to buttons\n";

assert(strpos($month_src, 'cell-add-btn') !== false, "month_schedule.php must have cell-add-btn class");
assert(strpos($month_src, 'cell-toggle-btn') !== false, "month_schedule.php must have cell-toggle-btn class");
assert(strpos($month_src, 'cell-patient-name') !== false, "month_schedule.php must have cell-patient-name class");
assert(strpos($month_src, 'cell-remarks') !== false, "month_schedule.php must have cell-remarks class");
assert(strpos($month_src, 'waku-name-label') !== false, "month_schedule.php must have waku-name-label class");
echo "[PASS] month_schedule.php has all required target cell classes\n";

assert(strpos($month_src, 'id="listWakuUsageCounter"') !== false, "month_schedule.php must have listWakuUsageCounter id");
assert(strpos($month_src, 'data-date=\'{$date_str}\'') !== false, "month_schedule.php calendar cells must have data-date attribute");
echo "[PASS] month_schedule.php calendar and counter elements properly tagged\n";

// 2. schedule_note.php のソースチェック
$note_src = file_get_contents(__DIR__ . '/../schedule_note.php');

assert(strpos($note_src, 'assets/js/toggle_no_waku.js') !== false, "schedule_note.php must include toggle_no_waku.js");
echo "[PASS] schedule_note.php includes toggle_no_waku.js\n";

assert(strpos($note_src, 'btn-toggle-no-waku') !== false, "schedule_note.php must render btn-toggle-no-waku");
assert(strpos($note_src, 'toggleNoWakuAsync') !== false, "schedule_note.php must bind toggleNoWakuAsync");
echo "[PASS] schedule_note.php binds toggleNoWakuAsync to buttons\n";

assert(strpos($note_src, 'cell-add-btn') !== false, "schedule_note.php must have cell-add-btn class");
assert(strpos($note_src, 'cell-toggle-btn') !== false, "schedule_note.php must have cell-toggle-btn class");
assert(strpos($note_src, 'cell-patient-name') !== false, "schedule_note.php must have cell-patient-name class");
assert(strpos($note_src, 'cell-remarks') !== false, "schedule_note.php must have cell-remarks class");
assert(strpos($note_src, 'waku-name-label') !== false, "schedule_note.php must have waku-name-label class");
echo "[PASS] schedule_note.php has all required target cell classes\n";

assert(strpos($note_src, 'id="noteEmptySlotCounter"') !== false, "schedule_note.php must have noteEmptySlotCounter id");
echo "[PASS] schedule_note.php empty counter element properly tagged\n";

// 3. toggle_no_waku.js のシンタックスおよび公開関数チェック
$js_src = file_get_contents(__DIR__ . '/../assets/js/toggle_no_waku.js');
assert(strpos($js_src, 'window.toggleNoWakuAsync = toggleNoWakuAsync;') !== false, "toggle_no_waku.js must export toggleNoWakuAsync");
assert(strpos($js_src, 'api_toggle_no_waku.php') !== false, "toggle_no_waku.js must call api_toggle_no_waku.php");
assert(strpos($js_src, 'showToast') !== false, "toggle_no_waku.js must trigger showToast");
assert(strpos($js_src, 'listWakuUsageCounter') !== false, "toggle_no_waku.js must update listWakuUsageCounter");
assert(strpos($js_src, 'noteEmptySlotCounter') !== false, "toggle_no_waku.js must update noteEmptySlotCounter");
echo "[PASS] toggle_no_waku.js structure and exports verified\n";

echo "\n>>> All checks in test_async_markup_verification.php passed successfully! <<<\n";
