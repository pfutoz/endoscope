<?php
/**
 * 生成日時: 2026-08-17 14:30:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\month_matrix_v2.php
 * ファイル名: month_matrix_v2.php
 * 
 * 内視鏡室 予約管理システム (マトリクス V2) - 完成版
 * 
 * 修正・追加機能:
 *   - カルテID: onblur + Enter 両方対応
 *   - 空欄・スペースのみの場合はクリア処理
 *   - 日付入力欄に曜日表示（小野会休日ルール連携）
 *   - 基本情報エリア（変更注意）を薄いグレー背景で区別
 *   - 「保存して印刷」ボタン（saveAndPrint関数実装済み）
 *   - サジェスト機能（カルテID/患者名）
 *   - MALL連携（患者情報・メモ自動取得）
 *   - 伏字モード / 起動時移動 / 迷子表示 / 削除表示
 *   - 5分自動更新 / スクロールボタン
 *   - 1ヶ月/3ヶ月表示切替
 *   - 詳細/簡略表示切替
 *   - 枠種別ON/OFF
 *   - ドラッグ＆ドロップ移動
 *   - Enterキー誤動作防止
 *   - PatientBOX表示適正化（2026-08-05）
 *     - ID区切りを全角コロン（：）に変更
 *     - 年齢の「歳」を削除
 *     - 生年月日を短縮表記（S23.5.22形式）
 *     - 医師アイコンを追加（黒バック白文字・インラインスタイル）
 *     - 詳細モード3行表示に凝縮
 *   - モーダル保存後のスクロール位置維持（2026-08-05）
 *   - 存在しない患者ID入力時のワーニングループ防止（2026-08-05）
 *   - 保存後リロードせずセルを更新（2026-08-05）
 *   - 来院時刻 プルダウン＋直接入力 ハイブリッド対応（2026-08-05）
 *   - 保存ボタンもリロードなし対応（2026-08-12）
 *   - ドラッグ＆ドロップ移動後リロードなし対応（2026-08-12）
 *   - buildPatientBox() で背景色強制指定（透過防止）（2026-08-12）
 *   - ヘッダー行の固定（position: sticky）復旧（2026-08-12）
 *   - 表示モード切り替え（使用/最大・残数）対応（2026-08-12）
 *   - 未割当（医師未割当）表示対応（2026-08-12）
 *   - 迷子（枠定義不一致）は専用列にのみ表示（2026-08-12）
 *   - カレンダーセルの医師別枠数表示を month_schedule.php と統一（2026-08-12）
 *   - 未割当表示トグル追加（2026-08-12）
 * 
 * 修正 (2026-08-14):
 *   - セッションで月・診療科を保持（month_schedule.php と共有）
 *   - GETパラメータ > セッション > デフォルト の優先順位
 *   - 相互リンクにGETパラメータ付与
 *   - 「メニューへ」「月間カレンダーへ」リンクを上部に設置
 *   - 患者検索連携（patient_search.php から選択可能）
 *   - 患者不在・連携エラー時に赤文字メッセージ表示
 *   - サジェスト機能を完全削除（カルテID入力欄）
 * 
 * 修正 (2026-08-17):
 *   - モーダル保存時に移動元セルも更新するよう修正（ゴーストタイル解消）
 *   - openEditModal() で変更前の日付/枠を hidden フィールドに保持
 *   - saveAndPrint() で移動元/移動先両方のセルを更新
 */
ini_set('display_errors', "1"); 
error_reporting(E_ALL); 

// ============================================================
// ★★★ セッション開始 ★★★
// ============================================================
session_start();

// ============================================================
// 共通ライブラリ読み込み
// ============================================================
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/calendar_helper.php';
require_once __DIR__ . '/lib/matrix_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

// ============================================================
// パラメータ取得（セッション対応版）
// ============================================================
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : 
        ($_SESSION['ui_state']['schedule_dept'] ?? 'PROCT');

$year = isset($_GET['year']) ? (int)$_GET['year'] : 
        (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr($_SESSION['ui_state']['schedule_month'], 0, 4) : (int)date('Y'));
$month = isset($_GET['month']) ? (int)$_GET['month'] : 
         (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr($_SESSION['ui_state']['schedule_month'], 5, 2) : (int)date('n'));

$_SESSION['ui_state']['schedule_month'] = sprintf('%04d-%02d', $year, $month);
$_SESSION['ui_state']['schedule_dept'] = $dept;

$show_canceled = isset($_GET['show_canceled']) && $_GET['show_canceled'] === '1';
$show_invalid = isset($_GET['show_invalid']) && $_GET['show_invalid'] === '1';
$show_unassigned = isset($_GET['show_unassigned']) && $_GET['show_unassigned'] === '1';
$simple_mode = isset($_GET['simple']) && $_GET['simple'] === '1';
$view_mode = isset($_GET['view']) ? $_GET['view'] : '1month';
$display_mode = isset($_GET['display_mode']) ? $_GET['display_mode'] : 'use_max';

if (!in_array($dept, ['PROCT', 'GASTRO'])) { $dept = 'PROCT'; }
if (!in_array($view_mode, ['1month', '3month'])) { $view_mode = '1month'; }
if (!in_array($display_mode, ['use_max', 'remain'])) { $display_mode = 'use_max'; }

// ============================================================
// DB接続
// ============================================================
$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ============================================================
// 表示対象月の決定
// ============================================================
function getTargetMonths($year, $month, $view_mode) {
    if ($view_mode === '1month') {
        return [['year' => $year, 'month' => $month]];
    }
    $months = [];
    for ($i = 0; $i < 3; $i++) {
        $y = $year + floor(($month + $i - 1) / 12);
        $m = (($month + $i - 1) % 12) + 1;
        $months[] = ['year' => $y, 'month' => $m];
    }
    return $months;
}

$target_months = getTargetMonths($year, $month, $view_mode);
$first_month = $target_months[0];
$last_month = $target_months[count($target_months) - 1];

$prev_year = $year; $prev_month = $month - 1;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_year = $year; $next_month = $month + 1;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$prev3_year = $year; $prev3_month = $month - 3;
if ($prev3_month < 1) { $prev3_month += 12; $prev3_year--; }
if ($prev3_month < 1) { $prev3_month += 12; $prev3_year--; }
$next3_year = $year; $next3_month = $month + 3;
if ($next3_month > 12) { $next3_month -= 12; $next3_year++; }
if ($next3_month > 12) { $next3_month -= 12; $next3_year++; }

// ============================================================
// マスタデータ取得
// ============================================================
$waku_list = getWakuList($pdo, $dept);

$waku_group_map = [];
foreach ($waku_list as $w) {
    $waku_group_map[$w['waku_name']] = $w['group_code'];
}

$doctors = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'doctor' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$types = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'exam_type' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$arrivals = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'arrival_time' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$locations = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'exam_location' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);

$default_doctor_map = [
    'PROCT' => ['GIF' => '誠吾', 'TCF' => '誠吾'],
    'GASTRO' => ['GIF' => '哲也', 'ECHO' => '義智', 'TCF' => '義智']
];

// ============================================================
// データ取得
// ============================================================
$start_date = sprintf('%04d-%02d-01', $first_month['year'], $first_month['month']);
$end_date = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $last_month['year'], $last_month['month'])));

$data_sql = "SELECT 
                s.schedule_id, s.exam_date, s.scheduled_time, s.department_code, 
                s.patient_id, s.patient_name, s.patient_kana, s.birthday,
                s.doctor_name, s.exam_type, s.arrival_time, s.exam_location, 
                s.remarks, s.is_canceled, s.booking_status,
                m.memo_today, m.memo_karte, m.memo_patient
             FROM t_endoscopy_schedule s
             LEFT JOIN t_mall_memo m ON s.patient_id = m.patient_id
             WHERE s.department_code = :dept
               AND s.exam_date BETWEEN :start AND :end";

if (!$show_canceled) { $data_sql .= " AND s.is_canceled = FALSE"; }

$data_stmt = $pdo->prepare($data_sql);
$data_stmt->execute([':dept' => $dept, ':start' => $start_date, ':end' => $end_date]);
$raw_schedules = $data_stmt->fetchAll();

