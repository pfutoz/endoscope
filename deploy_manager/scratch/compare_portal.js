const fs = require('fs');

const fHtml = fs.readFileSync('C:/Apache24/htdocs/index.html', 'utf8');
const fPhp = fs.readFileSync('C:/Apache24/htdocs/index.php', 'utf8');

console.log('index.html length:', fHtml.length);
console.log('index.php length:', fPhp.length);
console.log('Are identical:', fHtml === fPhp);

// どこが違うか先頭や特徴を比較
console.log('index.php starts with:', fPhp.substring(0, 100));
