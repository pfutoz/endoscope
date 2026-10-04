<?php
/**
 * ファイル名: api_get_followup.php
 * 設置パス: /endoscope/api_get_followup.php
 * 生成日時: 2026-08-17 17:00:00 JST
 * 
 * フォローアップ個別取得API（電話対応クイック検索用）
 * 
 * 機能:
 *   - ID指定でフォローアップデータを取得
 *   - 患者IDの下5桁（フォローアップ番号）で検索
 *   - JSON形式でデータを返却
 *   - 来院目的・来院方法のラベルも返却
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成（たたき台）
 *   - 2026-08-17: 患者ID下5桁での検索に対応
 *   - 2026-08-17: ラベル情報も返却するよう拡張
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';

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

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM t_followup WHERE followup_id = :id");
        $stmt->execute([':id' => $id]);
    } elseif ($no !== '') {
        // フォローアップ番号（患者IDの下5桁）で検索
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
    
    // ★★★ 次回来院日の表示（next_visit_display優先、なければnext_visit_dateから生成） ★★★
    $date_display = '';
    if (!empty($row['next_visit_display'])) {
        $date_display = $row['next_visit_display'];
    } elseif (!empty($row['next_visit_date']) && $row['next_visit_date'] !== '0000-00-00') {
        $date_display = date('Y年n月j日（D）', strtotime($row['next_visit_date']));
    } else {
        $date_display = '（未設定）';
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'followup_id' => $row['followup_id'],
        'patient_id' => $row['patient_id'],
        'patient_name' => $row['patient_name'],
        'patient_kana' => $row['patient_kana'],
        'doctor_name' => $row['doctor_name'],
        'next_visit_date' => $date_display,
        'visit_purpose' => $purpose_labels[$row['visit_purpose']] ?? $row['visit_purpose'],
        'visit_method' => $method_labels[$row['visit_method']] ?? $row['visit_method'],
        'visit_time' => $row['visit_time'],
        'notes' => $row['notes'],
        'exam_types' => json_decode($row['exam_types'] ?? '[]', true),
        'exam_types_display' => $row['exam_types_display'],
        'followup_items' => json_decode($row['followup_items'] ?? '[]', true),
        'issued_date' => $row['issued_date'],
        'is_printed' => $row['is_printed'] === 't' || $row['is_printed'] === true || $row['is_printed'] === 1
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'DBエラー: ' . $e->getMessage()]);
    exit;
}