<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\print_schedule_daily.php
 * ファイル名: print_schedule_daily.php
 * 概要: 日別内視鏡予約一覧 印刷専用モジュール (A4縦向き印刷対応)
 * 生成日時: 2026-10-20 22:35
 * 更新日時: 2026-11-08
 * 修正履歴:
 *   - 2026-11-08: 【基本時間枠カラー（waku_color）対応】
 *       1. 予約一覧表および他科VIP共有表の「時間枠」セル（col-time）に基本時間枠の背景色（waku_color）を反映（A4印刷時 print-color-adjust: exact 対応）
 *       2. 枠なし（休止）行およびマスタ不整合行では背景色を適用せず警告色（ピンク）を優先維持
 *   - 2026-11-06: 【時間枠世代管理対応】対象日（:date BETWEEN valid_from AND valid_to）の世代の時間枠マスタ（m2_waku）を参照して印刷一覧を生成するよう改修。
 *   - 2026-11-06: 【下剤表示（ビジクリア等）の先頭3文字略称化 ＆ 専用カラー・フォールバック色対応】
 *       1. 下剤（bowel_prep）に「ビジクリア」等の4文字以上の名称が設定された場合、先頭3文字（例: 「ビジク」）で略称表示する特殊処理を実装（通常一覧・他科VIP共有テーブル両対応）
 *       2. ビジクリア専用色（prep-visi: パープル #7c3aed）および未定義下剤フォールバック色（prep-other: スレート #64748b）を追加し、白地に白文字で印刷・表示から消えていた不具合を解消
 *   - 2026-10-30: 【タブレット印刷対応強化 ＆ サーバー直接印刷ハイブリッド ＆ それぞれ独立したプリンタ設定記憶】
 *       1. 印刷実行ボタン名を「🖨️ 本日リスト印刷」に変更し、Windowsは従来通り、タブレットはサーバー直接印刷のハイブリッド自動切替を実装
 *       2. 「🖨️ 出力先:」セレクターを新設し、おの_C2570複合機、クリニック_C2570、OKI B432など院内プリンタを自在に指示可能に
 *       3. 出力先プリンタ設定は「本日リスト印刷（endoscope_print_dest_daily）」と「ネームプレート印刷（endoscope_print_dest_nameplate）」で【それぞれ独立して端末記憶・固定】
 *       4. 「🏷️ ネームプレート印刷」ボタンを新設（大腸CF件数バッジ連動、print_nameplate.php へのシームレス連携）
 *       5. タッチデバイス対応: 左右スワイプによる日付前日/翌日移動機能を搭載（キーボード ← / → 連動）
 *       6. 各ボタンのタッチ領域最適化（touch-action: manipulation; user-select: none; 適用）
 *   - 2026-10-27: 【印刷日付表示の月日・曜日統一（タイトル同等サイズ） ＆ 隠し機能: 左右キー日付移動 ＆ ページ構成切替完全復元】
 *       1. 印刷ヘッダー日付: 「対象日: YYYY年MM月DD日」から年を省き「n月j日(曜)」（例: 10月27日(火)）へ変更
 *          - フォントサイズを用紙タイトルと同じ 12.5pt（太字・#0f172a）に拡大し、用紙両端の視認性とバランスを向上
 *          - 土曜は青、日曜は赤の一文字曜日カラーリング対応
 *       2. 隠し機能: キーボードの ← / → キー（ArrowLeft / ArrowRight）で日付を前日/翌日へ移動可能に
 *          - 現在の診療科・検査種別・空き枠・並び順パラメータを完全に保持して遷移
 *          - 入力フォーム（input, select, textarea）フォーカス時の誤爆防止ガード付き
 *       3. ページ構成切替の完全復元: ツールバーに「📄 ページ構成（2ページ分割 ⇔ 改ページなし全件連続）」セレクトを復元
 *          - #container-continuous を完全復元し、単一テーブル連続印刷（1ページ印刷）と2ページ分割印刷の両立を実現
 *       4. 帳票タイトル診療科表記の簡潔化: ［全診療科］、［肛門科］、［胃腸科］に統一（冗長な「（肛門科・胃腸科）」を除去）
 *       5. 部屋ソート時の空行撤去（時間枠ソート時のみ診療科境界に空行を挿入）
 *   - 2026-10-27: 【操作バー2段組化 ＆ 部屋・下剤一括ボタン設置】
 *       1. 画面上部操作バー（.no-print-bar）を最初から整然とした2段構成へデザイン刷新
 *          - 1段目: 診療科・種別・空き枠絞り込み ＆ 主アクション（部屋・下剤一括、印刷実行、閉じる）
 *          - 2段目: 印刷レイアウト設定（部屋ソート並び順、備考・手書きメモ余白切替）
 *       2. 「当日ホワイトボード」リンクを撤去し、「🚪 部屋・下剤一括」ボタンを新設（大腸CF件数バッジ付き）
 *       3. モーダル展開（bulk_edit_prep_room.php）および閉鎖時の自動リロードによる部屋割り即時反映に対応
 *   - 2026-10-27: 【並び順リストボックス化 ＆ 部屋ソート▲（昇順）新設】
 *       1. 上部バーの部屋ソートチェックボックスを「🚪 並び順:」リストボックスへ刷新
 *       2. 選択肢［時間枠］［部屋ソート▲］［部屋ソート▼］に対応（即時DOM並び替え＋localStorage記憶＋URL引数連動）
 *       3. 部屋ソート▲（昇順: 部屋名自然順昇順）を新規実装（部屋未指定患者は降順同様に最優先で先頭配置）
 *       4. 印刷用紙ヘッダーのバッジを「［部屋ソート▲］」「［部屋ソート▼］」に動的連動
 *   - 2026-10-21: ソート機能および区切り空行仕様の改修
 *       1. 上部バーに「部屋ソート（降順）」トグルスイッチ追加（即時DOM並び替え＋localStorage記憶対応）
 *       2. 部屋ソート（降順）の並び順ルール実装（未指定を先頭、部屋名を自然順降順、同部屋内は標準ソート順）
 *       3. 意味の変わる境界に行間空行（spacer-row, 高さ10px）の挿入機能追加
 *          - 標準ソート時: 診療科（PROCT/GASTRO）が変わる境界
 *          - 部屋ソート時: 部屋未指定⇔部屋あり、および部屋名称先頭1文字（ﾏ⇔ｺ等）が変わる境界
 *       4. 印刷用紙ヘッダーの「［部屋降順］」バッジ表示のトグル連動
 *   - 2026-10-20: A4縦印刷対応・使い勝手向上改修
 *       1. A4縦向き印刷に変更 (@page size: A4 portrait, 余白 8mm 6mm)
 *       2. 検査種別による2ブロック常時改ページ分割
 *          - 1ページ目: エコー・GIF (上部・超音波検査)
 *          - 2ページ目: CF・大腸内視鏡 (下部・ポリペク)
 *          - 各ページに専用見出しタイトル、ヘッダー（thead）自動繰り返し
 *       3. 各固定列（時間枠, 来院時刻, 患者No, 検査種別, 部屋, 下剤, 担当）の左右クリアランス・余白を極限まで圧縮 (padding: 3px 2px)
 *       4. 備考欄の印刷ON/OFF切り替え機能追加 (列ごと非表示・localStorage保持・JavaScript即時トグル)
 *   - 2026-09-08: 同一日内で部屋番号が重複している場合、部屋名称を赤太字・強調枠で警告表示
 *   - 2026-09-08: 枠重複の警告は撤去（枠重複は許容）
 *   - 2026-09-08: 生年月日の和暦表示化（convertToWareki）
 *   - 2026-09-08: 年齢計算を検査日時点の満年齢に修正（70歳以上: 濃いめオレンジ、69歳以下: 青）
 *   - 2026-09-08: 薬剤バッジ色を修正（モビ: 青、マグP: 濃いめオレンジ、ニフ: 緑）
 *   - 2026-09-08: 備考欄のRTF/HTMLデコード描画対応
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/printer_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/calendar_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$dept        = isset($_GET['dept']) ? (string)$_GET['dept'] : 'PROCT';
$date_str    = isset($_GET['date']) && is_string($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_exam = isset($_GET['exam']) ? trim((string)$_GET['exam']) : 'all';
$show_empty  = isset($_GET['show_empty']) ? ((string)$_GET['show_empty'] === '1') : false;
$sort_by     = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'time';
$page_split  = isset($_GET['page_split']) ? ((string)$_GET['page_split'] !== '0' && (string)$_GET['page_split'] !== 'false') : true;
if (!in_array($sort_by, ['time', 'room_asc', 'room_desc', 'asc', 'desc', 'room'], true)) {
    $sort_by = 'time';
}
if ($sort_by === 'asc') $sort_by = 'room_asc';
if ($sort_by === 'desc' || $sort_by === 'room') $sort_by = 'room_desc';

if (!in_array($dept, ['PROCT', 'GASTRO', 'ALL'], true)) { $dept = 'PROCT'; }

if ($dept === 'ALL') {
    $dept_label = '全診療科';
    $dept_condition_schedule = "1=1";
    $dept_condition_waku     = "1=1";
} else {
    $dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';
    $dept_condition_schedule = "s.department_code = '{$dept}'";
    $dept_condition_waku     = "department_code = '{$dept}'";
}

// 選択日の大腸CF件数（胃腸科・肛門科・TCF/W/ポリペク内訳）を取得（部屋・下剤一括ボタン用）
$cf_counts = getDailyCfCounts($pdo, $date_str);
$cf_tooltip_plain = "【" . date('n/j', strtotime($date_str)) . " 大腸CF件数】全体: {$cf_counts['total_cf']}件\n"
    . "・🔵 胃腸科: {$cf_counts['gastro']['total']}件 (TCF: {$cf_counts['gastro']['tcf']}, W: {$cf_counts['gastro']['w']}, ポリペク: {$cf_counts['gastro']['polypep']})\n"
    . "・🟢 肛門科: {$cf_counts['proct']['total']}件 (TCF: {$cf_counts['proct']['tcf']}, W: {$cf_counts['proct']['w']}, ポリペク: {$cf_counts['proct']['polypep']})\n"
    . "※クリックで部屋割り・下剤の一括設定画面を開きます";

/**
 * MALL3 RTF構文（NoteRTF / remarks）をHTML（カラー・太字・改行）へ完全変換
 */
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

    $html = preg_replace('/(?:<br>\s*)+$/i', '', trim($html));
    $html = preg_replace('/^(?:<br>\s*)+/i', '', $html);

    return $html;
}

