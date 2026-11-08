<?php
/**
 * tests/test_single_day_and_gear.php
 * 単一変更塗りつぶしモデル、🚫⭕従来互換操作、時間枠右⚙️名称変更、期間設定権限制御の自動テスト
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();

echo "=== 単一日塗りつぶし ＆ ギア名称変更 ＆ 権限制御 自動テスト開始 ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($cond, $title) {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$title}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$title}\n";
        $failCount++;
    }
}

// ------------------------------------------------------------
// テスト 1: 期間設定利用許可（isPeriodOverrideAllowed / setPeriodOverrideAllowed）
// ------------------------------------------------------------
echo "--- テスト 1: 期間設定の利用権限制御 ---\n";
// 最初は禁止にセット
setPeriodOverrideAllowed($pdo, false);
assertTest(isPeriodOverrideAllowed($pdo) === false, "デフォルト・禁止状態では isPeriodOverrideAllowed() が false");

// 許可にセット
setPeriodOverrideAllowed($pdo, true);
assertTest(isPeriodOverrideAllowed($pdo) === true, "許可後は isPeriodOverrideAllowed() が true");

// 再び禁止に戻す（現場保護標準）
setPeriodOverrideAllowed($pdo, false);
assertTest(isPeriodOverrideAllowed($pdo) === false, "禁止に戻すと isPeriodOverrideAllowed() が false");

// ------------------------------------------------------------
// テスト 2: 禁止状態での save_period_override 拒否ガード
// ------------------------------------------------------------
echo "\n--- テスト 2: 禁止状態での期間設定API拒否ガード ---\n";
$url = "http://localhost/endoscope/action_waku_override.php";
$postData = [
    'action'       => 'save_period_override',
    'dept'         => 'PROCT',
    'start_date'   => '2026-11-01',
    'end_date'     => '2026-11-03',
    'base_waku_id' => 31,
    'is_suspended' => '1',
    'reason'       => 'テスト期間休止'
];

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($postData),
        'timeout' => 5
    ]
]);

$resp = @file_get_contents($url, false, $ctx);
$json = json_decode($resp, true);
$errMsg = $json['error'] ?? $json['message'] ?? '';
assertTest(isset($json['success']) && $json['success'] === false, "禁止状態では save_period_override が拒絶される");
assertTest(strpos($errMsg, '許可されていません') !== false, "エラーメッセージに「許可されていません」が含まれる: {$errMsg}");

// ------------------------------------------------------------
// テスト 3: 許可状態での期間指定 ➔ 単一変更一括塗りつぶし（バルク展開）
// ------------------------------------------------------------
echo "\n--- テスト 3: 許可時の期間指定 ➔ 単一変更バルク塗りつぶし展開 ---\n";
setPeriodOverrideAllowed($pdo, true);

$postData['start_date'] = '2026-11-10';
$postData['end_date']   = '2026-11-12'; // 3日間

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($postData),
        'timeout' => 5
    ]
]);

$resp = @file_get_contents($url, false, $ctx);
$json = json_decode($resp, true);
assertTest(isset($json['success']) && $json['success'] === true, "許可状態では save_period_override が成功する");
assertTest(($json['days_count'] ?? 0) === 3, "3日分の単一日レコードが一括生成される");

// DB検証: PERIODではなくDATEレコードが3日分入っているか
$stmt = $pdo->prepare("SELECT target_date, override_type FROM m2_waku_override WHERE target_date BETWEEN '2026-11-10' AND '2026-11-12' AND base_waku_id = 31");
$stmt->execute();
$ovRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($ovRows) === 3, "DBに単一日（target_date）として3行格納されている");
$allDate = true;
foreach ($ovRows as $r) {
    if ($r['override_type'] !== 'DATE') { $allDate = false; break; }
}
assertTest($allDate === true, "すべてのレコードが override_type = 'DATE'（単日）として実体化されている（PERIODなし）");

// テストデータをクリーンアップ
$pdo->exec("DELETE FROM m2_waku_override WHERE target_date BETWEEN '2026-11-10' AND '2026-11-12' AND base_waku_id = 31");
setPeriodOverrideAllowed($pdo, false); // 元に戻す

// ------------------------------------------------------------
// テスト 4: 枠名称変更（⚙️ ギアアイコン連動 save_override）
// ------------------------------------------------------------
echo "\n--- テスト 4: 枠名称変更（⚙️ ギア連動 save_override） ---\n";
$renameData = [
    'action'            => 'save_override',
    'dept'              => 'PROCT',
    'date'              => '2026-11-15',
    'base_waku_id'      => 31,
    'display_waku_name' => '14:30(臨時エコー枠)',
    'is_suspended'      => '0'
];

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($renameData),
        'timeout' => 5
    ]
]);

$resp = @file_get_contents($url, false, $ctx);
$json = json_decode($resp, true);
assertTest(isset($json['success']) && $json['success'] === true, "save_override で枠名称変更が成功");

// 有効枠取得で枠名が反映されているか
$effList = getEffectiveWakuList($pdo, 'PROCT', '2026-11-15');
$foundRenamed = false;
foreach ($effList as $w) {
    if ($w['base_waku_id'] === 31 && $w['waku_name'] === '14:30(臨時エコー枠)') {
        $foundRenamed = true;
        break;
    }
}
assertTest($foundRenamed === true, "getEffectiveWakuList で変更後名称「14:30(臨時エコー枠)」が即時反映されている");

// 基本マスタ m2_waku は変更されていないことを確認
$stmt_bw = $pdo->prepare("SELECT waku_name FROM m2_waku WHERE id = 31");
$stmt_bw->execute();
$origBwName = $stmt_bw->fetchColumn();
assertTest($origBwName !== '14:30(臨時エコー枠)', "基本マスタ m2_waku は一切汚されず無傷である");

// クリーンアップ
$pdo->exec("DELETE FROM m2_waku_override WHERE target_date = '2026-11-15' AND base_waku_id = 31");

// ------------------------------------------------------------
// テスト 5: month_schedule.php の 🚫 (set_no_waku) と ⭕ (revert_waku) 連携
// ------------------------------------------------------------
echo "\n--- テスト 5: month_schedule.php の 🚫 (set_no_waku) と ⭕ (revert_waku) ---\n";
// 1. set_no_waku を呼ぶ
$testDate = '2026-11-20';
$testWaku = '14:30';
$urlNoWaku = "http://localhost/endoscope/month_schedule.php?dept=PROCT&year=2026&month=11&date={$testDate}&action=set_no_waku&waku=" . urlencode($testWaku);

$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'follow_location' => 0
    ]
]);
@file_get_contents($urlNoWaku, false, $ctx);

// t_endoscopy_schedule に備考「＊＊＊🚫枠なし🚫＊＊＊」が登録されたか
$stmt_chk = $pdo->prepare("SELECT schedule_id, remarks, patient_name FROM t_endoscopy_schedule WHERE exam_date = :d AND scheduled_time = :w AND department_code = 'PROCT'");
$stmt_chk->execute([':d' => $testDate, ':w' => $testWaku]);
$row_nw = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($row_nw) && $row_nw['remarks'] === '＊＊＊🚫枠なし🚫＊＊＊', "🚫 操作により備考「＊＊＊🚫枠なし🚫＊＊＊」が即座に登録された");

$sid = (int)($row_nw['schedule_id'] ?? 0);

// 2. revert_waku を呼ぶ
$urlRevert = "http://localhost/endoscope/month_schedule.php?dept=PROCT&year=2026&month=11&date={$testDate}&action=revert_waku&sid={$sid}&waku=" . urlencode($testWaku);
@file_get_contents($urlRevert, false, $ctx);

// レコードが削除されて通常枠に戻ったか
$stmt_chk->execute([':d' => $testDate, ':w' => $testWaku]);
$row_rev = $stmt_chk->fetch(PDO::FETCH_ASSOC);
assertTest(empty($row_rev), "⭕ 操作により枠なしダミー予約が完全削除され、通常枠に復帰した");

// ------------------------------------------------------------
// 結果集計
// ------------------------------------------------------------
echo "\n========================================\n";
echo "テスト結果: 合格: {$passCount} / 失敗: {$failCount}\n";
echo "========================================\n";

if ($failCount === 0) {
    echo "🎉 全項目合格！単一日塗りつぶし・🚫⭕従来操作・⚙️名称変更・権限制御が完全機能しています。\n";
    exit(0);
} else {
    echo "⚠️ 失敗した項目があります。\n";
    exit(1);
}
