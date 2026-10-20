const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

// カードのHTML構造を確認（カルテビューワーや内視鏡など）
const cardIdx = content.indexOf('/karteview/index.php');
if (cardIdx !== -1) {
    console.log('Card snippet around karteview:\n', content.substring(cardIdx - 150, cardIdx + 350));
}

// gridコンテナの確認
const gridIdx = content.indexOf('class="grid"');
if (gridIdx === -1) {
    const gridMatches = content.match(/class="[^"]*grid[^"]*"/gi);
    console.log('Grid matches:', gridMatches);
}
