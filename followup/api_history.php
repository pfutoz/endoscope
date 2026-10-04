<?php
/**
 * ファイル名: api_history.php
 * 設置パス: /endoscope/followup/api_history.php
 * 生成日時: 2026-08-17 20:00:00 JST
 * 
 * 患者F/U履歴取得API（患者ID指定）
 * 
 * 機能:
 *   - 指定された患者IDの全F/U履歴を取得
 *   - メイン情報＋F/U項目をJOINして返却
 *   - 登録日の降順でソート
 *   - JSON形式で返却
 * 
 * パラメータ:
 *   - patient_id: 患者ID（必須）
 *   - limit: 取得件数（任意、デフォルト50）
 * 
 * 戻り値:
 *   - success: boolean
 *   - history: 履歴配列
 *   - message: エラーメッセージ（失敗時）
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成
 *   - 2026-08-17: ステータス情報も返却
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'DB接続失敗']);
    exit;
}

$patient_id = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

if (empty($patient_id)) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'patient_id パラメータが不足しています']);
    exit;
}

try {
    $sql = "
        SELECT 
            f.followup_id,
            f.patient_id,
            f.patient_name,
            f.exam_types_display,
            f.next_visit_date,
            f.next_visit_display,
            f.visit_purpose,
            f.visit_method,
            f.created_at as registered_date,
            fi.item_id,
            fi.exam_type,
            fi.fu_interval,
            fi.fu_reason,
            fi.status,
            fi.completed_date,
            fi.created_at as item_created_at
        FROM t_followup f
        LEFT JOIN t_followup_items fi ON f.followup_id = fi.followup_id
        WHERE f.patient_id = :patient_id
        ORDER BY fi.created_at DESC, f.created_at DESC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_STR);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    
    // ステータスラベルマップ
    $status_labels = [
        'planned' => '予定',
        'completed' => '完了',
        'cancelled' => '中止',
        'postponed' => '延期'
    ];
    
    // 整形
    $history = [];
    foreach ($rows as $row) {
        $history[] = [
            'followup_id' => $row['followup_id'],
            'patient_id' => $row['patient_id'],
            'patient_name' => $row['patient_name'],
            'exam_types_display' => $row['exam_types_display'],
            'next_visit_date' => $row['next_visit_date'],
            'next_visit_display' => $row['next_visit_display'],
            'registered_date' => date('Y-m-d', strtotime($row['registered_date'] ?? 'now')),
            'item_id' => $row['item_id'],
            'exam_type' => $row['exam_type'],
            'fu_interval' => $row['fu_interval'],
            'fu_reason' => $row['fu_reason'],
            'status' => $row['status'],
            'status_label' => $status_labels[$row['status'] ?? 'planned'] ?? $row['status'],
            'completed_date' => $row['completed_date'] ? date('Y-m-d', strtotime($row['completed_date'])) : null
        ];
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'patient_id' => $patient_id,
        'count' => count($history),
        'history' => $history
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'DBエラー: ' . $e->getMessage()]);
    exit;
}