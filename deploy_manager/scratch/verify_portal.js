const http = require('http');

http.get('http://192.168.1.16/', res => {
    let data = '';
    res.on('data', c => data += c);
    res.on('end', () => {
        console.log('Status:', res.statusCode);
        console.log('Contains /ip/?monitor=1:', data.includes('/ip/?monitor=1'));
        console.log('Contains 重要端末ヘルスチェック:', data.includes('重要端末ヘルスチェック'));
        console.log('Contains header link 💻 重要端末監視:', data.includes('💻 重要端末監視'));
        
        // カード部分のスニペット
        const idx = data.indexOf('重要端末ヘルスチェック');
        if (idx !== -1) {
            console.log('Card snippet:\n', data.substring(idx - 60, idx + 250));
        }
    });
});
