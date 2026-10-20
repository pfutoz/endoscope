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
 *   - 2026-10-20: 印刷DX、仕様書、および特大Flatpickr日付コントロールライブラリ周りの全ファイルをデプロイ対象に追加
 */

$localBase = 'C:/Apache24/htdocs/endoscope';
$prodBase  = 'T:/endoscope';

if (!is_dir($prodBase)) {
    die("❌ エラー: 本番環境パス ({$prodBase}) にアクセスできません。\n");
}

$filesToDeploy = [
    // 1. 日付コントロール周り（共通アセット・ライブラリ）
    'assets/vendor/flatpickr/flatpickr.min.css',
    'assets/vendor/flatpickr/flatpickr.min.js',
    'assets/vendor/flatpickr/ja.js',
    'assets/css/onokai_flatpickr.css',
    'assets/js/onokai_flatpickr.js',

    // 2. 日付コントロール適用画面
    'schedule_note.php',
    'modal_edit.php',
    'slot_master_maintenance.php',
    'slot_search.php',
    'stats.php',
    'furiwake_daymemo.php',
    'followup/index.php',
    'followup/edit.php',

    // 3. 帳票印刷DX・ヘルプ
    'print_schedule_daily.php',
    'help.php',
    'help.html',

    // 4. 仕様書
    '仕様書/2026-10-20works.html',
    '仕様書/specdoc.html',

    // 5. ツール類
    'tools/apply_flatpickr.py',
    'tools/update_all_screens_flatpickr.py',
    'tools/deploy_to_production.php',
];

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
