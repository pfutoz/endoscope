<?php
/**
 * ==============================================================================
 * 医療法人小野会 クライアント端末 ヘルスレポーティング受信API
 * 設置先: /ip/api_report_health.php
 * ==============================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    // GETリクエストで最新レポート一覧の取得
    $recordsDir = __DIR__ . '/health_records';
    $results = [];
    if (is_dir($recordsDir)) {
        foreach (glob($recordsDir . '/*.json') as $f) {
            $ipKey = basename($f, '.json');
            $c = @file_get_contents($f);
            if ($c) {
                $j = json_decode($c, true);
                if ($j) {
                    $results[$ipKey] = [
                        'mtime' => filemtime($f),
                        'ageSec' => time() - filemtime($f),
                        'data' => $j
                    ];
                }
            }
        }
    }
    echo json_encode(['success' => true, 'count' => count($results), 'reports' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$data = json_decode($rawInput, true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '不正なJSONフォーマットです'], JSON_UNESCAPED_UNICODE);
    exit;
}

// クライアントのIPアドレスを特定 (ペイロード内 または REMOTE_ADDR)
$clientIp = trim((string)($data['ip'] ?? ''));
if (empty($clientIp) || !filter_var($clientIp, FILTER_VALIDATE_IP)) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

$recordsDir = __DIR__ . '/health_records';
if (!is_dir($recordsDir)) {
    @mkdir($recordsDir, 0777, true);
}

// 端末固有のJSONファイルとして保存 (例: health_records/192.168.1.50.json)
$safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $clientIp);
$targetFile = $recordsDir . '/' . $safeFilename . '.json';
$data['receivedAt'] = date('Y-m-d H:i:s');
$data['remoteAddr'] = $_SERVER['REMOTE_ADDR'] ?? '';

@file_put_contents($targetFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// data.json のインベントリ台帳を自動同期
$dataFile = __DIR__ . '/data.json';
$updatedRecord = false;

if (file_exists($dataFile)) {
    $rawLedger = @file_get_contents($dataFile);
    if ($rawLedger) {
        $ledger = json_decode($rawLedger, true);
        if (is_array($ledger)) {
            $mac = strtoupper(trim((string)($data['mac'] ?? '')));
            $host = strtoupper(trim((string)($data['computerName'] ?? '')));

            foreach ($ledger as &$item) {
                $itemIp = trim((string)($item['ip'] ?? ''));
                $itemMac = strtoupper(trim((string)($item['mac_address_1'] ?? '')));
                $itemHost = strtoupper(trim((string)($item['hostname'] ?? '')));

                $match = false;
                if ($itemIp === $clientIp) {
                    $match = true;
                } elseif (!empty($mac) && !empty($itemMac) && $mac === $itemMac) {
                    $match = true;
                } elseif (!empty($host) && !empty($itemHost) && $host === $itemHost) {
                    $match = true;
                }

                if ($match) {
                    // スペック情報の自動反映
                    if (!empty($data['storage']['volumes'][0]['totalGB'])) {
                        $item['c_drive_total'] = $data['storage']['volumes'][0]['totalGB'] . ' GB';
                    }
                    if (!empty($data['storage']['volumes'][0]['freeGB'])) {
                        $item['c_drive_free'] = $data['storage']['volumes'][0]['freeGB'] . ' GB';
                    }
                    if (!empty($data['resources']['memory']['totalGB'])) {
                        $item['memory_gb'] = $data['resources']['memory']['totalGB'] . ' GB';
                    }
                    if (!empty($data['resources']['cpu']['name'])) {
                        $item['cpu_name'] = $data['resources']['cpu']['name'];
                    }
                    if (!empty($data['host']['os'])) {
                        $item['os_version'] = $data['host']['os'];
                    }
                    if (!empty($data['mac']) && empty($item['mac_address_1'])) {
                        $item['mac_address_1'] = $data['mac'];
                    }
                    if (!empty($data['computerName']) && empty($item['hostname'])) {
                        $item['hostname'] = $data['computerName'];
                    }

                    $item['ping_status'] = 'ON';
                    $item['collected_at'] = date('Y/m/d H:i:s');
                    $updatedRecord = true;
                    break;
                }
            }
            unset($item);

            if ($updatedRecord) {
                @file_put_contents($dataFile, json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => 'ヘルスレポートを正常に受信・記録しました',
    'ip' => $clientIp,
    'ledgerUpdated' => $updatedRecord
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
