<?php
/**
 * tests/test_rename_sync_and_lost_prevention.php
 * 枠名称変更に伴う確定予約・枠なし予約の自動同期、および予約迷子化（MISMATCH）100%防止の自動テスト
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();

echo "=== 枠名称変更・予約同期・迷子防止 自動テスト開始 ===\n\n";

$pass_count = 0;
$fail_count = 0;

function assertCondition($cond, $msg) {
    global $pass_count, $fail_count;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $pass_count++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $fail_count++;
    }
}

$test_date = '2026-11-20';
$dept = 'PROCT';

// クリーンアップ
$pdo->exec("DELETE FROM m2_waku_override WHERE target_date = '{$test_date}'");
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE exam_date = '{$test_date}' AND department_code = '{$dept}'");

// 基本枠 GIF1 の ID 取得
$stmt = $pdo->prepare("SELECT id FROM m2_waku WHERE waku_name = 'GIF1' AND department_code = :dept");
$stmt->execute([':dept' => $dept]);
$base_id = (int)$stmt->fetchColumn();

// 1. GIF1 に確定予約を1件登録
$stmt_ins = $pdo->prepare("
    INSERT INTO t_endoscopy_schedule (
        department_code, exam_date, scheduled_time, waku_no, exam_type, exam_location,
        patient_id, patient_name, doctor_name, is_canceled, created_at
    ) VALUES (
        :dept, :date, 'GIF1', 1, 'GIF', '指定なし',
        'TEST999', 'テスト患者', '小野', FALSE, CURRENT_TIMESTAMP
    ) RETURNING schedule_id
");
$stmt_ins->execute([':dept' => $dept, ':date' => $test_date]);
$test_sid = (int)$stmt_ins->fetchColumn();

// 2. 枠名称を GIF1 ➔ GIF1(特) に変更
function doSaveOverride($dept, $date, $baseId, $origName, $displayName) {
    $_REQUEST = [
        'action' => 'save_override',
        'dept' => $dept,
        'date' => $date,
        'base_waku_id' => $baseId,
        'base_waku_name' => $origName,
        'display_waku_name' => $displayName,
        'is_suspended' => '0'
    ];
    ob_start();
    include __DIR__ . '/../action_waku_override.php';
    $output = ob_get_clean();
    return json_decode($output, true);
}

echo "--- テスト 1: 予約あり枠の名称変更と予約同期 ---\n";
$res1 = doSaveOverride($dept, $test_date, $base_id, 'GIF1', 'GIF1(特)');
assertCondition(!empty($res1['success']), "save_override が成功すること");

$cur_time = $pdo->query("SELECT scheduled_time FROM t_endoscopy_schedule WHERE schedule_id = {$test_sid}")->fetchColumn();
assertCondition($cur_time === 'GIF1(特)', "予約レコードの scheduled_time が新名称「GIF1(特)」に自動同期されること (実際: {$cur_time})");

$list1 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
$target_item = null;
foreach ($list1 as $item) {
    if ($item['schedule_id'] == $test_sid) $target_item = $item;
}
assertCondition($target_item !== null && empty($target_item['is_mismatch']), "予約が迷子にならず正常に取得できること");
assertCondition($target_item['slot_waku_name'] === 'GIF1(特)', "表示枠名が「GIF1(特)」であること");

echo "\n--- テスト 2: さらに再変更（GIF1(特) ➔ GIF1(午後)）した場合の迷子防止 ---\n";
$res2 = doSaveOverride($dept, $test_date, $base_id, 'GIF1', 'GIF1(午後)');
assertCondition(!empty($res2['success']), "再変更 save_override が成功すること");

$cur_time2 = $pdo->query("SELECT scheduled_time FROM t_endoscopy_schedule WHERE schedule_id = {$test_sid}")->fetchColumn();
assertCondition($cur_time2 === 'GIF1(午後)', "予約レコードの scheduled_time が新名称「GIF1(午後)」に自動同期されること (実際: {$cur_time2})");

$list2 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
$target_item2 = null;
foreach ($list2 as $item) {
    if ($item['schedule_id'] == $test_sid) $target_item2 = $item;
}
assertCondition($target_item2 !== null && empty($target_item2['is_mismatch']), "再変更後も絶対に迷子にならないこと");
assertCondition($target_item2['slot_waku_name'] === 'GIF1(午後)', "表示枠名が「GIF1(午後)」であること");

echo "\n--- テスト 3: 基本枠名へのリセット（元に戻す） ---\n";
$res3 = doSaveOverride($dept, $test_date, $base_id, 'GIF1', 'GIF1');
assertCondition(!empty($res3['success']), "リセット save_override が成功すること");

$ov_exists = $pdo->query("SELECT COUNT(*) FROM m2_waku_override WHERE target_date = '{$test_date}' AND base_waku_id = {$base_id}")->fetchColumn();
assertCondition((int)$ov_exists === 0, "m2_waku_override の該当レコードが削除され基本枠に復帰していること");

$cur_time3 = $pdo->query("SELECT scheduled_time FROM t_endoscopy_schedule WHERE schedule_id = {$test_sid}")->fetchColumn();
assertCondition($cur_time3 === 'GIF1', "予約レコードの scheduled_time も基本枠名「GIF1」に復帰同期していること (実際: {$cur_time3})");

$list3 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
$target_item3 = null;
foreach ($list3 as $item) {
    if ($item['schedule_id'] == $test_sid) $target_item3 = $item;
}
assertCondition($target_item3 !== null && empty($target_item3['is_mismatch']), "元に戻した後も迷子にならず基本枠で取得できること");
assertCondition($target_item3['slot_waku_name'] === 'GIF1', "表示枠名が基本枠名「GIF1」であること");

// テストデータ片付け
$pdo->exec("DELETE FROM m2_waku_override WHERE target_date = '{$test_date}'");
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE exam_date = '{$test_date}' AND department_code = '{$dept}'");

echo "\n========================================\n";
echo "テスト結果: 合格: {$pass_count} / 失敗: {$fail_count}\n";
echo "========================================\n";
if ($fail_count === 0) {
    echo "🎉 全項目合格！枠名変更・予約同期・迷子防止が完全動作しています。\n";
} else {
    exit(1);
}
