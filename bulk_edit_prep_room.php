<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\bulk_edit_prep_room.php
 * ファイル名: bulk_edit_prep_room.php
 * 概要: 当日予約の「マイトイレ部屋番号」「下剤」一括編集・保存画面 (m_input_master連携版)
 * 修正: 2026-09-10
 *   - 【下剤一括設定の絞り込み】下剤一括設定ボタンの対象を「下部内視鏡（TCF / ポリペク / W）」の予約行のみに限定
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/lib/db.php';

$pdo = getDbConnection();
if (!is_null($pdo) && $pdo === false) { die("データベース接続失敗"); }
if (!$pdo) { die("データベース接続失敗"); }

$dept = $_GET['dept'] ?? ($_SESSION['ui_state']['schedule_dept'] ?? 'PROCT');
$date = $_GET['date'] ?? date('Y-m-d');

if (!in_array($dept, ['PROCT', 'GASTRO', 'ALL'], true)) {
    $dept = 'PROCT';
}

if ($dept === 'ALL') {
    $dept_sql_condition = "1=1";
} else {
    $dept_sql_condition = "department_code = :dept";
}

function getMasterItems(PDO $pdo, string $category, array $default_items = []): array {
    try {
        $stmt = $pdo->prepare("
            SELECT item_value 
            FROM m_input_master 
            WHERE category = :cat 
            ORDER BY display_order ASC, master_id ASC
        ");
        $stmt->execute([':cat' => $category]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return (!empty($rows)) ? $rows : $default_items;
    } catch (Exception $e) {
        return $default_items;
    }
}

$rooms = getMasterItems($pdo, 'toilet_room', [
    'ﾏ101', 'ﾏ102', 'ﾏ103', 'ﾏ104', 'ﾏ105', 'ﾏ106', 'ﾏ107',
    'ﾏ201', 'ﾏ202', 'ﾏ203', 'ﾏ204', 'ﾏ205', 'ﾏ206', 'ﾏ207',
    'ﾏ障害',
    'ｲ203', 'ｲ205', 'ｲ206', 'ｲ207',
    'ｺ205', 'ｺ206', 'ｺ207', 'ｺ211', 'ｺ212'
]);

$preps = getMasterItems($pdo, 'bowel_prep', ['マグP', 'モビ', 'ニフ']);

$prep_labels = [
    'マグP' => 'マグコロールP',
    'モビ'  => 'モビプレップ',
    'ニフ'  => 'ニフレック'
];

function getDoctorBadgeHTML(string $doctor_name): string {
    $name = trim($doctor_name);
    if ($name === '') {
        return '<span class="doc-icon-badge bg-slate" title="担当未定">未</span>';
    }
    $char = mb_substr($name, 0, 1, 'UTF-8');
    $color_map = [
        '義' => 'bg-indigo',
        '二' => 'bg-emerald',
        '誠' => 'bg-amber',
        '哲' => 'bg-cyan',
        '太' => 'bg-rose',
    ];
    $cls = $color_map[$char] ?? 'bg-slate';
    return '<span class="doc-icon-badge ' . $cls . '" title="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($char, ENT_QUOTES, 'UTF-8') . '</span>';
}

function getRoomOptionStyle(string $roomName): string {
    $trimmed = trim($roomName);
    if ($trimmed === '') return '';

    if (preg_match('/^(?:ﾏ|マ)/u', $trimmed)) {
        return 'background-color: #ffffff; color: #1e293b;';
    }
    if (preg_match('/^(?:ｲ|イ)/u', $trimmed)) {
        return 'background-color: #eff6ff; color: #1e40af; font-weight: bold;';
    }
    if (preg_match('/^(?:ｵ|オ)/u', $trimmed)) {
        return 'background-color: #fef2f2; color: #b91c1c; font-weight: bold;';
    }

    return '';
}

function getPrepOptionStyle(string $prepName): string {
    $trimmed = trim($prepName);
    if ($trimmed === '') return '';

    if (preg_match('/^モビ/u', $trimmed)) {
        return 'background-color: #eff6ff; color: #1e40af; font-weight: bold;';
    }
    if (preg_match('/^マグP/u', $trimmed)) {
        return 'background-color: #fef2f2; color: #b91c1c; font-weight: bold;';
    }
    if (preg_match('/^ニフ/u', $trimmed)) {
        return 'background-color: #f0fdf4; color: #166534; font-weight: bold;';
    }

    return '';
}

// 保存処理
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['items'])) {
    try {
        $stmt_upd = $pdo->prepare("
            UPDATE t_endoscopy_schedule 
            SET room_number = :room, bowel_prep = :prep, updated_at = CURRENT_TIMESTAMP
            WHERE schedule_id = :sid AND department_code = :row_dept
        ");
        foreach ($_POST['items'] as $sid => $val) {
            $row_dept = trim($val['department_code'] ?? ($dept !== 'ALL' ? $dept : 'PROCT'));
            $stmt_upd->execute([
                ':room'     => trim($val['room_number'] ?? ''),
                ':prep'     => trim($val['bowel_prep'] ?? ''),
                ':sid'      => (int)$sid,
                ':row_dept' => $row_dept
            ]);
        }
        $message = '保存が完了しました。';
    } catch (Exception $e) {
        $message = 'エラー: ' . $e->getMessage();
    }
}

// 予約一覧取得
$sql = "
    SELECT schedule_id, department_code, scheduled_time, patient_id, patient_name, birthday, exam_type, doctor_name, 
           COALESCE(room_number, '') AS room_number, 
           COALESCE(bowel_prep, '') AS bowel_prep
    FROM t_endoscopy_schedule
    WHERE {$dept_sql_condition} AND exam_date = :date AND is_canceled = FALSE
    ORDER BY department_code ASC, scheduled_time ASC, schedule_id ASC
";
$stmt = $pdo->prepare($sql);
$params = [':date' => $date];
if ($dept !== 'ALL') {
    $params[':dept'] = $dept;
}
$stmt->execute($params);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>部屋・下剤 一括設定 - <?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            font-size: 12px; 
            margin: 15px; 
            background: #f0f4f8; 
            color: #1e293b; 
        }
        .card { 
            background: #fff; 
            border: 1px solid #cbd5e1; 
            border-top: 4px solid #0284c7; 
            border-radius: 0 0 8px 8px; 
            padding: 16px; 
            max-width: 980px; 
            margin: 0 auto; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
        }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
        .header-left { display: flex; align-items: center; gap: 10px; }
        .header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; table-layout: fixed; }
        th, td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
        select { padding: 4px 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px; font-weight: bold; width: 100%; background: #fff; }
        
        .dept-select-box {
            width: auto;
            min-width: 130px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: bold;
            border: 1.5px solid #0284c7;
            background: #f0f9ff;
            color: #0369a1;
            border-radius: 6px;
            cursor: pointer;
        }

        .btn { padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: bold; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-auto { background: #8b5cf6; color: #fff; }
        .btn-auto:hover { background: #7c3aed; }
        .btn-close { background: #64748b; color: #fff; }
        .btn-close:hover { background: #475569; }
        .btn-warning-submit { background: #dc2626; color: #fff; }
        .btn-warning-submit:hover { background: #b91c1c; }

        .msg { background: #dcfce7; color: #15803d; border: 1px solid #86efac; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; font-weight: bold; }

        .inline-warning-banner {
            display: none;
            background-color: #fff1f2;
            border: 2px solid #e11d48;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 14px;
            box-shadow: 0 4px 10px rgba(225, 29, 72, 0.12);
        }
        .inline-warning-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 800;
            color: #be123c;
            font-size: 13px;
            margin-bottom: 6px;
        }
        .inline-warning-list {
            margin: 0;
            padding-left: 20px;
            color: #9f1239;
            font-size: 11.5px;
            line-height: 1.5;
            max-height: 130px;
            overflow-y: auto;
        }
        .inline-warning-footer {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 10px;
            border-top: 1px dashed #fecdd3;
            padding-top: 8px;
        }

        .dept-icon-badge {
            display: inline-block;
            width: 17px;
            height: 17px;
            line-height: 17px;
            text-align: center;
            font-size: 9px;
            font-weight: 800;
            color: #ffffff !important;
            border-radius: 3px;
            margin-right: 4px;
            vertical-align: middle;
            flex-shrink: 0;
        }
        .dept-icon-badge.PROCT { background-color: #2563eb !important; }
        .dept-icon-badge.GASTRO { background-color: #059669 !important; }

        .doc-icon-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            min-width: 18px;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1;
            user-select: none;
            flex-shrink: 0;
        }
        .doc-icon-badge.bg-indigo  { background-color: #4f46e5; }
        .doc-icon-badge.bg-emerald { background-color: #059669; }
        .doc-icon-badge.bg-amber   { background-color: #d97706; }
        .doc-icon-badge.bg-cyan    { background-color: #0891b2; }
        .doc-icon-badge.bg-rose    { background-color: #e11d48; }
        .doc-icon-badge.bg-slate   { background-color: #64748b; }

        .age-box {
            display: inline-block;
            color: #ffffff !important;
            font-size: 9.5px;
            font-weight: 800;
            line-height: 1.1;
            padding: 1px 4px;
            border-radius: 3px;
            margin-left: 4px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .age-box.age-senior { background-color: #ea580c !important; }
        .age-box.age-under  { background-color: #2563eb !important; }

        .exam-doc-cell {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .exam-text {
            font-weight: 700;
            color: #334155;
            font-size: 11px;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="header">
        <div class="header-left">
            <h3 style="margin:0;">🚪 部屋・下剤 一括設定 (<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>)</h3>
            <select class="dept-select-box" onchange="changeDept(this.value)" title="対象診療科を切り替えます">
                <option value="PROCT" <?= $dept === 'PROCT' ? 'selected' : '' ?>>🟢 肛門科</option>
                <option value="GASTRO" <?= $dept === 'GASTRO' ? 'selected' : '' ?>>🔵 胃腸科</option>
                <option value="ALL" <?= $dept === 'ALL' ? 'selected' : '' ?>>🌐 全診療科</option>
            </select>
        </div>
        <div class="header-actions">
            <button type="button" class="btn btn-auto" onclick="autoAssignPreps()" 
                    title="下部内視鏡（TCF/ポリペク/W）かつ下剤が未設定の予約に対して、満年齢基準（70歳以上: マグP ／ 69歳以下: モビ）で一括選択します。">
                ⚡ 下剤一括設定
            </button>
            <span style="font-size:10px; color:#64748b;">(下部限定/未設定行のみ 70歳↑:ﾏｸﾞP / 69歳↓:ﾓﾋﾞ)</span>
            <button type="button" class="btn btn-close" onclick="window.opener ? window.opener.location.reload() : null; window.close();">✕ 閉じる</button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div id="inlineWarningBanner" class="inline-warning-banner">
        <div class="inline-warning-header">
            <span>⚠️ 入力内容に以下の確認・警告事項があります</span>
            <button type="button" onclick="dismissWarningBanner()" style="border:none; background:none; cursor:pointer; font-weight:bold; color:#be123c;">✕ 閉じる</button>
        </div>
        <ul id="inlineWarningList" class="inline-warning-list"></ul>
        <div class="inline-warning-footer">
            <span style="font-size:11px; color:#475569;">※このまま保存する場合は「強制保存」、修正を続ける場合は「入力を続ける」を選択してください。</span>
            <button type="button" class="btn btn-close" style="padding:4px 10px;" onclick="dismissWarningBanner()">✏️ 入力を続ける</button>
            <button type="button" class="btn btn-warning-submit" style="padding:4px 12px;" onclick="forceSubmitForm()">⚠️ この内容で強制保存する</button>
        </div>
    </div>

    <form method="POST" id="bulkForm">
        <table>
            <thead>
                <tr>
                    <th style="width: 145px;">時間枠</th>
                    <th style="width: 65px;">ID</th>
                    <th style="width: 160px;">患者名</th>
                    <th style="width: 95px;">種別</th>
                    <th style="width: 110px; background:#eff6ff;">🚪 部屋</th>
                    <th style="width: 155px; background:#f0fdf4;">💊 下剤</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($list)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">予約データがありません</td>
                </tr>
            <?php else: ?>
                <?php foreach ($list as $row): 
                    $sid = (int)$row['schedule_id']; 
                    $row_dept = (string)$row['department_code'];
                    $dept_char = ($row_dept === 'GASTRO') ? '胃' : '肛';
                    $dept_badge_cls = ($row_dept === 'GASTRO') ? 'GASTRO' : 'PROCT';

                    $age_int = null;
                    $age_cls = 'age-under';
                    if (!empty($row['birthday'])) {
                        $b_date_clean = substr(trim((string)$row['birthday']), 0, 10);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_date_clean)) {
                            $b_dt = new DateTime($b_date_clean);
                            $t_dt = new DateTime($date);
                            $age_int = $b_dt->diff($t_dt)->y;
                            if ($age_int >= 70) {
                                $age_cls = 'age-senior';
                            } else {
                                $age_cls = 'age-under';
                            }
                        }
                    }

                    $exam_name = trim((string)$row['exam_type']);
                    if ($exam_name === '') { $exam_name = '-'; }
                ?>
                    <tr class="reservation-row" 
                        data-sid="<?= $sid ?>" 
                        data-patient-name="<?= htmlspecialchars((string)$row['patient_name'], ENT_QUOTES, 'UTF-8') ?>"
                        data-age="<?= $age_int !== null ? $age_int : '' ?>"
                        data-exam-type="<?= htmlspecialchars($exam_name, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="items[<?= $sid ?>][department_code]" value="<?= htmlspecialchars($row_dept, ENT_QUOTES, 'UTF-8') ?>">
                        
                        <td>
                            <div style="display:flex; align-items:center; white-space:nowrap; overflow:hidden;">
                                <?php if ($dept === 'ALL'): ?>
                                    <span class="dept-icon-badge <?= $dept_badge_cls ?>"><?= $dept_char ?></span>
                                <?php endif; ?>
                                <strong title="<?= htmlspecialchars((string)$row['scheduled_time'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)$row['scheduled_time'], ENT_QUOTES, 'UTF-8') ?>
                                </strong>
                            </div>
                        </td>
                        <td><?= htmlspecialchars((string)$row['patient_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:2px;">
                                <strong><?= htmlspecialchars((string)$row['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($age_int !== null): ?>
                                    <span class="age-box <?= $age_cls ?>"><?= $age_int ?>歳</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <td>
                            <div class="exam-doc-cell">
                                <?= getDoctorBadgeHTML((string)$row['doctor_name']) ?>
                                <span class="exam-text"><?= htmlspecialchars($exam_name, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </td>

                        <td style="background:#eff6ff;">
                            <select name="items[<?= $sid ?>][room_number]" class="room-select">
                                <option value="">--未指定--</option>
                                <?php foreach ($rooms as $r): 
                                    $roomStyle = getRoomOptionStyle($r);
                                ?>
                                    <option value="<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>" 
                                            style="<?= $roomStyle ?>" 
                                            <?= $row['room_number'] === $r ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <td style="background:#f0fdf4;">
                            <select name="items[<?= $sid ?>][bowel_prep]" class="prep-select">
                                <option value="">-- 未指定 --</option>
                                <?php foreach ($preps as $p_val): 
                                    $sub_lbl = isset($prep_labels[$p_val]) ? " ({$prep_labels[$p_val]})" : "";
                                    $prepStyle = getPrepOptionStyle($p_val);
                                ?>
                                    <option value="<?= htmlspecialchars($p_val, ENT_QUOTES, 'UTF-8') ?>" 
                                            style="<?= $prepStyle ?>" 
                                            <?= $row['bowel_prep'] === $p_val ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p_val . $sub_lbl, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <div style="text-align: right;">
            <button type="button" class="btn btn-primary" onclick="handleSaveClick()">💾 まとめて保存する</button>
        </div>
    </form>
</div>

<script>
function changeDept(newDept) {
    const u = new URL(window.location.href);
    u.searchParams.set('dept', newDept);
    window.location.href = u.toString();
}

function autoAssignPreps() {
    const rows = document.querySelectorAll('.reservation-row');
    let updatedCount = 0;

    rows.forEach(row => {
        const prepSelect = row.querySelector('.prep-select');
        if (!prepSelect) return;

        // すでに下剤が設定されている場合はスキップ
        if (prepSelect.value !== '') return;

        // ★ 検査種別が下部内視鏡（TCF / ポリペク / W）の場合のみ設定する
        const examType = (row.getAttribute('data-exam-type') || '').trim().toUpperCase();
        const isLowerEndo = (examType === 'TCF' || examType === 'ポリペク' || examType === 'W' || examType.includes('TCF') || examType.includes('ポリペク') || examType === 'W');
        if (!isLowerEndo) return;

        const ageAttr = row.getAttribute('data-age');
        if (ageAttr === '' || isNaN(ageAttr)) return;

        const age = parseInt(ageAttr, 10);
        let targetPrep = (age >= 70) ? 'マグP' : 'モビ';

        for (let i = 0; i < prepSelect.options.length; i++) {
            if (prepSelect.options[i].value === targetPrep) {
                prepSelect.selectedIndex = i;
                updatedCount++;
                break;
            }
        }
    });

    if (updatedCount > 0) {
        alert(`${updatedCount} 件の下部内視鏡（TCF/ポリペク/W）の未設定行に下剤（70歳以上:マグP / 69歳以下:モビ）を反映しました。\n※画面上の反映のみです。内容を確認のうえ「まとめて保存する」を押してください。`);
    } else {
        alert('自動設定の対象となる下部内視鏡（TCF/ポリペク/W）の未設定行（年齢データあり）はありませんでした。');
    }
}

function handleSaveClick() {
    const rows = document.querySelectorAll('.reservation-row');
    const warnings = [];

    const roomMap = {};
    rows.forEach(row => {
        const roomSelect = row.querySelector('.room-select');
        const pName = row.getAttribute('data-patient-name') || '患者';
        if (roomSelect && roomSelect.value !== '') {
            const rm = roomSelect.value;
            if (!roomMap[rm]) roomMap[rm] = [];
            roomMap[rm].push(pName);
        }
    });

    for (const rm in roomMap) {
        if (roomMap[rm].length > 1) {
            warnings.push(`部屋「${rm}」が ${roomMap[rm].length}件 重複しています。（該当: ${roomMap[rm].join(', ')}）`);
        }
    }

    rows.forEach(row => {
        const ageAttr = row.getAttribute('data-age');
        if (ageAttr !== '' && !isNaN(ageAttr)) {
            const age = parseInt(ageAttr, 10);
            if (age >= 70) {
                const prepSelect = row.querySelector('.prep-select');
                const pName = row.getAttribute('data-patient-name') || '患者';
                const currentPrep = prepSelect ? prepSelect.value : '';

                if (currentPrep !== 'マグP') {
                    const prepDisp = currentPrep === '' ? '未指定' : currentPrep;
                    warnings.push(`70歳以上の患者「${pName}」(${age}歳) に マグP 以外（${prepDisp}）が設定されています。`);
                }
            }
        }
    });

    if (warnings.length > 0) {
        const banner = document.getElementById('inlineWarningBanner');
        const listEl = document.getElementById('inlineWarningList');
        listEl.innerHTML = '';
        warnings.forEach(w => {
            const li = document.createElement('li');
            li.textContent = w;
            listEl.appendChild(li);
        });
        banner.style.display = 'block';
        banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }

    document.getElementById('bulkForm').submit();
}

function dismissWarningBanner() {
    document.getElementById('inlineWarningBanner').style.display = 'none';
}

function forceSubmitForm() {
    document.getElementById('bulkForm').submit();
}
</script>
</body>
</html>