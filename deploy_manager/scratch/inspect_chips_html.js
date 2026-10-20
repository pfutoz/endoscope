const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/ip/index.php', 'utf8');

const htmlChipsIdx = content.indexOf('<div class="chips-group">');
if (htmlChipsIdx !== -1) {
    console.log(content.substring(htmlChipsIdx, htmlChipsIdx + 900));
}
