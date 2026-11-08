<?php
/**
 * 医療法人小野会 専用 カレンダー・休診判定ヘルパー（共通API連携ラッパー版）
 * 設置パス: /endoscope/calendar_helper_onokai.php
 * 
 * 💡【小野会共通APIプラットフォーム統合 (2026-11-04)】
 * コア判定ロジックおよびスタイル生成・コントロール生成は、共通基盤
 *   C:/Apache24/htdocs/api/v1/calendar/helper.php
 * へ集約・移譲されました。
 * 
 * 本ファイルは内視鏡システム内の全呼び出し元（slot_search, month_schedule, 
 * schedule_note, action_slot_assistant 等）との完全な後方互換性を保証する透過的ラッパーとして動作します。
 */

$common_helper_path = dirname(__DIR__) . '/api/v1/calendar/helper.php';

if (file_exists($common_helper_path)) {
    require_once $common_helper_path;
} else {
    // 共通API未配置環境（単体検証環境等）向け安全フォールバック
    if (!function_exists('check_onokai_calendar')) {
        function check_onokai_calendar($date_str, $waku_time = null) {
            $ts = strtotime($date_str);
            $w  = (int)date('w', $ts);
            $md = date('m-d', $ts);
            $res = ['is_holiday' => false, 'is_closed' => false, 'reason' => ''];
            if ($w === 0) { $res['is_closed'] = true; $res['reason'] = '日祝休診'; return $res; }
            if (in_array($md, ['12-31', '01-01', '01-02', '01-03'], true)) { $res['is_closed'] = true; $res['reason'] = '年末年始休診'; return $res; }
            return $res;
        }
    }
    if (!function_exists('get_onokai_cell_style')) {
        function get_onokai_cell_style($date_str, $waku_time = null) {
            return ['bg' => '#ffffff', 'fg' => '#333333', 'css' => 'background-color:#ffffff;color:#333333;'];
        }
    }
    if (!function_exists('render_onokai_date_control')) {
        function render_onokai_date_control($id, $name, $value = '', $options = []) {
            return "<input type='text' id='{$id}' name='{$name}' value='{$value}'>";
        }
    }
    if (!function_exists('getRoomBadgeClass')) {
        function getRoomBadgeClass(string $roomName): string {
            return 'room-other';
        }
    }
}