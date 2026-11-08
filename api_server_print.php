<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\api_server_print.php
 * ファイル名: api_server_print.php
 * 概要: サーバー直接印刷（リモート印刷）バックエンドAPI
 *       タブレット端末や院内端末から指示されたプリンタへ、Edge headless PDF生成 ＋ SumatraPDF により直接印刷ジョブを送信
 * 生成日時: 2026-10-30 11:15
 * 更新日時: 2026-11-06 11:20
 * 修正履歴:
 *   - 2026-11-06: 【内視鏡シート（target=endoscopy_sheet）サーバー直接印刷 ＆ 画面入力状態引き渡し対応】
 *       1. target=endoscopy_sheet を追加し、患者ごとの内視鏡シート（endoscopy_sheet.php?schedule_id=...）のEdge Headless PDF生成・SumatraPDF直接印刷に対応
 *       2. タブレット等で画面入力したチェック・丸囲み・テキスト状態（sheet_state JSON）を scratch/print_jobs/{jobId}_state.json に一時保存し、state_job パラメータ経由で印刷レンダリングへ完全反映する機構を実装（印刷完了後に一時ファイルを自動削除）
 *   - 2026-10-30: 【新規作成: サーバー直接印刷API】
 *       1. action=list: 院内の利用可能プリンタ一覧（printers.json）を返却
 *       2. action=print: 指定の画面（ネームプレート / 本日リスト）をPDF化し、指定プリンタへサイレント印刷
 *       3. test_mode=1 に対応（実印刷を行わずPDF生成およびコマンド妥当性の検証が可能）
 */

ini_set('display_errors', "0");
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'print';

