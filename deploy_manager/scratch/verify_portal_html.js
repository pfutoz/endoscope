const http = require('http');

http.get('http://192.168.1.16/index.html', res => {
    let data = '';
    res.on('data', c => data += c);
    res.on('end', () => {
        console.log('Status index.html:', res.statusCode);
        console.log('Contains /ip/?monitor=1:', data.includes('/ip/?monitor=1'));
        console.log('Contains 重要端末ヘルスチェック:', data.includes('重要端末ヘルスチェック'));
    });
});
