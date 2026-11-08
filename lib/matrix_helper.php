<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\matrix_helper.php
 * ファイル名: matrix_helper.php
 * 概要: マトリクス描画関数（高速化版・コンパクト全表示モード短縮ヘッダー対応）
 * 更新日時: 2026-11-07
 * 修正履歴:
 *   - 2026-08-28: ステップ2（renderUnassignedBadgeHTML共通利用）
 *   - 2026-11-07: 新世代（2027年〜）の枠数拡大（TCF9枠化等）に向け、コンパクト全表示モード用の短縮枠ヘッダー（.slot-label-short）出力を追加
 */

require_once __DIR__ . '/slot_helper.php';
require_once __DIR__ . '/calendar_helper.php';
require_once __DIR__ . '/patient_helper.php';

/**
 * コンパクト全表示モード向けに時間枠名を短縮表記へ変換
 * 例: "TCF13:30①" -> "13:30①", "GIF9:00" -> "G9:00", "エコー9:00" -> "US9:00"
 */
if (!function_exists('formatCompactSlotHeader')) {
    function formatCompactSlotHeader(string $waku_name): string {
        $s = trim($waku_name);
        if (preg_match('/^TCF\s*(.+)$/iu', $s, $m)) {
            $rest = $m[1];
            // "13:30~14:30" のような範囲表記は開始時刻+"~"に短縮
            $rest = preg_replace('/^(\d{1,2}:\d{2})\s*[~〜\-]\s*\d{1,2}:\d{2}$/u', '$1~', $rest);
            return $rest;
        }
        if (preg_match('/^GIF\s*(.+)$/iu', $s, $m)) {
            return 'G' . $m[1];
        }
        if (preg_match('/^(?:エコー|ECHO)\s*(.+)$/iu', $s, $m)) {
            return 'US' . $m[1];
        }
        $s = preg_replace('/^(\d{1,2}:\d{2})\s*[~〜\-]\s*\d{1,2}:\d{2}$/u', '$1~', $s);
        return $s;
    }
}

/**
 * 月次マトリクスを高速レンダリング（SQL発行ゼロ）
 */
