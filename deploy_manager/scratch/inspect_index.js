const fs = require('fs');
const content = fs.readFileSync('c:/Apache24/htdocs/ip/index.php', 'utf8');

// モーダル検索
const modalRegex = /<div[^>]*class="modal[^"]*"[^>]*id="([^"]+)"/g;
let match;
while ((match = modalRegex.exec(content)) !== null) {
    console.log('Found modal ID:', match[1]);
}

// スタイルタグ終了位置
const styleEnd = content.indexOf('</style>');
console.log('Style tag ends at index:', styleEnd);

// top-actions の位置
const topActions = content.indexOf('<div class="top-actions">');
console.log('top-actions at index:', topActions);

// col-spec の位置
const colSpec = content.indexOf('<td class="col-spec">');
console.log('col-spec at index:', colSpec);
