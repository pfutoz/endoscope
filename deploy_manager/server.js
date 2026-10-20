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
    if (system === '_portal') {
        if (fs.existsSync(PROD_DRIVE)) return PROD_DRIVE;
        return PROD_UNC;
    }
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

// 本番Webサーバーの即時HTTP Ping測定
function pingProdWebServer(timeoutMs = 2500) {
    return new Promise((resolve) => {
        const startTime = Date.now();
        const req = http.get('http://192.168.1.16/', { timeout: timeoutMs }, (res) => {
            const latencyMs = Date.now() - startTime;
            res.resume();
            resolve({
                reachable: true,
                statusCode: res.statusCode,
                latencyMs,
                error: null
            });
        });
        req.on('timeout', () => {
            req.destroy();
            resolve({
                reachable: false,
                statusCode: null,
                latencyMs: Date.now() - startTime,
                error: '接続タイムアウト (2.5秒超過)'
            });
        });
        req.on('error', (err) => {
            resolve({
                reachable: false,
                statusCode: null,
                latencyMs: Date.now() - startTime,
                error: err.message
            });
        });
    });
}

// 本番PHP API経由のリモートヘルス取得 / 更新
function fetchRemoteHealthApi(refresh = false, timeoutMs = 8000) {
    return new Promise((resolve) => {
        const url = `http://192.168.1.16/deploy_manager/server_agent/server_health_api.php${refresh ? '?refresh=1' : ''}`;
        const req = http.get(url, { timeout: timeoutMs }, (res) => {
            let data = '';
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => {
                try {
                    const parsed = JSON.parse(data);
                    resolve(parsed);
                } catch (e) {
                    resolve(null);
                }
            });
        });
        req.on('timeout', () => { req.destroy(); resolve(null); });
        req.on('error', () => { resolve(null); });
    });
}


// ── 除外ルール管理エンジン (Ver 2.2) ──
const CONFIG_PATH = path.join(__dirname, 'exclude_rules.json');

// スキップ対象インフラディレクトリ（探索の負荷を防止）
const BASE_EXCLUDE_DIRS = new Set([
    '.git', '.agents', '.gemini', 'node_modules', 'vendor', 
    'scratch', 'bak', 'ip_bak', 'gitshare', 'deploy_manager'
]);
const EXCLUDE_DIRS = BASE_EXCLUDE_DIRS;

const DEFAULT_GLOBAL_RULES = [
    '.git/**', '.agents/**', '.gemini/**', 'node_modules/**', 'vendor/**',
    'scratch/**', 'bak/**', 'ip_bak/**', 'gitshare/**', 'deploy_manager/**',
    '*.bak*', '*.tmp', '*.log', '*.code-workspace', 'test_*.js', 'scratch_*.js',
    'Thumbs.db', 'desktop.ini'
];

function loadExcludeConfig() {
    try {
        if (fs.existsSync(CONFIG_PATH)) {
            const raw = fs.readFileSync(CONFIG_PATH, 'utf-8');
            const data = JSON.parse(raw);
            if (!Array.isArray(data.global)) data.global = [...DEFAULT_GLOBAL_RULES];
            if (!data.systems || typeof data.systems !== 'object') data.systems = {};
            return data;
        }
    } catch (e) {
        console.error('Error loading exclude_rules.json:', e);
    }
    const defaultData = { global: [...DEFAULT_GLOBAL_RULES], systems: {} };
    saveExcludeConfig(defaultData);
    return defaultData;
}

function saveExcludeConfig(data) {
    try {
        fs.writeFileSync(CONFIG_PATH, JSON.stringify(data, null, 2), 'utf-8');
        return true;
    } catch (e) {
        console.error('Error saving exclude_rules.json:', e);
        return false;
    }
}

