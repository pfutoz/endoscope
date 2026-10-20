const fs = require('fs');

const targetPath = 'C:/Apache24/htdocs/ip/index.php';
let content = fs.readFileSync(targetPath, 'utf8');

// 1. PHP変数定義とフィルタマッチ処理の更新
const oldFilter = `$showEmpty = ($_GET['empty'] ?? '') === '1';
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$search = trim((string)($_GET['search'] ?? ''));

$filtered = match (true) {
    $showEmpty            => array_values(array_filter($data, fn(IpRecord $r) => $r->isUnset())),
    $statusFilter === 'on'  => array_values(array_filter($data, fn(IpRecord $r) => $r->ping_status === 'ON')),
    $statusFilter === 'off' => array_values(array_filter($data, fn(IpRecord $r) => $r->ping_status === 'OFF')),
    $search !== ''        => array_values(array_filter($data, fn(IpRecord $r) => $r->matches($search))),
    default               => $data,
};

$emptyCount   = count(array_filter($data, fn(IpRecord $r) => $r->isUnset()));`;

const newFilter = `$showMonitor = ($_GET['monitor'] ?? '') === '1';
$showEmpty = ($_GET['empty'] ?? '') === '1';
$statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));
$search = trim((string)($_GET['search'] ?? ''));

$filtered = match (true) {
    $showMonitor          => array_values(array_filter($data, fn(IpRecord $r) => isset($healthMap[trim($r->ip)]))),
    $showEmpty            => array_values(array_filter($data, fn(IpRecord $r) => $r->isUnset())),
    $statusFilter === 'on'  => array_values(array_filter($data, fn(IpRecord $r) => $r->ping_status === 'ON')),
    $statusFilter === 'off' => array_values(array_filter($data, fn(IpRecord $r) => $r->ping_status === 'OFF')),
    $search !== ''        => array_values(array_filter($data, fn(IpRecord $r) => $r->matches($search))),
    default               => $data,
};

$monitorCount = count(array_filter($data, fn(IpRecord $r) => isset($healthMap[trim($r->ip)])));
$emptyCount   = count(array_filter($data, fn(IpRecord $r) => $r->isUnset()));`;

if (!content.includes('$showMonitor =')) {
    content = content.replace(oldFilter, newFilter);
    console.log('Updated PHP filter logic with $showMonitor.');
}

// 2. chips-group へのチップ追加
const oldChips = `<div class="chips-group">
            <a href="?" class="chip <?= !$showEmpty && $statusFilter === '' && $search === '' ? 'active' : '' ?>">
                全件 <span class="chip-badge"><?= count($data) ?></span>
            </a>`;

const newChips = `<div class="chips-group">
            <a href="?" class="chip <?= !$showMonitor && !$showEmpty && $statusFilter === '' && $search === '' ? 'active' : '' ?>">
                全件 <span class="chip-badge"><?= count($data) ?></span>
            </a>
            <a href="?monitor=1" class="chip chip-monitor <?= $showMonitor ? 'active' : '' ?>" style="<?= $showMonitor ? 'background:#7c3aed; color:#fff; border-color:#6d28d9; font-weight:700;' : 'border-color:#c4b5fd; color:#6d28d9; font-weight:600;' ?>" title="SMARTヘルス監視データが報告されている重要端末のみ抽出">
                💻 監視端末 <span class="chip-badge" style="<?= $showMonitor ? 'background:rgba(255,255,255,0.3); color:#fff;' : 'background:#ede9fe; color:#6d28d9;' ?>"><?= $monitorCount ?></span>
            </a>`;

if (!content.includes('?monitor=1')) {
    content = content.replace(oldChips, newChips);
    console.log('Added 監視端末 chip to chips-group.');
}

// 3. モニターモード時のバナー（table-wrapper の直前）
const tableWrapperTarget = '<div class="table-wrapper">';
const bannerHtml = `<?php if ($showMonitor): ?>
    <div class="monitor-banner" style="background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%); border: 1px solid #c4b5fd; border-left: 5px solid #7c3aed; border-radius: 8px; padding: 10px 16px; margin: 8px 0 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 24px;">💻</span>
            <div>
                <div style="font-weight: 700; color: #4c1d95; font-size: 14px;">重要端末ヘルス監視モード (10分周期バックグラウンド自動巡回)</div>
                <div style="font-size: 12px; color: #6d28d9;">受付・診察室Dr端末・検査室PCなど、エージェントが稼働中の端末のみ表示しています。</div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="background: #fff; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #5b21b6; border: 1px solid #ddd6fe;">
                稼働中: <strong><?= $monitorCount ?></strong> 台
            </span>
            <a href="download_agent.php" class="btn" style="background: #7c3aed; color: #fff; font-size: 11px; padding: 3px 10px; font-weight: 600;" title="新たな重要端末へ導入するためのZIPをダウンロード">＋ 対象端末を追加 (ZIP)</a>
            <a href="?" class="btn btn-outline" style="font-size: 11px; padding: 3px 10px; height: auto;">全件台帳に戻る</a>
        </div>
    </div>
    <?php endif; ?>
    <div class="table-wrapper">`;

if (!content.includes('class="monitor-banner"')) {
    content = content.replace(tableWrapperTarget, bannerHtml);
    console.log('Added monitor-banner before table-wrapper.');
}

// 4. 空表示時のメッセージ調整
const oldEmptyMsg = "<?= $showEmpty ? '🎉 空欄のIPはありません！' : '📭 条件に合致するデータがありません' ?>";
const newEmptyMsg = "<?= $showMonitor ? '💻 現在ヘルス報告を受信している端末はありません（上部の「監視ツール(ZIP)」から導入してください）' : ($showEmpty ? '🎉 空欄のIPはありません！' : '📭 条件に合致するデータがありません') ?>";

if (content.includes(oldEmptyMsg)) {
    content = content.replace(oldEmptyMsg, newEmptyMsg);
    console.log('Updated empty message for monitor mode.');
}

fs.writeFileSync(targetPath, content, 'utf8');
console.log('Successfully updated ip/index.php with monitor mode!');
