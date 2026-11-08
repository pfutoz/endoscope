<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\endoscopy_sheet.php
 * ファイル名: endoscopy_sheet.php
 * 概要: 内視鏡検査・処置・問診記録シート（A4単票印刷 ＆ タブレット入力・サーバー直接印刷ハイブリッド版）
 * 生成日時: 2026-11-06
 * 更新日時: 2026-11-06 11:20
 * 修正履歴:
 *   - 2026-11-06: 【新規作成 ＆ A4縦1枚ハイブリッド設計・サーバー直接印刷統合】
 *       1. 原本Excel「仕様書/内視鏡シート.xls」の全項目（来院確認・前処置下剤・問診休薬・共通薬剤・CF処置・GIF処置・手紙/同意書/医師署名/会計）を完全網羅
 *       2. 鎮静・鎮痛・鎮痙・注射薬を共通ブロックに統合し、手技・加算・部位・前処置薬を「大腸(CF)」「胃(GIF)」に分離したA4縦1枚レイアウトへ再設計
 *       3. PostgreSQL（t_endoscopy_schedule / t_today_progress / t_patient_medical_alert）および電子カルテMALL3（M_Patient 性別・カナ）と自動連携
 *       4. 予約備考（remarks）のHTMLタグ（<p>等）露出バグを除去し、改行を維持して備考欄へ自動セット
 *       5. 画面上でのタップ・クリック操作（□チェック・◯丸囲み・楕円スタンプ・数値/時刻入力）と localStorage 自動保持を実装
 *       6. タブレットからの院内複合機・プリンタ直接出力（api_server_print.php target=endoscopy_sheet ＆ 画面入力状態 state_job 引き渡し）を実装
 *   - 2026-11-06: 【現場フィードバックに基づくミリ単位レイアウト・印刷調整】
 *       1. 最上段左上のクリニック名表記をカットし、右側の「枠（予約時間枠）」「来院（来院時刻）」の表示幅・文字サイズを拡大
 *       2. 患者基本情報テーブルのスリム化：「性別」「年齢」の見出しセルおよび男女の中点（・）を削除、「生年月日」を「生年/月日」2行改行化
 *       3. 2カラムグリッドを minmax(0, Xfr) 構成に統一し、「問診・医療安全確認事項」「採血・消毒 ／ 時間記録」の右端印刷見切れを解消（全枠ツライチ化）
 *       4. 問診リストを上詰め（flex: 0 0 auto）にし、下部の「予約時備考・申し送り事項」欄の印刷行数を最大化（flex: 1 1 auto ＋ 自動高さ拡張 autoFitRemarks）
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/printer_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続に失敗しました。");
}

// サーバー印刷時の画面入力状態（state_job）の取得
$server_print_state = null;
if (!empty($_GET['state_job']) && is_string($_GET['state_job'])) {
    $safe_job = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['state_job']);
    $state_file = __DIR__ . '/scratch/print_jobs/' . $safe_job . '_state.json';
    if ($safe_job !== '' && file_exists($state_file)) {
        $decoded_state = json_decode((string)file_get_contents($state_file), true);
        if (is_array($decoded_state)) {
            $server_print_state = $decoded_state;
        }
    }
}

// パラメータ取得（schedule_id / id / sheet_single いずれにも対応）
$schedule_id = 0;
if (isset($_GET['schedule_id'])) {
    $schedule_id = (int)$_GET['schedule_id'];
} elseif (isset($_GET['id'])) {
    $schedule_id = (int)$_GET['id'];
} elseif (isset($_GET['sheet_single'])) {
    $schedule_id = (int)$_GET['sheet_single'];
}

if ($schedule_id <= 0) {
    die("エラー: 対象のスケジュールIDが指定されていません。");
}

