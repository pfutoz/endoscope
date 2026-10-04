<?php
/**
 * audit_modal_inc.php
 * 予約操作履歴（Audit Log）＆ ワンクリック復元（Undo）共通UIモーダル
 */
require_once __DIR__ . '/lib/audit_helper.php';
$audit_curr_client = getAuditClientInfo();
$my_client_ip = $audit_curr_client['ip'];
$my_client_operator = $audit_curr_client['operator'];
?>
<!-- ── 予約操作履歴 & ワンクリック復元モーダル ── -->
<div id="auditLogModal" class="audit-modal-overlay" onclick="handleAuditOverlayClick(event)">
    <div class="audit-modal-content" onclick="event.stopPropagation()">
        <!-- ヘッダー -->
        <div class="audit-modal-header">
            <div class="audit-modal-title-wrap">
                <span class="audit-icon-main">🕒</span>
                <div>
                    <h3 class="audit-title-text">操作履歴（Audit Log）＆ ワンクリック復元（Undo）</h3>
                    <p class="audit-subtitle-text">予約の移動・誤削除・変更履歴を自動記録。誤操作時は「復元」ボタンで直前の状態に完全巻き戻しできます。</p>
                </div>
            </div>
            <button type="button" class="audit-close-btn" onclick="closeAuditLogModal()" title="閉じる (Esc)">✕</button>
        </div>

        <!-- ツールバー（フィルタ・検索・更新） -->
        <div class="audit-toolbar">
            <div class="audit-toolbar-left-group">
                <div class="audit-filter-tabs">
                    <button type="button" class="audit-tab-btn active" data-filter="all" onclick="setAuditFilter('all')">全操作 <span id="auditBadgeAll" class="audit-count-pill">-</span></button>
                    <button type="button" class="audit-tab-btn tab-move" data-filter="move" onclick="setAuditFilter('move')">🚚 枠移動 <span id="auditBadgeMove" class="audit-count-pill">-</span></button>
                    <button type="button" class="audit-tab-btn tab-delete" data-filter="delete" onclick="setAuditFilter('delete')">🗑️ 削除 <span id="auditBadgeDelete" class="audit-count-pill">-</span></button>
                    <button type="button" class="audit-tab-btn tab-update" data-filter="update" onclick="setAuditFilter('update')">✏️ 変更 <span id="auditBadgeUpdate" class="audit-count-pill">-</span></button>
                    <button type="button" class="audit-tab-btn tab-insert" data-filter="insert" onclick="setAuditFilter('insert')">➕ 新規 <span id="auditBadgeInsert" class="audit-count-pill">-</span></button>
                </div>
                
                <!-- ★ この端末のみ トグルボタン -->
                <button type="button" id="auditMyTerminalBtn" class="audit-terminal-toggle-btn" onclick="toggleMyTerminalFilter()" title="この端末（<?= htmlspecialchars($my_client_ip, ENT_QUOTES, 'UTF-8') ?>）の操作履歴のみ表示">
                    <span class="terminal-indicator-dot"></span>
                    <span>💻 この端末のみ</span>
                    <span id="auditBadgeMyTerminal" class="audit-count-pill">-</span>
                </button>
            </div>
            
            <div class="audit-toolbar-right">
                <input type="text" id="auditSearchInput" class="audit-search-input" placeholder="患者名・ID・端末IPで絞込..." oninput="handleAuditSearch(this.value)">
                <button type="button" class="audit-refresh-btn" onclick="loadAuditLogs(true)" title="最新履歴を再取得">🔄 更新</button>
            </div>
        </div>

        <!-- 履歴リストコンテナ -->
        <div class="audit-body" id="auditModalBody">
            <div class="audit-loading-spinner">
                <div class="audit-spinner"></div>
                <div style="margin-top:10px; color:#64748b; font-weight:600;">操作履歴を取得中...</div>
            </div>
        </div>

        <!-- フッター -->
        <div class="audit-modal-footer">
            <div class="audit-footer-hint">
                <span>💡</span> <strong>ヒント:</strong> カレンダーで別日へ誤移動したり、誤って削除した予約もここから即座に直前の状態へ完全復元できます。
            </div>
            <button type="button" class="audit-footer-close-btn" onclick="closeAuditLogModal()">閉じる</button>
        </div>
    </div>
