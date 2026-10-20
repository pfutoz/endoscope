<?php
/**
 * 医療法人小野会 クライアント監視エージェント ZIPダウンロード
 * 設置先: /ip/download_agent.php
 */
declare(strict_types=1);

$zipPath = __DIR__ . '/client_health_agent.zip';

if (!file_exists($zipPath)) {
    // ZIPが存在しない場合は client_agent/ から自動圧縮生成を試行
    $srcDir = __DIR__ . '/client_agent';
    if (is_dir($srcDir)) {
        @shell_exec('powershell -NoProfile -Command "Compress-Archive -Path \'' . $srcDir . '\*\' -DestinationPath \'' . $zipPath . '\' -Force"');
    }
}

if (!file_exists($zipPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "エラー: client_health_agent.zip が準備できていません。管理者にお問い合わせください。";
    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="onokai_client_health_agent.zip"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($zipPath);
exit;
