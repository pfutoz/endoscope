const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/ip/index.php', 'utf8');

const filterIdx = content.indexOf('chips-group');
if (filterIdx !== -1) {
    console.log(content.substring(filterIdx - 100, filterIdx + 600));
}

// PHP側のフィルタ処理部分
const filterPhpIdx = content.indexOf('$showEmpty');
if (filterPhpIdx !== -1) {
    console.log('PHP filter logic:\n', content.substring(filterPhpIdx - 100, filterPhpIdx + 500));
}
