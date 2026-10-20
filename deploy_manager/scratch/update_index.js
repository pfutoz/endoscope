const fs = require('fs');

const targetPath = 'C:/Apache24/htdocs/ip/index.php';
let content = fs.readFileSync(targetPath, 'utf8');

// 1. PHP先頭処理の追加
const phpSearch = "$data = $repository->findAll();";
const phpInsert = `
// ─── クライアントPC 常駐ヘルスチェックデータ読み込み (health_records/*.json) ───
$healthMap = [];
$healthDir = __DIR__ . '/health_records';
if (is_dir($healthDir)) {
    $healthFiles = glob($healthDir . '/*.json');
    if ($healthFiles !== false) {
        foreach ($healthFiles as $hfile) {
            $hIp = basename($hfile, '.json');
            $hJson = file_get_contents($hfile);
            if ($hJson !== false) {
                $hData = json_decode($hJson, true);
                if (is_array($hData)) {
                    $healthMap[$hIp] = $hData;
                }
            }
        }
    }
}

/**
 * SMARTヘルスチェックバッジのHTMLを生成
 */
function renderHealthBadge(?array $h): string {
    if (!$h) {
        return '';
    }

    $receivedAt = $h['receivedAt'] ?? $h['collectedAt'] ?? '';
    $storage = $h['storage'] ?? [];
    $disks = $storage['physicalDisks'] ?? [];
    $overallStatus = $storage['overallStatus'] ?? 'OK';

    $hasPredictFailure = false;
    $minWear = 100;
    $maxTemp = 0;

    foreach ($disks as $d) {
        if (!empty($d['predictFailure'])) {
            $hasPredictFailure = true;
        }
        if (isset($d['wearRemainingPercent']) && is_numeric($d['wearRemainingPercent'])) {
            $minWear = min($minWear, (int)$d['wearRemainingPercent']);
        }
        if (isset($d['temperature']) && is_numeric($d['temperature']) && (int)$d['temperature'] > 0) {
            $maxTemp = max($maxTemp, (int)$d['temperature']);
        }
    }

    $isLive = false;
    if ($receivedAt !== '') {
        $ts = strtotime($receivedAt);
        if ($ts && (time() - $ts) <= 900) {
            $isLive = true;
        }
    }

    $badgeClass = 'badge-health-ok';
    $icon = '🟢';
    $titleState = 'SMART正常';

    if ($hasPredictFailure || $overallStatus === 'Warning' || $overallStatus === 'Critical') {
        $badgeClass = 'badge-health-danger';
        $icon = '🔴';
        $titleState = 'SMART故障予兆検知！要交換';
    } elseif ($minWear <= 20) {
        $badgeClass = 'badge-health-warn';
        $icon = '🟡';
        $titleState = "SSD残存寿命低下 ({$minWear}%)";
    } elseif ($maxTemp >= 60) {
        $badgeClass = 'badge-health-warn';
        $icon = '🟡';
        $titleState = "高温注意 ({$maxTemp}℃)";
    }

    $shortText = "SSD:{$minWear}%";
    if ($maxTemp > 0) {
        $shortText .= " {$maxTemp}℃";
    }

    $tooltip = "【クライアント常駐ヘルス (10分周期)】\\n"
             . "状態: {$titleState}\\n"
             . "SSD残存寿命: {$minWear}%\\n"
             . ($maxTemp > 0 ? "最高温度: {$maxTemp}℃\\n" : "")
             . "最終報告: {$receivedAt}" . ($isLive ? " (🟢通信中)" : "") . "\\n"
             . "クリックで詳細SMART・リソース情報を表示";

    $safeJson = htmlspecialchars(json_encode($h, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
    $safeTooltip = htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8');

    return '<span class="badge-health ' . $badgeClass . '" data-health=\\'' . $safeJson . '\\' onclick="showHealthDetail(event, this)" title="' . $safeTooltip . '">'
         . $icon . ' ' . htmlspecialchars($shortText, ENT_QUOTES, 'UTF-8')
         . '</span>';
}
`;

if (!content.includes('function renderHealthBadge')) {
    content = content.replace(phpSearch, phpSearch + '\n' + phpInsert);
    console.log('Added PHP health loader and helper function.');
}

