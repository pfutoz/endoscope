<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\waku_override_modal_inc.php
 * ファイル名: waku_override_modal_inc.php
 * 概要: 時間枠日別・期間別オーバーライド（m2_waku_override）簡単設定モーダル コンポーネント
 * 生成日時: 2026-10-22 14:00
 * 修正履歴:
 *   - 2026-10-23: 【モーダル縦見切れの完全解決＆確実なスクロールコンテナ化】<dialog> を display:block に戻し内部に .wom-modal-container を導入。height: min(88vh, 780px); max-height: calc(100vh - 40px); margin: auto; と flex: 1 1 0%; height: 0; を設定し、どんな解像度（高さ600px台の画面）でもフッターが絶対に見切れずテーブルが確実にスクロールする構造へ刷新
 *   - 2026-10-23: 【枠数過多時の画面見切れ解消＆モーダル内スクロール完全対応】モーダル高さを画面内に確実固定
 *   - 2026-10-23: 【予約済み臨時枠の削除防止ガード】確定患者予約が入っている臨時枠（増枠）の削除ボタン（🗑️）を無効化し、予約の迷子化を根本防止
 *   - 2026-10-22: 【Phase 3 時間枠簡単設定ポップアップ】新規作成
 *   - 2026-10-22: 【UX全面刷新 ＆ 現場ファーストDX改修】
 *       1. 特大Flatpickr（小野会カレンダー・公休日色分け・横幅440px）への完全統一
 *       2. ブラウザ標準 prompt() を完全撤廃、画面内ワンクリック連打ポンポン休止UI
 *       3. よく使う休止理由パレット（院内共通 m_input_master 連携、追加・削除・編集対応）
 *       4. 当日枠特化 ＆ クライアント側下書き（Draft）＋ 一括保存（コミット）/ キャンセル仕様
 *       5. 期間オーバーライド・設定一覧は専用サブ管理ビューへ分離し、未保存衝突を解消
 *       6. 旧来のダミー予約（※枠なし）に代わる新標準枠休止への完全統合案内
 *   - 2026-10-22: 【枠名称の一時的修正機能（✏️）を復活・強化】
 *       1. 各行の時間枠名に鉛筆アイコン（✏️）を設置し、当日の表示枠名を即時編集可能に
 *       2. 変更前後の枠名表示（新枠名 ＆ 元枠名）およびワンクリック元に戻す（↩️）ボタンを実装
 *       3. 下書きバッチ保存および既存臨時枠の名称修正にも完全連動
 *   - 2026-10-22: 【予約あり枠の休止防止 ＆ 縦方向圧縮・大画面化】
 *       1. 予約が入っている枠は［🚫 休止］ボタンを無効化（disabled・休止不可表示）
 *       2. 休止理由/特記事項列に予約患者タグ（👤 患者名・検査種別・ホバーツールチップ）を表示
 *       3. モーダル絶対高さを90vh/94vhに拡大し、余白・パレット・行パディングを極限圧縮（1画面全枠一覧性向上）
 *   - 2026-10-22: 【日付ピッカー（Flatpickr）動作不具合の完全解消】
 *       1. flatpickr.min.css / flatpickr.min.js / ja.js / onokai_flatpickr.js の自己完結ロードを保証
 *       2. HTML5 <dialog> Top Layer に対応するため appendTo: modal 設定および最前面 z-index を指定
 *       3. 日付入力欄およびカレンダーアイコンのクリック発火（openWomDatePicker）とフォールバックを完備
 *   - 2026-11-06: onokai_flatpickr.js 読み込みタグに filemtime キャッシュバスターを追加
 */
require_once __DIR__ . '/lib/slot_helper.php';
$wom_is_period_allowed = isPeriodOverrideAllowed($pdo);
?>
<!-- 特大Flatpickr用スタイル & スクリプト（親画面未ロード時も安全に自己完結ロード） -->
<link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
<link rel="stylesheet" href="assets/css/onokai_flatpickr.css?v=<?= filemtime(__DIR__ . '/assets/css/onokai_flatpickr.css') ?>">
<script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="assets/vendor/flatpickr/ja.js"></script>
<script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>
<script>
window.WOM_IS_PERIOD_ALLOWED = <?= $wom_is_period_allowed ? 'true' : 'false' ?>;
</script>

<!-- ── 時間枠簡単設定・休止モーダル (<dialog>) ── -->
<style>
/* ── モーダル基本スタイル ── */
#wakuOverrideModal:not([open]) {
    display: none !important;
}

#wakuOverrideModal[open] {
    display: block !important; /* dialog 自体は block にし、UA標準の中央配置と高さ制約を確実に効かせる */
}

#wakuOverrideModal {
    box-sizing: border-box !important;
    border: none !important;
    border-radius: 10px !important;
    padding: 0 !important;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(0, 0, 0, 0.12) !important;
    background: #ffffff !important;
    color: #0f172a !important;
    font-family: -apple-system, BlinkMacSystemFont, "BIZ UDPGothic", "Segoe UI", Roboto, sans-serif !important;
    max-width: 1180px !important;
    width: 96vw !important;
    /* 🌟 モーダル画面出す位置を可能な限り上にだす（すりガラス上部を極限まで狭く調整） */
    position: fixed !important;
    top: 8px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    bottom: auto !important;
    margin: 0 !important;
    /* 🌟 縦領域を目一杯活用（下端に12pxの余白のみ確保） */
    height: calc(100vh - 20px) !important;
    max-height: calc(100vh - 20px) !important;
    overflow: hidden !important;
    z-index: 10000 !important;
}

/* 内部 flexbox コンテナ */
.wom-modal-container {
    display: flex !important;
    flex-direction: column !important;
    width: 100% !important;
    height: 100% !important;
    max-height: 100% !important;
    min-height: 0 !important;
    overflow: hidden !important;
    position: relative !important;
}

#wakuOverrideModal::backdrop {
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

/* Flatpickr カレンダーをモーダルより最前面に浮遊配置（dialog Top Layer対応・見切れ完全追放） */
.flatpickr-calendar,
#wakuOverrideModal .flatpickr-calendar {
    z-index: 100050 !important;
}

#wakuOverrideModal .wom-toolbar {
    position: relative;
    z-index: 100;
}

