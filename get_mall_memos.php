<?php
/**
 * ファイル名: get_mall_memos.php
 * 概要: MALL電子カルテ(SQL Server)から最新メモを取得し、RTF形式のカラー・スコープを正確に保持してHTMLに変換
 */
ini_set('display_errors', 0); 
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

$patient_no = isset($_GET['patient_no']) ? trim($_GET['patient_no']) : (isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '');

if ($patient_no === '' || !is_numeric($patient_no)) {
    echo json_encode(['success' => false, 'message' => 'IDが不正です']);
    exit;
}

$serverName = "192.168.1.77"; 
$dbName     = "his00";
$uid        = "sa";
$pwd        = "Kochi-cl";

/**
 * RTF → HTML変換関数（グループ構造・カラーのスコープ対応版）
 */
function rtfToHtml(?string $rtf): string {
    if (empty($rtf)) return '';
    if (strpos($rtf, '{\\rtf') === false) {
        return nl2br(htmlspecialchars($rtf, ENT_QUOTES, 'UTF-8'));
    }

    // カラーテーブルの抽出 (\colortbl;\red0\green0\blue0;\red255\green0\blue0;...)
    $colors = ['#000000'];
    if (preg_match('/\{\\\\colortbl;?([^}]+)\}/', $rtf, $m)) {
        $defs = explode(';', $m[1]);
        $colors = ['#000000'];
        foreach ($defs as $def) {
            if (empty(trim($def))) continue;
            $r = 0; $g = 0; $b = 0;
            if (preg_match('/\\\\red(\d+)/', $def, $rc)) $r = (int)$rc[1];
            if (preg_match('/\\\\green(\d+)/', $def, $gc)) $g = (int)$gc[1];
            if (preg_match('/\\\\blue(\d+)/', $def, $bc)) $b = (int)$bc[1];
            $colors[] = sprintf('#%02x%02x%02x', $r, $g, $b);
        }
    }

    // ヘッダー群の切り捨て
    $text = preg_replace('/^\{\\\\rtf[^\n\r]*(\r\n|\r|\n)/', '', $rtf);
    $text = preg_replace('/\{\\\\fonttbl[^\}]+\}/', '', $text);
    $text = preg_replace('/\{\\\\colortbl[^\}]+\}/', '', $text);
    $text = preg_replace('/\{\\\\stylesheet[^\}]+\}/', '', $text);
    $text = preg_replace('/\{\\\\info[^\}]+\}/', '', $text);
    $text = preg_replace('/\{\\\\[\*\w]+[^\}]*\}/', '', $text);

    // 日本語 Shift-JIS 16進エスケープ (\'82\'a0 等) のバイナリデコード
    $text = preg_replace_callback("/(\\\\'[0-9a-fA-F]{2})+/", function($m) {
        $hex = str_replace(["\\'", " "], '', $m[0]);
        $bin = @hex2bin($hex);
        if ($bin !== false) {
            $enc = @mb_detect_encoding($bin, ['CP932', 'SJIS', 'UTF-8', 'eucJP-win'], true);
            if ($enc && $enc !== 'UTF-8') {
                return @mb_convert_encoding($bin, 'UTF-8', $enc);
            }
            return $bin;
        }
        return $m[0];
    }, $text);

    // Unicodeエスケープ (\u12345?)
    $text = preg_replace_callback('/\\\\u([0-9]{3,6})\??/', function($m) {
        $val = (int)$m[1];
        if ($val < 0) $val += 65536;
        return mb_chr($val, 'UTF-8');
    }, $text);

    // トークン分割（コントロールワード、括弧、特殊文字、テキストを分解）
    $tokens = preg_split('/(\\\\[a-zA-Z0-9_-]+(?:\\s+)?|\\\\\'[0-9a-fA-F]{2}|[{}]|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $html = '';
    $style_stack = []; // スコープ管理用スタック
    $current_color = 'inherit';
    $is_bold = false;
    $sjis_buffer = '';

    $flush_sjis = function() use (&$sjis_buffer, &$html, &$current_color, &$is_bold) {
        if ($sjis_buffer === '') return;
        $utf8_str = mb_convert_encoding($sjis_buffer, 'UTF-8', 'CP932,SJIS,UTF-8');
        $escaped = htmlspecialchars($utf8_str, ENT_QUOTES, 'UTF-8');

        $style = '';
        if ($current_color !== 'inherit') {
            $style .= "color: {$current_color};";
        }
        if ($is_bold) {
            $style .= "font-weight: bold;";
        }

        if ($style !== '') {
            $html .= "<span style=\"{$style}\">{$escaped}</span>";
        } else {
            $html .= $escaped;
        }
        $sjis_buffer = '';
    };

    foreach ($tokens as $token) {
        if (preg_match('/^\\\\\'([0-9a-fA-F]{2})$/', $token, $m_hex)) {
            $sjis_buffer .= chr(hexdec($m_hex[1]));
            continue;
        }

        $flush_sjis();

        $lower_tok = strtolower(trim($token));

        if ($lower_tok === '\\line' || $lower_tok === '\\par' || $token === "\n" || $token === "\r") {
            $html .= "<br>\n";
        } elseif ($lower_tok === '\\tab') {
            $html .= " &nbsp; &nbsp; &nbsp; &nbsp; ";
        } elseif ($lower_tok === '\\b') {
            $is_bold = true;
        } elseif ($lower_tok === '\\b0') {
            $is_bold = false;
        } elseif (preg_match('/^\\\\cf(\d+)\b/', $lower_tok, $m_cf)) {
            $c_idx = (int)$m_cf[1];
            $current_color = $colors[$c_idx] ?? 'inherit';
        } elseif ($lower_tok === '\\cf0') {
            $current_color = 'inherit';
        } elseif ($token === '{') {
            // グループ開始：現在の状態をスタックに退避
            $style_stack[] = ['color' => $current_color, 'bold' => $is_bold];
        } elseif ($token === '}') {
            // グループ終了：スタックから直前の状態を復元（これにより色が正しく切れる）
            if (!empty($style_stack)) {
                $prev = array_pop($style_stack);
                $current_color = $prev['color'];
                $is_bold = $prev['bold'];
            }
        } elseif (strpos($token, '\\') === 0) {
            // その他の制御ワードはスキップ
            continue;
        } else {
            // 通常テキスト
            $style = '';
            if ($current_color !== 'inherit') $style .= "color: {$current_color};";
            if ($is_bold) $style .= "font-weight: bold;";

            $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
            if ($style !== '') {
                $html .= "<span style=\"{$style}\">{$escaped}</span>";
            } else {
                $html .= $escaped;
            }
        }
    }

    $flush_sjis();

    $html = preg_replace('/(?:<br>\s*)+$/i', '', trim($html));
    $html = preg_replace('/^(?:<br>\s*)+/i', '', $html);

    return $html;
}

try {
    $dsn = "sqlsrv:server=$serverName;Database=$dbName;TrustServerCertificate=1;ApplicationIntent=ReadOnly;";
    $conn = new PDO($dsn, $uid, $pwd, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $conn->exec("SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '電子カルテDB接続失敗']);
    exit;
}

try {
    $sql = "SELECT 
                Sub.MemoID,
                Sub.DivID,
                Sub.NoteText,
                Sub.NoteRtfText,
                CONVERT(varchar(10), Sub.MemoDate, 120) AS MemoDateStr,
                CONVERT(varchar(19), Sub.LastUpdateTime, 120) AS LastUpdateTimeStr,
                Sub.StatusFlag
            FROM (
                SELECT 
                    M.MemoID, 
                    M.DivID, 
                    CONVERT(varchar(max), M.Note) AS NoteText,
                    CONVERT(varchar(max), M.NoteRTF) AS NoteRtfText,
                    M.MemoDate, 
                    M.EditNo,
                    M.LastUpdateTime, 
                    M.StatusFlag,
                    ROW_NUMBER() OVER (
                        PARTITION BY M.DivID 
                        ORDER BY M.MemoDate DESC, M.EditNo DESC, M.LastUpdateTime DESC
                    ) AS row_num
                FROM T_Memo M
                INNER JOIN M_Patient P ON M.PatientID = P.PatientID
                WHERE P.PatientNo = :patient_no 
                  AND M.DivID IN (1, 2, 3)
                  AND M.StatusFlag IN (0, 8)
            ) Sub
            WHERE Sub.row_num = 1
            ORDER BY Sub.DivID ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([':patient_no' => $patient_no]);
    $raw_memos = $stmt->fetchAll();

    $memo_today   = '';
    $memo_karte   = '';
    $memo_patient = '';

    foreach ($raw_memos as $memo) {
        $div_id = (int)$memo['DivID'];
        
        // RTFが存在すればHTMLへ変換、なければプレーンテキストをエスケープ＋改行変換
        if (!empty($memo['NoteRtfText'])) {
            $contentHtml = rtfToHtml($memo['NoteRtfText']);
        } else {
            $contentHtml = nl2br(htmlspecialchars(trim((string)$memo['NoteText']), ENT_QUOTES, 'UTF-8'));
        }

        // ※ご要望により最上段の日付表示部分（【YYYY-MM-DD】）は削除しました

        if ($div_id === 1) {
            $memo_today = $contentHtml;
        } elseif ($div_id === 2) {
            $memo_karte = $contentHtml;
        } elseif ($div_id === 3) {
            $memo_patient = $contentHtml;
        }
    }

    echo json_encode([
        'success'      => true,
        'patient_no'   => $patient_no,
        'memo_today'   => $memo_today,
        'memo_karte'   => $memo_karte,
        'memo_patient' => $memo_patient
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'クエリ不具合']);
}