// 1. プリンタ一覧取得
if ($action === 'list') {
    $configFile = __DIR__ . '/config/printers.json';
    if (file_exists($configFile)) {
        $json = file_get_contents($configFile);
        $data = json_decode($json, true);
        if (is_array($data)) {
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // デフォルトフォールバック
    echo json_encode([
        'success' => true,
        'data' => [
            'default_printer' => 'おの_C2570 複合機',
            'printers' => [
                ['name' => 'おの_C2570 複合機', 'label' => 'おの_C2570 複合機 (192.168.1.195)'],
                ['name' => 'クリニック_C2570複合機', 'label' => 'クリニック_C2570 複合機 (192.168.1.196)'],
                ['name' => 'OKI B432(PCL6)', 'label' => 'OKI B432 高速モノクロ (192.168.1.197)']
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. 印刷実行
if ($action === 'print') {
    $target        = trim($_POST['target'] ?? $_GET['target'] ?? 'nameplate');
    $printer_name  = trim($_POST['printer_name'] ?? $_GET['printer_name'] ?? '');
    $printer_label = trim($_POST['printer_label'] ?? $_GET['printer_label'] ?? $printer_name);
    $date_str      = trim($_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'));
    $dept          = trim($_POST['dept'] ?? $_GET['dept'] ?? 'ALL');
    $sort_by       = trim($_POST['sort'] ?? $_GET['sort'] ?? 'room_asc');
    $page_split    = trim($_POST['page_split'] ?? $_GET['page_split'] ?? '1');
    $show_empty    = trim($_POST['show_empty'] ?? $_GET['show_empty'] ?? '0');
    $font          = trim($_POST['font'] ?? $_GET['font'] ?? 'biz-ud');
    $dry_run       = (isset($_POST['dry_run']) && $_POST['dry_run'] === '1') || (isset($_GET['dry_run']) && $_GET['dry_run'] === '1');

    // 自動テスト・検証スクリプトの後方互換: test_mode=1 かつ (simulate=1 または dry_run=1) の場合はシミュレーション
    if ((isset($_POST['test_mode']) && $_POST['test_mode'] === '1') || (isset($_GET['test_mode']) && $_GET['test_mode'] === '1')) {
        if ((isset($_POST['simulate']) && $_POST['simulate'] === '1') || (isset($_GET['simulate']) && $_GET['simulate'] === '1')) {
            $dry_run = true;
        }
    }

    if ($printer_name === '') {
        // 設定ファイルからデフォルトプリンタを取得
        $configFile = __DIR__ . '/config/printers.json';
        if (file_exists($configFile)) {
            $conf = json_decode(file_get_contents($configFile), true);
            $printer_name = $conf['default_printer'] ?? 'おの_C2570 複合機';
        } else {
            $printer_name = 'おの_C2570 複合機';
        }
    }

    // PDF一時保存ディレクトリ
    $jobDir = __DIR__ . '/scratch/print_jobs';
    if (!is_dir($jobDir)) {
        @mkdir($jobDir, 0777, true);
    }

    $jobId   = date('Ymd_His_') . substr(md5(uniqid((string)mt_rand(), true)), 0, 6);
    $pdfPath = $jobDir . '/' . $jobId . '.pdf';

    // 対象URLの組み立て
    $baseUrl = 'http://localhost/endoscope/';
    if ($target === 'test_page') {
        // 1枚もののテスト用紙HTMLを直接生成
        $testHtml = '<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>プリンター接続テスト</title>
<style>
  @page { size: A4 portrait; margin: 15mm; }
  body { font-family: "BIZ UDPGothic", "Meiryo", "Yu Gothic", sans-serif; color: #0f172a; margin: 0; padding: 20px; }
  .test-card { border: 3px double #0284c7; border-radius: 12px; padding: 36px 30px; text-align: center; background: #ffffff; }
  .title { font-size: 24px; font-weight: bold; color: #0369a1; margin-bottom: 8px; }
  .subtitle { font-size: 14px; color: #64748b; margin-bottom: 24px; }
  .badge { display: inline-block; background: #ecfdf5; color: #059669; padding: 6px 16px; border-radius: 20px; font-weight: bold; border: 1px solid #a7f3d0; font-size: 14px; margin-bottom: 24px; }
  .info-table { margin: 0 auto 30px; border-collapse: collapse; width: 90%; max-width: 500px; text-align: left; font-size: 13.5px; }
  .info-table th, .info-table td { padding: 10px 14px; border: 1px solid #cbd5e1; }
  .info-table th { background: #f8fafc; color: #475569; width: 35%; font-weight: bold; }
  .notice { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 14px; font-size: 12.5px; color: #0369a1; line-height: 1.6; margin: 0 auto 20px; max-width: 500px; text-align: left; }
  .footer { font-size: 11.5px; color: #94a3b8; }
</style>
</head>
<body>
  <div class="test-card">
    <div class="title">🖨️ 院内プリンター接続テスト印刷</div>
    <div class="subtitle">医療法人小野会 内視鏡予約管理システム</div>
    <div><span class="badge">✓ 正常疎通テスト用サンプル</span></div>
    
    <table class="info-table">
      <tr><th>プリンター名</th><td><strong>' . htmlspecialchars($printer_name, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>
      <tr><th>表示名称 (呼称)</th><td>' . htmlspecialchars($printer_label, ENT_QUOTES, 'UTF-8') . '</td></tr>
      <tr><th>送信日時</th><td>' . date('Y年m月d日 H:i:s') . '</td></tr>
      <tr><th>送信エンジン</th><td>Windows Web Server (Edge headless + SumatraPDF)</td></tr>
    </table>

    <div class="notice">
      <strong>【テスト結果のご確認】</strong><br>
      このテスト用紙が正常に排出されていれば、サーバー直接印刷（タブレットからのワンタップ印刷）の通信およびWindowsスプーラー連携はすべて正常に動作しています。<br>
      日別予約リスト印刷およびネームプレート印刷をご利用いただけます。
    </div>

    <div class="footer">Printed by Endoscopy Reservation Management System &copy; Medical Corporation Onokai</div>
  </div>
</body>
</html>';

        $testHtmlPath = $jobDir . '/' . $jobId . '_test.html';
        file_put_contents($testHtmlPath, $testHtml);
        $targetUrl = 'file:///' . str_replace('\\', '/', $testHtmlPath);
        $jobTitle  = "プリンター接続テスト ({$printer_name})";
    } elseif ($target === 'nameplate') {
        $targetUrl = $baseUrl . 'print_nameplate.php?date=' . urlencode($date_str) . '&dept=' . urlencode($dept) . '&sort=' . urlencode($sort_by) . '&font=' . urlencode($font);
        $jobTitle  = "ネームプレート ({$date_str})";
    } elseif ($target === 'endoscopy_sheet') {
        $schedule_id = (int)($_POST['schedule_id'] ?? $_GET['schedule_id'] ?? 0);
        $patient_name_param = trim((string)($_POST['patient_name'] ?? $_GET['patient_name'] ?? ''));
        $sheet_state_raw = (string)($_POST['sheet_state'] ?? $_GET['sheet_state'] ?? '');
        $stateParam = '';
        if ($sheet_state_raw !== '') {
            $stateFilePath = $jobDir . '/' . $jobId . '_state.json';
            file_put_contents($stateFilePath, $sheet_state_raw);
            $stateParam = '&state_job=' . urlencode($jobId);
        }
        $targetUrl = $baseUrl . 'endoscopy_sheet.php?schedule_id=' . $schedule_id . $stateParam;
        $jobTitle  = ($patient_name_param !== '') ? "内視鏡シート ({$patient_name_param} 様)" : "内視鏡シート (#{$schedule_id})";
    } else {
        $targetUrl = $baseUrl . 'print_schedule_daily.php?date=' . urlencode($date_str) . '&dept=' . urlencode($dept) . '&sort=' . urlencode($sort_by) . '&page_split=' . urlencode($page_split) . '&show_empty=' . urlencode($show_empty);
        $jobTitle  = "本日リスト ({$date_str})";
    }

    // Edgeのパス
    $edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
    if (!file_exists($edgePath)) {
        $edgePath = 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe';
    }
    if (!file_exists($edgePath)) {
        $edgePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    }

    if (!file_exists($edgePath)) {
        echo json_encode([
            'success' => false,
            'message' => 'PDFレンダリングエンジン (Edge/Chrome) が見つかりませんでした。'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // EdgeでPDF生成
    $edgeCmd = sprintf(
        '"%s" --headless=new --disable-gpu --print-to-pdf="%s" --no-pdf-header-footer "%s"',
        $edgePath,
        $pdfPath,
        $targetUrl
    );

    exec($edgeCmd, $edgeOutput, $edgeReturnCode);

    // 最大5秒待機（生成待ち）
    $waited = 0;
    while (!file_exists($pdfPath) && $waited < 10) {
        usleep(500000); // 0.5s
        $waited++;
    }

    if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'PDFの生成に失敗しました。',
            'command' => $edgeCmd,
            'code'    => $edgeReturnCode
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdfSize = filesize($pdfPath);

    // ドライラン（シミュレーション）の場合は印刷実行せず返却
    if ($dry_run) {
        echo json_encode([
            'success'      => true,
            'dry_run'      => true,
            'message'      => "【ドライラン成功】PDF生成完了（{$pdfSize} bytes）。プリンタ「{$printer_name}」への送信コマンド準備完了。",
            'printer_name' => $printer_name,
            'target'       => $target,
            'pdf_path'     => $pdfPath,
            'pdf_size'     => $pdfSize
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // SumatraPDFで指定プリンタへ送信
    $sumatraPath = __DIR__ . '/bin/SumatraPDF.exe';
    if (!file_exists($sumatraPath)) {
        echo json_encode([
            'success' => false,
            'message' => '印刷実行モジュール (SumatraPDF.exe) が見つかりませんでした。'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $safePrinterName = str_replace('"', '\"', $printer_name);
    $printCmd = sprintf(
        '"%s" -silent -print-to "%s" -print-settings "noscale" "%s"',
        $sumatraPath,
        $safePrinterName,
        $pdfPath
    );

    exec($printCmd, $printOutput, $printReturnCode);

    if ($printReturnCode !== 0) {
        echo json_encode([
            'success' => false,
            'message' => "プリンター「{$printer_name}」への送信中にエラーが発生しました（終了コード: {$printReturnCode}）。",
            'command' => $printCmd,
            'output'  => $printOutput
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $successMsg = ($target === 'test_page')
        ? "プリンター「{$printer_name}」へテスト印刷ジョブを送信しました。用紙が排出されるかご確認ください。"
        : "プリンタ「{$printer_name}」へ印刷指示を送信しました（{$jobTitle}）。";

    // 成功レスポンス
    echo json_encode([
        'success'      => true,
        'message'      => $successMsg,
        'printer_name' => $printer_name,
        'target'       => $target,
        'pdf_size'     => $pdfSize,
        'job_id'       => $jobId
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => '不正なリクエストアクションです。'], JSON_UNESCAPED_UNICODE);
