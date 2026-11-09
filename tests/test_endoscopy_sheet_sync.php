<?php
/**
 * 生成日時: 2026-11-08 16:36 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\tests\test_endoscopy_sheet_sync.php
 * ファイル名: test_endoscopy_sheet_sync.php
 * 概要: 計画⑥ 内視鏡シート（t_endoscopy_sheet）DB永続化・マルチ端末差分マージ・ホワイトボード双方向データ統合の自動検証テスト
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/progress_db.php';
require_once __DIR__ . '/../lib/medical_alert_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("❌ DB接続失敗\n");
}

initProgressTables($pdo);

$passed = 0;
$failed = 0;

function assertCond(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  ✅ PASS: {$label}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$label}\n";
        $failed++;
    }
}

/**
 * CLIサブプロセスでAPIスクリプトにJSON POSTまたはGETリクエストを送信してJSONレスポンスを取得
 */
function callApiScript(string $scriptName, string $method, array $params = []): array {
    $scriptPath = realpath(__DIR__ . '/../' . $scriptName);
    $runnerCode = '
        $_SERVER["REQUEST_METHOD"] = getenv("TEST_METHOD");
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        $payload = json_decode(getenv("TEST_PAYLOAD"), true) ?: [];
        if ($_SERVER["REQUEST_METHOD"] === "GET") {
            $_GET = $payload;
            $_REQUEST = $payload;
        } else {
            $_POST = $payload;
            $_REQUEST = $payload;
        }
        include getenv("TEST_SCRIPT");
    ';

    $baseEnv = getenv();
    if (!is_array($baseEnv)) {
        $baseEnv = $_SERVER;
    }
    $env = array_merge($baseEnv, [
        'TEST_METHOD'  => $method,
        'TEST_PAYLOAD' => json_encode($params, JSON_UNESCAPED_UNICODE),
        'TEST_SCRIPT'  => $scriptPath,
    ]);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open(['php', '-r', $runnerCode], $descriptors, $pipes, dirname($scriptPath), $env);
    if (!is_resource($proc)) {
        return ['success' => false, 'error' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'raw_stdout' => $stdout, 'raw_stderr' => $stderr];
    }
    return $decoded;
}

echo "=================================================================\n";
echo " 🧪 計画⑥ 内視鏡シートDB永続化 ＆ ホワイトボード双方向統合テスト\n";
echo "=================================================================\n\n";

$test_date = '2099-11-08';
$test_sched_id = 0;

