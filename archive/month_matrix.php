<?php
/**
 * 生成日時: 2026-07-15 21:50:00
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// 1. データベース接続設定 (内視鏡予約用 PostgreSQL)
$host     = 'localhost';
$dbname   = 'endoscope';
$user     = 'postgres';
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

// 外部化した小野会専用カレンダーヘルパーを読み込み
require_once 'calendar_helper_onokai.php';

// 2. 表示する診療科、年月、キャンセル表示モード、医師絞り込みの取得
$dept  = isset($_GET['dept'])  ? trim($_GET['dept'])  : 'PROCT';
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$show_canceled = isset($_GET['show_canceled']) && $_GET['show_canceled'] === '1';
$selected_doctor = isset($_GET['doctor']) ? trim($_GET['doctor']) : ''; 

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = date('t', $first_day_timestamp);

$prev_month_ts = strtotime("-1 month", $first_day_timestamp);
$prev_year  = date('Y', $prev_month_ts); $prev_month = date('n', $prev_month_ts);
$next_month_ts = strtotime("+1 month", $first_day_timestamp);
$next_year  = date('Y', $next_month_ts); $next_month = date('n', $next_month_ts);

// 3. 診療科（department_code）に連動して専用時間枠マスターから時間枠を自動ロード
$waku_stmt = $pdo->prepare("SELECT waku_name FROM m_waku_master WHERE department_code = :dept ORDER BY display_order ASC");
$waku_stmt->execute([':dept' => $dept]);
$waku_list = $waku_stmt->fetchAll(PDO::FETCH_COLUMN);

$doctors   = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'doctor' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$types     = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'exam_type' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$arrivals  = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'arrival_time' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);
$locations = $pdo->query("SELECT item_value FROM m_input_master WHERE category = 'exam_location' ORDER BY display_order ASC")->fetchAll(PDO::FETCH_COLUMN);

// 4. 予約データの取得（patient_kana、および3種のメモ情報も結合して取得）[cite: 5]
$data_sql = "SELECT 
                s.schedule_id, s.exam_date, s.scheduled_time, s.department_code, s.patient_id, s.patient_name, s.patient_kana, s.birthday,
                s.doctor_name, s.exam_type, s.arrival_time, s.exam_location, s.remarks, s.is_canceled,
                m.memo_today, m.memo_karte, m.memo_patient
             FROM t_endoscopy_schedule s
             LEFT JOIN t_mall_memo m ON s.patient_id = m.patient_id
             WHERE s.department_code = :dept
               AND EXTRACT(YEAR FROM s.exam_date) = :year 
               AND EXTRACT(MONTH FROM s.exam_date) = :month";

if (!$show_canceled) { $data_sql .= " AND s.is_canceled = FALSE"; }
if ($selected_doctor !== '') { $data_sql .= " AND s.doctor_name = :selected_doctor"; }

$data_stmt = $pdo->prepare($data_sql);
$bind_params = [':dept' => $dept, ':year' => $year, ':month' => $month];
if ($selected_doctor !== '') { $bind_params[':selected_doctor'] = $selected_doctor; }

$data_stmt->execute($bind_params);
$schedule_matrix = [];
foreach ($data_stmt->fetchAll() as $row) {
    $schedule_matrix[$row['exam_date']][$row['scheduled_time']][] = $row;
}

function calculateAgeOnExamDate($birthday, $examDate) {
    if (empty($birthday) || $birthday === '---') return null;
    try {
        $birth = new DateTime($birthday); $exam = new DateTime($examDate);
        if ($birth > $exam) return null;
        return $exam->diff($birth)->y;
    } catch (Exception $e) { return null; }
}

// サーバーサイド（PHP）側の年齢バッジスタイル判定用関数[cite: 5]
function getAgeBadgeHTML($age) {
    if ($age === null) return '';
    
    $style = "";
    if ($age < 65) {
        $style = "background-color: #e0f2fe; color: #0369a1;";
    } else if ($age >= 65 && $age <= 74) {
        $style = "background-color: #fef08a; color: #713f12; font-weight: bold;";
    } else if ($age >= 75 && $age <= 85) {
        $style = "background-color: #fca5a5; color: #991b1b; font-weight: bold;";
    } else {
        $style = "background-color: #ef4444; color: #ffffff; font-weight: bold;";
    }
    return "<span class='age-badge' style='display: inline-block; padding: 1px 5px; font-size: 10px; border-radius: 4px; line-height: 1.1; {$style}'>{$age}歳</span>";
}

// サーバーサイド（PHP）側の患者ボックス表示関数[cite: 5]
function renderPatientBox($data, $date_str, $prefix = "") {
    $is_canceled_row = isset($data['is_canceled']) && ($data['is_canceled'] === true || $data['is_canceled'] === 1 || $data['is_canceled'] === 't');
    $is_temporary = (strpos(bin2hex($data['patient_name']), bin2hex('※仮予約')) !== false);
    
    $box_class = 'patient-box';
    if ($is_canceled_row) $box_class .= ' is-canceled-box';
    elseif ($is_temporary) $box_class .= ' is-temporary-box';
    
    $custom_bg = '';
    if (!$is_canceled_row && !$is_temporary) {
        $color_map = [ 'GIF' => '#e0ffff', 'ポリペク' => '#dda0dd', 'TCF' => '#e2f0d9', 'W' => '#c5e0b4' ];
        $exam_type = $data['exam_type'] ?? '';
        $custom_bg = isset($color_map[$exam_type]) ? "background-color: {$color_map[$exam_type]};" : "background-color: #fff2cc;";
    }
    
    $draggable = $is_canceled_row ? 'false' : 'true';
    $tooltip = "【検査】" . $data['exam_type'] . "\n【医師】" . $data['doctor_name'] . "\n【ヨミ】" . ($data['patient_kana'] ?? '未登録') . "\n【備考】\n" . ($data['remarks'] ?? '');
    $b_date = !empty($data['birthday']) ? date('Y/m/d', strtotime($data['birthday'])) : '---';
    $age = calculateAgeOnExamDate($data['birthday'], $date_str);
    $age_badge_html = getAgeBadgeHTML($age);
    $link_title = "通常クリックで編集・削除\n[Ctrl + クリック] で画像管理サーバを別窓起動";

    echo "<div class='{$box_class}' style='{$custom_bg}' draggable='{$draggable}' title='" . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . "' data-schedule-id='" . htmlspecialchars($data['schedule_id'], ENT_QUOTES, 'UTF-8') . "' data-json='" . htmlspecialchars(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . "' ondragstart='dragStart(this, event)'>";
    
    // 検査種別のカラーバッジスタイル選定[cite: 5]
    $badge_style = "background-color: #e2e8f0; color: #475569;";
    $exam_type = $data['exam_type'] ?? '';
    if ($exam_type === 'GIF') {
        $badge_style = "background-color: #0ea5e9; color: #fff; font-weight: bold;";
    } else if ($exam_type === 'TCF') {
        $badge_style = "background-color: #10b981; color: #fff; font-weight: bold;";
    } else if ($exam_type === 'ポリペク') {
        $badge_style = "background-color: #8b5cf6; color: #fff; font-weight: bold;";
    } else if ($exam_type === 'W') {
        $badge_style = "background-color: #22c55e; color: #fff; font-weight: bold;";
    }

    // 1段目：カルテIDと年齢[cite: 5]
    echo "<div class='pid' style='display:flex; justify-content:space-between; align-items:center; border-bottom: 1px dotted rgba(0,0,0,0.15); padding-bottom: 2px; margin-bottom: 3px;'>";
    echo "<span style='font-weight: 800; color: #1e293b; font-size: 11px;'>ID:" . htmlspecialchars($data['patient_id']??'', ENT_QUOTES, 'UTF-8') . ($is_canceled_row ? ' [削除]' : '') . "</span>";
    echo $age_badge_html;
    echo "</div>";
    
    // 2段目：患者氏名[cite: 5]
    echo "<div class='pname' title='{$link_title}' onclick='openEditModalWithCtrlCheck(this, event)'>";
    echo $prefix;
    echo "<span class='real-pname'>" . htmlspecialchars($data['patient_name'], ENT_QUOTES, 'UTF-8') . "</span>";
    echo "<span class='masked-pname'>●●●●</span>";
    echo "</div>";
    
    // 3段目：生年月日 ＋ 検査種別[cite: 5]
    echo "<div style='display:flex; justify-content:space-between; align-items:center; margin-top: 3px; padding-top: 2px;'>";
    echo "<div class='pbirthday' style='font-size: 9px; color: #64748b;'>" . htmlspecialchars($b_date, ENT_QUOTES, 'UTF-8') . "</div>";
    echo "<span class='pinfo-badge' style='padding: 1px 6px; border-radius: 12px; font-size: 10px; {$badge_style}'>" . htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8') . "</span>";
    echo "</div>";
    
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内視鏡予約状況システム（横型マトリクス）</title>
    <style>
        body { font-family: sans-serif; margin: 15px; background-color: #f4f6f9; color: #333; }
        .nav-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 10px; flex-wrap: wrap; }
        .nav-buttons { display: flex; gap: 4px; align-items: center; }
        
        .dept-tabs { display: inline-flex; gap: 3px; margin-right: 5px; }
        .dept-tab { 
            padding: 5px 12px; text-decoration: none; font-weight: bold; border-radius: 4px; border: 1px solid #ccc; 
            background: #e6e6e6; color: #333; font-size: 12px; height: 34px; display: inline-flex; align-items: center; box-sizing: border-box;
        }
        .dept-tab.active-PROCT { background: #3498db; color: #fff; border-color: #2980b9; }
        .dept-tab.active-GASTRO { background: #2ecc71; color: #fff; border-color: #27ae60; }

        .nav-buttons a { text-decoration: none; display: inline-flex; }
        .nav-buttons button.month-arrow-btn { 
            padding: 5px 12px; font-size: 13px; font-weight: bold; cursor: pointer; height: 34px; box-sizing: border-box; display: inline-flex; align-items: center;
        }
        .nav-buttons span.current-month-display {
            font-weight: bold; background-color: #e6f2ff; padding: 5px 16px; font-size: 15px; border: 1px solid #adc5f5;
            height: 34px; box-sizing: border-box; display: inline-flex; align-items: center; color: #1e3a8a; border-radius: 4px;
        }
        
        .config-panel { background: #fff; padding: 6px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; font-weight: bold; display: flex; align-items: center; gap: 15px; }
        .home-icon-btn {
            position: fixed; top: 15px; right: 15px; z-index: 100; background-color: #2c3e50; color: white;
            border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            text-decoration: none;
        }
        .home-icon-btn:hover { background-color: #34495e; transform: scale(1.05); }
        
        .today-scroll-btn { position: fixed; bottom: 25px; right: 25px; z-index: 100; background-color: #e74c3c; color: white; border: none; width: 50px; height: 50px; border-radius: 50%; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.3); transition: transform 0.2s; }
        .today-scroll-btn:hover { background-color: #c0392b; transform: scale(1.1); }

        .auto-refresh-badge { display: flex; align-items: center; gap: 6px; color: #555; cursor: pointer; padding: 2px 6px; border-radius: 4px; transition: background 0.2s; user-select: none; }
        .auto-refresh-badge:hover { background-color: #f0f0f0; color: #000; }
        .auto-refresh-badge .clock-icon { font-size: 16px; display: inline-block; animation: waku-spin 12s linear infinite; }
        @keyframes waku-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .scroll-capsule {
            display: inline-flex; background: #e0e0e0; padding: 2px; border-radius: 20px; border: 1px solid #ccc; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        .scroll-arrow-btn {
            background: none; border: none; font-size: 14px; cursor: pointer; width: 32px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; color: #444; transition: all 0.15s ease; user-select: none;
        }
        .scroll-arrow-btn:hover { background: #fff; color: #000; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
        .scroll-arrow-btn:active { background: #d4d4d4; box-shadow: inset 0 1px 2px rgba(0,0,0,0.2); }
        
        .matrix-container { width: 100%; max-height: 72vh; overflow: auto; border: 1px solid #ccc; background: #fff; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .matrix-table { border-collapse: separate; border-spacing: 0; font-size: 13px; min-width: 3200px; table-layout: fixed; }
        .matrix-table th, .matrix-table td { border-right: 1px solid #bbb; border-bottom: 1px solid #bbb; padding: 10px 4px; text-align: center; vertical-align: top; width: 110px; box-sizing: border-box; }
        
        .fixed-waku-col { position: sticky; left: 0; background-color: #f2f2f2; font-weight: bold; z-index: 10; width: 110px !important; min-width: 110px; border-right: 2px solid #999; box-shadow: 2px 0 5px rgba(0,0,0,0.1); text-align: center; vertical-align: middle; }
        .header-row th { background-color: #e6f2ff; position: sticky; top: 0; z-index: 11; height: 42px; border-bottom: 2px solid #b4c6e7; }
        .matrix-table th.fixed-waku-col { position: sticky; top: 0; left: 0; z-index: 15; background-color: #d1e1f0 !important; border-right: 2px solid #999; border-bottom: 2px solid #b4c6e7; }
        
        .waku-cell { min-height: 70px; height: 70px; cursor: pointer; }
        .waku-cell.dragover { background-color: #fff3cd !important; border: 2px dashed #ffc107 !important; }
        
        .patient-box { border: 1px solid #b4c6e7; border-radius: 4px; padding: 4px; text-align: left; font-size: 11px; cursor: grab; margin-bottom: 2px; display: block; line-height: 1.3; }
        .patient-box:active { cursor: grabbing; }
        .patient-box.is-temporary-box { background-color: #fff2cc !important; border: 1px solid #ffcc66 !important; color: #b25900; }
        .patient-box.is-temporary-box .pname { color: #cc6600; font-style: italic; }
        .patient-box.is-canceled-box { background-color: #e9ecef !important; border: 1px solid #adb5bd !important; opacity: 0.65; cursor: pointer; background-image: linear-gradient(45deg, #f1f3f5 25%, transparent 25%, transparent 50%, #f1f3f5 50%, #f1f3f5 75%, transparent 75%, transparent); background-size: 10px 10px; }
        .patient-box.is-canceled-box .pname { text-decoration: line-through; color: #6c757d; }
        
        .pname { font-weight: bold; color: #000; font-size: 12px; cursor: pointer; }
        .pid { color: #555; font-size: 10px; font-weight: bold; }
        .pkana { font-size: 9px; color: #666; font-weight: normal; }

        body.mask-active .real-pname { display: none; }
        body.mask-active .masked-pname { display: inline; color: #777; font-style: italic; }
        .masked-pname { display: none; }
        
        dialog { border: none; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); padding: 0; width: 1100px; max-width: 95vw; }
        dialog::backdrop { background: rgba(0,0,0,0.5); }
        .modal-header { background: #e6f2ff; padding: 10px 20px; border-bottom: 1px solid #ccc; }
        .modal-header h3 { margin: 0; font-size: 16px; }
        .modal-body { padding: 18px; }
        
        .modal-split-container { display: flex; gap: 20px; align-items: stretch; }
        .modal-left-form { flex: 1.2; min-width: 520px; }
        .modal-right-memos { flex: 1; background: #f8f9fa; padding: 15px; border: 1px solid #ccc; border-radius: 6px; font-size: 12px; box-sizing: border-box; max-height: 520px; overflow-y: auto; display: flex; flex-direction: column; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { font-weight: bold; margin-bottom: 3px; font-size: 12px; color: #444; }
        .form-group input, .form-group select, .form-group textarea { padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; box-sizing: border-box; }
        
        .dialog-buttons { display: flex; justify-content: space-between; margin-top: 15px; padding-top: 10px; border-top: 1px solid #eee; }
        .dialog-buttons button { padding: 6px 16px; font-size: 13px; cursor: pointer; }
        
        .memo-view-block { margin-bottom: 12px; padding: 6px; background: #fff; border: 1px solid #e3e6ec; border-radius: 4px; white-space: pre-wrap; word-break: break-all; font-family: monospace; }
        .btn-quick-keep { background: #ff9800; color: white; border: none; border-radius: 4px; padding: 3px 8px; font-size: 11px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<a href="index.php" class="home-icon-btn" title="システムメニューに戻る">🏠</a>
<button type="button" class="today-scroll-btn" onclick="scrollToTodayColumn()" title="本日の日付列へジャンプ">今日</button>

<h2 style="margin: 0 0 10px 0; font-size: 20px;">内視鏡室 予約管理システム（横型マトリクス）</h2>

<div class="nav-bar" style="display: flex; align-items: center; justify-content: space-between; background: #fff; padding: 4px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; gap: 10px;">
    
    <div class="nav-buttons">
        <div class="dept-tabs">
            <a href="?dept=PROCT&year=<?=$year?>&month=<?=$month?>&show_canceled=<?=$show_canceled?'1':'0'?>&doctor=<?=urlencode($selected_doctor)?>" class="dept-tab <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛門科</a>
            <a href="?dept=GASTRO&year=<?=$year?>&month=<?=$month?>&show_canceled=<?=$show_canceled?'1':'0'?>&doctor=<?=urlencode($selected_doctor)?>" class="dept-tab <?=$dept==='GASTRO'?'active-GASTRO':''?>">⚪ 胃腸科</a>
        </div>

        <a href="?dept=<?=$dept?>&year=<?=$prev_year?>&month=<?=$prev_month?>&show_canceled=<?=$show_canceled?'1':'0'?>&doctor=<?=urlencode($selected_doctor)?>"><button class="month-arrow-btn">◀ <?=$prev_month?>月</button></a>
        <span class="current-month-display"><?=$year?>年 <?=$month?>月</span>
        <a href="?dept=<?=$dept?>&year=<?=$next_year?>&month=<?=$next_month?>&show_canceled=<?=$show_canceled?'1':'0'?>&doctor=<?=urlencode($selected_doctor)?>"><button class="month-arrow-btn"><?=$next_month?>月 ▶</button></a>
        
        <select id="filter_doctor" onchange="filterByDoctor(this.value)" style="padding: 2px 6px; font-size: 12px; font-weight: bold; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; margin-left: 5px; height: 34px;">
            <option value="">👤 全ての医師を表示</option>
            <?php foreach ($doctors as $d): ?>
                <option value="<?=htmlspecialchars($d, ENT_QUOTES, 'UTF-8')?>" <?=$selected_doctor === $d ? 'selected' : ''?>>
                    <?=htmlspecialchars($d, ENT_QUOTES, 'UTF-8')?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="scroll-capsule" title="画面をスクロールします">
            <button class="scroll-arrow-btn" onclick="smoothScrollMatrix('left')" title="左へスクロール">◀</button>
            <button class="scroll-arrow-btn" onclick="smoothScrollMatrix('up')" title="上へスクロール">🔼</button>
            <button class="scroll-arrow-btn" onclick="smoothScrollMatrix('down')" title="下へスクロール">🔽</button>
            <button class="scroll-arrow-btn" onclick="smoothScrollMatrix('right')" title="rightへスクロール">▶</button>
        </div>
    </div>
    
    <div class="config-panel" style="display: flex; align-items: center; gap: 12px; background: transparent; padding: 0; border: none; font-size: 12px; border-left: 1px solid #ddd; padding-left: 12px;">
        <div class="auto-refresh-badge" onclick="manualRefreshCalendar()" style="padding: 0; font-size: 11px; color: #666;" title="クリックで今すぐ最新状態に強制再描画">
            <span class="clock-icon" style="font-size:12px;">🕒</span>
            <span>5分自動更新</span>
        </div>

        <label style="cursor: pointer; display: flex; align-items: center; gap: 2px; color: #6f42c1;" title="チェックを入れるとカレンダー上の患者名を一瞬で●●に秘匿します（再読込なし）">
            <input type="checkbox" id="chk_mask_name" onchange="toggleNameMask(this.checked)">🥷 伏字
        </label>

        <label style="cursor: pointer; display: flex; align-items: center; gap: 2px; color: #0056b3;" title="画面起動時に今日の列を一番左に自動移動します">
            <input type="checkbox" id="chk_auto_scroll" onchange="saveScrollPreference(this.checked)">📅 起動時移動
        </label>
        
        <label style="cursor: pointer; display: flex; align-items: center; gap: 2px; color:#555;">
            <input type="checkbox" id="toggle_canceled" <?=$show_canceled ? 'checked' : ''?> onchange="toggleCanceledMode(this.checked)">🗑️ 削除
        </label>
    </div>
</div>

<div class="matrix-container" id="mainMatrixContainer">
    <table class="matrix-table">
        <thead>
            <tr class="header-row">
                <th class="fixed-waku-col">日付 / 曜日</th>
                <?php
                $current_date_str = date('Y-m-d'); //[cite: 5]
                $week_ja = ['日', '月', '火', '水', '木', '金', '土']; //[cite: 5]
                
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day); //[cite: 5]
                    $day_check = check_onokai_calendar($date_str, null); //[cite: 5]
                    $cell_style = get_onokai_cell_style($date_str); //[cite: 5]
                    $ts = strtotime($date_str); $w = date('w', $ts); //[cite: 5]
                    
                    $text = "{$month}/{$day} ({$week_ja[$w]})"; //[cite: 5]
                    if ($day_check['is_holiday']) { 
                        $text = "{$month}/{$day} ({$week_ja[$w]}・祝)";  //[cite: 5]
                    }
                    
                    $id_attr = ($date_str === $current_date_str) ? ' id="col-today"' : ''; //[cite: 5]
                    echo "<th{$id_attr} style='{$cell_style['css']}'>{$text}</th>"; //[cite: 5]
                }
                ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($waku_list as $waku_time): ?>
                <tr>
                    <td class="fixed-waku-col"><?=$waku_time?></td>
                    
                    <?php 
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day); //[cite: 5]
                        $waku_check = check_onokai_calendar($date_str, $waku_time); //[cite: 5]
                        $td_style_info = get_onokai_cell_style($date_str, $waku_time); //[cite: 5]
                        
                        echo "<td class='waku-cell' 
                                  style='{$td_style_info['css']}'
                                  data-date='{$date_str}' 
                                  data-waku='{$waku_time}'
                                  onclick='openInsertModal(this, event)'
                                  ondragover='allowDrop(event)' 
                                  ondragenter='dragEnter(this)'
                                  ondragleave='dragLeave(this)'
                                  ondrop='dropWaku(this, event)'>"; //[cite: 5]
                        
                        if (isset($schedule_matrix[$date_str][$waku_time])) {
                            foreach ($schedule_matrix[$date_str][$waku_time] as $data) {
                                renderPatientBox($data, $date_str); //[cite: 5]
                            }
                        } else {
                            if ($waku_check['is_closed'] && !empty($waku_check['reason'])) { echo "<span class='closed-text'>休診</span>"; } //[cite: 5]
                        }
                        echo "</td>"; //[cite: 5]
                    }
                    ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<dialog id="scheduleDialog">
    <form method="POST" action="action_schedule.php" id="dialogScheduleForm">
        <div class="modal-header"><h3 id="modalTitle">予約登録</h3></div>
        <div class="modal-body">
            <div class="modal-split-container">
                
                <div class="modal-left-form">
                    <input type="hidden" name="schedule_id" id="f_id">
                    <input type="hidden" name="action_type" id="f_action" value="insert">
                    
                    <div class="form-grid">
                        <div class="form-group"><label>検査日</label><input type="date" name="exam_date" id="f_date" required></div>
                        <div class="form-group">
                            <label>時間枠</label>
                            <select name="scheduled_time" id="f_waku" required>
                                <?php foreach($waku_list as $w): ?><option value="<?=$w?>"><?=htmlspecialchars($w, ENT_QUOTES, 'UTF-8')?></option><?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group full-width" style="background: #eef7ff; padding: 6px; border-radius: 4px; border: 1px solid #b4c6e7;">
                            <label style="color: #0056b3; font-weight: bold;">🏥 予約対象の診療科（引越し・科の変更用）</label>
                            <select name="department_code" id="f_dept" style="font-weight: bold;">
                                <option value="PROCT">🟢 肛門科 (PROCT)</option>
                                <option value="GASTRO">⚪ 胃腸科 (GASTRO)</option>
                            </select>
                        </div>

                        <div class="form-group"><label>カルテID</label><input type="text" name="patient_id" id="f_pid" onblur="fetchPatientFromMALL(this.value)"></div>
                        <div class="form-group">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <label>患者氏名</label>
                                <button type="button" class="btn-quick-keep" id="btn_quick_keep" onclick="applyTemporaryKeep()">⚡ 枠だけ仮確保</button>
                            </div>
                            <input type="text" name="patient_name" id="f_pname" required>
                        </div>
                        
                        <div class="form-group">
                            <label>患者ヨミガナ</label>
                            <input type="text" name="patient_kana" id="f_kana">
                        </div>
                        <div class="form-group"><label>生年月日</label><input type="date" name="birthday" id="f_birthday"></div>
                        
                        <div class="form-group">
                            <label>担当医師</label>
                            <select name="doctor_name" id="f_doc">
                                <?php foreach ($doctors as $d): ?><option value="<?=$d?>"><?=$d?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>検査種別</label>
                            <select name="exam_type" id="f_type">
                                <?php foreach ($types as $t): ?><option value="<?=$t?>"><?=$t?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>来院時刻</label>
                            <select name="arrival_time" id="f_arrival">
                                <option value="">---</option>
                                <?php foreach ($arrivals as $a): ?><option value="<?=$a?>"><?=$a?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>実施場所（物理施設部屋）</label>
                            <select name="exam_location" id="f_loc">
                                <?php foreach ($locations as $l): ?><option value="<?=$l?>"><?=$l?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full-width"><label>備考欄（システム内の固有備考）</label><textarea name="remarks" id="f_remarks" rows="3"></textarea></div>
                    </div>
                </div>
                
                <div class="modal-right-memos" id="mall_memos_area">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom:1px solid #bbb; margin-bottom:10px; padding-bottom: 6px; position: sticky; top: 0; background: #f8f9fa; z-index: 1;">
                        <span style="font-weight:bold; color:#2c3e50; font-size:13px;">📋 電子カルテ（MALL）同期最新メモ</span>
                        <button type="button" id="btn_reload_memo" onclick="reloadMallMemoText()" style="padding: 3px 8px; font-size: 11px; cursor: pointer; background: #2c3e50; color: white; border: none; border-radius: 3px;">🔄 メモ再読み込み</button>
                    </div>
                    <div id="memo_today_view" class="memo-view-block"></div>
                    <div id="memo_karte_view" class="memo-view-block"></div>
                    <div id="memo_patient_view" class="memo-view-block"></div>
                </div>
                
            </div>
            
            <div class="dialog-buttons">
                <button type="button" onclick="document.getElementById('scheduleDialog').close()">閉じる</button>
                <button type="submit" id="btnDelete" name="delete" value="1" style="background-color:#dc3545; color:white; display:none;" onclick="return confirm('本当に削除しますか？')">削除する</button>
                <button type="submit" id="btnRestore" name="restore" value="1" style="background-color:#007bff; color:white; display:none;" onclick="return confirm('この予約を復活しますか？')">予約を復活する</button>
                <button type="submit" id="btnSave" style="background-color:#28a745; color:white;">保存する</button>
            </div>
        </div>
    </form>
</dialog>

<script>
const dialog = document.getElementById('scheduleDialog'); //[cite: 5]

setInterval(function() { 
    if (dialog && !dialog.open) { window.location.href = window.location.href; } //[cite: 5]
}, 300000); //[cite: 5]

function manualRefreshCalendar() { window.location.href = window.location.href; } //[cite: 5]

function scrollToTodayColumn() {
    const container = document.getElementById('mainMatrixContainer'); //[cite: 5]
    const todayCol = document.getElementById('col-today'); //[cite: 5]
    if (container && todayCol) {
        container.scrollTo({ left: todayCol.offsetLeft - 120, behavior: 'smooth' }); //[cite: 5]
    }
} //[cite: 5]

function toggleNameMask(isChecked) {
    if (isChecked) { document.body.classList.add('mask-active'); } else { document.body.classList.remove('mask-active'); } //[cite: 5]
    localStorage.setItem('onokai_mask_patient_name', isChecked ? '1' : '0'); //[cite: 5]
} //[cite: 5]

function saveScrollPreference(isChecked) { localStorage.setItem('onokai_auto_scroll_today', isChecked ? '1' : '0'); } //[cite: 5]

window.addEventListener('DOMContentLoaded', () => {
    const chkScroll = document.getElementById('chk_auto_scroll'); //[cite: 5]
    const savedScroll = localStorage.getItem('onokai_auto_scroll_today') || '0'; //[cite: 5]
    if (chkScroll) {
        chkScroll.checked = (savedScroll === '1'); //[cite: 5]
        if (chkScroll.checked) { setTimeout(scrollToTodayColumn, 100); } //[cite: 5]
    }
    const chkMask = document.getElementById('chk_mask_name'); //[cite: 5]
    const savedMask = localStorage.getItem('onokai_mask_patient_name') || '0'; //[cite: 5]
    if (chkMask) {
        chkMask.checked = (savedMask === '1'); //[cite: 5]
        toggleNameMask(chkMask.checked); //[cite: 5]
    }
}); //[cite: 5]

dialog.addEventListener('keydown', function(event) {
    if (event.key === 'Enter') {
        if (event.target.tagName === 'TEXTAREA') return; //[cite: 5]
        event.preventDefault(); //[cite: 5]
        if (event.target.id === 'f_pid') { fetchPatientFromMALL(event.target.value); } //[cite: 5]
    }
}); //[cite: 5]

function filterByDoctor(doctorName) {
    const url = new URL(window.location.href); //[cite: 5]
    if (doctorName) { url.searchParams.set('doctor', doctorName); } else { url.searchParams.delete('doctor'); } //[cite: 5]
    window.location.href = url.toString(); //[cite: 5]
} //[cite: 5]

function toggleCanceledMode(isChecked) {
    const url = new URL(window.location.href); url.searchParams.set('show_canceled', isChecked ? '1' : '0'); window.location.href = url.toString(); //[cite: 5]
} //[cite: 5]

function applyTemporaryKeep() {
    document.getElementById('f_pname').value = "※仮予約（キープ）"; document.getElementById('f_pid').value = ""; document.getElementById('f_birthday').value = ""; document.getElementById('f_kana').value = ""; //[cite: 5]
} //[cite: 5]

function smoothScrollMatrix(direction) {
    const container = document.getElementById('mainMatrixContainer'); //[cite: 5]
    if (!container) return; //[cite: 5]
    const xAmount = 350, yAmount = 180; //[cite: 5]
    switch (direction) {
        case 'left':  container.scrollTo({ left: container.scrollLeft - xAmount, behavior: 'smooth' }); break; //[cite: 5]
        case 'right': container.scrollTo({ left: container.scrollLeft + xAmount, behavior: 'smooth' }); break; //[cite: 5]
        case 'up':    container.scrollTo({ top: container.scrollTop - yAmount, behavior: 'smooth' }); break; //[cite: 5]
        case 'down':  container.scrollTo({ top: container.scrollTop + yAmount, behavior: 'smooth' }); break; //[cite: 5]
    }
} //[cite: 5]

function jsGetAgeBadgeHTML(birthday, examDate) {
    if (!birthday || birthday === '---' || !examDate || examDate === 'UNKNOWN') return ''; //[cite: 5]
    const birthDate = new Date(birthday); //[cite: 5]
    const exam = new Date(examDate); //[cite: 5]
    if (isNaN(birthDate.getTime()) || isNaN(exam.getTime())) return ''; //[cite: 5]
    
    let age = exam.getFullYear() - birthDate.getFullYear(); //[cite: 5]
    const m = exam.getMonth() - birthDate.getMonth(); //[cite: 5]
    if (m < 0 || (m === 0 && exam.getDate() < birthDate.getDate())) {
        age--; //[cite: 5]
    }
    if (age < 0) return ''; //[cite: 5]

    let style = ""; //[cite: 5]
    if (age < 65) {
        style = "background-color: #e0f2fe; color: #0369a1;"; //[cite: 5]
    } else if (age >= 65 && age <= 74) {
        style = "background-color: #fef08a; color: #713f12; font-weight: bold;"; //[cite: 5]
    } else if (age >= 75 && age <= 85) {
        style = "background-color: #fca5a5; color: #991b1b; font-weight: bold;"; //[cite: 5]
    } else {
        style = "background-color: #ef4444; color: #ffffff; font-weight: bold;"; //[cite: 5]
    }
    return `<span class="age-badge" style="display: inline-block; padding: 1px 5px; font-size: 10px; border-radius: 4px; line-height: 1.1; ${style}">${age}歳</span>`; //[cite: 5]
}

function openInsertModal(cell, event) {
    if(event.target !== cell) return; //[cite: 5]
    document.getElementById('f_action').value = 'insert'; //[cite: 5]
    document.getElementById('modalTitle').innerText = '新規予約登録'; //[cite: 5]
    document.getElementById('f_id').value = ''; //[cite: 5]
    document.getElementById('f_date').value = cell.dataset.date; //[cite: 5]
    document.getElementById('f_waku').value = cell.dataset.waku; //[cite: 5]
    document.getElementById('f_dept').value = "<?=htmlspecialchars($dept, ENT_QUOTES, 'UTF-8')?>"; //[cite: 5]
    
    document.getElementById('f_pid').value = '';  //[cite: 5]
    document.getElementById('f_pname').value = '';  //[cite: 5]
    document.getElementById('f_kana').value = '';  //[cite: 5]
    document.getElementById('f_birthday').value = '';  //[cite: 5]
    document.getElementById('f_remarks').value = ''; //[cite: 5]
    
    document.getElementById('memo_today_view').innerHTML = '<span style="color:#aaa; font-style:italic;">====== 最新の本日メモ ====== なし</span>'; //[cite: 5]
    document.getElementById('memo_karte_view').innerHTML = '<span style="color:#aaa; font-style:italic;">====== 最新のカルテメモ ====== なし</span>'; //[cite: 5]
    document.getElementById('memo_patient_view').innerHTML = '<span style="color:#aaa; font-style:italic;">====== 最新の患者メモ ====== なし</span>'; //[cite: 5]
    
    document.getElementById('btnDelete').style.display = 'none'; document.getElementById('btnRestore').style.display = 'none'; //[cite: 5]
    document.getElementById('btnSave').style.display = 'block'; document.getElementById('btn_quick_keep').style.display = 'block'; //[cite: 5]
    setFormReadOnly(false); dialog.showModal(); //[cite: 5]
} //[cite: 5]

function openEditModalWithCtrlCheck(element, event) {
    event.stopPropagation(); const parentBox = element.closest('.patient-box'); const data = JSON.parse(parentBox.dataset.json); //[cite: 5]
    if (event.ctrlKey) {
        if (data.patient_id && data.patient_id !== '---') {
            window.open(`http://192.168.1.164/~rsn/2000.cgi?show=${encodeURIComponent(data.patient_id)}`, '_blank'); //[cite: 5]
        } else { alert("患者IDが未登録のため画像表示を行えません。"); } //[cite: 5]
        return; //[cite: 5]
    }
    openEditModal(parentBox, event); //[cite: 5]
} //[cite: 5]

function openEditModal(box, event) {
    if(event) event.stopPropagation(); //[cite: 5]
    const data = JSON.parse(box.dataset.json); //[cite: 5]
    const isCanceled = data.is_canceled === true || data.is_canceled === 1 || data.is_canceled === 't'; //[cite: 5]

    document.getElementById('f_id').value = data.schedule_id; //[cite: 5]
    document.getElementById('f_date').value = data.exam_date; //[cite: 5]
    document.getElementById('f_waku').value = data.scheduled_time; //[cite: 5]
    document.getElementById('f_dept').value = data.department_code ? data.department_code : "<?=htmlspecialchars($dept, ENT_QUOTES, 'UTF-8')?>"; //[cite: 5]
    document.getElementById('f_pid').value = data.patient_id; //[cite: 5]
    document.getElementById('f_pname').value = data.patient_name; //[cite: 5]
    document.getElementById('f_kana').value = data.patient_kana || ''; //[cite: 5]
    document.getElementById('f_birthday').value = (data.birthday && data.birthday.trim().length >= 10) ? data.birthday.trim().substring(0, 10) : ''; //[cite: 5]
    document.getElementById('f_doc').value = data.doctor_name; //[cite: 5]
    document.getElementById('f_type').value = data.exam_type; //[cite: 5]
    document.getElementById('f_arrival').value = data.arrival_time || ''; //[cite: 5]
    document.getElementById('f_loc').value = data.exam_location; //[cite: 5]
    document.getElementById('f_remarks').value = data.remarks || ''; //[cite: 5]
    
    function generateMemoHTML(title, content, color) {
        if (!content) return `<span style="color:#aaa; font-style:italic;">====== ${title} ====== なし</span>`; //[cite: 5]
        return `<span style="color:${color}; font-weight:bold;">====== ${title} ======</span><div>${escapeHTML(content).replace(/\n/g, '<br>')}</div>`; //[cite: 5]
    }
    document.getElementById('memo_today_view').innerHTML   = generateMemoHTML('最新の本日メモ', data.memo_today, '#d9383a'); //[cite: 5]
    document.getElementById('memo_karte_view').innerHTML   = generateMemoHTML('最新のカルテメモ', data.memo_karte, '#0056b3'); //[cite: 5]
    document.getElementById('memo_patient_view').innerHTML = generateMemoHTML('最新の患者メモ', data.memo_patient, '#1e5a34'); //[cite: 5]
    document.getElementById('btn_quick_keep').style.display = 'none'; //[cite: 5]

    if (isCanceled) {
        document.getElementById('f_action').value = 'restore'; document.getElementById('modalTitle').innerText = '⚠️ 削除済み予約データの確認・復活'; //[cite: 5]
        document.getElementById('btnDelete').style.display = 'none'; document.getElementById('btnSave').style.display = 'none'; document.getElementById('btnRestore').style.display = 'block'; //[cite: 5]
        setFormReadOnly(true); //[cite: 5]
    } else {
        document.getElementById('f_action').value = 'update'; document.getElementById('modalTitle').innerText = '予約データの変更・削除'; //[cite: 5]
        document.getElementById('btnDelete').style.display = 'block'; document.getElementById('btnSave').style.display = 'block'; document.getElementById('btnRestore').style.display = 'none'; //[cite: 5]
        setFormReadOnly(false); //[cite: 5]
    }
    dialog.showModal(); //[cite: 5]
} //[cite: 5]

function setFormReadOnly(isLock) {
    ['f_date', 'f_waku', 'f_pid', 'f_pname', 'f_kana', 'f_birthday', 'f_doc', 'f_type', 'f_arrival', 'f_loc', 'f_remarks'].forEach(id => {
        const el = document.getElementById(id); if (el) el.disabled = isLock; //[cite: 5]
    });
} //[cite: 5]

function fetchPatientFromMALL(patientNo) {
    if (!patientNo || isNaN(patientNo)) return; //[cite: 5]
    const nameInput = document.getElementById('f_pname'); //[cite: 5]
    const birthdayInput = document.getElementById('f_birthday'); //[cite: 5]
    const kanaInput = document.getElementById('f_kana'); //[cite: 5]
    
    nameInput.placeholder = "電子カルテ照会中..."; //[cite: 5]
    
    fetch(`get_mall_patient.php?patient_no=${patientNo}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                nameInput.value = data.patient_name || ''; //[cite: 5]
                kanaInput.value = data.patient_kana || ''; //[cite: 5]
                birthdayInput.value = (data.birthday && data.birthday.trim().length >= 10) ? data.birthday.trim().substring(0, 10) : ''; //[cite: 5]
                nameInput.placeholder = ""; //[cite: 5]
                autoLoadMallMemos(patientNo); //[cite: 5]
            } else { 
                nameInput.placeholder = "見つかりませんでした"; //[cite: 5]
            }
        })
        .catch(err => { 
            nameInput.placeholder = "連携エラー"; //[cite: 5]
        });
} //[cite: 5]

function autoLoadMallMemos(patientId) {
    const btn = document.getElementById('btn_reload_memo'); //[cite: 5]
    if (!patientId || isNaN(patientId)) return; //[cite: 5]
    if (btn) { btn.innerText = "同期中..."; btn.disabled = true; } //[cite: 5]
    
    fetch(`sync_mall_memo.php?patient_id=${patientId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                function generateMemoHTML(title, content, color) {
                    if (!content) return `<span style="color:#aaa; font-style:italic;">====== ${title} ====== なし</span>`; //[cite: 5]
                    return `<span style="color:${color}; font-weight:bold;">====== ${title} ======</span><div>${escapeHTML(content).replace(/\n/g, '<br>')}</div>`; //[cite: 5]
                }
                document.getElementById('memo_today_view').innerHTML   = generateMemoHTML('最新の本日メモ', data.memo_today, '#d9383a'); //[cite: 5]
                document.getElementById('memo_karte_view').innerHTML   = generateMemoHTML('最新のカルテメモ', data.memo_karte, '#0056b3'); //[cite: 5]
                document.getElementById('memo_patient_view').innerHTML = generateMemoHTML('最新の患者メモ', data.memo_patient, '#1e5a34'); //[cite: 5]
                updateCachedBoxJsonMemos(patientId, data); //[cite: 5]
            }
        })
        .catch(err => { console.error("自動メモ同期エラー:", err); }) //[cite: 5]
        .finally(() => { if (btn) { btn.innerText = "🔄 メモ再読み込み"; btn.disabled = false; } }); //[cite: 5]
} //[cite: 5]

function updateCachedBoxJsonMemos(patientId, memoData) {
    const boxes = document.querySelectorAll(`.patient-box`); //[cite: 5]
    boxes.forEach(box => {
        try {
            let bData = JSON.parse(box.dataset.json); //[cite: 5]
            if (String(bData.patient_id) === String(patientId)) {
                bData.memo_today = memoData.memo_today; //[cite: 5]
                bData.memo_karte = memoData.memo_karte; //[cite: 5]
                bData.memo_patient = memoData.memo_patient; //[cite: 5]
                box.dataset.json = JSON.stringify(bData); //[cite: 5]
            }
        } catch(e){}
    });
} //[cite: 5]

function reloadMallMemoText() {
    const patientId = document.getElementById('f_pid').value; const btn = document.getElementById('btn_reload_memo'); //[cite: 5]
    if (!patientId || isNaN(patientId)) { alert("カルテIDが正しくありません。"); return; } //[cite: 5]
    btn.innerText = "同期中..."; btn.disabled = true; //[cite: 5]
    fetch(`sync_mall_memo.php?patient_id=${patientId}`).then(res => res.json()).then(data => {
        if (data.success) {
            function generateMemoHTML(title, content, color) {
                if (!content) return `<span style="color:#aaa; font-style:italic;">====== ${title} ====== なし</span>`; //[cite: 5]
                return `<span style="color:${color}; font-weight:bold;">====== ${title} ======</span><div>${escapeHTML(content).replace(/\n/g, '<br>')}</div>`; //[cite: 5]
            }
            document.getElementById('memo_today_view').innerHTML   = generateMemoHTML('最新の本日メモ', data.memo_today, '#d9383a'); //[cite: 5]
            document.getElementById('memo_karte_view').innerHTML   = generateMemoHTML('最新のカルテメモ', data.memo_karte, '#0056b3'); //[cite: 5]
            document.getElementById('memo_patient_view').innerHTML = generateMemoHTML('最新の患者メモ', data.memo_patient, '#1e5a34'); //[cite: 5]
            updateCachedBoxJsonMemos(patientId, data); //[cite: 5]
        } else { alert("同期失敗: " + data.message); } //[cite: 5]
    }).finally(() => { btn.innerText = "🔄 メモ再読み込み"; btn.disabled = false; }); //[cite: 5]
} //[cite: 5]
function escapeHTML(str) { if (!str) return ''; return str.replace(/[&<>'"]/g, t => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[t] || t)); } //[cite: 5]

let draggedBox = null; //[cite: 5]
function dragStart(box, event) { draggedBox = box; event.dataTransfer.setData('text/plain', String(box.dataset.scheduleId)); } //[cite: 5]
function allowDrop(event) { event.preventDefault(); } //[cite: 5]
function dragEnter(cell) { cell.classList.add('dragover'); } //[cite: 5]
function dragLeave(cell) { cell.classList.remove('dragover'); } //[cite: 5]

function dropWaku(cell, event) {
    event.preventDefault(); cell.classList.remove('dragover'); //[cite: 5]
    const scheduleId = event.dataTransfer.getData('text/plain'); //[cite: 5]
    if (!scheduleId || scheduleId === "undefined" || !draggedBox) return; //[cite: 5]

    const targetDate = cell.dataset.date, targetWaku = cell.dataset.waku; //[cite: 5]
    const formData = new FormData(); //[cite: 5]
    formData.append('action_type', 'move'); formData.append('schedule_id', scheduleId); formData.append('exam_date', targetDate); formData.append('scheduled_time', targetWaku); //[cite: 5]

    fetch('action_schedule.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            cell.appendChild(draggedBox); //[cite: 5]
            const boxData = JSON.parse(draggedBox.dataset.json); //[cite: 5]
            boxData.exam_date = targetDate; boxData.scheduled_time = targetWaku; //[cite: 5]
            draggedBox.dataset.json = JSON.stringify(boxData); //[cite: 5]
            
            const pidRow = draggedBox.querySelector('.pid'); //[cite: 5]
            if (pidRow) {
                let isCanceledMark = boxData.is_canceled ? ' [削除]' : ''; //[cite: 5]
                const ageBadgeHTML = jsGetAgeBadgeHTML(boxData.birthday, targetDate); //[cite: 5]
                pidRow.innerHTML = `<span style="font-weight: 800; color: #1e293b; font-size: 11px;">ID:${escapeHTML(boxData.patient_id)}${isCanceledMark}</span>${ageBadgeHTML}`; //[cite: 5]
            }
        } else { alert("移動に失敗しました: " + (data.message || "不明なエラー")); window.location.reload(); } //[cite: 5]
    })
    .catch(err => { console.error(err); }) //[cite: 5]
    .finally(() => { draggedBox = null; }); //[cite: 5]
} //[cite: 5]

// 安定Ajaxインターセプト（💡保存時にMALLメモ同期も直前で確実にトリガーするよう補強）
window.addEventListener('DOMContentLoaded', () => {
    const modalForm = document.getElementById('dialogScheduleForm'); //[cite: 5]
    if (!modalForm) return; //[cite: 5]

    let clickedSubmitButton = null; //[cite: 5]
    modalForm.querySelectorAll('button[type="submit"]').forEach(btn => {
        btn.addEventListener('click', function() { clickedSubmitButton = this; }); //[cite: 5]
    });

    modalForm.addEventListener('submit', function(event) {
        event.preventDefault(); 
        event.stopPropagation(); //[cite: 5]

        const submitButtons = this.querySelectorAll('button[type="submit"]'); //[cite: 5]
        submitButtons.forEach(btn => btn.disabled = true); //[cite: 5]

        const patientIdVal = document.getElementById('f_pid').value;
        const executeSaveAction = () => {
            const formData = new FormData(this); //[cite: 5]
            if (clickedSubmitButton) { formData.append(clickedSubmitButton.name, clickedSubmitButton.value); } //[cite: 5]

            fetch('action_schedule.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(resData => {
                if (!resData.success) { alert('エラーが発生しました:\n' + resData.message); return; } //[cite: 5]

                const targetScheduleId = String(resData.schedule_id || formData.get('schedule_id')); //[cite: 5]
                const oldBox = document.querySelector('.patient-box[data-schedule-id="' + targetScheduleId + '"]'); //[cite: 5]
                
                let oldMemoToday = null, oldMemoKarte = null, oldMemoPatient = null; //[cite: 5]
                if (oldBox) {
                    try {
                        let oldJson = JSON.parse(oldBox.dataset.json); //[cite: 5]
                        oldMemoToday = oldJson.memo_today; //[cite: 5]
                        oldMemoKarte = oldJson.memo_karte; //[cite: 5]
                        oldMemoPatient = oldJson.memo_patient; //[cite: 5]
                    } catch(e){}
                }

                const isCanceledAction = (resData.action === 'delete'); //[cite: 5]
                const databaseSyncedData = {
                    schedule_id: targetScheduleId,
                    exam_date:       resData.exam_date       || formData.get('exam_date'),
                    scheduled_time:  resData.scheduled_time  || formData.get('scheduled_time'),
                    department_code: resData.department_code || formData.get('department_code'),
                    patient_id:      resData.patient_id      || formData.get('patient_id') || '---',
                    patient_name:    resData.patient_name    || formData.get('patient_name'),
                    patient_kana:    resData.patient_kana    || formData.get('patient_kana') || '',
                    birthday:        resData.birthday        || formData.get('birthday'),
                    doctor_name:     resData.doctor_name     || formData.get('doctor_name'),
                    exam_type:       resData.exam_type       || formData.get('exam_type'),
                    arrival_time:    resData.arrival_time    || formData.get('arrival_time'),
                    exam_location:   resData.exam_location   || formData.get('exam_location'),
                    remarks:         resData.remarks         || formData.get('remarks'),
                    is_canceled:     isCanceledAction,
                    memo_today:      oldMemoToday,
                    memo_karte:      oldMemoKarte,
                    memo_patient:    oldMemoPatient
                }; //[cite: 5]

                const currentViewDept = "<?=htmlspecialchars($dept, ENT_QUOTES, 'UTF-8')?>"; //[cite: 5]

                if (resData.action === 'delete') {
                    const showCanceledMode = new URL(window.location.href).searchParams.get('show_canceled') === '1'; //[cite: 5]
                    if (oldBox) {
                        if (showCanceledMode) {
                            oldBox.classList.add('is-canceled-box'); //[cite: 5]
                            oldBox.setAttribute('draggable', 'false'); //[cite: 5]
                            const pnameEl = oldBox.querySelector('.real-pname'); //[cite: 5]
                            if (pnameEl) pnameEl.innerText = databaseSyncedData.patient_name; //[cite: 5]
                            let boxData = JSON.parse(oldBox.dataset.json); boxData.is_canceled = true; //[cite: 5]
                            oldBox.dataset.json = JSON.stringify(boxData); //[cite: 5]
                        } else { oldBox.remove(); } //[cite: 5]
                    }
                }
                else if (resData.action === 'insert' || resData.action === 'restore') {
                    if (oldBox) oldBox.remove(); //[cite: 5]

                    if (databaseSyncedData.department_code === currentViewDept) {
                        let targetCell = document.querySelector(`td[data-date="${databaseSyncedData.exam_date}"][data-waku="${databaseSyncedData.scheduled_time}"]`); //[cite: 5]
                        if (targetCell) {
                            const newBox = document.createElement('div'); //[cite: 5]
                            newBox.className = 'patient-box'; //[cite: 5]
                            newBox.setAttribute('draggable', 'true'); //[cite: 5]
                            newBox.setAttribute('data-schedule-id', databaseSyncedData.schedule_id); //[cite: 5]
                            
                            const colorMap = { 'GIF': '#e0ffff', 'ポリペク': '#dda0dd', 'TCF': '#e2f0d9', 'W': '#c5e0b4' }; //[cite: 5]
                            newBox.style.backgroundColor = colorMap[databaseSyncedData.exam_type] ? colorMap[databaseSyncedData.exam_type] : '#fff2cc'; //[cite: 5]

                            if (databaseSyncedData.patient_name.includes('※仮予約')) { newBox.classList.add('is-temporary-box'); } //[cite: 5]

                            const tooltipText = `【検査】${databaseSyncedData.exam_type}\n【医師】${databaseSyncedData.doctor_name}\n【ヨミ】${databaseSyncedData.patient_kana || '未登録'}\n【備考】\n${databaseSyncedData.remarks || ''}`; //[cite: 5]
                            newBox.setAttribute('title', tooltipText); //[cite: 5]
                            newBox.dataset.json = JSON.stringify(databaseSyncedData); //[cite: 5]
                            
                            newBox.addEventListener('dragstart', function(e) { dragStart(this, e); }); //[cite: 5]

                            const ageBadgeHTML = jsGetAgeBadgeHTML(databaseSyncedData.birthday, databaseSyncedData.exam_date); //[cite: 5]
                            const bDateFormatted = (databaseSyncedData.birthday && databaseSyncedData.birthday.trim().length >= 10) ? databaseSyncedData.birthday.replace(/-/g, '/') : '---'; //[cite: 5]

                            let badgeStyle = "background-color: #e2e8f0; color: #475569;"; //[cite: 5]
                            const examType = databaseSyncedData.exam_type; //[cite: 5]
                            if (examType === 'GIF') {
                                badgeStyle = "background-color: #0ea5e9; color: #fff; font-weight: bold;"; //[cite: 5]
                            } else if (examType === 'TCF') {
                                badgeStyle = "background-color: #10b981; color: #fff; font-weight: bold;"; //[cite: 5]
                            } else if (examType === 'ポリペク') {
                                badgeStyle = "background-color: #8b5cf6; color: #fff; font-weight: bold;"; //[cite: 5]
                            } else if (examType === 'W') {
                                badgeStyle = "background-color: #22c55e; color: #fff; font-weight: bold;"; //[cite: 5]
                            }

                            newBox.innerHTML = `
                                <div class="pid" style="display:flex; justify-content:space-between; align-items:center; border-bottom: 1px dotted rgba(0,0,0,0.15); padding-bottom: 2px; margin-bottom: 3px;">
                                    <span style="font-weight: 800; color: #1e293b; font-size: 11px;">ID:${escapeHTML(databaseSyncedData.patient_id)}</span>
                                    ${ageBadgeHTML}
                                </div>
                                <div class="pname" title="通常クリックで編集・削除\n[Ctrl + クリック] で画像管理サーバを別窓起動" onclick="openEditModalWithCtrlCheck(this, event)">
                                    <span class="real-pname">${escapeHTML(databaseSyncedData.patient_name)}</span>
                                    <span class="masked-pname">●●●●</span>
                                </div>
                                <div class="pbirthday-row" style="display:flex; justify-content:space-between; align-items:center; margin-top: 3px; padding-top: 2px;">
                                    <div class="pbirthday" style="font-size: 9px; color: #64748b;">${escapeHTML(bDateFormatted)}</div>
                                    <span class="pinfo-badge" style="padding: 1px 6px; border-radius: 12px; font-size: 10px; ${badgeStyle}">
                                        ${escapeHTML(databaseSyncedData.exam_type)}
                                    </span>
                                </div>
                            `; //[cite: 5]
                            
                            targetCell.appendChild(newBox); //[cite: 5]
                            const chkMask = document.getElementById('chk_mask_name'); //[cite: 5]
                            if (chkMask && chkMask.checked) { toggleNameMask(true); } //[cite: 5]
                        }
                    }
                }
                else if (resData.action === 'update') {
                    if (oldBox) {
                        if (databaseSyncedData.department_code !== currentViewDept) {
                            oldBox.remove(); //[cite: 5]
                        } else {
                            oldBox.dataset.json = JSON.stringify(databaseSyncedData); //[cite: 5]
                            const colorMap = { 'GIF': '#e0ffff', 'ポリペク': '#dda0dd', 'TCF': '#e2f0d9', 'W': '#c5e0b4' }; //[cite: 5]
                            oldBox.style.backgroundColor = colorMap[databaseSyncedData.exam_type] ? colorMap[databaseSyncedData.exam_type] : '#fff2cc'; //[cite: 5]

                            const pidEl = oldBox.querySelector('.pid'); //[cite: 5]
                            if (pidEl) {
                                let isCanceledMark = databaseSyncedData.is_canceled ? ' [削除]' : ''; //[cite: 5]
                                const ageBadgeHTML = jsGetAgeBadgeHTML(databaseSyncedData.birthday, databaseSyncedData.exam_date); //[cite: 5]
                                pidEl.innerHTML = `<span style="font-weight: 800; color: #1e293b; font-size: 11px;">ID:${escapeHTML(databaseSyncedData.patient_id)}${isCanceledMark}</span>${ageBadgeHTML}`; //[cite: 5]
                            }

                            const pnameEl = oldBox.querySelector('.pname'); //[cite: 5]
                            if (pnameEl) {
                                pnameEl.innerHTML = `<span class="real-pname">${escapeHTML(databaseSyncedData.patient_name)}</span><span class="masked-pname">●●●●</span>`; //[cite: 5]
                            }
                            
                            const badgeEl = oldBox.querySelector('.pinfo-badge'); //[cite: 5]
                            if (badgeEl) {
                                badgeEl.innerText = databaseSyncedData.exam_type; //[cite: 5]
                                let badgeStyle = "padding: 1px 6px; border-radius: 12px; font-size: 10px; background-color: #e2e8f0; color: #475569;"; //[cite: 5]
                                const examType = databaseSyncedData.exam_type; //[cite: 5]
                                if (examType === 'GIF') {
                                    badgeStyle += "background-color: #0ea5e9; color: #fff; font-weight: bold;"; //[cite: 5]
                                } else if (examType === 'TCF') {
                                    badgeStyle += "background-color: #10b981; color: #fff; font-weight: bold;"; //[cite: 5]
                                } else if (examType === 'ポリペク') {
                                    badgeStyle += "background-color: #8b5cf6; color: #fff; font-weight: bold;"; //[cite: 5]
                                } else if (examType === 'W') {
                                    badgeStyle += "background-color: #22c55e; color: #fff; font-weight: bold;"; //[cite: 5]
                                }
                                badgeEl.style.cssText = badgeStyle; //[cite: 5]
                            }
                            
                            const bDateFormatted = (databaseSyncedData.birthday && databaseSyncedData.birthday.trim().length >= 10) ? databaseSyncedData.birthday.replace(/-/g, '/') : '---'; //[cite: 5]
                            oldBox.querySelector('.pbirthday').innerText = bDateFormatted; //[cite: 5]

                            const tooltipText = `【検査】${databaseSyncedData.exam_type}\n【医師】${databaseSyncedData.doctor_name}\n【ヨミ】${databaseSyncedData.patient_kana || '未登録'}\n【備考】\n${databaseSyncedData.remarks || ''}`; //[cite: 5]
                            oldBox.setAttribute('title', tooltipText); //[cite: 5]

                            let targetCell = document.querySelector(`td[data-date="${databaseSyncedData.exam_date}"][data-waku="${databaseSyncedData.scheduled_time}"]`); //[cite: 5]
                            if (targetCell && oldBox.parentElement !== targetCell) { targetCell.appendChild(oldBox); } //[cite: 5]
                        }
                    }
                }

                const dlg = document.getElementById('scheduleDialog'); //[cite: 5]
                if (dlg) dlg.close(); //[cite: 5]
            })
            .catch(err => { 
                alert("処理エラーが発生しました。コンソールログをご確認ください。"); //[cite: 5]
                console.error(err); //[cite: 5]
            })
            .finally(() => {
                submitButtons.forEach(btn => btn.disabled = false); //[cite: 5]
                clickedSubmitButton = null; //[cite: 5]
            });
        };

        // 保存ボタンが押された瞬間、カルテIDが存在すれば確実にバックグラウンドで sync_mall_memo.php を叩いてから保存を実行
        if (patientIdVal && patientIdVal !== '---' && !isNaN(patientIdVal)) {
            fetch(`sync_mall_memo.php?patient_id=${patientIdVal}`)
                .then(res => res.json())
                .catch(e => {})
                .finally(() => {
                    executeSaveAction();
                });
        } else {
            executeSaveAction();
        }
    });
}); //[cite: 5]
</script>
</body>
</html>