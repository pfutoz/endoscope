<?php
/**
 * lib/calendar_helper.php - カレンダー関連関数（高速化版）
 * 修正: 2026-08-28（枠数0かつ予約あり時の異常値検知・残数-1等の赤枠強調対応）
 */

require_once __DIR__ . '/slot_helper.php';
require_once __DIR__ . '/patient_helper.php';

/**
 * インメモリ枠数判定（SQL発行ゼロ）
 */
function getDoctorStatusFromCacheMatrix(string $doctor_name, string $date_str, int $w, array $master_cache): array {
    // 1. 出勤例外 (m2_doctor_attendance)
    if (isset($master_cache['attendance'][$date_str][$doctor_name])) {
        $data = $master_cache['attendance'][$date_str][$doctor_name];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots
        ];
    }

    // 2. 日別上書き (m2_doctor_daily_capacity_v2)
    if (isset($master_cache['daily_capacity'][$date_str][$doctor_name])) {
        $data = $master_cache['daily_capacity'][$date_str][$doctor_name];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots
        ];
    }

    // 3. 曜日パターン (m2_doctor_slot_pattern)
    if (isset($master_cache['patterns'][$w][$doctor_name])) {
        $slots = $master_cache['patterns'][$w][$doctor_name];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots
        ];
    }

    // 4. デフォルト（0枠・出勤なし）
    return [
        'present' => false,
        'slots' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0]
    ];
}

/**
 * 日付枠用枠使用状況のインメモリ高速集計（定員0かつ予約ありも異常として収集）
 */
function getDayDataForCalendarFast(string $dept, string $date_str, int $w, array $doctors, array $reserved_cache, array $master_cache): array {
    $result = [];
    $total_used = 0;
    $total_max = 0;
    $gif_group = ['GIF', '◆胃がん検診◆', '胃がん検診'];
    $echo_group = ['ECHO', 'エコー'];
    $tcf_group = ['TCF', 'W', 'ポリペク'];
    
    $day_cache = $reserved_cache[$date_str] ?? [];

    foreach ($doctors as $doctor_name) {
        $status = getDoctorStatusFromCacheMatrix($doctor_name, $date_str, $w, $master_cache);
        $doc_exams = $day_cache[$doctor_name] ?? [];
        
        // 予約件数の集計
        $used_gif = 0;
        foreach ($gif_group as $g_exam) { $used_gif += (int)($doc_exams[$g_exam] ?? 0); }
        $used_echo = 0;
        foreach ($echo_group as $e_exam) { $used_echo += (int)($doc_exams[$e_exam] ?? 0); }
        $used_tcf = 0;
        foreach ($tcf_group as $t_exam) { $used_tcf += (int)($doc_exams[$t_exam] ?? 0); }
        
        $has_reservation = ($used_gif > 0 || $used_echo > 0 || $used_tcf > 0);
        
        // 出勤あり、または出勤なしでも予約が存在する場合は表示対象とする
        if (!$status['present'] && !$has_reservation) {
            continue;
        }
        
        $doctor_data = ['name' => $doctor_name, 'slots' => []];
        $has_any_slot = false;
        
        // GIF
        $max_gif = (int)($status['slots']['GIF'] ?? 0);
        if ($max_gif > 0 || $used_gif > 0) {
            $doctor_data['slots']['GIF'] = ['used' => $used_gif, 'max' => $max_gif];
            $total_used += $used_gif;
            $total_max += $max_gif;
            $has_any_slot = true;
        }
        
        // ECHO
        $max_echo = (int)($status['slots']['ECHO'] ?? 0);
        if ($max_echo > 0 || $used_echo > 0) {
            $doctor_data['slots']['ECHO'] = ['used' => $used_echo, 'max' => $max_echo];
            $total_used += $used_echo;
            $total_max += $max_echo;
            $has_any_slot = true;
        }
        
        // TCF
        $max_tcf = (int)($status['slots']['TCF'] ?? 0);
        if ($max_tcf > 0 || $used_tcf > 0) {
            $doctor_data['slots']['TCF'] = ['used' => $used_tcf, 'max' => $max_tcf];
            $total_used += $used_tcf;
            $total_max += $max_tcf;
            $has_any_slot = true;
        }
        
        if ($has_any_slot) {
            $result[] = $doctor_data;
        }
    }
    
    return ['doctors' => $result, 'total_used' => $total_used, 'total_max' => $total_max];
}

