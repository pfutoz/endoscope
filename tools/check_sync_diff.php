<?php
/**
 * tools/check_sync_diff.php
 * 本日変更ファイルおよび本番環境(T:\endoscope)との差分チェック
 */

$localBase = 'C:/Apache24/htdocs/endoscope';
$prodBase  = 'T:/endoscope';

$excludeDirs = ['.git', '.agents', '.VSCodeCounter', 'scratch', 'deploy_manager', 'tests', 'archive'];

$diffs = [];
$todayFiles = [];
$todayDate = '2026-10-09';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($localBase, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $path = str_replace('\\', '/', $item->getPathname());
    $rel = substr($path, strlen($localBase) + 1);

    // 除外ディレクトリ
    $skip = false;
    foreach ($excludeDirs as $ed) {
        if ($rel === $ed || strpos($rel, $ed . '/') === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    // 除外拡張子/パターン
    if (preg_match('/(\.bak|\.log|\.tmp|Thumbs\.db)$/i', $rel)) {
        continue;
    }

    if ($item->isFile()) {
        $mtime = filemtime($path);
        $mdate = date('Y-m-d H:i:s', $mtime);
        $size = filesize($path);

        $prodPath = $prodBase . '/' . $rel;
        $inProd = file_exists($prodPath);
        $prodMtime = $inProd ? filemtime($prodPath) : 0;
        $prodDate = $inProd ? date('Y-m-d H:i:s', $prodMtime) : 'MISSING';
        $prodSize = $inProd ? filesize($prodPath) : 0;

        $isDiff = false;
        if (!$inProd) {
            $isDiff = true;
            $diffType = 'NEW_IN_LOCAL';
        } else {
            // サイズまたはハッシュで比較
            if ($size !== $prodSize || md5_file($path) !== md5_file($prodPath)) {
                $isDiff = true;
                $diffType = 'MODIFIED';
            }
        }

        if (strpos($mdate, $todayDate) === 0) {
            $todayFiles[] = [
                'rel' => $rel,
                'mdate' => $mdate,
                'size' => $size,
                'in_prod' => $inProd,
                'is_diff' => $isDiff
            ];
        }

        if ($isDiff) {
            $diffs[] = [
                'rel' => $rel,
                'type' => $diffType,
                'local_date' => $mdate,
                'prod_date' => $prodDate,
                'local_size' => $size,
                'prod_size' => $prodSize
            ];
        }
    }
}

echo "=== 1. 本日 (2026-10-09) ローカルで更新されたファイル一覧 ===" . PHP_EOL;
foreach ($todayFiles as $f) {
    $diffMark = $f['is_diff'] ? '⚡ [差分あり]' : '✓ [一致]';
    echo sprintf("%-45s | %-19s | %s\n", $f['rel'], $f['mdate'], $diffMark);
}

echo PHP_EOL . "=== 2. 本番環境(T:\\endoscope)との全差分ファイル一覧 ===" . PHP_EOL;
printf("%-45s | %-12s | %-19s | %-19s\n", "相対パス", "状態", "ローカル更新日時", "本番更新日時");
echo str_repeat("-", 105) . PHP_EOL;
foreach ($diffs as $d) {
    printf("%-45s | %-12s | %-19s | %-19s\n", $d['rel'], $d['type'], $d['local_date'], $d['prod_date']);
}
echo "合計差分件数: " . count($diffs) . " 件" . PHP_EOL;
