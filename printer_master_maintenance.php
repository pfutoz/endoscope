<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\printer_master_maintenance.php
 * ファイル名: printer_master_maintenance.php
 * 概要: 院内プリンターマスターメンテナンス画面
 *       - サーバーOS上のインストール済みプリンターの自動検出（Get-Printer）
 *       - 印刷画面（本日一覧印刷・ネームプレート印刷）に表示するプリンターの選択・有効化
 *       - 画面表示名称（ラベル）、設置場所、デフォルトプリンターの自由編集・保存
 * 生成日時: 2026-10-30 13:20
 * 修正履歴:
 *   - 2026-10-30: 【新規作成: 院内プリンターマスターメンテナンス】
 *   - 2026-11-01: 統合ヘッダーナビに「📢 修正インフォメーション」タブを追加
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/lib/printer_helper.php';
require_once __DIR__ . '/lib/env_helper.php';

$message = '';
$is_error = false;

// 1. 保存処理 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_printers') {
        $names       = $_POST['printer_name'] ?? [];
        $labels      = $_POST['printer_label'] ?? [];
        $ips         = $_POST['printer_ip'] ?? [];
        $locations   = $_POST['printer_location'] ?? [];
        $actives     = $_POST['printer_active'] ?? [];
        $default_prt = trim($_POST['default_printer'] ?? '');

        $new_printers = [];
        for ($i = 0; $i < count($names); $i++) {
            $name = trim($names[$i] ?? '');
            if ($name === '') continue;

            $label    = trim($labels[$i] ?? '') !== '' ? trim($labels[$i]) : $name;
            $ip       = trim($ips[$i] ?? '');
            $location = trim($locations[$i] ?? '');
            $is_act   = !empty($actives[$name]);

            $new_printers[] = [
                'name'      => $name,
                'label'     => $label,
                'ip'        => $ip,
                'location'  => $location,
                'is_active' => $is_act
            ];
        }

        $new_config = [
            'default_printer' => $default_prt !== '' ? $default_prt : ($new_printers[0]['name'] ?? ''),
            'printers'        => $new_printers
        ];

        if (savePrinterConfig($new_config)) {
            $message = "✅ プリンター設定を正常に更新・保存しました。本日一覧印刷およびネームプレート印刷の選択肢へ即座に反映されます。";
        } else {
            $message = "❌ 設定の保存に失敗しました。書き込み権限をご確認ください。";
            $is_error = true;
        }
    }
}

// 2. 現在の設定およびサーバー検出プリンターを取得
$current_config   = getPrinterConfig();
$configured_names = array_column($current_config['printers'] ?? [], 'name');
$detected_printers = getSystemInstalledPrinters();

