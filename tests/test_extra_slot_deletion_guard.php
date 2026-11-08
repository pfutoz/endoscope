<?php
/**
 * 予約済み臨時枠の削除防止ガード単体テスト
 * 生成日時: 2026-10-23
 */
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();
$test_date = '2099-08-08';
$dept = 'PROCT';
$extra_name = '2099臨時テスト枠_01';

echo "=== 予約済み臨時枠の削除ガード検証テスト ===\n";

// クリーンアップ
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE exam_date = :d")->execute([':d' => $test_date]);
$pdo->prepare("DELETE FROM m2_waku_override WHERE target_date = :d")->execute([':d' => $test_date]);

// 1. 臨時枠を追加
$stmt_ins = $pdo->prepare("
    INSERT INTO m2_waku_override 
    (department_code, base_waku_id, override_type, target_date, display_waku_name, group_code, display_order, is_suspended, reason)
    VALUES
    (:dept, NULL, 'DATE', :d, :name, 'TCF', 99, FALSE, 'テスト臨時枠')
    RETURNING override_id
");
$stmt_ins->execute([':dept' => $dept, ':d' => $test_date, ':name' => $extra_name]);
$override_id = (int)$stmt_ins->fetchColumn();

// 2. その臨時枠に予約を登録
$stmt_res = $pdo->prepare("
    INSERT INTO t_endoscopy_schedule 
    (department_code, exam_date, scheduled_time, waku_no, doctor_name, exam_location, patient_id, patient_name, exam_type, is_canceled)
    VALUES
    (:dept, :d, :time, 99, '小野 義二', '内視鏡室', '999999', 'テスト患者 様', 'TCF', FALSE)
    RETURNING schedule_id
");
$stmt_res->execute([':dept' => $dept, ':d' => $test_date, ':time' => $extra_name]);
$schedule_id = (int)$stmt_res->fetchColumn();

function callApiTest($data) {
    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open('php ' . escapeshellarg(__DIR__ . '/../scratch/call_api.php'), $descriptorspec, $pipes);
    fwrite($pipes[0], json_encode($data));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    return json_decode($output, true) ?: $output;
}

// 3. テストA: save_day_overrides_batch でこの臨時枠を削除しようとした場合、例外でブロックされるか
$resA = callApiTest([
    'action'  => 'save_day_overrides_batch',
    'dept'    => $dept,
    'date'    => $test_date,
    'changes' => json_encode([
        'slots' => [],
        'new_extra_slots' => [],
        'updated_extra_slots' => [],
        'deleted_override_ids' => [$override_id]
    ])
]);

assert(is_array($resA) && $resA['success'] === false && strpos($resA['error'], '既に患者の確定予約') !== false, "Test A failed");
echo "  [PASS] 一括削除API (save_day_overrides_batch) で予約存在枠の削除を完全ブロック\n";

// 4. テストB: delete_override 単体削除APIでも同様にブロックされるか
$resB = callApiTest([
    'action'      => 'delete_override',
    'override_id' => $override_id
]);

assert(is_array($resB) && $resB['success'] === false && strpos($resB['error'], '既に患者の確定予約') !== false, "Test B failed");
echo "  [PASS] 単体削除API (delete_override) で予約存在枠の削除を完全ブロック\n";

// 5. 予約をキャンセルした後は、正常に削除できるか
$pdo->prepare("UPDATE t_endoscopy_schedule SET is_canceled = TRUE WHERE schedule_id = :id")->execute([':id' => $schedule_id]);

$resC = callApiTest([
    'action'      => 'delete_override',
    'override_id' => $override_id
]);

assert(is_array($resC) && $resC['success'] === true, "Test C failed");
echo "  [PASS] 予約キャンセル後の安全削除は正常に許可\n";

// クリーンアップ
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE exam_date = :d")->execute([':d' => $test_date]);
$pdo->prepare("DELETE FROM m2_waku_override WHERE target_date = :d")->execute([':d' => $test_date]);

echo "========================================\n";
echo "テスト結果: 全項目合格 (3/3)\n";
