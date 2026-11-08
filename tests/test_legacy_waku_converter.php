<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\tests\test_legacy_waku_converter.php
 * ファイル名: test_legacy_waku_converter.php
 * 概要: 従来枠なし・休止データコンバーター 単体テスト
 * 生成日時: 2026-10-23 10:52
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("DB接続失敗\n");
}

function assertTest($cond, $msg) {
    if ($cond) {
        echo "  [PASS] {$msg}\n";
    } else {
        echo "  [FAIL] {$msg}\n";
        exit(1);
    }
}

echo "=== 従来枠なし・休止データ コンバーター テスト開始 ===\n";

$test_date = '2026-11-29'; // 日曜日（通常検査なし日をテスト用に利用）
$test_dept = 'GASTRO';
$test_waku_name = 'GIF9:40';

// 1. クリーンアップ
$pdo->prepare("DELETE FROM m2_waku_override WHERE department_code = :dept AND target_date = :dt")->execute([':dept' => $test_dept, ':dt' => $test_date]);
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE department_code = :dept AND exam_date = :dt AND patient_name = '※枠なし'")->execute([':dept' => $test_dept, ':dt' => $test_date]);

// 2. テスト用レガシー休止レコードを投入
// 2. テスト用レガシー休止レコードを投入（既存レコードからクローン）
$stmt_ins = $pdo->prepare("
    INSERT INTO t_endoscopy_schedule (
        exam_date, waku_no, doctor_name, arrival_time, scheduled_time, exam_type, exam_location,
        patient_id, patient_name, birthday, patient_memo, karte_memo, remarks, is_canceled,
        department_code, waku_id, patient_kana, booking_status, room_number, bowel_prep,
        has_sedation, has_drive_caution, is_admission
    )
    SELECT 
        :dt, waku_no, doctor_name, arrival_time, :time, exam_type, exam_location,
        patient_id, '※枠なし', birthday, patient_memo, karte_memo, '＊＊＊🚫枠なし🚫＊＊＊', FALSE,
        :dept, waku_id, patient_kana, booking_status, room_number, bowel_prep,
        has_sedation, has_drive_caution, is_admission
    FROM t_endoscopy_schedule 
    WHERE patient_name = '※枠なし'
    LIMIT 1
    RETURNING schedule_id
");
$stmt_ins->execute([':dt' => $test_date, ':time' => $test_waku_name, ':dept' => $test_dept]);
$sched_id = (int)$stmt_ins->fetchColumn();
assertTest($sched_id > 0, "テスト用旧枠なしレコード作成 (ID: {$sched_id})");

// 3. コンバート処理をシミュレート
// 基本枠マスタ特定
$stmt_w = $pdo->prepare("SELECT id, group_code, display_order FROM m2_waku WHERE department_code = :dept AND waku_name = :name AND is_active = TRUE");
$stmt_w->execute([':dept' => $test_dept, ':name' => $test_waku_name]);
$waku = $stmt_w->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($waku['id']), "基本枠マスタとのマッチング確認 (ID: {$waku['id']})");

// m2_waku_override に休止レコード作成
$stmt_ov_ins = $pdo->prepare("
    INSERT INTO m2_waku_override (
        department_code, base_waku_id, override_type, target_date,
        display_waku_name, group_code, display_order, is_suspended, reason, is_active
    ) VALUES (
        :dept, :base_waku_id, 'DATE', :dt,
        :name, :grp, :ord, TRUE, '※枠なし (旧予約より移行)', TRUE
    ) RETURNING override_id
");
$stmt_ov_ins->execute([
    ':dept' => $test_dept,
    ':base_waku_id' => $waku['id'],
    ':dt' => $test_date,
    ':name' => $test_waku_name,
    ':grp' => $waku['group_code'],
    ':ord' => $waku['display_order']
]);
$ov_id = (int)$stmt_ov_ins->fetchColumn();
assertTest($ov_id > 0, "m2_waku_override への休止オーバーライド登録成功 (OverrideID: {$ov_id})");

// 元レコードのキャンセル更新
$stmt_cancel = $pdo->prepare("
    UPDATE t_endoscopy_schedule 
    SET is_canceled = TRUE, remarks = remarks || ' [新・枠休止オーバーライドへ移行済]'
    WHERE schedule_id = :id
");
$stmt_cancel->execute([':id' => $sched_id]);

// 4. 検証: 元レコードがキャンセル扱いになったか
$stmt_chk_sched = $pdo->prepare("SELECT is_canceled, remarks FROM t_endoscopy_schedule WHERE schedule_id = :id");
$stmt_chk_sched->execute([':id' => $sched_id]);
$sched_row = $stmt_chk_sched->fetch(PDO::FETCH_ASSOC);
assertTest($sched_row['is_canceled'] === true, "元の予約レコードが is_canceled=TRUE に更新されたこと");
assertTest(strpos($sched_row['remarks'], '[新・枠休止オーバーライドへ移行済]') !== false, "元の予約レコードの備考に移行済タグが付与されたこと");

// 5. 検証: getEffectiveWakuList で該当枠が休止（is_suspended=TRUE）になっているか
$effective = getEffectiveWakuList($pdo, $test_dept, $test_date);
$target_slot = null;
foreach ($effective as $slot) {
    if ($slot['waku_name'] === $test_waku_name) {
        $target_slot = $slot;
        break;
    }
}
assertTest(!empty($target_slot), "合成後の有効枠リストに該当枠が存在すること");
assertTest(!empty($target_slot['is_suspended']), "該当枠が新方式で休止（is_suspended=TRUE）と判定されること");

// 6. クリーンアップ
$pdo->prepare("DELETE FROM m2_waku_override WHERE override_id = :id")->execute([':id' => $ov_id]);
$pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :id")->execute([':id' => $sched_id]);
echo "  [PASS] テストデータ クリーンアップ完了\n";

echo "🎉 コンバーターの単体テストがすべて完全合格しました！\n";
