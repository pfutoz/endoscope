<?php
/**
 * 医療法人小野会 内視鏡予約システム - 患者情報＆電子カルテメモ丸ごと一括同期ヘルパー
 * 
 * [実行方法]
 * 1. ブラウザから実行: http://localhost/endoscope/batch_sync_patient_all.php
 * 2. コマンドラインから実行: php batch_sync_patient_all.php
 */

set_time_limit(0);
ini_set('display_errors', "1");
error_reporting(E_ALL);

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    header('Content-Type: text/plain; charset=UTF-8');
    @ob_end_clean();
    ob_implicit_flush(true);
}

// 接続設定 (PostgreSQL のみ。MALL接続は既存APIに丸投げします)
$pg_host     = 'localhost';
$pg_dbname   = 'endoscope';
$pg_user     = 'postgres';
$pg_pass     = 'postgres';

try {
    $pg_pdo = new PDO("pgsql:host=$pg_host;dbname=$pg_dbname;", $pg_user, $pg_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo "【エラー】データベース接続失敗: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== 患者情報・同期メモ フル一括同期バッチ 開始 ===\n";
echo "開始時刻: " . date('Y-m-d H:i:s') . "\n";
echo "-----------------------------------------\n";

// 1. 同期対象の予約データ（ユニークなカルテID一覧）を PostgreSQL から抽出
$select_sql = "SELECT DISTINCT patient_id, patient_name 
               FROM t_endoscopy_schedule 
               WHERE patient_id IS NOT NULL 
                 AND patient_id != '' 
                 AND patient_id != '---'
               ORDER BY patient_id ASC";

$stmt_pg = $pg_pdo->query($select_sql);
$targets = $stmt_pg->fetchAll();
$total_count = count($targets);

echo "同期対象患者（ユニークID）数: {$total_count} 名\n\n";

if ($total_count === 0) {
    echo "同期対象の患者データはありません。処理を終了します。\n";
    exit(0);
}

// --- プリペアドステートメントの用意 ---

// PostgreSQL側：同期したMALL情報（名前、ヨミ、生年月日）を予約テーブルに反映する
$stmt_update_schedule = $pg_pdo->prepare("
    UPDATE t_endoscopy_schedule 
    SET patient_name = :patient_name,
        patient_kana = :patient_kana,
        birthday     = :birthday
    WHERE patient_id = :patient_id
");

$success_count = 0;
$skip_count = 0;
$error_count = 0;

// 自サーバー上の API を叩くためのベースURLを解決
$base_url = "http://localhost/endoscope/";

foreach ($targets as $index => $row) {
    $patient_id   = trim($row['patient_id']);
    $patient_name = $row['patient_name'];
    
    $current_no = $index + 1;
    echo "[{$current_no}/{$total_count}] ID: {$patient_id} ({$patient_name}) を同期中... ";

    if (!is_numeric($patient_id)) {
        echo "【スキップ】仮IDまたは数値以外のIDです。\n";
        $skip_count++;
        continue;
    }

    // 2. 実績のある既存同期API「sync_mall_memo.php」を叩いて同期処理を実行させる！
    $sync_url = $base_url . "sync_mall_memo.php?patient_id=" . $patient_id;
    $sync_json = @file_get_contents($sync_url);

    if ($sync_json === false) {
        echo "【エラー】同期APIとの接続に失敗しました。\n";
        $error_count++;
        continue;
    }

    $sync_res = json_decode($sync_json, true);
    if (!$sync_res || !isset($sync_res['success']) || !$sync_res['success']) {
        $msg = isset($sync_res['message']) ? $sync_res['message'] : '同期API側での処理失敗';
        echo "【エラー】 {$msg}\n";
        $error_count++;
        continue;
    }

    // 3. MALL側最新データの引き込み（get_mall_patient.php から安全に抽出）
    $patient_url = $base_url . "get_mall_patient.php?patient_no=" . $patient_id;
    $patient_json = @file_get_contents($patient_url);

    if ($patient_json === false) {
        echo "【エラー】基本情報APIとの通信に失敗しました。\n";
        $error_count++;
        continue;
    }

    $p_res = json_decode($patient_json, true);
    if (!$p_res || !isset($p_res['success']) || !$p_res['success']) {
        echo "【不整合】MALLに該当患者が存在しません。\n";
        $error_count++;
        continue;
    }

    // 最新の綺麗な患者基本情報を変数に格納
    $clean_name = trim($p_res['patient_name']);
    $clean_kana = isset($p_res['patient_kana']) ? trim($p_res['patient_kana']) : '';
    $birthday   = !empty($p_res['birthday']) ? $p_res['birthday'] : null;

    try {
        // 4. PostgreSQLの予約テーブルに「最新の氏名・ヨミガナ・生年月日」を一括適用
        $stmt_update_schedule->execute([
            ':patient_name' => $clean_name,
            ':patient_kana' => $clean_kana,
            ':birthday'     => $birthday,
            ':patient_id'   => $patient_id
        ]);

        echo "完了（氏名:{$clean_name} / ヨミ:{$clean_kana} / 生年月日:{$birthday} / メモ同期完了）\n";
        $success_count++;

    } catch (Exception $ex) {
        echo "【予約データ更新エラー】 " . $ex->getMessage() . "\n";
        $error_count++;
    }

    // サーバーやAPIへの負荷を軽減するため、1件ごとにちょっとした休息（40ミリ秒）
    usleep(40000); 
}

echo "-----------------------------------------\n";
echo "=== フル同期完了 ===\n";
echo "終了時刻: " . date('Y-m-d H:i:s') . "\n";
echo "同期成功: {$success_count} 名 / スキップ: {$skip_count} 名 / エラー・対象外: {$error_count} 名 (総数: {$total_count} 名)\n";