<?php
/**
 * 医療法人小野会 本番サーバー ヘルス情報 API エンドポイント
 * 設置先: c:\Apache24\htdocs\deploy_manager\server_agent\server_health_api.php
 */
ini_set('display_errors', "0");
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

// 院内ローカルネットワークまたはローカル端末限定
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_allowed = in_array($remote_ip, ['127.0.0.1', '::1', 'localhost'], true) 
    || strpos($remote_ip, '192.168.') === 0 
    || strpos($remote_ip, '10.') === 0;

if (!$is_allowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'アクセス権限がありません: ' . $remote_ip], JSON_UNESCAPED_UNICODE);
    exit;
}

$htdocs_dir = 'c:/Apache24/htdocs/';
$json_path = $htdocs_dir . '_server_health.json';
$ps_script = __DIR__ . '/collect_server_health.ps1';

$force_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$need_collect = false;

if (!file_exists($json_path) || $force_refresh) {
    $need_collect = true;
} else {
    $mtime = filemtime($json_path);
    // 10分以上古い場合はオンデマンド再収集
    if ((time() - $mtime) > 600) {
        $need_collect = true;
    }
}

if ($need_collect && file_exists($ps_script)) {
    // PowerShell スクリプトを実行して更新
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . $ps_script . '"';
    @shell_exec($cmd);
}

if (file_exists($json_path)) {
    $content = file_get_contents($json_path);
    $data = json_decode($content, true);
    if ($data) {
        $data['source'] = 'file';
        $data['fileMtime'] = date('Y-m-d H:i:s', filemtime($json_path));
        $data['fileAgeSec'] = time() - filemtime($json_path);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// まだファイルが生成できていない場合のレスポンス
echo json_encode([
    'version' => '1.0',
    'status' => 'pending',
    'message' => 'ヘルス情報は初回生成中です。タスクスケジューラまたは管理者権限でのスクリプト実行をお待ちください。',
    'webServer' => [
        'hasService' => true,
        'serviceStatus' => 'Running',
        'port80Listening' => true,
        'phpVersion' => PHP_VERSION
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
