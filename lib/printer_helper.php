<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\printer_helper.php
 * ファイル名: printer_helper.php
 * 概要: 院内プリンター設定管理・システム検出・選択肢生成共通ヘルパー
 * 生成日時: 2026-10-30 13:15
 * 修正履歴:
 *   - 2026-10-30: 【新規作成: プリンターマスター管理ヘルパー】
 *       1. config/printers.json の読み込み・保存
 *       2. 印刷画面（本日一覧・ネームプレート）用 <option> 自動レンダリング
 *       3. Windows OS にインストールされている物理/仮想プリンタの自動検出（Get-Printer）
 */

/**
 * 現在のプリンター設定を取得
 */
function getPrinterConfig(): array {
    $file = __DIR__ . '/../config/printers.json';
    if (file_exists($file)) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['printers'])) {
            return $data;
        }
    }
    return [
        'default_printer' => 'おの_C2570 複合機',
        'printers' => [
            [
                'name' => 'おの_C2570 複合機',
                'label' => 'Apeos C2570 (肛) / おの複合機',
                'ip' => '192.168.1.195',
                'location' => '処置室・内視鏡室',
                'is_active' => true
            ],
            [
                'name' => 'クリニック_C2570複合機',
                'label' => 'Apeos C2570 (胃) / クリニック複合機',
                'ip' => '192.168.1.196',
                'location' => '受付・クリニック',
                'is_active' => true
            ],
            [
                'name' => 'OKI B432(PCL6)',
                'label' => 'OKI B432 高速モノクロ',
                'ip' => '192.168.1.197',
                'location' => '処置室',
                'is_active' => true
            ]
        ]
    ];
}

/**
 * プリンター設定を保存
 */
function savePrinterConfig(array $config): bool {
    $dir = __DIR__ . '/../config';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . '/printers.json';
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($file, $json) !== false;
}

/**
 * 印刷画面（print_schedule_daily / print_nameplate）のセレクトボックス用オプションを動的生成
 */
function renderPrinterSelectOptions(?string $selectedValue = null): string {
    $config = getPrinterConfig();
    $html = '<option value="local">💻 この端末から印刷 (通常)</option>' . "\n";

    foreach ($config['printers'] ?? [] as $p) {
        // 非アクティブなプリンタは選択肢から除外
        if (isset($p['is_active']) && !$p['is_active']) {
            continue;
        }
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') continue;

        $label = trim((string)($p['label'] ?? $name));
        $selected = ($selectedValue === $name) ? ' selected' : '';

        $nameEsc  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        $html .= "<option value=\"{$nameEsc}\"{$selected}>🏢 {$labelEsc}</option>\n";
    }

    return $html;
}

/**
 * Windows サーバー本体に実際にインストールされているプリンタ一覧を検出
 */
function getSystemInstalledPrinters(): array {
    $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; Get-Printer | Select-Object Name, PortName, DriverName, Default | ConvertTo-Json"';
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);

    if ($ret === 0 && !empty($output)) {
        $json = implode("\n", $output);
        $data = json_decode($json, true);
        if (is_array($data)) {
            // 単一プリンタのみの場合は連想配列になるため配列化
            if (isset($data['Name'])) {
                $data = [$data];
            }
            return $data;
        }
    }
    return [];
}
