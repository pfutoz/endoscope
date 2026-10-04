<?php
/**
 * 生成日時: 2026-07-29
 * 胃腸科 枠数表示テスト（午前/午後分離）
 * 7/19〜7/25 の胃腸科の枠状況を表示
 * 午前: GIF + エコー / 午後: TCF
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// データベース接続
$host = 'localhost';
$dbname = 'endoscope';
$user = 'postgres';
$password = 'postgres';

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

// 小野会カレンダーヘルパー
require_once 'calendar_helper_onokai.php';

// ============================================================
// コア関数
// ============================================================

function getDoctorStatus($pdo, $doctor_name, $department_code, $date_str) {
    $day_of_week = (int)date('w', strtotime($date_str));
    
    $stmt = $pdo->prepare("
        SELECT pattern_name, reason 
        FROM m_doctor_attendance 
        WHERE doctor_name = :doctor_name 
          AND department_code = :dept 
          AND target_date = :date
    ");
    $stmt->execute([
        ':doctor_name' => $doctor_name,
        ':dept' => $department_code,
        ':date' => $date_str
    ]);
    $exception = $stmt->fetch();
    
    $pattern_name = $exception ? $exception['pattern_name'] : '通常';
    $reason = $exception ? $exception['reason'] : null;
    
    $stmt = $pdo->prepare("
        SELECT slot_type, max_slots
        FROM m_doctor_slot_pattern
        WHERE doctor_name = :doctor_name
          AND department_code = :dept
          AND pattern_name = :pattern
          AND day_of_week = :dow
          AND is_active = TRUE
    ");
    $stmt->execute([
        ':doctor_name' => $doctor_name,
        ':dept' => $department_code,
        ':pattern' => $pattern_name,
        ':dow' => $day_of_week
    ]);
    $rows = $stmt->fetchAll();
    
    $slots = [];
    foreach ($rows as $row) {
        $slots[$row['slot_type']] = (int)$row['max_slots'];
    }
    
    $is_present = !empty($slots) && array_sum($slots) > 0;
    
    return [
        'present' => $is_present,
        'pattern_name' => $pattern_name,
        'reason' => $reason,
        'slots' => $slots,
        'is_exception' => ($exception !== null)
    ];
}

function getSlotAvailability($pdo, $department_code, $date_str) {
    $day_check = check_onokai_calendar($date_str, null);
    $is_closed = $day_check['is_closed'];
    $closed_reason = $day_check['reason'] ?? '';
    
    $stmt = $pdo->prepare("
        SELECT doctor_name 
        FROM m_doctor 
        WHERE department_code = :dept AND is_active = TRUE
        ORDER BY display_order
    ");
    $stmt->execute([':dept' => $department_code]);
    $all_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $result = [
        'total' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0],
        'doctors' => [],
        'present_doctors' => [],
        'is_closed' => $is_closed,
        'closed_reason' => $closed_reason,
        'am_total' => 0,  // 午前合計（GIF + エコー）
        'pm_total' => 0,  // 午後合計（TCF）
    ];
    
    if ($is_closed) {
        return $result;
    }
    
    foreach ($all_doctors as $doctor_name) {
        $status = getDoctorStatus($pdo, $doctor_name, $department_code, $date_str);
        $result['doctors'][$doctor_name] = $status;
        
        if ($status['present']) {
            $result['present_doctors'][] = $doctor_name;
            foreach ($status['slots'] as $type => $count) {
                $result['total'][$type] += $count;
                if ($type === 'GIF' || $type === 'ECHO') {
                    $result['am_total'] += $count;
                } elseif ($type === 'TCF') {
                    $result['pm_total'] += $count;
                }
            }
        }
    }
    
    return $result;
}

// ============================================================
// メイン処理
// ============================================================

$start_date = '2026-07-19';
$end_date = '2026-07-25';
$week_ja = ['日', '月', '火', '水', '木', '金', '土'];

// 胃腸科だけ
$dept_code = 'GASTRO';
$dept_name = '胃腸科';

$dates = [];
for ($d = strtotime($start_date); $d <= strtotime($end_date); $d = strtotime('+1 day', $d)) {
    $dates[] = date('Y-m-d', $d);
}

// 枠の色
$slot_colors = [
    'GIF' => '#dbeafe',
    'ECHO' => '#fce7f3',
    'TCF' => '#d1fae5'
];
$slot_labels = [
    'GIF' => 'GIF',
    'ECHO' => 'エコー',
    'TCF' => 'TCF'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>胃腸科 枠数表示テスト（7/19〜7/25）</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #333;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #34495e;
            padding-bottom: 10px;
        }
        .dept-section {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .dept-title {
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 15px 0;
            padding: 8px 16px;
            border-radius: 4px;
            display: inline-block;
            background: #d1fae5;
            color: #065f46;
        }
        
        .slot-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .slot-table th,
        .slot-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: center;
            vertical-align: middle;
        }
        .slot-table th {
            background: #f1f5f9;
            font-weight: bold;
            color: #334155;
        }
        .slot-table .date-cell {
            font-weight: bold;
            background: #f8fafc;
            min-width: 70px;
            font-size: 14px;
            white-space: nowrap;
        }
        .slot-table .date-cell .weekday {
            font-weight: normal;
            font-size: 11px;
            color: #64748b;
        }
        .slot-table .date-cell .closed-label {
            font-size: 11px;
            color: #ef4444;
            font-weight: bold;
        }
        
        /* 午前・午後ラベル */
        .period-label {
            font-weight: bold;
            font-size: 13px;
            background: #f8fafc;
        }
        .period-label.am {
            background: #f0f7ff;
            color: #1e40af;
        }
        .period-label.pm {
            background: #f5f5f5;
            color: #475569;
        }
        
        /* 枠セル */
        .slot-cell {
            padding: 6px 8px !important;
            min-width: 55px;
            font-weight: bold;
            font-size: 12px;
            border-radius: 4px;
            text-align: center;
        }
        .slot-cell .count {
            font-size: 15px;
            display: block;
        }
        .slot-cell .label {
            font-size: 9px;
            opacity: 0.7;
        }
        
        .slot-empty {
            color: #94a3b8;
            font-size: 12px;
        }
        
        .doctor-name {
            font-weight: bold;
            font-size: 12px;
            white-space: nowrap;
        }
        .doctor-name .absent {
            color: #ef4444;
            text-decoration: line-through;
        }
        .doctor-name .present {
            color: #22c55e;
        }
        .doctor-name .exception {
            color: #f59e0b;
            font-size: 10px;
            font-weight: normal;
        }
        
        .exception-reason {
            font-size: 10px;
            color: #f59e0b;
            background: #fffbeb;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .total-row td {
            font-weight: bold;
            background: #f8fafc;
            border-top: 2px solid #94a3b8;
            font-size: 15px;
        }
        .total-row .total-label {
            text-align: right;
            padding-right: 15px;
            color: #334155;
        }
        .total-row .total-am {
            background: #f0f7ff;
            color: #1e40af;
        }
        .total-row .total-pm {
            background: #f5f5f5;
            color: #065f46;
        }
        
        .legend {
            display: flex;
            gap: 20px;
            margin: 15px 0 10px 0;
            font-size: 12px;
            flex-wrap: wrap;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .legend-color {
            width: 30px;
            height: 24px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        .legend-color.gif { background: #dbeafe; }
        .legend-color.echo { background: #fce7f3; }
        .legend-color.tcf { background: #d1fae5; }
        .legend-color.am-bg { background: #f0f7ff; border: 2px solid #1e40af; }
        .legend-color.pm-bg { background: #f5f5f5; border: 2px solid #475569; }
        
        .note {
            font-size: 12px;
            color: #64748b;
            margin-top: 10px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }
        .note strong {
            color: #333;
        }
    </style>
</head>
<body>

<h1>📊 胃腸科 枠数表示テスト（2026年7月19日〜25日）</h1>

<div class="legend">
    <span class="legend-item"><span class="legend-color gif"></span> GIF（午前）</span>
    <span class="legend-item"><span class="legend-color echo"></span> エコー（午前）</span>
    <span class="legend-item"><span class="legend-color tcf"></span> TCF（午後）</span>
    <span class="legend-item"><span class="legend-color am-bg"></span> 午前エリア</span>
    <span class="legend-item"><span class="legend-color pm-bg"></span> 午後エリア</span>
    <span class="legend-item">✅ 出勤 / ❌ 休診 / 🔵 例外</span>
</div>

<div class="dept-section">
    <div class="dept-title">⚪ 胃腸科</div>
    
    <table class="slot-table">
        <thead>
            <tr>
                <th style="min-width:70px;">日付</th>
                <th style="min-width:90px;">医師</th>
                <th colspan="100" style="background:#f0f7ff; color:#1e40af; font-size:13px;">
                    🕐 午前（GIF + エコー）
                </th>
                <th colspan="100" style="background:#f5f5f5; color:#475569; font-size:13px;">
                    🕐 午後（TCF）
                </th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dates as $date_str): 
                $ts = strtotime($date_str);
                $day = date('j', $ts);
                $w = date('w', $ts);
                $weekday = $week_ja[$w];
                $is_today = ($date_str === date('Y-m-d'));
                
                $slot_info = getSlotAvailability($pdo, $dept_code, $date_str);
                
                if (empty($slot_info['doctors'])) {
                    continue;
                }
                
                $rowspan = count($slot_info['doctors']);
                $first = true;
                
                foreach ($slot_info['doctors'] as $doctor_name => $status):
                    $gif = $status['slots']['GIF'] ?? 0;
                    $echo = $status['slots']['ECHO'] ?? 0;
                    $tcf = $status['slots']['TCF'] ?? 0;
                    
                    // 午前枠（GIF + エコー）を配列に
                    $am_slots = [];
                    for ($i = 0; $i < $gif; $i++) { $am_slots[] = 'GIF'; }
                    for ($i = 0; $i < $echo; $i++) { $am_slots[] = 'ECHO'; }
                    
                    // 午後枠（TCF）
                    $pm_slots = [];
                    for ($i = 0; $i < $tcf; $i++) { $pm_slots[] = 'TCF'; }
            ?>
                <tr style="<?= $is_today ? 'background:#ffffe0;' : '' ?>">
                    <?php if ($first): ?>
                        <td class="date-cell" rowspan="<?= $rowspan ?>">
                            <?= $day ?>日<br>
                            <span class="weekday">(<?= $weekday ?>)</span>
                            <?php if ($slot_info['is_closed']): ?>
                                <br><span class="closed-label">⛔ 休診</span>
                            <?php endif; ?>
                            <?php 
                            $has_exception = false;
                            foreach ($slot_info['doctors'] as $doc) {
                                if ($doc['is_exception']) { $has_exception = true; break; }
                            }
                            if ($has_exception && !$slot_info['is_closed']): 
                            ?>
                                <br><span style="font-size:10px;color:#f59e0b;">🔵 例外</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    
                    <td class="doctor-name">
                        <?php if ($slot_info['is_closed']): ?>
                            <span class="absent">❌ <?= $doctor_name ?></span>
                            <br><span style="font-size:10px;color:#ef4444;">休診</span>
                        <?php elseif ($status['present']): ?>
                            <span class="present">✅ <?= $doctor_name ?></span>
                            <?php if ($status['is_exception']): ?>
                                <span class="exception">(<?= $status['pattern_name'] ?>)</span>
                            <?php endif; ?>
                            <?php if ($status['reason']): ?>
                                <br><span class="exception-reason">📌 <?= htmlspecialchars($status['reason'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="absent">❌ <?= $doctor_name ?></span>
                            <?php if ($status['reason']): ?>
                                <br><span class="exception-reason">📌 <?= htmlspecialchars($status['reason'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    
                    <?php if ($slot_info['is_closed'] || !$status['present']): ?>
                        <!-- 休診 or 休診医師 -->
                        <td colspan="100" style="color:#94a3b8; font-size:12px; text-align:center;">
                            -
                        </td>
                        <td colspan="100" style="color:#94a3b8; font-size:12px; text-align:center;">
                            -
                        </td>
                    <?php else: ?>
                        <!-- 午前枠 -->
                        <?php if (empty($am_slots)): ?>
                            <td style="color:#94a3b8; font-size:12px;">-</td>
                        <?php else: ?>
                            <?php foreach ($am_slots as $type): ?>
                                <td class="slot-cell" style="background:<?= $slot_colors[$type] ?>;">
                                    <span class="count"><?= $slot_labels[$type] ?></span>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- 午後枠 -->
                        <?php if (empty($pm_slots)): ?>
                            <td style="color:#94a3b8; font-size:12px;">-</td>
                        <?php else: ?>
                            <?php foreach ($pm_slots as $type): ?>
                                <td class="slot-cell" style="background:<?= $slot_colors[$type] ?>;">
                                    <span class="count"><?= $slot_labels[$type] ?></span>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </tr>
                <?php 
                    $first = false;
                endforeach; 
                
                // 合計行
                if (!$slot_info['is_closed'] && ($slot_info['am_total'] > 0 || $slot_info['pm_total'] > 0)):
                ?>
                <tr class="total-row">
                    <td colspan="2" class="total-label">合計</td>
                    
                    <!-- 午前合計 -->
                    <?php 
                    $am_slots_total = [];
                    for ($i = 0; $i < $slot_info['total']['GIF']; $i++) { $am_slots_total[] = 'GIF'; }
                    for ($i = 0; $i < $slot_info['total']['ECHO']; $i++) { $am_slots_total[] = 'ECHO'; }
                    if (empty($am_slots_total)):
                    ?>
                        <td style="color:#94a3b8;">-</td>
                    <?php else: ?>
                        <?php foreach ($am_slots_total as $type): ?>
                            <td class="slot-cell total-am" style="background:<?= $slot_colors[$type] ?>; font-size:13px;">
                                <span class="count"><?= $slot_labels[$type] ?></span>
                            </td>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- 午後合計 -->
                    <?php 
                    $pm_slots_total = [];
                    for ($i = 0; $i < $slot_info['total']['TCF']; $i++) { $pm_slots_total[] = 'TCF'; }
                    if (empty($pm_slots_total)):
                    ?>
                        <td style="color:#94a3b8;">-</td>
                    <?php else: ?>
                        <?php foreach ($pm_slots_total as $type): ?>
                            <td class="slot-cell total-pm" style="background:<?= $slot_colors[$type] ?>; font-size:13px;">
                                <span class="count"><?= $slot_labels[$type] ?></span>
                            </td>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
                <?php elseif ($slot_info['is_closed']): ?>
                <tr class="total-row" style="background:#fef2f2;">
                    <td colspan="100" style="text-align:center; color:#ef4444; font-weight:bold;">
                        ⛔ 休診（<?= htmlspecialchars($slot_info['closed_reason'], ENT_QUOTES, 'UTF-8') ?>）
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="note">
        <strong>📌 見方:</strong>
        各医師の行に、<strong>午前（GIF / エコー）</strong> と <strong>午後（TCF）</strong> の各枠を1セルずつ表示。
        一番下の行がその日の <strong>科合計</strong> です。
    </div>
</div>

</body>
</html>