</div>

<!-- 復元完了・エラー通知トースト -->
<div id="auditToast" class="audit-toast"></div>

<style>
/* ── 操作履歴モーダル スタイル ── */
.audit-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    animation: auditFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.audit-modal-overlay.active {
    display: flex;
}
@keyframes auditFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.audit-modal-content {
    background: #ffffff;
    width: 95%;
    max-width: 1060px;
    height: 88vh;
    max-height: 820px;
    border-radius: 12px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0, 0, 0, 0.08);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: auditScaleUp 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes auditScaleUp {
    from { opacity: 0; transform: scale(0.97) translateY(8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.audit-modal-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #ffffff;
    padding: 14px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.audit-modal-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}
.audit-icon-main {
    font-size: 24px;
    background: rgba(255, 255, 255, 0.12);
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}
.audit-title-text {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: -0.01em;
}
.audit-subtitle-text {
    margin: 2px 0 0 0;
    font-size: 11.5px;
    color: #94a3b8;
    line-height: 1.35;
}
.audit-close-btn {
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #e2e8f0;
    font-size: 16px;
    font-weight: bold;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}
.audit-close-btn:hover {
    background: rgba(239, 68, 68, 0.85);
    color: #fff;
}

/* ツールバー */
.audit-toolbar {
    background: #f8fafc;
    padding: 10px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}
.audit-toolbar-left-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.audit-filter-tabs {
    display: inline-flex;
    background: #e2e8f0;
    padding: 3px;
    border-radius: 8px;
    gap: 2px;
}
.audit-terminal-toggle-btn {
    background: #ffffff;
    border: 1.5px solid #cbd5e1;
    color: #475569;
    padding: 5px 12px;
    font-size: 11.5px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.audit-terminal-toggle-btn:hover {
    background: #f8fafc;
    border-color: #94a3b8;
    color: #0f172a;
}
.audit-terminal-toggle-btn.active {
    background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
    border-color: #0284c7;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35);
}
.audit-terminal-toggle-btn .terminal-indicator-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #94a3b8;
    display: inline-block;
    transition: all 0.2s;
}
.audit-terminal-toggle-btn.active .terminal-indicator-dot {
    background: #4ade80;
    box-shadow: 0 0 8px #4ade80;
}
.audit-terminal-toggle-btn.active .audit-count-pill {
    background: rgba(255, 255, 255, 0.25);
    color: #ffffff;
}
.terminal-tag-this {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #bae6fd;
    font-size: 9.5px;
    font-weight: 800;
    padding: 1px 5px;
    border-radius: 4px;
}
.audit-tab-btn {
    background: transparent;
    border: none;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.15s;
}
.audit-tab-btn:hover {
    color: #1e293b;
}
.audit-tab-btn.active {
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}
.audit-count-pill {
    background: rgba(0, 0, 0, 0.07);
    padding: 1px 6px;
    border-radius: 9999px;
    font-size: 10px;
    font-weight: 700;
}
.audit-tab-btn.active .audit-count-pill {
    background: #e2e8f0;
    color: #0f172a;
}
.audit-toolbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
}
.audit-search-input {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    width: 220px;
    outline: none;
    transition: all 0.2s;
}
.audit-search-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}
.audit-refresh-btn {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
    transition: all 0.15s;
}
.audit-refresh-btn:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}

/* ボディ（リスト） */
.audit-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    background: #f8fafc;
}
.audit-loading-spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 240px;
}
.audit-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid #cbd5e1;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: auditSpin 0.7s linear infinite;
}
@keyframes auditSpin {
    to { transform: rotate(360deg); }
}

