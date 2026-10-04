<?php
/**
 * get_present_doctors.php - 当日の出勤医師リストAPI
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

// 当該科の全医師から当日出勤（枠あり）医師を抽出
$stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order");
$stmt->execute([':dept' => $dept]);
$dept_doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);

$present_doctors = [];
foreach ($dept_doctors as $doctor_name) {
    $status = getDoctorStatus($pdo, $doctor_name, $dept, $date);
    if (!empty($status['present'])) {
        $present_doctors[] = $doctor_name;
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
    'all_doctors'     => $all_registered_doctors
]);
?>