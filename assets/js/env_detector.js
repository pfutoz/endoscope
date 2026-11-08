/**
 * 設置パス: c:\Apache24\htdocs\endoscope\assets\js\env_detector.js
 * ファイル名: env_detector.js
 * 生成日時: 2026-10-23 10:29:00 JST
 * 概要: ローカル/テスト環境（192.168.1.111 等）の自動検知＆誤操作防止UI注入スクリプト
 * 
 * 修正履歴:
 *   - 2026-10-23: 新規作成。192.168.1.111 / localhost を自動検知し、外側ブラック背景クラスと識別バナーを自動適用
 */
(function() {
    'use strict';

    function isLocalTestHost() {
        const host = (window.location.hostname || '').toLowerCase();
        return (
            host === '192.168.1.111' ||
            host === 'localhost' ||
            host === '127.0.0.1' ||
            host === '::1' ||
            host.endsWith('.local')
        );
    }

    if (!isLocalTestHost()) {
        return; // 本番環境では何もしない
    }

    // 1. 即座に html / body にクラスを付与（チラつき防止）
    if (document.documentElement) {
        document.documentElement.classList.add('is-local-test-env');
        document.documentElement.setAttribute('data-env', 'local');
    }

    function applyLocalEnvUI() {
        if (document.body) {
            document.body.classList.add('is-local-test-env');
        }

        // 2. 最上部バナーがまだ無ければ自動挿入
        if (!document.querySelector('.local-env-top-banner') && document.body) {
            const banner = document.createElement('div');
            banner.className = 'local-env-top-banner';
            banner.setAttribute('title', 'ローカル検証・テスト環境です。本番環境ではありません。');
            banner.innerHTML = '<span>🧪</span><span>【検証・テスト環境】 (Host: ' + escapeHtml(window.location.host) + ') ※本番環境ではありません</span>';
            document.body.insertBefore(banner, document.body.firstChild);
        }
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyLocalEnvUI);
    } else {
        applyLocalEnvUI();
    }
})();
