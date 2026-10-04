<?php
/**
 * ファイル名: edit.php
 * 設置パス: /endoscope/followup/edit.php
 * 生成日時: 2026-08-25 21:00:00 JST
 * 
 * フォローアップ登録・編集画面
 * 
 * 機能:
 *   - フォローアップデータの新規登録・編集
 *   - 患者検索連携（patient_search.php）
 *   - 今回の検査（実施済み）の複数選択
 *   - 次回F/U検査の行追加・削除（複数登録可能）
 *   - F/U期間（プルダウン＋カスタム入力）
 *   - F/U理由（特記事項）
 *   - ステータス管理（予定/完了/キャンセル/延期）
 *   - 過去のF/U履歴表示（患者ID指定で自動読み込み）
 *   - 次回来院日の柔軟な入力（確定日/〜以降/〜頃/第○週/最終/未定）
 *   - month_schedule.php からのGET引継ぎ対応（患者情報・検査種別）
 *   - 次回来院日 簡易入力ボタン（1週間後/2週間後/1か月後/2か月後/3か月後/1年後/2年後/3年後）
 * 
 * ボタン動作:
 *   - 「保存」: 保存して一覧（index.php）に戻る
 *   - 「保存して印刷」: 保存して印刷プレビューを開く
 *   - 「キャンセル」: 保存せず一覧に戻る（確認ダイアログあり）
 *   - 「削除」: 確認後削除して一覧に戻る（編集時のみ）
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成
 *   - 2026-08-17: F/U項目の行追加・削除機能を実装
 *   - 2026-08-17: カスタム期間入力に対応
 *   - 2026-08-17: 過去のF/U履歴表示機能を実装
 *   - 2026-08-17: ステータス管理を実装
 *   - 2026-08-18: ボタン動作を整理（保存→一覧戻り／キャンセル確認ダイアログ）
 *   - 2026-08-18: Enterキー誤爆防止を実装
 *   - 2026-08-25: month_schedule.php からのGET引継ぎ対応
 *   - 2026-08-25: 新規登録時にF/U項目のデフォルト行を表示しないよう修正
 *   - 2026-08-25: 次回来院日 簡易入力ボタンを追加（1週間後/2週間後/1か月後/2か月後/3か月後/1年後/2年後/3年後）
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ============================================================
// ★★★ パラメータ取得 ★★★
// ============================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

// メッセージ
$msg = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$error = isset($_GET['error']) ? (int)$_GET['error'] : 0;

// ★★★ month_schedule.php からのGET引継ぎパラメータ ★★★
$get_patient_id = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
$get_patient_name = isset($_GET['patient_name']) ? trim($_GET['patient_name']) : '';
$get_patient_kana = isset($_GET['patient_kana']) ? trim($_GET['patient_kana']) : '';
$get_birthday = isset($_GET['birthday']) ? trim($_GET['birthday']) : '';
$get_department_code = isset($_GET['department_code']) ? trim($_GET['department_code']) : '';
$get_doctor_name = isset($_GET['doctor_name']) ? trim($_GET['doctor_name']) : '';
$get_exam_types = isset($_GET['exam_types']) ? trim($_GET['exam_types']) : '';

// ★★★ exam_types を配列に変換（カンマ区切り） ★★★
$get_exam_types_array = [];
if (!empty($get_exam_types)) {
    $get_exam_types_array = array_map('trim', explode(',', $get_exam_types));
}

// ============================================================
// データ取得（優先順位：DB > GETパラメータ > デフォルト）
// ============================================================
$data = [
    'followup_id' => 0,
    'patient_id' => '',
    'patient_name' => '',
    'patient_kana' => '',
    'birthday' => '',
    'department_code' => 'GASTRO',
    'doctor_name' => '',
    'exam_types' => '[]',
    'exam_types_display' => '',
    'next_visit_date' => '',
    'next_visit_display' => '',
    'next_visit_date_type' => 'exact',
    'next_visit_pattern' => 'none',
    'next_visit_week_num' => 1,
    'next_visit_day_of_week' => 6,
    'visit_purpose' => 'next_exam',
    'visit_method' => 'reserved',
    'visit_time' => '',
    'notes' => '',
    'issued_date' => date('Y-m-d'),
    'is_printed' => false
];

$items = [];  // F/U項目（t_followup_items）
$history = []; // 過去のF/U履歴（同一患者）

if ($is_edit) {
    // ============================================================
    // 【優先順位1】編集時：DBからデータ取得
    // ============================================================
    $stmt = $pdo->prepare("SELECT * FROM t_followup WHERE followup_id = :id");
    $stmt->execute([':id' => $id]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $data = $fetched;
    } else {
        $is_edit = false;
    }
    
    // F/U項目取得
    $stmt = $pdo->prepare("
        SELECT * FROM t_followup_items 
        WHERE followup_id = :id 
        ORDER BY display_order, created_at
    ");
    $stmt->execute([':id' => $id]);
    $items = $stmt->fetchAll();
    
    // 履歴取得（同一患者の全履歴）
    if (!empty($data['patient_id'])) {
        $stmt = $pdo->prepare("
            SELECT 
                f.followup_id,
                f.exam_types_display,
                f.next_visit_date,
                f.created_at as registered_date,
                fi.item_id,
                fi.exam_type,
                fi.fu_interval,
                fi.fu_reason,
                fi.status,
                fi.completed_date,
                fi.created_at as item_created_at
            FROM t_followup f
            LEFT JOIN t_followup_items fi ON f.followup_id = fi.followup_id
            WHERE f.patient_id = :patient_id
              AND f.followup_id != :current_id
            ORDER BY fi.created_at DESC
        ");
        $stmt->execute([
            ':patient_id' => $data['patient_id'],
            ':current_id' => $id
        ]);
        $history = $stmt->fetchAll();
    }
    
} elseif (!empty($get_patient_id)) {
    // ============================================================
    // 【優先順位2】新規登録 + GETパラメータあり（month_schedule.php から遷移）
    // ============================================================
    $data['patient_id'] = $get_patient_id;
    $data['patient_name'] = $get_patient_name;
    $data['patient_kana'] = $get_patient_kana;
    $data['birthday'] = $get_birthday;
    $data['department_code'] = !empty($get_department_code) ? $get_department_code : 'GASTRO';
    $data['doctor_name'] = $get_doctor_name;
    
    // ★★★ 今回の検査を設定（GETパラメータがあれば） ★★★
    if (!empty($get_exam_types_array)) {
        $data['exam_types'] = json_encode($get_exam_types_array);
        $data['exam_types_display'] = implode(' + ', $get_exam_types_array);
        if (in_array('GIF', $get_exam_types_array) && in_array('TCF', $get_exam_types_array)) {
            $data['exam_types_display'] .= '（ダブル）';
        }
    }
    
    // 履歴取得（同一患者の全履歴）
    if (!empty($get_patient_id)) {
        $stmt = $pdo->prepare("
            SELECT 
                f.followup_id,
                f.exam_types_display,
                f.next_visit_date,
                f.created_at as registered_date,
                fi.item_id,
                fi.exam_type,
                fi.fu_interval,
                fi.fu_reason,
                fi.status,
                fi.completed_date,
                fi.created_at as item_created_at
            FROM t_followup f
            LEFT JOIN t_followup_items fi ON f.followup_id = fi.followup_id
            WHERE f.patient_id = :patient_id
            ORDER BY fi.created_at DESC
        ");
        $stmt->execute([':patient_id' => $get_patient_id]);
        $history = $stmt->fetchAll();
    }
}

// ============================================================
// ★★★ 今回の検査（チェックボックス用）★★★
// ============================================================
$exam_types = json_decode($data['exam_types'] ?? '[]', true);

// 選択肢
$purpose_labels = [
    'followup' => '経過観察',
    'tissue_result' => '組織検査結果説明',
    'next_exam' => '次回検査',
    'medication' => '薬の調整',
    'other' => 'その他'
];

$method_labels = [
    'reserved' => '予約済み',
    'phone_required' => '事前電話要',
    'contact_when_ready' => '都合連絡',
    'walk_in' => '直接来院',
    'adjustment_needed' => '要調整'
];

$status_labels = [
    'planned' => '📋 予定',
    'completed' => '✅ 完了',
    'cancelled' => '❌ 中止',
    'postponed' => '⏳ 延期'
];

$interval_options = [
    '3ヶ月' => '3ヶ月後',
    '6ヶ月' => '6ヶ月後',
    '1年' => '1年後',
    '2年' => '2年後',
    '3年' => '3年後',
    '5年' => '5年後',
    'カスタム' => '✏️ カスタム（自由入力）'
];

$exam_type_options = [
    'GIF' => 'GIF（上部）',
    'TCF' => 'TCF（下部）',
    'エコー' => 'エコー',
    'ポリペク' => 'ポリペク',
    'その他' => 'その他'
];

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_edit ? 'フォローアップ編集' : 'フォローアップ新規登録' ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            background: #f1f4f8;
            padding: 30px 20px;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            padding: 32px 36px;
            border: 1px solid #e9edf4;
        }

        /* ヘッダー */
        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #dce2ec;
        }
        .form-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
        }
        .form-header .sub {
            font-size: 13px;
            color: #64748b;
            font-weight: 400;
            margin-left: 8px;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .header-actions a {
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-outline {
            background: transparent;
            color: #475569;
            border: 1px solid #d0d8e3;
        }
        .btn-outline:hover { background: #f1f4f8; border-color: #b0bccd; }
        .btn-primary {
            background: #2563eb;
            color: #fff;
            border: 1px solid #2563eb;
        }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-success {
            background: #16a34a;
            color: #fff;
            border: 1px solid #16a34a;
        }
        .btn-success:hover { background: #15803d; border-color: #15803d; }
        .btn-danger {
            background: #dc2626;
            color: #fff;
            border: 1px solid #dc2626;
        }
        .btn-danger:hover { background: #b91c1c; border-color: #b91c1c; }
        .btn-warning {
            background: #f59e0b;
            color: #fff;
            border: 1px solid #f59e0b;
        }
        .btn-warning:hover { background: #d97706; border-color: #d97706; }
        .btn-sm {
            padding: 4px 12px;
            font-size: 11px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-sm.danger { background: #dc2626; color: #fff; }
        .btn-sm.danger:hover { background: #b91c1c; }
        .btn-sm.primary { background: #2563eb; color: #fff; }
        .btn-sm.primary:hover { background: #1d4ed8; }

        /* セクション */
        .section {
            background: #fafcff;
            border: 1px solid #e9edf4;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 20px;
            transition: border-color 0.15s;
        }
        .section:hover { border-color: #d0d8e3; }
        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        .section-title .badge {
            font-size: 10px;
            font-weight: 600;
            background: #e2e8f0;
            color: #475569;
            padding: 2px 10px;
            border-radius: 30px;
        }
        .section-title .badge-new {
            background: #dbeafe;
            color: #1d4ed8;
        }

        /* グリッド */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
        }
        .full-width { grid-column: 1 / -1; }

        /* フォーム要素 */
        .field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .field label {
            font-size: 12.5px;
            font-weight: 600;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .field label .required {
            color: #dc2626;
            font-weight: 700;
            font-size: 14px;
        }
        .field .hint {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 400;
        }
        .field input, .field select, .field textarea {
            padding: 9px 12px;
            border: 1px solid #d0d8e3;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #ffffff;
            width: 100%;
            color: #0f172a;
            transition: border 0.2s, box-shadow 0.2s;
        }
        .field input:focus, .field select:focus, .field textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.10);
        }
        .field textarea {
            resize: vertical;
            min-height: 56px;
        }
        .field input[readonly] {
            background: #f1f5f9;
            color: #475569;
        }
        .field .input-with-button {
            display: flex;
            gap: 8px;
        }
        .field .input-with-button input { flex: 1; }
        .field .input-with-button button {
            padding: 9px 18px;
            background: #f1f4f8;
            border: 1px solid #d0d8e3;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .field .input-with-button button:hover {
            background: #e2e8f0;
            border-color: #b0bccd;
        }
        .field .input-with-button button.primary {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
        .field .input-with-button button.primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        /* チェックボックス */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 24px;
            padding: 4px 0;
        }
        .checkbox-group label {
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            transition: background 0.1s;
        }
        .checkbox-group label:hover { background: #f1f5f9; }
        .checkbox-group label input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #2563eb;
            cursor: pointer;
            flex-shrink: 0;
        }
        .selected-preview {
            font-size: 13px;
            font-weight: 600;
            color: #1d4ed8;
            background: #eff6ff;
            padding: 6px 14px;
            border-radius: 30px;
            display: inline-block;
            margin-top: 4px;
        }

        /* F/U項目テーブル */
        .fu-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 13px;
        }
        .fu-table th {
            background: #f1f5f9;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #dce2ec;
            font-size: 12px;
        }
        .fu-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e9edf4;
            vertical-align: middle;
        }
        .fu-table .fu-row:hover { background: #f8fafc; }
        .fu-table select, .fu-table input {
            padding: 6px 8px;
            border: 1px solid #d0d8e3;
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
            width: 100%;
        }
        .fu-table select:focus, .fu-table input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
        }
        .fu-table .col-exam { width: 18%; }
        .fu-table .col-interval { width: 18%; }
        .fu-table .col-custom { width: 15%; }
        .fu-table .col-reason { width: 30%; }
        .fu-table .col-status { width: 13%; }
        .fu-table .col-action { width: 6%; text-align: center; }

        .fu-table .custom-input {
            display: none;
        }
        .fu-table .custom-input.show {
            display: block;
        }

        .add-row-btn {
            padding: 8px 20px;
            background: #f1f4f8;
            border: 1px dashed #b0bccd;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
            margin-top: 10px;
        }
        .add-row-btn:hover {
            background: #e2e8f0;
            border-color: #2563eb;
            color: #1d4ed8;
        }

        /* 履歴テーブル */
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 8px;
        }
        .history-table th {
            background: #f1f5f9;
            padding: 6px 10px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #dce2ec;
            font-size: 11px;
        }
        .history-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #e9edf4;
            vertical-align: middle;
        }
        .history-table .status-badge {
            display: inline-block;
            padding: 1px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-badge.planned { background: #dbeafe; color: #1e40af; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.cancelled { background: #fef2f2; color: #991b1b; }
        .status-badge.postponed { background: #fef3c7; color: #92400e; }
        .history-table .exam-tag {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        .exam-tag.gif { background: #0ea5e9; color: #fff; }
        .exam-tag.tcf { background: #10b981; color: #fff; }
        .exam-tag.echo { background: #8b5cf6; color: #fff; }
        .exam-tag.polypec { background: #f59e0b; color: #fff; }
        .exam-tag.other { background: #e2e8f0; color: #475569; }
        .empty-msg {
            text-align: center;
            color: #94a3b8;
            padding: 20px;
            font-size: 13px;
        }

        /* フッター */
        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
            padding-top: 20px;
            border-top: 1px solid #e9edf4;
            justify-content: space-between;
            align-items: center;
        }
        .form-actions .left-group { display: flex; gap: 10px; flex-wrap: wrap; }
        .form-actions .right-group { display: flex; gap: 10px; flex-wrap: wrap; }
        .form-actions button, .form-actions a {
            padding: 10px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .msg {
            padding: 10px 16px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .msg-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        .status-badge-lg {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge-lg.draft { background: #f1f5f9; color: #475569; }
        .status-badge-lg.printed { background: #dbeafe; color: #1d4ed8; }

        /* ★★★ 簡易入力ボタン ★★★ */
        .quick-date-btn {
            padding: 4px 12px;
            border: 1px solid #d0d8e3;
            border-radius: 16px;
            background: #f8fafc;
            color: #334155;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .quick-date-btn:hover {
            background: #e2e8f0;
            border-color: #2563eb;
            color: #1d4ed8;
        }
        .quick-date-btn:active {
            transform: scale(0.95);
        }

        @media (max-width: 768px) {
            .container { padding: 20px 16px; }
            .grid-2 { grid-template-columns: 1fr; gap: 14px; }
            .form-header { flex-direction: column; align-items: stretch; gap: 12px; }
            .form-header h1 { font-size: 19px; }
            .header-actions { flex-wrap: wrap; }
            .fu-table { font-size: 12px; }
            .fu-table th, .fu-table td { padding: 4px 6px; }
            .fu-table .col-exam { width: 15%; }
            .fu-table .col-interval { width: 15%; }
            .fu-table .col-custom { width: 12%; }
            .fu-table .col-reason { width: 28%; }
            .fu-table .col-status { width: 15%; }
            .fu-table .col-action { width: 8%; }
            .form-actions { flex-direction: column; align-items: stretch; }
            .form-actions .left-group, .form-actions .right-group { justify-content: center; }
            .history-table { font-size: 11px; }
            .history-table th, .history-table td { padding: 3px 6px; }
            .quick-date-btn { font-size: 10px; padding: 3px 8px; }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- ヘッダー -->
    <div class="form-header">
        <div>
            <h1>
                <?= $is_edit ? '✏️ フォローアップ編集' : '📋 フォローアップ新規登録' ?>
                <span class="sub">（患者さんにお渡しするシート）</span>
            </h1>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn-outline">📋 一覧へ</a>
            <a href="../index.php" class="btn-primary">🏠 メニューへ</a>
        </div>
    </div>

    <!-- メッセージ -->
    <?php if ($msg): ?>
        <div class="msg <?= $error ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="action.php" id="mainForm">
        <input type="hidden" name="followup_id" value="<?= $data['followup_id'] ?>">
        <input type="hidden" name="action" value="<?= $is_edit ? 'update' : 'insert' ?>">

        <!-- ============================================================
        セクション1: 患者情報
        ============================================================ -->
        <div class="section">
            <div class="section-title">
                👤 患者情報
                <span class="badge">必須</span>
            </div>
            <div class="grid-2">
                <div class="field">
                    <label>カルテID <span class="required">*</span></label>
                    <div class="input-with-button">
                        <input type="text" name="patient_id" id="f_pid"
                               value="<?= htmlspecialchars($data['patient_id'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="例: 74819" required
                               onchange="loadPatientHistory(this.value)">
                        <button type="button" class="primary" onclick="openPatientSearch()">🔍 検索</button>
                    </div>
                    <span class="hint">患者検索画面から選択できます。IDを入力すると過去のF/U履歴を表示します</span>
                </div>
                <div class="field">
                    <label>患者氏名 <span class="required">*</span></label>
                    <input type="text" name="patient_name" id="f_pname"
                           value="<?= htmlspecialchars($data['patient_name'], ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="患者氏名" required>
                </div>
                <div class="field">
                    <label>患者ヨミガナ</label>
                    <input type="text" name="patient_kana" id="f_kana"
                           value="<?= htmlspecialchars($data['patient_kana'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="ヤマダ タロウ">
                </div>
                <div class="field">
                    <label>生年月日</label>
                    <input type="date" name="birthday" id="f_birthday"
                           value="<?= htmlspecialchars($data['birthday'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label>診療科</label>
                    <select name="department_code">
                        <option value="PROCT" <?= ($data['department_code'] ?? '') === 'PROCT' ? 'selected' : '' ?>>肛門科</option>
                        <option value="GASTRO" <?= ($data['department_code'] ?? '') === 'GASTRO' ? 'selected' : '' ?>>胃腸科</option>
                    </select>
                </div>
                <div class="field">
                    <label>担当医師</label>
                    <input type="text" name="doctor_name"
                           value="<?= htmlspecialchars($data['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="例: 誠吾">
                </div>
            </div>
        </div>

        <!-- ============================================================
        セクション2: 今回の検査（実施済み）
        ============================================================ -->
        <div class="section">
            <div class="section-title">
                🔬 今回の検査（実施済み）
                <span class="badge">必須</span>
            </div>
            <div class="field">
                <label>実施した検査種別 <span class="required">*</span></label>
                <div class="checkbox-group" id="examCheckboxGroup">
                    <?php foreach ($exam_type_options as $key => $label): ?>
                        <label>
                            <input type="checkbox" name="exam_types[]" value="<?= $key ?>"
                                   <?= in_array($key, $exam_types) ? 'checked' : '' ?>
                                   onchange="updateExamDisplay()">
                            <?= $label ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div id="examDisplayPreview" class="selected-preview">
                    <?php
                    $display = $data['exam_types_display'] ?? '';
                    if (empty($display) && !empty($exam_types)) {
                        $display = implode(' + ', $exam_types);
                        if (in_array('GIF', $exam_types) && in_array('TCF', $exam_types)) {
                            $display .= '（ダブル）';
                        }
                    }
                    echo !empty($display) ? '✅ 選択中: ' . htmlspecialchars($display, ENT_QUOTES, 'UTF-8') : '（選択してください）';
                    ?>
                </div>
                <input type="hidden" name="exam_types_json" id="f_exam_types_json"
                       value="<?= htmlspecialchars($data['exam_types'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="exam_types_display" id="f_exam_types_display"
                       value="<?= htmlspecialchars($data['exam_types_display'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <!-- ============================================================
        セクション3: 次回F/U検査（予定）★ ここがメイン
        ============================================================ -->
        <div class="section" style="border-color: #bfdbfe; background: #f8fbff;">
            <div class="section-title">
                🔄 次回F/U検査（予定）
                <span class="badge badge-new">複数登録可</span>
            </div>

            <div class="field">
                <label>F/U検査項目 <span class="required">*</span></label>
                <div style="font-size:12px; color:#64748b; margin-bottom:8px;">
                    ※ 各検査ごとにF/U期間と理由を設定できます。「カスタム」を選ぶと自由入力欄が表示されます
                </div>

                <table class="fu-table" id="fuTable">
                    <thead>
                        <tr>
                            <th class="col-exam">検査種別</th>
                            <th class="col-interval">F/U期間</th>
                            <th class="col-custom">カスタム</th>
                            <th class="col-reason">理由（特記事項）</th>
                            <th class="col-status">ステータス</th>
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody id="fuTableBody">
                        <?php if (!empty($items)): ?>
                            <?php foreach ($items as $idx => $item): 
                                $is_custom = ($item['fu_interval'] === 'カスタム');
                            ?>
                                <tr class="fu-row" data-index="<?= $idx ?>">
                                    <td>
                                        <select name="fu_exam[]" class="fu-exam">
                                            <?php foreach ($exam_type_options as $key => $label): ?>
                                                <option value="<?= $key ?>" <?= $item['exam_type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="fu_interval[]" class="fu-interval" onchange="toggleCustomInput(this)">
                                            <?php foreach ($interval_options as $key => $label): ?>
                                                <option value="<?= $key ?>" <?= $item['fu_interval'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="fu_custom[]" class="fu-custom <?= $is_custom ? 'show' : '' ?>"
                                               value="<?= $is_custom ? htmlspecialchars($item['fu_interval'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"
                                               placeholder="自由入力（例：2年半）">
                                    </td>
                                    <td>
                                        <input type="text" name="fu_reason[]" class="fu-reason"
                                               value="<?= htmlspecialchars($item['fu_reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="例：ポリープ切除後経過観察">
                                    </td>
                                    <td>
                                        <select name="fu_status[]" class="fu-status">
                                            <?php foreach ($status_labels as $key => $label): ?>
                                                <option value="<?= $key ?>" <?= ($item['status'] ?? 'planned') === $key ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <button type="button" class="btn-sm danger" onclick="removeRow(this)">✕</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- ★★★ 新規登録時は完全に空っぽ（何も表示しない） ★★★ -->
                        <?php endif; ?>
                    </tbody>
                </table>

                <button type="button" class="add-row-btn" onclick="addRow()">➕ F/U検査を追加</button>
                <input type="hidden" name="fu_items_json" id="f_fu_items_json" value="">
            </div>
        </div>

        <!-- ============================================================
        セクション4: 過去のF/U履歴
        ============================================================ -->
        <div class="section" id="historySection" style="<?= empty($history) ? 'display:none;' : '' ?>">
            <div class="section-title">
                📜 過去のF/U履歴（この患者さん）
                <span class="badge">参照用</span>
            </div>
            <div id="historyContainer">
                <?php if (!empty($history)): ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>登録日</th>
                                <th>検査種別</th>
                                <th>F/U期間</th>
                                <th>理由</th>
                                <th>ステータス</th>
                                <th>完了日</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h):
                                $cls = strtolower($h['exam_type'] ?? '');
                                if ($cls === 'エコー') $cls = 'echo';
                                if ($cls === 'ポリペク') $cls = 'polypec';
                            ?>
                                <tr>
                                    <td><?= date('Y-m-d', strtotime($h['registered_date'] ?? 'now')) ?></td>
                                    <td><span class="exam-tag <?= $cls ?>"><?= htmlspecialchars($h['exam_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($h['fu_interval'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="font-size:11px; color:#64748b;"><?= htmlspecialchars(mb_substr($h['fu_reason'] ?? '', 0, 30), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="status-badge <?= $h['status'] ?? 'planned' ?>"><?= $status_labels[$h['status'] ?? 'planned'] ?? $h['status'] ?></span></td>
                                    <td><?= $h['completed_date'] ? date('Y-m-d', strtotime($h['completed_date'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-msg">📝 過去のF/U履歴はありません</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
        セクション5: 次回来院情報
        ============================================================ -->
        <div class="section">
            <div class="section-title">
                📅 次回来院情報
                <span class="badge">必須</span>
            </div>

            <div class="grid-2">
                <div class="field full-width">
                    <label>来院日 <span class="required">*</span></label>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:6px;">
                        <select id="visitDateType" name="next_visit_date_type" onchange="toggleDateInput(this.value)" style="padding:8px 12px; border:1px solid #d0d8e3; border-radius:8px; font-size:13px; flex:1; min-width:140px;">
                            <option value="exact" <?= ($data['next_visit_date_type'] ?? 'exact') === 'exact' ? 'selected' : '' ?>>確定日</option>
                            <option value="after" <?= ($data['next_visit_date_type'] ?? '') === 'after' ? 'selected' : '' ?>>〜以降</option>
                            <option value="around" <?= ($data['next_visit_date_type'] ?? '') === 'around' ? 'selected' : '' ?>>〜頃（アバウト）</option>
                            <option value="weekly" <?= ($data['next_visit_date_type'] ?? '') === 'weekly' ? 'selected' : '' ?>>毎週○曜日</option>
                            <option value="monthly_week" <?= ($data['next_visit_date_type'] ?? '') === 'monthly_week' ? 'selected' : '' ?>>第○週○曜日</option>
                            <option value="monthly_last" <?= ($data['next_visit_date_type'] ?? '') === 'monthly_last' ? 'selected' : '' ?>>最終○曜日</option>
                            <option value="undecided" <?= ($data['next_visit_date_type'] ?? '') === 'undecided' ? 'selected' : '' ?>>未定（後日調整）</option>
                        </select>
                    </div>

                    <!-- 日付ピッカー（exact / after） -->
                    <div id="datePickerArea">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:6px;">
                            <input type="date" name="next_visit_date" id="f_next_date"
                                   value="<?= htmlspecialchars($data['next_visit_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   style="padding:8px 12px; border:1px solid #d0d8e3; border-radius:8px; font-size:14px; width:auto;">
                            <span id="dateSuffix" style="font-size:13px; color:#64748b; margin-left:6px;"></span>
                        </div>
                        
                        <!-- ★★★ 簡易入力ボタン（確定日/exact + after 時のみ表示） ★★★ -->
                        <div id="quickDateButtons" style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(7)" title="今日から7日後">1週間後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(14)" title="今日から14日後">2週間後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(30)" title="今日から30日後">1か月後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(60)" title="今日から60日後">2か月後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(90)" title="今日から90日後">3か月後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(365)" title="今日から365日後">1年後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(730)" title="今日から730日後">2年後</button>
                            <button type="button" class="quick-date-btn" onclick="setRelativeDate(1095)" title="今日から1095日後">3年後</button>
                        </div>
                    </div>

                    <!-- 曜日パターン -->
                    <div id="weekdayPatternArea" style="display:none; display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:6px;">
                        <span style="font-size:12px;">毎月</span>
                        <select id="fWeekNum" name="next_visit_week_num" style="padding:6px 10px; border:1px solid #d0d8e3; border-radius:6px;">
                            <option value="1" <?= ($data['next_visit_week_num'] ?? 1) == 1 ? 'selected' : '' ?>>第1</option>
                            <option value="2" <?= ($data['next_visit_week_num'] ?? 1) == 2 ? 'selected' : '' ?>>第2</option>
                            <option value="3" <?= ($data['next_visit_week_num'] ?? 1) == 3 ? 'selected' : '' ?>>第3</option>
                            <option value="4" <?= ($data['next_visit_week_num'] ?? 1) == 4 ? 'selected' : '' ?>>第4</option>
                            <option value="5" <?= ($data['next_visit_week_num'] ?? 1) == 5 ? 'selected' : '' ?>>第5</option>
                        </select>
                        <select id="fDayOfWeek" name="next_visit_day_of_week" style="padding:6px 10px; border:1px solid #d0d8e3; border-radius:6px;">
                            <?php foreach ($week_ja as $i => $w): ?>
                                <option value="<?= $i ?>" <?= ($data['next_visit_day_of_week'] ?? 6) == $i ? 'selected' : '' ?>><?= $w ?>曜日</option>
                            <?php endforeach; ?>
                        </select>
                        <span id="weekdayPreview" style="font-size:12px; color:#1e40af; font-weight:bold;"></span>
                    </div>

                    <!-- テキスト入力（around / undecided） -->
                    <div id="dateTextArea" style="display:none; margin-top:6px;">
                        <input type="text" name="next_visit_display" id="f_next_display"
                               value="<?= htmlspecialchars($data['next_visit_display'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               style="width:100%; padding:8px 12px; border:1px solid #d0d8e3; border-radius:8px; font-size:14px;"
                               placeholder="例：2029年3月頃">
                    </div>
                    <input type="hidden" name="next_visit_pattern" id="f_next_pattern"
                           value="<?= htmlspecialchars($data['next_visit_pattern'] ?? 'none', ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">※ 確定日はカレンダーから選択 / アバウトな場合は「〜頃」を選択</span>
                </div>

                <div class="field">
                    <label>来院時間（任意）</label>
                    <input type="text" name="visit_time" value="<?= htmlspecialchars($data['visit_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例: 9:00">
                </div>
            </div>

            <div class="grid-2" style="margin-top:12px;">
                <div class="field">
                    <label>来院目的 <span class="required">*</span></label>
                    <select name="visit_purpose">
                        <?php foreach ($purpose_labels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($data['visit_purpose'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>来院方法 <span class="required">*</span></label>
                    <select name="visit_method">
                        <?php foreach ($method_labels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($data['visit_method'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============================================================
        セクション6: 特記事項・発行設定
        ============================================================ -->
        <div class="section">
            <div class="section-title">
                📝 特記事項・発行設定
            </div>
            <div class="grid-2">
                <div class="field full-width">
                    <label>特記事項（患者への伝達事項など）</label>
                    <textarea name="notes" rows="2"><?= htmlspecialchars($data['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="field">
                    <label>発行日</label>
                    <input type="date" name="issued_date" value="<?= htmlspecialchars($data['issued_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="hint">デフォルトは今日の日付です</span>
                </div>
                <div class="field">
                    <label>ステータス</label>
                    <div style="padding-top:6px;">
                        <span class="status-badge-lg <?= ($data['is_printed'] ?? false) ? 'printed' : 'draft' ?>">
                            <?= ($data['is_printed'] ?? false) ? '📄 印刷済み' : '📄 下書き（未印刷）' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        アクションボタン
        ============================================================ -->
        <div class="form-actions">
            <div class="left-group">
                <!-- キャンセル（保存せず一覧へ戻る） -->
                <a href="index.php" class="btn-outline" style="padding:10px 24px; border-radius:30px; font-size:14px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; background:transparent; color:#475569; border:1px solid #d0d8e3;" onclick="return confirm('編集内容は保存されませんが、よろしいですか？')">
                    ✕ キャンセル
                </a>
            </div>
            <div class="right-group">
                <?php if ($is_edit): ?>
                    <!-- 削除（編集時のみ） -->
                    <a href="action.php?action=delete&id=<?= $data['followup_id'] ?>" class="btn-danger" style="padding:10px 24px; border-radius:30px; font-size:14px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; background:#dc2626; color:#fff; border:none; cursor:pointer;" onclick="return confirm('本当に削除しますか？')">
                        🗑️ 削除
                    </a>
                <?php endif; ?>
                <!-- 保存して印刷 -->
                <button type="submit" class="btn-warning" name="save_and_print" value="1" style="padding:10px 24px; border-radius:30px; font-size:14px; font-weight:600; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; background:#f59e0b; color:#fff;">
                    🖨️ 保存して印刷
                </button>
                <!-- 保存 -->
                <button type="submit" class="btn-primary" style="padding:10px 24px; border-radius:30px; font-size:14px; font-weight:600; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; background:#2563eb; color:#fff;">
                    💾 保存
                </button>
            </div>
        </div>

    </form>

    <div style="text-align:center; font-size:11px; color:#94a3b8; margin-top:28px; padding-top:14px; border-top:1px solid #e9edf4;">
        おの肛門科胃腸科内視鏡クリニック ｜ フォローアップ管理システム v1.0
    </div>

</div>

<script>
// ============================================================
// 患者検索
// ============================================================
function openPatientSearch() {
    const url = '../patient_search.php';
    window.open(url, '_blank', 'width=1000,height=700,scrollbars=yes,resizable=yes');
}

function selectPatientFromSearch(patientNo, patientName, birthday, kana) {
    document.getElementById('f_pid').value = patientNo || '';
    document.getElementById('f_pname').value = patientName || '';
    document.getElementById('f_kana').value = kana || '';
    document.getElementById('f_birthday').value = birthday || '';
    if (patientNo) {
        loadPatientHistory(patientNo);
    }
}

// ============================================================
// 患者IDで過去のF/U履歴を読み込む
// ============================================================
function loadPatientHistory(patientId) {
    if (!patientId || patientId.length < 2) {
        document.getElementById('historySection').style.display = 'none';
        return;
    }

    fetch('api_history.php?patient_id=' + encodeURIComponent(patientId))
        .then(res => res.json())
        .then(data => {
            const section = document.getElementById('historySection');
            const container = document.getElementById('historyContainer');

            if (data.success && data.history && data.history.length > 0) {
                section.style.display = 'block';
                let html = '<table class="history-table"><thead><tr>';
                html += '<th>登録日</th><th>検査種別</th><th>F/U期間</th><th>理由</th><th>ステータス</th><th>完了日</th>';
                html += '</tr></thead><tbody>';

                data.history.forEach(function(item) {
                    const cls = (item.exam_type || '').toLowerCase();
                    const statusLabels = {
                        'planned': '📋 予定',
                        'completed': '✅ 完了',
                        'cancelled': '❌ 中止',
                        'postponed': '⏳ 延期'
                    };
                    const statusText = statusLabels[item.status] || item.status;
                    html += '<tr>';
                    html += '<td>' + (item.registered_date || '-') + '</td>';
                    html += '<td><span class="exam-tag ' + cls + '">' + (item.exam_type || '') + '</span></td>';
                    html += '<td>' + (item.fu_interval || '') + '</td>';
                    html += '<td style="font-size:11px;color:#64748b;">' + (item.fu_reason || '').substring(0, 30) + '</td>';
                    html += '<td><span class="status-badge ' + (item.status || 'planned') + '">' + statusText + '</span></td>';
                    html += '<td>' + (item.completed_date || '-') + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                section.style.display = 'block';
                container.innerHTML = '<div class="empty-msg">📝 過去のF/U履歴はありません</div>';
            }
        })
        .catch(function(err) {
            console.error('履歴取得エラー:', err);
        });
}

// ============================================================
// ★★★ 次回来院日 簡易入力ボタン関数 ★★★
// ============================================================
/**
 * 今日から指定日数後の日付をセットする
 * @param {number} days - 今日からの日数（例：7 = 1週間後）
 */
function setRelativeDate(days) {
    const today = new Date();
    const target = new Date(today);
    target.setDate(target.getDate() + days);
    
    const year = target.getFullYear();
    const month = String(target.getMonth() + 1).padStart(2, '0');
    const day = String(target.getDate()).padStart(2, '0');
    const dateStr = year + '-' + month + '-' + day;
    
    const dateInput = document.getElementById('f_next_date');
    dateInput.value = dateStr;
    
    // 曜日表示を更新
    if (typeof updateDayOfWeek === 'function') {
        updateDayOfWeek(dateStr);
    }
    
    dateInput.dispatchEvent(new Event('change'));
    dateInput.dispatchEvent(new Event('input'));
}

// ============================================================
// 今回の検査 表示更新
// ============================================================
function updateExamDisplay() {
    const checks = document.querySelectorAll('#examCheckboxGroup input[type="checkbox"]:checked');
    const values = Array.from(checks).map(c => c.value);
    const preview = document.getElementById('examDisplayPreview');
    const jsonInput = document.getElementById('f_exam_types_json');
    const displayInput = document.getElementById('f_exam_types_display');

    if (values.length === 0) {
        preview.textContent = '（選択してください）';
        jsonInput.value = '[]';
        displayInput.value = '';
        return;
    }

    let display = values.join(' + ');
    if (values.includes('GIF') && values.includes('TCF')) {
        display += '（ダブル）';
    }
    preview.textContent = '✅ 選択中: ' + display;
    jsonInput.value = JSON.stringify(values);
    displayInput.value = display;
}

// ============================================================
// F/U項目 行追加・削除
// ============================================================
function addRow() {
    const tbody = document.getElementById('fuTableBody');
    const index = tbody.querySelectorAll('.fu-row').length;

    const examOptions = <?= json_encode($exam_type_options) ?>;
    const intervalOptions = <?= json_encode($interval_options) ?>;
    const statusOptions = <?= json_encode($status_labels) ?>;

    let examHtml = '<select name="fu_exam[]" class="fu-exam">';
    for (const [key, label] of Object.entries(examOptions)) {
        examHtml += '<option value="' + key + '">' + label + '</option>';
    }
    examHtml += '</select>';

    let intervalHtml = '<select name="fu_interval[]" class="fu-interval" onchange="toggleCustomInput(this)">';
    for (const [key, label] of Object.entries(intervalOptions)) {
        intervalHtml += '<option value="' + key + '"' + (key === '1年' ? ' selected' : '') + '>' + label + '</option>';
    }
    intervalHtml += '</select>';

    let statusHtml = '<select name="fu_status[]" class="fu-status">';
    for (const [key, label] of Object.entries(statusOptions)) {
        statusHtml += '<option value="' + key + '"' + (key === 'planned' ? ' selected' : '') + '>' + label + '</option>';
    }
    statusHtml += '</select>';

    const tr = document.createElement('tr');
    tr.className = 'fu-row';
    tr.dataset.index = index;
    tr.innerHTML = `
        <td>${examHtml}</td>
        <td>${intervalHtml}</td>
        <td><input type="text" name="fu_custom[]" class="fu-custom" placeholder="自由入力"></td>
        <td><input type="text" name="fu_reason[]" class="fu-reason" placeholder="F/U理由"></td>
        <td>${statusHtml}</td>
        <td><button type="button" class="btn-sm danger" onclick="removeRow(this)">✕</button></td>
    `;
    tbody.appendChild(tr);
}

function removeRow(btn) {
    const tbody = document.getElementById('fuTableBody');
    if (tbody.querySelectorAll('.fu-row').length <= 1) {
        alert('F/U検査は最低1行必要です');
        return;
    }
    const tr = btn.closest('.fu-row');
    if (tr) tr.remove();
}

// ============================================================
// カスタム期間入力の表示切替
// ============================================================
function toggleCustomInput(select) {
    const row = select.closest('.fu-row');
    if (!row) return;
    const customInput = row.querySelector('.fu-custom');
    if (select.value === 'カスタム') {
        customInput.classList.add('show');
        customInput.style.display = 'block';
        customInput.focus();
    } else {
        customInput.classList.remove('show');
        customInput.style.display = 'none';
        customInput.value = '';
    }
}

// ============================================================
// 日付タイプ切替
// ============================================================
function toggleDateInput(type) {
    const picker = document.getElementById('datePickerArea');
    const textArea = document.getElementById('dateTextArea');
    const patternArea = document.getElementById('weekdayPatternArea');
    const dateInput = document.getElementById('f_next_date');
    const textInput = document.getElementById('f_next_display');
    const suffix = document.getElementById('dateSuffix');
    const patternInput = document.getElementById('f_next_pattern');
    const preview = document.getElementById('weekdayPreview');
    const quickButtons = document.getElementById('quickDateButtons');

    picker.style.display = 'none';
    textArea.style.display = 'none';
    patternArea.style.display = 'none';
    if (quickButtons) quickButtons.style.display = 'none';
    dateInput.required = false;
    textInput.required = false;
    suffix.textContent = '';

    switch(type) {
        case 'exact':
            picker.style.display = 'block';
            if (quickButtons) quickButtons.style.display = 'flex';
            dateInput.required = true;
            patternInput.value = 'none';
            break;
        case 'after':
            picker.style.display = 'block';
            if (quickButtons) quickButtons.style.display = 'flex';
            dateInput.required = true;
            suffix.textContent = '以降';
            patternInput.value = 'none';
            break;
        case 'weekly':
            patternArea.style.display = 'flex';
            patternInput.value = 'weekly';
            updateWeekdayPreview();
            break;
        case 'monthly_week':
            patternArea.style.display = 'flex';
            patternInput.value = 'monthly_week';
            updateWeekdayPreview();
            break;
        case 'monthly_last':
            patternArea.style.display = 'flex';
            patternInput.value = 'monthly_last';
            updateWeekdayPreview();
            break;
        case 'around':
            textArea.style.display = 'block';
            textInput.required = true;
            textInput.placeholder = '例：2029年3月頃';
            patternInput.value = 'none';
            break;
        case 'undecided':
            textArea.style.display = 'block';
            textInput.required = true;
            textInput.placeholder = '例：後日調整';
            if (!textInput.value) textInput.value = '後日調整';
            patternInput.value = 'none';
            break;
    }
}

function updateWeekdayPreview() {
    const weekNum = document.getElementById('fWeekNum').value;
    const dow = document.getElementById('fDayOfWeek').value;
    const days = ['日', '月', '火', '水', '木', '金', '土'];
    const type = document.getElementById('f_next_pattern').value;
    const preview = document.getElementById('weekdayPreview');

    let text = '';
    switch(type) {
        case 'weekly':
            text = '毎週 ' + days[dow] + '曜日';
            break;
        case 'monthly_week':
            text = '毎月 第' + weekNum + days[dow] + '曜日';
            break;
        case 'monthly_last':
            text = '毎月 最終' + days[dow] + '曜日';
            break;
        default:
            text = '';
    }
    preview.textContent = text ? '→ ' + text : '';
}

// ============================================================
// フォーム送信前処理
// ============================================================
document.getElementById('mainForm').addEventListener('submit', function(e) {
    // F/U項目をJSON化
    const items = [];
    document.querySelectorAll('#fuTableBody .fu-row').forEach(function(row) {
        const exam = row.querySelector('.fu-exam')?.value || '';
        const interval = row.querySelector('.fu-interval')?.value || '';
        const custom = row.querySelector('.fu-custom')?.value || '';
        const reason = row.querySelector('.fu-reason')?.value || '';
        const status = row.querySelector('.fu-status')?.value || 'planned';

        // カスタムの場合はカスタム値をintervalとして使用
        let finalInterval = interval;
        if (interval === 'カスタム' && custom) {
            finalInterval = custom;
        }

        if (exam) {
            items.push({
                exam_type: exam,
                fu_interval: finalInterval,
                fu_reason: reason,
                status: status
            });
        }
    });

    document.getElementById('f_fu_items_json').value = JSON.stringify(items);
});

// ============================================================
// Enterキー誤爆防止（フォーム全体）
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // ★★★ 簡易入力ボタン関数をグローバルに公開 ★★★
    window.setRelativeDate = setRelativeDate;

    // フォーム全体で Enter キーによる送信を防止
    const form = document.getElementById('mainForm');
    if (form) {
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const target = e.target;
                if (target.tagName === 'TEXTAREA') {
                    return;
                }
                if (target.id === 'f_pid') {
                    e.preventDefault();
                    loadPatientHistory(target.value);
                    return;
                }
                e.preventDefault();
                return false;
            }
        });
    }
    
    // カルテID入力欄の Enter キー処理（二重送信防止）
    const pidInput = document.getElementById('f_pid');
    if (pidInput) {
        pidInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadPatientHistory(this.value);
                return false;
            }
        });
    }

    // 日付タイプの初期表示
    const typeSelect = document.getElementById('visitDateType');
    if (typeSelect) {
        toggleDateInput(typeSelect.value);
    }

    // 患者IDが既にある場合は履歴を読み込む
    const pid = document.getElementById('f_pid')?.value;
    if (pid && pid.length >= 2) {
        loadPatientHistory(pid);
    }
});
</script>

</body>
</html>