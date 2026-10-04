<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\print_schedule_daily.php
 * ファイル名: print_schedule_daily.php
 * 概要: 日別内視鏡予約一覧 印刷専用モジュール (A4横向き)
 * 修正: 2026-09-08
 *   - 同一日内で部屋番号が重複している場合、部屋名称を赤太字・強調枠で警告表示
 *   - 枠重複の警告は撤去（枠重複は許容）
 *   - 生年月日の和暦表示化（convertToWareki）
 *   - 年齢計算を検査日時点の満年齢に修正（70歳以上: 濃いめオレンジ、69歳以下: 青）
 *   - 薬剤バッジ色を修正（モビ: 青、マグP: 濃いめオレンジ、ニフ: 緑）
 *   - 備考欄のRTF/HTMLデコード描画対応
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$dept        = isset($_GET['dept']) ? (string)$_GET['dept'] : 'PROCT';
$date_str    = isset($_GET['date']) && is_string($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_exam = isset($_GET['exam']) ? trim((string)$_GET['exam']) : 'all';
$show_empty  = isset($_GET['show_empty']) ? ((string)$_GET['show_empty'] === '1') : false;
$sort_by     = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'time';

if (!in_array($dept, ['PROCT', 'GASTRO', 'ALL'], true)) { $dept = 'PROCT'; }

if ($dept === 'ALL') {
    $dept_label = '全診療科（肛門科・胃腸科）';
    $dept_condition_schedule = "1=1";
    $dept_condition_waku     = "1=1";
} else {
    $dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';
    $dept_condition_schedule = "s.department_code = '{$dept}'";
    $dept_condition_waku     = "department_code = '{$dept}'";
}

/**
 * MALL3 RTF構文（NoteRTF / remarks）をHTML（カラー・太字・改行）へ完全変換
 */
function parseRtfToHtml(?string $rtf): string {
    if ($rtf === null || $rtf === '') return '';
    $text = trim($rtf);

    if (strpos($text, '{\\rtf') !== 0) {
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    $color_map = [0 => 'inherit'];
    if (preg_match('/{\\\\colortbl\\s*;?(.*?)}/s', $text, $m_col)) {
        $defs = explode(';', $m_col[1]);
        $idx = 1;
        foreach ($defs as $d) {
            $d = trim($d);
            if ($d === '') continue;
            if (preg_match('/\\\\red(\d+)\\\\green(\d+)\\\\blue(\d+)/', $d, $rgb)) {
                $color_map[$idx] = "rgb({$rgb[1]},{$rgb[2]},{$rgb[3]})";
                $idx++;
            }
        }
    }

    $text = preg_replace('/{\\\\\\*?\\\\fonttbl.*?}/s', '', $text);
    $text = preg_replace('/{\\\\\\*?\\\\colortbl.*?}/s', '', $text);
    $text = preg_replace('/{\\\\\\*?\\\\stylesheet.*?}/s', '', $text);
    $text = preg_replace('/{\\\\\\*?\\\\info.*?}/s', '', $text);
    $text = preg_replace('/^.*?\\\\viewkind\d+\\\\uc\d+/s', '', $text);
    $text = preg_replace('/^.*?\\\\pard/s', '', $text);

    $tokens = preg_split('/(\\\\[a-zA-Z0-9_-]+(?:\\s+)?|\\\\\'[0-9a-fA-F]{2}|\\\\par|\\\\tab|[{}]|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $html = '';
    $is_bold = false;
    $current_color = 'inherit';
    $sjis_buffer = '';

    $flush_sjis = function() use (&$sjis_buffer, &$html, &$is_bold, &$current_color) {
        if ($sjis_buffer === '') return;
        $utf8_str = mb_convert_encoding($sjis_buffer, 'UTF-8', 'CP932,SJIS,UTF-8');
        $escaped = htmlspecialchars($utf8_str, ENT_QUOTES, 'UTF-8');

        $style = '';
        if ($current_color !== 'inherit') {
            $style .= "color: {$current_color};";
        }
        if ($is_bold) {
            $style .= "font-weight: bold;";
        }

        if ($style !== '') {
            $html .= "<span style=\"{$style}\">{$escaped}</span>";
        } else {
            $html .= $escaped;
        }
        $sjis_buffer = '';
    };

    foreach ($tokens as $token) {
        if (preg_match('/^\\\\\'([0-9a-fA-F]{2})$/', $token, $m_hex)) {
            $sjis_buffer .= chr(hexdec($m_hex[1]));
            continue;
        }

        $flush_sjis();

        $lower_tok = strtolower(trim($token));

        if ($lower_tok === '\\par' || $token === "\n" || $token === "\r") {
            $html .= "<br>\n";
        } elseif ($lower_tok === '\\tab') {
            $html .= " &nbsp; &nbsp; ";
        } elseif ($lower_tok === '\\b') {
            $is_bold = true;
        } elseif ($lower_tok === '\\b0') {
            $is_bold = false;
        } elseif (preg_match('/^\\\\cf(\d+)$/', $lower_tok, $m_cf)) {
            $c_idx = (int)$m_cf[1];
            $current_color = $color_map[$c_idx] ?? 'inherit';
        } elseif ($token === '{' || $token === '}') {
            continue;
        } elseif (strpos($token, '\\') === 0) {
            continue;
        } else {
            $style = '';
            if ($current_color !== 'inherit') $style .= "color: {$current_color};";
            if ($is_bold) $style .= "font-weight: bold;";

            $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
            if ($style !== '') {
                $html .= "<span style=\"{$style}\">{$escaped}</span>";
            } else {
                $html .= $escaped;
            }
        }
    }

    $flush_sjis();

    $html = preg_replace('/(?:<br>\s*)+$/i', '', trim($html));
    $html = preg_replace('/^(?:<br>\s*)+/i', '', $html);

    return $html;
}

function renderRemarksRtfHtml(?string $raw): string {
    if ($raw === null || $raw === '') return '';
    $trimmed = trim($raw);
    
    if (strpos($trimmed, '{\\rtf') === 0) {
        return '<div class="rich-remarks">' . parseRtfToHtml($trimmed) . '</div>';
    }
    if (preg_match('/<(?:p|br|strong|b|em|span|ul|ol|li)[^>]*>/i', $trimmed)) {
        $allowed = '<p><br><strong><b><em><span><ul><ol><li>';
        return '<div class="rich-remarks">' . strip_tags($trimmed, $allowed) . '</div>';
    }
    return '<div class="plain-remarks">' . nl2br(htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')) . '</div>';
}

// 予約データ取得SQL
if ($show_empty) {
    $sql = "
    WITH 
    all_slots AS (
        SELECT department_code, waku_name, display_order, group_code
        FROM m2_waku
        WHERE {$dept_condition_waku} AND is_active = TRUE
    ),
    reserved_data AS (
        SELECT 
            s.schedule_id, s.exam_date, s.scheduled_time, s.waku_no,
            s.patient_id, s.patient_name, s.patient_kana, s.birthday,
            s.doctor_name, s.exam_type, s.arrival_time, s.exam_location,
            s.remarks, s.room_number, s.bowel_prep, s.is_canceled,
            s.department_code,
            w.display_order AS waku_display_order, w.group_code,
            CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
            FALSE AS is_empty
        FROM t_endoscopy_schedule s
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
        WHERE {$dept_condition_schedule} 
          AND s.exam_date = :date 
          AND s.is_canceled = FALSE
    )
    SELECT 
        COALESCE(r.scheduled_time, 'MISMATCH') AS scheduled_time,
        -1 AS waku_no,
        COALESCE(r.group_code, 'MISMATCH') AS group_code,
        r.department_code,
        COALESCE(r.patient_id, '') AS patient_id,
        COALESCE(r.patient_name, '') AS patient_name,
        COALESCE(r.birthday::text, '') AS birthday,
        COALESCE(r.doctor_name, '') AS doctor_name,
        COALESCE(r.exam_type, '') AS exam_type,
        COALESCE(r.arrival_time, '') AS arrival_time,
        COALESCE(r.remarks, '') AS remarks,
        COALESCE(r.room_number, '') AS room_number,
        COALESCE(r.bowel_prep, '') AS bowel_prep,
        COALESCE(r.schedule_id, 0) AS schedule_id,
        FALSE AS is_empty,
        TRUE AS is_mismatch
    FROM reserved_data r
    WHERE r.is_mismatch = TRUE

    UNION ALL

    SELECT 
        a.waku_name AS scheduled_time,
        a.display_order AS waku_no,
        a.group_code,
        a.department_code,
        COALESCE(r.patient_id, '') AS patient_id,
        COALESCE(r.patient_name, '') AS patient_name,
        COALESCE(r.birthday::text, '') AS birthday,
        COALESCE(r.doctor_name, '') AS doctor_name,
        COALESCE(r.exam_type, '') AS exam_type,
        COALESCE(r.arrival_time, '') AS arrival_time,
        COALESCE(r.remarks, '') AS remarks,
        COALESCE(r.room_number, '') AS room_number,
        COALESCE(r.bowel_prep, '') AS bowel_prep,
        COALESCE(r.schedule_id, 0) AS schedule_id,
        CASE WHEN r.schedule_id IS NULL THEN TRUE ELSE FALSE END AS is_empty,
        FALSE AS is_mismatch
    FROM all_slots a
    LEFT JOIN reserved_data r 
        ON a.waku_name = r.scheduled_time 
        AND a.department_code = r.department_code
    ORDER BY is_mismatch DESC, department_code ASC, waku_no ASC, schedule_id ASC;
    ";
} else {
    $sql = "
    SELECT 
        s.*,
        w.display_order AS waku_display_order,
        COALESCE(w.group_code, 'MISMATCH') AS group_code,
        CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
        FALSE AS is_empty
    FROM t_endoscopy_schedule s
    LEFT JOIN m2_waku w 
        ON s.department_code = w.department_code 
        AND s.scheduled_time = w.waku_name
        AND w.is_active = TRUE
    WHERE {$dept_condition_schedule} 
      AND s.exam_date = :date 
      AND s.is_canceled = FALSE
    ORDER BY is_mismatch DESC, s.department_code ASC, COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC;
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([':date' => $date_str]);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$print_rows = [];
// 当日内の部屋重複カウント集計
$room_counts = [];

foreach ($raw_data as $row) {
    $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
    $is_mismatch = isset($row['is_mismatch']) && $row['is_mismatch'] === true;
    $exam = (string)($row['exam_type'] ?? '');
    $group = (string)($row['group_code'] ?? 'OTHER');

    $row_cat = 'OTHER';
    if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') {
        $row_cat = 'GIF';
    } elseif ($exam === 'ECHO' || $exam === 'エコー') {
        $row_cat = 'ECHO';
    } elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') {
        $row_cat = 'TCF';
    }

    $final_cat = ($row_cat !== 'OTHER') ? $row_cat : $group;

    if ($filter_exam !== 'all') {
        $target_exams = explode(',', $filter_exam);
        if (!$is_mismatch && !in_array($final_cat, $target_exams, true)) {
            continue;
        }
    }
    $print_rows[] = $row;

    // 予約が入っている有効な部屋番号を集計
    if (!$is_empty) {
        $rm = trim((string)($row['room_number'] ?? ''));
        if ($rm !== '' && $rm !== '未指定' && $rm !== '-') {
            $room_counts[$rm] = ($room_counts[$rm] ?? 0) + 1;
        }
    }
}

if ($sort_by === 'room') {
    usort($print_rows, function ($a, $b) {
        $roomA = trim((string)($a['room_number'] ?? ''));
        $roomB = trim((string)($b['room_number'] ?? ''));

        if ($roomA === '' && $roomB !== '') return 1;
        if ($roomA !== '' && $roomB === '') return -1;
        if ($roomA !== $roomB) {
            return strnatcasecmp($roomA, $roomB);
        }

        return strnatcasecmp((string)($a['scheduled_time'] ?? ''), (string)($b['scheduled_time'] ?? ''));
    });
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内視鏡予約一覧印刷 - <?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; }
        @page {
            size: A4 landscape;
            margin: 10mm 12mm;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 9.5pt;
            color: #0f172a;
            background: #fff;
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .no-print-bar {
            background: #1e293b;
            color: #fff;
            padding: 8px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .no-print-bar select, .no-print-bar input {
            padding: 3px 6px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            font-size: 11px;
            font-weight: bold;
        }
        .btn-print {
            background: #10b981;
            color: #fff;
            border: none;
            padding: 5px 16px;
            font-size: 12px;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-print:hover { background: #059669; }

        .print-container {
            padding: 10px 14px;
        }
        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        .sheet-title {
            font-size: 14pt;
            font-weight: 800;
            letter-spacing: -0.01em;
        }
        .sheet-meta {
            font-size: 9pt;
            font-weight: bold;
            color: #334155;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        th, td {
            border: 1px solid #64748b;
            padding: 4px 6px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #f1f5f9 !important;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            font-size: 8.5pt;
        }
        tr:nth-child(even) td {
            background-color: #fafafa;
        }

        .dept-icon-badge {
            display: inline-block;
            width: 16px;
            height: 16px;
            line-height: 16px;
            text-align: center;
            font-size: 7.5pt;
            font-weight: 800;
            color: #ffffff !important;
            border-radius: 3px;
            margin-right: 4px;
            vertical-align: middle;
        }
        .dept-icon-badge.PROCT { background-color: #2563eb !important; }
        .dept-icon-badge.GASTRO { background-color: #059669 !important; }

        .exam-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 7.5pt;
            color: #fff !important;
            white-space: nowrap;
        }
        .exam-badge.gif { background-color: #0ea5e9 !important; }
        .exam-badge.tcf { background-color: #10b981 !important; }
        .exam-badge.echo { background-color: #8b5cf6 !important; }
        .exam-badge.other { background-color: #64748b !important; }

        /* 部屋バッジ（通常 / 重複警告） */
        .room-badge {
            display: inline-block;
            padding: 1px 5px;
            background: #eff6ff !important;
            color: #1e40af !important;
            border: 1px solid #bfdbfe;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: 800;
            white-space: nowrap;
        }
        .room-badge.room-duplicate {
            background-color: #fee2e2 !important;
            color: #dc2626 !important;
            border: 1.5px solid #ef4444 !important;
            font-weight: 900 !important;
        }

        /* 薬剤バッジ（モビ: 青, マグP: 濃いめオレンジ, ニフ: 緑） */
        .prep-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: 800;
            color: #ffffff !important;
            white-space: nowrap;
        }
        .prep-badge.prep-mobi { background-color: #2563eb !important; }
        .prep-badge.prep-magp { background-color: #ea580c !important; }
        .prep-badge.prep-nif  { background-color: #16a34a !important; }

        /* 年齢バッジ（70歳以上: 濃いめオレンジ, 69歳以下: 青） */
        .age-box {
            display: inline-block;
            color: #ffffff !important;
            font-size: 7.5pt;
            font-weight: 800;
            line-height: 1;
            padding: 1px 4px;
            border-radius: 2px;
            margin-left: 4px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .age-box.age-senior { background-color: #ea580c !important; }
        .age-box.age-under  { background-color: #2563eb !important; }

        .rich-remarks p, .plain-remarks { margin: 0; padding: 0; line-height: 1.3; }
        .rich-remarks ul, .rich-remarks ol { margin: 2px 0 2px 14px; padding: 0; }
        .rich-remarks strong, .rich-remarks b { font-weight: 800; }

        .print-footer {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 7.5pt;
            color: #64748b;
        }

        @media print {
            .no-print-bar { display: none !important; }
            .print-container { padding: 0; }
            body { font-size: 9pt; }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span style="font-weight:bold; font-size:13px;">🖨️ 印刷設定</span>
        
        <form method="GET" id="filterForm" style="display:flex; align-items:center; gap:8px; margin:0;">
            <input type="hidden" name="date" value="<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>">
            
            <label>診療科:
                <select name="dept" onchange="this.form.submit()">
                    <option value="ALL" <?= $dept === 'ALL' ? 'selected' : '' ?>>全診療科 (肛門+胃腸)</option>
                    <option value="PROCT" <?= $dept === 'PROCT' ? 'selected' : '' ?>>肛門科</option>
                    <option value="GASTRO" <?= $dept === 'GASTRO' ? 'selected' : '' ?>>胃腸科</option>
                </select>
            </label>

            <label>検査種別:
                <select name="exam" onchange="this.form.submit()">
                    <option value="all" <?= $filter_exam === 'all' ? 'selected' : '' ?>>全種別</option>
                    <option value="GIF" <?= $filter_exam === 'GIF' ? 'selected' : '' ?>>GIF のみ</option>
                    <option value="ECHO" <?= $filter_exam === 'ECHO' ? 'selected' : '' ?>>エコー のみ</option>
                    <option value="TCF" <?= $filter_exam === 'TCF' ? 'selected' : '' ?>>TCF のみ</option>
                </select>
            </label>

            <label>並び順:
                <select name="sort" onchange="this.form.submit()">
                    <option value="time" <?= $sort_by === 'time' ? 'selected' : '' ?>>時間枠順</option>
                    <option value="room" <?= $sort_by === 'room' ? 'selected' : '' ?>>🚪 部屋番号順</option>
                </select>
            </label>

            <label style="cursor:pointer; display:inline-flex; align-items:center; gap:3px;">
                <input type="checkbox" name="show_empty" value="1" <?= $show_empty ? 'checked' : '' ?> onchange="this.form.submit()">
                空き枠を含む
            </label>
        </form>
    </div>

    <div style="display:flex; gap:8px; align-items: center;">
        <a href="progress_monitor.php?date=<?= urlencode($date_str) ?>&dept=<?= urlencode($dept) ?>" style="background:linear-gradient(135deg, #2563eb, #1d4ed8); color:#fff; text-decoration:none; padding:5px 12px; border-radius:4px; font-weight:bold; font-size:12px; display:inline-flex; align-items:center; gap:4px;">📊 当日ホワイトボード</a>
        <button type="button" class="btn-print" onclick="window.print()">🖨️ 印刷を実行</button>
        <button type="button" onclick="window.close()" style="background:#475569; color:#fff; border:none; padding:5px 12px; border-radius:4px; cursor:pointer; font-weight:bold;">✕ 閉じる</button>
    </div>
</div>

<div class="print-container">
    <div class="sheet-header">
        <div class="sheet-title">内視鏡予約一覧表（<?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>）</div>
        <div class="sheet-meta">
            対象日: <strong><?= date('Y年m月d日', strtotime($date_str)) ?></strong>
            （<?= count($print_rows) ?> 件）
            <?= $sort_by === 'room' ? '<span style="color:#2563eb; margin-left:6px;">［部屋番号順］</span>' : '' ?>
        </div>
    </div>

    <?php if (empty($print_rows)): ?>
        <p style="text-align:center; padding:30px; color:#64748b;">該当する予約はありません</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 12%;">時間枠</th>
                    <th style="width: 7%;">来院時刻</th>
                    <th style="width: 8%;">カルテID</th>
                    <th style="width: 18%;">患者名</th>
                    <th style="width: 7%;">検査種別</th>
                    <th style="width: 7%;">部屋</th>
                    <th style="width: 6%;">下剤</th>
                    <th style="width: 8%;">担当</th>
                    <th style="width: 27%;">備考</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($print_rows as $row): 
                    $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
                    $is_mismatch = isset($row['is_mismatch']) && $row['is_mismatch'] === true;
                    
                    $exam = (string)($row['exam_type'] ?? '');
                    $cls = 'other';
                    if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') $cls = 'gif';
                    elseif ($exam === 'ECHO' || $exam === 'エコー') $cls = 'echo';
                    elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') $cls = 'tcf';

                    $time_disp = (string)($row['scheduled_time'] ?? '---');
                    if ($is_mismatch) {
                        $time_disp = '🚨 迷子 (' . $time_disp . ')';
                    } elseif ($time_disp === 'MISMATCH' || empty($time_disp)) {
                        $time_disp = '🚨 枠未設定';
                    }

                    $row_dept = (string)($row['department_code'] ?? 'PROCT');
                    $dept_char = ($row_dept === 'GASTRO') ? '胃' : '肛';
                    $dept_badge_cls = ($row_dept === 'GASTRO') ? 'GASTRO' : 'PROCT';

                    $arrival_disp = (string)($row['arrival_time'] ?? '');
                    if (empty($arrival_disp) && !$is_empty) $arrival_disp = '---';

                    $patient_id_disp = $is_empty ? '' : (string)($row['patient_id'] ?? '---');
                    $patient_name_disp = $is_empty ? '［空き枠］' : (string)($row['patient_name'] ?? '');
                    $doctor_name_disp = $is_empty ? '' : (string)($row['doctor_name'] ?? '');

                    // 年齢計算＆和暦変換
                    $age_int = null;
                    $age_cls = 'age-under';
                    $b_date_clean = '';
                    $b_wareki = '';
                    if (!$is_empty && !empty($row['birthday'])) {
                        $b_date_clean = substr(trim((string)$row['birthday']), 0, 10);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_date_clean)) {
                            $b_dt = new DateTime($b_date_clean);
                            $t_dt = new DateTime($date_str);
                            $age_int = $b_dt->diff($t_dt)->y;
                            if ($age_int >= 70) {
                                $age_cls = 'age-senior';
                            } else {
                                $age_cls = 'age-under';
                            }
                            $b_wareki = convertToWareki($b_date_clean);
                        }
                    }

                    // 部屋設定＆重複判定
                    $room_val = trim((string)($row['room_number'] ?? ''));
                    $is_room_duplicate = false;
                    if (!$is_empty && $room_val !== '' && $room_val !== '未指定' && $room_val !== '-') {
                        if (isset($room_counts[$room_val]) && $room_counts[$room_val] > 1) {
                            $is_room_duplicate = true;
                        }
                    }

                    // 下剤カラー（モビ: 青, マグP: 濃いめオレンジ, ニフ: 緑）
                    $prep_val = trim((string)($row['bowel_prep'] ?? ''));
                    $prep_cls = '';
                    if ($prep_val === 'モビ') {
                        $prep_cls = 'prep-mobi';
                    } elseif ($prep_val === 'マグP') {
                        $prep_cls = 'prep-magp';
                    } elseif ($prep_val === 'ニフ') {
                        $prep_cls = 'prep-nif';
                    }
                ?>
                    <tr <?= $is_mismatch ? 'style="background-color:#fee2e2 !important;"' : ($is_empty ? 'style="color:#64748b;"' : '') ?>>
                        <td>
                            <span class="dept-icon-badge <?= $dept_badge_cls ?>"><?= $dept_char ?></span>
                            <strong><?= htmlspecialchars($time_disp, ENT_QUOTES, 'UTF-8') ?></strong>
                        </td>
                        <td style="text-align:center;"><?= htmlspecialchars($arrival_disp, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($patient_id_disp, ENT_QUOTES, 'UTF-8') ?></td>
                        
                        <td>
                            <?php if ($is_empty): ?>
                                <span style="font-weight:bold; color:#64748b;">［空き枠］</span>
                            <?php else: ?>
                                <div>
                                    <strong><?= htmlspecialchars($patient_name_disp, ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ($age_int !== null): ?>
                                        <span class="age-box <?= $age_cls ?>"><?= $age_int ?>歳</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($b_wareki !== ''): ?>
                                    <div style="font-size:7.5pt; color:#64748b;">
                                        🎂 <?= htmlspecialchars($b_wareki, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:center;">
                            <?php if (!$is_empty): ?>
                                <span class="exam-badge <?= $cls ?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- 部屋表示：重複時は赤太字・強調 -->
                        <td style="text-align:center;">
                            <?php if (!$is_empty && !empty($room_val) && $room_val !== '未指定'): ?>
                                <span class="room-badge <?= $is_room_duplicate ? 'room-duplicate' : '' ?>">
                                    <?= $is_room_duplicate ? '⚠️ ' : '' ?><?= htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <!-- 下剤表示 -->
                        <td style="text-align:center;">
                            <?php if (!$is_empty && !empty($prep_val) && $prep_val !== '未指定'): ?>
                                <span class="prep-badge <?= $prep_cls ?>"><?= htmlspecialchars($prep_val, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($doctor_name_disp, ENT_QUOTES, 'UTF-8') ?></td>

                        <!-- 備考欄（RTF/リッチテキスト対応） -->
                        <td style="font-size:8pt; word-break:break-all;">
                            <?= renderRemarksRtfHtml((string)($row['remarks'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="print-footer">
        <div>印刷日時: <?= date('Y-m-d H:i:s') ?></div>
        <div>医療法人小野会 おの肛門科胃腸科内視鏡クリニック</div>
    </div>
</div>

</body>
</html>