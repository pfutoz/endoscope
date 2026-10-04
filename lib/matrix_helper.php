<?php
/**
 * lib/matrix_helper.php - マトリクス描画関数（高速化版）
 * 修正: 2026-08-28 ステップ2（renderUnassignedBadgeHTML共通利用）
 */

require_once __DIR__ . '/slot_helper.php';
require_once __DIR__ . '/calendar_helper.php';
require_once __DIR__ . '/patient_helper.php';

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
        echo "<th class='slot-header' data-group='{$w['group_code']}'>{$w['waku_name']}</th>";
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
        
        echo "<tr style='{$row_style}'{$tr_id}>";
        
        renderDateCellFast($date_str, $dept, $w, $doctors_list, $reserved_cache, $master_cache, $week_ja, $simple_mode, $show_unassigned, $display_mode);
        
        foreach ($waku_list as $w) {
            $waku_name = $w['waku_name'];
            $group_code = $w['group_code'];
            
            $cell_style = '';
            if ($is_closed) { $cell_style = 'background-color:#fef2f2 !important;'; }
            elseif ($is_today) { $cell_style = 'background-color:#ffffe0 !important;'; }
            elseif ($is_past) { $cell_style = 'background-color:#f1f5f9 !important; opacity:0.7;'; }
            else {
                $color_map = ['GIF' => '#dbeafe', 'ECHO' => '#fce7f3', 'TCF' => '#d1fae5'];
                $cell_style = 'background-color:' . ($color_map[$group_code] ?? '#ffffff') . ' !important;';
            }
            
            $has_duplicate = false;
            $has_out_of_slot = false;
            if (isset($month_matrix[$date_str][$waku_name]) && is_array($month_matrix[$date_str][$waku_name])) {
                foreach ($month_matrix[$date_str][$waku_name] as $d) {
                    if (($d['booking_status'] ?? 'normal') === 'duplicate') { $has_duplicate = true; }
                    if (($d['booking_status'] ?? 'normal') === 'out_of_slot') { $has_out_of_slot = true; }
                }
            }
            if ($has_duplicate) { $cell_style = 'background-color:#fef2f2 !important; border:2px solid #dc2626 !important;'; }
            elseif ($has_out_of_slot) { $cell_style = 'background-color:#ffedd5 !important; border:2px solid #f59e0b !important;'; }
            
            echo "<td class='waku-cell " . ($is_past && !$is_today ? 'slot-past' : '') . " " . ($has_duplicate ? 'slot-duplicate' : '') . " " . ($has_out_of_slot ? 'slot-out-of-slot' : '') . "' style='{$cell_style}' data-date='{$date_str}' data-waku='{$waku_name}' data-group='{$group_code}' onclick='openInsertModal(this, event)' ondragover='allowDrop(event)' ondragenter='dragEnter(this)' ondragleave='dragLeave(this)' ondrop='dropWaku(this, event)'>";
            
            if (!$is_closed) {
                if (isset($month_matrix[$date_str][$waku_name]) && is_array($month_matrix[$date_str][$waku_name])) {
                    foreach ($month_matrix[$date_str][$waku_name] as $data) {
                        renderPatientBox($data, $date_str, '', $simple_mode);
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