<?php
/**
 * 生成日時: 2026-08-05
 * MALL3 API 接続テスト
 * get_mall_patient.php と sync_mall_memo.php の動作確認
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MALL3 API 接続テスト</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #333;
            font-size: 13px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #34495e;
            padding-bottom: 10px;
        }
        .test-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
        }
        .test-box label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        .test-box input[type="text"] {
            padding: 8px 12px;
            font-size: 16px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            width: 200px;
        }
        .test-box button {
            padding: 8px 20px;
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
            margin-left: 10px;
        }
        .test-box button:hover { background: #2563eb; }

        .result-box {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
            margin-top: 10px;
        }
        .result-box .success { color: #4ade80; }
        .result-box .error { color: #f87171; }
        .result-box .info { color: #60a5fa; }

        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 12px;
        }
        .status-badge.ok { background: #d1fae5; color: #065f46; }
        .status-badge.ng { background: #fef2f2; color: #991b1b; }
        .status-badge.waiting { background: #fef3c7; color: #92400e; }

        .test-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .test-item:last-child { border-bottom: none; }
        .test-item .label { font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <h2>🔌 MALL3 API 接続テスト</h2>

    <!-- テスト実行エリア -->
    <div class="test-box">
        <label>📋 テスト用 カルテID（PatientNo）</label>
        <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
            <input type="text" id="test_patient_no" placeholder="例: 80422" value="80422">
            <button onclick="runTest()">▶ テスト実行</button>
            <button onclick="clearResult()" style="background:#6c757d;">クリア</button>
        </div>
        <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
            💡 実在するカルテIDを入力してください（例：80422, 80341, 71111）
        </div>
    </div>

    <!-- テスト結果 -->
    <div id="result_area">
        <h3 style="margin:15px 0 8px 0; font-size:14px;">📊 テスト結果</h3>
        <div class="result-box" id="result_log">
            <span class="info">⏳ テストを実行してください...</span>
        </div>
    </div>

    <!-- 各APIの状態表示 -->
    <div style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:15px;">
        <h3 style="margin:0 0 10px 0; font-size:14px;">📡 API ステータス</h3>
        <div id="api_status">
            <div class="test-item">
                <span class="label">📡 get_mall_patient.php</span>
                <span class="status-badge waiting" id="status_patient">未テスト</span>
            </div>
            <div class="test-item">
                <span class="label">📡 get_mall_memos.php</span>
                <span class="status-badge waiting" id="status_memos">未テスト</span>
            </div>
            <div class="test-item">
                <span class="label">📡 sync_mall_memo.php</span>
                <span class="status-badge waiting" id="status_sync">未テスト</span>
            </div>
            <div class="test-item">
                <span class="label">📡 get_present_doctors.php</span>
                <span class="status-badge waiting" id="status_doctors">未テスト</span>
            </div>
        </div>
    </div>

</div>

<script>
const log = document.getElementById('result_log');

function logMessage(msg, type = 'info') {
    const prefix = type === 'success' ? '✅ ' : type === 'error' ? '❌ ' : 'ℹ️ ';
    const colorClass = type === 'success' ? 'success' : type === 'error' ? 'error' : 'info';
    log.innerHTML += `<span class="${colorClass}">${prefix}${msg}</span>\n`;
    log.scrollTop = log.scrollHeight;
}

function clearResult() {
    log.innerHTML = '<span class="info">⏳ テストを実行してください...</span>';
    document.querySelectorAll('.status-badge').forEach(el => {
        el.className = 'status-badge waiting';
        el.textContent = '未テスト';
    });
}

function updateStatus(id, status, label) {
    const el = document.getElementById(id);
    el.className = `status-badge ${status}`;
    el.textContent = label;
}

async function runTest() {
    const patientNo = document.getElementById('test_patient_no').value.trim();
    if (!patientNo || isNaN(patientNo)) {
        logMessage('有効なカルテIDを入力してください', 'error');
        return;
    }

    clearResult();
    logMessage(`🔍 テスト開始: カルテID = ${patientNo}`, 'info');

    // ============================================================
    // テスト1: get_mall_patient.php
    // ============================================================
    logMessage('📡 テスト1: get_mall_patient.php を実行中...', 'info');
    try {
        const res1 = await fetch(`get_mall_patient.php?patient_no=${patientNo}`);
        const data1 = await res1.json();
        
        if (data1.success) {
            logMessage(`✅ 患者情報取得成功: ${data1.patient_name} (${data1.patient_kana || 'ヨミガナなし'})`, 'success');
            logMessage(`   📅 生年月日: ${data1.birthday || '未登録'}`, 'info');
            logMessage(`   🏥 性別: ${data1.gender || '未登録'}`, 'info');
            logMessage(`   📍 住所: ${data1.address || '未登録'}`, 'info');
            updateStatus('status_patient', 'ok', '✅ OK');
        } else {
            logMessage(`❌ 患者情報取得失敗: ${data1.message || '不明なエラー'}`, 'error');
            updateStatus('status_patient', 'ng', '❌ NG');
        }
    } catch (e) {
        logMessage(`❌ get_mall_patient.php エラー: ${e.message}`, 'error');
        updateStatus('status_patient', 'ng', '❌ NG');
    }

    // ============================================================
    // テスト2: get_mall_memos.php
    // ============================================================
    logMessage('📡 テスト2: get_mall_memos.php を実行中...', 'info');
    try {
        const res2 = await fetch(`get_mall_memos.php?patient_no=${patientNo}`);
        const data2 = await res2.json();
        
        if (data2.success) {
            const count = data2.memos ? data2.memos.length : 0;
            logMessage(`✅ メモ取得成功: ${count}件`, 'success');
            if (data2.memos) {
                data2.memos.forEach(m => {
                    logMessage(`   📝 [${m.div_name}] ${m.note ? m.note.substring(0, 50) : '(空)'}`, 'info');
                });
            }
            updateStatus('status_memos', 'ok', '✅ OK');
        } else {
            logMessage(`❌ メモ取得失敗: ${data2.message || '不明なエラー'}`, 'error');
            updateStatus('status_memos', 'ng', '❌ NG');
        }
    } catch (e) {
        logMessage(`❌ get_mall_memos.php エラー: ${e.message}`, 'error');
        updateStatus('status_memos', 'ng', '❌ NG');
    }

    // ============================================================
    // テスト3: sync_mall_memo.php（POST）
    // ============================================================
    logMessage('📡 テスト3: sync_mall_memo.php を実行中...', 'info');
    try {
        const res3 = await fetch(`sync_mall_memo.php?patient_id=${patientNo}`);
        const data3 = await res3.json();
        
        if (data3.success) {
            logMessage(`✅ メモ同期成功`, 'success');
            logMessage(`   📝 本日メモ: ${data3.memo_today || '(空)'}`, 'info');
            logMessage(`   📝 カルテメモ: ${data3.memo_karte || '(空)'}`, 'info');
            logMessage(`   📝 患者メモ: ${data3.memo_patient || '(空)'}`, 'info');
            updateStatus('status_sync', 'ok', '✅ OK');
        } else {
            logMessage(`❌ メモ同期失敗: ${data3.message || '不明なエラー'}`, 'error');
            updateStatus('status_sync', 'ng', '❌ NG');
        }
    } catch (e) {
        logMessage(`❌ sync_mall_memo.php エラー: ${e.message}`, 'error');
        updateStatus('status_sync', 'ng', '❌ NG');
    }

    // ============================================================
    // テスト4: get_present_doctors.php
    // ============================================================
    const today = new Date().toISOString().split('T')[0];
    logMessage(`📡 テスト4: get_present_doctors.php (date=${today}) を実行中...`, 'info');
    try {
        const res4 = await fetch(`get_present_doctors.php?date=${today}&dept=GASTRO`);
        const data4 = await res4.json();
        
        if (data4.doctors) {
            logMessage(`✅ 出勤医師取得成功: ${data4.doctors.join(', ')}`, 'success');
            updateStatus('status_doctors', 'ok', '✅ OK');
        } else {
            logMessage(`❌ 出勤医師取得失敗: データがありません`, 'error');
            updateStatus('status_doctors', 'ng', '❌ NG');
        }
    } catch (e) {
        logMessage(`❌ get_present_doctors.php エラー: ${e.message}`, 'error');
        updateStatus('status_doctors', 'ng', '❌ NG');
    }

    logMessage('🏁 テスト完了', 'info');
}

// Enterキーでテスト実行
document.getElementById('test_patient_no').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        runTest();
    }
});
</script>

</body>
</html>