<?php
/**
 * tests/test_waku_override.php
 * 
 * 生成日時: 2026-10-22
 * 修正履歴:
 *   - 2026-10-22: 【Phase 1】時間枠マスタ日別・期間別オーバーライドの単体テスト＆ベンチマーク作成
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

echo "======================================================================" . PHP_EOL;
echo "  時間枠マスタ柔軟化（日別・期間別オーバーライド）単体テスト＆検証" . PHP_EOL;
echo "======================================================================" . PHP_EOL . PHP_EOL;

$pdo = getDbConnection();
if (!$pdo) {
    echo "❌ DB接続失敗" . PHP_EOL;
    exit(1);
}

$testOverrides = [];
$passCount = 0;
$failCount = 0;

function assertCondition($testName, $condition, $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$testName}" . ($detail ? " ({$detail})" : "") . PHP_EOL;
        $passCount++;
    } else {
        echo "  [FAIL] ❌ {$testName}" . ($detail ? " ({$detail})" : "") . PHP_EOL;
        $failCount++;
    }
}

try {
    // -------------------------------------------------------------------------
    // テスト1: 通常日（オーバーライドなし）の検証
    // -------------------------------------------------------------------------
    echo "【テスト1: 通常日（オーバーライド未設定）の取得】" . PHP_EOL;
    $normalDate = '2026-11-01';
    $normalSlots = getEffectiveWakuList($pdo, 'GASTRO', $normalDate);
    // m2_waku の GASTRO は全17件中、エコー予備2件が無効(is_active=FALSE)のため有効件数は15件
    $expectedBaseCount = (int)$pdo->query("SELECT count(*) FROM m2_waku WHERE department_code = 'GASTRO' AND is_active = TRUE")->fetchColumn();
    assertCondition("基本有効枠件数一致 ({$expectedBaseCount}件)", count($normalSlots) === $expectedBaseCount, "取得件数: " . count($normalSlots));
    assertCondition("オーバーライドフラグ未設定", $normalSlots[0]['is_override'] === false);
    assertCondition("休止フラグ未設定", $normalSlots[0]['is_suspended'] === false);
    assertCondition("枠名が基本マスタと一致", $normalSlots[0]['waku_name'] === 'エコー9:00', "枠名: {$normalSlots[0]['waku_name']}");
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト2: 特定日（DATE）オーバーライド ＆ 休止（🚫）のテスト
    // -------------------------------------------------------------------------
    echo "【テスト2: 特定日（DATE）での枠名変更 ＆ 休止枠（🚫）】" . PHP_EOL;
    $targetDate = '2026-11-05';

    // ID=36 (GIF0900 / GIF9:00) を枠名変更
    $stmt = $pdo->prepare("
        INSERT INTO m2_waku_override (
            department_code, base_waku_id, override_type, target_date,
            display_waku_name, group_code, is_suspended, reason
        ) VALUES (
            'GASTRO', 36, 'DATE', :target_date,
            'GIF9:00 (特別枠)', 'GIF', FALSE, '臨時検査枠'
        ) RETURNING override_id
    ");
    $stmt->execute([':target_date' => $targetDate]);
    $ov1 = (int)$stmt->fetchColumn();
    $testOverrides[] = $ov1;

    // ID=39 (GIF1000 / GIF10:00) を休止枠（🚫）に設定
    $stmt = $pdo->prepare("
        INSERT INTO m2_waku_override (
            department_code, base_waku_id, override_type, target_date,
            display_waku_name, group_code, is_suspended, reason
        ) VALUES (
            'GASTRO', 39, 'DATE', :target_date,
            '🚫 GIF10:00［研修休止］', 'GIF', TRUE, '院内研修のため休止'
        ) RETURNING override_id
    ");
    $stmt->execute([':target_date' => $targetDate]);
    $ov2 = (int)$stmt->fetchColumn();
    $testOverrides[] = $ov2;

    $daySlots = getEffectiveWakuList($pdo, 'GASTRO', $targetDate);
    $slotMap = [];
    foreach ($daySlots as $s) {
        $slotMap[$s['base_waku_id']] = $s;
    }

    assertCondition("上書き枠名が反映される", $slotMap[36]['waku_name'] === 'GIF9:00 (特別枠)', "枠名: {$slotMap[36]['waku_name']}");
    assertCondition("上書きフラグがTRUE", $slotMap[36]['is_override'] === true);
    assertCondition("元の枠名が保持されている", $slotMap[36]['original_name'] === 'GIF9:00');
    assertCondition("休止枠フラグがTRUE", $slotMap[39]['is_suspended'] === true);
    assertCondition("休止枠名が反映される", $slotMap[39]['waku_name'] === '🚫 GIF10:00［研修休止］');
    assertCondition("休止理由が反映される", $slotMap[39]['reason'] === '院内研修のため休止');

    // 翌日 2026-11-06 は通常枠に戻ることを確認
    $nextDaySlots = getEffectiveWakuList($pdo, 'GASTRO', '2026-11-06');
    $nextMap = [];
    foreach ($nextDaySlots as $s) {
        $nextMap[$s['base_waku_id']] = $s;
    }
    assertCondition("翌日は通常枠名に戻る", $nextMap[36]['waku_name'] === 'GIF9:00');
    assertCondition("翌日は休止が解除されている", $nextMap[39]['is_suspended'] === false);
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト3: 期間指定（PERIOD）オーバーライドのテスト
    // -------------------------------------------------------------------------
    echo "【テスト3: 期間指定（PERIOD）オーバーライド】" . PHP_EOL;
    // ID=50 (TCF1330 / TCF13:30~14:30) を 2027年1月〜3月の期間で上書き
    $stmt = $pdo->prepare("
        INSERT INTO m2_waku_override (
            department_code, base_waku_id, override_type, start_date, end_date,
            display_waku_name, group_code, is_suspended, reason
        ) VALUES (
            'GASTRO', 50, 'PERIOD', '2027-01-01', '2027-03-31',
            'TCF13:30 (2027新設)', 'TCF', FALSE, '2027年体制移行'
        ) RETURNING override_id
    ");
    $stmt->execute();
    $ov3 = (int)$stmt->fetchColumn();
    $testOverrides[] = $ov3;

    // 期間前 (2026-12-31): 通常枠
    $beforePeriod = getEffectiveWakuList($pdo, 'GASTRO', '2026-12-31');
    $beforeMap = [];
    foreach ($beforePeriod as $s) { $beforeMap[$s['base_waku_id']] = $s; }
    assertCondition("期間前は通常枠名", $beforeMap[50]['waku_name'] === 'TCF13:30~14:30');

    // 期間中 (2027-01-15): 上書き枠名
    $duringPeriod = getEffectiveWakuList($pdo, 'GASTRO', '2027-01-15');
    $duringMap = [];
    foreach ($duringPeriod as $s) { $duringMap[$s['base_waku_id']] = $s; }
    assertCondition("期間中は新枠名に上書き", $duringMap[50]['waku_name'] === 'TCF13:30 (2027新設)');
    assertCondition("期間中のオーバーライド種別はPERIOD", $duringMap[50]['override_type'] === 'PERIOD');

    // 期間後 (2027-04-01): 通常枠
    $afterPeriod = getEffectiveWakuList($pdo, 'GASTRO', '2027-04-01');
    $afterMap = [];
    foreach ($afterPeriod as $s) { $afterMap[$s['base_waku_id']] = $s; }
    assertCondition("期間後は通常枠名に戻る", $afterMap[50]['waku_name'] === 'TCF13:30~14:30');
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト4: 優先順位（DATE ＞ PERIOD）のテスト
    // -------------------------------------------------------------------------
    echo "【テスト4: 優先順位の検証（特定日 DATE が 期間 PERIOD より優先される）】" . PHP_EOL;
    // 期間中の 2027-01-15 に、同一枠 ID=50 に対して特定日上書きを追加
    $stmt = $pdo->prepare("
        INSERT INTO m2_waku_override (
            department_code, base_waku_id, override_type, target_date,
            display_waku_name, group_code, is_suspended, reason
        ) VALUES (
            'GASTRO', 50, 'DATE', '2027-01-15',
            'TCF13:30 [特定日最優先]', 'TCF', FALSE, '特定日個別変更'
        ) RETURNING override_id
    ");
    $stmt->execute();
    $ov4 = (int)$stmt->fetchColumn();
    $testOverrides[] = $ov4;

    $prioritySlots = getEffectiveWakuList($pdo, 'GASTRO', '2027-01-15');
    $pMap = [];
    foreach ($prioritySlots as $s) { $pMap[$s['base_waku_id']] = $s; }
    assertCondition("同一枠で特定日DATEが期間PERIODより優先採用される", $pMap[50]['waku_name'] === 'TCF13:30 [特定日最優先]', "枠名: {$pMap[50]['waku_name']}");
    assertCondition("採用された種別がDATEである", $pMap[50]['override_type'] === 'DATE');

    // 翌日 2027-01-16 は期間指定（PERIOD）が再び適用されること
    $pNextSlots = getEffectiveWakuList($pdo, 'GASTRO', '2027-01-16');
    $pNextMap = [];
    foreach ($pNextSlots as $s) { $pNextMap[$s['base_waku_id']] = $s; }
    assertCondition("特定日以外は期間指定PERIODが適用される", $pNextMap[50]['waku_name'] === 'TCF13:30 (2027新設)');
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト5: 臨時追加枠（base_waku_id IS NULL）のテスト
    // -------------------------------------------------------------------------
    echo "【テスト5: 臨時追加枠（base_waku_id IS NULL）】" . PHP_EOL;
    $stmt = $pdo->prepare("
        INSERT INTO m2_waku_override (
            department_code, base_waku_id, override_type, target_date,
            display_waku_name, group_code, display_order, reason
        ) VALUES (
            'GASTRO', NULL, 'DATE', '2026-11-10',
            '夕方臨時枠17:00', 'GIF', 200, '臨時夕方増枠'
        ) RETURNING override_id
    ");
    $stmt->execute();
    $ov5 = (int)$stmt->fetchColumn();
    $testOverrides[] = $ov5;

    $extraDateSlots = getEffectiveWakuList($pdo, 'GASTRO', '2026-11-10');
    assertCondition("臨時追加日のみ枠数が1件増加 ({$expectedBaseCount}件 ➔ " . ($expectedBaseCount + 1) . "件)", count($extraDateSlots) === $expectedBaseCount + 1, "件数: " . count($extraDateSlots));
    $lastSlot = end($extraDateSlots);
    assertCondition("末尾に臨時枠が配置される", $lastSlot['waku_name'] === '夕方臨時枠17:00');
    assertCondition("base_waku_id が NULL である", $lastSlot['base_waku_id'] === null);
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト6: カレンダー用一括プリフェッチ (getEffectiveWakuMapForPeriod)
    // -------------------------------------------------------------------------
    echo "【テスト6: カレンダー用期間一括プリフェッチ（DBアクセスたった1回）】" . PHP_EOL;
    $monthMap = getEffectiveWakuMapForPeriod($pdo, 'GASTRO', '2026-11-01', '2026-11-30');
    assertCondition("30日分の日付キーが生成される", count($monthMap) === 30, "日数: " . count($monthMap));
    
    // 通常日 (11-01)
    assertCondition("11-01は通常枠{$expectedBaseCount}件", count($monthMap['2026-11-01']) === $expectedBaseCount);
    // 特定日 (11-05)
    $m5Map = [];
    foreach ($monthMap['2026-11-05'] as $s) { $m5Map[$s['base_waku_id']] = $s; }
    assertCondition("11-05に特定枠が正確にマッピングされている", $m5Map[36]['waku_name'] === 'GIF9:00 (特別枠)');
    // 臨時枠日 (11-10)
    assertCondition("11-10に臨時枠" . ($expectedBaseCount + 1) . "件が正確にマッピングされている", count($monthMap['2026-11-10']) === $expectedBaseCount + 1);
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト7: 実行速度ベンチマーク実測
    // -------------------------------------------------------------------------
    echo "【テスト7: 実行速度ベンチマーク実測】" . PHP_EOL;
    
    // 単一日インメモリマージ
    $t0 = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        getEffectiveWakuList($pdo, 'GASTRO', '2026-11-05');
    }
    $t1 = microtime(true);
    $singleAvgMs = (($t1 - $t0) / 100) * 1000;
    echo sprintf("  - 単一日 getEffectiveWakuList (DB取得含む): 平均 %.3f ms\n", $singleAvgMs);
    assertCondition("単一日取得が 1ms 未満", $singleAvgMs < 1.0);

    // 月間30日分一括プリフェッチ
    $t2 = microtime(true);
    for ($i = 0; $i < 50; $i++) {
        getEffectiveWakuMapForPeriod($pdo, 'GASTRO', '2026-11-01', '2026-11-30');
    }
    $t3 = microtime(true);
    $monthAvgMs = (($t3 - $t2) / 50) * 1000;
    echo sprintf("  - 月間30日分一括プリフェッチ (getEffectiveWakuMapForPeriod): 平均 %.3f ms\n", $monthAvgMs);
    assertCondition("月間30日分の一括処理が 2ms 未満", $monthAvgMs < 2.0);

    // 純粋なメモリ上での参照（O(1)）
    $cachedMonth = getEffectiveWakuMapForPeriod($pdo, 'GASTRO', '2026-11-01', '2026-11-30');
    $t4 = microtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $slots = $cachedMonth['2026-11-05'];
    }
    $t5 = microtime(true);
    $memoryAccessMs = (($t5 - $t4) / 10000) * 1000;
    echo sprintf("  - カレンダー描画ループ内のメモリハッシュ参照 (O(1)): 平均 %.5f ms\n", $memoryAccessMs);
    assertCondition("描画ループ内メモリ参照が 0.001ms 未満（実質ゼロ）", $memoryAccessMs < 0.001);
    echo PHP_EOL;

    // -------------------------------------------------------------------------
    // テスト8: 逆引き検索関数（findEffectiveWaku）の検証
    // -------------------------------------------------------------------------
    echo "【テスト8: 枠名逆引き・全角半角自動吸収 (findEffectiveWaku)】" . PHP_EOL;
    $hit1 = findEffectiveWaku($daySlots, 'GIF9:00 (特別枠)');
    assertCondition("新枠名で完全ヒット", $hit1 !== null && $hit1['base_waku_id'] === 36);

    $hit2 = findEffectiveWaku($daySlots, 'GIF9:00'); // 元枠名での検索
    assertCondition("元枠名（original_name）でも安全に逆引きヒット", $hit2 !== null && $hit2['base_waku_id'] === 36);

    $hit3 = findEffectiveWaku($daySlots, 'ＧＩＦ９：００'); // 全角文字
    assertCondition("全角文字でも自動正規化されてヒット", $hit3 !== null && $hit3['base_waku_id'] === 36);
    echo PHP_EOL;

} catch (Exception $e) {
    echo "❌ テスト中例外発生: " . $e->getMessage() . PHP_EOL;
    $failCount++;
} finally {
    // クリーンアップ: 作成したテストデータを確実に削除
    if (!empty($testOverrides)) {
        $inClause = implode(',', array_map('intval', $testOverrides));
        $pdo->exec("DELETE FROM m2_waku_override WHERE override_id IN ({$inClause})");
        echo "🧹 テスト用オーバーライドデータ (" . count($testOverrides) . "件) を安全にクリーンアップしました。" . PHP_EOL;
    }
}

echo PHP_EOL . "======================================================================" . PHP_EOL;
echo sprintf("  テスト結果サマリ: 全%d項目中 [PASS: %d] / [FAIL: %d]\n",
    $passCount + $failCount, $passCount, $failCount
);
echo "======================================================================" . PHP_EOL;

if ($failCount > 0) {
    exit(1);
}
