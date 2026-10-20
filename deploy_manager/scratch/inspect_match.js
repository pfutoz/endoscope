const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/ip/index.php', 'utf8');

const filterIdx = content.indexOf('$filtered = match (true)');
if (filterIdx !== -1) {
    console.log(content.substring(filterIdx, filterIdx + 800));
}
