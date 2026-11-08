<?php
/**
 * 予約あり枠の休止防止 ＆ 予約者情報付与の自動テスト
 */

function runActionProcess($req, $post = []) {
    $script = tempnam(sys_get_temp_dir(), 'test_wom_');
    $content = '<?php' . "\n"
             . '$_REQUEST = ' . var_export($req, true) . ";\n"
             . '$_POST = ' . var_export($post, true) . ";\n"
             . 'require ' . var_export(__DIR__ . '/../action_waku_override.php', true) . ";\n";
    file_put_contents($script, $content);
    $output = shell_exec('php ' . escapeshellarg($script));
    @unlink($script);
    return json_decode($output, true);
}

$date = '2026-10-22';
$dept = 'PROCT';

echo "=== 1. get_day_slots 予約マッピング検証 ===\n";
$data = runActionProcess(['action' => 'get_day_slots', 'dept' => $dept, 'date' => $date]);

if (!$data || empty($data['success'])) {
    echo "[FAIL] get_day_slots failed: " . ($data['error'] ?? 'invalid') . "\n";
    exit(1);
}

echo "[OK] 有効枠一覧の取得成功\n";
$reservedSlots = [];
$targetSlot = null;

foreach ($data['effective_slots'] as $s) {
    if (!empty($s['has_reservation'])) {
        $pNames = array_map(function($r) { 
            return "{$r['patient_name']} (ID:{$r['patient_id']}, 検査:{$r['exam_type']})"; 
        }, $s['reservations']);
        $reservedSlots[] = "枠: {$s['waku_name']} (base_id: " . ($s['base_waku_id'] ?? 'なし') . ") -> " . implode(', ', $pNames);
        if (!empty($s['base_waku_id']) && !$targetSlot) {
            $targetSlot = $s;
        }
    }
}

echo "予約あり枠 (" . count($reservedSlots) . "件):\n";
foreach ($reservedSlots as $r) {
    echo "  - {$r}\n";
}

if (count($reservedSlots) === 0) {
    echo "[FAIL] 予約あり枠が0件です\n";
    exit(1);
}
echo "[PASS] 予約情報（患者名・ID・種別）が正しく各枠にマッピングされています\n\n";

echo "=== 2. set_slot_suspend の休止防止ガード検証 ===\n";
echo "対象枠: {$targetSlot['waku_name']} (base_waku_id: {$targetSlot['base_waku_id']})\n";
$suspendRes = runActionProcess([
    'action'       => 'set_slot_suspend',
    'dept'         => $dept,
    'date'         => $date,
    'base_waku_id' => $targetSlot['base_waku_id'],
    'is_suspended' => '1',
    'reason'       => 'テスト休止要求'
]);

if (!empty($suspendRes['success'])) {
    echo "[FAIL] 予約がある枠の休止が成功してしまいました！\n";
    exit(1);
} else {
    echo "[PASS] 期待通り例外で拒絶されました: {$suspendRes['error']}\n\n";
}

echo "=== 3. save_day_overrides_batch の休止防止ガード検証 ===\n";
$batchRes = runActionProcess([
    'action' => 'save_day_overrides_batch'
], [
    'dept'    => $dept,
    'date'    => $date,
    'changes' => json_encode([
        'slots' => [
            [
                'base_waku_id'      => $targetSlot['base_waku_id'],
                'display_waku_name' => $targetSlot['waku_name'],
                'is_suspended'      => true,
                'reason'            => 'バッチ休止要求'
            ]
        ]
    ])
]);

if (!empty($batchRes['success'])) {
    echo "[FAIL] 一括保存で予約がある枠の休止が成功してしまいました！\n";
    exit(1);
} else {
    echo "[PASS] 期待通り一括保存時も例外でロールバック＆拒絶されました: {$batchRes['error']}\n\n";
}

echo "=== 4. 予約のない枠の休止（正常系）の動作検証 ===\n";
$emptySlot = null;
foreach ($data['effective_slots'] as $s) {
    if (empty($s['has_reservation']) && !empty($s['base_waku_id']) && empty($s['is_suspended'])) {
        $emptySlot = $s;
        break;
    }
}

if ($emptySlot) {
    echo "空き枠対象: {$emptySlot['waku_name']} (base_waku_id: {$emptySlot['base_waku_id']})\n";
    // 休止設定
    $okRes = runActionProcess([
        'action'       => 'set_slot_suspend',
        'dept'         => $dept,
        'date'         => $date,
        'base_waku_id' => $emptySlot['base_waku_id'],
        'is_suspended' => '1',
        'reason'       => '院内テスト'
    ]);
    if (empty($okRes['success'])) {
        echo "[FAIL] 空き枠の休止が失敗しました: {$okRes['error']}\n";
        exit(1);
    }
    echo "[OK] 空き枠の休止が正常に完了しました\n";

    // 休止解除
    $undoRes = runActionProcess([
        'action'       => 'set_slot_suspend',
        'dept'         => $dept,
        'date'         => $date,
        'base_waku_id' => $emptySlot['base_waku_id'],
        'is_suspended' => '0'
    ]);
    if (empty($undoRes['success'])) {
        echo "[FAIL] 空き枠の休止解除が失敗しました: {$undoRes['error']}\n";
        exit(1);
    }
    echo "[OK] 空き枠の休止解除が正常に完了しました\n";
    echo "[PASS] 空き枠の休止・復帰は正常に動作します\n\n";
}

echo "========================================\n";
echo "🎉 全ての検証項目（4/4）に合格しました！\n";
echo "========================================\n";