try {
    // テスト用予約レコード作成
    $ins = $pdo->prepare("
        INSERT INTO t_endoscopy_schedule (
            exam_date, scheduled_time, waku_no, department_code,
            patient_id, patient_name, patient_kana, birthday,
            doctor_name, exam_type, exam_location, room_number, bowel_prep, remarks, is_canceled
        ) VALUES (
            :exam_date, 'TCF13:30①', 10, 'PROCT',
            '999901', 'テスト 統合太郎', 'テスト トウゴウタロウ', '1965-04-01',
            '哲也', 'TCF', '内視鏡室1', '個室1', 'モビプレップ', '<p>初期備考テスト</p>', FALSE
        ) RETURNING schedule_id
    ");
    $ins->execute([':exam_date' => $test_date]);
    $test_sched_id = (int)$ins->fetchColumn();
    assertCond($test_sched_id > 0, "テスト用予約レコード作成 (schedule_id={$test_sched_id})");

    // 抗血栓薬アラート初期レコード作成（未確認状態）
    $pdo->prepare("
        INSERT INTO t_patient_medical_alert (schedule_id, exam_date, has_antithrombotic, antithrombotic_verified)
        VALUES (:id, :date, TRUE, FALSE)
    ")->execute([':id' => $test_sched_id, ':date' => $test_date]);

    // -------------------------------------------------------------------------
    // 1. 端末A（前処置室タブレット）からの入力保存テスト
    // -------------------------------------------------------------------------
    echo "\n[1] 端末A（受付・前処置室タブレット）からの血圧・排便回数・休薬確認保存\n";
    $res1 = callApiScript('action_endoscopy_sheet.php', 'POST', [
        'action'        => 'save_state',
        'schedule_id'   => $test_sched_id,
        'arrival_time'  => '12:45',
        'delta_checks'  => [
            'anti_yes'      => true,
            'anti_stop_yes' => true,
            'prep_movi'     => true,
        ],
        'delta_circles' => [
            'prep_movi_15' => true,
        ],
        'delta_inputs'  => [
            'bp_sys'      => '132',
            'bp_dia'      => '78',
            'stool_yest'  => '2',
            'stool_today' => '6',
            'stool_last'  => '12:30',
        ],
    ]);

    assertCond(!empty($res1['success']), "端末A save_state 成功");

    // t_today_progress の来院時刻と t_patient_medical_alert の休薬確認済フラグを検証
    $prog_stmt = $pdo->prepare("SELECT * FROM t_today_progress WHERE schedule_id = :id");
    $prog_stmt->execute([':id' => $test_sched_id]);
    $prog_row = $prog_stmt->fetch(PDO::FETCH_ASSOC);
    assertCond(($prog_row['arrival_time'] ?? '') === '12:45' && !empty($prog_row['is_arrived']), "来院時刻(12:45)が t_today_progress へ双方向同期されている");

    $alert_stmt = $pdo->prepare("SELECT antithrombotic_verified FROM t_patient_medical_alert WHERE schedule_id = :id");
    $alert_stmt->execute([':id' => $test_sched_id]);
    $antithrombotic_ver = (bool)$alert_stmt->fetchColumn();
    assertCond($antithrombotic_ver === true, "シート問診の「休薬あり」チェックで t_patient_medical_alert.antithrombotic_verified が TRUE に同期されている");

    // -------------------------------------------------------------------------
    // 2. 端末B（内視鏡検査室PC）からの同時リレー入力（キー単位差分マージ ＆ ステータス4自動進捗）
    // -------------------------------------------------------------------------
    echo "\n[2] 端末B（内視鏡検査室PC）からの鎮静剤・開始時刻・生検部位入力（差分マージ検証）\n";
    $res2 = callApiScript('action_endoscopy_sheet.php', 'POST', [
        'action'        => 'save_state',
        'schedule_id'   => $test_sched_id,
        'delta_checks'  => [
            'drug_propofol' => true,
            'cf_nbi'        => true,
            'cf_xylo'       => true,
        ],
        'delta_circles' => [
            'cf_reach_5'  => true, // 盲腸
            'cf_biopsy_1' => true, // Ｓ状結腸
        ],
        'delta_inputs'  => [
            'drug_propofol_mg' => '45',
            't_start_h'        => '13',
            't_start_m'        => '35',
            'remarks_memo'     => "初期備考テスト\n検査室から追記メモ",
        ],
    ]);

    assertCond(!empty($res2['success']), "端末B save_state 成功");
    assertCond((int)($res2['current_status_id'] ?? 0) === 4, "検査開始時刻(13:35)の入力によりホワイトボードステータスが 4(検査中) へ自動進捗");

    // 端末Aの入力（血圧・排便回数）が上書き消去されず保持されているか検証
    $sheet_stmt = $pdo->prepare("SELECT * FROM t_endoscopy_sheet WHERE schedule_id = :id");
    $sheet_stmt->execute([':id' => $test_sched_id]);
    $sheet_row = $sheet_stmt->fetch(PDO::FETCH_ASSOC);
    $saved_json = json_decode((string)$sheet_row['sheet_state_json'], true);

    assertCond((int)$sheet_row['bp_sys'] === 132 && (int)$sheet_row['bp_dia'] === 78, "端末Aの血圧(132/78)が構造化カラムに保持されている");
    assertCond(($saved_json['inputs']['stool_today'] ?? '') === '6', "端末Aの排便回数(今日6回)がJSONBキー単位マージで保持されている");
    assertCond(($saved_json['inputs']['drug_propofol_mg'] ?? '') === '45', "端末Bのプロポフォール用量(45mg)がマージ保存されている");
    assertCond(!empty($saved_json['checks']['cf_biopsy']), "生検部位(cf_biopsy_1)の丸囲みにより親チェック(cf_biopsy)もONになっている");

    // t_today_progress の鎮静・生検・開始時刻を検証
    $prog_stmt->execute([':id' => $test_sched_id]);
    $prog_row = $prog_stmt->fetch(PDO::FETCH_ASSOC);
    assertCond(($prog_row['exam_start_time'] ?? '') === '13:35', "t_today_progress.exam_start_time が '13:35' に同期されている");
    assertCond(!empty($prog_row['has_sedation']) && ($prog_row['sedation_type'] ?? '') === 'PROPOFOL', "プロポフォール入力で t_today_progress.sedation_type が 'PROPOFOL' に同期されている");
    assertCond(!empty($prog_row['has_biopsy']) && mb_strpos((string)$prog_row['biopsy_sites'], 'Ｓ状結腸') !== false, "シートの生検部位丸囲みが t_today_progress.biopsy_sites ('{$prog_row['biopsy_sites']}') に同期されている");

    // 予約備考（t_endoscopy_schedule.remarks）への同期検証
    $sched_stmt = $pdo->prepare("SELECT remarks FROM t_endoscopy_schedule WHERE schedule_id = :id");
    $sched_stmt->execute([':id' => $test_sched_id]);
    $updated_remarks = (string)$sched_stmt->fetchColumn();
    assertCond(mb_strpos($updated_remarks, '検査室から追記メモ') !== false, "シート備考欄の追記が t_endoscopy_schedule.remarks に双方向同期されている");

    // -------------------------------------------------------------------------
    // 3. 検査後動線スタンプ [回復] ＆ 医師サイン・[□会計へ] 連動テスト
    // -------------------------------------------------------------------------
    echo "\n[3] 検査後動線スタンプ [回復] ➔ ステータス5(リカバリ中) ＆ 洗浄指示連動\n";
    $res3 = callApiScript('action_endoscopy_sheet.php', 'POST', [
        'action'        => 'save_state',
        'schedule_id'   => $test_sched_id,
        'delta_circles' => [
            'stamp_recovery' => true,
        ],
    ]);
    assertCond((int)($res3['current_status_id'] ?? 0) === 5, "[回復]スタンプ押下でホワイトボードステータスが 5(リカバリ中) へ自動進捗");

    $prog_stmt->execute([':id' => $test_sched_id]);
    $prog_row = $prog_stmt->fetch(PDO::FETCH_ASSOC);
    assertCond(($prog_row['scope_cleaning_status'] ?? '') === 'ORDERED', "[回復]遷移時にスコープ洗浄指示(ORDERED)が自動発行されている");

    // -------------------------------------------------------------------------
    // 4. ホワイトボード側からの操作 ➔ 内視鏡シートへの逆方向同期 (syncProgressToSheet)
    // -------------------------------------------------------------------------
    echo "\n[4] ホワイトボード（progress_monitor.php）操作 ➔ 内視鏡シート（t_endoscopy_sheet）への逆方向同期\n";
    syncProgressToSheet($pdo, $test_sched_id, [
        'wake_time'        => '14:15',
        'explain_time'     => '14:20',
        'post_destination' => 'WAITING',
        'has_biopsy'       => true,
        'biopsy_sites'     => 'Ｓ状結腸:1, 直腸:1',
    ]);

    $res_get = callApiScript('action_endoscopy_sheet.php', 'GET', [
        'action'      => 'get_state',
        'schedule_id' => $test_sched_id,
    ]);
    assertCond(($res_get['state']['inputs']['t_wake_h'] ?? '') === '14' && ($res_get['state']['inputs']['t_wake_m'] ?? '') === '15', "ホワイトボードの起床時刻(14:15)がシートの起床欄(14:15)へ逆同期されている");
    assertCond(!empty($res_get['state']['circles']['cf_biopsy_0']), "ホワイトボード検体照合の「直腸」がシートの直腸丸囲み(cf_biopsy_0)へ逆同期されている");
    assertCond(!empty($res_get['state']['circles']['stamp_waiting']) && empty($res_get['state']['circles']['stamp_recovery']), "誘導スタンプが排他制御で [待合室] に切り替わっている");

    // -------------------------------------------------------------------------
    // 5. 医師サイン・[□会計へ] ＆ get_progress_data.php サマリ・医事課レセプト入力完了チェック
    // -------------------------------------------------------------------------
    echo "\n[5] 医師サイン・[□会計へ] ＆ 医事課レセプト入力完了トグル検証\n";
    callApiScript('action_endoscopy_sheet.php', 'POST', [
        'action'       => 'save_state',
        'schedule_id'  => $test_sched_id,
        'delta_checks' => ['go_accounting' => true, 'post_cf_exp' => true],
        'delta_inputs' => ['doctor_sign' => '哲也'],
    ]);

    $wb_data = callApiScript('get_progress_data.php', 'GET', [
        'date' => $test_date,
        'dept' => 'ALL',
    ]);
    assertCond(!empty($wb_data['success']), "get_progress_data.php 取得成功");
    assertCond((int)($wb_data['summary']['ready_accounting_count'] ?? 0) === 1, "サマリーの会計待件数(ready_accounting_count)が 1 になっている");

    $target_pt = null;
    foreach ($wb_data['patients'] ?? [] as $pt) {
        if ((int)$pt['schedule_id'] === $test_sched_id) {
            $target_pt = $pt;
            break;
        }
    }
    assertCond($target_pt !== null && !empty($target_pt['sheet_summary']['has_sheet']), "患者データに sheet_summary が結合されている");
    assertCond(($target_pt['sheet_summary']['doctor_sign'] ?? '') === '哲也', "sheet_summary に医師サイン('哲也')が反映されている");
    assertCond(in_array('プロポフォール1% 45mg', $target_pt['sheet_summary']['drugs_summary'] ?? [], true), "sheet_summary.drugs_summary に 'プロポフォール1% 45mg' が抽出されている");

    // 医事入力完了トグルON
    $res_bill = callApiScript('action_endoscopy_sheet.php', 'POST', [
        'action'               => 'toggle_billing',
        'schedule_id'          => $test_sched_id,
        'is_billing_completed' => 1,
    ]);
    assertCond(!empty($res_bill['success']) && !empty($res_bill['is_billing_completed']), "医事・レセプト入力完了トグル(is_billing_completed=true)成功");

    $wb_data2 = callApiScript('get_progress_data.php', 'GET', [
        'date' => $test_date,
        'dept' => 'ALL',
    ]);
    assertCond((int)($wb_data2['summary']['billing_completed_count'] ?? 0) === 1 && (int)($wb_data2['summary']['ready_accounting_count'] ?? 0) === 0, "医事入力完了後、billing_completed_count=1 / ready_accounting_count=0 に更新されている");

} finally {
    if ($test_sched_id > 0) {
        $pdo->prepare("DELETE FROM t_endoscopy_sheet WHERE schedule_id = :id")->execute([':id' => $test_sched_id]);
        $pdo->prepare("DELETE FROM t_progress_status_log WHERE schedule_id = :id")->execute([':id' => $test_sched_id]);
        $pdo->prepare("DELETE FROM t_today_progress WHERE schedule_id = :id")->execute([':id' => $test_sched_id]);
        $pdo->prepare("DELETE FROM t_patient_medical_alert WHERE schedule_id = :id")->execute([':id' => $test_sched_id]);
        $pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :id")->execute([':id' => $test_sched_id]);
    }
}

echo "\n=================================================================\n";
echo " テスト結果: PASS={$passed} / FAIL={$failed}\n";
echo "=================================================================\n";
exit($failed > 0 ? 1 : 0);
