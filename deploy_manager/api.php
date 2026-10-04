<?php
/**
 * 院内システム総合デプロイマネージャー (Deploy Manager API)
 * ファイル名: c:\Apache24\htdocs\deploy_manager\api.php
 */
ini_set('display_errors', "0");
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

// ── 1. ローカル端末限定アクセス制御 ──
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local = in_array($remote_ip, ['127.0.0.1', '::1', 'localhost'], true);
if (!$is_local) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'アクセス権限がありません（ローカル端末専用ツールです）: ' . $remote_ip], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 2. パス定数 ──
define('LOCAL_HTDOCS', 'c:/Apache24/htdocs/');
define('BACKUP_ROOT', 'c:/deploy_backups/');

function getProdHtdocsBase() {
    $candidates = [
        '\\\\192.168.1.16\\htdocs\\',
        '\\\\192.168.1.16\\c$\\Apache24\\htdocs\\',
        '//192.168.1.16/htdocs/',
        '//192.168.1.16/c$/Apache24/htdocs/'
    ];
    foreach ($candidates as $cand) {
        if (@is_dir($cand)) {
            return $cand;
        }
    }
    // PHP直接が無理な場合は第一候補
    return '\\\\192.168.1.16\\htdocs\\';
}
define('PROD_HTDOCS', getProdHtdocsBase());

// 除外ディレクトリ
$exclude_dirs = [
    '.git', '.agents', '.gemini', 'node_modules', 'vendor', 
    'scratch', 'bak', 'ip_bak', 'gitshare', 'deploy_manager'
];

// 除外ファイル拡張子・パターン
function isExcludedFile($filename) {
    if (preg_match('/(\.bak|\.tmp|\.log|test_.*\.js|scratch_.*\.js|Thumbs\.db|desktop\.ini)$/i', $filename)) {
        return true;
    }
    return false;
}

$action = $_REQUEST['action'] ?? '';

