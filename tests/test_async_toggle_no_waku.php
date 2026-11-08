<?php
/**
 * test_async_toggle_no_waku.php
 * 
 * api_toggle_no_waku.php の単体テスト
 * 
 * 【作成日時】
 * 2026-10-23
 * 
 * 【修正履歴】
 * 2026-10-23: 新規作成。非同期枠なし設定・解除APIの正常動作・監査ログ記録・サマリー算出を検証。
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/audit_helper.php';
require_once __DIR__ . '/../lib/slot_helper.php';
require_once __DIR__ . '/../api_toggle_no_waku.php';

$pdo = getDbConnection();

echo "=== Test: api_toggle_no_waku.php Unit Test ===\n";

$test_dept = 'PROCT';
$test_date = '2026-11-20'; // 将来のテスト日付
$test_waku = 'GIF9:00';
$test_base_id = 1;
$test_orig_name = 'GIF1';

// 1. クリーンアップ
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE department_code = :dept AND exam_date = :date AND patient_name = '※枠なし'")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);
$pdo->prepare("DELETE FROM m2_waku_override WHERE department_code = :dept AND target_date = :date AND is_suspended = TRUE")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);

// 2. set_no_waku テスト
$res1 = toggleNoWakuRecord($pdo, [
    'dept'      => $test_dept,
    'date'      => $test_date,
    'action'    => 'set_no_waku',
    'waku'      => $test_waku,
    'base_id'   => $test_base_id,
    'orig_name' => $test_orig_name
]);

assert($res1['success'] === true, "set_no_waku should succeed");
assert($res1['schedule_id'] > 0, "schedule_id should be positive: " . var_export($res1, true));
assert(isset($res1['stats']['no_waku_slots']) && $res1['stats']['no_waku_slots'] >= 1, "no_waku_slots should be >= 1");
$created_sid = $res1['schedule_id'];
echo "[PASS] set_no_waku successfully created schedule_id: {$created_sid}\n";

// DB確認
$stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :sid");
$stmt->execute([':sid' => $created_sid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
assert($row !== false, "Record should exist in DB");
assert($row['patient_name'] === '※枠なし', "patient_name should be ※枠なし");
assert(strpos($row['remarks'], '＊＊＊🚫枠なし') !== false, "remarks should contain 枠なし marker");
echo "[PASS] DB record verified\n";

// 3. 二重登録ガードテスト（同じ枠を再度 set_no_waku しても重複レコードを作らない）
$res_dup = toggleNoWakuRecord($pdo, [
    'dept'      => $test_dept,
    'date'      => $test_date,
    'action'    => 'set_no_waku',
    'waku'      => $test_waku,
    'base_id'   => $test_base_id,
    'orig_name' => $test_orig_name
]);
assert($res_dup['success'] === true, "Duplicate set_no_waku should succeed safely");
assert($res_dup['schedule_id'] === $created_sid, "Duplicate set_no_waku should return same schedule_id");
echo "[PASS] Duplicate set_no_waku guard verified\n";

// 4. revert_waku テスト
$res2 = toggleNoWakuRecord($pdo, [
    'dept'      => $test_dept,
    'date'      => $test_date,
    'action'    => 'revert_waku',
    'sid'       => $created_sid,
    'waku'      => $test_waku,
    'base_id'   => $test_base_id,
    'orig_name' => $test_orig_name
]);

assert($res2['success'] === true, "revert_waku should succeed");
assert($res2['schedule_id'] === 0, "schedule_id should be 0 on revert");
echo "[PASS] revert_waku successfully reverted\n";

// DB確認（削除されていること）
$stmt->execute([':sid' => $created_sid]);
$row2 = $stmt->fetch(PDO::FETCH_ASSOC);
assert($row2 === false, "Record should be deleted from DB");
echo "[PASS] DB record deletion verified\n";

// 5. 監査ログ確認
$stmt_log = $pdo->prepare("SELECT log_id, action_type, diff_summary FROM t_schedule_audit_log WHERE schedule_id = :sid ORDER BY log_id DESC LIMIT 2");
$stmt_log->execute([':sid' => $created_sid]);
$logs = $stmt_log->fetchAll(PDO::FETCH_ASSOC);
assert(count($logs) >= 2, "At least 2 audit logs (insert & delete) should be recorded");
assert($logs[0]['action_type'] === 'delete', "Latest log should be delete");
assert($logs[1]['action_type'] === 'insert', "Previous log should be insert");
echo "[PASS] Audit logs verified (insert & delete recorded)\n";

// クリーンアップ
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE department_code = :dept AND exam_date = :date AND patient_name = '※枠なし'")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);

echo "\n>>> All tests in test_async_toggle_no_waku.php passed successfully! <<<\n";
