<?php
/**
 * tools/deploy_to_production.php
 * 本番環境(T:\endoscope / 192.168.1.16)への安全デプロイスクリプト
 * 
 * 1. 本番既存ファイルのバックアップ作成 (.bak_YYYYMMDD_HHMMSS)
 * 2. 最新ファイルのコピー
 * 3. MD5ハッシュによる整合性チェック
 * 
 * 修正履歴:
 *   - 2026-10-27: 部屋重複リアルタイム検知、A4印刷DX（キーボード左右移動・年号省略・ページ構成復元）、2026-10-27works.html をデプロイ対象に追加
 *   - 2026-10-26: デイリーノート縦圧縮・科切替・印刷DX・検査マスタ一本化
 *   - 2026-10-20: 印刷DX、仕様書、および特大Flatpickr日付コントロールライブラリ周りの全ファイルをデプロイ対象に追加
 */

$localBase = 'C:/Apache24/htdocs/endoscope';
$prodBase  = 'T:/endoscope';

if (!is_dir($prodBase)) {
    die("❌ エラー: 本番環境パス ({$prodBase}) にアクセスできません。\n");
}

$filesToDeploy = [
    // 0. 本日改修分（2026-11-02: 注目検査（VIP）機能・患者検索画面UI/UX改善）
    'search_patient.php',
    'modal_edit.php',
    'action_schedule.php',
    'print_schedule_daily.php',
    'month_schedule.php',
    'lib/slot_helper.php',

    // 0.1 前日改修分（2026-11-01: v1101 本番稼働リリース・修正インフォメーションマスタ管理・進捗モニター/デイリーノート/空き枠検索強化）
    'system_info_maintenance.php',
    'inc_system_info.php',
    'config/system_info.json',
    'index.php',
    'index.html',
    'schedule_note.php',
    'progress_monitor.php',
    'slot_search.php',
    'lib/calendar_helper.php',
    'action_slot_assistant.php',
    'master_maintenance.php',
    'slot_master_maintenance.php',
    'exam_master_maintenance.php',
    'printer_master_maintenance.php',
    'help.php',
    'help.html',
    '仕様書/specdoc.html',
    '仕様書/2026-11-1works.html',
    'tools/migrate_system_info_table.php',
    'tests/test_system_info_maintenance.php',

    // 0.1 前期改修分（2026-10-30: ネームプレート印刷・本日リスト出力先設定・プリンターマスターメンテ・サーバー直接印刷）
    'lib/printer_helper.php',
    'print_nameplate.php',
    'api_server_print.php',
    'bin/SumatraPDF.exe',
    'get_mall_patient.php',

    // 1. 最新機能（2026-10-27: 部屋重複リアルタイム検知・A4印刷DX・ページ構成復元・年号省略日付）
    'bulk_edit_prep_room.php',
    'print_schedule_daily.php',
    'schedule_note.php',
    'month_schedule.php',
    'month_matrix_v2.php',
    'exam_master_maintenance.php',
    'action_exam_master.php',
    'master_maintenance.php',
    'action_doctor_notification.php',
    'action_master.php',
    'furiwake_daymemo.php',
    'get_progress_data.php',
    'index.php',
    'lib/slot_helper.php',
    'matrix_data_loader.php',
    'modal_edit.php',
    'slot_master_maintenance.php',
    'slot_search.php',
    'stats.php',
    'action_slot_assistant.php',
    'urgent_slot_modal_inc.php',
    'waku_override_modal_inc.php',
    '仕様書/specdoc.html',
    '仕様書/2026-10-30works.html',
    '仕様書/2026-10-27works.html',
    '仕様書/2026-10-26works.html',
    '仕様書/2026-10-25works.html',
    '仕様書/2026-10-24works.html',
    '仕様書/2026-10-22works.html',
    '仕様書/2026-10-21works.html',
    'tests/test_compact_mode.php',
    'tests/test_print_page_split.php',

    // 2. RS-Base キャンバス連携・検査実績タイムライン（2026-10-24/25）
    'lib/canvas_helper.php',
    'lib/patient_helper.php',
    'followup/index.php',
    'help.php',
    'help.html',
    'tests/test_canvas_integration.php',

    // 3. 時間枠オーバーライド・枠名称変更・枠中止連動・非同期化（2026-10-22/23）
    'waku_console_proto.php',
    'waku_legacy_convert.php',
    'action_waku_override.php',
    'api_toggle_no_waku.php',
    'assets/js/toggle_no_waku.js',
    'assets/js/env_detector.js',
    'lib/env_helper.php',
    'action_schedule.php',
    'lib/matrix_helper.php',
    'lib/audit_helper.php',
    'bulk_edit_prep_room.php',
    'daily_slot_summary.php',
    'month_matrix_v2.php',
    'assets/css/month_schedule.css',
    'assets/css/onokai_flatpickr.css',
    'assets/css/theme.css',
    'assets/js/onokai_flatpickr.js',
    'audit_modal_inc.php',
    'calendar_helper_onokai.php',

    // 4. ツール・マイグレーション
    'tools/deploy_to_production.php',
    'tools/verify_production_http.php',
    'tools/check_prod_connection.php',
    'tools/backup_production_db.php',
    'tools/migrate_production_db_safe.php',
    'tools/diff_production_files.php',
    'tools/migrate_m2_waku_override_to_production.php',
    'tools/migration_20261022_create_m2_waku_override.sql',
];

