const fs = require('fs');
const content = fs.readFileSync('C:/Apache24/htdocs/index.html', 'utf8');

// aタグのリンクとテキストを抽出
const links = [];
const aRegex = /<a\s+[^>]*href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;
let m;
while ((m = aRegex.exec(content)) !== null) {
    const href = m[1];
    const text = m[2].replace(/<[^>]+>/g, '').trim();
    links.push({ href, text });
}

console.log('Total links:', links.length);
console.log('Links:', links);

// ip へのリンクがあるか
const ipLinks = links.filter(l => l.href.includes('ip'));
console.log('IP related links:', ipLinks);
