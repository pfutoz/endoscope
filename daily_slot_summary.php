<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\daily_slot_summary.php
 * ファイル名: daily_slot_summary.php
 * 
 * 枠・幾何バランス照会サマリ（閲覧専用・更新機能なし）
 * 修正: 2026-09-18
 *   - 【予約実績・消化率の統合】各検査種別ごとの「予約使用数」「消化率」「残り空き枠」を算出・表示
 *   - 【異常値・トラブル検知】未割当（迷子）予約、枠なし設定、肛門科W制限超過などのアラート表示
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'PROCT';
if (!in_array($dept, ['PROCT', 'GASTRO'], true)) {
    $dept = 'PROCT';
}

$date_str = isset($_GET['date']) && is_string($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';

$ts = strtotime($date_str);
$weekday_idx = (int)date('w', $ts);
$weekday_names = ['日', '月', '火', '水', '木', '金', '土'];
$weekday_str = $weekday_names[$weekday_idx] ?? '';

// 休診判定
$day_check = check_onokai_calendar($date_str, null);
$is_closed = $day_check['is_closed'] ?? false;

// ---------------------------------------------------------
// 1. 時間枠キャパシティ（箱の数）の集計 (m2_waku)
// ---------------------------------------------------------
$stmt_waku = $pdo->prepare("
    SELECT group_code, waku_name, display_order
    FROM m2_waku
    WHERE department_code = :dept
      AND is_active = TRUE
    ORDER BY display_order ASC, id ASC
");
$stmt_waku->execute([':dept' => $dept]);
$raw_waku = $stmt_waku->fetchAll(PDO::FETCH_ASSOC);

$waku_boxes = [
    'GIF'  => [],
    'ECHO' => [],
    'TCF'  => []
];
$waku_count = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
$valid_waku_names = [];

foreach ($raw_waku as $w) {
    $grp = (string)$w['group_code'];
    $wname = (string)$w['waku_name'];
    $valid_waku_names[$wname] = $grp;
    if (isset($waku_count[$grp])) {
        $waku_count[$grp]++;
        $waku_boxes[$grp][] = $wname;
    }
}

// ---------------------------------------------------------
// 2. 当日の実際の予約データの取得と実績集計
// ---------------------------------------------------------
$stmt_res = $pdo->prepare("
    SELECT s.*, w.group_code AS waku_group_code
    FROM t_endoscopy_schedule s
    LEFT JOIN m2_waku w 
      ON s.department_code = w.department_code 
      AND s.scheduled_time = w.waku_name 
      AND w.is_active = TRUE
    WHERE s.department_code = :dept 
      AND s.exam_date = :date 
      AND s.is_canceled = FALSE
");
$stmt_res->execute([':dept' => $dept, ':date' => $date_str]);
$raw_reservations = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

$actual_used = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
$no_waku_count = 0;
$mismatch_count = 0;
$w_exam_count = 0;

$gif_group_types = ['GIF', '◆胃がん検診◆', '胃がん検診'];
$echo_group_types = ['ECHO', 'エコー'];
$tcf_group_types = ['TCF', 'W', 'ポリペク'];

foreach ($raw_reservations as $res) {
    $remarks = trim((string)($res['remarks'] ?? ''));
    $scheduled_time = trim((string)($res['scheduled_time'] ?? ''));
    $exam_type = trim((string)($res['exam_type'] ?? ''));

    // 枠なし設定の判定
    if ($remarks === '＊＊＊🚫枠なし🚫＊＊＊') {
        $no_waku_count++;
        continue;
    }

    // 迷子・未割当判定
    if ($scheduled_time === '' || $scheduled_time === 'MISMATCH' || !isset($valid_waku_names[$scheduled_time])) {
        $mismatch_count++;
    }

    // W検査件数カウント (肛門科)
    if ($dept === 'PROCT' && in_array($exam_type, ['W', 'ポリペク'], true)) {
        $w_exam_count++;
    }

    // 種別ごとの使用数集計
    if (in_array($exam_type, $gif_group_types, true)) {
        $actual_used['GIF']++;
    } elseif (in_array($exam_type, $echo_group_types, true)) {
        $actual_used['ECHO']++;
    } elseif (in_array($exam_type, $tcf_group_types, true)) {
        $actual_used['TCF']++;
    }
}

// ---------------------------------------------------------
// 3. 当該診療科の全医師と供給枠数の集計 (m2_doctor / m2_doctor_*)
// ---------------------------------------------------------
$stmt_all_docs = $pdo->prepare("
    SELECT doctor_name
    FROM m2_doctor
    WHERE department_code = :dept
      AND is_active = TRUE
    ORDER BY display_order ASC, id ASC
");
$stmt_all_docs->execute([':dept' => $dept]);
$all_doctors = $stmt_all_docs->fetchAll(PDO::FETCH_COLUMN);

if (empty($all_doctors)) {
    $all_doctors = getDoctorsForDept($pdo, $dept);
    if (!is_array($all_doctors)) { $all_doctors = []; }
}

$slot_master_cache = [
    'attendance'     => [],
    'daily_capacity' => [],
    'patterns'       => []
];

$stmt_att = $pdo->prepare("
    SELECT doctor_name, gif_slots, echo_slots, tcf_slots, reason, pattern_name
    FROM m2_doctor_attendance
    WHERE department_code = :dept AND target_date = :date
");
$stmt_att->execute([':dept' => $dept, ':date' => $date_str]);
while ($row = $stmt_att->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['attendance'][(string)$row['doctor_name']] = [
        'GIF'    => (int)($row['gif_slots'] ?? 0),
        'ECHO'   => (int)($row['echo_slots'] ?? 0),
        'TCF'    => (int)($row['tcf_slots'] ?? 0),
        'reason' => (string)($row['reason'] ?? $row['pattern_name'] ?? '出勤例外')
    ];
}

$stmt_cap = $pdo->prepare("
    SELECT doctor_name, gif_slots, echo_slots, tcf_slots, memo_jp
    FROM m2_doctor_daily_capacity_v2
    WHERE department_code = :dept AND target_date = :date
");
$stmt_cap->execute([':dept' => $dept, ':date' => $date_str]);
while ($row = $stmt_cap->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['daily_capacity'][(string)$row['doctor_name']] = [
        'GIF'    => (int)($row['gif_slots'] ?? 0),
        'ECHO'   => (int)($row['echo_slots'] ?? 0),
        'TCF'    => (int)($row['tcf_slots'] ?? 0),
        'reason' => (string)($row['memo_jp'] ?? '日別上書き')
    ];
}

$stmt_pat = $pdo->prepare("
    SELECT doctor_name, MAX(gif_slots) as gif_slots, MAX(echo_slots) as echo_slots, MAX(tcf_slots) as tcf_slots
    FROM m2_doctor_slot_pattern
    WHERE department_code = :dept AND day_of_week = :w AND is_active = TRUE
    GROUP BY doctor_name
");
$stmt_pat->execute([':dept' => $dept, ':w' => $weekday_idx]);
while ($row = $stmt_pat->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['patterns'][(string)$row['doctor_name']] = [
        'GIF'  => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF'  => (int)($row['tcf_slots'] ?? 0)
    ];
}

$doctor_supply = [];
$total_doctor_supply = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];

foreach ($all_doctors as $doc_name) {
    $doc_str = (string)$doc_name;
    $slots = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    $rule_type = '通常パターン';
    $note = '';

    if (isset($slot_master_cache['attendance'][$doc_str])) {
        $data = $slot_master_cache['attendance'][$doc_str];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        $rule_type = '出勤例外';
        $note = $data['reason'];
    } elseif (isset($slot_master_cache['daily_capacity'][$doc_str])) {
        $data = $slot_master_cache['daily_capacity'][$doc_str];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        $rule_type = '日別上書き';
        $note = $data['reason'];
    } elseif (isset($slot_master_cache['patterns'][$doc_str])) {
        $slots = $slot_master_cache['patterns'][$doc_str];
        $rule_type = '曜日パターン';
    } else {
        $rule_type = '出勤なし';
    }

    $sum = array_sum($slots);
    if ($sum === 0 && $rule_type === '通常パターン') {
        $rule_type = '出勤なし';
    }

    $doctor_supply[] = [
        'name'      => $doc_str,
        'slots'     => $slots,
        'total'     => $sum,
        'rule_type' => $rule_type,
        'note'      => $note
    ];

    $total_doctor_supply['GIF']  += $slots['GIF'];
    $total_doctor_supply['ECHO'] += $slots['ECHO'];
    $total_doctor_supply['TCF']  += $slots['TCF'];
}

// ---------------------------------------------------------
// 4. 特別判定・ギャップ計算と消化率の算出
// ---------------------------------------------------------
$categories = [
    'GIF'  => ['label' => 'GIF（上部）', 'color' => '#2563eb'],
    'ECHO' => ['label' => 'エコー',       'color' => '#c026d3'],
    'TCF'  => ['label' => 'TCF（下部）', 'color' => '#059669']
];

$gap_summary = [];
foreach ($categories as $cat => $cfg) {
    $raw_boxes    = $waku_count[$cat] ?? 0;
    $sup          = $total_doctor_supply[$cat] ?? 0;
    $used         = $actual_used[$cat] ?? 0;

    $is_special_tcf = ($dept === 'GASTRO' && $cat === 'TCF');
    $max_capacity   = $is_special_tcf ? ($raw_boxes * 4) : $raw_boxes;
    $diff           = $sup - $raw_boxes;
    
    // 消化率 (供給枠に対する実際の予約割合)
    $digestion_rate = ($sup > 0) ? round(($used / $sup) * 100, 1) : 0;
    $remaining_slots = $sup - $used; // 残り空き枠

    $gap_summary[$cat] = [
        'raw_boxes'      => $raw_boxes,
        'max_capacity'   => $max_capacity,
        'supply'         => $sup,
        'used'           => $used,
        'remaining'      => $remaining_slots,
        'digestion_rate' => $digestion_rate,
        'diff'           => $diff,
        'is_special_tcf' => $is_special_tcf
    ];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>枠・供給バランス照会 - <?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f8fafc;
            color: #0f172a;
            font-size: 13px;
        }
        .container {
            max-width: 1020px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 24px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dept-tag {
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            color: #fff;
            background-color: <?= $dept === 'PROCT' ? '#3b82f6' : '#10b981' ?>;
        }
        .close-btn {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            color: #475569;
        }
        .close-btn:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }

        /* アラート・異常値サマリボックス */
        .alert-box {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        .alert-box.normal {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }
        .alert-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            font-size: 12.5px;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            margin: 22px 0 10px 0;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .section-title::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 14px;
            background-color: #2563eb;
            border-radius: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-bottom: 8px;
        }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 10px 12px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 12px;
        }

        .diff-tag {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 12px;
            line-height: 1.2;
        }
        .diff-surplus { background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .diff-shortage { background-color: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .diff-equal { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .diff-multi { background-color: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; }

        .progress-bar-bg {
            background-color: #e2e8f0;
            border-radius: 4px;
            height: 8px;
            width: 100%;
            margin-top: 4px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background-color: #3b82f6;
            border-radius: 4px;
        }

        .box-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin: 4px 0 0 0;
            padding: 0;
            list-style: none;
        }
        .box-item {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 4px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #334155;
        }

        .rule-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            display: inline-block;
        }
        .rule-pattern { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .rule-exception { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
        .rule-daily { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .rule-off { background: #f1f5f9; color: #94a3b8; border: 1px dashed #cbd5e1; }

        .row-absent { background-color: #fafafa; color: #94a3b8; }

        .note-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.6;
            margin-top: 24px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h2>
            <span>📊 枠・供給バランス照会サマリ</span>
            <span class="dept-tag"><?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?></span>
            <span style="font-size:14px; font-weight:normal; color:#64748b;">
                <?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?> (<?= $weekday_str ?>)
                <?= $is_closed ? '<strong style="color:#dc2626;">［休診設定日］</strong>' : '' ?>
            </span>
        </h2>
        <button type="button" class="close-btn" onclick="window.close()">✕ 閉じる</button>
    </div>

    <!-- 💡 異常値・トラブル検知アラートボックス -->
    <?php 
    $has_warnings = ($mismatch_count > 0 || $no_waku_count > 0 || ($dept === 'PROCT' && $w_exam_count >= 3));
    ?>
    <div class="alert-box <?= $has_warnings ? '' : 'normal' ?>">
        <div style="font-weight: 800; font-size: 13px; color: <?= $has_warnings ? '#dc2626' : '#166534' ?>;">
            <?= $has_warnings ? '⚠️ トラブル・異常値検知:' : '✅ 予約データの整合性:' ?>
        </div>
        <div class="alert-item" style="color: <?= $mismatch_count > 0 ? '#dc2626' : 'inherit' ?>;">
            🚨 未割当・迷子予約: <strong><?= $mismatch_count ?>件</strong>
        </div>
        <div class="alert-item" style="color: <?= $no_waku_count > 0 ? '#d97706' : 'inherit' ?>;">
            🚫 枠なし設定: <strong><?= $no_waku_count ?>件</strong>
        </div>
        <?php if ($dept === 'PROCT'): ?>
            <div class="alert-item" style="color: <?= $w_exam_count >= 3 ? '#dc2626' : 'inherit' ?>;" title="肛門科W検査の推奨上限は通常2件です">
                ⚠️ W検査件数: <strong><?= $w_exam_count ?>件</strong> <?= $w_exam_count >= 3 ? '(制限超過)' : '' ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 1. 時間枠キャパシティ・医師供給枠・実績の比較 -->
    <div class="section-title">検査種別ごとの受入枠（供給）と実績・差分バランス</div>
    <table>
        <thead>
            <tr>
                <th style="width: 120px;">検査種別</th>
                <th style="width: 90px; text-align: center;">時間枠（箱）</th>
                <th style="width: 100px; text-align: center;">医師供給枠</th>
                <th style="width: 100px; text-align: center;">実予約数</th>
                <th style="width: 110px; text-align: center;">残り空き枠</th>
                <th style="width: 140px; text-align: center;">消化率</th>
                <th style="width: 140px; text-align: center;">供給 − 箱数（差分）</th>
                <th>分析と状態</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat => $cfg): 
            $boxes       = $gap_summary[$cat]['raw_boxes'];
            $max_cap     = $gap_summary[$cat]['max_capacity'];
            $sup         = $gap_summary[$cat]['supply'];
            $used        = $gap_summary[$cat]['used'];
            $remaining   = $gap_summary[$cat]['remaining'];
            $rate        = $gap_summary[$cat]['digestion_rate'];
            $diff        = $gap_summary[$cat]['diff'];
            $is_spec_tcf = $gap_summary[$cat]['is_special_tcf'];
        ?>
            <tr>
                <td>
                    <strong style="color: <?= $cfg['color'] ?>;"><?= $cfg['label'] ?></strong>
                    <?php if ($is_spec_tcf): ?>
                        <span style="display:block; font-size:10px; color:#7c3aed; font-weight:bold;">★最大<?= $max_cap ?>検査</span>
                    <?php endif; ?>
                </td>
                <td style="text-align: center; font-weight: bold;">
                    <?= $boxes ?> 枠
                </td>
                <td style="text-align: center; font-weight: bold; color: #2563eb;">
                    <?= $sup ?> 枠
                </td>
                <td style="text-align: center; font-weight: bold; color: #0f172a; background-color: #f8fafc;">
                    <?= $used ?> 件
                </td>
                <td style="text-align: center; font-weight: bold; color: <?= $remaining < 0 ? '#dc2626' : '#059669' ?>;">
                    <?= $remaining ?> 枠
                </td>
                <td style="text-align: center;">
                    <div style="font-weight: bold; font-size: 11.5px;"><?= $rate ?>%</div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= min(100, $rate) ?>%; background-color: <?= $rate > 100 ? '#dc2626' : ($rate >= 80 ? '#f59e0b' : '#3b82f6') ?>;"></div>
                    </div>
                </td>
                <td style="text-align: center;">
                    <?php if ($is_spec_tcf): ?>
                        <?php if ($sup <= $boxes): ?>
                            <span class="diff-tag diff-equal">余裕あり</span>
                        <?php elseif ($sup <= $max_cap): ?>
                            <span class="diff-tag diff-multi">特別受入内</span>
                        <?php else: ?>
                            <span class="diff-tag diff-surplus">上限超過</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($diff < 0): ?>
                            <span class="diff-tag diff-surplus"><?= $diff ?>（箱余り）</span>
                        <?php elseif ($diff > 0): ?>
                            <span class="diff-tag diff-shortage">+<?= $diff ?>（箱不足）</span>
                        <?php else: ?>
                            <span class="diff-tag diff-equal">±0（均衡）</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td style="font-size: 11.5px; color: #475569;">
                    <?php if ($is_spec_tcf): ?>
                        1枠4検査対応（最大<?= $max_cap ?>検査）。予約 <?= $used ?>件 / 医師供給 <?= $sup ?>枠。
                    <?php else: ?>
                        予約 <?= $used ?>件に対し医師供給 <?= $sup ?>枠（残り <?= $remaining ?>枠）。
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 2. 当該診療科 所属医師全体の供給枠 内訳 -->
    <div class="section-title"><?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?> 所属医師の枠設定・出勤状況（全医師）</div>
    <?php if (empty($doctor_supply)): ?>
        <p style="color:#94a3b8; padding: 12px; border:1px dashed #cbd5e1; border-radius:6px; background:#fff;">
            <?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>に登録されている医師がいません。
        </p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 140px;">担当医師</th>
                    <th style="width: 120px; text-align: center;">判定基準</th>
                    <th style="width: 80px; text-align: center;">🔵 GIF</th>
                    <th style="width: 80px; text-align: center;">🟠 エコー</th>
                    <th style="width: 80px; text-align: center;">🟢 TCF</th>
                    <th style="width: 90px; text-align: center;">医師合計</th>
                    <th>特記事項 / 上書き理由</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($doctor_supply as $doc): 
                $is_off = ($doc['total'] === 0);
                $rule_badge_cls = 'rule-pattern';
                if ($doc['rule_type'] === '出勤例外') $rule_badge_cls = 'rule-exception';
                elseif ($doc['rule_type'] === '日別上書き') $rule_badge_cls = 'rule-daily';
                elseif ($doc['rule_type'] === '出勤なし') $rule_badge_cls = 'rule-off';
            ?>
                <tr class="<?= $is_off ? 'row-absent' : '' ?>">
                    <td>
                        <strong style="<?= $is_off ? 'color:#64748b;' : 'color:#0f172a;' ?>">
                            <?= htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8') ?>
                        </strong>
                    </td>
                    <td style="text-align: center;">
                        <span class="rule-badge <?= $rule_badge_cls ?>"><?= htmlspecialchars($doc['rule_type'], ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td style="text-align: center; font-weight: bold; color: <?= $is_off ? '#94a3b8' : '#2563eb' ?>;">
                        <?= $doc['slots']['GIF'] ?>
                    </td>
                    <td style="text-align: center; font-weight: bold; color: <?= $is_off ? '#94a3b8' : '#c026d3' ?>;">
                        <?= $doc['slots']['ECHO'] ?>
                    </td>
                    <td style="text-align: center; font-weight: bold; color: <?= $is_off ? '#94a3b8' : '#059669' ?>;">
                        <?= $doc['slots']['TCF'] ?>
                    </td>
                    <td style="text-align: center; font-weight: bold; <?= $is_off ? 'color:#94a3b8;' : 'background-color:#f8fafc;' ?>">
                        <?= $is_off ? '-' : $doc['total'] . ' 枠' ?>
                    </td>
                    <td style="color:#64748b; font-size:11px;">
                        <?= !empty($doc['note']) ? htmlspecialchars($doc['note'], ENT_QUOTES, 'UTF-8') : ($is_off ? '出勤枠なし' : '-') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 3. マスタ定義されている時間枠（箱）一覧 -->
    <div class="section-title">定義済み時間枠（箱）一覧（照会専用）</div>
    <table>
        <thead>
            <tr>
                <th style="width: 140px;">検査グループ</th>
                <th style="width: 80px; text-align: center;">箱の数</th>
                <th>登録されている時間枠名（m2_waku）</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat => $cfg): 
            $boxes = $waku_boxes[$cat] ?? [];
        ?>
            <tr>
                <td><strong style="color: <?= $cfg['color'] ?>;"><?= $cfg['label'] ?></strong></td>
                <td style="text-align: center; font-weight: bold;"><?= count($boxes) ?></td>
                <td>
                    <?php if (empty($boxes)): ?>
                        <span style="color:#94a3b8; font-size:11px;">登録枠なし</span>
                    <?php else: ?>
                        <ul class="box-list">
                            <?php foreach ($boxes as $bx): ?>
                                <li class="box-item"><?= htmlspecialchars($bx, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="note-box">
        <strong>💡 サマリの見方:</strong><br>
        ・<strong>実予約数・消化率:</strong> 当日に実際に埋まっている予約件数と、医師供給枠に対する消化率（％）・残り空き枠をリアルタイム表示しています。<br>
        ・<strong>トラブル検知アラート:</strong> 時間枠の紐づきがおかしい「迷子予約」や「枠なし設定」、「W検査の制限超過（肛門科）」などを上部バナーで警告表示します。<br>
        ・<strong>胃腸科TCFの特別判定:</strong> 1枠最大4検査に対応しており、供給枠数が箱数を上回る場合でも許容範囲内であれば複数名予約として正しく処理されます。
    </div>
</div>

</body>
</html>