$filesToDeploy = array_values(array_unique($filesToDeploy));

$timestamp = date('Ymd_His');

echo "=== 本番環境 (192.168.1.16) へのデプロイ開始 ===" . PHP_EOL;
echo "デプロイ日時: " . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;

// 1. バックアップ作成
echo "1. 既存本番ファイルのバックアップ作成..." . PHP_EOL;
foreach ($filesToDeploy as $file) {
    $prodFile = $prodBase . '/' . $file;
    if (file_exists($prodFile)) {
        $backupFile = $prodFile . '.bak_' . $timestamp;
        if (copy($prodFile, $backupFile)) {
            echo "  ✓ バックアップ完了: {$file} -> " . basename($backupFile) . PHP_EOL;
        } else {
            die("❌ エラー: バックアップ作成に失敗しました: {$prodFile}\n");
        }
    } else {
        echo "  ℹ️ 新規ファイル（バックアップ不要）: {$file}" . PHP_EOL;
    }
}
echo PHP_EOL;

// 2. ファイルコピー
echo "2. 最新ファイルの同期（コピー）実行..." . PHP_EOL;
$deployedCount = 0;
foreach ($filesToDeploy as $file) {
    $src = $localBase . '/' . $file;
    $dst = $prodBase . '/' . $file;

    // ディレクトリ存在チェック
    $dstDir = dirname($dst);
    if (!is_dir($dstDir)) {
        mkdir($dstDir, 0777, true);
    }

    if (!file_exists($src)) {
        die("❌ エラー: コピー元ファイルが存在しません: {$src}\n");
    }

    if (copy($src, $dst)) {
        touch($dst, filemtime($src)); // ローカルの更新日時（タイムスタンプ）を維持
        $size = filesize($dst);
        echo "  ✓ コピー成功: {$file} ({$size} bytes)" . PHP_EOL;
        $deployedCount++;
    } else {
        die("❌ エラー: ファイルコピーに失敗しました: {$file}\n");
    }
}
echo PHP_EOL . "計 {$deployedCount} 件のファイルを本番環境へ配置しました。" . PHP_EOL . PHP_EOL;

// 3. ハッシュ整合性チェック
echo "3. MD5ハッシュによる完全一致検証..." . PHP_EOL;
$allMatched = true;
foreach ($filesToDeploy as $file) {
    $src = $localBase . '/' . $file;
    $dst = $prodBase . '/' . $file;

    $srcMd5 = md5_file($src);
    $dstMd5 = md5_file($dst);

    if ($srcMd5 === $dstMd5) {
        echo "  ✓ 一致確認: {$file} (MD5: {$srcMd5})" . PHP_EOL;
    } else {
        echo "  ❌ 不一致: {$file}" . PHP_EOL;
        $allMatched = false;
    }
}

if (!$allMatched) {
    die("\n❌ エラー: ハッシュが一致しないファイルがあります。\n");
}

echo PHP_EOL . "=== 本番環境ファイル配置・整合性検証 完了 ===" . PHP_EOL;