.wom-header {
    background: linear-gradient(135deg, #075985 0%, #0369a1 100%);
    color: #ffffff;
    padding: 6px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.wom-title {
    font-size: 13.5px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.01em;
}

.wom-close-btn {
    background: rgba(255, 255, 255, 0.15);
    border: none;
    color: #ffffff;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.wom-close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.05);
}

/* ── ツールバー / 日付選択 ── */
.wom-toolbar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 4px 14px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    flex-shrink: 0;
}

/* ── ツールバー日付ピッカー（西暦・和暦切替＋特大Flatpickr起動） ── */
.wom-custom-date-control {
    display: inline-flex;
    align-items: center;
    height: 30px; /* 30pxコンパクト */
    border: 2px solid #3b82f6;
    border-radius: 6px;
    background: #eff6ff;
    box-shadow: 0 1px 3px rgba(59, 130, 246, 0.15);
    user-select: none;
    box-sizing: border-box;
    transition: all 0.2s ease-in-out;
    vertical-align: middle;
    position: relative;
    cursor: pointer;
}
.wom-custom-date-control:hover {
    background: #dbeafe;
    border-color: #2563eb;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
}
.wom-date-text-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    height: 100%;
    cursor: pointer;
    padding: 0 6px 0 8px;
}
.wom-date-val-text {
    font-size: 12.5px;
    font-weight: 800;
    color: #1e3a8a;
    letter-spacing: -0.01em;
    white-space: nowrap;
}
.wom-date-mode-badge {
    font-size: 8.5px;
    font-weight: 800;
    padding: 1px 3px;
    border-radius: 3px;
    background: #bfdbfe;
    color: #1e40af;
    line-height: 1;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.wom-date-calendar-icon-btn {
    width: 28px;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    cursor: pointer;
    border-left: 1px solid #bfdbfe;
    transition: background 0.15s;
    border-top-right-radius: 5px;
    border-bottom-right-radius: 5px;
}
.wom-date-calendar-icon-btn:hover {
    background: #bfdbfe;
}
.wom-hidden-date-input {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
    left: 0;
    bottom: 0;
    margin: 0;
    padding: 0;
    border: none;
}

.wom-dept-badge {
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 800;
}
.wom-dept-badge.PROCT { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.wom-dept-badge.GASTRO { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

/* ── 休止理由パレット ── */
.wom-palette-wrap {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 4px 8px;
    margin-bottom: 4px;
    flex-shrink: 0;
}

.wom-palette-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
}

.wom-chips-container {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
}

.wom-reason-chip {
    padding: 2px 8px;
    border-radius: 9999px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
    user-select: none;
}
.wom-reason-chip:hover {
    border-color: #0284c7;
    color: #0284c7;
    background: #f0f9ff;
}
.wom-reason-chip.active {
    background: #0284c7;
    border-color: #0284c7;
    color: #ffffff;
    font-weight: 800;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
}

.wom-chip-del-btn {
    font-size: 10px;
    color: #94a3b8;
    cursor: pointer;
    padding: 0 2px;
    border-radius: 50%;
}
.wom-reason-chip.active .wom-chip-del-btn {
    color: rgba(255, 255, 255, 0.7);
}
.wom-chip-del-btn:hover {
    color: #ef4444 !important;
}

.wom-chip-add-btn {
    padding: 2px 8px;
    border-radius: 9999px;
    border: 1.5px dashed #94a3b8;
    background: transparent;
    color: #475569;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.wom-chip-add-btn:hover {
    border-color: #0284c7;
    color: #0284c7;
    background: #f0f9ff;
}

/* ── モーダルボディ（縦スクロールコンテナ） ── */
.wom-body {
    padding: 8px 16px 14px 16px !important;
    flex: 1 1 0% !important; /* 重要: 0% にすることでコンテンツの高さに引っ張られず親の残り高さに固定 */
    height: 0 !important;
    min-height: 0 !important;
    overflow-y: auto !important; /* 縦スクロールを明示・強制 */
    overflow-x: hidden !important;
    -webkit-overflow-scrolling: touch;
}

/* スクロールバーのスタイリング（見やすく操作しやすいモダンバー） */
.wom-body::-webkit-scrollbar {
    width: 10px;
}
.wom-body::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 5px;
}
.wom-body::-webkit-scrollbar-thumb {
    background: #94a3b8;
    border-radius: 5px;
    border: 2px solid #f1f5f9;
}
.wom-body::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

/* ── スロットテーブル ── */
.wom-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-bottom: 8px;
}

.wom-table th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    color: #334155;
    font-weight: 800;
    text-align: left;
    padding: 7px 8px;
    border-bottom: 2px solid #cbd5e1;
    font-size: 11.5px;
    z-index: 5; /* スクロール時にtdの上に重なる */
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.wom-table td {
    padding: 4px 8px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    transition: background-color 0.15s ease;
}

.wom-table tr:hover td {
    background-color: #f8fafc;
}

.wom-table tr.row-suspended td {
    background-color: #fef2f2 !important;
}

.wom-table tr.row-extra td {
    background-color: #f0fdf4 !important;
}

.wom-table tr.row-dirty td:first-child {
    border-left: 3.5px solid #f59e0b;
}

.wom-slot-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
}
.wom-slot-badge.GIF { background: #dbeafe; color: #1e40af; }
.wom-slot-badge.ECHO { background: #fce7f3; color: #9d174d; }
.wom-slot-badge.TCF { background: #d1fae5; color: #065f46; }

.wom-status-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 6px;
    border-radius: 9999px;
    font-size: 10.5px;
    font-weight: 700;
}
.wom-status-tag.normal { background: #f1f5f9; color: #64748b; }
.wom-status-tag.suspended { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.wom-status-tag.override { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }

/* 確定予約者バッジ（青タグ） */
.wom-reserved-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 11px;
    font-weight: 700;
    cursor: help;
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
    box-shadow: 0 1px 2px rgba(2, 132, 199, 0.08);
    transition: all 0.15s ease;
}
.wom-reserved-tag:hover {
    background: #bae6fd;
    color: #0284c7;
    transform: translateY(-1px);
}

/* 行内理由クリック編集 */
.wom-reason-clickable {
    cursor: pointer;
    padding: 1px 6px;
    border-radius: 4px;
    background: rgba(239, 68, 68, 0.08);
    border: 1px dashed rgba(239, 68, 68, 0.4);
    color: #dc2626;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.wom-reason-clickable:hover {
    background: rgba(239, 68, 68, 0.16);
}

/* アクションボタン類 */
.wom-action-btn {
    border: none;
    border-radius: 5px;
    padding: 3px 10px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
    user-select: none;
}

.wom-btn-suspend {
    background: #ffffff;
    color: #dc2626;
    border: 1.5px solid #fca5a5;
}
.wom-btn-suspend:hover {
    background: #ef4444;
    color: #ffffff;
    border-color: #ef4444;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
}

/* 予約あり枠の休止不可ボタン */
.wom-btn-suspend:disabled {
    background: #f1f5f9 !important;
    color: #94a3b8 !important;
    border: 1px solid #cbd5e1 !important;
    cursor: not-allowed !important;
    opacity: 0.65;
    box-shadow: none !important;
    transform: none !important;
}

.wom-btn-restore {
    background: #10b981;
    color: #ffffff;
    border: 1.5px solid #059669;
}
.wom-btn-restore:hover {
    background: #059669;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
}

.wom-btn-mini-edit {
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 12px;
    padding: 1px 3px;
    border-radius: 4px;
    opacity: 0.65;
    transition: all 0.15s ease;
    vertical-align: middle;
}
.wom-btn-mini-edit:hover {
    opacity: 1;
    background: #e0f2fe;
    transform: scale(1.15);
}

/* 臨時枠アコーディオンカード */
.wom-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 12px;
    margin-top: 6px;
}

.wom-card-title {
    font-size: 11.5px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.wom-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1.5fr auto;
    gap: 8px;
    align-items: end;
}

.wom-form-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.wom-form-label {
    font-size: 10.5px;
    font-weight: 700;
    color: #475569;
}

.wom-form-input, .wom-form-select {
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 3px 6px;
    font-size: 11.5px;
    background: #ffffff;
}

/* ── フッター ── */
.wom-footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 10px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    z-index: 10;
    box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.04);
}

.wom-link-subview {
    font-size: 11.5px;
    font-weight: 700;
    color: #0284c7;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.wom-link-subview:hover {
    color: #0369a1;
    text-decoration: underline;
}

