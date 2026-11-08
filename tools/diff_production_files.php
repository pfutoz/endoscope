<?php
/**
 * tools/diff_production_files.php
 * 本番(T:/endoscope)とローカル(C:/Apache24/htdocs/endoscope)の
 * 主要PHP/JS/CSSファイルの差分(MD5)をチェック
 */
$localBase = 'C:/Apache24/htdocs/endoscope';
$prodBase  = 'T:/endoscope';

$scanDirs = ['', 'lib', 'assets/css', 'assets/js', 'followup', 'tools', '仕様書'];
$files = [];

foreach ($scanDirs as $dir) {
    $fullDir = $localBase . ($dir ? '/' . $dir : '');
    if (!is_dir($fullDir)) continue;
    $dh = opendir($fullDir);
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $relPath = ($dir ? $dir . '/' : '') . $file;
        $localPath = $localBase . '/' . $relPath;
        if (is_file($localPath)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($ext, ['php', 'js', 'css', 'html', 'sql'])) {
                $files[] = $relPath;
            }
        }
    }
    closedir($dh);
}

sort($files);

$identical = [];
$different = [];
$missingInProd = [];

foreach ($files as $f) {
    // tests/ や backup/ は本番デプロイ対象外としてスキップ
    if (strpos($f, 'tests/') === 0 || strpos($f, 'backup/') === 0) continue;
    
    $localFile = $localBase . '/' . $f;
    $prodFile  = $prodBase . '/' . $f;

    if (!file_exists($prodFile)) {
        $missingInProd[] = $f;
    } else {
        $localMd5 = md5_file($localFile);
        $prodMd5  = md5_file($prodFile);
        if ($localMd5 === $prodMd5) {
            $identical[] = $f;
        } else {
            $different[] = $f;
        }
    }
}

echo "=== 本番環境 (T:/endoscope) とローカルのファイル比較 ===" . PHP_EOL;
echo "一致ファイル数: " . count($identical) . PHP_EOL;
echo "差分ありファイル数: " . count($different) . PHP_EOL;
echo "本番未配置ファイル数: " . count($missingInProd) . PHP_EOL . PHP_EOL;

if (!empty($different)) {
    echo "【差分あり（更新が必要）】" . PHP_EOL;
    foreach ($different as $f) {
        echo "  M {$f}" . PHP_EOL;
    }
    echo PHP_EOL;
}

if (!empty($missingInProd)) {
    echo "【本番未配置（新規配置が必要）】" . PHP_EOL;
    foreach ($missingInProd as $f) {
        echo "  + {$f}" . PHP_EOL;
    }
    echo PHP_EOL;
}
