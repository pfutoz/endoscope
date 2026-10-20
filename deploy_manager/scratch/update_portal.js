const fs = require('fs');

const cardHtml = `
            <!-- 10. 重要端末ヘルスチェック（紫枠 / 稼働中） -->
            <a href="/ip/?monitor=1" class="menu-item" style="border-left-color: #7c3aed;">
                <div class="item-header">
                    <div class="item-title-area">
                        <span class="item-title">💻 重要端末ヘルスチェック</span>
                        <span class="badge-active" style="background:#7c3aed;">稼働中</span>
                    </div>
                    <span class="item-arrow">→</span>
                </div>
                <div class="item-desc">
                    受付・診察室Dr端末・検査室PCなど院内重要端末のSSD/HDD健全性（SMART）、残存寿命%、Cドライブ空き容量、稼働負荷を10分間隔で集中監視します。
                </div>
            </a>
`;

const headerLinkHtml = `
                <span>
                    <a href="/ip/?monitor=1" style="color: #ffffff; text-decoration: none; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: rgba(124, 58, 237, 0.45); padding: 2px 8px; border-radius: 4px; border: 1px solid rgba(196, 181, 253, 0.5);" title="院内の重要端末（受付・Dr・検査室等）のSMART・SSD寿命・空き容量監視画面へ移動">💻 重要端末監視</a>
                </span>`;

function updatePortalFile(filePath) {
    let content = fs.readFileSync(filePath, 'utf8');

    // 1. メイングリッドにカード追加
    if (!content.includes('重要端末ヘルスチェック')) {
        // オペ管理システムを探す
        const opSearch = '/op/index.php" class="menu-item"';
        const opIdx = content.indexOf(opSearch);
        if (opIdx !== -1) {
            // その後の </a> を探す
            const aCloseIdx = content.indexOf('</a>', opIdx);
            if (aCloseIdx !== -1) {
                const afterA = aCloseIdx + 4;
                content = content.substring(0, afterA) + '\n' + cardHtml + content.substring(afterA);
                console.log(`Added card to ${filePath}`);
            }
        }
    } else {
        console.log(`Card already exists in ${filePath}`);
    }

    // 2. ヘッダーにリンク追加
    if (!content.includes('💻 重要端末監視')) {
        const headerInfoSearch = '<div class="header-info">';
        const hIdx = content.indexOf(headerInfoSearch);
        if (hIdx !== -1) {
            const afterHeaderInfo = hIdx + headerInfoSearch.length;
            content = content.substring(0, afterHeaderInfo) + '\n' + headerLinkHtml + content.substring(afterHeaderInfo);
            console.log(`Added header link to ${filePath}`);
        }
    } else {
        console.log(`Header link already exists in ${filePath}`);
    }

    fs.writeFileSync(filePath, content, 'utf8');
}

updatePortalFile('C:/Apache24/htdocs/index.php');
updatePortalFile('C:/Apache24/htdocs/index.html');