.wom-footer-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.wom-btn-cancel {
    background: #ffffff;
    color: #475569;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s ease;
}
.wom-btn-cancel:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.wom-btn-save {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 6px 16px;
    font-size: 12.5px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
.wom-btn-save:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 3px 5px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}
.wom-btn-save:disabled {
    background: #94a3b8;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
}

.wom-save-badge {
    background: #f59e0b;
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 800;
    padding: 1px 5px;
    border-radius: 9999px;
    margin-left: 4px;
}

/* メインビューのコンテナ */
#womView_day {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 0% !important;
    height: 0 !important; /* 重要: height: 0 + flex: 1 1 0% で親の高さを超えないようにする */
    min-height: 0 !important;
    max-height: 100% !important;
    overflow: hidden !important;
}

/* サブビュー（期間・一覧管理） */
#womSubview_manage {
    display: none;
    flex-direction: column !important;
    flex: 1 1 0% !important;
    height: 0 !important;
    min-height: 0 !important;
    max-height: 100% !important;
    overflow-y: auto !important;
    padding: 10px 16px !important;
}
</style>

<dialog id="wakuOverrideModal" closedby="any">
    <div class="wom-modal-container">
        <!-- ヘッダー -->
        <div class="wom-header">
        <div class="wom-title">
            <span>⚙️</span>
            <span id="womHeaderTitle">時間枠の臨時設定・休止</span>
            <code style="font-size: 10.5px; font-weight: 600; font-family: ui-monospace, monospace; background: rgba(255,255,255,0.2); color: #ffffff; padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.35);" title="PostgreSQL 対象テーブル">TABLE: m2_waku_override</code>
        </div>
        <button type="button" class="wom-close-btn" onclick="cancelWomModal()" title="閉じる">✕</button>
    </div>

    <!-- ── メインビュー: 当日の時間枠設定（下書きコミット型） ── -->
    <div id="womView_day">
        <!-- ツールバー（特大Flatpickr日付選択・診療科） -->
        <div class="wom-toolbar">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="user-select:none; font-size:12.5px; font-weight:700; color:#334155;">📅 対象日:</span>
                <!-- 院内共通 小野会日付ピッカー（角丸ピル形状・西暦/和暦切替・仕切り📅アイコン） -->
                <?= render_onokai_date_control('womTargetDate', 'target_date', date('Y-m-d'), ['control_id' => 'womCustomDateControl']) ?>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span id="womDeptBadge" class="wom-dept-badge PROCT">肛門科</span>
            </div>
        </div>

        <div class="wom-body">
            <!-- 🏷️ よく使う休止理由パレット -->
            <div class="wom-palette-wrap">
                <div class="wom-palette-header">
                    <span>🏷️ よく使う休止理由（クリックで選択 ➔ 下の枠を「🚫 休止」で即時セット）:</span>
                    <div>
                        <button type="button" class="wom-chip-add-btn" onclick="promptAddCustomReason()">＋ 理由を追加</button>
                    </div>
                </div>
                <div id="womChipsContainer" class="wom-chips-container">
                    <span style="font-size:11px; color:#94a3b8;">理由データをロード中...</span>
                </div>
            </div>

            <!-- ガイダンスメッセージ -->
            <div style="font-size:11.5px; color:#475569; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    💡 各枠の <strong>[🚫 休止]</strong> で休止設定、<strong>[✏️]</strong> で当日枠名を変更できます。<br>
                    <span style="color:#64748b; font-size:10.5px;">（※右下の「💾 変更を保存して反映」を押すまで確定されません。確定予約が入っている枠は休止できません）</span>
                </div>
                <div id="womDirtyNotice" style="display:none; color:#d97706; font-size:11.5px; font-weight:700;">
                    ⚠️ 未保存の変更があります
                </div>
            </div>

            <!-- 時間枠テーブル -->
            <table class="wom-table">
                <thead>
                    <tr>
                        <th style="width:120px;">時間枠名</th>
                        <th style="width:65px;">区分</th>
                        <th style="width:110px;">状態</th>
                        <th>休止理由 / 特記事項 / 予約者</th>
                        <th style="width:120px; text-align:center;">操作</th>
                    </tr>
                </thead>
                <tbody id="womSlotTableBody">
                    <tr>
                        <td colspan="5" style="text-align:center; padding:30px; color:#64748b;">
                            ⏳ 枠データを読み込み中...
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- 臨時枠追加フォーム -->
            <div class="wom-card">
                <div class="wom-card-title">
                    <span>➕ この日に臨時枠（増枠）を追加する</span>
                </div>
                <form id="womAddExtraForm" onsubmit="addDraftExtraSlot(event)">
                    <div class="wom-form-grid">
                        <div class="wom-form-group">
                            <label class="wom-form-label">枠名称 *</label>
                            <input type="text" id="womExtraName" class="wom-form-input" required placeholder="例: 14:30(特)">
                        </div>
                        <div class="wom-form-group">
                            <label class="wom-form-label">検査区分 *</label>
                            <select id="womExtraGroup" class="wom-form-select">
                                <option value="GIF">GIF (胃)</option>
                                <option value="ECHO">ECHO (超音波)</option>
                                <option value="TCF" selected>TCF (大腸)</option>
                            </select>
                        </div>
                        <div class="wom-form-group">
                            <label class="wom-form-label">理由・特記</label>
                            <input type="text" id="womExtraReason" class="wom-form-input" placeholder="例: 午後臨時枠">
                        </div>
                        <div>
                            <button type="submit" class="wom-action-btn" style="background:#0284c7; color:#fff; padding:4px 12px;">
                                ➕ 下書きに追加
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- フッター -->
        <div class="wom-footer">
            <div>
                <?php if ($wom_is_period_allowed): ?>
                    <a class="wom-link-subview" onclick="switchToManageView()">
                        <span>📅 期間オーバーライド・設定一覧の管理へ ➔</span>
                    </a>
                <?php else: ?>
                    <span style="font-size:12px; color:#94a3b8; font-weight:600; cursor:pointer;" onclick="alert('⚠️ 現在、期間指定設定機能は許可されていません。\n（現場スタッフの誤操作防止のため単一日操作のみに制限されています。\n有効化する場合は【枠マスタ保守】の設定をご確認ください）');" title="現在、期間指定機能はマスタ設定により許可されていません（単一日操作のみ有効）">
                        🔒 期間設定機能: 許可されていません（単一日のみ）
                    </span>
                <?php endif; ?>
            </div>
            <div class="wom-footer-actions">
                <button type="button" class="wom-btn-cancel" onclick="cancelWomModal()">
                    キャンセル (破棄)
                </button>
                <button type="button" id="womBtnSave" class="wom-btn-save" onclick="saveWomDraftBatch()">
                    <span>💾 変更を保存して反映</span>
                    <span id="womSaveBadge" class="wom-save-badge" style="display:none;">0</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── サブビュー: 期間オーバーライド登録 ＆ 設定一覧管理 ── -->
    <div id="womSubview_manage">
        <div class="wom-toolbar">
            <button type="button" class="wom-action-btn" style="background:#e2e8f0; color:#334155;" onclick="switchToDayView()">
                ⬅️ 当日の時間枠設定に戻る
            </button>
            <span style="font-weight:700; font-size:13px; color:#475569;">
                期間オーバーライド登録 ＆ 設定一覧
            </span>
        </div>

        <div class="wom-body">
            <!-- 期間登録フォーム -->
            <div class="wom-card" style="margin-top:0; margin-bottom:18px;">
                <div class="wom-card-title">
                    <span>📅 期間指定オーバーライドの新規登録</span>
                </div>
                <form id="womPeriodForm" onsubmit="submitWomPeriodOverride(event)">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:12px; align-items: start;">
                        <div class="wom-form-group">
                            <label class="wom-form-label">開始日 *</label>
                            <?= render_onokai_date_control('womPeriodStart', 'start_date', date('Y-m-d'), ['width' => '100%']) ?>
                        </div>
                        <div class="wom-form-group">
                            <label class="wom-form-label">終了日 *</label>
                            <?= render_onokai_date_control('womPeriodEnd', 'end_date', date('Y-m-d'), ['width' => '100%']) ?>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                        <div class="wom-form-group">
                            <label class="wom-form-label">対象基本枠 *</label>
                            <select id="womPeriodBaseWaku" class="wom-form-select" onchange="onPeriodBaseWakuChange(this)" required>
                                <option value="">-- 基本枠を選択 --</option>
                            </select>
                        </div>
                        <div class="wom-form-group">
                            <label class="wom-form-label">表示枠名（変更する場合）</label>
                            <input type="text" id="womPeriodDisplayName" class="wom-form-input" placeholder="基本枠名と同じ">
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns: 1.2fr 2fr auto; gap:10px; align-items:end;">
                        <div class="wom-form-group">
                            <label class="wom-form-label">休止設定 *</label>
                            <select id="womPeriodIsSuspended" class="wom-form-select">
                                <option value="1">🚫 期間中、枠を休止にする</option>
                                <option value="0">✅ 休止せず枠名のみ変更</option>
                            </select>
                        </div>
                        <div class="wom-form-group">
                            <label class="wom-form-label">理由・特記</label>
                            <input type="text" id="womPeriodReason" class="wom-form-input" placeholder="例: 年末年始減枠">
                        </div>
                        <div>
                            <button type="submit" class="wom-action-btn" style="background:#0284c7; color:#fff; padding:6px 16px;">
                                💾 登録する
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- 設定済み一覧テーブル -->
            <div style="font-weight:700; font-size:13px; color:#1e293b; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                <span>📋 設定済みオーバーライド一覧 (<span id="womOverrideCount">0</span>件)</span>
                <button type="button" class="wom-action-btn" style="background:#f1f5f9; color:#475569; font-size:11px;" onclick="loadWomOverridesList()">🔄 最新化</button>
            </div>
            <table class="wom-table">
                <thead>
                    <tr>
                        <th style="width:70px;">種別</th>
                        <th style="width:150px;">対象日 / 期間</th>
                        <th style="width:110px;">枠名</th>
                        <th style="width:80px;">状態</th>
                        <th>理由</th>
                        <th style="width:70px; text-align:center;">解除</th>
                    </tr>
                </thead>
                <tbody id="womOverrideTableBody">
                    <tr>
                        <td colspan="6" style="text-align:center; padding:20px; color:#64748b;">
                            読み込み中...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="wom-footer">
            <button type="button" class="wom-action-btn" style="background:#e2e8f0; color:#334155;" onclick="switchToDayView()">
                ⬅️ 当日の時間枠設定に戻る
            </button>
            <button type="button" class="wom-btn-cancel" onclick="closeWakuOverrideModal()">
                閉じる
            </button>
        </div>
    </div>
    </div>
