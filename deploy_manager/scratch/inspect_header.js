const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

const headerIdx = content.indexOf('<header');
const headerEnd = content.indexOf('</header>');
console.log(content.substring(headerIdx, headerEnd + 9));
