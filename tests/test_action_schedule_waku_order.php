<?php
/**
 * 枠名称変更・オーバーライド時の予約表示順序（waku_no）判定テスト
 * 生成日時: 2026-10-23
 */
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$code = file_get_contents(__DIR__ . '/../action_schedule.php');
if (preg_match('/function getWakuDisplayOrderFromM2\(.+?\n\}/s', $code, $m)) {
    eval($m[0]);
} else {
    die("getWakuDisplayOrderFromM2 not found");
}

$pdo = getDbConnection();
$test_date = '2099-07-07';
$dept = 'GASTRO';

echo "=== action_schedule.php waku_no 取得テスト開始 ===\n";

// 1. 基本枠を1つ取得
$stmt = $pdo->prepare("SELECT id, waku_name, display_order FROM m2_waku WHERE department_code = :dept ORDER BY display_order ASC LIMIT 1");
$stmt->execute([':dept' => $dept]);
$base = $stmt->fetch(PDO::FETCH_ASSOC);

// テストA: 静的マスタの枠名で正しく waku_no が取れるか
$orderA = getWakuDisplayOrderFromM2($pdo, $dept, $base['waku_name'], $test_date);
assert($orderA === (int)$base['display_order'], "Test A failed: expected {$base['display_order']}, got {$orderA}");
echo "  [PASS] 静的マスタ枠名で正常取得 (order={$orderA})\n";

// テストB: オーバーライドで枠名を変更した場合、新枠名で正しい display_order が取れるか
$override_name = '2099特設枠_99';
$ins = $pdo->prepare("
    INSERT INTO m2_waku_override 
    (department_code, override_type, target_date, base_waku_id, display_waku_name, group_code, display_order, is_suspended, reason)
    VALUES
    (:dept, 'DATE', :d, :bid, :wname, 'GIF', :d_order, FALSE, 'テスト')
");
$ins->execute([
    ':dept' => $dept,
    ':d' => $test_date,
    ':bid' => $base['id'],
    ':wname' => $override_name,
    ':d_order' => $base['display_order']
]);

// 新枠名で呼ぶ
$orderB = getWakuDisplayOrderFromM2($pdo, $dept, $override_name, $test_date);
assert($orderB === (int)$base['display_order'], "Test B failed: expected {$base['display_order']}, got {$orderB}");
echo "  [PASS] オーバーライド枠名で正常取得 (order={$orderB})\n";

// 元枠名（base_waku_idの元の名前）で呼んでもヒットするか
$orderB_orig = getWakuDisplayOrderFromM2($pdo, $dept, $base['waku_name'], $test_date);
assert($orderB_orig === (int)$base['display_order'], "Test B (orig) failed: expected {$base['display_order']}, got {$orderB_orig}");
echo "  [PASS] 元枠名でもフォールバック正常取得 (order={$orderB_orig})\n";

// クリーンアップ
$pdo->prepare("DELETE FROM m2_waku_override WHERE target_date = :d")->execute([':d' => $test_date]);
echo "========================================\n";
echo "テスト結果: 全項目合格 (3/3)\n";
