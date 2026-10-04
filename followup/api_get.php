<?php
/**
 * ファイル名: api_get.php
 * 設置パス: /endoscope/followup/api_get.php
 * 生成日時: 2026-08-17 20:00:00 JST
 * 
 * フォローアップ個別取得API（電話対応クイック検索用）
 * 
 * 機能:
 *   - ID指定でフォローアップデータを取得
 *   - 患者IDの下5桁（フォローアップ番号）で検索
 *   - F/U項目も一緒に返却
 *   - JSON形式で返却
 * 
 * パラメータ:
 *   - id: followup_id（任意）
 *   - no: 患者IDの下5桁（任意、idより優先度低）
 * 
 * 戻り値:
 *   - success: boolean
 *   - data: フォローアップデータ
 *   - items: F/U項目配列
 *   - message: エラーメッセージ（失敗時）
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成
 *   - 2026-08-17: F/U項目もJOINで取得
 *   - 2026-08-17: 日付表示ロジックを修正
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$no = isset($_GET['no']) ? trim($_GET['no']) : '';

$purpose_labels = [
    'followup' => '経過観察',
    'tissue_result' => '組織結果説明',
    'next_exam' => '次回検査',
    'medication' => '薬の調整',
    'other' => 'その他'
];

$method_labels = [
    'reserved' => '予約済み',
    'phone_required' => '事前電話要',
    'contact_when_ready' => '都合連絡',
    'walk_in' => '直接来院',
    'adjustment_needed' => '要調整'
];

$status_labels = [
    'planned' => '予定',
    'completed' => '完了',
    'cancelled' => '中止',
    'postponed' => '延期'
];

try {
    // ---- メインデータ取得 ----
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM t_followup WHERE followup_id = :id");
        $stmt->execute([':id' => $id]);
    } elseif ($no !== '') {
        // 患者IDの下5桁で検索
        $stmt = $pdo->prepare("SELECT * FROM t_followup WHERE RIGHT(patient_id, 5) = :no ORDER BY followup_id DESC LIMIT 1");
        $stmt->execute([':no' => $no]);
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'パラメータが不足しています（id または no が必要です）']);
        exit;
    }
    
    $row = $stmt->fetch();
    
    if (!$row) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'データが見つかりません']);
        exit;
    }
    
    // ---- F/U項目取得 ----
    $stmt = $pdo->prepare("
        SELECT * FROM t_followup_items 
        WHERE followup_id = :id 
        ORDER BY display_order, created_at
    ");
    $stmt->execute([':id' => $row['followup_id']]);
    $items = $stmt->fetchAll();
    
    // ---- 日付表示 ----
    $date_display = '';
    if (!empty($row['next_visit_display'])) {
        $date_display = $row['next_visit_display'];
    } elseif (!empty($row['next_visit_date']) && $row['next_visit_date'] !== '0000-00-00') {
        $date_display = date('Y年n月j日（D）', strtotime($row['next_visit_date']));
    } else {
        $date_display = '（未設定）';
    }
    
    // ---- 返却データ整形 ----
    $response = [
        'success' => true,
        'data' => [
            'followup_id' => $row['followup_id'],
            'patient_id' => $row['patient_id'],
            'patient_name' => $row['patient_name'],
            'patient_kana' => $row['patient_kana'],
            'birthday' => $row['birthday'],
            'department_code' => $row['department_code'],
            'doctor_name' => $row['doctor_name'],
            'exam_types' => json_decode($row['exam_types'] ?? '[]', true),
            'exam_types_display' => $row['exam_types_display'],
            'next_visit_date' => $row['next_visit_date'],
            'next_visit_display' => $date_display,
            'next_visit_date_type' => $row['next_visit_date_type'],
            'visit_purpose' => $row['visit_purpose'],
            'visit_purpose_label' => $purpose_labels[$row['visit_purpose']] ?? $row['visit_purpose'],
            'visit_method' => $row['visit_method'],
            'visit_method_label' => $method_labels[$row['visit_method']] ?? $row['visit_method'],
            'visit_time' => $row['visit_time'],
            'notes' => $row['notes'],
            'issued_date' => $row['issued_date'],
            'is_printed' => $row['is_printed'] === 't' || $row['is_printed'] === true || $row['is_printed'] === 1
        ],
        'items' => []
    ];
    
    // ---- F/U項目整形 ----
    foreach ($items as $item) {
        $response['items'][] = [
            'item_id' => $item['item_id'],
            'exam_type' => $item['exam_type'],
            'fu_interval' => $item['fu_interval'],
            'fu_reason' => $item['fu_reason'],
            'fu_date' => $item['fu_date'],
            'status' => $item['status'],
            'status_label' => $status_labels[$item['status'] ?? 'planned'] ?? $item['status'],
            'completed_date' => $item['completed_date']
        ];
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'DBエラー: ' . $e->getMessage()]);
    exit;
}