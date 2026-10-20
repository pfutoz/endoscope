const http = require('http');

http.get('http://192.168.1.16/ip/', res => {
    let data = '';
    res.on('data', chunk => data += chunk);
    res.on('end', () => {
        const matches = [...data.matchAll(/<span class="badge-health[^"]*"[^>]*>[\s\S]*?<\/span>/g)];
        console.log('Total badges rendered:', matches.length);
        matches.forEach((m, i) => {
            console.log(`Badge [${i}]:`, m[0]);
        });
    });
});
