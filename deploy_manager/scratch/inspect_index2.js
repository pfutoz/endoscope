const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/ip/index.php', 'utf8');

const matches = content.match(/<div[^>]*id="[^"]*Modal[^"]*"[^>]*>/gi);
console.log('Modals found:', matches);

const rdpIndex = content.indexOf('rdpModal');
if (rdpIndex !== -1) {
    console.log('rdpModal snippet:\n', content.substring(rdpIndex - 50, rdpIndex + 300));
}
