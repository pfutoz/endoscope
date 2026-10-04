<?php
/**
 * 生成日時: 2026-08-27 23:55:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\month_schedule.php
 * ファイル名: month_schedule.php
 * 
 * 内視鏡予約 ダッシュボード (month_schedule.php)
 * カレンダー形式で各日の空き状況を表示し、選択日の予約一覧を表示する
 * 
 * 主要機能:
 *   - カレンダー表示（月次ナビゲーション / 休診判定 / 曜日色分け）
 *   - 高速化：月間予約・未割当データの一括プリフェッチ（N+1問題解消）
 *   - 未来月クイック遷移（当月起点12ヶ月ピルナビ）
 *   - 医師・検査種別フィルタ
 *   - 使用/最大・残数・空き枠 表示モード切替（カレンダー用）
 *   - 選択日の予約一覧表示（m2_waku.display_order 順・固定）
 *   - 個別検査シート印刷（📋アイコン）
 *   - F/U登録リンク（/endoscope/followup/edit.php へ絶対パス）
 *   - 枠種別フィルタ（GIF/エコー/TCF 表示切替）
 *   - 空き枠表示スイッチ（デフォルトON / 常に表示）
 *   - ➕ アイコン：新規予約登録（modal_edit.php を別窓で開く）
 *   - ✏️ アイコン：既存予約編集（modal_edit.php を別窓で開く）
 *   - カレンダー色分け：空き＝薄い黄色、過去＝濃いグレー、休診＝薄い赤、今日＝クリーム色
 *   - 異常値（予約＞枠数）を赤枠で強調表示（両モード対応）
 *   - 休診日＋予約あり：赤枠＋⚠️マークで異常表示
 *   - 印刷時：空き枠表示ONの場合は空き枠も印刷
 *   - 画面上部ナビ：メニュー / 予約マトリクス / 空き枠検索 連携
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// ============================================================
// ★★★ セッション開始 ★★★
// ============================================================
session_start();

// ============================================================
// 1. 共通ライブラリ読み込み
// ============================================================
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ============================================================
// 2. パラメータ取得（セッション対応版・空き枠デフォルトON）
// ============================================================
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : 
        ($_SESSION['ui_state']['schedule_dept'] ?? 'PROCT');

$year = isset($_GET['year']) ? (int)$_GET['year'] : 
        (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr($_SESSION['ui_state']['schedule_month'], 0, 4) : (int)date('Y'));
$month = isset($_GET['month']) ? (int)$_GET['month'] : 
         (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr($_SESSION['ui_state']['schedule_month'], 5, 2) : (int)date('n'));

$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_doctor = isset($_GET['doctor']) ? trim($_GET['doctor']) : 'all';
$filter_exam = isset($_GET['exam']) ? trim($_GET['exam']) : 'all';
$print_mode = isset($_GET['print']) && $_GET['print'] === '1';
$sheet_single = isset($_GET['sheet_single']) ? (int)$_GET['sheet_single'] : 0;
$display_mode = isset($_GET['display_mode']) ? $_GET['display_mode'] : 'use_max';

// デフォルトを空き枠表示(true)
$show_empty = isset($_GET['show_empty']) ? ($_GET['show_empty'] === '1') : true;

if (!in_array($dept, ['PROCT', 'GASTRO'])) { $dept = 'PROCT'; }
if (!in_array($display_mode, ['use_max', 'remain', 'empty'])) { $display_mode = 'use_max'; }

$_SESSION['ui_state']['schedule_month'] = sprintf('%04d-%02d', $year, $month);
$_SESSION['ui_state']['schedule_dept'] = $dept;

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = (int)date('t', $first_day_timestamp);
$start_weekday = (int)date('w', $first_day_timestamp);

$start_date_month = sprintf('%04d-%02d-01', $year, $month);
$end_date_month   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$prev_month_ts = strtotime("-1 month", $first_day_timestamp);
$prev_year = (int)date('Y', $prev_month_ts);
$prev_month = (int)date('n', $prev_month_ts);
$next_month_ts = strtotime("+1 month", $first_day_timestamp);
$next_year = (int)date('Y', $next_month_ts);
$next_month = (int)date('n', $next_month_ts);

// クイックジャンプ用未来月リスト生成（当月起点12ヶ月分）
$quick_months = [];
$current_ts = strtotime(date('Y-m-01'));
for ($m_idx = 0; $m_idx < 12; $m_idx++) {
    $target_ts = strtotime("+$m_idx month", $current_ts);
    $q_year = (int)date('Y', $target_ts);
    $q_month = (int)date('n', $target_ts);
    $quick_months[] = [
        'year' => $q_year,
        'month' => $q_month,
        'is_active' => ($q_year === $year && $q_month === $month),
        'is_new_year' => ($q_month === 1 && $m_idx > 0)
    ];
}

// ============================================================
// ★★★ 高速化：月間予約データの一括プリフェッチ ★★★
// ============================================================
// 1. 月内の確定予約数（日付 × 医師 × 検査種別）
$stmt_pref = $pdo->prepare("
    SELECT 
        exam_date::text AS exam_date,
        doctor_name,
        exam_type,
        COUNT(*) AS cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date >= :start_date
      AND exam_date <= :end_date
      AND is_canceled = FALSE
    GROUP BY exam_date, doctor_name, exam_type
");
$stmt_pref->execute([
    ':dept' => $dept,
    ':start_date' => $start_date_month,
    ':end_date' => $end_date_month
]);

$month_reserved_cache = [];
while ($row = $stmt_pref->fetch(PDO::FETCH_ASSOC)) {
    $d = $row['exam_date'];
    $doc = $row['doctor_name'];
    $etype = $row['exam_type'];
    $cnt = (int)$row['cnt'];
    if (!isset($month_reserved_cache[$d])) {
        $month_reserved_cache[$d] = [];
    }
    if (!isset($month_reserved_cache[$d][$doc])) {
        $month_reserved_cache[$d][$doc] = [];
    }
    $month_reserved_cache[$d][$doc][$etype] = $cnt;
}

// 2. 月内の時間枠未割当データ
$stmt_unassigned = $pdo->prepare("
    SELECT 
        exam_date::text AS exam_date,
        exam_type,
        COUNT(*) AS cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date >= :start_date
      AND exam_date <= :end_date
      AND is_canceled = FALSE
      AND (scheduled_time IS NULL OR scheduled_time = '' OR scheduled_time = 'MISMATCH')
    GROUP BY exam_date, exam_type
");
$stmt_unassigned->execute([
    ':dept' => $dept,
    ':start_date' => $start_date_month,
    ':end_date' => $end_date_month
]);

$month_unassigned_cache = [];
while ($row = $stmt_unassigned->fetch(PDO::FETCH_ASSOC)) {
    $d = $row['exam_date'];
    $etype = $row['exam_type'] ?? 'その他';
    if (!isset($month_unassigned_cache[$d])) {
        $month_unassigned_cache[$d] = [];
    }
    $month_unassigned_cache[$d][$etype] = (int)$row['cnt'];
}

// ============================================================
// 3. ヘルパー関数
// ============================================================

function getExamIcon($exam_type) {
    $map = ['GIF' => '🔵', 'ECHO' => '🟠', 'TCF' => '🟢'];
    return $map[$exam_type] ?? '●';
}

function getExamLabel($exam_type) {
    $map = ['GIF' => 'GIF', 'ECHO' => 'エコー', 'TCF' => 'TCF'];
    return $map[$exam_type] ?? $exam_type;
}

/**
 * 選択日における医師別予約件数をキャッシュから集計
 */
function getDoctorCountsFast($doctors, $date_str, $cache) {
    $counts = [];
    $day_cache = $cache[$date_str] ?? [];
    foreach ($doctors as $doctor) {
        $doc_cache = $day_cache[$doctor] ?? [];
        $counts[$doctor] = array_sum($doc_cache);
    }
    return $counts;
}

/**
 * 1日分の枠使用状況をメモリ内キャッシュから高速集計
 */
