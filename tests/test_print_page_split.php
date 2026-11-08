<?php
/**
 * A4日別印刷 ページ構成（2ページ分割 ⇔ 改ページなし全件）切替機能の自動テスト
 */

function fetchPrintPage($params) {
    $script = tempnam(sys_get_temp_dir(), 'test_print_');
    $content = '<?php' . "\n"
             . '$_GET = ' . var_export($params, true) . ";\n"
             . 'require ' . var_export(__DIR__ . '/../print_schedule_daily.php', true) . ";\n";
    file_put_contents($script, $content);
    $output = shell_exec('php ' . escapeshellarg($script));
    @unlink($script);
    return $output;
}

$date = '2026-10-22';
$dept = 'PROCT';

echo "=== TEST 1: デフォルト (page_split 未指定) ===\n";
$html1 = fetchPrintPage(['date' => $date, 'dept' => $dept]);

if (strpos($html1, 'id="selectPageSplit"') !== false) {
    echo "[PASS] ページ構成セレクトボックスが存在します\n";
} else {
    echo "[FAIL] ページ構成セレクトボックスが見つかりません\n";
    exit(1);
}

if (strpos($html1, 'id="container-split"') !== false && strpos($html1, 'id="container-continuous"') !== false) {
    echo "[PASS] 分割コンテナと連続コンテナの両方が出力されています\n";
} else {
    echo "[FAIL] コンテナが見つかりません\n";
    exit(1);
}

// デフォルトでは container-split が表示、container-continuous が display:none
if (strpos($html1, 'id="container-continuous" class="print-container-continuous" style="display:none;"') !== false) {
    echo "[PASS] デフォルトで改ページなしコンテナが display:none になっています\n";
} else {
    echo "[FAIL] container-continuous の初期スタイルが不正です\n";
    exit(1);
}

echo "\n=== TEST 2: page_split=0 (改ページなし指定) ===\n";
$html2 = fetchPrintPage(['date' => $date, 'dept' => $dept, 'page_split' => '0']);

if (strpos($html2, 'id="container-split" class="print-container-split" style="display:none;"') !== false) {
    echo "[PASS] page_split=0 指定時、分割コンテナが display:none になっています\n";
} else {
    echo "[FAIL] container-split のスタイルが不正です\n";
    exit(1);
}

if (strpos($html2, 'id="container-continuous" class="print-container-continuous" style=""') !== false) {
    echo "[PASS] page_split=0 指定時、連続コンテナが表示状態になっています\n";
} else {
    echo "[FAIL] container-continuous の表示状態が不正です\n";
    exit(1);
}

echo "\n=== TEST 3: 改ページなしコンテナのタイトルとテーブル内容検証 ===\n";
if (strpos($html2, '内視鏡予約一覧表［肛門科］') !== false) {
    echo "[PASS] 統合タイトル「内視鏡予約一覧表［肛門科］」が出力されています\n";
} else {
    echo "[FAIL] 統合タイトルが見つかりません\n";
    exit(1);
}

// 予約患者が同一コンテナ内に存在することを確認
$contStart = strpos($html2, 'id="container-continuous"');
$contHtml = substr($html2, $contStart);

if (strpos($contHtml, '森澤') !== false || strpos($contHtml, '島井') !== false) {
    echo "[PASS] 連続コンテナ内に予約が出力されています\n";
} else {
    echo "[FAIL] 連続コンテナ内に予約データが見つかりません\n";
    exit(1);
}

// 連続コンテナ内に強制改ページクラス（page-break）が付与されていないことを確認
if (strpos($contHtml, 'page-break') === false) {
    echo "[PASS] 連続コンテナ内には強制改ページ（page-break）が付与されていません\n";
} else {
    echo "[FAIL] 連続コンテナ内に予期せぬ page-break が検出されました\n";
    exit(1);
}

echo "\n========================================\n";
echo "🎉 全てのテスト項目に合格しました！\n";
echo "========================================\n";