function renderMonthMatrixFast($pdo, $year, $month, $dept, $waku_list, $month_matrix, $week_ja, $show_canceled, $show_invalid, $show_unassigned, $simple_mode, $doctors_list, $reserved_cache, $master_cache) {
    $days_in_month = (int)date('t', strtotime("$year-$month-01"));
    $current_date_str = date('Y-m-d');
    
    $display_mode = isset($_GET['display_mode']) && is_string($_GET['display_mode']) ? $_GET['display_mode'] : 'use_max';
    
    echo "<div class='month-section'>";
    echo "<div class='month-header'>📅 {$year}年 {$month}月";
    
    echo '<span style="float:right; font-size:11px; font-weight:normal;">';
    echo '表示: ';
    $modes = [
        'use_max' => '使用/最大',
        'remain' => '残数'
    ];
    foreach ($modes as $key => $label) {
        $active = ($display_mode === $key) ? ' style="background:#3b82f6; color:#fff; border-radius:3px; padding:1px 8px;"' : ' style="padding:1px 8px;"';
        echo '<a href="?dept=' . $dept . '&year=' . $year . '&month=' . $month . '&display_mode=' . $key . '"' . $active . '>' . $label . '</a> ';
    }
    echo '</span>';
    
    echo "</div>";
    echo "<table class='matrix-table'>";
    echo "<thead><tr class='header-row'>";
    echo "<th class='fixed-date-col'>日付 / 曜日</th>";
    foreach ($waku_list as $w) {
        $w_full = htmlspecialchars((string)$w['waku_name'], ENT_QUOTES, 'UTF-8');
        $w_short = htmlspecialchars(formatCompactSlotHeader((string)$w['waku_name']), ENT_QUOTES, 'UTF-8');
        $g_code = htmlspecialchars((string)$w['group_code'], ENT_QUOTES, 'UTF-8');
        echo "<th class='slot-header' data-group='{$g_code}' title='{$w_full}'><span class='slot-label-full'>{$w_full}</span><span class='slot-label-short'>{$w_short}</span></th>";
    }
    if ($show_invalid) {
        echo "<th class='invalid-cell slot-header' data-group='MISMATCH'>🚨 迷子</th>";
    }
    echo "</tr></thead><tbody>";
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $ts = strtotime($date_str);
        $w = (int)date('w', $ts);
        $day_check = check_onokai_calendar($date_str, null);
        $is_closed = $day_check['is_closed'];
        $is_today = ($date_str === $current_date_str);
        $is_past = ($date_str < $current_date_str);
        $tr_id = $is_today ? ' id="row-today"' : '';
        
        $row_style = '';
        if ($is_closed) { $row_style = 'background-color:#fef2f2 !important;'; }
        elseif ($is_today) { $row_style = 'background-color:#ffffe0 !important;'; }
        elseif ($is_past) { $row_style = 'background-color:#f1f5f9 !important; opacity:0.7;'; }

        // 当該日の医師別枠数・残数をインメモリ算出（案C: 空きCF枠ガイド・定員到達グレーアウト ＆ 案D: 医師指名逆引きフォーカス用）
        $day_doc_info = (!$is_closed) ? getDayDataForCalendarFast($dept, $date_str, $w, $doctors_list, $reserved_cache, $master_cache) : ['doctors' => [], 'total_used' => 0, 'total_max' => 0];
        $total_tcf_max = 0;
        $total_tcf_used = 0;
        $avail_tcf_docs = [];
        $avail_tcf_short_labels = [];
        $avail_any_docs = [];
        $present_docs = [];

        foreach (($day_doc_info['doctors'] ?? []) as $d_item) {
            $d_name = (string)($d_item['name'] ?? '');
            if ($d_name === '') continue;
            $present_docs[] = $d_name;
            $d_short = mb_substr($d_name, 0, 1);

            $t_max = (int)($d_item['slots']['TCF']['max'] ?? 0);
            $t_used = (int)($d_item['slots']['TCF']['used'] ?? 0);
            $t_rem = $t_max - $t_used;
            $total_tcf_max += $t_max;
            $total_tcf_used += $t_used;

            $g_max = (int)($d_item['slots']['GIF']['max'] ?? 0);
            $g_used = (int)($d_item['slots']['GIF']['used'] ?? 0);
            $g_rem = $g_max - $g_used;

            if ($t_rem > 0) {
                $avail_tcf_docs[] = $d_name;
                $avail_tcf_short_labels[] = "{$d_short}{$t_rem}";
            }
            if ($t_rem > 0 || $g_rem > 0) {
                $avail_any_docs[] = $d_name;
            }
        }
        $total_tcf_rem = $total_tcf_max - $total_tcf_used;
        $is_tcf_limit_reached = (!$is_closed && $total_tcf_max > 0 && $total_tcf_rem <= 0);

        $attr_avail_tcf = htmlspecialchars(implode(',', $avail_tcf_docs), ENT_QUOTES, 'UTF-8');
        $attr_avail_any = htmlspecialchars(implode(',', $avail_any_docs), ENT_QUOTES, 'UTF-8');
        $attr_present   = htmlspecialchars(implode(',', $present_docs), ENT_QUOTES, 'UTF-8');
        
        echo "<tr class='matrix-day-row' style='{$row_style}'{$tr_id} data-date='{$date_str}' data-avail-tcf-docs='{$attr_avail_tcf}' data-avail-any-docs='{$attr_avail_any}' data-present-docs='{$attr_present}'>";
        
        renderDateCellFast($date_str, $dept, $w, $doctors_list, $reserved_cache, $master_cache, $week_ja, $simple_mode, $show_unassigned, $display_mode);
        
        foreach ($waku_list as $w) {
            $waku_name = $w['waku_name'];
            $group_code = $w['group_code'];
            
            // 当該日の有効枠情報（オーバーライド・休止）を参照
            $effective_list = $master_cache['effective_waku_map'][$date_str] ?? [];
            $slot_info = findEffectiveWaku($effective_list, $waku_name);
            $is_slot_suspended = !empty($slot_info['is_suspended']);
            $is_slot_override = !empty($slot_info['is_override']);
            $override_reason = $slot_info['reason'] ?? '';

            $has_cell_reservations = (isset($month_matrix[$date_str][$waku_name]) && is_array($month_matrix[$date_str][$waku_name]) && !empty($month_matrix[$date_str][$waku_name]));
            $is_empty_tcf_over_limit = ($group_code === 'TCF' && !$has_cell_reservations && !$is_slot_suspended && !$is_closed && !$is_past && $is_tcf_limit_reached);

            $cell_style = '';
            if ($is_closed) { $cell_style = 'background-color:#fef2f2 !important;'; }
            elseif ($is_slot_suspended) { $cell_style = 'background-color:#fee2e2 !important; opacity:0.85;'; }
            elseif ($is_today) { $cell_style = 'background-color:#ffffe0 !important;'; }
            elseif ($is_past) { $cell_style = 'background-color:#f1f5f9 !important; opacity:0.7;'; }
            elseif ($is_empty_tcf_over_limit) {
                $cell_style = 'background: repeating-linear-gradient(-45deg, #f1f5f9, #f1f5f9 5px, #e2e8f0 5px, #e2e8f0 10px) !important;';
            } else {
                $color_map = ['GIF' => '#dbeafe', 'ECHO' => '#fce7f3', 'TCF' => '#d1fae5'];
                $cell_style = 'background-color:' . ($color_map[$group_code] ?? '#ffffff') . ' !important;';
            }
            
            $has_duplicate = false;
            $has_out_of_slot = false;
            if ($has_cell_reservations) {
                foreach ($month_matrix[$date_str][$waku_name] as $d) {
                    if (($d['booking_status'] ?? 'normal') === 'duplicate') { $has_duplicate = true; }
                    if (($d['booking_status'] ?? 'normal') === 'out_of_slot') { $has_out_of_slot = true; }
                }
            }
            if ($has_duplicate) { $cell_style = 'background-color:#fef2f2 !important; border:2px solid #dc2626 !important;'; }
            elseif ($has_out_of_slot) { $cell_style = 'background-color:#ffedd5 !important; border:2px solid #f59e0b !important;'; }
            
            $suspended_cls = $is_slot_suspended ? ' slot-suspended' : '';
            $limit_cls = $is_empty_tcf_over_limit ? ' slot-doctor-limit-reached' : '';
            echo "<td class='waku-cell " . ($is_past && !$is_today ? 'slot-past' : '') . " " . ($has_duplicate ? 'slot-duplicate' : '') . " " . ($has_out_of_slot ? 'slot-out-of-slot' : '') . "{$suspended_cls}{$limit_cls}' style='{$cell_style}' data-date='{$date_str}' data-waku='{$waku_name}' data-group='{$group_code}' onclick='openInsertModal(this, event)' ondragover='allowDrop(event)' ondragenter='dragEnter(this)' ondragleave='dragLeave(this)' ondrop='dropWaku(this, event)'>";
            
            if (!$is_closed) {
                if ($is_slot_suspended) {
                    $reason_disp = $override_reason ? htmlspecialchars($override_reason, ENT_QUOTES, 'UTF-8') : '休止';
                    echo "<div class='suspended-slot-badge' style='background:#fee2e2; color:#b91c1c; border:1px dashed #ef4444; border-radius:3px; padding:1px 3px; font-size:10px; margin-bottom:3px; text-align:center; font-weight:bold;'>🚫 休止 [{$reason_disp}]</div>";
                }
                if ($has_cell_reservations) {
                    foreach ($month_matrix[$date_str][$waku_name] as $data) {
                        renderPatientBox($data, $date_str, '', $simple_mode);
                    }
                } elseif (!$is_slot_suspended && !$is_past && $group_code === 'TCF') {
                    if ($is_tcf_limit_reached) {
                        echo "<div class='doc-limit-reached-guide' style='pointer-events:none; color:#64748b; font-size:8.5px; text-align:center; padding-top:16px; font-weight:700;'>🈵医師定員</div>";
                    } elseif (!empty($avail_tcf_short_labels)) {
                        $guide_txt = htmlspecialchars(implode('・', $avail_tcf_short_labels), ENT_QUOTES, 'UTF-8');
                        echo "<div class='avail-doc-guide' style='pointer-events:none; color:#047857; opacity:0.65; font-size:8.5px; text-align:center; padding-top:16px; font-weight:700;'>残:{$guide_txt}</div>";
                    }
                }
                
                if ($show_unassigned) {
                    $unassigned_data = [];
                    if (!empty($month_matrix[$date_str]) && is_array($month_matrix[$date_str])) {
                        foreach ($month_matrix[$date_str] as $waku => $rows) {
                            if ($waku === 'MISMATCH') continue;
                            if (!is_array($rows)) continue;
                            foreach ($rows as $row) {
                                if (empty($row['doctor_name']) || $row['doctor_name'] === '') {
                                    $unassigned_data[] = $row;
                                }
                            }
                        }
                    }
                    if (!empty($unassigned_data)) {
                        echo renderUnassignedBadgeHTML($unassigned_data);
                    }
                }
            }
            
            if ($is_past && !$is_today && !isset($month_matrix[$date_str][$waku_name])) {
                echo '<div style="color:#94a3b8; font-size:9px; text-align:center; padding-top:20px;">-</div>';
            }
            echo "</td>";
        }
        
        if ($show_invalid) {
            echo "<td class='waku-cell invalid-cell' data-date='{$date_str}' data-waku='MISMATCH' data-group='MISMATCH' onclick='openInsertModal(this, event)' ondragover='allowDrop(event)' ondragenter='dragEnter(this)' ondragleave='dragLeave(this)' ondrop='dropWaku(this, event)'>";
            if (!$is_closed && isset($month_matrix[$date_str]['MISMATCH']) && is_array($month_matrix[$date_str]['MISMATCH'])) {
                foreach ($month_matrix[$date_str]['MISMATCH'] as $data) {
                    renderPatientBox($data, $date_str, '🚨[迷子] ', $simple_mode);
                }
            }
            echo "</td>";
        }
        
        echo "</tr>";
    }
    
    echo "</tbody></table></div>";
}