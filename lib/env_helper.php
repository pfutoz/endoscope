<?php
/**
 * 生成日時: 2026-10-23 10:28:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\env_helper.php
 * ファイル名: env_helper.php
 * 
 * 環境判定ヘルパー（本番環境 / ローカル検証環境）
 * 192.168.1.111 や localhost などのテスト環境を自動判定し、
 * 本番との誤操作・誤更新を防止するためのクラス・バナーを提供する
 * 
 * 修正履歴:
 *   - 2026-10-23: 新規作成。192.168.1.111 等のローカル環境誤操作防止（黒背景・環境識別）に対応
 */

/**
 * 現在のアクセスがローカル/テスト環境（192.168.1.111 等）であるかを判定
 * 
 * @return bool ローカル/テスト環境なら true
 */
function isLocalTestEnvironment(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostname = strtolower(explode(':', $host)[0]);

    // 判定対象ホスト
    $local_hosts = [
        '192.168.1.111',
        'localhost',
        '127.0.0.1',
        '::1',
    ];
    if (in_array($hostname, $local_hosts, true)) {
        return true;
    }
    if (substr($hostname, -6) === '.local') {
        return true;
    }

    $server_ip = $_SERVER['SERVER_ADDR'] ?? '';
    if (in_array($server_ip, ['192.168.1.111', '127.0.0.1'], true)) {
        return true;
    }

    return false;
}

/**
 * <body> タグに付与する環境クラス文字列を返却
 * 
 * @param string $additional_classes 追加クラス（任意）
 * @return string
 */
function getBodyEnvClass(string $additional_classes = ''): string {
    $classes = array_filter(explode(' ', trim($additional_classes)));
    if (isLocalTestEnvironment()) {
        $classes[] = 'is-local-test-env';
    }
    return implode(' ', $classes);
}

/**
 * ローカル検証環境用の識別バナーHTMLを生成
 * 
 * @return string
 */
function renderLocalEnvBanner(): string {
    if (!isLocalTestEnvironment()) {
        return '';
    }
    $host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? '192.168.1.111', ENT_QUOTES, 'UTF-8');
    return '<div class="local-env-top-banner" title="テスト・検証環境です。本番DBではありません。">'
        . '<span>🧪</span>'
        . '<span>【検証・テスト環境】(HOST: ' . $host . ') ※本番環境ではありません</span>'
        . '</div>';
}
