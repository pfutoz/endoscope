/**
 * 院内システム総合デプロイマネージャー 自動常駐セットアップ (CLI)
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const green = (s) => `\x1b[32m${s}\x1b[0m`;
const red = (s) => `\x1b[31m${s}\x1b[0m`;
const cyan = (s) => `\x1b[36m${s}\x1b[0m`;
const bold = (s) => `\x1b[1m${s}\x1b[0m`;

const mode = process.argv[2]; // '--register' or '--unregister'
const appData = process.env.APPDATA;
const startupDir = path.join(appData, 'Microsoft', 'Windows', 'Start Menu', 'Programs', 'Startup');
const destFile = path.join(startupDir, 'Onokai_Deploy_Manager_Daemon.vbs');
const srcFile = path.join(__dirname, 'startup_launcher.vbs');

if (mode === '--unregister') {
    console.log(cyan('======================================================================'));
    console.log(cyan('  医療法人小野会 院内システム総合デプロイマネージャー 【常駐解除】'));
    console.log(cyan('======================================================================\n'));

    if (fs.existsSync(destFile)) {
        try {
            fs.unlinkSync(destFile);
            console.log(green('  [成功] スタートアップ自動常駐スクリプトを削除しました。'));
            console.log('         次回以降のWindows起動時に自動起動しなくなります。\n');
        } catch (e) {
            console.log(red(`  [エラー] 削除に失敗しました: ${e.message}\n`));
        }
    } else {
        console.log('  [情報] スタートアップに常駐スクリプトは登録されていませんでした。\n');
    }
    console.log(cyan('======================================================================\n'));
    process.exit(0);
}

// デフォルトは登録 (--register)
console.log(cyan('======================================================================'));
console.log(cyan('  医療法人小野会 院内システム総合デプロイマネージャー 【自動常駐登録】'));
console.log(cyan('======================================================================\n'));

try {
    fs.copyFileSync(srcFile, destFile);
    console.log(green('  [1/2] スタートアップへの登録に成功しました！'));
    console.log(`        配置先: ${destFile}\n`);
} catch (e) {
    console.log(red(`  [エラー] スタートアップ登録に失敗しました: ${e.message}`));
    console.log('          手動で startup_launcher.vbs をスタートアップフォルダに配置してください。\n');
    process.exit(1);
}

// 現在の稼働確認と即時起動
console.log('  [2/2] 支援サーバー (ポート 5050) の現在稼働確認中...');
let isListening = false;
try {
    const netstatOut = execSync('netstat -ano -p tcp', { encoding: 'utf-8' });
    isListening = /:5050\s+.*LISTENING/i.test(netstatOut);
} catch (e) {}

if (isListening) {
    console.log(green('        -> サーバーは既にポート 5050 で稼働しています。\n'));
} else {
    try {
        execSync(`wscript.exe "${destFile}"`);
        console.log(green('        -> 支援サーバーをバックグラウンドで起動しました (ポート 5050)。\n'));
    } catch (e) {
        console.log(red(`        -> サーバー起動で例外が発生しました: ${e.message}\n`));
    }
}

console.log(cyan('======================================================================'));
console.log(green(bold('  【設定完了】')));
console.log('  次回からWindows起動時に自動でサーバーが常駐します。');
console.log('  ブラウザのブックマークからいつでも即座にご利用いただけます。');
console.log('  ※常駐を解除したい場合は unregister_startup.bat を実行してください。');
console.log(cyan('======================================================================\n'));