</dialog>

<!-- 特大Flatpickr JSの読み込み保証 -->
<script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>

<script>
/**
 * 時間枠簡単設定モーダル JS コントローラ
 */
let currentWomDate = '<?= date("Y-m-d") ?>';
let currentWomDept = 'PROCT';
let womNeedsReloadParent = false;
let womFpInstance = null;

// 下書き（Draft）ステート管理
let womInitialSlots = [];
let womDraftSlots = [];
let womDeletedOverrideIds = [];
let womReasons = [];
let womActiveReason = '院内研修';

/**
 * モーダルを開く
 */
async function openWakuOverrideModal(date, dept, defaultView = 'day') {
    // 引数の順序逆転（dept が第1引数、date が第2引数）を自動判定・吸収
    if (date === 'PROCT' || date === 'GASTRO') {
        const tempDept = date;
        date = (typeof dept === 'string' && dept.match(/^\d{4}-\d{2}-\d{2}$/)) ? dept : '<?= date("Y-m-d") ?>';
        dept = tempDept;
    }

    currentWomDate = (date && String(date).match(/^\d{4}-\d{2}-\d{2}$/)) ? String(date) : currentWomDate;
    currentWomDept = (dept === 'GASTRO') ? 'GASTRO' : 'PROCT';
    womNeedsReloadParent = false;
    womDeletedOverrideIds = [];

    const modal = document.getElementById('wakuOverrideModal');
    if (!modal) return;

    // ヘッダー情報セット
    updateWomToolbarDisplay();

    // モーダル表示
    if (typeof modal.showModal === 'function') {
        modal.showModal();
    } else {
        modal.setAttribute('open', '');
    }

    // 特大Flatpickrのバインド（未初期化なら初期化）
    initWomFlatpickr();

    // 理由パレットの読み込み
    await loadWomReasons();

    // ビューの切り替え（期間指定が禁止されている場合は安全に当日の単一日画面へフォールバック）
    if (defaultView === 'manage' && window.WOM_IS_PERIOD_ALLOWED) {
        switchToManageView();
    } else {
        switchToDayView();
    }
}

/**
 * 特大Flatpickrのカレンダーオープン
 */
function openWomDatePicker(e) {
    if (e) {
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }
    if (!womFpInstance) {
        initWomFlatpickr();
    }
    if (womFpInstance) {
        womFpInstance.open();
    }
}

/**
 * 特大Flatpickrの初期化
 */
function initWomFlatpickr() {
    const input = document.getElementById('womTargetDate');
    if (!input) return;

    input.value = currentWomDate;

    const modal = document.getElementById('wakuOverrideModal');

    if (window.OnokaiDatePicker) {
        const ctrl = OnokaiDatePicker.bindControl(input, {
            defaultDate: currentWomDate,
            static: true,
            appendTo: modal || document.body,
            onChange: function(selectedDates, dateStr) {
                if (dateStr && dateStr !== currentWomDate) {
                    onWomDateChange(dateStr);
                }
            }
        });
        if (ctrl) {
            ctrl.setDate(currentWomDate, false);
            womFpInstance = ctrl.flatpickr;
        }
    }
}

let womPeriodStartFp = null;
let womPeriodEndFp = null;

/**
 * 期間指定ピッカーの初期化（Top Layer・裏隠れ防止版）
 */
