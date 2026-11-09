<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\system_info_maintenance.php
 * ファイル名: system_info_maintenance.php
 * 概要: 修正インフォメーション・リリース情報マスターメンテナンス画面
 *       - ポータル（index.php）および index.html に配信する修正インフォメーションを一元管理
 *       - 新規追加、編集、削除、表示/非表示（公開/非公開）のワンクリック切り替え
 *       - DB（m_system_info）、JSON（config/system_info.json）、静的HTML（index.html）へのリアルタイム自動同期
 * 生成日時: 2026-11-01 19:15 JST
 * 修正履歴:
 *   - 2026-11-01: 【初版作成】v1101 本番稼働に伴う修正インフォメーション管理機能の新設
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/inc_system_info.php';
require_once __DIR__ . '/lib/env_helper.php';

$pdo = getSystemInfoPdo();
if (!$pdo) {
    die("❌ データベースに接続できません。PostgreSQL サービスが稼働しているかご確認ください。");
}

$message = '';
$is_error = false;

// POSTアクション処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = saveSystemInfoRecord($pdo, $_POST);
            $msg = !empty($_POST['id']) ? "ID: {$id} のインフォメーションを更新しました。" : "新しいインフォメーション（ID: {$id}）を登録しました。";
            header("Location: system_info_maintenance.php?msg=" . urlencode($msg . "（DB・JSON・index.html へ即時反映完了）"));
            exit;
        } elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                toggleSystemInfoActive($pdo, $id);
                header("Location: system_info_maintenance.php?msg=" . urlencode("ID: {$id} の公開／非公開を切り替えました。"));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                deleteSystemInfoRecord($pdo, $id);
                header("Location: system_info_maintenance.php?msg=" . urlencode("ID: {$id} のインフォメーションを削除しました。"));
                exit;
            }
        } elseif ($action === 'sync_all') {
            syncSystemInfoToJsonAndHtml($pdo);
            header("Location: system_info_maintenance.php?msg=" . urlencode("JSONおよび index.html への手動再同期が完了しました。"));
            exit;
        }
    } catch (Exception $e) {
        $message = "エラー: " . $e->getMessage();
        $is_error = true;
    }
}

// GETメッセージ
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
}
if (isset($_GET['error'])) {
    $message = trim($_GET['error']);
    $is_error = true;
}

// 編集対象の取得
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_row = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM m_system_info WHERE id = :id");
    $stmt->execute([':id' => $edit_id]);
    $edit_row = $stmt->fetch();
}