// 2. CSSスタイル追加
const cssInsert = `
        /* ─── SMARTヘルスチェックバッジ ─── */
        .badge-health {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
            transition: all 0.15s ease-in-out;
            white-space: nowrap;
            margin-left: 3px;
            vertical-align: middle;
        }
        .badge-health:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }
        .badge-health-ok {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .badge-health-ok:hover {
            background: #d1fae5;
            border-color: #6ee7b7;
        }
        .badge-health-warn {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .badge-health-warn:hover {
            background: #fef3c7;
            border-color: #fcd34d;
        }
        .badge-health-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            animation: pulse-danger 1.5s infinite;
        }
        @keyframes pulse-danger {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.85; transform: scale(1.03); }
        }

        /* ─── ヘルス詳細モーダル ─── */
        .health-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .health-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
        }
        .health-box-title {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .health-disk-item {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 8px;
        }
        .health-disk-item:last-child {
            margin-bottom: 0;
        }
        .health-progress-bar {
            background: #e2e8f0;
            border-radius: 4px;
            height: 7px;
            overflow: hidden;
            margin: 4px 0;
        }
        .health-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        .fill-ok { background: #10b981; }
        .fill-warn { background: #f59e0b; }
        .fill-danger { background: #ef4444; }
`;

if (!content.includes('.badge-health {')) {
    content = content.replace('</style>', cssInsert + '\n    </style>');
    console.log('Added health CSS styles.');
}

// 3. top-actions にダウンロードボタン追加
const topActionsTarget = '<div class="top-actions">';
const downloadBtnHtml = '<a href="download_agent.php" class="btn" style="background:#7c3aed; color:#fff; font-weight:700; box-shadow:0 1px 3px rgba(124,58,237,0.3);" title="重要PC（受付・Dr・検査室等）のSMART・容量10分自動監視ツールZIPをダウンロード">💻 監視ツール(ZIP)</a>\n            ';

if (!content.includes('download_agent.php')) {
    content = content.replace(topActionsTarget, topActionsTarget + '\n            ' + downloadBtnHtml);
    console.log('Added 監視ツール(ZIP) button to top-actions.');
}

// 4. テーブル行にヘルスバッジ追加
const specEndTarget = '<?php endif; ?>\n                                </div>\n                            </td>';
const specBadgeInsert = `<?php endif; ?>
                                    <?= renderHealthBadge($healthMap[$rawIp] ?? null) ?>
                                </div>
                            </td>`;

if (!content.includes('renderHealthBadge($healthMap[$rawIp] ?? null)')) {
    content = content.replace(specEndTarget, specBadgeInsert);
    console.log('Added renderHealthBadge to table spec column.');
}

// 5. モーダルHTML追加
const modalHtml = `
<!-- ─── モーダル: クライアント端末 SMART・ヘルス詳細 ─── -->
<div id="healthModal" class="modal-overlay" onclick="handleHealthBackdropClick(event)">
    <div class="modal-card" style="max-width: 680px;">
        <div class="modal-header" style="border-bottom: 2px solid #7c3aed; padding-bottom: 10px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:22px;">💻</span>
                <div>
                    <div class="modal-title" id="healthModalTitle" style="font-size:16px; font-weight:700; color:#1e293b;">端末ヘルスチェック詳細</div>
                    <div id="healthModalSubtitle" style="font-size:11px; color:#64748b;">IP: - | 最終報告: -</div>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeHealthModal()">&times;</button>
        </div>
        <div class="modal-body" id="healthModalBody" style="padding-top:12px; max-height:75vh; overflow-y:auto;">
            <!-- JSで動的生成 -->
        </div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:10px; border-top:1px solid #e2e8f0;">
            <span style="font-size:11px; color:#94a3b8;">⏱ 10分毎にバックグラウンド自動収集・送信中</span>
            <button type="button" class="btn btn-outline" onclick="closeHealthModal()">閉じる</button>
        </div>
    </div>
</div>
`;

if (!content.includes('id="healthModal"')) {
    // rdpModal の手前に挿入
    const rdpTarget = '<!-- ════════════════════════════════════\n     モーダル: RDP直接起動の技';
    if (content.includes(rdpTarget)) {
        content = content.replace(rdpTarget, modalHtml + '\n' + rdpTarget);
        console.log('Added healthModal HTML before rdpModal.');
    } else {
        // 見つからない場合は </body> の直前
        content = content.replace('</body>', modalHtml + '\n</body>');
        console.log('Added healthModal HTML before </body>.');
    }
}

