const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

const sectionMatches = content.match(/<!--.*?-->/g);
console.log('Comments / sections found:\n', sectionMatches.filter(c => c.includes('カルテ') || c.includes('マニュアル') || c.includes('グリッド') || c.includes('管理') || c.includes('システム')));
