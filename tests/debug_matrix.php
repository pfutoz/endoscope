<?php
// 全てのエラーを画面に表示する
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ローカルの PostgreSQL (予約システム) 接続テスト
$pg_status = "未検証";
try {
    $dsn = "pgsql:host=localhost;dbname=endoscope;";
    $pdo = new PDO($dsn, 'postgres', 'postgres', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pg_status = "<span style='color:green; font-weight:bold;'>🟢 接続成功 (PostgreSQL)</span>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM m_input_master");
    $waku_count = $stmt->fetchColumn();
    $pg_status .= " — m_input_masterレコード数: " . $waku_count . "件";
} catch (PDOException $e) {
    $pg_status = "<span style='color:red; font-weight:bold;'>❌ 接続失敗: " . htmlspecialchars($e->getMessage()) . "</span>";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>予約システム 集中デバッグ画面</title>
    <style>
        body { font-family: sans-serif; margin: 30px; background-color: #f8f9fa; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        h3 { margin-top: 0; color: #34495e; border-bottom: 2px solid #34495e; padding-bottom: 6px; }
        .console-log { background: #1e1e1e; color: #00ff00; padding: 15px; font-family: monospace; border-radius: 6px; font-size: 13px; min-height: 150px; white-space: pre-wrap; overflow-y: auto; }
        input[type="text"] { padding: 8px; font-size: 14px; width: 180px; border: 1px solid #ccc; border-radius: 4px; }
        .test-input-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px; }
        .test-input-grid label { font-weight: bold; font-size: 13px; display: block; margin-bottom: 3px; }
        .test-input-grid input { padding: 6px; width: 100%; box-sizing: border-box; }
        
        /* メモ表示用のスタイル */
        .memo-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
        .memo-table th, .memo-table td { border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: top; }
        .memo-table th { background: #e6f2ff; width: 180px; }
        .memo-text { white-space: pre-wrap; background: #fffde6; padding: 6px; border-radius: 4px; border: 1px solid #e6db55; font-family: monospace; }
    </style>
</head>
<body>

<h1>🛠️ 予約システム・MALL分離連携 集中デバッグ画面</h1>
<p style="color:#666;">「基本情報（get_mall_patient.php）」と「全区分のメモ情報（get_mall_memos.php）」の両方が正しく動いているか検証します。</p>

<div class="card">
    <h3>【テスト1】 予約システム用データベース（PostgreSQL）通信状況</h3>
    <p>現在のステータス: <?=$pg_status?></p>
</div>

<div class="card">
    <h3>【テスト2】 分離型APIのリアルタイム動作確認</h3>
    <p>実在する患者IDを入力して、<strong>Enterキー</strong>を押すか、<strong>枠の外をクリック</strong>してください。Enter時の送信ブロック制御と、2本のAPI（基本、メモ）が同時走査されます。</p>
    
    <div style="background: #f1f3f5; padding: 15px; border-radius: 6px;">
        <div>
            <label for="test_pid" style="font-weight:bold; color:#d32f2f;">👉 ここに患者IDを入力: </label>
            <input type="text" id="test_pid" placeholder="例: 15111" autocomplete="off">
        </div>
        
        <div class="test-input-grid">
            <div>
                <label>基本情報：患者氏名 (get_mall_patient.php より)</label>
                <input type="text" id="test_pname" readonly style="background:#e9ecef; font-weight:bold;">
            </div>
            <div>
                <label>基本情報：生年月日 (get_mall_patient.php より)</label>
                <input type="text" id="test_birthday" readonly style="background:#e9ecef;">
            </div>
        </div>

        <div style="margin-top: 20px;">
            <label style="font-weight: bold; font-size: 13px;">全種類のメモ一覧 (get_mall_memos.php より)</label>
            <table class="memo-table">
                <thead>
                    <tr><th>メモの種類 (DivID)</th><th>内容 (Note) / 更新日時</th></tr>
                </thead>
                <tbody id="memo_list_tbody">
                    <tr><td colspan="2" style="text-align:center; color:#999;">IDを入力するとここに全区分のメモが並びます</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h3>【テスト3】 リアルタイム動作追跡ログ（JavaScriptブラウザコンソール）</h3>
    <div class="console-log" id="custom_console">【システムログ】検証を開始しました。ID入力を待機中...</div>
</div>

<script>
const consoleLogArea = document.getElementById('custom_console');
const pidInput = document.getElementById('test_pid');

function logToScreen(message, isError = false) {
    const time = new Date().toLocaleTimeString();
    const color = isError ? '#ff6b6b' : '#00ff00';
    consoleLogArea.innerHTML += `\n<span style="color: ${color}">[${time}] ${message}</span>`;
    consoleLogArea.scrollTop = consoleLogArea.scrollHeight;
}

// Enterキーの誤送信ブロック＆検索処理実行
pidInput.addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        event.preventDefault(); // 画面送信を確実にブロック
        logToScreen("⌨️ Enterキーを検出！不要なフォーム送信（削除・保存）を正常にブロックしました。");
        runDualFetch(this.value);
    }
});

// フォーカスが外れたときも自動検索
pidInput.addEventListener('blur', function() {
    logToScreen(`鼠标 入力枠からフォーカスが外れました(onblur)。`);
    runDualFetch(this.value);
});

// 2つのAPIを同時に呼び出す処理
function runDualFetch(patientNo) {
    if (!patientNo || isNaN(patientNo)) {
        logToScreen("⚠️ 有効な数値IDではないため処理をスキップしました。", true);
        return;
    }

    // 以前の検索結果の初期化
    document.getElementById('test_pname').value = '';
    document.getElementById('test_birthday').value = '';
    document.getElementById('memo_list_tbody').innerHTML = '<tr><td colspan="2" style="text-align:center;">データを読み込み中...</td></tr>';

    logToScreen(`=======================================================`);
    logToScreen(`🚀 患者ID: [ ${patientNo} ] の2系統同時照会を開始します。`);

    // --- 系統A: 基本情報APIの呼び出し ---
    logToScreen(`📡 系統A: get_mall_patient.php にリクエスト送信中...`);
    fetch(`get_mall_patient.php?patient_no=${patientNo}`)
        .then(res => {
            logToScreen(`📥 系統Aレスポンス受信 (Status: ${res.status})`);
            return res.json();
        })
        .then(data => {
            if(data.success) {
                document.getElementById('test_pname').value = data.patient_name;
                document.getElementById('test_birthday').value = data.birthday;
                logToScreen(`✅ 【基本情報 取得成功】 氏名: ${data.patient_name} / 生年月日: ${data.birthday || '未登録'}`);
            } else {
                logToScreen(`❌ 【基本情報 取得失敗】 理由: ${data.message}`, true);
            }
        })
        .catch(err => logToScreen(`💥 【基本情報 致命的エラー】: ${err.message}`, true));

    // --- 系統B: メモ情報APIの呼び出し ---
    logToScreen(`📡 系統B: get_mall_memos.php にリクエスト送信中...`);
    fetch(`get_mall_memos.php?patient_no=${patientNo}`)
        .then(res => {
            logToScreen(`📥 系統Bレスポンス受信 (Status: ${res.status})`);
            return res.json();
        })
        .then(data => {
            if(data.success) {
                const tbody = document.getElementById('memo_list_tbody');
                tbody.innerHTML = ''; // クリア

                if(data.memos.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="2" style="text-align:center; color:#666;">この患者に紐づくメモレコードはありません</td></tr>';
                    logToScreen(`ℹ️ 【メモ情報 取得結果】 レコードは0件です。`);
                    return;
                }

                // 取得したメモ一覧をループでテーブルにはめ込む
                data.memos.forEach(memo => {
                    const tr = document.createElement('tr');
                    
                    // 当日メモ(DivID:1)の場合のみ対象日を付与
                    let dateBadge = (memo.div_id === 1 && memo.memo_date) ? `<br><span style="background:#34495e; color:#fff; padding:1px 4px; border-radius:3px; font-size:10px;">対象日: ${memo.memo_date}</span>` : '';
                    
                    tr.innerHTML = `
                        <td><strong>【項番 ${memo.div_id}】 ${memo.div_name}</strong>${dateBadge}</td>
                        <td>
                            <div class="memo-text">${memo.note || '<span style="color:#aaa; font-style:italic;">(空欄)</span>'}</div>
                            <div style="font-size:10px; color:#888; margin-top:4px; text-align:right;">
                                更新: ${memo.update_time} | MemoID: ${memo.memo_id} | Status: ${memo.status_flag}
                            </div>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
                logToScreen(`✅ 【メモ情報 取得成功】 合計 ${data.memos.length} 件のメモを展開しました。`);
            } else {
                logToScreen(`❌ 【メモ情報 取得失敗】 理由: ${data.message}`, true);
            }
        })
        .catch(err => logToScreen(`💥 【メモ情報 致命的エラー】: ${err.message}`, true));
}
</script>

</body>
</html>