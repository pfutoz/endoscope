const fs = require('fs');
const content = fs.readFileSync(__dirname + '/../slot_search.php', 'utf8');

// generateCalPreviewHTML 関数の抽出
const start = content.indexOf('function generateCalPreviewHTML(');
const end = content.indexOf('// イベントリスナーの登録', start);
const fnCode = content.substring(start, end);

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

eval(fnCode);

// テスト用データ
const testDayData = {
    date: '2026-11-10',
    dow: '火',
    is_closed: false,
    holiday_name: '',
    memos: { GASTRO: 'GIF12:30追加' },
    doctor_events: [{ label: '🙅🏼哲也 午前休', raw_title: '午前休診' }],
    departments: {
        GASTRO: {
            code: 'GASTRO',
            name: '胃腸科',
            doctors: [
                {
                    name: '哲也',
                    short: '哲',
                    cls: 'bg-cyan',
                    slots: {
                        GIF: { used: 10, max: 10 },
                        ECHO: { used: 2, max: 2 },
                        TCF: { used: 7, max: 7 }
                    }
                },
                {
                    name: '義智',
                    short: '義',
                    cls: 'bg-indigo',
                    slots: {
                        GIF: { used: 0, max: 0 },
                        ECHO: { used: 0, max: 0 },
                        TCF: { used: 3, max: 3 }
                    }
                }
            ],
            overall: {
                GIF: { used: 10, max: 10 },
                ECHO: { used: 2, max: 2 },
                TCF: { used: 10, max: 10 }
            }
        },
        PROCT: {
            code: 'PROCT',
            name: '肛門科',
            doctors: [
                {
                    name: '誠吾',
                    short: '誠',
                    cls: 'bg-amber',
                    slots: {
                        GIF: { used: 1, max: 2 },
                        ECHO: { used: 0, max: 0 },
                        TCF: { used: 6, max: 6 }
                    }
                }
            ],
            overall: {
                GIF: { used: 1, max: 2 },
                ECHO: { used: 0, max: 0 },
                TCF: { used: 6, max: 6 }
            }
        }
    }
};

const html = generateCalPreviewHTML(testDayData);
console.log("=== 生成されたツールチップ HTML ===");
console.log(html);

// 検証チェック
const checks = [
    { name: '日付表示', pass: html.includes('2026-11-10') && html.includes('火') },
    { name: '胃腸科タイトル', pass: html.includes('胃腸科') },
    { name: '哲也先生のバッジ', pass: html.includes('哲') && html.includes('🔵10/10') && html.includes('🟠2/2') && html.includes('🟢7/7') },
    { name: '義智先生のバッジ', pass: html.includes('義') && html.includes('🟢3/3') },
    { name: '胃腸科全体集計', pass: html.includes('全体') && html.includes('🔵10/10 🟠2/2 🟢10/10') },
    { name: '肛門科タイトル', pass: html.includes('肛門科') },
    { name: '誠吾先生のバッジ', pass: html.includes('誠') && html.includes('🟢6/6') },
    { name: '日別メモ', pass: html.includes('GIF12:30追加') },
    { name: '医師予定', pass: html.includes('哲也 午前休') }
];

console.log("\n=== テスト検証結果 ===");
let allOk = true;
checks.forEach(c => {
    console.log(`  ${c.pass ? '✅' : '❌'} ${c.name}: ${c.pass ? 'PASS' : 'FAIL'}`);
    if (!c.pass) allOk = false;
});
if (allOk) {
    console.log("\n🎉 全てのUI要件・表示要件を100%クリアしました！");
} else {
    process.exit(1);
}
