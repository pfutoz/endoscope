/**
 * 院内システム総合デプロイマネージャー 起動スクリプト (CLI / Launcher)
 */
const fs = require('fs');
const path = require('path');
const { spawn, execSync, exec } = require('child_process');

const green = (s) => `\x1b[32m${s}\x1b[0m`;
const red = (s) => `\x1b[31m${s}\x1b[0m`;
const yellow = (s) => `\x1b[33m${s}\x1b[0m`;
const cyan = (s) => `\x1b[36m${s}\x1b[0m`;
const bold = (s) => `\x1b[1m${s}\x1b[0m`;

console.log(cyan('======================================================================'));
console.log(cyan('  医療法人小野会 院内システム総合デプロイマネージャー 起動'));
console.log(cyan('  (Onokai System Deploy & Rollback Manager)'));
console.log(cyan('======================================================================\n'));

// 1. 本番サーバー (T: ドライブ) のマウント確認
console.log('[1/3] 本番サーバー (192.168.1.16) の共有確認中...');
if (fs.existsSync('T:/')) {
    console.log(`   -> ${green('[OK]')} 本番サーバー T: ドライブ (Apache24\\htdocs) 認識完了\n`);
} else {
    console.log(`   -> ${yellow('[注意]')} 本番サーバーの「T:」ドライブが見つかりません。`);
    console.log(yellow('      前提条件: \\\\192.168.1.16\\c$\\Apache24\\htdocs を'));
    console.log(yellow('      ネットワークドライブ「T:」として割り当ててください。\n'));
}

// 2. 支援サーバー稼働確認 (ポート 5050)
console.log('[2/3] ローカル支援サーバーの稼働確認中 (ポート 5050)...');
let isRunning = false;
try {
    const netstatOut = execSync('netstat -ano -p tcp', { encoding: 'utf-8' });
    isRunning = /:5050\s+.*LISTENING/i.test(netstatOut);
} catch (e) {}

if (isRunning) {
    console.log(`   -> ${green('[OK]')} 支援サーバーは既にバックグラウンドで稼働しています。\n`);
} else {
    console.log('   -> 支援サーバーをバックグラウンドで起動します...');
    try {
        const vbsPath = path.join(__dirname, 'startup_launcher.vbs');
        if (fs.existsSync(vbsPath)) {
            execSync(`wscript.exe "${vbsPath}"`);
        } else {
            const child = spawn('node', [path.join(__dirname, 'server.js')], {
                detached: true,
                stdio: 'ignore',
                cwd: __dirname,
                windowsHide: true
            });
            child.unref();
        }
        console.log(`   -> ${green('[OK]')} バックグラウンド常駐起動が完了しました。\n`);
    } catch (e) {
        console.log(`   -> ${red('[エラー]')} サーバー起動に失敗しました: ${e.message}\n`);
    }
}

// 3. ブラウザ起動
console.log('[3/3] ブラウザでデプロイマネージャーを開きます...');
exec('start http://localhost:5050/');

console.log(cyan('\n======================================================================'));
console.log(green(bold('  起動完了！ブラウザの画面から安全に本番配布が行えます。')));
console.log('');
console.log('  ※この起動ウィンドウは閉じていただいて構いません。');
console.log('    (支援サーバーはバックグラウンドで動作を継続します)');
console.log(cyan('======================================================================\n'));

setTimeout(() => {
    process.exit(0);
}, 2000);
