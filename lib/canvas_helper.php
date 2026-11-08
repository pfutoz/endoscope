<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\canvas_helper.php
 * ファイル名: canvas_helper.php
 * 概要: RS-Base 検査タイムライン・キャンバス (canvas_view.php) 外部連携ヘルパー
 * 生成日時: 2026-10-24 23:05:00 JST
 * 修正履歴:
 *   - 2026-10-24: 新規作成。旧フォローアップ管理の刷新に伴い、RS-Base検査タイムライン・キャンバス連携を共通化。
 *                 検査種別(exam_type)からのモダリティ判定、キャンバス起動URL生成、ボタンHTML出力を提供。
 *   - 2026-10-25: 最新検査数リミット(limit=5)の自動付与に対応。グリッド折り返し配置と連動し、カードの縮小・文字豆粒化を防止。
 */

if (!function_exists('getCanvasModality')) {
    /**
     * 内視鏡システムの検査種別文字列から RS-Base canvas_view.php 用の modality コードを判定
     *
     * @param string $exam_type 検査種別 (例: GIF, TCF, ECHO, エコー, ポリペク, W 等)
     * @return string ES (内視鏡) / US (超音波) / ECG (心電図) / XP (胸部XP) / ALL (全件)
     */
    function getCanvasModality(string $exam_type): string {
        $type = trim($exam_type);
        if ($type === '') {
            return 'ES';
        }

        // 全検査 (ALL)
        if (strcasecmp($type, 'ALL') === 0 || $type === '全' || $type === '全て') {
            return 'ALL';
        }

        // 超音波 / エコー
        if (
            stripos($type, 'echo') !== false ||
            stripos($type, 'エコー') !== false ||
            strcasecmp($type, 'US') === 0
        ) {
            return 'US';
        }

        // 心電図
        if (
            stripos($type, 'ecg') !== false ||
            stripos($type, '心電図') !== false
        ) {
            return 'ECG';
        }

        // レントゲン
        if (
            stripos($type, 'xp') !== false ||
            stripos($type, 'レントゲン') !== false ||
            stripos($type, 'x線') !== false ||
            stripos($type, '胸部') !== false
        ) {
            return 'XP';
        }

        // 内視鏡（GIF, TCF, W, ポリペク, ESD, 胃カメラ, 大腸カメラ等）
        return 'ES';
    }
}

if (!function_exists('getCanvasUrl')) {
    /**
     * RS-Base canvas_view.php の起動URLを生成（相対パス）
     *
     * @param string $patient_no 患者番号 (カルテNo)
     * @param string $exam_type 検査種別またはモダリティコード
     * @param string $mode 表示モード ('', 'embed', 'minimal', 'modal')
     * @param int $limit 表示件数上限（既定: 5件。0の場合は全件）
     * @return string canvas_view.php 相対URL
     */
    function getCanvasUrl(string $patient_no, string $exam_type = 'ES', string $mode = '', int $limit = 5): string {
        $p_no = trim($patient_no);
        if ($p_no === '' || $p_no === '---') {
            return '#';
        }

        $modality = getCanvasModality($exam_type);
        $params = [
            'patient_no' => $p_no,
            'modality'   => $modality,
        ];

        if ($limit > 0) {
            $params['limit'] = $limit;
        }

        if ($mode !== '') {
            $params['mode'] = $mode;
        }

        // 実行階層に応じた相対パス解決（直下なら ../rsbase/、サブディレクトリなら ../../rsbase/）
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        $prefix = (strpos($script_dir, '/followup') !== false) ? '../../rsbase/' : '../rsbase/';

        return $prefix . 'canvas_view.php?' . http_build_query($params);
    }
}

if (!function_exists('renderCanvasButtonHTML')) {
    /**
     * 検査タイムライン・キャンバス起動ボタンのHTMLを生成
     *
     * @param string $patient_no 患者番号
     * @param string $exam_type 検査種別
     * @param string $label ボタン文言（既定: '🗺️ 実績'）
     * @param string $extra_class 追加CSSクラス
     * @return string HTML
     */
    function renderCanvasButtonHTML(string $patient_no, string $exam_type = 'ES', string $label = '🗺️ 実績', string $extra_class = ''): string {
        $url = getCanvasUrl($patient_no, $exam_type);
        if ($url === '#') {
            return '<span style="font-size:8px; color:#cbd5e1;">-</span>';
        }

        $p_escaped = htmlspecialchars($patient_no, ENT_QUOTES, 'UTF-8');
        $modality = getCanvasModality($exam_type);
        $title = "患者No.{$p_escaped} の検査実績タイムライン・キャンバスを開く (モダリティ: {$modality})";

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" class="sheet-btn-small btn-canvas-timeline %s" title="%s" style="background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:2px; font-weight:700;">%s</a>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($extra_class, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }
}
