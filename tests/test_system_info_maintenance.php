<?php
/**
 * tests/test_system_info_maintenance.php
 * 修正インフォメーションマスターメンテ機能および非干渉配置の総合テスト
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

echo "=======================================================\n";
echo " 📢 修正インフォメーションマスターメンテ 総合テスト\n";
echo "=======================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($name, $cond, $extra = '') {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] {$name}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$name} {$extra}\n";
        $failCount++;
    }
}

require_once __DIR__ . '/../inc_system_info.php';
$pdo = getSystemInfoPdo();

// 1. DB取得テスト
assertCondition("DB接続とPDO取得", $pdo instanceof PDO);
$infos = getSystemInformations($pdo, true);
assertCondition("インフォメーション取得件数（3件以上）", count($infos) >= 3, "count: " . count($infos));

// 2. system_info_maintenance.php 描画テスト
ob_start();
include __DIR__ . '/../system_info_maintenance.php';
$html_maint = ob_get_clean();
assertCondition("system_info_maintenance.php 描画成功", !empty($html_maint));
assertCondition("system_info_maintenance.php にナビゲーションが存在", strpos($html_maint, 'master-hub-nav') !== false);
assertCondition("system_info_maintenance.php に登録フォームが存在", strpos($html_maint, 'input-form') !== false);
assertCondition("system_info_maintenance.php に一覧テーブルが存在", strpos($html_maint, '登録済みインフォメーション一覧') !== false);

// 3. index.php の非干渉最下部配置テスト
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
include __DIR__ . '/../index.php';
$html_index = ob_get_clean();

assertCondition("index.php 描画成功", !empty($html_index));
$posMenuMaster = strpos($html_index, 'section-master');
$posSysInfo = strpos($html_index, 'system-info-section');
$posMainClose = strpos($html_index, '</main>');

assertCondition("マスタ管理セクションが存在", $posMenuMaster !== false);
assertCondition("修正インフォメーションバナーが存在", $posSysInfo !== false);
assertCondition("インフォメーションがマスタ管理セクション（ボタン群）より下に配置されている", $posSysInfo > $posMenuMaster, "posSysInfo: {$posSysInfo}, posMenuMaster: {$posMenuMaster}");
assertCondition("インフォメーションが</main>の手前に配置されている", $posSysInfo < $posMainClose, "posSysInfo: {$posSysInfo}, posMainClose: {$posMainClose}");
assertCondition("マスタ管理セクションに修正インフォメンテカードが存在", strpos($html_index, '修正インフォメーションメンテ') !== false);

// 4. index.html のボタン最上部・インフォ最下部テスト
$html_redirect = file_get_contents(__DIR__ . '/../index.html');
$posBtn = strpos($html_redirect, '<a href="index.php" class="btn-enter"');
$posInfoBox = strpos($html_redirect, '<div class="info-box">');
assertCondition("index.html にボタンが存在", $posBtn !== false);
assertCondition("index.html にインフォボックスが存在", $posInfoBox !== false);
assertCondition("index.html でボタンがインフォボックスより上に配置されている（邪魔にならない）", $posBtn < $posInfoBox, "posBtn: {$posBtn}, posInfoBox: {$posInfoBox}");

// 5. 各マスタ画面の統合ナビゲーションに修正インフォメーションが存在することを確認
$html_slot = file_get_contents(__DIR__ . '/../slot_master_maintenance.php');
assertCondition("slot_master_maintenance.php に修正インフォタブ存在", strpos($html_slot, 'system_info_maintenance.php') !== false);

$html_exam = file_get_contents(__DIR__ . '/../exam_master_maintenance.php');
assertCondition("exam_master_maintenance.php に修正インフォタブ存在", strpos($html_exam, 'system_info_maintenance.php') !== false);

$html_master = file_get_contents(__DIR__ . '/../master_maintenance.php');
assertCondition("master_maintenance.php に修正インフォタブ存在", strpos($html_master, 'system_info_maintenance.php') !== false);
assertCondition("master_maintenance.php のカテゴリにsystem_infoが存在", strpos($html_master, "'system_info'") !== false);

$html_printer = file_get_contents(__DIR__ . '/../printer_master_maintenance.php');
assertCondition("printer_master_maintenance.php に修正インフォタブ存在", strpos($html_printer, 'system_info_maintenance.php') !== false);

// 6. 登録・トグル・削除のサイクルテスト
$testTitle = '【自動テスト用】一時的インフォメーション_' . time();
$newId = saveSystemInfoRecord($pdo, [
    'info_date'    => '2026-11-01',
    'version_tag'  => 'vTEST',
    'badge_label'  => 'テスト',
    'badge_cls'    => 'badge-primary',
    'title'        => $testTitle,
    'summary'      => 'テスト概要',
    'detail_items' => "テスト項目1\nテスト項目2",
    'display_order'=> 999,
    'is_active'    => 1
]);
assertCondition("新規登録実行 (ID: {$newId})", $newId > 0);

// トグル非公開
toggleSystemInfoActive($pdo, $newId);
$row = $pdo->query("SELECT is_active FROM m_system_info WHERE id = {$newId}")->fetch();
assertCondition("公開/非公開トグル成功（非アクティブ化）", $row && !$row['is_active']);

// 削除
deleteSystemInfoRecord($pdo, $newId);
$rowAfter = $pdo->query("SELECT id FROM m_system_info WHERE id = {$newId}")->fetch();
assertCondition("テストデータ安全削除成功", empty($rowAfter));

echo "\n-------------------------------------------------------\n";
echo " テスト結果: PASS = {$passCount} / FAIL = {$failCount}\n";
echo "=======================================================\n";

if ($failCount > 0) {
    exit(1);
}