// システム検出プリンターの重複除外と整理
$system_printers_map = [];
foreach ($detected_printers as $sp) {
    $pname = trim($sp['Name'] ?? '');
    if ($pname === '') continue;
    $system_printers_map[$pname] = $sp;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>プリンターマスターメンテナンス</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", "Hiragino Sans", "Meiryo", sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
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

        h2 {
            margin-top: 0;
            color: #0f172a;
            border-bottom: 2px solid #334155;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn-nav-action {
            background: #334155;
            color: #fff;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: none;
            cursor: pointer;
        }
        .btn-nav-action:hover { background: #1e293b; }

        .msg {
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 13px;
        }
        .msg-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .msg-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* ガイドボックス */
        .info-card {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .info-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #0369a1;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-card p {
            color: #334155;
            font-size: 12.5px;
            margin-bottom: 4px;
        }

        /* テーブル */
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 25px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        table.master-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        table.master-table th, table.master-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: middle;
        }
        table.master-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            white-space: nowrap;
        }
        table.master-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .input-text {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12.5px;
            background: #fff;
            box-sizing: border-box;
        }
        .input-text:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.2);
        }

        .btn-primary {
            background: #0284c7;
            color: #fff;
            border: none;
            padding: 9px 20px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: #0369a1; }

        .btn-test {
            background: #64748b;
            color: #fff;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-test:hover { background: #475569; }

        .btn-add-system {
            background: #10b981;
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-add-system:hover { background: #059669; }

        .badge-detected {
            display: inline-block;
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-missing {
            display: inline-block;
            background: #fee2e2;
            color: #991b1b;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        /* スイッチトグル */
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 22px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1; transition: .2s; border-radius: 22px;
        }
        .slider:before {
            position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px;
            background-color: white; transition: .2s; border-radius: 50%;
        }
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(22px); }
    </style>
</head>
<body <?= getBodyEnvClass() ?>>
<?= renderLocalEnvBanner() ?>

<div class="container">

    <!-- 3大マスタ統合ヘッダーナビゲーション -->
    <nav class="master-hub-nav" aria-label="マスタ管理ナビゲーション">
        <span class="master-hub-title">📋 院内マスタ管理:</span>
        <a href="slot_master_maintenance.php" class="hub-tab">⏱️ 検査枠マスタ</a>
        <a href="exam_master_maintenance.php" class="hub-tab">🔬 検査種別マスタ</a>
        <a href="master_maintenance.php" class="hub-tab">🔧 汎用選択肢マスタ</a>
        <a href="printer_master_maintenance.php" class="hub-tab active">🖨️ プリンターマスタ</a>
        <a href="system_info_maintenance.php" class="hub-tab">📢 修正インフォメーション</a>
        <a href="furiwake_daymemo.php" class="hub-tab">📝 日別メモ科振分</a>
        <a href="index.php" class="hub-tab home-link">🏠 ポータルへ</a>
    </nav>

    <h2>
        <span>🖨️ 院内プリンターマスターメンテナンス</span>
        <div class="header-actions">
            <a href="print_schedule_daily.php" target="_blank" class="btn-nav-action" title="日別印刷一覧画面を確認">📋 本日リスト印刷 ↗</a>
            <a href="print_nameplate.php" target="_blank" class="btn-nav-action" title="ネームプレート印刷画面を確認">🏷️ ネームプレート印刷 ↗</a>
            <a href="index.php" class="btn-nav-action">🏠 メニューへ</a>
        </div>
    </h2>

    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- 案内ガイドバナー -->
    <div class="info-card">
        <div class="info-card-title">💡 サーバー直接印刷（タブレット対応）プリンター設定について</div>
        <p>・<strong>有効スイッチ:</strong> ON にしたプリンターのみが、「本日リスト印刷」および「ネームプレート印刷」画面の「🖨️ 出力先:」選択肢に表示されます。</p>
        <p>・<strong>表示名称（ラベル）:</strong> 画面上でスタッフが見やすい名称（例: <code>Apeos C2570 (肛)</code>、<code>OKI B432 高速モノクロ</code> 等）を自由に変更できます。</p>
        <p>・<strong>システム登録名（実機名）:</strong> サーバー本体（Windows OS）の「プリンターとスキャナー」に登録されている名称です。SumatraPDF サイレント印刷がこのキューへ送信します。</p>
        <p>・<strong>デフォルト:</strong> ラジオボタンで指定したプリンターが、タブレット初回アクセス時の初期選択先になります。</p>
    </div>

    <!-- メイン編集フォーム -->
    <form method="POST" id="printerForm">
        <input type="hidden" name="action" value="save_printers">

        <h3 style="margin-bottom: 12px; font-size: 15px; color: #0f172a; display: flex; align-items: center; justify-content: space-between;">
            <span>📋 画面表示用 プリンター設定一覧</span>
            <span style="font-size: 12px; color: #64748b; font-weight: normal;">※ 行の追加・削除・名称変更後は下部の「保存」を押してください</span>
        </h3>

        <div class="table-responsive">
            <table class="master-table" id="configuredTable">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">既定</th>
                        <th style="width: 60px; text-align: center;">有効</th>
                        <th style="width: 250px;">画面表示名称 (ラベル)</th>
                        <th style="width: 260px;">システム登録名 (Windows側キュー名)</th>
                        <th style="width: 140px;">IP / ポート</th>
                        <th style="width: 160px;">設置場所・用途</th>
                        <th style="width: 90px; text-align: center;">OS検出状態</th>
                        <th style="width: 80px; text-align: center;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $printers = $current_config['printers'] ?? [];
                    $default_name = $current_config['default_printer'] ?? '';

                    foreach ($printers as $idx => $p): 
                        $p_name     = $p['name'] ?? '';
                        $p_label    = $p['label'] ?? $p_name;
                        $p_ip       = $p['ip'] ?? '';
                        $p_location = $p['location'] ?? '';
                        $p_active   = !isset($p['is_active']) || $p['is_active'];
                        $is_default = ($p_name === $default_name);
                        $is_detected = isset($system_printers_map[$p_name]);
                    ?>
                    <tr class="printer-row">
                        <td style="text-align: center;">
                            <input type="radio" name="default_printer" value="<?= htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8') ?>" <?= $is_default ? 'checked' : '' ?> title="デフォルトプリンタに指定">
                        </td>
                        <td style="text-align: center;">
                            <label class="switch" title="有効/無効切り替え">
                                <input type="checkbox" name="printer_active[<?= htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $p_active ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </td>
                        <td>
                            <input type="text" name="printer_label[]" value="<?= htmlspecialchars($p_label, ENT_QUOTES, 'UTF-8') ?>" class="input-text" placeholder="例: Apeos C2570 (肛) / おの複合機" required>
                        </td>
                        <td>
                            <input type="text" name="printer_name[]" value="<?= htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8') ?>" class="input-text" readonly style="background:#f1f5f9; font-family:monospace; font-size:12px; font-weight:bold;">
                        </td>
                        <td>
                            <input type="text" name="printer_ip[]" value="<?= htmlspecialchars($p_ip, ENT_QUOTES, 'UTF-8') ?>" class="input-text" placeholder="例: 192.168.1.195">
                        </td>
                        <td>
                            <input type="text" name="printer_location[]" value="<?= htmlspecialchars($p_location, ENT_QUOTES, 'UTF-8') ?>" class="input-text" placeholder="例: 処置室・内視鏡室">
                        </td>
                        <td style="text-align: center;">
                            <?php if ($is_detected): ?>
                                <span class="badge-detected" title="Windows OS上で正常に認識されています">✓ 認識中</span>
                            <?php else: ?>
                                <span class="badge-missing" title="現在Windows側に同名のキューが存在しません">⚠️ 未検出</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <div style="display:flex; gap:4px; justify-content:center;">
                                <button type="button" class="btn-test" onclick="testPrinterSend('<?= htmlspecialchars($p_name, ENT_QUOTES, 'UTF-8') ?>', this)">🧪 テスト</button>
                                <button type="button" class="btn-test" style="background:#ef4444;" onclick="removePrinterRow(this)" title="このプリンターを設定から削除">🗑️</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px;">
            <button type="submit" class="btn-primary">💾 プリンター設定を一括保存</button>
            <span style="color: #64748b; font-size: 12px;">設定保存先: <code>config/printers.json</code></span>
        </div>
    </form>

    <!-- サーバーOS検出プリンター一覧（候補からワンクリック追加） -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-top: 20px;">
        <h3 style="margin-bottom: 8px; font-size: 14.5px; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
            <span>🖥️ WebサーバーOS 検出プリンター一覧 (Get-Printer)</span>
            <button type="button" class="btn-nav-action" onclick="location.reload()">🔄 再検出・更新</button>
        </h3>
        <p style="font-size: 12px; color: #64748b; margin-bottom: 14px;">
            サーバーPC（Windows本体）にインストールされている実際のプリンターです。「➕ 選択肢に追加」を押すと上の設定テーブルに即座に追加できます。
        </p>

        <div class="table-responsive" style="margin-bottom: 0;">
            <table class="master-table">
                <thead>
                    <tr>
                        <th>Windows プリンター名</th>
                        <th>ポート / 接続先</th>
                        <th>ドライバ名</th>
                        <th style="width: 140px; text-align: center;">現在の登録状態</th>
                        <th style="width: 130px; text-align: center;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($detected_printers as $dp):
                        $d_name   = trim($dp['Name'] ?? '');
                        if ($d_name === '') continue;
                        $d_port   = trim($dp['PortName'] ?? '');
                        $d_driver = trim($dp['DriverName'] ?? '');
                        $is_configured = in_array($d_name, $configured_names, true);
                    ?>
                    <tr>
                        <td><strong style="font-family:monospace; font-size:12.5px;"><?= htmlspecialchars($d_name, ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td style="color:#64748b; font-size:12px;"><?= htmlspecialchars($d_port, ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="color:#64748b; font-size:12px;"><?= htmlspecialchars($d_driver, ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="text-align: center;">
                            <?php if ($is_configured): ?>
                                <span class="badge-detected">登録済み</span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:11.5px;">未追加</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if ($is_configured): ?>
                                <button type="button" class="btn-test" onclick="testPrinterSend('<?= htmlspecialchars($d_name, ENT_QUOTES, 'UTF-8') ?>', this)">🧪 テスト</button>
                            <?php else: ?>
                                <button type="button" class="btn-add-system" onclick="addSystemPrinter('<?= htmlspecialchars($d_name, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($d_port, ENT_QUOTES, 'UTF-8') ?>')">➕ 選択肢に追加</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
/**
 * サーバー検出プリンターを設定テーブルに行追加
 */
function addSystemPrinter(name, port) {
    const tableBody = document.querySelector('#configuredTable tbody');
    
    // 既存チェック
    const existingInputs = tableBody.querySelectorAll('input[name="printer_name[]"]');
    for (let inp of existingInputs) {
        if (inp.value === name) {
            alert('このプリンターは既に一覧に追加されています。');
            return;
        }
    }

    const tr = document.createElement('tr');
    tr.className = 'printer-row';
    tr.innerHTML = `
        <td style="text-align: center;">
            <input type="radio" name="default_printer" value="${name}">
        </td>
        <td style="text-align: center;">
            <label class="switch">
                <input type="checkbox" name="printer_active[${name}]" value="1" checked>
                <span class="slider"></span>
            </label>
        </td>
        <td>
            <input type="text" name="printer_label[]" value="${name}" class="input-text" placeholder="画面表示名称" required>
        </td>
        <td>
            <input type="text" name="printer_name[]" value="${name}" class="input-text" readonly style="background:#f1f5f9; font-family:monospace; font-size:12px; font-weight:bold;">
        </td>
        <td>
            <input type="text" name="printer_ip[]" value="${port.replace('IP_', '')}" class="input-text" placeholder="IPまたはポート">
        </td>
        <td>
            <input type="text" name="printer_location[]" value="" class="input-text" placeholder="例: 処置室">
        </td>
        <td style="text-align: center;">
            <span class="badge-detected">✓ 認識中</span>
        </td>
        <td style="text-align: center;">
            <div style="display:flex; gap:4px; justify-content:center;">
                <button type="button" class="btn-test" onclick="testPrinterSend('${name}', this)">🧪 テスト</button>
                <button type="button" class="btn-test" style="background:#ef4444;" onclick="removePrinterRow(this)">🗑️</button>
            </div>
        </td>
    `;

    tableBody.appendChild(tr);
    alert(`「${name}」を設定テーブルに追加しました。\n表示名称を入力後、最下部の「一括保存」を押してください。`);
    tr.scrollIntoView({ behavior: 'smooth' });
}

/**
 * 行削除
 */
function removePrinterRow(btn) {
    if (!confirm('このプリンターを設定一覧から削除しますか？\n（画面の選択肢から除外されます）')) {
        return;
    }
    const tr = btn.closest('tr');
    if (tr) tr.remove();
}

/**
 * テスト印刷API呼び出し (target=test_page 実印刷実行)
 */
function testPrinterSend(printerName, btnEl) {
    if (!printerName) return;
    
    const btn = btnEl || event.target;
    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ 送信中...';

    // 行からラベル名を取得
    let printerLabel = printerName;
    if (btn) {
        const tr = btn.closest('tr');
        if (tr) {
            const labelInput = tr.querySelector('input[name="printer_label[]"]');
            if (labelInput && labelInput.value.trim() !== '') {
                printerLabel = labelInput.value.trim();
            }
        }
    }

    const formData = new FormData();
    formData.append('action', 'print');
    formData.append('target', 'test_page');
    formData.append('printer_name', printerName);
    formData.append('printer_label', printerLabel);

    fetch('api_server_print.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(`【🖨️ テスト印刷送信完了】\n\n対象プリンター: ${data.printer_name} (${printerLabel})\n\nサーバーから印刷指示を送信しました。\nプリンターからテスト用紙が排出されるかご確認ください。`);
        } else {
            alert(`【❌ テスト印刷失敗】\n\n${data.message}`);
        }
    })
    .catch(err => {
        alert('通信エラー: ' + err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = origText;
    });
}
</script>

</body>
</html>
