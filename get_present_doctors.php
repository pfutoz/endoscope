<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\get_present_doctors.php
 * ファイル名: get_present_doctors.php
 * 概要: 当日の出勤医師リストおよび医師別枠上限・予約数取得API
 * 更新日時: 2026-11-07
 * 修正履歴:
 *   - 2026-11-07: modal_edit.php での医師別残枠・上限リアルタイム表示のため doctor_capacity / slot_summary 情報をレスポンスに追加（従来キー doctors, present_doctors, all_doctors は完全互換維持）。
 */
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['doctors' => ['◆未定◆']]);
    exit;
}

$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'GASTRO';

// 当該科の全医師から当日出勤（枠あり）医師および上限枠数を抽出
$stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order");
$stmt->execute([':dept' => $dept]);
$dept_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);

$present_doctors = [];
$doctor_capacity = [];
$total_max = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
$total_used = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];

// 当日の確定予約（枠なしダミー除外）から医師別・グループ別の使用数を集計
$used_by_doc = [];
try {
    $stmt_res = $pdo->prepare("
        SELECT doctor_name, exam_type, patient_name, remarks
        FROM t_endoscopy_schedule
        WHERE department_code = :dept
          AND exam_date = :date
          AND is_canceled = FALSE
    ");
    $stmt_res->execute([':dept' => $dept, ':date' => $date]);
    $day_res_rows = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

    foreach ($day_res_rows as $r) {
        $pn = trim((string)($r['patient_name'] ?? ''));
        $rm = trim((string)($r['remarks'] ?? ''));
        if ($pn === '※枠なし' || strpos($rm, '＊＊＊🚫枠なし') !== false) {
            continue;
        }
        $d_name = trim((string)($r['doctor_name'] ?? ''));
        if ($d_name === '') $d_name = '◆未定◆';
        $e_type = trim((string)($r['exam_type'] ?? ''));
        $grp = function_exists('resolveExamGroup') ? resolveExamGroup($e_type, $pdo) : (in_array($e_type, ['TCF', 'W', 'ポリペク', '浣腸CF'], true) ? 'TCF' : ($e_type === 'エコー' || $e_type === 'ECHO' ? 'ECHO' : 'GIF'));
        if (!isset($used_by_doc[$d_name])) {
            $used_by_doc[$d_name] = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
        }
        if (isset($used_by_doc[$d_name][$grp])) {
            $used_by_doc[$d_name][$grp]++;
        }
        if (isset($total_used[$grp])) {
            $total_used[$grp]++;
        }
    }
} catch (Throwable $e) {
    // 集計エラー時も医師リスト返却は継続
}

foreach ($dept_doctors as $doctor_name) {
    $status = getDoctorStatus($pdo, $doctor_name, $dept, $date);
    $doc_used = $used_by_doc[$doctor_name] ?? ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    $max_slots = $status['slots'] ?? ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0];
    if (!empty($status['present']) || array_sum($doc_used) > 0) {
        if (!empty($status['present'])) {
            $present_doctors[] = $doctor_name;
            foreach (['GIF', 'ECHO', 'TCF'] as $g) {
                $total_max[$g] += (int)($max_slots[$g] ?? 0);
            }
        }
        $doctor_capacity[$doctor_name] = [
            'present' => !empty($status['present']),
            'max'     => $max_slots,
            'used'    => $doc_used,
            'remain'  => [
                'GIF'  => (int)($max_slots['GIF'] ?? 0) - (int)$doc_used['GIF'],
                'ECHO' => (int)($max_slots['ECHO'] ?? 0) - (int)$doc_used['ECHO'],
                'TCF'  => (int)($max_slots['TCF'] ?? 0) - (int)$doc_used['TCF'],
            ],
            'reason'  => $status['reason'] ?? null,
        ];
    }
}

// 科横断の全アクティブ医師リスト（誠吾・哲也・義智・慶太郎等）
$all_stmt = $pdo->query("SELECT DISTINCT doctor_name, department_code, display_order FROM m2_doctor WHERE is_active = TRUE ORDER BY department_code DESC, display_order ASC");
$all_doctors_rows = $all_stmt->fetchAll(PDO::FETCH_ASSOC);
$all_registered_doctors = [];
foreach ($all_doctors_rows as $row) {
    if (!in_array($row['doctor_name'], $all_registered_doctors, true)) {
        $all_registered_doctors[] = $row['doctor_name'];
    }
}

if (empty($present_doctors)) {
    $present_doctors = ['◆未定◆'];
} else {
    $present_doctors[] = '◆未定◆';
}

echo json_encode([
    'doctors'         => $present_doctors,
    'present_doctors' => $present_doctors,
    'all_doctors'     => $all_registered_doctors,
    'doctor_capacity' => $doctor_capacity,
    'slot_summary'    => [
        'max'    => $total_max,
        'used'   => $total_used,
        'remain' => [
            'GIF'  => $total_max['GIF'] - $total_used['GIF'],
            'ECHO' => $total_max['ECHO'] - $total_used['ECHO'],
            'TCF'  => $total_max['TCF'] - $total_used['TCF'],
        ]
    ]
], JSON_UNESCAPED_UNICODE);
?>