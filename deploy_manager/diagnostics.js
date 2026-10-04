/**
 * 院内システム総合デプロイマネージャー 環境自己診断ツール (CLI版)
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const green = (s) => `\x1b[32m${s}\x1b[0m`;
const red = (s) => `\x1b[31m${s}\x1b[0m`;
const yellow = (s) => `\x1b[33m${s}\x1b[0m`;
const cyan = (s) => `\x1b[36m${s}\x1b[0m`;
const bold = (s) => `\x1b[1m${s}\x1b[0m`;

console.log(cyan('======================================================================'));
console.log(cyan('  医療法人小野会 院内システム総合デプロイマネージャー'));
console.log(cyan('  【環境自己診断 ＆ 前提条件チェックツール】'));
console.log(cyan('======================================================================\n'));

let hasError = false;

// 1. Node.js バージョン
console.log(bold('[1/6] Node.js 実行環境の確認:'));
console.log(`   -> ${green('[OK]')} Node.js ${process.version} 正常稼働中 (実行ユーザー: ${process.env.USERNAME || '一般ユーザー'})\n`);

// 2. 支援サーバー (ポート 5050) の稼働確認
console.log(bold('[2/6] ローカル支援サーバー (ポート 5050) の稼働確認:'));
try {
    const netstatOut = execSync('netstat -ano -p tcp', { encoding: 'utf-8' });
    const isListening = /:5050\s+.*LISTENING/i.test(netstatOut);
    if (isListening) {
        console.log(`   -> ${green('[OK]')} ポート 5050 で支援サーバーが正常待機中 (LISTENING)\n`);
    } else {
        console.log(`   -> ${red('[NG]')} 支援サーバー (server.js) が起動していません。`);
        console.log(yellow('          対処法: start_deploy_manager.bat を実行して起動してください。'));
        console.log(yellow('          または register_startup.bat でWindows起動時自動常駐を設定してください。\n'));
        hasError = true;
    }
} catch (e) {
    console.log(`   -> ${yellow('[注意]')} ポート確認コマンドの実行で例外が発生しました: ${e.message}\n`);
}

// 3. 本番サーバー (T: ドライブ) マウント確認
console.log(bold('[3/6] 本番サーバー (T: ドライブ) のマウント確認:'));
const prodDrive = 'T:/';
if (fs.existsSync(prodDrive)) {
    console.log(`   -> ${green('[OK]')} 本番サーバー htdocs (T:\\) が正常に認識されています。\n`);
} else {
    console.log(`   -> ${red('[NG]')} T: ドライブが見つかりません！`);
    console.log(yellow('          本システムは本番ファイルを T:\\<system> として読み書き・差分比較します。'));
    console.log(yellow('          対処法: エクスプローラーで「ネットワークドライブの割り当て」を開き、'));
    console.log(yellow('                  フォルダー: \\\\192.168.1.16\\c$\\Apache24\\htdocs'));
    console.log(yellow('                  ドライブ文字: T:'));
    console.log(yellow('                  「サインイン時に再接続する」にチェックを入れて完了してください。\n'));
    hasError = true;
}

// 4. 本番サーバー UNC 共有確認
console.log(bold('[4/6] 本番サーバー UNC 共有 (\\\\192.168.1.16) の疎通確認:'));
const prodUnc = '//192.168.1.16/c$/Apache24/htdocs/';
if (fs.existsSync(prodUnc)) {
    console.log(`   -> ${green('[OK]')} 本番UNCパス (\\\\192.168.1.16\\c$\\Apache24\\htdocs) 疎通確認完了\n`);
} else {
    console.log(`   -> ${yellow('[注意]')} UNCパスへの直接アクセスが制限されています (T: ドライブ接続があれば正常に動作可能です)。\n`);
}

// 5. ローカル開発環境確認
console.log(bold('[5/6] ローカル開発ディレクトリ (c:\\Apache24\\htdocs) の確認:'));
const localHtdocs = 'c:/Apache24/htdocs/';
if (fs.existsSync(localHtdocs)) {
    console.log(`   -> ${green('[OK]')} c:\\Apache24\\htdocs を確認しました。\n`);
} else {
    console.log(`   -> ${red('[NG]')} c:\\Apache24\\htdocs が存在しません。\n`);
    hasError = true;
}

// 6. バックアップ保存先確認
console.log(bold('[6/6] 自動バックアップ保存先 (c:\\deploy_backups) の確認:'));
const backupRoot = 'c:/deploy_backups/';
if (fs.existsSync(backupRoot)) {
    console.log(`   -> ${green('[OK]')} c:\\deploy_backups が存在します。\n`);
} else {
    console.log(`   -> ${cyan('[情報]')} 未作成です (初回の本番デプロイ時に自動作成されます)。\n`);
}

console.log(cyan('======================================================================'));
if (!hasError) {
    console.log(green(bold('  【診断結果: すべて正常 (PASS)】')));
    console.log(green('  デプロイマネージャーのすべての前提条件を満たしています。'));
    console.log(green('  ブラウザから安全に本番配布およびバックアップ復元が行えます。'));
} else {
    console.log(red(bold('  【診断結果: 要確認項目あり (CHECK)】')));
    console.log(yellow('  上記の [NG] と表示された項目について、記載の対処法をご確認ください。'));
    console.log(yellow('  詳しい設定手順は README_SETUP.md にも記載されています。'));
}
console.log(cyan('======================================================================\n'));