function initWomPeriodPickers() {
    const pStart = document.getElementById('womPeriodStart');
    const pEnd = document.getElementById('womPeriodEnd');
    const modal = document.getElementById('wakuOverrideModal');
    const attachFn = window.attachOnokaiDatePicker || (window.OnokaiDatePicker && window.OnokaiDatePicker.attach);

    if (!pStart || !pEnd) return;

    if (!pStart.value) pStart.value = currentWomDate;
    if (!pEnd.value) pEnd.value = currentWomDate;

    if (window.OnokaiDatePicker) {
        if (!pStart._onokaiControl) {
            const ctrlStart = OnokaiDatePicker.bindControl(pStart, {
                defaultDate: pStart.value,
                static: false,
                appendTo: modal || document.body,
                onChange: function(selectedDates, dateStr) {
                    if (womPeriodEndFp && dateStr) {
                        womPeriodEndFp.set('minDate', dateStr);
                        if (pEnd.value && pEnd.value < dateStr) {
                            if (pEnd._onokaiControl) {
                                pEnd._onokaiControl.setDate(dateStr, true);
                            } else if (womPeriodEndFp) {
                                womPeriodEndFp.setDate(dateStr, true);
                            }
                        }
                    }
                }
            });
            womPeriodStartFp = ctrlStart ? ctrlStart.flatpickr : null;
        } else {
            pStart._onokaiControl.setDate(pStart.value, false);
        }

        if (!pEnd._onokaiControl) {
            const ctrlEnd = OnokaiDatePicker.bindControl(pEnd, {
                defaultDate: pEnd.value,
                static: false,
                appendTo: modal || document.body,
                minDate: pStart.value
            });
            womPeriodEndFp = ctrlEnd ? ctrlEnd.flatpickr : null;
        } else {
            pEnd._onokaiControl.setDate(pEnd.value, false);
        }
    }
}

/**
 * 日付変更時の処理
 */
function onWomDateChange(newDate) {
    if (!newDate || newDate === currentWomDate) return;

    // 未保存変更がある場合は確認
    const changes = getWomDraftChanges();
    if (changes.totalCount > 0) {
        if (!confirm('当日の設定に未保存の変更があります。\n変更を破棄して別の日付に移動しますか？')) {
            // 元の日付に戻す
            if (womFpInstance) womFpInstance.setDate(currentWomDate, false);
            return;
        }
    }

    currentWomDate = newDate;
    updateWomToolbarDisplay();
    loadWomDaySlots();
}

let womDateDisplayMode = 'seireki';
try {
    womDateDisplayMode = localStorage.getItem('schedule_note_date_mode') || localStorage.getItem('waku_modal_date_mode') || 'seireki';
} catch (e) {}

/**
 * 西暦 ⇔ 和暦 表示切替
 */
function toggleWomDateMode(e) {
    if (e) {
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }
    womDateDisplayMode = (womDateDisplayMode === 'seireki') ? 'wareki' : 'seireki';
    try {
        localStorage.setItem('schedule_note_date_mode', womDateDisplayMode);
        localStorage.setItem('waku_modal_date_mode', womDateDisplayMode);
    } catch (e) {}
    updateWomToolbarDisplay();
}

/**
 * 和暦フォーマット
 */
function formatWomWareki(dateStr, dowStr) {
    const parts = dateStr.split('-');
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    let g = '令和';
    let gy = y - 2018;
    if (y < 2019) {
        g = '平成';
        gy = y - 1988;
    }
    const gStr = (gy === 1) ? '元' : gy;
    return `${g}${gStr}年${m}/${d} (${dowStr})`;
}

/**
 * 西暦フォーマット
 */
function formatWomSeireki(dateStr, dowStr) {
    const parts = dateStr.split('-');
    const y = parts[0];
    const m = parts[1];
    const d = parts[2];
    return `${y}/${m}/${d} (${dowStr})`;
}

/**
 * ツールバーの表示更新（曜日・科バッジ・西暦和暦コントロール）
 */
function updateWomToolbarDisplay() {
    const input = document.getElementById('womTargetDate');
    if (input) {
        input.value = currentWomDate;
        if (input._onokaiControl) {
            input._onokaiControl.setDate(currentWomDate, false);
        }
    }

    const badge = document.getElementById('womDeptBadge');
    if (badge) {
        badge.className = `wom-dept-badge ${currentWomDept}`;
        badge.textContent = (currentWomDept === 'GASTRO') ? '胃腸科' : '肛門科';
    }
}

/**
 * 理由プリセットのロード
 */
async function loadWomReasons() {
    try {
        const res = await fetch(`action_waku_override.php?action=get_suspend_reasons`);
        const json = await res.json();
        if (json.success && Array.isArray(json.reasons)) {
            womReasons = json.reasons;
            if (womReasons.length > 0 && !womReasons.some(r => r.reason_text === womActiveReason)) {
                womActiveReason = womReasons[0].reason_text;
            }
            renderWomReasonChips();
        }
    } catch (e) {
        console.error('loadWomReasons error:', e);
    }
}

/**
 * 理由パレットの描画
 */
function renderWomReasonChips() {
    const container = document.getElementById('womChipsContainer');
    if (!container) return;

    container.innerHTML = '';
    womReasons.forEach(r => {
        const isActive = (r.reason_text === womActiveReason);
        const chip = document.createElement('div');
        chip.className = `wom-reason-chip ${isActive ? 'active' : ''}`;
        chip.innerHTML = `
            <span>${escapeWomHtml(r.reason_text)}</span>
            <span class="wom-chip-del-btn" title="この理由を削除" onclick="deleteCustomReason(event, ${r.master_id}, '${escapeWomHtml(r.reason_text)}')">✕</span>
        `;
        chip.onclick = (e) => {
            if (e.target.classList.contains('wom-chip-del-btn')) return;
            womActiveReason = r.reason_text;
            renderWomReasonChips();
        };
        container.appendChild(chip);
    });
}

/**
 * 新しい理由の追加
 */
async function promptAddCustomReason() {
    const newReason = window.prompt('新しい休止理由を入力してください (例: 台風接近、院長学会出張 など):');
    if (!newReason || !newReason.trim()) return;

    try {
        const fd = new FormData();
        fd.append('action', 'add_suspend_reason');
        fd.append('reason_text', newReason.trim());

        const res = await fetch('action_waku_override.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
            womActiveReason = newReason.trim();
            await loadWomReasons();
        } else {
            alert('理由の追加に失敗しました: ' + (json.error || json.message));
        }
    } catch (e) {
        alert('エラー: ' + e.message);
    }
}

/**
 * 理由の削除
 */
async function deleteCustomReason(e, masterId, text) {
    e.stopPropagation();
    if (!confirm(`休止理由「${text}」をパレットから削除しますか？`)) return;

    try {
        const fd = new FormData();
        fd.append('action', 'delete_suspend_reason');
        fd.append('master_id', masterId);

        const res = await fetch('action_waku_override.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
            await loadWomReasons();
        }
    } catch (e) {
        alert('削除エラー: ' + e.message);
    }
}

/**
 * ビュー切り替え: 当日設定
 */
function switchToDayView() {
    document.getElementById('womView_day').style.display = 'block';
    document.getElementById('womSubview_manage').style.display = 'none';
    document.getElementById('womHeaderTitle').textContent = '時間枠の臨時設定・休止';
    loadWomDaySlots();
}

/**
 * ビュー切り替え: 期間・一覧管理
 */
function switchToManageView() {
    if (!window.WOM_IS_PERIOD_ALLOWED) {
        alert("⚠️ 現在、期間指定設定機能は許可されていません。\n（現場スタッフの誤操作防止のため単一日操作のみに制限されています。\n有効化する場合は【枠マスタ保守】の設定をご確認ください）");
        return;
    }

    const changes = getWomDraftChanges();
    if (changes.totalCount > 0) {
        if (!confirm('当日の設定に未保存の変更があります。\n破棄して管理画面を開きますか？\n（※保存する場合は「キャンセル」を押して先に保存してください）')) {
            return;
        }
    }

    document.getElementById('womView_day').style.display = 'none';
    document.getElementById('womSubview_manage').style.display = 'block';
    document.getElementById('womHeaderTitle').textContent = '時間枠オーバーライド管理（期間設定＆一覧）';

    // 期間フォームの日付初期化＆ピッカーバインド
    initWomPeriodPickers();

    // 基本枠選択肢のロード
    loadWomBaseSlotsForPeriod();
    loadWomOverridesList();
}

