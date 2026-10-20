<?php
/**
 * 生成日時: 2026-09-01 10:00:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\index.php
 * ファイル名: index.php
 * 
 * 医療法人小野会 内視鏡予約・運用プロトタイプ統合ポータル
 * 
 * 修正履歴:
 *   - 2026-09-28: ver0928 リリース、m2_* マスタ管理連携
 *   - 2026-10-04: ver1004 アップデート、総合操作マニュアル help.php へのリンク正規化
 *   - 2026-10-09: 日別メモ診療科振り分けメンテナンス (furiwake_daymemo.php) へのリンクカードをマスタ管理セクションに追加
 *   - 2026-10-16: ヘッダー最左端に院内総合ポータル(../index.php)直行ボタン[🏥]を設置、ヘッダー表示を「医療法人小野会」に簡素化、フッターに総合ポータルリンクを増設
 */

// デバッグ設定ファイルのパス
$debug_config_file = __DIR__ . '/debug_config.json';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '不明';

// 非同期からのデバッグモード切替リクエスト処理
if (isset($_GET['toggle_debug'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $current_status = false;
    if (file_exists($debug_config_file)) {
        $conf = json_decode(file_get_contents($debug_config_file), true);
        $current_status = isset($conf['debug_mode']) ? (bool)$conf['debug_mode'] : false;
    }
    $new_status = !$current_status;
    @file_put_contents($debug_config_file, json_encode(['debug_mode' => $new_status], JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'debug_mode' => $new_status]);
    exit;
}

$is_debug_mode = false;
if (file_exists($debug_config_file)) {
    $conf = json_decode(file_get_contents($debug_config_file), true);
    $is_debug_mode = isset($conf['debug_mode']) ? (bool)$conf['debug_mode'] : false;
}

// データベース接続と当日予約サマリ取得
require_once __DIR__ . '/lib/db.php';
$pdo = getDbConnection();
$today_str = date('Y-m-d');
$today_stats = ['PROCT' => 0, 'GASTRO' => 0, 'total' => 0];
$db_connected = ($pdo !== null);

if ($db_connected) {
    try {
        $stmt = $pdo->prepare("
            SELECT department_code, COUNT(*) as cnt 
            FROM t_endoscopy_schedule 
            WHERE exam_date = :today AND is_canceled = FALSE 
            GROUP BY department_code
        ");
        $stmt->execute([':today' => $today_str]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = $row['department_code'] ?? '';
            $cnt = (int)$row['cnt'];
            if (isset($today_stats[$code])) {
                $today_stats[$code] = $cnt;
            }
            $today_stats['total'] += $cnt;
        }
    } catch (Exception $e) {
        // エラー時はフォールバック
    }
}

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
$today_dow = $week_ja[(int)date('w')];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="医療法人小野会 内視鏡予約管理システム 統合ポータル。縦型マトリクス予約、カレンダー、空き枠検索、患者検索">
    <title>🔬 内視鏡予約システム | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        /* ポータル専用レイアウト (高密度コンパクト仕様) */
        .portal-wrapper {
            max-width: 1240px;
            margin: 0 auto;
            padding: 10px 16px 20px;
        }

        /* スキップリンク（アクセシビリティ対応） */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 10px;
            background: var(--primary-700);
            color: #ffffff;
            padding: 6px 14px;
            border-radius: var(--radius-md);
            font-weight: 700;
            z-index: 10000;
            transition: top 0.2s;
        }
        .skip-link:focus { top: 8px; }

        /* ヘッダーエリア (ウルトラスリム・1行バナー) */
        .portal-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #2563eb 100%);
            border-radius: var(--radius-lg);
            color: #ffffff;
            padding: 8px 16px;
            margin-bottom: 8px;
            box-shadow: var(--shadow-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .portal-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        /* 院内総合ポータル直行ボタン (共通ヘッダー左端・アイコン専用ボタン) */
        .btn-global-portal {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: var(--radius-md);
            color: #ffffff;
            font-size: 14px;
            text-decoration: none;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
            transition: all var(--transition-fast);
            user-select: none;
            line-height: 1;
        }
        .btn-global-portal:hover {
            background: rgba(255, 255, 255, 0.32);
            border-color: rgba(255, 255, 255, 0.75);
            color: #ffffff;
            transform: scale(1.08);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.25);
        }
        .btn-global-portal:active {
            transform: scale(0.96);
        }
        .btn-global-portal-icon {
            font-size: 14px;
            line-height: 1;
        }
        .portal-breadcrumb-sep {
            color: rgba(255, 255, 255, 0.4);
            font-size: 13px;
            font-weight: 300;
            user-select: none;
            margin: 0 -2px;
        }
        .portal-header h1 {
            color: #ffffff;
            font-size: 17px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .portal-header-sub {
            color: rgba(255, 255, 255, 0.85);
            font-size: 12px;
            margin: 0;
            border-left: 1px solid rgba(255, 255, 255, 0.3);
            padding-left: 10px;
        }

        .header-badges {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ip-pill {
            background: rgba(0, 0, 0, 0.25);
            color: rgba(255, 255, 255, 0.95);
            font-size: 11px;
            font-family: var(--font-mono);
            padding: 2px 8px;
            border-radius: var(--radius-full);
            backdrop-filter: blur(4px);
        }
        .ver-pill {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: var(--radius-full);
        }
        .help-btn {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: bold;
            transition: all var(--transition-fast);
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-decoration: none;
        }
        .help-btn:hover {
            background: rgba(255, 255, 255, 0.35);
            transform: scale(1.08);
            color: #ffffff;
        }

        /* 当日サマリ & LIVE進捗統合ステータスバー (高密度スリム) */
        .status-summary-bar {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 6px 12px;
            margin-bottom: 8px;
            box-shadow: var(--shadow-xs);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .today-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .today-date-badge {
            font-weight: 800;
            color: var(--slate-900);
            background: var(--slate-100);
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
        }
        .today-count-chips {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .stat-chip {
            padding: 2px 7px;
            border-radius: var(--radius-full);
            font-size: 11.5px;
            font-weight: 700;
        }
        .stat-chip-proct { background: var(--primary-50); color: var(--primary-700); border: 1px solid var(--primary-200); }
        .stat-chip-gastro { background: var(--emerald-50); color: var(--emerald-700); border: 1px solid var(--emerald-200); }
        .stat-chip-total { background: var(--slate-100); color: var(--slate-800); border: 1px solid var(--slate-300); }

        /* 当日ホワイトボード LIVE ボタン */
        .today-monitor-btn {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%);
            color: #ffffff !important;
            font-size: 12px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 5px rgba(220, 38, 38, 0.3);
            transition: all var(--transition-fast);
        }
        .today-monitor-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 38, 38, 0.4);
        }
        .pulse-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            background: #ffffff;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.8); }
        }

        .controls-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .control-toggle {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--slate-700);
            cursor: pointer;
            user-select: none;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            transition: background var(--transition-fast);
        }
        .control-toggle:hover { background: var(--slate-100); }
        .control-toggle input[type="checkbox"] {
            accent-color: var(--primary-600);
            width: 13px;
            height: 13px;
            cursor: pointer;
        }
        .control-toggle-debug {
            background: var(--amber-50);
            color: var(--amber-800);
            border: 1px solid var(--amber-300);
            padding: 2px 7px;
        }

        /* セクション見出し (超コンパクト) */
        .section-header {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 800;
            color: var(--slate-600);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 8px 0 5px 2px;
            border-bottom: 1.5px solid var(--slate-200);
            padding-bottom: 2px;
        }

        /* メニューグリッド (高密度) */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 8px;
            margin-bottom: 4px;
        }

        /* メニューカード (高密度・省スペース) */
        .menu-card {
            background: var(--color-surface);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 8px 12px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            transition: all var(--transition-fast);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .menu-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-400);
        }
        .menu-card:focus-visible {
            outline: 2px solid var(--primary-600) !important;
            outline-offset: 1px !important;
        }

        /* フィーチャーカード（メイン画面） */
        .menu-card.featured {
            border-color: var(--primary-300);
            background: linear-gradient(180deg, #ffffff 0%, var(--primary-50) 100%);
        }
        .menu-card.featured:hover {
            border-color: var(--primary-600);
        }

        .menu-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 4px;
        }
        .menu-card-title {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .menu-card-title h2 {
            font-size: 14px;
            font-weight: 800;
            color: var(--slate-900);
            margin: 0;
            letter-spacing: -0.2px;
        }

        .menu-card-desc {
            font-size: 11.5px;
            color: var(--slate-600);
            line-height: 1.4;
            margin: 0 0 4px 0;
            flex-grow: 1;
        }
        .menu-card-desc strong {
            color: var(--slate-800);
        }

        .menu-card-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-top: 3px;
            border-top: 1px dashed var(--slate-200);
            font-size: 11px;
            font-weight: 700;
            color: var(--primary-600);
            gap: 3px;
        }
        .menu-card:hover .menu-card-footer {
            color: var(--primary-700);
            gap: 5px;
        }

        /* 個別カードのアクセントカラー */
        .menu-card-green {
            border-color: #a7f3d0;
            background: linear-gradient(180deg, #ffffff 0%, #ecfdf5 100%);
        }
        .menu-card-green:hover { border-color: var(--emerald-500); }
        .menu-card-green .menu-card-footer { color: var(--emerald-600); }

        .menu-card-purple {
            border-color: #ddd6fe;
            background: linear-gradient(180deg, #ffffff 0%, #f5f3ff 100%);
        }
        .menu-card-purple:hover { border-color: var(--purple-500); }
        .menu-card-purple .menu-card-footer { color: var(--purple-600); }

        .menu-card-amber {
            border-color: #fde68a;
            background: linear-gradient(180deg, #ffffff 0%, #fffbeb 100%);
        }
        .menu-card-amber:hover { border-color: var(--amber-500); }
        .menu-card-amber .menu-card-footer { color: var(--amber-700); }

        .card-header-actions {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .menu-help-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--slate-100);
            color: var(--slate-600);
            font-size: 11px;
            font-weight: 700;
            border: 1px solid var(--slate-300);
            cursor: pointer;
            transition: all var(--transition-fast);
            text-decoration: none;
            line-height: 1;
            user-select: none;
        }
        .menu-help-btn:hover {
            background: var(--primary-100);
            color: var(--primary-700);
            border-color: var(--primary-400);
            transform: scale(1.15);
        }

        /* フッター */
        .portal-footer {
            text-align: center;
            font-size: 11px;
            color: var(--slate-400);
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px solid var(--slate-200);
            line-height: 1.5;
        }
        .portal-footer-nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .portal-footer-link {
            color: var(--slate-600);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .portal-footer-link:hover {
            color: var(--primary-700);
            background: var(--primary-50);
            text-decoration: none;
        }
        .portal-footer-sep {
            color: var(--slate-300);
            font-size: 11px;
            user-select: none;
        }

        @media (max-width: 768px) {
            .portal-wrapper { padding: 8px 10px; }
            .portal-header { padding: 8px 12px; }
            .portal-header h1 { font-size: 15px; }
            .status-summary-bar { flex-direction: column; align-items: flex-start; }
            .menu-grid { grid-template-columns: 1fr; }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // IE非対応警告
            var ua = window.navigator.userAgent;
            if (/MSIE |Trident\//.test(ua)) {
                alert('⚠️ 本システムはInternet Explorer（IE）では動作しません。Google Chrome等の最新ブラウザをご利用ください。');
                document.body.innerHTML = '<div style="padding:50px 20px;text-align:center;font-family:sans-serif;"><h2 style="color:#dc3545;">Internet Explorer は非対応です</h2><p>Google Chrome などのモダンブラウザをご使用ください。</p></div>';
                return;
            }
        });

        // メニューカードからヘルプへの個別ジャンプ（カードクリックの発火を防止）
        function openHelpSection(e, secId) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            window.open('help.php#' + secId, '_blank');
        }

        function toggleDescriptions(checkbox) {
            document.querySelectorAll('.menu-card-desc').forEach(function(el) {
                el.style.display = checkbox.checked ? '' : 'none';
            });
        }

        function toggleDebugMode(checkbox) {
            fetch('index.php?toggle_debug=1')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success) {
                        checkbox.checked = data.debug_mode;
                    } else {
                        alert('デバッグモードの切替に失敗しました。');
                        checkbox.checked = !checkbox.checked;
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    alert('通信エラーが発生しました。');
                    checkbox.checked = !checkbox.checked;
                });
        }
    </script>
