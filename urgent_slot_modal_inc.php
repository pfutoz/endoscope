<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\urgent_slot_modal_inc.php
 * ファイル名: urgent_slot_modal_inc.php
 * 
 * 直近空き枠速報 ＆ スマート予約アシスト モーダル コンポーネント
 * 医療法人小野会 内視鏡予約管理システム
 * 
 * 特徴:
 * - 本日〜30日以内の大腸内視鏡（CF/TCF/W/ポリペク）空き枠を全科横断・全医師横断で高速集約
 * - 直近7日以内にキャンセル・移動が発生した「⚡キャンセル空き」をリアルタイム検知＆最優先表示
 * - 医師指名（誠吾・哲也・義智・慶太郎）に対応したカラーコードバッジ＆ワンタップ絞り込み
 * - 枠が埋まった瞬間に自動消滅（二重予約トラブル防止）
 * - 科の垣根を超えて該当日のカレンダー・予約モーダルへダイレクト連携
 * - 既存の空き枠詳細検索（slot_search.php）へのスムーズな導線
 * 
 * 修正履歴 (2026-09-29):
 * - 【フローティング化＆操作性向上】直近空き枠速報を画面右下フローティングパネル化。医師通知欄と同等のダーク系半透明グラスモフィズム（backdrop-filter）を採用
 * - 【ドラッグ移動＆24時間位置保持】ヘッダー部のドラッグ移動、画面外はみ出し防止、localStorage（24時間有効期限付き）による位置＆最小化状態の復元
 * - 【スマート最小化】✕ボタンによるコンパクトピル（📢 空き枠速報: ○枠）への最小化とワンクリック再展開
 */
?>
<!-- ── 直近空き枠速報 ＆ スマート予約アシスト モーダル ── -->
<style>
/* ── スタイル定義 ── */
.nav-btn-urgent {
    background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%) !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3) !important;
    border: 1px solid #d97706 !important;
    transition: all 0.2s ease !important;
}
.nav-btn-urgent:hover {
    background: linear-gradient(135deg, #d97706 0%, #c2410c 100%) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(245, 158, 11, 0.4) !important;
}
.nav-btn-urgent.has-urgent-slot {
    animation: urgentPulse 2s infinite ease-in-out;
}
@keyframes urgentPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
    50% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
}

/* ── 直近空き枠速報 フローティングコンテナ ── */
.urgent-slot-floating-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 2400;
    display: none;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    touch-action: none;
}

