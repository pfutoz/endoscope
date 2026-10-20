const fs = require('fs');

const content = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

const opIdx = content.indexOf('<!-- 9. オペ管理システム');
console.log('--- OP snippet ---');
console.log(content.substring(opIdx, opIdx + 600));

const headerIdx = content.indexOf('<div class="header-info">');
console.log('--- Header snippet ---');
console.log(content.substring(headerIdx, headerIdx + 500));