/**
 * 日付枠セルを高速レンダリング（異常値は赤枠・マイナス表示）
 */
function renderDateCellFast(string $date_str, string $dept, int $w, array $doctors, array $reserved_cache, array $master_cache, array $week_ja, bool $simple_mode = false, bool $show_unassigned = true, string $display_mode = 'use_max') {
    $ts = strtotime($date_str);
    $day = (int)date('j', $ts);
    $month = (int)date('n', $ts);
    $year = (int)date('Y', $ts);
    $today_str = date('Y-m-d');
    
    $day_check = check_onokai_calendar($date_str, null);
    $is_closed = $day_check['is_closed'];
    $is_today = ($date_str === $today_str);
    $is_past = ($date_str < $today_str);
    
    $has_reservation_on_closed = false;
    if ($is_closed && isset($reserved_cache[$date_str]) && is_array($reserved_cache[$date_str])) {
        $count = 0;
        foreach ($reserved_cache[$date_str] as $doc_counts) {
            $count += is_array($doc_counts) ? array_sum($doc_counts) : 0;
        }
        $has_reservation_on_closed = ($count > 0);
    }

    $day_data = getDayDataForCalendarFast($dept, $date_str, $w, $doctors, $reserved_cache, $master_cache);
    
    // 超過・空き枠判定（max=0でused>0の場合もhas-negativeをtrueにする）
    $has_available = false;
    $has_negative = false;
    if (!$is_closed) {
        if ($day_data['total_max'] > 0) {
            $has_available = ($day_data['total_used'] < $day_data['total_max']);
        }
        foreach ($day_data['doctors'] as $d) {
            foreach (['GIF', 'ECHO', 'TCF'] as $exam) {
                if (isset($d['slots'][$exam])) {
                    if ((int)$d['slots'][$exam]['used'] > (int)$d['slots'][$exam]['max']) {
                        $has_negative = true;
                        break 2;
                    }
                }
            }
        }
    }

    $cell_class = 'fixed-date-col';
    if ($is_today) $cell_class .= ' today';
    if ($is_closed) $cell_class .= ' closed';
    if ($is_past) $cell_class .= ' past';
    if ($has_negative && !$is_closed) $cell_class .= ' has-negative';
    if ($has_available && !$is_past && !$is_closed) $cell_class .= ' has-available';
    if ($has_reservation_on_closed) $cell_class .= ' has-reservation-on-closed';
    
    $click_url = "./month_schedule.php?dept={$dept}&year={$year}&month={$month}&date={$date_str}";
    
    echo "<td class='{$cell_class}' data-date='{$date_str}' data-dept='{$dept}' onclick=\"location.href='{$click_url}'\">";
    
    $weekday_style = '';
    if (!$is_closed) {
        if ($w === 4) {
            $weekday_style = 'color: #1e40af;';
        } elseif ($w === 0) {
            $weekday_style = 'color: #ef4444;';
        }
    }
    
    $day_memo = trim((string)($master_cache['daily_memo'][$date_str] ?? ''));
    $memo_badge_html = '';
    if ($day_memo !== '') {
        $memo_title = htmlspecialchars($day_memo, ENT_QUOTES, 'UTF-8');
        $memo_badge_html = "<span class='day-memo-badge' title='【日別カレンダーメモ】{$memo_title}'>📝 {$memo_title}</span>";
    }

    echo "<div style='font-weight:bold; font-size:12px; text-align:center; display:flex; align-items:center; justify-content:center; gap:2px; flex-wrap:wrap;'>";
    echo "<span>{$month}/{$day} <span style='{$weekday_style}'>({$week_ja[$w]})</span></span>{$memo_badge_html}";
    echo "</div>";
    
    if ($is_closed) {
        echo "<div style='font-size:11px; font-weight:bold; color:#ef4444; text-align:center; margin-top:4px;'>⛔ 休診</div>";
        echo "</td>";
        return;
    }
    
    if (!empty($day_data['doctors'])) {
        echo "<div style='font-size:9px; line-height:1.4; text-align:left; padding-left:2px; margin-top:2px;'>";
        
        foreach ($day_data['doctors'] as $d) {
            echo "<div style='margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>";
            echo "<span style='font-weight:bold; color:#1e293b;'>" . htmlspecialchars((string)$d['name'], ENT_QUOTES, 'UTF-8') . "</span> ";
            
            foreach (['GIF', 'ECHO', 'TCF'] as $exam) {
                if (isset($d['slots'][$exam])) {
                    $used = (int)$d['slots'][$exam]['used'];
                    $max = (int)$d['slots'][$exam]['max'];
                    
                    if ($max > 0 || $used > 0) {
                        $is_neg = ($used > $max);
                        
                        if ($display_mode === 'use_max') {
                            $color = ($is_neg || $used >= $max) ? '#dc2626' : '#2563eb';
                            if ($is_neg) {
                                echo "<span style='border:1.5px solid #dc2626; border-radius:2px; padding:0 2px; background-color:#fee2e2; font-weight:bold; color:#dc2626; font-size:8px; line-height:1.2; display:inline-block; margin-right:1px;'>" . getExamIcon($exam) . "{$used}/{$max}</span> ";
                            } else {
                                echo "<span style='color:{$color}; font-weight:600; display:inline-block; margin-right:1px;'>" . getExamIcon($exam) . "{$used}/{$max}</span> ";
                            }
                        } else {
                            $remain = $max - $used;
                            $color = ($is_neg || $remain === 0) ? '#dc2626' : ($remain <= 2 ? '#f59e0b' : '#22c55e');
                            if ($is_neg) {
                                echo "<span style='border:1.5px solid #dc2626; border-radius:2px; padding:0 2px; background-color:#fee2e2; font-weight:bold; color:#dc2626; font-size:8px; line-height:1.2; display:inline-block; margin-right:1px;'>" . getExamIcon($exam) . "{$remain}</span> ";
                            } else {
                                echo "<span style='color:{$color}; font-weight:600; display:inline-block; margin-right:1px;'>" . getExamIcon($exam) . "{$remain}</span> ";
                            }
                        }
                    }
                }
            }
            echo "</div>";
        }
        
        // 全体合計表示
        if (count($day_data['doctors']) >= 2) {
            $total_gif_used = 0; $total_gif_max = 0;
            $total_echo_used = 0; $total_echo_max = 0;
            $total_tcf_used = 0; $total_tcf_max = 0;
            
            foreach ($day_data['doctors'] as $d) {
                if (isset($d['slots']['GIF'])) {
                    $total_gif_used += (int)$d['slots']['GIF']['used'];
                    $total_gif_max += (int)$d['slots']['GIF']['max'];
                }
                if (isset($d['slots']['ECHO'])) {
                    $total_echo_used += (int)$d['slots']['ECHO']['used'];
                    $total_echo_max += (int)$d['slots']['ECHO']['max'];
                }
                if (isset($d['slots']['TCF'])) {
                    $total_tcf_used += (int)$d['slots']['TCF']['used'];
                    $total_tcf_max += (int)$d['slots']['TCF']['max'];
                }
            }
            
            $overall_parts = [];
            if ($display_mode === 'use_max') {
                if ($total_gif_max > 0 || $total_gif_used > 0) $overall_parts[] = '🔵' . $total_gif_used . '/' . $total_gif_max;
                if ($total_echo_max > 0 || $total_echo_used > 0) $overall_parts[] = '🟠' . $total_echo_used . '/' . $total_echo_max;
                if ($total_tcf_max > 0 || $total_tcf_used > 0) $overall_parts[] = '🟢' . $total_tcf_used . '/' . $total_tcf_max;
            } else {
                if ($total_gif_max > 0 || $total_gif_used > 0) $overall_parts[] = '🔵' . ($total_gif_max - $total_gif_used);
                if ($total_echo_max > 0 || $total_echo_used > 0) $overall_parts[] = '🟠' . ($total_echo_max - $total_echo_used);
                if ($total_tcf_max > 0 || $total_tcf_used > 0) $overall_parts[] = '🟢' . ($total_tcf_max - $total_tcf_used);
            }
            
            if (!empty($overall_parts)) {
                echo "<div style='font-size:8px; font-weight:bold; margin-top:2px; text-align:center; padding:1px 3px; border-radius:8px; color:#22c55e; background:#dcfce7;'>全体 " . implode(' ', $overall_parts) . "</div>";
            }
        }
        
        echo "</div>";
    } else {
        echo "<div style='font-size:8px; color:#94a3b8; text-align:center; padding-top:10px;'>-</div>";
    }
    
    echo "</td>";
}