/**
 * 当日の枠データ取得 ➔ 下書き展開
 */
async function loadWomDaySlots() {
    const tbody = document.getElementById('womSlotTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px; color:#64748b;">⏳ 枠データを読み込み中...</td></tr>';

    try {
        const res = await fetch(`action_waku_override.php?action=get_day_slots&dept=${currentWomDept}&date=${currentWomDate}`);
        const json = await res.json();

        if (!json.success) {
            throw new Error(json.error || 'データ取得失敗');
        }

        // 初期ステートと下書きステートを複製
        womInitialSlots = JSON.parse(JSON.stringify(json.effective_slots || []));
        womDraftSlots = JSON.parse(JSON.stringify(json.effective_slots || []));
        womDeletedOverrideIds = [];

        renderWomDaySlotsTable();
        updateSaveButtonState();

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:20px; color:#dc2626;">エラー: ${escapeWomHtml(e.message)}</td></tr>`;
    }
}

/**
 * 当日枠テーブルの描画
 */
function renderWomDaySlotsTable() {
    const tbody = document.getElementById('womSlotTableBody');
    if (!tbody) return;

    tbody.innerHTML = '';
    const slots = womDraftSlots.filter(s => !s.is_deleted);

    if (slots.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px; color:#64748b;">枠データがありません</td></tr>';
        return;
    }

    slots.forEach((slot, idx) => {
        const tr = document.createElement('tr');
        const isSusp = !!slot.is_suspended;
        const isExtra = !!slot.is_override && !slot.base_waku_id;
        const isDirty = checkSlotDirty(slot);

        // 確定予約の有無
        const resList = slot.reservations || [];
        const hasReservation = (slot.has_reservation || resList.length > 0);

        if (isSusp) tr.classList.add('row-suspended');
        if (isExtra) tr.classList.add('row-extra');
        if (isDirty) tr.classList.add('row-dirty');

        // 状態バッジ
        let statusBadge = '<span class="wom-status-tag normal">通常枠</span>';
        if (isSusp) {
            statusBadge = '<span class="wom-status-tag suspended">🚫 休止中</span>';
        } else if (isExtra) {
            statusBadge = '<span class="wom-status-tag override">➕ 臨時枠</span>';
        }

        // 理由・特記事項・予約者表示
        let reasonHtml = '<span style="color:#94a3b8;">-</span>';
        if (isSusp) {
            const rText = slot.reason || '休止';
            reasonHtml = `<span class="wom-reason-clickable" title="クリックで理由を変更" onclick="promptChangeSlotReason(${idx})">✏️ ${escapeWomHtml(rText)}</span>`;
        } else {
            if (hasReservation) {
                const resTags = resList.map(r => {
                    const tip = `カルテID: ${r.patient_id} / 医師: ${r.doctor_name || '未定'} / 検査場所: ${r.exam_location || '未定'}`;
                    return `<span class="wom-reserved-tag" title="${escapeWomHtml(tip)}">👤 ${escapeWomHtml(r.patient_name)} <span style="font-size:10px; opacity:0.85;">(${escapeWomHtml(r.exam_type)})</span></span>`;
                }).join(' ');

                const extraNote = slot.reason ? `<span style="color:#64748b; font-size:11px; margin-left:6px;">(${escapeWomHtml(slot.reason)})</span>` : '';
                reasonHtml = `${resTags}${extraNote}`;
            } else if (slot.reason) {
                reasonHtml = `<span style="color:#475569;">${escapeWomHtml(slot.reason)}</span>`;
            }
        }

        // 枠名称の表示（当日の枠名称一時変更 ＆ 元枠名復帰）
        const currentName = slot.display_waku_name || slot.waku_name || '';
        const baseName = slot.original_name || (slot.base_waku_id ? slot.waku_name : '') || currentName;
        const isNameChanged = (baseName && currentName !== baseName);

        let nameDisplayHtml = '';
        if (isNameChanged) {
            nameDisplayHtml = `
                <div style="display:flex; flex-direction:column; gap:1px;">
                    <div style="display:flex; align-items:center; gap:4px;">
                        <strong style="color:#0284c7; font-size:12.5px;">${escapeWomHtml(currentName)}</strong>
                        <button type="button" class="wom-btn-mini-edit" onclick="promptChangeSlotName(${idx})" title="枠名称を修正">✏️</button>
                    </div>
                    <div style="font-size:10.5px; color:#64748b; display:flex; align-items:center; gap:4px;">
                        <span>(元: ${escapeWomHtml(baseName)})</span>
                        <a href="javascript:void(0)" onclick="resetSlotDisplayName(${idx})" style="color:#dc2626; font-size:10px; font-weight:700; text-decoration:underline;">[↩️元に戻す]</a>
                    </div>
                </div>
            `;
        } else {
            nameDisplayHtml = `
                <div style="display:inline-flex; align-items:center; gap:4px;">
                    <strong>${escapeWomHtml(currentName)}</strong>
                    <button type="button" class="wom-btn-mini-edit" onclick="promptChangeSlotName(${idx})" title="${isExtra ? '臨時枠の名称を修正' : 'この日の枠名称を一時的に修正'}">✏️</button>
                </div>
            `;
        }

        // 操作ボタン
        let actionBtnHtml = '';
        if (isSusp) {
            actionBtnHtml = `<button type="button" class="wom-action-btn wom-btn-restore" onclick="toggleDraftSlotSuspend(${idx})">✅ 復帰</button>`;
        } else if (hasReservation) {
            actionBtnHtml = `<button type="button" class="wom-action-btn wom-btn-suspend" disabled title="既に確定予約が入っているため休止できません">🚫 休止不可</button>`;
        } else {
            actionBtnHtml = `<button type="button" class="wom-action-btn wom-btn-suspend" onclick="toggleDraftSlotSuspend(${idx})">🚫 休止</button>`;
        }

        // 臨時枠の場合は削除ボタンも付与（確定予約がある場合は削除禁止ガード）
        if (isExtra) {
            if (hasReservation) {
                actionBtnHtml += ` <button type="button" class="wom-action-btn" disabled style="background:#f1f5f9; color:#94a3b8; margin-left:4px; cursor:not-allowed; opacity:0.6;" title="既に確定患者予約が入っているため削除できません（迷子防止）">🗑️</button>`;
            } else {
                actionBtnHtml += ` <button type="button" class="wom-action-btn" style="background:#fee2e2; color:#dc2626; margin-left:4px;" title="臨時枠を削除" onclick="removeDraftExtraSlot(${idx})">🗑️</button>`;
            }
        }

        tr.innerHTML = `
            <td>
                ${nameDisplayHtml}
                ${isDirty ? '<span style="color:#f59e0b; font-size:10px; margin-left:4px;" title="未保存の変更">●</span>' : ''}
            </td>
            <td><span class="wom-slot-badge ${escapeWomHtml(slot.group_code || '')}">${escapeWomHtml(slot.group_code || '')}</span></td>
            <td>${statusBadge}</td>
            <td>${reasonHtml}</td>
            <td style="text-align:center;">${actionBtnHtml}</td>
        `;

        tbody.appendChild(tr);
    });
}

/**
 * スロットが変更されたかチェック
 */
function checkSlotDirty(slot) {
    if (slot.is_new_extra) return true;
    const init = womInitialSlots.find(s => s.base_waku_id === slot.base_waku_id && s.override_id === slot.override_id);
    if (!init) return true;

    const curName = (slot.display_waku_name || slot.waku_name || '').trim();
    const initName = (init.display_waku_name || init.waku_name || '').trim();

    return (!!init.is_suspended !== !!slot.is_suspended) 
        || (init.reason !== slot.reason)
        || (curName !== initName);
}

/**
 * 枠の表示名称のインライン一時修正（下書き）
 */
function promptChangeSlotName(index) {
    const slot = womDraftSlots[index];
    if (!slot) return;

    const currentName = slot.display_waku_name || slot.waku_name || '';
    const baseName = slot.original_name || (slot.base_waku_id ? slot.waku_name : '') || currentName;

    const promptMsg = slot.base_waku_id 
        ? `時間枠「${baseName}」の当日の表示名称を入力してください:\n（※元の名称に戻す場合は空欄、または「${baseName}」を入力）`
        : `臨時枠の名称を入力してください:`;

    const newName = window.prompt(promptMsg, currentName);
    if (newName === null) return;

    const trimmed = newName.trim();
    if (slot.base_waku_id) {
        if (trimmed === '' || trimmed === baseName) {
            slot.display_waku_name = baseName;
            slot.waku_name = baseName;
        } else {
            slot.display_waku_name = trimmed;
            slot.waku_name = trimmed;
        }
    } else {
        if (trimmed !== '') {
            slot.display_waku_name = trimmed;
            slot.waku_name = trimmed;
        }
    }

    renderWomDaySlotsTable();
    updateSaveButtonState();
}

/**
 * 枠名称を元のマスタ名にリセット
 */
function resetSlotDisplayName(index) {
    const slot = womDraftSlots[index];
    if (!slot) return;
    const baseName = slot.original_name || (slot.base_waku_id ? slot.waku_name : '');
    if (baseName) {
        slot.display_waku_name = baseName;
        slot.waku_name = baseName;
        renderWomDaySlotsTable();
        updateSaveButtonState();
    }
}

/**
 * 枠の休止/復帰をワンクリックでトグル（下書き）
 */
function toggleDraftSlotSuspend(index) {
    const slot = womDraftSlots[index];
    if (!slot) return;

    if (slot.is_suspended) {
        // 復帰（休止解除時は理由もクリア）
        slot.is_suspended = false;
        slot.reason = '';
    } else {
        // 確定予約が入っている枠は休止不可
        if (slot.has_reservation || (slot.reservations && slot.reservations.length > 0)) {
            alert('この時間枠には既に確定予約が入っているため休止できません。');
            return;
        }
        // 休止（パレットのアクティブ理由を即時適用）
        slot.is_suspended = true;
        slot.reason = womActiveReason || '休止';
    }

    renderWomDaySlotsTable();
    updateSaveButtonState();
}

/**
 * 休止理由のインライン変更
 */
function promptChangeSlotReason(index) {
    const slot = womDraftSlots[index];
    if (!slot) return;

    const currentReason = slot.reason || womActiveReason;
    const newReason = window.prompt(`時間枠「${slot.display_waku_name || slot.waku_name}」の休止理由を入力してください:`, currentReason);
    if (newReason === null) return;

    slot.reason = newReason.trim() || '休止';
    renderWomDaySlotsTable();
    updateSaveButtonState();
}

/**
 * 臨時枠を下書きに追加
 */
function addDraftExtraSlot(e) {
    e.preventDefault();
    const nameInput = document.getElementById('womExtraName');
    const groupInput = document.getElementById('womExtraGroup');
    const reasonInput = document.getElementById('womExtraReason');

    const name = nameInput.value.trim();
    if (!name) return;

    womDraftSlots.push({
        base_waku_id: null,
        override_id: null,
        waku_name: name,
        display_waku_name: name,
        group_code: groupInput.value,
        is_suspended: false,
        reason: reasonInput.value.trim() || '臨時増枠',
        is_override: true,
        is_new_extra: true
    });

    nameInput.value = '';
    reasonInput.value = '';

    renderWomDaySlotsTable();
    updateSaveButtonState();
}

/**
 * 臨時枠の削除（下書き）
 */
function removeDraftExtraSlot(index) {
    const slot = womDraftSlots[index];
    if (!slot) return;

    // ★ 確定予約保護ガード（迷子予約防止）
    const hasRes = !!(slot.reservation_info || (slot.reservations && slot.reservations.length > 0));
    if (hasRes) {
        const slotName = slot.display_waku_name || slot.waku_name || '臨時枠';
        alert(`時間枠「${slotName}」には既に患者の確定予約が入っているため削除できません。\n\n枠を削除すると予約が迷子になってしまいます。\n先に予約を別の時間枠へ移動するか、キャンセルを行ってください。`);
        return;
    }

    if (slot.override_id) {
        womDeletedOverrideIds.push(slot.override_id);
    }
    slot.is_deleted = true;

    renderWomDaySlotsTable();
    updateSaveButtonState();
}

/**
 * 差分（下書きの変更）を算出
 */
function getWomDraftChanges() {
    const changedSlots = [];
    const newExtraSlots = [];
    const updatedExtraSlots = [];

    womDraftSlots.forEach(slot => {
        if (slot.is_deleted) return;

        const currentName = (slot.display_waku_name || slot.waku_name || '').trim();

        if (slot.is_new_extra) {
            newExtraSlots.push({
                waku_name: currentName,
                group_code: slot.group_code,
                reason: slot.reason
            });
        } else if (slot.base_waku_id) {
            const init = womInitialSlots.find(s => s.base_waku_id === slot.base_waku_id);
            const initName = (init ? (init.display_waku_name || init.waku_name || '') : '').trim();
            const baseName = (slot.original_name || (init ? init.original_name : '') || slot.waku_name || '').trim();

            if (!init || (!!init.is_suspended !== !!slot.is_suspended) || (init.reason !== slot.reason) || (currentName !== initName)) {
                changedSlots.push({
                    base_waku_id: slot.base_waku_id,
                    display_waku_name: currentName,
                    original_name: baseName,
                    is_suspended: !!slot.is_suspended,
                    reason: slot.reason || (slot.is_suspended ? '休止' : '')
                });
            }
        } else if (slot.override_id && !slot.base_waku_id) {
            // 既存の臨時枠の更新（名前または理由の修正）
            const init = womInitialSlots.find(s => s.override_id === slot.override_id);
            const initName = (init ? (init.display_waku_name || init.waku_name || '') : '').trim();
            if (!init || (currentName !== initName) || (init.reason !== slot.reason)) {
                updatedExtraSlots.push({
                    override_id: slot.override_id,
                    waku_name: currentName,
                    reason: slot.reason
                });
            }
        }
    });

    const totalCount = changedSlots.length + newExtraSlots.length + updatedExtraSlots.length + womDeletedOverrideIds.length;

    return {
        slots: changedSlots,
        new_extra_slots: newExtraSlots,
        updated_extra_slots: updatedExtraSlots,
        deleted_override_ids: womDeletedOverrideIds,
        totalCount: totalCount
    };
}

/**
 * 保存ボタンと未保存表示の更新
 */
function updateSaveButtonState() {
    const changes = getWomDraftChanges();
    const btnSave = document.getElementById('womBtnSave');
    const badge = document.getElementById('womSaveBadge');
    const notice = document.getElementById('womDirtyNotice');

    if (changes.totalCount > 0) {
        btnSave.disabled = false;
        badge.style.display = 'inline-block';
        badge.textContent = `${changes.totalCount}`;
        if (notice) notice.style.display = 'block';
    } else {
        badge.style.display = 'none';
        if (notice) notice.style.display = 'none';
    }
}

/**
 * 下書きの一括保存（コミット）
 */
async function saveWomDraftBatch() {
    const changes = getWomDraftChanges();
    if (changes.totalCount === 0) {
        alert('変更はありません。');
        return;
    }

    const btn = document.getElementById('womBtnSave');
    btn.disabled = true;
    btn.textContent = '⏳ 保存中...';

    try {
        const fd = new FormData();
        fd.append('action', 'save_day_overrides_batch');
        fd.append('dept', currentWomDept);
        fd.append('date', currentWomDate);
        fd.append('changes', JSON.stringify(changes));

        const res = await fetch('action_waku_override.php', { method: 'POST', body: fd });
        const json = await res.json();

        if (!json.success) {
            throw new Error(json.error || '保存に失敗しました');
        }

        womNeedsReloadParent = true;
        closeWakuOverrideModal();

    } catch (e) {
        alert('保存エラー: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<span>💾 変更を保存して反映</span> <span id="womSaveBadge" class="wom-save-badge">!</span>';
    }
}

/**
 * キャンセル（破棄して閉じる）
 */
function cancelWomModal() {
    const changes = getWomDraftChanges();
    if (changes.totalCount > 0) {
        if (!confirm(`未保存の変更が ${changes.totalCount} 件あります。\n破棄して画面を閉じますか？`)) {
            return;
        }
    }
    womNeedsReloadParent = false;
    closeWakuOverrideModal();
}

/**
 * モーダルを閉じる
 */
function closeWakuOverrideModal() {
    const modal = document.getElementById('wakuOverrideModal');
    if (!modal) return;

    if (typeof modal.close === 'function') {
        modal.close();
    } else {
        modal.removeAttribute('open');
    }

    if (womNeedsReloadParent) {
        location.reload();
    }
}

/**
 * 期間登録用: 基本枠リストのロード
 */
async function loadWomBaseSlotsForPeriod() {
    const select = document.getElementById('womPeriodBaseWaku');
    if (!select) return;

    try {
        const res = await fetch(`action_waku_override.php?action=get_day_slots&dept=${currentWomDept}&date=${currentWomDate}`);
        const json = await res.json();
        if (json.success && Array.isArray(json.base_slots)) {
            select.innerHTML = '<option value="">-- 基本枠を選択 --</option>';
            json.base_slots.forEach(slot => {
                select.innerHTML += `<option value="${slot.id}" data-name="${escapeWomHtml(slot.waku_name)}">[${slot.group_code}] ${escapeWomHtml(slot.waku_name)}</option>`;
            });
        }
    } catch (e) {
        console.error('loadWomBaseSlotsForPeriod error:', e);
    }
}

function onPeriodBaseWakuChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.name) {
        document.getElementById('womPeriodDisplayName').value = opt.dataset.name;
    }
}

