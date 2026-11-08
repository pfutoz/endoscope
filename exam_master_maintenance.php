<?php
/**
 * 生成日時: 2026-10-26 10:00:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\exam_master_maintenance.php
 * ファイル名: exam_master_maintenance.php
 * 
 * 検査種別マスターメンテナンス (m_exam_type_group 専用管理モジュール)
 * 概要:
 *   - 検査種別（GIF、TCF、エコー、ポリペク等）の名称、大分類グループ（上部/下部/エコー/その他）、
 *     予約画面プルダウン表示順、選択肢表示フラグを1箇所で完全一元管理。
 *   - 監査ログ（t_master_audit_log）への自動差分記録、ワンクリックUndo（復元）対応。
 * 修正履歴:
 *   - 2026-10-26: 初版新設（m_exam_type_group 完全一本化に伴う専用UI）
 *   - 2026-11-01: 統合ヘッダーナビに「📢 修正インフォメーション」タブを追加
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host={$host};dbname={$dbname};";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

require_once __DIR__ . '/lib/master_audit_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/env_helper.php';

$op_mode = isset($_GET['op_mode']) && $_GET['op_mode'] === 'add' ? 'add' : 'edit';
$message = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$is_error = isset($_GET['error']) ? (int)$_GET['error'] : 0;

// m_exam_type_group 全レコード取得（表示順・ID順）
$items = $pdo->query("
    SELECT * 
    FROM m_exam_type_group 
    ORDER BY is_selectable DESC, display_order ASC, id ASC
")->fetchAll();

// 監査ログ（直近15件）
$recent_logs = getMasterAuditLogs($pdo, 'exam_type_group', 15);

// 集計
$total_count = count($items);
$selectable_count = 0;
foreach ($items as $it) {
    if (!empty($it['is_selectable'])) $selectable_count++;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔬 検査種別マスターメンテナンス | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            margin: 0;
            padding: 20px;
            font-size: 13px;
        }
        .container {
            max-width: 1040px;
            margin: 0 auto;
            background: #fff;
            padding: 24px 28px;
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07);
            border: 1px solid #cbd5e1;
        }

        /* 3大マスタ統合ヘッダーナビ */
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
            padding: 6px 14px;
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

        /* タイトルバー */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .page-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .page-badges {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .count-pill {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }

        /* 案内ガイドボックス */
        .guide-banner {
            background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%);
            border: 1.5px solid #7dd3fc;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 12.5px;
            line-height: 1.6;
            color: #0c4a6e;
        }
        .guide-banner-title {
            font-weight: 800;
            font-size: 13.5px;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: #0369a1;
        }

        /* グループバッジ表示定義 */
        .badge-gif   { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
        .badge-tcf   { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-echo  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-other { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .group-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        /* モード切替タブ */
        .mode-tabs {
            display: flex;
            gap: 6px;
            border-bottom: 2px solid #cbd5e1;
            margin-bottom: 18px;
        }
        .mode-tab {
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            color: #64748b;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            margin-bottom: -2px;
            transition: all 0.15s;
        }
        .mode-tab:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .mode-tab.active {
            background: #fff;
            color: #0284c7;
            border-top: 3px solid #0284c7;
            border-bottom: 2px solid #fff;
            padding-bottom: 9px;
            z-index: 2;
        }

        /* テーブル */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            background: #fff;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            white-space: nowrap;
        }
        tr:hover {
            background-color: #f8fafc;
        }
        tr.not-selectable {
            background-color: #fafaf9;
            opacity: 0.9;
        }

        /* フォーム部品 */
        .input-text {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12.5px;
        }
        .input-order {
            width: 70px;
            text-align: center;
            padding: 6px 4px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12.5px;
            font-weight: 700;
        }
        .select-group {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12.5px;
            font-weight: 700;
            width: 100%;
        }

        /* ボタン */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-secondary:hover { background: #475569; }

        /* メッセージ表示 */
        .msg {
            padding: 10px 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .msg-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* 追加カード */
        .add-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px 24px;
        }
        .form-row {
            margin-bottom: 16px;
        }
        .form-label {
            font-weight: 700;
            font-size: 12px;
            color: #334155;
            margin-bottom: 6px;
            display: block;
        }

        /* 監査ログテーブル */
        .audit-section {
            margin-top: 36px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        .badge-insert { background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; }
        .badge-update { background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; }
        .badge-delete { background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; }
    </style>
</head>
<body <?= getBodyEnvClass() ?>>
<?= renderLocalEnvBanner() ?>

<div class="container">

    <!-- 3大マスタ統合ヘッダーナビゲーション -->
    <nav class="master-hub-nav" aria-label="マスタ管理ナビゲーション">
        <span class="master-hub-title">📋 院内マスタ管理:</span>
        <a href="slot_master_maintenance.php" class="hub-tab">⏱️ 検査枠マスタ</a>
        <a href="exam_master_maintenance.php" class="hub-tab active">🔬 検査種別マスタ</a>
        <a href="master_maintenance.php" class="hub-tab">🔧 汎用選択肢マスタ</a>
        <a href="printer_master_maintenance.php" class="hub-tab">🖨️ プリンターマスタ</a>
        <a href="system_info_maintenance.php" class="hub-tab">📢 修正インフォメーション</a>
        <a href="furiwake_daymemo.php" class="hub-tab">📝 日別メモ科振分</a>
        <a href="index.php" class="hub-tab home-link">🏠 ポータルへ</a>
    </nav>

    <!-- ページタイトル -->
    <div class="page-header">
        <h1 class="page-title">
            <span>🔬 検査種別マスターメンテナンス</span>
            <code style="font-size: 11px; font-weight: 600; font-family: ui-monospace, monospace; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; border: 1px solid #cbd5e1;" title="PostgreSQL 対象テーブル">TABLE: m_exam_type_group</code>
        </h1>
        <div class="page-badges">
            <span class="count-pill">全登録: <?= $total_count ?>件</span>
            <span class="count-pill" style="background:#ecfdf5; border-color:#86efac; color:#15803d;">予約選択肢: <?= $selectable_count ?>件</span>
        </div>
    </div>

    <!-- 案内ガイドバナー -->
    <div class="guide-banner">
        <div class="guide-banner-title">
            <span>✨ 【m_exam_type_group 完全一本化】検査種別とグループの一元管理</span>
        </div>
        <div>
            本画面で登録・修正した検査種別は、予約登録画面（<code>modal_edit.php</code>）のプルダウン、月間カレンダー、当日ノート、日次印刷、進捗モニター、空き枠検索、統計集計の<strong>全システムに1秒で自動連動</strong>します。<br>
            ・<strong>大分類グループ:</strong> 検査枠の自動判定（上部/下部/エコー）、テーマカラー、前処置室・下剤要否、集計先を決定します。<br>
            ・<strong>予約選択肢:</strong> 「表示」にすると予約プルダウンに現れます。「非表示」にするとプルダウンには出ず、過去データや表記揺れ（例: 胃がん検診）の自動判定専用として安全に保持されます。
        </div>
    </div>

    <!-- メッセージ表示 -->
    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error === 1 ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- モード切替タブ -->
    <div class="mode-tabs">
        <a href="?op_mode=edit" class="mode-tab <?= $op_mode === 'edit' ? 'active' : '' ?>">✏️ 登録一覧・一括編集</a>
        <a href="?op_mode=add" class="mode-tab <?= $op_mode === 'add' ? 'active' : '' ?>">✨ 新規検査種別を追加</a>
    </div>

    <?php if ($op_mode === 'edit'): ?>
        <!-- 一括編集モード -->
        <form action="action_exam_master.php" method="POST">
            <input type="hidden" name="action" value="bulk_update">

            <table>
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">ID</th>
                        <th style="min-width: 140px;">検査種別名 (表示名)</th>
                        <th style="width: 160px; text-align: center;">大分類グループ</th>
                        <th style="width: 100px; text-align: center;">予約選択肢</th>
                        <th style="width: 90px; text-align: center;">表示順</th>
                        <th style="width: 80px; text-align: center;">状態</th>
                        <th style="width: 70px; text-align: center;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $row): 
                        $grp = (string)($row['slot_group'] ?? 'OTHER');
                        $is_sel = !empty($row['is_selectable']);
                        $is_act = !empty($row['is_active']);
                        $cls_grp = strtolower($grp);
                    ?>
                        <tr class="<?= $is_sel ? '' : 'not-selectable' ?>">
                            <td style="text-align: center; color: #64748b; font-weight: bold;">
                                <?= $row['id'] ?>
                                <input type="hidden" name="items[<?= $row['id'] ?>][id]" value="<?= $row['id'] ?>">
                            </td>
                            <td>
                                <input type="text" name="items[<?= $row['id'] ?>][exam_type]" value="<?= htmlspecialchars($row['exam_type'], ENT_QUOTES, 'UTF-8') ?>" class="input-text" required style="font-weight: 700;">
                            </td>
                            <td style="text-align: center;">
                                <select name="items[<?= $row['id'] ?>][slot_group]" class="select-group">
                                    <option value="GIF" <?= $grp === 'GIF' ? 'selected' : '' ?>>🔵 GIF (上部・胃カメラ)</option>
                                    <option value="TCF" <?= $grp === 'TCF' ? 'selected' : '' ?>>🟢 TCF (下部・大腸内視鏡)</option>
                                    <option value="ECHO" <?= $grp === 'ECHO' ? 'selected' : '' ?>>🟡 ECHO (エコー・超音波)</option>
                                    <option value="OTHER" <?= $grp === 'OTHER' ? 'selected' : '' ?>>⚪ OTHER (その他)</option>
                                </select>
                            </td>
                            <td style="text-align: center;">
                                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: bold;">
                                    <input type="checkbox" name="items[<?= $row['id'] ?>][is_selectable]" value="1" <?= $is_sel ? 'checked' : '' ?>>
                                    <?= $is_sel ? '<span style="color:#059669;">🟢 表示</span>' : '<span style="color:#94a3b8;">⚪ 判定専用</span>' ?>
                                </label>
                            </td>
                            <td style="text-align: center;">
                                <input type="number" name="items[<?= $row['id'] ?>][display_order]" value="<?= (int)($row['display_order'] ?? 100) ?>" class="input-order" required>
                            </td>
                            <td style="text-align: center;">
                                <label style="cursor: pointer; font-size: 11px; font-weight: bold;">
                                    <input type="checkbox" name="items[<?= $row['id'] ?>][is_active]" value="1" <?= $is_act ? 'checked' : '' ?>>
                                    <?= $is_act ? '<span style="color:#0284c7;">有効</span>' : '<span style="color:#ef4444;">無効</span>' ?>
                                </label>
                            </td>
                            <td style="text-align: center;">
                                <a href="action_exam_master.php?action=delete&id=<?= $row['id'] ?>" class="btn btn-danger" style="padding: 3px 8px; font-size: 11px;" onclick="return confirm('検査種別「<?= htmlspecialchars($row['exam_type'], ENT_QUOTES, 'UTF-8') ?>」を削除しますか？\n※過去の予約データに登録されている場合は「無効」または「非表示」にすることを推奨します。');">削除</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 16px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 12px; color: #64748b;">
                    ※「表示順」の数値を変更して一括保存すると、予約画面や月間カレンダーの選択肢の並び順が即座に切り替わります。
                </span>
                <button type="submit" class="btn btn-primary" style="padding: 9px 22px; font-size: 13px;">
                    ⚡ 修正内容を一括保存する
                </button>
            </div>
        </form>

    <?php else: ?>
        <!-- 新規追加モード -->
        <div class="add-card">
            <h2 style="font-size: 15px; font-weight: 800; color: #0284c7; margin: 0 0 16px 0;">
                ➕ 新しい検査種別を登録
            </h2>
            <form action="action_exam_master.php" method="POST">
                <input type="hidden" name="action" value="insert">

                <div class="form-row">
                    <label class="form-label" for="exam_type">検査種別名 (画面表示名): <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="exam_type" name="exam_type" class="input-text" style="max-width: 400px; font-size: 14px; font-weight: 700;" placeholder="例: 経鼻GIF, 大腸ESD, カプセル内視鏡..." required autofocus>
                </div>

                <div class="form-row">
                    <label class="form-label" for="slot_group">大分類グループ (枠判定・集計・配色): <span style="color:#ef4444;">*</span></label>
                    <select id="slot_group" name="slot_group" class="select-group" style="max-width: 320px;">
                        <option value="GIF">🔵 GIF (上部・胃カメラ系)</option>
                        <option value="TCF" selected>🟢 TCF (下部・大腸内視鏡系)</option>
                        <option value="ECHO">🟡 ECHO (エコー・超音波系)</option>
                        <option value="OTHER">⚪ OTHER (その他検査)</option>
                    </select>
                    <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                        ※下部(TCF)を選ぶと予約画面で「前処置室(マイトイレ)」および「下剤」の選択欄が自動で有効になります。
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label" for="display_order">表示順 (小さいほど先頭):</label>
                    <input type="number" id="display_order" name="display_order" class="input-order" value="50" style="width: 100px;" required>
                </div>

                <div class="form-row">
                    <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px;">
                        <input type="checkbox" name="is_selectable" value="1" checked>
                        <span>予約入力画面のプルダウン選択肢として表示する</span>
                    </label>
                    <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                        ※チェックを外すとプルダウンには表示されず、表記揺れや過去データの自動グループ判定用として安全に保持されます。
                    </div>
                </div>

                <div style="margin-top: 22px;">
                    <button type="submit" class="btn btn-success" style="padding: 9px 24px; font-size: 13px;">
                        ➕ 検査種別を新しく追加する
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- 変更履歴・復元（Undo）セクション -->
    <div class="audit-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div>
                <strong style="font-size: 13.5px; color: #1e293b;">📜 変更履歴・ワンクリック復元 (直近15件)</strong>
                <p style="margin: 2px 0 0 0; font-size: 11.5px; color: #64748b;">検査種別の変更・追加・削除履歴を記録しています。誤った操作は「↩️ 元に戻す」で直前の状態に復元可能です。</p>
            </div>
        </div>

        <?php if (empty($recent_logs)): ?>
            <p style="color: #94a3b8; font-size: 12px; text-align: center; padding: 16px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0;">
                記録された変更履歴はありません。
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 130px;">日時</th>
                        <th style="width: 70px; text-align: center;">操作</th>
                        <th>変更内容（差分サマリ）</th>
                        <th style="width: 90px; text-align: center;">復元</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): 
                        $badge_cls = 'badge-insert';
                        $lbl = '追加';
                        if ($log['action_type'] === 'update') {
                            $badge_cls = 'badge-update';
                            $lbl = '更新';
                        } elseif ($log['action_type'] === 'delete') {
                            $badge_cls = 'badge-delete';
                            $lbl = '削除';
                        }
                    ?>
                        <tr>
                            <td style="font-size: 11.5px; color: #64748b;"><?= htmlspecialchars(substr($log['created_at'], 0, 19), ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="text-align: center;"><span class="<?= $badge_cls ?>"><?= $lbl ?></span></td>
                            <td style="font-size: 12px;"><?= htmlspecialchars($log['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="text-align: center;">
                                <?php if (!empty($log['is_undone'])): ?>
                                    <span style="color: #94a3b8; font-size: 11px; text-decoration: line-through;">復元済</span>
                                <?php else: ?>
                                    <a href="action_exam_master.php?action=undo&log_id=<?= $log['log_id'] ?>" class="btn btn-secondary" style="padding: 2px 6px; font-size: 11px;" onclick="return confirm('この操作を取り消して直前の状態に戻しますか？');">↩️ 復元</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
