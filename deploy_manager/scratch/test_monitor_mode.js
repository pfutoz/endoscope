const http = require('http');

http.get('http://192.168.1.16/ip/?monitor=1', res => {
    let data = '';
    res.on('data', c => data += c);
    res.on('end', () => {
        console.log('Status:', res.statusCode);
        console.log('Has monitor banner:', data.includes('monitor-banner'));
        console.log('Has 監視端末 chip:', data.includes('監視端末'));
        const rows = data.match(/<tr[^>]*data-id=/g) || [];
        console.log('Count of table rows:', rows.length);
        const badges = data.match(/<span class="badge-health[^"]*"[^>]*>/g) || [];
        console.log('Count of health badges:', badges.length);
    });
});