</head>
<body>

<a href="#main-content" class="skip-link">メインコンテンツへスキップ</a>

<div class="portal-wrapper">

    <!-- ヘッダー (ウルトラスリム) -->
    <header class="portal-header" role="banner">
        <div class="portal-header-left">
            <a href="../index.php" class="btn-global-portal" title="医療法人小野会 院内総合ポータルへ戻る" aria-label="院内総合ポータルへ戻る">
                <span class="btn-global-portal-icon" aria-hidden="true">🏥</span>
            </a>
            <span class="portal-breadcrumb-sep" aria-hidden="true">›</span>
            <h1>
                <span aria-hidden="true">🔬</span>
                <span>内視鏡予約システム</span>
            </h1>
            <span class="portal-header-sub">医療法人小野会</span>
        </div>
        <div class="header-badges">
            <a href="./仕様書/specdoc.html" class="help-btn" target="_blank" rel="noopener noreferrer" title="操作マニュアルを表示（別ウィンドウ）" aria-label="操作マニュアルを表示">📖</a>
            <a href="help.php" class="help-btn" target="_blank" rel="noopener noreferrer" title="システム仕様書を表示（別ウィンドウ）" aria-label="仕様書を表示">❓</a>
            <span class="ver-pill" title="システムバージョン">ver1004</span>
            <span class="ip-pill" title="クライアントIPアドレス"><?= htmlspecialchars($client_ip, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </header>

    <!-- 当日予約サマリ & 電子ホワイトボード直行バー -->
    <aside class="status-summary-bar" aria-label="システムステータスおよび当日運用">
        <div class="today-info">
            <span class="today-date-badge tabular-nums">📅 本日: <?= htmlspecialchars($today_str, ENT_QUOTES, 'UTF-8') ?> (<?= $today_dow ?>)</span>
            <div class="today-count-chips">
                <span class="stat-chip stat-chip-proct tabular-nums">肛門科: <?= $today_stats['PROCT'] ?>件</span>
                <span class="stat-chip stat-chip-gastro tabular-nums">胃腸科: <?= $today_stats['GASTRO'] ?>件</span>
                <span class="stat-chip stat-chip-total tabular-nums">当日計: <?= $today_stats['total'] ?>件</span>
            </div>
            <a href="progress_monitor.php" class="today-monitor-btn" title="内視鏡室・リカバリ室の当日リアルタイム動線管理ホワイトボードを開く">
                <span class="pulse-dot"></span>
                <span>📊 当日ホワイトボード (LIVE)</span>
                <span aria-hidden="true">➔</span>
            </a>
        </div>
        <div class="controls-group">
            <label class="control-toggle control-toggle-debug" title="ONにすると書き込み系のデバッグログが system_debug.log に記録されます">
                <input type="checkbox" id="debugToggle" <?= $is_debug_mode ? 'checked' : '' ?> onchange="toggleDebugMode(this)">
                <span>🛠️ デバッグ</span>
            </label>
            <label class="control-toggle" title="カードの説明文の表示/非表示を切り替えます">
                <input type="checkbox" id="descToggle" onchange="toggleDescriptions(this)" checked>
                <span>詳細説明</span>
            </label>
        </div>
    </aside>

    <main id="main-content" role="main">

        <!-- 1. 予約操作 -->
        <section aria-labelledby="section-booking">
            <div class="section-header" id="section-booking">
                <span aria-hidden="true">📌</span>
                <span>予約操作</span>
            </div>
            <div class="menu-grid">
                <!-- 予約マトリクス (縦型V2) -->
                <a href="month_matrix_v2.php" class="menu-card featured">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">📊</span>
                            <h2>予約マトリクス (縦型V2)</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-primary">表表示</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-matrix-v2')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-matrix-v2');}" title="予約マトリクスの操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        縦軸日付・横軸時間枠の月間総覧。新規予約登録・ドラッグ＆ドロップ移動対応。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- 月間カレンダービュー -->
                <a href="month_schedule.php" class="menu-card">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">📅</span>
                            <h2>月間カレンダービュー</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-success">カレンダー</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-calendar')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-calendar');}" title="月間カレンダービューの操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        月間空き枠確認・日別一覧・予約登録・個別/一覧印刷（Modern Web版）。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- 空き枠検索 -->
                <a href="slot_search.php" class="menu-card menu-card-green">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">🔍</span>
                            <h2>空き枠検索</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-success">高速</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-slot-search')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-slot-search');}" title="空き枠検索の操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        医師・検査種別・曜日別の空き枠絞り込み一覧＆カレンダー直行。
                    </div>
                    <div class="menu-card-footer">
                        <span>探す</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- 当日進捗モニター (LIVE) -->
                <a href="progress_monitor.php" class="menu-card" style="border-color: #93c5fd; background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%);">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">📋</span>
                            <h2>当日進捗モニター</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge" style="background: #ef4444; color: #fff; font-size: 10.5px; padding: 2px 6px;">LIVE</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-progress-monitor')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-progress-monitor');}" title="当日進捗モニターの操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        7段階動線（受付〜処置〜検査〜リカバリ〜説明〜完了）の電子ホワイトボード。
                    </div>
                    <div class="menu-card-footer" style="color: #2563eb;">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>
            </div>
        </section>

        <!-- 2. 検索・集計 -->
        <section aria-labelledby="section-search">
            <div class="section-header" id="section-search">
                <span aria-hidden="true">🔎</span>
                <span>検索・集計</span>
            </div>
            <div class="menu-grid">
                <!-- 予約済患者検索 -->
                <a href="search_patient.php" class="menu-card">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">🔎</span>
                            <h2>予約済患者検索</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-neutral">高速</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-patient-search')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-patient-search');}" title="予約済患者検索の操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        全期間・全科の患者横断検索。過去未来の予約履歴・カレンダージャンプ。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- フォローアップ管理 -->
                <a href="followup/index.php" class="menu-card menu-card-purple">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">📋</span>
                            <h2>フォローアップ管理</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-neutral">F/U</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-followup')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-followup');}" title="フォローアップ管理の操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        次回受診日・来院目的の登録、検査別F/U期間シートの発行・履歴管理。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- 検査数集計 -->
                <a href="stats.php" class="menu-card">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">📊</span>
                            <h2>検査数集計</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-neutral">CSV</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-stats')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-stats');}" title="検査数集計の操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        月別・科別・医師別の実績集計（GIF/TCF/エコー/術前F区分）＆CSV出力。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>
            </div>
        </section>

        <!-- 3. マスタ管理 -->
        <section aria-labelledby="section-master">
            <div class="section-header" id="section-master">
                <span aria-hidden="true">⚙️</span>
                <span>マスタ管理</span>
            </div>
            <div class="menu-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
                <!-- 検査枠マスターメンテナンス -->
                <a href="slot_master_maintenance.php" class="menu-card menu-card-amber">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">⚙️</span>
                            <h2>検査枠マスターメンテ</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-warning">枠・医師</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-slot-master')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-slot-master');}" title="検査枠マスターメンテの操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        医師所属科・時間枠定義・曜日別枠数パターン・日別例外の一括管理。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- 汎用マスタメンテナンス -->
                <a href="master_maintenance.php" class="menu-card">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">🔧</span>
                            <h2>汎用マスタメンテナンス</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-neutral">選択肢</span>
                            <span class="menu-help-btn" role="button" tabindex="0" onclick="openHelpSection(event, 'sec-master')" onkeydown="if(event.key==='Enter'||event.key===' '){openHelpSection(event, 'sec-master');}" title="汎用マスタメンテナンスの操作マニュアルを開く">❓</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        検査種別・来院時刻・実施場所などシステム内の各種選択肢の保守。
                    </div>
                    <div class="menu-card-footer">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>

                <!-- 日別メモ診療科振り分けメンテ -->
                <a href="furiwake_daymemo.php" class="menu-card" style="border-color: #fde68a; background: linear-gradient(180deg, #ffffff 0%, #fffbeb 100%);">
                    <div class="menu-card-header">
                        <div class="menu-card-title">
                            <span aria-hidden="true">📝</span>
                            <h2>日別メモ科振分メンテ</h2>
                        </div>
                        <div class="card-header-actions">
                            <span class="badge badge-warning">メモ整理</span>
                        </div>
                    </div>
                    <div class="menu-card-desc">
                        日別カレンダーメモ（休診・体制）の確認、肛門科・胃腸科へのワンクリック振り分け・整理。
                    </div>
                    <div class="menu-card-footer" style="color: #b45309;">
                        <span>開く</span>
                        <span aria-hidden="true">➔</span>
                    </div>
                </a>
            </div>
        </section>

    </main>

    <!-- フッター -->
    <footer class="portal-footer" role="contentinfo">
        <nav class="portal-footer-nav" aria-label="フッターナビゲーション">
            <a href="../index.php" class="portal-footer-link" title="医療法人小野会 院内総合ポータルへ戻る">🏥 院内総合ポータル</a>
            <span class="portal-footer-sep" aria-hidden="true">•</span>
            <a href="./仕様書/specdoc.html" class="portal-footer-link" target="_blank" rel="noopener noreferrer" title="操作マニュアルを表示（別ウィンドウ）">📖 操作マニュアル</a>
            <span class="portal-footer-sep" aria-hidden="true">•</span>
            <a href="help.php" class="portal-footer-link" target="_blank" rel="noopener noreferrer" title="システム仕様書を表示（別ウィンドウ）">❓ システム仕様書</a>
        </nav>
        <p>&copy; 2026 Medical Corporation Onokai. All Rights Reserved.</p>
        <p style="font-size: 11px; color: var(--slate-400); margin-top: 4px;">内視鏡予約システム Modernized Edition</p>
    </footer>

</div>

</body>
</html>