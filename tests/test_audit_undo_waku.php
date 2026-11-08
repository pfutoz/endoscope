<?php
/**
 * tests/test_audit_undo_waku.php
 * 時間枠操作の監査ログ記録 ＆ ワンクリック復元（Undo）検証スクリプト
 * 
 * 生成日時: 2026-10-22 19:15
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/audit_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("DB接続失敗\n");
}

echo "=== 時間枠操作 Undo 復元 自動テスト開始 ===\n\n";

$test_dept = 'PROCT';
$test_date = '2026-12-15'; // テスト専用日付

// 事前クリーンアップ
$pdo->prepare("DELETE FROM m2_waku_override WHERE department_code = :dept AND target_date = :date")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE department_code = :dept AND exam_date = :date")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);

// 基本枠IDの取得
$stmt_bw = $pdo->prepare("SELECT id, waku_name FROM m2_waku WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order ASC LIMIT 1");
$stmt_bw->execute([':dept' => $test_dept]);
$bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
$base_waku_id = (int)$bw['id'];
$base_waku_name = $bw['waku_name'];

echo "対象基本枠: ID={$base_waku_id}, 名={$base_waku_name}\n";

// -------------------------------------------------------------------------
// テスト1: 新規休止登録 ➔ Undo ➔ 通常枠（オーバーライド削除）への復元
// -------------------------------------------------------------------------
echo "\n--- テスト1: 新規休止登録 ➔ Undo 復元 ---\n";

// 1-1. 休止オーバーライドを登録
$stmt_ins = $pdo->prepare("
    INSERT INTO m2_waku_override (
        department_code, base_waku_id, override_type, target_date,
        display_waku_name, group_code, display_order, is_suspended, reason,
        created_at, updated_at
    ) VALUES (
        :dept, :base_id, 'DATE', :date,
        :name, 'GIF', 1, TRUE, 'テスト休止理由',
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    ) RETURNING override_id
");
$stmt_ins->execute([
    ':dept'    => $test_dept,
    ':base_id' => $base_waku_id,
    ':date'    => $test_date,
    ':name'    => $base_waku_name
]);
$override_id1 = (int)$stmt_ins->fetchColumn();

// 監査ログ記録
$log_id1 = recordScheduleAuditLog($pdo, 'update', 0, null, [
    'override_id'       => $override_id1,
    'department_code'   => $test_dept,
    'target_date'       => $test_date,
    'base_waku_id'      => $base_waku_id,
    'display_waku_name' => $base_waku_name,
    'is_suspended'      => true,
    'reason'            => 'テスト休止理由'
], [
    'department_code' => $test_dept,
    'description'     => "【枠休止設定】{$test_date} {$test_dept} {$base_waku_name} を休止に設定 (理由: テスト休止理由)",
    'diff_summary'    => "枠休止登録"
]);

echo "1-1. 休止オーバーライド登録完了: override_id={$override_id1}, log_id={$log_id1}\n";

// 1-2. Undo 実行
$undo_res1 = undoScheduleAuditLog($pdo, $log_id1, 'テストオペレータ');
echo "1-2. Undo実行結果: " . json_encode($undo_res1, JSON_UNESCAPED_UNICODE) . "\n";

if (!$undo_res1['success']) {
    die("❌ テスト1失敗: Undo実行エラー: " . $undo_res1['message'] . "\n");
}

// 1-3. オーバーライドが削除され通常枠に戻ったか確認
$stmt_chk1 = $pdo->prepare("SELECT COUNT(*) FROM m2_waku_override WHERE override_id = :id");
$stmt_chk1->execute([':id' => $override_id1]);
$count1 = (int)$stmt_chk1->fetchColumn();

if ($count1 === 0) {
    echo "✅ テスト1成功: 休止オーバーライドが正常に削除され、通常枠へ復元されました\n";
} else {
    die("❌ テスト1失敗: オーバーライドレコードがまだ残っています\n");
}

// -------------------------------------------------------------------------
// テスト2: 枠名変更（一括更新） ➔ Undo ➔ 元の枠名への復元
// -------------------------------------------------------------------------
echo "\n--- テスト2: 枠名変更 ➔ Undo 復元 ---\n";

// 2-1. 既存オーバーライドを作成
$stmt_ins2 = $pdo->prepare("
    INSERT INTO m2_waku_override (
        department_code, base_waku_id, override_type, target_date,
        display_waku_name, group_code, display_order, is_suspended, reason,
        created_at, updated_at
    ) VALUES (
        :dept, :base_id, 'DATE', :date,
        '元枠名A', 'GIF', 1, FALSE, NULL,
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    ) RETURNING override_id
");
$stmt_ins2->execute([
    ':dept'    => $test_dept,
    ':base_id' => $base_waku_id,
    ':date'    => $test_date
]);
$override_id2 = (int)$stmt_ins2->fetchColumn();

$old_row2 = [
    'override_id'       => $override_id2,
    'department_code'   => $test_dept,
    'target_date'       => $test_date,
    'base_waku_id'      => $base_waku_id,
    'display_waku_name' => '元枠名A',
    'is_suspended'      => false,
    'reason'            => null
];

// 枠名を変更 UPDATE
$pdo->prepare("UPDATE m2_waku_override SET display_waku_name = '変更後枠名B' WHERE override_id = :id")
    ->execute([':id' => $override_id2]);

$new_row2 = [
    'override_id'       => $override_id2,
    'department_code'   => $test_dept,
    'target_date'       => $test_date,
    'base_waku_id'      => $base_waku_id,
    'display_waku_name' => '変更後枠名B',
    'is_suspended'      => false,
    'reason'            => null
];

$log_id2 = recordScheduleAuditLog($pdo, 'update', 0, $old_row2, $new_row2, [
    'department_code' => $test_dept,
    'description'     => "【枠設定更新】{$test_date} 枠ID:{$base_waku_id} (表示名: 変更後枠名B)",
    'diff_summary'    => "枠日別オーバーライド一括更新"
]);

echo "2-1. 枠名変更完了: override_id={$override_id2}, log_id={$log_id2}\n";

// 2-2. Undo 実行
$undo_res2 = undoScheduleAuditLog($pdo, $log_id2, 'テストオペレータ');
echo "2-2. Undo実行結果: " . json_encode($undo_res2, JSON_UNESCAPED_UNICODE) . "\n";

if (!$undo_res2['success']) {
    die("❌ テスト2失敗: Undo実行エラー: " . $undo_res2['message'] . "\n");
}

// 2-3. 枠名が「元枠名A」に戻ったか確認
$stmt_chk2 = $pdo->prepare("SELECT display_waku_name FROM m2_waku_override WHERE override_id = :id");
$stmt_chk2->execute([':id' => $override_id2]);
$curr_name2 = $stmt_chk2->fetchColumn();

if ($curr_name2 === '元枠名A') {
    echo "✅ テスト2成功: 枠名が「変更後枠名B」から「元枠名A」へ正常に復元されました\n";
} else {
    die("❌ テスト2失敗: 復元後の枠名が '{$curr_name2}' です（期待値: 元枠名A）\n");
}

// -------------------------------------------------------------------------
// テスト3: 休止解除（通常復帰） ➔ Undo ➔ 再び休止状態への復元
// -------------------------------------------------------------------------
echo "\n--- テスト3: 休止解除 ➔ Undo 復元（再休止） ---\n";

// 3-1. 休止解除前のオーバーライド（休止状態）
$old_row3 = [
    'override_id'       => $override_id2,
    'department_code'   => $test_dept,
    'base_waku_id'      => $base_waku_id,
    'override_type'     => 'DATE',
    'target_date'       => $test_date,
    'display_waku_name' => $base_waku_name,
    'group_code'        => 'GIF',
    'display_order'     => 1,
    'is_suspended'      => true,
    'reason'            => '一時休診'
];

// 休止解除操作（DELETE）
$pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :id")->execute([':id' => $override_id2]);

$log_id3 = recordScheduleAuditLog($pdo, 'delete', 0, $old_row3, null, [
    'department_code' => $test_dept,
    'description'     => "【枠設定解除】{$test_date} 枠ID:{$base_waku_id} (基本枠復帰)",
    'diff_summary'    => "枠日別オーバーライド一括解除"
]);

echo "3-1. 休止解除完了（オーバーライド削除）: log_id={$log_id3}\n";

// 3-2. Undo 実行（休止状態へ再復元）
$undo_res3 = undoScheduleAuditLog($pdo, $log_id3, 'テストオペレータ');
echo "3-2. Undo実行結果: " . json_encode($undo_res3, JSON_UNESCAPED_UNICODE) . "\n";

if (!$undo_res3['success']) {
    die("❌ テスト3失敗: Undo実行エラー: " . $undo_res3['message'] . "\n");
}

// 3-3. 休止オーバーライドが再登録されているか確認
$stmt_chk3 = $pdo->prepare("SELECT is_suspended, reason FROM m2_waku_override WHERE department_code = :dept AND target_date = :date AND base_waku_id = :bid");
$stmt_chk3->execute([':dept' => $test_dept, ':date' => $test_date, ':bid' => $base_waku_id]);
$row3 = $stmt_chk3->fetch(PDO::FETCH_ASSOC);

if ($row3 && ($row3['is_suspended'] === true || $row3['is_suspended'] === 't' || $row3['is_suspended'] == 1) && $row3['reason'] === '一時休診') {
    echo "✅ テスト3成功: 解除された休止設定が正常に再登録（再休止: 一時休診）されました\n";
} else {
    die("❌ テスト3失敗: 再登録状態が不正です: " . json_encode($row3) . "\n");
}

// -------------------------------------------------------------------------
// テスト4: 確定予約が存在する場合の休止復元ガードテスト
// -------------------------------------------------------------------------
echo "\n--- テスト4: 確定予約あり枠の休止復元拒否ガード ---\n";

// 4-1. いったん枠を通常復帰（休止解除）
$pdo->prepare("DELETE FROM m2_waku_override WHERE department_code = :dept AND target_date = :date")->execute([':dept' => $test_dept, ':date' => $test_date]);

$log_id4 = recordScheduleAuditLog($pdo, 'delete', 0, $old_row3, null, [
    'department_code' => $test_dept,
    'description'     => "【枠設定解除】{$test_date} 枠ID:{$base_waku_id} (基本枠復帰)",
    'diff_summary'    => "枠日別オーバーライド一括解除"
]);

// 4-2. ここで当該枠に確定患者予約を1件登録する
$stmt_ins_sched = $pdo->prepare("
    INSERT INTO t_endoscopy_schedule (
        department_code, exam_date, scheduled_time, waku_no,
        patient_id, patient_name, patient_kana, doctor_name, exam_type, exam_location, is_canceled,
        created_at, updated_at
    ) VALUES (
        :dept, :date, :time, 1,
        '999999', '衝突検証患者', 'ショウトツケンショウ', 'テスト医師', 'GF', '検査室1', FALSE,
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    ) RETURNING schedule_id
");
$stmt_ins_sched->execute([
    ':dept' => $test_dept,
    ':date' => $test_date,
    ':time' => $base_waku_name
]);
$test_sched_id = (int)$stmt_ins_sched->fetchColumn();

echo "4-2. 確定予約を登録: schedule_id={$test_sched_id}, 枠名={$base_waku_name}\n";

// 4-3. Undo（休止復元）を試行 ➔ 予約があるため拒否されるべき
$undo_res4 = undoScheduleAuditLog($pdo, $log_id4, 'テストオペレータ');
echo "4-3. Undo試行結果: " . json_encode($undo_res4, JSON_UNESCAPED_UNICODE) . "\n";

if (!$undo_res4['success'] && strpos($undo_res4['message'], '確定予約') !== false) {
    echo "✅ テスト4成功: 確定予約（衝突検証患者様）の存在を正しく検知し、休止への復元を安全に拒絶しました！\n";
} else {
    die("❌ テスト4失敗: 確定予約があるにもかかわらず休止復元が許可されたか、エラーメッセージが不正です\n");
}

// -------------------------------------------------------------------------
// クリーンアップ
// -------------------------------------------------------------------------
echo "\n--- テストデータのクリーンアップ ---\n";
$pdo->prepare("DELETE FROM m2_waku_override WHERE department_code = :dept AND target_date = :date")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE department_code = :dept AND exam_date = :date")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);
$pdo->prepare("DELETE FROM t_schedule_audit_log WHERE department_code = :dept AND (new_exam_date = :date OR old_exam_date = :date)")
    ->execute([':dept' => $test_dept, ':date' => $test_date]);

echo "クリーンアップ完了。\n";
echo "\n🎉 全ての自動テスト（テスト1〜テスト4）が合格しました！\n";