// 1. 予約データの取得
$stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id");
$stmt->execute([':id' => $schedule_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    die("指定された予約データ（ID: #" . htmlspecialchars((string)$schedule_id, ENT_QUOTES, 'UTF-8') . "）が見つかりません。");
}

$exam_type = trim((string)($p['exam_type'] ?? ''));
if ($exam_type === 'エコー' || $exam_type === 'ECHO' || $exam_type === 'US') {
    die("エコー検査は内視鏡シート印刷の対象外です。");
}

// 2. 当日進捗データ（t_today_progress）の補完取得
$progress = null;
try {
    $stmt_prog = $pdo->prepare("SELECT * FROM t_today_progress WHERE schedule_id = :id");
    $stmt_prog->execute([':id' => $schedule_id]);
    $progress = $stmt_prog->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // テーブル未作成時等は無視
}

// 3. 医療安全アラート（t_patient_medical_alert）の取得
$alert = [
    'has_antithrombotic'      => false,
    'antithrombotic_verified' => false,
    'has_diabetes'            => false,
    'has_psychotropic'        => false,
    'has_nsaids'              => false,
    'has_allergy'             => false,
    'has_infection'           => false,
    'has_cold_fever'          => false,
];
try {
    $alerts_map = getMedicalAlertsMap($pdo, [$schedule_id]);
    if (isset($alerts_map[$schedule_id])) {
        $alert = array_merge($alert, $alerts_map[$schedule_id]);
    }
} catch (Exception $e) {}

// 4. MALL3 電子カルテから性別・カナ・生年月日の補完取得（未保持項目対策）
$patient_id   = trim((string)($p['patient_id'] ?? ''));
$patient_name = trim((string)($p['patient_name'] ?? ''));
$patient_kana = trim((string)($p['patient_kana'] ?? ''));
$birthday     = trim((string)($p['birthday'] ?? ''));
$gender       = trim((string)($p['gender'] ?? ''));

if ($patient_id !== '' && is_numeric($patient_id) && ($gender === '' || $gender === '---' || $patient_kana === '')) {
    try {
        $dsn_mall = "sqlsrv:server=192.168.1.77;Database=his00;TrustServerCertificate=1;ApplicationIntent=ReadOnly;LoginTimeout=1;";
        $conn_mall = new PDO($dsn_mall, "sa", "Kochi-cl", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $conn_mall->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
        $stmt_m = $conn_mall->prepare("
            SELECT PatientName, PatientKana, CONVERT(varchar(10), BirthDay, 120) AS BirthDayStr, Sex
            FROM M_Patient WITH (NOLOCK)
            WHERE PatientNo = :pno
        ");
        $stmt_m->execute([':pno' => $patient_id]);
        $m_row = $stmt_m->fetch();
        if ($m_row) {
            $sex_code = trim((string)($m_row['Sex'] ?? ''));
            if ($sex_code === '1') $gender = '男';
            elseif ($sex_code === '2') $gender = '女';
            elseif ($sex_code !== '') $gender = $sex_code;

            if ($patient_kana === '' && !empty($m_row['PatientKana'])) {
                $patient_kana = trim((string)$m_row['PatientKana']);
            }
            if (($birthday === '' || $birthday === '---') && !empty($m_row['BirthDayStr'])) {
                $birthday = trim((string)$m_row['BirthDayStr']);
            }
        }
    } catch (Exception $e) {
        // オフライン・接続不可時はクライアント側非同期補完へフォールバック
    }
}

// 性別の正規化（「男性」→「男」、「女性」→「女」）
$gender_short = '';
if (mb_strpos($gender, '男') !== false) $gender_short = '男';
elseif (mb_strpos($gender, '女') !== false) $gender_short = '女';

// 5. 日付・年齢・診療科・各種フラグの整形
$exam_date = trim((string)($p['exam_date'] ?? ''));
$exam_date_disp = $exam_date;
if ($exam_date !== '') {
    $ts = strtotime($exam_date);
    if ($ts !== false) {
        $w_names = ['日', '月', '火', '水', '木', '金', '土'];
        $w_str = $w_names[(int)date('w', $ts)];
        $exam_date_disp = date('Y年n月j日', $ts) . " ({$w_str})";
    }
}

$wareki_bday = ($birthday !== '' && $birthday !== '---') ? convertToWareki($birthday) : '---';
$age_int = calculateAge($birthday, $exam_date !== '' ? $exam_date : date('Y-m-d'));
$age_disp = ($age_int !== null && $age_int !== '---') ? $age_int : '';

$dept_code = (string)($p['department_code'] ?? 'GASTRO');
$is_proct  = ($dept_code === 'PROCT');

$scheduled_time = trim((string)($p['scheduled_time'] ?? ''));
$arrival_time   = trim((string)($progress['arrival_time'] ?? $p['arrival_time'] ?? ''));
$doctor_name    = trim((string)($p['doctor_name'] ?? ''));
if ($doctor_name === '◆未定◆' || $doctor_name === '--担当未定--') {
    $doctor_name = '';
}
$exam_location  = trim((string)($p['exam_location'] ?? ''));
if ($exam_location === '指定なし') {
    $exam_location = '';
}
$room_number    = trim((string)($progress['toilet_room'] ?? $p['room_number'] ?? ''));
$bowel_prep     = trim((string)($p['bowel_prep'] ?? ''));

// 備考テキストのクリーンアップ（<p>タグの露出バグを防止し、改行を保持）
$raw_remarks = (string)($p['remarks'] ?? '');
$clean_remarks = $raw_remarks;
if ($clean_remarks !== '') {
    // </p>, <br>, </li> 等を改行に変換してからタグ除去
    $clean_remarks = preg_replace('/<\/p\s*>|<br\s*\/?>|<\/li\s*>|<\/div\s*>/i', "\n", $clean_remarks);
    $clean_remarks = strip_tags($clean_remarks);
    $clean_remarks = html_entity_decode($clean_remarks, ENT_QUOTES, 'UTF-8');
    $clean_remarks = preg_replace("/\r\n|\r/", "\n", $clean_remarks);
    $clean_remarks = preg_replace("/\n{3,}/", "\n\n", trim($clean_remarks));
}

// 鎮静・運転・入院予定の判定
$has_sedation = false;
if (isset($progress['has_sedation']) && $progress['has_sedation'] !== null) {
    $has_sedation = !empty($progress['has_sedation']);
} elseif (isset($p['has_sedation']) && $p['has_sedation'] !== null) {
    $has_sedation = !empty($p['has_sedation']);
} elseif (mb_strpos($clean_remarks, '注あり') !== false || mb_strpos($clean_remarks, '注射あり') !== false || mb_strpos($clean_remarks, '鎮静あり') !== false) {
    $has_sedation = true;
}

$has_drive_caution = !empty($p['has_drive_caution']) || (mb_strpos($clean_remarks, '迎え') !== false || mb_strpos($clean_remarks, '車') !== false || mb_strpos($clean_remarks, 'タクシー') !== false);
$is_admission      = !empty($p['is_admission']) || (mb_strpos($exam_type, 'ポリペク') !== false);

// 検査種別から「大腸内視鏡」「胃・十二指腸内視鏡」の該当判定
$exam_group = function_exists('resolveExamGroup') ? resolveExamGroup($exam_type, $pdo) : '';
$is_cf_active  = ($exam_group === 'TCF' || in_array($exam_type, ['TCF', 'W', 'ポリペク', '浣腸CF', '浣腸F', 'CF'], true) || mb_strpos($exam_type, 'CF') !== false || mb_strpos($exam_type, 'ポリペク') !== false);
$is_gif_active = ($exam_group === 'GIF' || in_array($exam_type, ['GIF', 'W', '◆胃がん検診◆', '胃がん検診'], true) || mb_strpos($exam_type, 'GIF') !== false || mb_strpos($exam_type, '胃') !== false);
$is_polypep    = (mb_strpos($exam_type, 'ポリペク') !== false || mb_strpos($clean_remarks, 'ポリペク') !== false);
$is_enema_cf   = (mb_strpos($exam_type, '浣腸') !== false);

// 下剤予定の自動マッチ判定
$prep_is_mag  = (mb_strpos($bowel_prep, 'マグ') !== false || mb_strpos($clean_remarks, 'マグコロール') !== false);
$prep_is_nif  = (mb_strpos($bowel_prep, 'ニフ') !== false || mb_strpos($clean_remarks, 'ニフレック') !== false);
$prep_is_movi = (mb_strpos($bowel_prep, 'モビ') !== false || mb_strpos($clean_remarks, 'モビプレップ') !== false);
$prep_is_visi = (mb_strpos($bowel_prep, 'ビジ') !== false || mb_strpos($clean_remarks, 'ビジクリア') !== false);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内視鏡シート - <?= htmlspecialchars($patient_name !== '' ? $patient_name : '未登録', ENT_QUOTES, 'UTF-8') ?> 様</title>
    <style>
        /* =========================================================
           A4 1ページ完全収容 ＆ 画面/タブレット操作ハイブリッドCSS
           ========================================================= */
        @page {
            size: A4 portrait;
            margin: 6mm 8mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            margin: 0;
            padding: 0;
            background-color: #cbd5e1;
            color: #000;
            font-family: "Yu Gothic", "YuGothic", "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif;
            font-size: 9pt;
            line-height: 1.28;
        }

        /* 上部操作ツールバー（非印刷） */
        .no-print-toolbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #1e293b;
            color: #fff;
            padding: 8px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            font-size: 12px;
        }
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .toolbar-title {
            font-size: 14px;
            font-weight: 800;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .toolbar-hint {
            background: #334155;
            color: #e2e8f0;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
        }
        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-tool {
            border: 1px solid #475569;
            background: #334155;
            color: #f8fafc;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background 0.15s;
            touch-action: manipulation;
            user-select: none;
            -webkit-user-select: none;
        }
        .btn-tool:hover {
            background: #475569;
        }
        .btn-print-main {
            background: #10b981;
            border-color: #059669;
            color: #fff;
            padding: 6px 16px;
            font-size: 13px;
        }
        .btn-print-main:hover {
            background: #059669;
        }

        /* サーバー直接印刷セレクター & トースト通知 (print_schedule_daily.php 共通仕様) */
        .printer-select-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #0f172a;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 4px 8px;
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 600;
        }
        .printer-select-pill select {
            background: transparent;
            color: #38bdf8;
            border: none;
            outline: none;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            max-width: 190px;
        }
        .printer-select-pill select option {
            background: #1e293b;
            color: #f8fafc;
        }
        .server-print-toast {
            position: fixed;
            top: 56px;
            right: 20px;
            z-index: 99999;
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid #38bdf8;
            border-radius: 8px;
            padding: 10px 16px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            transform: translateY(-20px);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
        }
        .server-print-toast.show {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }
        .server-print-toast.toast-success { border-color: #10b981; }
        .server-print-toast.toast-error { border-color: #ef4444; }

        /* A4 シート本体 */
        .sheet-container {
            width: 194mm;
            min-height: 282mm;
            margin: 8px auto 16px auto;
            background: #fff;
            padding: 5mm 6mm;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        /* 共通インタラクティブ要素（□チェック ＆ ◯丸囲み ＆ 入力欄） */
        .cb-item {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            cursor: pointer;
            user-select: none;
            padding: 1px 3px;
            border-radius: 3px;
            transition: background-color 0.1s;
        }
        .cb-item:hover {
            background-color: #f1f5f9;
        }
        .cb-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 12px;
            height: 12px;
            border: 1.2px solid #000;
            font-size: 10px;
            font-weight: 900;
            line-height: 1;
            background: #fff;
            color: transparent;
            flex-shrink: 0;
        }
        .cb-item.is-checked .cb-box {
            color: #000;
            background: #fef08a;
        }
        .cb-item.is-checked .cb-label {
            font-weight: 800;
            text-decoration: underline;
        }

        /* 選択肢の丸囲み（無・有、部位、禁忌理由、OK/回復/待合室 等） */
        .circle-choice {
            display: inline-block;
            padding: 0px 4px;
            margin: 0 1px;
            border: 1.5px solid transparent;
            border-radius: 999px;
            cursor: pointer;
            user-select: none;
            transition: all 0.1s;
        }
        .circle-choice:hover {
            background-color: #f1f5f9;
            border-color: #94a3b8;
        }
        .circle-choice.is-circled {
            border-color: #000;
            background-color: #fef08a;
            font-weight: 800;
        }

        /* インライン手入力（タブレット/PC入力 ＆ 印刷時は下線または括弧） */
        .inline-input {
            border: none;
            border-bottom: 1px solid #333;
            background: transparent;
            font-family: inherit;
            font-size: inherit;
            font-weight: 700;
            text-align: center;
            color: #000;
            padding: 0 2px;
            outline: none;
        }
        .inline-input:focus {
            background-color: #eff6ff;
            border-bottom: 1.5px solid #2563eb;
        }
        .paren-group {
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
        .paren-group .inline-input {
            border-bottom: none;
        }

        /* ハイライト・警告スタイル */
        .hl-yellow {
            background-color: #fef08a !important;
        }
        .hl-prep-active {
            background-color: #fef9c3 !important;
            font-weight: 700;
        }
        .hl-alert-row {
            background-color: #fff1f2 !important;
        }
        .alert-tag-mini {
            display: inline-block;
            font-size: 7.5pt;
            font-weight: 800;
            padding: 0 4px;
            border-radius: 3px;
            border: 1px solid #000;
            background: #fef08a;
            margin-left: 3px;
        }

        /* 1. ヘッダー＆患者基本情報テーブル */
        .sheet-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3px;
            width: 100%;
        }
        .clinic-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dept-switch-group {
            font-size: 10.5pt;
            font-weight: 800;
            display: inline-flex;
            gap: 12px;
        }
        .exam-meta-right {
            font-size: 9pt;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: nowrap;
        }
        .meta-pill {
            border: 1px solid #000;
            padding: 1.5px 10px;
            border-radius: 3px;
            background: #f8fafc;
            font-weight: 800;
            font-size: 9.5pt;
            min-width: 82px;
            text-align: center;
            white-space: nowrap;
        }
        .meta-pill-wide {
            min-width: 105px;
        }

        .patient-header-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            border: 1.5px solid #000;
            margin-bottom: 4px;
        }
        .patient-header-table th,
        .patient-header-table td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
            overflow: hidden;
        }
        .patient-header-table th {
            background: #f1f5f9;
            font-size: 8pt;
            font-weight: 800;
            text-align: center;
            white-space: nowrap;
        }
        .pid-cell {
            font-size: 13pt;
            font-weight: 900;
            text-align: center;
            letter-spacing: 0.5px;
        }
        .pname-cell {
            padding: 1px 6px !important;
        }
        .pkana-sub {
            font-size: 7pt;
            color: #333;
            line-height: 1.1;
            min-height: 9pt;
        }
        .pname-main {
            font-size: 12.5pt;
            font-weight: 900;
            line-height: 1.15;
        }

        /* 2. 第1ブロック：受付・下剤（左） ＆ 問診・安全確認・備考（右） */
        .two-col-grid {
            display: grid;
            grid-template-columns: minmax(0, 42fr) minmax(0, 58fr);
            gap: 4px;
            width: 100%;
            margin-bottom: 4px;
        }
        .box-panel {
            border: 1.2px solid #000;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        .panel-header {
            background: #e2e8f0;
            border-bottom: 1px solid #000;
            padding: 2px 6px;
            font-size: 8.5pt;
            font-weight: 900;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .compact-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        .compact-table td, .compact-table th {
            border-bottom: 1px solid #cbd5e1;
            padding: 2px 3px;
            vertical-align: middle;
        }
        .compact-table tr:last-child td {
            border-bottom: none;
        }
        .compact-table th {
            background: #f8fafc;
            text-align: left;
            font-weight: 800;
            white-space: nowrap;
            width: 34%;
            border-right: 1px solid #cbd5e1;
        }

        /* 問診リスト（上詰めにして下部の備考欄スペースを最大化） */
        .monshin-list {
            padding: 1px 4px;
            font-size: 7.8pt;
            line-height: 1.24;
            flex: 0 0 auto;
        }
        .monshin-row {
            padding: 1px 1px;
            border-bottom: 1px dashed #cbd5e1;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 1px;
        }
        .monshin-row .cb-item {
            padding: 0 2px;
        }
        .monshin-row:last-child {
            border-bottom: none;
        }
        .monshin-label {
            font-weight: 800;
            min-width: 56px;
        }
        .remarks-box {
            border-top: 1px solid #000;
            padding: 2px 5px;
            background: #fffbeb;
            font-size: 8pt;
            flex: 1 1 auto;
            min-height: 24mm;
            display: flex;
            flex-direction: column;
        }
        .remarks-title {
            font-size: 7.5pt;
            font-weight: 800;
            color: #333;
            margin-bottom: 1px;
            flex: 0 0 auto;
        }
        .remarks-textarea {
            width: 100%;
            flex: 1 1 auto;
            border: none;
            background: transparent;
            font-family: inherit;
            font-size: 8.2pt;
            font-weight: 700;
            line-height: 1.28;
            resize: none;
            outline: none;
            padding: 0;
        }

        /* 3. 第2ブロック：共通薬剤（鎮静・鎮痛・鎮痙・生食） ＆ 採血・検査時刻 */
        .common-drug-grid {
            display: grid;
            grid-template-columns: minmax(0, 64fr) minmax(0, 36fr);
            gap: 4px;
            width: 100%;
            margin-bottom: 4px;
        }
        .drug-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.2pt;
        }
        .drug-table td {
            border-bottom: 1px solid #cbd5e1;
            padding: 2px 4px;
            vertical-align: middle;
        }
        .drug-table tr:last-child td {
            border-bottom: none;
        }
        .drug-name-col {
            width: 48%;
            font-weight: 700;
            border-right: 1px solid #cbd5e1;
        }
        .time-record-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        .time-record-table td {
            border-bottom: 1px solid #cbd5e1;
            padding: 2px 4px;
            white-space: nowrap;
        }
        .time-record-table tr:last-child td {
            border-bottom: none;
        }
        .btn-now-mini {
            font-size: 9px;
            padding: 0 3px;
            border: 1px solid #94a3b8;
            background: #f1f5f9;
            border-radius: 3px;
            cursor: pointer;
            margin-left: 2px;
        }

        /* 4. 第3ブロック：大腸内視鏡 ＆ 胃・十二指腸内視鏡 */
        .exam-section-box {
            border: 1.5px solid #000;
            margin-bottom: 4px;
            width: 100%;
        }
        .exam-section-header {
            background: #e2e8f0;
            border-bottom: 1.2px solid #000;
            padding: 2.5px 8px;
            font-size: 9.5pt;
            font-weight: 900;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .exam-section-box.is-scheduled-exam {
            border-width: 2px;
        }
        .exam-section-box.is-scheduled-exam .exam-section-header {
            background: #fef08a;
        }
        .exam-target-badge {
            font-size: 8pt;
            font-weight: 900;
            padding: 0 6px;
            border: 1px solid #000;
            background: #fff;
            border-radius: 3px;
        }

        .exam-body-grid {
            display: grid;
            grid-template-columns: minmax(0, 71fr) minmax(0, 29fr);
            width: 100%;
        }
        .exam-proc-col {
            border-right: 1px solid #000;
            padding: 2px 5px;
            font-size: 8.2pt;
            min-width: 0;
        }
        .exam-prep-col {
            padding: 3px 5px;
            background: #f8fafc;
            font-size: 8pt;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-width: 0;
        }
        .prep-subbox-title {
            font-size: 7.5pt;
            font-weight: 800;
            border-bottom: 1px solid #94a3b8;
            margin-bottom: 2px;
            padding-bottom: 1px;
        }
        .proc-row {
            padding: 2px 0;
            border-bottom: 1px dotted #cbd5e1;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }
        .proc-row:last-child {
            border-bottom: none;
        }
        .proc-title {
            font-weight: 800;
            min-width: 145px;
        }
        .site-list {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 3px;
            font-size: 8pt;
        }

        /* OK / 回復 / 待合室 の楕円バッジ群 */
        .oval-status-group {
            display: flex;
            justify-content: space-around;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
            padding-top: 3px;
            border-top: 1px dashed #94a3b8;
        }
        .oval-stamp {
            border: 1.5px solid #475569;
            border-radius: 999px;
            padding: 2px 10px;
            font-size: 8.5pt;
            font-weight: 800;
            cursor: pointer;
            user-select: none;
            background: #fff;
            transition: all 0.1s;
        }
        .oval-stamp.is-circled {
            border: 2px solid #000;
            background: #fef08a;
            transform: scale(1.04);
        }

        /* 5. 第4ブロック（破線区切り下）：説明・手紙・同意書・医師署名・会計 */
        .bottom-dashed-divider {
            border-top: 2px dashed #000;
            margin: 2px 0 4px 0;
        }
        .footer-workflow-grid {
            display: grid;
            grid-template-columns: minmax(0, 25fr) minmax(0, 39fr) minmax(0, 36fr);
            gap: 4px;
            width: 100%;
            border: 1.2px solid #000;
            padding: 4px 6px;
            font-size: 8.2pt;
            align-items: center;
        }
        .letter-branch-box {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .branch-lines {
            display: flex;
            flex-direction: column;
            gap: 2px;
            border-left: 1.5px solid #000;
            padding-left: 5px;
            margin-left: 2px;
        }
        .doc-check-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.8pt;
        }
        .doc-check-table th, .doc-check-table td {
            border: 1px solid #000;
            padding: 1.5px 4px;
            text-align: center;
        }
        .doc-check-table td.doc-name {
            text-align: left;
            font-weight: 700;
        }

        .sign-accounting-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 4px;
            padding-top: 2px;
        }
        .doctor-sign-line {
            font-size: 9.5pt;
            font-weight: 800;
            border-bottom: 1.5px solid #000;
            min-width: 65mm;
            padding-bottom: 1px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .accounting-box {
            font-size: 10.5pt;
            font-weight: 900;
            border: 1.5px solid #000;
            padding: 2px 12px;
            background: #f8fafc;
        }
        .sheet-sys-footer {
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            color: #475569;
            margin-top: 2px;
        }

        @media print {
            body {
                background: #fff !important;
            }
            .no-print, .no-print-toolbar, .btn-now-mini, .server-print-toast {
                display: none !important;
            }
            .sheet-container {
                width: 100%;
                max-width: 100%;
                min-height: 0;
                max-height: none;
                margin: 0;
                padding: 0 0.5mm;
                box-shadow: none;
            }
            .remarks-textarea {
                overflow: hidden !important;
            }
        }
    </style>
</head>
<body>

<!-- 非印刷：上部操作バー -->
<div class="no-print-toolbar">
    <div class="toolbar-left">
        <div class="toolbar-title">
            📋 内視鏡シート
        </div>
        <span class="toolbar-hint">💡 画面上の「□」や選択肢（部位・無/有・OK等）をタップするとチェック・丸囲みできます</span>
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn-tool" onclick="toggleDoctorPrefill()">👨‍⚕️ 医師名印字</button>
        <button type="button" class="btn-tool" onclick="resetSheetInputs()">🔄 リセット</button>

        <!-- 🖨️ 出力先プリンタ選択セレクター (内視鏡シート専用・端末ごとに独立記憶) -->
        <label class="printer-select-pill" title="印刷出力先を選択します（端末の通常印刷、またはサーバーから院内プリンタへの直接印刷）">
            <span>🖨️ 出力先:</span>
            <select id="selectPrintDestination" onchange="onPrintDestinationChange(this.value)">
                <?= renderPrinterSelectOptions() ?>
            </select>
        </label>

        <!-- 🖨️ シート印刷 ボタン（Windows端末印刷 ⇔ タブレット・サーバー直接印刷ハイブリッド） -->
        <button type="button" id="btnSheetPrintExecute" class="btn-tool btn-print-main" onclick="executeSheetPrint()">
            <span id="btnSheetPrintText">🖨️ シート印刷</span>
        </button>

        <button type="button" class="btn-tool" onclick="window.close()">✕ 閉じる</button>
    </div>
</div>

<!-- サーバー印刷 トースト通知 -->
<div id="serverPrintToast" class="server-print-toast">
    <span id="toastIcon">ℹ️</span>
    <span id="toastMessage">メッセージ</span>
</div>

<!-- A4 1枚シート本体 -->
<div class="sheet-container" id="endoscopySheetRoot" data-schedule-id="<?= (int)$schedule_id ?>">

    <div>
        <!-- 最上段：診療科 ＆ 検査日・予約枠・来院時刻・部屋情報 -->
        <div class="sheet-top-bar">
            <div class="clinic-brand">
                <div class="dept-switch-group">
                    <span class="cb-item <?= $is_proct ? 'is-checked' : '' ?>" data-key="dept_proct">
                        <span class="cb-box">✓</span><span class="cb-label">肛門科</span>
                    </span>
                    <span class="cb-item <?= !$is_proct ? 'is-checked' : '' ?>" data-key="dept_gastro">
                        <span class="cb-box">✓</span><span class="cb-label">胃腸科</span>
                    </span>
                </div>
            </div>
            <div class="exam-meta-right">
                <span style="font-size:9.5pt; margin-right:4px;"><strong>検査日：</strong><?= htmlspecialchars($exam_date_disp, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="meta-pill meta-pill-wide">枠: <?= htmlspecialchars($scheduled_time !== '' ? $scheduled_time : '　　　', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="meta-pill">来院: <?= htmlspecialchars($arrival_time !== '' ? $arrival_time : '　 : 　', ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($room_number !== ''): ?>
                    <span class="meta-pill hl-yellow">部屋: <?= htmlspecialchars($room_number, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 患者基本情報テーブル -->
        <table class="patient-header-table">
            <tr>
                <th style="width:9%;">カルテ番号</th>
                <td style="width:15%;" class="pid-cell hl-yellow"><?= htmlspecialchars($patient_id !== '' ? $patient_id : '---', ENT_QUOTES, 'UTF-8') ?></td>
                <th style="width:5%;">氏名</th>
                <td style="width:33%;" class="pname-cell">
                    <div class="pkana-sub" id="dispPatientKana"><?= htmlspecialchars($patient_kana, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="pname-main"><?= htmlspecialchars($patient_name !== '' ? $patient_name : '（氏名未登録）', ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td style="width:9%; text-align:center; font-weight:800; font-size:9.5pt;" id="dispGenderCell">
                    <span class="circle-choice <?= $gender_short === '男' ? 'is-circled' : '' ?>" data-key="sex_m">男</span>
                    <span class="circle-choice <?= $gender_short === '女' ? 'is-circled' : '' ?>" data-key="sex_f">女</span>
                </td>
                <th style="width:5%; line-height:1.15;">生年<br>月日</th>
                <td style="width:16%; text-align:center; font-size:8.5pt; font-weight:700;">
                    <?= htmlspecialchars($wareki_bday, ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="width:8%; text-align:center; font-weight:900; font-size:10.5pt;">
                    <?= htmlspecialchars((string)$age_disp, ENT_QUOTES, 'UTF-8') ?><span style="font-size:8pt; font-weight:normal;">歳</span>
                </td>
            </tr>
            <tr>
                <th>予定検査</th>
                <td colspan="3" style="font-weight:800;">
                    <span class="hl-yellow" style="padding:1px 8px; border:1px solid #000; font-size:10pt; display:inline-block;">
                        <?= htmlspecialchars($exam_type !== '' ? $exam_type : '未設定', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ($has_sedation): ?>
                        <span class="alert-tag-mini">💉鎮静予定</span>
                    <?php endif; ?>
                    <?php if ($is_admission): ?>
                        <span class="alert-tag-mini">🏥入院予定</span>
                    <?php endif; ?>
                    <?php if ($has_drive_caution): ?>
                        <span class="alert-tag-mini">🚗送迎/車注意</span>
                    <?php endif; ?>
                </td>
                <td colspan="2" style="padding:0;">
                    <div style="display:flex; align-items:center; height:100%;">
                        <span style="background:#f1f5f9; font-weight:800; font-size:8pt; padding:3px 6px; border-right:1px solid #000; white-space:nowrap;">担当医</span>
                        <span style="flex:1; text-align:center; font-weight:900; font-size:9.5pt;"><?= htmlspecialchars($doctor_name !== '' ? $doctor_name : '　　　', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </td>
                <td colspan="2" style="padding:0;">
                    <div style="display:flex; align-items:center; height:100%;">
                        <span style="background:#f1f5f9; font-weight:800; font-size:8pt; padding:3px 6px; border-right:1px solid #000; white-space:nowrap;">検査室</span>
                        <span style="flex:1; text-align:center; font-weight:700; font-size:8pt;"><?= htmlspecialchars($exam_location !== '' ? $exam_location : '　　　', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </td>
            </tr>
        </table>

        <!-- 第1ブロック：受付・来院確認＆前処置下剤（左） ／ 問診・安全確認＆備考（右） -->
        <div class="two-col-grid">
            <!-- 左カラム：来院時確認 ＆ 前処置・下剤 -->
            <div style="display:flex; flex-direction:column; gap:4px; min-width:0;">
                <div class="box-panel">
                    <div class="panel-header">
                        <span>■ 受付・来院時チェック</span>
                    </div>
                    <table class="compact-table">
                        <tr>
                            <th>付添</th>
                            <td>
                                <span class="circle-choice" data-key="attendant_no">無</span> ・
                                <span class="circle-choice" data-key="attendant_yes">有</span>
                                （<input type="text" class="inline-input" style="width:72px;" data-input="attendant_note" placeholder="">）
                            </td>
                        </tr>
                        <tr class="<?= $has_drive_caution ? 'hl-prep-active' : '' ?>">
                            <th>迎え・ﾀｸｼｰ・車</th>
                            <td>
                                <span class="circle-choice" data-key="trans_pickup">迎え</span>・<span class="circle-choice" data-key="trans_taxi">ﾀｸｼｰ</span>・<span class="circle-choice" data-key="trans_car">車</span>
                                （<input type="text" class="inline-input" style="width:52px;" data-input="trans_note">）
                            </td>
                        </tr>
                        <tr>
                            <th>荷物 ／ 内服</th>
                            <td>
                                荷物:<span class="circle-choice" data-key="bag_no">無</span>･<span class="circle-choice" data-key="bag_yes">有</span>(<input type="text" class="inline-input" style="width:24px;" data-input="bag_note">) ／
                                内服:<span class="circle-choice" data-key="med_no">無</span>･<span class="circle-choice" data-key="med_yes">有</span>(<input type="text" class="inline-input" style="width:24px;" data-input="med_note">)
                            </td>
                        </tr>
                        <tr class="<?= $is_admission ? 'hl-prep-active' : '' ?>">
                            <th>TV・入院ｾｯﾄ</th>
                            <td>
                                特(<input type="text" class="inline-input" style="width:24px;" data-input="room_toku">)
                                個(<input type="text" class="inline-input" style="width:24px;" data-input="room_ko">)
                                大(<input type="text" class="inline-input" style="width:24px;" data-input="room_dai">)
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 前処置・下剤チェック欄（原本の全6種完全網羅） -->
                <div class="box-panel" style="flex:1;">
                    <div class="panel-header">
                        <span>■ 前処置・下剤</span>
                        <?php if ($bowel_prep !== ''): ?>
                            <span class="alert-tag-mini">予定: <?= htmlspecialchars($bowel_prep, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <table class="compact-table">
                        <tr class="<?= $is_enema_cf ? 'hl-prep-active' : '' ?>">
                            <td>
                                <span class="cb-item <?= $is_enema_cf ? 'is-checked' : '' ?>" data-key="prep_gly50">
                                    <span class="cb-box">✓</span><span class="cb-label">グリセリン浣腸液50%</span>
                                </span>
                            </td>
                            <td>
                                <span class="circle-choice" data-key="prep_gly50_60">60ml</span> ・
                                <span class="circle-choice" data-key="prep_gly50_120">120ml</span>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <span class="cb-item" data-key="prep_gly_high">
                                    <span class="cb-box">✓</span><span class="cb-label">高圧浣腸 グリセリン</span>
                                </span>
                            </td>
                            <td>
                                <span class="circle-choice" data-key="prep_gly_high_100">100ml</span>
                            </td>
                        </tr>
                        <tr class="<?= $prep_is_mag ? 'hl-prep-active' : '' ?>">
                            <td>
                                <span class="cb-item <?= $prep_is_mag ? 'is-checked' : '' ?>" data-key="prep_mag">
                                    <span class="cb-box">✓</span><span class="cb-label">マグコロール散68%</span>
                                </span>
                            </td>
                            <td>
                                <span class="circle-choice" data-key="prep_mag_50">50g</span> ・
                                <span class="circle-choice" data-key="prep_mag_100">100g</span> ・
                                <span class="circle-choice" data-key="prep_mag_150">150g</span>
                            </td>
                        </tr>
                        <tr class="<?= $prep_is_nif ? 'hl-prep-active' : '' ?>">
                            <td>
                                <span class="cb-item <?= $prep_is_nif ? 'is-checked' : '' ?>" data-key="prep_nif">
                                    <span class="cb-box">✓</span><span class="cb-label">ニフレック</span>
                                </span>
                            </td>
                            <td>
                                <span class="circle-choice" data-key="prep_nif_1">1袋</span>
                                （ <span class="circle-choice" data-key="prep_nif_15">1.5</span> ・ <span class="circle-choice" data-key="prep_nif_20">2</span> ）
                            </td>
                        </tr>
                        <tr class="<?= $prep_is_movi ? 'hl-prep-active' : '' ?>">
                            <td>
                                <span class="cb-item <?= $prep_is_movi ? 'is-checked' : '' ?>" data-key="prep_movi">
                                    <span class="cb-box">✓</span><span class="cb-label">モビプレップ</span>
                                </span>
                            </td>
                            <td>
                                <span class="circle-choice" data-key="prep_movi_1">1袋</span>
                                （ <span class="circle-choice" data-key="prep_movi_15">1.5</span> ・ <span class="circle-choice" data-key="prep_movi_20">2</span> ）
                            </td>
                        </tr>
                        <tr class="<?= $prep_is_visi ? 'hl-prep-active' : '' ?>">
                            <td>
                                <span class="cb-item <?= $prep_is_visi ? 'is-checked' : '' ?>" data-key="prep_visi">
                                    <span class="cb-box">✓</span><span class="cb-label">ビジクリア</span>
                                </span>
                            </td>
                            <td>
                                <input type="text" class="inline-input" style="width:45px;" data-input="prep_visi_tabs"> 錠
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- 右カラム：問診・安全確認事項 ＆ 予約時備考 -->
            <div class="box-panel">
                <div class="panel-header">
                    <span>■ 問診・医療安全確認事項</span>
                    <?php if (!empty($alert['has_antithrombotic']) || !empty($alert['has_diabetes']) || !empty($alert['has_infection']) || !empty($alert['has_allergy'])): ?>
                        <span class="alert-tag-mini">⚠️ 電カル/備考アラート検知あり</span>
                    <?php endif; ?>
                </div>
                <div class="monshin-list">
                    <div class="monshin-row">
                        <span class="monshin-label">排便回数：</span>
                        <span>昨日(<input type="text" class="inline-input" style="width:18px;" data-input="stool_yest">)･今日(<input type="text" class="inline-input" style="width:18px;" data-input="stool_today">) 最終(<input type="text" class="inline-input" style="width:32px;" data-input="stool_last">)</span>
                        <span style="margin-left:2px;">便秘:<span class="circle-choice" data-key="constip_no">無</span>･<span class="circle-choice" data-key="constip_yes">有</span></span>
                        <span style="margin-left:2px;">難聴:<span class="circle-choice" data-key="deaf_no">無</span>･<span class="circle-choice" data-key="deaf_yes">有</span></span>
                    </div>

                    <div class="monshin-row <?= !empty($alert['has_infection']) ? 'hl-alert-row' : '' ?>">
                        <span class="monshin-label">感染症　：</span>
                        <span>HB(<input type="text" class="inline-input" style="width:22px;" data-input="inf_hb">)・HC(<input type="text" class="inline-input" style="width:22px;" data-input="inf_hc">)・RPR(<input type="text" class="inline-input" style="width:22px;" data-input="inf_rpr">)</span>
                        <span class="cb-item" data-key="inf_untested" style="margin-left:3px;"><span class="cb-box">✓</span><span class="cb-label">未</span></span>
                        <?php if (!empty($alert['has_infection'])): ?><span class="alert-tag-mini">🔴感染症注意</span><?php endif; ?>
                    </div>

                    <div class="monshin-row <?= !empty($alert['has_antithrombotic']) ? 'hl-alert-row' : '' ?>">
                        <span class="monshin-label">抗凝固薬：</span>
                        <span class="cb-item" data-key="anti_no"><span class="cb-box">✓</span><span class="cb-label">無</span></span>
                        <span class="cb-item <?= !empty($alert['has_antithrombotic']) ? 'is-checked' : '' ?>" data-key="anti_yes"><span class="cb-box">✓</span><span class="cb-label">有</span></span>
                        <span>（<span class="cb-item" data-key="anti_stop_no"><span class="cb-box">✓</span><span class="cb-label">休薬なし</span></span>
                        <span class="cb-item" data-key="anti_stop_yes"><span class="cb-box">✓</span><span class="cb-label">休薬あり</span></span>
                        <input type="text" class="inline-input" style="width:18px;" data-input="anti_m">/<input type="text" class="inline-input" style="width:18px;" data-input="anti_d">～<input type="text" class="inline-input" style="width:46px;" data-input="anti_drug">）</span>
                    </div>

                    <div class="monshin-row <?= !empty($alert['has_diabetes']) ? 'hl-alert-row' : '' ?>">
                        <span class="monshin-label">糖尿病薬：</span>
                        <span class="cb-item" data-key="dm_no"><span class="cb-box">✓</span><span class="cb-label">無</span></span>
                        <span class="cb-item <?= !empty($alert['has_diabetes']) ? 'is-checked' : '' ?>" data-key="dm_yes"><span class="cb-box">✓</span><span class="cb-label">有</span></span>
                        <span>（<span class="cb-item" data-key="dm_stop_no"><span class="cb-box">✓</span><span class="cb-label">休薬なし</span></span>
                        <span class="cb-item" data-key="dm_stop_yes"><span class="cb-box">✓</span><span class="cb-label">休薬あり</span></span>
                        <input type="text" class="inline-input" style="width:18px;" data-input="dm_m">/<input type="text" class="inline-input" style="width:18px;" data-input="dm_d">～<input type="text" class="inline-input" style="width:46px;" data-input="dm_drug">）</span>
                    </div>

                    <div class="monshin-row <?= !empty($alert['has_diabetes']) ? 'hl-alert-row' : '' ?>">
                        <span class="monshin-label">インスリン：</span>
                        <span class="cb-item" data-key="ins_no"><span class="cb-box">✓</span><span class="cb-label">無</span></span>
                        <span class="cb-item" data-key="ins_yes"><span class="cb-box">✓</span><span class="cb-label">有</span></span>
                        <span>（<span class="cb-item" data-key="ins_stop_no"><span class="cb-box">✓</span><span class="cb-label">休薬なし</span></span>
                        <span class="cb-item" data-key="ins_stop_yes"><span class="cb-box">✓</span><span class="cb-label">休薬あり</span></span>
                        <input type="text" class="inline-input" style="width:18px;" data-input="ins_m">/<input type="text" class="inline-input" style="width:18px;" data-input="ins_d">～<input type="text" class="inline-input" style="width:46px;" data-input="ins_drug">）</span>
                    </div>

                    <div class="monshin-row">
                        <span class="monshin-label">シャント・乳腺手術：</span>
                        <span class="cb-item" data-key="shunt_no"><span class="cb-box">✓</span><span class="cb-label">無</span></span>
                        <span class="cb-item" data-key="shunt_yes"><span class="cb-box">✓</span><span class="cb-label">有</span></span>
                        <span style="margin-left:6px;">注射（ <span class="circle-choice" data-key="inj_right">右</span> ・ <span class="circle-choice" data-key="inj_left">左</span> ）</span>
                    </div>

                    <div class="monshin-row">
                        <span class="monshin-label">当日内服：</span>
                        <span>血圧:<span class="circle-choice" data-key="bp_med_done">済</span>･<span class="circle-choice" data-key="bp_med_yet">未</span></span>
                        <span style="margin-left:3px;">BP(<input type="text" class="inline-input" style="width:26px;" data-input="bp_sys">/<input type="text" class="inline-input" style="width:26px;" data-input="bp_dia">)</span>
                        <span style="margin-left:3px;">他(<input type="text" class="inline-input" style="width:56px;" data-input="today_med_other">)</span>
                    </div>
                </div>

                <!-- 予約備考・特記事項欄 -->
                <div class="remarks-box">
                    <div class="remarks-title">📝 予約時備考・申し送り事項（追記可）：</div>
                    <textarea class="remarks-textarea" id="sheetRemarksArea" data-input="remarks_memo" rows="1"><?= htmlspecialchars($clean_remarks, ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
        </div>

        <!-- 第2ブロック：共通薬剤（鎮静・鎮痛・鎮痙・注射） ＆ 採血・検査時刻記録 -->
        <div class="common-drug-grid">
            <div class="box-panel">
                <div class="panel-header">
                    <span>■ 使用薬剤（鎮静・鎮痛・鎮痙・注射 共通）</span>
                    <?php if ($has_sedation): ?>
                        <span class="alert-tag-mini">💉 鎮静希望/予定あり</span>
                    <?php endif; ?>
                </div>
                <table class="drug-table">
                    <tr>
                        <td class="drug-name-col">
                            <span class="cb-item" data-key="drug_propofol">
                                <span class="cb-box">✓</span><span class="cb-label">プロポフォール 1％</span>
                            </span>
                        </td>
                        <td>
                            <input type="text" class="inline-input" style="width:55px;" data-input="drug_propofol_mg"> <strong>mg</strong>
                        </td>
                    </tr>
                    <tr>
                        <td class="drug-name-col">
                            <span class="cb-item" data-key="drug_diazepam">
                                <span class="cb-box">✓</span><span class="cb-label">ジアゼパム 10mg ／ セルシン 10mg</span>
                            </span>
                        </td>
                        <td>
                            <input type="text" class="inline-input" style="width:55px;" data-input="drug_diazepam_ml"> <strong>ml</strong>
                        </td>
                    </tr>
                    <tr>
                        <td class="drug-name-col">
                            <span class="cb-item" data-key="drug_pethilorfan">
                                <span class="cb-box">✓</span><span class="cb-label">ペチロルファン ／ 弱ペチロルファン 35mg</span>
                            </span>
                        </td>
                        <td>
                            <input type="text" class="inline-input" style="width:55px;" data-input="drug_pethi_ml"> <strong>ml</strong>
                            （ <span class="circle-choice" data-key="pethi_norm">ペチ</span> ・ <span class="circle-choice" data-key="pethi_weak">弱ペチ</span> ）
                        </td>
                    </tr>
                    <tr>
                        <td class="drug-name-col">
                            <span class="cb-item" data-key="drug_buscopan">
                                <span class="cb-box">✓</span><span class="cb-label">ブスコパン 20mg</span>
                            </span>
                        </td>
                        <td>
                            <span class="circle-choice" data-key="buscopan_1ml">1 ml</span>
                            （<input type="text" class="inline-input" style="width:45px;" data-input="buscopan_note">）
                        </td>
                    </tr>
                    <tr>
                        <td class="drug-name-col">
                            <span class="cb-item" data-key="drug_glucagon">
                                <span class="cb-box">✓</span><span class="cb-label">注射用グルカゴンG・ノボ</span>
                            </span>
                        </td>
                        <td>
                            <span class="circle-choice" data-key="glucagon_1v">1 V</span>
                            <span style="margin-left:8px;">
                                （<span class="circle-choice" data-key="glu_heart">心</span>）
                                （<span class="circle-choice" data-key="glu_prostate">前</span>）
                                （<span class="circle-choice" data-key="glu_glaucoma">緑</span>）
                                （<span class="circle-choice" data-key="glu_age">齢</span>）
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="drug-name-col">
                            <span class="cb-item" data-key="drug_saline">
                                <span class="cb-box">✓</span><span class="cb-label">生食</span>
                            </span>
                        </td>
                        <td>
                            <span class="circle-choice" data-key="saline_20">20 ml</span> 　
                            <span class="circle-choice" data-key="saline_100">100 ml</span> 　
                            <span class="circle-choice" data-key="saline_500">500 ml</span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 右側：採血・アル綿禁 ＆ 検査時刻記録 -->
            <div class="box-panel">
                <div class="panel-header">
                    <span>■ 採血・消毒 ／ 時間記録</span>
                </div>
                <table class="time-record-table">
                    <tr class="<?= !empty($alert['has_allergy']) ? 'hl-alert-row' : '' ?>">
                        <td colspan="2" style="font-weight:800; padding:3px 6px;">
                            <div>
                                ＊＊採血：
                                <span class="circle-choice" data-key="blood_draw_yes">あり</span> 　
                                <span class="circle-choice" data-key="blood_draw_no">なし</span>
                            </div>
                            <div style="margin-top:2px;">
                                <span class="cb-item <?= !empty($alert['has_allergy']) ? 'is-checked' : '' ?>" data-key="alc_swab_ban">
                                    <span class="cb-box">✓</span><span class="cb-label" style="color:#b91c1c; font-weight:900;">アル綿 禁</span>
                                </span>
                                <?php if (!empty($alert['has_allergy'])): ?>
                                    <span class="alert-tag-mini">⚠️局麻/ｱﾚﾙｷﾞｰ注意</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:800; width:34%;">検査開始 ：</td>
                        <td>
                            (<input type="text" class="inline-input" style="width:22px;" id="timeStartH" data-input="t_start_h">:<input type="text" class="inline-input" style="width:22px;" id="timeStartM" data-input="t_start_m">)
                            <button type="button" class="btn-now-mini" onclick="stampNow('timeStartH','timeStartM')">打刻</button>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:800;">検査終了 ：</td>
                        <td>
                            (<input type="text" class="inline-input" style="width:22px;" id="timeEndH" data-input="t_end_h">:<input type="text" class="inline-input" style="width:22px;" id="timeEndM" data-input="t_end_m">)
                            <button type="button" class="btn-now-mini" onclick="stampNow('timeEndH','timeEndM')">打刻</button>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:800;">起　　床 ：</td>
                        <td>
                            (<input type="text" class="inline-input" style="width:22px;" id="timeWakeH" data-input="t_wake_h">:<input type="text" class="inline-input" style="width:22px;" id="timeWakeM" data-input="t_wake_m">)
                            <button type="button" class="btn-now-mini" onclick="stampNow('timeWakeH','timeWakeM')">打刻</button>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:800;">説　　明 ：</td>
                        <td>
                            (<input type="text" class="inline-input" style="width:22px;" id="timeExpH" data-input="t_exp_h">:<input type="text" class="inline-input" style="width:22px;" id="timeExpM" data-input="t_exp_m">)
                            <button type="button" class="btn-now-mini" onclick="stampNow('timeExpH','timeExpM')">打刻</button>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 第3ブロック①：【大腸内視鏡】手技・加算・部位・前処置薬 -->
        <div class="exam-section-box <?= $is_cf_active ? 'is-scheduled-exam' : '' ?>">
            <div class="exam-section-header">
                <span>
                    <span class="cb-item <?= $is_cf_active ? 'is-checked' : '' ?>" data-key="sec_cf_enable">
                        <span class="cb-box">✓</span><span class="cb-label" style="font-size:9.5pt;">大腸内視鏡（CF）</span>
                    </span>
                </span>
                <?php if ($is_cf_active): ?>
                    <span class="exam-target-badge">★ 本日予定（<?= htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8') ?>）</span>
                <?php endif; ?>
            </div>
            <div class="exam-body-grid">
                <div class="exam-proc-col">
                    <!-- 挿入到達部位 -->
                    <div class="proc-row">
                        <span class="proc-title">【挿入・到達部位】</span>
                        <div class="site-list">
                            <?php foreach (['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸'] as $idx => $site): ?>
                                <span class="circle-choice" data-key="cf_reach_<?= $idx ?>"><?= $site ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 加算・特殊手技 -->
                    <div class="proc-row">
                        <span class="proc-title">【加算・特殊法】</span>
                        <span class="cb-item" data-key="cf_dye"><span class="cb-box">✓</span><span class="cb-label">色素法</span></span>
                        <span class="cb-item" data-key="cf_nbi" style="margin-left:8px;"><span class="cb-box">✓</span><span class="cb-label">狭帯域光強調加算</span></span>
                        <span class="cb-item" data-key="cf_tattoo" style="margin-left:8px;"><span class="cb-box">✓</span><span class="cb-label">点墨法</span></span>
                    </div>

                    <!-- ポリープ切除術（2cm未満） -->
                    <div class="proc-row <?= $is_polypep ? 'hl-prep-active' : '' ?>">
                        <span class="proc-title">
                            <span class="cb-item" data-key="cf_poly_u2"><span class="cb-box">✓</span><span class="cb-label">ポリープ切除術（2cm未満）</span></span>
                        </span>
                        <div class="site-list">
                            <?php foreach (['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸', '回腸'] as $idx => $site): ?>
                                <span class="circle-choice" data-key="cf_poly_u2_<?= $idx ?>"><?= $site ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ポリープ切除術（2cm以上） -->
                    <div class="proc-row">
                        <span class="proc-title">
                            <span class="cb-item" data-key="cf_poly_o2"><span class="cb-box">✓</span><span class="cb-label">ポリープ切除術（2cm以上）</span></span>
                        </span>
                        <div class="site-list">
                            <?php foreach (['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸'] as $idx => $site): ?>
                                <span class="circle-choice" data-key="cf_poly_o2_<?= $idx ?>"><?= $site ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 生検 -->
                    <div class="proc-row">
                        <span class="proc-title">
                            <span class="cb-item" data-key="cf_biopsy"><span class="cb-box">✓</span><span class="cb-label">生検</span></span>
                        </span>
                        <div class="site-list">
                            <?php foreach (['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸'] as $idx => $site): ?>
                                <span class="circle-choice" data-key="cf_biopsy_<?= $idx ?>"><?= $site ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- EMR生食・ムコアップ・止血・異物除去 -->
                    <div class="proc-row">
                        <span class="cb-item" data-key="cf_emr_saline"><span class="cb-box">✓</span><span class="cb-label">EMR生食 20ml</span></span>
                        <span class="cb-item" data-key="cf_muco" style="margin-left:8px;"><span class="cb-box">✓</span><span class="cb-label">ムコアップ 1V</span></span>
                        <span class="cb-item" data-key="cf_hemo" style="margin-left:14px;"><span class="cb-box">✓</span><span class="cb-label">止血術</span></span>
                        <span class="cb-item" data-key="cf_foreign" style="margin-left:10px;"><span class="cb-box">✓</span><span class="cb-label">異物除去術</span></span>
                    </div>
                </div>

                <!-- 大腸 前処置薬・資材ボックス -->
                <div class="exam-prep-col">
                    <div>
                        <div class="prep-subbox-title">【大腸 前処置薬・資材】</div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="cf_xylo"><span class="cb-box">✓</span><span class="cb-label">キシロカインゼリー</span></span>
                            <strong>10ml</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="cf_dimeth"><span class="cb-box">✓</span><span class="cb-label">ジメチコン内用液2％</span></span>
                            <strong>5ml</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="cf_pants"><span class="cb-box">✓</span><span class="cb-label">検査用パンツ</span></span>
                            <strong>1個</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="cf_pad"><span class="cb-box">✓</span><span class="cb-label">尿パッド</span></span>
                            <span></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 第3ブロック②：【胃・十二指腸内視鏡】手技・加算・部位・前処置薬・動線スタンプ -->
        <div class="exam-section-box <?= $is_gif_active ? 'is-scheduled-exam' : '' ?>">
            <div class="exam-section-header">
                <span>
                    <span class="cb-item <?= $is_gif_active ? 'is-checked' : '' ?>" data-key="sec_gif_enable">
                        <span class="cb-box">✓</span><span class="cb-label" style="font-size:9.5pt;">胃・十二指腸内視鏡（GIF）</span>
                    </span>
                </span>
                <?php if ($is_gif_active): ?>
                    <span class="exam-target-badge">★ 本日予定（<?= htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8') ?>）</span>
                <?php endif; ?>
            </div>
            <div class="exam-body-grid">
                <div class="exam-proc-col">
                    <!-- 加算 -->
                    <div class="proc-row">
                        <span class="proc-title">【加算・特殊法】</span>
                        <span class="cb-item" data-key="gif_dye"><span class="cb-box">✓</span><span class="cb-label">色素法</span></span>
                        <span class="cb-item" data-key="gif_nbi" style="margin-left:12px;"><span class="cb-box">✓</span><span class="cb-label">狭帯域光強調加算</span></span>
                    </div>

                    <!-- 生検（胃・食道・十二指腸） -->
                    <div class="proc-row">
                        <span class="proc-title">
                            <span class="cb-item" data-key="gif_biopsy"><span class="cb-box">✓</span><span class="cb-label">生検</span></span>
                        </span>
                        <div class="site-list">
                            <?php foreach (['胃', '食道', '十二指腸'] as $idx => $site): ?>
                                <span class="circle-choice" data-key="gif_biopsy_<?= $idx ?>" style="padding:0 8px;"><?= $site ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ポリープ切除術・止血術・異物除去術 -->
                    <div class="proc-row">
                        <span class="proc-title">【処置・手術】</span>
                        <span class="cb-item" data-key="gif_poly"><span class="cb-box">✓</span><span class="cb-label">ポリープ切除術</span></span>
                        <span class="cb-item" data-key="gif_hemo" style="margin-left:12px;"><span class="cb-box">✓</span><span class="cb-label">止血術</span></span>
                        <span class="cb-item" data-key="gif_foreign" style="margin-left:12px;"><span class="cb-box">✓</span><span class="cb-label">異物除去術</span></span>
                    </div>

                    <!-- 迅速ウレアーゼ試験定性 -->
                    <div class="proc-row">
                        <span class="cb-item" data-key="gif_urease">
                            <span class="cb-box">✓</span><span class="cb-label" style="font-weight:800;">迅速ウレアーゼ試験定性（内視鏡下生検法）</span>
                        </span>
                    </div>

                    <!-- 動線・状態丸枠（原本の「OK」「回復」「待合室」シェイプ） -->
                    <div class="oval-status-group">
                        <span style="font-size:7.5pt; font-weight:800; color:#334155;">【検査後動線】</span>
                        <span class="oval-stamp" data-key="stamp_ok">OK</span>
                        <span class="oval-stamp" data-key="stamp_recovery">回復</span>
                        <span class="oval-stamp" data-key="stamp_waiting">待合室</span>
                    </div>
                </div>

                <!-- 胃 前処置薬ボックス -->
                <div class="exam-prep-col">
                    <div>
                        <div class="prep-subbox-title">【胃カメラ 前処置薬】</div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="gif_pronase"><span class="cb-box">✓</span><span class="cb-label">プロナーゼMS</span></span>
                            <strong>0.5g</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="gif_bicarb"><span class="cb-box">✓</span><span class="cb-label">重曹</span></span>
                            <strong>1.0g</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="gif_dimeth"><span class="cb-box">✓</span><span class="cb-label">ジメチコン内用液2%</span></span>
                            <strong>5ml</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="gif_lido_sp"><span class="cb-box">✓</span><span class="cb-label">リドカインポンプスプレー8%</span></span>
                            <strong>2g</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding:1px 0;">
                            <span class="cb-item" data-key="gif_lido_vis"><span class="cb-box">✓</span><span class="cb-label">リドカイン塩酸塩ビスカス2%</span></span>
                            <strong>5ml</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 下部セクション（破線区切り下）：説明・手紙・同意書・医師署名・会計 -->
    <div>
        <div class="bottom-dashed-divider"></div>

        <div class="footer-workflow-grid">
            <!-- 左：説明・検査後採血チェック -->
            <div style="display:flex; flex-direction:column; gap:3px;">
                <span class="cb-item" data-key="post_gif_exp"><span class="cb-box">✓</span><span class="cb-label">胃カメラ説明</span></span>
                <span class="cb-item" data-key="post_cf_exp"><span class="cb-box">✓</span><span class="cb-label">大腸カメラ説明</span></span>
                <span class="cb-item" data-key="post_blood"><span class="cb-box">✓</span><span class="cb-label">検査後採血あり</span></span>
            </div>

            <!-- 中央：手紙（本人渡し／中澤・スミ）＆その他 -->
            <div style="display:flex; flex-direction:column; gap:4px;">
                <div class="letter-branch-box">
                    <span class="cb-item" data-key="post_letter"><span class="cb-box">✓</span><span class="cb-label">手紙</span></span>
                    <div class="branch-lines">
                        <div>
                            <span class="circle-choice" data-key="letter_self">本人渡し</span>
                            （ <span class="circle-choice" data-key="letter_self_done">スミ</span> ）
                        </div>
                        <div>
                            <span class="circle-choice" data-key="letter_nakazawa">中澤</span>　　
                            （ <span class="circle-choice" data-key="letter_nakazawa_done">スミ</span> ）
                        </div>
                    </div>
                </div>
                <div>
                    <strong>その他：</strong><input type="text" class="inline-input" style="width:155px; text-align:left;" data-input="post_other_note">
                </div>
            </div>

            <!-- 右：書類（スキャン／配布）チェック表 -->
            <div>
                <table class="doc-check-table">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th>書類確認</th>
                            <th style="width:22%;">スキャン</th>
                            <th style="width:22%;">配布</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="doc-name">・入院診療計画書</td>
                            <td><span class="cb-item" data-key="doc_plan_scan"><span class="cb-box">✓</span></span></td>
                            <td><span class="cb-item" data-key="doc_plan_dist"><span class="cb-box">✓</span></span></td>
                        </tr>
                        <tr>
                            <td class="doc-name">・検査及び入院・手術同意書</td>
                            <td><span class="cb-item" data-key="doc_consent_scan"><span class="cb-box">✓</span></span></td>
                            <td><span class="cb-item" data-key="doc_consent_dist"><span class="cb-box">✓</span></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 医師サイン欄 ＆ □会計へ -->
        <div class="sign-accounting-bar">
            <div class="doctor-sign-line">
                <span>医師</span>
                <input type="text" class="inline-input" id="doctorSignInput" data-input="doctor_sign"
                       style="border-bottom:none; width:160px; font-size:11pt; text-align:center;"
                       value="" data-default-doctor="<?= htmlspecialchars($doctor_name, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="accounting-box">
                <span class="cb-item" data-key="go_accounting">
                    <span class="cb-box" style="width:14px; height:14px;">✓</span>
                    <span class="cb-label" style="font-size:10.5pt;">会計へ</span>
                </span>
            </div>
        </div>

        <div class="sheet-sys-footer">
            <div>スケジュールID: #<?= (int)$schedule_id ?> / 患者No: <?= htmlspecialchars($patient_id !== '' ? $patient_id : '---', ENT_QUOTES, 'UTF-8') ?></div>
            <div>おの肛門科胃腸科内視鏡クリニック 内視鏡検査・処置記録シート</div>
        </div>
    </div>

</div>

<script>
(function() {
    const root = document.getElementById('endoscopySheetRoot');
    const scheduleId = root ? root.getAttribute('data-schedule-id') : '0';
    const STORAGE_KEY = 'endoscopy_sheet_v1_' + scheduleId;
    const SERVER_PRINT_STATE = <?= json_encode($server_print_state, JSON_UNESCAPED_UNICODE) ?>;

    function collectCurrentState() {
        const state = { checks: {}, circles: {}, inputs: {} };
        document.querySelectorAll('.cb-item[data-key]').forEach(el => {
            state.checks[el.getAttribute('data-key')] = el.classList.contains('is-checked');
        });
        document.querySelectorAll('.circle-choice[data-key], .oval-stamp[data-key]').forEach(el => {
            state.circles[el.getAttribute('data-key')] = el.classList.contains('is-circled');
        });
        document.querySelectorAll('[data-input]').forEach(el => {
            state.inputs[el.getAttribute('data-input')] = el.value;
        });
        return state;
    }

    function applyStateObj(state) {
        if (!state || typeof state !== 'object') return;
        if (state.checks) {
            Object.keys(state.checks).forEach(k => {
                const el = document.querySelector(`.cb-item[data-key="${k}"]`);
                if (el) el.classList.toggle('is-checked', !!state.checks[k]);
            });
        }
        if (state.circles) {
            Object.keys(state.circles).forEach(k => {
                const el = document.querySelector(`[data-key="${k}"]`);
                if (el) el.classList.toggle('is-circled', !!state.circles[k]);
            });
        }
        if (state.inputs) {
            Object.keys(state.inputs).forEach(k => {
                const el = document.querySelector(`[data-input="${k}"]`);
                if (el && state.inputs[k] !== undefined) el.value = state.inputs[k];
            });
        }
    }

    // 状態保存・復元（タブレット・ブラウザでの入力保持用）
    function saveState() {
        const state = collectCurrentState();
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {}
    }

    function autoFitRemarks() {
        const area = document.getElementById('sheetRemarksArea');
        if (!area) return;
        area.style.height = 'auto';
        if (area.scrollHeight > area.clientHeight) {
            area.style.height = (area.scrollHeight + 2) + 'px';
        }
    }

    function loadState() {
        if (SERVER_PRINT_STATE) {
            applyStateObj(SERVER_PRINT_STATE);
            autoFitRemarks();
            return;
        }
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                autoFitRemarks();
                return;
            }
            const state = JSON.parse(raw);
            applyStateObj(state);
        } catch (e) {}
        autoFitRemarks();
    }

    // イベントバインド：□チェックボックス
    document.querySelectorAll('.cb-item').forEach(el => {
        el.addEventListener('click', function() {
            this.classList.toggle('is-checked');
            saveState();
        });
    });

    // イベントバインド：◯丸囲み選択肢＆楕円スタンプ
    document.querySelectorAll('.circle-choice, .oval-stamp').forEach(el => {
        el.addEventListener('click', function() {
            this.classList.toggle('is-circled');
            saveState();
        });
    });

    // イベントバインド：テキスト・数値入力
    document.querySelectorAll('[data-input]').forEach(el => {
        el.addEventListener('input', function() {
            if (this.id === 'sheetRemarksArea') autoFitRemarks();
            saveState();
        });
    });

    // 現在時刻打刻ヘルパー
    window.stampNow = function(hId, mId) {
        const now = new Date();
        const hEl = document.getElementById(hId);
        const mEl = document.getElementById(mId);
        if (hEl) hEl.value = String(now.getHours()).padStart(2, '0');
        if (mEl) mEl.value = String(now.getMinutes()).padStart(2, '0');
        saveState();
    };

    // 医師署名欄への担当医名印字トグル
    window.toggleDoctorPrefill = function() {
        const signInput = document.getElementById('doctorSignInput');
        if (!signInput) return;
        const defDoc = signInput.getAttribute('data-default-doctor') || '';
        if (signInput.value.trim() === '') {
            signInput.value = defDoc;
        } else {
            signInput.value = '';
        }
        saveState();
    };

    // リセット
    window.resetSheetInputs = function() {
        if (!confirm('画面上で入力・チェックした内容を初期状態に戻しますか？')) return;
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {}
        location.reload();
    };

    // サーバー側でMALL3性別が未取得だった場合の非同期フォールバック補完
    const patientNo = <?= json_encode($patient_id) ?>;
    const patientName = <?= json_encode($patient_name) ?>;
    const hasGender = <?= json_encode($gender_short !== '') ?>;
    if (patientNo && !hasGender) {
        fetch('get_mall_patient.php?patient_no=' + encodeURIComponent(patientNo))
            .then(r => r.json())
            .then(d => {
                if (d && d.success) {
                    if (d.gender && d.gender.indexOf('男') !== -1) {
                        const m = document.querySelector('[data-key="sex_m"]');
                        if (m) m.classList.add('is-circled');
                    } else if (d.gender && d.gender.indexOf('女') !== -1) {
                        const f = document.querySelector('[data-key="sex_f"]');
                        if (f) f.classList.add('is-circled');
                    }
                    const kanaEl = document.getElementById('dispPatientKana');
                    if (kanaEl && !kanaEl.textContent.trim() && d.patient_kana) {
                        kanaEl.textContent = d.patient_kana;
                    }
                }
            })
            .catch(() => {});
    }

    loadState();

    // =====================================================================
    // 端末判別 & 出力先プリンタ制御（Windows: 通常印刷 / タブレット: サーバー直印刷）
    // ※ 内視鏡シート専用の設定キー 'endoscope_print_dest_sheet' で端末ごとに独立記憶
    // =====================================================================
    const isWindows = navigator.platform.indexOf('Win') > -1 || navigator.userAgent.indexOf('Windows') > -1;
    const DEST_STORAGE_KEY = 'endoscope_print_dest_sheet';

    const selectDest = document.getElementById('selectPrintDestination');
    if (selectDest) {
        let savedDest = null;
        try {
            savedDest = localStorage.getItem(DEST_STORAGE_KEY) || localStorage.getItem('endoscope_print_dest_daily');
        } catch (e) {}
        const defaultDest = isWindows ? 'local' : 'おの_C2570 複合機';
        const activeDest = savedDest || defaultDest;

        const hasOption = Array.from(selectDest.options).some(opt => opt.value === activeDest);
        selectDest.value = hasOption ? activeDest : 'local';
    }

    window.onPrintDestinationChange = function(val) {
        try {
            localStorage.setItem(DEST_STORAGE_KEY, val);
        } catch (e) {}
    };

    function showServerPrintToast(isSuccess, message) {
        const toast = document.getElementById('serverPrintToast');
        const msgEl = document.getElementById('toastMessage');
        const iconEl = document.getElementById('toastIcon');
        if (!toast || !msgEl) {
            alert(message);
            return;
        }
        msgEl.textContent = message;
        if (iconEl) iconEl.textContent = isSuccess ? '✅' : '⚠️';
        toast.className = 'server-print-toast ' + (isSuccess ? 'toast-success' : 'toast-error') + ' show';
        setTimeout(() => {
            toast.className = 'server-print-toast';
        }, 4000);
    }

    window.executeSheetPrint = function() {
        const dest = selectDest ? selectDest.value : 'local';
        if (dest === 'local') {
            setTimeout(() => {
                window.print();
            }, 50);
            return;
        }

        const btn = document.getElementById('btnSheetPrintExecute');
        const btnText = document.getElementById('btnSheetPrintText');
        const origText = '🖨️ シート印刷';

        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.7';
        }
        if (btnText) {
            btnText.textContent = '⏳ 送信中...';
        }

        const formData = new FormData();
        formData.append('action', 'print');
        formData.append('target', 'endoscopy_sheet');
        formData.append('printer_name', dest);
        formData.append('schedule_id', scheduleId);
        formData.append('patient_name', patientName);
        formData.append('sheet_state', JSON.stringify(collectCurrentState()));

        fetch('api_server_print.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                showServerPrintToast(true, data.message || '印刷指示を送信しました');
            } else {
                showServerPrintToast(false, (data && data.message) ? data.message : '印刷送信に失敗しました');
            }
        })
        .catch(err => {
            showServerPrintToast(false, '通信エラー: ' + err.message);
        })
        .finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.style.opacity = '1';
            }
            if (btnText) {
                btnText.textContent = origText;
            }
        });
    };
})();
</script>
</body>
</html>