/**
 * 期間オーバーライド登録の送信
 */
async function submitWomPeriodOverride(e) {
    e.preventDefault();
    const start = document.getElementById('womPeriodStart').value;
    const end = document.getElementById('womPeriodEnd').value;
    const baseWakuId = document.getElementById('womPeriodBaseWaku').value;
    const dispName = document.getElementById('womPeriodDisplayName').value.trim();
    const isSusp = document.getElementById('womPeriodIsSuspended').value;
    const reason = document.getElementById('womPeriodReason').value.trim();

    if (!start || !end || !baseWakuId) {
        alert('開始日、終了日、対象基本枠は必須です。');
        return;
    }

    try {
        const fd = new FormData();
        fd.append('action', 'save_period_override');
        fd.append('dept', currentWomDept);
        fd.append('start_date', start);
        fd.append('end_date', end);
        fd.append('base_waku_id', baseWakuId);
        fd.append('display_waku_name', dispName);
        fd.append('is_suspended', isSusp);
        fd.append('reason', reason);

        const res = await fetch('action_waku_override.php', { method: 'POST', body: fd });
        const json = await res.json();

        if (!json.success) {
            throw new Error(json.error || '期間登録失敗');
        }

        alert('期間オーバーライドを登録しました。');
        womNeedsReloadParent = true;
        loadWomOverridesList();

    } catch (e) {
        alert('エラー: ' + e.message);
    }
}

