<?php
/**
 * schedule_note.php の枠名称変更・枠中止(🚫)・復帰(⭕)・迷子防止 統合テスト
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();

$dept = 'PROCT';
$testDate = '2026-11-20'; // テスト専用日

// クリーンアップ
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE department_code = '{$dept}' AND exam_date = '{$testDate}'");
$pdo->exec("DELETE FROM m2_waku_override WHERE department_code = '{$dept}' AND target_date = '{$testDate}'");

echo "=== schedule_note.php 枠名称変更 ＆ 枠中止 統合テスト開始 ===\n";

$passCount = 0;
$failCount = 0;
function assertTest($cond, $msg) {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

// 1. 初期状態の枠一覧確認（PROCT は通常 9枠）
$slots = getDailyReservationsWithEffectiveSlots($pdo, $dept, $testDate, 'all', 'all', true);
assertTest(count($slots) === 9, "初期状態で9枠取得できること (実際: " . count($slots) . ")");

// 2. 枠名称変更: GIF1 (base_waku_id=34) を「エコー特別枠」に変更
$pdo->exec("
    INSERT INTO m2_waku_override 
    (department_code, base_waku_id, override_type, target_date, display_waku_name, group_code, display_order, is_suspended, reason, created_at, updated_at)
    VALUES
    ('{$dept}', 34, 'DATE', '{$testDate}', 'エコー特別枠', 'GIF', 8, FALSE, 'テスト変更', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
");

$slotsAfterRename = getDailyReservationsWithEffectiveSlots($pdo, $dept, $testDate, 'all', 'all', true);
assertTest(count($slotsAfterRename) === 9, "枠名称変更後も総枠数は9枠であること (実際: " . count($slotsAfterRename) . ")");
$renamedSlot = null;
foreach ($slotsAfterRename as $s) {
    if ($s['scheduled_time'] === 'エコー特別枠') {
        $renamedSlot = $s;
        break;
    }
}
assertTest($renamedSlot !== null, "「エコー特別枠」が表示枠名として取得できること");
assertTest(($renamedSlot['original_name'] ?? '') === 'GIF1', "元枠名が「GIF1」として保持されていること");
assertTest(($renamedSlot['base_waku_id'] ?? 0) == 34, "base_waku_id が 34 であること");
assertTest(empty($renamedSlot['is_mismatch']), "迷子判定されていないこと");

// 3. 枠の中止: 「エコー特別枠」に対して枠なし設定（🚫）を投入
$pdo->exec("
    INSERT INTO t_endoscopy_schedule 
    (department_code, exam_date, scheduled_time, waku_no, waku_id, exam_type, exam_location, doctor_name, patient_name, remarks, is_canceled, created_at, updated_at)
    VALUES
    ('{$dept}', '{$testDate}', 'エコー特別枠', 8, 34, 'GIF', '指定なし', '◆未定◆', '※枠なし', '＊＊＊🚫枠なし🚫＊＊＊', FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
");

$slotsAfterSuspend = getDailyReservationsWithEffectiveSlots($pdo, $dept, $testDate, 'all', 'all', true);
assertTest(count($slotsAfterSuspend) === 9, "枠中止(🚫)後も総枠数は9枠であること (実際: " . count($slotsAfterSuspend) . ")");
$suspendedSlot = null;
foreach ($slotsAfterSuspend as $s) {
    if ($s['scheduled_time'] === 'エコー特別枠') {
        $suspendedSlot = $s;
        break;
    }
}
assertTest($suspendedSlot !== null, "「エコー特別枠」として枠なし行が存在すること");
assertTest($suspendedSlot['patient_name'] === '※枠なし', "患者名が「※枠なし」であること");
assertTest(empty($suspendedSlot['is_mismatch']), "枠なし行が迷子判定されていないこと");
assertTest(($suspendedSlot['base_waku_id'] ?? 0) == 34, "枠なし行の base_waku_id が 34 であること");

// 4. 枠の復帰: 枠なし予約を削除（⭕）
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE department_code = '{$dept}' AND exam_date = '{$testDate}' AND scheduled_time = 'エコー特別枠'");

$slotsAfterRevert = getDailyReservationsWithEffectiveSlots($pdo, $dept, $testDate, 'all', 'all', true);
assertTest(count($slotsAfterRevert) === 9, "枠復帰(⭕)後も総枠数は9枠であること (実際: " . count($slotsAfterRevert) . ")");
$revertedSlot = null;
foreach ($slotsAfterRevert as $s) {
    if ($s['scheduled_time'] === 'エコー特別枠') {
        $revertedSlot = $s;
        break;
    }
}
assertTest($revertedSlot !== null && !empty($revertedSlot['is_empty']), "「エコー特別枠」が空き枠として正常復帰したこと");

// クリーンアップ
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE department_code = '{$dept}' AND exam_date = '{$testDate}'");
$pdo->exec("DELETE FROM m2_waku_override WHERE department_code = '{$dept}' AND target_date = '{$testDate}'");

echo "\n========================================\n";
echo "テスト結果: 合格: {$passCount} / 失敗: {$failCount}\n";
echo "========================================\n";

if ($failCount === 0) {
    echo "🎉 全項目完全合格！schedule_note.php の枠名称変更・枠中止(🚫)・枠復帰(⭕)が完全連動しています。\n";
} else {
    echo "❌ 一部のテストが失敗しました。\n";
}
