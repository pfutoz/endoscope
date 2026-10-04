<?php
/**
 * ファイル名: patient_soap_view.php
 * 概要: MALL3電子カルテ SOAPデータ照会ビュー（別ウィンドウ表示用）
 */
$patient_no = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : (isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>カルテSOAP照会 - <?= htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 18px; background: #f8fafc; color: #1e293b; font-size: 13px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px; }
        .patient-title { font-size: 16px; font-weight: bold; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .patient-no-badge { background: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 4px; font-size: 13px; border: 1px solid #bfdbfe; }
        .close-btn { padding: 5px 14px; cursor: pointer; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; font-weight: bold; font-size: 12px; color: #475569; }
        .close-btn:hover { background: #f1f5f9; color: #0f172a; }

        .toolbar { display: flex; align-items: center; justify-content: space-between; background: #f1f5f9; padding: 8px 12px; border-radius: 6px; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .date-select { padding: 5px 10px; font-size: 13px; font-weight: bold; border-radius: 4px; border: 1px solid #cbd5e1; background: #fff; }

        .soap-card { border: 1px solid #cbd5e1; border-radius: 6px; margin-bottom: 14px; overflow: hidden; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
        .soap-header { display: flex; align-items: center; gap: 8px; padding: 6px 12px; font-weight: bold; font-size: 12px; }
        .soap-body { padding: 12px 16px; white-space: pre-wrap; word-break: break-all; line-height: 1.6; font-size: 13px; }

        /* S / O / A / P 区分色 */
        .type-S .soap-header { background: #eff6ff; color: #1d4ed8; border-bottom: 1px solid #bfdbfe; }
        .type-O .soap-header { background: #f0fdf4; color: #15803d; border-bottom: 1px solid #bbf7d0; }
        .type-A .soap-header { background: #fefce8; color: #a16207; border-bottom: 1px solid #fef08a; }
        .type-P .soap-header { background: #faf5ff; color: #7e22ce; border-bottom: 1px solid #e9d5ff; }
        .type-その他 .soap-header { background: #f1f5f9; color: #475569; border-bottom: 1px solid #cbd5e1; }

        .badge-soap { display: inline-block; width: 22px; height: 22px; line-height: 22px; text-align: center; border-radius: 4px; color: #fff; font-weight: 800; font-size: 12px; }
        .type-S .badge-soap { background: #2563eb; }
        .type-O .badge-soap { background: #16a34a; }
        .type-A .badge-soap { background: #ca8a04; }
        .type-P .badge-soap { background: #9333ea; }
        .type-その他 .badge-soap { background: #64748b; }

        .meta-text { font-size: 11px; font-weight: normal; color: #64748b; margin-left: auto; }
        .empty-msg { text-align: center; padding: 40px; color: #94a3b8; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="patient-title" id="patientInfo">
            <span>カルテ照会中...</span>
        </div>
        <button type="button" class="close-btn" onclick="window.close()">✕ 閉じる</button>
    </div>

    <div class="toolbar">
        <div style="display: flex; align-items: center; gap: 8px;">
            <label for="dateSelect" style="font-weight: bold; color: #334155;">📅 受診日履歴:</label>
            <select id="dateSelect" class="date-select" onchange="fetchSoapData(this.value)">
            </select>
        </div>
        <div id="statusInfo" style="font-size: 11px; color: #64748b;"></div>
    </div>

    <div id="soapContent">
        <div class="empty-msg">MALL電子カルテからSOAP明細を取得しています...</div>
    </div>
</div>

<script>
const patientNo = <?= json_encode($patient_no) ?>;

function fetchSoapData(targetDate = '') {
    const content = document.getElementById('soapContent');
    const statusInfo = document.getElementById('statusInfo');
    content.innerHTML = '<div class="empty-msg">読み込み中...</div>';

    let url = 'get_mall_soap.php?patient_no=' + encodeURIComponent(patientNo);
    if (targetDate) {
        url += '&date=' + encodeURIComponent(targetDate);
    }

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = `<div style="color:#dc2626; padding:30px; text-align:center; font-weight:bold;">${data.message}</div>`;
                return;
            }

            const p = data.patient;
            document.getElementById('patientInfo').innerHTML = `
                <span class="patient-no-badge">ID: ${escapeHtml(p.patient_no)}</span>
                <span>${escapeHtml(p.patient_name)} 様</span>
                <span style="font-size:12px; color:#64748b; font-weight:normal;">(${escapeHtml(p.patient_kana)})</span>
            `;

            // 日付プルダウンの初期生成
            const dateSel = document.getElementById('dateSelect');
            if (dateSel.options.length === 0 && data.history_dates) {
                data.history_dates.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d;
                    opt.innerText = d;
                    if (d === data.current_date) opt.selected = true;
                    dateSel.appendChild(opt);
                });
            }

            statusInfo.innerText = `受診日: ${data.current_date} (計 ${data.records ? data.records.length : 0} 項目)`;

            if (!data.records || data.records.length === 0) {
                content.innerHTML = '<div class="empty-msg">該当受診日のカルテ明細（SOAP）はありません。</div>';
                return;
            }

            let html = '';
            data.records.forEach(r => {
                const note = r.note !== '' ? r.note : '（記載なし）';
                html += `
                    <div class="soap-card type-${r.soap_type}">
                        <div class="soap-header">
                            <span class="badge-soap">${r.soap_type}</span>
                            <span>${r.soap_type}（${getSoapLabel(r.soap_type)}）</span>
                            <span class="meta-text">記載医: <strong>${escapeHtml(r.doctor_name)}</strong> | 記録日時: ${escapeHtml(r.edit_datetime)}</span>
                        </div>
                        <div class="soap-body">${escapeHtml(note)}</div>
                    </div>
                `;
            });
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = `<div style="color:#dc2626; padding:30px; text-align:center; font-weight:bold;">通信エラーが発生しました: ${escapeHtml(err.message)}</div>`;
        });
}

function getSoapLabel(type) {
    const map = {
        'S': '主訴・患者情報',
        'O': '所見・検査結果',
        'A': '評価・診断',
        'P': '治療計画・処方・処置'
    };
    return map[type] || 'その他';
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g, tag => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[tag] || tag));
}

document.addEventListener('DOMContentLoaded', () => {
    fetchSoapData();
});
</script>

</body>
</html>