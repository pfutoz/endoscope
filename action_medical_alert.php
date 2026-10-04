<?php
/**
 * action_medical_alert.php
 * 医療安全アラート（抗血栓薬・アレルギー・感染症・風邪症状）専用API
 */
ini_set('display_errors', "0");
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';
require_once __DIR__ . '/logger.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'データベース接続失敗']);
    exit;
}

$action = $_REQUEST['action'] ?? 'scan';

// 1. スキャンプレビュー取得（ユーザー判断材料の抽出）
if ($action === 'scan') {
    $options = [];

    // 対象月数（デフォルト: 6ヶ月 / 0またはallで全期間）
    if (isset($_REQUEST['months'])) {
        $mVal = trim((string)$_REQUEST['months']);
        $options['months'] = ($mVal === 'all' || $mVal === '0') ? null : (int)$mVal;
    } else {
        $options['months'] = 6;
    }

    // オンライン資格確認API照会の有効/無効
    if (isset($_REQUEST['include_onshi'])) {
        $options['include_onshi'] = !empty($_REQUEST['include_onshi']);
    } else {
        $options['include_onshi'] = true;
    }

    if (!empty($_REQUEST['patient_id'])) {
        $pid = trim((string)$_REQUEST['patient_id']);
        $options['patient_id'] = $pid;
        $patientScanOptions = [
            'include_onshi' => !empty($_REQUEST['include_onshi']),
            'months'        => $options['months'],
            'source'        => $_REQUEST['source'] ?? 'new'
        ];
        $directAlert = scanMedicalAlertsForPatientId($pdo, $pid, $patientScanOptions);
    }
    if (!empty($_REQUEST['schedule_ids'])) {
        $ids = is_array($_REQUEST['schedule_ids']) ? $_REQUEST['schedule_ids'] : explode(',', (string)$_REQUEST['schedule_ids']);
        $options['schedule_ids'] = array_filter(array_map('intval', $ids));
    }
    if (!empty($_REQUEST['date'])) {
        $options['date'] = trim((string)$_REQUEST['date']);
    }
    if (!empty($_REQUEST['dept'])) {
        $options['dept'] = trim((string)$_REQUEST['dept']);
    }
    if (!empty($_REQUEST['year']) && !empty($_REQUEST['month'])) {
        $options['year'] = (int)$_REQUEST['year'];
        $options['month'] = (int)$_REQUEST['month'];
    }

    $candidates = scanMedicalAlertsForSchedules($pdo, $options);

    // directAlertがある場合、候補に入っていなければ合成
    if (!empty($directAlert) && !empty($directAlert['reasons']) && empty($candidates)) {
        $candidates[] = [
            'schedule_id'             => 0,
            'exam_date'               => date('Y-m-d'),
            'scheduled_time'          => '',
            'department_code'         => $_REQUEST['dept'] ?? 'PROCT',
            'patient_id'              => $directAlert['patient_id'],
            'patient_name'            => '',
            'has_antithrombotic'      => $directAlert['has_antithrombotic'],
            'has_diabetes'            => $directAlert['has_diabetes'],
            'has_psychotropic'        => $directAlert['has_psychotropic'],
            'has_nsaids'              => $directAlert['has_nsaids'],
            'has_allergy'             => $directAlert['has_allergy'],
            'has_infection'           => $directAlert['has_infection'],
            'has_cold_fever'          => $directAlert['has_cold_fever'],
            'antithrombotic_verified' => false,
            'reasons'                 => $directAlert['reasons'],
            'onshi_info'              => $directAlert['onshi_info'] ?? null,
            'has_onshi_detection'     => !empty($directAlert['onshi_info']['has_risk_drugs'])
        ];
    }

    echo json_encode([
        'success'       => true,
        'count'         => count($candidates),
        'filter_months' => $options['months'],
        'candidates'    => $candidates,
        'direct_alert'  => $directAlert ?? null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. ユーザー確認後のフラグ一括保存
if ($action === 'save') {
    $raw_alerts = $_POST['alerts'] ?? '[]';
    $alerts = is_array($raw_alerts) ? $raw_alerts : json_decode($raw_alerts, true);
    $operator = $_POST['operator_name'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'staff');

    if (!is_array($alerts)) {
        echo json_encode(['success' => false, 'message' => '無効なデータ形式です']);
        exit;
    }

    $ok = saveMedicalAlertDecisions($pdo, $alerts, $operator);
    echo json_encode([
        'success' => $ok,
        'message' => count($alerts) . '件のアラート設定を反映・保存しました'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. 当日ホワイトボード用: 休薬確認済トグル
if ($action === 'toggle_verified') {
    $sid = (int)($_POST['schedule_id'] ?? 0);
    $verified = !empty($_POST['verified']);
    if ($sid <= 0) {
        echo json_encode(['success' => false, 'message' => '無効な予約ID']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO t_patient_medical_alert (schedule_id, exam_date, antithrombotic_verified, updated_at)
        VALUES (:id, CURRENT_DATE, :v, CURRENT_TIMESTAMP)
        ON CONFLICT (schedule_id) DO UPDATE SET antithrombotic_verified = :v2, updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':id' => $sid, ':v' => $verified ? 'TRUE' : 'FALSE', ':v2' => $verified ? 'TRUE' : 'FALSE']);

    echo json_encode([
        'success'  => true,
        'verified' => $verified,
        'message'  => $verified ? '休薬確認済に更新しました' : '休薬確認要に戻しました'
    ]);
    exit;
}

// 4. 当日ホワイトボード用: 風邪・発熱トグル
if ($action === 'toggle_cold') {
    $sid = (int)($_POST['schedule_id'] ?? 0);
    $has_cold = !empty($_POST['has_cold']);
    if ($sid <= 0) {
        echo json_encode(['success' => false, 'message' => '無効な予約ID']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO t_patient_medical_alert (schedule_id, exam_date, has_cold_fever, updated_at)
        VALUES (:id, CURRENT_DATE, :c, CURRENT_TIMESTAMP)
        ON CONFLICT (schedule_id) DO UPDATE SET has_cold_fever = :c2, updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':id' => $sid, ':c' => $has_cold ? 'TRUE' : 'FALSE', ':c2' => $has_cold ? 'TRUE' : 'FALSE']);

    echo json_encode([
        'success'  => true,
        'has_cold' => $has_cold,
        'message'  => $has_cold ? '風邪・発熱ありに設定しました' : '風邪・発熱なしに戻しました'
    ]);
    exit;
}

// 5. アラートマップ取得
if ($action === 'get_map') {
    $ids = !empty($_REQUEST['schedule_ids']) ? (is_array($_REQUEST['schedule_ids']) ? $_REQUEST['schedule_ids'] : explode(',', $_REQUEST['schedule_ids'])) : [];
    $map = getMedicalAlertsMap($pdo, array_filter(array_map('intval', $ids)));
    echo json_encode(['success' => true, 'map' => $map], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => '不明なアクション: ' . $action]);
exit;
