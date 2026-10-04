<?php
/**
 * progress_monitor.php
 * 内視鏡室 当日進捗ステータスモニター (電子ホワイトボード)
 * 
 * 生成日時: 2026-09-09 20:30:00 JST
 * 改修履歴:
 *   - 2026-09-29 (第4弾: 多数患者俯瞰・スクロール削減・インデックス絞り込み):
 *       1. 3つの表示モード切替（🔲 タイル ／ 📑 高密度表 ／ 📄 標準表）新設、職種別初期モード連動＆端末自動記憶
 *       2. タイル表示モード（予約枠時間順マトリクス・4〜6列グリッド、現在ステータス・タイマー・重要アラート集約）新設
 *       3. タイルクリックで高密度表へ自動ジャンプ＆該当行スムーズスクロール・パルスハイライト連携
 *       4. 動線サマリーバーを常時1行の横並びスリムインライン表示に刷新（ステータスワンクリック絞り込み対応）
 *       5. 画面右端に縦型「五十音インデックスバー（[全] [あ] [か] [さ]…）」新設、文字入力不要のワンタップ絞り込み
 *       6. 院内「🏃 滞在中のみ」ワンクリックトグル新設
 *   - 2026-09-28 (第3弾):
 *       1. 新検査種別「浣腸CF」の管理に対応（完全な腸洗浄なし・浣腸のみ・胃カメラGIF枠/TCF枠での消化）
 *       2. 検査種別フィルタータブを4タブ化（🔬全内視鏡 / 🟢大腸系(TCF) / 🟣浣腸CF / 🔵胃カメラ）
 *       3. サマリーバーに「🟣 浣腸CF」集計カードを新設
 *       4. 浣腸CF専用バッジ (exam-chip-enema: 🟣) を導入、前処置(マイトイレ案内)スキップ・1➔3直接進捗に対応
 *       5. ヘッダーに「➕ 当日・緊急追加」ボタンを設置、当日飛び込み検査の即時入力・リアルタイム反映を完全支援
 *   - 2026-09-28 (第2弾):
 *       1. リカバリタイマー完了時の「画面全体パルス点滅」＆「ピピッ×3回 音声アラート (15秒周期)」＆「大型確認モーダル」を実装
 *       2. 補助看護師（補助看）ビューを含む全職種画面でタイマー完了を自動検知・即時操作可能に連携
 *       3. ポップアップから直接「覚醒確認・説明待ちへ」「あと5分延長」「あと10分延長」「閉じる」操作に対応
 *   - 2026-09-28 (第1弾): リカバリ目標時間の個別自由調整、タイマー併記、スコープ洗浄完了の誤クリック取り消し
 *   - 2026-09-26:
 *       - エコー(ECHO)完全除外
 *       - GIF(胃カメラ)動線最適化（前処置なし・1➔3直接進捗）
 *       - セデーション(鎮静剤/プロポフォール)有無管理・リカバリスキップ連動
 *       - 検査種別フィルター(全内視鏡 / 大腸系 / 胃カメラ)常設
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/progress_db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

initProgressTables($pdo);

$active_doctors = [];
try {
    $doc_stmt = $pdo->query("SELECT doctor_name FROM m2_doctor WHERE is_active = TRUE ORDER BY display_order ASC, id ASC");
    $active_doctors = $doc_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$date_str = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) ? $_GET['date'] : date('Y-m-d');
$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'ALL';
if (!in_array($dept, ['ALL', 'PROCT', 'GASTRO'], true)) {
    $dept = 'ALL';
}

$exam_filter = isset($_GET['exam_filter']) ? trim((string)$_GET['exam_filter']) : 'ALL';
if (!in_array($exam_filter, ['ALL', 'CF', 'ENEMA', 'GIF'], true)) {
    $exam_filter = 'ALL';
}

$today_str = date('Y-m-d');
$is_today = ($date_str === $today_str);

$prev_date = date('Y-m-d', strtotime($date_str . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date_str . ' +1 day'));
$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
$dow = $week_ja[(int)date('w', strtotime($date_str))];

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>当日進捗ステータスモニター | 内視鏡室電子ホワイトボード</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-app: #0f172a;
            --bg-surface: #1e293b;
            --bg-surface-elevated: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-sub: #64748b;
            --border-color: #334155;
            --border-subtle: #1e293b;
            
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --purple: #8b5cf6;
            --indigo: #6366f1;

            --radius-sm: 4px;
            --radius-md: 6px;
            --radius-lg: 10px;
            --radius-full: 9999px;

            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background-color: var(--bg-app);
            color: var(--text-main);
            min-height: 100vh;
            line-height: 1.4;
            font-size: 13px;
        }

        /* ヘッダー */
        .board-header {
            background-color: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            padding: 10px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-md);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 8px;
        }

        .header-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .header-title {
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #ffffff;
            letter-spacing: -0.01em;
        }

        .live-badge {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            animation: pulse-live 2s infinite;
        }

        .live-dot {
            width: 6px;
            height: 6px;
            background-color: #ffffff;
            border-radius: 50%;
        }

        @keyframes pulse-live {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(0.96); }
            100% { opacity: 1; transform: scale(1); }
        }

        /* 日付・診療科・検査種別ナビ */
        .controls-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .date-stepper {
            display: inline-flex;
            align-items: center;
            background: var(--bg-app);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .date-btn {
            background: transparent;
            color: var(--text-main);
            border: none;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
        }

        .date-btn:hover { background: var(--bg-surface-elevated); }

        .date-current {
            padding: 6px 14px;
            font-weight: 800;
            font-size: 14px;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .dept-tabs, .role-tabs, .exam-tabs {
            display: inline-flex;
            background: var(--bg-app);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 2px;
            gap: 2px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 5px 12px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .tab-btn:hover {
            color: var(--text-main);
            background: var(--bg-surface);
        }

        .tab-btn.active {
            background: var(--primary);
            color: #ffffff;
            box-shadow: var(--shadow-sm);
        }

        .tab-btn.active-proct { background: #0284c7; color: #fff; }
        .tab-btn.active-gastro { background: #059669; color: #fff; }

        /* 自動更新 & ツール */
        .sync-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .auto-refresh-box {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            background: var(--bg-app);
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: var(--radius-md);
        }

        .select-dark {
            background: var(--bg-surface);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            outline: none;
            cursor: pointer;
        }

        .refresh-progress {
            width: 40px;
            height: 4px;
            background: #334155;
            border-radius: 2px;
            overflow: hidden;
        }

        .refresh-bar {
            height: 100%;
            background: var(--primary);
            width: 100%;
            transform-origin: left;
        }

        .btn-header {
            background: var(--bg-surface-elevated);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: var(--radius-md);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s;
        }

        .btn-header:hover {
            background: #475569;
            color: #ffffff;
        }

        /* ============================================================
           動線サマリーバー (1行スリムインライン化)
        ============================================================ */
        .summary-bar-inline {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            background: #0b1329;
            border-bottom: 1px solid var(--border-color);
            overflow-x: auto;
            white-space: nowrap;
            scrollbar-width: thin;
        }

        .summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            padding: 3px 9px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s ease;
        }

        .summary-pill:hover {
            background: var(--bg-surface-elevated);
            border-color: #475569;
            transform: translateY(-1px);
        }

        .summary-pill.active-filter {
            border-color: #38bdf8 !important;
            background: rgba(56, 189, 248, 0.2) !important;
            box-shadow: 0 0 8px rgba(56, 189, 248, 0.4);
        }

        .summary-pill-total {
            background: rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.4);
        }

        .summary-pill-alert {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.15);
        }

        .summary-pill.active-filter.summary-pill-alert {
            border-color: #ef4444 !important;
            background: rgba(239, 68, 68, 0.3) !important;
            box-shadow: 0 0 8px rgba(239, 68, 68, 0.5);
        }

        .summary-divider {
            width: 1px;
            height: 18px;
            background: var(--border-color);
            margin: 0 3px;
            flex-shrink: 0;
        }

        .pill-label {
            font-size: 11px;
            color: var(--text-muted);
        }

        .pill-num {
            font-size: 13px;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            color: #ffffff;
        }

        .pill-num.text-danger { color: #f87171; }
        .pill-num.text-warning { color: #fbbf24; }
        .pill-num.text-success { color: #34d399; }
        .pill-num.text-primary { color: #60a5fa; }
        .pill-num.text-purple { color: #c084fc; }
        .pill-num.text-indigo { color: #818cf8; }

        /* ============================================================
           表示モード切替タブ ＆ 滞在中トグル
        ============================================================ */
        .view-mode-tabs {
            display: inline-flex;
            background: var(--bg-app);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 2px;
            gap: 2px;
            margin-left: 10px;
        }

        .btn-view-mode {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-view-mode:hover {
            color: var(--text-main);
            background: var(--bg-surface);
        }

        .btn-view-mode.active {
            background: #0284c7;
            color: #ffffff;
            box-shadow: var(--shadow-sm);
        }

        .btn-active-stay {
            background: var(--bg-app);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 5px 12px;
            border-radius: var(--radius-md);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 6px;
        }

        .btn-active-stay:hover {
            color: var(--text-main);
            background: var(--bg-surface);
        }

        .btn-active-stay.active {
            background: #059669;
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.4);
        }

        /* 絞り込み中情報バー */
        .active-filter-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(14, 116, 144, 0.2);
            border: 1px solid #06b6d4;
            border-radius: var(--radius-md);
            padding: 6px 14px;
            margin-bottom: 12px;
            font-size: 12px;
            color: #e0f2fe;
            animation: modal-slide-in 0.15s ease-out;
        }

        .btn-clear-filter {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 2px 10px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-clear-filter:hover {
            background: #ef4444;
            color: #ffffff;
        }

        /* ============================================================
           画面右端固定 五十音インデックスバー
        ============================================================ */
        .kana-index-bar {
            position: fixed;
            right: 6px;
            top: 55%;
            transform: translateY(-50%);
            z-index: 150;
            display: flex;
            flex-direction: column;
            gap: 2px;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(8px);
            border: 1px solid var(--border-color);
            padding: 5px 3px;
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            user-select: none;
        }

        .kana-btn {
            width: 24px;
            height: 23px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            border-radius: 4px;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all 0.12s ease;
        }

        .kana-btn:hover {
            background: var(--bg-surface-elevated);
            color: #38bdf8;
            transform: scale(1.15);
        }

        .kana-btn.active {
            background: var(--primary);
            color: #ffffff;
            font-weight: 900;
            box-shadow: 0 0 6px rgba(59, 130, 246, 0.6);
        }

        /* ============================================================
           タイルグリッド表示モード (🔲 タイル)
        ============================================================ */
        .tile-grid-view {
            padding-bottom: 24px;
        }

        .patient-tile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 10px;
        }

        .patient-tile-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 9px 12px;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            display: flex;
            flex-direction: column;
            gap: 5px;
            position: relative;
            border-left: 5px solid #64748b;
        }

        .patient-tile-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.45);
            border-color: #38bdf8;
        }

        .patient-tile-card.card-done {
            opacity: 0.6;
            background: #141c2b;
        }

        .patient-tile-card.card-biopsy-alert {
            border-left-color: #ef4444 !important;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.35);
        }

        .patient-tile-card.card-explain-wait {
            border-left-color: #f59e0b !important;
        }

        .tile-header {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .tile-waku-time {
            font-size: 13px;
            font-weight: 800;
            color: #38bdf8;
            font-variant-numeric: tabular-nums;
        }

        .tile-doctor {
            font-size: 11px;
            color: var(--text-muted);
            margin-left: auto;
            font-weight: 600;
        }

        .tile-body {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tile-patient-name {
            font-size: 14.5px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tile-patient-sub {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .tile-kana {
            font-size: 10px;
            color: #94a3b8;
        }

        .tile-status-banner {
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            font-size: 11.5px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1px;
        }

        .status-bg-1 { background: rgba(59, 130, 246, 0.15); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3); }
        .status-bg-2 { background: rgba(245, 158, 11, 0.15); color: #fde68a; border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-bg-3 { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
        .status-bg-4 { background: rgba(239, 68, 68, 0.2);  color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); animation: pulse-live 2s infinite; }
        .status-bg-5 { background: rgba(99, 102, 241, 0.2); color: #c7d2fe; border: 1px solid rgba(99, 102, 241, 0.4); }
        .status-bg-6 { background: rgba(168, 85, 247, 0.2); color: #e9d5ff; border: 1px solid rgba(168, 85, 247, 0.4); }
        .status-bg-7 { background: rgba(100, 116, 139, 0.15); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.3); }

        .status-border-1 { border-left-color: #3b82f6 !important; }
        .status-border-2 { border-left-color: #f59e0b !important; }
        .status-border-3 { border-left-color: #10b981 !important; }
        .status-border-4 { border-left-color: #ef4444 !important; }
        .status-border-5 { border-left-color: #6366f1 !important; }
        .status-border-6 { border-left-color: #a855f7 !important; }
        .status-border-7 { border-left-color: #475569 !important; }

        .tile-alerts {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 2px;
        }

        .tile-alert-chip {
            font-size: 10px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }

        .alert-biopsy { background: #dc2626; color: #fff; animation: pulse-live 1.2s infinite; }
        .alert-explain { background: #d97706; color: #fff; }
        .alert-overdue { background: #b91c1c; color: #fff; font-weight: 900; }
        .alert-anti { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid #ef4444; }
        .alert-infection { background: rgba(190, 18, 60, 0.2); color: #fda4af; border: 1px solid #be123c; }
        .alert-allergy { background: rgba(245, 158, 11, 0.2); color: #fde68a; border: 1px solid #f59e0b; }
        .alert-cold { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid #3b82f6; }

        .tile-sed-chip {
            font-size: 10px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 3px;
            background: rgba(192, 132, 252, 0.15);
            color: #d8b4fe;
            border: 1px solid rgba(192, 132, 252, 0.3);
        }
        .tile-sed-chip.none {
            background: transparent;
            color: var(--text-sub);
            border: 1px solid #334155;
        }

        /* タイルクリックからの行フォーカスアニメーション */
        @keyframes row-targeted-pulse {
            0% { background: rgba(56, 189, 248, 0.45); box-shadow: 0 0 15px rgba(56, 189, 248, 0.6); }
            50% { background: rgba(56, 189, 248, 0.25); }
            100% { background: inherit; box-shadow: none; }
        }

        .row-targeted-highlight {
            animation: row-targeted-pulse 2.5s ease-out !important;
        }

        /* ============================================================
           高密度表モード (view-mode-compact)
           行高・余白・フォントサイズを極限までスリム化（約45〜50px/行）
        ============================================================ */
        .view-mode-compact .monitor-table {
            border-spacing: 0 2px !important;
        }
        .view-mode-compact .monitor-table thead th {
            padding: 4px 8px !important;
            font-size: 10.5px !important;
        }
        .view-mode-compact .monitor-table td {
            padding: 4px 8px !important;
            font-size: 12px !important;
        }
        .view-mode-compact .waku-time {
            font-size: 12px !important;
        }
        .view-mode-compact .dept-badge {
            font-size: 9.5px !important;
            padding: 0 4px !important;
            margin-top: 1px !important;
        }
        .view-mode-compact .patient-name-row {
            font-size: 13px !important;
            gap: 4px !important;
        }
        .view-mode-compact .patient-id-sub {
            font-size: 10.5px !important;
            margin-top: 1px !important;
            gap: 4px !important;
        }
        .view-mode-compact .exam-chip,
        .view-mode-compact .sedation-chip {
            font-size: 10px !important;
            padding: 1px 5px !important;
        }
        .view-mode-compact .status-timeline {
            gap: 2px !important;
        }
        .view-mode-compact .step-node {
            font-size: 10px !important;
            padding: 2px 5px !important;
            height: 22px !important;
            line-height: 22px !important;
            min-width: 44px !important;
        }
        .view-mode-compact .step-actions {
            margin-top: 3px !important;
            gap: 4px !important;
        }
        .view-mode-compact .btn-forward {
            font-size: 11px !important;
            padding: 3px 8px !important;
        }
        .view-mode-compact .btn-undo {
            font-size: 10px !important;
            padding: 2px 6px !important;
        }
        .view-mode-compact .arrival-badge,
        .view-mode-compact .arrival-btn-empty {
            font-size: 11px !important;
            padding: 3px 6px !important;
        }
        .view-mode-compact .room-select {
            font-size: 11px !important;
            padding: 2px 4px !important;
        }
        .view-mode-compact .prep-status-toggle {
            font-size: 10px !important;
            padding: 2px 6px !important;
        }
        .view-mode-compact .prep-med-badge {
            font-size: 10px !important;
            padding: 1px 5px !important;
        }
        .view-mode-compact .task-chip {
            font-size: 10.5px !important;
            padding: 2px 6px !important;
            margin-bottom: 2px !important;
        }
        .view-mode-compact .recovery-timer {
            font-size: 11px !important;
            padding: 3px 6px !important;
        }
        .view-mode-compact .btn-explain-wait {
            font-size: 10px !important;
            padding: 2px 6px !important;
            margin-top: 2px !important;
        }
        .view-mode-compact .location-badge {
            font-size: 10px !important;
            padding: 2px 6px !important;
            margin-top: 2px !important;
        }
        .view-mode-compact .remarks-doctor-highlight,
        .view-mode-compact .remarks-box {
            font-size: 10.5px !important;
            padding: 3px 6px !important;
            margin-top: 2px !important;
        }
        .view-mode-compact .med-board-btn,
        .view-mode-compact .med-board-badge {
            font-size: 9.5px !important;
            padding: 1px 4px !important;
        }

        /* メインテーブルコンテナ */
        .main-container {
            padding: 16px 20px;
            overflow-x: auto;
        }

        .monitor-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 6px;
            min-width: 1150px;
        }

        .monitor-table thead th {
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            text-align: left;
            border-bottom: 2px solid var(--border-color);
            background: transparent;
        }

        .monitor-row {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            transition: all 0.15s;
        }

        .monitor-row:hover {
            background: #243247;
            box-shadow: var(--shadow-sm);
        }

        .monitor-row.row-done {
            opacity: 0.6;
            background: #151f30;
        }

        .monitor-row.row-biopsy-alert {
            border-left: 4px solid #ef4444 !important;
        }

        .monitor-row.row-explain-wait {
            border-left: 4px solid #f59e0b !important;
            background: #252e3e;
        }

        .monitor-table td {
            padding: 10px 12px;
            vertical-align: middle;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .monitor-table td:first-child {
            border-left: 1px solid var(--border-color);
            border-top-left-radius: var(--radius-md);
            border-bottom-left-radius: var(--radius-md);
        }

        .monitor-table td:last-child {
            border-right: 1px solid var(--border-color);
            border-top-right-radius: var(--radius-md);
            border-bottom-right-radius: var(--radius-md);
        }

        /* セル内コンポーネント */
        .waku-time {
            font-size: 13px;
            font-weight: 800;
            color: #ffffff;
            font-variant-numeric: tabular-nums;
        }

        .dept-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: var(--radius-sm);
            font-size: 10px;
            font-weight: 700;
            margin-top: 2px;
        }
        .dept-badge-PROCT { background: #0369a1; color: #e0f2fe; }
        .dept-badge-GASTRO { background: #047857; color: #d1fae5; }

        .patient-name-row {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .patient-id-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .age-chip {
            padding: 0 4px;
            border-radius: 3px;
            font-size: 10.5px;
            font-weight: bold;
        }
        .age-chip-elder { background: #ef4444; color: #fff; }
        .age-chip-mid { background: #f59e0b; color: #000; }
        .age-chip-young { background: #3b82f6; color: #fff; }

        .exam-chip {
            padding: 1px 6px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 800;
            background: #334155;
            color: #cbd5e1;
        }
        .exam-chip-gif { background: #0284c7; color: #ffffff; }
        .exam-chip-cf { background: #059669; color: #ffffff; }
        .exam-chip-enema { background: #7c3aed; color: #ffffff; border: 1px solid #a78bfa; }

        /* セデーションバッジ */
        .sedation-chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 7px;
            border-radius: var(--radius-sm);
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            user-select: none;
        }
        .sedation-chip:hover { transform: scale(1.03); }
        .sedation-chip-propofol { background: #581c87; color: #f3e8ff; border: 1px solid #c084fc; }
        .sedation-chip-sedative { background: #1e3a8a; color: #dbeafe; border: 1px solid #60a5fa; }
        .sedation-chip-none { background: #334155; color: #94a3b8; border: 1px dashed #64748b; }

        /* 受付セル */
        .arrival-badge {
            background: #064e3b;
            color: #34d399;
            border: 1px solid #059669;
            padding: 3px 8px;
            border-radius: var(--radius-md);
            font-weight: 800;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .arrival-btn-empty {
            background: var(--bg-surface-elevated);
            color: var(--text-muted);
            border: 1px dashed var(--border-color);
            padding: 4px 10px;
            border-radius: var(--radius-md);
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }
        .arrival-btn-empty:hover { background: #475569; color: #ffffff; border-style: solid; }

        /* 前処置・マイトイレセル */
        .room-select {
            background: #0f172a;
            color: #38bdf8;
            font-weight: bold;
            border: 1px solid #0284c7;
            padding: 3px 6px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            outline: none;
            cursor: pointer;
        }

        .prep-med-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 800;
            margin-top: 4px;
            cursor: pointer;
        }
        .prep-med-mobi { background: #1e3a8a; color: #93c5fd; border: 1px solid #3b82f6; }
        .prep-med-magp { background: #7c2d12; color: #fdba74; border: 1px solid #f97316; }
        .prep-med-nif { background: #064e3b; color: #6ee7b7; border: 1px solid #10b981; }

        .prep-status-toggle {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            border: 1px solid var(--border-color);
            background: var(--bg-surface-elevated);
            color: var(--text-muted);
            margin-top: 3px;
            display: inline-block;
        }
        .prep-status-prepared { background: #166534; color: #bbf7d0; border-color: #22c55e; }
        .prep-status-delivered { background: #0369a1; color: #bae6fd; border-color: #0ea5e9; }

        /* ステータス進捗バー */
        .status-timeline {
            display: flex;
            align-items: center;
            background: #0b1329;
            border-radius: var(--radius-full);
            padding: 3px;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .step-node {
            flex: 1;
            text-align: center;
            padding: 5px 2px;
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text-sub);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            user-select: none;
            white-space: nowrap;
        }

        .step-node:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.05);
        }

        .step-node.active {
            color: #ffffff;
            font-weight: 800;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
            animation: pulse-active 2s infinite;
        }

        .step-node.completed { color: #94a3b8; }

        .step-node.step-skipped {
            opacity: 0.35;
            text-decoration: line-through;
            color: #64748b;
            cursor: not-allowed;
        }

        .step-node.active-1 { background: #2563eb; }
        .step-node.active-2 { background: #d97706; }
        .step-node.active-3 { background: #059669; }
        .step-node.active-4 { background: #e11d48; }
        .step-node.active-5 { background: #4f46e5; }
        .step-node.active-6 { background: #9333ea; }
        .step-node.active-7 { background: #475569; }

        @keyframes pulse-active {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }

        .step-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
        }

        .btn-forward {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            padding: 4px 12px;
            border-radius: var(--radius-md);
            font-size: 11.5px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.1s, background 0.15s;
        }

        .btn-forward:hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            transform: translateY(-1px);
        }

        .btn-undo {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 4px 8px;
            border-radius: var(--radius-md);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
        }

        .btn-undo:hover {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border-color: #ef4444;
        }

        /* タスクチップ */
        .task-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            margin-bottom: 4px;
            border: 1px solid transparent;
            transition: all 0.15s;
        }

        .task-chip-biopsy-alert {
            background: #7f1d1d;
            color: #fca5a5;
            border-color: #ef4444;
            animation: pulse-danger 1.5s infinite;
        }

        .task-chip-biopsy-ok {
            background: #064e3b;
            color: #6ee7b7;
            border-color: #10b981;
        }

        .task-chip-scope-ordered {
            background: #78350f;
            color: #fde68a;
            border-color: #f59e0b;
        }

        .task-chip-scope-done {
            background: #1e293b;
            color: #94a3b8;
            border-color: #334155;
        }

        @keyframes pulse-danger {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }

        /* リカバリセル */
        .recovery-timer {
            font-size: 11px;
            font-weight: 700;
            color: #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            border-radius: var(--radius-sm);
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.25);
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            user-select: none;
        }
        .recovery-timer:hover {
            background: rgba(59, 130, 246, 0.25);
            border-color: #3b82f6;
            color: #93c5fd;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        }
        .recovery-timer.timer-warning {
            color: #fca5a5;
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.5);
            font-weight: 800;
        }
        .recovery-timer.timer-warning:hover {
            background: rgba(239, 68, 68, 0.35);
            border-color: #ef4444;
            color: #ffffff;
        }

        /* リカバリ時間調整モーダル ボタン群 */
        .recovery-btn-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 6px;
        }
        .recovery-quick-btn {
            background: rgba(30, 41, 59, 0.9);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.4);
            padding: 7px 12px;
            border-radius: var(--radius-md);
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            user-select: none;
        }
        .recovery-quick-btn:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #3b82f6;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }
        .recovery-quick-btn.btn-direct-remaining {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(6, 78, 59, 0.4));
            color: #6ee7b7;
            border-color: #059669;
        }
        .recovery-quick-btn.btn-direct-remaining:hover {
            background: #059669;
            color: #ffffff;
            border-color: #10b981;
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.3);
        }
        .recovery-quick-btn.btn-preset {
            background: rgba(51, 65, 85, 0.6);
            color: #e2e8f0;
            border-color: rgba(148, 163, 184, 0.3);
            font-weight: 600;
        }
        .recovery-quick-btn.btn-preset:hover {
            background: #475569;
            color: #ffffff;
            border-color: #94a3b8;
        }

        /* 画面全体の点滅フラッシュアニメーション */
        @keyframes screen-alert-pulse {
            0% { box-shadow: inset 0 0 0 8px rgba(239, 68, 68, 0.95), inset 0 0 40px rgba(239, 68, 68, 0.6); }
            50% { box-shadow: inset 0 0 0 14px rgba(245, 158, 11, 0.95), inset 0 0 60px rgba(245, 158, 11, 0.7); }
            100% { box-shadow: inset 0 0 0 8px rgba(239, 68, 68, 0.95), inset 0 0 40px rgba(239, 68, 68, 0.6); }
        }
        body.screen-alert-flashing {
            animation: screen-alert-pulse 1.1s infinite ease-in-out !important;
        }

        /* 大型リカバリ完了ポップアップ */
        #recoveryAlertModal .modal-card {
            max-width: 640px;
            border: 2px solid #ef4444;
            box-shadow: 0 10px 40px rgba(239, 68, 68, 0.4), 0 0 0 1px rgba(239, 68, 68, 0.6);
            animation: alert-card-shake 0.4s ease-in-out;
        }
        @keyframes alert-card-shake {
            0%, 100% { transform: translateY(0); }
            20% { transform: translateY(-4px) scale(1.01); }
            40% { transform: translateY(3px) scale(0.99); }
            60% { transform: translateY(-2px); }
            80% { transform: translateY(1px); }
        }
        .recovery-alert-patient-box {
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-left: 5px solid #ef4444;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 12px;
        }
        .recovery-alert-patient-box.is-overdue {
            border-left-color: #f87171;
            background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(127, 29, 29, 0.25));
        }
        .recovery-alert-pname {
            font-size: 15px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .recovery-alert-pinfo {
            font-size: 12.5px;
            color: #cbd5e1;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .recovery-alert-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
            align-items: center;
        }
        .btn-alert-action-ok {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            font-size: 12.5px;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            border: 1px solid #34d399;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.4);
            transition: all 0.15s;
        }
        .btn-alert-action-ok:hover {
            background: linear-gradient(135deg, #34d399, #10b981);
            transform: translateY(-1px);
        }
        .btn-alert-action-ext {
            background: rgba(30, 41, 59, 0.9);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.4);
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-alert-action-ext:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #3b82f6;
        }
        .btn-alert-action-dismiss {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            font-size: 11.5px;
            padding: 5px 10px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            margin-left: auto;
            transition: all 0.15s;
        }
        .btn-alert-action-dismiss:hover {
            background: rgba(148, 163, 184, 0.2);
            color: #ffffff;
        }

        .btn-explain-wait {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }

        .btn-explain-wait.active {
            background: #b45309;
            color: #fef3c7;
            border-color: #f59e0b;
            animation: pulse-live 1.5s infinite;
        }

        /* モーダル */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(2px);
        }

        .modal-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 520px;
            padding: 24px;
            box-shadow: var(--shadow-lg);
            animation: modal-slide-in 0.2s ease-out;
        }

        @keyframes modal-slide-in {
            from { opacity: 0; transform: translateY(10px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .modal-title {
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .modal-body { margin-bottom: 20px; }
        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }

        .form-input {
            width: 100%;
            background: var(--bg-app);
            border: 1px solid var(--border-color);
            color: #ffffff;
            padding: 8px 10px;
            border-radius: var(--radius-md);
            font-size: 13px;
            outline: none;
        }

        .site-chips-group { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }

        .site-chip-btn {
            background: var(--bg-app);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }

        .site-chip-btn.selected { background: var(--primary); color: #ffffff; border-color: var(--primary); }

        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }

        .btn-cancel {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-weight: 700;
            cursor: pointer;
        }

        .btn-save {
            background: var(--primary);
            border: none;
            color: #ffffff;
            padding: 8px 18px;
            border-radius: var(--radius-md);
            font-weight: 800;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }

        .btn-danger-action {
            background: var(--danger);
            border: none;
            color: #ffffff;
            padding: 8px 18px;
            border-radius: var(--radius-md);
            font-weight: 800;
            cursor: pointer;
        }

        .info-popover {
            position: absolute;
            background: #0f172a;
            border: 1px solid #38bdf8;
            color: #e0f2fe;
            padding: 8px 12px;
            border-radius: var(--radius-md);
            font-size: 11px;
            max-width: 240px;
            box-shadow: var(--shadow-lg);
            z-index: 500;
            display: none;
            line-height: 1.5;
        }

        /* ============================================================
        職種別ビュー専用ガイダンスバー & スタイル
        ============================================================ */
        .role-guidance-bar {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid #334155;
            border-radius: var(--radius-md);
            padding: 10px 18px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: var(--shadow-sm);
            transition: all 0.25s ease;
        }
        .role-guidance-left {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 700;
        }
        .role-guidance-icon { font-size: 22px; }
        .role-guidance-chips {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .role-guidance-chip {
            padding: 4px 11px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .role-chip-primary { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid #3b82f6; }
        .role-chip-success { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid #10b981; }
        .role-chip-warning { background: rgba(245, 158, 11, 0.2); color: #fde68a; border: 1px solid #f59e0b; }
        .role-chip-danger  { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid #ef4444; }
        .role-chip-purple  { background: rgba(168, 85, 247, 0.2); color: #e9d5ff; border: 1px solid #a855f7; }

        .role-filter-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }
        .role-filter-btn:hover { background: rgba(255, 255, 255, 0.18); }
        .role-filter-btn.active {
            background: #3b82f6;
            border-color: #60a5fa;
            color: #ffffff;
            box-shadow: 0 0 8px rgba(59, 130, 246, 0.5);
        }

        /* ------------------------------------------------------------
           列幅の基本設定（全体俯瞰）
        ------------------------------------------------------------ */
        .monitor-table th.col-time, .monitor-table td.cell-time-waku { width: 95px; }
        .monitor-table th.col-patient, .monitor-table td.cell-patient { width: 220px; }
        .monitor-table th.col-arrival, .monitor-table td.cell-arrival { width: 105px; }
        .monitor-table th.col-prep, .monitor-table td.cell-prep { width: 160px; }
        .monitor-table th.col-timeline, .monitor-table td.cell-status-bar { min-width: 440px; }
        .monitor-table th.col-tasks, .monitor-table td.cell-tasks { width: 155px; }
        .monitor-table th.col-recovery, .monitor-table td.cell-recovery { width: 145px; }

        /* ------------------------------------------------------------
           1. 👨‍⚕️ 医師ビュー (role-doctor)
           【非表示】: マイトイレ/下剤 (col-prep)、来院受付打刻 (col-arrival)
           【特大表示】: カルテ特記事項 (remarks)、検査開始・完了・説明完了ボタン
        ------------------------------------------------------------ */
        .role-doctor .role-guidance-bar {
            background: linear-gradient(135deg, #064e3b, #0f172a);
            border-color: #059669;
        }
        .role-doctor .col-prep { display: none !important; }
        .role-doctor .col-arrival { display: none !important; }
        .role-doctor .col-patient { width: 300px !important; }
        .role-doctor .col-tasks { width: 170px !important; }
        .role-doctor .col-recovery { width: 170px !important; }

        .role-doctor .row-focus-doctor-ready {
            border-left: 6px solid #10b981 !important;
            background: rgba(16, 185, 129, 0.12) !important;
        }
        .role-doctor .row-focus-doctor-exam {
            border-left: 6px solid #f43f5e !important;
            background: rgba(244, 63, 94, 0.14) !important;
        }
        .role-doctor .row-focus-doctor-explain {
            border-left: 6px solid #a855f7 !important;
            background: rgba(168, 85, 247, 0.14) !important;
        }
        .role-doctor .row-fade-other { opacity: 0.45; }
        .role-doctor .remarks-doctor-highlight {
            display: block !important;
            background: #422006 !important;
            color: #fef08a !important;
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 6px;
            margin-top: 6px;
            font-size: 12px;
            border-left: 4px solid #facc15;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            line-height: 1.4;
        }
        .role-doctor .btn-doctor-action {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            font-weight: 800;
            font-size: 12.5px;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.4);
            transition: all 0.15s;
        }
        .role-doctor .btn-doctor-action:hover {
            background: linear-gradient(135deg, #34d399, #10b981);
            transform: translateY(-1px);
        }

        /* ------------------------------------------------------------
           2. 👩‍⚕️ 看護師ビュー (role-nurse)
           【最小化】: 来院受付
           【特大表示】: 検体未照合アラート、リカバリ安静タイマー、セデーション
        ------------------------------------------------------------ */
        .role-nurse .role-guidance-bar {
            background: linear-gradient(135deg, #312e81, #0f172a);
            border-color: #6366f1;
        }
        .role-nurse .col-arrival { width: 85px !important; opacity: 0.6; }
        .role-nurse .col-tasks { width: 185px !important; }
        .role-nurse .col-recovery { width: 185px !important; }
        .role-nurse .row-focus-nurse-ready {
            border-left: 6px solid #10b981 !important;
            background: rgba(16, 185, 129, 0.1) !important;
        }
        .role-nurse .row-focus-nurse-exam {
            border-left: 6px solid #f43f5e !important;
            background: rgba(244, 63, 94, 0.12) !important;
        }
        .role-nurse .row-focus-nurse-recovery {
            border-left: 6px solid #6366f1 !important;
            background: rgba(99, 102, 241, 0.15) !important;
        }
        .role-nurse .sedation-chip { font-size: 13px; padding: 4px 10px; font-weight: 800; }
        .role-nurse .recovery-timer {
            font-size: 13.5px;
            font-weight: 900;
            padding: 6px 10px;
            border-radius: 4px;
            background: rgba(99, 102, 241, 0.2);
            border: 1px solid #6366f1;
        }
        .role-nurse .task-chip-biopsy-alert {
            font-size: 13px;
            padding: 6px 12px;
            font-weight: 900;
        }

        /* ------------------------------------------------------------
           3. 🧑‍🔬 補助看護師ビュー (role-assistant)
           【非表示】: リカバリ/説明 (col-recovery)
           【特大表示】: マイトイレ部屋選択・下剤作成ボタン・スコープ洗浄指示中
        ------------------------------------------------------------ */
        .role-assistant .role-guidance-bar {
            background: linear-gradient(135deg, #78350f, #0f172a);
            border-color: #f59e0b;
        }
        .role-assistant .col-recovery { display: none !important; }
        .role-assistant .col-prep {
            width: 260px !important;
            background: rgba(245, 158, 11, 0.09) !important;
            border-left: 2px dashed #f59e0b;
            border-right: 2px dashed #f59e0b;
        }
        .role-assistant .col-tasks { width: 185px !important; }
        .role-assistant .room-select {
            font-size: 14px;
            padding: 6px 10px;
            border: 2px solid #38bdf8;
            background: #0f172a;
            font-weight: 800;
        }
        .role-assistant .prep-status-toggle {
            font-size: 12px;
            padding: 5px 12px;
            font-weight: 800;
        }
        .role-assistant .task-chip-scope-ordered {
            font-size: 13.5px;
            padding: 8px 14px;
            animation: pulse-live 1.2s infinite;
            border: 2px solid #f59e0b;
            background: #78350f;
            color: #fef08a;
        }
        .role-assistant .row-focus-assistant-clean {
            border-left: 6px solid #f59e0b !important;
            background: rgba(245, 158, 11, 0.18) !important;
        }
        .role-assistant .row-focus-assistant-prep {
            border-left: 6px solid #38bdf8 !important;
            background: rgba(56, 189, 248, 0.12) !important;
        }
        .role-assistant .row-fade-other { opacity: 0.45; }

        /* ------------------------------------------------------------
           4. 📋 受付事務ビュー (role-clerk)
           【非表示】: マイトイレ/下剤 (col-prep)、検体/スコープ (col-tasks)、リカバリ (col-recovery)
           【特大表示】: 来院受付打刻 (col-arrival)、患者現在所在、会計帰宅完了ボタン
        ------------------------------------------------------------ */
        .role-clerk .role-guidance-bar {
            background: linear-gradient(135deg, #0e7490, #0f172a);
            border-color: #06b6d4;
        }
        .role-clerk .col-prep { display: none !important; }
        .role-clerk .col-tasks { display: none !important; }
        .role-clerk .col-recovery { display: none !important; }
        .role-clerk .col-arrival {
            width: 150px !important;
            background: rgba(16, 185, 129, 0.08);
            border-left: 2px dashed #10b981;
            border-right: 2px dashed #10b981;
        }
        .role-clerk .col-patient { width: 300px !important; }
        .role-clerk .arrival-badge { font-size: 14px; padding: 6px 12px; font-weight: 800; }
        .role-clerk .arrival-btn-empty {
            font-size: 13px;
            padding: 8px 14px;
            font-weight: 800;
            border-color: #10b981;
            color: #34d399;
            background: rgba(16, 185, 129, 0.15);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }
        .role-clerk .row-focus-clerk-uncheckin {
            border-left: 6px solid #3b82f6 !important;
            background: rgba(59, 130, 246, 0.14) !important;
        }
        .role-clerk .row-focus-clerk-checkout {
            border-left: 6px solid #10b981 !important;
            background: rgba(16, 185, 129, 0.16) !important;
        }
        .role-clerk .location-badge {
            background: #0f172a;
            color: #38bdf8;
            border: 1px solid #0284c7;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 800;
            display: inline-block;
            margin-top: 5px;
        }
        .role-clerk .row-fade-other { opacity: 0.5; }

        /* ------------------------------------------------------------
           クライアント保存トースト通知
        ------------------------------------------------------------ */
        .saved-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #064e3b;
            color: #6ee7b7;
            border: 1px solid #10b981;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-size: 12.5px;
            font-weight: 800;
            box-shadow: var(--shadow-lg);
            z-index: 2000;
            display: none;
            align-items: center;
            gap: 6px;
            animation: modal-slide-in 0.2s ease-out;
        }
        .saved-indicator {
            font-size: 11px;
            color: #38bdf8;
            background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.3);
            padding: 2px 7px;
            border-radius: 3px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* 医療安全アラート (ホワイトボード用) */
        .med-board-btn {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 800;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .med-board-btn.unverified {
            background: #ef4444;
            color: #ffffff;
            border-color: #dc2626;
            animation: pulse-anti 1.8s infinite ease-in-out;
        }
        @keyframes pulse-anti {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { transform: scale(1.03); box-shadow: 0 0 0 4px rgba(239, 68, 68, 0); }
        }
        .med-board-btn.verified {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            border-color: #10b981;
        }
        .med-board-btn.verified:hover {
            background: rgba(16, 185, 129, 0.35);
        }
        .med-board-btn.cold {
            background: #2563eb;
            color: #ffffff;
            border-color: #1d4ed8;
        }
        .med-board-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }
        .med-board-badge.allergy {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            border: 1px solid #f59e0b;
        }
        .med-board-badge.infection {
            background: rgba(225, 29, 72, 0.2);
            color: #fda4af;
            border: 1px solid #e11d48;
        }
        .row-anti-warning {
            border-left: 4px solid #ef4444 !important;
        }
        .row-infection-warning {
            border-left: 4px solid #be123c !important;
        }

        /* ------------------------------------------------------------
           医師向け予約変更通知フロートパネル & 医師選択UI
        ------------------------------------------------------------ */
        .doctor-notif-floating-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2500;
            display: none;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .doctor-notif-trigger-btn {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: #f8fafc;
            border: 2px solid #38bdf8;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5), 0 0 12px rgba(56, 189, 248, 0.4);
            border-radius: 9999px;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .doctor-notif-trigger-btn:hover {
            transform: translateY(-2px) scale(1.02);
            border-color: #60a5fa;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.6), 0 0 18px rgba(96, 165, 250, 0.6);
        }
        .doctor-notif-trigger-btn.has-unread {
            border-color: #f59e0b;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5), 0 0 14px rgba(245, 158, 11, 0.5);
            animation: notif-pulse 2.5s infinite ease-in-out;
        }
        @keyframes notif-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.04); }
        }

        .doctor-notif-badge-count {
            background: #ef4444;
            color: #ffffff;
            font-size: 11px;
            font-weight: 900;
            padding: 1px 7px;
            border-radius: 9999px;
            display: inline-block;
            min-width: 18px;
            text-align: center;
            line-height: 1.4;
        }

        .doctor-notif-panel {
            width: 420px;
            max-width: calc(100vw - 32px);
            max-height: 480px;
            background: rgba(30, 41, 59, 0.96);
            backdrop-filter: blur(12px);
            border: 1px solid #475569;
            border-radius: 12px;
            box-shadow: 0 20px 40px -4px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,255,255,0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: notif-slide-up 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes notif-slide-up {
            from { opacity: 0; transform: translateY(16px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .doctor-notif-header {
            background: #0f172a;
            border-bottom: 1px solid #334155;
            padding: 11px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .doctor-notif-header-title {
            font-size: 13.5px;
            font-weight: 800;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .doctor-notif-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .doctor-notif-clear-all-btn {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }
        .doctor-notif-clear-all-btn:hover {
            background: #ef4444;
            color: #ffffff;
        }
        .doctor-notif-close-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 16px;
            cursor: pointer;
            line-height: 1;
            padding: 2px 6px;
        }
        .doctor-notif-close-btn:hover { color: #ffffff; }

        .doctor-notif-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .doctor-notif-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 10px 12px;
            transition: all 0.2s ease;
            position: relative;
        }
        .doctor-notif-card:hover {
            border-color: #64748b;
            background: #243248;
        }
        .doctor-notif-card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .doctor-notif-meta-left {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .doctor-notif-badge {
            font-size: 10.5px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .doctor-notif-badge.badge-success { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); }
        .doctor-notif-badge.badge-warning { background: rgba(245, 158, 11, 0.2); color: #fde68a; border: 1px solid rgba(245, 158, 11, 0.4); }
        .doctor-notif-badge.badge-danger  { background: rgba(239, 68, 68, 0.2);  color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
        .doctor-notif-badge.badge-primary { background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }
        .doctor-notif-badge.badge-purple  { background: rgba(139, 92, 246, 0.2); color: #c4b5fd; border: 1px solid rgba(139, 92, 246, 0.4); }

        .doctor-notif-time {
            font-size: 11px;
            color: #94a3b8;
        }
        .doctor-notif-card-body {
            font-size: 12.5px;
            line-height: 1.45;
        }
        .doctor-notif-patient-name {
            font-weight: 800;
            color: #f8fafc;
        }
        .doctor-notif-summary {
            font-size: 12px;
            color: #e2e8f0;
            background: rgba(15, 23, 42, 0.7);
            padding: 5px 8px;
            border-radius: 4px;
            margin-top: 5px;
            border-left: 3px solid #38bdf8;
            word-break: break-all;
        }
        .doctor-notif-dismiss-btn {
            background: transparent;
            border: 1px solid #475569;
            color: #cbd5e1;
            border-radius: 4px;
            font-size: 11px;
            padding: 2px 8px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.15s;
        }
        .doctor-notif-dismiss-btn:hover {
            background: #10b981;
            border-color: #059669;
            color: #ffffff;
        }

        .doctor-notif-empty {
            text-align: center;
            padding: 32px 16px;
            color: #94a3b8;
            font-size: 12.5px;
        }

        /* 医師クイック選択モーダル */
        .doctor-picker-modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            backdrop-filter: blur(2px);
        }
        .doctor-picker-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            width: 360px;
            max-width: 90%;
            padding: 22px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }
        .doctor-picker-btn {
            width: 100%;
            padding: 11px 14px;
            background: #0f172a;
            border: 1px solid #334155;
            color: #f8fafc;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 800;
            cursor: pointer;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.15s;
        }
        .doctor-picker-btn:hover {
            background: #2563eb;
            border-color: #3b82f6;
            transform: translateY(-1px);
        }
        .doctor-picker-btn.current {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.15);
            color: #6ee7b7;
        }

        /* 医師ビュー時の自分の担当行ハイライト */
        .role-doctor .row-my-doctor-highlight {
            border-left: 6px solid #3b82f6 !important;
            background: rgba(59, 130, 246, 0.08) !important;
        }

        /* ============================================================
           ステータス3 検査待ち2段階（待機中 ⇔ 準備完了・医師呼出OK）スタイル
           ============================================================ */
        /* タイル・行の準備完了（医師呼出OK）強調 */
        .card-exam-ready {
            border: 2px solid #10b981 !important;
            box-shadow: 0 0 14px rgba(16, 185, 129, 0.45) !important;
            background: linear-gradient(145deg, rgba(16, 185, 129, 0.08), rgba(15, 23, 42, 0.95)) !important;
        }
        .row-exam-ready {
            background-color: rgba(16, 185, 129, 0.12) !important;
            box-shadow: inset 4px 0 0 #10b981;
        }
        .row-exam-waiting {
            background-color: rgba(100, 116, 139, 0.04);
        }

        /* 呼出OK点灯バッジ・待機中バッジ */
        .badge-exam-ready {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: var(--radius-full);
            font-size: 11.5px;
            font-weight: 800;
            background: #065f46;
            color: #6ee7b7;
            border: 1px solid #10b981;
            box-shadow: 0 0 8px rgba(16, 185, 129, 0.5);
            cursor: pointer;
            transition: all 0.2s;
        }
        .badge-exam-ready:hover {
            background: #047857;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.8);
        }
        .badge-exam-waiting {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: var(--radius-full);
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(100, 116, 139, 0.25);
            color: #cbd5e1;
            border: 1px solid #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .badge-exam-waiting:hover {
            background: rgba(100, 116, 139, 0.45);
            color: #ffffff;
            border-color: #94a3b8;
        }

        /* 待機経過時間警告スタイル (30分黄、60分赤、90分赤点滅) */
        .wait-time-tag {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 800;
            margin-left: 4px;
            background: rgba(148, 163, 184, 0.15);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.3);
        }
        /* ≥30分: 黄色 */
        .wait-time-warn-yellow {
            background: rgba(245, 158, 11, 0.2) !important;
            color: #fde68a !important;
            border: 1px solid #f59e0b !important;
        }
        /* ≥60分: 赤色 */
        .wait-time-warn-red {
            background: rgba(239, 68, 68, 0.25) !important;
            color: #fca5a5 !important;
            border: 1px solid #ef4444 !important;
        }
        /* ≥90分: 赤点滅アニメーション */
        .wait-time-warn-pulse {
            background: rgba(220, 38, 38, 0.4) !important;
            color: #ffffff !important;
            border: 1px solid #ef4444 !important;
            animation: pulse-wait-danger 1s infinite alternate !important;
        }
        @keyframes pulse-wait-danger {
            0% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
                border-color: #ef4444;
                transform: scale(1);
            }
            100% {
                box-shadow: 0 0 12px 3px rgba(239, 68, 68, 0.95);
                border-color: #fca5a5;
                transform: scale(1.05);
            }
        }

        /* 呼出OKボタン（ナース用アクションボタン） */
        .btn-call-ready {
            background: linear-gradient(135deg, #059669, #047857) !important;
            border: 1px solid #10b981 !important;
            color: #ffffff !important;
            font-weight: 800 !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.4) !important;
        }
        .btn-call-ready:hover {
            background: linear-gradient(135deg, #10b981, #059669) !important;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.6) !important;
        }

        @media (max-width: 900px) {
            .header-top { flex-direction: column; align-items: flex-start; }
            .sync-controls { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>

<!-- ポップオーバー (下剤簡易注意) -->
<div id="infoPopover" class="info-popover"></div>

<!-- ヘッダー -->
<header class="board-header">
    <div class="header-top">
        <div class="header-title-group">
            <h1 class="header-title">
                <span>📊 当日進捗ステータスモニター</span>
                <span class="live-badge"><span class="live-dot"></span>LIVE</span>
            </h1>
            <!-- 職種別ビュー切替タブ -->
            <nav class="role-tabs" aria-label="職種別ビュー切替">
                <button type="button" class="tab-btn active" data-role="all" onclick="switchRoleView('all', this)">🌐 全体俯瞰</button>
                <button type="button" class="tab-btn" data-role="doctor" onclick="switchRoleView('doctor', this)">👨‍⚕️ 医師</button>
                <button type="button" class="tab-btn" data-role="nurse" onclick="switchRoleView('nurse', this)">👩‍⚕️ 看護師</button>
                <button type="button" class="tab-btn" data-role="assistant" onclick="switchRoleView('assistant', this)">🧑‍🔬 補助看護師</button>
                <button type="button" class="tab-btn" data-role="clerk" onclick="switchRoleView('clerk', this)">📋 受付事務</button>
            </nav>

            <!-- 表示モード切替タブ -->
            <nav class="view-mode-tabs" aria-label="表示モード切替">
                <button type="button" class="btn-view-mode active" id="btnModeTile" onclick="switchViewMode('tile')">🔲 タイル</button>
                <button type="button" class="btn-view-mode" id="btnModeCompact" onclick="switchViewMode('compact')">📑 高密度表</button>
                <button type="button" class="btn-view-mode" id="btnModeStandard" onclick="switchViewMode('standard')">📄 標準表</button>
            </nav>
        </div>

        <div class="sync-controls">
            <!-- 自動更新間隔 -->
            <div class="auto-refresh-box">
                <span>🔄 自動更新:</span>
                <select id="refreshIntervalSelect" class="select-dark" onchange="changeRefreshInterval(this.value)">
                    <option value="10">10秒</option>
                    <option value="20">20秒</option>
                    <option value="30">30秒</option>
                    <option value="60">60秒</option>
                    <option value="0">停止</option>
                </select>
                <span id="savedIndicator" class="saved-indicator" title="更新間隔と職種表示は各端末に自動保存されます">💾 端末保存</span>
                <div class="refresh-progress" title="次回更新までのカウントダウン">
                    <div id="refreshBar" class="refresh-bar"></div>
                </div>
            </div>

            <button type="button" class="btn-header" onclick="fetchProgressData(true)" title="今すぐデータを最新に更新">
                <span>⚡ 今すぐ更新</span>
            </button>
            <button type="button" class="btn-header" style="background: linear-gradient(135deg, #059669, #047857); border-color:#059669; color:#fff; font-weight:800;" onclick="openEmergencyRegisterModal()" title="当日の緊急検査・浣腸CF・当日飛び込み予約を追加">
                <span>➕ 当日・緊急追加</span>
            </button>
            <button type="button" class="btn-header" style="background: linear-gradient(135deg, #e11d48, #be123c); border-color:#e11d48; color:#fff;" onclick="openMedicalAlertScanModal({date: '<?= $date_str ?>', dept: '<?= $dept ?>'})" title="当日の抗血栓薬・アレルギー・感染症・風邪アラートスキャン">
                <span>🛡️ アラートスキャン</span>
            </button>
            <button type="button" class="btn-header nav-btn-urgent" style="background: linear-gradient(135deg, #f59e0b, #ea580c); border-color:#d97706; color:#fff; font-weight:800;" onclick="openUrgentSlotModal()" id="btnNavUrgentSlot" title="本日〜30日以内の大腸内視鏡(CF/TCF)空き枠・直前キャンセル速報">
                <span>⚡ 直近空き枠速報</span>
                <span id="navUrgentSlotBadge" style="background:#fff; color:#ea580c; font-size:10px; padding:1px 6px; border-radius:10px; font-weight:900; margin-left:3px; display:none;">0</span>
            </button>
            <a href="print_schedule_daily.php?date=<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>&dept=<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>" class="btn-header" target="_blank" title="A4当日一覧を印刷">
                <span>🖨️ 当日印刷</span>
            </a>
            <a href="month_schedule.php?dept=<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>" class="btn-header" title="月間カレンダーへ戻る">
                <span>📅 カレンダー</span>
            </a>
            <a href="index.php" class="btn-header" title="ポータルメニューへ戻る">
                <span>🏠 ポータル</span>
            </a>
        </div>
    </div>

    <!-- 2行目：日付選択 ＆ 診療科切替 ＆ 検査種別フィルター ＆ 滞在中トグル -->
    <div class="controls-row">
        <div class="date-stepper">
            <a href="?date=<?= $prev_date ?>&dept=<?= $dept ?>&exam_filter=<?= $exam_filter ?>" class="date-btn" title="前日へ">◀</a>
            <div class="date-current">
                <span>📅 <?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?> (<?= $dow ?>)</span>
                <?php if ($is_today): ?>
                    <span style="font-size: 10px; background: #38bdf8; color: #0f172a; padding: 1px 5px; border-radius: 3px; font-weight: 800;">本日</span>
                <?php endif; ?>
            </div>
            <a href="?date=<?= $next_date ?>&dept=<?= $dept ?>&exam_filter=<?= $exam_filter ?>" class="date-btn" title="翌日へ">▶</a>
        </div>
        <?php if (!$is_today): ?>
            <a href="?date=<?= $today_str ?>&dept=<?= $dept ?>&exam_filter=<?= $exam_filter ?>" class="btn-header" style="background:#0284c7;color:#fff;">本日に戻る</a>
        <?php endif; ?>

        <!-- 診療科タブ -->
        <div class="dept-tabs">
            <a href="?date=<?= $date_str ?>&dept=ALL&exam_filter=<?= $exam_filter ?>" class="tab-btn <?= $dept === 'ALL' ? 'active' : '' ?>">全診療科横断</a>
            <a href="?date=<?= $date_str ?>&dept=PROCT&exam_filter=<?= $exam_filter ?>" class="tab-btn <?= $dept === 'PROCT' ? 'active active-proct' : '' ?>">肛門科</a>
            <a href="?date=<?= $date_str ?>&dept=GASTRO&exam_filter=<?= $exam_filter ?>" class="tab-btn <?= $dept === 'GASTRO' ? 'active active-gastro' : '' ?>">胃腸科</a>
        </div>

        <!-- 検査種別フィルター4タブ -->
        <div class="exam-tabs" style="margin-left: 6px;">
            <a href="?date=<?= $date_str ?>&dept=<?= $dept ?>&exam_filter=ALL" class="tab-btn <?= $exam_filter === 'ALL' ? 'active' : '' ?>">🔬 全内視鏡</a>
            <a href="?date=<?= $date_str ?>&dept=<?= $dept ?>&exam_filter=CF" class="tab-btn <?= $exam_filter === 'CF' ? 'active' : '' ?>" style="<?= $exam_filter === 'CF' ? 'background:#059669;color:#fff;' : '' ?>">🟢 大腸系 (TCF)</a>
            <a href="?date=<?= $date_str ?>&dept=<?= $dept ?>&exam_filter=ENEMA" class="tab-btn <?= $exam_filter === 'ENEMA' ? 'active' : '' ?>" style="<?= $exam_filter === 'ENEMA' ? 'background:#7c3aed;color:#fff;' : '' ?>">🟣 浣腸CF</a>
            <a href="?date=<?= $date_str ?>&dept=<?= $dept ?>&exam_filter=GIF" class="tab-btn <?= $exam_filter === 'GIF' ? 'active' : '' ?>" style="<?= $exam_filter === 'GIF' ? 'background:#0284c7;color:#fff;' : '' ?>">🔵 胃カメラ</a>
        </div>

        <!-- 院内滞在中クイック絞り込みボタン -->
        <button type="button" class="btn-active-stay" id="btnStayOnly" onclick="toggleStayOnlyFilter()" title="未受付(来院前)・帰宅完了を除外し、現在院内に滞在している患者のみ表示">
            <span>🏃 滞在中のみ</span>
        </button>
    </div>
</header>

<!-- 画面右端固定 五十音インデックスバー -->
<aside class="kana-index-bar" id="kanaIndexBar" aria-label="五十音頭文字絞り込み">
    <button type="button" class="kana-btn active" data-kana="ALL" onclick="setKanaFilter('ALL', this)" title="全件表示">全</button>
    <button type="button" class="kana-btn" data-kana="あ" onclick="setKanaFilter('あ', this)" title="あ行 (あ・い・う・え・お)">あ</button>
    <button type="button" class="kana-btn" data-kana="か" onclick="setKanaFilter('か', this)" title="か行 (か・き・く・け・こ / が・ぎ・ぐ・げ・ご)">か</button>
    <button type="button" class="kana-btn" data-kana="さ" onclick="setKanaFilter('さ', this)" title="さ行 (さ・し・す・せ・そ / ざ・じ・ず・ぜ・ぞ)">さ</button>
    <button type="button" class="kana-btn" data-kana="た" onclick="setKanaFilter('た', this)" title="た行 (た・ち・つ・て・と / だ・ぢ・づ・で・ど)">た</button>
    <button type="button" class="kana-btn" data-kana="な" onclick="setKanaFilter('な', this)" title="な行 (な・に・ぬ・ね・の)">な</button>
    <button type="button" class="kana-btn" data-kana="は" onclick="setKanaFilter('は', this)" title="は行 (は・ひ・ふ・へ・ほ / ば・び・ぶ・べ・ぼ / ぱ・ぴ・ぷ・ぺ・ぽ)">は</button>
    <button type="button" class="kana-btn" data-kana="ま" onclick="setKanaFilter('ま', this)" title="ま行 (ま・み・む・め・も)">ま</button>
    <button type="button" class="kana-btn" data-kana="や" onclick="setKanaFilter('や', this)" title="や行 (や・ゆ・よ)">や</button>
    <button type="button" class="kana-btn" data-kana="ら" onclick="setKanaFilter('ら', this)" title="ら行 (ら・り・る・れ・ろ)">ら</button>
    <button type="button" class="kana-btn" data-kana="わ" onclick="setKanaFilter('わ', this)" title="わ行 (わ・を・ん)">わ</button>
</aside>

<!-- 動線サマリーバー (1行スリムインライン化) -->
<section class="summary-bar-inline" id="summaryBar">
    <div class="summary-pill summary-pill-total" onclick="clearStatusFilter()" title="全患者を表示 (フィルター解除)">
        <span class="pill-label">👥 計</span>
        <span class="pill-num" id="sumTotal">-</span>
    </div>
    <div class="summary-divider"></div>
    <div class="summary-pill" onclick="quickFilterExam('CF')" title="大腸系(TCF)のみ絞り込み">
        <span class="pill-label">🟢 TCF</span>
        <span class="pill-num text-success" id="sumCF">-</span>
    </div>
    <div class="summary-pill" onclick="quickFilterExam('ENEMA')" title="🟣 浣腸CFのみ絞り込み">
        <span class="pill-label">🟣 浣腸</span>
        <span class="pill-num text-purple" id="sumEnema">-</span>
    </div>
    <div class="summary-pill" onclick="quickFilterExam('GIF')" title="🔵 胃カメラ(GIF)のみ絞り込み">
        <span class="pill-label">🔵 胃</span>
        <span class="pill-num text-primary" id="sumGIF">-</span>
    </div>
    <div class="summary-divider"></div>
    <div class="summary-pill summary-filter-st" data-status="1" onclick="toggleStatusFilter(1)" title="1. 受付済のみ絞り込み">
        <span class="pill-label">1.受付</span>
        <span class="pill-num text-primary" id="sumSt1">-</span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="2" onclick="toggleStatusFilter(2)" title="2. マイトイレ案内中のみ絞り込み">
        <span class="pill-label">2.トイレ</span>
        <span class="pill-num text-warning" id="sumSt2">-</span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="3" onclick="toggleStatusFilter(3)" title="3. 検査待ち（呼出OK／待機中）のみ絞り込み">
        <span class="pill-label">3.検査待</span>
        <span class="pill-num text-success" id="sumSt3">-</span>
        <span id="sumSt3Sub" style="font-size:10px; font-weight:800; color:#94a3b8; margin-left:3px; display:none;"></span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="4" onclick="toggleStatusFilter(4)" title="4. 検査中のみ絞り込み">
        <span class="pill-label">4.検査中</span>
        <span class="pill-num text-danger" id="sumSt4">-</span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="5" onclick="toggleStatusFilter(5)" title="5. リカバリ中のみ絞り込み">
        <span class="pill-label">5.リカバリ</span>
        <span class="pill-num text-indigo" id="sumSt5">-</span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="exp" onclick="toggleStatusFilter('exp')" title="説明待ち(医師待)のみ絞り込み" id="cardExplainWait">
        <span class="pill-label">🔔説明待</span>
        <span class="pill-num text-warning" id="sumExpWait">-</span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="6" onclick="toggleStatusFilter(6)" title="6. 結果説明中のみ絞り込み">
        <span class="pill-label">6.説明中</span>
        <span class="pill-num text-purple" id="sumSt6">-</span>
    </div>
    <div class="summary-pill summary-filter-st" data-status="7" onclick="toggleStatusFilter(7)" title="7. 帰宅・完了のみ絞り込み">
        <span class="pill-label">7.完了</span>
        <span class="pill-num text-muted" id="sumSt7">-</span>
    </div>
    <div class="summary-divider"></div>
    <div class="summary-pill summary-pill-alert summary-filter-st" data-status="biopsy" id="cardBiopsyAlert" onclick="toggleStatusFilter('biopsy')" title="🚨 検体未照合のみ絞り込み">
        <span class="pill-label">🚨未照合</span>
        <span class="pill-num text-danger" id="sumBiopsyAlert">-</span>
    </div>
    <div class="summary-pill summary-pill-alert" id="cardAntiAlert" style="display:none;" onclick="openMedicalAlertScanModal({date: currentDate, dept: currentDept})" title="💊 休薬要確認モーダルを開く">
        <span class="pill-label">💊休薬</span>
        <span class="pill-num text-danger" id="sumAntiAlert">0</span>
    </div>
    <div class="summary-pill" id="cardInfectionAlert" style="display:none;" onclick="openMedicalAlertScanModal({date: currentDate, dept: currentDept})" title="🔴 感染症注意">
        <span class="pill-label">🔴感染症</span>
        <span class="pill-num" style="color:#f43f5e;" id="sumInfectionAlert">0</span>
    </div>
    <div class="summary-pill" id="cardAllergyAlert" style="display:none;" onclick="openMedicalAlertScanModal({date: currentDate, dept: currentDept})" title="⚠️ 局麻注意">
        <span class="pill-label">⚠️局麻</span>
        <span class="pill-num text-warning" id="sumAllergyAlert">0</span>
    </div>
    <div class="summary-pill" id="cardColdAlert" style="display:none;" onclick="openMedicalAlertScanModal({date: currentDate, dept: currentDept})" title="😷 風邪・発熱">
        <span class="pill-label">😷風邪</span>
        <span class="pill-num" style="color:#60a5fa;" id="sumColdAlert">0</span>
    </div>
</section>

<!-- トースト通知 -->
<div id="savedToast" class="saved-toast"></div>

<!-- メインコンテナ -->
<main class="main-container" id="mainContainer">
    <!-- 職種専用ガイダンス & インジケーターバー -->
    <div id="roleGuidanceBar" class="role-guidance-bar"></div>

    <!-- 絞り込み中バー -->
    <div id="activeFilterBanner" class="active-filter-banner" style="display:none;"></div>

    <!-- タイル表示グリッド (表示モード: tile の時に表示) -->
    <div id="tileGridView" class="tile-grid-view" style="display:none;">
        <div id="patientTileGrid" class="patient-tile-grid"></div>
    </div>

    <!-- テーブル表示 (表示モード: compact または standard の時に表示) -->
    <div id="tableViewWrap" class="table-view-wrap">
        <table class="monitor-table" id="monitorTable">
            <thead>
                <tr>
                    <th class="col-time">枠 / 科</th>
                    <th class="col-patient">患者情報 ＆ 💉鎮静</th>
                    <th class="col-arrival">受付時刻</th>
                    <th class="col-prep">マイトイレ / 下剤</th>
                    <th class="col-timeline">動線ステータス進捗 (7段階)</th>
                    <th class="col-tasks">検体 ＆ 洗浄タスク</th>
                    <th class="col-recovery">リカバリ / 説明</th>
                </tr>
            </thead>
            <tbody id="patientListBody">
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                        読み込み中...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</main>

<!-- ============================================================
モーダル：セデーション (鎮静剤) 設定切替
============================================================ -->
<div class="modal-overlay" id="sedationModal">
    <div class="modal-card">
        <div class="modal-title" style="color: #c084fc;">
            <span>💉 セデーション (鎮静剤) 設定変更</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="modalSedationSchedId" value="">
            <p style="font-size: 13px; margin-bottom: 14px;">
                患者: <strong id="modalSedationPatientName" style="color:#ffffff;"></strong> さん
            </p>

            <div class="form-group">
                <label class="form-label">鎮静剤の使用</label>
                <div style="display: flex; gap: 16px; margin-bottom: 10px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <input type="radio" name="modalHasSedation" value="1" id="radioSedYes" onchange="toggleSedationDetails(true)" checked>
                        <span style="font-weight: bold; color: #a855f7;">あり (セデーション実施)</span>
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <input type="radio" name="modalHasSedation" value="0" id="radioSedNo" onchange="toggleSedationDetails(false)">
                        <span style="color: var(--text-muted);">なし (覚醒下 / 咽頭麻酔のみ)</span>
                    </label>
                </div>
            </div>

            <div id="sedationDetailBox">
                <div class="form-group">
                    <label class="form-label">使用薬剤種別</label>
                    <select id="modalSedationType" class="form-input">
                        <option value="PROPOFOL">プロポフォール (短時間覚醒・肛門科標準 / 安静20分)</option>
                        <option value="DIAZEPAM">ジアゼパム / セルシン (胃腸科標準 / 安静30分)</option>
                        <option value="MIDAZOLAM">ドルミカム / ミダゾラム等 (安静30分)</option>
                        <option value="OTHER">その他鎮静薬</option>
                    </select>
                </div>
            </div>
            
            <p style="font-size: 11px; color: var(--text-sub); line-height: 1.5;">
                ※「なし」に設定した場合、検査終了後はリカバリ室をスキップして直接「説明待ち」へ進めます。
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('sedationModal')">キャンセル</button>
            <button type="button" class="btn-save" onclick="saveSedationSetting()">設定を保存</button>
        </div>
    </div>
</div>

<!-- ============================================================
モーダル：組織採取・検体照合モーダル (4➔5連動・安全照合)
============================================================ -->
<div class="modal-overlay" id="biopsyModal">
    <div class="modal-card">
        <div class="modal-title">
            <span>🧪 組織採取・病理検体 カルテ声出し照合</span>
        </div>
        <div class="modal-body">
            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                部位取違い・採取個数ミスのインシデント防止のため、検体瓶ラベル・カルテオーダーと声出し照合を行ってください。
            </p>
            <input type="hidden" id="modalBiopsySchedId" value="">
            
            <div class="form-group">
                <label class="form-label">組織採取（生検・ポリペクトミー）</label>
                <div style="display: flex; gap: 16px;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <input type="radio" name="modalHasBiopsy" value="0" onchange="toggleBiopsyFields(false)">
                        <span>なし (採取ゼロ)</span>
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <input type="radio" name="modalHasBiopsy" value="1" onchange="toggleBiopsyFields(true)" checked>
                        <span style="font-weight: bold; color: #f87171;">あり (採取あり)</span>
                    </label>
                </div>
            </div>

            <div id="biopsyDetailFields">
                <div class="form-group">
                    <label class="form-label">採取本数 / 瓶数</label>
                    <input type="number" id="modalBiopsyCount" class="form-input" min="1" max="10" value="1" style="width: 100px;">
                </div>

                <div class="form-group">
                    <label class="form-label">採取部位クイック選択（クリックで追加）</label>
                    <div class="site-chips-group">
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('盲腸(C)')">盲腸(C)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('上行(A)')">上行(A)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('横行(T)')">横行(T)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('下行(D)')">下行(D)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('S状(S)')">S状(S)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('直腸(Rb/Ra)')">直腸(Rb/Ra)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('肛門管(P)')">肛門管(P)</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('胃角部')">胃角部</button>
                        <button type="button" class="site-chip-btn" onclick="addBiopsySite('胃前庭部')">胃前庭部</button>
                    </div>
                    <input type="text" id="modalBiopsySites" class="form-input" style="margin-top: 8px;" placeholder="例: S状(S):1, 上行(A):2">
                </div>

                <div class="form-group" style="background: rgba(16, 185, 129, 0.1); border: 1px solid #059669; padding: 10px; border-radius: var(--radius-md);">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: bold; color: #34d399;">
                        <input type="checkbox" id="modalBiopsyVerified" value="1">
                        <span>カルテオーダー・検体瓶ラベル・患者氏名を声出し照合完了</span>
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('biopsyModal')">キャンセル</button>
            <button type="button" class="btn-save" onclick="saveBiopsyData()">検体照合を保存</button>
        </div>
    </div>
</div>

<!-- ============================================================
モーダル：ステータス巻き戻し (Undo)
============================================================ -->
<div class="modal-overlay" id="undoModal">
    <div class="modal-card">
        <div class="modal-title" style="color: #f87171;">
            <span>◀ ステータス巻き戻し (誤操作取り消し)</span>
        </div>
        <div class="modal-body">
            <input type="hidden" id="modalUndoSchedId" value="">
            <p style="font-size: 13px; margin-bottom: 14px;">
                患者: <strong id="modalUndoPatientName" style="color:#ffffff;"></strong> さんのステータスを戻します。
            </p>

            <div class="form-group">
                <label class="form-label">戻す先のステータス</label>
                <select id="modalUndoTargetStatus" class="form-input"></select>
            </div>

            <div class="form-group">
                <label class="form-label">戻す理由（履歴ログに記録されます）</label>
                <select id="modalUndoReason" class="form-input" onchange="checkCustomReason(this.value)">
                    <option value="ボタン押し間違い (誤タップ)">ボタン押し間違い (誤タップ)</option>
                    <option value="患者取り違えクリック">患者取り違えクリック</option>
                    <option value="前処置の再確認が必要">前処置の再確認が必要</option>
                    <option value="患者体調不良・一時待機">患者体調不良・一時待機</option>
                    <option value="医師指示による順番変更">医師指示による順番変更</option>
                    <option value="その他">その他 (自由入力)</option>
                </select>
                <input type="text" id="modalUndoReasonCustom" class="form-input" style="display:none; margin-top:6px;" placeholder="理由を入力してください">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('undoModal')">キャンセル</button>
            <button type="button" class="btn-danger-action" onclick="submitRevertStatus()">ステータスを戻す</button>
        </div>
    </div>
</div>

<!-- ============================================================
モーダル：リカバリ目標時間調整
============================================================ -->
<div class="modal-overlay" id="recoveryTargetModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-title" style="color: #60a5fa; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span>⏱️ リカバリ時間調整</span>
            </div>
            <button type="button" onclick="closeModal('recoveryTargetModal')" style="background:transparent; border:none; color:var(--text-muted); font-size:18px; cursor:pointer; padding:2px 6px;">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="modalRecoverySchedId" value="">
            <input type="hidden" id="modalRecoveryElapsed" value="0">
            <input type="hidden" id="modalRecoveryCurrentTarget" value="30">

            <div style="background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(148, 163, 184, 0.2); padding: 10px 14px; border-radius: var(--radius-md); margin-bottom: 14px;">
                <div style="font-size: 13.5px; font-weight: 700; color: #ffffff;" id="modalRecoveryPatientName">患者名: ---</div>
                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;" id="modalRecoveryStatusSummary">
                    経過: 0分 ／ 目標: 30分 (残り 30分)
                </div>
            </div>

            <!-- 1. 今から あと〇分（直感・直接残時間指定） -->
            <div class="form-group">
                <label class="form-label" style="color: #34d399; font-weight: 800;">🌟【今から あと〇分】(計算不要・直接残時間を指定)</label>
                <div class="recovery-btn-grid">
                    <button type="button" class="recovery-quick-btn btn-direct-remaining" onclick="applyDirectRemaining(5)">あと 5分</button>
                    <button type="button" class="recovery-quick-btn btn-direct-remaining" onclick="applyDirectRemaining(10)">あと 10分</button>
                    <button type="button" class="recovery-quick-btn btn-direct-remaining" onclick="applyDirectRemaining(15)">あと 15分</button>
                    <button type="button" class="recovery-quick-btn btn-direct-remaining" onclick="applyDirectRemaining(20)">あと 20分</button>
                    <button type="button" class="recovery-quick-btn btn-direct-remaining" onclick="applyDirectRemaining(30)">あと 30分</button>
                </div>
            </div>

            <!-- 2. 現在の目標にプラス・マイナス -->
            <div class="form-group" style="margin-top: 14px;">
                <label class="form-label" style="color: #93c5fd; font-weight: 700;">➕【現在の目標時間に加算・短縮】</label>
                <div class="recovery-btn-grid">
                    <button type="button" class="recovery-quick-btn" onclick="applyDeltaTarget(5)">＋5分</button>
                    <button type="button" class="recovery-quick-btn" onclick="applyDeltaTarget(10)">＋10分</button>
                    <button type="button" class="recovery-quick-btn" onclick="applyDeltaTarget(15)">＋15分</button>
                    <button type="button" class="recovery-quick-btn" style="color:#fca5a5; border-color:rgba(239,68,68,0.4);" onclick="applyDeltaTarget(-5)">－5分</button>
                    <button type="button" class="recovery-quick-btn" style="color:#fca5a5; border-color:rgba(239,68,68,0.4);" onclick="applyDeltaTarget(-10)">－10分</button>
                </div>
            </div>

            <!-- 3. 定番プリセット -->
            <div class="form-group" style="margin-top: 14px;">
                <label class="form-label">🎯【定番の目標時間から選択】</label>
                <div class="recovery-btn-grid">
                    <button type="button" class="recovery-quick-btn btn-preset" onclick="saveRecoveryTargetValue(15)">15分</button>
                    <button type="button" class="recovery-quick-btn btn-preset" onclick="saveRecoveryTargetValue(20)">20分</button>
                    <button type="button" class="recovery-quick-btn btn-preset" onclick="saveRecoveryTargetValue(30)">30分</button>
                    <button type="button" class="recovery-quick-btn btn-preset" onclick="saveRecoveryTargetValue(40)">40分</button>
                    <button type="button" class="recovery-quick-btn btn-preset" onclick="saveRecoveryTargetValue(45)">45分</button>
                    <button type="button" class="recovery-quick-btn btn-preset" onclick="saveRecoveryTargetValue(60)">60分</button>
                </div>
            </div>

            <!-- 4. 直接数値入力 -->
            <div class="form-group" style="margin-top: 14px; padding-top: 10px; border-top: 1px dashed rgba(148, 163, 184, 0.2);">
                <label class="form-label">✏️【任意の目標分数を指定】</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="font-size: 12.5px; color: var(--text-muted);">目標:</span>
                    <input type="number" id="modalRecoveryInputMinutes" class="form-input" min="5" max="300" step="1" style="width: 85px; text-align: center; font-size: 13.5px; font-weight: 700;">
                    <span style="font-size: 12.5px; color: #ffffff;">分</span>
                    <button type="button" class="btn-save" style="margin-left: auto; padding: 6px 16px;" onclick="saveCustomRecoveryTarget()">保存</button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('recoveryTargetModal')">閉じる</button>
        </div>
    </div>
</div>

<!-- ============================================================
モーダル：安静タイマー完了アラート (視覚点滅 ＆ 音声連動)
============================================================ -->
<div class="modal-overlay" id="recoveryAlertModal" style="z-index: 9999;">
    <div class="modal-card">
        <div class="modal-title" style="color: #f87171; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid rgba(239, 68, 68, 0.4); margin-bottom: 12px; padding-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 22px; animation: pulse-live 1s infinite;">🚨</span>
                <span style="font-size: 16px; font-weight: 900; letter-spacing: 0.5px;">【安静タイマー完了】 覚醒確認を行ってください</span>
            </div>
            <button type="button" onclick="dismissAllAlerts()" style="background:transparent; border:none; color:var(--text-muted); font-size:22px; cursor:pointer; padding:2px 8px;" title="アラートを停止して閉じる">✕</button>
        </div>
        <div class="modal-body" style="margin-top: 10px; max-height: 60vh; overflow-y: auto;">
            <p style="font-size: 12.5px; color: #fca5a5; margin-bottom: 12px; font-weight: 600; line-height: 1.5;">
                以下の患者様のリカバリ安静目安時間を達成しました。患者様の覚醒状態とバイタルを確認してください。<br>
                <span style="font-size: 11.5px; color: #94a3b8; font-weight: normal;">※いずれかのアクションを行うか「閉じる」を押すと、アラーム音と画面点滅は停止します。</span>
            </p>
            <div id="recoveryAlertList">
                <!-- 動的生成 -->
            </div>
        </div>
        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
            <span style="font-size: 11.5px; color: #94a3b8;">⏱️ 15秒ごとにアラーム音が鳴動します</span>
            <button type="button" class="btn-cancel" style="padding: 7px 18px; font-weight: 700;" onclick="dismissAllAlerts()">すべてのアラートを停止して閉じる</button>
        </div>
    </div>
</div>

<script>
    var currentRole = localStorage.getItem('endoscope_role_view') || 'all';
    var currentViewMode = localStorage.getItem('endoscope_view_mode') || (currentRole === 'all' ? 'tile' : 'compact');
    var activeKanaFilter = 'ALL';
    var activeStatusFilter = null; // null or 1..7, 'exp', 'biopsy'
    var isStayOnlyActive = false;

    var refreshIntervalSec = 10;
    var savedInterval = localStorage.getItem('endoscope_refresh_sec');
    if (savedInterval !== null && !isNaN(parseInt(savedInterval, 10))) {
        refreshIntervalSec = parseInt(savedInterval, 10);
    }
    var roleOnlyFilter = false;
    var refreshTimer = null;
    var countdownTimer = null;
    var remainingSeconds = refreshIntervalSec;
    var cachedData = null;
    var currentDate = '<?= $date_str ?>';
    var currentDept = '<?= $dept ?>';
    var currentExamFilter = '<?= $exam_filter ?>';

    var ACTIVE_DOCTORS = <?= json_encode($active_doctors) ?>;
    var selectedDoctor = localStorage.getItem('endoscope_selected_doctor') || '';
    var doctorNotifData = {
        unreadCount: 0,
        maxLogId: 0,
        notifications: []
    };
    var isNotifPanelOpen = false;

    function showSavedToast(msg) {
        var t = document.getElementById('savedToast');
        if (!t) return;
        t.innerHTML = '<span>💾</span> <span>' + escapeHtml(msg) + '</span>';
        t.style.display = 'flex';
        if (window._toastTimeout) clearTimeout(window._toastTimeout);
        window._toastTimeout = setTimeout(function() {
            t.style.display = 'none';
        }, 2200);
    }

    document.addEventListener('DOMContentLoaded', function() {
        // 保存された更新間隔のプルダウン反映
        var sel = document.getElementById('refreshIntervalSelect');
        if (sel) {
            sel.value = refreshIntervalSec.toString();
        }

        // 保存された職種ビューのタブ反映
        var validRoles = ['all', 'doctor', 'nurse', 'assistant', 'clerk'];
        if (validRoles.indexOf(currentRole) === -1) {
            currentRole = 'all';
        }
        document.querySelectorAll('.role-tabs .tab-btn').forEach(function(b) {
            b.classList.remove('active');
            if (b.getAttribute('data-role') === currentRole) {
                b.classList.add('active');
            }
        });

        // 保存された表示モードの反映
        applyViewMode(currentViewMode, false);

        applyRoleVisibility();
        updateDoctorNotifVisibility();
        if (currentRole === 'doctor' && selectedDoctor) {
            fetchDoctorNotifications();
        }
        fetchProgressData(false);
        startRefreshLoop();
    });

    function switchRoleView(role, btn) {
        currentRole = role;
        roleOnlyFilter = false; // ビュー切り替え時は全員表示から開始
        try {
            localStorage.setItem('endoscope_role_view', role);
            var roleTitles = {
                'all': '全体俯瞰',
                'doctor': '医師',
                'nurse': '看護師',
                'assistant': '補助看護師',
                'clerk': '受付事務'
            };
            showSavedToast('表示職種を端末に保存: ' + (roleTitles[role] || role));
        } catch(e) {}

        document.querySelectorAll('.role-tabs .tab-btn').forEach(function(b) {
            b.classList.remove('active');
        });
        if (btn) {
            btn.classList.add('active');
        } else {
            var targetBtn = document.querySelector('.role-tabs .tab-btn[data-role="' + role + '"]');
            if (targetBtn) targetBtn.classList.add('active');
        }

        // 職種別初期モード連動 (全体俯瞰はタイル、それ以外は高密度表)
        var preferredMode = (role === 'all') ? 'tile' : 'compact';
        applyViewMode(preferredMode, true);

        updateDoctorNotifVisibility();
        if (currentRole === 'doctor') {
            if (!selectedDoctor) {
                openDoctorPickerModal();
            } else {
                fetchDoctorNotifications();
            }
        }

        applyRoleVisibility();
    }

    function applyRoleVisibility() {
        var table = document.getElementById('monitorTable');
        var tileGrid = document.getElementById('patientTileGrid');
        var mainContainer = document.getElementById('mainContainer');
        var roleClass = 'role-' + currentRole;
        if (table) {
            table.classList.remove('role-doctor', 'role-nurse', 'role-assistant', 'role-clerk', 'role-all');
            table.classList.add(roleClass);
        }
        if (tileGrid) {
            tileGrid.classList.remove('role-doctor', 'role-nurse', 'role-assistant', 'role-clerk', 'role-all');
            tileGrid.classList.add(roleClass);
        }
        if (mainContainer) {
            mainContainer.classList.remove('role-doctor', 'role-nurse', 'role-assistant', 'role-clerk', 'role-all');
            mainContainer.classList.add(roleClass);
        }
        updateRoleGuidanceBar();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    /* ============================================================
       表示モード切替 (🔲 タイル / 📑 高密度表 / 📄 標準表)
    ============================================================ */
    function switchViewMode(mode) {
        currentViewMode = mode;
        try {
            localStorage.setItem('endoscope_view_mode', mode);
            var modeLabels = { 'tile': 'タイル表示', 'compact': '高密度表', 'standard': '標準表' };
            showSavedToast('表示モードを端末に保存: ' + (modeLabels[mode] || mode));
        } catch(e) {}
        applyViewMode(mode, false);
    }

    function applyViewMode(mode, isAutoRoleSwitch) {
        currentViewMode = mode;
        var btnTile = document.getElementById('btnModeTile');
        var btnCompact = document.getElementById('btnModeCompact');
        var btnStandard = document.getElementById('btnModeStandard');
        if (btnTile) btnTile.classList.toggle('active', mode === 'tile');
        if (btnCompact) btnCompact.classList.toggle('active', mode === 'compact');
        if (btnStandard) btnStandard.classList.toggle('active', mode === 'standard');

        var container = document.getElementById('mainContainer');
        if (container) {
            container.classList.toggle('view-mode-compact', mode === 'compact');
        }

        var tileView = document.getElementById('tileGridView');
        var tableView = document.getElementById('tableViewWrap');
        if (tileView && tableView) {
            if (mode === 'tile') {
                tileView.style.display = 'block';
                tableView.style.display = 'none';
            } else {
                tileView.style.display = 'none';
                tableView.style.display = 'block';
            }
        }
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    /* ============================================================
       絞り込み (五十音インデックス / サマリーステータス / 滞在中)
    ============================================================ */
    function setKanaFilter(kana, btnElem) {
        if (activeKanaFilter === kana && kana !== 'ALL') {
            activeKanaFilter = 'ALL';
        } else {
            activeKanaFilter = kana;
        }
        document.querySelectorAll('.kana-btn').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-kana') === activeKanaFilter);
        });
        updateActiveFilterBanner();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    function toggleStatusFilter(st) {
        if (activeStatusFilter === st) {
            activeStatusFilter = null;
        } else {
            activeStatusFilter = st;
        }
        updateStatusFilterUI();
        updateActiveFilterBanner();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    function clearStatusFilter() {
        activeStatusFilter = null;
        activeKanaFilter = 'ALL';
        isStayOnlyActive = false;
        updateStatusFilterUI();
        document.querySelectorAll('.kana-btn').forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-kana') === 'ALL');
        });
        var btnStay = document.getElementById('btnStayOnly');
        if (btnStay) btnStay.classList.remove('active');
        updateActiveFilterBanner();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    function updateStatusFilterUI() {
        document.querySelectorAll('.summary-filter-st').forEach(function(el) {
            var st = el.getAttribute('data-status');
            if (activeStatusFilter !== null && String(activeStatusFilter) === String(st)) {
                el.classList.add('active-filter');
            } else {
                el.classList.remove('active-filter');
            }
        });
    }

    function toggleStayOnlyFilter() {
        isStayOnlyActive = !isStayOnlyActive;
        var btn = document.getElementById('btnStayOnly');
        if (btn) {
            btn.classList.toggle('active', isStayOnlyActive);
        }
        updateActiveFilterBanner();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    function quickFilterExam(type) {
        var url = '?date=' + encodeURIComponent(currentDate) + '&dept=' + encodeURIComponent(currentDept) + '&exam_filter=' + encodeURIComponent(type);
        window.location.href = url;
    }

    function updateActiveFilterBanner() {
        var banner = document.getElementById('activeFilterBanner');
        if (!banner) return;
        var tags = [];
        if (activeKanaFilter && activeKanaFilter !== 'ALL') {
            tags.push('頭文字「' + activeKanaFilter + '行」');
        }
        if (activeStatusFilter !== null) {
            var stNames = { 1: '1.受付済', 2: '2.マイトイレ案内', 3: '3.検査待ち', 4: '4.検査中', 5: '5.リカバリ中', 6: '6.結果説明中', 7: '7.完了', 'exp': '🔔説明待ち', 'biopsy': '🚨検体未照合' };
            tags.push('ステータス「' + (stNames[activeStatusFilter] || activeStatusFilter) + '」');
        }
        if (isStayOnlyActive) {
            tags.push('🏃 院内滞在中のみ');
        }

        if (tags.length > 0) {
            banner.style.display = 'flex';
            banner.innerHTML = '<span>🔍 絞り込み中: <strong>' + tags.join(' ＋ ') + '</strong></span>' +
                '<button type="button" class="btn-clear-filter" onclick="clearStatusFilter()">✕ 絞り込み全解除</button>';
        } else {
            banner.style.display = 'none';
            banner.innerHTML = '';
        }
    }

    function focusPatientInTable(scheduleId) {
        // タイルから該当行へのフォーカスジャンプ
        switchViewMode('compact');
        setTimeout(function() {
            var row = document.getElementById('row-sched-' + scheduleId);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.remove('row-targeted-highlight');
                void row.offsetWidth; // reflow
                row.classList.add('row-targeted-highlight');
                setTimeout(function() {
                    row.classList.remove('row-targeted-highlight');
                }, 2800);
            }
        }, 100);
    }

    function toggleRoleFilter() {
        roleOnlyFilter = !roleOnlyFilter;
        updateRoleGuidanceBar();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    function updateRoleGuidanceBar() {
        var bar = document.getElementById('roleGuidanceBar');
        if (!bar) return;
        var sum = cachedData ? cachedData.summary : null;
        var stCounts = sum ? sum.status_counts : {};
        var unverifiedBiopsy = sum ? sum.unverified_biopsy_count : 0;
        var scopeOrdered = sum ? sum.scope_cleaning_pending_count : 0;
        var explainWait = sum ? sum.explain_waiting_count : 0;

        var html = '';
        var filterBtnHtml = '';

        if (currentRole === 'doctor') {
            var docTargetCount = (stCounts[3] || 0) + (stCounts[4] || 0) + (explainWait || 0);
            filterBtnHtml = '<button type="button" class="role-filter-btn ' + (roleOnlyFilter ? 'active' : '') + '" onclick="toggleRoleFilter()">' +
                (roleOnlyFilter ? '🔥 診察・検査対象のみ表示中 (' + docTargetCount + '名) ✕解除' : '🔍 診察・検査対象のみに絞り込む (' + docTargetCount + '名)') +
                '</button>';

            var docSelectHtml = '<div style="display:inline-flex; align-items:center; gap:6px; margin-left:10px; background:rgba(15,23,42,0.6); padding:2px 8px; border-radius:6px; border:1px solid #059669;">' +
                '<span style="font-size:11.5px; color:#6ee7b7; font-weight:800;">👨‍⚕️ 担当:</span>' +
                '<select class="select-dark" style="font-weight:800; font-size:12px; padding:2px 6px; border-color:#059669; color:#ffffff; background:#0f172a;" onchange="selectDoctor(this.value)">' +
                '<option value="" ' + (!selectedDoctor ? 'selected' : '') + '>🌐 全医師</option>';
            ACTIVE_DOCTORS.forEach(function(d) {
                docSelectHtml += '<option value="' + escapeHtml(d) + '" ' + (selectedDoctor === d ? 'selected' : '') + '>' + escapeHtml(d) + ' 先生</option>';
            });
            docSelectHtml += '</select>' +
                '<button type="button" class="btn-doctor-action" style="padding:2px 7px; font-size:11px;" onclick="openDoctorPickerModal()">🔄 切替</button>' +
                '</div>';

            html += '<div class="role-guidance-left">';
            html += '  <span class="role-guidance-icon">👨‍⚕️</span>';
            html += '  <div><strong>医師ビュー:</strong> 検査待ち・検査中・カルテ特記を最優先表示</div>';
            html += '  ' + docSelectHtml;
            html += '</div>';
            var readyCnt = sum ? (sum.exam_ready_count || 0) : 0;
            var waitCnt = sum ? (sum.waiting_count || 0) : 0;
            html += '<div class="role-guidance-chips">';
            html += '  <span class="role-guidance-chip role-chip-success" style="box-shadow: 0 0 8px rgba(16,185,129,0.5);">🚪 呼出OK: ' + readyCnt + '名</span>';
            if (waitCnt > 0) {
                html += '  <span class="role-guidance-chip role-chip-warning">⏳ 空き待ち: ' + waitCnt + '名</span>';
            }
            html += '  <span class="role-guidance-chip role-chip-danger">🔬 検査中: ' + (stCounts[4] || 0) + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-purple">💬 説明待ち: ' + (explainWait || 0) + '名</span>';
            html += '  ' + filterBtnHtml;
            html += '</div>';
        } else if (currentRole === 'nurse') {
            var nurseTargetCount = (stCounts[3] || 0) + (stCounts[4] || 0) + (stCounts[5] || 0) + unverifiedBiopsy;
            filterBtnHtml = '<button type="button" class="role-filter-btn ' + (roleOnlyFilter ? 'active' : '') + '" onclick="toggleRoleFilter()">' +
                (roleOnlyFilter ? '🚨 看護師要対応のみ表示中 ✕解除' : '🔍 介助・リカバリ・未照合のみ絞り込む') +
                '</button>';

            html += '<div class="role-guidance-left">';
            html += '  <span class="role-guidance-icon">👩‍⚕️</span>';
            html += '  <div><strong>看護師ビュー:</strong> 検査準備・介助・検体声出し照合・リカバリ安静タイマーを最大強調中</div>';
            html += '</div>';
            html += '<div class="role-guidance-chips">';
            if (unverifiedBiopsy > 0) {
                html += '  <span class="role-guidance-chip role-chip-danger" style="animation:pulse-live 1.2s infinite;">🚨 検体未照合: ' + unverifiedBiopsy + '件</span>';
            } else {
                html += '  <span class="role-guidance-chip role-chip-success">✅ 検体照合全完了</span>';
            }
            html += '  <span class="role-guidance-chip role-chip-primary">⏱️ リカバリ安静中: ' + (stCounts[5] || 0) + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-warning">💉 鎮静患者: ' + (sum ? sum.sedation_count : 0) + '名</span>';
            html += '  ' + filterBtnHtml;
            html += '</div>';
        } else if (currentRole === 'assistant') {
            var assistTargetCount = (stCounts[2] || 0) + scopeOrdered;
            filterBtnHtml = '<button type="button" class="role-filter-btn ' + (roleOnlyFilter ? 'active' : '') + '" onclick="toggleRoleFilter()">' +
                (roleOnlyFilter ? '🧼 トイレ案内・洗浄指示のみ表示中 (' + assistTargetCount + '件) ✕解除' : '🔍 トイレ案内 ＆ 洗浄指示のみ絞り込む (' + assistTargetCount + '件)') +
                '</button>';

            html += '<div class="role-guidance-left">';
            html += '  <span class="role-guidance-icon">🧑‍🔬</span>';
            html += '  <div><strong>補助看護師ビュー:</strong> マイトイレ部屋割・下剤作成配薬・スコープ洗浄指示を特大強調（リカバリ列は非表示）</div>';
            html += '</div>';
            html += '<div class="role-guidance-chips">';
            html += '  <span class="role-guidance-chip role-chip-warning">🚽 マイトイレ案内中: ' + (stCounts[2] || 0) + '名</span>';
            if (scopeOrdered > 0) {
                html += '  <span class="role-guidance-chip role-chip-danger" style="animation:pulse-live 1.2s infinite;">🧼 スコープ洗浄指示: ' + scopeOrdered + '本</span>';
            } else {
                html += '  <span class="role-guidance-chip role-chip-success">🧼 洗浄指示なし</span>';
            }
            html += '  ' + filterBtnHtml;
            html += '</div>';
        } else if (currentRole === 'clerk') {
            var uncheckin = (stCounts[1] || 0);
            var checkoutWait = (stCounts[6] || 0);
            filterBtnHtml = '<button type="button" class="role-filter-btn ' + (roleOnlyFilter ? 'active' : '') + '" onclick="toggleRoleFilter()">' +
                (roleOnlyFilter ? '📋 未受付 ＆ 会計待ちのみ表示中 ✕解除' : '🔍 未受付 ＆ 会計待ちのみ絞り込む') +
                '</button>';

            html += '<div class="role-guidance-left">';
            html += '  <span class="role-guidance-icon">📋</span>';
            html += '  <div><strong>受付事務ビュー:</strong> 来院受付打刻・院内所在・会計帰宅完了に特化（医療処置列は自動非表示）</div>';
            html += '</div>';
            html += '<div class="role-guidance-chips">';
            html += '  <span class="role-guidance-chip role-chip-primary">🕒 未受付/受付済: ' + uncheckin + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-purple">🏥 院内滞在中: ' + ((stCounts[2]||0)+(stCounts[3]||0)+(stCounts[4]||0)+(stCounts[5]||0)+(stCounts[6]||0)) + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-success">🏁 帰宅完了: ' + (stCounts[7] || 0) + '名</span>';
            html += '  ' + filterBtnHtml;
            html += '</div>';
        } else {
            filterBtnHtml = '<button type="button" class="role-filter-btn ' + (roleOnlyFilter ? 'active' : '') + '" onclick="toggleRoleFilter()">' +
                (roleOnlyFilter ? '⏳ 院内滞在中のみ表示中 ✕解除' : '🔍 院内滞在中の患者のみ絞り込む') +
                '</button>';

            html += '<div class="role-guidance-left">';
            html += '  <span class="role-guidance-icon">🌐</span>';
            html += '  <div><strong>全体俯瞰ビュー:</strong> 内視鏡室全体の動線・全部署のステータスを均等フル表示中</div>';
            html += '</div>';
            html += '<div class="role-guidance-chips">';
            html += '  <span class="role-guidance-chip role-chip-primary">本日合計: ' + (sum ? sum.total : 0) + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-success">大腸系TCF: ' + (sum ? sum.cf_count : 0) + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-purple" style="background:#581c87; color:#f3e8ff; border-color:#a855f7;">浣腸CF: ' + (sum ? (sum.enema_count || 0) : 0) + '名</span>';
            html += '  <span class="role-guidance-chip role-chip-primary">胃カメラGIF: ' + (sum ? sum.gif_count : 0) + '名</span>';
            html += '  ' + filterBtnHtml;
            html += '</div>';
        }
        bar.innerHTML = html;
    }

    function startRefreshLoop() {
        if (refreshTimer) clearInterval(refreshTimer);
        if (countdownTimer) clearInterval(countdownTimer);

        if (refreshIntervalSec <= 0) {
            var bar = document.getElementById('refreshBar');
            if (bar) bar.style.width = '0%';
            return;
        }

        remainingSeconds = refreshIntervalSec;
        updateProgressBar();

        countdownTimer = setInterval(function() {
            remainingSeconds--;
            if (remainingSeconds < 0) remainingSeconds = refreshIntervalSec;
            updateProgressBar();
        }, 1000);

        refreshTimer = setInterval(function() {
            fetchProgressData(false);
            if (currentRole === 'doctor') {
                fetchDoctorNotifications();
            }
        }, refreshIntervalSec * 1000);
    }

    function updateProgressBar() {
        var bar = document.getElementById('refreshBar');
        if (!bar || refreshIntervalSec <= 0) return;
        var pct = (remainingSeconds / refreshIntervalSec) * 100;
        bar.style.width = pct + '%';
    }

    function changeRefreshInterval(sec) {
        refreshIntervalSec = parseInt(sec, 10);
        if (isNaN(refreshIntervalSec)) refreshIntervalSec = 10;
        try {
            localStorage.setItem('endoscope_refresh_sec', refreshIntervalSec.toString());
            var label = (refreshIntervalSec > 0) ? (refreshIntervalSec + '秒') : '停止';
            showSavedToast('更新間隔を端末に保存: ' + label);
        } catch(e) {}
        startRefreshLoop();
    }

    function fetchProgressData(showFeedback) {
        var url = 'get_progress_data.php?date=' + encodeURIComponent(currentDate) + 
                  '&dept=' + encodeURIComponent(currentDept) + 
                  '&exam_filter=' + encodeURIComponent(currentExamFilter);
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    cachedData = data;
                    renderSummary(data.summary);
                    updateRoleGuidanceBar();
                    renderTable(data);
                    if (showFeedback) {
                        showSavedToast('最新データに更新しました');
                    }
                } else {
                    console.error('API Error:', data.error);
                }
            })
            .catch(function(err) {
                console.error('Fetch Error:', err);
            });
    }

    function renderSummary(sum) {
        if (!sum) return;
        document.getElementById('sumTotal').textContent = sum.total;
        document.getElementById('sumCF').textContent = sum.cf_count || 0;
        if (document.getElementById('sumEnema')) {
            document.getElementById('sumEnema').textContent = sum.enema_count || 0;
        }
        document.getElementById('sumGIF').textContent = sum.gif_count || 0;
        document.getElementById('sumSt1').textContent = sum.status_counts[1] || 0;
        document.getElementById('sumSt2').textContent = sum.status_counts[2] || 0;
        var st3Total = sum.status_counts[3] || 0;
        document.getElementById('sumSt3').textContent = st3Total;
        var subEl = document.getElementById('sumSt3Sub');
        if (subEl) {
            if (st3Total > 0) {
                var readyCnt = sum.exam_ready_count || 0;
                var waitCnt = sum.waiting_count || 0;
                subEl.innerHTML = '(<span style="color:#34d399;" title="呼出OK">呼' + readyCnt + '</span>/<span style="color:#fde68a;" title="待機中">待' + waitCnt + '</span>)';
                subEl.style.display = 'inline';
            } else {
                subEl.style.display = 'none';
            }
        }
        document.getElementById('sumSt4').textContent = sum.status_counts[4] || 0;
        document.getElementById('sumSt5').textContent = sum.status_counts[5] || 0;
        document.getElementById('sumSt6').textContent = sum.status_counts[6] || 0;
        document.getElementById('sumSt7').textContent = sum.status_counts[7] || 0;

        document.getElementById('sumExpWait').textContent = sum.explain_waiting_count || 0;
        document.getElementById('sumBiopsyAlert').textContent = sum.unverified_biopsy_count || 0;

        var biopsyCard = document.getElementById('cardBiopsyAlert');
        if (sum.unverified_biopsy_count > 0) {
            biopsyCard.style.display = 'flex';
        } else {
            biopsyCard.style.display = 'flex';
            biopsyCard.classList.remove('alert-card');
        }

        // 医療安全アラートカード
        var antiCard = document.getElementById('cardAntiAlert');
        var antiCount = sum.alert_anti_pending_count || 0;
        if (antiCard) {
            document.getElementById('sumAntiAlert').textContent = antiCount;
            antiCard.style.display = (antiCount > 0) ? 'flex' : 'none';
        }

        var infCard = document.getElementById('cardInfectionAlert');
        var infCount = sum.alert_infection_count || 0;
        if (infCard) {
            document.getElementById('sumInfectionAlert').textContent = infCount;
            infCard.style.display = (infCount > 0) ? 'flex' : 'none';
        }

        var allergyCard = document.getElementById('cardAllergyAlert');
        var allergyCount = sum.alert_allergy_count || 0;
        if (allergyCard) {
            document.getElementById('sumAllergyAlert').textContent = allergyCount;
            allergyCard.style.display = (allergyCount > 0) ? 'flex' : 'none';
        }

        var coldCard = document.getElementById('cardColdAlert');
        var coldCount = sum.alert_cold_count || 0;
        if (coldCard) {
            document.getElementById('sumColdAlert').textContent = coldCount;
            coldCard.style.display = (coldCount > 0) ? 'flex' : 'none';
        }
    }

    function renderTileGrid(patients, statuses, toiletRooms) {
        if (!patients || patients.length === 0) {
            return '<div style="grid-column: 1 / -1; text-align: center; padding: 48px 16px; color: var(--text-muted); background: var(--bg-surface); border-radius: var(--radius-md); border: 1px dashed var(--border-color);">' +
                '該当する患者データはありません<br>' +
                '<button type="button" class="btn-clear-filter" style="margin-top: 12px; padding: 6px 14px;" onclick="clearStatusFilter()">絞り込みを全解除</button>' +
                '</div>';
        }

        var html = '';
        patients.forEach(function(p) {
            var stId = parseInt(p.current_status_id, 10);
            var isDone = (stId === 7);
            var isGif = !!p.is_gif;
            var isEnema = (!!p.is_enema || p.exam_type === '浣腸CF' || p.exam_type === '浣腸F');
            var hasSedation = !!p.has_sedation;

            var cardClass = 'patient-tile-card status-border-' + stId;
            if (isDone) cardClass += ' card-done';
            if (p.is_unverified_biopsy) cardClass += ' card-biopsy-alert';
            if (p.is_explain_waiting) cardClass += ' card-explain-wait';

            // 担当医師ハイライト
            if (currentRole === 'doctor' && selectedDoctor && p.doctor_name === selectedDoctor) {
                cardClass += ' row-my-doctor-highlight';
            }

            // 検査種別バッジ
            var examBadgeCls = isGif ? 'exam-chip-gif' : (isEnema ? 'exam-chip-enema' : 'exam-chip-cf');
            var examBadge = '<span class="exam-chip ' + examBadgeCls + '">' + escapeHtml(p.exam_type || (isGif ? 'GIF' : (isEnema ? '浣腸CF' : 'CF'))) + '</span>';

            // 診療科
            var deptLabel = (p.department_code === 'PROCT') ? '肛門' : '胃腸';
            var deptBadge = '<span class="dept-badge dept-badge-' + escapeHtml(p.department_code) + '">' + deptLabel + '</span>';

            // 年齢バッジ
            var ageBadge = '';
            if (p.age !== null) {
                var ageCls = (p.age >= 70) ? 'age-chip-elder' : (p.age >= 65 ? 'age-chip-mid' : 'age-chip-young');
                ageBadge = '<span class="age-chip ' + ageCls + '">' + p.age + '歳</span>';
            }

            // 鎮静バッジ
            var sedationChip = '';
            if (hasSedation) {
                if (p.sedation_type === 'PROPOFOL') {
                    sedationChip = '<span class="tile-sed-chip">💉プロポ</span>';
                } else if (p.sedation_type === 'DIAZEPAM') {
                    sedationChip = '<span class="tile-sed-chip">💉ジアゼ</span>';
                } else {
                    sedationChip = '<span class="tile-sed-chip">💉鎮静</span>';
                }
            } else {
                sedationChip = '<span class="tile-sed-chip none">覚醒下</span>';
            }

            // 現在ステータス文言 & サブ文言
            var statusText = '';
            var statusSub = '';
            if (stId === 1) {
                statusText = p.arrival_time ? '1. 来院受付済' : '1. 未受付 (来院前)';
                statusSub = p.arrival_time ? ('🕒 ' + p.arrival_time) : '';
            } else if (stId === 2) {
                statusText = '2. マイトイレ案内中';
                if (p.toilet_room) statusSub = '🚽 ' + p.toilet_room;
                else statusSub = '下剤前処置';
            } else if (stId === 3) {
                var isReady = !!p.is_exam_ready;
                var waitM = (p.wait_elapsed_minutes !== null && p.wait_elapsed_minutes !== undefined) ? parseInt(p.wait_elapsed_minutes, 10) : 0;
                var waitWarnCls = '';
                if (waitM >= 90) waitWarnCls = 'wait-time-warn-pulse';
                else if (waitM >= 60) waitWarnCls = 'wait-time-warn-red';
                else if (waitM >= 30) waitWarnCls = 'wait-time-warn-yellow';

                var waitTag = p.wait_start_time ? ('<span class="wait-time-tag ' + waitWarnCls + '">+' + waitM + '分</span>') : '';

                if (isReady) {
                    cardClass += ' card-exam-ready';
                    statusText = '3. 🚪 呼出OK (点滴完了)';
                    statusSub = 'ベッド入室済 ' + waitTag + ' <button type="button" class="btn-undo" style="padding:2px 6px; font-size:10px; margin-left:4px;" onclick="toggleExamReady(' + p.schedule_id + ', 0, event)" title="待機中に戻す">待機へ</button>';
                } else {
                    statusText = '3. ⏳ 待機中 (空き待ち)';
                    statusSub = '前処置完了 ' + waitTag + ' <button type="button" class="btn-forward btn-call-ready" style="padding:2px 7px; font-size:10px; margin-left:4px;" onclick="toggleExamReady(' + p.schedule_id + ', 1, event)" title="入室・点滴完了にして医師呼出OKにする">呼出OKへ</button>';
                }
            } else if (stId === 4) {
                statusText = '4. 🔬 検査中';
                statusSub = (p.doctor_name || 'Dr');
            } else if (stId === 5) {
                statusText = '5. 🛏️ リカバリ安静中';
                if (hasSedation) {
                    var elapsed = p.recovery_elapsed_minutes !== null ? parseInt(p.recovery_elapsed_minutes, 10) : 0;
                    var target = parseInt(p.recovery_target_minutes, 10) || (p.sedation_type === 'PROPOFOL' ? 20 : 30);
                    var remaining = target - elapsed;
                    if (remaining <= 0) {
                        statusSub = '⚠️超過 +' + Math.abs(remaining) + '分';
                    } else {
                        statusSub = '⏱️残 ' + remaining + '分 (' + elapsed + '/' + target + 'm)';
                    }
                } else {
                    statusSub = '覚醒下';
                }
            } else if (stId === 6) {
                statusText = '6. 💬 結果説明中';
                statusSub = (p.doctor_name || '');
            } else if (stId === 7) {
                statusText = '7. 🏁 帰宅・完了';
                statusSub = p.done_time ? ('🕒 ' + p.done_time) : '';
            }

            // タイル内アラートバッジ群
            var alertBadges = [];
            if (p.is_unverified_biopsy) {
                alertBadges.push('<span class="tile-alert-chip alert-biopsy">🚨 検体未照合 (' + (p.biopsy_count || 1) + ')</span>');
            }
            if (p.is_explain_waiting) {
                alertBadges.push('<span class="tile-alert-chip alert-explain">🔔 説明待ち</span>');
            }
            if (stId === 5 && hasSedation) {
                var elapsed = p.recovery_elapsed_minutes !== null ? parseInt(p.recovery_elapsed_minutes, 10) : 0;
                var target = parseInt(p.recovery_target_minutes, 10) || (p.sedation_type === 'PROPOFOL' ? 20 : 30);
                if (target - elapsed <= 0) {
                    alertBadges.push('<span class="tile-alert-chip alert-overdue">⏱️ 安静超過</span>');
                }
            }
            if (p.medical_alert) {
                var ma = p.medical_alert;
                if (ma.has_antithrombotic && !ma.antithrombotic_verified) {
                    alertBadges.push('<span class="tile-alert-chip alert-anti">💊 休薬要確認</span>');
                }
                if (ma.has_infection) {
                    alertBadges.push('<span class="tile-alert-chip alert-infection">🔴 感染症注意</span>');
                }
                if (ma.has_allergy) {
                    alertBadges.push('<span class="tile-alert-chip alert-allergy">⚠️ 局麻注意</span>');
                }
                if (ma.has_cold_fever) {
                    alertBadges.push('<span class="tile-alert-chip alert-cold">😷 風邪・発熱</span>');
                }
            }

            var alertsHtml = '';
            if (alertBadges.length > 0) {
                alertsHtml = '<div class="tile-alerts">' + alertBadges.join('') + '</div>';
            }

            var kanaDisplay = (p.patient_kana && p.patient_kana.trim() !== '') ? ('<span class="tile-kana">(' + escapeHtml(p.patient_kana) + ')</span>') : '';

            html += '<div class="' + cardClass + '" onclick="focusPatientInTable(' + p.schedule_id + ')" title="👆 クリックで高密度表の該当行へ移動・操作">';
            html += '  <div class="tile-header">';
            html += '    <div class="tile-waku-time">' + escapeHtml(p.scheduled_time || '--:--') + '</div>';
            html += '    ' + deptBadge + ' ' + examBadge;
            html += '    <div class="tile-doctor">' + escapeHtml(p.doctor_name || 'Dr未定') + '</div>';
            html += '  </div>';
            html += '  <div class="tile-body">';
            html += '    <div class="tile-patient-name">';
            html += '      <span>' + escapeHtml(p.patient_name) + '</span> ' + ageBadge;
            html += '    </div>';
            html += '    <div class="tile-patient-sub">';
            html += '      <span>ID:' + escapeHtml(p.patient_id) + '</span> ' + kanaDisplay + ' ' + sedationChip;
            html += '    </div>';
            html += '  </div>';
            html += '  <div class="tile-status-banner status-bg-' + stId + '">';
            html += '    <span class="tile-status-text">' + statusText + '</span>';
            if (statusSub) {
                html += '    <span class="tile-status-sub" style="font-size:11px;font-weight:700;">' + statusSub + '</span>';
            }
            html += '  </div>';
            html += alertsHtml;
            html += '</div>';
        });

        return html;
    }

    function renderTable(data) {
        var tbody = document.getElementById('patientListBody');
        var tileGrid = document.getElementById('patientTileGrid');
        if (!tbody && !tileGrid) return;

        var patients = data.patients || [];
        var statuses = data.statuses || [];
        var toiletRooms = data.toilet_rooms || [];

        // リカバリ安静タイマー完了アラートチェック (全職種ビュー対象・補助看含む)
        checkRecoveryAlerts(data);

        if (patients.length === 0) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">本日の該当予約データはありません</td></tr>';
            if (tileGrid) tileGrid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-muted); background: var(--bg-surface); border-radius: var(--radius-md); border: 1px dashed var(--border-color);">本日の該当予約データはありません</div>';
            return;
        }

        var kanaRowMap = {
            'あ': /^[あいうえおアイウエオｱｲｳｴｵ]/,
            'か': /^[かきくけこがぎぐげごカキクケコガギグゲゴｶｷｸｹｺ]/,
            'さ': /^[さしすせそざじずぜぞサシスセソザジズゼゾｻｼｽｾｿ]/,
            'た': /^[たちつてとだぢづでどタチツテトダヂヅデドﾀﾁﾂﾃﾄ]/,
            'な': /^[なにぬねのナニヌネノﾅﾆﾇﾈﾉ]/,
            'は': /^[はひふへほばびぶべぼぱぴぷぺぽハヒフヘホバビブベボパピプペポﾊﾋﾌﾍﾎ]/,
            'ま': /^[まみむめもマミムメモﾏﾐﾑﾒﾓ]/,
            'や': /^[やゆよヤユヨﾔﾕﾖ]/,
            'ら': /^[らりるれろラリルレロﾗﾘﾙﾚﾛ]/,
            'わ': /^[わをんワヲンﾜｦﾝ]/
        };

        var filteredPatients = [];
        patients.forEach(function(p) {
            // ★ 空き枠・枠なし（患者がいない枠）は進捗モニターでは完全非表示
            if (!p.patient_name || p.patient_name.trim() === '' || p.patient_name.indexOf('枠なし') !== -1 || p.patient_name === '※枠なし' || p.patient_name === '空き' || p.patient_name === '空き枠') {
                return;
            }

            var stId = parseInt(p.current_status_id, 10);

            // 1. 職種専用クイック絞り込みフィルター判定
            if (roleOnlyFilter) {
                if (currentRole === 'doctor') {
                    if (!(stId === 3 || stId === 4 || stId === 6 || p.is_explain_waiting)) return;
                } else if (currentRole === 'nurse') {
                    if (!(stId === 3 || stId === 4 || stId === 5 || p.is_unverified_biopsy)) return;
                } else if (currentRole === 'assistant') {
                    if (!(stId === 2 || p.scope_cleaning_status === 'ORDERED')) return;
                } else if (currentRole === 'clerk') {
                    if (!(stId === 1 || stId === 6 || stId === 7)) return;
                } else if (currentRole === 'all') {
                    if (stId === 7 || (stId === 1 && !p.arrival_time)) return;
                }
            }

            // 2. 滞在中のみフィルター判定
            if (isStayOnlyActive) {
                if (stId === 7 || (stId === 1 && !p.arrival_time)) return;
            }

            // 3. サマリーバー連動ステータスフィルター判定
            if (activeStatusFilter !== null) {
                if (activeStatusFilter === 'exp') {
                    if (!p.is_explain_waiting) return;
                } else if (activeStatusFilter === 'biopsy') {
                    if (!p.is_unverified_biopsy) return;
                } else if (parseInt(activeStatusFilter, 10) !== stId) {
                    return;
                }
            }

            // 4. 五十音インデックス（頭文字）フィルター判定
            if (activeKanaFilter && activeKanaFilter !== 'ALL') {
                var re = kanaRowMap[activeKanaFilter];
                if (re) {
                    var targetText = (p.patient_kana && p.patient_kana.trim() !== '') ? p.patient_kana.trim() : (p.patient_name || '').trim();
                    if (!re.test(targetText)) return;
                }
            }

            filteredPatients.push(p);
        });

        // タイルグリッド描画
        if (tileGrid) {
            tileGrid.innerHTML = renderTileGrid(filteredPatients, statuses, toiletRooms);
        }

        // テーブル行描画
        var html = '';
        var visibleCount = filteredPatients.length;

        filteredPatients.forEach(function(p) {
            var stId = parseInt(p.current_status_id, 10);
            var isDone = (stId === 7);
            var isGif = !!p.is_gif;
            var isEnema = (!!p.is_enema || p.exam_type === '浣腸CF' || p.exam_type === '浣腸F');
            var hasSedation = !!p.has_sedation;

            var rowClass = 'monitor-row';
            if (isDone) rowClass += ' row-done';
            if (p.is_unverified_biopsy) rowClass += ' row-biopsy-alert';
            if (p.is_explain_waiting) rowClass += ' row-explain-wait';
            if (stId === 3) {
                if (p.is_exam_ready) rowClass += ' row-exam-ready';
                else rowClass += ' row-exam-waiting';
            }

            // 職種に応じたフォーカスクラスの付与
            if (currentRole === 'doctor') {
                if (selectedDoctor && p.doctor_name === selectedDoctor) {
                    rowClass += ' row-my-doctor-highlight';
                }
                if (stId === 3) rowClass += ' row-focus-doctor-ready';
                else if (stId === 4) rowClass += ' row-focus-doctor-exam';
                else if (stId === 6 || p.is_explain_waiting) rowClass += ' row-focus-doctor-explain';
                else if (stId === 1 || stId === 2 || stId === 7) rowClass += ' row-fade-other';
            } else if (currentRole === 'nurse') {
                if (stId === 3) rowClass += ' row-focus-nurse-ready';
                else if (stId === 4) rowClass += ' row-focus-nurse-exam';
                else if (stId === 5) rowClass += ' row-focus-nurse-recovery';
            } else if (currentRole === 'assistant') {
                if (p.scope_cleaning_status === 'ORDERED') rowClass += ' row-focus-assistant-clean';
                else if (stId === 2) rowClass += ' row-focus-assistant-prep';
                else if (stId >= 4 && stId !== 5) rowClass += ' row-fade-other';
            } else if (currentRole === 'clerk') {
                if (stId === 1 && !p.arrival_time) rowClass += ' row-focus-clerk-uncheckin';
                else if (stId >= 6) rowClass += ' row-focus-clerk-checkout';
            }

            // 年齢バッジ
            var ageBadge = '';
            if (p.age !== null) {
                var ageCls = (p.age >= 70) ? 'age-chip-elder' : (p.age >= 65 ? 'age-chip-mid' : 'age-chip-young');
                ageBadge = '<span class="age-chip ' + ageCls + '">' + p.age + '歳</span>';
            }

            // 検査種別バッジ (GIF:青, 浣腸CF:紫, 大腸TCF:緑)
            var examBadgeCls = isGif ? 'exam-chip-gif' : (isEnema ? 'exam-chip-enema' : 'exam-chip-cf');
            var examBadge = '<span class="exam-chip ' + examBadgeCls + '">' + escapeHtml(p.exam_type || (isGif ? 'GIF' : (isEnema ? '浣腸CF' : 'CF'))) + '</span>';

            // セデーション (鎮静剤) バッジ
            var sedationBadge = '';
            if (hasSedation) {
                if (p.sedation_type === 'PROPOFOL') {
                    sedationBadge = '<span class="sedation-chip sedation-chip-propofol" onclick="openSedationModal(' + p.schedule_id + ')" title="鎮静: プロポフォール (クリックで変更)">💉 鎮静: プロポ</span>';
                } else if (p.sedation_type === 'DIAZEPAM') {
                    sedationBadge = '<span class="sedation-chip sedation-chip-sedative" onclick="openSedationModal(' + p.schedule_id + ')" title="鎮静: ジアゼパム (クリックで変更)">💉 鎮静: ジアゼ</span>';
                } else {
                    sedationBadge = '<span class="sedation-chip sedation-chip-sedative" onclick="openSedationModal(' + p.schedule_id + ')" title="鎮静あり (クリックで変更)">💉 鎮静あり</span>';
                }
            } else {
                sedationBadge = '<span class="sedation-chip sedation-chip-none" onclick="openSedationModal(' + p.schedule_id + ')" title="鎮静なし/覚醒下 (クリックで変更)">🚫 鎮静なし</span>';
            }

            // 受付セル
            var arrivalHtml = '';
            if (p.arrival_time) {
                arrivalHtml = '<span class="arrival-badge" onclick="editArrival(' + p.schedule_id + ', \'' + escapeHtml(p.arrival_time) + '\')">🕒 ' + escapeHtml(p.arrival_time) + '</span>';
            } else {
                var stampLabel = (currentRole === 'clerk') ? '🕒 来院打刻' : '🕒 打刻';
                arrivalHtml = '<button type="button" class="arrival-btn-empty" onclick="stampArrival(' + p.schedule_id + ')">' + stampLabel + '</button>';
            }

            // 前処置室 (マイトイレ) セル ※GIFなら前処置なし、浣腸CFなら浣腸のみ
            var prepContent = '';
            if (isGif) {
                prepContent = '<div style="color: var(--text-sub); font-size: 11px; padding: 4px 0; font-weight: bold;">🚫 前処置なし (GIF)</div>';
            } else if (isEnema) {
                prepContent = '<div style="color: #c084fc; font-size: 11px; padding: 4px 0; font-weight: bold;">💉 浣腸のみ (下剤不要)</div>';
            } else {
                var roomOptions = '<option value="">(未設定)</option>';
                toiletRooms.forEach(function(r) {
                    var sel = (p.toilet_room === r) ? 'selected' : '';
                    roomOptions += '<option value="' + escapeHtml(r) + '" ' + sel + '>' + escapeHtml(r) + '</option>';
                });
                var prepRoomHtml = '<select class="room-select" onchange="changeToiletRoom(' + p.schedule_id + ', this.value)">' + roomOptions + '</select>';

                var medHtml = '';
                if (p.bowel_prep) {
                    var medRaw = p.bowel_prep;
                    var medCls = 'prep-med-mobi';
                    if (medRaw.indexOf('マグ') !== -1) medCls = 'prep-med-magp';
                    if (medRaw.indexOf('ニフ') !== -1) medCls = 'prep-med-nif';
                    medHtml = '<div class="prep-med-badge ' + medCls + '" onclick="showMedInfo(event, \'' + escapeHtml(medRaw) + '\')">💊 ' + escapeHtml(medRaw) + ' <small>ℹ️</small></div>';
                }

                var prepStatus = p.prep_med_status || 'NONE';
                var prepBtnCls = 'prep-status-toggle';
                var prepBtnTxt = '下剤: 未作成';
                if (prepStatus === 'PREPARED') {
                    prepBtnCls += ' prep-status-prepared';
                    prepBtnTxt = '下剤: 作成済✅';
                } else if (prepStatus === 'DELIVERED') {
                    prepBtnCls += ' prep-status-delivered';
                    prepBtnTxt = '下剤: 配薬済💊';
                }
                var prepStatusBtn = '<span class="' + prepBtnCls + '" onclick="cyclePrepMedStatus(' + p.schedule_id + ', \'' + prepStatus + '\')">' + prepBtnTxt + '</span>';

                prepContent = '<div>' + prepRoomHtml + '</div>' + medHtml + '<div>' + prepStatusBtn + '</div>';
            }

            // ステータスタイムライン (7段階)
            var timelineHtml = '<div class="status-timeline">';
            statuses.forEach(function(st) {
                var sId = parseInt(st.status_id, 10);
                var isCurrent = (sId === stId);
                var isPast = (sId < stId);
                var nodeCls = 'step-node';
                var isSkipped = false;
                var title = st.status_name;

                if (sId === 2 && (isGif || isEnema)) {
                    isSkipped = true;
                    title = isGif ? '胃カメラのため前処置なし' : '浣腸CFのため下剤・マイトイレ前処置なし';
                }
                if (sId === 5 && !hasSedation) {
                    isSkipped = true;
                    title = '鎮静なしのためリカバリ不要';
                }

                if (isCurrent) nodeCls += ' active active-' + sId;
                if (isPast) nodeCls += ' completed';
                if (isSkipped) nodeCls += ' step-skipped';

                timelineHtml += '<div class="' + nodeCls + '" onclick="directChangeStatus(' + p.schedule_id + ', ' + sId + ', ' + stId + ')" title="' + escapeHtml(title) + '">' + escapeHtml(st.short_name) + '</div>';
            });
            timelineHtml += '</div>';

            // アクションボタン (次へ進む / 戻す)
            var actionsHtml = '<div class="step-actions">';
            if (stId === 3) {
                var isReady = !!p.is_exam_ready;
                var waitM = (p.wait_elapsed_minutes !== null && p.wait_elapsed_minutes !== undefined) ? parseInt(p.wait_elapsed_minutes, 10) : 0;
                var waitWarnCls = '';
                if (waitM >= 90) waitWarnCls = 'wait-time-warn-pulse';
                else if (waitM >= 60) waitWarnCls = 'wait-time-warn-red';
                else if (waitM >= 30) waitWarnCls = 'wait-time-warn-yellow';

                var waitTagHtml = p.wait_start_time ? ('<span class="wait-time-tag ' + waitWarnCls + '" title="待機開始: ' + escapeHtml(p.wait_start_time) + '">+' + waitM + '分</span>') : '';

                var readyBadgeHtml = isReady 
                    ? '<span class="badge-exam-ready" onclick="toggleExamReady(' + p.schedule_id + ', 0, event)" title="クリックで【待機中】に戻す">✅ 呼出OK (点滴済)' + waitTagHtml + '</span>'
                    : '<span class="badge-exam-waiting" onclick="toggleExamReady(' + p.schedule_id + ', 1, event)" title="クリックで【準備完了(呼出OK)】にする">⏳ 待機中 (空き待ち)' + waitTagHtml + '</span>';

                actionsHtml += '<div style="margin-bottom: 4px;">' + readyBadgeHtml + '</div>';

                if (isReady) {
                    // 準備完了済みの場合 ➔ 検査開始ボタン（緑強調）
                    actionsHtml += '<button type="button" class="btn-forward btn-doctor-action" style="background:#059669; border-color:#10b981;" onclick="forwardStatus(' + p.schedule_id + ', 4)">🔬 検査開始 ▶</button>';
                } else {
                    // 待機中の場合 ➔ 看護師は「入室・点滴完了 (呼出OK)」ボタン、医師は「検査開始」も可能
                    if (currentRole === 'doctor') {
                        actionsHtml += '<button type="button" class="btn-forward" onclick="forwardStatus(' + p.schedule_id + ', 4)">🚪 検査開始 (入室)</button>';
                        actionsHtml += '<button type="button" class="btn-forward btn-call-ready" style="padding:2px 8px; font-size:11px; margin-left:4px;" onclick="toggleExamReady(' + p.schedule_id + ', 1, event)">呼出OK点灯</button>';
                    } else {
                        actionsHtml += '<button type="button" class="btn-forward btn-call-ready" onclick="toggleExamReady(' + p.schedule_id + ', 1, event)">🚪 入室・点滴完了 (呼出OK) ▶</button>';
                    }
                }
            } else if (stId < 7) {
                var nextStId = stId + 1;
                var nextLabel = '次へ進む ▶';

                if (stId === 1 && (isGif || isEnema)) {
                    nextStId = 3;
                    nextLabel = isGif ? '検査待ちへ ▶ (前処置なし)' : '検査待ちへ ▶ (前処置なし・浣腸のみ)';
                }
                if (stId === 4 && !hasSedation) {
                    nextStId = 6;
                    nextLabel = '説明待ちへ ▶ (リカバリ不要)';
                }

                if (currentRole === 'doctor') {
                    if (stId === 4) nextLabel = (p.has_biopsy && !p.biopsy_verified) ? '✅ 検査終了 (検体照合へ)' : '✅ 検査完了 ▶';
                    else if (stId === 6) nextLabel = '📋 結果説明完了 ▶';
                } else if (currentRole === 'nurse') {
                    if (stId === 2) nextLabel = '便OK (準備完了) ▶';
                    else if (stId === 4) nextLabel = 'リカバリベッド移動 ▶';
                    else if (stId === 5) nextLabel = '覚醒OK・説明待ち ▶';
                } else if (currentRole === 'assistant') {
                    if (stId === 2) nextLabel = '下剤・便確認依頼 ▶';
                } else if (currentRole === 'clerk') {
                    if (stId === 6) nextLabel = '🚪 帰宅・完了 (会計済) ▶';
                }

                var btnCls = 'btn-forward';
                if (currentRole === 'doctor' && (stId === 4 || stId === 6)) {
                    btnCls += ' btn-doctor-action';
                }
                actionsHtml += '<button type="button" class="' + btnCls + '" onclick="forwardStatus(' + p.schedule_id + ', ' + nextStId + ')">' + nextLabel + '</button>';
            } else {
                actionsHtml += '<span style="font-size: 11px; color: var(--text-muted);">✅ 完了済</span>';
            }

            if (stId > 1) {
                actionsHtml += '<button type="button" class="btn-undo" onclick="openUndoModal(' + p.schedule_id + ', \'' + escapeHtml(p.patient_name) + '\', ' + stId + ')" title="1つ前のステータスに戻す">◀ 戻す</button>';
            }
            actionsHtml += '</div>';

            // 検体 ＆ 洗浄タスク (4➔5連動)
            var tasksHtml = '';
            var cleanSt = p.scope_cleaning_status || 'NONE';
            if (cleanSt === 'ORDERED') {
                tasksHtml += '<div class="task-chip task-chip-scope-ordered" onclick="changeScopeCleaning(' + p.schedule_id + ', \'DONE\')" title="クリックで洗浄完了へ">🧼 スコープ洗浄中 (指示)</div>';
            } else if (cleanSt === 'DONE') {
                tasksHtml += '<div class="task-chip task-chip-scope-done" onclick="revertScopeCleaning(' + p.schedule_id + ')" title="クリックで洗浄完了を取り消し【洗浄中】に戻す">🧼 洗浄完了✅</div>';
            } else if (stId >= 4) {
                tasksHtml += '<div class="task-chip task-chip-scope-done" onclick="changeScopeCleaning(' + p.schedule_id + ', \'ORDERED\')">🧼 洗浄指示を発行</div>';
            }

            if (p.has_biopsy) {
                if (p.biopsy_verified) {
                    tasksHtml += '<div class="task-chip task-chip-biopsy-ok" onclick="openBiopsyModal(' + p.schedule_id + ')" title="照合完了済・クリックで再確認">🧪 検体' + (p.biopsy_count || 1) + '個 照合済✅</div>';
                } else {
                    tasksHtml += '<div class="task-chip task-chip-biopsy-alert" onclick="openBiopsyModal(' + p.schedule_id + ')" title="重大：カルテオーダーと声出し照合を行ってください">🚨 検体' + (p.biopsy_count || 1) + '個 未照合⚠️</div>';
                }
            } else if (stId >= 4) {
                tasksHtml += '<div class="task-chip" style="background:#1e293b;color:#94a3b8;border:1px dashed #475569;" onclick="openBiopsyModal(' + p.schedule_id + ')">🧪 検体なし (登録)</div>';
            }

            // リカバリ ＆ 説明
            var recHtml = '';
            if (!hasSedation) {
                recHtml += '<div style="color: var(--text-sub); font-size: 11px; margin-bottom: 4px;">🚫 リカバリ不要 (覚醒下)</div>';
            } else if (stId === 5) {
                var elapsed = p.recovery_elapsed_minutes !== null ? parseInt(p.recovery_elapsed_minutes, 10) : 0;
                var target = parseInt(p.recovery_target_minutes, 10) || (p.sedation_type === 'PROPOFOL' ? 20 : 30);
                var remaining = target - elapsed;
                var isOver = (remaining <= 0);
                var timerCls = isOver ? 'timer-warning' : '';
                var timerText = '';
                if (isOver) {
                    var overMin = Math.abs(remaining);
                    timerText = '⚠️ 超過 +' + overMin + '分 (経過 ' + elapsed + '分/' + target + '分)';
                } else {
                    timerText = '⏱️ 残り ' + remaining + '分 (経過 ' + elapsed + '分/' + target + '分)';
                }
                var pNameSafe = escapeHtml(p.patient_name || '').replace(/'/g, "\\'");
                recHtml += '<div class="recovery-timer ' + timerCls + '" onclick="openRecoveryTargetModal(' + p.schedule_id + ', \'' + pNameSafe + '\', ' + elapsed + ', ' + target + ')" title="👆 クリックでリカバリ目標時間を調整">' + timerText + '</div>';
                if (isOver) {
                    recHtml += '<div style="font-size:10px;color:#f87171;font-weight:bold;margin-top:2px;">覚醒目安達成 (超過)</div>';
                }
            }

            if (stId >= 4 && stId < 7) {
                var expCls = p.is_explain_waiting ? 'btn-explain-wait active' : 'btn-explain-wait';
                var expTxt = p.is_explain_waiting ? '🔔 説明待ち中 (医師待)' : '🔔 説明待ちにする';
                recHtml += '<button type="button" class="' + expCls + '" onclick="toggleExplainWait(' + p.schedule_id + ', !' + (p.is_explain_waiting ? 'true' : 'false') + ')">' + expTxt + '</button>';
            }

            // 患者所在バッジ
            var locationBadge = '';
            if (stId === 1) {
                locationBadge = '<div class="location-badge">📍 待合室 (受付済)</div>';
            } else if (stId === 2) {
                locationBadge = '<div class="location-badge">🚽 ' + (p.toilet_room ? escapeHtml(p.toilet_room) : 'マイトイレ準備中') + '</div>';
            } else if (stId === 3) {
                locationBadge = '<div class="location-badge" style="background:#064e3b;color:#6ee7b7;border-color:#10b981;">🚪 検査待合 (準備完了)</div>';
            } else if (stId === 4) {
                locationBadge = '<div class="location-badge" style="background:#881337;color:#fecdd3;border-color:#f43f5e;">🔬 内視鏡室 (検査中)</div>';
            } else if (stId === 5) {
                locationBadge = '<div class="location-badge" style="background:#312e81;color:#c7d2fe;border-color:#6366f1;">🛏️ リカバリ室 (安静中)</div>';
            } else if (stId === 6) {
                locationBadge = '<div class="location-badge" style="background:#581c87;color:#e9d5ff;border-color:#a855f7;">💬 診察室 (結果説明中)</div>';
            } else if (stId === 7) {
                locationBadge = '<div class="location-badge" style="background:#1e293b;color:#94a3b8;border-color:#475569;">🏁 帰宅完了</div>';
            }

            // カルテ備考
            var remarksHtml = '';
            if (p.remarks) {
                var cleanRemarks = p.remarks.replace(/<[^>]*>/g, '').trim();
                if (cleanRemarks) {
                    if (currentRole === 'doctor') {
                        remarksHtml = '<div class="remarks-doctor-highlight">📋 カルテ備考: ' + escapeHtml(cleanRemarks) + '</div>';
                    } else if (currentRole === 'all' || currentRole === 'nurse') {
                        remarksHtml = '<div style="font-size:11px;color:#94a3b8;margin-top:2px;">📝 ' + escapeHtml(cleanRemarks) + '</div>';
                    }
                }
            }

            // 医療安全アラートバッジ
            var alertBadgesHtml = '';
            if (p.medical_alert) {
                var a = p.medical_alert;
                var aBadges = [];
                if (a.has_antithrombotic) {
                    if (a.antithrombotic_verified) {
                        aBadges.push('<button type="button" class="med-board-btn verified" onclick="toggleAlertVerified(' + p.schedule_id + ', false, event)" title="💊 抗血栓薬：当日休薬確認済（クリックで要確認に戻す）">💊 休薬確認済 ✔</button>');
                    } else {
                        aBadges.push('<button type="button" class="med-board-btn unverified" onclick="toggleAlertVerified(' + p.schedule_id + ', true, event)" title="💊 抗血栓薬内服中！当日要休薬確認！（クリックで確認済にする）">💊 要休薬確認 ⚠️</button>');
                        rowClass += ' row-anti-warning';
                    }
                }
                if (a.has_allergy) {
                    aBadges.push('<span class="med-board-badge allergy" title="⚠️ 局麻・薬剤アレルギー注意（キシロカイン等）">⚠️ 局麻注意</span>');
                }
                if (a.has_infection) {
                    aBadges.push('<span class="med-board-badge infection" title="🔴 感染症注意（肝炎/梅毒等：防護具・専用洗浄要）">🔴 感染症注意</span>');
                    if (rowClass.indexOf('row-anti-warning') === -1) {
                        rowClass += ' row-infection-warning';
                    }
                }
                if (a.has_cold_fever) {
                    aBadges.push('<button type="button" class="med-board-btn cold" onclick="toggleAlertCold(' + p.schedule_id + ', false, event)" title="😷 風邪症状・発熱（クリックで解除）">😷 風邪・発熱 ✕</button>');
                } else {
                    aBadges.push('<button type="button" class="med-board-btn" style="background:#1e293b; color:#64748b; border:1px dashed #475569;" onclick="toggleAlertCold(' + p.schedule_id + ', true, event)" title="クリックで風邪・発熱フラグをONにする">😷+風邪</button>');
                }
                if (aBadges.length > 0) {
                    alertBadgesHtml = '<div class="patient-alert-row" style="margin-top:4px; display:flex; flex-wrap:wrap; gap:3px; align-items:center;">' + aBadges.join('') + '</div>';
                }
            } else {
                alertBadgesHtml = '<div class="patient-alert-row" style="margin-top:4px; display:flex; flex-wrap:wrap; gap:3px; align-items:center;"><button type="button" class="med-board-btn" style="background:#1e293b; color:#64748b; border:1px dashed #475569;" onclick="toggleAlertCold(' + p.schedule_id + ', true, event)" title="クリックで風邪・発熱フラグをONにする">😷+風邪</button></div>';
            }

            // 行組み立て (ID付与でスクロール・フォーカス可能)
            html += '<tr id="row-sched-' + p.schedule_id + '" class="' + rowClass + '">';
            html += '<td class="cell-time-waku">';
            html += '  <div class="waku-time">' + escapeHtml(p.scheduled_time || '') + '</div>';
            html += '  <span class="dept-badge dept-badge-' + escapeHtml(p.department_code) + '">' + (p.department_code === 'PROCT' ? '肛門科' : '胃腸科') + '</span>';
            html += '</td>';

            html += '<td class="cell-patient">';
            html += '  <div class="patient-name-row"><span>' + escapeHtml(p.patient_name) + '</span> ' + ageBadge + '</div>';
            var arrivalSub = (currentRole === 'doctor' && p.arrival_time) ? ' <span style="font-size:10.5px;color:#34d399;font-weight:700;">(来院 ' + escapeHtml(p.arrival_time) + ')</span>' : '';
            html += '  <div class="patient-id-sub"><span>ID:' + escapeHtml(p.patient_id) + '</span> ' + examBadge + ' ' + sedationBadge + arrivalSub + ' <small>' + escapeHtml(p.doctor_name || '') + '</small></div>';
            if (currentRole === 'clerk') {
                html += '  ' + locationBadge;
            }
            html += '  ' + remarksHtml;
            html += '  ' + alertBadgesHtml;
            html += '</td>';

            html += '<td class="cell-arrival col-arrival">' + arrivalHtml + '</td>';
            html += '<td class="cell-prep col-prep">' + prepContent + '</td>';
            html += '<td class="cell-status-bar col-timeline">' + timelineHtml + actionsHtml + '</td>';
            html += '<td class="cell-tasks col-tasks">' + tasksHtml + '</td>';
            html += '<td class="cell-recovery col-recovery">' + recHtml + '</td>';
            html += '</tr>';
        });

        if (visibleCount === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted); font-size: 13.5px;">' +
                '該当する条件の患者はいません<br>' +
                '<button type="button" class="btn-clear-filter" style="margin-top: 12px; padding: 6px 14px;" onclick="clearStatusFilter()">絞り込みを全解除</button>' +
                '</td></tr>';
            return;
        }

        tbody.innerHTML = html;
    }

    // ================================================================
    // API操作
    // ================================================================

    function postAction(payload, onSuccess) {
        fetch('action_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                if (onSuccess) onSuccess(data);
                fetchProgressData(false);
            } else {
                alert('エラー: ' + (data.error || '処理に失敗しました'));
            }
        })
        .catch(function(err) {
            console.error(err);
            alert('通信エラーが発生しました');
        });
    }

    function toggleAlertVerified(schedId, verified, event) {
        if (event) event.stopPropagation();
        var fd = new FormData();
        fd.append('action', 'toggle_verified');
        fd.append('schedule_id', schedId);
        fd.append('verified', verified ? '1' : '0');

        fetch('action_medical_alert.php', { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data && data.success) {
                    showSavedToast(data.message || (verified ? '休薬確認済に更新しました' : '休薬要確認に戻しました'));
                    fetchProgressData(false);
                } else {
                    alert('エラー: ' + (data ? data.message : '更新失敗'));
                }
            })
            .catch(function(err) { alert('通信エラー: ' + err.toString()); });
    }

    function toggleAlertCold(schedId, hasCold, event) {
        if (event) event.stopPropagation();
        var fd = new FormData();
        fd.append('action', 'toggle_cold');
        fd.append('schedule_id', schedId);
        fd.append('has_cold', hasCold ? '1' : '0');

        fetch('action_medical_alert.php', { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data && data.success) {
                    showSavedToast(data.message || (hasCold ? '風邪・発熱フラグを設定しました' : '風邪・発熱フラグを解除しました'));
                    fetchProgressData(false);
                } else {
                    alert('エラー: ' + (data ? data.message : '更新失敗'));
                }
            })
            .catch(function(err) { alert('通信エラー: ' + err.toString()); });
    }

    function toggleExamReady(schedId, isReady, event) {
        if (event) event.stopPropagation();
        postAction({
            action: 'toggle_exam_ready',
            schedule_id: schedId,
            is_ready: (isReady !== undefined && isReady !== null) ? (isReady ? 1 : 0) : undefined,
            operator_name: 'スタッフ',
            operator_role: currentRole
        }, function(data) {
            var msg = data.is_exam_ready ? '準備完了（医師呼出OK）に更新しました' : '待機中（空き待ち）に更新しました';
            showSavedToast(msg);
        });
    }

    function forwardStatus(schedId, toStatusId) {
        // 4 (検査中) ➔ 5 (リカバリ中) の場合、組織採取の確認を促す
        if (toStatusId === 5) {
            var p = findPatient(schedId);
            if (p && !p.has_biopsy && !p.biopsy_verified) {
                if (confirm('【組織採取・検体確認】\nこの検査で組織採取（生検・ポリペクトミー）はありましたか？\n\n「OK」➔ 検体照合ダイアログを開きます\n「キャンセル」➔ 検体なしでリカバリへ進めます')) {
                    openBiopsyModal(schedId);
                    return;
                }
            }
        }

        postAction({
            action: 'update_status',
            schedule_id: schedId,
            to_status_id: toStatusId,
            operator_name: 'スタッフ',
            operator_role: currentRole
        });
    }

    function directChangeStatus(schedId, toStatusId, currentStatusId) {
        if (toStatusId === currentStatusId) return;
        if (toStatusId < currentStatusId) {
            var p = findPatient(schedId);
            openUndoModal(schedId, p ? p.patient_name : '', currentStatusId, toStatusId);
            return;
        }
        forwardStatus(schedId, toStatusId);
    }

    function stampArrival(schedId) {
        var now = new Date();
        var hh = ('0' + now.getHours()).slice(-2);
        var mm = ('0' + now.getMinutes()).slice(-2);
        postAction({
            action: 'update_arrival',
            schedule_id: schedId,
            arrival_time: hh + ':' + mm
        });
    }

    function editArrival(schedId, currentTime) {
        var newTime = prompt('受付時刻を修正 (HH:MM形式):', currentTime);
        if (newTime !== null && newTime.trim() !== '') {
            postAction({
                action: 'update_arrival',
                schedule_id: schedId,
                arrival_time: newTime.trim()
            });
        }
    }

    function changeToiletRoom(schedId, roomName) {
        postAction({
            action: 'update_toilet_room',
            schedule_id: schedId,
            toilet_room: roomName
        });
    }

    function cyclePrepMedStatus(schedId, currentStatus) {
        var next = 'PREPARED';
        if (currentStatus === 'PREPARED') next = 'DELIVERED';
        else if (currentStatus === 'DELIVERED') next = 'NONE';
        postAction({
            action: 'update_prep_med',
            schedule_id: schedId,
            prep_med_status: next
        });
    }

    function changeScopeCleaning(schedId, nextStatus) {
        postAction({
            action: 'update_scope_cleaning',
            schedule_id: schedId,
            scope_cleaning_status: nextStatus
        });
    }

    function revertScopeCleaning(schedId) {
        if (!confirm('スコープ洗浄完了を取り消して【洗浄中】に戻しますか？')) return;
        changeScopeCleaning(schedId, 'ORDERED');
    }

    function toggleExplainWait(schedId, isWaiting) {
        postAction({
            action: 'toggle_explain_waiting',
            schedule_id: schedId,
            is_explain_waiting: isWaiting ? 1 : 0
        });
    }

    // ================================================================
    // リカバリ目標時間調整モーダル
    // ================================================================

    function openRecoveryTargetModal(schedId, patientName, elapsed, currentTarget) {
        document.getElementById('modalRecoverySchedId').value = schedId;
        document.getElementById('modalRecoveryElapsed').value = elapsed;
        document.getElementById('modalRecoveryCurrentTarget').value = currentTarget;
        document.getElementById('modalRecoveryPatientName').innerText = '👤 ' + patientName + ' 様';

        var remaining = currentTarget - elapsed;
        var summaryText = '経過: ' + elapsed + '分 ／ 目標: ' + currentTarget + '分 ';
        if (remaining <= 0) {
            summaryText += '<span style="color:#f87171; font-weight:bold;">(目標達成 +' + Math.abs(remaining) + '分超過)</span>';
        } else {
            summaryText += '<span style="color:#34d399; font-weight:bold;">(残り ' + remaining + '分)</span>';
        }
        document.getElementById('modalRecoveryStatusSummary').innerHTML = summaryText;
        document.getElementById('modalRecoveryInputMinutes').value = currentTarget;

        document.getElementById('recoveryTargetModal').style.display = 'flex';
    }

    // 1. 今から あと〇分（直接残時間を指定）
    function applyDirectRemaining(min) {
        var elapsed = parseInt(document.getElementById('modalRecoveryElapsed').value, 10) || 0;
        var newTarget = elapsed + min;
        if (newTarget < 5) newTarget = 5;
        saveRecoveryTargetValue(newTarget);
    }

    // 2. 現在の目標時間に加算・減算
    function applyDeltaTarget(delta) {
        var currentTarget = parseInt(document.getElementById('modalRecoveryCurrentTarget').value, 10) || 30;
        var newTarget = currentTarget + delta;
        if (newTarget < 5) newTarget = 5;
        saveRecoveryTargetValue(newTarget);
    }

    // 3. 自由入力値の保存
    function saveCustomRecoveryTarget() {
        var val = parseInt(document.getElementById('modalRecoveryInputMinutes').value, 10);
        if (isNaN(val) || val < 5 || val > 300) {
            alert('リカバリ時間は5分〜300分の間で指定してください。');
            return;
        }
        saveRecoveryTargetValue(val);
    }

    // 4. サーバーへ送信・保存
    function saveRecoveryTargetValue(targetMinutes) {
        var schedId = parseInt(document.getElementById('modalRecoverySchedId').value, 10);
        if (!schedId) return;

        closeModal('recoveryTargetModal');

        // ローカルキャッシュの即時更新（画面のリフレッシュを待たずに即時反映）
        if (cachedData && cachedData.patients) {
            for (var i = 0; i < cachedData.patients.length; i++) {
                if (parseInt(cachedData.patients[i].schedule_id, 10) === schedId) {
                    cachedData.patients[i].recovery_target_minutes = targetMinutes;
                    break;
                }
            }
            renderTable(cachedData);
        }

        postAction({
            action: 'update_recovery_target',
            schedule_id: schedId,
            recovery_target_minutes: targetMinutes
        }, function(resp) {
            showSavedToast('⏱️ リカバリ目標時間を ' + targetMinutes + '分 に変更しました');
        });
    }

    // ================================================================
    // 安静タイマー完了アラート (視覚点滅 ＆ 音声連動)
    // ================================================================
    var acknowledgedRecoveryAlerts = {}; // { 'scheduleId_targetMin': true }
    var activeRecoveryAlertTimer = null; // 15秒ごとの音声再生タイマー
    var currentAlertingPatients = [];    // 現在アラート対象の患者リスト
    var recoveryAudioContext = null;

    // Web Audio API の初期化・再開
    function getRecoveryAudioContext() {
        if (!recoveryAudioContext) {
            var AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                recoveryAudioContext = new AudioContextClass();
            }
        }
        if (recoveryAudioContext && recoveryAudioContext.state === 'suspended') {
            recoveryAudioContext.resume().catch(function(){});
        }
        return recoveryAudioContext;
    }

    // 画面クリックでブラウザの自動再生制限を解除
    document.addEventListener('click', function() {
        getRecoveryAudioContext();
    });

    // ピピッ x 3回 の電子音を合成再生 (明瞭な2音チャイム電子音)
    function playTripleBeepSound() {
        try {
            var ctx = getRecoveryAudioContext();
            if (!ctx) return;

            var now = ctx.currentTime;
            // 3回の「ピピッ」 (0.19秒間隔)
            for (var i = 0; i < 3; i++) {
                var start = now + (i * 0.19);
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();

                osc.type = 'sine';
                // 2音（ド→ミ風：1046Hz -> 1318Hz）の明瞭な電子音
                osc.frequency.setValueAtTime(1046.5, start);
                osc.frequency.setValueAtTime(1318.5, start + 0.04);

                gain.gain.setValueAtTime(0.28, start);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.12);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start(start);
                osc.stop(start + 0.13);
            }
        } catch (e) {
            console.warn('Audio playback failed:', e);
        }
    }

    // タイマー完了チェック (renderTableから全職種ビューで実行)
    function checkRecoveryAlerts(data) {
        if (!data || !data.patients) return;

        var newlyCompleted = [];
        data.patients.forEach(function(p) {
            var stId = parseInt(p.current_status_id, 10);
            if (stId === 5) { // リカバリ中
                var elapsed = p.recovery_elapsed_minutes !== null ? parseInt(p.recovery_elapsed_minutes, 10) : 0;
                var target = parseInt(p.recovery_target_minutes, 10) || (p.sedation_type === 'PROPOFOL' ? 20 : 30);

                if (elapsed >= target) {
                    var alertKey = p.schedule_id + '_' + target;
                    if (!acknowledgedRecoveryAlerts[alertKey]) {
                        newlyCompleted.push({
                            patient: p,
                            elapsed: elapsed,
                            target: target,
                            overdue: elapsed - target,
                            alertKey: alertKey
                        });
                    }
                }
            }
        });

        if (newlyCompleted.length > 0) {
            triggerRecoveryAlertModal(newlyCompleted);
        } else {
            // アラート対象がいなくなった場合の自動解除チェック
            if (currentAlertingPatients.length > 0) {
                var stillActive = [];
                currentAlertingPatients.forEach(function(item) {
                    var found = data.patients.find(function(pt) { return parseInt(pt.schedule_id, 10) === parseInt(item.patient.schedule_id, 10); });
                    if (found && parseInt(found.current_status_id, 10) === 5) {
                        var el = found.recovery_elapsed_minutes !== null ? parseInt(found.recovery_elapsed_minutes, 10) : 0;
                        var tg = parseInt(found.recovery_target_minutes, 10) || (found.sedation_type === 'PROPOFOL' ? 20 : 30);
                        if (el >= tg && !acknowledgedRecoveryAlerts[found.schedule_id + '_' + tg]) {
                            stillActive.push({
                                patient: found,
                                elapsed: el,
                                target: tg,
                                overdue: el - tg,
                                alertKey: found.schedule_id + '_' + tg
                            });
                        }
                    }
                });
                if (stillActive.length === 0) {
                    stopRecoveryAlerts();
                } else if (stillActive.length !== currentAlertingPatients.length) {
                    triggerRecoveryAlertModal(stillActive);
                }
            }
        }
    }

    // アラート開始 (画面点滅 ＋ 音声 ＋ ポップアップ)
    function triggerRecoveryAlertModal(alertItems) {
        currentAlertingPatients = alertItems;

        // 1. 画面全体の点滅フラッシュ
        document.body.classList.add('screen-alert-flashing');

        // 2. モーダル内の患者リスト組み立て
        renderRecoveryAlertList(alertItems);

        // 3. モーダル表示
        document.getElementById('recoveryAlertModal').style.display = 'flex';

        // 4. 音声の即時再生 ＆ 15秒ごとの繰り返しタイマー
        if (!activeRecoveryAlertTimer) {
            playTripleBeepSound();
            activeRecoveryAlertTimer = setInterval(function() {
                playTripleBeepSound();
            }, 15000);
        }
    }

    // モーダル内の患者カードリストを描画
    function renderRecoveryAlertList(alertItems) {
        var container = document.getElementById('recoveryAlertList');
        if (!container) return;

        var html = '';
        alertItems.forEach(function(item) {
            var p = item.patient;
            var overdueText = item.overdue > 0 ? '<span style="color:#f87171; font-weight:800;">(+' + item.overdue + '分超過)</span>' : '<span style="color:#34d399; font-weight:800;">(目安時間達成)</span>';
            var sedLabel = (p.sedation_type === 'PROPOFOL') ? 'プロポフォール' : (p.sedation_type === 'DIAZEPAM' ? 'ジアゼパム' : (p.sedation_type === 'MIDAZOLAM' ? 'ミダゾラム' : '鎮静あり'));

            html += '<div class="recovery-alert-patient-box' + (item.overdue > 0 ? ' is-overdue' : '') + '" id="alert_box_' + p.schedule_id + '">';
            html += '  <div class="recovery-alert-pname">';
            html += '    <span>👤 ' + escapeHtml(p.patient_name) + ' 様 <small style="font-weight:normal; color:#94a3b8; font-size:12px;">(ID:' + escapeHtml(p.patient_id) + ')</small></span>';
            html += '    <span style="font-size:12px; color:#fca5a5;">' + (p.department_code === 'PROCT' ? '肛門科' : '胃腸科') + ' / ' + sedLabel + '</span>';
            html += '  </div>';
            html += '  <div class="recovery-alert-pinfo">';
            html += '    <span>⏱️ 経過: <strong>' + item.elapsed + '分</strong> ／ 目標: <strong>' + item.target + '分</strong></span>';
            html += '    <span>' + overdueText + '</span>';
            if (p.doctor_name) {
                html += '    <span style="color:#94a3b8;">担当医: ' + escapeHtml(p.doctor_name) + '</span>';
            }
            html += '  </div>';
            html += '  <div class="recovery-alert-actions">';
            html += '    <button type="button" class="btn-alert-action-ok" onclick="alertActionExplainWait(' + p.schedule_id + ')">✅ 覚醒確認・説明待ちへ進める</button>';
            html += '    <button type="button" class="btn-alert-action-ext" onclick="alertActionExtend(' + p.schedule_id + ', 5)">⏱️ あと5分延長</button>';
            html += '    <button type="button" class="btn-alert-action-ext" onclick="alertActionExtend(' + p.schedule_id + ', 10)">⏱️ あと10分延長</button>';
            html += '    <button type="button" class="btn-alert-action-dismiss" onclick="alertActionDismiss(' + p.schedule_id + ')">閉じる</button>';
            html += '  </div>';
            html += '</div>';
        });

        container.innerHTML = html;
    }

    // アラート全停止
    function stopRecoveryAlerts() {
        if (activeRecoveryAlertTimer) {
            clearInterval(activeRecoveryAlertTimer);
            activeRecoveryAlertTimer = null;
        }
        document.body.classList.remove('screen-alert-flashing');
        closeModal('recoveryAlertModal');
        currentAlertingPatients = [];
    }

    // すべてのアラートを停止して閉じる
    function dismissAllAlerts() {
        currentAlertingPatients.forEach(function(item) {
            acknowledgedRecoveryAlerts[item.alertKey] = true;
        });
        stopRecoveryAlerts();
    }

    // 個別患者のアラートを閉じる
    function alertActionDismiss(schedId) {
        var idx = currentAlertingPatients.findIndex(function(it) { return parseInt(it.patient.schedule_id, 10) === parseInt(schedId, 10); });
        if (idx !== -1) {
            acknowledgedRecoveryAlerts[currentAlertingPatients[idx].alertKey] = true;
            currentAlertingPatients.splice(idx, 1);
        }
        if (currentAlertingPatients.length === 0) {
            stopRecoveryAlerts();
        } else {
            renderRecoveryAlertList(currentAlertingPatients);
        }
    }

    // 個別患者の覚醒確認・説明待ちへ進める
    function alertActionExplainWait(schedId) {
        var idx = currentAlertingPatients.findIndex(function(it) { return parseInt(it.patient.schedule_id, 10) === parseInt(schedId, 10); });
        if (idx !== -1) {
            acknowledgedRecoveryAlerts[currentAlertingPatients[idx].alertKey] = true;
            currentAlertingPatients.splice(idx, 1);
        }

        // ステータスを6 (結果説明待ち) に進める
        forwardStatus(schedId, 6);

        if (currentAlertingPatients.length === 0) {
            stopRecoveryAlerts();
        } else {
            renderRecoveryAlertList(currentAlertingPatients);
        }
    }

    // 個別患者の目標時間延長 (今から +5分 / +10分)
    function alertActionExtend(schedId, addMinutes) {
        var p = findPatient(schedId);
        if (!p) return;

        var elapsed = p.recovery_elapsed_minutes !== null ? parseInt(p.recovery_elapsed_minutes, 10) : 0;
        var newTarget = elapsed + addMinutes;
        if (newTarget < 5) newTarget = 5;

        var idx = currentAlertingPatients.findIndex(function(it) { return parseInt(it.patient.schedule_id, 10) === parseInt(schedId, 10); });
        if (idx !== -1) {
            acknowledgedRecoveryAlerts[currentAlertingPatients[idx].alertKey] = true;
            currentAlertingPatients.splice(idx, 1);
        }

        saveRecoveryTargetValue(newTarget);

        if (currentAlertingPatients.length === 0) {
            stopRecoveryAlerts();
        } else {
            renderRecoveryAlertList(currentAlertingPatients);
        }
    }

    // ================================================================
    // セデーション設定モーダル
    // ================================================================

    function openSedationModal(schedId) {
        document.getElementById('modalSedationSchedId').value = schedId;
        var p = findPatient(schedId);
        if (p) {
            document.getElementById('modalSedationPatientName').textContent = p.patient_name;
            var hasSed = !!p.has_sedation;
            document.getElementById('radioSedYes').checked = hasSed;
            document.getElementById('radioSedNo').checked = !hasSed;
            document.getElementById('modalSedationType').value = p.sedation_type || 'PROPOFOL';
            toggleSedationDetails(hasSed);
        }
        document.getElementById('sedationModal').style.display = 'flex';
    }

    function toggleSedationDetails(show) {
        document.getElementById('sedationDetailBox').style.display = show ? 'block' : 'none';
    }

    function saveSedationSetting() {
        var schedId = parseInt(document.getElementById('modalSedationSchedId').value, 10);
        var hasSed = document.getElementById('radioSedYes').checked;
        var sedType = hasSed ? document.getElementById('modalSedationType').value : 'NONE';

        postAction({
            action: 'toggle_sedation',
            schedule_id: schedId,
            has_sedation: hasSed ? 1 : 0,
            sedation_type: sedType
        }, function() {
            closeModal('sedationModal');
        });
    }

    // ================================================================
    // Undo モーダル
    // ================================================================

    function openUndoModal(schedId, patientName, currentStId, defaultTargetStId) {
        document.getElementById('modalUndoSchedId').value = schedId;
        document.getElementById('modalUndoPatientName').textContent = patientName;

        var sel = document.getElementById('modalUndoTargetStatus');
        sel.innerHTML = '';

        if (cachedData && cachedData.statuses) {
            cachedData.statuses.forEach(function(st) {
                var sId = parseInt(st.status_id, 10);
                if (sId < currentStId) {
                    var opt = document.createElement('option');
                    opt.value = sId;
                    opt.textContent = st.status_id + '. ' + st.status_name;
                    if (defaultTargetStId && sId === defaultTargetStId) {
                        opt.selected = true;
                    } else if (!defaultTargetStId && sId === (currentStId - 1)) {
                        opt.selected = true;
                    }
                    sel.appendChild(opt);
                }
            });
        }

        document.getElementById('undoModal').style.display = 'flex';
    }

    function checkCustomReason(val) {
        var custom = document.getElementById('modalUndoReasonCustom');
        custom.style.display = (val === 'その他') ? 'block' : 'none';
    }

    function submitRevertStatus() {
        var schedId = parseInt(document.getElementById('modalUndoSchedId').value, 10);
        var targetStId = parseInt(document.getElementById('modalUndoTargetStatus').value, 10);
        var reasonSel = document.getElementById('modalUndoReason').value;
        var reason = reasonSel;
        if (reasonSel === 'その他') {
            reason = document.getElementById('modalUndoReasonCustom').value.trim() || 'その他';
        }

        postAction({
            action: 'revert_status',
            schedule_id: schedId,
            to_status_id: targetStId,
            revert_reason: reason,
            operator_name: 'スタッフ',
            operator_role: currentRole
        }, function() {
            closeModal('undoModal');
        });
    }

    // ================================================================
    // 組織採取・病理検体モーダル
    // ================================================================

    function openBiopsyModal(schedId) {
        document.getElementById('modalBiopsySchedId').value = schedId;
        var p = findPatient(schedId);

        if (p) {
            var hasB = p.has_biopsy ? '1' : '0';
            var radios = document.getElementsByName('modalHasBiopsy');
            for (var i = 0; i < radios.length; i++) {
                radios[i].checked = (radios[i].value === hasB);
            }
            document.getElementById('modalBiopsyCount').value = p.biopsy_count || 1;
            document.getElementById('modalBiopsySites').value = p.biopsy_sites || '';
            document.getElementById('modalBiopsyVerified').checked = !!p.biopsy_verified;
            toggleBiopsyFields(p.has_biopsy);
        } else {
            toggleBiopsyFields(true);
        }

        document.getElementById('biopsyModal').style.display = 'flex';
    }

    function toggleBiopsyFields(show) {
        var box = document.getElementById('biopsyDetailFields');
        box.style.display = show ? 'block' : 'none';
    }

    function addBiopsySite(siteName) {
        var input = document.getElementById('modalBiopsySites');
        var cur = input.value.trim();
        if (cur === '') {
            input.value = siteName + ':1';
        } else {
            input.value = cur + ', ' + siteName + ':1';
        }
    }

    function saveBiopsyData() {
        var schedId = parseInt(document.getElementById('modalBiopsySchedId').value, 10);
        var radios = document.getElementsByName('modalHasBiopsy');
        var hasBiopsy = false;
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked && radios[i].value === '1') {
                hasBiopsy = true;
                break;
            }
        }

        var count = parseInt(document.getElementById('modalBiopsyCount').value, 10) || 0;
        var sites = document.getElementById('modalBiopsySites').value.trim();
        var verified = document.getElementById('modalBiopsyVerified').checked;

        if (hasBiopsy && !verified) {
            if (!confirm('⚠️ カルテとの声出し照合チェックが入っていません。\n未照合のまま保存しますか？（後で照合を完了してください）')) {
                return;
            }
        }

        postAction({
            action: 'update_biopsy',
            schedule_id: schedId,
            has_biopsy: hasBiopsy ? 1 : 0,
            biopsy_count: hasBiopsy ? count : 0,
            biopsy_sites: hasBiopsy ? sites : '',
            biopsy_verified: verified ? 1 : 0,
            operator_name: '看護師'
        }, function() {
            closeModal('biopsyModal');
        });
    }

    // 下剤注意ポップオーバー
    function showMedInfo(e, medName) {
        e.stopPropagation();
        var pop = document.getElementById('infoPopover');
        var info = '';

        if (medName.indexOf('モビ') !== -1) {
            info = '<strong>💊 モビプレップ</strong><br>・水1Lで全量溶解<br>・冷やすと服用しやすい<br>・内服ペース: コップ1杯(約180ml)を10〜15分かけてゆっくり';
        } else if (medName.indexOf('マグ') !== -1) {
            info = '<strong>💊 マグコロールP</strong><br>・水1.8Lで全量溶解<br>・排泄便が透明水様になるまで飲用<br>・冷水推奨';
        } else if (medName.indexOf('ニフ') !== -1) {
            info = '<strong>💊 ニフレック</strong><br>・水2Lで溶解<br>・約2時間かけて服用';
        } else {
            info = '<strong>💊 ' + escapeHtml(medName) + '</strong><br>・指示通りの用量・ペースで内服してください';
        }

        pop.innerHTML = info;
        pop.style.left = (e.pageX + 10) + 'px';
        pop.style.top = (e.pageY + 10) + 'px';
        pop.style.display = 'block';
    }

    document.addEventListener('click', function() {
        var pop = document.getElementById('infoPopover');
        if (pop) pop.style.display = 'none';
    });

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function findPatient(schedId) {
        if (!cachedData || !cachedData.patients) return null;
        for (var i = 0; i < cachedData.patients.length; i++) {
            if (parseInt(cachedData.patients[i].schedule_id, 10) === schedId) {
                return cachedData.patients[i];
            }
        }
        return null;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ------------------------------------------------------------
       医師選択 & 予約変更通知ロジック
    ------------------------------------------------------------ */
    function openDoctorPickerModal() {
        var modal = document.getElementById('doctorPickerModal');
        if (!modal) return;
        document.querySelectorAll('#doctorPickerList .doctor-picker-btn').forEach(function(btn) {
            var doc = btn.getAttribute('data-doc');
            if (doc === selectedDoctor) {
                btn.classList.add('current');
            } else {
                btn.classList.remove('current');
            }
        });
        modal.style.display = 'flex';
    }

    function closeDoctorPickerModal() {
        var modal = document.getElementById('doctorPickerModal');
        if (modal) modal.style.display = 'none';
    }

    function selectDoctor(docName) {
        selectedDoctor = docName;
        try {
            localStorage.setItem('endoscope_selected_doctor', docName);
            showSavedToast('担当医師を保存: ' + (docName ? (docName + ' 先生') : '全医師'));
        } catch(e) {}
        closeDoctorPickerModal();
        updateRoleGuidanceBar();
        updateDoctorNotifVisibility();
        fetchDoctorNotifications();
        if (cachedData) {
            renderTable(cachedData);
        }
    }

    function updateDoctorNotifVisibility() {
        var container = document.getElementById('doctorNotifContainer');
        if (!container) return;
        if (currentRole === 'doctor') {
            container.style.display = 'flex';
            var nameSpan = document.getElementById('notifTriggerDoctorName');
            if (nameSpan) {
                nameSpan.textContent = selectedDoctor ? (selectedDoctor + ' 先生') : '(全医師)';
            }
        } else {
            container.style.display = 'none';
            toggleDoctorNotifPanel(false);
        }
    }

    function fetchDoctorNotifications() {
        if (currentRole !== 'doctor') return;

        var url = 'action_doctor_notification.php?action=get_notifications&doctor_name=' + encodeURIComponent(selectedDoctor);
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    var prevCount = doctorNotifData.unreadCount;
                    doctorNotifData.unreadCount = data.unread_count;
                    doctorNotifData.maxLogId = data.max_log_id;
                    doctorNotifData.notifications = data.notifications;
                    renderDoctorNotifications();

                    // 新着通知があり、以前より増えた場合はフロートトレイを自動オープン
                    if (data.unread_count > 0 && data.unread_count > prevCount && !isNotifPanelOpen) {
                        toggleDoctorNotifPanel(true);
                    }
                }
            })
            .catch(function(err) {
                console.error('通知取得失敗', err);
            });
    }

    function toggleDoctorNotifPanel(forceOpen) {
        var panel = document.getElementById('doctorNotifPanel');
        var btn = document.getElementById('doctorNotifTriggerBtn');
        if (!panel) return;

        if (typeof forceOpen === 'boolean') {
            isNotifPanelOpen = forceOpen;
        } else {
            isNotifPanelOpen = (panel.style.display === 'none' || panel.style.display === '');
        }

        panel.style.display = isNotifPanelOpen ? 'flex' : 'none';
        if (btn) {
            btn.style.opacity = isNotifPanelOpen ? '0.75' : '1';
        }
    }

    function renderDoctorNotifications() {
        var triggerBadge = document.getElementById('notifTriggerBadge');
        var triggerBtn = document.getElementById('doctorNotifTriggerBtn');
        var panelBadge = document.getElementById('notifPanelCountBadge');
        var panelTitle = document.getElementById('notifPanelDoctorTitle');
        var body = document.getElementById('doctorNotifBody');

        var count = doctorNotifData.unreadCount;

        if (panelTitle) {
            panelTitle.textContent = (selectedDoctor ? (selectedDoctor + ' 先生') : '全医師') + ' 宛通知';
        }

        if (triggerBadge) {
            triggerBadge.textContent = count;
            triggerBadge.style.display = count > 0 ? 'inline-block' : 'none';
        }
        if (panelBadge) {
            panelBadge.textContent = count;
        }
        if (triggerBtn) {
            if (count > 0) {
                triggerBtn.classList.add('has-unread');
            } else {
                triggerBtn.classList.remove('has-unread');
            }
        }

        if (!body) return;

        if (count === 0) {
            body.innerHTML = '<div class="doctor-notif-empty"><span>✅</span><br>未確認の予約変更通知はありません</div>';
            return;
        }

        var html = '';
        doctorNotifData.notifications.forEach(function(item) {
            html += '<div class="doctor-notif-card" id="notifCard_' + item.log_id + '">';
            html += '  <div class="doctor-notif-card-top">';
            html += '    <div class="doctor-notif-meta-left">';
            html += '      <span class="doctor-notif-badge ' + item.action_badge + '">' + item.action_icon + ' ' + escapeHtml(item.action_label) + '</span>';
            html += '      <span class="doctor-notif-time">' + escapeHtml(item.time_ago) + '</span>';
            html += '    </div>';
            html += '    <button type="button" class="doctor-notif-dismiss-btn" onclick="dismissNotification(' + item.log_id + ')" title="確認済みにする">✔ 消込</button>';
            html += '  </div>';
            html += '  <div class="doctor-notif-card-body">';
            html += '    <div><span class="doctor-notif-patient-name">' + escapeHtml(item.patient_name) + '</span> <span style="font-size:11px; color:#94a3b8;">(' + escapeHtml(item.patient_id) + ')</span></div>';
            html += '    <div style="font-size:11.5px; color:#93c5fd; margin-top:2px;">📅 ' + escapeHtml(item.target_date) + ' ' + escapeHtml(item.target_time || '') + ' <span style="color:#cbd5e1;">[' + escapeHtml(item.target_exam || '検査') + ']</span> 担当: ' + escapeHtml(item.target_doc || '未定') + '</div>';
            if (item.diff_summary) {
                html += '    <div class="doctor-notif-summary">' + escapeHtml(item.diff_summary) + '</div>';
            }
            html += '  </div>';
            html += '</div>';
        });

        body.innerHTML = html;
    }

    function dismissNotification(logId) {
        var targetDoc = selectedDoctor || 'ALL';

        var card = document.getElementById('notifCard_' + logId);
        if (card) {
            card.style.transition = 'all 0.2s';
            card.style.opacity = '0';
            card.style.transform = 'translateX(30px)';
        }

        var fd = new FormData();
        fd.append('action', 'mark_read');
        fd.append('doctor_name', targetDoc);
        fd.append('log_id', logId);

        fetch('action_doctor_notification.php', { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                setTimeout(function() {
                    doctorNotifData.notifications = doctorNotifData.notifications.filter(function(n) { return n.log_id !== logId; });
                    doctorNotifData.unreadCount = doctorNotifData.notifications.length;
                    renderDoctorNotifications();
                }, 200);
            })
            .catch(function(err) {
                console.error('個別消込失敗', err);
            });
    }

    function dismissAllNotifications() {
        if (doctorNotifData.maxLogId <= 0) return;
        var targetDoc = selectedDoctor || 'ALL';

        if (!confirm('表示中の未読通知（' + doctorNotifData.unreadCount + '件）をすべて確認済みにしますか？')) {
            return;
        }

        var fd = new FormData();
        fd.append('action', 'mark_all_read');
        fd.append('doctor_name', targetDoc);
        fd.append('max_log_id', doctorNotifData.maxLogId);

        fetch('action_doctor_notification.php', { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                doctorNotifData.notifications = [];
                doctorNotifData.unreadCount = 0;
                renderDoctorNotifications();
                showSavedToast('通知を一括消込しました');
            })
            .catch(function(err) {
                console.error('一括消込失敗', err);
            });
    }

    // 🚨 当日・緊急追加（浣腸CF・当日飛び込み予約）ポップアップ呼び出し
    function openEmergencyRegisterModal() {
        var date = '<?= $date_str ?>';
        var dept = ('<?= $dept ?>' === 'ALL') ? 'PROCT' : '<?= $dept ?>';
        var url = 'modal_edit.php?date=' + encodeURIComponent(date) 
                + '&dept=' + encodeURIComponent(dept) 
                + '&schedule_id=0'
                + '&exam_type=' + encodeURIComponent('浣腸CF');

        var win = window.open(url, 'emergency_register', 'width=1040,height=880,scrollbars=yes,resizable=yes');
        if (win) {
            win.focus();
            var checkTimer = setInterval(function() {
                if (win.closed) {
                    clearInterval(checkTimer);
                    fetchProgressData(true);
                    showSavedToast('当日予約を反映しました');
                }
            }, 1000);
        }
    }

    // ウィンドウ再フォーカス時の安全な自動更新
    window.addEventListener('focus', function() {
        if (!isNotifPanelOpen) {
            fetchProgressData(false);
        }
    });
</script>

<!-- 医師選択モーダル -->
<div id="doctorPickerModal" class="doctor-picker-modal" onclick="if(event.target===this)closeDoctorPickerModal()">
    <div class="doctor-picker-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #334155; padding-bottom:8px;">
            <div style="font-size:15px; font-weight:800; color:#f8fafc; display:flex; align-items:center; gap:6px;">
                <span>👨‍⚕️</span> 担当医師を選択
            </div>
            <button type="button" onclick="closeDoctorPickerModal()" style="background:transparent; border:none; color:#94a3b8; font-size:16px; cursor:pointer;">✕</button>
        </div>
        <p style="font-size:12px; color:#94a3b8; margin-bottom:14px; line-height:1.4;">
            通知を表示する担当医師を選択してください。<br>この設定はブラウザに記憶され、いつでも切り替え可能です。
        </p>
        <div id="doctorPickerList">
            <?php foreach ($active_doctors as $doc): ?>
                <button type="button" class="doctor-picker-btn" data-doc="<?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?>" onclick="selectDoctor('<?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?>')">
                    <span>👨‍⚕️ <?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?> 先生</span>
                    <span style="font-size:11px; color:#94a3b8;">選択 ➔</span>
                </button>
            <?php endforeach; ?>
            <button type="button" class="doctor-picker-btn" data-doc="" onclick="selectDoctor('')" style="margin-top:6px; background:#1e293b; border-style:dashed;">
                <span>🌐 全医師の通知を表示</span>
                <span style="font-size:11px; color:#94a3b8;">全体 ➔</span>
            </button>
        </div>
    </div>
</div>

<!-- 医師向け予約変更通知フロートパネル -->
<div id="doctorNotifContainer" class="doctor-notif-floating-container">
    <!-- 展開パネル -->
    <div id="doctorNotifPanel" class="doctor-notif-panel" style="display:none;">
        <div class="doctor-notif-header">
            <div class="doctor-notif-header-title">
                <span>🔔</span>
                <span id="notifPanelDoctorTitle">予約変更通知</span>
                <span id="notifPanelCountBadge" class="doctor-notif-badge-count">0</span>
            </div>
            <div class="doctor-notif-actions">
                <button type="button" class="doctor-notif-clear-all-btn" onclick="dismissAllNotifications()" title="表示中の未読通知をすべて消込">🧹 すべて消込</button>
                <button type="button" class="doctor-notif-close-btn" onclick="toggleDoctorNotifPanel(false)" title="パネルを最小化">✕</button>
            </div>
        </div>
        <div id="doctorNotifBody" class="doctor-notif-body">
            <!-- カード一覧が動的に描画されます -->
        </div>
    </div>

    <!-- フロートトリガーボタン -->
    <button type="button" id="doctorNotifTriggerBtn" class="doctor-notif-trigger-btn" onclick="toggleDoctorNotifPanel()">
        <span>🔔 予約変更通知</span>
        <span id="notifTriggerDoctorName" style="color:#93c5fd; font-weight:700;">(未選択)</span>
        <span id="notifTriggerBadge" class="doctor-notif-badge-count" style="display:none;">0</span>
    </button>
</div>

<?php include __DIR__ . '/medical_alert_modal_inc.php'; ?>
<?php include __DIR__ . '/urgent_slot_modal_inc.php'; ?>

</body>
</html>