/* フローティング速報パネル（ダーク系半透明グラスモフィズム） */
.urgent-slot-quick-bar {
    width: 440px;
    max-width: calc(100vw - 32px);
    background: rgba(15, 23, 42, 0.90);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1.5px solid rgba(245, 158, 11, 0.5);
    border-radius: 12px;
    box-shadow: 0 16px 36px -4px rgba(0, 0, 0, 0.6), 0 0 16px rgba(245, 158, 11, 0.25);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: urgent-slide-up 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes urgent-slide-up {
    from { opacity: 0; transform: translateY(16px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.urgent-bar-header {
    background: rgba(30, 41, 59, 0.85);
    border-bottom: 1px solid rgba(245, 158, 11, 0.25);
    padding: 8px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: grab;
    user-select: none;
}
.urgent-bar-header:active {
    cursor: grabbing;
}
.urgent-bar-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
}
.urgent-bar-badge {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #ffffff;
    font-size: 11px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    letter-spacing: 0.02em;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
.urgent-drag-indicator {
    font-size: 10px;
    color: #94a3b8;
    font-weight: 600;
    pointer-events: none;
}
.urgent-bar-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #cbd5e1;
    width: 22px;
    height: 22px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s;
}
.urgent-bar-close-btn:hover {
    background: rgba(239, 68, 68, 0.85);
    color: #ffffff;
    border-color: #ef4444;
}

.urgent-bar-content {
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.urgent-bar-text {
    font-size: 12px;
    color: #f1f5f9;
    font-weight: 500;
    line-height: 1.5;
}
.urgent-bar-highlight {
    background: rgba(245, 158, 11, 0.22);
    color: #fbbf24;
    border: 1px solid rgba(245, 158, 11, 0.45);
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 800;
}
.urgent-bar-actions {
    display: flex;
    justify-content: flex-end;
}
.urgent-bar-btn {
    background: linear-gradient(135deg, #d97706, #b45309);
    color: #ffffff;
    border: 1px solid #f59e0b;
    padding: 5px 14px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.15s ease;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
.urgent-bar-btn:hover {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    box-shadow: 0 4px 10px rgba(245, 158, 11, 0.4);
    transform: translateY(-1px);
}

/* 最小化時トリガーボタン */
.urgent-slot-trigger-btn {
    background: rgba(15, 23, 42, 0.90);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    color: #f8fafc;
    border: 1.5px solid #f59e0b;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5), 0 0 12px rgba(245, 158, 11, 0.35);
    border-radius: 9999px;
    padding: 7px 15px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    animation: urgent-pulse 2.5s infinite ease-in-out;
}
.urgent-slot-trigger-btn:hover {
    transform: translateY(-2px) scale(1.03);
    border-color: #fbbf24;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.6), 0 0 16px rgba(245, 158, 11, 0.55);
}
.urgent-trigger-badge {
    background: #ea580c;
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 900;
    padding: 1px 7px;
    border-radius: 9999px;
}

/* モーダル本体 */
.urgent-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(3px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.urgent-modal-overlay.active {
    display: flex;
}
.urgent-modal-content {
    background: #ffffff;
    width: 100%;
    max-width: 1080px;
    max-height: 92vh;
    border-radius: 14px;
    box-shadow: 0 25px 30px -5px rgba(0, 0, 0, 0.3), 0 10px 15px -5px rgba(0, 0, 0, 0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: urgentModalIn 0.22s ease-out;
}
@keyframes urgentModalIn {
    from { opacity: 0; transform: scale(0.95) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.urgent-modal-header {
    padding: 14px 20px;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid #f59e0b;
}
.urgent-modal-title {
    font-size: 16px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 10px;
}
.urgent-modal-title .urgent-tag {
    background: linear-gradient(135deg, #f59e0b, #ea580c);
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 4px;
}

.urgent-filter-container {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 10px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.urgent-doctor-tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.urgent-doc-btn {
    padding: 4px 12px;
    border-radius: 20px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s ease;
}
.urgent-doc-btn:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}
.urgent-doc-btn.active {
    background: #1e293b;
    color: #ffffff;
    border-color: #1e293b;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.urgent-doc-btn.active.doc-seigo {
    background: #059669;
    border-color: #059669;
}
.urgent-doc-btn.active.doc-tetsuya {
    background: #4f46e5;
    border-color: #4f46e5;
}
.urgent-doc-btn.active.doc-yoshitomo {
    background: #7c3aed;
    border-color: #7c3aed;
}
.urgent-doc-btn.active.doc-keitaro {
    background: #d97706;
    border-color: #d97706;
}
.urgent-doc-badge {
    background: rgba(0, 0, 0, 0.08);
    color: inherit;
    font-size: 10px;
    font-weight: 800;
    padding: 1px 6px;
    border-radius: 10px;
}
.urgent-doc-btn.active .urgent-doc-badge {
    background: rgba(255, 255, 255, 0.3);
    color: #ffffff;
}

.urgent-sub-filters {
    display: flex;
    align-items: center;
    gap: 12px;
}
.urgent-toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    color: #b45309;
    background: #fef3c7;
    padding: 3px 10px;
    border-radius: 6px;
    border: 1px solid #fde68a;
    user-select: none;
}
.urgent-toggle-label input {
    cursor: pointer;
    accent-color: #d97706;
}

.urgent-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    background: #f1f5f9;
}

/* カードグリッド */
.urgent-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 12px;
}
.urgent-card {
    background: #ffffff;
    border-radius: 10px;
    border: 1.5px solid #e2e8f0;
    padding: 12px 14px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}
.urgent-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
    border-color: #94a3b8;
}
.urgent-card.is-cancel {
    border-color: #f59e0b;
    background: linear-gradient(to bottom, #fffdfa 0%, #ffffff 100%);
    box-shadow: 0 2px 6px rgba(245, 158, 11, 0.15);
}
.urgent-card.is-cancel::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #f59e0b, #ea580c);
}

.urgent-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #f1f5f9;
}
.urgent-card-date {
    display: flex;
    align-items: center;
    gap: 6px;
}
.urgent-card-date-main {
    font-size: 14.5px;
    font-weight: 800;
    color: #0f172a;
}
.urgent-card-dow {
    font-size: 12px;
    font-weight: 800;
    padding: 1px 6px;
    border-radius: 4px;
}
.urgent-card-dow.dow-sat { background: #dbeafe; color: #1d4ed8; }
.urgent-card-dow.dow-sun { background: #fee2e2; color: #dc2626; }
.urgent-card-dow.dow-weekday { background: #f1f5f9; color: #475569; }

.urgent-relative-badge {
    font-size: 10.5px;
    font-weight: 800;
    padding: 1px 7px;
    border-radius: 12px;
    background: #e2e8f0;
    color: #334155;
}
.urgent-relative-badge.today { background: #ef4444; color: #ffffff; }
.urgent-relative-badge.tomorrow { background: #f97316; color: #ffffff; }
.urgent-relative-badge.day2 { background: #f59e0b; color: #ffffff; }

.urgent-card-doctor {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}
.urgent-doc-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12.5px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 6px;
    color: #ffffff;
}
.urgent-dept-tag {
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
}

/* メーター */
.urgent-slot-meter-area {
    margin-bottom: 10px;
}
.urgent-meter-labels {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 4px;
    font-size: 11px;
}
.urgent-free-highlight {
    font-size: 15px;
    font-weight: 900;
    color: #059669;
}
.urgent-meter-track {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
    display: flex;
}
.urgent-meter-fill {
    height: 100%;
    transition: width 0.3s ease;
}

/* キャンセルアラート枠 */
.urgent-cancel-notice {
    background: #fffbeb;
    border: 1px dashed #f59e0b;
    border-radius: 6px;
    padding: 5px 8px;
    margin-bottom: 10px;
    font-size: 11px;
    color: #92400e;
    line-height: 1.35;
}
.urgent-cancel-title {
    font-weight: 800;
    color: #b45309;
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 2px;
}

/* アクションボタン */
.urgent-card-actions {
    display: flex;
    gap: 6px;
    align-items: center;
}
.urgent-action-btn {
    flex: 1;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.urgent-btn-cal {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #cbd5e1;
}
.urgent-btn-cal:hover {
    background: #e2e8f0;
    color: #0f172a;
}
.urgent-btn-book {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.3);
}
.urgent-btn-book:hover {
    background: linear-gradient(135deg, #1d4ed8, #1e40af);
}

.urgent-modal-footer {
    padding: 10px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.urgent-footer-note {
    font-size: 11px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 4px;
}
.urgent-footer-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.urgent-search-link {
    background: #f1f5f9;
    color: #0f766e;
    border: 1px solid #ccfbf1;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s;
}
.urgent-search-link:hover {
    background: #ccfbf1;
    color: #115e59;
}
</style>

<!-- ── モーダル HTML ── -->
<div id="urgentSlotModal" class="urgent-modal-overlay" onclick="handleUrgentOverlayClick(event)">
    <div class="urgent-modal-content" onclick="event.stopPropagation()">
        <!-- ヘッダー -->
        <div class="urgent-modal-header">
            <div class="urgent-modal-title">
                <span>⚡ 直近空き枠速報 ＆ スマート予約アシスト</span>
                <span class="urgent-tag">大腸内視鏡 (CF/TCF/W/ポリペク) 直近30日</span>
            </div>
            <button type="button" class="dup-modal-close-btn" style="color:#ffffff;" onclick="closeUrgentSlotModal()" title="閉じる">✕</button>
        </div>

        <!-- フィルターバー -->
        <div class="urgent-filter-container">
            <div class="urgent-doctor-tabs" id="urgentDoctorTabs">
                <button type="button" class="urgent-doc-btn active" data-doctor="all" onclick="filterUrgentByDoctor('all')">
                    👨‍⚕️ 全員 <span class="urgent-doc-badge" id="badgeUrgentAll">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="誠吾" onclick="filterUrgentByDoctor('誠吾')">
                    🟢 誠吾 <span class="urgent-dept-tag">肛門科</span> <span class="urgent-doc-badge" id="badgeUrgentSeigo">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="哲也" onclick="filterUrgentByDoctor('哲也')">
                    🔵 哲也 <span class="urgent-dept-tag">胃腸科</span> <span class="urgent-doc-badge" id="badgeUrgentTetsuya">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="義智" onclick="filterUrgentByDoctor('義智')">
                    🟣 義智 <span class="urgent-dept-tag">胃腸科</span> <span class="urgent-doc-badge" id="badgeUrgentYoshitomo">0</span>
                </button>
                <button type="button" class="urgent-doc-btn" data-doctor="慶太郎" onclick="filterUrgentByDoctor('慶太郎')">
                    🟠 慶太郎 <span class="urgent-dept-tag">胃腸科</span> <span class="urgent-doc-badge" id="badgeUrgentKeitaro">0</span>
                </button>
            </div>

            <div class="urgent-sub-filters">
                <label class="urgent-toggle-label" title="直近7日以内にキャンセル・移動が発生した枠のみを表示">
                    <input type="checkbox" id="urgentOnlyCancelToggle" onchange="handleUrgentCancelToggle()">
                    <span>⚡ キャンセル空きのみ</span>
                    <span class="urgent-doc-badge" id="badgeUrgentCancel" style="background:#f59e0b; color:#fff;">0</span>
                </label>
                <select id="urgentDaysSelect" onchange="handleUrgentDaysChange()" style="padding:4px 8px; border-radius:6px; border:1px solid #cbd5e1; font-size:11.5px; font-weight:bold; background:#fff; color:#334155;">
                    <option value="14">直近 14日間</option>
                    <option value="30" selected>直近 30日間（推奨）</option>
                    <option value="45">直近 45日間</option>
                </select>
            </div>
        </div>

        <!-- カード一覧ボディ -->
        <div class="urgent-modal-body" id="urgentModalBody">
            <div style="text-align:center; padding:40px; color:#64748b;">
                <div style="font-size:24px; margin-bottom:8px;">⏳</div>
                <div>大腸内視鏡の直近空き枠を計算しています...</div>
            </div>
        </div>

        <!-- フッター -->
        <div class="urgent-modal-footer">
            <div class="urgent-footer-note">
                <span>💡</span>
                <span>CF/TCFは基本1か月待ちとなります。枠が埋まり次第、全画面からリアルタイムに自動消滅します。</span>
            </div>
            <div class="urgent-footer-actions">
                <a href="slot_search.php" target="_blank" class="urgent-search-link" title="胃カメラ同日や詳細な日時条件で探す">
                    🔍 詳細条件で探す (空き枠検索) ➔
                </a>
                <button type="button" onclick="loadUrgentSlots(true)" class="cal-toggle-btn" style="background:#ffffff; color:#334155;">
                    🔄 最新情報に更新
                </button>
                <button type="button" onclick="closeUrgentSlotModal()" class="cal-toggle-btn" style="background:#1e293b; color:#ffffff; border-color:#1e293b;">
                    閉じる
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ── 直近空き枠速報 JavaScript ロジック ──
let urgentSlotCache = null;
let currentUrgentDoctor = 'all';

function openUrgentSlotModal() {
    const modal = document.getElementById('urgentSlotModal');
    if (!modal) return;
    modal.classList.add('active');
    loadUrgentSlots(false);
}

function closeUrgentSlotModal() {
    const modal = document.getElementById('urgentSlotModal');
    if (modal) modal.classList.remove('active');
}

function handleUrgentOverlayClick(e) {
    if (e.target.id === 'urgentSlotModal') {
        closeUrgentSlotModal();
    }
}

function filterUrgentByDoctor(doc) {
    currentUrgentDoctor = doc;
    document.querySelectorAll('#urgentDoctorTabs .urgent-doc-btn').forEach(btn => {
        if (btn.getAttribute('data-doctor') === doc) {
            btn.classList.add('active');
            if (doc === '誠吾') btn.classList.add('doc-seigo');
            else if (doc === '哲也') btn.classList.add('doc-tetsuya');
            else if (doc === '義智') btn.classList.add('doc-yoshitomo');
            else if (doc === '慶太郎') btn.classList.add('doc-keitaro');
        } else {
            btn.classList.remove('active', 'doc-seigo', 'doc-tetsuya', 'doc-yoshitomo', 'doc-keitaro');
        }
    });
    renderUrgentSlotCards();
}

function handleUrgentCancelToggle() {
    renderUrgentSlotCards();
}

function handleUrgentDaysChange() {
    loadUrgentSlots(true);
}

async function loadUrgentSlots(forceReload = false) {
    const body = document.getElementById('urgentModalBody');
    const daysSelect = document.getElementById('urgentDaysSelect');
    const days = daysSelect ? daysSelect.value : 30;

    if (!urgentSlotCache || forceReload) {
        if (body) {
            body.innerHTML = `
                <div style="text-align:center; padding:40px; color:#64748b;">
                    <div style="font-size:24px; margin-bottom:8px;">⏳</div>
                    <div>最新の予約データと空き枠を計算しています...</div>
                </div>
            `;
        }

        try {
            const res = await fetch(`action_slot_assistant.php?action=get_urgent_slots&days=${days}&_t=${Date.now()}`);
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || '空き枠取得エラー');
            }
            urgentSlotCache = data;
            updateUrgentBadges(data);
        } catch (err) {
            console.error('loadUrgentSlots error:', err);
            if (body) {
                body.innerHTML = `
                    <div style="text-align:center; padding:30px; color:#dc2626;">
                        <div style="font-size:24px; margin-bottom:6px;">⚠️</div>
                        <div style="font-weight:bold;">空き枠データの取得に失敗しました</div>
                        <div style="font-size:11.5px; color:#64748b; margin-top:4px;">${escapeHtml(err.message)}</div>
                    </div>
                `;
            }
            return;
        }
    }

    renderUrgentSlotCards();
}

function updateUrgentBadges(data) {
    const summary = data.summary || {};
    const totalFree = summary.total_free || 0;
    const cancelFree = summary.total_cancel_free || 0;
    const byDoc = summary.by_doctor || {};

    // ナビゲーションバッジ
    const navBadge = document.getElementById('navUrgentSlotBadge');
    const navBtn = document.getElementById('btnNavUrgentSlot');
    if (navBadge) {
        navBadge.textContent = totalFree;
        navBadge.style.display = totalFree > 0 ? 'inline-block' : 'none';
    }
    if (navBtn) {
        if (cancelFree > 0) {
            navBtn.classList.add('has-urgent-slot');
        } else {
            navBtn.classList.remove('has-urgent-slot');
        }
    }

    // モーダル内バッジ
    const bAll = document.getElementById('badgeUrgentAll');
    if (bAll) bAll.textContent = totalFree;

    const bCancel = document.getElementById('badgeUrgentCancel');
    if (bCancel) bCancel.textContent = cancelFree;

    const bSeigo = document.getElementById('badgeUrgentSeigo');
    if (bSeigo) bSeigo.textContent = byDoc['誠吾'] || 0;

    const bTetsuya = document.getElementById('badgeUrgentTetsuya');
    if (bTetsuya) bTetsuya.textContent = byDoc['哲也'] || 0;

    const bYoshitomo = document.getElementById('badgeUrgentYoshitomo');
    if (bYoshitomo) bYoshitomo.textContent = byDoc['義智'] || 0;

    const bKeitaro = document.getElementById('badgeUrgentKeitaro');
    if (bKeitaro) bKeitaro.textContent = byDoc['慶太郎'] || 0;

    // クイック速報バーの更新
    updateQuickBar(data);
}

const URGENT_STATE_KEY = 'endoscope_urgent_quickbar_state';
const URGENT_STATE_TTL = 24 * 60 * 60 * 1000; // 24時間保持

function saveUrgentQuickBarState() {
    const container = document.getElementById('urgentSlotFloatingContainer');
    const bar = document.getElementById('urgentSlotQuickBar');
    if (!container) return;

    try {
        const isCollapsed = bar ? (bar.style.display === 'none') : false;
        const rect = container.getBoundingClientRect();
        const hasCustomPos = (container.style.left !== '' && container.style.left !== 'auto');
        const data = {
            left: Math.round(rect.left),
            top: Math.round(rect.top),
            collapsed: isCollapsed,
            hasCustomPos: hasCustomPos,
            timestamp: Date.now()
        };
        localStorage.setItem(URGENT_STATE_KEY, JSON.stringify(data));
    } catch(e) {}
}

function restoreUrgentQuickBarState() {
    const container = document.getElementById('urgentSlotFloatingContainer');
    const bar = document.getElementById('urgentSlotQuickBar');
    const trigger = document.getElementById('urgentSlotTriggerBtn');
    if (!container) return;

    try {
        const saved = localStorage.getItem(URGENT_STATE_KEY);
        if (!saved) return;
        const data = JSON.parse(saved);

        // 24時間有効期限判定
        if (!data.timestamp || (Date.now() - data.timestamp) > URGENT_STATE_TTL) {
            localStorage.removeItem(URGENT_STATE_KEY);
            return;
        }

        // 位置の復元
        if (data.hasCustomPos && typeof data.left === 'number' && typeof data.top === 'number') {
            const maxLeft = Math.max(10, window.innerWidth - (container.offsetWidth || 440) - 10);
            const maxTop = Math.max(10, window.innerHeight - (container.offsetHeight || 130) - 10);
            const safeLeft = Math.max(10, Math.min(data.left, maxLeft));
            const safeTop = Math.max(10, Math.min(data.top, maxTop));

            container.style.right = 'auto';
            container.style.bottom = 'auto';
            container.style.left = safeLeft + 'px';
            container.style.top = safeTop + 'px';
        }

        // 開閉状態の復元
        if (data.collapsed) {
            if (bar) bar.style.display = 'none';
            if (trigger) trigger.style.display = 'inline-flex';
        } else {
            if (bar) bar.style.display = 'flex';
            if (trigger) trigger.style.display = 'none';
        }
    } catch(e) {}
}

function toggleUrgentQuickBar(expand) {
    const bar = document.getElementById('urgentSlotQuickBar');
    const trigger = document.getElementById('urgentSlotTriggerBtn');

    if (expand) {
        if (bar) bar.style.display = 'flex';
        if (trigger) trigger.style.display = 'none';
    } else {
        if (bar) bar.style.display = 'none';
        if (trigger) trigger.style.display = 'inline-flex';
    }
    saveUrgentQuickBarState();
}
window.toggleUrgentQuickBar = toggleUrgentQuickBar;

// ドラッグ移動ハンドラ初期化
function initUrgentSlotDraggable() {
    const container = document.getElementById('urgentSlotFloatingContainer');
    const handle = document.getElementById('urgentSlotDragHandle');
    if (!container || !handle || handle.dataset.dragBound === 'true') return;
    handle.dataset.dragBound = 'true';

    let isDragging = false;
    let startX = 0, startY = 0;
    let initialLeft = 0, initialTop = 0;

    function onPointerDown(e) {
        if (e.target.closest('.urgent-bar-close-btn') || e.target.closest('button')) return;

        isDragging = true;
        const rect = container.getBoundingClientRect();
        startX = (e.touches && e.touches[0] ? e.touches[0].clientX : e.clientX) || 0;
        startY = (e.touches && e.touches[0] ? e.touches[0].clientY : e.clientY) || 0;
        initialLeft = rect.left;
        initialTop = rect.top;

        document.addEventListener('mousemove', onPointerMove, { passive: false });
        document.addEventListener('mouseup', onPointerUp);
        document.addEventListener('touchmove', onPointerMove, { passive: false });
        document.addEventListener('touchend', onPointerUp);
    }

    function onPointerMove(e) {
        if (!isDragging) return;
        e.preventDefault();

        const clientX = (e.touches && e.touches[0] ? e.touches[0].clientX : e.clientX) || 0;
        const clientY = (e.touches && e.touches[0] ? e.touches[0].clientY : e.clientY) || 0;
        const deltaX = clientX - startX;
        const deltaY = clientY - startY;

        let newLeft = initialLeft + deltaX;
        let newTop = initialTop + deltaY;

        const maxLeft = Math.max(10, window.innerWidth - container.offsetWidth - 10);
        const maxTop = Math.max(10, window.innerHeight - container.offsetHeight - 10);
        newLeft = Math.max(10, Math.min(newLeft, maxLeft));
        newTop = Math.max(10, Math.min(newTop, maxTop));

        container.style.right = 'auto';
        container.style.bottom = 'auto';
        container.style.left = newLeft + 'px';
        container.style.top = newTop + 'px';
    }

    function onPointerUp() {
        if (!isDragging) return;
        isDragging = false;

        document.removeEventListener('mousemove', onPointerMove);
        document.removeEventListener('mouseup', onPointerUp);
        document.removeEventListener('touchmove', onPointerMove);
        document.removeEventListener('touchend', onPointerUp);

        saveUrgentQuickBarState();
    }

    handle.addEventListener('mousedown', onPointerDown);
    handle.addEventListener('touchstart', onPointerDown, { passive: true });

    // リサイズ時のクランプ
    window.addEventListener('resize', function() {
        if (container.style.left && container.style.left !== 'auto') {
            const rect = container.getBoundingClientRect();
            const maxLeft = Math.max(10, window.innerWidth - container.offsetWidth - 10);
            const maxTop = Math.max(10, window.innerHeight - container.offsetHeight - 10);
            const safeLeft = Math.max(10, Math.min(rect.left, maxLeft));
            const safeTop = Math.max(10, Math.min(rect.top, maxTop));
            container.style.left = safeLeft + 'px';
            container.style.top = safeTop + 'px';
        }
    });
}

function updateQuickBar(data) {
    const container = document.getElementById('urgentSlotFloatingContainer');
    const bar = document.getElementById('urgentSlotQuickBar');
    const trigger = document.getElementById('urgentSlotTriggerBtn');
    const triggerBadge = document.getElementById('urgentSlotTriggerBadge');

    const summary = data.summary || {};
    const totalFree = summary.total_free || 0;
    const cancelFree = summary.total_cancel_free || 0;
    const nearestLabel = summary.nearest_label || '';
    const nearestDoc = summary.nearest_doctor || '';

    if (totalFree <= 0) {
        if (container) container.style.display = 'none';
        if (bar) bar.style.display = 'none';
        if (trigger) trigger.style.display = 'none';
        return;
    }

    if (container) {
        container.style.display = 'flex';
    }
    if (triggerBadge) {
        triggerBadge.textContent = totalFree + '枠';
    }

    const textEl = document.getElementById('urgentQuickBarText');
    if (textEl) {
        let textHtml = `直近30日の大腸(TCF)空き枠: <strong class="urgent-bar-highlight">${totalFree}枠</strong>`;
        if (nearestLabel) {
            textHtml += ` ／ <strong>最短: ${nearestLabel} (${nearestDoc}先生)</strong>`;
        }
        if (cancelFree > 0) {
            textHtml += ` ／ <span style="color:#f59e0b; font-weight:800;">⚡ 直前キャンセル空き: ${cancelFree}件発生中！</span>`;
        }
        textEl.innerHTML = textHtml;
    }

    initUrgentSlotDraggable();
    restoreUrgentQuickBarState();
}

function renderUrgentSlotCards() {
    const body = document.getElementById('urgentModalBody');
    if (!body || !urgentSlotCache) return;

    const onlyCancel = document.getElementById('urgentOnlyCancelToggle')?.checked || false;
    const allSlots = urgentSlotCache.slots || [];

    // フィルタリング
    const filtered = allSlots.filter(s => {
        if (currentUrgentDoctor !== 'all' && s.doctor !== currentUrgentDoctor) {
            return false;
        }
        if (onlyCancel && !s.has_cancel) {
            return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        body.innerHTML = `
            <div style="text-align:center; padding:50px 20px; color:#64748b; background:#fff; border-radius:10px; border:1px dashed #cbd5e1;">
                <div style="font-size:32px; margin-bottom:10px;">📋</div>
                <div style="font-size:14px; font-weight:bold; color:#1e293b;">該当する大腸内視鏡の空き枠はありません</div>
                <div style="font-size:12px; margin-top:6px; color:#64748b;">
                    ${currentUrgentDoctor !== 'all' ? `担当医「${currentUrgentDoctor}」` : ''} 
                    ${onlyCancel ? 'キャンセル空き枠' : ''} の条件では現在すべて予約済です。
                </div>
                <button type="button" onclick="filterUrgentByDoctor('all'); document.getElementById('urgentOnlyCancelToggle').checked=false; renderUrgentSlotCards();" class="cal-toggle-btn" style="margin-top:14px; background:#eff6ff; color:#2563eb; border-color:#bfdbfe;">
                    すべての空き枠を表示する
                </button>
            </div>
        `;
        return;
    }

    let html = '<div class="urgent-cards-grid">';
    filtered.forEach(s => {
        let dowCls = 'dow-weekday';
        if (s.dow === '土') dowCls = 'dow-sat';
        else if (s.dow === '日') dowCls = 'dow-sun';

        let relCls = '';
        if (s.day_diff === 0) relCls = 'today';
        else if (s.day_diff === 1) relCls = 'tomorrow';
        else if (s.day_diff === 2) relCls = 'day2';

        const pct = Math.min(100, Math.round((s.used / s.cap) * 100));

        html += `
        <div class="urgent-card ${s.has_cancel ? 'is-cancel' : ''}">
            <div>
                <div class="urgent-card-header">
                    <div class="urgent-card-date">
                        <span class="urgent-card-date-main">📅 ${s.date}</span>
                        <span class="urgent-card-dow ${dowCls}">${s.dow}</span>
                        <span class="urgent-relative-badge ${relCls}">${s.relative_label}</span>
                    </div>
                    <span class="urgent-dept-tag">${s.dept_name}</span>
                </div>

                <div class="urgent-card-doctor">
                    <span class="urgent-doc-badge-pill" style="background:${s.color};">
                        👨‍⚕️ ${s.doctor} 先生
                    </span>
                    ${s.has_cancel ? '<span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:10px; font-weight:800; padding:1px 6px; border-radius:4px;">⚡ キャンセル空き</span>' : ''}
                </div>

                <!-- 空きメーター -->
                <div class="urgent-slot-meter-area">
                    <div class="urgent-meter-labels">
                        <span>予約進捗 (使用: ${s.used} / 定員: ${s.cap})</span>
                        <span class="urgent-free-highlight">残 ${s.free} 枠</span>
                    </div>
                    <div class="urgent-meter-track">
                        <div class="urgent-meter-fill" style="width:${pct}%; background:${s.color};"></div>
                    </div>
                </div>

                <!-- キャンセル詳細 -->
                ${s.has_cancel && s.cancel_details && s.cancel_details.length > 0 ? `
                    <div class="urgent-cancel-notice">
                        <div class="urgent-cancel-title">
                            <span>⚡</span>
                            <span>${escapeHtml(s.cancel_details[0].reason_label || '直前キャンセル')} (${escapeHtml(s.cancel_details[0].time_ago)})</span>
                        </div>
                        <div>${escapeHtml(s.cancel_details[0].patient_name)} 様 (${escapeHtml(s.cancel_details[0].exam_type)}) の取消枠</div>
                    </div>
                ` : ''}
            </div>

            <!-- アクション -->
            <div class="urgent-card-actions">
                <a href="${s.jump_url}" class="urgent-action-btn urgent-btn-cal" title="該当日のカレンダー・予約一覧へ移動">
                    📅 カレンダー表示
                </a>
                <button type="button" onclick="jumpAndBookSlot('${s.dept}', ${s.year}, ${s.month}, '${s.date}', '${s.doctor}')" class="urgent-action-btn urgent-btn-book" title="この枠で新規予約登録を開始">
                    ➕ この枠で予約
                </button>
            </div>
        </div>
        `;
    });
    html += '</div>';

    body.innerHTML = html;
}

// 該当枠へのジャンプ＆予約登録
function jumpAndBookSlot(dept, year, month, dateStr, doctorName) {
    // 既存ページが同科・同日の場合はモーダルを開く
    const currentDept = '<?= htmlspecialchars($dept ?? "PROCT", ENT_QUOTES, "UTF-8") ?>';
    const currentDate = '<?= htmlspecialchars($selected_date ?? "", ENT_QUOTES, "UTF-8") ?>';

    if (currentDept === dept && currentDate === dateStr && typeof openModal === 'function') {
        closeUrgentSlotModal();
        // 最初の空き枠で開く
        openModal(dateStr, '', 0, dept);
    } else {
        // 別日または別科の場合はリダイレクト（open_new=1付き）
        window.location.href = `month_schedule.php?dept=${encodeURIComponent(dept)}&year=${year}&month=${month}&date=${encodeURIComponent(dateStr)}&open_new=1&target_doctor=${encodeURIComponent(doctorName)}`;
    }
}

// 初回ロード時に自動取得（バックグラウンド集計）
document.addEventListener('DOMContentLoaded', function() {
    // わずかに遅延させて初期レンダリングをブロックしないように実行
    setTimeout(() => {
        fetch(`action_slot_assistant.php?action=get_urgent_slots&days=30&_t=${Date.now()}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    urgentSlotCache = data;
                    updateUrgentBadges(data);
                }
            })
            .catch(err => console.error('Initial urgent slots fetch failed:', err));
    }, 300);

    // URL パラメータ open_new=1 の自動予約モーダル起動
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('open_new') === '1' && typeof openModal === 'function') {
        const targetDate = urlParams.get('date');
        const targetDept = urlParams.get('dept');
        if (targetDate && targetDept) {
            setTimeout(() => {
                openModal(targetDate, '', 0, targetDept);
            }, 400);
        }
    }
});
</script>
