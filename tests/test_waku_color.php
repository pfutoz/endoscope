<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\tests\test_waku_color.php
 * ファイル名: test_waku_color.php
 * 概要: 基本時間枠カラー（waku_color）機能の自動検証テストスクリプト
 * 作成日時: 2026-11-08
 */
declare(strict_types=1);

if (getenv('TEST_TARGET_SCRIPT')) {
    $_GET = json_decode((string)getenv('TEST_GET_JSON'), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    chdir(dirname(__DIR__));
    include dirname(__DIR__) . '/' . getenv('TEST_TARGET_SCRIPT');
    exit(0);
}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';
require_once __DIR__ . '/../lib/master_audit_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    fwrite(STDERR, "[FAIL] DB connection failed\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        $failed++;
    }
}

function renderPhpPage(string $script, array $getParams): string {
    $env = array_merge($_ENV, [
        'TEST_TARGET_SCRIPT' => $script,
        'TEST_GET_JSON'      => json_encode($getParams),
        'SystemRoot'         => getenv('SystemRoot') ?: 'C:\\Windows',
        'PATH'               => getenv('PATH') ?: '',
    ]);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open([PHP_BINARY, __FILE__], $descriptors, $pipes, dirname(__DIR__), $env);
    if (!is_resource($proc)) {
        return '';
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);
    return $stdout;
}

echo "=== 1. カラー正規化・プリセット関数テスト ===\n";
assertTrue(sanitizeWakuColor('#E0F2FE') === '#e0f2fe', 'sanitizeWakuColor: 大文字HEXを小文字正規化');
assertTrue(sanitizeWakuColor('') === '', 'sanitizeWakuColor: 空文字は色なし');
assertTrue(sanitizeWakuColor('invalid-color') === '', 'sanitizeWakuColor: 不正文字列は空文字にサニタイズ');
assertTrue(strpos(getWakuColorLabel('#dcfce7'), 'グリーン') !== false, 'getWakuColorLabel: プリセット名称取得');
assertTrue(strpos(getWakuColorLabel('#abcdef'), 'カスタム') !== false, 'getWakuColorLabel: カスタム色ラベル取得');

echo "\n=== 2. DBトランザクション内での有効枠・予約マージ・単発名称変更・色解決テスト ===\n";
$test_date = date('Y-m-d');
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        SELECT id, waku_name, group_code, COALESCE(waku_color, '') AS waku_color
        FROM m2_waku
        WHERE department_code = 'PROCT'
          AND is_active = TRUE
          AND :date BETWEEN valid_from AND valid_to
        ORDER BY display_order ASC
        LIMIT 1
    ");
    $stmt->execute([':date' => $test_date]);
    $target_waku = $stmt->fetch(PDO::FETCH_ASSOC);
    assertTrue(!empty($target_waku), 'PROCTの有効な基本時間枠を1件取得');

    $waku_id = (int)$target_waku['id'];
    $waku_name = (string)$target_waku['waku_name'];
    $group_code = (string)$target_waku['group_code'];

    // 色を #e0f2fe に設定
    $upd = $pdo->prepare("UPDATE m2_waku SET waku_color = '#e0f2fe' WHERE id = :id");
    $upd->execute([':id' => $waku_id]);

    $eff_list = getEffectiveWakuList($pdo, 'PROCT', $test_date);
    $found_eff = null;
    foreach ($eff_list as $ew) {
        if ((int)$ew['base_waku_id'] === $waku_id) {
            $found_eff = $ew;
            break;
        }
    }
    assertTrue($found_eff !== null && $found_eff['waku_color'] === '#e0f2fe', 'getEffectiveWakuList: waku_color (#e0f2fe) が取得されること');

    // 単発枠名称変更 (m2_waku_override) を挿入しても base_waku の waku_color が引き継がれるか検証
    $custom_display_name = $waku_name . '(特テスト)';
    $ins_ov = $pdo->prepare("
        INSERT INTO m2_waku_override (department_code, base_waku_id, override_type, target_date, display_waku_name, group_code, is_suspended, is_active)
        VALUES ('PROCT', :bid, 'DATE', :tdate, :dname, :grp, FALSE, TRUE)
    ");
    $ins_ov->execute([
        ':bid'   => $waku_id,
        ':tdate' => $test_date,
        ':dname' => $custom_display_name,
        ':grp'   => $group_code,
    ]);

    $eff_list_ov = getEffectiveWakuList($pdo, 'PROCT', $test_date);
    $found_ov = null;
    foreach ($eff_list_ov as $ew) {
        if ((int)$ew['base_waku_id'] === $waku_id) {
            $found_ov = $ew;
            break;
        }
    }
    assertTrue(
        $found_ov !== null && $found_ov['waku_name'] === $custom_display_name && $found_ov['waku_color'] === '#e0f2fe',
        '単発名称変更（⚙️オーバーライド）時も基本時間枠の waku_color (#e0f2fe) が維持されること'
    );

    // resolveEffectiveWakuColorForRow で変更後枠名からも元の枠名からも色が解決できるか
    $eff_by_dept = ['PROCT' => $eff_list_ov];
    $color_by_disp = resolveEffectiveWakuColorForRow([
        'department_code' => 'PROCT',
        'scheduled_time'  => $custom_display_name,
    ], $eff_by_dept);
    $color_by_orig = resolveEffectiveWakuColorForRow([
        'department_code' => 'PROCT',
        'scheduled_time'  => $waku_name,
    ], $eff_by_dept);
    assertTrue($color_by_disp === '#e0f2fe', 'resolveEffectiveWakuColorForRow: 変更後の表示枠名から #e0f2fe を解決');
    assertTrue($color_by_orig === '#e0f2fe', 'resolveEffectiveWakuColorForRow: 変更前の基本枠名から #e0f2fe を解決');

    // getDailyReservationsWithEffectiveSlots でも waku_color が格納されているか
    $daily_rows = getDailyReservationsWithEffectiveSlots($pdo, 'PROCT', $test_date, 'all', 'all', true);
    $matched_daily = null;
    foreach ($daily_rows as $dr) {
        if ((int)($dr['base_waku_id'] ?? 0) === $waku_id) {
            $matched_daily = $dr;
            break;
        }
    }
    assertTrue(
        $matched_daily !== null && ($matched_daily['waku_color'] ?? '') === '#e0f2fe',
        'getDailyReservationsWithEffectiveSlots: 行データに waku_color (#e0f2fe) が伝播していること'
    );
} finally {
    $pdo->rollBack();
}