// 全インフォメーション取得（非公開含む）
$all_infos = getSystemInformations($pdo, true);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>修正インフォメーションマスターメンテ - 院内内視鏡システム</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            max-width: 1060px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        /* 統合ヘッダーナビゲーション */
        .master-hub-nav {
            display: flex;
            gap: 6px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            align-items: center;
            flex-wrap: wrap;
        }
        .master-hub-title {
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 6px;
        }
        .hub-tab {
            padding: 6px 13px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 6px;
            text-decoration: none;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .hub-tab:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .hub-tab.active {
            background: #0284c7;
            color: #fff;
            border-color: #0284c7;
            box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
        }
        .hub-tab.home-link {
            margin-left: auto;
            background: #fff;
            border-color: #cbd5e1;
        }
        .hub-tab.home-link:hover {
            background: #f1f5f9;
        }

        /* ページヘッダー */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .page-title {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .page-table-tag {
            font-size: 11px;
            font-weight: 600;
            font-family: ui-monospace, monospace;
            background: #f1f5f9;
            color: #475569;
            padding: 2px 7px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
        }

        /* メッセージバナー */
        .msg {
            padding: 10px 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .msg-success {
            background-color: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .msg-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        /* ライブプレビュー */
        .preview-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 22px;
        }
        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .preview-title {
            font-size: 13px;
            font-weight: 800;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* セクション見出し */
        .sec-title {
            font-size: 15px;
            font-weight: 800;
            color: #1e293b;
            border-left: 4px solid #0284c7;
            padding-left: 8px;
            margin: 0 0 12px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* 入力フォームカード */
        .form-card {
            background: #ffffff;
            border: 1.5px solid #93c5fd;
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }
        .form-card.edit-mode {
            border-color: #f59e0b;
            background: #fffdfa;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-group label {
            font-weight: 700;
            font-size: 12px;
            color: #334155;
        }
        .form-group label .req {
            color: #dc2626;
            margin-left: 2px;
        }
        .input-text, .input-select, .input-textarea {
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
        }
        .input-text:focus, .input-select:focus, .input-textarea:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.2);
        }
        .input-textarea {
            width: 100%;
            height: 90px;
            line-height: 1.5;
            resize: vertical;
        }

        /* ボタン */
        .btn {
            padding: 7px 16px;
            border-radius: 5px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover { background: #15803d; }
        .btn-warning { background: #d97706; color: #fff; }
        .btn-warning:hover { background: #b45309; }
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-secondary:hover { background: #475569; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm {
            padding: 3px 8px;
            font-size: 11.5px;
            border-radius: 4px;
        }

        /* テーブル */
        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin-top: 8px;
            font-size: 12.5px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 9px 12px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 800;
        }
        tr:hover { background-color: #f8fafc; }
        tr.is-inactive {
            opacity: 0.6;
            background-color: #f8fafc;
        }

        .badge-preview {
            display: inline-block;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 12px;
        }
        .badge-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-primary { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .badge-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-warning { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .badge-danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

        .items-preview-list {
            margin: 0;
            padding-left: 18px;
            color: #475569;
            font-size: 11.5px;
            line-height: 1.4;
        }
    </style>
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css?v=<?= filemtime(__DIR__ . '/assets/css/onokai_flatpickr.css') ?>">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>
</head>
<body <?= getBodyEnvClass() ?>>
<?= renderLocalEnvBanner() ?>

<div class="container">

    <!-- 統合マスタヘッダーナビゲーション -->
    <nav class="master-hub-nav" aria-label="マスタ管理ナビゲーション">
        <span class="master-hub-title">📋 院内マスタ管理:</span>
        <a href="slot_master_maintenance.php" class="hub-tab">⏱️ 検査枠マスタ</a>
        <a href="exam_master_maintenance.php" class="hub-tab">🔬 検査種別マスタ</a>
        <a href="master_maintenance.php" class="hub-tab">🔧 汎用選択肢マスタ</a>
        <a href="printer_master_maintenance.php" class="hub-tab">🖨️ プリンターマスタ</a>
        <a href="system_info_maintenance.php" class="hub-tab active">📢 修正インフォメーション</a>
        <a href="furiwake_daymemo.php" class="hub-tab">📝 日別メモ科振分</a>
        <a href="index.php" class="hub-tab home-link">🏠 ポータルへ</a>
    </nav>

    <div class="page-header">
        <h2 class="page-title">
            <span>📢 修正インフォメーションマスターメンテ</span>
            <code class="page-table-tag" title="PostgreSQL 対象テーブル">TABLE: m_system_info</code>
            <code class="page-table-tag" style="background:#e0f2fe; color:#0369a1; border-color:#bae6fd;" title="自動同期ファイル">AUTO-SYNC: index.html</code>
        </h2>
        <form action="system_info_maintenance.php" method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn-secondary btn-sm" title="データベースの内容を config/system_info.json および index.html に再同期します">
                🔄 全ファイル手動再同期
            </button>
        </form>
    </div>

    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error ? 'msg-error' : 'msg-success' ?>">
            <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" onclick="this.parentElement.style.display='none';" style="background:none; border:none; cursor:pointer; font-weight:bold; font-size:14px; color:inherit;">×</button>
        </div>
    <?php endif; ?>

    <!-- ライブプレビュー（ポータルにどう表示されるか） -->
    <div class="preview-box">
        <div class="preview-header">
            <span class="preview-title">
                <span>👁️ ポータル画面（index.php）での実際の表示プレビュー:</span>
            </span>
            <span style="font-size: 11.5px; color: #64748b;">
                ※ 一番上に公開設定されている最新インフォメーションがメインバナーとして配信されます
            </span>
        </div>
        <?= renderSystemInfoBanner() ?>
    </div>

    <!-- 登録・編集フォーム -->
    <div class="form-card <?= $edit_row ? 'edit-mode' : '' ?>" id="input-form">
        <h3 class="sec-title" style="border-left-color: <?= $edit_row ? '#f59e0b' : '#0284c7' ?>;">
            <span><?= $edit_row ? "✏️ インフォメーション編集（ID: {$edit_row['id']}）" : "✨ 新規インフォメーション登録" ?></span>
            <?php if ($edit_row): ?>
                <a href="system_info_maintenance.php" class="btn btn-secondary btn-sm">キャンセル</a>
            <?php endif; ?>
        </h3>

        <form action="system_info_maintenance.php" method="POST">
            <input type="hidden" name="action" value="save">
            <?php if ($edit_row): ?>
                <input type="hidden" name="id" value="<?= htmlspecialchars($edit_row['id'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label>日付 (YYYY-MM-DD)<span class="req">*</span></label>
                    <input type="text" id="info_date" name="info_date" class="onokai-date-picker" data-compact="true" data-dark="true" required autocomplete="off" value="<?= htmlspecialchars($edit_row['info_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label>バージョン表記 (例: v1101, ver1030)</label>
                    <input type="text" name="version_tag" class="input-text" placeholder="例: v1101" value="<?= htmlspecialchars($edit_row['version_tag'] ?? 'v1101', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label>バッジ文言 (例: 重要・本番稼働開始, 印刷DX)</label>
                    <input type="text" name="badge_label" class="input-text" placeholder="例: 重要・本番稼働開始" value="<?= htmlspecialchars($edit_row['badge_label'] ?? '新機能・改善', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label>バッジ配色</label>
                    <select name="badge_cls" class="input-select">
                        <?php
                        $badges = [
                            'badge-success'   => '🟢 成功・重要 (グリーン)',
                            'badge-primary'   => '🔵 注目・新機能 (ブルー)',
                            'badge-secondary' => '⚪ 標準・改善 (スレートグレー)',
                            'badge-warning'   => '🟡 注意・予告 (イエロー)',
                            'badge-danger'    => '🔴 緊急・停止 (レッド)'
                        ];
                        $curr_cls = $edit_row['badge_cls'] ?? 'badge-primary';
                        foreach ($badges as $val => $lbl):
                        ?>
                            <option value="<?= $val ?>" <?= $curr_cls === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label>タイトル見出し<span class="req">*</span></label>
                <input type="text" name="title" class="input-text" required placeholder="例: 🎉 内視鏡予約・運用管理システム v1101 本番稼働を開始しました" value="<?= htmlspecialchars($edit_row['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label>概要サマリー（1〜2行の要約文）</label>
                <input type="text" name="summary" class="input-text" placeholder="例: 現場運用開始に向けたUI大幅スリム化、コントロール統合、空き枠検索の強化を実施しました。" value="<?= htmlspecialchars($edit_row['summary'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label>改修・変更項目（箇条書き。1行に1項目を入力してください）</label>
                <textarea name="detail_items" class="input-textarea" placeholder="【機能名】改修内容の説明文&#10;【機能名2】改修内容の説明文2"><?= htmlspecialchars($edit_row['detail_items'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <span style="font-size: 11px; color: #64748b;">※ 改行ごとに <code>&lt;li&gt;</code> 項目として整形され、ポータルおよび index.html に自動配信されます。</span>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0;">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div class="form-group" style="flex-direction: row; align-items: center; gap: 6px;">
                        <label style="margin: 0; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" <?= (!isset($edit_row) || !empty($edit_row['is_active'])) ? 'checked' : '' ?>>
                            <strong style="color: #047857;">📢 公開する（現場画面に表示）</strong>
                        </label>
                    </div>
                    <div class="form-group" style="flex-direction: row; align-items: center; gap: 6px;">
                        <label style="margin: 0;">表示優先順:</label>
                        <input type="number" name="display_order" value="<?= htmlspecialchars($edit_row['display_order'] ?? '10', ENT_QUOTES, 'UTF-8') ?>" style="width: 60px; padding: 4px 6px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;">
                        <span style="font-size: 11px; color: #64748b;">(小 ➔ 優先)</span>
                    </div>
                </div>

                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn <?= $edit_row ? 'btn-warning' : 'btn-success' ?>" style="font-size: 13px; padding: 8px 20px;">
                        <?= $edit_row ? '💾 更新して同期' : '✨ 登録して現場へ同期' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- 登録済みインフォメーション一覧 -->
    <h3 class="sec-title">
        <span>📋 登録済みインフォメーション一覧（全<?= count($all_infos) ?>件）</span>
        <span style="font-size: 12px; font-weight: normal; color: #64748b;">
            ※ 順序は「優先順 ➔ 日付降順」
        </span>
    </h3>

    <table>
        <thead>
            <tr>
                <th style="width: 45px; text-align: center;">状態</th>
                <th style="width: 50px; text-align: center;">順位</th>
                <th style="width: 90px;">日付</th>
                <th style="width: 65px;">Ver</th>
                <th style="width: 120px;">バッジ</th>
                <th>タイトル / 概要 / 改修詳細</th>
                <th style="width: 130px; text-align: center;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($all_infos)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #64748b; padding: 20px;">
                        インフォメーションが登録されていません。上のフォームから登録してください。
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($all_infos as $info): ?>
                    <tr class="<?= empty($info['is_active']) ? 'is-inactive' : '' ?>">
                        <td style="text-align: center;">
                            <?php if (!empty($info['is_active'])): ?>
                                <span title="公開中（ポータル等に表示）" style="color: #16a34a; font-size: 14px;">🟢</span>
                            <?php else: ?>
                                <span title="非公開（現場には非表示）" style="color: #94a3b8; font-size: 14px;">⚪</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; font-weight: 700; color: #64748b;">
                            <?= htmlspecialchars($info['display_order'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td style="font-weight: 700; white-space: nowrap;">
                            <?= htmlspecialchars($info['date'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td style="font-family: ui-monospace, monospace; font-weight: 700; color: #0369a1;">
                            <?= htmlspecialchars($info['ver'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <span class="badge-preview <?= htmlspecialchars($info['badge_cls'] ?? 'badge-primary', ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($info['badge'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <strong style="color: #0f172a; font-size: 13px; display: block; margin-bottom: 2px;">
                                <?= htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                            <?php if (!empty($info['summary'])): ?>
                                <div style="color: #475569; margin-bottom: 4px; font-size: 12px;">
                                    <?= htmlspecialchars($info['summary'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($info['items'])): ?>
                                <ul class="items-preview-list">
                                    <?php foreach ($info['items'] as $it): ?>
                                        <li><?= htmlspecialchars($it, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 4px; justify-content: center; flex-wrap: wrap;">
                                <a href="system_info_maintenance.php?edit_id=<?= $info['id'] ?>#input-form" class="btn btn-primary btn-sm" title="内容を編集">
                                    ✏️ 編集
                                </a>
                                <form action="system_info_maintenance.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= $info['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="<?= !empty($info['is_active']) ? '非公開にする' : '公開にする' ?>">
                                        <?= !empty($info['is_active']) ? '非公開' : '公開' ?>
                                    </button>
                                </form>
                                <form action="system_info_maintenance.php" method="POST" style="margin: 0;" onsubmit="return confirm('インフォメーション「<?= htmlspecialchars(addslashes($info['title']), ENT_QUOTES, 'UTF-8') ?>」を完全に削除しますか？');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $info['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="削除">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</div>

<script>
if (window.OnokaiDatePicker) {
    OnokaiDatePicker.bindControl('#info_date', {
        compact: true,
        dark: true
    });
}
</script>
</body>
</html>
