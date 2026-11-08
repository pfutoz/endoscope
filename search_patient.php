<?php
/**
 * search_patient.php
 * 患者全体データ検索システム（左右分割マスター・ディテールレイアウト対応版）
 * 
 * 改善履歴:
 * - 2026-11-02: 
 *   - カナ検索の完全対応（ひらがな・全角カタカナ・半角カタカナの相互自動変換検索）
 *   - 備考欄（remarks）を常時検索対象に統合（不要なチェックボックスオプションを撤廃）
 *   - 検索ボックスを左ペイン幅に合わせてコンパクト化・上部に統合
 *   - 右ペイン（患者検査詳細ビュー）を画面上部まで広げて縦表示領域を大幅拡大
 *   - 検索候補リスト・詳細一覧の表題・デザイン刷新（「科」「検査日」）
 *   - 診療科表示の改善（GASTRO/PROCT → 🔵 胃腸科 / 🟢 肛門科 テキスト＋アイコン）
 *   - 患者名枠内への和暦生年月日（例: S42.4.28生）表示
 *   - 患者検査詳細ビュー上部の患者サマリーカード（氏名、和暦生年月日、患者ID）表示
 *   - 備考欄のRTF/リッチテキスト構文完全レンダリング対応
 *   - 検索時の先頭患者自動選択＆非同期即時切替（ページ再読込なし・爆速UX）
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗: DB接続を取得できませんでした。");
}

// -------------------------------------------------------------
// ヘルパー関数: RTF構文パーサー & 備考レンダラー
// -------------------------------------------------------------
if (!function_exists('parseRtfToHtml')) {
    function parseRtfToHtml(?string $rtf): string {
        if ($rtf === null || $rtf === '') return '';
        $text = trim($rtf);

        if (strpos($text, '{\\rtf') !== 0) {
            return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }

        $color_map = [0 => 'inherit'];
        if (preg_match('/{\\\\colortbl\\s*;?(.*?)}/s', $text, $m_col)) {
            $defs = explode(';', $m_col[1]);
            $idx = 1;
            foreach ($defs as $d) {
                $d = trim($d);
                if ($d === '') continue;
                if (preg_match('/\\\\red(\d+)\\\\green(\d+)\\\\blue(\d+)/', $d, $rgb)) {
                    $color_map[$idx] = "rgb({$rgb[1]},{$rgb[2]},{$rgb[3]})";
                    $idx++;
                }
            }
        }

        $text = preg_replace('/{\\\\\\*?\\\\fonttbl.*?}/s', '', $text);
        $text = preg_replace('/{\\\\\\*?\\\\colortbl.*?}/s', '', $text);
        $text = preg_replace('/{\\\\\\*?\\\\stylesheet.*?}/s', '', $text);
        $text = preg_replace('/{\\\\\\*?\\\\info.*?}/s', '', $text);
        $text = preg_replace('/^.*?\\\\viewkind\d+\\\\uc\d+/s', '', $text);
        $text = preg_replace('/^.*?\\\\pard/s', '', $text);

        $tokens = preg_split('/(\\\\[a-zA-Z0-9_-]+(?:\\s+)?|\\\\\'[0-9a-fA-F]{2}|\\\\par|\\\\tab|[{}]|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $html = '';
        $is_bold = false;
        $current_color = 'inherit';
        $sjis_buffer = '';

        $flush_sjis = function() use (&$sjis_buffer, &$html, &$is_bold, &$current_color) {
            if ($sjis_buffer === '') return;
            $utf8_str = mb_convert_encoding($sjis_buffer, 'UTF-8', 'CP932,SJIS,UTF-8');
            $escaped = htmlspecialchars($utf8_str, ENT_QUOTES, 'UTF-8');

            $style = '';
            if ($current_color !== 'inherit') $style .= "color: {$current_color};";
            if ($is_bold) $style .= "font-weight: bold;";

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

            if ($lower_tok === '\\par' || $token === "\n" || $token === "\r") {
                $html .= "<br>\n";
            } elseif ($lower_tok === '\\tab') {
                $html .= " &nbsp; &nbsp; ";
            } elseif ($lower_tok === '\\b') {
                $is_bold = true;
            } elseif ($lower_tok === '\\b0') {
                $is_bold = false;
            } elseif (preg_match('/^\\\\cf(\d+)$/', $lower_tok, $m_cf)) {
                $c_idx = (int)$m_cf[1];
                $current_color = $color_map[$c_idx] ?? 'inherit';
            } elseif ($token === '{' || $token === '}') {
                continue;
            } elseif (strpos($token, '\\') === 0) {
                continue;
            } else {
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
        return preg_replace('/(?:<br>\s*)+$/i', '', trim($html));
    }
}

if (!function_exists('renderRemarksRtfHtml')) {
    function renderRemarksRtfHtml(?string $raw): string {
        if ($raw === null || $raw === '') return '<span style="color:#94a3b8;">--</span>';
        $trimmed = trim($raw);
        
        if (mb_strpos($trimmed, '枠なし') !== false || mb_strpos($trimmed, '休止') !== false) {
            return '<span class="badge-nowaku">＊＊＊🚫枠なし🚫＊＊＊</span>';
        }
        
        if (strpos($trimmed, '{\\rtf') === 0) {
            return '<div class="rich-remarks">' . parseRtfToHtml($trimmed) . '</div>';
        }
        
        if (preg_match('/<(?:p|br|strong|b|em|span|ul|ol|li)[^>]*>/i', $trimmed)) {
            $allowed = '<p><br><strong><b><em><span><ul><ol><li>';
            return '<div class="rich-remarks">' . strip_tags($trimmed, $allowed) . '</div>';
        }
        
        return '<div class="plain-remarks">' . nl2br(htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')) . '</div>';
    }
}

// 診療科テキスト＋アイコンフォーマッター
function formatDepartment(?string $code): string {
    $code = strtoupper(trim((string)$code));
    if ($code === 'GASTRO') {
        return '<span class="dept-text dept-gastro">🔵 胃腸科</span>';
    } elseif ($code === 'PROCT') {
        return '<span class="dept-text dept-proct">🟢 肛門科</span>';
    } elseif (!empty($code)) {
        return '<span class="dept-text">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return '<span style="color:#94a3b8;">--</span>';
}

// 和暦生年月日フォーマッター（例: S42.4.28生）
function formatBirthWareki(?string $birthday): string {
    if (empty($birthday) || $birthday === '---' || $birthday === '0000-00-00') {
        return '';
    }
    $short = convertToShortWareki($birthday);
    if (empty($short)) return '';
    return $short . '生';
}

// -------------------------------------------------------------
// リクエストパラメータ取得
// -------------------------------------------------------------
$keyword    = isset($_GET['keyword'])    ? trim($_GET['keyword'])    : '';
$target_pid = isset($_GET['patient_id']) ? trim($_GET['patient_id']) : '';

// デフォルトは「未来のみ (future_only)」。選択肢: 'future_only' | 'all'
$scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'future_only';
if ($scope !== 'future_only' && $scope !== 'all') {
    $scope = 'future_only';
}

// 右ペインのタイムライン選択（デフォルトは 'future'）
$timeline = isset($_GET['timeline']) ? trim($_GET['timeline']) : 'future';
if ($timeline !== 'future' && $timeline !== 'past') {
    $timeline = 'future';
}

$today_str = date('Y-m-d');

// -------------------------------------------------------------
// 1. 全体検索結果を取得（左ペイン用：キーワードにヒットする患者一覧）
// -------------------------------------------------------------
$search_results = [];
if (!empty($keyword)) {
    // スペースの正規化
    $kw_clean = trim(preg_replace('/\s+/u', ' ', $keyword));
    
    // カッコ付きOR検索のパース
    preg_match_all('/\([^)]+\)|[^\s()]+/u', $kw_clean, $matches);
    
    $and_clauses = [];
    $params = [];
    $param_index = 0;
    
    // トークンごとの検索条件（カナ・漢字・ID・備考すべてに対応）を生成する関数
    $build_token_clause = function(string $token) use (&$param_index, &$params) {
        $token = trim($token);
        if ($token === '') return null;

        // 全角・半角・ひらがなのバリエーションを生成
        $zen_kata  = mb_convert_kana($token, 'KVC', 'UTF-8'); // 全角カタカナ
        $half_kata = mb_convert_kana($zen_kata, 'k', 'UTF-8'); // 半角カタカナ
        $hira      = mb_convert_kana($zen_kata, 'c', 'UTF-8'); // ひらがな

        $p_raw  = ":kw_" . ($param_index++);
        $p_zen  = ":kw_" . ($param_index++);
        $p_half = ":kw_" . ($param_index++);
        $p_hira = ":kw_" . ($param_index++);

        $params[$p_raw]  = '%' . $token . '%';
        $params[$p_zen]  = '%' . $zen_kata . '%';
        $params[$p_half] = '%' . $half_kata . '%';
        $params[$p_hira] = '%' . $hira . '%';

        // 備考欄（remarks）も常に含める
        return "(
            CAST(patient_id AS TEXT) ILIKE $p_raw
            OR patient_name ILIKE $p_raw
            OR patient_name ILIKE $p_zen
            OR patient_name ILIKE $p_hira
            OR patient_kana ILIKE $p_half
            OR patient_kana ILIKE $p_zen
            OR patient_kana ILIKE $p_raw
            OR remarks ILIKE $p_raw
            OR remarks ILIKE $p_zen
        )";
    };
    
    if (!empty($matches[0])) {
        foreach ($matches[0] as $token) {
            $token = trim($token);
            if ($token === '') continue;
            
            if (strpos($token, '(') === 0 && substr($token, -1) === ')') {
                $or_content = trim(substr($token, 1, -1));
                $or_words = array_filter(explode(' ', $or_content), function($val) { return $val !== ''; });
                
                if (!empty($or_words)) {
                    $or_sub_clauses = [];
                    foreach ($or_words as $word) {
                        $clause = $build_token_clause($word);
                        if ($clause) $or_sub_clauses[] = $clause;
                    }
                    if (!empty($or_sub_clauses)) {
                        $and_clauses[] = '(' . implode(' OR ', $or_sub_clauses) . ')';
                    }
                }
            } else {
                $clause = $build_token_clause($token);
                if ($clause) {
                    $and_clauses[] = $clause;
                }
            }
        }
    }
    
    // スコープ条件
    $scope_condition = "";
    if ($scope === 'future_only') {
        $scope_condition = " AND exam_date >= :scope_today ";
        $params[':scope_today'] = $today_str;
    }

    // 常に直近の検査日（今日以降で最も近い日付・ASC。未来がなければ最新の過去日）を最優先で取得
    $params[':today_sort'] = $today_str;
    $search_sql = "SELECT DISTINCT ON (COALESCE(patient_id, ''), patient_name) 
                          patient_id, patient_name, birthday, department_code, exam_date, doctor_name
                   FROM t_endoscopy_schedule 
                   WHERE is_canceled = FALSE" . $scope_condition;
                   
    if (!empty($and_clauses)) {
        $search_sql .= " AND " . implode(' AND ', $and_clauses);
    }
    
    $search_sql .= " ORDER BY COALESCE(patient_id, ''), patient_name, 
                              CASE WHEN exam_date >= :today_sort THEN 0 ELSE 1 END ASC,
                              CASE WHEN exam_date >= :today_sort THEN exam_date END ASC,
                              exam_date DESC";
    
    $search_stmt = $pdo->prepare($search_sql);
    $search_stmt->execute($params);
    $search_results = $search_stmt->fetchAll();
}

// 検索結果の自動選択ロジック（1名ヒット時、ID検索時、またはtarget_pidが結果に合致しない時は即座にその患者を選択）
if (!empty($search_results)) {
    $matched = false;
    if (!empty($target_pid)) {
        foreach ($search_results as $r) {
            if ((string)($r['patient_id'] ?? '') === $target_pid) {
                $matched = true;
                break;
            }
        }
    }
    // 1名のみヒットした場合は無条件でその患者を即座に選択！
    // または target_pid が検索結果リストに存在しない場合も先頭の患者を選択！
    if (count($search_results) === 1 || !$matched) {
        $target_pid = (string)($search_results[0]['patient_id'] ?? '');
    }
}

// -------------------------------------------------------------
// 2. 右ペイン描画ロジック（関数化して通常描画とAjaxで共通利用）
// -------------------------------------------------------------
function renderDetailPaneContent($pdo, $target_pid, $timeline, $today_str, $keyword, $scope) {
    if (empty($target_pid)) {
        ?>
        <div class="empty-state-detail">
            <div class="empty-icon">👈</div>
            <div class="empty-title">患者が選択されていません</div>
            <div class="empty-sub">左側の候補リストから患者をクリックすると、ここに詳細な検査スケジュール・履歴が即時表示されます。</div>
        </div>
        <?php
        return;
    }

    // 患者基本情報（氏名、生年月日）
    $pinfo_sql = "SELECT patient_name, birthday FROM t_endoscopy_schedule 
                  WHERE patient_id = :pid 
                  ORDER BY updated_at DESC, exam_date DESC LIMIT 1";
    $pinfo_stmt = $pdo->prepare($pinfo_sql);
    $pinfo_stmt->execute([':pid' => $target_pid]);
    $pinfo = $pinfo_stmt->fetch();

    $p_name = $pinfo ? ($pinfo['patient_name'] ?? '') : '';
    $p_birth = $pinfo ? ($pinfo['birthday'] ?? '') : '';
    $wareki_str = formatBirthWareki($p_birth);

    // タイムラインに応じた履歴データ
    if ($timeline === 'future') {
        $hist_sql = "SELECT * FROM t_endoscopy_schedule 
                     WHERE patient_id = :pid 
                       AND is_canceled = FALSE 
                       AND exam_date >= :today 
                     ORDER BY exam_date ASC, scheduled_time ASC";
    } else {
        $hist_sql = "SELECT * FROM t_endoscopy_schedule 
                     WHERE patient_id = :pid 
                       AND is_canceled = FALSE 
                       AND exam_date < :today 
                     ORDER BY exam_date DESC, scheduled_time DESC";
    }

    $hist_stmt = $pdo->prepare($hist_sql);
    $hist_stmt->execute([':pid' => $target_pid, ':today' => $today_str]);
    $patient_history = $hist_stmt->fetchAll();
    ?>

    <!-- 👤 選択中患者サマリーカード -->
    <div class="patient-summary-card">
        <div class="summary-avatar">👤</div>
        <div class="summary-body">
            <div class="summary-top">
                <span class="summary-label">選択中の患者</span>
                <span class="summary-id-badge">ID: <?=htmlspecialchars($target_pid, ENT_QUOTES, 'UTF-8')?></span>
            </div>
            <div class="summary-main">
                <h3 class="summary-name"><?=htmlspecialchars($p_name ?: '（氏名未登録）', ENT_QUOTES, 'UTF-8')?></h3>
                <?php if (!empty($wareki_str)): ?>
                    <span class="summary-birth-badge">🎂 <?=htmlspecialchars($wareki_str, ENT_QUOTES, 'UTF-8')?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 📅 タイムライン切替セグメントコントロール -->
    <div class="timeline-segment-wrap">
        <div class="segment-tabs">
            <button type="button" 
                    class="segment-tab <?=$timeline === 'future' ? 'active' : ''?>" 
                    onclick="switchTimeline('future')"
                    id="tab-future">
                📅 未来の検査予定 (昇順)
            </button>
            <button type="button" 
                    class="segment-tab <?=$timeline === 'past' ? 'active' : ''?>" 
                    onclick="switchTimeline('past')"
                    id="tab-past">
                📂 過去の検査履歴 (降順)
            </button>
        </div>
        <div class="timeline-hint">
            💡 日付クリックでその日のカレンダーへ移動
        </div>
    </div>

    <!-- 📊 検査一覧テーブル -->
    <?php if (empty($patient_history)): ?>
        <div class="empty-timeline-box">
            <div style="font-size:24px; margin-bottom:6px;">📭</div>
            <div style="font-weight:600; color:#475569;">
                <?= $timeline === 'future' ? 'これ以降の未来の検査予定はありません。' : '過去の検査履歴はありません。' ?>
            </div>
            <div style="font-size:12px; color:#94a3b8; margin-top:4px;">
                <?= $timeline === 'future' ? '「過去の検査履歴」タブで以前の受診・検査履歴を確認できます。' : '「未来の検査予定」タブで予定を確認できます。' ?>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="detail-data-table">
                <thead>
                    <tr>
                        <th style="width: 105px;">検査日</th>
                        <th style="width: 95px;">科</th>
                        <th style="width: 55px;">枠番</th>
                        <th style="width: 75px;">予定時刻</th>
                        <th style="width: 70px;">種別</th>
                        <th style="width: 85px;">担当医</th>
                        <th>備考 (RTF)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patient_history as $row): 
                        $exam_date = $row['exam_date'];
                        $year  = date('Y', strtotime($exam_date));
                        $month = date('n', strtotime($exam_date));
                        $dept  = $row['department_code'] ?? '';
                        $jump_url = "month_schedule.php?dept=" . urlencode($dept) . "&year={$year}&month={$month}&date={$exam_date}";
                        $exam_type = trim((string)($row['exam_type'] ?? ''));
                    ?>
                        <tr class="detail-row" onclick="location.href='<?=$jump_url?>'" title="クリックしてカレンダー（<?=$exam_date?>）を開く">
                            <td class="col-date">
                                <a href="<?=$jump_url?>" class="date-link" onclick="event.stopPropagation();">
                                    <?=htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8')?> <span class="link-icon">🔗</span>
                                </a>
                            </td>
                            <td class="col-dept"><?=formatDepartment($dept)?></td>
                            <td class="col-center"><span class="waku-pill"><?=htmlspecialchars($row['waku_no'] ?? '--', ENT_QUOTES, 'UTF-8')?></span></td>
                            <td class="col-center"><?=htmlspecialchars($row['scheduled_time'] ?? '--', ENT_QUOTES, 'UTF-8')?></td>
                            <td class="col-center">
                                <?php if (!empty($exam_type)): ?>
                                    <span class="exam-type-pill"><?=htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8')?></span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">--</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-doctor"><?=htmlspecialchars($row['doctor_name'] ?? '--', ENT_QUOTES, 'UTF-8')?></td>
                            <td class="col-remarks">
                                <div class="remarks-box">
                                    <?=renderRemarksRtfHtml($row['remarks'] ?? '')?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
}

// -------------------------------------------------------------
// 3. Ajaxリクエスト（右ペイン即時切替）のエンドポイント
// -------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    header('Content-Type: text/html; charset=UTF-8');
    renderDetailPaneContent($pdo, $target_pid, $timeline, $today_str, $keyword, $scope);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔎 患者全体データ検索 | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #f8fafc;
            --surface-card: #ffffff;
            --border-light: #e2e8f0;
            --border-focus: #3b82f6;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --success: #16a34a;
            --font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hiragino Kaku Gothic ProN", "Yu Gothic", Meiryo, sans-serif;
            --shadow-card: 0 4px 14px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -2px rgba(15, 23, 42, 0.03);
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-full: 9999px;
        }

        * { box-sizing: border-box; }
        body {
            font-family: var(--font-family);
            margin: 0;
            padding: 12px 18px;
            background-color: var(--bg-page);
            color: var(--text-primary);
            font-size: 13px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .main-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
        }

        /* 🏠 ホーム戻るボタン */
        .home-icon-btn {
            position: fixed;
            top: 12px;
            right: 18px;
            z-index: 100;
            background: #ffffff;
            color: #1e293b;
            border: 1px solid var(--border-light);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .home-icon-btn:hover {
            background-color: #f1f5f9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* 🔍 ヘッダー部（コンパクト） */
        .header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            flex-shrink: 0;
        }
        .page-title {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.01em;
        }
        .page-title .title-badge {
            font-size: 10.5px;
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            font-weight: 600;
        }

        /* 💡 左右分割レイアウト (Master-Detail Pane) */
        .workspace {
            display: flex;
            gap: 16px;
            align-items: stretch;
            flex: 1;
            min-height: 0; /* flex-childのスクロールに必須 */
        }

        /* 左カラム（検索ボックス ＋ 候補一覧） */
        .col-left {
            width: 410px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-shrink: 0;
            min-height: 0;
        }

        /* 🔍 コンパクト検索ボックス（左ペイン上部にぴったり） */
        .compact-search-box {
            background: var(--surface-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 10px 12px;
            box-shadow: var(--shadow-card);
            flex-shrink: 0;
        }
        .search-bar-row {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .search-input-group {
            position: relative;
            flex: 1;
            display: flex;
            align-items: center;
        }
        .search-icon-inside {
            position: absolute;
            left: 10px;
            font-size: 14px;
            color: #94a3b8;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            height: 36px;
            padding: 0 32px 0 32px;
            font-size: 13px;
            font-family: inherit;
            border: 1px solid #fde047;
            border-radius: var(--radius-md);
            background: #fef9c3; /* 視認性の高い上品な薄いイエロー */
            color: #1e293b;
            font-weight: 500;
            transition: all 0.2s;
        }
        .search-input:focus {
            outline: none;
            background: #fffbeb;
            border-color: #eab308;
            box-shadow: 0 0 0 3px rgba(234, 179, 8, 0.25);
        }
        .search-input::placeholder {
            color: #854d0e;
            opacity: 0.6;
            font-size: 12px;
        }
        .btn-clear {
            position: absolute;
            right: 8px;
            background: #e2e8f0;
            border: none;
            color: #64748b;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            text-decoration: none;
        }
        .btn-clear:hover {
            background: #cbd5e1;
            color: #1e293b;
        }

        .btn-search {
            height: 36px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 600;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
            box-shadow: 0 1px 4px rgba(37, 99, 235, 0.2);
            flex-shrink: 0;
        }
        .btn-search:hover {
            background: var(--primary-hover);
        }

        /* 検索スコープ（コンパクトピル） */
        .search-scope-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed var(--border-light);
            font-size: 11.5px;
        }
        .scope-pills {
            display: inline-flex;
            background: #f1f5f9;
            padding: 2px;
            border-radius: var(--radius-md);
            gap: 2px;
            width: 100%;
        }
        .scope-pill-label {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 6px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            user-select: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .scope-pill-label input[type="radio"] {
            display: none;
        }
        .scope-pill-label.active {
            background: #ffffff;
            color: var(--primary);
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }

        /* 左ペイン（候補一覧カード） */
        .pane-left {
            flex: 1;
            background: var(--surface-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }

        /* 右ペイン（患者検査詳細ビュー：画面上部から広く上に伸びる！） */
        .pane-right {
            flex: 1;
            background: var(--surface-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            min-height: 0;
        }

        .pane-header {
            padding: 10px 14px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .pane-title {
            margin: 0;
            font-size: 13.5px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .pane-badge {
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: var(--radius-full);
            white-space: nowrap;
        }

        /* 診療科絞り込みボタングループ（件数横） */
        .dept-filter-group {
            display: inline-flex;
            background: #f1f5f9;
            padding: 2px;
            border-radius: 6px;
            gap: 2px;
            border: 1px solid var(--border-light);
        }
        .dept-filter-btn {
            border: none;
            background: transparent;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            border-radius: 4px;
            cursor: pointer;
            line-height: 1.2;
            transition: all 0.15s ease;
            white-space: nowrap;
            user-select: none;
        }
        .dept-filter-btn:hover {
            color: #0f172a;
            background: rgba(255, 255, 255, 0.7);
        }
        .dept-filter-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .dept-filter-btn.filter-gastro.active {
            color: #1d4ed8;
            font-weight: 700;
            background: #eff6ff;
        }
        .dept-filter-btn.filter-proct.active {
            color: #15803d;
            font-weight: 700;
            background: #f0fdf4;
        }

        .pane-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            min-height: 0;
        }

        /* 📋 左ペイン：候補一覧テーブル */
        .master-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
        }
        .master-table th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
            font-size: 11px;
            padding: 8px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-light);
            position: sticky;
            top: 0;
            z-index: 10;
            letter-spacing: 0.02em;
        }
        .master-table td {
            padding: 8px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            transition: background 0.15s;
        }

        .clickable-row {
            cursor: pointer;
            border-left: 4px solid transparent;
            transition: all 0.15s ease;
        }
        .clickable-row:hover {
            background-color: #f1f5f9;
        }
        /* 💡 選択行：パッと見でハッキリ分かる高コントラストブルー */
        .clickable-row.selected {
            background-color: #dbeafe !important; /* 明確なアクティブブルー */
            border-left: 5px solid #2563eb !important; /* 鮮明なロイヤルブルーバー */
            box-shadow: inset 0 0 0 1px #bfdbfe;
        }
        .clickable-row.selected td {
            background-color: transparent !important;
        }
        .clickable-row.selected .p-name-main {
            color: #1e40af !important;
            font-weight: 800 !important;
        }
        .clickable-row.selected .p-id-pill {
            background-color: #2563eb !important;
            color: #ffffff !important;
            font-weight: 800;
        }
        .clickable-row.selected .exam-date-text {
            color: #1e3a8a !important;
            font-weight: 700;
        }

        .p-id-pill {
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 700;
            color: #475569;
            background: #f1f5f9;
            padding: 2px 5px;
            border-radius: 4px;
            font-size: 11.5px;
        }
        .p-name-cell {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .p-name-main {
            font-weight: 700;
            font-size: 13px;
            color: #0f172a;
        }
        .p-birth-sub {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }

        /* 診療科テキスト＋アイコン */
        .dept-text {
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-size: 11.5px;
            white-space: nowrap;
        }
        .dept-gastro {
            color: #1d4ed8;
        }
        .dept-proct {
            color: #15803d;
        }

        .exam-date-text {
            font-weight: 600;
            color: #334155;
            font-size: 12px;
            white-space: nowrap;
        }

        /* 👤 右ペイン：患者サマリーカード */
        .patient-summary-card {
            background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-md);
            padding: 10px 14px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 1px 4px rgba(37, 99, 235, 0.05);
            flex-shrink: 0;
        }
        .summary-avatar {
            width: 38px;
            height: 38px;
            background: #ffffff;
            border: 1px solid #93c5fd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .summary-body {
            flex: 1;
        }
        .summary-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 1px;
        }
        .summary-label {
            font-size: 10.5px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .summary-id-badge {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-family: monospace;
            font-weight: 700;
            color: #1e293b;
        }
        .summary-main {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .summary-name {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: -0.01em;
        }
        .summary-birth-badge {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 1px 8px;
            border-radius: var(--radius-full);
            font-size: 11.5px;
            font-weight: 600;
        }

        /* 📅 タイムライン切替バー（セグメントコントロール） */
        .timeline-segment-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        .segment-tabs {
            display: inline-flex;
            background: #f1f5f9;
            padding: 2px;
            border-radius: var(--radius-md);
            gap: 2px;
        }
        .segment-tab {
            padding: 5px 12px;
            border: none;
            background: transparent;
            font-size: 11.5px;
            font-weight: 600;
            color: #64748b;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .segment-tab:hover {
            color: #1e293b;
        }
        .segment-tab.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .timeline-hint {
            font-size: 11px;
            color: #64748b;
        }

        /* 📊 右ペイン：詳細一覧テーブル */
        .table-container {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            overflow-y: auto;
            background: #ffffff;
            flex: 1;
            min-height: 0;
        }
        .detail-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .detail-data-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid var(--border-light);
            text-align: left;
            font-size: 11px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .detail-data-table th:last-child {
            border-right: none;
        }
        .detail-data-table td {
            padding: 7px 10px;
            border-bottom: 1px solid var(--border-light);
            border-right: 1px solid var(--border-light);
            vertical-align: top;
        }
        .detail-data-table td:last-child {
            border-right: none;
        }
        .detail-row {
            cursor: pointer;
            transition: background 0.15s;
        }
        .detail-row:hover {
            background-color: #f8fafc;
        }

        .date-link {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }
        .date-link:hover {
            text-decoration: underline;
            color: #1d4ed8;
        }
        .link-icon {
            font-size: 9px;
            opacity: 0.7;
        }

        .col-center { text-align: center; }
        .col-date { font-weight: 600; white-space: nowrap; }
        .col-dept { white-space: nowrap; }
        .col-doctor { white-space: nowrap; font-weight: 500; }
        .col-remarks { line-height: 1.4; word-break: break-word; }

        .waku-pill {
            display: inline-block;
            padding: 1px 5px;
            background: #f1f5f9;
            color: #475569;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 600;
        }
        .exam-type-pill {
            display: inline-block;
            padding: 1px 5px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 700;
        }

        /* 備考欄（RTF / 枠なしバッジ） */
        .remarks-box {
            font-size: 11.5px;
            color: #334155;
            max-height: 100px;
            overflow-y: auto;
        }
        .badge-nowaku {
            display: inline-block;
            background-color: #fee2e2;
            color: #dc2626;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 10.5px;
            border: 1px solid #fca5a5;
        }
        .rich-remarks {
            line-height: 1.4;
        }
        .plain-remarks {
            line-height: 1.4;
        }

        /* エンプティステート */
        .empty-state-left, .empty-state-detail {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 18px;
            text-align: center;
            color: #64748b;
        }
        .empty-icon {
            font-size: 36px;
            margin-bottom: 8px;
            opacity: 0.8;
        }
        .empty-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
        }
        .empty-sub {
            font-size: 11.5px;
            color: #94a3b8;
            max-width: 280px;
            line-height: 1.4;
        }
        .empty-timeline-box {
            text-align: center;
            padding: 30px 18px;
            background: #f8fafc;
            border: 1px dashed var(--border-light);
            border-radius: var(--radius-md);
        }

        /* 非同期更新時のローディング */
        .pane-loading {
            opacity: 0.4;
            pointer-events: none;
            transition: opacity 0.15s ease;
        }
    </style>
</head>
<body>

<a href="index.php" class="home-icon-btn" title="システムメニュー（ホーム）に戻る">🏠</a>

<div class="main-container">
    <div class="header-bar">
        <h2 class="page-title">
            <span>🔎 患者全体データ検索</span>
            <span class="title-badge">リアルタイム予約＆履歴</span>
        </h2>
    </div>

    <!-- 💡 左右分割マスター・ディテールワークスペース -->
    <div class="workspace">
        <!-- 【左カラム】検索ボックス ＋ 検索候補患者リスト -->
        <div class="col-left">
            <!-- コンパクト検索カード -->
            <div class="compact-search-box">
                <form method="GET" action="search_patient.php" id="searchForm" onsubmit="document.getElementById('hiddenPatientId').value='';">
                    <input type="hidden" name="patient_id" id="hiddenPatientId" value="<?=htmlspecialchars($target_pid, ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="timeline" id="hiddenTimeline" value="<?=htmlspecialchars($timeline, ENT_QUOTES, 'UTF-8')?>">
                    
                    <div class="search-bar-row">
                        <div class="search-input-group">
                            <span class="search-icon-inside">🔍</span>
                            <input type="text" 
                                   id="keyword" 
                                   name="keyword" 
                                   class="search-input" 
                                   value="<?=htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8')?>" 
                                   placeholder="氏名・カナ・ID・備考（例: 山田 または やまだ）" 
                                   autofocus 
                                   autocomplete="off">
                            <?php if (!empty($keyword) || !empty($target_pid) || $scope !== 'future_only'): ?>
                                <a href="search_patient.php" class="btn-clear" title="検索条件をクリア">✕</a>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn-search">
                            <span>検索</span>
                        </button>
                    </div>

                    <div class="search-scope-row">
                        <div class="scope-pills">
                            <label class="scope-pill-label <?=$scope === 'future_only' ? 'active' : ''?>">
                                <input type="radio" name="scope" value="future_only" <?=$scope==='future_only'?'checked':''?> onchange="this.form.submit()">
                                📅 未来の予約のみ
                            </label>
                            <label class="scope-pill-label <?=$scope === 'all' ? 'active' : ''?>">
                                <input type="radio" name="scope" value="all" <?=$scope==='all'?'checked':''?> onchange="this.form.submit()">
                                📂 すべての履歴（過去含む）
                            </label>
                        </div>
                    </div>
                </form>
            </div>

            <!-- 検索結果患者一覧 -->
            <div class="pane-left">
                <div class="pane-header">
                    <div style="display:flex; align-items:center; gap:6px;">
                        <h3 class="pane-title">
                            <span>📋 検索候補患者</span>
                        </h3>
                        <?php if (!empty($keyword)): ?>
                            <span class="pane-badge" id="candidateCountBadge"><?=count($search_results)?> 件</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($search_results)): ?>
                        <div class="dept-filter-group" title="診療科で絞り込み">
                            <button type="button" class="dept-filter-btn active" data-dept-filter="ALL" onclick="filterCandidates('ALL')">全科</button>
                            <button type="button" class="dept-filter-btn filter-gastro" data-dept-filter="GASTRO" onclick="filterCandidates('GASTRO')">🔵 胃腸科</button>
                            <button type="button" class="dept-filter-btn filter-proct" data-dept-filter="PROCT" onclick="filterCandidates('PROCT')">🟢 肛門科</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pane-body" style="padding:0;">
                    <?php if (empty($keyword)): ?>
                        <div class="empty-state-left">
                            <div class="empty-icon">⌨️</div>
                            <div class="empty-title">キーワードを入力してください</div>
                            <div class="empty-sub">患者氏名（漢字・カナ・ひらがな）、カルテID、備考欄の語句で即座に絞り込めます。</div>
                        </div>
                    <?php elseif (empty($search_results)): ?>
                        <div class="empty-state-left">
                            <div class="empty-icon">🔍</div>
                            <div class="empty-title">一致する患者が見つかりません</div>
                            <div class="empty-sub">入力内容をご確認いただくか、スコープを「すべての履歴（過去含む）」に切り替えてお試しください。</div>
                        </div>
                    <?php else: ?>
                        <table class="master-table">
                            <thead>
                                <tr>
                                    <th style="width: 65px;">ID</th>
                                    <th>患者名</th>
                                    <th style="width: 75px;">科</th>
                                    <th style="width: 90px;">検査日</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($search_results as $p): 
                                    $safe_pid   = trim((string)($p['patient_id'] ?? ''));
                                    $safe_pname = trim((string)($p['patient_name'] ?? ''));
                                    $safe_birth = trim((string)($p['birthday'] ?? ''));
                                    $safe_dept  = strtoupper(trim((string)($p['department_code'] ?? '')));
                                    $wareki_sub = formatBirthWareki($safe_birth);
                                    $is_selected = ($safe_pid === $target_pid && $target_pid !== '');
                                ?>
                                    <tr class="clickable-row <?=$is_selected ? 'selected' : ''?>" 
                                        data-pid="<?=htmlspecialchars($safe_pid, ENT_QUOTES, 'UTF-8')?>"
                                        data-dept="<?=htmlspecialchars($safe_dept, ENT_QUOTES, 'UTF-8')?>"
                                        onclick="selectPatient('<?=htmlspecialchars($safe_pid, ENT_QUOTES, 'UTF-8')?>', this)">
                                        <td>
                                            <span class="p-id-pill"><?=htmlspecialchars($safe_pid ?: '--', ENT_QUOTES, 'UTF-8')?></span>
                                        </td>
                                        <td>
                                            <div class="p-name-cell">
                                                <span class="p-name-main"><?=htmlspecialchars($safe_pname ?: '（氏名未登録）', ENT_QUOTES, 'UTF-8')?></span>
                                                <?php if (!empty($wareki_sub)): ?>
                                                    <span class="p-birth-sub"><?=htmlspecialchars($wareki_sub, ENT_QUOTES, 'UTF-8')?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?=formatDepartment($p['department_code'] ?? '')?></td>
                                        <td>
                                            <span class="exam-date-text"><?=htmlspecialchars($p['exam_date'] ?? '', ENT_QUOTES, 'UTF-8')?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 【右カラム】選択患者の検査詳細・履歴（画面上部まで広げて縦スペース最大化！） -->
        <div class="col-right" style="flex:1; display:flex; flex-direction:column; min-height:0;">
            <div class="pane-right">
                <div class="pane-header">
                    <h3 class="pane-title">
                        <span>📊 患者検査詳細ビュー</span>
                    </h3>
                </div>

                <div class="pane-body" id="paneRightContent">
                    <?php
                    // 右ペインコンテンツを描画
                    renderDetailPaneContent($pdo, $target_pid, $timeline, $today_str, $keyword, $scope);
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentPid = <?=json_encode($target_pid, JSON_UNESCAPED_UNICODE)?>;
let currentTimeline = <?=json_encode($timeline, JSON_UNESCAPED_UNICODE)?>;
const currentKeyword = <?=json_encode($keyword, JSON_UNESCAPED_UNICODE)?>;
const currentScope = <?=json_encode($scope, JSON_UNESCAPED_UNICODE)?>;

/**
 * 左ペインで患者が選択された時（非同期で右ペインを即座に更新）
 */
function selectPatient(pid, rowElement) {
    if (!pid) return;
    currentPid = pid;

    // 左ペインのアクティブ選択スタイルを更新
    document.querySelectorAll('.clickable-row').forEach(r => r.classList.remove('selected'));
    if (rowElement) {
        rowElement.classList.add('selected');
    } else {
        const row = document.querySelector(`.clickable-row[data-pid="${pid}"]`);
        if (row) row.classList.add('selected');
    }

    const paneRight = document.getElementById('paneRightContent');
    if (!paneRight) return;
    paneRight.classList.add('pane-loading');

    // hidden input および ブラウザURLバーを同期
    const hiddenId = document.getElementById('hiddenPatientId');
    if (hiddenId) hiddenId.value = pid;

    const url = new URL(window.location.href);
    url.searchParams.set('patient_id', pid);
    url.searchParams.set('timeline', currentTimeline);
    window.history.replaceState({}, '', url.toString());

    // 非同期 fetch で詳細コンテンツを取得・差し替え
    const fetchUrl = `search_patient.php?ajax=detail&patient_id=${encodeURIComponent(pid)}&timeline=${encodeURIComponent(currentTimeline)}&keyword=${encodeURIComponent(currentKeyword)}&scope=${encodeURIComponent(currentScope)}`;
    
    fetch(fetchUrl)
        .then(response => {
            if (!response.ok) throw new Error('通信エラーが発生しました');
            return response.text();
        })
        .then(html => {
            paneRight.innerHTML = html;
            paneRight.classList.remove('pane-loading');
        })
        .catch(err => {
            console.error('Fetch error:', err);
            paneRight.innerHTML = '<div style="color:#ef4444; padding:20px; text-align:center;">データの取得に失敗しました。ページをリロードしてください。</div>';
            paneRight.classList.remove('pane-loading');
        });
}

/**
 * タイムライン切替（未来 / 過去）
 */
function switchTimeline(newTimeline) {
    if (currentTimeline === newTimeline) return;
    currentTimeline = newTimeline;

    const hiddenTl = document.getElementById('hiddenTimeline');
    if (hiddenTl) hiddenTl.value = newTimeline;

    // 現在選択中の患者IDで再取得
    if (currentPid) {
        selectPatient(currentPid);
    }
}

/**
 * 診療科フィルター（全科 / 胃腸科 / 肛門科）
 */
let currentDeptFilter = 'ALL';

function filterCandidates(dept) {
    currentDeptFilter = dept;

    // ボタンのactiveスタイル切り替え
    document.querySelectorAll('.dept-filter-btn').forEach(btn => {
        if (btn.dataset.deptFilter === dept) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    const rows = document.querySelectorAll('.master-table tbody .clickable-row');
    let visibleCount = 0;
    let firstVisibleRow = null;
    let selectedIsVisible = false;

    rows.forEach(row => {
        const rowDept = (row.dataset.dept || '').toUpperCase();
        const match = (dept === 'ALL' || rowDept === dept);
        if (match) {
            row.style.display = '';
            visibleCount++;
            if (!firstVisibleRow) firstVisibleRow = row;
            if (row.classList.contains('selected')) {
                selectedIsVisible = true;
            }
        } else {
            row.style.display = 'none';
        }
    });

    // 件数バッジの更新
    const badge = document.getElementById('candidateCountBadge');
    if (badge) {
        if (dept === 'ALL') {
            badge.textContent = `${visibleCount} 件`;
        } else {
            const totalCount = rows.length;
            badge.textContent = `${visibleCount} / ${totalCount} 件`;
        }
    }

    // もし現在選択されていた患者が絞り込みで非表示になった場合、表示中の1人目を自動選択
    if (!selectedIsVisible && firstVisibleRow) {
        const newPid = firstVisibleRow.dataset.pid;
        selectPatient(newPid, firstVisibleRow);
    }
}
</script>

</body>
</html>