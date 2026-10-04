<?php
/**
 * modal_functions.php - モーダル関連関数
 */

function getDoctorsForDept($pdo, $dept) {
    $stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor WHERE department_code = :dept AND is_active = TRUE ORDER BY display_order");
    $stmt->execute([':dept' => $dept]);
    $doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $doctors[] = '◆未定◆';
    return $doctors;
}

function getDefaultDoctorForSlot($pdo, $dept, $group_code) {
    $stmt = $pdo->prepare("SELECT doctor_name FROM m2_doctor_slot_pattern WHERE department_code = :dept AND group_code = :group AND max_slots > 0 ORDER BY max_slots DESC LIMIT 1");
    $stmt->execute([':dept' => $dept, ':group' => $group_code]);
    $result = $stmt->fetch();
    return $result ? $result['doctor_name'] : '◆未定◆';
}

function getDefaultExamType($group_code) {
    $map = ['GIF' => 'GIF', 'ECHO' => 'エコー', 'TCF' => 'TCF'];
    return $map[$group_code] ?? 'TCF';
}
?>