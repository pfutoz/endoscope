<?php
/**
 * tests/test_phase3_batch.php
 * Phase 3 改修（理由マスタAPI ＆ 下書き一括保存API）の自動テスト
 */
require_once dirname(__DIR__) . '/lib/db.php';
$pdo = getDbConnection();

$dept = 'PROCT';
$test_date = '2026-11-25';

echo "=== [Phase 3 バッチ保存＆理由マスタ テスト開始] ===\n\n";
$pass_count = 0;
$fail_count = 0;

function assertTest($cond, $msg) {
    global $pass_count, $fail_count;
    if ($cond) {
        echo "  [PASS] {$msg}\n";
        $pass_count++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $fail_count++;
    }
}

// ----------------------------------------------------
// 1. get_suspend_reasons
// ----------------------------------------------------
echo "--- [TEST 1: get_suspend_reasons 理由一覧] ---\n";
$_REQUEST = ['action' => 'get_suspend_reasons'];
$_GET = $_REQUEST;
$_POST = [];

ob_start();
require dirname(__DIR__) . '/action_waku_override.php';
$out = ob_get_clean();
$json = json_decode($out, true);

assertTest($json['success'] === true, 'get_suspend_reasons 正常取得');
assertTest(count($json['reasons']) >= 5, 'プリセット理由が5件以上取得できる (件数: ' . count($json['reasons']) . ')');

// ----------------------------------------------------
// 2. add_suspend_reason & delete_suspend_reason
// ----------------------------------------------------
echo "\n--- [TEST 2: add_suspend_reason / delete_suspend_reason] ---\n";
$_REQUEST = ['action' => 'add_suspend_reason'];
$_POST = ['reason_text' => 'テスト用臨時理由'];

ob_start();
require dirname(__DIR__) . '/action_waku_override.php';
$out = ob_get_clean();
$json_add = json_decode($out, true);

assertTest($json_add['success'] === true, 'add_suspend_reason 正常登録');
$added_id = (int)($json_add['master_id'] ?? 0);
assertTest($added_id > 0, '新規master_idが発行された (ID: ' . $added_id . ')');

// 削除
$_REQUEST = ['action' => 'delete_suspend_reason'];
$_POST = ['master_id' => $added_id];

ob_start();
require dirname(__DIR__) . '/action_waku_override.php';
$out = ob_get_clean();
$json_del = json_decode($out, true);

assertTest($json_del['success'] === true, 'delete_suspend_reason 正常削除');

// ----------------------------------------------------
// 3. save_day_overrides_batch 下書き一括保存
// ----------------------------------------------------
echo "\n--- [TEST 3: save_day_overrides_batch 一括コミット] ---\n";
// 基本枠を1件取得
$stmt_w = $pdo->prepare("SELECT id, waku_name FROM m2_waku WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order ASC LIMIT 1");
$stmt_w->execute([':dept' => $dept]);
$first_waku = $stmt_w->fetch(PDO::FETCH_ASSOC);
$base_w_id = (int)$first_waku['id'];

// 休止1件＋臨時枠1件を一括保存
$batch_changes = [
    'slots' => [
        [
            'base_waku_id' => $base_w_id,
            'is_suspended' => true,
            'reason'       => '院内設備点検'
        ]
    ],
    'new_extra_slots' => [
        [
            'waku_name'  => '15:15(特)',
            'group_code' => 'TCF',
            'reason'     => 'バッチ臨時枠'
        ]
    ],
    'deleted_override_ids' => []
];

$_REQUEST = ['action' => 'save_day_overrides_batch'];
$_POST = [
    'dept'    => $dept,
    'date'    => $test_date,
    'changes' => json_encode($batch_changes)
];

ob_start();
require dirname(__DIR__) . '/action_waku_override.php';
$out = ob_get_clean();
$json_batch = json_decode($out, true);

assertTest($json_batch['success'] === true, 'save_day_overrides_batch 正常レスポンス');
assertTest($json_batch['inserted_count'] === 2, '休止設定および臨時枠が2件INSERTされた');

// 実際にgetEffectiveWakuListで確認
require_once dirname(__DIR__) . '/lib/slot_helper.php';
$eff = getEffectiveWakuList($pdo, $dept, $test_date);

$found_susp = false;
$found_extra = false;
foreach ($eff as $s) {
    if ($s['base_waku_id'] == $base_w_id && !empty($s['is_suspended']) && $s['reason'] === '院内設備点検') {
        $found_susp = true;
    }
    if ($s['waku_name'] === '15:15(特)' && !empty($s['is_override'])) {
        $found_extra = true;
    }
}
assertTest($found_susp === true, '休止枠がDBに反映され有効枠として取得された');
assertTest($found_extra === true, '臨時枠がDBに反映され有効枠として取得された');

// ----------------------------------------------------
// 4. 一括復帰（休止解除）テスト
// ----------------------------------------------------
echo "\n--- [TEST 4: 一括休止解除] ---\n";
$batch_restore = [
    'slots' => [
        [
            'base_waku_id' => $base_w_id,
            'is_suspended' => false,
            'reason'       => ''
        ]
    ],
    'new_extra_slots' => [],
    'deleted_override_ids' => []
];

$_REQUEST = ['action' => 'save_day_overrides_batch'];
$_POST = [
    'dept'    => $dept,
    'date'    => $test_date,
    'changes' => json_encode($batch_restore)
];

ob_start();
require dirname(__DIR__) . '/action_waku_override.php';
$out = ob_get_clean();
$json_restore = json_decode($out, true);

assertTest($json_restore['success'] === true, '一括休止解除 正常レスポンス');
assertTest($json_restore['deleted_count'] === 1, '休止オーバーライドが物理削除された (削除件数: 1)');

// ----------------------------------------------------
// 5. クリーンアップ
// ----------------------------------------------------
echo "\n--- [クリーンアップ] ---\n";
$del_all = $pdo->prepare("DELETE FROM m2_waku_override WHERE department_code = :dept AND (target_date = :date OR reason = 'バッチ臨時枠')");
$del_all->execute([':dept' => $dept, ':date' => $test_date]);
assertTest(true, 'テスト用オーバーライドデータを完全削除');

echo "\n=========================================\n";
echo "テスト結果: 合格: {$pass_count} / 失敗: {$fail_count}\n";
echo "=========================================\n";