$monthly_data = [];
foreach ($raw_schedules as $row) {
    $m_key = date('Y-m', strtotime($row['exam_date']));
    if (!isset($monthly_data[$m_key])) { $monthly_data[$m_key] = []; }
    $monthly_data[$m_key][] = $row;
}

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内視鏡予約室 管理マトリクス V2</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 15px;
            background-color: #f4f6f9;
            color: #333;
            font-size: 13px;
        }
        .container { max-width: 1600px; margin: 0 auto; }

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .top-header h2 {
            margin: 0;
            font-size: 18px;
        }
        .top-header .header-links {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .header-link-btn {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
            transition: background 0.2s;
        }
        .header-link-btn.menu {
            background: #2c3e50;
            color: #fff;
        }
        .header-link-btn.menu:hover {
            background: #34495e;
        }
        .header-link-btn.calendar {
            background: #3b82f6;
            color: #fff;
        }
        .header-link-btn.calendar:hover {
            background: #2563eb;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px 16px;
            background: #f8fafc;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 15px;
        }
        .top-bar-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .top-bar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .dept-tabs { display: inline-flex; gap: 3px; }
        .dept-tab {
            padding: 4px 10px;
            text-decoration: none;
            font-weight: bold;
            border-radius: 4px;
            border: 1px solid #ccc;
            background: #e6e6e6;
            color: #333;
            font-size: 11px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }
        .dept-tab.active-PROCT { background: #3498db; color: #fff; border-color: #2980b9; }
        .dept-tab.active-GASTRO { background: #2ecc71; color: #fff; border-color: #27ae60; }

        .month-nav-btn {
            padding: 4px 10px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            height: 30px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }
        .month-display {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-weight: bold;
            background-color: #e6f2ff;
            padding: 4px 14px;
            font-size: 14px;
            border: 1px solid #adc5f5;
            height: 30px;
            border-radius: 4px;
            color: #1e3a8a;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        .month-display:hover { background-color: #d1e1f0; }
        .month-display .month-item { padding: 0 2px; }

        .scroll-capsule {
            display: inline-flex;
            background: #e0e0e0;
            padding: 2px;
            border-radius: 16px;
            border: 1px solid #ccc;
        }
        .scroll-arrow-btn {
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
            width: 28px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: #444;
            transition: all 0.15s ease;
        }
        .scroll-arrow-btn:hover { background: #fff; color: #000; }

        .slot-toggle-group {
            display: flex;
            align-items: center;
            gap: 2px;
            padding: 3px 8px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .slot-toggle-group .toggle-item {
            display: flex;
            align-items: center;
            gap: 2px;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .slot-toggle-group .toggle-item:hover { background: #f1f5f9; }
        .slot-toggle-group .toggle-item input[type="checkbox"] { margin: 0; cursor: pointer; }
        .color-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(0,0,0,0.1); }
        .color-dot.gif { background: #93c5fd; }
        .color-dot.echo { background: #f0abfc; }
        .color-dot.tcf { background: #86efac; }
        .color-dot.other { background: #fca5a5; }

        .view-mode-group {
            display: flex;
            align-items: center;
            gap: 2px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .view-mode-group .mode-btn {
            padding: 2px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            font-weight: bold;
            background: transparent;
            color: #94a3b8;
            transition: all 0.15s;
        }
        .view-mode-group .mode-btn:hover { background: #f1f5f9; }
        .view-mode-group .mode-btn.active { background: #3b82f6; color: #fff; }

        .display-mode-toggle {
            display: flex;
            gap: 2px;
            background: #f1f5f9;
            border-radius: 6px;
            padding: 2px;
            border: 1px solid #e2e8f0;
        }
        .display-mode-toggle .mode-btn {
            padding: 4px 12px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            background: transparent;
            color: #64748b;
            transition: all 0.15s;
            text-decoration: none;
        }
        .display-mode-toggle .mode-btn:hover { background: #e2e8f0; }
        .display-mode-toggle .mode-btn.active {
            background: #3b82f6;
            color: #fff;
        }

        .setting-group {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .setting-group label {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 3px;
            font-weight: normal;
            color: #475569;
            white-space: nowrap;
        }
        .setting-group label:hover { color: #0f172a; }
        .setting-group input[type="checkbox"] { margin: 0; cursor: pointer; }

        .new-badge {
            background: #ef4444;
            color: #fff;
            font-size: 8px;
            font-weight: bold;
            padding: 1px 6px;
            border-radius: 10px;
            margin-left: 2px;
            animation: blink 2s ease-in-out infinite;
        }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .matrix-container {
            overflow: auto;
            max-height: 720px;
            border: 1px solid #cbd5e1;
            background: #fff;
            border-radius: 4px;
            position: relative;
        }
        .matrix-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            min-width: 800px;
            margin-bottom: 10px;
        }
        .matrix-table thead tr.header-row th {
            position: sticky;
            top: 0;
            background-color: #e6f2ff;
            color: #2c3e50;
            font-weight: bold;
            z-index: 20;
            height: 36px;
            border-bottom: 2px solid #b4c6e7;
            border-right: 1px solid #cbd5e1;
            padding: 3px 4px;
            font-size: 10px;
            text-align: center;
        }
        .matrix-table td.fixed-date-col {
            position: sticky;
            left: 0;
            z-index: 10;
            width: 130px !important;
            min-width: 130px;
            max-width: 130px;
            border-right: 2px solid #999;
            border-bottom: 1px solid #cbd5e1;
            text-align: center;
            vertical-align: middle;
            padding: 3px 4px;
            font-size: 10px;
            background-color: #fafafa;
            cursor: pointer;
        }
        .matrix-table th.fixed-date-col {
            position: sticky;
            top: 0;
            left: 0;
            background-color: #d1e1f0 !important;
            z-index: 30;
            border-right: 2px solid #999;
            border-bottom: 2px solid #b4c6e7;
        }
        .matrix-table td {
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            padding: 2px;
            vertical-align: top;
            min-width: 65px;
            width: 65px;
        }

        .month-section { margin-bottom: 20px; }
        .month-header {
            font-weight: bold;
            font-size: 14px;
            padding: 6px 10px;
            background: #e6f2ff;
            border: 1px solid #b4c6e7;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            color: #1e3a8a;
        }
        .month-section .matrix-table {
            border-top: none;
            border-radius: 0 0 4px 4px;
        }
        .month-section .matrix-table thead tr.header-row th {
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .waku-cell {
            min-height: 60px;
            height: auto;
            cursor: pointer;
            background-color: #fff;
            transition: background-color 0.2s;
            position: relative;
        }
        .waku-cell.dragover { background-color: #fff3cd !important; border: 2px dashed #ffc107 !important; }
        .waku-cell.slot-duplicate { background-color: #fef2f2 !important; border: 2px solid #dc2626 !important; }
        .waku-cell.slot-out-of-slot { background-color: #ffedd5 !important; border: 2px solid #f59e0b !important; }
        .waku-cell.slot-unavailable { background-color: #f1f5f9 !important; border: 1px dashed #94a3b8 !important; }
        .waku-cell.slot-past { background-color: #f1f5f9 !important; opacity: 0.7; }

        .patient-box {
            border: 1px solid #b4c6e7;
            border-radius: 4px;
            padding: 3px 4px;
            text-align: left;
            font-size: 10px;
            cursor: grab;
            margin-bottom: 2px;
            display: block;
            line-height: 1.2;
            position: relative;
        }
        .patient-box:active { cursor: grabbing; }
        .patient-box.is-temporary-box { background-color: #fff2cc !important; border: 1px solid #ffcc66 !important; color: #b25900; }
        .patient-box.is-canceled-box { background-color: #e9ecef !important; border: 1px solid #adb5bd !important; opacity: 0.65; background-image: linear-gradient(45deg, #f1f3f5 25%, transparent 25%, transparent 50%, #f1f3f5 50%, #f1f3f5 75%, transparent 75%, transparent); background-size: 10px 10px; }
        .patient-box.is-canceled-box .pname-row { text-decoration: line-through; color: #6c757d; }
        .patient-box.duplicate { background-color: #fef2f2 !important; border: 2px solid #dc2626 !important; border-left: 3px solid #dc2626 !important; }
        .patient-box.out-of-slot { background-color: #ffedd5 !important; border: 2px solid #f59e0b !important; border-left: 3px solid #f59e0b !important; }

        .invalid-cell { background-color: #fff5f5 !important; }

        body.mask-active .real-pname { display: none; }
        body.mask-active .masked-pname { display: inline !important; }

        dialog {
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            padding: 0;
            width: 1100px;
            max-width: 95vw;
        }
        dialog::backdrop { background: rgba(0,0,0,0.5); }
        .modal-header { background: #e6f2ff; padding: 10px 20px; border-bottom: 1px solid #ccc; }
        .modal-header h3 { margin: 0; font-size: 16px; }
        .modal-body { padding: 18px; max-height: 80vh; overflow-y: auto; }
        .modal-split-container { display: flex; gap: 20px; align-items: stretch; flex-wrap: wrap; }
        .modal-left-form { flex: 1.2; min-width: 480px; }
        .modal-right-memos {
            flex: 1;
            background: #f8f9fa;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 12px;
            max-height: 520px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { font-weight: bold; margin-bottom: 3px; font-size: 11px; color: #444; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 5px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 12px;
            box-sizing: border-box;
        }
        .dialog-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        .dialog-buttons button { padding: 6px 16px; font-size: 12px; cursor: pointer; border-radius: 4px; border: none; }

        .basic-info-area {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 15px;
        }
        .basic-info-area .area-label {
            font-size: 11px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .basic-info-area .area-label .note {
            font-weight: normal;
            color: #94a3b8;
            font-size: 10px;
        }
        .basic-info-area .form-grid {
            margin-bottom: 0;
        }
        .basic-info-area .form-group input,
        .basic-info-area .form-group select {
            background: #ffffff;
            border-color: #cbd5e1;
        }

        .btn-quick-keep {
            background: #ff9800;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 2px 8px;
            font-size: 10px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-print-slip {
            background: #8b5cf6;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 14px;
            font-size: 12px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-print-slip:hover { background: #7c3aed; }
        .btn-print-slip:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .memo-view-block { margin-bottom: 10px; padding: 6px 8px; background: #fff; border: 1px solid #e3e6ec; border-radius: 4px; white-space: pre-wrap; font-family: monospace; font-size: 11px; }
        .suggestion-wrapper { position: relative; width: 100%; }
        .suggestion-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
        }
        .suggestion-item {
            display: flex;
            gap: 10px;
            padding: 5px 10px;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            align-items: center;
        }
        .suggestion-item:hover { background: #f1f5f9; }
        .suggestion-item .suggestion-id { font-weight: bold; color: #1e40af; min-width: 55px; }
        .suggestion-item .suggestion-name { font-weight: bold; color: #0f172a; flex: 1; }
        .suggestion-item .suggestion-birth { color: #64748b; font-size: 11px; min-width: 85px; }

        /* ★★★ エラーメッセージ用 ★★★ */
        .error-msg {
            color: #dc2626;
            font-size: 11px;
            font-weight: bold;
            display: none;
        }

        @media (max-width: 900px) {
            .top-bar { flex-direction: column; align-items: stretch; }
            .top-bar-left { justify-content: center; }
            .top-bar-right { justify-content: center; gap: 6px; }
            .top-header { flex-direction: column; align-items: stretch; text-align: center; }
            .top-header .header-links { justify-content: center; }
        }
        @media (max-width: 600px) {
            .top-bar-left { flex-wrap: wrap; justify-content: center; }
            .top-bar-right { flex-wrap: wrap; justify-content: center; }
            .scroll-capsule { display: none; }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- 上部ヘッダー -->
    <div class="top-header">
        <h2>内視鏡室 予約管理システム (MatrixView Vertical Date)</h2>
        <div class="header-links">
            <a href="index.php" class="header-link-btn menu">🏠 メニューへ</a>
            <a href="month_schedule.php?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>" class="header-link-btn calendar">📅 月間カレンダーへ</a>
        </div>
    </div>

    <!-- トップバー -->
    <div class="top-bar">
        <div class="top-bar-left">
            <div class="dept-tabs">
                <a href="?dept=PROCT&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=<?=$display_mode?>" class="dept-tab <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛</a>
                <a href="?dept=GASTRO&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=<?=$display_mode?>" class="dept-tab <?=$dept==='GASTRO'?'active-GASTRO':''?>">🔵 胃</a>
            </div>

            <?php if ($view_mode === '1month'): ?>
                <a href="?dept=<?=$dept?>&year=<?=$prev_year?>&month=<?=$prev_month?>&view=1month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">◀</a>
                <div class="month-display" onclick="toggleMonthView()">
                    <span class="month-item">[<?=$year?>.<?=$month?>]</span>
                    <span style="font-size:10px; color:#94a3b8;">▼</span>
                </div>
                <a href="?dept=<?=$dept?>&year=<?=$next_year?>&month=<?=$next_month?>&view=1month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">▶</a>
            <?php else: ?>
                <a href="?dept=<?=$dept?>&year=<?=$prev3_year?>&month=<?=$prev3_month?>&view=3month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">◀</a>
                <div class="month-display" onclick="toggleMonthView()">
                    <?php foreach ($target_months as $m): ?>
                        <span class="month-item">[<?=$m['year']?>.<?=$m['month']?>]</span>
                    <?php endforeach; ?>
                    <span style="font-size:10px; color:#94a3b8;">▲</span>
                </div>
                <a href="?dept=<?=$dept?>&year=<?=$next3_year?>&month=<?=$next3_month?>&view=3month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">▶</a>
            <?php endif; ?>

            <div class="scroll-capsule">
                <button class="scroll-arrow-btn" onclick="smoothScroll('left')">⬅</button>
                <button class="scroll-arrow-btn" onclick="smoothScroll('up')">⬆</button>
                <button class="scroll-arrow-btn" onclick="smoothScroll('down')">⬇</button>
                <button class="scroll-arrow-btn" onclick="smoothScroll('right')">➡</button>
            </div>
        </div>

        <div class="top-bar-right">
            <div class="slot-toggle-group">
                <span style="font-size:10px; color:#94a3b8; margin-right:2px;">枠</span>
                <label class="toggle-item"><input type="checkbox" checked onchange="toggleSlotGroup('GIF', this.checked)"><span class="color-dot gif"></span> GIF</label>
                <label class="toggle-item"><input type="checkbox" checked onchange="toggleSlotGroup('ECHO', this.checked)"><span class="color-dot echo"></span> エコー</label>
                <label class="toggle-item"><input type="checkbox" checked onchange="toggleSlotGroup('TCF', this.checked)"><span class="color-dot tcf"></span> TCF</label>
                <label class="toggle-item"><input type="checkbox" checked onchange="toggleSlotGroup('MISMATCH', this.checked)"><span class="color-dot other"></span> 迷子</label>
            </div>

            <div class="view-mode-group">
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=0&display_mode=<?=$display_mode?>" class="mode-btn <?=!$simple_mode?'active':''?>">📝 詳細</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=1&display_mode=<?=$display_mode?>" class="mode-btn <?=$simple_mode?'active':''?>">📄 簡略</a>
                <span class="new-badge">NEW</span>
            </div>

            <div class="setting-group">
                <label onclick="manualRefresh()" style="cursor:pointer; font-weight:bold; color:#3b82f6;"><span style="font-size:14px;">🔄</span> 5分</label>
                <label><input type="checkbox" id="chk_mask" onchange="toggleMask(this.checked)"> 🥷 伏字</label>
                <label><input type="checkbox" id="chk_auto" checked onchange="saveAutoScroll(this.checked)"> 📅 起動時</label>
                <label><input type="checkbox" id="toggle_invalid" <?=$show_invalid?'checked':''?> onchange="toggleInvalid(this.checked)"> ⚠️ 迷子</label>
                <label><input type="checkbox" id="toggle_canceled" <?=$show_canceled?'checked':''?> onchange="toggleCanceled(this.checked)"> 🗑️ 削除</label>
                <label><input type="checkbox" id="toggle_unassigned" <?=$show_unassigned?'checked':''?> onchange="toggleUnassigned(this.checked)"> 📌 未割当</label>
                <span style="font-size:10px; color:#94a3b8; margin:0 2px;">表示:</span>
                <div class="display-mode-toggle">
                    <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=use_max" class="mode-btn <?=$display_mode==='use_max'?'active':''?>">📊 使用/最大</a>
                    <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&simple=<?=$simple_mode?'1':'0'?>&display_mode=remain" class="mode-btn <?=$display_mode==='remain'?'active':''?>">📉 残数</a>
                </div>
            </div>
        </div>
    </div>

    <!-- マトリクス本体 -->
    <div class="matrix-container" id="matrixContainer">
        <?php
        foreach ($target_months as $m) {
            $m_key = sprintf('%04d-%02d', $m['year'], $m['month']);
            $month_data = $monthly_data[$m_key] ?? [];
            $month_matrix = [];
            foreach ($month_data as $row) {
                $date_str = $row['exam_date'];
                $waku_name = $row['scheduled_time'];
                $waku_exists = false;
                foreach ($waku_list as $w) {
                    if ($w['waku_name'] === $waku_name) { $waku_exists = true; break; }
                }
                $key = $waku_exists ? $waku_name : 'MISMATCH';
                $month_matrix[$date_str][$key][] = $row;
            }
            renderMonthMatrix($pdo, $m['year'], $m['month'], $dept, $waku_list, $month_matrix, $week_ja, $show_canceled, $show_invalid, $show_unassigned, $simple_mode);
        }
        ?>
    </div>

    <!-- ============================================================
    モーダル（予約登録/編集）
    ============================================================ -->
    <dialog id="scheduleDialog">
        <form method="POST" action="action_schedule.php" id="dialogScheduleForm">
            <div class="modal-header"><h3 id="modalTitle">予約登録</h3></div>
            <div class="modal-body">
                <div class="modal-split-container">
                    <div class="modal-left-form">
                        <input type="hidden" name="schedule_id" id="f_id">
                        <input type="hidden" name="action_type" id="f_action" value="insert">

                        <!-- 基本情報エリア -->
                        <div class="basic-info-area">
                            <div class="area-label">
                                📌 基本情報
                                <span class="note">（変更は極力避けてください）</span>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>検査日</label>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <input type="date" name="exam_date" id="f_date" required 
                                               onchange="updateDayOfWeek(this.value)" 
                                               oninput="updateDayOfWeek(this.value)">
                                        <span id="dayOfWeekDisplay" style="font-size:12px; font-weight:bold; color:#1e40af; min-width:60px;"></span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>時間枠</label>
                                    <select name="scheduled_time" id="f_waku" required>
                                        <?php foreach($waku_list as $w): ?>
                                            <option value="<?=$w['waku_name']?>"><?=htmlspecialchars($w['waku_name'], ENT_QUOTES, 'UTF-8')?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label>診療科</label>
                                    <select name="department_code" id="f_dept" style="font-weight:bold;">
                                        <option value="PROCT">🟢 肛門科 (PROCT)</option>
                                        <option value="GASTRO">⚪ 胃腸科 (GASTRO)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 患者情報エリア -->
                        <div class="form-grid">
                            <!-- カルテID -->
                            <div class="form-group">
                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <label style="margin:0; font-weight:bold; font-size:11px; color:#444;">カルテID</label>
                                    <span id="patient_not_found_msg" class="error-msg">⚠️ 対象患者は電子カルテに存在しません</span>
                                </div>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:3px;">
                                    <input type="text" name="patient_id" id="f_pid" class="input-text" 
                                           style="width:120px; max-width:150px;" 
                                           placeholder="例: 74819"
                                           onkeydown="if(event.key==='Enter'){event.preventDefault(); handlePatientIdEnter(this.value);}">
                                </div>
                                <small style="color:#94a3b8; font-size:10px; display:block; margin-top:2px;">カルテIDを入力してEnterキーで照会</small>
                            </div>

                            <!-- 患者氏名 -->
                            <div class="form-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <label>患者氏名</label>
                                    <button type="button" class="btn-quick-keep" id="btn_quick_keep" onclick="applyTemporaryKeep()">⚡ 仮確保</button>
                                </div>
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" name="patient_name" id="f_pname" class="input-text" required 
                                           style="flex:1; background:#f8fafc;"
                                           placeholder="患者名（検索ボタンから選択）" readonly>
                                    <button type="button" onclick="openPatientSearch()" 
                                            style="padding:5px 14px; background:#3b82f6; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px; white-space:nowrap;">
                                        🔍 検索
                                    </button>
                                </div>
                                <small style="color:#94a3b8; font-size:10px; display:block; margin-top:2px;">検索ボタンを押して患者を選択します</small>
                            </div>

                            <!-- 患者ヨミガナ -->
                            <div class="form-group">
                                <label>患者ヨミガナ</label>
                                <input type="text" name="patient_kana" id="f_kana" class="input-text">
                            </div>

                            <!-- 生年月日 -->
                            <div class="form-group">
                                <label>生年月日</label>
                                <input type="date" name="birthday" id="f_birthday" class="input-text">
                            </div>

                            <!-- 担当医師 -->
                            <div class="form-group">
                                <label>担当医師</label>
                                <select name="doctor_name" id="f_doc" class="input-text"></select>
                            </div>

                            <!-- 検査種別 -->
                            <div class="form-group">
                                <label>検査種別</label>
                                <select name="exam_type" id="f_type" class="input-text">
                                    <?php foreach ($types as $t): ?><option value="<?=$t?>"><?=$t?></option><?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 来院時刻 -->
                            <div class="form-group">
                                <label>来院時刻</label>
                                <div style="display:flex; gap:4px; align-items:center;">
                                    <select id="f_arrival_select" class="input-text" style="flex:1;" onchange="toggleArrivalInput()">
                                        <option value="">---</option>
                                        <?php foreach ($arrivals as $a): ?>
                                            <option value="<?=htmlspecialchars($a, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($a, ENT_QUOTES, 'UTF-8')?></option>
                                        <?php endforeach; ?>
                                        <option value="__custom__">✏️ 直接入力</option>
                                    </select>
                                    <input type="text" name="arrival_time" id="f_arrival" 
                                           style="flex:1; min-width:80px; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:12px; display:none;" 
                                           placeholder="自由入力（例: 13:30）">
                                </div>
                            </div>

                            <!-- 実施場所 -->
                            <div class="form-group full-width">
                                <label>実施場所</label>
                                <select name="exam_location" id="f_loc" class="input-text">
                                    <?php foreach ($locations as $l): ?><option value="<?=$l?>"><?=$l?></option><?php endforeach; ?>
                                </select>
                            </div>

                            <!-- 備考 -->
                            <div class="form-group full-width">
                                <label>備考</label>
                                <textarea name="remarks" id="f_remarks" rows="2" class="input-text"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- MALL同期メモエリア -->
                    <div class="modal-right-memos">
                        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #bbb; margin-bottom:10px; padding-bottom:6px;">
                            <span style="font-weight:bold; color:#2c3e50; font-size:13px;">📋 MALL同期メモ</span>
                            <button type="button" id="btn_reload_memo" onclick="reloadMallMemo()" style="padding:3px 8px; font-size:11px; cursor:pointer; background:#2c3e50; color:#fff; border:none; border-radius:3px;">🔄 再読込</button>
                        </div>
                        <div id="memo_today_view" class="memo-view-block"></div>
                        <div id="memo_karte_view" class="memo-view-block"></div>
                        <div id="memo_patient_view" class="memo-view-block"></div>
                    </div>
                </div>

                <!-- ダイアログボタン -->
                <div class="dialog-buttons">
                    <button type="button" onclick="document.getElementById('scheduleDialog').close()" style="background:#6c757d; color:#fff;">閉じる</button>
                    <button type="button" onclick="saveAndPrint(event, true)" class="btn-print-slip" id="btnSavePrint">💾 保存して印刷</button>
                    
                    <button type="button" id="btnDelete" style="background:#dc3545; color:#fff; display:none;" onclick="deleteSchedule()">🗑️ 削除する</button>
                    <button type="submit" id="btnRestore" name="restore" value="1" style="background:#007bff; color:#fff; display:none;" onclick="return confirm('復活しますか？')">復活する</button>
                    <button type="button" id="btnSave" style="background:#28a745; color:#fff;" onclick="saveAndPrint(event, false)">保存する</button>
                </div>
            </div>
        </form>
    </dialog>

</div>

<script>
// ============================================================
// 初期設定
// ============================================================
const dialog = document.getElementById('scheduleDialog');
const matrixContainer = document.getElementById('matrixContainer');
const wakuGroupMap = <?= json_encode($waku_group_map) ?>;
const defaultDoctorMap = <?= json_encode($default_doctor_map) ?>;
const currentDept = '<?= $dept ?>';
const simpleMode = <?= $simple_mode ? 'true' : 'false' ?>;

// ============================================================
// 月表示トグル
// ============================================================
function toggleMonthView() {
    const url = new URL(window.location.href);
    const currentView = url.searchParams.get('view') || '1month';
    const newView = currentView === '1month' ? '3month' : '1month';
    url.searchParams.set('view', newView);
    window.location.href = url.toString();
}

// ============================================================
// 伏字・自動更新・スクロール
// ============================================================
function toggleMask(checked) {
    document.body.classList.toggle('mask-active', checked);
    localStorage.setItem('onokai_mask', checked ? '1' : '0');
}

function saveAutoScroll(checked) {
    localStorage.setItem('onokai_auto_scroll', checked ? '1' : '0');
}

function manualRefresh() {
    window.location.href = window.location.href;
}

function smoothScroll(dir) {
    const el = matrixContainer;
    if (!el) return;
    const x = dir === 'left' ? -300 : dir === 'right' ? 300 : 0;
    const y = dir === 'up' ? -150 : dir === 'down' ? 150 : 0;
    el.scrollBy({ left: x, top: y, behavior: 'smooth' });
}

function scrollToToday() {
    const today = document.getElementById('row-today');
    if (today && matrixContainer) {
        matrixContainer.scrollTop = today.offsetTop - 60;
    }
}

function toggleInvalid(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_invalid', checked ? '1' : '0');
    window.location.href = url.toString();
}

function toggleCanceled(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_canceled', checked ? '1' : '0');
    window.location.href = url.toString();
}

function toggleUnassigned(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_unassigned', checked ? '1' : '0');
    window.location.href = url.toString();
}

function toggleSlotGroup(group, checked) {
    document.querySelectorAll(`.slot-header[data-group="${group}"]`).forEach(el => {
        el.style.display = checked ? '' : 'none';
    });
    document.querySelectorAll(`td[data-group="${group}"]`).forEach(el => {
        el.style.display = checked ? '' : 'none';
    });
}

// ============================================================
// 初期ロード時処理（スクロール位置復元対応）
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const mask = localStorage.getItem('onokai_mask') || '0';
    const auto = localStorage.getItem('onokai_auto_scroll') || '0';
    document.getElementById('chk_mask').checked = mask === '1';
    document.getElementById('chk_auto').checked = auto === '1';
    if (mask === '1') document.body.classList.add('mask-active');

    const scrollTop = sessionStorage.getItem('scroll_top');
    const scrollLeft = sessionStorage.getItem('scroll_left');
    if (scrollTop !== null && matrixContainer) {
        matrixContainer.scrollTop = parseInt(scrollTop, 10);
        matrixContainer.scrollLeft = parseInt(scrollLeft, 10);
        sessionStorage.removeItem('scroll_top');
        sessionStorage.removeItem('scroll_left');
    } else if (auto === '1') {
        setTimeout(scrollToToday, 150);
    }
});

// 5分自動更新
setInterval(function() {
    if (!dialog.open) { 
        window.location.href = window.location.href; 
    }
}, 300000);

// ============================================================
// 日付曜日表示
// ============================================================
function updateDayOfWeek(dateValue) {
    const display = document.getElementById('dayOfWeekDisplay');
    if (!dateValue) {
        display.textContent = '';
        display.style.color = '';
        return;
    }
    
    const days = ['日', '月', '火', '水', '木', '金', '土'];
    const d = new Date(dateValue);
    if (isNaN(d.getTime())) {
        display.textContent = '';
        return;
    }
    
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const w = days[d.getDay()];
    
    if (d.getDay() === 0) {
        display.textContent = `${y}/${m}/${day} (${w}) 🏥 休診`;
        display.style.color = '#ef4444';
    } else if (d.getDay() === 6) {
        display.textContent = `${y}/${m}/${day} (${w})`;
        display.style.color = '#1e40af';
    } else {
        display.textContent = `${y}/${m}/${day} (${w})`;
        display.style.color = '#22c55e';
    }
}

// ============================================================
// カルテID処理（Enterのみ）
// ============================================================
let isProcessingPatient = false;

function isValidPatientId(value) {
    if (!value || value.trim() === '') return false;
    return /^\d+$/.test(value.trim());
}

function handlePatientIdEnter(patientNo) {
    if (isProcessingPatient) return;
    
    if (!isValidPatientId(patientNo)) {
        clearPatientFields();
        return;
    }
    fetchPatientFromMALL(patientNo);
}

// ============================================================
// MALL連携（患者情報取得）
// ============================================================
function fetchPatientFromMALL(patientNo) {
    isProcessingPatient = true;
    
    if (!isValidPatientId(patientNo)) {
        clearPatientFields();
        isProcessingPatient = false;
        return;
    }
    
    const nameInput = document.getElementById('f_pname');
    const birthdayInput = document.getElementById('f_birthday');
    const kanaInput = document.getElementById('f_kana');
    const msgElement = document.getElementById('patient_not_found_msg');
    
    // メッセージを非表示に
    if (msgElement) {
        msgElement.textContent = '⚠️ 対象患者は電子カルテに存在しません';
        msgElement.style.display = 'none';
    }
    
    nameInput.placeholder = "電子カルテ照会中...";
    nameInput.disabled = true;
    
    fetch(`get_mall_patient.php?patient_no=${patientNo}`)
        .then(res => res.json())
        .then(data => {
            nameInput.disabled = false;
            isProcessingPatient = false;
            
            if (data.success) {
                nameInput.value = data.patient_name || '';
                kanaInput.value = data.patient_kana || '';
                birthdayInput.value = (data.birthday && data.birthday.trim().length >= 10) 
                    ? data.birthday.trim().substring(0, 10) 
                    : '';
                nameInput.placeholder = "";
                autoLoadMallMemo(patientNo);
                if (msgElement) msgElement.style.display = 'none';
            } else {
                clearPatientFields();
                if (msgElement) {
                    msgElement.textContent = '⚠️ 対象患者は電子カルテに存在しません';
                    msgElement.style.display = 'inline';
                }
                nameInput.placeholder = "（未登録患者）";
            }
        })
        .catch(err => {
            nameInput.disabled = false;
            isProcessingPatient = false;
            nameInput.placeholder = "連携エラー";
            if (msgElement) {
                msgElement.textContent = '⚠️ 電子カルテ連携エラー';
                msgElement.style.display = 'inline';
            }
        });
}

function clearPatientFields() {
    document.getElementById('f_pname').value = '';
    document.getElementById('f_kana').value = '';
    document.getElementById('f_birthday').value = '';
    document.getElementById('f_pname').placeholder = '';
    
    const msgElement = document.getElementById('patient_not_found_msg');
    if (msgElement) msgElement.style.display = 'none';
    
    document.getElementById('memo_today_view').innerHTML = '<span style="color:#aaa;font-style:italic;">====== 本日メモ ====== なし</span>';
    document.getElementById('memo_karte_view').innerHTML = '<span style="color:#aaa;font-style:italic;">====== カルテメモ ====== なし</span>';
    document.getElementById('memo_patient_view').innerHTML = '<span style="color:#aaa;font-style:italic;">====== 患者メモ ====== なし</span>';
}

// ============================================================
// MALL連携（メモ取得）
// ============================================================
function autoLoadMallMemo(pid) {
    const btn = document.getElementById('btn_reload_memo');
    if (btn) { btn.innerText = '同期中...'; btn.disabled = true; }
    fetch(`sync_mall_memo.php?patient_id=${pid}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                function setMemoContent(id, title, content) {
                    document.getElementById(id).innerHTML = content ? 
                        `<span style="color:#d9383a;font-weight:bold;">====== ${title} ======</span><div>${escapeHTML(content).replace(/\n/g,'<br>')}</div>` :
                        `<span style="color:#aaa;font-style:italic;">====== ${title} ====== なし</span>`;
                }
                setMemoContent('memo_today_view', '本日メモ', data.memo_today);
                setMemoContent('memo_karte_view', 'カルテメモ', data.memo_karte);
                setMemoContent('memo_patient_view', '患者メモ', data.memo_patient);
            }
        })
        .finally(() => {
            if (btn) { btn.innerText = '🔄 再読込'; btn.disabled = false; }
        });
}

function reloadMallMemo() {
    const pid = document.getElementById('f_pid').value;
    if (!pid || isNaN(pid)) { alert('カルテIDが正しくありません。'); return; }
    autoLoadMallMemo(pid);
}

// ============================================================
// 来院時刻 プルダウン＋直接入力 切替
// ============================================================
function toggleArrivalInput() {
    const select = document.getElementById('f_arrival_select');
    const input = document.getElementById('f_arrival');
    
    if (select.value === '__custom__') {
        input.style.display = 'block';
        input.focus();
        input.value = '';
    } else {
        input.style.display = 'none';
        input.value = select.value;
    }
}

function setArrivalTime(value) {
    const select = document.getElementById('f_arrival_select');
    const input = document.getElementById('f_arrival');
    
    if (!value || value === '') {
        select.value = '';
        input.style.display = 'none';
        input.value = '';
        return;
    }
    
    let found = false;
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value === value) {
            select.value = value;
            found = true;
            break;
        }
    }
    
    if (found) {
        input.style.display = 'none';
        input.value = value;
    } else {
        select.value = '__custom__';
        input.style.display = 'block';
        input.value = value;
    }
}

// ============================================================
// 患者検索画面を開く（別タブ）
// ============================================================
function openPatientSearch() {
    const url = 'patient_search.php';
    window.open(url, '_blank', 'width=1000,height=700,scrollbars=yes,resizable=yes');
}

// ============================================================
// 検索画面から選択された時のコールバック
// ============================================================
function selectPatientFromSearch(patientNo, patientName, birthday, kana) {
    document.getElementById('f_pid').value = patientNo;
    document.getElementById('f_pname').value = patientName;
    document.getElementById('f_kana').value = kana || '';
    document.getElementById('f_birthday').value = birthday || '';
    
    const msgElement = document.getElementById('patient_not_found_msg');
    if (msgElement) {
        msgElement.textContent = '⚠️ 対象患者は電子カルテに存在しません';
        msgElement.style.display = 'none';
    }
    
    if (patientNo && !isNaN(patientNo)) {
        fetchPatientFromMALL(patientNo);
    }
}

// ============================================================
// セル更新関数
// ============================================================
function buildPatientBox(row, date_str, simple_mode) {
    let age = '';
    if (row.birthday && row.exam_date) {
        const birth = new Date(row.birthday);
        const exam = new Date(row.exam_date);
        if (!isNaN(birth) && !isNaN(exam) && birth <= exam) {
            age = exam.getFullYear() - birth.getFullYear();
            const m = exam.getMonth() - birth.getMonth();
            if (m < 0 || (m === 0 && exam.getDate() < birth.getDate())) {
                age--;
            }
        }
    }
    
    let birthShort = '';
    if (row.birthday) {
        const d = new Date(row.birthday);
        if (!isNaN(d)) {
            const y = d.getFullYear();
            const m = d.getMonth() + 1;
            const day = d.getDate();
            let era = '';
            if (y >= 2019) { era = 'R' + (y - 2018); }
            else if (y >= 1989) { era = 'H' + (y - 1988); }
            else if (y >= 1926) { era = 'S' + (y - 1925); }
            else if (y >= 1912) { era = 'T' + (y - 1911); }
            else { era = 'M' + (y - 1867); }
            birthShort = era + '.' + m + '.' + day;
        }
    }
    
    let doctorIcon = '？';
    const docName = row.doctor_name || '';
    if (docName) {
        const map = {'誠吾':'誠','哲也':'哲','義智':'義','誠':'誠','哲':'哲','義':'義'};
        let found = false;
        for (const [key, val] of Object.entries(map)) {
            if (docName.indexOf(key) !== -1) { doctorIcon = val; found = true; break; }
        }
        if (!found) { doctorIcon = docName.charAt(0); }
    }
    
    const examType = row.exam_type || '';
    
    let customBg = '';
    const colorMap = {
        'GIF': '#e0ffff',
        'ポリペク': '#dda0dd',
        'TCF': '#e2f0d9',
        'W': '#c5e0b4'
    };
    if (examType && colorMap[examType]) {
        customBg = colorMap[examType];
    }
    
    const isCanceled = row.is_canceled === true || row.is_canceled === 1 || row.is_canceled === 't';
    const isTemporary = row.patient_name && row.patient_name.indexOf('※仮予約') !== -1;
    if (isCanceled || isTemporary) {
        customBg = '';
    }
    
    let ageStyle = 'background-color:#e0f2fe; color:#0369a1;';
    if (age >= 65 && age <= 74) { ageStyle = 'background-color:#fef08a; color:#713f12; font-weight:bold;'; }
    else if (age >= 75 && age <= 85) { ageStyle = 'background-color:#fca5a5; color:#991b1b; font-weight:bold;'; }
    else if (age > 85) { ageStyle = 'background-color:#ef4444; color:#ffffff; font-weight:bold;'; }
    
    let examStyle = 'background-color:#e2e8f0; color:#475569;';
    if (examType === 'GIF') { examStyle = 'background-color:#0ea5e9; color:#fff; font-weight:bold;'; }
    else if (examType === 'TCF') { examStyle = 'background-color:#10b981; color:#fff; font-weight:bold;'; }
    else if (examType === 'エコー' || examType === 'ECHO') { examStyle = 'background-color:#8b5cf6; color:#fff; font-weight:bold;'; }
    else if (examType === 'ポリペク') { examStyle = 'background-color:#8b5cf6; color:#fff; font-weight:bold;'; }
    else if (examType === 'W') { examStyle = 'background-color:#22c55e; color:#fff; font-weight:bold;'; }
    
    const box = document.createElement('div');
    box.className = 'patient-box';
    
    if (customBg) {
        box.style.setProperty('background-color', customBg, 'important');
    } else {
        box.style.setProperty('background-color', '#ffffff', 'important');
    }
    
    if (simple_mode) {
        box.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:800; color:#1e293b; font-size:9px;">ID:${row.patient_id || ''}</span>
                <span style="font-weight:bold; font-size:10px; cursor:pointer; flex:1; margin:0 6px;" onclick="openEditModalWithCtrlCheck(this, event)">
                    <span class="real-pname">${escapeHTML(row.patient_name || '')}</span>
                    <span class="masked-pname" style="display:none; color:#777; font-style:italic;">●●●●</span>
                </span>
                <span class="exam-badge" style="${examStyle} padding:1px 6px; border-radius:12px; font-size:10px;">${examType}</span>
            </div>
        `;
    } else {
        box.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dotted rgba(0,0,0,0.15); padding-bottom:2px; margin-bottom:3px;">
                <span style="font-weight:800; color:#1e293b; font-size:9px;">ID：${row.patient_id || ''}</span>
                <span style="display:inline-block; padding:1px 5px; font-size:10px; border-radius:4px; ${ageStyle}">${age}</span>
            </div>
            <div style="font-weight:bold; font-size:10px; cursor:pointer;" onclick="openEditModalWithCtrlCheck(this, event)">
                <span class="real-pname">${escapeHTML(row.patient_name || '')}</span>
                <span class="masked-pname" style="display:none; color:#777; font-style:italic;">●●●●</span>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:3px; padding-top:2px;">
                <span style="font-size:9px; color:#64748b;">${birthShort}</span>
                <span>
                    ${doctorIcon ? `<span style="display:inline-block; background:#1a1a2e; color:#ffffff; font-weight:bold; font-size:8px; padding:0px 3px; border-radius:10px; min-width:16px; text-align:center; line-height:1.4; margin-right:3px;">${doctorIcon}</span>` : ''}
                    <span class="exam-badge" style="${examStyle} padding:1px 6px; border-radius:12px; font-size:10px;">${examType}</span>
                </span>
            </div>
        `;
    }
    
    box.dataset.scheduleId = row.schedule_id || '';
    box.dataset.json = JSON.stringify(row);
    box.draggable = true;
    box.ondragstart = function(e) { dragStart(this, e); };
    box.onclick = function(e) { openEditModalWithCtrlCheck(this, e); };
    
    return box;
}

function refreshCell(date, waku) {
    const encodedDate = encodeURIComponent(date);
    const encodedWaku = encodeURIComponent(waku);
    const encodedDept = encodeURIComponent(currentDept);
    
    const targetCell = document.querySelector(`td[data-date="${date}"][data-waku="${waku}"]`);
    const fetchUrl = `get_cell_data.php?date=${encodedDate}&waku=${encodedWaku}&dept=${encodedDept}`;
    
    fetch(fetchUrl)
        .then(res => res.json())
        .then(data => {
            const cell = targetCell || document.querySelector(`td[data-date="${date}"][data-waku="${waku}"]`);
            if (!cell) { return; }
            
            cell.querySelectorAll('.patient-box').forEach(el => el.remove());
            
            if (data.length > 0) {
                data.forEach((row) => {
                    const box = buildPatientBox(row, date, simpleMode);
                    cell.appendChild(box);
                });
            }
            
            setTimeout(function() {
                const cellToScroll = document.querySelector(`td[data-date="${date}"][data-waku="${waku}"]`);
                if (cellToScroll && matrixContainer) {
                    const rect = cellToScroll.getBoundingClientRect();
                    const containerRect = matrixContainer.getBoundingClientRect();
                    const targetScrollTop = matrixContainer.scrollTop + (rect.top - containerRect.top) - (containerRect.height / 2) + (rect.height / 2);
                    matrixContainer.scrollTop = targetScrollTop;
                }
            }, 200);
        })
        .catch(err => {
            console.error('セル更新エラー:', err);
        });
}

// ============================================================
// 保存して印刷
// ============================================================
function saveAndPrint(event, openPrint = true) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const form = document.getElementById('dialogScheduleForm');
    const scheduleId = document.getElementById('f_id').value;
    
    // ★★★ 変更前/変更後の日付と枠を取得 ★★★
    const oldDate = document.getElementById('f_old_date')?.value || '';
    const oldWaku = document.getElementById('f_old_waku')?.value || '';
    const newDate = document.getElementById('f_date').value;
    const newWaku = document.getElementById('f_waku').value;
    const actionType = document.getElementById('f_action').value;
    
    const btn = document.getElementById('btnSavePrint');
    const saveBtn = document.getElementById('btnSave');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ 保存中...'; }
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '⏳ 保存中...'; }
    
    const fd = new FormData(form);
    if (!fd.get('action_type') || fd.get('action_type') === '') {
        fd.set('action_type', 'insert');
    }
    
    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (btn) { btn.disabled = false; btn.textContent = '💾 保存して印刷'; }
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '保存する'; }
            
            if (data.success) {
                const newId = data.schedule_id || scheduleId;
                if (newId && newId !== '0' && openPrint) {
                    window.open(`print_patient_slip.php?id=${newId}`, '_blank', 'width=800,height=900');
                }
                if (newId && newId !== '0') {
                    document.getElementById('f_id').value = newId;
                    document.getElementById('f_action').value = 'update';
                }
                
                dialog.close();
                
                // ★★★ 修正: 両方のセルを更新（ゴーストタイル解消） ★★★
                // 1. 変更前のセルを更新（移動があった場合のみ）
                //    → update/move かつ 日付または枠が変わった場合
                const isUpdate = (actionType === 'update' || actionType === 'move');
                const hasMoved = (oldDate !== newDate || oldWaku !== newWaku);
                
                if (isUpdate && hasMoved && oldDate && oldWaku) {
                    refreshCell(oldDate, oldWaku);
                }
                
                // 2. 変更後のセルを更新（新規登録 or 移動先）
                if (newDate && newWaku) {
                    refreshCell(newDate, newWaku);
                }
                // ★★★ 修正ここまで ★★★
                
            } else {
                alert('保存に失敗しました: ' + (data.message || '不明なエラー'));
            }
        })
        .catch(err => {
            if (btn) { btn.disabled = false; btn.textContent = '💾 保存して印刷'; }
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '保存する'; }
            alert('保存処理中にエラーが発生しました: ' + err.message);
        });
}

// ============================================================
// その他ユーティリティ
// ============================================================
function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[t] || t);
}

// ============================================================
// モーダル関連
// ============================================================
function applyTemporaryKeep() {
    document.getElementById('f_pname').value = '※仮予約（キープ）';
    document.getElementById('f_pid').value = '';
    document.getElementById('f_birthday').value = '';
    document.getElementById('f_kana').value = '';
}

function openInsertModal(cell, event) {
    if (event && event.target !== cell) return;
    const date = cell.dataset.date;
    const waku = cell.dataset.waku;
    const group = cell.dataset.group || 'TCF';
    const dept = '<?= $dept ?>';
    
    document.getElementById('f_action').value = 'insert';
    document.getElementById('modalTitle').innerText = '新規予約登録';
    document.getElementById('f_id').value = '';
    document.getElementById('f_date').value = date;
    document.getElementById('f_waku').value = waku;
    document.getElementById('f_dept').value = dept;
    
    updateDayOfWeek(date);
    
    const defaultExam = group === 'ECHO' ? 'エコー' : group;
    document.getElementById('f_type').value = defaultExam;
    
    document.getElementById('f_pid').value = '';
    document.getElementById('f_pname').value = '';
    document.getElementById('f_kana').value = '';
    document.getElementById('f_birthday').value = '';
    document.getElementById('f_remarks').value = '';
    
    // エラーメッセージを非表示
    const msgElement = document.getElementById('patient_not_found_msg');
    if (msgElement) msgElement.style.display = 'none';
    
    document.getElementById('memo_today_view').innerHTML = '<span style="color:#aaa;font-style:italic;">====== 本日メモ ====== なし</span>';
    document.getElementById('memo_karte_view').innerHTML = '<span style="color:#aaa;font-style:italic;">====== カルテメモ ====== なし</span>';
    document.getElementById('memo_patient_view').innerHTML = '<span style="color:#aaa;font-style:italic;">====== 患者メモ ====== なし</span>';
    
    // ★★★ 新規登録時は old_* をクリア ★★★
    const oldDateInput = document.getElementById('f_old_date');
    const oldWakuInput = document.getElementById('f_old_waku');
    if (oldDateInput) oldDateInput.value = '';
    if (oldWakuInput) oldWakuInput.value = '';
    
    updateDoctorDropdown(date, dept, group);
    setArrivalTime('');
    
    document.getElementById('btnDelete').style.display = 'none';
    document.getElementById('btnRestore').style.display = 'none';
    document.getElementById('btnSave').style.display = 'block';
    document.getElementById('btnSavePrint').style.display = 'block';
    document.getElementById('btnSavePrint').disabled = false;
    document.getElementById('btnSavePrint').textContent = '💾 保存して印刷';
    document.getElementById('btn_quick_keep').style.display = 'block';
    
    dialog.showModal();
}

function updateDoctorDropdown(date, dept, group) {
    const select = document.getElementById('f_doc');
    fetch(`get_present_doctors.php?date=${date}&dept=${dept}`)
        .then(res => res.json())
        .then(data => {
            let doctors = data.doctors || ['◆未定◆'];
            const defaultDoc = defaultDoctorMap[dept]?.[group] || doctors[0] || '◆未定◆';
            select.innerHTML = doctors.map(d => 
                `<option value="${d}" ${d === defaultDoc ? 'selected' : ''}>${d}</option>`
            ).join('');
        })
        .catch(() => {
            select.innerHTML = '<option value="◆未定◆">◆未定◆</option>';
        });
}

function openEditModalWithCtrlCheck(el, event) {
    event.stopPropagation();
    const box = el.closest('.patient-box');
    if (!box) return;
    const data = JSON.parse(box.dataset.json);
    if (event.ctrlKey && data.patient_id) {
        window.open(`http://192.168.1.164/~rsn/2000.cgi?show=${encodeURIComponent(data.patient_id)}`, '_blank');
        return;
    }
    openEditModal(box, data);
}

function openEditModal(box, data) {
    const isCanceled = data.is_canceled === true || data.is_canceled === 1 || data.is_canceled === 't';
    
    document.getElementById('f_action').value = isCanceled ? 'restore' : 'update';
    document.getElementById('modalTitle').innerText = isCanceled ? '⚠️ 削除済み予約の確認・復活' : '予約データの変更・削除';
    document.getElementById('f_id').value = data.schedule_id;
    document.getElementById('f_date').value = data.exam_date;
    document.getElementById('f_waku').value = data.scheduled_time;
    document.getElementById('f_dept').value = data.department_code || '<?= $dept ?>';
    document.getElementById('f_pid').value = data.patient_id || '';
    document.getElementById('f_pname').value = data.patient_name || '';
    document.getElementById('f_kana').value = data.patient_kana || '';
    document.getElementById('f_birthday').value = data.birthday ? data.birthday.substring(0,10) : '';
    document.getElementById('f_doc').value = data.doctor_name || '';
    document.getElementById('f_type').value = data.exam_type || '';
    document.getElementById('f_arrival').value = data.arrival_time || '';
    document.getElementById('f_loc').value = data.exam_location || '';
    document.getElementById('f_remarks').value = data.remarks || '';
    
    updateDayOfWeek(data.exam_date);
    
    const group = data.exam_type === 'エコー' ? 'ECHO' : (data.exam_type === 'GIF' ? 'GIF' : 'TCF');
    updateDoctorDropdown(data.exam_date, data.department_code || '<?= $dept ?>', group);
    setArrivalTime(data.arrival_time || '');
    
    function setMemo(id, title, content) {
        document.getElementById(id).innerHTML = content ? 
            `<span style="color:#d9383a;font-weight:bold;">====== ${title} ======</span><div>${escapeHTML(content).replace(/\n/g,'<br>')}</div>` :
            `<span style="color:#aaa;font-style:italic;">====== ${title} ====== なし</span>`;
    }
    setMemo('memo_today_view', '本日メモ', data.memo_today);
    setMemo('memo_karte_view', 'カルテメモ', data.memo_karte);
    setMemo('memo_patient_view', '患者メモ', data.memo_patient);
    
    // ★★★ 変更前の日付と枠を保持（hiddenフィールドに保存）★★★
    // 既存の hidden フィールドがあれば一旦削除（重複防止）
    const oldDateInput = document.getElementById('f_old_date');
    const oldWakuInput = document.getElementById('f_old_waku');
    if (oldDateInput) oldDateInput.remove();
    if (oldWakuInput) oldWakuInput.remove();
    
    // 新しく hidden フィールドを作成して form に追加
    const hiddenOldDate = document.createElement('input');
    hiddenOldDate.type = 'hidden';
    hiddenOldDate.id = 'f_old_date';
    hiddenOldDate.name = 'old_exam_date';
    hiddenOldDate.value = data.exam_date || '';
    document.getElementById('dialogScheduleForm').appendChild(hiddenOldDate);
    
    const hiddenOldWaku = document.createElement('input');
    hiddenOldWaku.type = 'hidden';
    hiddenOldWaku.id = 'f_old_waku';
    hiddenOldWaku.name = 'old_scheduled_time';
    hiddenOldWaku.value = data.scheduled_time || '';
    document.getElementById('dialogScheduleForm').appendChild(hiddenOldWaku);
    
    document.getElementById('btn_quick_keep').style.display = 'none';
    
    document.getElementById('btnSavePrint').style.display = 'block';
    document.getElementById('btnSavePrint').disabled = false;
    document.getElementById('btnSavePrint').textContent = '💾 保存して印刷';
    
    if (isCanceled) {
        document.getElementById('btnDelete').style.display = 'none';
        document.getElementById('btnSave').style.display = 'none';
        document.getElementById('btnRestore').style.display = 'block';
        document.querySelectorAll('#dialogScheduleForm .form-group input, #dialogScheduleForm .form-group select').forEach(el => el.disabled = true);
    } else {
        document.getElementById('btnDelete').style.display = 'block';
        document.getElementById('btnSave').style.display = 'block';
        document.getElementById('btnRestore').style.display = 'none';
        document.querySelectorAll('#dialogScheduleForm .form-group input, #dialogScheduleForm .form-group select').forEach(el => el.disabled = false);
    }
    
    dialog.showModal();
}

// ============================================================
// Enterキー無効化（削除ボタン誤爆防止）
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && dialog.open) {
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'SELECT' || active.tagName === 'TEXTAREA')) {
            e.preventDefault();
            return false;
        }
    }
});

// ============================================================
// ドラッグ＆ドロップ
// ============================================================
let draggedBox = null;

function dragStart(box, e) {
    draggedBox = box;
    e.dataTransfer.setData('text/plain', String(box.dataset.scheduleId));
}

function allowDrop(e) { e.preventDefault(); }
function dragEnter(cell) { cell.classList.add('dragover'); }
function dragLeave(cell) { cell.classList.remove('dragover'); }

function dropWaku(cell, e) {
    e.preventDefault();
    cell.classList.remove('dragover');
    const sid = e.dataTransfer.getData('text/plain');
    if (!sid || sid === 'undefined' || !draggedBox) return;
    
    const date = cell.dataset.date;
    const waku = cell.dataset.waku;
    
    const srcBox = draggedBox;
    const srcCell = srcBox.closest('.waku-cell');
    const srcDate = srcCell ? srcCell.dataset.date : null;
    const srcWaku = srcCell ? srcCell.dataset.waku : null;
    
    const fd = new FormData();
    fd.append('action_type', 'move');
    fd.append('schedule_id', sid);
    fd.append('exam_date', date);
    fd.append('scheduled_time', waku);
    
    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                cell.appendChild(draggedBox);
                const boxData = JSON.parse(draggedBox.dataset.json);
                boxData.exam_date = date;
                boxData.scheduled_time = waku;
                draggedBox.dataset.json = JSON.stringify(boxData);
                
                if (srcDate && srcWaku) {
                    refreshCell(srcDate, srcWaku);
                }
                if (date && waku) {
                    refreshCell(date, waku);
                }
            } else {
                alert('移動に失敗しました: ' + (data.message || '不明なエラー'));
                window.location.reload();
            }
        })
        .catch(() => {
            alert('移動処理中にエラーが発生しました');
            window.location.reload();
        })
        .finally(() => { draggedBox = null; });
}

// ============================================================
// 削除処理（非同期）
// ============================================================
function deleteSchedule() {
    const scheduleId = document.getElementById('f_id').value;
    const date = document.getElementById('f_date').value;
    const waku = document.getElementById('f_waku').value;
    
    if (!scheduleId || scheduleId === '0') {
        alert('削除対象の予約が特定できません');
        return;
    }
    
    if (!confirm('この予約を本当に削除しますか？？？')) {
        return;
    }
    
    // ボタンを無効化
    const btn = document.getElementById('btnDelete');
    btn.disabled = true;
    btn.textContent = '⏳ 削除中...';
    
    const fd = new FormData();
    fd.append('action_type', 'delete');
    fd.append('schedule_id', scheduleId);
    
    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = '🗑️ 削除する';
            
            if (data.success) {
                // 削除成功
                dialog.close();
                if (date && waku) {
                    refreshCell(date, waku);
                }
                // 同じ日付の全セルを更新（念のため）
                document.querySelectorAll(`td[data-date="${date}"]`).forEach(cell => {
                    const w = cell.dataset.waku;
                    if (w) {
                        refreshCell(date, w);
                    }
                });
            } else {
                alert('削除に失敗しました: ' + (data.message || '不明なエラー'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = '🗑️ 削除する';
            alert('通信エラーが発生しました: ' + err.message);
        });
}
</script>

</body>
</html>