function renderRemarksRtfHtml(?string $raw): string {
    if ($raw === null || $raw === '') return '';
    $trimmed = trim($raw);
    
    if (strpos($trimmed, '{\\rtf') === 0) {
        return '<div class="rich-remarks">' . parseRtfToHtml($trimmed) . '</div>';
    }
    if (preg_match('/<(?:p|br|strong|b|em|span|ul|ol|li)[^>]*>/i', $trimmed)) {
        $allowed = '<p><br><strong><b><em><span><ul><ol><li>';
        return '<div class="rich-remarks">' . strip_tags($trimmed, $allowed) . '</div>';
    }
    return '<div class="plain-remarks">' . nl2br(htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')) . '</div>';
}

// 予約データ取得SQL
if ($show_empty) {
    $sql = "
    WITH 
    all_slots AS (
        SELECT department_code, waku_name, display_order, group_code, COALESCE(waku_color, '') AS waku_color
        FROM m2_waku
        WHERE {$dept_condition_waku}
          AND is_active = TRUE
          AND :date BETWEEN valid_from AND valid_to
    ),
    reserved_data AS (
        SELECT 
            s.schedule_id, s.exam_date, s.scheduled_time, s.waku_no,
            s.patient_id, s.patient_name, s.patient_kana, s.birthday,
            s.doctor_name, s.exam_type, s.arrival_time, s.exam_location,
            s.remarks, s.room_number, s.bowel_prep, s.is_vip, s.is_canceled,
            s.department_code,
            w.display_order AS waku_display_order, w.group_code,
            COALESCE(w.waku_color, '') AS waku_color,
            CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
            FALSE AS is_empty
        FROM t_endoscopy_schedule s
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
            AND s.exam_date BETWEEN w.valid_from AND w.valid_to
        WHERE {$dept_condition_schedule} 
          AND s.exam_date = :date 
          AND s.is_canceled = FALSE
    )
    SELECT 
        COALESCE(r.scheduled_time, 'MISMATCH') AS scheduled_time,
        -1 AS waku_no,
        COALESCE(r.group_code, 'MISMATCH') AS group_code,
        COALESCE(r.waku_color, '') AS waku_color,
        r.department_code,
        COALESCE(r.patient_id, '') AS patient_id,
        COALESCE(r.patient_name, '') AS patient_name,
        COALESCE(r.birthday::text, '') AS birthday,
        COALESCE(r.doctor_name, '') AS doctor_name,
        COALESCE(r.exam_type, '') AS exam_type,
        COALESCE(r.arrival_time, '') AS arrival_time,
        COALESCE(r.remarks, '') AS remarks,
        COALESCE(r.room_number, '') AS room_number,
        COALESCE(r.bowel_prep, '') AS bowel_prep,
        COALESCE(r.schedule_id, 0) AS schedule_id,
        COALESCE(r.is_vip, FALSE) AS is_vip,
        FALSE AS is_empty,
        TRUE AS is_mismatch
    FROM reserved_data r
    WHERE r.is_mismatch = TRUE

    UNION ALL

    SELECT 
        a.waku_name AS scheduled_time,
        a.display_order AS waku_no,
        a.group_code,
        COALESCE(a.waku_color, '') AS waku_color,
        a.department_code,
        COALESCE(r.patient_id, '') AS patient_id,
        COALESCE(r.patient_name, '') AS patient_name,
        COALESCE(r.birthday::text, '') AS birthday,
        COALESCE(r.doctor_name, '') AS doctor_name,
        COALESCE(r.exam_type, '') AS exam_type,
        COALESCE(r.arrival_time, '') AS arrival_time,
        COALESCE(r.remarks, '') AS remarks,
        COALESCE(r.room_number, '') AS room_number,
        COALESCE(r.bowel_prep, '') AS bowel_prep,
        COALESCE(r.schedule_id, 0) AS schedule_id,
        COALESCE(r.is_vip, FALSE) AS is_vip,
        CASE WHEN r.schedule_id IS NULL THEN TRUE ELSE FALSE END AS is_empty,
        FALSE AS is_mismatch
    FROM all_slots a
    LEFT JOIN reserved_data r 
        ON a.waku_name = r.scheduled_time 
        AND a.department_code = r.department_code
    ORDER BY is_mismatch DESC, department_code ASC, waku_no ASC, schedule_id ASC;
    ";
} else {
    $sql = "
    SELECT 
        s.*,
        w.display_order AS waku_display_order,
        COALESCE(w.group_code, 'MISMATCH') AS group_code,
        COALESCE(w.waku_color, '') AS waku_color,
        CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
        FALSE AS is_empty
    FROM t_endoscopy_schedule s
    LEFT JOIN m2_waku w 
        ON s.department_code = w.department_code 
        AND s.scheduled_time = w.waku_name
        AND w.is_active = TRUE
        AND s.exam_date BETWEEN w.valid_from AND w.valid_to
    WHERE {$dept_condition_schedule} 
      AND s.exam_date = :date 
      AND s.is_canceled = FALSE
    ORDER BY is_mismatch DESC, s.department_code ASC, COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC;
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([':date' => $date_str]);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 他科 注目検査（VIP）予約の取得（自科がPROCTならGASTRO、GASTROならPROCT、ALLなら不要）
$other_dept_vips = [];
$other_dept_label = "";
if ($dept === "PROCT" || $dept === "GASTRO") {
    $other_dept = ($dept === "PROCT") ? "GASTRO" : "PROCT";
    $other_dept_label = ($other_dept === "GASTRO") ? "胃腸科" : "肛門科";
    $vip_sql = "
        SELECT 
            s.*,
            COALESCE(w.display_order, 9999) AS waku_display_order,
            COALESCE(w.waku_color, '') AS waku_color
        FROM t_endoscopy_schedule s
        LEFT JOIN m2_waku w 
            ON s.department_code = w.department_code 
            AND s.scheduled_time = w.waku_name
            AND w.is_active = TRUE
            AND s.exam_date BETWEEN w.valid_from AND w.valid_to
        WHERE s.department_code = :other_dept 
          AND s.exam_date = :date 
          AND s.is_canceled = FALSE
          AND s.is_vip = TRUE
        ORDER BY s.scheduled_time ASC, COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC
    ";
    $vip_stmt = $pdo->prepare($vip_sql);
    $vip_stmt->execute([":other_dept" => $other_dept, ":date" => $date_str]);
    $other_dept_vips = $vip_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 対象日の有効時間枠マップ（単発名称変更された枠でも基本枠カラーを解決できるように保持）
$effective_waku_by_dept = [
    'PROCT'  => getEffectiveWakuList($pdo, 'PROCT', $date_str),
    'GASTRO' => getEffectiveWakuList($pdo, 'GASTRO', $date_str),
];
foreach ($other_dept_vips as &$vip_row) {
    $vip_row['waku_color'] = resolveEffectiveWakuColorForRow($vip_row, $effective_waku_by_dept);
}
unset($vip_row);

// データ仕分け：全件連続（$rows_all）、エコー+GIF（1ページ目）と CF・大腸内視鏡（2ページ目）
$rows_all      = [];
$rows_echo_gif = [];
$rows_cf       = [];
$room_counts   = []; // 当日内の部屋重複カウント集計

foreach ($raw_data as $row) {
    $row['waku_color'] = resolveEffectiveWakuColorForRow($row, $effective_waku_by_dept);
    $is_empty    = isset($row['is_empty']) && $row['is_empty'] === true;
    $is_mismatch = isset($row['is_mismatch']) && $row['is_mismatch'] === true;
    $exam        = (string)($row['exam_type'] ?? '');
    $group       = (string)($row['group_code'] ?? 'OTHER');

    $row_cat = 'OTHER';
    if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') {
        $row_cat = 'GIF';
    } elseif ($exam === 'ECHO' || $exam === 'エコー') {
        $row_cat = 'ECHO';
    } elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') {
        $row_cat = 'TCF';
    }

    $final_cat = ($row_cat !== 'OTHER') ? $row_cat : $group;

    if ($filter_exam !== 'all') {
        $target_exams = explode(',', $filter_exam);
        if (!$is_mismatch && !in_array($final_cat, $target_exams, true)) {
            continue;
        }
    }

    // 全件連続一覧用に追加
    $rows_all[] = $row;

    // CF系（TCF/W/ポリペク）とエコー+GIF系（その他含む）にグループ分け
    if ($final_cat === 'TCF') {
        $rows_cf[] = $row;
    } else {
        $rows_echo_gif[] = $row;
    }

    // 予約が入っている有効な部屋番号を集計
    if (!$is_empty) {
        $rm = trim((string)($row['room_number'] ?? ''));
        if ($rm !== '' && $rm !== '未指定' && $rm !== '-') {
            $room_counts[$rm] = ($room_counts[$rm] ?? 0) + 1;
        }
    }
}

// 並び替え処理：初期HTMLは常に標準ソート順（時間枠・表示順）で出力し、
// クライアント側JavaScriptで即時トグル（標準ソート ⇔ 部屋ソート降順）およびlocalStorage記憶を実行


/**
 * 予約テーブルHTML描画関数
 */
function renderScheduleTable(array $rows, string $date_str, array $room_counts): void {
    if (empty($rows)) return;
    ?>
    <table class="schedule-table">
        <thead>
            <tr>
                <th class="col-time">時間枠</th>
                <th class="col-arrival">来院</th>
                <th class="col-patient-id">患者No</th>
                <th class="col-patient-name">患者名</th>
                <th class="col-exam">検査種別</th>
                <th class="col-room">部屋</th>
                <th class="col-prep">下剤</th>
                <th class="col-doctor">担当</th>
                <th class="col-remarks">
                    <span class="remarks-title-text">備考</span>
                    <span class="memo-title-text" style="display:none;">手書きメモ</span>
                </th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $prev_dept = null;
            foreach ($rows as $idx => $row): 
                $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
                $is_mismatch = isset($row['is_mismatch']) && $row['is_mismatch'] === true;
                
                $exam = (string)($row['exam_type'] ?? '');
                $cls = 'other';
                if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') $cls = 'gif';
                elseif ($exam === 'ECHO' || $exam === 'エコー') $cls = 'echo';
                elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') $cls = 'tcf';

                $time_disp = (string)($row['scheduled_time'] ?? '---');
                if ($is_mismatch) {
                    $time_disp = '🚨 迷子 (' . $time_disp . ')';
                } elseif ($time_disp === 'MISMATCH' || empty($time_disp)) {
                    $time_disp = '🚨 枠未設定';
                }

                $row_dept = (string)($row['department_code'] ?? 'PROCT');
                $dept_char = ($row_dept === 'GASTRO') ? '胃' : '肛';
                $dept_badge_cls = ($row_dept === 'GASTRO') ? 'GASTRO' : 'PROCT';

                $arrival_disp = (string)($row['arrival_time'] ?? '');
                if (empty($arrival_disp) && !$is_empty) $arrival_disp = '---';

                $patient_id_disp = $is_empty ? '' : (string)($row['patient_id'] ?? '---');
                $patient_name_disp = $is_empty ? '［空き枠］' : (string)($row['patient_name'] ?? '');
                $doctor_name_disp = $is_empty ? '' : (string)($row['doctor_name'] ?? '');

                // 年齢計算＆和暦変換
                $age_int = null;
                $age_cls = 'age-under';
                $b_date_clean = '';
                $b_wareki = '';
                if (!$is_empty && !empty($row['birthday'])) {
                    $b_date_clean = substr(trim((string)$row['birthday']), 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_date_clean)) {
                        $b_dt = new DateTime($b_date_clean);
                        $t_dt = new DateTime($date_str);
                        $age_int = $b_dt->diff($t_dt)->y;
                        if ($age_int >= 70) {
                            $age_cls = 'age-senior';
                        } else {
                            $age_cls = 'age-under';
                        }
                        $b_wareki = convertToWareki($b_date_clean);
                    }
                }

                // 部屋設定＆重複判定
                $room_val = trim((string)($row['room_number'] ?? ''));
                $clean_room = ($room_val === '-' || $room_val === '未指定') ? '' : $room_val;
                $room_first_char = ($clean_room !== '') ? mb_substr($clean_room, 0, 1, 'UTF-8') : '';

                $is_room_duplicate = false;
                if (!$is_empty && $clean_room !== '') {
                    if (isset($room_counts[$room_val]) && $room_counts[$room_val] > 1) {
                        $is_room_duplicate = true;
                    }
                }

                // 下剤カラー（モビ: 青, マグP: 濃いめオレンジ, ニフ: 緑, ビジクリア: 紫 #7c3aed, その他: スレート #64748b）
                $prep_val = trim((string)($row['bowel_prep'] ?? ''));
                $prep_cls = 'prep-other';
                if (mb_strpos($prep_val, 'モビ') === 0) {
                    $prep_cls = 'prep-mobi';
                } elseif (mb_strpos($prep_val, 'マグ') === 0) {
                    $prep_cls = 'prep-magp';
                } elseif (mb_strpos($prep_val, 'ニフ') === 0) {
                    $prep_cls = 'prep-nif';
                } elseif (mb_strpos($prep_val, 'ビジ') === 0) {
                    $prep_cls = 'prep-visi';
                }
                // 特殊処理: 4文字以上の下剤名称（ビジクリア等）は先頭3文字で略称表示
                $prep_disp = (mb_strlen($prep_val, 'UTF-8') > 3) ? mb_substr($prep_val, 0, 3, 'UTF-8') : $prep_val;

                // 【標準ソート時】診療科が変わる境界にわずかな空行（高さ10px）を挿入
                if ($idx > 0 && $prev_dept !== null && $prev_dept !== $row_dept) {
                    echo '<tr class="spacer-row"><td colspan="9"></td></tr>';
                }
                $prev_dept = $row_dept;

                $remarks_raw = (string)($row['remarks'] ?? '');
                $p_name_raw  = trim((string)($row['patient_name'] ?? ''));
                $is_real_patient = ($p_name_raw !== '' && $p_name_raw !== '※枠なし' && mb_strpos($p_name_raw, '［') !== 0);
                $is_no_waku = (!$is_empty && !$is_real_patient && (trim($remarks_raw) === '＊＊＊🚫枠なし🚫＊＊＊' || $p_name_raw === '※枠なし' || mb_strpos($remarks_raw, '枠なし') !== false || !empty($row['is_suspended'])));

                $waku_color = sanitizeWakuColor((string)($row['waku_color'] ?? ''));
                $waku_td_style = '';
                if ($waku_color !== '' && !$is_no_waku && !$is_mismatch) {
                    $waku_td_style = ' style="background-color:' . htmlspecialchars($waku_color, ENT_QUOTES, 'UTF-8') . ' !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;"';
                }
                $tr_row_style = '';
                if ($is_mismatch) {
                    $tr_row_style = 'style="background-color:#fee2e2 !important;"';
                } elseif ($is_no_waku) {
                    $tr_row_style = 'style="background-color:#fff1f2 !important;"';
                } elseif ($is_empty) {
                    $tr_row_style = 'style="color:#64748b;"';
                }
            ?>
                <tr class="schedule-row <?= ($idx % 2 === 1) ? 'row-even' : '' ?>"
                    data-standard-idx="<?= $idx ?>"
                    data-dept="<?= htmlspecialchars($row_dept, ENT_QUOTES, 'UTF-8') ?>"
                    data-room="<?= htmlspecialchars($clean_room, ENT_QUOTES, 'UTF-8') ?>"
                    data-room-first="<?= htmlspecialchars($room_first_char, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $tr_row_style ?>>
                    <!-- 時間枠 -->
                    <td class="col-time"<?= $waku_td_style ?>>
                        <span class="dept-icon-badge <?= $dept_badge_cls ?>"><?= $dept_char ?></span>
                        <strong><?= htmlspecialchars($time_disp, ENT_QUOTES, 'UTF-8') ?></strong>
                    </td>

                    <!-- 来院時刻 -->
                    <td class="col-arrival"><?= htmlspecialchars($arrival_disp, ENT_QUOTES, 'UTF-8') ?></td>

                    <!-- 患者No -->
                    <td class="col-patient-id"><?= htmlspecialchars($patient_id_disp, ENT_QUOTES, 'UTF-8') ?></td>
                    
                    <!-- 患者名 -->
                    <td class="col-patient-name">
                        <?php if ($is_empty): ?>
                            <span style="font-weight:bold; color:#64748b;">［空き枠］</span>
                        <?php else: ?>
                            <div class="patient-line">
                                <?php if (!empty($row['is_vip'])): ?><span class="vip-badge" title="注目検査">V</span><?php endif; ?><strong class="p-name"><?= htmlspecialchars($patient_name_disp, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($age_int !== null): ?>
                                    <span class="age-box <?= $age_cls ?>"><?= $age_int ?>歳</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($b_wareki !== ''): ?>
                                <div class="b-wareki">
                                    🎂 <?= htmlspecialchars($b_wareki, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <!-- 検査種別 -->
                    <td class="col-exam">
                        <?php if (!$is_empty): ?>
                            <span class="exam-badge <?= $cls ?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- 部屋表示：重複時は赤太字・強調 -->
                    <td class="col-room">
                        <?php if (!$is_empty && !empty($room_val) && $room_val !== '未指定'): ?>
                            <span class="room-badge <?= $is_room_duplicate ? 'room-duplicate' : '' ?>">
                                <?= $is_room_duplicate ? '⚠️ ' : '' ?><?= htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <!-- 下剤表示 -->
                    <td class="col-prep">
                        <?php if (!$is_empty && !empty($prep_val) && $prep_val !== '未指定'): ?>
                            <span class="prep-badge <?= $prep_cls ?>" title="<?= htmlspecialchars($prep_val, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($prep_disp, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <!-- 担当 -->
                    <td class="col-doctor"><?= htmlspecialchars($doctor_name_disp, ENT_QUOTES, 'UTF-8') ?></td>

                    <!-- 備考欄（RTF/リッチテキスト対応） -->
                    <td class="col-remarks">
                        <?= renderRemarksRtfHtml((string)($row['remarks'] ?? '')) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * 他科 注目検査（VIP）共有テーブルHTML描画関数
 */
function renderOtherDeptVipTable(array $vips, string $other_dept_label, string $date_str): void {
    if (empty($vips)) return;
    ?>
    <div class="vip-share-box">
        <div class="vip-share-title">
            <span class="vip-badge">V</span>
            <span>【他科 注目検査（<?= htmlspecialchars($other_dept_label, ENT_QUOTES, 'UTF-8') ?>）共有】</span>
            <span style="font-weight:normal; font-size:7.5pt; color:#6b21a8; margin-left:6px;">※他科で設定された注目患者（VIP）です</span>
        </div>
        <table class="schedule-table vip-share-table">
            <thead>
                <tr>
                    <th class="col-time">時間枠</th>
                    <th class="col-arrival">来院</th>
                    <th class="col-patient-id">患者No</th>
                    <th class="col-patient-name">患者名</th>
                    <th class="col-exam">検査種別</th>
                    <th class="col-room">部屋</th>
                    <th class="col-prep">下剤</th>
                    <th class="col-doctor">担当</th>
                    <th class="col-remarks">備考</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($vips as $idx => $row): 
                    $exam = (string)($row['exam_type'] ?? '');
                    $cls = 'other';
                    if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') $cls = 'gif';
                    elseif ($exam === 'ECHO' || $exam === 'エコー') $cls = 'echo';
                    elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') $cls = 'tcf';

                    $time_disp = (string)($row['scheduled_time'] ?? '---');
                    $row_dept = (string)($row['department_code'] ?? 'GASTRO');
                    $dept_char = ($row_dept === 'GASTRO') ? '胃' : '肛';
                    $dept_badge_cls = ($row_dept === 'GASTRO') ? 'GASTRO' : 'PROCT';

                    $arrival_disp = (string)($row['arrival_time'] ?? '');
                    if (empty($arrival_disp)) $arrival_disp = '---';

                    $patient_id_disp = (string)($row['patient_id'] ?? '---');
                    $patient_name_disp = (string)($row['patient_name'] ?? '');
                    $doctor_name_disp = (string)($row['doctor_name'] ?? '');

                    // 年齢計算＆和暦変換
                    $age_int = null;
                    $age_cls = 'age-under';
                    $b_wareki = '';
                    if (!empty($row['birthday'])) {
                        $b_date_clean = substr(trim((string)$row['birthday']), 0, 10);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_date_clean)) {
                            $b_dt = new DateTime($b_date_clean);
                            $t_dt = new DateTime($date_str);
                            $age_int = $b_dt->diff($t_dt)->y;
                            $age_cls = ($age_int >= 70) ? 'age-senior' : 'age-under';
                            $b_wareki = convertToWareki($b_date_clean);
                        }
                    }

                    $room_val = trim((string)($row['room_number'] ?? ''));
                    $prep_val = trim((string)($row['bowel_prep'] ?? ''));
                    $prep_cls = 'prep-other';
                    if (mb_strpos($prep_val, 'モビ') === 0) $prep_cls = 'prep-mobi';
                    elseif (mb_strpos($prep_val, 'マグ') === 0) $prep_cls = 'prep-magp';
                    elseif (mb_strpos($prep_val, 'ニフ') === 0) $prep_cls = 'prep-nif';
                    elseif (mb_strpos($prep_val, 'ビジ') === 0) $prep_cls = 'prep-visi';
                    $prep_disp = (mb_strlen($prep_val, 'UTF-8') > 3) ? mb_substr($prep_val, 0, 3, 'UTF-8') : $prep_val;
                    $vip_waku_color = sanitizeWakuColor((string)($row['waku_color'] ?? ''));
                    $vip_waku_td_style = ($vip_waku_color !== '') ? ' style="background-color:' . htmlspecialchars($vip_waku_color, ENT_QUOTES, 'UTF-8') . ' !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;"' : '';
                ?>
                    <tr class="schedule-row <?= ($idx % 2 === 1) ? 'row-even' : '' ?>">
                        <td class="col-time"<?= $vip_waku_td_style ?>>
                            <span class="dept-icon-badge <?= $dept_badge_cls ?>"><?= $dept_char ?></span>
                            <strong><?= htmlspecialchars($time_disp, ENT_QUOTES, 'UTF-8') ?></strong>
                        </td>
                        <td class="col-arrival"><?= htmlspecialchars($arrival_disp, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="col-patient-id"><?= htmlspecialchars($patient_id_disp, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="col-patient-name">
                            <div class="patient-line">
                                <span class="vip-badge" title="注目検査">V</span>
                                <strong class="p-name"><?= htmlspecialchars($patient_name_disp, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($age_int !== null): ?>
                                    <span class="age-box <?= $age_cls ?>"><?= $age_int ?>歳</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($b_wareki !== ''): ?>
                                <div class="b-wareki">
                                    🎂 <?= htmlspecialchars($b_wareki, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="col-exam">
                            <span class="exam-badge <?= $cls ?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td class="col-room">
                            <?= (!empty($room_val) && $room_val !== '未指定' && $room_val !== '-') ? htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') : '-' ?>
                        </td>
                        <td class="col-prep">
                            <?php if (!empty($prep_val) && $prep_val !== '未指定' && $prep_val !== '-'): ?>
                                <span class="prep-badge <?= $prep_cls ?>" title="<?= htmlspecialchars($prep_val, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($prep_disp, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="col-doctor"><?= htmlspecialchars($doctor_name_disp, ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="col-remarks">
                            <?= renderRemarksRtfHtml((string)($row['remarks'] ?? '')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内視鏡予約一覧印刷 - <?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; }
        
        @page {
            size: A4 portrait;
            margin: 8mm 6mm;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 8.5pt;
            color: #0f172a;
            background: #cbd5e1;
            margin: 0;
            padding: 0;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* 画面上部操作バー（最初から整然とした2段構成） */
        .no-print-bar {
            background: #1e293b;
            color: #fff;
            padding: 6px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .no-print-bar-row {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .no-print-bar-row-top {
            justify-content: space-between;
            gap: 12px;
        }
        .no-print-bar-row-bottom {
            background: rgba(15, 23, 42, 0.45);
            padding: 3px 8px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            gap: 8px;
            display: flex;
            align-items: center;
        }
        .no-print-bar select, .no-print-bar input[type="text"] {
            padding: 2.5px 6px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            font-size: 11px;
            font-weight: bold;
            background: #fff;
            color: #0f172a;
        }
        .bar-control-pill {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: bold;
            background: #334155;
            padding: 2.5px 8px;
            border-radius: 4px;
            user-select: none;
            font-size: 11px;
            color: #f1f5f9;
        }
        .bar-control-pill select {
            background: #1e293b;
            color: #fff;
            border: 1px solid #64748b;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
        }
        .bar-control-pill select:hover {
            border-color: #94a3b8;
        }
        .compact-bar-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            height: 26px;
            padding: 0 10px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            color: #ffffff;
            transition: all 0.15s ease;
            box-sizing: border-box;
            white-space: nowrap;
            text-decoration: none;
        }
        .compact-bar-btn-prep {
            background: #0d9488;
            box-shadow: 0 1px 3px rgba(13, 148, 136, 0.3);
        }
        .compact-bar-btn-prep:hover {
            background: #0f766e;
            box-shadow: 0 2px 6px rgba(13, 148, 136, 0.4);
        }
        .compact-bar-btn-nameplate {
            background: #6366f1;
            box-shadow: 0 1px 3px rgba(99, 102, 241, 0.3);
        }
        .compact-bar-btn-nameplate:hover {
            background: #4f46e5;
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.4);
        }
        .compact-mini-pill {
            background: #ffffff;
            color: #0d9488;
            font-size: 10px;
            font-weight: 800;
            padding: 0 5px;
            border-radius: 8px;
            line-height: 14px;
            display: inline-block;
        }
        .btn-print, .btn-close, .compact-bar-btn {
            touch-action: manipulation;
            user-select: none;
            -webkit-user-select: none;
        }
        .btn-print {
            background: #10b981;
            color: #fff;
            border: none;
            height: 26px;
            padding: 0 14px;
            font-size: 11.5px;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s;
        }
        .btn-print:hover { background: #059669; }
        .btn-close {
            background: #475569;
            color: #fff;
            border: none;
            height: 26px;
            padding: 0 12px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 11.5px;
            display: inline-flex;
            align-items: center;
            transition: background 0.15s;
        }
        .btn-close:hover { background: #334155; }

        /* サーバー直接印刷セレクター & コントロール */
        .printer-select-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #0f172a;
            border: 1px solid #475569;
            border-radius: 4px;
            padding: 2px 6px;
            height: 26px;
            color: #cbd5e1;
            font-size: 11.5px;
            font-weight: 600;
        }
        .printer-select-pill select {
            background: transparent;
            color: #38bdf8;
            border: none;
            outline: none;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            max-width: 175px;
        }
        .printer-select-pill select option {
            background: #1e293b;
            color: #f8fafc;
        }

        /* トースト通知 */
        .server-print-toast {
            position: fixed;
            top: 54px;
            right: 20px;
            z-index: 99999;
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid #38bdf8;
            border-radius: 8px;
            padding: 10px 16px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            transform: translateY(-20px);
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
        }
        .server-print-toast.show {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }
        .server-print-toast.toast-success { border-color: #10b981; }
        .server-print-toast.toast-error { border-color: #ef4444; }

        /* 印刷用紙コンテナ (画面上ではA4カードプレビュー表示) */
        .print-page {
            background: #fff;
            max-width: 210mm;
            min-height: 280mm;
            margin: 16px auto;
            padding: 8mm 6mm;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            border-radius: 4px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .sheet-content {
            flex-grow: 1;
        }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 3px;
            margin-bottom: 6px;
        }
        .sheet-title {
            font-size: 12.5pt;
            font-weight: 800;
            letter-spacing: -0.01em;
            color: #0f172a;
        }
        .sheet-meta {
            font-size: 8pt;
            font-weight: bold;
            color: #334155;
            text-align: right;
            line-height: 1.2;
            display: flex;
            align-items: baseline;
            justify-content: flex-end;
            gap: 3px;
            white-space: nowrap;
        }
        .sheet-date-text {
            font-size: 12.5pt;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.01em;
        }
        .sheet-date-weekday {
            font-size: 12.5pt;
            font-weight: 800;
        }
        .sheet-date-weekday.sat { color: #2563eb; }
        .sheet-date-weekday.sun { color: #dc2626; }

        /* テーブル基本設定 */
        table.schedule-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            table-layout: auto;
        }
        table.schedule-table th, 
        table.schedule-table td {
            border: 1px solid #64748b;
            padding: 2.5px 2px; /* 限界までクリアランスを詰める */
            text-align: left;
            vertical-align: middle;
            line-height: 1.2;
        }
        table.schedule-table th {
            background: #f1f5f9 !important;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            font-size: 7.5pt;
            white-space: nowrap;
            padding: 4px 2px;
        }
        table.schedule-table tr.schedule-row.row-even td {
            background-color: #fafafa;
        }

        /* 境界の空行（わずかな空行：高さ10px・罫線なし） */
        tr.spacer-row td {
            height: 10px !important;
            padding: 0 !important;
            border-top: none !important;
            border-bottom: none !important;
            border-left: none !important;
            border-right: none !important;
            background: transparent !important;
        }

        /* 列幅とアライメントの最適化（クリアランス限界圧縮） */
        .col-time {
            width: 1%;
            white-space: nowrap;
            padding-left: 2px !important;
            padding-right: 2px !important;
        }
        .col-arrival {
            width: 1%;
            white-space: nowrap;
            text-align: center !important;
            font-size: 7.5pt;
        }
        .col-patient-id {
            width: 1%;
            white-space: nowrap;
            text-align: center !important;
            font-family: Consolas, "Courier New", monospace;
            font-size: 7.5pt;
            font-weight: bold;
        }
        .col-patient-name {
            min-width: 90px;
            padding-left: 3px !important;
            padding-right: 3px !important;
        }
        .col-exam {
            width: 1%;
            white-space: nowrap;
            text-align: center !important;
        }
        .col-room {
            width: 1%;
            white-space: nowrap;
            text-align: center !important;
        }
        .col-prep {
            width: 1%;
            white-space: nowrap;
            text-align: center !important;
        }
        .col-doctor {
            width: 1%;
            white-space: nowrap;
            text-align: center !important;
            font-size: 7.5pt;
        }
        .col-remarks {
            font-size: 7pt;
            word-break: break-all;
            padding-left: 4px !important;
            padding-right: 4px !important;
        }

        /* 備考欄OFF（手書きメモ余白モード）制御 */
        body.hide-remarks th.col-remarks .remarks-title-text {
            display: none !important;
        }
        body.hide-remarks th.col-remarks .memo-title-text {
            display: inline !important;
        }
        body.hide-remarks td.col-remarks > * {
            display: none !important;
        }
        body.hide-remarks th.col-remarks,
        body.hide-remarks td.col-remarks {
            width: 42% !important;
            min-width: 75mm !important;
        }
        body.hide-remarks tr.schedule-row td {
            height: 24px;
        }

        /* 患者名ブロック */
        .patient-line {
            display: flex;
            align-items: center;
            gap: 3px;
            flex-wrap: wrap;
        }
        .p-name {
            font-size: 8.5pt;
            font-weight: 800;
        }
        .b-wareki {
            font-size: 6.5pt;
            color: #64748b;
            line-height: 1;
            margin-top: 1px;
        }

        .dept-icon-badge {
            display: inline-block;
            width: 14px;
            height: 14px;
            line-height: 14px;
            text-align: center;
            font-size: 7pt;
            font-weight: 800;
            color: #ffffff !important;
            border-radius: 2px;
            margin-right: 2px;
            vertical-align: middle;
        }
        .dept-icon-badge.PROCT { background-color: #2563eb !important; }
        .dept-icon-badge.GASTRO { background-color: #059669 !important; }

        /* 注目検査（VIP）バッジ（四角囲み、黄色背景、黒枠、紫文字、BOLD） */
        .vip-badge {
            display: inline-block;
            background-color: #fde047;
            color: #6b21a8;
            border: 1.5px solid #000000;
            font-weight: 900;
            font-size: 8pt;
            padding: 0 3px;
            border-radius: 2px;
            line-height: 1.15;
            margin-right: 3px;
            vertical-align: middle;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* 他科 注目検査（VIP）共有テーブル */
        .vip-share-box {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 2px dashed #a855f7;
            page-break-inside: avoid;
        }
        .vip-share-title {
            font-size: 8.5pt;
            font-weight: bold;
            color: #6b21a8;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
        }
        table.vip-share-table {
            background-color: #fdfaff;
            border: 1.5px solid #a855f7;
        }
        table.vip-share-table th {
            background: #f3e8ff !important;
            color: #581c87;
            border: 1px solid #c084fc;
        }
        table.vip-share-table td {
            border: 1px solid #d8b4fe;
        }
        table.vip-share-table tr.schedule-row.row-even td {
            background-color: #fbf5ff;
        }

        .exam-badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 7pt;
            color: #fff !important;
            white-space: nowrap;
        }
        .exam-badge.gif { background-color: #0ea5e9 !important; }
        .exam-badge.tcf { background-color: #10b981 !important; }
        .exam-badge.echo { background-color: #8b5cf6 !important; }
        .exam-badge.other { background-color: #64748b !important; }

        /* 部屋バッジ（通常 / 重複警告） */
        .room-badge {
            display: inline-block;
            padding: 1px 4px;
            background: #eff6ff !important;
            color: #1e40af !important;
            border: 1px solid #bfdbfe;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: 800;
            white-space: nowrap;
        }
        .room-badge.room-duplicate {
            background-color: #fee2e2 !important;
            color: #dc2626 !important;
            border: 1.5px solid #ef4444 !important;
            font-weight: 900 !important;
        }

        /* 薬剤バッジ（モビ: 青, マグP: 濃いめオレンジ, ニフ: 緑, ビジクリア: 紫 #7c3aed, その他: スレート #64748b） */
        .prep-badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: 800;
            color: #ffffff !important;
            background-color: #64748b !important;
            white-space: nowrap;
        }
        .prep-badge.prep-mobi  { background-color: #2563eb !important; }
        .prep-badge.prep-magp  { background-color: #ea580c !important; }
        .prep-badge.prep-nif   { background-color: #16a34a !important; }
        .prep-badge.prep-visi  { background-color: #7c3aed !important; }
        .prep-badge.prep-other { background-color: #64748b !important; }

        /* 年齢バッジ（70歳以上: 濃いめオレンジ, 69歳以下: 青） */
        .age-box {
            display: inline-block;
            color: #ffffff !important;
            font-size: 6.5pt;
            font-weight: 800;
            line-height: 1;
            padding: 1px 3px;
            border-radius: 2px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .age-box.age-senior { background-color: #ea580c !important; }
        .age-box.age-under  { background-color: #2563eb !important; }

        .rich-remarks p, .plain-remarks { margin: 0; padding: 0; line-height: 1.25; }
        .rich-remarks ul, .rich-remarks ol { margin: 1px 0 1px 12px; padding: 0; }
        .rich-remarks strong, .rich-remarks b { font-weight: 800; }

        .print-footer {
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            color: #64748b;
            border-top: 1px solid #cbd5e1;
            padding-top: 3px;
        }

        /* 非表示コンテナの除外（画面＆印刷共通） */
        .print-container-split.is-hidden,
        .print-container-continuous.is-hidden {
            display: none !important;
        }

        /* 印刷時スタイル */
        @media print {
            .no-print-bar { display: none !important; }
            body {
                background: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .print-page {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
                min-height: auto !important;
                border-radius: 0 !important;
            }
            /* 2ページ目の強制改ページ */
            .print-page.page-break {
                page-break-before: always !important;
                break-before: page !important;
            }
            /* 非表示コンテナの印刷除外（クラス ＆ style 両対応） */
            .print-container-split.is-hidden,
            .print-container-continuous.is-hidden,
            .print-container-split[style*="display:none"],
            .print-container-split[style*="display: none"],
            .print-container-continuous[style*="display:none"],
            .print-container-continuous[style*="display: none"] {
                display: none !important;
            }
            thead {
                display: table-header-group;
            }
            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <!-- 1段目: タイトル、診療科・種別・空き枠、メインアクション -->
    <div class="no-print-bar-row no-print-bar-row-top">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            
            <form method="GET" id="filterForm" style="display:inline-flex; align-items:center; gap:8px; margin:0; flex-wrap:wrap;">
                <input type="hidden" name="date" value="<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="sort" id="formSort" value="<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="page_split" id="formPageSplit" value="<?= $page_split ? '1' : '0' ?>">
                
                <label style="display:inline-flex; align-items:center; gap:3px; font-size:11.5px; color:#cbd5e1;">診療科:
                    <select name="dept" onchange="this.form.submit()">
                        <option value="ALL" <?= $dept === 'ALL' ? 'selected' : '' ?>>全診療科</option>
                        <option value="PROCT" <?= $dept === 'PROCT' ? 'selected' : '' ?>>肛門科</option>
                        <option value="GASTRO" <?= $dept === 'GASTRO' ? 'selected' : '' ?>>胃腸科</option>
                    </select>
                </label>

                <label style="display:inline-flex; align-items:center; gap:3px; font-size:11.5px; color:#cbd5e1;">検査種別:
                    <select name="exam" onchange="this.form.submit()">
                        <option value="all" <?= $filter_exam === 'all' ? 'selected' : '' ?>>全種別</option>
                        <option value="GIF" <?= $filter_exam === 'GIF' ? 'selected' : '' ?>>GIF のみ</option>
                        <option value="ECHO" <?= $filter_exam === 'ECHO' ? 'selected' : '' ?>>エコー のみ</option>
                        <option value="TCF" <?= $filter_exam === 'TCF' ? 'selected' : '' ?>>TCF (大腸) のみ</option>
                    </select>
                </label>

                <label style="cursor:pointer; display:inline-flex; align-items:center; gap:3px; user-select:none; font-size:11.5px; color:#cbd5e1;">
                    <input type="checkbox" name="show_empty" value="1" <?= $show_empty ? 'checked' : '' ?> onchange="this.form.submit()">
                    空き枠を含む
                </label>
            </form>
        </div>

        <div style="display:flex; gap:6px; align-items:center;">
            <!-- 🏷️ ネームプレート印刷 ボタン -->
            <button type="button" onclick="openNameplatePrint('<?= $dept ?>', '<?= $date_str ?>')" 
                    class="compact-bar-btn compact-bar-btn-nameplate" 
                    title="下部検査患者のネームプレート（横147×縦70mm）印刷画面を開きます">
                <span>🏷️ ネームプレート印刷</span>
                <?php if ($cf_counts['total_cf'] > 0): ?>
                    <span class="compact-mini-pill"><?= $cf_counts['total_cf'] ?></span>
                <?php endif; ?>
            </button>

            <!-- 🖨️ 出力先プリンタ選択セレクター (本日リスト専用・端末ごとに独立記憶) -->
            <label class="printer-select-pill" title="印刷出力先を選択します（端末の通常印刷、またはサーバーから院内プリンタへの直接印刷）">
                <span>🖨️ 出力先:</span>
                <select id="selectPrintDestination" onchange="onPrintDestinationChange(this.value)">
                    <?= renderPrinterSelectOptions() ?>
                </select>
            </label>

            <!-- 🖨️ 当日リスト印刷 ボタン -->
            <button type="button" id="btnDailyPrintExecute" class="btn-print" onclick="executeListPrint()">
                <span id="btnDailyPrintText">🖨️ 当日リスト印刷</span>
            </button>

            <!-- ✕ 閉じる ボタン -->
            <button type="button" onclick="window.close()" class="btn-close">✕ 閉じる</button>
        </div>
    </div>

    <!-- サーバー印刷 トースト通知 -->
    <div id="serverPrintToast" class="server-print-toast">
        <span id="toastIcon">ℹ️</span>
        <span id="toastMessage">メッセージ</span>
    </div>

    <!-- 2段目: 印刷レイアウト・表示設定（ページ構成、並び順、備考・手書き余白） ＆ 部屋・下剤一括 -->
    <div class="no-print-bar-row no-print-bar-row-bottom">
        <!-- ページ構成（2ページ分割 ⇔ 改ページなし連続）切り替え -->
        <label class="bar-control-pill" title="印刷時のページ構成を切り替えます（2ページ分割 ⇔ 1つの表で連続印刷）">
            <span>📄 ページ構成:</span>
            <select id="selectPageSplit" onchange="changePageSplit(this.value)">
                <option value="1" <?= $page_split ? 'selected' : '' ?>>2ページ分割 (エコー/CF)</option>
                <option value="0" <?= !$page_split ? 'selected' : '' ?>>改ページなし (全件連続)</option>
            </select>
        </label>

        <!-- 並び順（時間枠 ⇔ 部屋ソート▲ ⇔ 部屋ソート▼）切り替え -->
        <label class="bar-control-pill" title="一覧の並び順（時間枠順・部屋番号順）を即時切り替えます">
            <span>🚪 並び順:</span>
            <select id="selectRoomSort" onchange="changeRoomSort(this.value)">
                <option value="time" <?= $sort_by === 'time' ? 'selected' : '' ?>>時間枠</option>
                <option value="room_asc" <?= $sort_by === 'room_asc' ? 'selected' : '' ?>>部屋ソート▲</option>
                <option value="room_desc" <?= $sort_by === 'room_desc' ? 'selected' : '' ?>>部屋ソート▼</option>
            </select>
        </label>

        <!-- 備考印刷ON/OFFスイッチ（OFF時は手書きメモ余白モード） -->
        <label class="bar-control-pill" title="チェックを外すと備考文を非表示にし、スタッフ手書き用メモスペースになります">
            <input type="checkbox" id="toggleRemarks" checked onchange="toggleRemarksColumn(this.checked)">
            <span id="labelRemarksToggle">📝 備考を印刷</span>
        </label>

        <!-- 🚪 部屋・下剤一括 ボタン (処理系が印刷とは異なるため2段目右端へ配置) -->
        <button type="button" onclick="openBulkPrepRoomModal('<?= $dept ?>', '<?= $date_str ?>')" 
                class="compact-bar-btn compact-bar-btn-prep" 
                style="margin-left:auto;"
                title="<?= htmlspecialchars($cf_tooltip_plain, ENT_QUOTES, 'UTF-8') ?>">
            <span>🚪 部屋・下剤一括</span>
            <?php if ($cf_counts['total_cf'] > 0): ?>
                <span class="compact-mini-pill"><?= $cf_counts['total_cf'] ?></span>
            <?php endif; ?>
        </button>

    </div>
</div>

<?php 
$has_echo_gif = !empty($rows_echo_gif);
$has_cf       = !empty($rows_cf);

// 印刷用日付表示（月・日・一文字曜日：内視鏡予約タイトルと同等サイズ 12.5pt）
$w_idx = (int)date('w', strtotime($date_str));
$weekday_jp = ['日', '月', '火', '水', '木', '金', '土'][$w_idx];
$weekday_cls = ($w_idx === 6) ? 'sat' : (($w_idx === 0) ? 'sun' : '');
$month_day_num = date('n月j日', strtotime($date_str));
$date_disp_print = $month_day_num . '<span class="sheet-date-weekday ' . $weekday_cls . '">(' . $weekday_jp . ')</span>';
?>

<?php if (empty($rows_all)): ?>
    <div class="print-page">
        <div class="sheet-content">
            <div class="sheet-header">
                <div class="sheet-title">内視鏡予約一覧表（<?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>）</div>
                <div class="sheet-meta">
                    <span class="sheet-date-text"><?= $date_disp_print ?></span>
                </div>
            </div>
            <p style="text-align:center; padding:60px 0; color:#64748b; font-size:11pt; font-weight:bold;">
                該当する予約はありません
            </p>
            <?php 
            if (!empty($other_dept_vips)) {
                renderOtherDeptVipTable($other_dept_vips, $other_dept_label, $date_str);
            }
            ?>
        </div>
        <div class="print-footer">
            <div>印刷日時: <?= date('Y-m-d H:i:s') ?></div>
            <div>医療法人小野会 おの肛門科胃腸科内視鏡クリニック</div>
        </div>
    </div>
<?php else: ?>
<?php
$room_badge_text = ($sort_by === 'room_asc') ? '［部屋ソート▲］' : (($sort_by === 'room_desc') ? '［部屋ソート▼］' : '');
$room_badge_style = !empty($room_badge_text) ? 'display:inline;' : 'display:none;';
?>

    <!-- ── 2ページ分割表示コンテナ（デフォルト） ── -->
    <div id="container-split" class="print-container-split <?= !$page_split ? 'is-hidden' : '' ?>" style="<?= $page_split ? '' : 'display:none;' ?>">
        <!-- 1ページ目：エコー・GIF (上部・超音波検査) -->
        <?php if ($has_echo_gif): ?>
            <div class="print-page print-page-echo-gif">
                <div class="sheet-content">
                    <div class="sheet-header">
                        <div class="sheet-title">内視鏡予約一覧表（エコー・GIF）［<?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>］</div>
                        <div class="sheet-meta">
                            <span class="sheet-date-text"><?= $date_disp_print ?></span>
                            <span style="font-size:8pt; font-weight:normal; margin-left:3px;">（<?= count($rows_echo_gif) ?> 件）</span>
                            <span class="room-sort-badge" style="color:#2563eb; margin-left:4px; <?= $room_badge_style ?>"><?= $room_badge_text ?></span>
                        </div>
                    </div>
                    <?php renderScheduleTable($rows_echo_gif, $date_str, $room_counts); ?>
                    <?php 
                    if (!$has_cf && !empty($other_dept_vips)) {
                        renderOtherDeptVipTable($other_dept_vips, $other_dept_label, $date_str);
                    }
                    ?>
                </div>
                <div class="print-footer">
                    <div>印刷日時: <?= date('Y-m-d H:i:s') ?></div>
                    <div>医療法人小野会 おの肛門科胃腸科内視鏡クリニック</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2ページ目：CF・大腸内視鏡 (下部・ポリペク) -->
        <?php if ($has_cf): ?>
            <div class="print-page print-page-cf <?= $has_echo_gif ? 'page-break' : '' ?>">
                <div class="sheet-content">
                    <div class="sheet-header">
                        <div class="sheet-title">内視鏡予約一覧表（CF・大腸内視鏡）［<?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>］</div>
                        <div class="sheet-meta">
                            <span class="sheet-date-text"><?= $date_disp_print ?></span>
                            <span style="font-size:8pt; font-weight:normal; margin-left:3px;">（<?= count($rows_cf) ?> 件）</span>
                            <span class="room-sort-badge" style="color:#2563eb; margin-left:4px; <?= $room_badge_style ?>"><?= $room_badge_text ?></span>
                        </div>
                    </div>
                    <?php renderScheduleTable($rows_cf, $date_str, $room_counts); ?>
                    <?php 
                    if (!empty($other_dept_vips)) {
                        renderOtherDeptVipTable($other_dept_vips, $other_dept_label, $date_str);
                    }
                    ?>
                </div>
                <div class="print-footer">
                    <div>印刷日時: <?= date('Y-m-d H:i:s') ?></div>
                    <div>医療法人小野会 おの肛門科胃腸科内視鏡クリニック</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── 改ページなし連続表示コンテナ ── -->
    <div id="container-continuous" class="print-container-continuous <?= $page_split ? 'is-hidden' : '' ?>" style="<?= !$page_split ? '' : 'display:none;' ?>">
        <div class="print-page print-page-all">
            <div class="sheet-content">
                <div class="sheet-header">
                    <div class="sheet-title">内視鏡予約一覧表［<?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>］</div>
                    <div class="sheet-meta">
                        <span class="sheet-date-text"><?= $date_disp_print ?></span>
                        <span style="font-size:8pt; font-weight:normal; margin-left:3px;">（<?= count($rows_all) ?> 件）</span>
                        <span class="room-sort-badge" style="color:#2563eb; margin-left:4px; <?= $room_badge_style ?>"><?= $room_badge_text ?></span>
                    </div>
                </div>
                <?php renderScheduleTable($rows_all, $date_str, $room_counts); ?>
                <?php 
                if (!empty($other_dept_vips)) {
                    renderOtherDeptVipTable($other_dept_vips, $other_dept_label, $date_str);
                }
                ?>
            </div>
            <div class="print-footer">
                <div>印刷日時: <?= date('Y-m-d H:i:s') ?></div>
                <div>医療法人小野会 おの肛門科胃腸科内視鏡クリニック</div>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
/**
 * 備考欄の表示/非表示トグル制御（localStorage記憶対応）
 */
function toggleRemarksColumn(show) {
    document.body.classList.toggle('hide-remarks', !show);
    var lbl = document.getElementById('labelRemarksToggle');
    if (lbl) {
        lbl.textContent = show ? '📝 備考を印刷' : '✏️ 手書きメモ余白';
    }
    try {
        localStorage.setItem('print_schedule_show_remarks', show ? '1' : '0');
    } catch (e) {
        console.warn('localStorage access failed:', e);
    }
}

/**
 * 部屋・下剤一括設定モーダル画面を開く
 */
function openBulkPrepRoomModal(dept, date) {
    var url = 'bulk_edit_prep_room.php?dept=' + encodeURIComponent(dept) + '&date=' + encodeURIComponent(date);
    var win = window.open(url, 'bulk_prep_room', 'width=920,height=780,scrollbars=yes,resizable=yes');
    if (win) {
        win.focus();
        // モーダルが閉じられたら印刷一覧を自動更新（部屋・下剤の変更を即時反映）
        var timer = setInterval(function() {
            if (win.closed) {
                clearInterval(timer);
                window.location.reload();
            }
        }, 1000);
    }
}
window.openBulkPrepRoomModal = openBulkPrepRoomModal;

/**
 * 並び順（時間枠 ⇔ 部屋ソート▲ ⇔ 部屋ソート▼）切り替え制御（即時DOM並び替え＋空行挿入＋localStorage記憶対応）
 */
function changeRoomSort(sortMode) {
    if (!sortMode || (sortMode !== 'room_asc' && sortMode !== 'room_desc' && sortMode !== 'time')) {
        sortMode = 'time';
    }

    try {
        localStorage.setItem('print_schedule_room_sort', sortMode);
    } catch (e) {
        console.warn('localStorage access failed:', e);
    }

    // フォーム同期
    var formSort = document.getElementById('formSort');
    if (formSort) {
        formSort.value = sortMode;
    }
    var selectRoomSort = document.getElementById('selectRoomSort');
    if (selectRoomSort && selectRoomSort.value !== sortMode) {
        selectRoomSort.value = sortMode;
    }

    // ヘッダーの［部屋ソート▲］［部屋ソート▼］バッジ表示切り替え
    document.querySelectorAll('.room-sort-badge').forEach(function(badge) {
        if (sortMode === 'room_asc') {
            badge.textContent = '［部屋ソート▲］';
            badge.style.display = 'inline';
        } else if (sortMode === 'room_desc') {
            badge.textContent = '［部屋ソート▼］';
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
    });

    // 各テーブルの並び替え & スペーサー行（空行）の再構築
    document.querySelectorAll('table.schedule-table').forEach(function(table) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        // 既存のスペーサー行をすべて削除
        tbody.querySelectorAll('tr.spacer-row').forEach(function(row) {
            row.remove();
        });

        // データ行（.schedule-row）を取得
        var rows = Array.from(tbody.querySelectorAll('tr.schedule-row'));
        if (rows.length === 0) return;

        if (sortMode === 'time') {
            // 【時間枠（標準ソート）】：元の出力順（data-standard-idx 昇順）
            rows.sort(function(a, b) {
                var idxA = parseInt(a.dataset.standardIdx, 10);
                var idxB = parseInt(b.dataset.standardIdx, 10);
                return idxA - idxB;
            });
        } else if (sortMode === 'room_asc') {
            // 【部屋ソート▲（昇順）】：
            // 1. 部屋未指定（空欄、-、未指定）を先頭
            // 2. 部屋名を自然順昇順
            // 3. 同部屋内は標準ソート順（時間枠順）
            rows.sort(function(a, b) {
                var roomA = (a.dataset.room || '').trim();
                var roomB = (b.dataset.room || '').trim();
                if (roomA === '-' || roomA === '未指定') roomA = '';
                if (roomB === '-' || roomB === '未指定') roomB = '';

                var emptyA = (roomA === '');
                var emptyB = (roomB === '');

                // 部屋なし（空文字）を先頭に
                if (emptyA && !emptyB) return -1;
                if (!emptyA && emptyB) return 1;

                var idxA = parseInt(a.dataset.standardIdx, 10);
                var idxB = parseInt(b.dataset.standardIdx, 10);

                if (emptyA && emptyB) {
                    return idxA - idxB;
                }

                // 部屋名の自然順昇順
                var cmp = roomA.localeCompare(roomB, 'ja', { numeric: true, sensitivity: 'base' });
                if (cmp !== 0) return cmp;

                return idxA - idxB;
            });
        } else if (sortMode === 'room_desc') {
            // 【部屋ソート▼（降順）】：
            // 1. 部屋未指定（空欄、-、未指定）を先頭
            // 2. 部屋名を自然順降順
            // 3. 同部屋内は標準ソート順（時間枠順）
            rows.sort(function(a, b) {
                var roomA = (a.dataset.room || '').trim();
                var roomB = (b.dataset.room || '').trim();
                if (roomA === '-' || roomA === '未指定') roomA = '';
                if (roomB === '-' || roomB === '未指定') roomB = '';

                var emptyA = (roomA === '');
                var emptyB = (roomB === '');

                // 部屋なし（空文字）を先頭に
                if (emptyA && !emptyB) return -1;
                if (!emptyA && emptyB) return 1;

                var idxA = parseInt(a.dataset.standardIdx, 10);
                var idxB = parseInt(b.dataset.standardIdx, 10);

                if (emptyA && emptyB) {
                    return idxA - idxB;
                }

                // 部屋名の自然順降順
                var cmp = roomB.localeCompare(roomA, 'ja', { numeric: true, sensitivity: 'base' });
                if (cmp !== 0) return cmp;

                return idxA - idxB;
            });
        }

        // DOM再配置 & 空行挿入 & ストライプ更新
        tbody.innerHTML = '';
        var prevDept = null;
        var prevRoomClean = null;
        var prevRoomFirst = null;

        rows.forEach(function(row, idx) {
            var currDept = (row.dataset.dept || '').trim();
            var currRoom = (row.dataset.room || '').trim();
            if (currRoom === '-' || currRoom === '未指定') currRoom = '';
            var currRoomFirst = (row.dataset.roomFirst || '').trim();

            var needSpacer = false;

            if (idx > 0) {
                if (sortMode === 'time') {
                    // 時間枠ソート（標準）：診療科が変わる境界に空行を挿入
                    if (prevDept !== null && currDept !== prevDept) {
                        needSpacer = true;
                    }
                }
                // 部屋ソート（昇順・降順）の時は空行不要のため spacer 挿入なし
            }

            if (needSpacer) {
                var spacer = document.createElement('tr');
                spacer.className = 'spacer-row';
                spacer.innerHTML = '<td colspan="9"></td>';
                tbody.appendChild(spacer);
            }

            // ストライプクラスの再適用
            row.classList.remove('row-even');
            if (idx % 2 === 1) {
                row.classList.add('row-even');
            }

            tbody.appendChild(row);

            prevDept = currDept;
            prevRoomClean = currRoom;
            prevRoomFirst = currRoomFirst;
        });
    });
}

/**
 * ページ構成（2ページ分割 ⇔ 改ページなし連続）切り替え制御（即時DOMトグル ＆ localStorage記憶対応）
 */
function changePageSplit(val) {
    var isSplit = (val === '1' || val === 1 || val === true || val === 'true');
    var splitCont = document.getElementById('container-split');
    var contCont = document.getElementById('container-continuous');

    if (splitCont) {
        splitCont.style.display = isSplit ? 'block' : 'none';
        splitCont.classList.toggle('is-hidden', !isSplit);
    }
    if (contCont) {
        contCont.style.display = isSplit ? 'none' : 'block';
        contCont.classList.toggle('is-hidden', isSplit);
    }

    var selectSplit = document.getElementById('selectPageSplit');
    if (selectSplit) {
        selectSplit.value = isSplit ? '1' : '0';
    }

    var formSplit = document.getElementById('formPageSplit');
    if (formSplit) {
        formSplit.value = isSplit ? '1' : '0';
    }

    try {
        localStorage.setItem('print_schedule_page_split', isSplit ? '1' : '0');
        var url = new URL(window.location.href);
        url.searchParams.set('page_split', isSplit ? '1' : '0');
        window.history.replaceState(null, '', url.toString());
    } catch (e) {
        console.warn('localStorage access failed:', e);
    }

    // 表示が切り替わった側のテーブルの並び順と空行・スタイルを最新化
    var savedSort = localStorage.getItem('print_schedule_room_sort') || 'time';
    var formSort = document.getElementById('formSort');
    if (formSort && formSort.value) {
        savedSort = formSort.value;
    }
    changeRoomSort(savedSort);
}
window.changePageSplit = changePageSplit;

// 後方互換性用ラッパー関数
window.toggleRoomSort = function(isRoomSort) {
    changeRoomSort(isRoomSort ? 'room_desc' : 'time');
};

// 初期ロード時にユーザーの前回設定を復元
document.addEventListener('DOMContentLoaded', function() {
    // ページ構成（2ページ分割 ⇔ 改ページなし）の前回設定復元（URL引数 page_split も考慮）
    try {
        var urlParams = new URLSearchParams(window.location.search);
        var urlSplit = urlParams.get('page_split');
        var savedSplit = localStorage.getItem('print_schedule_page_split');

        var isSplit = true; // デフォルトは2ページ分割
        if (urlSplit !== null) {
            isSplit = (urlSplit !== '0' && urlSplit !== 'false');
        } else if (savedSplit !== null) {
            isSplit = (savedSplit === '1');
        }
        changePageSplit(isSplit);
    } catch (e) {
        console.warn('localStorage access failed:', e);
    }

    // 備考欄の前回設定復元
    try {
        var savedRemarks = localStorage.getItem('print_schedule_show_remarks');
        if (savedRemarks === '0') {
            var chkRemarks = document.getElementById('toggleRemarks');
            if (chkRemarks) {
                chkRemarks.checked = false;
                toggleRemarksColumn(false);
            }
        }
    } catch (e) {
        console.warn('localStorage access failed:', e);
    }

    // 並び順（部屋ソート）の前回設定復元（URL引数 sort も考慮）
    try {
        var urlParams = new URLSearchParams(window.location.search);
        var urlSort = urlParams.get('sort');
        var savedSort = localStorage.getItem('print_schedule_room_sort');

        var currentSort = 'time';
        if (urlSort !== null) {
            if (urlSort === 'room_asc' || urlSort === 'asc') {
                currentSort = 'room_asc';
            } else if (urlSort === 'room_desc' || urlSort === 'desc' || urlSort === 'room') {
                currentSort = 'room_desc';
            } else if (urlSort === 'time') {
                currentSort = 'time';
            }
        } else if (savedSort !== null) {
            if (savedSort === 'room_asc' || savedSort === 'asc') {
                currentSort = 'room_asc';
            } else if (savedSort === 'room_desc' || savedSort === 'desc' || savedSort === '1') {
                currentSort = 'room_desc';
            } else {
                currentSort = 'time';
            }
        }

        changeRoomSort(currentSort);
    } catch (e) {
        console.warn('localStorage access failed:', e);
    }
});

// ── 隠し機能: キーボードの左右キー（← / →）で日付移動 ──
document.addEventListener('keydown', function(e) {
    var active = document.activeElement;
    var isInputActive = active && (
        active.tagName === 'INPUT' ||
        active.tagName === 'TEXTAREA' ||
        active.tagName === 'SELECT' ||
        active.isContentEditable
    );
    if (isInputActive) {
        return;
    }

    if (!e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            navigateDate(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            navigateDate(1);
        }
    }
});

function navigateDate(offsetDays) {
    var currentDateStr = '<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>';
    var parts = currentDateStr.split('-');
    if (parts.length !== 3) return;
    var dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    dt.setDate(dt.getDate() + offsetDays);

    var y = dt.getFullYear();
    var m = ('0' + (dt.getMonth() + 1)).slice(-2);
    var d = ('0' + dt.getDate()).slice(-2);
    var targetDate = y + '-' + m + '-' + d;

    var url = new URL(window.location.href);
    url.searchParams.set('date', targetDate);
    window.location.href = url.toString();
}

/**
 * 端末判別 & 出力先プリンタ制御（Windows: 従来通り / タブレット: サーバー直印刷）
 * ※ 本日リスト印刷専用の設定キー 'endoscope_print_dest_daily' で端末ごとに独立保存
 */
var isWindows = navigator.platform.indexOf('Win') > -1 || navigator.userAgent.indexOf('Windows') > -1;
var isTablet = /iPad|iPhone|iPod|Android/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

document.addEventListener('DOMContentLoaded', function() {
    var selectDest = document.getElementById('selectPrintDestination');
    if (selectDest) {
        var savedDest = localStorage.getItem('endoscope_print_dest_daily');
        // Windowsなら従来通り 'local'、タブレットなら 'おの_C2570 複合機' がデフォルト
        var defaultDest = isWindows ? 'local' : 'おの_C2570 複合機';
        var activeDest = savedDest || defaultDest;

        var hasOption = Array.from(selectDest.options).some(function(opt) { return opt.value === activeDest; });
        if (hasOption) {
            selectDest.value = activeDest;
        } else {
            selectDest.value = 'local';
        }
        updateDailyPrintButtonLabel(selectDest.value);
    }
});

function onPrintDestinationChange(val) {
    localStorage.setItem('endoscope_print_dest_daily', val);
    updateDailyPrintButtonLabel(val);
}

function updateDailyPrintButtonLabel(dest) {
    var btnText = document.getElementById('btnDailyPrintText');
    if (!btnText) return;
    btnText.textContent = '🖨️ 当日リスト印刷';
}

/**
 * 当日リスト印刷実行（Windows端末印刷 ⇔ サーバー直接印刷の自動ハイブリッド）
 */
function executeListPrint() {
    var selectDest = document.getElementById('selectPrintDestination');
    var dest = selectDest ? selectDest.value : 'local';

    if (dest === 'local') {
        // 【従来通り】ブラウザ標準の印刷ダイアログ
        setTimeout(function() {
            window.print();
        }, 50);
        return;
    }

    // 【サーバー直接印刷】指定プリンタへジョブ送信
    var btn = document.getElementById('btnDailyPrintExecute');
    var btnText = document.getElementById('btnDailyPrintText');
    var origText = '🖨️ 当日リスト印刷';

    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
    }
    if (btnText) {
        btnText.textContent = '⏳ 送信中...';
    }

    var formData = new FormData();
    formData.append('action', 'print');
    formData.append('target', 'daily_list');
    formData.append('printer_name', dest);
    formData.append('date', '<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>');
    formData.append('dept', '<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>');
    var selectSplit = document.getElementById('selectPageSplit');
    var currentSplit = selectSplit ? selectSplit.value : '<?= $page_split ? "1" : "0" ?>';
    var selectSort = document.getElementById('selectRoomSort');
    var currentSort = selectSort ? selectSort.value : '<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>';
    var chkEmpty = document.querySelector('input[name="show_empty"]');
    var currentEmpty = (chkEmpty && chkEmpty.checked) ? '1' : '0';

    formData.append('sort', currentSort);
    formData.append('page_split', currentSplit);
    formData.append('show_empty', currentEmpty);

    fetch('api_server_print.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            showDailyServerPrintToast(true, data.message || '印刷指示を送信しました');
        } else {
            showDailyServerPrintToast(false, data.message || '印刷送信に失敗しました');
        }
    })
    .catch(function(err) {
        showDailyServerPrintToast(false, '通信エラー: ' + err.message);
    })
    .finally(function() {
        if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
        if (btnText) {
            btnText.textContent = origText;
        }
    });
}

function showDailyServerPrintToast(isSuccess, message) {
    var toast = document.getElementById('serverPrintToast');
    var msgEl = document.getElementById('toastMessage');
    var iconEl = document.getElementById('toastIcon');
    if (!toast || !msgEl) {
        alert(message);
        return;
    }

    msgEl.textContent = message;
    iconEl.textContent = isSuccess ? '✅' : '⚠️';
    toast.className = 'server-print-toast ' + (isSuccess ? 'toast-success' : 'toast-error') + ' show';

    setTimeout(function() {
        toast.className = 'server-print-toast';
    }, 4000);
}

/**
 * 下部検査ネームプレート印刷画面を開く (横147mm×縦70mm A4面付け)
 */
function openNameplatePrint(dept, date) {
    var d = dept || '<?= $dept ?>';
    var dt = date || '<?= $date_str ?>';
    var url = 'print_nameplate.php?dept=' + encodeURIComponent(d) + '&date=' + encodeURIComponent(dt);
    window.open(url, '_blank');
}

/**
 * タッチデバイス向け 左右スワイプによる日付移動 (← 前日 / 翌日 →)
 */
(function setupSwipeDateNavigation() {
    var touchStartX = 0;
    var touchStartY = 0;
    var minSwipeDistance = 60; // 最小横移動距離 (px)
    var maxVerticalDrift = 50;  // 許容最大縦ブレ (px)

    document.addEventListener('touchstart', function(e) {
        if (e.touches.length !== 1) return;
        var touch = e.touches[0];
        touchStartX = touch.clientX;
        touchStartY = touch.clientY;
    }, { passive: true });

    document.addEventListener('touchend', function(e) {
        if (!e.changedTouches || e.changedTouches.length !== 1) return;
        var touch = e.changedTouches[0];
        var diffX = touch.clientX - touchStartX;
        var diffY = touch.clientY - touchStartY;

        // 入力フォームやモーダル操作中は誤爆防止
        var active = document.activeElement;
        var isInputActive = active && (
            active.tagName === 'INPUT' ||
            active.tagName === 'TEXTAREA' ||
            active.tagName === 'SELECT' ||
            active.isContentEditable
        );
        if (isInputActive) return;

        // 縦スクロールと明確に区別（縦ブレが小さく、横スワイプが大きい場合のみ）
        if (Math.abs(diffY) <= maxVerticalDrift && Math.abs(diffX) >= minSwipeDistance) {
            if (diffX > 0) {
                // 右スワイプ ➔ 前日へ (←)
                navigateDate(-1);
            } else {
                // 左スワイプ ➔ 翌日へ (→)
                navigateDate(1);
            }
        }
    }, { passive: true });
})();
</script>

</body>
</html>