echo "\n=== 3. 各画面HTML描画検証テスト（一時的にwaku_colorを設定し検証後即復元） ===\n";
$stmt = $pdo->prepare("
    SELECT id, waku_name, COALESCE(waku_color, '') AS orig_color
    FROM m2_waku
    WHERE department_code = 'PROCT'
      AND is_active = TRUE
      AND :date BETWEEN valid_from AND valid_to
    ORDER BY display_order ASC
    LIMIT 1
");
$stmt->execute([':date' => $test_date]);
$row_w = $stmt->fetch(PDO::FETCH_ASSOC);
$target_id = (int)$row_w['id'];
$orig_color = (string)$row_w['orig_color'];

try {
    $pdo->prepare("UPDATE m2_waku SET waku_color = '#dbeafe' WHERE id = :id")->execute([':id' => $target_id]);

    // 1) slot_master_maintenance.php
    $html_maint = renderPhpPage('slot_master_maintenance.php', ['dept' => 'PROCT', 'tab' => 'waku']);
    assertTrue(strpos($html_maint, 'name="waku_color"') !== false && strpos($html_maint, '#dbeafe') !== false, 'slot_master_maintenance.php: waku_color 入力欄および設定色 #dbeafe が描画されること');

    // 2) month_schedule.php
    $html_month = renderPhpPage('month_schedule.php', ['dept' => 'PROCT', 'date' => $test_date, 'show_empty' => '1']);
    assertTrue(strpos($html_month, 'data-waku-color="#dbeafe"') !== false && strpos($html_month, 'background-color:#dbeafe;') !== false, 'month_schedule.php: 時間枠セルに background-color:#dbeafe; が描画されること');

    // 3) schedule_note.php
    $html_note = renderPhpPage('schedule_note.php', ['dept' => 'PROCT', 'date' => $test_date, 'show_empty' => '1']);
    assertTrue(strpos($html_note, 'class="col-waku" style="background-color:#dbeafe;') !== false, 'schedule_note.php: 時間枠セル (col-waku) に background-color:#dbeafe; が描画されること');

    // 4) print_schedule_daily.php
    $html_print = renderPhpPage('print_schedule_daily.php', ['dept' => 'PROCT', 'date' => $test_date, 'show_empty' => '1']);
    assertTrue(strpos($html_print, 'class="col-time" style="background-color:#dbeafe !important;') !== false, 'print_schedule_daily.php: 時間枠セル (col-time) に background-color:#dbeafe !important; が描画されること');

    // 5) bulk_edit_prep_room.php（一時テスト予約を1件登録して描画検証後即削除）
    $w_name_test = (string)$row_w['waku_name'];
    $ins_res = $pdo->prepare("
        INSERT INTO t_endoscopy_schedule (exam_date, waku_no, scheduled_time, department_code, patient_id, patient_name, exam_type, exam_location, doctor_name, is_canceled)
        VALUES (:dt, 1, :wt, 'PROCT', '999999', 'テスト患者', 'TCF', 'おの', '◆未定◆', FALSE)
        RETURNING schedule_id
    ");
    $ins_res->execute([':dt' => $test_date, ':wt' => $w_name_test]);
    $temp_sid = (int)$ins_res->fetchColumn();
    try {
        $html_bulk = renderPhpPage('bulk_edit_prep_room.php', ['dept' => 'PROCT', 'date' => $test_date]);
        assertTrue(strpos($html_bulk, '<td style="background-color:#dbeafe;">') !== false, 'bulk_edit_prep_room.php: 時間枠セルに background-color:#dbeafe; が描画されること');
    } finally {
        if ($temp_sid > 0) {
            $pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :sid")->execute([':sid' => $temp_sid]);
        }
    }

} finally {
    $pdo->prepare("UPDATE m2_waku SET waku_color = :c WHERE id = :id")->execute([':c' => $orig_color, ':id' => $target_id]);
}

echo "\n=== 全テスト完了: PASS={$passed}, FAIL={$failed} ===\n";
exit($failed > 0 ? 1 : 0);
