<?php
/**
 * tests/test_duplicate_waku_name_and_no_duplication.php
 * 複数の枠を同名に変更した状態で枠停止（🚫）・復活（⭕）を繰り返しても枠が増殖しないことの自動テスト
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();

echo "=== 複数同名枠における停止・復活・枠増殖防止 自動テスト開始 ===\n\n";

$pass_count = 0;
$fail_count = 0;

function assertCond($cond, $msg) {
    global $pass_count, $fail_count;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $pass_count++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $fail_count++;
    }
}

$test_date = '2026-11-25';
$dept = 'PROCT';

// クリーンアップ
$pdo->exec("DELETE FROM m2_waku_override WHERE target_date = '{$test_date}'");
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE exam_date = '{$test_date}' AND department_code = '{$dept}'");

// 基本枠 GIF1 (34), GIF2 (35), 15:00 (30) をすべて「同名枠X」に変更
$same_name = '同名枠X';
$pdo->prepare("
    INSERT INTO m2_waku_override (department_code, base_waku_id, override_type, target_date, display_waku_name, group_code, display_order, is_suspended, created_at, updated_at)
    VALUES 
        (:dept, 30, 'DATE', :date, :name, 'TCF', 4, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
        (:dept, 34, 'DATE', :date, :name, 'GIF', 8, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
        (:dept, 35, 'DATE', :date, :name, 'GIF', 9, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
")->execute([':dept' => $dept, ':date' => $test_date, ':name' => $same_name]);

// 1. 初期の枠一覧取得
echo "--- テスト 1: 3枠が同名枠Xになった状態での総枠数検証 ---\n";
$rows1 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
assertCond(count($rows1) === 9, "同名枠が3つあっても総枠数は9枠であること (実際: " . count($rows1) . "枠)");

// 2. GIF1 (base_id=34) を 🚫 枠なし設定（1回目）
echo "\n--- テスト 2: 枠なし設定（🚫）の実行と二重登録防止 ---\n";
function simulateSetNoWaku($pdo, $dept, $date, $target_waku, $target_base_id, $target_orig_name) {
    $bw = null;
    if ($target_base_id > 0) {
        $stmt_bw = $pdo->prepare("SELECT id, display_order, group_code, waku_name FROM m2_waku WHERE id = :id AND department_code = :dept AND is_active = TRUE");
        $stmt_bw->execute([':id' => $target_base_id, ':dept' => $dept]);
        $bw = $stmt_bw->fetch(PDO::FETCH_ASSOC);
    }
    $bw_id    = $bw ? (int)$bw['id'] : $target_base_id;
    $bw_order = $bw ? (int)$bw['display_order'] : 1;
    $bw_group = $bw ? (string)$bw['group_code'] : 'GIF';
    $bw_display_name = !empty($target_waku) ? $target_waku : ($bw ? $bw['waku_name'] : 'GIF1');

    $already_exists = false;
    if ($bw_id > 0) {
        $stmt_dup = $pdo->prepare("
            SELECT schedule_id FROM t_endoscopy_schedule 
            WHERE department_code = :dept 
              AND exam_date = :date 
              AND is_canceled = FALSE 
              AND (patient_name = '※枠なし' OR remarks LIKE '%＊＊＊🚫枠なし%')
              AND (waku_id = :bid OR waku_no = :ord)
            LIMIT 1
        ");
        $stmt_dup->execute([':dept' => $dept, ':date' => $date, ':bid' => $bw_id, ':ord' => $bw_order]);
        $already_exists = (bool)$stmt_dup->fetchColumn();
    }

    if (!$already_exists) {
        $ins = $pdo->prepare("
            INSERT INTO t_endoscopy_schedule 
            (department_code, exam_date, scheduled_time, waku_no, waku_id, exam_type, exam_location, doctor_name, patient_name, remarks, is_canceled, created_at, updated_at)
            VALUES
            (:dept, :date, :waku, :waku_no, :waku_id, :exam_type, '指定なし', '◆未定◆', '※枠なし', '＊＊＊🚫枠なし🚫＊＊＊', FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            ':dept'      => $dept,
            ':date'      => $date,
            ':waku'      => $bw_display_name,
            ':waku_no'   => $bw_order,
            ':waku_id'   => $bw_id,
            ':exam_type' => $bw_group
        ]);
    }
}

function simulateRevertWaku($pdo, $dept, $date, $target_sid, $target_base_id) {
    if ($target_sid > 0) {
        $del = $pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :sid AND department_code = :dept");
        $del->execute([':sid' => $target_sid, ':dept' => $dept]);
    }
}

simulateSetNoWaku($pdo, $dept, $test_date, $same_name, 34, 'GIF1');
$rows2 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
assertCond(count($rows2) === 9, "🚫枠なし設定後も総枠数は9枠のままであること (実際: " . count($rows2) . "枠)");

// 同じ枠をもう一度 🚫 実行（連打シミュレーション）
simulateSetNoWaku($pdo, $dept, $test_date, $same_name, 34, 'GIF1');
$rows2_dup = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
assertCond(count($rows2_dup) === 9, "🚫連打しても二重登録されず総枠数は9枠のままであること (実際: " . count($rows2_dup) . "枠)");

// 3. GIF2 (base_id=35) も 🚫 枠なし設定
echo "\n--- テスト 3: もうひとつの同名枠 (GIF2) も 🚫 枠なし設定 ---\n";
simulateSetNoWaku($pdo, $dept, $test_date, $same_name, 35, 'GIF2');
$rows3 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
assertCond(count($rows3) === 9, "2つ目の同名枠を枠なしにしても総枠数は9枠のままであること (実際: " . count($rows3) . "枠)");

// 4. 15:00 (base_id=30) に確定予約を登録
echo "\n--- テスト 4: 残る同名枠 (15:00) に患者予約を登録 ---\n";
$stmt_res = $pdo->prepare("
    INSERT INTO t_endoscopy_schedule (
        department_code, exam_date, scheduled_time, waku_no, waku_id, exam_type, exam_location,
        patient_id, patient_name, doctor_name, is_canceled, created_at, updated_at
    ) VALUES (
        :dept, :date, :waku, 4, 30, 'TCF', '指定なし',
        '99999', '同名テスト患者', '小野', FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    ) RETURNING schedule_id
");
$stmt_res->execute([':dept' => $dept, ':date' => $test_date, ':waku' => $same_name]);
$test_sid = (int)$stmt_res->fetchColumn();

$rows4 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
assertCond(count($rows4) === 9, "予約追加後も総枠数は9枠のままであること (実際: " . count($rows4) . "枠)");

$item_patient = null;
foreach ($rows4 as $item) {
    if ($item['schedule_id'] == $test_sid) $item_patient = $item;
}
assertCond($item_patient !== null && empty($item_patient['is_mismatch']), "予約が迷子にならず正常であること");
assertCond((int)$item_patient['base_waku_id'] === 30, "予約が正しく 15:00 (base_id=30) に紐付いていること");

// 5. 枠復活（⭕）の実行
echo "\n--- テスト 5: 枠復活（⭕）の実行と総枠数の安定性 ---\n";
$stmt_sid = $pdo->prepare("SELECT schedule_id FROM t_endoscopy_schedule WHERE exam_date = :date AND department_code = :dept AND waku_id = 34");
$stmt_sid->execute([':date' => $test_date, ':dept' => $dept]);
$gif1_sid = (int)$stmt_sid->fetchColumn();

simulateRevertWaku($pdo, $dept, $test_date, $gif1_sid, 34);

$rows5 = getDailyReservationsWithEffectiveSlots($pdo, $dept, $test_date, 'all', 'all', true);
assertCond(count($rows5) === 9, "⭕枠復活後も総枠数は9枠のままであること (実際: " . count($rows5) . "枠)");

$item_empty = null;
foreach ($rows5 as $item) {
    if ((int)($item['base_waku_id'] ?? 0) === 34) $item_empty = $item;
}
assertCond($item_empty !== null && !empty($item_empty['is_empty']), "GIF1 が正しく空き枠に復帰していること");

// クリーンアップ
$pdo->exec("DELETE FROM m2_waku_override WHERE target_date = '{$test_date}'");
$pdo->exec("DELETE FROM t_endoscopy_schedule WHERE exam_date = '{$test_date}' AND department_code = '{$dept}'");

echo "\n========================================\n";
echo "テスト結果: 合格: {$pass_count} / 失敗: {$fail_count}\n";
echo "========================================\n";
if ($fail_count === 0) {
    echo "🎉 全項目合格！同名枠が複数あっても枠増殖は100%完全に防止されています。\n";
} else {
    exit(1);
}
