<?php
/**
 * action_audit_log.php
 * 予約操作履歴（Audit Log）取得 & ワンクリック復元（Undo）専用API
 */
ini_set('display_errors', "0");
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/audit_helper.php';
require_once __DIR__ . '/logger.php';

$pdo = getDbConnection();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'データベース接続失敗']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';

if ($action === 'list') {
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 40;
    $dept  = !empty($_GET['dept']) ? trim($_GET['dept']) : null;
    $sid   = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : null;

    $logs = getRecentAuditLogs($pdo, $limit, $dept, $sid);
    echo json_encode([
        'success' => true,
        'count'   => count($logs),
        'logs'    => $logs
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'undo') {
    $log_id = isset($_POST['log_id']) ? (int)$_POST['log_id'] : 0;
    $operator = $_POST['operator_name'] ?? null;

    if ($log_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無効なログIDです']);
        exit;
    }

    writeLog('AUDIT', "ワンクリック復元実行リクエスト: log_id = {$log_id}");
    $res = undoScheduleAuditLog($pdo, $log_id, $operator);
    writeLog('AUDIT', "ワンクリック復元実行結果: " . json_encode($res, JSON_UNESCAPED_UNICODE));

    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => '無効なアクションです: ' . $action]);
exit;