function getDayDataFast($pdo, $dept, $date_str, $doctors, $cache, $filter_doctor = 'all', $filter_exam = 'all') {
    $result = [];
    $total_used = 0;
    $total_max = 0;
    $tcf_group = ['TCF', 'W', 'ポリペク'];
    $day_cache = $cache[$date_str] ?? [];

    foreach ($doctors as $doctor_name) {
        $status = getDoctorStatus($pdo, $doctor_name, $dept, $date_str);
        if (!$status['present']) { continue; }
        if ($filter_doctor !== 'all' && $filter_doctor !== $doctor_name) { continue; }
        
        $doctor_data = ['name' => $doctor_name, 'slots' => []];
        $has_any_slot = false;
        $doc_counts = $day_cache[$doctor_name] ?? [];
        
        // GIF
        if ($filter_exam === 'all' || $filter_exam === 'GIF') {
            $max = $status['slots']['GIF'] ?? 0;
            if ($max > 0) {
                // GIFは科全体予約数として算出
                $used = 0;
                foreach ($day_cache as $doc => $exams) {
                    $used += $exams['GIF'] ?? 0;
                }
                $doctor_data['slots']['GIF'] = ['used' => $used, 'max' => $max];
                $total_used += $used;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        // ECHO
        if ($filter_exam === 'all' || $filter_exam === 'ECHO') {
            $max = $status['slots']['ECHO'] ?? 0;
            if ($max > 0) {
                $used = 0;
                foreach ($day_cache as $doc => $exams) {
                    $used += ($exams['エコー'] ?? 0) + ($exams['ECHO'] ?? 0);
                }
                $doctor_data['slots']['ECHO'] = ['used' => $used, 'max' => $max];
                $total_used += $used;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        // TCF
        if ($filter_exam === 'all' || $filter_exam === 'TCF') {
            $max = $status['slots']['TCF'] ?? 0;
            if ($max > 0) {
                $used = 0;
                foreach ($day_cache as $doc => $exams) {
                    foreach ($tcf_group as $t_exam) {
                        $used += $exams[$t_exam] ?? 0;
                    }
                }
                $doctor_data['slots']['TCF'] = ['used' => $used, 'max' => $max];
                $total_used += $used;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($has_any_slot) {
            $result[] = $doctor_data;
        }
    }
    
    return ['doctors' => $result, 'total_used' => $total_used, 'total_max' => $total_max];
}

function getGroupCodeByWaku($pdo, $dept, $waku_name) {
    static $cache = [];
    $key = $dept . '|' . $waku_name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("
        SELECT group_code FROM m2_waku 
        WHERE department_code = :dept AND waku_name = :waku AND is_active = TRUE
    ");
    $stmt->execute([':dept' => $dept, ':waku' => $waku_name]);
    $row = $stmt->fetch();
    $result = $row ? $row['group_code'] : 'OTHER';
    $cache[$key] = $result;
    return $result;
}

function getDailyReservations($pdo, $dept, $date_str, $filter_doctor = 'all', $filter_exam = 'all') {
    $sql = "SELECT 
                s.*,
                w.display_order AS waku_display_order,
                w.group_code,
                FALSE AS is_empty
            FROM t_endoscopy_schedule s
            LEFT JOIN m2_waku w 
                ON s.department_code = w.department_code 
                AND s.scheduled_time = w.waku_name
                AND w.is_active = TRUE
            WHERE s.department_code = :dept 
              AND s.exam_date = :date 
              AND s.is_canceled = FALSE";
    
    if ($filter_doctor !== 'all') {
        $sql .= " AND s.doctor_name = :doctor";
    }
    if ($filter_exam !== 'all') {
        $sql .= " AND s.exam_type = :exam";
    }
    
    $sql .= " ORDER BY COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC";
    
    $stmt = $pdo->prepare($sql);
    $params = [':dept' => $dept, ':date' => $date_str];
    if ($filter_doctor !== 'all') { $params[':doctor'] = $filter_doctor; }
    if ($filter_exam !== 'all') { $params[':exam'] = $filter_exam; }
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function renderUnassignedBadgeFast($date_str, $unassigned_cache) {
    $data = $unassigned_cache[$date_str] ?? [];
    if (empty($data)) return '';
    
    $parts = [];
    $icon_map = ['GIF' => '🔵', 'ECHO' => '🟠', 'エコー' => '🟠', 'TCF' => '🟢', 'その他' => '●'];
    foreach ($data as $type => $cnt) {
        if ($cnt > 0) {
            $icon = $icon_map[$type] ?? '●';
            $parts[] = $icon . $cnt;
        }
    }
    
    if (empty($parts)) return '';
    
    return '<div class="unassigned-badge" style="display:inline-block; background-color:#fef2f2; border:1px solid #fca5a5; border-radius:4px; padding:1px 6px; font-size:9px; color:#991b1b; margin-top:2px;">⚠️ 未割当: ' . implode(' ', $parts) . '</div>';
}

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
$doctors_list = getDoctorsForDept($pdo, $dept);
$dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';

// ============================================================
// 4. 個別検査シート印刷（sheet_single）
// ============================================================
if ($sheet_single > 0) {
    $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id AND department_code = :dept AND is_canceled = FALSE");
    $stmt->execute([':id' => $sheet_single, ':dept' => $dept]);
    $p = $stmt->fetch();
    
    if (!$p) {
        die("指定された予約が見つかりません。");
    }
    
    $exam = $p['exam_type'] ?? '';
    if ($exam === 'エコー' || $exam === 'ECHO') {
        die("エコーはシート印刷の対象外です。");
    }
    
    $exam_date = $p['exam_date'] ?? '';
    $age_str = calculateAge($p['birthday'] ?? '', $exam_date);
    $is_proct = ($p['department_code'] === 'PROCT');
    $wareki = convertToWareki($p['birthday'] ?? '');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>内視鏡検査シート</title>
        <style>
            @page { size: A4 portrait; margin: 10mm 12mm; }
            body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10pt; color: #000; background: #fff; }
            .sheet-page { padding-bottom: 10mm; }
            .sheet-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 8px; }
            .clinic-title { font-size: 14pt; font-weight: bold; }
            .dept-checkboxes { font-size: 10pt; font-weight: bold; }
            .sheet-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 9pt; }
            .sheet-table th, .sheet-table td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
            .sheet-table th { background: #f2f2f2; text-align: center; font-weight: bold; }
            .highlight-yellow { background: #ffff00 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .section-title { background: #e6e6e6; font-weight: bold; padding: 3px 8px; border: 1px solid #000; border-bottom: none; font-size: 9pt; margin-top: 6px; }
            .footer-line { display: flex; justify-content: space-between; font-size: 7pt; color: #555; margin-top: 5px; }
        </style>
    </head>
    <body>
        <div class="sheet-page">
            <div class="sheet-header">
                <div class="clinic-title">おの肛門科胃腸科内視鏡クリニック</div>
                <div class="dept-checkboxes">[ <?= $is_proct ? '☑' : '□' ?> ] 肛門科 &nbsp;&nbsp;&nbsp; [ <?= !$is_proct ? '☑' : '□' ?> ] 胃腸科</div>
            </div>
            <table class="sheet-table">
                <tr>
                    <th style="width:12%;">カルテ番号</th>
                    <td style="width:23%; font-weight:bold; font-size:12pt; text-align:center;" class="highlight-yellow"><?= htmlspecialchars($p['patient_id'] ?? '---', ENT_QUOTES, 'UTF-8') ?></td>
                    <th style="width:10%;">氏名</th>
                    <td style="width:35%; font-weight:bold; font-size:13pt;" class="highlight-yellow"><?= htmlspecialchars($p['patient_name'] ?? '（氏名未登録）', ENT_QUOTES, 'UTF-8') ?></td>
                    <th style="width:8%;">性別</th>
                    <td style="width:12%; text-align:center;"><?= htmlspecialchars($p['gender'] ?? '---', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>生年月日</th>
                    <td style="text-align:center;"><?= htmlspecialchars($wareki, ENT_QUOTES, 'UTF-8') ?></td>
                    <th>年齢</th>
                    <td style="text-align:center; font-weight:bold;"><?= $age_str ?></td>
                    <th>検査日</th>
                    <td style="text-align:center; font-weight:bold;"><?= htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><td style="width:50%;"><strong>付添：</strong>無 ・ 有（     ）<br><strong>迎え・タクシー・車：</strong>（      ）</td>
                    <td style="width:50%;"><strong>荷物：</strong>無 ・ 有（     ）<br><strong>内服：</strong>無 ・ 有（     ）<br><strong>TVセット・入院セット：</strong> 特( ) 個( ) 大( )</td></tr>
            </table>
            <div class="section-title">■ 前処置・下剤チェック欄</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><th style="width:35%;">薬剤名 / 処置</th><th style="width:15%;">数量・規格</th><th>実施状況・特記事項</th></tr>
                <tr><td>グリセリン浣腸液 50%</td><td style="text-align:center;">60ml / 120ml</td><td><span class="highlight-yellow" style="padding:2px 10px;">[ ] 実施 &nbsp;&nbsp;&nbsp; 時間：(  :  )</span></td></tr>
                <tr><td>高圧浣腸 グリセリン</td><td style="text-align:center;">100ml</td><td>[ ] 実施</td></tr>
                <tr><td>マグコロール散 68%</td><td style="text-align:center;">50g / 100g / 150g</td><td>服薬状況：(            )</td></tr>
                <tr><td>ニフレック / モビプレップ</td><td style="text-align:center;">1袋 / 2L</td><td>排便状況：(            )</td></tr>
            </table>
            <div class="section-title">■ 問診・安全確認事項</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><td style="width:50%;">・排便回数：昨日（ ）・今日（ ） 最終（   ）<br>・便秘： 無 ・ 有 / 難聴： 無 ・ 有<br>・感染症： HB（ ）・HC（ ）・RPR（ ）□未</td>
                    <td style="width:50%;">・抗凝固薬： □無 □有 （休薬： / ～ ）<br>・糖尿病薬： □無 □有 （休薬： / ～ ）<br>・インスリン：□無 □有 （休薬： / ～ ）<br>・シャント・乳腺手術：□無 □有（右・左）</td></tr>
                <tr><td colspan="2"><strong>当日内服・血圧：</strong> 血圧： 済 ・ 未 &nbsp; BP（   /   ） &nbsp;&nbsp; <strong>その他特記事項：</strong> <?= htmlspecialchars($p['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
            </table>
            <div class="section-title">■ 検査・薬剤投与記録（当日）</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><th style="width:35%;">項目</th><th style="width:20%; text-align:center;">予定 / 数量</th><th>実施記録・サイン</th></tr>
                <tr><td>検査種別</td><td style="text-align:center; font-weight:bold;" class="highlight-yellow"><?= htmlspecialchars($p['exam_type'] ?? '---', ENT_QUOTES, 'UTF-8') ?></td><td>場所: <strong><?= htmlspecialchars($p['exam_location'] ?? '---', ENT_QUOTES, 'UTF-8') ?></strong> / 担当医: <strong><?= htmlspecialchars($p['doctor_name'] ?? '---', ENT_QUOTES, 'UTF-8') ?></strong></td></tr>
                <tr><td>プロフォール 1％</td><td style="text-align:center;"> mg</td><td></td></tr>
                <tr><td>ジアゼパム 10mg</td><td style="text-align:center;"> ml</td><td></td></tr>
                <tr><td>ブスコパン 20mg / グルカゴン</td><td style="text-align:center;"> 1ml / 1V</td><td>採血：あり ・ なし / アル綿禁：[ ]</td></tr>
            </table>
            <div class="section-title">■ 所見・メモ・特記事項</div>
            <div style="border:1px solid #000; height:30mm; padding:4px; font-size:8pt;">
                <?php if (!empty($p['remarks'])): ?>予約時備考: <?= htmlspecialchars($p['remarks'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </div>
            <div class="footer-line">
                <div>スケジュールID: #<?= (int)$p['schedule_id'] ?> (枠: <?= htmlspecialchars($p['scheduled_time'] ?? '---', ENT_QUOTES, 'UTF-8') ?>)</div>
                <div>おの肛門科胃腸科内視鏡クリニック 診療記録シート</div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// 5. 印刷モード（一覧印刷）
// ============================================================
if ($print_mode) {
    if ($show_empty) {
        $sql = "
        WITH 
        all_slots AS (
            SELECT 
                waku_name,
                display_order,
                group_code
            FROM m2_waku
            WHERE department_code = :dept 
              AND is_active = TRUE
        ),
        reserved_data AS (
            SELECT 
                s.schedule_id,
                s.exam_date,
                s.scheduled_time,
                s.waku_no,
                s.patient_id,
                s.patient_name,
                s.patient_kana,
                s.birthday,
                s.doctor_name,
                s.exam_type,
                s.arrival_time,
                s.exam_location,
                s.remarks,
                s.is_canceled,
                s.booking_status,
                s.department_code,
                w.display_order AS waku_display_order,
                w.group_code,
                FALSE AS is_empty
            FROM t_endoscopy_schedule s
            LEFT JOIN m2_waku w 
                ON s.department_code = w.department_code 
                AND s.scheduled_time = w.waku_name
                AND w.is_active = TRUE
            WHERE s.department_code = :dept 
              AND s.exam_date = :date 
              AND s.is_canceled = FALSE
        ";
        if ($filter_doctor !== 'all') {
            $sql .= " AND s.doctor_name = :doctor";
        }
        if ($filter_exam !== 'all') {
            $sql .= " AND s.exam_type = :exam";
        }
        $sql .= "
        )
        SELECT 
            a.waku_name AS scheduled_time,
            a.display_order AS waku_no,
            a.group_code,
            COALESCE(r.patient_id, '') AS patient_id,
            COALESCE(r.patient_name, '') AS patient_name,
            COALESCE(r.patient_kana, '') AS patient_kana,
            COALESCE(r.birthday::text, '') AS birthday,
            COALESCE(r.doctor_name, '') AS doctor_name,
            COALESCE(r.exam_type, '') AS exam_type,
            COALESCE(r.arrival_time, '') AS arrival_time,
            COALESCE(r.exam_location, '') AS exam_location,
            COALESCE(r.remarks, '') AS remarks,
            COALESCE(r.schedule_id, 0) AS schedule_id,
            CASE WHEN r.schedule_id IS NULL THEN TRUE ELSE FALSE END AS is_empty,
            COALESCE(r.department_code, :dept) AS department_code
        FROM all_slots a
        LEFT JOIN reserved_data r ON a.waku_name = r.scheduled_time
        ORDER BY a.display_order ASC;
        ";
        
        $stmt = $pdo->prepare($sql);
        $params = [':dept' => $dept, ':date' => $selected_date];
        if ($filter_doctor !== 'all') { $params[':doctor'] = $filter_doctor; }
        if ($filter_exam !== 'all') { $params[':exam'] = $filter_exam; }
        $stmt->execute($params);
        $print_data = $stmt->fetchAll();
    } else {
        $print_data = getDailyReservations($pdo, $dept, $selected_date, $filter_doctor, $filter_exam);
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>予約一覧印刷 - <?=$selected_date?></title>
        <style>
            @page { size: A4 landscape; margin: 12mm 15mm; }
            body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10pt; color: #000; background: #fff; }
            h2 { text-align: center; margin-bottom: 3px; font-size: 14pt; }
            .sub-title { text-align: center; font-size: 10pt; color: #555; margin-bottom: 12px; }
            table { width: 100%; border-collapse: collapse; font-size: 9pt; }
            th, td { border: 1px solid #000; padding: 4px 6px; text-align: left; }
            th { background: #f0f0f0; font-weight: bold; }
            .exam-badge {
                display: inline-block;
                padding: 1px 10px;
                border-radius: 3px;
                font-weight: bold;
                font-size: 8pt;
            }
            .exam-badge.gif { background: #0ea5e9; color: #fff; }
            .exam-badge.tcf { background: #10b981; color: #fff; }
            .exam-badge.echo { background: #8b5cf6; color: #fff; }
            .exam-badge.other { background: #e2e8f0; color: #333; }
            .footer {
                text-align: center;
                font-size: 7pt;
                color: #999;
                margin-top: 15px;
                border-top: 1px solid #ddd;
                padding-top: 8px;
            }
            .col-time { width: 10%; }
            .col-arrival { width: 10%; }
            .col-id { width: 9%; }
            .col-name { width: 18%; }
            .col-exam { width: 10%; }
            .col-doctor { width: 10%; }
            .col-remarks { width: 33%; }
        </style>
    </head>
    <body>
        <h2>内視鏡予約一覧</h2>
        <div class="sub-title"><?=date('Y年m月d日', strtotime($selected_date))?>（<?=$dept_label?>）</div>
        <?php if (empty($print_data)): ?>
            <p style="text-align:center; color:#999;">予約はありません</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="col-time">時間枠</th>
                        <th class="col-arrival">来院時刻</th>
                        <th class="col-id">カルテID</th>
                        <th class="col-name">患者名</th>
                        <th class="col-exam">検査種別</th>
                        <th class="col-doctor">担当医師</th>
                        <th class="col-remarks">備考</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($print_data as $row): 
                        $exam = $row['exam_type'] ?? '';
                        $cls = 'other';
                        if ($exam === 'GIF') $cls = 'gif';
                        elseif ($exam === 'ECHO') $cls = 'echo';
                        elseif ($exam === 'TCF') $cls = 'tcf';
                        
                        $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
                        
                        $time_display = $row['scheduled_time'] ?? '---';
                        if ($time_display === 'MISMATCH') {
                            $time_display = '⚠️ 未割当';
                        }
                        
                        $arrival_display = $row['arrival_time'] ?? '';
                        if (empty($arrival_display)) {
                            $arrival_display = '---';
                        }
                        
                        $patient_id_display = $is_empty ? '' : ($row['patient_id'] ?? '---');
                        $patient_name_display = $is_empty ? '' : ($row['patient_name'] ?? '');
                        $doctor_name_display = $is_empty ? '' : ($row['doctor_name'] ?? '');
                        $exam_display = $is_empty ? '' : $exam;
                        $remarks_display = $is_empty ? '' : ($row['remarks'] ?? '');
                        $arrival_display_print = $is_empty ? '' : $arrival_display;
                    ?>
                        <tr>
                            <td><?=htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($arrival_display_print, ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($patient_id_display, ENT_QUOTES, 'UTF-8')?></td>
                            <td><?=htmlspecialchars($patient_name_display, ENT_QUOTES, 'UTF-8')?></td>
                            <td>
                                <?php if (!$is_empty): ?>
                                    <span class="exam-badge <?=$cls?>"><?=htmlspecialchars($exam_display, ENT_QUOTES, 'UTF-8')?></span>
                                <?php endif; ?>
                            </td>
                            <td><?=htmlspecialchars($doctor_name_display, ENT_QUOTES, 'UTF-8')?></td>
                            <td style="font-size:8pt;"><?=htmlspecialchars($remarks_display, ENT_QUOTES, 'UTF-8')?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="footer">印刷日時: <?=date('Y-m-d H:i:s')?> | 医療法人小野会 内視鏡室</div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// 6. データ取得（通常表示用）
// ============================================================
$day_data = getDayDataFast($pdo, $dept, $selected_date, $doctors_list, $month_reserved_cache, $filter_doctor, $filter_exam);
$doctor_counts = getDoctorCountsFast($doctors_list, $selected_date, $month_reserved_cache);

if ($show_empty) {
    $sql = "
    WITH 
    all_slots AS (
        SELECT 
            waku_name,
            display_order,
            group_code
        FROM m2_waku
        WHERE department_code = :dept 
          AND is_active = TRUE
    ),
    reserved_data AS (
        SELECT 
            s.schedule_id,
            s.exam_date,
            s.scheduled_time,
            s.waku_no,
            s.patient_id,
            s.patient_name,
            s.patient_kana,
            s.birthday,
            s.doctor_name,
            s.exam_type,
            s.arrival_time,
            s.exam_location,
            s.remarks,
            s.is_canceled,
            s.booking_status,
            s.department_code,
            w.display_order AS waku_display_order,
            w.group_code,
            FALSE AS is_empty
        FROM t_endoscopy_schedule s
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
        WHERE s.department_code = :dept 
          AND s.exam_date = :date 
          AND s.is_canceled = FALSE
    ";
    if ($filter_doctor !== 'all') {
        $sql .= " AND s.doctor_name = :doctor";
    }
    if ($filter_exam !== 'all') {
        $sql .= " AND s.exam_type = :exam";
    }
    $sql .= "
    )
    SELECT 
        a.waku_name AS scheduled_time,
        a.display_order AS waku_no,
        a.group_code,
        COALESCE(r.patient_id, '') AS patient_id,
        COALESCE(r.patient_name, '') AS patient_name,
        COALESCE(r.patient_kana, '') AS patient_kana,
        COALESCE(r.birthday::text, '') AS birthday,
        COALESCE(r.doctor_name, '') AS doctor_name,
        COALESCE(r.exam_type, '') AS exam_type,
        COALESCE(r.arrival_time, '') AS arrival_time,
        COALESCE(r.exam_location, '') AS exam_location,
        COALESCE(r.remarks, '') AS remarks,
        COALESCE(r.schedule_id, 0) AS schedule_id,
        CASE WHEN r.schedule_id IS NULL THEN TRUE ELSE FALSE END AS is_empty,
        COALESCE(r.department_code, :dept) AS department_code
    FROM all_slots a
    LEFT JOIN reserved_data r ON a.waku_name = r.scheduled_time
    ORDER BY a.display_order ASC;
    ";
    
    $stmt = $pdo->prepare($sql);
    $params = [':dept' => $dept, ':date' => $selected_date];
    if ($filter_doctor !== 'all') { $params[':doctor'] = $filter_doctor; }
    if ($filter_exam !== 'all') { $params[':exam'] = $filter_exam; }
    $stmt->execute($params);
    $list_data = $stmt->fetchAll();
    
} else {
    $list_data = getDailyReservations($pdo, $dept, $selected_date, $filter_doctor, $filter_exam);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内視鏡予約 ダッシュボード</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 15px;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 13px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            padding: 18px 22px;
        }

        /* 1. ヘッダー & ナビゲーション */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
            background: #ffffff;
            padding: 12px 18px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
            gap: 12px;
        }
        .header-title-area {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-title-area h2 {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        .header-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 9999px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }
        .header-nav {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
            cursor: pointer;
            line-height: 1.4;
        }
        .nav-btn-home {
            background-color: #f8fafc;
            color: #475569;
            border-color: #cbd5e1;
        }
        .nav-btn-home:hover {
            background-color: #f1f5f9;
            color: #1e293b;
            border-color: #94a3b8;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            transform: translateY(-1px);
        }
        .nav-btn-matrix {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.25);
        }
        .nav-btn-matrix:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.35);
            transform: translateY(-1px);
        }
        .nav-btn-search {
            background: linear-gradient(135deg, #0d9488, #0f766e);
            color: #ffffff;
            box-shadow: 0 2px 4px rgba(13, 148, 136, 0.25);
        }
        .nav-btn-search:hover {
            background: linear-gradient(135deg, #0f766e, #115e59);
            box-shadow: 0 4px 6px rgba(13, 148, 136, 0.35);
            transform: translateY(-1px);
        }

        /* 診療科タブ */
        .dept-tabs {
            display: inline-flex;
            gap: 4px;
            background: #e2e8f0;
            padding: 3px;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .dept-tab {
            padding: 5px 16px;
            text-decoration: none;
            font-weight: 700;
            border-radius: 6px;
            color: #64748b;
            font-size: 12px;
            transition: all 0.2s;
        }
        .dept-tab.active-PROCT {
            background: #3b82f6;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .dept-tab.active-GASTRO {
            background: #10b981;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        /* フィルタ */
        .filters {
            background: #ffffff;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }
        .filter-group { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 4px 0; }
        .filter-group .filter-label { font-weight: 700; font-size: 12px; color: #475569; min-width: 75px; }
        .filter-toggle {
            padding: 3px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            color: #475569;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .filter-toggle:hover { background: #f1f5f9; }
        .filter-toggle.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .filter-toggle.active-all { background: #1e293b; color: #fff; border-color: #1e293b; }
        .filter-toggle .badge { background: #e2e8f0; color: #475569; font-size: 9px; padding: 0 6px; border-radius: 8px; }
        .filter-toggle.active .badge, .filter-toggle.active-all .badge { background: rgba(255,255,255,0.25); color: #fff; }

        .display-mode-toggle {
            display: flex;
            gap: 2px;
            background: #f1f5f9;
            border-radius: 6px;
            padding: 2px;
            border: 1px solid #e2e8f0;
        }
        .display-mode-toggle .mode-btn {
            padding: 4px 12px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            background: transparent;
            color: #64748b;
            transition: all 0.15s;
            text-decoration: none;
        }
        .display-mode-toggle .mode-btn:hover { background: #e2e8f0; }
        .display-mode-toggle .mode-btn.active {
            background: #3b82f6;
            color: #fff;
        }
        .display-mode-toggle .mode-btn.empty-active {
            background: #8b5cf6;
            color: #fff;
        }

        /* 2. カレンダーヘッダー（左寄せ ＆ 未来月クイックボタン群） */
        .calendar-wrapper {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            overflow: auto;
        }
        .cal-nav-bar {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }
        .cal-primary-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cal-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            white-space: nowrap;
            letter-spacing: -0.01em;
        }
        .cal-btn-group {
            display: inline-flex;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            background: #f8fafc;
        }
        .cal-nav-arrow {
            padding: 4px 10px;
            background: #ffffff;
            color: #334155;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            transition: background 0.15s;
            display: inline-flex;
            align-items: center;
        }
        .cal-nav-arrow:hover { background: #e2e8f0; }
        .cal-nav-today {
            padding: 4px 12px;
            background: #ffffff;
            color: #2563eb;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
            border-left: 1px solid #cbd5e1;
            border-right: 1px solid #cbd5e1;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .cal-nav-today:hover { background: #eff6ff; }

        .cal-quick-months {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
            background: #f8fafc;
            padding: 3px 6px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .quick-month-label {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            margin-right: 2px;
            margin-left: 2px;
            white-space: nowrap;
        }
        .month-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 24px;
            padding: 0 6px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            color: #475569;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            transition: all 0.15s ease;
        }
        .month-pill:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .month-pill.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 1px 2px rgba(37,99,235,0.25);
        }
        .month-pill.year-edge {
            border-left: 2px solid #94a3b8;
        }
        .month-pill-sub {
            font-size: 8px;
            opacity: 0.75;
            margin-left: 1px;
        }

        /* カレンダーテーブル */
        .cal-table {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            table-layout: fixed;
            border-collapse: collapse;
            border: 1px solid #e2e8f0;
        }
        .cal-table th,
        .cal-table td {
            width: 14.285%;
            max-width: 14.285%;
            min-width: 14.285%;
            box-sizing: border-box;
            border: 1px solid #e2e8f0;
            text-align: center;
            vertical-align: top;
            padding: 3px 4px;
            font-size: 9px;
        }
        .cal-table th {
            background: #f1f5f9;
            font-weight: bold;
            font-size: 11px;
            padding: 4px;
        }
        .cal-table .day-cell {
            cursor: pointer;
            min-height: 68px;
            max-height: 100px;
            overflow: hidden;
            transition: background 0.15s;
        }
        .cal-table .day-cell:hover { background: #f8fafc; }
        .cal-table .day-cell.has-available { background-color: #fef9c3 !important; }
        .cal-table .day-cell.past {
            background-color: #d1d5db !important;
            opacity: 0.8;
            color: #6b7280;
            cursor: not-allowed;
        }
        .cal-table .day-cell.closed { background-color: #fef2f2 !important; color: #ef4444; }
        .cal-table .day-cell.today { background-color: #ffffe0 !important; }
        .cal-table .day-cell.selected {
            border: 2px solid #3b82f6 !important;
            background-color: #eff6ff !important;
        }
        .cal-table .day-cell.has-negative { border: 2px solid #dc2626 !important; }
        .cal-table .day-cell.has-reservation-on-closed {
            border: 3px solid #dc2626 !important;
            position: relative;
        }
        .cal-table .day-cell.has-reservation-on-closed::after {
            content: '⚠️';
            position: absolute;
            top: 0px;
            right: 2px;
            font-size: 9px;
        }
        .cal-table .day-cell .day-number {
            font-weight: bold;
            font-size: 13px;
            display: block;
            margin-bottom: 1px;
        }
        .cal-table .day-cell .day-slots {
            font-size: 8px;
            line-height: 1.3;
            text-align: left;
            padding-left: 2px;
            max-height: 46px;
            overflow-y: auto;
        }
        .cal-table .day-cell .doctor-slot-row {
            margin-top: 1px;
            padding: 1px 2px;
            border-bottom: 1px dotted #f1f5f9;
            font-size: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cal-table .day-cell .doctor-slot-row:last-child { border-bottom: none; }
        .cal-table .day-cell .slot-item {
            display: inline-block;
            margin-right: 1px;
            font-weight: 600;
            font-size: 8px;
        }
        .cal-table .day-cell .slot-item.negative {
            display: inline-block;
            border: 1.5px solid #dc2626;
            border-radius: 2px;
            padding: 0 2px;
            background-color: #fee2e2;
            font-weight: bold;
            color: #dc2626;
            font-size: 8px;
            line-height: 1.2;
        }
        .cal-table .day-cell .day-available {
            font-size: 8px;
            font-weight: bold;
            margin-top: 2px;
            text-align: center;
            padding: 1px 3px;
            border-radius: 8px;
            background: #f1f5f9;
        }
        .cal-table .day-cell .day-available.avail-green { color: #22c55e; background: #dcfce7; }
        .cal-table .day-cell .day-available.avail-yellow { color: #f59e0b; background: #fef3c7; }
        .cal-table .day-cell .day-available.avail-red { color: #ef4444; background: #fecaca; }
        .cal-table .day-cell .day-available.avail-closed { color: #94a3b8; background: #f1f5f9; }
        .cal-table .day-cell .empty-cell-text {
            font-size: 8px;
            color: #94a3b8;
            padding-top: 18px;
        }

        /* 3. 予約一覧（下ペイン） */
        .list-wrapper {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px;
        }
        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
            gap: 8px;
        }
        .list-header .list-title { font-weight: 700; font-size: 14px; color: #0f172a; }
        .list-header .list-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .list-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .list-table th, .list-table td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        .list-table th { background: #f8fafc; font-weight: 700; font-size: 11px; color: #475569; }
        .list-table tr:hover { background: #f8fafc; }
        .list-table .empty-msg { text-align: center; color: #94a3b8; padding: 20px; }

        .exam-badge {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 10px;
        }
        .exam-badge.gif { background: #0ea5e9; color: #fff; }
        .exam-badge.echo { background: #8b5cf6; color: #fff; }
        .exam-badge.tcf { background: #10b981; color: #fff; }
        .exam-badge.other { background: #e2e8f0; color: #475569; }

        .sheet-btn-small {
            display: inline-block;
            padding: 2px 8px;
            background: #8b5cf6;
            color: #fff;
            border-radius: 3px;
            text-decoration: none;
            font-size: 9px;
            font-weight: bold;
            border: none;
            cursor: pointer;
        }
        .sheet-btn-small:hover { background: #7c3aed; }
        .sheet-btn-small.disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .sheet-btn-small.followup {
            background: #8b5cf6;
            margin-left: 4px;
        }
        .sheet-btn-small.followup:hover {
            background: #7c3aed;
        }

        .slot-filter-label {
            cursor: pointer;
            padding: 2px 10px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: #fff;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            user-select: none;
            transition: all 0.15s;
        }
        .slot-filter-label:hover { background: #f1f5f9; }
        .slot-filter-label input[type="checkbox"] { margin: 0; cursor: pointer; width: 14px; height: 14px; }
        .color-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
        .color-dot.gif { background: #93c5fd; }
        .color-dot.echo { background: #f0abfc; }
        .color-dot.tcf { background: #86efac; }

        .empty-slot-label {
            cursor: pointer;
            padding: 3px 12px;
            border-radius: 6px;
            border: 1.5px solid #8b5cf6;
            background: #f5f3ff;
            color: #6d28d9;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            user-select: none;
            transition: all 0.15s;
        }
        .empty-slot-label input[type="checkbox"] {
            margin: 0;
            cursor: pointer;
            width: 14px;
            height: 14px;
            accent-color: #8b5cf6;
        }

        .print-btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #10b981;
            color: #fff;
            padding: 5px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            transition: background 0.15s;
        }
        .print-btn-modern:hover { background: #059669; }

        .icon-btn {
            text-decoration: none;
            font-size: 16px;
            cursor: pointer;
            display: inline-block;
            width: 28px;
            text-align: center;
            transition: transform 0.1s;
            line-height: 1.4;
        }
        .icon-btn:hover { transform: scale(1.15); }
        .icon-btn.add { color: #22c55e; }
        .icon-btn.add:hover { color: #16a34a; }
        .icon-btn.edit { color: #2563eb; }
        .icon-btn.edit:hover { color: #1d4ed8; }
        .icon-btn.disabled { color: #cbd5e1; cursor: default; pointer-events: none; }

        @media (max-width: 768px) {
            .container { padding: 10px; }
            .cal-table .day-cell { min-height: 55px; max-height: 85px; font-size: 8px; }
            .cal-table .day-cell .day-number { font-size: 11px; }
            .cal-table .day-cell .day-slots { max-height: 38px; font-size: 7px; }
            .filter-group { gap: 4px; }
            .filter-toggle { font-size: 10px; padding: 2px 8px; }
            .list-table { font-size: 11px; }
            .list-header { flex-direction: column; align-items: stretch; gap: 6px; }
            .list-table th, .list-table td { padding: 4px 5px; font-size: 10px; }
            .cal-nav-bar { flex-direction: column; align-items: stretch; }
            .list-actions { justify-content: flex-start; }
        }
    </style>
</head>
<body>

<div class="container">

    <!-- 1. ヘッダー & ナビゲーション -->
    <div class="header">
        <div class="header-title-area">
            <h2>📅 内視鏡予約 ダッシュボード</h2>
            <span class="header-badge">予約管理・枠振分</span>
        </div>
        <div class="header-nav">
            <a href="index.php" class="nav-btn nav-btn-home">
                <span>🏠</span> メニュー
            </a>
            <a href="month_matrix_v2.php?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>" class="nav-btn nav-btn-matrix">
                <span>📊</span> 予約マトリクス
            </a>
            <a href="slot_search.php?dept=<?=$dept?>" class="nav-btn nav-btn-search">
                <span>🔍</span> 空き枠検索
            </a>
        </div>
    </div>

    <!-- 診療科タブ -->
    <div class="dept-tabs">
        <a href="?dept=PROCT&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛門科</a>
        <a href="?dept=GASTRO&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab <?=$dept==='GASTRO'?'active-GASTRO':''?>">🔵 胃腸科</a>
    </div>

    <!-- フィルタ -->
    <div class="filters">
        <!-- 医師フィルタ -->
        <div class="filter-group doctor-filter">
            <span class="filter-label">👨‍⚕️ 医師</span>
            <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=all&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor==='all'?'active-all':''?>">全医師</a>
            <?php foreach ($doctors_list as $doc): ?>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($doc)?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor===$doc?'active':''?>">
                    <?=$doc?> <span class="badge"><?=$doctor_counts[$doc] ?? 0?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <!-- 検査種別フィルタ -->
        <div class="filter-group exam-filter">
            <span class="filter-label">🔬 検査種別</span>
            <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=all&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='all'?'active-all':''?>">全種別</a>
            <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=GIF&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='GIF'?'active':''?>">🔵 GIF</a>
            <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=ECHO&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='ECHO'?'active':''?>">🟠 エコー</a>
            <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=TCF&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='TCF'?'active':''?>">🟢 TCF</a>
        </div>
        <!-- 表示モード -->
        <div class="filter-group" style="margin-top:6px; border-top:1px solid #e2e8f0; padding-top:6px;">
            <span class="filter-label">📊 表示モード:</span>
            <div class="display-mode-toggle">
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=use_max&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='use_max'?'active':''?>">📊 使用/最大</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=remain&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='remain'?'active':''?>">📉 残数</a>
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=empty&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='empty'?'empty-active':''?>" style="<?=$display_mode==='empty'?'background:#8b5cf6;color:#fff;':'background:transparent;color:#64748b;'?>">⬜ 空き枠</a>
            </div>
        </div>
    </div>

    <!-- 2. カレンダー部（左寄せ ＆ 未来月クイック遷移ボタン） -->
    <div class="calendar-wrapper">
        <div class="cal-nav-bar">
            <!-- 左側：年月と前後移動ボタン -->
            <div class="cal-primary-controls">
                <span class="cal-title"><?=$year?>年 <?=$month?>月</span>
                <div class="cal-btn-group">
                    <a href="?dept=<?=$dept?>&year=<?=$prev_year?>&month=<?=$prev_month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-arrow" title="前月">◀</a>
                    <a href="?dept=<?=$dept?>&year=<?=date('Y')?>&month=<?=date('n')?>&date=<?=date('Y-m-d')?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-today">📅 今日</a>
                    <a href="?dept=<?=$dept?>&year=<?=$next_year?>&month=<?=$next_month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-arrow" title="次月">▶</a>
                </div>
            </div>

            <!-- 右側：未来月クイックボタン（当月起点で12ヶ月分ジャンプ） -->
            <div class="cal-quick-months">
                <span class="quick-month-label">月ジャンプ:</span>
                <?php foreach ($quick_months as $qm): ?>
                    <?php 
                        $pill_class = 'month-pill' . ($qm['is_active'] ? ' active' : '') . ($qm['is_new_year'] ? ' year-edge' : '');
                        $pill_label = $qm['is_new_year'] ? ("'" . substr((string)$qm['year'], 2) . '/' . $qm['month']) : $qm['month'];
                    ?>
                    <a href="?dept=<?=$dept?>&year=<?=$qm['year']?>&month=<?=$qm['month']?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" 
                       class="<?=$pill_class?>" 
                       title="<?=$qm['year']?>年<?=$qm['month']?>月">
                        <?=$pill_label?><span class="month-pill-sub"><?=$qm['is_new_year'] ? '' : '月'?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <table class="cal-table">
            <thead>
                <tr>
                    <th style="color:#ef4444;">日</th>
                    <th>月</th>
                    <th>火</th>
                    <th>水</th>
                    <th style="color:#1e40af;">木</th>
                    <th>金</th>
                    <th style="color:#3b82f6;">土</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                <?php
                for ($i = 0; $i < $start_weekday; $i++) {
                    echo "<td style='background:#fafafa;'></td>";
                }

                $today_str = date('Y-m-d');
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $ts = strtotime($date_str);
                    $w = (int)date('w', $ts);
                    $day_check = check_onokai_calendar($date_str, null);
                    $is_closed = $day_check['is_closed'];
                    $is_today = ($date_str === $today_str);
                    $is_selected = ($date_str === $selected_date);
                    $is_past = ($date_str < $today_str);

                    // キャッシュから休診日＋予約ありの判定
                    $has_reservation_on_closed = false;
                    if ($is_closed && isset($month_reserved_cache[$date_str])) {
                        $count = 0;
                        foreach ($month_reserved_cache[$date_str] as $doc_counts) {
                            $count += array_sum($doc_counts);
                        }
                        $has_reservation_on_closed = ($count > 0);
                    }

                    // キャッシュから枠データ取得（クエリなし）
                    $has_negative = false;
                    $has_available = false;
                    $day_data = getDayDataFast($pdo, $dept, $date_str, $doctors_list, $month_reserved_cache, $filter_doctor, $filter_exam);
                    
                    if (!$is_closed && $day_data['total_max'] > 0) {
                        $has_available = ($day_data['total_used'] < $day_data['total_max']);
                        
                        foreach ($day_data['doctors'] as $d) {
                            foreach (['GIF', 'ECHO', 'TCF'] as $exam) {
                                if (isset($d['slots'][$exam])) {
                                    $used = $d['slots'][$exam]['used'];
                                    $max = $d['slots'][$exam]['max'];
                                    if ($used > $max) {
                                        $has_negative = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }

                    $cell_class = 'day-cell';
                    if ($is_selected) $cell_class .= ' selected';
                    if ($is_today) $cell_class .= ' today';
                    if ($is_closed) $cell_class .= ' closed';
                    if ($is_past) $cell_class .= ' past';
                    if ($has_negative && !$is_closed) $cell_class .= ' has-negative';
                    if ($has_available && !$is_past && !$is_closed) $cell_class .= ' has-available';
                    if ($has_reservation_on_closed) {
                        $cell_class .= ' has-reservation-on-closed';
                    }

                    // 未割当バッジ（キャッシュから即時展開）
                    $unassigned_html = renderUnassignedBadgeFast($date_str, $month_unassigned_cache);

                    $total_free = $day_data['total_max'] - $day_data['total_used'];
                    $has_data = $day_data['total_max'] > 0;

                    $avail_class = 'avail-closed';
                    $avail_text = '';
                    if (!$is_closed && $has_data) {
                        $total_gif_used = 0; $total_gif_max = 0;
                        $total_echo_used = 0; $total_echo_max = 0;
                        $total_tcf_used = 0; $total_tcf_max = 0;
                        foreach ($day_data['doctors'] as $d) {
                            if (isset($d['slots']['GIF'])) {
                                $total_gif_used += $d['slots']['GIF']['used'];
                                $total_gif_max += $d['slots']['GIF']['max'];
                            }
                            if (isset($d['slots']['ECHO'])) {
                                $total_echo_used += $d['slots']['ECHO']['used'];
                                $total_echo_max += $d['slots']['ECHO']['max'];
                            }
                            if (isset($d['slots']['TCF'])) {
                                $total_tcf_used += $d['slots']['TCF']['used'];
                                $total_tcf_max += $d['slots']['TCF']['max'];
                            }
                        }
                        
                        $doctor_count = count($day_data['doctors']);
                        if ($doctor_count >= 2) {
                            $overall_parts = [];
                            if ($display_mode === 'use_max') {
                                if ($total_gif_max > 0) {
                                    $overall_parts[] = '🔵' . $total_gif_used . '/' . $total_gif_max;
                                }
                                if ($total_echo_max > 0) {
                                    $overall_parts[] = '🟠' . $total_echo_used . '/' . $total_echo_max;
                                }
                                if ($total_tcf_max > 0) {
                                    $overall_parts[] = '🟢' . $total_tcf_used . '/' . $total_tcf_max;
                                }
                            } else {
                                if ($total_gif_max > 0) {
                                    $remain = $total_gif_max - $total_gif_used;
                                    $overall_parts[] = '🔵' . $remain;
                                }
                                if ($total_echo_max > 0) {
                                    $remain = $total_echo_max - $total_echo_used;
                                    $overall_parts[] = '🟠' . $remain;
                                }
                                if ($total_tcf_max > 0) {
                                    $remain = $total_tcf_max - $total_tcf_used;
                                    $overall_parts[] = '🟢' . $remain;
                                }
                            }
                            $avail_text = '全体 ' . implode(' ', $overall_parts);
                            $avail_class = 'avail-green';
                        }
                    }

                    $show_empty_val = $show_empty ? '1' : '0';
                    echo "<td class='{$cell_class}' onclick=\"location.href='?dept={$dept}&year={$year}&month={$month}&date={$date_str}&doctor={$filter_doctor}&exam={$filter_exam}&display_mode={$display_mode}&show_empty={$show_empty_val}'\">";
                    echo "<div class='day-number'>{$day}</div>";

                    if ($is_closed) {
                        echo "<div class='day-available avail-closed'>⛔休</div>";
                    } elseif ($has_data) {
                        echo "<div class='day-slots'>";
                        foreach ($day_data['doctors'] as $d) {
                            echo "<div class='doctor-slot-row'>";
                            echo "<span style='font-weight:bold; color:#1e293b;'>" . htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') . "</span> ";
                            foreach (['GIF', 'ECHO', 'TCF'] as $exam) {
                                if ($filter_exam !== 'all' && $filter_exam !== $exam) continue;
                                if (isset($d['slots'][$exam])) {
                                    $used = $d['slots'][$exam]['used'];
                                    $max = $d['slots'][$exam]['max'];
                                    if ($max > 0) {
                                        if ($display_mode === 'use_max') {
                                            $is_negative = ($used > $max);
                                            $color = ($is_negative) ? '#dc2626' : (($used >= $max) ? '#dc2626' : '#2563eb');
                                            if ($is_negative) {
                                                echo "<span class='slot-item negative'>" . getExamIcon($exam) . "{$used}/{$max}</span> ";
                                            } else {
                                                echo "<span style='color:{$color};' class='slot-item'>" . getExamIcon($exam) . "{$used}/{$max}</span> ";
                                            }
                                        } else {
                                            $remain = $max - $used;
                                            $is_negative = ($remain < 0);
                                            $color = ($is_negative) ? '#dc2626' : (($remain === 0) ? '#dc2626' : ($remain <= 2 ? '#f59e0b' : '#22c55e'));
                                            if ($is_negative) {
                                                echo "<span class='slot-item negative'>" . getExamIcon($exam) . "{$remain}</span> ";
                                            } else {
                                                echo "<span style='color:{$color};' class='slot-item'>" . getExamIcon($exam) . "{$remain}</span> ";
                                            }
                                        }
                                    }
                                }
                            }
                            echo "</div>";
                        }
                        echo "</div>";
                        if (!empty($avail_text)) {
                            echo "<div class='day-available {$avail_class}'>{$avail_text}</div>";
                        }
                        if (!empty($unassigned_html)) {
                            echo $unassigned_html;
                        }
                    } else {
                        echo "<div class='empty-cell-text'>-</div>";
                    }

                    echo "</td>";

                    if ($w == 6) {
                        echo "</tr><tr>";
                    }
                }

                while ($start_weekday + $days_in_month <= 35 || ($start_weekday + $days_in_month) % 7 != 0) {
                    echo "<td style='background:#fafafa;'></td>";
                    $start_weekday++;
                }
                ?>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- 3. 予約一覧（下部ペイン：デフォルトで空き枠表示ON） -->
    <div class="list-wrapper">
        <div class="list-header">
            <div class="list-title">
                📋 <?=date('Y年m月d日', strtotime($selected_date))?> の予約一覧
                <span style="font-size:11px; color:#64748b; font-weight:normal; margin-left:8px;">
                    (<?=$day_data['total_used']?>/<?=$day_data['total_max']?>枠 使用中)
                </span>
                <?php if ($display_mode === 'empty'): ?>
                    <span style="font-size:11px; color:#8b5cf6; font-weight:bold; margin-left:8px;">［空き枠表示モード］</span>
                <?php endif; ?>
            </div>
            <div class="list-actions">
                <!-- 空き枠表示スイッチ -->
                <label class="empty-slot-label">
                    <input type="checkbox" id="show_empty" <?= $show_empty ? 'checked' : '' ?> onchange="toggleEmptySlots(this.checked)">
                    ⬜ 空き枠表示
                </label>
                
                <!-- 枠種別フィルタ -->
                <span style="font-size:11px; font-weight:bold; color:#475569;">🎯</span>
                <label class="slot-filter-label" style="border-color:#93c5fd;">
                    <input type="checkbox" id="show_gif" checked onchange="applySlotFilter()">
                    <span class="color-dot gif"></span> GIF
                </label>
                <label class="slot-filter-label" style="border-color:#f0abfc;">
                    <input type="checkbox" id="show_echo" checked onchange="applySlotFilter()">
                    <span class="color-dot echo"></span> エコー
                </label>
                <label class="slot-filter-label" style="border-color:#86efac;">
                    <input type="checkbox" id="show_tcf" checked onchange="applySlotFilter()">
                    <span class="color-dot tcf"></span> TCF
                </label>
                <span style="font-size:9px; color:#94a3b8;">（チェックを外すと非表示）</span>
                
                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>&print=1" target="_blank" class="print-btn-modern">🖨️ 一覧印刷</a>
            </div>
        </div>

        <?php if (empty($list_data)): ?>
            <div class="empty-msg">この日の予約はありません</div>
        <?php else: ?>
            <table class="list-table" id="reservationTable">
                <thead>
                    <tr>
                        <th style="width:32px; text-align:center;" title="新規予約を追加">➕</th>
                        <th style="width:32px; text-align:center;" title="予約を編集・削除">✏️</th>
                        <th style="width:75px;">時間枠</th>
                        <th style="width:85px;">カルテID</th>
                        <th style="width:150px;">患者名</th>
                        <th style="width:75px;">検査種別</th>
                        <th style="width:75px;">担当医師</th>
                        <th>備考</th>
                        <th style="width:55px; text-align:center;">シート</th>
                        <th style="width:75px; text-align:center;">F/U</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($list_data as $row): 
                    $exam = $row['exam_type'] ?? '';
                    $cls = 'other';
                    if ($exam === 'GIF') $cls = 'gif';
                    elseif ($exam === 'ECHO') $cls = 'echo';
                    elseif ($exam === 'TCF') $cls = 'tcf';
                    
                    $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
                    $group_code = $row['group_code'] ?? 'OTHER';
                    $schedule_id = $row['schedule_id'] ?? 0;
                    $waku = $row['scheduled_time'] ?? '';
                    
                    $can_sheet = ($exam !== 'エコー' && $exam !== 'ECHO' && !$is_empty);
                    
                    $time_display = $row['scheduled_time'] ?? '---';
                    if ($time_display === 'MISMATCH') {
                        $time_display = '⚠️ 未割当';
                    }
                    
                    // F/Uリンク
                    $fu_url = '#';
                    $fu_label = '登録';
                    $fu_class = 'followup';
                    $fu_title = 'F/U登録';

                    if (!$is_empty && !empty($row['patient_id'])) {
                        $fu_params = '';
                        $fu_params .= 'patient_id=' . urlencode(isset($row['patient_id']) ? $row['patient_id'] : '');
                        $fu_params .= '&patient_name=' . urlencode(isset($row['patient_name']) ? $row['patient_name'] : '');
                        $fu_params .= '&patient_kana=' . urlencode(isset($row['patient_kana']) ? $row['patient_kana'] : '');
                        $fu_params .= '&birthday=' . urlencode(isset($row['birthday']) ? $row['birthday'] : '');
                        $fu_params .= '&department_code=' . urlencode($dept);
                        $fu_params .= '&doctor_name=' . urlencode(isset($row['doctor_name']) ? $row['doctor_name'] : '');
                        $fu_params .= '&exam_types=' . urlencode($exam);
                        
                        $fu_url = '/endoscope/followup/edit.php?' . $fu_params;
                        $fu_title = 'F/U登録（新規タブで開きます）';
                    }
                    
                    $modal_date = $selected_date;
                    $modal_waku = $waku;
                    $modal_dept = $dept;
                    $modal_schedule_id = $schedule_id;
                ?>
                    <tr data-group="<?= htmlspecialchars($group_code, ENT_QUOTES, 'UTF-8') ?>" 
                        data-reserved="<?= $is_empty ? 'false' : 'true' ?>"
                        <?= $is_empty ? 'style="background-color:#faf5ff;"' : '' ?>>
                        
                        <!-- 1列目：➕（常に表示：新規登録） -->
                        <td style="text-align:center;">
                            <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', 0, '<?= $modal_dept ?>')" 
                               class="icon-btn add" title="この枠に新規予約を追加">➕</a>
                        </td>
                        
                        <!-- 2列目：✏️（予約ありのみ表示：編集） -->
                        <td style="text-align:center;">
                            <?php if (!$is_empty && $schedule_id > 0): ?>
                            <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', <?= $schedule_id ?>, '<?= $modal_dept ?>')" 
                               class="icon-btn edit" title="この予約を編集・削除">✏️</a>
                            <?php else: ?>
                            <span class="icon-btn disabled">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <td><strong><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= $is_empty ? '' : htmlspecialchars($row['patient_id'] ?? '---', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($is_empty): ?>
                                <span style="color:#8b5cf6; font-size:11px; font-weight:600;">［空き枠］</span>
                            <?php else: ?>
                                <strong><?= htmlspecialchars($row['patient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$is_empty): ?>
                                <span class="exam-badge <?=$cls?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $is_empty ? '' : htmlspecialchars($row['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="font-size:11px; color:#64748b;">
                            <?= $is_empty ? '' : htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($can_sheet): ?>
                                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=$filter_doctor?>&exam=<?=$filter_exam?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>&sheet_single=<?=$row['schedule_id']?>" target="_blank" class="sheet-btn-small" title="検査シート印刷">📋</a>
                            <?php else: ?>
                                <span style="font-size:8px; color:#94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if (!$is_empty && !empty($row['patient_id'])): ?>
                                <a href="<?= $fu_url ?>" target="_blank" class="sheet-btn-small <?= $fu_class ?>" title="<?= $fu_title ?>">
                                    📋 <?= $fu_label ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size:8px; color:#94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<script>
function applySlotFilter() {
    const showGif = document.getElementById('show_gif').checked;
    const showEcho = document.getElementById('show_echo').checked;
    const showTcf = document.getElementById('show_tcf').checked;
    const showEmpty = document.getElementById('show_empty');
    const showEmptyChecked = showEmpty ? showEmpty.checked : true;
    const allChecked = showGif && showEcho && showTcf;
    
    document.querySelectorAll('#reservationTable tbody tr').forEach(function(tr) {
        const isReserved = tr.dataset.reserved === 'true';
        const group = tr.dataset.group || 'OTHER';
        
        if (isReserved) {
            if (allChecked) {
                tr.style.display = '';
                return;
            }
            let show = false;
            if (group === 'GIF') show = showGif;
            else if (group === 'ECHO') show = showEcho;
            else if (group === 'TCF') show = showTcf;
            else show = true;
            tr.style.display = show ? '' : 'none';
            return;
        }
        
        if (!showEmptyChecked) {
            tr.style.display = 'none';
            return;
        }
        if (allChecked) {
            tr.style.display = '';
            return;
        }
        let show = false;
        if (group === 'GIF') show = showGif;
        else if (group === 'ECHO') show = showEcho;
        else if (group === 'TCF') show = showTcf;
        else show = true;
        tr.style.display = show ? '' : 'none';
    });
}

function toggleEmptySlots(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_empty', checked ? '1' : '0');
    window.location.href = url.toString();
}

function openModal(date, waku, scheduleId, dept) {
    var url = 'modal_edit.php';
    url += '?date=' + encodeURIComponent(date);
    url += '&waku=' + encodeURIComponent(waku);
    url += '&dept=' + encodeURIComponent(dept);
    if (scheduleId && scheduleId > 0) {
        url += '&schedule_id=' + encodeURIComponent(scheduleId);
    }
    window.open(url, '_blank', 'width=1000,height=800,scrollbars=yes,resizable=yes');
}

document.addEventListener('DOMContentLoaded', function() {
    applySlotFilter();
});
</script>

</body>
</html>