const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

const startIdx = content.indexOf('<!-- 9. オペ管理システム');
console.log(content.substring(startIdx, startIdx + 1200));
