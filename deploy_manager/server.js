/**
 * 院内システム総合デプロイマネージャー (Deploy Manager Server)
 * 設置パス: c:\Apache24\htdocs\deploy_manager\server.js
 * 動作要件: Node.js (標準モジュールのみ・外部パッケージ不要)
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync, spawn } = require('child_process');

const PORT = 5050;
const LOCAL_HTDOCS = 'c:/Apache24/htdocs/';
const PROD_UNC = '//192.168.1.16/c$/Apache24/htdocs/';
const PROD_DRIVE = 'T:/';
const BACKUP_ROOT = 'c:/deploy_backups/';

// 本番パスの判定（Tドライブ または UNC）
function getProdBase(system) {
    if (fs.existsSync(path.join(PROD_DRIVE, system))) {
        return path.join(PROD_DRIVE, system);
    }
    return path.join(PROD_UNC, system);
}

// 本番サーバー自体の稼働チェック
function isProdOnline() {
    try {
        if (fs.existsSync(PROD_DRIVE)) return true;
        if (fs.existsSync(PROD_UNC)) return true;
    } catch (e) {}
    return false;
}

// 除外ディレクトリ
const EXCLUDE_DIRS = new Set([
    '.git', '.agents', '.gemini', 'node_modules', 'vendor', 
    'scratch', 'bak', 'ip_bak', 'gitshare', 'deploy_manager'
]);

function isExcludedFile(name) {
    return /(\.bak|\.tmp|\.log|test_.*\.js|scratch_.*\.js|Thumbs\.db|desktop\.ini)$/i.test(name);
}

// 再帰的ファイルスキャン
function scanDirectoryFiles(dirPath, baseDir = dirPath) {
    let results = [];
    if (!fs.existsSync(dirPath)) return results;

    const list = fs.readdirSync(dirPath, { withFileTypes: true });
    for (const item of list) {
        if (item.isDirectory()) {
            if (EXCLUDE_DIRS.has(item.name)) continue;
            results = results.concat(scanDirectoryFiles(path.join(dirPath, item.name), baseDir));
        } else if (item.isFile()) {
            if (isExcludedFile(item.name)) continue;
            const fullPath = path.join(dirPath, item.name);
            const relPath = path.relative(baseDir, fullPath).replace(/\\/g, '/');
            try {
                const stat = fs.statSync(fullPath);
                results.push({
                    relPath,
                    size: stat.size,
                    mtime: stat.mtimeMs,
                    mtimeStr: stat.mtime.toLocaleString('ja-JP')
                });
            } catch (e) {}
        }
    }
    return results;
}

// HTTPサーバー
const server = http.createServer((req, res) => {
    // CORS許可（ローカル開発用）
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }

    const parsedUrl = new URL(req.url, `http://localhost:${PORT}`);
    const pathname = parsedUrl.pathname;

    // ── API ルーティング ──

    // 0. 環境診断・ステータス取得
    if (pathname === '/api/diagnostics' && req.method === 'GET') {
        const prodDriveOk = fs.existsSync(PROD_DRIVE);
        const prodUncOk = fs.existsSync(PROD_UNC);
        const localHtdocsOk = fs.existsSync(LOCAL_HTDOCS);
        const backupRootOk = fs.existsSync(BACKUP_ROOT);
        const prodOnline = isProdOnline();

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({
            success: true,
            nodeVersion: process.version,
            whoami: process.env.USERNAME || process.env.USER || 'Unknown',
            port: PORT,
            serverUptimeSec: Math.floor(process.uptime()),
            localHtdocs: LOCAL_HTDOCS,
            localHtdocsOk,
            backupRoot: BACKUP_ROOT,
            backupRootOk,
            prodDrive: PROD_DRIVE,
            prodDriveOk,
            prodUnc: PROD_UNC,
            prodUncOk,
            prodOnline,
            activeProdPath: prodDriveOk ? 'T:/ (ネットワークドライブ)' : (prodUncOk ? 'UNC共有 (//192.168.1.16/c$/...)' : '未接続')
        }));
        return;
    }

    // 1. システム一覧取得
    if (pathname === '/api/systems' && req.method === 'GET') {
        const prodOnline = isProdOnline();
        const items = fs.readdirSync(LOCAL_HTDOCS, { withFileTypes: true });
        const systems = [];

        for (const item of items) {
            if (item.isDirectory() && !EXCLUDE_DIRS.has(item.name)) {
                const sysName = item.name;
                const localPath = path.join(LOCAL_HTDOCS, sysName);
                const prodPath = getProdBase(sysName);
                let prodExists = false;
                try {
                    prodExists = fs.existsSync(prodPath);
                } catch (e) {}

                systems.push({
                    name: sysName,
                    localPath: localPath.replace(/\\/g, '/'),
                    prodPath: prodPath.replace(/\\/g, '/'),
                    prodExists
                });
            }
        }

        // 優先度ソート
        const priority = { 'endoscope': 1, 'op': 2, 'karteview': 3, 'hiyarihat': 4, 'yotei': 5 };
        systems.sort((a, b) => {
            const pa = priority[a.name] || 99;
            const pb = priority[b.name] || 99;
            if (pa !== pb) return pa - pb;
            return a.name.localeCompare(b.name);
        });

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({ success: true, prodOnline, systems }));
        return;
    }

    // 2. 差分スキャン
    if (pathname === '/api/diff' && req.method === 'GET') {
        const system = parsedUrl.searchParams.get('system');
        if (!system || !/^[a-zA-Z0-9_\-]+$/.test(system)) {
            res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
            res.end(JSON.stringify({ success: false, message: '不正なシステム名です' }));
            return;
        }

        const localDir = path.join(LOCAL_HTDOCS, system);
        const prodDir = getProdBase(system);

        if (!fs.existsSync(localDir)) {
            res.writeHead(404, { 'Content-Type': 'application/json; charset=UTF-8' });
            res.end(JSON.stringify({ success: false, message: 'ローカルディレクトリが存在しません' }));
            return;
        }

        const localFiles = scanDirectoryFiles(localDir);
        const diffs = [];
        let stats = { new: 0, modified: 0, same: 0 };
        const prodExists = fs.existsSync(prodDir);

        for (const lf of localFiles) {
            const prodFilePath = path.join(prodDir, lf.relPath);
            let pStat = null;
            try {
                if (fs.existsSync(prodFilePath)) {
                    pStat = fs.statSync(prodFilePath);
                }
            } catch (e) {}

            if (!pStat) {
                diffs.push({
                    path: lf.relPath,
                    status: 'NEW',
                    localSize: lf.size,
                    localMtime: lf.mtimeStr,
                    prodSize: null,
                    prodMtime: null
                });
                stats.new++;
            } else {
                // 差分判定: サイズ相違または更新日時の差（2秒超）
                const isDiff = (lf.size !== pStat.size) || (Math.abs(lf.mtime - pStat.mtimeMs) > 2000);
                if (isDiff) {
                    diffs.push({
                        path: lf.relPath,
                        status: 'MODIFIED',
                        localSize: lf.size,
                        localMtime: lf.mtimeStr,
                        prodSize: pStat.size,
                        prodMtime: pStat.mtime.toLocaleString('ja-JP')
                    });
                    stats.modified++;
                } else {
                    stats.same++;
                }
            }
        }

        // ソート (MODIFIED -> NEW -> path順)
        diffs.sort((a, b) => {
            const order = { 'MODIFIED': 1, 'NEW': 2 };
            const diff = (order[a.status] || 9) - (order[b.status] || 9);
            if (diff !== 0) return diff;
            return a.path.localeCompare(b.path);
        });

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({
            success: true,
            system,
            prodExists,
            stats,
            totalLocalFiles: localFiles.length,
            diffCount: diffs.length,
            diffs
        }));
        return;
    }

    // 3. デプロイ実行 (自動バックアップ ➔ コピー)
    if (pathname === '/api/deploy' && req.method === 'POST') {
        let bodyRaw = '';
        req.on('data', chunk => bodyRaw += chunk);
        req.on('end', () => {
            try {
                const body = JSON.parse(bodyRaw);
                const system = body.system;
                const files = body.files || [];

                if (!system || files.length === 0) {
                    res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
                    res.end(JSON.stringify({ success: false, message: 'システムまたは配布ファイルが選択されていません' }));
                    return;
                }

                const localDir = path.join(LOCAL_HTDOCS, system);
                const prodDir = getProdBase(system);

                // 1. 本番ディレクトリ作成
                if (!fs.existsSync(prodDir)) {
                    fs.mkdirSync(prodDir, { recursive: true });
                }

                // 2. 事前ZIPバックアップ作成 (PowerShell Compress-Archive)
                const sysBackupDir = path.join(BACKUP_ROOT, system);
                if (!fs.existsSync(sysBackupDir)) {
                    fs.mkdirSync(sysBackupDir, { recursive: true });
                }

                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                const tsStr = `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
                const backupFilename = `${system}_backup_${tsStr}.zip`;
                const backupZipPath = path.join(sysBackupDir, backupFilename);

                // 本番に既存する対象ファイルを一時フォルダに退避してZIP化
                const tempStagingDir = path.join(sysBackupDir, `_staging_${tsStr}`);
                let backedUpCount = 0;

                for (const rel of files) {
                    const prodTarget = path.join(prodDir, rel);
                    if (fs.existsSync(prodTarget)) {
                        const stageTarget = path.join(tempStagingDir, rel);
                        fs.mkdirSync(path.dirname(stageTarget), { recursive: true });
                        fs.copyFileSync(prodTarget, stageTarget);
                        backedUpCount++;
                    }
                }

                let backupSuccess = false;
                if (backedUpCount > 0) {
                    try {
                        const psCmd = `Compress-Archive -Path '${tempStagingDir}/*' -DestinationPath '${backupZipPath}' -Force`;
                        execSync(`powershell -NoProfile -Command "${psCmd}"`);
                        backupSuccess = fs.existsSync(backupZipPath);
                    } catch (e) {
                        console.error('Backup ZIP creation error:', e);
                    } finally {
                        // 一時ステージングフォルダ削除
                        try {
                            fs.rmSync(tempStagingDir, { recursive: true, force: true });
                        } catch (e) {}
                    }

                    if (!backupSuccess) {
                        res.writeHead(500, { 'Content-Type': 'application/json; charset=UTF-8' });
                        res.end(JSON.stringify({ success: false, message: '本番ファイルの事前ZIPバックアップに失敗したため、デプロイを中断しました' }));
                        return;
                    }
                }

                // 3. 安全コピー
                const copiedFiles = [];
                const failedFiles = [];

                for (const rel of files) {
                    const src = path.join(localDir, rel);
                    const dest = path.join(prodDir, rel);

                    if (!fs.existsSync(src)) {
                        failedFiles.push({ path: rel, error: 'ローカルファイルが存在しません' });
                        continue;
                    }

                    try {
                        fs.mkdirSync(path.dirname(dest), { recursive: true });
                        fs.copyFileSync(src, dest);
                        const srcStat = fs.statSync(src);
                        fs.utimesSync(dest, srcStat.atime, srcStat.mtime);
                        copiedFiles.push({
                            path: rel,
                            size: srcStat.size,
                            mtime: srcStat.mtime.toLocaleString('ja-JP')
                        });
                    } catch (err) {
                        failedFiles.push({ path: rel, error: err.message });
                    }
                }

                const allSuccess = failedFiles.length === 0;
                res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({
                    success: allSuccess,
                    message: allSuccess
                        ? `${copiedFiles.length} 件のファイルを本番サーバーへ安全に配布しました`
                        : `${copiedFiles.length} 件成功、${failedFiles.length} 件失敗しました`,
                    backupFilename: backupSuccess ? backupFilename : null,
                    backedUpCount,
                    copiedCount: copiedFiles.length,
                    failedCount: failedFiles.length,
                    copiedFiles,
                    failedFiles
                }));
            } catch (err) {
                res.writeHead(500, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({ success: false, message: '処理エラー: ' + err.message }));
            }
        });
        return;
    }

    // 4. バックアップ履歴一覧
    if (pathname === '/api/backups' && req.method === 'GET') {
        const system = parsedUrl.searchParams.get('system');
        if (!system) {
            res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
            res.end(JSON.stringify({ success: false, message: 'システム名を指定してください' }));
            return;
        }

        const sysBackupDir = path.join(BACKUP_ROOT, system);
        const backups = [];

        if (fs.existsSync(sysBackupDir)) {
            const files = fs.readdirSync(sysBackupDir);
            for (const f of files) {
                if (/\.zip$/i.test(f)) {
                    const fp = path.join(sysBackupDir, f);
                    try {
                        const stat = fs.statSync(fp);
                        backups.push({
                            filename: f,
                            size: stat.size,
                            sizeFormatted: (stat.size / 1024).toFixed(1) + ' KB',
                            mtime: stat.mtime.toLocaleString('ja-JP')
                        });
                    } catch (e) {}
                }
            }
            backups.sort((a, b) => b.filename.localeCompare(a.filename));
        }

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({ success: true, system, backups }));
        return;
    }

    // 5. ワンクリック・ロールバック
    if (pathname === '/api/rollback' && req.method === 'POST') {
        let bodyRaw = '';
        req.on('data', chunk => bodyRaw += chunk);
        req.on('end', () => {
            try {
                const body = JSON.parse(bodyRaw);
                const system = body.system;
                const zipFilename = path.basename(body.zipFilename || '');

                if (!system || !zipFilename) {
                    res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
                    res.end(JSON.stringify({ success: false, message: 'パラメータが不足しています' }));
                    return;
                }

                const zipPath = path.join(BACKUP_ROOT, system, zipFilename);
                if (!fs.existsSync(zipPath)) {
                    res.writeHead(404, { 'Content-Type': 'application/json; charset=UTF-8' });
                    res.end(JSON.stringify({ success: false, message: 'バックアップファイルが見つかりません' }));
                    return;
                }

                const prodDir = getProdBase(system);
                if (!fs.existsSync(prodDir)) {
                    fs.mkdirSync(prodDir, { recursive: true });
                }

                // PowerShell Expand-Archive で本番へ直接上書き展開
                const psCmd = `Expand-Archive -Path '${zipPath}' -DestinationPath '${prodDir}' -Force`;
                execSync(`powershell -NoProfile -Command "${psCmd}"`);

                res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({
                    success: true,
                    message: `バックアップ「${zipFilename}」から本番サーバーへのロールバック（復元）が完了しました`
                }));
            } catch (err) {
                res.writeHead(500, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({ success: false, message: 'ロールバック処理失敗: ' + err.message }));
            }
        });
        return;
    }

    // ── 静的ファイル配信 (index.html, style.css, app.js, 仕様書など) ──
    let decodedPath = pathname;
    try {
        decodedPath = decodeURIComponent(pathname);
    } catch (e) {}

    let filePath = path.join(__dirname, decodedPath === '/' ? 'index.html' : decodedPath);
    if (fs.existsSync(filePath) && fs.statSync(filePath).isDirectory()) {
        filePath = path.join(filePath, 'index.html');
    }
    if (!fs.existsSync(filePath)) {
        filePath = path.join(__dirname, 'index.html');
    }

    if (fs.existsSync(filePath)) {
        const ext = path.extname(filePath).toLowerCase();
        const mimeTypes = {
            '.html': 'text/html; charset=UTF-8',
            '.css': 'text/css; charset=UTF-8',
            '.js': 'text/javascript; charset=UTF-8',
            '.json': 'application/json; charset=UTF-8',
            '.png': 'image/png',
            '.ico': 'image/x-icon'
        };
        const contentType = mimeTypes[ext] || 'application/octet-stream';
        res.writeHead(200, { 'Content-Type': contentType });
        fs.createReadStream(filePath).pipe(res);
    } else {
        res.writeHead(404, { 'Content-Type': 'text/plain; charset=UTF-8' });
        res.end('Not Found');
    }
});

server.listen(PORT, '127.0.0.1', () => {
    console.log(`[Deploy Manager] Server running at http://localhost:${PORT}/`);
});
