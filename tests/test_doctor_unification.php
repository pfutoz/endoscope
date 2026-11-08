<?php
/**
 * 医師マスタ一本化検証テスト
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();
$passes = [];
$errors = [];

echo "=== 1. m_input_master の doctor 削除確認 ===\n";
$cnt = $pdo->query("SELECT count(*) FROM m_input_master WHERE category = 'doctor'")->fetchColumn();
if ((int)$cnt === 0) {
    $passes[] = "m_input_master からの doctor 削除確認 (残存0件)";
} else {
    $errors[] = "m_input_master に doctor が残存しています ({$cnt}件)";
}

$bak_cnt = $pdo->query("SELECT count(*) FROM bak_m_input_master_doctor")->fetchColumn();
if ((int)$bak_cnt === 5) {
    $passes[] = "bak_m_input_master_doctor バックアップ退避確認 (5件)";
} else {
    $errors[] = "bak_m_input_master_doctor の退避件数が不正です ({$bak_cnt}件)";
}

echo "\n=== 2. m2_doctor および lib/slot_helper.php の医師一覧取得 ===\n";
$proct_docs = getDoctorsForDept($pdo, 'PROCT');
$gastro_docs = getDoctorsForDept($pdo, 'GASTRO');
echo "肛門科医師: " . implode(', ', $proct_docs) . "\n";
echo "胃腸科医師: " . implode(', ', $gastro_docs) . "\n";

if ($proct_docs === ['誠吾'] && in_array('哲也', $gastro_docs) && in_array('義智', $gastro_docs) && in_array('慶太郎', $gastro_docs)) {
    $passes[] = "科別医師リスト (getDoctorsForDept) 正常 (計4名)";
} else {
    $errors[] = "科別医師リストが不正です";
}

echo "\n=== 3. action_doctor_notification.php のロジック確認 ===\n";
$stmtDocs = $pdo->query("SELECT doctor_name FROM m2_doctor WHERE is_active = TRUE ORDER BY display_order");
$docList = $stmtDocs->fetchAll(PDO::FETCH_COLUMN);
$doctorsToClear = array_merge(['ALL'], $docList);
$doctorsToClear = array_unique(array_filter($doctorsToClear));
echo "消込対象医師一覧: " . implode(', ', $doctorsToClear) . "\n";
if (in_array('ALL', $doctorsToClear) && in_array('誠吾', $doctorsToClear) && in_array('哲也', $doctorsToClear) && in_array('義智', $doctorsToClear) && in_array('慶太郎', $doctorsToClear)) {
    $passes[] = "action_doctor_notification 用消込医師リスト正常";
} else {
    $errors[] = "消込医師リストが不正です";
}

echo "\n=== 4. master_maintenance.php レンダリング確認 ===\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['category' => 'doctor'];
ob_start();
include __DIR__ . '/../master_maintenance.php';
$html = ob_get_clean();
if (strpos($html, '医師マスタは検査枠マスタ（m2_doctor）へ完全一本化されました') !== false && strpos($html, 'slot_master_maintenance.php?tab=doctor') !== false) {
    $passes[] = "master_maintenance.php の doctor 案内バナー正常表示";
} else {
    $errors[] = "master_maintenance.php の案内バナー表示失敗";
}

echo "\n=========================================\n";
echo "【検証結果サマリ】\n";
echo "成功: " . count($passes) . " 件\n";
foreach ($passes as $p) {
    echo "  ✅ " . $p . "\n";
}
if (!empty($errors)) {
    echo "失敗: " . count($errors) . " 件\n";
    foreach ($errors as $e) {
        echo "  ❌ " . $e . "\n";
    }
} else {
    echo "\n🎉 全ての検証項目が 100% 成功しました！\n";
}