.audit-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #64748b;
}
.audit-empty-icon {
    font-size: 40px;
    margin-bottom: 8px;
    opacity: 0.6;
}

/* 各履歴カード */
.audit-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 10px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    display: grid;
    grid-template-columns: 120px 100px 180px 1fr 140px;
    align-items: center;
    gap: 14px;
    transition: all 0.15s;
}
.audit-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 10px -2px rgba(0, 0, 0, 0.06);
}
.audit-card.is-undone {
    background: #fafaf9;
    opacity: 0.75;
    border-style: dashed;
}

.audit-time-col {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.audit-time-rel {
    font-size: 12px;
    font-weight: 700;
    color: #0f172a;
}
.audit-time-exact {
    font-size: 10.5px;
    color: #64748b;
}

/* 操作バッジ */
.audit-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.badge-move { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-delete { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.badge-update { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-insert { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-restore { background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }
.badge-undo { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
.badge-other { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

.audit-patient-col {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.audit-patient-header {
    display: flex;
    align-items: center;
    gap: 6px;
}
.audit-patient-id {
    font-size: 11px;
    font-family: monospace;
    font-weight: 700;
    color: #2563eb;
    text-decoration: none;
    background: #eff6ff;
    padding: 1px 4px;
    border-radius: 4px;
}
.audit-patient-id:hover {
    text-decoration: underline;
}
.audit-dept-pill {
    font-size: 9.5px;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 4px;
}
.audit-dept-PROCT { background: #dcfce7; color: #166534; }
.audit-dept-GASTRO { background: #e0f2fe; color: #075985; }

.audit-patient-name {
    font-size: 12.5px;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* 変更内容 */
.audit-diff-col {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.audit-diff-main {
    font-size: 12px;
    color: #1e293b;
    font-weight: 600;
    line-height: 1.4;
    word-break: break-all;
}
.audit-diff-meta {
    font-size: 10.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.audit-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

/* アクション・復元ボタン */
.audit-action-col {
    text-align: right;
    display: flex;
    justify-content: flex-end;
}
.audit-undo-btn {
    background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
    color: #ffffff;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    box-shadow: 0 2px 4px rgba(124, 58, 237, 0.25);
    transition: all 0.15s;
    white-space: nowrap;
}
.audit-undo-btn:hover {
    background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);
    box-shadow: 0 4px 8px rgba(124, 58, 237, 0.35);
    transform: translateY(-1px);
}
.audit-undo-btn:active {
    transform: translateY(0);
}
.audit-undone-label {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    background: #f1f5f9;
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

/* フッター */
.audit-modal-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.audit-footer-hint {
    font-size: 11.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 6px;
}
.audit-footer-close-btn {
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #334155;
    padding: 6px 16px;
    font-size: 12.5px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
}
.audit-footer-close-btn:hover {
    background: #e2e8f0;
    color: #0f172a;
}

/* トースト通知 */
.audit-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #0f172a;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
    z-index: 100000;
    display: none;
    align-items: center;
    gap: 8px;
    animation: auditToastSlide 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.audit-toast.show {
    display: flex;
}
.audit-toast.success {
    background: #047857;
    border: 1px solid #10b981;
}
.audit-toast.error {
    background: #b91c1c;
    border: 1px solid #ef4444;
}
@keyframes auditToastSlide {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 860px) {
    .audit-card {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .audit-action-col {
        justify-content: flex-start;
    }
}
</style>

<script>
/**
 * 予約操作履歴 & ワンクリック復元 JavaScript ロジック
 */
const MY_CLIENT_IP = <?= json_encode($my_client_ip, JSON_UNESCAPED_UNICODE) ?>;
const MY_CLIENT_OPERATOR = <?= json_encode($my_client_operator, JSON_UNESCAPED_UNICODE) ?>;

let auditLogsCache = [];
let currentAuditFilter = 'all';
let currentAuditSearch = '';
let isMyTerminalOnly = false;

function toggleMyTerminalFilter() {
    isMyTerminalOnly = !isMyTerminalOnly;
    const btn = document.getElementById('auditMyTerminalBtn');
    if (btn) {
        btn.classList.toggle('active', isMyTerminalOnly);
    }
    renderAuditLogsList();
}

function isItemFromMyTerminal(item) {
    if (!item) return false;
    const itemIp = (item.client_ip || '').trim().toLowerCase();
    const myIp = (MY_CLIENT_IP || '').trim().toLowerCase();
    const itemOp = (item.operator_name || '').trim().toLowerCase();
    const myOp = (MY_CLIENT_OPERATOR || '').trim().toLowerCase();

    if (itemIp && myIp && itemIp === myIp) return true;
    if (itemOp && myOp && itemOp === myOp) return true;
    if (itemIp && myOp && itemIp === myOp) return true;
    if (itemOp && myIp && itemOp === myIp) return true;

    // ローカルホスト (127.0.0.1, ::1, localhost) 相互判定
    const isLocalMy = myIp.includes('127.0.0.1') || myIp.includes('::1') || myIp.includes('ローカル');
    const isLocalItem = itemIp.includes('127.0.0.1') || itemIp.includes('::1') || itemIp.includes('ローカル');
    if (isLocalMy && isLocalItem) return true;

    return false;
}

function openAuditLogModal(dept, scheduleId) {
    const modal = document.getElementById('auditLogModal');
    if (!modal) return;
    modal.classList.add('active');
    loadAuditLogs();
}

function closeAuditLogModal() {
    const modal = document.getElementById('auditLogModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function handleAuditOverlayClick(e) {
    if (e.target === document.getElementById('auditLogModal')) {
        closeAuditLogModal();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('auditLogModal');
        if (modal && modal.classList.contains('active')) {
            closeAuditLogModal();
        }
    }
});

function setAuditFilter(filterType) {
    currentAuditFilter = filterType;
    document.querySelectorAll('.audit-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === filterType);
    });
    renderAuditLogsList();
}

function handleAuditSearch(keyword) {
    currentAuditSearch = (keyword || '').trim().toLowerCase();
    renderAuditLogsList();
}

function loadAuditLogs(isRefresh) {
    const body = document.getElementById('auditModalBody');
    if (!body) return;
    
    if (isRefresh || auditLogsCache.length === 0) {
        body.innerHTML = `
            <div class="audit-loading-spinner">
                <div class="audit-spinner"></div>
                <div style="margin-top:10px; color:#64748b; font-weight:600;">操作履歴を取得中...</div>
            </div>
        `;
    }

    fetch('action_audit_log.php?action=list&limit=60')
        .then(res => res.json())
        .then(data => {
            if (data && data.success && Array.isArray(data.logs)) {
                auditLogsCache = data.logs;
                updateAuditCounts();
                renderAuditLogsList();
            } else {
                body.innerHTML = `<div class="audit-empty-state"><div class="audit-empty-icon">⚠️</div><div>履歴の取得に失敗しました: ${escapeAuditHtml(data.message || '不明なエラー')}</div></div>`;
            }
        })
        .catch(err => {
            body.innerHTML = `<div class="audit-empty-state"><div class="audit-empty-icon">⚠️</div><div>通信エラーが発生しました: ${escapeAuditHtml(err.toString())}</div></div>`;
        });
}

function updateAuditCounts() {
    const all = auditLogsCache.length;
    const move = auditLogsCache.filter(l => l.action_type === 'move').length;
    const del  = auditLogsCache.filter(l => l.action_type === 'delete').length;
    const upd  = auditLogsCache.filter(l => l.action_type === 'update').length;
    const ins  = auditLogsCache.filter(l => l.action_type === 'insert').length;
    const myCount = auditLogsCache.filter(l => isItemFromMyTerminal(l)).length;

    const elAll = document.getElementById('auditBadgeAll');
    const elMove = document.getElementById('auditBadgeMove');
    const elDel = document.getElementById('auditBadgeDelete');
    const elUpd = document.getElementById('auditBadgeUpdate');
    const elIns = document.getElementById('auditBadgeInsert');
    const elMy  = document.getElementById('auditBadgeMyTerminal');

    if (elAll) elAll.textContent = all;
    if (elMove) elMove.textContent = move;
    if (elDel) elDel.textContent = del;
    if (elUpd) elUpd.textContent = upd;
    if (elIns) elIns.textContent = ins;
    if (elMy)  elMy.textContent  = myCount;
}

function renderAuditLogsList() {
    const body = document.getElementById('auditModalBody');
    if (!body) return;

    let items = auditLogsCache.filter(item => {
        // ★ この端末のみフィルタ
        if (isMyTerminalOnly && !isItemFromMyTerminal(item)) {
            return false;
        }

        // 操作種別フィルタ
        if (currentAuditFilter !== 'all' && item.action_type !== currentAuditFilter) {
            return false;
        }

        // 検索キーワード
        if (currentAuditSearch !== '') {
            const pid   = (item.patient_id || '').toLowerCase();
            const pname = (item.patient_name || '').toLowerCase();
            const ip    = (item.client_ip || '').toLowerCase();
            const op    = (item.operator_name || '').toLowerCase();
            const desc  = (item.description || '').toLowerCase();
            if (!pid.includes(currentAuditSearch) && !pname.includes(currentAuditSearch) && !ip.includes(currentAuditSearch) && !op.includes(currentAuditSearch) && !desc.includes(currentAuditSearch)) {
                return false;
            }
        }
        return true;
    });

    if (items.length === 0) {
        body.innerHTML = `
            <div class="audit-empty-state">
                <div class="audit-empty-icon">📂</div>
                <div style="font-size:14px; font-weight:700; color:#334155; margin-bottom:4px;">該当する操作履歴はありません</div>
                <div style="font-size:12px; color:#94a3b8;">フィルタ条件を変更するか、最新の操作をお待ちください</div>
            </div>
        `;
        return;
    }

    let html = '';
    items.forEach(log => {
        const isUndone = log.is_undone;
        const cardCls = isUndone ? 'audit-card is-undone' : 'audit-card';
        const karteUrl = log.patient_id ? `http://192.168.1.16/karteview/karteview.php?source=new&do_search=1&c_filter=all&show_all_sick=0&show_images=1&show_orders=1&show_dots=1&patient_no=${encodeURIComponent(log.patient_id)}` : '#';

        let actionBtnHtml = '';
        if (isUndone) {
            const undoneTime = log.undone_at ? formatShortTime(log.undone_at) : '';
            actionBtnHtml = `<span class="audit-undone-label" title="復元日時: ${escapeAuditHtml(log.undone_at || '')}">✅ 復元済 ${undoneTime}</span>`;
        } else if (log.can_undo) {
            actionBtnHtml = `
                <button type="button" class="audit-undo-btn" onclick="executeAuditUndo(${log.log_id}, '${escapeAuditHtml(log.patient_name || '')}', '${log.action_type}', '${escapeAuditHtml(log.diff_summary || '')}')" title="この操作を取り消して直前の状態に復元します">
                    <span>↩️</span> 復元する
                </button>
            `;
        } else {
            actionBtnHtml = `<span style="font-size:11px; color:#94a3b8;">-</span>`;
        }

        const deptCls = log.department_code === 'PROCT' ? 'audit-dept-PROCT' : 'audit-dept-GASTRO';

        html += `
            <div class="${cardCls}">
                <!-- 1. 時刻 -->
                <div class="audit-time-col">
                    <span class="audit-time-rel">${escapeAuditHtml(log.time_relative)}</span>
                    <span class="audit-time-exact">${escapeAuditHtml(log.created_at)}</span>
                </div>

                <!-- 2. 操作バッジ -->
                <div>
                    <span class="audit-badge ${escapeAuditHtml(log.action_badge)}">
                        <span>${log.icon}</span> <span>${escapeAuditHtml(log.action_label)}</span>
                    </span>
                </div>

                <!-- 3. 患者情報 -->
                <div class="audit-patient-col">
                    <div class="audit-patient-header">
                        ${log.patient_id ? `<a href="${karteUrl}" target="_blank" rel="noopener noreferrer" class="audit-patient-id" title="カルテビューアーを開く">${escapeAuditHtml(log.patient_id)}</a>` : '<span style="font-size:10px; color:#94a3b8;">(IDなし)</span>'}
                        <span class="audit-dept-pill ${deptCls}">${escapeAuditHtml(log.department_name)}</span>
                    </div>
                    <div class="audit-patient-name" title="${escapeAuditHtml(log.patient_name || '')}">
                        ${escapeAuditHtml(log.patient_name || '患者未設定')}
                    </div>
                </div>

                <!-- 4. 変更サマリ & 端末情報 -->
                <div class="audit-diff-col">
                    <div class="audit-diff-main">${escapeAuditHtml(log.description || log.diff_summary || '')}</div>
                    <div class="audit-diff-meta">
                        <span class="audit-meta-item" title="端末/操作者">💻 ${escapeAuditHtml(log.operator_name || log.client_ip || '端末')} ${isItemFromMyTerminal(log) ? '<span class="terminal-tag-this">自端末</span>' : ''}</span>
                        <span class="audit-meta-item" title="予約ID">🆔 スケジュール#${log.schedule_id}</span>
                        ${log.old_exam_date && log.new_exam_date ? `<span class="audit-meta-item" style="color:#2563eb; font-weight:600;">📅 ${escapeAuditHtml(log.old_exam_date)} ➔ ${escapeAuditHtml(log.new_exam_date)}</span>` : ''}
                    </div>
                </div>

                <!-- 5. 復元ボタン -->
                <div class="audit-action-col">
                    ${actionBtnHtml}
                </div>
            </div>
        `;
    });

    body.innerHTML = html;
}

function formatShortTime(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr);
        return ('0' + (d.getMonth() + 1)).slice(-2) + '/' + ('0' + d.getDate()).slice(-2) + ' ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
    } catch(e) {
        return dateStr.substring(5, 16);
    }
}

function escapeAuditHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showAuditToast(msg, type = 'success') {
    const toast = document.getElementById('auditToast');
    if (!toast) return;
    toast.textContent = msg;
    toast.className = `audit-toast ${type} show`;
    setTimeout(() => {
        toast.className = 'audit-toast';
    }, 4500);
}

function executeAuditUndo(logId, patientName, actionType, summary) {
    let actionDesc = '直前の状態';
    if (actionType === 'move') actionDesc = '移動前の日時・時間枠';
    else if (actionType === 'delete') actionDesc = '削除前の予約データ（削除取り消し）';
    else if (actionType === 'update') actionDesc = '変更前の内容';
    else if (actionType === 'insert') actionDesc = '新規登録前の状態（登録取り消し）';

    const pName = patientName || '患者様';
    const msg = `【ワンクリック復元】\n\n対象: ${pName}\n操作: ${summary}\n\nこの操作を取り消して、${actionDesc} へ完全復元しますか？`;

    if (!confirm(msg)) return;

    const fd = new FormData();
    fd.append('action', 'undo');
    fd.append('log_id', logId);

    fetch('action_audit_log.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data && data.success) {
            showAuditToast(`✅ ${data.message || '正常に復元しました'}`, 'success');
            // 最新履歴を再読み込みして復元済みに
            loadAuditLogs();

            // 親画面のカレンダー・マトリクスをリフレッシュ
            setTimeout(() => {
                window.location.reload();
            }, 1200);
        } else {
            showAuditToast(`⚠️ 復元失敗: ${data.message || 'エラーが発生しました'}`, 'error');
            loadAuditLogs();
        }
    })
    .catch(err => {
        showAuditToast(`通信エラー: ${err.toString()}`, 'error');
    });
}
</script>