if ($action === 'test_exec') {
    $ps1 = @shell_exec('powershell -Command "Test-Path \\\\192.168.1.16\\htdocs"');
    $ps2 = @shell_exec('powershell -Command "Test-Path \\\\192.168.1.16\\c$\\Apache24\\htdocs"');
    $who = @shell_exec('whoami');
    echo json_encode([
        'whoami' => trim((string)$who),
        'test_htdocs' => trim((string)$ps1),
        'test_c_admin' => trim((string)$ps2)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 3. システム一覧取得 ──
if ($action === 'list_systems') {
    $systems = [];
    $dirs = scandir(LOCAL_HTDOCS);
    foreach ($dirs as $d) {
        if ($d === '.' || $d === '..') continue;
        if (in_array($d, $exclude_dirs, true)) continue;
        
        $local_path = LOCAL_HTDOCS . $d;
        if (is_dir($local_path)) {
            $prod_path = PROD_HTDOCS . $d;
            $prod_exists = is_dir($prod_path);
            
            $systems[] = [
                'name' => $d,
                'local_path' => $local_path,
                'prod_path' => $prod_path,
                'prod_exists' => $prod_exists
            ];
        }
    }
    
    // 表示順（主要システムを先頭に）
    usort($systems, function($a, $b) {
        $priority = ['endoscope' => 1, 'op' => 2, 'karteview' => 3, 'hiyarihat' => 4, 'yotei' => 5];
        $pA = $priority[$a['name']] ?? 99;
        $pB = $priority[$b['name']] ?? 99;
        if ($pA !== $pB) return $pA - $pB;
        return strcmp($a['name'], $b['name']);
    });

    echo json_encode([
        'success' => true,
        'prod_reachable' => is_dir(PROD_HTDOCS),
        'systems' => $systems
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 4. 差分スキャン ──
if ($action === 'scan_diff') {
    $system = trim($_REQUEST['system'] ?? '');
    if (!$system || !preg_match('/^[a-zA-Z0-9_\-]+$/', $system)) {
        echo json_encode(['success' => false, 'message' => '不正なシステム名です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $local_base = rtrim(LOCAL_HTDOCS . $system, '/') . '/';
    $prod_base = rtrim(PROD_HTDOCS . $system, '/') . '/';

    if (!is_dir($local_base)) {
        echo json_encode(['success' => false, 'message' => 'ローカルシステムが見つかりません: ' . $system], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prod_exists = is_dir($prod_base);

    // 再帰的にローカルファイル収集
    $local_files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($local_base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $rel_path = str_replace('\\', '/', substr($item->getPathname(), strlen($local_base)));
        
        // ディレクトリ除外チェック
        $parts = explode('/', $rel_path);
        $skip = false;
        foreach ($parts as $p) {
            if (in_array($p, $exclude_dirs, true)) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        if ($item->isFile()) {
            if (isExcludedFile($item->getFilename())) continue;
            
            $local_files[$rel_path] = [
                'size' => $item->getSize(),
                'mtime' => $item->getMTime()
            ];
        }
    }

    // 本番ファイルとの比較
    $diff_list = [];
    $stats = ['new' => 0, 'modified' => 0, 'same' => 0];

    foreach ($local_files as $rel => $info) {
        $prod_file = $prod_base . $rel;
        
        if (!file_exists($prod_file)) {
            $diff_list[] = [
                'path' => $rel,
                'status' => 'NEW',
                'local_size' => $info['size'],
                'local_mtime' => date('Y-m-d H:i:s', $info['mtime']),
                'prod_size' => null,
                'prod_mtime' => null
            ];
            $stats['new']++;
        } else {
            $prod_size = filesize($prod_file);
            $prod_mtime = filemtime($prod_file);

            // 差分判定: 更新日時またはサイズが異なる場合（2秒以上の差）
            $is_diff = ($prod_size !== $info['size']) || (abs($info['mtime'] - $prod_mtime) > 2);
            
            if ($is_diff) {
                $diff_list[] = [
                    'path' => $rel,
                    'status' => 'MODIFIED',
                    'local_size' => $info['size'],
                    'local_mtime' => date('Y-m-d H:i:s', $info['mtime']),
                    'prod_size' => $prod_size,
                    'prod_mtime' => date('Y-m-d H:i:s', $prod_mtime)
                ];
                $stats['modified']++;
            } else {
                $stats['same']++;
            }
        }
    }

    // ソート: MODIFIED, NEWの順、パス昇順
    usort($diff_list, function($a, $b) {
        $p = ['MODIFIED' => 1, 'NEW' => 2];
        $stA = $p[$a['status']] ?? 9;
        $stB = $p[$b['status']] ?? 9;
        if ($stA !== $stB) return $stA - $stB;
        return strcmp($a['path'], $b['path']);
    });

    echo json_encode([
        'success' => true,
        'system' => $system,
        'prod_exists' => $prod_exists,
        'stats' => $stats,
        'total_local_files' => count($local_files),
        'diff_count' => count($diff_list),
        'diffs' => $diff_list
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 5. 本番デプロイ実行（自動バックアップ ➔ コピー） ──
if ($action === 'deploy') {
    $system = trim($_POST['system'] ?? '');
    $files = $_POST['files'] ?? [];
    if (!is_array($files)) {
        $files = json_decode($files, true) ?: [];
    }

    if (!$system || empty($files)) {
        echo json_encode(['success' => false, 'message' => 'システム名または配布ファイルが選択されていません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $local_base = rtrim(LOCAL_HTDOCS . $system, '/') . '/';
    $prod_base = rtrim(PROD_HTDOCS . $system, '/') . '/';

    if (!is_dir(PROD_HTDOCS)) {
        echo json_encode(['success' => false, 'message' => '本番サーバー（192.168.1.16）にアクセスできません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1. 本番ディレクトリが存在しなければ作成
    if (!is_dir($prod_base)) {
        @mkdir($prod_base, 0777, true);
    }

    // 2. 本番ファイルの事前ZIPバックアップ作成
    $backup_dir = BACKUP_ROOT . $system . '/';
    if (!is_dir($backup_dir)) {
        @mkdir($backup_dir, 0777, true);
    }

    $backup_filename = sprintf('%s_backup_%s.zip', $system, date('Ymd_His'));
    $backup_zip_path = $backup_dir . $backup_filename;

    $zip = new ZipArchive();
    $zip_created = false;
    $backed_up_count = 0;

    if ($zip->open($backup_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($files as $rel) {
            $prod_target = $prod_base . $rel;
            if (file_exists($prod_target) && is_file($prod_target)) {
                $zip->addFile($prod_target, $rel);
                $backed_up_count++;
            }
        }
        $zip->close();
        $zip_created = file_exists($backup_zip_path);
    }

    if (!$zip_created && $backed_up_count > 0) {
        echo json_encode(['success' => false, 'message' => '本番ファイルの事前バックアップ作成に失敗したため、デプロイを中止しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. 安全コピー
    $copied_files = [];
    $failed_files = [];

    foreach ($files as $rel) {
        $src = $local_base . $rel;
        $dest = $prod_base . $rel;

        if (!file_exists($src) || !is_file($src)) {
            $failed_files[] = ['path' => $rel, 'error' => 'ローカルファイルが存在しません'];
            continue;
        }

        // 親ディレクトリ作成
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0777, true);
        }

        // コピー実行
        if (@copy($src, $dest)) {
            // タイムスタンプをローカルと完全同期
            @touch($dest, filemtime($src));
            $copied_files[] = [
                'path' => $rel,
                'size' => filesize($dest),
                'mtime' => date('Y-m-d H:i:s', filemtime($dest))
            ];
        } else {
            $failed_files[] = ['path' => $rel, 'error' => 'コピー処理に失敗しました'];
        }
    }

    $all_success = empty($failed_files);

    echo json_encode([
        'success' => $all_success,
        'message' => $all_success 
            ? sprintf('%d 件のファイルを本番サーバーへ安全に配布しました', count($copied_files))
            : sprintf('%d 件成功、%d 件失敗しました', count($copied_files), count($failed_files)),
        'backup_zip' => $zip_created ? $backup_filename : null,
        'backed_up_count' => $backed_up_count,
        'copied_count' => count($copied_files),
        'failed_count' => count($failed_files),
        'copied_files' => $copied_files,
        'failed_files' => $failed_files
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 6. 過去バックアップ一覧 ──
if ($action === 'list_backups') {
    $system = trim($_REQUEST['system'] ?? '');
    if (!$system) {
        echo json_encode(['success' => false, 'message' => 'システム名を指定してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backup_dir = BACKUP_ROOT . $system . '/';
    $backups = [];

    if (is_dir($backup_dir)) {
        $files = scandir($backup_dir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $f) {
            if (preg_match('/\.zip$/i', $f)) {
                $fp = $backup_dir . $f;
                $backups[] = [
                    'filename' => $f,
                    'size' => filesize($fp),
                    'size_formatted' => round(filesize($fp) / 1024, 1) . ' KB',
                    'mtime' => date('Y-m-d H:i:s', filemtime($fp))
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'system' => $system,
        'backups' => $backups
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 7. ワンクリック・ロールバック ──
if ($action === 'rollback') {
    $system = trim($_POST['system'] ?? '');
    $zip_filename = basename($_POST['zip_filename'] ?? '');

    if (!$system || !$zip_filename) {
        echo json_encode(['success' => false, 'message' => 'パラメータが不足しています'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $zip_path = BACKUP_ROOT . $system . '/' . $zip_filename;
    if (!file_exists($zip_path)) {
        echo json_encode(['success' => false, 'message' => 'バックアップファイルが存在しません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $prod_base = rtrim(PROD_HTDOCS . $system, '/') . '/';
    if (!is_dir(PROD_HTDOCS)) {
        echo json_encode(['success' => false, 'message' => '本番サーバーにアクセスできません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        echo json_encode(['success' => false, 'message' => 'ZIPファイルの展開に失敗しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $restored_count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry_name = $zip->getNameIndex($i);
        $dest = $prod_base . $entry_name;
        
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0777, true);
        }

        $stream = $zip->getStream($entry_name);
        if ($stream) {
            $fp = fopen($dest, 'w');
            if ($fp) {
                while (!feof($stream)) {
                    fwrite($fp, fread($stream, 8192));
                }
                fclose($fp);
                $restored_count++;
            }
            fclose($stream);
        }
    }
    $zip->close();

    echo json_encode([
        'success' => true,
        'message' => sprintf('バックアップ「%s」から %d 件のファイルを本番へ復元（ロールバック）しました', $zip_filename, $restored_count),
        'restored_count' => $restored_count
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => '無効なアクションです: ' . htmlspecialchars($action)], JSON_UNESCAPED_UNICODE);
exit;
