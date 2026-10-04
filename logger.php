<?php
/**
 * デバッグモード判定 ＆ 共通ロガー
 */

// デバッグ設定ファイルのパス
function getDebugConfigPath() {
    return __DIR__ . '/debug_config.json';
}

// デバッグモードが有効かどうかを取得
function isDebugMode() {
    $configFile = getDebugConfigPath();
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true);
        return isset($config['debug_mode']) ? (bool)$config['debug_mode'] : false;
    }
    return false; // デフォルトはOFF
}

// デバッグモードの切替（ON/OFF）
function setDebugMode($bool) {
    $configFile = getDebugConfigPath();
    $config = ['debug_mode' => (bool)$bool];
    @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
}

// ログ出力関数（デバッグモードがONの時だけ書き込む）
function writeLog($tag, $message, $data = null) {
    if (!isDebugMode()) {
        return; // 通常モード（OFF）の時は何もしない
    }

    $logFile = __DIR__ . '/system_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$tag] $message";
    
    if ($data !== null) {
        $logMessage .= " | DATA: " . print_r($data, true);
    }
    
    @file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
}