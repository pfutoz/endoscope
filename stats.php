<?php
/**
 * 生成日時: 2026-08-27
 * 検査数集計 (stats.php)
 * 
 * 修正: 過去データも集計対象（未来の予約も含む）
 * デフォルト期間: 当月1日〜今日
 * 月ボタン: 今月から過去に遡る12ヶ月分（西暦付き）
 * 注意書き追加
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/date_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ============================================================
// 1. パラメータ取得
// ============================================================
$default_from = date('Y-m-01');  // 今月1日
$default_to = date('Y-m-d');     // 今日
$start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : $default_from;
$end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : $default_to;

$selected_dept = isset($_GET['dept']) ? $_GET['dept'] : 'all';
if (!in_array($selected_dept, ['PROCT', 'GASTRO', 'all'])) { $selected_dept = 'all'; }

$selected_doctor_raw = isset($_GET['doctor']) && $_GET['doctor'] !== '' ? $_GET['doctor'] : 'all';
$selected_doctors = explode(',', $selected_doctor_raw);
if (empty($selected_doctors) || (count($selected_doctors) == 1 && $selected_doctors[0] === '')) {
    $selected_doctors = ['all'];
}

$weekdays_raw = isset($_GET['weekdays']) && $_GET['weekdays'] !== '' ? $_GET['weekdays'] : 'all';
$weekdays = explode(',', $weekdays_raw);
if (empty($weekdays) || (count($weekdays) == 1 && $weekdays[0] === '')) {
    $weekdays = ['all'];
}

$selected_month_raw = isset($_GET['months']) && $_GET['months'] !== '' ? $_GET['months'] : '';
$selected_months = $selected_month_raw !== '' ? explode(',', $selected_month_raw) : [];
$is_month_mode = !empty($selected_months);

$output = isset($_GET['output']) ? $_GET['output'] : 'html';

// ============================================================
// 2. マスタデータ取得（UI用）
// ============================================================
$all_doctors = [];
foreach (['PROCT', 'GASTRO'] as $dept) {
    $all_doctors[$dept] = getDoctorsForDept($pdo, $dept);
}
$all_doctors_list = [];
foreach ($all_doctors as $list) {
    foreach ($list as $doc) {
        if (!in_array($doc, $all_doctors_list)) {
            $all_doctors_list[] = $doc;
        }
    }
}
sort($all_doctors_list);

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];

// ★★★ 月ショートカット（今月から過去に遡る 12ヶ月分・西暦付き） ★★★
$month_shortcuts = [];
$now_ts = time();
for ($i = 0; $i < 12; $i++) {
    $ts = strtotime("-$i month", $now_ts);
    $month_shortcuts[] = [
        'key'   => date('Y-n', $ts),
        'label' => date('Y/n', $ts),  // 2026/8 形式
        'month' => (int)date('n', $ts),
        'year'  => (int)date('Y', $ts)
    ];
}

// ============================================================
// 3. 集計処理
// ============================================================
function getStats($pdo, $start_date, $end_date, $selected_dept, $selected_doctors, $weekdays, $selected_months, $is_month_mode) {
    $dates = [];
    
    if ($is_month_mode && !empty($selected_months)) {
        foreach ($selected_months as $ym) {
            if (strpos($ym, '-') !== false) {
                list($y, $m) = explode('-', $ym);
            } else {
                $y = date('Y');
                $m = (int)$ym;
            }
            $start = strtotime(sprintf('%04d-%02d-01', $y, $m));
            $end = strtotime(date('Y-m-t', $start));
            while ($start <= $end) {
                $date_str = date('Y-m-d', $start);
                $dates[] = $date_str;
                $start = strtotime('+1 day', $start);
            }
        }
    } else {
        $start = ($start_date !== '') ? strtotime($start_date) : strtotime(date('Y-m-01'));
        $end = ($end_date !== '') ? strtotime($end_date) : strtotime(date('Y-m-d'));
        while ($start <= $end) {
            $dates[] = date('Y-m-d', $start);
            $start = strtotime('+1 day', $start);
        }
    }
    
    $dates = array_unique($dates);
    sort($dates);
    
    if (empty($dates)) {
        return [];
    }
    
    $date_placeholders = implode(',', array_fill(0, count($dates), '?'));
    
    $dept_condition = '';
    $dept_params = [];
    if ($selected_dept !== 'all') {
        $dept_condition = " AND s.department_code = ?";
        $dept_params[] = $selected_dept;
    }
    
    $doctor_condition = '';
    $doctor_params = [];
    if (!in_array('all', $selected_doctors) && !empty($selected_doctors)) {
        $placeholders = implode(',', array_fill(0, count($selected_doctors), '?'));
        $doctor_condition = " AND s.doctor_name IN ($placeholders)";
        $doctor_params = $selected_doctors;
    }
    
    $day_condition = '';
    $day_params = [];
    if (!in_array('all', $weekdays) && !empty($weekdays)) {
        $placeholders = implode(',', array_fill(0, count($weekdays), '?'));
        $day_condition = " AND EXTRACT(DOW FROM s.exam_date) IN ($placeholders)";
        $day_params = $weekdays;
    }
    
    $sql = "
        SELECT 
            s.doctor_name,
            s.exam_type,
            COUNT(*) as count
        FROM t_endoscopy_schedule s
        WHERE s.department_code IN ('PROCT', 'GASTRO')
          AND s.exam_date IN ($date_placeholders)
          AND s.is_canceled = FALSE
          $dept_condition
          $doctor_condition
          $day_condition
        GROUP BY s.doctor_name, s.exam_type
        ORDER BY s.doctor_name, s.exam_type
    ";
    
    $params = array_merge($dates, $dept_params, $doctor_params, $day_params);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    $result = [];
    foreach ($rows as $row) {
        $doctor = $row['doctor_name'];
        $exam_type = $row['exam_type'];
        $count = (int)$row['count'];
        
        if (!isset($result[$doctor])) {
            $result[$doctor] = [
                'GIF' => 0,
                'W' => 0,
                'TCF' => 0,
                'ポリペク' => 0,
                'エコー' => 0,
                'ECHO' => 0,
                '術前F' => 0,
                'upper_total' => 0,
                'lower_total' => 0
            ];
        }
        
        if ($exam_type === 'GIF') {
            $result[$doctor]['GIF'] += $count;
            $result[$doctor]['upper_total'] += $count;
        } elseif ($exam_type === 'W') {
            $result[$doctor]['W'] += $count;
            $result[$doctor]['upper_total'] += $count;
            $result[$doctor]['lower_total'] += $count;
        } elseif ($exam_type === 'TCF') {
            $result[$doctor]['TCF'] += $count;
            $result[$doctor]['lower_total'] += $count;
        } elseif ($exam_type === 'ポリペク') {
            $result[$doctor]['ポリペク'] += $count;
            $result[$doctor]['lower_total'] += $count;
        } elseif ($exam_type === 'エコー' || $exam_type === 'ECHO') {
            $result[$doctor]['エコー'] += $count;
        } elseif ($exam_type === '術前F') {
            $result[$doctor]['術前F'] += $count;
        }
    }
    
    foreach ($result as $doctor => $data) {
        if (isset($data['ECHO']) && $data['ECHO'] > 0) {
            $result[$doctor]['エコー'] += $data['ECHO'];
        }
        unset($result[$doctor]['ECHO']);
    }
    
    $totals = ['GIF' => 0, 'W' => 0, 'TCF' => 0, 'ポリペク' => 0, 'エコー' => 0, '術前F' => 0, 'upper_total' => 0, 'lower_total' => 0];
    foreach ($result as $doctor => $data) {
        foreach ($data as $type => $count) {
            if (isset($totals[$type])) {
                $totals[$type] += $count;
            }
        }
    }
    $result['_total'] = $totals;
    
    return $result;
}

$stats = getStats($pdo, $start_date, $end_date, $selected_dept, $selected_doctors, $weekdays, $selected_months, $is_month_mode);

// ============================================================
// 4. CSV出力
// ============================================================
if ($output === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stats_' . $start_date . '_' . $end_date . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['医師', '上部合計', 'GIF', 'W', '下部合計', 'W', 'TCF', 'ポリペク', 'エコー', '術前F']);
    foreach ($stats as $doctor => $data) {
        if ($doctor === '_total') continue;
        fputcsv($fp, [$doctor, $data['upper_total'], $data['GIF'], $data['W'], $data['lower_total'], $data['W'], $data['TCF'], $data['ポリペク'], $data['エコー'], $data['術前F']]);
    }
    fputcsv($fp, ['【合計】', $stats['_total']['upper_total'], $stats['_total']['GIF'], $stats['_total']['W'], $stats['_total']['lower_total'], $stats['_total']['W'], $stats['_total']['TCF'], $stats['_total']['ポリペク'], $stats['_total']['エコー'], $stats['_total']['術前F']]);
    fclose($fp);
    exit;
}

// ============================================================
// 5. HTML表示
// ============================================================
$is_all_days = in_array('all', $weekdays);
$day_labels = $is_all_days ? ['全曜日'] : array_map(function($d) use ($week_ja) { return $week_ja[(int)$d]; }, $weekdays);
$day_label_str = implode('・', $day_labels);
$dept_label = $selected_dept === 'all' ? '全診療科' : ($selected_dept === 'PROCT' ? '肛門科' : '胃腸科');
$doctor_label = in_array('all', $selected_doctors) ? '全医師' : implode('・', $selected_doctors);
$start_label = date('Y年m月d日', strtotime($start_date));
$end_label = date('Y年m月d日', strtotime($end_date));

$month_label = '';
if (!empty($selected_months)) {
    $labels = [];
    foreach ($selected_months as $ym) {
        if (strpos($ym, '-') !== false) {
            list($y, $m) = explode('-', $ym);
            $labels[] = $y . '/' . $m;
        } else {
            $labels[] = date('Y') . '/' . $ym;
        }
    }
    $month_label = implode('・', $labels);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>検査数集計</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            margin: 20px;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px 28px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        h2 {
            margin-top: 0;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 20px;
        }
        .home-btn {
            background: #2c3e50;
            color: #fff;
            padding: 6px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        .home-btn:hover { background: #34495e; }
        
        .filter-bar {
            background: #f8fafc;
            padding: 14px 18px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 10px;
            padding: 4px 0;
            border-bottom: 1px dashed #e9edf4;
        }
        .filter-row:last-child { border-bottom: none; }
        .filter-label {
            font-weight: 700;
            font-size: 12px;
            color: #475569;
            min-width: 56px;
            cursor: pointer;
            user-select: none;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .filter-label:hover { background: #e2e8f0; }
        .filter-label.off { color: #94a3b8; }
        .filter-label .toggle-icon { font-size: 10px; }
        .btn {
            padding: 4px 12px;
            border: 1.5px solid #d0d8e3;
            border-radius: 20px;
            background: #fff;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            transition: all 0.15s;
        }
        .btn:hover { background: #f1f5f9; border-color: #94a3b8; }
        .btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn.active-all { background: #2c3e50; color: #fff; border-color: #2c3e50; }
        .btn.disabled { opacity: 0.4; pointer-events: none; cursor: default; }
        .date-input {
            padding: 4px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            background: #fff;
            width: 140px;
            transition: all 0.15s;
        }
        .date-input:focus { outline: none; border-color: #2563eb; }
        .date-input.disabled {
            opacity: 0.4;
            pointer-events: none;
            background: #f1f5f9;
        }
        .month-btn {
            font-size: 11px;
            padding: 3px 8px;
        }
        .month-btn.active {
            background: #8b5cf6;
            color: #fff;
            border-color: #8b5cf6;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            padding-top: 8px;
            margin-top: 4px;
            border-top: 1px solid #e2e8f0;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
            padding: 6px 18px;
            border-radius: 6px;
        }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-success {
            background: #10b981;
            color: #fff;
            border-color: #10b981;
            padding: 6px 18px;
            border-radius: 6px;
        }
        .btn-success:hover { background: #059669; border-color: #059669; }

        .stats-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 24px;
            background: #f0f7ff;
            padding: 10px 16px;
            border-radius: 6px;
            border: 1px solid #b4c6e7;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .stats-summary .item { font-weight: 700; color: #1e3a8a; }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 8px;
        }
        .stats-table th, .stats-table td {
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            text-align: center;
        }
        .stats-table th {
            background: #f1f5f9;
            font-weight: 700;
            color: #334155;
            font-size: 11px;
        }
        .stats-table .doctor-name { font-weight: 700; text-align: left; color: #1e293b; }
        .stats-table .total-row { background: #f8fafc; font-weight: 700; border-top: 2px solid #94a3b8; }
        .stats-table .total-row td { border-top: 2px solid #94a3b8; }
        .stats-table .num { text-align: right; }
        .stats-table .num-highlight { background: #eff6ff; font-weight: 700; color: #1e40af; }
        .stats-table .sub-item { font-size: 10px; color: #64748b; font-weight: 400; }
        .stats-table .col-upper { color: #0ea5e9; }
        .stats-table .col-lower { color: #10b981; }
        .stats-table .col-echo { color: #8b5cf6; }
        .stats-table .col-preop { color: #f59e0b; }
        .empty-msg { color: #94a3b8; text-align: center; padding: 40px 0; }
        .stats-note { font-size: 11px; color: #94a3b8; margin-top: 8px; text-align: right; border-top: 1px solid #e2e8f0; padding-top: 8px; }
        .stats-caution {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            padding: 10px 16px;
            margin-top: 12px;
            font-size: 12px;
            color: #92400e;
            text-align: center;
        }
        .required-mark { color: #dc2626; font-weight: 700; margin-left: 2px; }
        @media (max-width: 768px) {
            .container { padding: 16px; }
            .filter-row { gap: 4px 6px; }
            .date-input { width: 100%; }
            .btn { font-size: 11px; padding: 3px 8px; }
            .stats-table { font-size: 10px; }
            .stats-table th, .stats-table td { padding: 3px 5px; }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        📊 検査数集計
        <a href="index.php" class="home-btn">🏠 メニューへ</a>
    </h2>

    <form method="GET" action="" class="filter-bar" id="statsForm">
        <input type="hidden" name="output" value="html">

        <!-- 期間 + 月ショートカット -->
        <div class="filter-row">
            <span class="filter-label" style="cursor:default;">期間 <span class="required-mark">*</span></span>
            <input type="date" name="start_date" id="date_from" class="date-input <?= $is_month_mode ? 'disabled' : '' ?>" 
                   value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>">
            <span>〜</span>
            <input type="date" name="end_date" id="date_to" class="date-input <?= $is_month_mode ? 'disabled' : '' ?>" 
                   value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>">
            
            <!-- 月ショートカット（今月から過去に遡る 12ヶ月分・西暦付き） -->
            <span style="display:flex; gap:4px; margin-left:8px; flex-wrap:wrap;">
                <?php foreach ($month_shortcuts as $m): 
                    $val = $m['key'];
                    $is_act = in_array($val, $selected_months) || in_array((string)$m['month'], $selected_months);
                ?>
                    <button type="button" class="btn month-btn <?= $is_act ? 'active' : '' ?>" 
                            data-month="<?= $val ?>" onclick="toggleMonth(this)">
                        <?= $m['label'] ?>
                    </button>
                <?php endforeach; ?>
            </span>
            <input type="hidden" name="months" id="monthsInput" value="<?= implode(',', $selected_months) ?>">
        </div>

        <!-- 診療科（1つだけ選択） -->
        <div class="filter-row" data-row-group="dept">
            <span class="filter-label" onclick="toggleLabelGroup('dept')">
                <span class="toggle-icon">▼</span> 診療科
            </span>
            <button type="button" class="btn <?= $selected_dept === 'all' ? 'active-all' : '' ?>" data-group="dept" data-value="all">全診療科</button>
            <button type="button" class="btn <?= $selected_dept === 'PROCT' ? 'active' : '' ?>" data-group="dept" data-value="PROCT">肛門科</button>
            <button type="button" class="btn <?= $selected_dept === 'GASTRO' ? 'active' : '' ?>" data-group="dept" data-value="GASTRO">胃腸科</button>
            <input type="hidden" name="dept" id="deptInput" value="<?= $selected_dept ?>">
        </div>

        <!-- 医師（複数選択可・OR条件） -->
        <div class="filter-row" data-row-group="doctor">
            <span class="filter-label" onclick="toggleLabelGroup('doctor')">
                <span class="toggle-icon">▼</span> 医師
            </span>
            <button type="button" class="btn <?= in_array('all', $selected_doctors) ? 'active-all' : '' ?>" data-group="doctor" data-value="all">全医師</button>
            <?php foreach ($all_doctors_list as $doc): ?>
                <button type="button" class="btn <?= in_array($doc, $selected_doctors) ? 'active' : '' ?>" data-group="doctor" data-value="<?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($doc, ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endforeach; ?>
            <input type="hidden" name="doctor" id="doctorInput" value="<?= implode(',', $selected_doctors) ?>">
        </div>

        <!-- 曜日（複数選択可・OR条件） -->
        <div class="filter-row" data-row-group="weekday">
            <span class="filter-label" onclick="toggleLabelGroup('weekday')">
                <span class="toggle-icon">▼</span> 曜日
            </span>
            <button type="button" class="btn <?= in_array('all', $weekdays) ? 'active-all' : '' ?>" data-group="weekday" data-value="all">全曜日</button>
            <?php foreach ($week_ja as $i => $label): ?>
                <button type="button" class="btn <?= in_array((string)$i, $weekdays) ? 'active' : '' ?>" data-group="weekday" data-value="<?= $i ?>">
                    <?= $label ?>
                </button>
            <?php endforeach; ?>
            <input type="hidden" name="weekdays" id="weekdaysInput" value="<?= implode(',', $weekdays) ?>">
        </div>

        <!-- アクション -->
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">🔍 集計実行</button>
            <button type="button" class="btn btn-success" onclick="exportCSV()">📥 CSV出力</button>
            <button type="reset" class="btn" style="border-color:#d0d8e3; background:#fff;" onclick="resetForm()">↺ リセット</button>
        </div>
    </form>

    <!-- 集計サマリ -->
    <div class="stats-summary">
        <span>📅 期間: <span class="item"><?= $start_label ?> 〜 <?= $end_label ?></span></span>
        <?php if ($month_label): ?>
            <span>📆 月: <span class="item"><?= $month_label ?></span></span>
        <?php endif; ?>
        <span>🏥 診療科: <span class="item"><?= $dept_label ?></span></span>
        <span>👨‍⚕️ 医師: <span class="item"><?= $doctor_label ?></span></span>
        <span>📆 曜日: <span class="item"><?= $day_label_str ?></span></span>
    </div>

    <!-- 集計結果 -->
    <?php if (empty($stats) || (count($stats) === 1 && isset($stats['_total']))): ?>
        <div class="empty-msg">該当するデータがありません</div>
    <?php else: ?>
        <table class="stats-table">
            <thead>
                <tr>
                    <th style="width:80px; text-align:left;">医師</th>
                    <th style="width:80px;" class="col-upper">上部合計<br><span class="sub-item">(GIF+W)</span></th>
                    <th style="width:60px;" class="col-upper">GIF</th>
                    <th style="width:60px;" class="col-upper">W</th>
                    <th style="width:80px;" class="col-lower">下部合計<br><span class="sub-item">(TCF+W+ポリペク)</span></th>
                    <th style="width:60px;" class="col-lower">W</th>
                    <th style="width:60px;" class="col-lower">TCF</th>
                    <th style="width:70px;" class="col-lower">ポリペク</th>
                    <th style="width:60px;" class="col-echo">エコー</th>
                    <th style="width:70px;" class="col-preop">術前F</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $grand_upper = 0; $grand_lower = 0;
                $grand_gif = 0; $grand_w = 0; $grand_tcf = 0; $grand_poli = 0;
                $grand_echo = 0; $grand_preop = 0;
                ?>
                <?php foreach ($stats as $doctor => $data): ?>
                    <?php if ($doctor === '_total') continue; ?>
                    <?php
                    $upper = $data['upper_total'];
                    $lower = $data['lower_total'];
                    $grand_upper += $upper;
                    $grand_lower += $lower;
                    $grand_gif += $data['GIF'];
                    $grand_w += $data['W'];
                    $grand_tcf += $data['TCF'];
                    $grand_poli += $data['ポリペク'];
                    $grand_echo += $data['エコー'];
                    $grand_preop += $data['術前F'];
                    ?>
                    <tr>
                        <td class="doctor-name"><?= htmlspecialchars($doctor, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="num <?= $upper > 0 ? 'num-highlight' : '' ?>"><?= $upper ?></td>
                        <td class="num"><?= $data['GIF'] ?></td>
                        <td class="num"><?= $data['W'] ?></td>
                        <td class="num <?= $lower > 0 ? 'num-highlight' : '' ?>"><?= $lower ?></td>
                        <td class="num"><?= $data['W'] ?></td>
                        <td class="num"><?= $data['TCF'] ?></td>
                        <td class="num"><?= $data['ポリペク'] ?></td>
                        <td class="num <?= $data['エコー'] > 0 ? 'num-highlight' : '' ?>"><?= $data['エコー'] ?></td>
                        <td class="num <?= $data['術前F'] > 0 ? 'num-highlight' : '' ?>" style="color:#f59e0b; font-weight:bold;"><?= $data['術前F'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td class="doctor-name">📊 合計</td>
                    <td class="num" style="font-weight:bold; color:#0ea5e9;"><?= $grand_upper ?></td>
                    <td class="num"><?= $grand_gif ?></td>
                    <td class="num"><?= $grand_w ?></td>
                    <td class="num" style="font-weight:bold; color:#10b981;"><?= $grand_lower ?></td>
                    <td class="num"><?= $grand_w ?></td>
                    <td class="num"><?= $grand_tcf ?></td>
                    <td class="num"><?= $grand_poli ?></td>
                    <td class="num" style="font-weight:bold; color:#8b5cf6;"><?= $grand_echo ?></td>
                    <td class="num" style="font-weight:bold; color:#f59e0b;"><?= $grand_preop ?></td>
                </tr>
            </tbody>
        </table>
        <div class="stats-note">※ Wは上部・下部に重複カウントされます。術前Fは独立した別枠です。</div>
    <?php endif; ?>

    <!-- ★★★ 注意書き ★★★ -->
    <div class="stats-caution">
        ⚠️ 実績データは予定データに基づきます。正確な数字はMALL3で集計してください。
    </div>

</div>

<script>
// ============================================================
// ★★★ 月ボタン（複数選択可・期間Disable連動） ★★★
// ============================================================
function toggleMonth(btn) {
    btn.classList.toggle('active');
    var selected = [];
    document.querySelectorAll('.month-btn.active').forEach(function(b) {
        selected.push(b.dataset.month);
    });
    document.getElementById('monthsInput').value = selected.join(',');
    
    var dateFrom = document.getElementById('date_from');
    var dateTo = document.getElementById('date_to');
    if (selected.length > 0) {
        dateFrom.disabled = true;
        dateTo.disabled = true;
        dateFrom.classList.add('disabled');
        dateTo.classList.add('disabled');
        // ★★★ 値をクリアする ★★★
        dateFrom.value = '';
        dateTo.value = '';
    } else {
        dateFrom.disabled = false;
        dateTo.disabled = false;
        dateFrom.classList.remove('disabled');
        dateTo.classList.remove('disabled');
        // ★★★ デフォルト値（当月1日〜今日）をセット ★★★
        var today = new Date();
        var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        dateFrom.value = firstDay.toISOString().split('T')[0];
        dateTo.value = today.toISOString().split('T')[0];
    }
}

// ============================================================
// ★★★ ラベルクリックで全ON/OFF ★★★
// ============================================================
function toggleLabelGroup(groupName) {
    var container = document.querySelector('.filter-row[data-row-group="' + groupName + '"]');
    if (!container) return;
    
    var label = container.querySelector('.filter-label');
    var isOff = label.classList.toggle('off');
    var icon = label.querySelector('.toggle-icon');
    if (icon) { icon.textContent = isOff ? '▶' : '▼'; }
    
    var btns = container.querySelectorAll('.btn');
    btns.forEach(function(btn) {
        btn.classList.toggle('disabled', isOff);
    });
    
    if (groupName === 'dept' && isOff) {
        var allBtn = container.querySelector('.btn[data-value="all"]');
        if (allBtn) {
            btns.forEach(function(b) { b.classList.remove('active', 'active-all'); });
            allBtn.classList.add('active-all');
            document.getElementById('deptInput').value = 'all';
        }
    }
    if ((groupName === 'doctor' || groupName === 'weekday') && isOff) {
        var allBtn = container.querySelector('.btn[data-value="all"]');
        if (allBtn) {
            btns.forEach(function(b) { b.classList.remove('active', 'active-all'); });
            allBtn.classList.add('active-all');
            var input = document.getElementById(groupName + 'Input');
            if (input) { input.value = 'all'; }
        }
    }
}

// ============================================================
// ★★★ 診療科ボタン（1つだけ選択） ★★★
// ============================================================
document.querySelectorAll('.btn[data-group="dept"]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var value = this.dataset.value;
        var siblings = document.querySelectorAll('.btn[data-group="dept"]');
        siblings.forEach(function(b) {
            b.classList.remove('active', 'active-all');
        });
        this.classList.add(value === 'all' ? 'active-all' : 'active');
        document.getElementById('deptInput').value = value;
    });
});

// ============================================================
// ★★★ 医師ボタン（複数選択可・OR条件） ★★★
// ============================================================
document.querySelectorAll('.btn[data-group="doctor"]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var value = this.dataset.value;
        var input = document.getElementById('doctorInput');
        if (value === 'all') {
            document.querySelectorAll('.btn[data-group="doctor"]').forEach(function(b) {
                b.classList.remove('active', 'active-all');
            });
            this.classList.add('active-all');
            input.value = 'all';
            return;
        }
        var allBtn = document.querySelector('.btn[data-group="doctor"][data-value="all"]');
        if (allBtn) { allBtn.classList.remove('active-all'); }
        this.classList.toggle('active');
        var selected = [];
        document.querySelectorAll('.btn[data-group="doctor"].active').forEach(function(b) {
            selected.push(b.dataset.value);
        });
        if (selected.length === 0) {
            if (allBtn) {
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else { input.value = ''; }
        } else {
            selected.sort();
            input.value = selected.join(',');
        }
    });
});

// ============================================================
// ★★★ 曜日ボタン（複数選択可・OR条件） ★★★
// ============================================================
document.querySelectorAll('.btn[data-group="weekday"]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var value = this.dataset.value;
        var input = document.getElementById('weekdaysInput');
        if (value === 'all') {
            document.querySelectorAll('.btn[data-group="weekday"]').forEach(function(b) {
                b.classList.remove('active', 'active-all');
            });
            this.classList.add('active-all');
            input.value = 'all';
            return;
        }
        var allBtn = document.querySelector('.btn[data-group="weekday"][data-value="all"]');
        if (allBtn) { allBtn.classList.remove('active-all'); }
        this.classList.toggle('active');
        var selected = [];
        document.querySelectorAll('.btn[data-group="weekday"].active').forEach(function(b) {
            selected.push(b.dataset.value);
        });
        if (selected.length === 0) {
            if (allBtn) {
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else { input.value = ''; }
        } else {
            selected.sort();
            input.value = selected.join(',');
        }
    });
});

// ============================================================
// CSV出力
// ============================================================
function exportCSV() {
    var form = document.getElementById('statsForm');
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'output';
    input.value = 'csv';
    form.appendChild(input);
    form.submit();
}

// ============================================================
// リセット
// ============================================================
function resetForm() {
    document.querySelectorAll('.btn[data-group]').forEach(function(btn) {
        btn.classList.remove('active', 'active-all');
    });
    document.querySelectorAll('.btn[data-value="all"]').forEach(function(btn) {
        btn.classList.add('active-all');
    });
    
    document.querySelectorAll('.btn[data-group="dept"]').forEach(function(btn) {
        btn.classList.remove('active', 'active-all');
    });
    document.querySelector('.btn[data-group="dept"][data-value="all"]').classList.add('active-all');
    document.getElementById('deptInput').value = 'all';
    document.getElementById('doctorInput').value = 'all';
    document.getElementById('weekdaysInput').value = 'all';
    
    document.querySelectorAll('.month-btn.active').forEach(function(b) {
        b.classList.remove('active');
    });
    document.getElementById('monthsInput').value = '';
    document.getElementById('date_from').disabled = false;
    document.getElementById('date_to').disabled = false;
    document.getElementById('date_from').classList.remove('disabled');
    document.getElementById('date_to').classList.remove('disabled');
    
    document.querySelectorAll('.filter-label').forEach(function(label) {
        label.classList.remove('off');
        var icon = label.querySelector('.toggle-icon');
        if (icon) { icon.textContent = '▼'; }
    });
    document.querySelectorAll('.btn.disabled').forEach(function(btn) {
        btn.classList.remove('disabled');
    });
    
    var today = new Date();
    var from = new Date(today.getFullYear(), today.getMonth(), 1);
    var fromStr = from.getFullYear() + '-' + String(from.getMonth() + 1).padStart(2, '0') + '-' + String(from.getDate()).padStart(2, '0');
    var toStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    document.querySelector('input[name="start_date"]').value = fromStr;
    document.querySelector('input[name="end_date"]').value = toStr;
    
    document.getElementById('statsForm').submit();
}
</script>

</body>
</html>