// 6. JavaScript関数の追加
const jsCode = `
// ─── クライアントヘルス詳細モーダル ───
const healthModal = document.getElementById('healthModal');

function showHealthDetail(e, el) {
    if (e) e.stopPropagation();
    const dataStr = el.getAttribute('data-health');
    if (!dataStr) return;
    
    let h;
    try {
        h = JSON.parse(dataStr);
    } catch(err) {
        console.error('Failed to parse health JSON', err);
        return;
    }

    const modal = document.getElementById('healthModal');
    const title = document.getElementById('healthModalTitle');
    const subtitle = document.getElementById('healthModalSubtitle');
    const body = document.getElementById('healthModalBody');

    const hostName = (h.host && h.host.computerName) || h.computerName || 'クライアント端末';
    const ip = h.ip || '-';
    const receivedAt = h.receivedAt || h.collectedAt || '-';
    const os = (h.host && h.host.os) || '-';
    const uptime = (h.host && h.host.uptime) || '-';

    title.textContent = '💻 ' + hostName + ' のヘルス詳細';
    subtitle.innerHTML = 'IP: <strong>' + escapeHtml(ip) + '</strong> &nbsp;|&nbsp; 最終報告: <strong>' + escapeHtml(receivedAt) + '</strong> &nbsp;|&nbsp; OS: ' + escapeHtml(os);

    let html = '';

    // 1. 物理ディスク SMART 健康状態
    const storage = h.storage || {};
    const disks = storage.physicalDisks || [];
    const overall = storage.overallStatus || 'OK';

    html += '<div class="health-box">';
    html += '<div class="health-box-title"><span>🛡 物理ドライブ SMART 診断</span> <span class="badge-health ' + (overall === 'OK' ? 'badge-health-ok' : 'badge-health-danger') + '">' + (overall === 'OK' ? '総合正常' : '要確認') + '</span></div>';

    if (disks.length === 0) {
        html += '<p style="color:#94a3b8; font-size:12px; margin:0;">物理ドライブ情報なし</p>';
    } else {
        disks.forEach(d => {
            const name = d.friendlyName || ('Disk ' + (d.deviceId || ''));
            const type = d.mediaType || '不明';
            const size = d.sizeGB ? (d.sizeGB + ' GB') : '-';
            const predictFail = d.predictFailure ? '⚠️ 故障予兆検知 (要即交換)' : '正常 (予兆なし)';
            const wear = (d.wearRemainingPercent !== null && d.wearRemainingPercent !== undefined) ? d.wearRemainingPercent : null;
            const temp = (d.temperature && d.temperature > 0) ? (d.temperature + ' ℃') : null;

            let wearBar = '';
            if (wear !== null) {
                const fillClass = wear <= 20 ? 'fill-danger' : (wear <= 50 ? 'fill-warn' : 'fill-ok');
                wearBar = '<div style="font-size:11px; margin-top:4px; display:flex; justify-content:space-between;">'
                        + '<span>SSD残存寿命: <strong>' + wear + '%</strong></span>'
                        + '<span style="color:#64748b;">(消耗率: ' + (d.wearUsedPercent || 0) + '%)</span>'
                        + '</div>'
                        + '<div class="health-progress-bar"><div class="health-progress-fill ' + fillClass + '" style="width:' + wear + '%"></div></div>';
            }

            html += '<div class="health-disk-item">'
                  + '<div style="display:flex; justify-content:space-between; align-items:center;">'
                  + '<strong style="font-size:13px; color:#1e293b;">' + escapeHtml(name) + '</strong>'
                  + '<span style="font-size:11px; background:#e2e8f0; padding:1px 6px; border-radius:4px; font-weight:600;">' + escapeHtml(type) + ' / ' + escapeHtml(size) + '</span>'
                  + '</div>'
                  + '<div style="font-size:11px; color:#475569; margin-top:3px; display:flex; gap:12px; flex-wrap:wrap;">'
                  + '<span>状態: <strong style="color:' + (d.predictFailure ? '#ef4444' : '#10b981') + '">' + escapeHtml(predictFail) + '</strong></span>'
                  + (temp ? ('<span>温度: <strong style="color:' + (parseInt(temp) >= 60 ? '#ef4444' : '#0284c7') + '">' + escapeHtml(temp) + '</strong></span>') : '')
                  + (d.readErrors !== null && d.readErrors !== undefined ? ('<span>読取エラー: ' + d.readErrors + '</span>') : '')
                  + '</div>'
                  + wearBar
                  + '</div>';
        });
    }
    html += '</div>';

    // 2. ボリューム・リソース
    const volumes = storage.volumes || [];
    const res = h.resources || {};
    const cpu = res.cpu || {};
    const mem = res.memory || {};

    html += '<div class="health-card-grid">';

    // ドライブ容量
    html += '<div class="health-box">';
    html += '<div class="health-box-title"><span>💾 ドライブ空き容量</span></div>';
    if (volumes.length === 0) {
        html += '<p style="color:#94a3b8; font-size:12px; margin:0;">ボリューム情報なし</p>';
    } else {
        volumes.forEach(v => {
            const drive = v.driveLetter || 'C:';
            const label = v.label ? ('(' + v.label + ')') : '';
            const usedPct = v.usedPercent || 0;
            const freeGb = v.freeGB || 0;
            const totalGb = v.totalGB || 0;
            const fillClass = usedPct >= 90 ? 'fill-danger' : (usedPct >= 80 ? 'fill-warn' : 'fill-ok');

            html += '<div style="margin-bottom:8px;">'
                  + '<div style="display:flex; justify-content:space-between; font-size:11px;">'
                  + '<strong>' + escapeHtml(drive) + ' ' + escapeHtml(label) + '</strong>'
                  + '<span>空き <strong>' + freeGb + ' GB</strong> / ' + totalGb + ' GB (' + usedPct + '%使用)</span>'
                  + '</div>'
                  + '<div class="health-progress-bar"><div class="health-progress-fill ' + fillClass + '" style="width:' + usedPct + '%"></div></div>'
                  + '</div>';
        });
    }
    html += '</div>';

    // CPU / メモリ / 稼働時間
    html += '<div class="health-box">';
    html += '<div class="health-box-title"><span>⚡ システムリソース & 稼働</span></div>';
    html += '<div style="font-size:12px; line-height:1.8; color:#334155;">'
          + '<div>CPU使用率: <strong>' + (cpu.loadPercent !== undefined ? (cpu.loadPercent + '%') : '-') + '</strong> <span style="font-size:10px; color:#64748b;">(' + escapeHtml(cpu.name || '') + ')</span></div>'
          + '<div>メモリ: <strong>' + (mem.usedGB || '-') + ' GB</strong> / ' + (mem.totalGB || '-') + ' GB (' + (mem.usedPercent || 0) + '%使用)</div>'
          + '<div>連続稼働時間: <strong>' + escapeHtml(uptime) + '</strong></div>'
          + '</div>';
    html += '</div>';

    html += '</div>';

    body.innerHTML = html;
    modal.classList.add('open');
}

function closeHealthModal() {
    const modal = document.getElementById('healthModal');
    if (modal) modal.classList.remove('open');
}

function handleHealthBackdropClick(e) {
    const modal = document.getElementById('healthModal');
    if (e.target === modal) closeHealthModal();
}
`;

if (!content.includes('function showHealthDetail')) {
    const rdpCloseTarget = 'function closeRdpModal() {\n    rdpModal.classList.remove(\'open\');\n}';
    if (content.includes(rdpCloseTarget)) {
        content = content.replace(rdpCloseTarget, rdpCloseTarget + '\n' + jsCode);
        console.log('Added healthModal JS functions.');
    } else {
        content = content.replace('</script>', jsCode + '\n</script>');
        console.log('Added healthModal JS functions before </script>.');
    }
}

// Escキーハンドラに closeHealthModal を追加
if (content.includes("if (e.key === 'Escape') {")) {
    if (!content.includes('closeHealthModal()')) {
        content = content.replace("if (e.key === 'Escape') {", "if (e.key === 'Escape') {\n        if (healthModal && healthModal.classList.contains('open')) { closeHealthModal(); return; }");
        console.log('Added healthModal Escape key handling.');
    }
}

fs.writeFileSync(targetPath, content, 'utf8');
console.log('Successfully updated index.php!');
