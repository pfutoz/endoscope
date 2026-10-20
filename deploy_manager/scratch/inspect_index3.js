const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/ip/index.php', 'utf8');

const rdpJsIndex = content.indexOf('function openRdpModal');
if (rdpJsIndex !== -1) {
    console.log('rdpModal JS snippet:\n', content.substring(rdpJsIndex, rdpJsIndex + 400));
}
