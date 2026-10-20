<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\furiwake_daymemo.php
 * ファイル名: furiwake_daymemo.php
 * 概要: 日別カレンダーメモ (t_daily_memo) 診療科振り分けメンテナンスツール
 * 生成日時: 2026-10-09 22:50:00 JST
 * 修正履歴:
 *   - 2026-10-09: 新規作成
 *     - 登録済みの日付メモを全件一覧表示
 *     - 各メモを「🟢 肛門科 (PROCT)」と「🔵 胃腸科 (GASTRO)」へワンクリックで即時振り分け（AJAX）
 *     - 同日付に振り分け先レコードが既に存在する場合の衝突防止（確認・安全マージ対応）
 *     - メモ本文のインライン編集・更新機能
 *     - 不要メモの削除機能（物理クリーンアップ）
 *     - 新規メモの直接登録フォーム（日付ピッカー・科選択）
 *     - 診療科フィルター（全件 / 肛門科 / 胃腸科）、年月フィルター、キーワード絞り込み
 *     - month_schedule.php / schedule_note.php / month_matrix_v2.php へのシームレスな相互リンク
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ── AJAX API 処理 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        if ($action === 'change_dept') {
            $id = (int)($_POST['id'] ?? 0);
            $new_dept = trim((string)($_POST['new_dept'] ?? ''));

            if ($id <= 0 || !in_array($new_dept, ['PROCT', 'GASTRO'], true)) {
                throw new Exception("無効なパラメータです (id: {$id}, dept: {$new_dept})");
            }

            // 対象レコードの取得
            $stmt = $pdo->prepare("SELECT * FROM t_daily_memo WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                throw new Exception("対象のメモが見つかりません (ID: {$id})");
            }

            if ($current['department_code'] === $new_dept) {
                echo json_encode(['success' => true, 'message' => 'すでに指定の診療科です', 'current' => $current], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $target_date = $current['target_date'];
            $current_memo = $current['memo'];

            // 振り分け先に同日のレコードが既に存在するか確認
            $stmt_check = $pdo->prepare("SELECT * FROM t_daily_memo WHERE department_code = :dept AND target_date = :date AND id <> :id");
            $stmt_check->execute([':dept' => $new_dept, ':date' => $target_date, ':id' => $id]);
            $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // 既存レコードが存在する場合はメモを結合して現在のレコードを削除
                $merged_memo = trim($existing['memo'] . "\n" . $current_memo);
                $upd = $pdo->prepare("UPDATE t_daily_memo SET memo = :memo, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $upd->execute([':memo' => $merged_memo, ':id' => $existing['id']]);

                $del = $pdo->prepare("DELETE FROM t_daily_memo WHERE id = :id");
                $del->execute([':id' => $id]);

                echo json_encode([
                    'success' => true,
                    'merged' => true,
                    'message' => "{$target_date} の既存【{$new_dept}】メモに結合しました",
                    'deleted_id' => $id,
                    'updated_id' => $existing['id'],
                    'new_memo' => $merged_memo
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                // 競合なし：科コードを更新
                $upd = $pdo->prepare("UPDATE t_daily_memo SET department_code = :dept, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $upd->execute([':dept' => $new_dept, ':id' => $id]);

                echo json_encode([
                    'success' => true,
                    'merged' => false,
                    'message' => "【{$new_dept}】へ振り分けました",
                    'id' => $id,
                    'new_dept' => $new_dept
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($action === 'update_memo') {
            $id = (int)($_POST['id'] ?? 0);
            $memo = trim((string)($_POST['memo'] ?? ''));

            if ($id <= 0) {
                throw new Exception("無効なIDです");
            }

            if ($memo === '') {
                // 空文字の場合は削除
                $stmt = $pdo->prepare("DELETE FROM t_daily_memo WHERE id = :id");
                $stmt->execute([':id' => $id]);
                echo json_encode(['success' => true, 'deleted' => true, 'message' => 'メモを削除しました', 'id' => $id], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                $stmt = $pdo->prepare("UPDATE t_daily_memo SET memo = :memo, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':memo' => $memo, ':id' => $id]);
                echo json_encode(['success' => true, 'deleted' => false, 'message' => 'メモを更新しました', 'id' => $id, 'memo' => $memo], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($action === 'delete_memo') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("無効なIDです");
            }

            $stmt = $pdo->prepare("DELETE FROM t_daily_memo WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'メモを削除しました', 'id' => $id], JSON_UNESCAPED_UNICODE);
            exit;
        } elseif ($action === 'add_memo') {
            $dept = trim((string)($_POST['dept'] ?? ''));
            $date = trim((string)($_POST['date'] ?? ''));
            $memo = trim((string)($_POST['memo'] ?? ''));

            if (!in_array($dept, ['PROCT', 'GASTRO'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $memo === '') {
                throw new Exception("すべての必須項目を入力してください");
            }

            $stmt = $pdo->prepare("
                INSERT INTO t_daily_memo (department_code, target_date, memo, updated_at)
                VALUES (:dept, :date, :memo, CURRENT_TIMESTAMP)
                ON CONFLICT (department_code, target_date)
                DO UPDATE SET memo = EXCLUDED.memo, updated_at = CURRENT_TIMESTAMP
                RETURNING id
            ");
            $stmt->execute([':dept' => $dept, ':date' => $date, ':memo' => $memo]);
            $new_id = (int)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'message' => '新規メモを登録しました', 'id' => $new_id], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new Exception("未知のアクションです");
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── 全メモデータの取得 ──
$stmt_all = $pdo->query("SELECT * FROM t_daily_memo ORDER BY target_date DESC, id DESC");
$all_memos = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

// 集計
$total_count = count($all_memos);
$proct_count = 0;
$gastro_count = 0;
$months_available = [];

foreach ($all_memos as $m) {
    if ($m['department_code'] === 'PROCT') $proct_count++;
    if ($m['department_code'] === 'GASTRO') $gastro_count++;
    $ym = substr((string)$m['target_date'], 0, 7);
    $months_available[$ym] = true;
}
krsort($months_available);

$w_map = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📝 日別メモ 診療科振り分けメンテナンス | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        :root {
            --proct-color: #059669;
            --proct-bg: #ecfdf5;
            --proct-border: #a7f3d0;
            --gastro-color: #2563eb;
            --gastro-bg: #eff6ff;
            --gastro-border: #bfdbfe;
        }

        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
            margin: 0;
            padding: 16px 20px 40px;
        }

        .main-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        /* ── ヘッダー ── */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            flex-wrap: wrap;
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title-badge {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-sub-badge {
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 6px;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .header-nav-btns {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .header-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            color: #334155;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            transition: all 0.15s ease;
        }

        .header-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
            transform: translateY(-1px);
        }

        .header-btn.btn-primary {
            background: #3b82f6;
            color: #ffffff;
            border-color: #2563eb;
        }
        .header-btn.btn-primary:hover {
            background: #2563eb;
        }

        /* ── サマリ KPI カード ── */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .kpi-card.kpi-proct {
            border-left: 4px solid var(--proct-color);
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
        }
        .kpi-card.kpi-gastro {
            border-left: 4px solid var(--gastro-color);
            background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%);
        }
        .kpi-card.kpi-total {
            border-left: 4px solid #64748b;
        }

        .kpi-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
        }

        .kpi-val {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
        }

        /* ── 新規メモ追加カード ── */
        .add-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 18px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .add-card-header {
            font-size: 13px;
            font-weight: 800;
            color: #334155;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .add-form-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .form-input {
            padding: 6px 10px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            background: #ffffff;
            color: #0f172a;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .form-select {
            padding: 6px 10px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            background: #ffffff;
            cursor: pointer;
        }

        .btn-add-submit {
            background: #10b981;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 7px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-add-submit:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        /* ── ツールバー（フィルター＆検索） ── */
        .toolbar-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 16px;
            margin-bottom: 14px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .toolbar-filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .filter-pill {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        .filter-pill:hover {
            background: #f1f5f9;
        }
        .filter-pill.active {
            background: #1e293b;
            color: #ffffff;
            border-color: #1e293b;
        }
        .filter-pill.active-proct {
            background: var(--proct-color);
            color: #ffffff;
            border-color: var(--proct-color);
        }
        .filter-pill.active-gastro {
            background: var(--gastro-color);
            color: #ffffff;
            border-color: var(--gastro-color);
        }

        .search-box {
            position: relative;
        }
        .search-input {
            padding: 6px 12px 6px 30px;
            border: 1.5px solid #cbd5e1;
            border-radius: 20px;
            font-size: 12px;
            width: 220px;
            transition: width 0.2s;
        }
        .search-input:focus {
            width: 300px;
            border-color: #3b82f6;
            outline: none;
        }
        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: #94a3b8;
        }

        /* ── メモ一覧テーブル ── */
        .table-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .memo-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .memo-table th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 800;
            padding: 10px 12px;
            border-bottom: 1.5px solid #cbd5e1;
            text-align: left;
            font-size: 12px;
        }

        .memo-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .memo-table tr:hover {
            background-color: #f8fafc;
        }

        .memo-row-proct {
            border-left: 4px solid var(--proct-color);
        }
        .memo-row-gastro {
            border-left: 4px solid var(--gastro-color);
        }

        /* 日付バッジ */
        .date-badge-box {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 800;
            font-size: 13.5px;
        }
        .dow-pill {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 800;
        }
        .dow-sun { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .dow-sat { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .dow-weekday { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .closed-pill {
            background: #fee2e2;
            color: #dc2626;
            font-size: 10px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            border: 1px solid #fca5a5;
        }

        /* 診療科バッジ */
        .dept-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }
        .dept-badge.proct {
            background: var(--proct-bg);
            color: var(--proct-color);
            border: 1.5px solid var(--proct-border);
        }
        .dept-badge.gastro {
            background: var(--gastro-bg);
            color: var(--gastro-color);
            border: 1.5px solid var(--gastro-border);
        }

        /* メモテキストエリア */
        .memo-edit-textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px 8px;
            font-size: 13px;
            font-family: inherit;
            resize: vertical;
            min-height: 38px;
            line-height: 1.4;
            transition: all 0.2s ease;
        }
        .memo-edit-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }

        /* クイックアクションボタン群 */
        .action-btns {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-act {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-to-proct {
            background: #f0fdf4;
            color: var(--proct-color);
            border-color: var(--proct-border);
        }
        .btn-to-proct:hover {
            background: #dcfce7;
            transform: translateY(-1px);
        }
        .btn-to-proct.is-current {
            background: #e2e8f0;
            color: #94a3b8;
            border-color: #cbd5e1;
            cursor: default;
            opacity: 0.6;
            transform: none !important;
        }

        .btn-to-gastro {
            background: #eff6ff;
            color: var(--gastro-color);
            border-color: var(--gastro-border);
        }
        .btn-to-gastro:hover {
            background: #dbeafe;
            transform: translateY(-1px);
        }
        .btn-to-gastro.is-current {
            background: #e2e8f0;
            color: #94a3b8;
            border-color: #cbd5e1;
            cursor: default;
            opacity: 0.6;
            transform: none !important;
        }

        .btn-save-memo {
            background: #f1f5f9;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn-save-memo:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-del-memo {
            background: #fff1f2;
            color: #e11d48;
            border-color: #fecdd3;
        }
        .btn-del-memo:hover {
            background: #ffe4e6;
            color: #be123c;
        }

        /* トースト通知 */
        .toast-notify {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1e293b;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 8px 20px rgba(0,0,0,0.18);
            z-index: 9999;
            display: none;
            align-items: center;
            gap: 8px;
            transition: opacity 0.3s ease;
        }
        .toast-notify.toast-success { background: #065f46; border-left: 4px solid #34d399; }
        .toast-notify.toast-error { background: #881337; border-left: 4px solid #f43f5e; }

        .empty-row-notice {
            text-align: center;
            padding: 40px;
            color: #64748b;
            font-weight: 700;
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="main-container">

    <!-- ── ページヘッダー ── -->
    <div class="page-header">
        <div class="header-left">
            <div class="header-title-badge">
                <span>📝 日別メモ 診療科振り分けメンテナンス</span>
            </div>
            <span class="header-sub-badge">🔧 データ整理・科別完全独立</span>
        </div>
        <div class="header-nav-btns">
            <a href="month_schedule.php?dept=PROCT" class="header-btn">
                <span>🟢</span> 肛門科カレンダー
            </a>
            <a href="month_schedule.php?dept=GASTRO" class="header-btn">
                <span>🔵</span> 胃腸科カレンダー
            </a>
            <a href="schedule_note.php?dept=PROCT" class="header-btn">
                <span>📖</span> デイリーノート
            </a>
            <a href="month_matrix_v2.php?dept=PROCT" class="header-btn">
                <span>📊</span> 予約マトリクス
            </a>
            <a href="index.php" class="header-btn btn-primary">
                <span>🏠</span> メニュー
            </a>
        </div>
    </div>

    <!-- ── サマリ KPI カード ── -->
    <div class="kpi-row">
        <div class="kpi-card kpi-total">
            <div>
                <div class="kpi-label">登録メモ総数</div>
                <div class="kpi-val" id="kpiTotal"><?= $total_count ?></div>
            </div>
            <span style="font-size: 26px;">📋</span>
        </div>
        <div class="kpi-card kpi-proct">
            <div>
                <div class="kpi-label">🟢 肛門科 (PROCT) メモ</div>
                <div class="kpi-val" id="kpiProct"><?= $proct_count ?></div>
            </div>
            <span style="font-size: 26px;">🟢</span>
        </div>
        <div class="kpi-card kpi-gastro">
            <div>
                <div class="kpi-label">🔵 胃腸科 (GASTRO) メモ</div>
                <div class="kpi-val" id="kpiGastro"><?= $gastro_count ?></div>
            </div>
            <span style="font-size: 26px;">🔵</span>
        </div>
    </div>

    <!-- ── 新規メモ追加カード ── -->
    <div class="add-card">
        <div class="add-card-header">
            <span>➕</span> <span>新規日付メモの直接登録</span>
        </div>
        <form id="addMemoForm" onsubmit="submitAddMemo(event)">
            <div class="add-form-row">
                <input type="date" id="newDate" class="form-input" required value="<?= date('Y-m-d') ?>" style="width: 150px; font-weight: bold;">
                <select id="newDept" class="form-select" required>
                    <option value="PROCT">🟢 肛門科 (PROCT)</option>
                    <option value="GASTRO">🔵 胃腸科 (GASTRO)</option>
                </select>
                <input type="text" id="newMemo" class="form-input" placeholder="連絡メモ・医師休診など（例：哲也先生午後休診）" style="flex: 1; min-width: 260px;" required>
                <button type="submit" class="btn-add-submit">＋ 登録する</button>
            </div>
        </form>
    </div>

    <!-- ── ツールバー（フィルター ＆ 検索） ── -->
    <div class="toolbar-box">
        <div class="toolbar-filter-group">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; margin-right: 4px;">診療科:</span>
            <button type="button" class="filter-pill active" onclick="setDeptFilter('ALL', this)">すべて (<span id="cntAll"><?= $total_count ?></span>)</button>
            <button type="button" class="filter-pill" onclick="setDeptFilter('PROCT', this)">🟢 肛門科 (<span id="cntProct"><?= $proct_count ?></span>)</button>
            <button type="button" class="filter-pill" onclick="setDeptFilter('GASTRO', this)">🔵 胃腸科 (<span id="cntGastro"><?= $gastro_count ?></span>)</button>
        </div>

        <div class="toolbar-filter-group">
            <span style="font-size: 12px; font-weight: 700; color: #64748b; margin-right: 4px;">年月:</span>
            <select id="monthFilter" class="form-select" onchange="applyFilters()" style="padding: 3px 8px; font-size: 12px;">
                <option value="ALL">全期間</option>
                <?php foreach (array_keys($months_available) as $ym): ?>
                    <option value="<?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="searchKeyword" class="search-input" placeholder="メモ・医師名を検索..." oninput="applyFilters()">
        </div>
    </div>

    <!-- ── メモ一覧テーブル ── -->
    <div class="table-card">
        <table class="memo-table">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">ID</th>
                    <th style="width: 170px;">対象日付</th>
                    <th style="width: 110px;">現在の診療科</th>
                    <th>メモ内容 (直接編集可)</th>
                    <th style="width: 130px;">更新日時</th>
                    <th style="width: 250px; text-align: center;">振り分け・操作</th>
                </tr>
            </thead>
            <tbody id="memoTableBody">
                <?php if (empty($all_memos)): ?>
                    <tr id="noDataRow">
                        <td colspan="6" class="empty-row-notice">登録されている日付メモはありません</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($all_memos as $row): 
                        $mid = (int)$row['id'];
                        $mdate = (string)$row['target_date'];
                        $mdept = (string)$row['department_code'];
                        $mmemo = (string)$row['memo'];
                        $mupd = substr((string)$row['updated_at'], 0, 16);

                        $ts = strtotime($mdate);
                        $w = (int)date('w', $ts);
                        $w_str = $w_map[$w];
                        $dow_cls = ($w === 0) ? 'dow-sun' : (($w === 6) ? 'dow-sat' : 'dow-weekday');

                        $day_check = check_onokai_calendar($mdate);
                        $is_closed = $day_check['is_closed'];

                        $row_cls = ($mdept === 'PROCT') ? 'memo-row-proct' : 'memo-row-gastro';
                    ?>
                        <tr id="row-<?= $mid ?>" class="<?= $row_cls ?>" data-id="<?= $mid ?>" data-dept="<?= $mdept ?>" data-date="<?= $mdate ?>" data-ym="<?= substr($mdate, 0, 7) ?>">
                            <td style="text-align: center; font-weight: 700; color: #94a3b8; font-size: 11px;">
                                <?= $mid ?>
                            </td>
                            <td>
                                <div class="date-badge-box">
                                    <span><?= htmlspecialchars($mdate, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="dow-pill <?= $dow_cls ?>"><?= $w_str ?></span>
                                    <?php if ($is_closed): ?>
                                        <span class="closed-pill" title="<?= htmlspecialchars($day_check['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>">休診</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($mdept === 'PROCT'): ?>
                                    <span class="dept-badge proct" id="dept-badge-<?= $mid ?>">🟢 肛門科</span>
                                <?php else: ?>
                                    <span class="dept-badge gastro" id="dept-badge-<?= $mid ?>">🔵 胃腸科</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <textarea id="text-<?= $mid ?>" class="memo-edit-textarea" rows="1" oninput="markRowDirty(<?= $mid ?>)"><?= htmlspecialchars($mmemo, ENT_QUOTES, 'UTF-8') ?></textarea>
                            </td>
                            <td style="font-size: 11px; color: #64748b;" id="upd-<?= $mid ?>">
                                <?= htmlspecialchars($mupd, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <!-- 肛門科振分ボタン -->
                                    <button type="button" 
                                            class="btn-act btn-to-proct <?= ($mdept === 'PROCT') ? 'is-current' : '' ?>" 
                                            id="btn-proct-<?= $mid ?>"
                                            onclick="changeMemoDept(<?= $mid ?>, 'PROCT')"
                                            <?= ($mdept === 'PROCT') ? 'disabled' : '' ?>
                                            title="<?= ($mdept === 'PROCT') ? '現在肛門科です' : '肛門科へ振り分け' ?>">
                                        🟢 肛門科へ
                                    </button>

                                    <!-- 胃腸科振分ボタン -->
                                    <button type="button" 
                                            class="btn-act btn-to-gastro <?= ($mdept === 'GASTRO') ? 'is-current' : '' ?>" 
                                            id="btn-gastro-<?= $mid ?>"
                                            onclick="changeMemoDept(<?= $mid ?>, 'GASTRO')"
                                            <?= ($mdept === 'GASTRO') ? 'disabled' : '' ?>
                                            title="<?= ($mdept === 'GASTRO') ? '現在胃腸科です' : '胃腸科へ振り分け' ?>">
                                        🔵 胃腸科へ
                                    </button>

                                    <!-- 保存ボタン -->
                                    <button type="button" class="btn-act btn-save-memo" id="btn-save-<?= $mid ?>" onclick="saveMemoText(<?= $mid ?>)" title="編集内容を保存">
                                        💾 保存
                                    </button>

                                    <!-- 削除ボタン -->
                                    <button type="button" class="btn-act btn-del-memo" onclick="deleteMemo(<?= $mid ?>)" title="メモを削除">
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- ── トースト通知要素 ── -->
<div id="toastNotify" class="toast-notify"></div>

<script>
let currentDeptFilter = 'ALL';

// ── トースト通知 ──
function showToast(message, type = 'success') {
    const el = document.getElementById('toastNotify');
    if (!el) return;
    el.textContent = message;
    el.className = 'toast-notify ' + (type === 'error' ? 'toast-error' : 'toast-success');
    el.style.display = 'inline-flex';
    el.style.opacity = '1';

    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; }, 300);
    }, 2500);
}

// ── 診療科フィルター ──
function setDeptFilter(dept, btn) {
    currentDeptFilter = dept;
    document.querySelectorAll('.filter-pill').forEach(b => {
        b.classList.remove('active', 'active-proct', 'active-gastro');
    });

    if (dept === 'ALL') btn.classList.add('active');
    else if (dept === 'PROCT') btn.classList.add('active-proct');
    else if (dept === 'GASTRO') btn.classList.add('active-gastro');

    applyFilters();
}

// ── 複合フィルター適用 ──
function applyFilters() {
    const ymFilter = document.getElementById('monthFilter').value;
    const keyword = document.getElementById('searchKeyword').value.trim().toLowerCase();

    const rows = document.querySelectorAll('#memoTableBody tr[data-id]');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowDept = row.dataset.dept;
        const rowYm = row.dataset.ym;
        const txtEl = document.getElementById('text-' + row.dataset.id);
        const rowText = txtEl ? txtEl.value.toLowerCase() : '';
        const rowDate = row.dataset.date;

        let ok = true;
        if (currentDeptFilter !== 'ALL' && rowDept !== currentDeptFilter) ok = false;
        if (ymFilter !== 'ALL' && rowYm !== ymFilter) ok = false;
        if (keyword !== '' && !rowText.includes(keyword) && !rowDate.includes(keyword)) ok = false;

        row.style.display = ok ? '' : 'none';
        if (ok) visibleCount++;
    });

    const noDataRow = document.getElementById('noDataRow');
    if (noDataRow) {
        noDataRow.style.display = (visibleCount === 0) ? '' : 'none';
    }
}

// ── メモ編集感知 ──
function markRowDirty(id) {
    const btn = document.getElementById('btn-save-' + id);
    if (btn) {
        btn.style.background = '#fef3c7';
        btn.style.color = '#b45309';
        btn.style.borderColor = '#fde047';
    }
}

// ── 診療科振り分け実行 (AJAX) ──
async function changeMemoDept(id, newDept) {
    const row = document.getElementById('row-' + id);
    if (!row) return;

    const deptName = (newDept === 'PROCT') ? '🟢 肛門科' : '🔵 胃腸科';
    
    const formData = new FormData();
    formData.append('action', 'change_dept');
    formData.append('id', id);
    formData.append('new_dept', newDept);

    try {
        const res = await fetch('furiwake_daymemo.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');

            if (data.merged) {
                // 既存レコードに結合された場合は行をフェードアウト削除し、結合先行を更新
                row.style.transition = 'opacity 0.4s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 400);

                const destRow = document.getElementById('row-' + data.updated_id);
                if (destRow) {
                    const txt = document.getElementById('text-' + data.updated_id);
                    if (txt) txt.value = data.new_memo;
                }
            } else {
                // 科バッジとボタンスタイルを更新
                row.dataset.dept = newDept;
                row.className = (newDept === 'PROCT') ? 'memo-row-proct' : 'memo-row-gastro';

                const badge = document.getElementById('dept-badge-' + id);
                if (badge) {
                    badge.className = 'dept-badge ' + (newDept === 'PROCT' ? 'proct' : 'gastro');
                    badge.innerHTML = (newDept === 'PROCT' ? '🟢 肛門科' : '🔵 胃腸科');
                }

                const btnProct = document.getElementById('btn-proct-(' + id + ')');
                const btnGastro = document.getElementById('btn-gastro-(' + id + ')');

                document.getElementById('btn-proct-' + id).className = 'btn-act btn-to-proct ' + (newDept === 'PROCT' ? 'is-current' : '');
                document.getElementById('btn-proct-' + id).disabled = (newDept === 'PROCT');

                document.getElementById('btn-gastro-' + id).className = 'btn-act btn-to-gastro ' + (newDept === 'GASTRO' ? 'is-current' : '');
                document.getElementById('btn-gastro-' + id).disabled = (newDept === 'GASTRO');
            }

            updateKpiCounters();
            applyFilters();
        } else {
            showToast('振り分け失敗: ' + (data.error || '不明なエラー'), 'error');
        }
    } catch (err) {
        showToast('通信エラーが発生しました: ' + err, 'error');
    }
}

// ── メモ内容保存 (AJAX) ──
async function saveMemoText(id) {
    const txtEl = document.getElementById('text-' + id);
    if (!txtEl) return;
    const memo = txtEl.value.trim();

    const formData = new FormData();
    formData.append('action', 'update_memo');
    formData.append('id', id);
    formData.append('memo', memo);

    try {
        const res = await fetch('furiwake_daymemo.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            if (data.deleted) {
                const row = document.getElementById('row-' + id);
                if (row) row.remove();
                updateKpiCounters();
            } else {
                const btn = document.getElementById('btn-save-' + id);
                if (btn) {
                    btn.style.background = '#f1f5f9';
                    btn.style.color = '#334155';
                    btn.style.borderColor = '#cbd5e1';
                }
            }
        } else {
            showToast('更新失敗: ' + (data.error || '不明なエラー'), 'error');
        }
    } catch (err) {
        showToast('通信エラーが発生しました: ' + err, 'error');
    }
}

// ── メモ削除 (AJAX) ──
async function deleteMemo(id) {
    if (!confirm('この日別メモを削除しますか？\n（カレンダー・デイリーノートからも削除されます）')) return;

    const formData = new FormData();
    formData.append('action', 'delete_memo');
    formData.append('id', id);

    try {
        const res = await fetch('furiwake_daymemo.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            const row = document.getElementById('row-' + id);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                    updateKpiCounters();
                    applyFilters();
                }, 300);
            }
        } else {
            showToast('削除失敗: ' + (data.error || '不明なエラー'), 'error');
        }
    } catch (err) {
        showToast('通信エラーが発生しました: ' + err, 'error');
    }
}

// ── 新規メモ追加 (AJAX) ──
async function submitAddMemo(e) {
    e.preventDefault();

    const date = document.getElementById('newDate').value;
    const dept = document.getElementById('newDept').value;
    const memo = document.getElementById('newMemo').value.trim();

    if (!date || !dept || !memo) {
        alert('すべての日付・科・メモを入力してください');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'add_memo');
    formData.append('date', date);
    formData.append('dept', dept);
    formData.append('memo', memo);

    try {
        const res = await fetch('furiwake_daymemo.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.reload(), 500);
        } else {
            showToast('登録失敗: ' + (data.error || '不明なエラー'), 'error');
        }
    } catch (err) {
        showToast('通信エラーが発生しました: ' + err, 'error');
    }
}

// ── KPI カウンターリアルタイム再計算 ──
function updateKpiCounters() {
    const rows = document.querySelectorAll('#memoTableBody tr[data-id]');
    let total = rows.length;
    let proct = 0;
    let gastro = 0;

    rows.forEach(r => {
        if (r.dataset.dept === 'PROCT') proct++;
        if (r.dataset.dept === 'GASTRO') gastro++;
    });

    document.getElementById('kpiTotal').textContent = total;
    document.getElementById('kpiProct').textContent = proct;
    document.getElementById('kpiGastro').textContent = gastro;

    const cntAll = document.getElementById('cntAll');
    if (cntAll) cntAll.textContent = total;
    const cntProct = document.getElementById('cntProct');
    if (cntProct) cntProct.textContent = proct;
    const cntGastro = document.getElementById('cntGastro');
    if (cntGastro) cntGastro.textContent = gastro;
}
</script>

</body>
</html>
