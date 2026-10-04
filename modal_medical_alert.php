<?php
/**
 * 生成日時: 2026-10-01 21:12:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\modal_medical_alert.php
 * ファイル名: modal_medical_alert.php
 * 
 * 概要:
 *   医療安全アラート スキャン判定＆決定モーダル (独立単体ファイル版)
 *   modal_edit.php と同一アーキテクチャの Native <dialog> + <iframe> および別ウィンドウ連携。
 * 
 * 履歴:
 *   - 2026-10-01: 新規作成 (modal_medical_alert.php)
 *       1. medical_alert_modal_inc.php 内のインラインモーダル画面・ロジックを完全分離・独立ページ化
 *       2. URLパラメータ (date, dept, year, month, schedule_ids) を受け取り自動初期スキャン実行
 *       3. モーダル・別ウィンドウ統合クローズ処理 (closeMedicalAlertWindow) 実装
 *       4. 反映・保存完了時に親画面へ postMessage ('medical_alert_saved') を送信しダイアログ終了＆親画面自動リロード連携
 *       5. キャンセル・閉じる(✕)・ESCキー押下時に postMessage ('medical_alert_close') を送信
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// パラメータ取得
$date = $_GET['date'] ?? '';
$dept = $_GET['dept'] ?? 'GI';
$year = $_GET['year'] ?? '';
$month = $_GET['month'] ?? '';
$schedule_ids = $_GET['schedule_ids'] ?? '';
$patient_id = $_GET['patient_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医療安全アラート スキャン判定・決定</title>
    <style>
        /* ── リセット＆ベース ── */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            user-select: none;
        }

        /* ── ヘッダー ── */
        .med-header {
            background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
            color: #ffffff;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .med-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .med-icon-main {
            font-size: 24px;
            background: rgba(255, 255, 255, 0.15);
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            flex-shrink: 0;
        }
        .med-title-text {
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .med-subtitle-text {
            color: #ccfbf1;
            margin-top: 2px;
            font-size: 11.5px;
            line-height: 1.35;
        }
        .med-close-btn {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: #ffffff;
            font-size: 16px;
            font-weight: bold;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            flex-shrink: 0;
        }
        .med-close-btn:hover {
            background: rgba(239, 68, 68, 0.9);
            transform: scale(1.05);
        }

        /* ── ツールバー ── */
        .med-toolbar {
            background: #ffffff;
            padding: 10px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-shrink: 0;
        }
        .med-toolbar-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .med-count-pill {
            background: #ccfbf1;
            color: #0f766e;
            border: 1px solid #99f6e4;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 10px;
            border-radius: 9999px;
        }
        .med-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .med-btn-quick {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .med-btn-quick:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        /* ── スキャン結果ボディ ── */
        .med-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
            background: #f8fafc;
            user-select: text;
        }
        .med-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 260px;
        }
        .med-spinner {
            width: 36px;
            height: 36px;
            border: 3.5px solid #cbd5e1;
            border-top-color: #0d9488;
            border-radius: 50%;
            animation: medSpin 0.7s linear infinite;
        }
        @keyframes medSpin {
            to { transform: rotate(360deg); }
        }

        /* ── 患者カード ── */
        .med-patient-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            transition: all 0.2s;
        }
        .med-patient-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }
        .med-patient-card.has-tag {
            border-left: 5px solid #f59e0b;
        }

        .med-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 10px;
        }
        .med-patient-identity {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .med-pid-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 12px;
            font-weight: 800;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 2px 7px;
            border-radius: 4px;
        }
        .med-pid-badge.med-pid-link {
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #bfdbfe;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s ease-in-out;
        }
        .med-pid-badge.med-pid-link:hover {
            background: #dbeafe;
            color: #1e40af;
            border-color: #93c5fd;
            text-decoration: underline;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.2);
            transform: translateY(-1px);
        }
        .med-pname {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
        }
        .med-sched-meta {
            font-size: 11.5px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* 根拠・判断材料エリア */
        .med-reasons-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .med-reason-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 11.5px;
            line-height: 1.4;
        }
        .med-source-tag {
            display: inline-flex;
            align-items: center;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 800;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .src-tag-gold { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .src-tag-remarks { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .src-tag-memo { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .src-tag-onshi { background: #ecfeff; color: #0891b2; border: 1px solid #a5f3fc; }

        .med-snippet-text {
            color: #334155;
            word-break: break-all;
        }
        .med-matched-keyword {
            background: #fef08a;
            color: #854d0e;
            font-weight: 800;
            padding: 0 4px;
            border-radius: 2px;
        }

        /* グループ別チェックボックスエリア */
        .med-groups-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .med-group-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .med-group-header {
            font-size: 11.5px;
            font-weight: 800;
            color: #475569;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.02em;
        }
        .med-decisions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px;
            user-select: none;
        }
        .med-check-label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            transition: all 0.15s;
        }
        .med-check-label:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .med-check-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .med-check-label.is-checked-anti { background: #eff6ff; border-color: #3b82f6; color: #1d4ed8; }
        .med-check-label.is-checked-diabetes { background: #eff6ff; border-color: #2563eb; color: #1d4ed8; }
        .med-check-label.is-checked-psychotropic { background: #faf5ff; border-color: #a855f7; color: #7e22ce; }
        .med-check-label.is-checked-nsaids { background: #fff7ed; border-color: #f97316; color: #c2410c; }
        .med-check-label.is-checked-allergy { background: #fffbeb; border-color: #f59e0b; color: #b45309; }
        .med-check-label.is-checked-infection { background: #fef2f2; border-color: #ef4444; color: #b91c1c; }
        .med-check-label.is-checked-cold { background: #f0fdf4; border-color: #10b981; color: #15803d; }

        .med-onshi-link {
            font-size: 11px;
            font-weight: 700;
            color: #0284c7;
            text-decoration: none;
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
        }
        .med-onshi-link:hover {
            background: #bae6fd;
            color: #0369a1;
            text-decoration: underline;
        }
        .med-badge-onshi-detect {
            font-size: 10px;
            font-weight: 800;
            background: #ecfeff;
            color: #0891b2;
            padding: 1px 6px;
            border-radius: 4px;
            border: 1px solid #a5f3fc;
        }
        .med-patient-card.has-onshi {
            border-left: 5px solid #06b6d4;
        }
        .med-patient-card.has-tag.has-onshi {
            border-left: 5px solid #f59e0b;
        }

        /* ── フッター ── */
        .med-footer {
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            flex-shrink: 0;
            box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.03);
        }
        .med-footer-hint {
            font-size: 11.5px;
            color: #64748b;
        }
        .med-footer-hint code {
            background: #f1f5f9;
            padding: 1px 4px;
            border-radius: 3px;
            color: #0f766e;
            font-weight: bold;
        }
        .med-footer-close-btn {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .med-footer-close-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .med-footer-save-btn {
            background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
            border: none;
            color: #ffffff;
            padding: 8px 22px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(13, 148, 136, 0.25);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .med-footer-save-btn:hover {
            background: linear-gradient(135deg, #0f766e 0%, #115e59 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(13, 148, 136, 0.35);
        }
        .med-footer-save-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
    </style>
</head>
<body>

    <!-- ヘッダー -->
    <div class="med-header">
        <div class="med-title-wrap">
            <span class="med-icon-main">🛡️</span>
            <div>
                <h3 class="med-title-text">医療安全アラート スキャン判定・決定</h3>
                <p class="med-subtitle-text">オンライン資格確認 薬剤情報（4大リスク処方歴）および予約備考・MALLメモから検出された注意候補を表示しています。内容を確認し、反映するフラグを決定してください。</p>
            </div>
        </div>
        <button type="button" class="med-close-btn" onclick="closeMedicalAlertWindow(false)" title="閉じる (Esc)">✕</button>
    </div>

    <!-- ツールバー -->
    <div class="med-toolbar">
        <div class="med-toolbar-info">
            <span id="medScanSummaryText" style="font-weight:700; color:#1e293b;">スキャン対象を準備中...</span>
            <span id="medScanCountBadge" class="med-count-pill" style="display:none;">0名</span>
        </div>
        <div class="med-toolbar-actions">
            <div style="display:inline-flex; align-items:center; gap:5px; margin-right:8px;">
                <span style="font-size:11.5px; font-weight:700; color:#475569;">📅 処方期間:</span>
                <select id="medMonthsSelect" onchange="changeScanMonths(this.value)" style="border:1.5px solid #cbd5e1; border-radius:6px; padding:3px 8px; font-size:11.5px; font-weight:700; color:#1e293b; background:#fff; cursor:pointer;">
                    <option value="6" selected>直近6ヶ月</option>
                    <option value="0">全期間 (すべて)</option>
                </select>
            </div>
            <button type="button" class="med-btn-quick" onclick="toggleAllAlerts(true)">全選択</button>
            <button type="button" class="med-btn-quick" onclick="toggleAllAlerts(false)">全解除</button>
            <button type="button" class="med-btn-quick" onclick="rescanAlerts()">🔄 再スキャン</button>
        </div>
    </div>

    <!-- スキャン結果・判断材料リスト -->
    <div class="med-body" id="medAlertModalBody">
        <div class="med-loading">
            <div class="med-spinner"></div>
            <div style="margin-top:12px; color:#64748b; font-weight:600; font-size:13px;">予約備考・電子カルテメモ・オンライン資格確認をスキャン中...</div>
        </div>
    </div>

    <!-- フッター -->
    <div class="med-footer">
        <div class="med-footer-hint">
            <span>💡</span> <strong>ヒント:</strong> オン資格処方から抗血栓・糖尿病・向精神薬・NSAIDsを自動検出します。予約備考の <code>【抗血栓】</code> <code>【アレルギー】</code> <code>【感染症】</code> <code>【風邪】</code> も自動判定されます。
        </div>
        <div style="display:flex; gap:8px;">
            <button type="button" class="med-footer-close-btn" onclick="closeMedicalAlertWindow(false)">キャンセル</button>
            <button type="button" id="btnSaveAlerts" class="med-footer-save-btn" onclick="saveMedicalAlertDecisionsUI()">
                <span>✅</span> この内容でアラートを反映・保存
            </button>
        </div>
    </div>

    <script>
    /**
     * 医療安全アラート スキャン判定＆決定クライアントロジック
     */
    const medScanOptions = {
        date: <?= json_encode($date, JSON_UNESCAPED_UNICODE) ?>,
        dept: <?= json_encode($dept, JSON_UNESCAPED_UNICODE) ?>,
        year: <?= json_encode($year, JSON_UNESCAPED_UNICODE) ?>,
        month: <?= json_encode($month, JSON_UNESCAPED_UNICODE) ?>,
        schedule_ids: <?= json_encode($schedule_ids, JSON_UNESCAPED_UNICODE) ?>,
        patient_id: <?= json_encode($patient_id, JSON_UNESCAPED_UNICODE) ?>,
        months: 6
    };

    let medAlertCandidatesCache = [];
    let isSaving = false;

    // HTMLエスケープヘルパー
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeRegExp(string) {
        if (!string) return '';
        return String(string).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // 処方対象期間の切り替え
    function changeScanMonths(val) {
        medScanOptions.months = parseInt(val, 10);
        runMedicalAlertScan();
    }

    // モーダル・別ウィンドウ統合クローズ処理 (modal_edit.php と同一仕様)
    function closeMedicalAlertWindow(needReload = false, alertData = null) {
        if (needReload) {
            try { sessionStorage.setItem('need_reload', '1'); } catch(e) {}
        }
        // 1. iframe モーダルランナー (親画面) で動作している場合
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: needReload ? 'medical_alert_saved' : 'medical_alert_close',
                alerts: alertData || [],
                firstAlert: (alertData && alertData.length > 0) ? alertData[0] : null
            }, '*');
            return;
        }
        // 2. 従来の別ウィンドウ (ポップアップ) で動作している場合
        if (window.opener && !window.opener.closed) {
            if (needReload) {
                try { window.opener.location.reload(); } catch(e) {}
            }
        }
        window.close();
    }

    // ESCキー押下でクローズ
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMedicalAlertWindow(false);
        }
    });

    // スキャン実行
    function runMedicalAlertScan() {
        const body = document.getElementById('medAlertModalBody');
        const summary = document.getElementById('medScanSummaryText');
        const badge = document.getElementById('medScanCountBadge');

        body.innerHTML = `
            <div class="med-loading">
                <div class="med-spinner"></div>
                <div style="margin-top:12px; color:#64748b; font-weight:600; font-size:13px;">予約備考・電子カルテメモ・オンライン資格確認をスキャン中...</div>
            </div>
        `;

        let url = 'action_medical_alert.php?action=scan';
        if (medScanOptions.patient_id) url += '&patient_id=' + encodeURIComponent(medScanOptions.patient_id);
        if (medScanOptions.date) url += '&date=' + encodeURIComponent(medScanOptions.date);
        if (medScanOptions.dept) url += '&dept=' + encodeURIComponent(medScanOptions.dept);
        if (medScanOptions.year) url += '&year=' + encodeURIComponent(medScanOptions.year);
        if (medScanOptions.month) url += '&month=' + encodeURIComponent(medScanOptions.month);
        if (medScanOptions.schedule_ids) url += '&schedule_ids=' + encodeURIComponent(medScanOptions.schedule_ids);
        url += '&months=' + encodeURIComponent(medScanOptions.months !== undefined ? medScanOptions.months : 6);
        url += '&include_onshi=1';

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    medAlertCandidatesCache = data.candidates || [];
                    const mText = (data.filter_months ? `直近${data.filter_months}ヶ月` : '全期間');
                    if (summary) summary.textContent = `スキャン完了 (${mText}): 注意患者 ${medAlertCandidatesCache.length} 名を検出`;
                    if (badge) {
                        badge.style.display = 'inline-block';
                        badge.textContent = `${medAlertCandidatesCache.length}名`;
                    }
                    renderMedAlertCandidates();
                } else {
                    body.innerHTML = `<div style="text-align:center; padding:50px; color:#dc2626;">スキャンに失敗しました: ${escapeHtml((data && data.message) ? data.message : '不明なエラー')}</div>`;
                }
            })
            .catch(err => {
                body.innerHTML = `<div style="text-align:center; padding:50px; color:#dc2626;">通信エラー: ${escapeHtml(err.toString())}</div>`;
            });
    }

    function rescanAlerts() {
        runMedicalAlertScan();
    }

    // 候補描画
    function renderMedAlertCandidates() {
        const body = document.getElementById('medAlertModalBody');
        if (!body) return;

        try {
            if (!medAlertCandidatesCache || medAlertCandidatesCache.length === 0) {
                const mText = (medScanOptions.months ? `直近${medScanOptions.months}ヶ月` : '全期間');
                body.innerHTML = `
                    <div style="text-align:center; padding:60px 20px; color:#64748b;">
                        <div style="font-size:36px; margin-bottom:8px;">✅</div>
                        <div style="font-size:14px; font-weight:700; color:#334155;">抗血栓薬・糖尿病薬・向精神薬等の注意候補は見つかりませんでした (${mText})</div>
                        <div style="font-size:11.5px; color:#94a3b8; margin-top:4px;">オンライン資格確認に共有された処方データや、予約備考・カルテメモから自動検出されます</div>
                    </div>
                `;
                return;
            }

            let html = '';
            medAlertCandidatesCache.forEach((cand, idx) => {
                const hasTag = Boolean(cand.has_tag_detection);
                const hasOnshi = Boolean(cand.has_onshi_detection);
                let cardCls = 'med-patient-card';
                if (hasTag) cardCls += ' has-tag';
                if (hasOnshi) cardCls += ' has-onshi';

                // 検出理由の組み立て（7系統）
                let reasonRows = [];
                const cats = [
                    { key: 'antithrombotic', label: '💊 抗血栓薬' },
                    { key: 'diabetes',       label: '💉 糖尿病薬' },
                    { key: 'psychotropic',   label: '🧠 向精神薬・睡眠薬' },
                    { key: 'nsaids',         label: '💊 NSAIDs消炎鎮痛薬' },
                    { key: 'allergy',        label: '⚠️ 局麻アレルギー' },
                    { key: 'infection',      label: '🔴 感染症注意' },
                    { key: 'cold_fever',     label: '😷 風邪症状・発熱' }
                ];

                const reasonsObj = cand.reasons || {};
                cats.forEach(c => {
                    const list = reasonsObj[c.key] || [];
                    list.forEach(r => {
                        let tagCls = 'src-tag-memo';
                        let tagLabel = escapeHtml(r.source || 'カルテメモ');
                        if (r.is_onshi) {
                            tagCls = 'src-tag-onshi';
                            tagLabel = '🌐 オン資処方';
                        } else if (r.is_tag) {
                            tagCls = 'src-tag-gold';
                            tagLabel = '★確定タグ';
                        } else if (r.source && String(r.source).indexOf('予約備考') !== -1) {
                            tagCls = 'src-tag-remarks';
                            tagLabel = '予約備考';
                        }

                        const wordStr = (r.word !== null && r.word !== undefined) ? String(r.word) : '';
                        const snippetStr = (r.snippet !== null && r.snippet !== undefined) ? String(r.snippet) : '';
                        let displaySnippet = escapeHtml(snippetStr);
                        if (wordStr.trim() !== '') {
                            try {
                                const escapedWord = escapeHtml(wordStr);
                                displaySnippet = displaySnippet.replace(
                                    new RegExp(escapeRegExp(escapedWord), 'g'),
                                    `<span class="med-matched-keyword">${escapedWord}</span>`
                                );
                            } catch (e) {}
                        }

                        reasonRows.push(`
                            <div class="med-reason-row">
                                <span class="med-source-tag ${tagCls}">${tagLabel}</span>
                                <div class="med-snippet-text">
                                    <strong>${c.label}:</strong> ${displaySnippet}
                                </div>
                            </div>
                        `);
                    });
                });

                const reasonsHtml = reasonRows.length > 0 
                    ? `<div class="med-reasons-box">${reasonRows.join('')}</div>` 
                    : `<div style="font-size:11px; color:#94a3b8; margin-bottom:8px;">※現在保存されているアラート設定（手動変更済）</div>`;

                const pid = cand.patient_id ? String(cand.patient_id).trim() : '';
                const karteUrl = pid 
                    ? `http://192.168.1.16/karteview/karteview.php?source=new&do_search=1&c_filter=all&show_all_sick=0&show_images=1&show_orders=1&show_dots=1&patient_no=${encodeURIComponent(pid)}` 
                    : '#';

                const pidBadgeHtml = pid 
                    ? `<a href="${karteUrl}" target="_blank" rel="noopener noreferrer" class="med-pid-badge med-pid-link" title="MALL3電子カルテビューアーを開く (別タブ)" onclick="event.stopPropagation()">${escapeHtml(pid)} <span style="font-size:10px; font-weight:normal;">↗</span></a>`
                    : `<span class="med-pid-badge" style="background:#f1f5f9; color:#94a3b8;">ID未設定</span>`;

                // オン資ステータス表示
                let onshiStatusHtml = '';
                if (cand.onshi_info) {
                    const o = cand.onshi_info;
                    if (!o.has_consent) {
                        onshiStatusHtml += `<span style="font-size:10px; color:#64748b; background:#f1f5f9; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0;">オン資: 同意なし</span>`;
                    } else if (!o.has_records) {
                        onshiStatusHtml += `<span style="font-size:10px; color:#64748b; background:#f1f5f9; padding:2px 6px; border-radius:4px; border:1px solid #e2e8f0;">オン資: 処方データなし</span>`;
                    } else if (!o.has_risk_drugs) {
                        const mLabel = o.filter_months ? `直近${o.filter_months}ヶ月` : '全期間';
                        onshiStatusHtml += `<span style="font-size:10px; color:#059669; background:#ecfdf5; padding:2px 6px; border-radius:4px; border:1px solid #a7f3d0;">オン資: リスク薬なし (${mLabel})</span>`;
                    }
                    if (o.viewer_url) {
                        onshiStatusHtml += `<a href="${escapeHtml(o.viewer_url)}" target="_blank" rel="noopener noreferrer" class="med-onshi-link" title="オンライン資格確認 他院処方履歴を開く (別タブ)" onclick="event.stopPropagation()">📋 オン資処方一覧 ↗</a>`;
                    }
                }

                html += `
                    <div class="${cardCls}" id="medCard_${cand.schedule_id}">
                        <div class="med-card-header">
                            <div class="med-patient-identity">
                                ${pidBadgeHtml}
                                <span class="med-pname">${escapeHtml(cand.patient_name || '')}</span>
                                ${hasTag ? '<span style="font-size:10px; font-weight:800; background:#fef3c7; color:#b45309; padding:1px 6px; border-radius:4px; border:1px solid #fde68a;">★確定タグあり</span>' : ''}
                                ${hasOnshi ? '<span class="med-badge-onshi-detect">🌐 オン資リスク検出</span>' : ''}
                                ${onshiStatusHtml}
                            </div>
                            <div class="med-sched-meta">
                                <span>📅 ${escapeHtml(cand.exam_date || '')} ${escapeHtml(cand.scheduled_time || '')}</span>
                                <span>(${escapeHtml(cand.department_code === 'PROCT' ? '肛門科' : '胃腸科')})</span>
                                <span>🔬 ${escapeHtml(cand.exam_type || '-')}</span>
                                <span>👨‍⚕️ ${escapeHtml(cand.doctor_name || '-')}</span>
                            </div>
                        </div>

                        <!-- 根拠・判断材料エリア -->
                        ${reasonsHtml}

                        <!-- 2グループ化 反映・決定チェックボックス群 -->
                        <div class="med-groups-wrapper">
                            <!-- Group 1: 🌐 オンライン資格確認・薬剤リスク -->
                            <div class="med-group-block">
                                <div class="med-group-header">
                                    <span>🌐 オンライン資格確認・薬剤リスク (休薬・絶食・麻酔注意)</span>
                                </div>
                                <div class="med-decisions-grid">
                                    <label class="med-check-label ${cand.has_antithrombotic ? 'is-checked-anti' : ''}">
                                        <input type="checkbox" id="chk_anti_${cand.schedule_id}" ${cand.has_antithrombotic ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-anti')">
                                        <span>💊 抗血栓薬 (休薬確認要)</span>
                                    </label>

                                    <label class="med-check-label ${cand.has_diabetes ? 'is-checked-diabetes' : ''}">
                                        <input type="checkbox" id="chk_diabetes_${cand.schedule_id}" ${cand.has_diabetes ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-diabetes')">
                                        <span>💉 糖尿病薬 (絶食/低血糖)</span>
                                    </label>

                                    <label class="med-check-label ${cand.has_psychotropic ? 'is-checked-psychotropic' : ''}">
                                        <input type="checkbox" id="chk_psychotropic_${cand.schedule_id}" ${cand.has_psychotropic ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-psychotropic')">
                                        <span>🧠 向精神薬・睡眠薬 (鎮静注意)</span>
                                    </label>

                                    <label class="med-check-label ${cand.has_nsaids ? 'is-checked-nsaids' : ''}">
                                        <input type="checkbox" id="chk_nsaids_${cand.schedule_id}" ${cand.has_nsaids ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-nsaids')">
                                        <span>💊 NSAIDs消炎鎮痛 (潰瘍/出血)</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Group 2: 📋 カルテ・予約注意 -->
                            <div class="med-group-block">
                                <div class="med-group-header">
                                    <span>📋 カルテメモ・予約注意 (アレルギー・感染症・風邪症状)</span>
                                </div>
                                <div class="med-decisions-grid">
                                    <label class="med-check-label ${cand.has_allergy ? 'is-checked-allergy' : ''}">
                                        <input type="checkbox" id="chk_allergy_${cand.schedule_id}" ${cand.has_allergy ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-allergy')">
                                        <span>⚠️ 局麻・アレルギー注意</span>
                                    </label>

                                    <label class="med-check-label ${cand.has_infection ? 'is-checked-infection' : ''}">
                                        <input type="checkbox" id="chk_inf_${cand.schedule_id}" ${cand.has_infection ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-infection')">
                                        <span>🔴 感染症注意 (肝炎/梅毒等)</span>
                                    </label>

                                    <label class="med-check-label ${cand.has_cold_fever ? 'is-checked-cold' : ''}">
                                        <input type="checkbox" id="chk_cold_${cand.schedule_id}" ${cand.has_cold_fever ? 'checked' : ''} onchange="toggleMedLabelClass(this, 'is-checked-cold')">
                                        <span>😷 風邪症状・発熱 (有症状)</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            body.innerHTML = html;
        } catch (renderErr) {
            console.error('renderMedAlertCandidates error:', renderErr);
            body.innerHTML = `<div style="text-align:center; padding:30px; color:#dc2626;">表示処理エラーが発生しました: ${escapeHtml(renderErr.toString())}</div>`;
        }
    }

    function toggleMedLabelClass(input, cls) {
        const label = input.closest('.med-check-label');
        if (label) {
            label.classList.toggle(cls, input.checked);
        }
    }

    function toggleAllAlerts(checked) {
        const alertKeys = [
            { k: 'anti',         cls: 'is-checked-anti' },
            { k: 'diabetes',     cls: 'is-checked-diabetes' },
            { k: 'psychotropic', cls: 'is-checked-psychotropic' },
            { k: 'nsaids',       cls: 'is-checked-nsaids' },
            { k: 'allergy',      cls: 'is-checked-allergy' },
            { k: 'inf',          cls: 'is-checked-infection' },
            { k: 'cold',         cls: 'is-checked-cold' }
        ];

        medAlertCandidatesCache.forEach(cand => {
            alertKeys.forEach(item => {
                const el = document.getElementById(`chk_${item.k}_${cand.schedule_id}`);
                if (el) {
                    el.checked = checked;
                    toggleMedLabelClass(el, item.cls);
                }
            });
        });
    }

    function saveMedicalAlertDecisionsUI() {
        if (isSaving) return;

        if (medAlertCandidatesCache.length === 0) {
            closeMedicalAlertWindow(false);
            return;
        }

        const btnSave = document.getElementById('btnSaveAlerts');
        if (btnSave) {
            btnSave.disabled = true;
            btnSave.innerHTML = `<span>⏳</span> 保存中...`;
        }
        isSaving = true;

        const payload = medAlertCandidatesCache.map(cand => {
            const sid = cand.schedule_id;
            const anti         = document.getElementById(`chk_anti_${sid}`)?.checked || false;
            const diabetes     = document.getElementById(`chk_diabetes_${sid}`)?.checked || false;
            const psychotropic = document.getElementById(`chk_psychotropic_${sid}`)?.checked || false;
            const nsaids       = document.getElementById(`chk_nsaids_${sid}`)?.checked || false;
            const allergy      = document.getElementById(`chk_allergy_${sid}`)?.checked || false;
            const inf          = document.getElementById(`chk_inf_${sid}`)?.checked || false;
            const cold         = document.getElementById(`chk_cold_${sid}`)?.checked || false;

            return {
                schedule_id: sid,
                patient_id: cand.patient_id,
                patient_name: cand.patient_name,
                exam_date: cand.exam_date,
                department_code: cand.department_code,
                has_antithrombotic: anti,
                has_diabetes: diabetes,
                has_psychotropic: psychotropic,
                has_nsaids: nsaids,
                has_allergy: allergy,
                has_infection: inf,
                has_cold_fever: cold,
                antithrombotic_verified: cand.antithrombotic_verified,
                reasons: cand.reasons
            };
        });

        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('alerts', JSON.stringify(payload));

        fetch('action_medical_alert.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    // 親画面へ保存完了シグナルと保存データを送信して閉じる
                    closeMedicalAlertWindow(true, payload);
                } else {
                    alert('保存失敗: ' + (data.message || 'エラー'));
                    if (btnSave) {
                        btnSave.disabled = false;
                        btnSave.innerHTML = `<span>✅</span> この内容でアラートを反映・保存`;
                    }
                    isSaving = false;
                }
            })
            .catch(err => {
                alert('通信エラー: ' + err.toString());
                if (btnSave) {
                    btnSave.disabled = false;
                    btnSave.innerHTML = `<span>✅</span> この内容でアラートを反映・保存`;
                }
                isSaving = false;
            });
    }

    // 初期起動
    document.addEventListener('DOMContentLoaded', function() {
        runMedicalAlertScan();
    });
    </script>
</body>
</html>
