const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

const karteIdx = content.indexOf('<!-- 2. カルテビューワー');
if (karteIdx !== -1) {
    console.log(content.substring(karteIdx, karteIdx + 600));
}
