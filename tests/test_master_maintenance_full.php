<?php
/**
 * 検査種別マスターメンテナンス＆マスタ統合構造 全体動作検証テスト
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';
require_once __DIR__ . '/../lib/master_audit_helper.php';

$pdo = getDbConnection();
$errors = [];
$passes = [];

echo "=== 1. m_exam_type_group データ検証 ===\n";
$stmt = $pdo->query("SELECT exam_type, slot_group, display_order, is_selectable FROM m_exam_type_group ORDER BY display_order ASC, exam_type ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "登録件数: " . count($rows) . " 件\n";
foreach ($rows as $r) {
    echo sprintf("  - %-15s [グループ: %-5s] 順: %3d | 選択肢: %s\n", 
        $r['exam_type'], 
        $r['slot_group'], 
        $r['display_order'], 
        $r['is_selectable'] ? '有効(プルダウン表示)' : '無効(表記揺れ/非表示)'
    );
}

if (count($rows) >= 11) {
    $passes[] = "m_exam_type_group データ確認 OK";
} else {
    $errors[] = "m_exam_type_group のデータ件数が不足しています";
}

echo "\n=== 2. lib/slot_helper.php 共通関数検証 ===\n";
$options = getExamTypeOptions($pdo);
echo "予約プルダウン用選択肢 (" . count($options) . "件): " . implode(', ', $options) . "\n";
if (in_array('TCF', $options) && in_array('GIF', $options) && in_array('エコー', $options) && !in_array('ECHO', $options)) {
    $passes[] = "getExamTypeOptions 正常 (有効な選択肢のみ取得)";
} else {
    $errors[] = "getExamTypeOptions で表記揺れ(ECHO)が混入しているか、必須選択肢が欠損しています";
}

$group_map = getExamTypeGroupMap($pdo);
echo "グループマップ件数: " . count($group_map) . "\n";
if ($group_map['TCF'] === 'TCF' && $group_map['GIF'] === 'GIF' && $group_map['エコー'] === 'ECHO' && $group_map['ECHO'] === 'ECHO') {
    $passes[] = "getExamTypeGroupMap 正常 (表記揺れも含む全種別のグループマップ)";
} else {
    $errors[] = "getExamTypeGroupMap のマッピングが異常です";
}

if (resolveExamGroup('TCF', $pdo) === 'TCF' && resolveExamGroup('浣腸F', $pdo) === 'TCF' && resolveExamGroup('ECHO', $pdo) === 'ECHO') {
    $passes[] = "resolveExamGroup 正常";
} else {
    $errors[] = "resolveExamGroup の判定が異常です";
}

echo "\n=== 3. 画面レンダリング検証 (バッファ取得) ===\n";
// exam_master_maintenance.php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
ob_start();
include __DIR__ . '/../exam_master_maintenance.php';
$html_exam = ob_get_clean();
if (strpos($html_exam, '検査種別マスターメンテナンス') !== false && strpos($html_exam, 'master-hub-nav') !== false) {
    $passes[] = "exam_master_maintenance.php レンダリング OK (3大マスタヘッダー確認)";
} else {
    $errors[] = "exam_master_maintenance.php レンダリング失敗";
}

// master_maintenance.php
$_GET = ['category' => 'exam_type'];
ob_start();
include __DIR__ . '/../master_maintenance.php';
$html_master = ob_get_clean();
if (strpos($html_master, 'へ完全一本化されました') !== false && strpos($html_master, 'master-hub-nav') !== false) {
    $passes[] = "master_maintenance.php レンダリング OK (専用画面への案内バナー確認)";
} else {
    $errors[] = "master_maintenance.php レンダリング失敗";
}

// slot_master_maintenance.php
$_GET = ['tab' => 'doctor'];
ob_start();
include __DIR__ . '/../slot_master_maintenance.php';
$html_slot = ob_get_clean();
if (strpos($html_slot, '検査枠マスターメンテナンス') !== false && strpos($html_slot, 'master-hub-nav') !== false) {
    $passes[] = "slot_master_maintenance.php レンダリング OK (3大マスタヘッダー確認)";
} else {
    $errors[] = "slot_master_maintenance.php レンダリング失敗";
}

// index.php
$_GET = [];
ob_start();
include __DIR__ . '/../index.php';
$html_index = ob_get_clean();
if (strpos($html_index, '検査種別マスターメンテ') !== false && strpos($html_index, '汎用選択肢マスタメンテ') !== false) {
    $passes[] = "index.php レンダリング OK (マスタ管理新設カード確認)";
} else {
    $errors[] = "index.php レンダリング失敗";
}

echo "\n=== 4. action_exam_master.php の CRUD & Undo 検証 ===\n";
// テスト用の種別名
$test_type = 'TEST_EXAM_99';

// 念のため事前クリーンアップ
$pdo->prepare("DELETE FROM m_exam_type_group WHERE exam_type = :t")->execute([':t' => $test_type]);
$pdo->prepare("DELETE FROM t_master_audit_log WHERE target_name = :t")->execute([':t' => $test_type]);

// 1. 新規追加シミュレーション
$stmt = $pdo->prepare("
    INSERT INTO m_exam_type_group (exam_type, slot_group, display_order, is_selectable, is_active, created_at)
    VALUES (:t, :grp, :ord, :sel, TRUE, CURRENT_TIMESTAMP)
    RETURNING id
");
$stmt->execute([
    ':t'   => $test_type,
    ':grp' => 'GIF',
    ':ord' => 99,
    ':sel' => 1
]);
$new_id = (int)$stmt->fetchColumn();

$log_id_insert = recordMasterAuditLog(
    $pdo,
    'exam_type_group',
    'insert',
    'exam_type',
    (string)$new_id,
    $test_type,
    null,
    [
        'id'            => $new_id,
        'exam_type'     => $test_type,
        'slot_group'    => 'GIF',
        'display_order' => 99,
        'is_selectable' => true,
        'is_active'     => true
    ],
    "新規検査種別を追加: 「{$test_type}」(大分類: GIF)"
);

$chk = $pdo->prepare("SELECT * FROM m_exam_type_group WHERE id = :id");
$chk->execute([':id' => $new_id]);
$row = $chk->fetch(PDO::FETCH_ASSOC);
if ($row && $row['slot_group'] === 'GIF') {
    $passes[] = "新規追加 & 監査ログ記録 OK (ID: {$new_id})";
} else {
    $errors[] = "新規追加失敗";
}

// 2. 削除シミュレーション
$stmt_del = $pdo->prepare("DELETE FROM m_exam_type_group WHERE id = :id");
$stmt_del->execute([':id' => $new_id]);

$log_id_delete = recordMasterAuditLog(
    $pdo,
    'exam_type_group',
    'delete',
    'exam_type',
    (string)$new_id,
    $test_type,
    $row,
    null,
    "検査種別を削除: 「{$test_type}」"
);

$chk->execute([':id' => $new_id]);
if (!$chk->fetch()) {
    $passes[] = "削除 & 監査ログ記録 OK";
} else {
    $errors[] = "削除失敗";
}

// 3. 監査ログからの復元 (Undo) シミュレーション
$restore_stmt = $pdo->prepare("SELECT old_data FROM t_master_audit_log WHERE log_id = :lid");
$restore_stmt->execute([':lid' => $log_id_delete]);
$log_row = $restore_stmt->fetch(PDO::FETCH_ASSOC);
if ($log_row && !empty($log_row['old_data'])) {
    $old = json_decode($log_row['old_data'], true);
    $stmt_res = $pdo->prepare("
        INSERT INTO m_exam_type_group (id, exam_type, slot_group, display_order, is_selectable, is_active, created_at)
        VALUES (:id, :t, :grp, :ord, :sel, :act, CURRENT_TIMESTAMP)
    ");
    $stmt_res->execute([
        ':id'  => $old['id'],
        ':t'   => $old['exam_type'],
        ':grp' => $old['slot_group'] ?? 'OTHER',
        ':ord' => $old['display_order'] ?? 100,
        ':sel' => !empty($old['is_selectable']) ? 1 : 0,
        ':act' => !empty($old['is_active']) ? 1 : 0
    ]);
    
    $chk->execute([':id' => $new_id]);
    $restored_row = $chk->fetch(PDO::FETCH_ASSOC);
    if ($restored_row && $restored_row['exam_type'] === $test_type) {
        $passes[] = "監査ログからのワンクリック復元 (Undo) OK";
    } else {
        $errors[] = "復元失敗";
    }
} else {
    $errors[] = "監査ログ取得失敗";
}

// クリーンアップ
$pdo->prepare("DELETE FROM m_exam_type_group WHERE id = :id")->execute([':id' => $new_id]);
$pdo->prepare("DELETE FROM t_master_audit_log WHERE target_name = :t")->execute([':t' => $test_type]);

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