/**
 * 設定済みオーバーライド一覧のロード
 */
async function loadWomOverridesList() {
    const tbody = document.getElementById('womOverrideTableBody');
    const countBadge = document.getElementById('womOverrideCount');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">読み込み中...</td></tr>';

    try {
        const y = currentWomDate.substring(0, 4);
        const m = currentWomDate.substring(5, 7);
        const res = await fetch(`action_waku_override.php?action=list_overrides&dept=${currentWomDept}&year=${y}&month=${m}`);
        const json = await res.json();

        if (!json.success) {
            throw new Error(json.error || '一覧取得失敗');
        }

        const list = json.list || [];
        if (countBadge) countBadge.textContent = list.length;

        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">当月・期間内のオーバーライドはありません</td></tr>';
            return;
        }

        tbody.innerHTML = '';
        list.forEach(row => {
            const isPeriod = (row.override_type === 'PERIOD');
            const dateStr = isPeriod ? `${row.start_date} 〜 ${row.end_date}` : row.target_date;
            const isSusp = !!row.is_suspended;
            const wName = row.display_waku_name || row.base_waku_name || '-';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><span class="badge ${isPeriod ? 'badge-info' : 'badge-neutral'}">${isPeriod ? '期間' : '特定日'}</span></td>
                <td><strong>${escapeWomHtml(dateStr)}</strong></td>
                <td>${escapeWomHtml(wName)}</td>
                <td><span class="badge ${isSusp ? 'badge-danger' : 'badge-success'}">${isSusp ? '🚫 休止' : '変更'}</span></td>
                <td style="color:#64748b;">${escapeWomHtml(row.reason || '-')}</td>
                <td style="text-align:center;">
                    <button type="button" class="wom-action-btn" style="background:#fee2e2; color:#dc2626; padding:3px 8px; font-size:11px;" onclick="deleteWomOverrideItem(${row.override_id})">🗑️ 解除</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:#dc2626;">エラー: ${escapeWomHtml(e.message)}</td></tr>`;
    }
}

/**
 * 設定一覧からの個別解除
 */
async function deleteWomOverrideItem(overrideId) {
    if (!confirm('このオーバーライド設定を解除して元に戻しますか？')) return;

    try {
        const fd = new FormData();
        fd.append('action', 'delete_override');
        fd.append('override_id', overrideId);

        const res = await fetch('action_waku_override.php', { method: 'POST', body: fd });
        const json = await res.json();

        if (!json.success) {
            throw new Error(json.error || '解除失敗');
        }

        womNeedsReloadParent = true;
        loadWomOverridesList();

    } catch (e) {
        alert('エラー: ' + e.message);
    }
}

/**
 * HTMLエスケープヘルパー
 */
function escapeWomHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