// 単一ルールのマッチング判定
function testSingleRule(normalizedRel, filename, rule) {
    if (!rule || typeof rule !== 'string') return false;
    const r = rule.trim().replace(/\\/g, '/').replace(/^\/+/, '');
    if (!r) return false;

    const normLower = normalizedRel.toLowerCase();
    const fileLower = filename.toLowerCase();
    const ruleLower = r.toLowerCase();

    // 1. 相対パスまたはファイル名との完全一致
    if (normLower === ruleLower || fileLower === ruleLower) {
        return true;
    }

    // 2. ディレクトリプレフィックス指定 (例: "cache/**", "uploads/*", "temp/")
    if (r.endsWith('/**') || r.endsWith('/*') || r.endsWith('/')) {
        const prefix = r.replace(/(\/\*\*|\/\*|\/)$/, '') + '/';
        const prefixLower = prefix.toLowerCase();
        if (normLower.startsWith(prefixLower) || normLower === prefixLower.slice(0, -1)) {
            return true;
        }
    }

    // 3. ワイルドカード・正規表現 (* または ?)
    if (r.includes('*') || r.includes('?')) {
        if (r.includes('/')) {
            // パスを含むワイルドカード (例: config/*.php, dir/**/file.ext)
            const regexStr = '^' + r
                .replace(/[-[\]{}()+.,\\^$|#\s]/g, '\\$&')
                .replace(/\*\*/g, '.*')
                .replace(/\*/g, '[^/]*')
                .replace(/\?/g, '.') + '$';
            try {
                if (new RegExp(regexStr, 'i').test(normalizedRel)) return true;
            } catch (e) {}
        } else {
            // ファイル名に対するワイルドカード (例: *.log, *.env*, test_*)
            const regexStr = '^' + r
                .replace(/[-[\]{}()+.,\\^$|#\s]/g, '\\$&')
                .replace(/\*/g, '.*')
                .replace(/\?/g, '.') + '$';
            try {
                if (new RegExp(regexStr, 'i').test(filename)) return true;
            } catch (e) {}
        }
    }

    return false;
}

// システムおよびグローバルの除外判定
function checkFileExclusion(relPath, system, config = null) {
    const cfg = config || loadExcludeConfig();
    const normalized = relPath.replace(/\\/g, '/').replace(/^\/+/, '');
    const filename = path.basename(normalized);

    // 1. 対象システムの個別ルールを優先チェック
    const sysRules = (cfg.systems && Array.isArray(cfg.systems[system])) ? cfg.systems[system] : [];
    for (const rule of sysRules) {
        if (testSingleRule(normalized, filename, rule)) {
            return { isExcluded: true, rule, scope: 'system' };
        }
    }

    // 2. 全システム共通ルールをチェック
    const globalRules = Array.isArray(cfg.global) ? cfg.global : DEFAULT_GLOBAL_RULES;
    for (const rule of globalRules) {
        if (testSingleRule(normalized, filename, rule)) {
            return { isExcluded: true, rule, scope: 'global' };
        }
    }

    return { isExcluded: false, rule: null, scope: null };
}

// ルート直下ファイル専用スキャン（サブディレクトリは辿らない）
function scanRootFiles(dirPath) {
    let results = [];
    if (!fs.existsSync(dirPath)) return results;

    const list = fs.readdirSync(dirPath, { withFileTypes: true });
    for (const item of list) {
        if (item.isFile()) {
            const fullPath = path.join(dirPath, item.name);
            try {
                const stat = fs.statSync(fullPath);
                results.push({
                    relPath: item.name,
                    size: stat.size,
                    mtime: stat.mtimeMs,
                    mtimeStr: stat.mtime.toLocaleString('ja-JP')
                });
            } catch (e) {}
        }
    }
    return results;
}

// 再帰的ファイルスキャン (各システム用)
function scanDirectoryFiles(dirPath, baseDir = dirPath) {
    let results = [];
    if (!fs.existsSync(dirPath)) return results;

    const list = fs.readdirSync(dirPath, { withFileTypes: true });
    for (const item of list) {
        if (item.isDirectory()) {
            if (BASE_EXCLUDE_DIRS.has(item.name)) continue;
            results = results.concat(scanDirectoryFiles(path.join(dirPath, item.name), baseDir));
        } else if (item.isFile()) {
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
const server = http.createServer(async (req, res) => {
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
            version: '2.2.0',
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

    // 0-B. 本番サーバー総合ヘルス (SMART / 空き容量 / メモリ / CPU / Web即時Ping)
    if (pathname === '/api/server-health' && req.method === 'GET') {
        const doRefresh = parsedUrl.searchParams.get('refresh') === '1';

        // 1. Web サーバー即時 Ping
        const webPing = await pingProdWebServer();

        // 2. ヘルスJSON ファイルの探索
        const candidates = [
            path.join(PROD_DRIVE, '_server_health.json'),
            path.join(PROD_UNC, '_server_health.json')
        ];
        let healthData = null;
        let filePath = null;
        let fileStat = null;

        // リフレッシュ要求かつWeb疎通可能な場合は、まずリモートAPIを叩いて更新を試みる
        if (doRefresh && webPing.reachable) {
            const refreshed = await fetchRemoteHealthApi(true);
            if (refreshed && refreshed.storage) {
                healthData = refreshed;
            }
        }

        if (!healthData) {
            for (const p of candidates) {
                try {
                    if (fs.existsSync(p)) {
                        const content = fs.readFileSync(p, 'utf-8');
                        healthData = JSON.parse(content);
                        filePath = p;
                        fileStat = fs.statSync(p);
                        break;
                    }
                } catch (e) {}
            }
        }

        // ファイルから読めずWeb疎通可能な場合はリモートAPIから直接取得を試行
        if (!healthData && webPing.reachable) {
            healthData = await fetchRemoteHealthApi(false);
        }

        let fileAgeSec = null;
        let isStale = false;
        if (fileStat) {
            fileAgeSec = Math.floor((Date.now() - fileStat.mtimeMs) / 1000);
            isStale = (fileAgeSec > 900); // 15分以上更新なし
        }

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({
            success: true,
            hasData: !!healthData,
            webPing,
            health: healthData,
            filePath,
            fileAgeSec,
            isStale
        }));
        return;
    }


    // 1. システム一覧取得
    if (pathname === '/api/systems' && req.method === 'GET') {
        const prodOnline = isProdOnline();
        const items = fs.readdirSync(LOCAL_HTDOCS, { withFileTypes: true });
        const systems = [];

        // ルート (htdocs 直下の index.php 等) を「_portal」として先頭に追加
        const portalProdPath = getProdBase('_portal');
        let portalProdExists = false;
        try {
            portalProdExists = fs.existsSync(portalProdPath);
        } catch (e) {}

        systems.push({
            name: '_portal',
            displayName: '🏠 ポータル (htdocs/index.php)',
            localPath: LOCAL_HTDOCS.replace(/\\/g, '/'),
            prodPath: portalProdPath.replace(/\\/g, '/'),
            prodExists: portalProdExists,
            isRoot: true
        });

        for (const item of items) {
            if (item.isDirectory() && !BASE_EXCLUDE_DIRS.has(item.name)) {
                const sysName = item.name;
                const localPath = path.join(LOCAL_HTDOCS, sysName);
                const prodPath = getProdBase(sysName);
                let prodExists = false;
                try {
                    prodExists = fs.existsSync(prodPath);
                } catch (e) {}

                systems.push({
                    name: sysName,
                    displayName: sysName,
                    localPath: localPath.replace(/\\/g, '/'),
                    prodPath: prodPath.replace(/\\/g, '/'),
                    prodExists,
                    isRoot: false
                });
            }
        }

        // 優先度ソート
        const priority = { '_portal': 0, 'endoscope': 1, 'op': 2, 'karteview': 3, 'hiyarihat': 4, 'yotei': 5 };
        systems.sort((a, b) => {
            const pa = priority[a.name] !== undefined ? priority[a.name] : 99;
            const pb = priority[b.name] !== undefined ? priority[b.name] : 99;
            if (pa !== pb) return pa - pb;
            return a.name.localeCompare(b.name);
        });

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({ success: true, prodOnline, systems }));
        return;
    }

    // 2. 差分スキャン (除外ルール判定・ステータス分類)
    if (pathname === '/api/diff' && req.method === 'GET') {
        const system = parsedUrl.searchParams.get('system');
        if (!system || !/^[a-zA-Z0-9_\-]+$/.test(system)) {
            res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
            res.end(JSON.stringify({ success: false, message: '不正なシステム名です' }));
            return;
        }

        const isRoot = (system === '_portal');
        const localDir = isRoot ? LOCAL_HTDOCS : path.join(LOCAL_HTDOCS, system);
        const prodDir = getProdBase(system);

        if (!fs.existsSync(localDir)) {
            res.writeHead(404, { 'Content-Type': 'application/json; charset=UTF-8' });
            res.end(JSON.stringify({ success: false, message: 'ローカルディレクトリが存在しません' }));
            return;
        }

        const excludeConfig = loadExcludeConfig();
        const localFiles = isRoot ? scanRootFiles(localDir) : scanDirectoryFiles(localDir);
        const diffs = [];
        let stats = { new: 0, modified: 0, same: 0, excluded: 0 };
        const prodExists = fs.existsSync(prodDir);

        for (const lf of localFiles) {
            const prodFilePath = path.join(prodDir, lf.relPath);
            let pStat = null;
            try {
                if (fs.existsSync(prodFilePath)) {
                    pStat = fs.statSync(prodFilePath);
                }
            } catch (e) {}

            const chk = checkFileExclusion(lf.relPath, system, excludeConfig);

            if (!pStat) {
                // 本番に存在しない = 新規ファイル
                if (chk.isExcluded) {
                    stats.excluded++;
                    diffs.push({
                        path: lf.relPath,
                        status: 'NEW',
                        localSize: lf.size,
                        localMtime: lf.mtimeStr,
                        prodSize: null,
                        prodMtime: null,
                        isExcluded: true,
                        excludeRule: chk.rule,
                        excludeScope: chk.scope
                    });
                } else {
                    stats.new++;
                    diffs.push({
                        path: lf.relPath,
                        status: 'NEW',
                        localSize: lf.size,
                        localMtime: lf.mtimeStr,
                        prodSize: null,
                        prodMtime: null,
                        isExcluded: false,
                        excludeRule: null,
                        excludeScope: null
                    });
                }
            } else {
                // 本番に存在する = 差分判定 (サイズまたは更新日時2秒超)
                const isDiff = (lf.size !== pStat.size) || (Math.abs(lf.mtime - pStat.mtimeMs) > 2000);
                if (isDiff) {
                    if (chk.isExcluded) {
                        stats.excluded++;
                        diffs.push({
                            path: lf.relPath,
                            status: 'MODIFIED',
                            localSize: lf.size,
                            localMtime: lf.mtimeStr,
                            prodSize: pStat.size,
                            prodMtime: pStat.mtime.toLocaleString('ja-JP'),
                            isExcluded: true,
                            excludeRule: chk.rule,
                            excludeScope: chk.scope
                        });
                    } else {
                        stats.modified++;
                        diffs.push({
                            path: lf.relPath,
                            status: 'MODIFIED',
                            localSize: lf.size,
                            localMtime: lf.mtimeStr,
                            prodSize: pStat.size,
                            prodMtime: pStat.mtime.toLocaleString('ja-JP'),
                            isExcluded: false,
                            excludeRule: null,
                            excludeScope: null
                        });
                    }
                } else {
                    stats.same++;
                }
            }
        }

        // ソート (有効なMODIFIED -> 有効なNEW -> 除外ファイル, それぞれパス順)
        diffs.sort((a, b) => {
            if (a.isExcluded !== b.isExcluded) {
                return a.isExcluded ? 1 : -1;
            }
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
            activeDiffCount: stats.new + stats.modified,
            excludedCount: stats.excluded,
            diffs
        }));
        return;
    }

    // 2-B. 除外ルール一覧取得
    if (pathname === '/api/exclude-rules' && req.method === 'GET') {
        const system = parsedUrl.searchParams.get('system') || '_portal';
        const cfg = loadExcludeConfig();
        const systemRules = (cfg.systems && Array.isArray(cfg.systems[system])) ? cfg.systems[system] : [];
        const globalRules = Array.isArray(cfg.global) ? cfg.global : DEFAULT_GLOBAL_RULES;

        res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
        res.end(JSON.stringify({
            success: true,
            system,
            systemRules,
            globalRules,
            allSystems: Object.keys(cfg.systems || {})
        }));
        return;
    }

    // 2-C. 除外ルール一括保存
    if (pathname === '/api/exclude-rules' && req.method === 'POST') {
        let bodyRaw = '';
        req.on('data', chunk => bodyRaw += chunk);
        req.on('end', () => {
            try {
                const body = JSON.parse(bodyRaw);
                const system = body.system;
                const cfg = loadExcludeConfig();

                if (Array.isArray(body.globalRules)) {
                    cfg.global = body.globalRules.map(s => String(s).trim()).filter(Boolean);
                }
                if (system && Array.isArray(body.systemRules)) {
                    if (!cfg.systems) cfg.systems = {};
                    cfg.systems[system] = body.systemRules.map(s => String(s).trim()).filter(Boolean);
                }

                saveExcludeConfig(cfg);
                res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({ success: true, message: '除外ルールを更新・保持しました', config: cfg }));
            } catch (e) {
                res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({ success: false, message: e.message }));
            }
        });
        return;
    }

    // 2-D. 除外ルール単一追加 (差分行からのクイック登録)
    if (pathname === '/api/exclude-rules/add' && req.method === 'POST') {
        let bodyRaw = '';
        req.on('data', chunk => bodyRaw += chunk);
        req.on('end', () => {
            try {
                const body = JSON.parse(bodyRaw);
                const system = body.system;
                const rule = (body.rule || '').trim();
                const scope = body.scope || 'system';

                if (!rule) {
                    res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
                    res.end(JSON.stringify({ success: false, message: '除外ルールが指定されていません' }));
                    return;
                }

                const cfg = loadExcludeConfig();
                if (scope === 'global') {
                    if (!cfg.global.includes(rule)) {
                        cfg.global.push(rule);
                    }
                } else {
                    if (!system) {
                        res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
                        res.end(JSON.stringify({ success: false, message: '対象システム名が指定されていません' }));
                        return;
                    }
                    if (!cfg.systems) cfg.systems = {};
                    if (!Array.isArray(cfg.systems[system])) cfg.systems[system] = [];
                    if (!cfg.systems[system].includes(rule)) {
                        cfg.systems[system].push(rule);
                    }
                }

                saveExcludeConfig(cfg);
                res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({
                    success: true,
                    message: `除外ルール「${rule}」を${scope === 'global' ? '全システム共通' : system}に登録・保持しました`,
                    rule,
                    scope,
                    system
                }));
            } catch (e) {
                res.writeHead(500, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({ success: false, message: e.message }));
            }
        });
        return;
    }

    // 2-E. 除外ルール単一削除 (除外解除)
    if (pathname === '/api/exclude-rules/remove' && req.method === 'POST') {
        let bodyRaw = '';
        req.on('data', chunk => bodyRaw += chunk);
        req.on('end', () => {
            try {
                const body = JSON.parse(bodyRaw);
                const system = body.system;
                const rule = (body.rule || '').trim();
                const scope = body.scope || 'system';

                const cfg = loadExcludeConfig();
                let removed = false;

                if (scope === 'global') {
                    const idx = cfg.global.indexOf(rule);
                    if (idx !== -1) {
                        cfg.global.splice(idx, 1);
                        removed = true;
                    }
                } else if (system && cfg.systems && Array.isArray(cfg.systems[system])) {
                    const idx = cfg.systems[system].indexOf(rule);
                    if (idx !== -1) {
                        cfg.systems[system].splice(idx, 1);
                        removed = true;
                    }
                }

                saveExcludeConfig(cfg);
                res.writeHead(200, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({
                    success: true,
                    message: removed ? `除外ルール「${rule}」を解除しました` : `指定ルールが見つかりませんでした`,
                    removed
                }));
            } catch (e) {
                res.writeHead(500, { 'Content-Type': 'application/json; charset=UTF-8' });
                res.end(JSON.stringify({ success: false, message: e.message }));
            }
        });
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

                // ── 除外ファイル強制ブロック防壁 ──
                const excludeConfig = loadExcludeConfig();
                const blocked = [];
                for (const rel of files) {
                    const chk = checkFileExclusion(rel, system, excludeConfig);
                    if (chk.isExcluded) {
                        blocked.push(`${rel} (除外ルール: ${chk.rule})`);
                    }
                }
                if (blocked.length > 0) {
                    res.writeHead(400, { 'Content-Type': 'application/json; charset=UTF-8' });
                    res.end(JSON.stringify({
                        success: false,
                        message: `除外ルールに登録されているファイルが含まれているためデプロイを中断しました:\n${blocked.join('\n')}`
                    }));
                    return;
                }

                const isRoot = (system === '_portal');
                const localDir = isRoot ? LOCAL_HTDOCS : path.join(LOCAL_HTDOCS, system);
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
