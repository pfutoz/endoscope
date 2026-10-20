<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\schedule_note.php
 * ファイル名: schedule_note.php
 * 概要: 内視鏡予約 デイリーノート表示モード (Ultra Fast Daily Note View)
 * 更新日時: 2026-10-20 23:08
 * 修正履歴:
 *   - 2026-10-20: 【特大Flatpickr導入・西暦/和暦切替・ツールバー寸法保護】
 *     - ブラウザ標準日付ピッカーからオフライン特大Flatpickr（assets/vendor/flatpickr/）へ刷新
 *     - ツールバー上のコントロール高さ（32px）と横幅（195px）を完全固定し画面デザインのツライチを絶対保護
 *     - 日付テキストクリックで「西暦 (YYYY/MM/DD)」⇔「和暦 (令和X年M/D)」のワンクリック切替（localStorage記憶）
 *     - 右端📅アイコンクリック時のみ横幅440pxの特大カレンダーがドロップダウン展開（誤爆防止）
 *     - カレンダー内で小野会公休（日曜・祝日）を赤色（最優先）、木曜日を青色に色分け表示
 *     - 日付クリックで即座に対象日のデイリーノートへ画面遷移
 *     - Flatpickr展開中はキーボードショートカット移動を一時ガードし誤動作防止
 *   - 2026-09-29: ツールバーUI全面刷新（month_schedule.php と統一）
 *     - 重複していた上段の「🔬 種別: [全種別][GIF][エコー][TCF]」フィルターを完全撤去
 *     - 下段アクション行に「枠:」ラベル付きカプセル（GIF/エコー/TCFトグルボタン・エコー水色同期）を配置
 *     - 「⬜ 空き枠表示」をチェックボックス廃止・独立トグルボタン化
 *     - 🎯絵文字アイコンを撤去
 *     - 全ボタンの高さを32pxに完全ツライチ統一
 *   - 2026-09-29: キーボードショートカット日付移動機能を実装
 *     - [←][→]キーで1日前後、[↑][↓]キーで7日前後に移動
 *     - [Shift+←][Shift+→]キーで休診日をスキップして前診療日/次診療日へ移動
 *     - [T] / [Home]キーで今日へジャンプ（同日時はトースト通知）
 *     - フォーム入力中（input/textarea/select/contentEditable）やモーダル表示中は誤爆防止のため無効化
 *     - 画面ナビゲーションボタンにショートカットキーガイド（<kbd>）を表示
 *     - 日付ピッカー（date-picker-input）の大型化（15px/太字/淡色背景・青枠線強調・クリック領域拡大）
 *   - 2026-09-29: 【アセット統合・ファイル名正規化】month_schedule2.js から統合版 assets/js/month_schedule.js へ更新。CSSも assets/css/month_schedule.css に正規化
 *   - 2026-10-09: 【日別メモ 診療科完全独立 ＆ 振り分けメンテ連携】ツールバーおよび日別メモ編集モーダル内に振り分けメンテ画面（furiwake_daymemo.php）への直通リンクを新設
 * 特徴:
 *   - 月間カレンダー計算・空き枠速報等を完全排除し、瞬時の高速表示を実現 (<30ms)
 *   - 使い慣れた紙ベースのノート感覚で閲覧・管理できる全幅一覧表示
 *   - 充実の日付ナビゲーション（キーボード操作・前週/前日/前診療日/今日/次診療日/翌日/翌週/大型日付ピッカー）
 *   - 医師・検査種別・空き枠の自在な絞り込み
 *   - 新規予約・編集・削除・枠なし設定/復帰・一括部屋割り・印刷・重複チェックを完備
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/audit_helper.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';
require_once __DIR__ . '/lib/calendar_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 
        (string)($_SESSION['ui_state']['schedule_dept'] ?? 'PROCT');
if (!in_array($dept, ['PROCT', 'GASTRO'], true)) {
    $dept = 'PROCT';
}

$selected_date = isset($_GET['date']) && is_string($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');

$filter_doctor = isset($_GET['doctor']) && is_string($_GET['doctor']) ? trim($_GET['doctor']) : 'all';
$filter_exam   = isset($_GET['exam']) && is_string($_GET['exam']) ? trim($_GET['exam']) : 'all';
$show_empty    = isset($_GET['show_empty']) ? ((string)$_GET['show_empty'] === '1') : true;

// ── 診療日スキップ計算ヘルパー ──
function getAdjacentClinicDay(string $date_str, int $direction = 1): string {
    $cur = new DateTime($date_str);
    for ($i = 0; $i < 30; $i++) {
        if ($direction > 0) {
            $cur->modify('+1 day');
        } else {
            $cur->modify('-1 day');
        }
        $check = check_onokai_calendar($cur->format('Y-m-d'));
        if (!$check['is_closed']) {
            return $cur->format('Y-m-d');
        }
    }
    return $cur->format('Y-m-d');
}

// ── 日別メモ保存・更新 AJAX API ──
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'ajax_save_daily_memo') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $m_dept = trim((string)($_REQUEST['dept'] ?? ''));
        $m_date = trim((string)($_REQUEST['date'] ?? ''));
        $m_memo = trim((string)($_REQUEST['memo'] ?? ''));

        if (empty($m_dept) || empty($m_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $m_date)) {
            throw new Exception("無効なパラメータです");
        }

        if ($m_memo === '') {
            $stmt = $pdo->prepare("DELETE FROM t_daily_memo WHERE department_code = :dept AND target_date = :date");
            $stmt->execute([':dept' => $m_dept, ':date' => $m_date]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO t_daily_memo (department_code, target_date, memo, updated_at)
                VALUES (:dept, :date, :memo, CURRENT_TIMESTAMP)
                ON CONFLICT (department_code, target_date)
                DO UPDATE SET memo = EXCLUDED.memo, updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([':dept' => $m_dept, ':date' => $m_date, ':memo' => $m_memo]);
        }

        echo json_encode(['success' => true, 'memo' => $m_memo], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── 枠なし設定・解除のDB更新処理 ──
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $target_waku = $_GET['waku'] ?? '';
    $target_sid = (int)($_GET['sid'] ?? 0);
    
    if ($action === 'set_no_waku' && !empty($target_waku)) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO t_endoscopy_schedule 
                (department_code, exam_date, scheduled_time, waku_no, exam_type, exam_location, doctor_name, patient_name, remarks, is_canceled, created_at)
                SELECT 
                    :dept, 
                    :date, 
                    :waku, 
                    COALESCE(w.display_order, 1), 
                    COALESCE(w.group_code, 'GIF'), 
                    '指定なし', 
                    '◆未定◆', 
                    '※枠なし', 
                    '＊＊＊🚫枠なし🚫＊＊＊', 
                    FALSE, 
                    CURRENT_TIMESTAMP
                FROM (SELECT 1) AS dummy
                LEFT JOIN m2_waku w 
                  ON w.department_code = :dept2 
                  AND w.waku_name = :waku2 
                  AND w.is_active = TRUE
            ");
            $ins->execute([
                ':dept'  => $dept,
                ':date'  => $selected_date,
                ':waku'  => $target_waku,
                ':dept2' => $dept,
                ':waku2' => $target_waku
            ]);
            $new_sid = (int)$pdo->lastInsertId();
            if ($new_sid > 0) {
                $new_row = getScheduleRecordById($pdo, $new_sid);
                recordScheduleAuditLog($pdo, 'insert', $new_sid, null, $new_row, [
                    'description'  => "【枠なし設定】{$selected_date} {$target_waku} を枠なし（予約不可）に設定",
                    'diff_summary' => "枠なしブロック設定"
                ]);
            }
            header("Location: schedule_note.php?dept={$dept}&date={$selected_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0'));
            exit;
        } catch (PDOException $e) {
            die("枠なし登録SQLエラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    } elseif ($action === 'revert_waku' && $target_sid > 0) {
        try {
            $old_row = getScheduleRecordById($pdo, $target_sid);
            $del = $pdo->prepare("DELETE FROM t_endoscopy_schedule WHERE schedule_id = :sid AND department_code = :dept");
            $del->execute([':sid' => $target_sid, ':dept' => $dept]);
            if ($old_row) {
                recordScheduleAuditLog($pdo, 'delete', $target_sid, $old_row, null, [
                    'description'  => "【枠なし解除】{$old_row['exam_date']} {$old_row['scheduled_time']} を通常枠に復帰",
                    'diff_summary' => "枠なし解除"
                ]);
            }
            header("Location: schedule_note.php?dept={$dept}&date={$selected_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0'));
            exit;
        } catch (PDOException $e) {
            die("枠復帰SQLエラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
}

// ── ヘルパー関数定義 ──
if (!function_exists('getDoctorBadgeHTML')) {
    function getDoctorBadgeHTML(string $doctor_name): string {
        $name = trim($doctor_name);
        if ($name === '' || $name === '◆未定◆') {
            return '<span class="doc-icon-badge default" title="担当未定">医</span>';
        }
        $char = mb_substr($name, 0, 1, 'UTF-8');
        $color_map = [
            '義' => 'bg-indigo',
            '二' => 'bg-emerald',
            '誠' => 'bg-amber',
            '哲' => 'bg-cyan',
            '太' => 'bg-rose',
        ];
        $cls = $color_map[$char] ?? 'bg-slate';
        return '<span class="doc-icon-badge ' . $cls . '" title="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($char, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

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
            $html .= ($style !== '') ? "<span style=\"{$style}\">{$escaped}</span>" : $escaped;
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
                $current_color = $color_map[(int)$m_cf[1]] ?? 'inherit';
            } elseif ($token === '{' || $token === '}' || strpos($token, '\\') === 0) {
                continue;
            } else {
                $style = '';
                if ($current_color !== 'inherit') $style .= "color: {$current_color};";
                if ($is_bold) $style .= "font-weight: bold;";
                $escaped = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
                $html .= ($style !== '') ? "<span style=\"{$style}\">{$escaped}</span>" : $escaped;
            }
        }
        $flush_sjis();
        return preg_replace('/(?:<br>\s*)+$/i', '', trim($html));
    }
}

if (!function_exists('renderRemarksRtfHtml')) {
    function renderRemarksRtfHtml(?string $raw): string {
        if ($raw === null || $raw === '') return '';
        $trimmed = trim($raw);
        if ($trimmed === '＊＊＊🚫枠なし🚫＊＊＊') {
            return '<span style="background-color:#fee2e2; color:#dc2626; padding:2px 6px; border-radius:3px; font-weight:bold;">＊＊＊🚫枠なし🚫＊＊＊</span>';
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

if (!function_exists('getDailyReservationsWithMismatch')) {
    function getDailyReservationsWithMismatch(PDO $pdo, string $dept, string $date_str, string $filter_doctor = 'all', string $filter_exam = 'all', bool $show_empty = true): array {
        if ($show_empty) {
            $sql = "
            WITH 
            all_slots AS (
                SELECT waku_name, display_order, group_code
                FROM m2_waku
                WHERE department_code = :dept AND is_active = TRUE
            ),
            reserved_data AS (
                SELECT 
                    s.schedule_id, s.exam_date, s.scheduled_time, s.waku_no,
                    s.patient_id, s.patient_name, s.patient_kana, s.birthday,
                    s.doctor_name, s.exam_type, s.arrival_time, s.exam_location,
                    s.remarks, s.room_number, s.bowel_prep, s.is_canceled,
                    s.booking_status, s.department_code, s.created_at, s.updated_at,
                    w.display_order AS waku_display_order, w.group_code,
                    CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
                    FALSE AS is_empty
                FROM t_endoscopy_schedule s
                LEFT JOIN m2_waku w 
                    ON s.department_code = w.department_code 
                    AND s.scheduled_time = w.waku_name
                    AND w.is_active = TRUE
                WHERE s.department_code = :dept 
                  AND s.exam_date = :date 
                  AND s.is_canceled = FALSE
            ";
            if ($filter_doctor !== 'all') { $sql .= " AND s.doctor_name = :doctor"; }
            if ($filter_exam !== 'all') { $sql .= " AND s.exam_type = :exam"; }
            $sql .= "
            )
            SELECT 
                COALESCE(r.scheduled_time, 'MISMATCH') AS scheduled_time,
                -1 AS waku_no,
                COALESCE(r.group_code, 'MISMATCH') AS group_code,
                COALESCE(r.patient_id, '') AS patient_id,
                COALESCE(r.patient_name, '') AS patient_name,
                COALESCE(r.patient_kana, '') AS patient_kana,
                COALESCE(r.birthday::text, '') AS birthday,
                COALESCE(r.doctor_name, '') AS doctor_name,
                COALESCE(r.exam_type, '') AS exam_type,
                COALESCE(r.arrival_time, '') AS arrival_time,
                COALESCE(r.exam_location, '') AS exam_location,
                COALESCE(r.remarks, '') AS remarks,
                COALESCE(r.room_number, '') AS room_number,
                COALESCE(r.bowel_prep, '') AS bowel_prep,
                COALESCE(r.schedule_id, 0) AS schedule_id,
                FALSE AS is_empty,
                TRUE AS is_mismatch,
                COALESCE(r.department_code, :dept) AS department_code,
                COALESCE(r.created_at::text, '') AS created_at,
                COALESCE(r.updated_at::text, '') AS updated_at
            FROM reserved_data r
            WHERE r.is_mismatch = TRUE

            UNION ALL

            SELECT 
                a.waku_name AS scheduled_time,
                a.display_order AS waku_no,
                a.group_code,
                COALESCE(r.patient_id, '') AS patient_id,
                COALESCE(r.patient_name, '') AS patient_name,
                COALESCE(r.patient_kana, '') AS patient_kana,
                COALESCE(r.birthday::text, '') AS birthday,
                COALESCE(r.doctor_name, '') AS doctor_name,
                COALESCE(r.exam_type, '') AS exam_type,
                COALESCE(r.arrival_time, '') AS arrival_time,
                COALESCE(r.exam_location, '') AS exam_location,
                COALESCE(r.remarks, '') AS remarks,
                COALESCE(r.room_number, '') AS room_number,
                COALESCE(r.bowel_prep, '') AS bowel_prep,
                COALESCE(r.schedule_id, 0) AS schedule_id,
                CASE WHEN r.schedule_id IS NULL THEN TRUE ELSE FALSE END AS is_empty,
                FALSE AS is_mismatch,
                COALESCE(r.department_code, :dept) AS department_code,
                COALESCE(r.created_at::text, '') AS created_at,
                COALESCE(r.updated_at::text, '') AS updated_at
            FROM all_slots a
            LEFT JOIN reserved_data r ON a.waku_name = r.scheduled_time
            ORDER BY is_mismatch DESC, waku_no ASC, schedule_id ASC;
            ";
        } else {
            $sql = "
            SELECT 
                s.*,
                w.display_order AS waku_display_order,
                COALESCE(w.group_code, 'MISMATCH') AS group_code,
                CASE WHEN w.waku_name IS NULL THEN TRUE ELSE FALSE END AS is_mismatch,
                FALSE AS is_empty
            FROM t_endoscopy_schedule s
            LEFT JOIN m2_waku w 
                ON s.department_code = w.department_code 
                AND s.scheduled_time = w.waku_name
                AND w.is_active = TRUE
            WHERE s.department_code = :dept 
              AND s.exam_date = :date 
              AND s.is_canceled = FALSE
            ";
            if ($filter_doctor !== 'all') { $sql .= " AND s.doctor_name = :doctor"; }
            if ($filter_exam !== 'all') { $sql .= " AND s.exam_type = :exam"; }
            $sql .= " ORDER BY is_mismatch DESC, COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC";
        }

        $stmt = $pdo->prepare($sql);
        $params = [':dept' => $dept, ':date' => $date_str];
        if ($filter_doctor !== 'all') { $params[':doctor'] = $filter_doctor; }
        if ($filter_exam !== 'all') { $params[':exam'] = $filter_exam; }
        $stmt->execute($params);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($res) ? $res : [];
    }
}

// ── 医師リスト取得 ──
$doctors_list = getDoctorsForDept($pdo, $dept);
if (!is_array($doctors_list)) { $doctors_list = []; }

// ── 当日のデータ取得 ──
$list_data = getDailyReservationsWithMismatch($pdo, $dept, $selected_date, $filter_doctor, $filter_exam, $show_empty);

// ── 医師別予約件数・部屋重複の集計 ──
$doctor_counts = [];
$room_counts   = [];
$booked_count  = 0;
$empty_count   = 0;
$exam_counts   = ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0, 'OTHER' => 0];

foreach ($list_data as $row_item) {
    if (empty($row_item['is_empty'])) {
        $booked_count++;
        $dname = trim((string)($row_item['doctor_name'] ?? ''));
        if ($dname !== '') {
            $doctor_counts[$dname] = ($doctor_counts[$dname] ?? 0) + 1;
        }
        $rm = trim((string)($row_item['room_number'] ?? ''));
        if ($rm !== '' && $rm !== '未指定' && $rm !== '-') {
            $room_counts[$rm] = ($room_counts[$rm] ?? 0) + 1;
        }
        $etype = trim((string)($row_item['exam_type'] ?? ''));
        if ($etype === 'GIF') $exam_counts['GIF']++;
        elseif ($etype === 'ECHO' || $etype === 'エコー') $exam_counts['ECHO']++;
        elseif ($etype === 'TCF' || $etype === 'W' || $etype === 'ポリペク') $exam_counts['TCF']++;
        else $exam_counts['OTHER']++;
    } else {
        $empty_count++;
    }
}

// ── アラートマップ取得 ──
$all_list_sids = [];
foreach ($list_data as $r_item) {
    $sid = (int)($r_item['schedule_id'] ?? 0);
    if ($sid > 0) $all_list_sids[] = $sid;
}
$medical_alerts_map = !empty($all_list_sids) ? getMedicalAlertsMap($pdo, $all_list_sids) : [];

// ── 日別メモ取得 ──
$selected_day_memo = '';
try {
    $stmt = $pdo->prepare("SELECT memo FROM t_daily_memo WHERE department_code = :dept AND target_date = :date");
    $stmt->execute([':dept' => $dept, ':date' => $selected_date]);
    $selected_day_memo = trim((string)$stmt->fetchColumn());
} catch (Exception $e) {}

// 選択日の大腸CF件数（胃腸科・肛門科・TCF/W/ポリペク内訳）を取得（部屋・下剤一括ホバーサマリ用）
$cf_counts = getDailyCfCounts($pdo, $selected_date);
$cf_tooltip_plain = "【" . date('n/j', strtotime($selected_date)) . " 大腸CF件数】全体: {$cf_counts['total_cf']}件\n"
    . "・🔵 胃腸科: {$cf_counts['gastro']['total']}件 (TCF: {$cf_counts['gastro']['tcf']}, W: {$cf_counts['gastro']['w']}, ポリペク: {$cf_counts['gastro']['polypep']})\n"
    . "・🟢 肛門科: {$cf_counts['proct']['total']}件 (TCF: {$cf_counts['proct']['tcf']}, W: {$cf_counts['proct']['w']}, ポリペク: {$cf_counts['proct']['polypep']})\n"
    . "※クリックで部屋割り・下剤の一括設定画面を開きます";

// ── 日付計算 ──
$sel_ts   = strtotime($selected_date);
$sel_w    = (int)date('w', $sel_ts);
$w_map    = ['日', '月', '火', '水', '木', '金', '土'];
$day_str  = $w_map[$sel_w];

// 小野会休診判定
$day_check = check_onokai_calendar($selected_date);
$is_closed = $day_check['is_closed'];
$close_reason = $day_check['reason'] ?? '';

// ナビゲーション日付
$prev_week_date = date('Y-m-d', strtotime('-7 days', $sel_ts));
$prev_day_date  = date('Y-m-d', strtotime('-1 day', $sel_ts));
$prev_clinic_date = getAdjacentClinicDay($selected_date, -1);
$today_date     = date('Y-m-d');
$next_clinic_date = getAdjacentClinicDay($selected_date, 1);
$next_day_date  = date('Y-m-d', strtotime('+1 day', $sel_ts));
$next_week_date = date('Y-m-d', strtotime('+7 days', $sel_ts));

$is_today = ($selected_date === $today_date);

// 小野会祝日・休診辞書生成（Flatpickr特大カレンダー用：前後1年分）
$sel_year = (int)date('Y', $sel_ts);
$holidays_map = [];
for ($y = $sel_year - 1; $y <= $sel_year + 1; $y++) {
    for ($m = 1; $m <= 12; $m++) {
        $days_in_m = cal_days_in_month(CAL_GREGORIAN, $m, $y);
        for ($d = 1; $d <= $days_in_m; $d++) {
            $chk_date = sprintf('%04d-%02d-%02d', $y, $m, $d);
            $chk = check_onokai_calendar($chk_date, null);
            if ($chk['is_holiday']) {
                $holidays_map[$chk_date] = $chk['reason'] ?? '公休';
            }
        }
    }
}
$onokai_holidays_json = json_encode($holidays_map, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📖 ノート表示: <?= htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8') ?> (<?= $day_str ?>) | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="assets/css/month_schedule.css?v=<?= time() ?>">
    <!-- 🖨️ 完全ローカル・オフライン動作 Flatpickr -->
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <style>
        /* ── ノート表示専用 スタイリング ── */
        .note-container {
            max-width: 1720px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 10px 16px;
        }

        /* ── 日付ナビゲーションバー（リッチ版） ── */
        .note-date-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 14px;
            margin-bottom: 10px;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
        }
        .date-nav-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .date-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            cursor: pointer;
            white-space: nowrap;
        }
        .date-nav-btn:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
            transform: translateY(-1px);
        }
        .date-nav-btn.btn-today {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #93c5fd;
        }
        .date-nav-btn.btn-today:hover {
            background: #dbeafe;
        }
        .date-nav-btn.btn-clinic {
            background: #f0fdf4;
            color: #15803d;
            border-color: #86efac;
        }
        .date-nav-btn.btn-clinic:hover {
            background: #dcfce7;
        }

        .date-display-center {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .date-title-main {
            font-size: 19px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dow-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 800;
        }
        .dow-sun { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }
        .dow-sat { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .dow-weekday { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .clinic-status-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
        }
        .status-open { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .status-closed { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; }

        /* ── ツールバー日付ピッカー（西暦・和暦切替＋特大Flatpickr起動） ── */
        .custom-date-control {
            display: inline-flex;
            align-items: center;
            height: 32px; /* 32pxツライチ完全固定 */
            width: 195px; /* 固定幅で西暦/和暦切替時も周囲を1ミリも押し出さない */
            border: 2px solid #3b82f6;
            border-radius: 8px;
            background: #eff6ff;
            box-shadow: 0 1px 3px rgba(59, 130, 246, 0.15);
            user-select: none;
            box-sizing: border-box;
            transition: all 0.2s ease-in-out;
            vertical-align: middle;
            position: relative;
        }
        .custom-date-control:hover {
            background: #dbeafe;
            border-color: #2563eb;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.2);
        }
        .date-text-toggle {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            height: 100%;
            cursor: pointer;
            padding: 0 4px 0 8px;
        }
        .date-val-text {
            font-size: 13px;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: -0.01em;
            white-space: nowrap;
        }
        .date-mode-badge {
            font-size: 9px;
            font-weight: 800;
            padding: 1px 4px;
            border-radius: 3px;
            background: #bfdbfe;
            color: #1e40af;
            line-height: 1;
            white-space: nowrap;
        }
        .date-calendar-icon-btn {
            width: 32px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            border-left: 1px solid #bfdbfe;
            transition: background 0.15s;
            border-top-right-radius: 6px;
            border-bottom-right-radius: 6px;
        }
        .date-calendar-icon-btn:hover {
            background: #bfdbfe;
        }

        /* ── Flatpickr 特大カレンダーカスタマイズ (横幅440px / 文字18px) ── */
        .flatpickr-calendar {
            width: 440px !important;
            max-width: 95vw !important;
            background: #ffffff !important;
            border: 2px solid #2563eb !important;
            border-radius: 12px !important;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.25) !important;
            padding: 8px !important;
            font-family: -apple-system, BlinkMacSystemFont, "BIZ UDPGothic", "Segoe UI", Roboto, sans-serif !important;
            z-index: 999999 !important;
        }
        .flatpickr-months {
            height: 48px !important;
            align-items: center !important;
            border-bottom: 2px solid #e2e8f0 !important;
            padding-bottom: 4px !important;
        }
        .flatpickr-current-month {
            font-size: 18px !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            padding: 4px 0 !important;
        }
        .flatpickr-current-month .cur-month {
            font-weight: 800 !important;
        }
        .flatpickr-monthDropdown-months {
            font-size: 17px !important;
            font-weight: 800 !important;
        }
        .flatpickr-current-month input.cur-year {
            font-size: 18px !important;
            font-weight: 800 !important;
        }
        .flatpickr-prev-month, .flatpickr-next-month {
            padding: 8px 12px !important;
        }
        .flatpickr-prev-month svg, .flatpickr-next-month svg {
            width: 18px !important;
            height: 18px !important;
            fill: #2563eb !important;
        }
        .flatpickr-weekdays {
            height: 38px !important;
            margin-top: 4px !important;
        }
        span.flatpickr-weekday {
            font-size: 15px !important;
            font-weight: 800 !important;
            color: #475569 !important;
        }
        /* 曜日ヘッダーカラー（日: 赤、木: 青、土: 青） */
        span.flatpickr-weekday:first-child { color: #dc2626 !important; }
        span.flatpickr-weekday:nth-child(5) { color: #2563eb !important; }
        span.flatpickr-weekday:last-child { color: #2563eb !important; }

        .flatpickr-days {
            width: 100% !important;
        }
        .dayContainer {
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
            justify-content: space-around !important;
        }
        .flatpickr-day {
            height: 52px !important;
            line-height: 50px !important;
            max-width: 56px !important;
            font-size: 17px !important;
            font-weight: 800 !important;
            border-radius: 8px !important;
            margin: 2px 1px !important;
            border: 1px solid transparent !important;
            transition: all 0.15s ease !important;
        }
        .flatpickr-day:hover {
            background: #e0f2fe !important;
            border-color: #38bdf8 !important;
        }

        /* 💡 小野会公休（日曜・祝日）: 赤（最優先） */
        .flatpickr-day.onokai-holiday {
            background: #ffe6e6 !important;
            color: #d9383a !important;
            border-color: #fca5a5 !important;
        }
        .flatpickr-day.onokai-holiday:hover {
            background: #fecaca !important;
        }

        /* 💡 木曜: 青 */
        .flatpickr-day.onokai-thursday {
            background: #e6f2ff !important;
            color: #2471a3 !important;
            border-color: #bfdbfe !important;
        }
        .flatpickr-day.onokai-thursday:hover {
            background: #dbeafe !important;
        }

        /* 💡 選択中の日付 */
        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: #2563eb !important;
            color: #ffffff !important;
            border-color: #1d4ed8 !important;
            font-weight: 900 !important;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.4) !important;
        }

        /* 💡 本日（今日）枠線強調 */
        .flatpickr-day.today:not(.selected) {
            border-color: #0284c7 !important;
            background: #f0f9ff !important;
        }

        .nav-kbd {
            display: inline-block;
            padding: 1px 5px;
            font-size: 10px;
            font-family: inherit;
            color: #64748b;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            box-shadow: 0 1px 0 #94a3b8;
            margin-left: 4px;
            line-height: 1.2;
            vertical-align: middle;
            font-weight: 700;
        }
        .date-nav-btn:hover .nav-kbd {
            color: #0f172a;
            background: #e2e8f0;
            border-color: #94a3b8;
        }
        .nav-shortcut-hint {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            background: #f8fafc;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px dashed #cbd5e1;
        }

        /* ── サマリ KPI バー ── */
        .note-summary-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #faf5ff;
            border: 1px solid #e9d5ff;
            border-radius: 8px;
            padding: 6px 14px;
            margin-bottom: 10px;
            font-size: 12px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .kpi-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .kpi-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 700;
            color: #334155;
        }
        .kpi-pill {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
        }

        /* ── 紙ベース風ノートテーブル ── */
        .note-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
        }
        .note-table th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 800;
            font-size: 11.5px;
            padding: 7px 6px;
            border: 1px solid #cbd5e1;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 5;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .note-table td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            vertical-align: middle;
        }
        .note-table tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }
        .note-table tbody tr:hover {
            background-color: #f0f7ff !important;
        }
        .note-table tr.empty-slot-row {
            background-color: #fdfaff;
        }
        .note-table tr.empty-slot-row:hover {
            background-color: #f5edfd !important;
        }

        /* 印刷スタイル最適化 */
        @media print {
            .top-toolbar, .note-date-nav, .filters-compact, .note-summary-bar, .list-actions {
                display: none !important;
            }
            .note-container {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }
            .note-table {
                font-size: 10pt !important;
            }
            .note-table th, .note-table td {
                padding: 3px 4px !important;
            }
        }
    </style>
</head>
<body>

<div class="note-container">

    <!-- ── トップツールバー ── -->
    <div class="top-toolbar">
        <div class="top-left-group">
            <div class="top-title-area">
                <h2>📖 内視鏡予約 デイリーノート</h2>
                <span class="header-badge" style="background:#fef3c7; color:#92400e; border-color:#fde68a; font-weight:700;">⚡ 超高速 ノート表示</span>
            </div>
            <div class="dept-tabs-inline">
                <a href="?dept=PROCT&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab-inline <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛門科</a>
                <a href="?dept=GASTRO&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab-inline <?=$dept==='GASTRO'?'active-GASTRO':''?>">🔵 胃腸科</a>
            </div>
        </div>
        <div class="header-nav">
            <a href="month_schedule.php?dept=<?=$dept?>&date=<?=$selected_date?>" class="nav-btn" style="background:#3b82f6; color:#fff;" title="月間カレンダー表示に切り替え">
                <span>📅</span> カレンダー表示へ
            </a>
            <a href="index.php" class="nav-btn nav-btn-home">
                <span>🏠</span> 
            </a>
            <a href="progress_monitor.php?dept=<?=$dept?>" class="nav-btn nav-btn-whiteboard">
                <span>📊</span> 当日ホワイトボード
            </a>
            <a href="month_matrix_v2.php?dept=<?=$dept?>&year=<?=date('Y', $sel_ts)?>&month=<?=date('n', $sel_ts)?>" class="nav-btn nav-btn-matrix">
                <span>📊</span> 予約マトリクス
            </a>
            <a href="slot_search.php?dept=<?=$dept?>" class="nav-btn nav-btn-search">
                <span>🔍</span> 空き枠検索
            </a>
            <button type="button" onclick="openDuplicateCheckModal()" class="nav-btn nav-btn-duplicate" title="同一患者の未来複数予約・重複チェック">
                <span>⚠️</span> 重複チェック
            </button>
            <button type="button" onclick="openAuditLogModal()" class="nav-btn nav-btn-audit" title="直近の操作履歴・ワンクリック復元（Undo）">
                <span>🕒</span> 操作履歴
            </button>
            <button type="button" onclick="openMedicalAlertScanModal({date: '<?=$selected_date?>', dept: '<?=$dept?>'})" class="nav-btn nav-btn-alert-scan" title="抗血栓薬・アレルギー・感染症・風邪アラートスキャン">
                <span>🛡️</span> アラートスキャン
            </button>
            <a href="furiwake_daymemo.php" class="nav-btn" style="background: #fef3c7; color: #92400e; border: 1.5px solid #fde68a; font-weight: 700;" title="日別メモの診療科振り分けメンテナンス（データ整理）">
                <span>📝</span> メモ振分
            </a>
        </div>
    </div>

    <!-- ── 日付ナビゲーションバー（1〜4すべて完備 ＆ キーボードショートカット対応） ── -->
    <div class="note-date-nav">
        <!-- 過去側ナビ -->
        <div class="date-nav-group">
            <a href="?dept=<?=$dept?>&date=<?=$prev_week_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn" id="navPrevWeek" title="前週（-7日）へジャンプ [↑キー]">
                ◀◀ -7日 <kbd class="nav-kbd">↑</kbd>
            </a>
            <a href="?dept=<?=$dept?>&date=<?=$prev_day_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn" id="navPrevDay" title="前日へ移動 [←キー]">
                ◀ 前日 <kbd class="nav-kbd">←</kbd>
            </a>
            <a href="?dept=<?=$dept?>&date=<?=$prev_clinic_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn btn-clinic" id="navPrevClinic" title="休診日をスキップして直前の診療日へ移動 [Shift+←]">
                ◀ 前診療日 (<?=date('m/d', strtotime($prev_clinic_date))?>) <kbd class="nav-kbd">Shift+←</kbd>
            </a>
        </div>

        <!-- 中央：現在日付 ＆ 大型ピッカー ＆ 今日 ＆ ショートカットヒント -->
        <div class="date-display-center">
            <div class="date-title-main">
                <span>📅 <?= date('Y年n月j日', $sel_ts) ?></span>
                <?php
                $dow_cls = 'dow-weekday';
                if ($sel_w === 0) $dow_cls = 'dow-sun';
                elseif ($sel_w === 6) $dow_cls = 'dow-sat';
                ?>
                <span class="dow-badge <?=$dow_cls?>">(<?=$day_str?>)</span>
            </div>

            <?php if ($is_closed): ?>
                <span class="clinic-status-badge status-closed">⛔ 休診<?= $close_reason ? " ({$close_reason})" : '' ?></span>
            <?php else: ?>
                <span class="clinic-status-badge status-open">🟢 診療日</span>
            <?php endif; ?>

            <!-- 🖨️ 直感特大Flatpickr日付コントロール（西暦/和暦切替＋小野会カラー・ツールバー高さ32px完全保護） -->
            <div class="custom-date-control" id="customDateControl" title="クリックで西暦／和暦 表示切替、📅で特大カレンダー展開">
                <div class="date-text-toggle" id="dateTextToggle" title="クリックで西暦／和暦 表示切替">
                    <span class="date-val-text" id="dateValText"><?= date('Y/m/d', $sel_ts) ?> (<?= $day_str ?>)</span>
                    <span class="date-mode-badge" id="dateModeBadge">西暦</span>
                </div>
                <div class="date-calendar-icon-btn" id="calendarTriggerBtn" title="特大カレンダーを開く">
                    📅
                </div>
                <input type="text" id="mainFlatpickr" style="display:none;" value="<?= $selected_date ?>">
            </div>

            <?php if (!$is_today): ?>
                <a href="?dept=<?=$dept?>&date=<?=$today_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn btn-today" id="navToday" title="本日の予約一覧へ移動 [Tキー / Homeキー]">
                    📅 今日へ <kbd class="nav-kbd">T</kbd>
                </a>
            <?php else: ?>
                <span class="date-nav-btn btn-today" style="cursor:default; font-weight:800;">
                    ⭐ 本日
                </span>
            <?php endif; ?>
        </div>

        <!-- 未来側ナビ -->
        <div class="date-nav-group">
            <a href="?dept=<?=$dept?>&date=<?=$next_clinic_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn btn-clinic" id="navNextClinic" title="休診日をスキップして次の診療日へ移動 [Shift+→]">
                次診療日 (<?=date('m/d', strtotime($next_clinic_date))?>) ▶ <kbd class="nav-kbd">Shift+→</kbd>
            </a>
            <a href="?dept=<?=$dept?>&date=<?=$next_day_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn" id="navNextDay" title="翌日へ移動 [→キー]">
                翌日 ▶ <kbd class="nav-kbd">→</kbd>
            </a>
            <a href="?dept=<?=$dept?>&date=<?=$next_week_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="date-nav-btn" id="navNextWeek" title="翌週（+7日）へジャンプ [↓キー]">
                +7日 ▶▶ <kbd class="nav-kbd">↓</kbd>
            </a>
        </div>
    </div>

    <!-- ── 絞り込みツールバー ── -->
    <div class="filters-compact">
        <div class="filter-row-inline">
            <div class="filter-group-inline">
                <span class="filter-label">👨‍⚕️ 医師:</span>
                <a href="?dept=<?=$dept?>&date=<?=$selected_date?>&doctor=all&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor==='all'?'active-all':''?>">全医師</a>
                <?php foreach ($doctors_list as $doc): 
                    $doc_str = (string)$doc;
                ?>
                    <a href="?dept=<?=$dept?>&date=<?=$selected_date?>&doctor=<?=urlencode($doc_str)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor===$doc_str?'active':''?>">
                        <?=htmlspecialchars($doc_str, ENT_QUOTES, 'UTF-8')?> <span class="badge"><?=(int)($doctor_counts[$doc_str] ?? 0)?></span>
                    </a>
                <?php endforeach; ?>
            </div>

        </div>

        <div class="list-actions" style="margin: 0;">
            <!-- 空き枠表示トグルボタン -->
            <button type="button" id="btn_toggle_empty" class="btn-feature-empty <?= $show_empty ? 'active' : '' ?>" onclick="toggleEmptySlotsBtn(this)" title="空き枠の表示/非表示切替">
                ⬜ 空き枠表示
            </button>
            
            <!-- 枠トグルボタングループ (カプセル・各枠背景色連動) -->
            <div class="slot-toggle-group">
                <span class="toolbar-label">枠:</span>
                <button type="button" id="show_gif" class="btn-slot-toggle btn-slot-gif active" onclick="toggleSlotFilterBtn('GIF', this)" title="GIF(胃カメラ)枠の表示切替">GIF</button>
                <button type="button" id="show_echo" class="btn-slot-toggle btn-slot-echo active" onclick="toggleSlotFilterBtn('ECHO', this)" title="エコー(US)枠の表示切替">エコー</button>
                <button type="button" id="show_tcf" class="btn-slot-toggle btn-slot-tcf active" onclick="toggleSlotFilterBtn('TCF', this)" title="TCF(大腸)枠の表示切替">TCF</button>
            </div>
            
            <!-- 部屋・下剤一括設定ボタン（ホバーで胃腸科・肛門科CF件数を表示） -->
            <div class="bulk-prep-btn-container">
                <button type="button" onclick="openBulkPrepRoomModal('<?= $dept ?>', '<?= $selected_date ?>')" class="summary-btn-modern" style="background:#0d9488;" aria-label="当日の部屋割り・下剤を一括設定">
                    🚪 部屋・下剤一括
                    <?php if ($cf_counts['total_cf'] > 0): ?>
                        <span class="cf-mini-pill"><?= $cf_counts['total_cf'] ?></span>
                    <?php endif; ?>
                </button>

                <!-- ホバーで表示されるリッチカード -->
                <div class="cf-hover-card" role="tooltip">
                    <div class="cf-hover-header">
                        <div class="cf-hover-title">
                            <span>🚪 大腸CF件数サマリ</span>
                            <span class="cf-hover-date"><?= date('n/j', $sel_ts) ?>(<?= $day_str ?>)</span>
                        </div>
                        <div class="cf-hover-total-badge">
                            全体計 <strong class="cf-total-num"><?= $cf_counts['total_cf'] ?></strong> 件
                        </div>
                    </div>
                    
                    <div class="cf-hover-body">
                        <table class="cf-hover-table">
                            <thead>
                                <tr>
                                    <th class="th-dept">診療科</th>
                                    <th class="th-total">CF合計</th>
                                    <th class="th-detail">TCF</th>
                                    <th class="th-detail">W</th>
                                    <th class="th-detail">ポリペク</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="row-gastro">
                                    <td class="td-dept">
                                        <strong>🔵 胃腸科</strong>
                                    </td>
                                    <td class="td-total">
                                        <span class="count-badge count-gastro"><?= $cf_counts['gastro']['total'] ?>件</span>
                                    </td>
                                    <td class="td-detail <?= $cf_counts['gastro']['tcf'] > 0 ? 'has-count' : 'is-zero' ?>"><?= $cf_counts['gastro']['tcf'] ?></td>
                                    <td class="td-detail <?= $cf_counts['gastro']['w'] > 0 ? 'has-count' : 'is-zero' ?>"><?= $cf_counts['gastro']['w'] ?></td>
                                    <td class="td-detail <?= $cf_counts['gastro']['polypep'] > 0 ? 'has-count' : 'is-zero' ?>"><?= $cf_counts['gastro']['polypep'] ?></td>
                                </tr>
                                <tr class="row-proct">
                                    <td class="td-dept">
                                        <strong>🟢 肛門科</strong>
                                    </td>
                                    <td class="td-total">
                                        <span class="count-badge count-proct"><?= $cf_counts['proct']['total'] ?>件</span>
                                    </td>
                                    <td class="td-detail <?= $cf_counts['proct']['tcf'] > 0 ? 'has-count' : 'is-zero' ?>"><?= $cf_counts['proct']['tcf'] ?></td>
                                    <td class="td-detail <?= $cf_counts['proct']['w'] > 0 ? 'has-count' : 'is-zero' ?>"><?= $cf_counts['proct']['w'] ?></td>
                                    <td class="td-detail <?= $cf_counts['proct']['polypep'] > 0 ? 'has-count' : 'is-zero' ?>"><?= $cf_counts['proct']['polypep'] ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="cf-hover-footer">
                        <span>※CF対象（TCF+W+ポリペク）の前処置・部屋割</span>
                        <span style="font-size:10.5px; color:#0f766e; font-weight:700;">クリックで開く ➔</span>
                    </div>
                </div>
            </div>

            <button type="button" onclick="openSlotSummary('<?= $dept ?>', '<?= $selected_date ?>')" class="summary-btn-modern" title="当日の時間枠と医師供給枠のバランスサマリを別画面で表示">
                📊 枠・供給サマリ
            </button>
            
            <button type="button" onclick="openPrintSchedule('<?= $dept ?>', '<?= $selected_date ?>')" class="print-btn-modern" title="当日の内視鏡予約一覧を別タブで印刷">
                🖨️ ノート印刷
            </button>
        </div>
    </div>

    <!-- ── サマリ KPI バー ＆ メモ ── -->
    <div class="note-summary-bar">
        <div class="kpi-group">
            <span class="kpi-item">
                📋 予約件数: <span class="kpi-pill" style="color:#2563eb; background:#eff6ff; border-color:#bfdbfe; font-size:12px;"><?= $booked_count ?> 件</span>
            </span>
            <span class="kpi-item">
                🔵 GIF: <span class="kpi-pill"><?= $exam_counts['GIF'] ?></span>
            </span>
            <span class="kpi-item">
                🟠 エコー: <span class="kpi-pill"><?= $exam_counts['ECHO'] ?></span>
            </span>
            <span class="kpi-item">
                🟢 TCF/W: <span class="kpi-pill"><?= $exam_counts['TCF'] ?></span>
            </span>
            <?php if ($show_empty): ?>
                <span class="kpi-item" style="color:#7c3aed;">
                    ⬜ 空き枠: <span class="kpi-pill" style="color:#7c3aed; background:#f5f3ff; border-color:#ddd6fe;"><?= $empty_count ?> 枠</span>
                </span>
            <?php endif; ?>
        </div>

        <div style="display:flex; align-items:center; gap:8px;">
            <?php if (!empty($selected_day_memo)): ?>
                <span class="list-title-memo" onclick="openDailyMemoModal('<?= $selected_date ?>', <?= htmlspecialchars(json_encode($selected_day_memo, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)" title="クリックして日別カレンダーメモを編集">
                    📢 <?= htmlspecialchars($selected_day_memo, ENT_QUOTES, 'UTF-8') ?> <span style="font-size:10px; opacity:0.75;">✏️</span>
                </span>
            <?php else: ?>
                <button type="button" class="btn-memo-add" onclick="openDailyMemoModal('<?= $selected_date ?>', '')" title="この日に日別カレンダーメモ・連絡事項を登録">
                    📝 メモ追加
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 紙ベース風 ノートテーブル本体 ── -->
    <?php if (empty($list_data)): ?>
        <div class="empty-msg" style="background:#f8fafc; color:#475569; font-weight:bold; padding:45px; text-align:center; font-size:14px; border:1px dashed #cbd5e1; border-radius:8px;">
            📭 この日の予約枠・予定データはありません
        </div>
    <?php else: ?>
        <table class="note-table" id="reservationTable">
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;" title="新規追加">➕</th>
                    <th style="width:34px; text-align:center;" title="枠なし設定・復帰">🚫</th>
                    <th style="width:30px; text-align:center;" title="編集・削除">✏️</th>
                    <th style="width:115px;">時間枠</th>
                    <th style="width:90px; text-align:center;">患者No</th>
                    <th style="width:220px;">患者名</th>
                    <th style="width:95px; text-align:center;">担当 / 種別</th>
                    <th style="width:68px; text-align:center;">部屋</th>
                    <th style="width:58px; text-align:center;">下剤</th>
                    <th>備考</th>
                    <th style="width:45px; text-align:center;">シート</th>
                    <th style="width:65px; text-align:center;">F/U</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($list_data as $row): 
                $exam = (string)($row['exam_type'] ?? '');
                $cls = 'other';
                $row_exam_category = 'OTHER';

                if ($exam === 'GIF' || $exam === '◆胃がん検診◆' || $exam === '胃がん検診') {
                    $cls = 'gif';
                    $row_exam_category = 'GIF';
                } elseif ($exam === 'ECHO' || $exam === 'エコー') {
                    $cls = 'echo';
                    $row_exam_category = 'ECHO';
                } elseif ($exam === 'TCF' || $exam === 'W' || $exam === 'ポリペク') {
                    $cls = 'tcf';
                    $row_exam_category = 'TCF';
                }
                
                $is_empty = isset($row['is_empty']) && $row['is_empty'] === true;
                $is_mismatch = isset($row['is_mismatch']) && $row['is_mismatch'] === true;
                $group_code = (string)($row['group_code'] ?? 'OTHER');
                $schedule_id = (int)($row['schedule_id'] ?? 0);
                $waku = (string)($row['scheduled_time'] ?? '');
                $remarks_str = (string)($row['remarks'] ?? '');
                
                $is_no_waku = (!$is_empty && trim($remarks_str) === '＊＊＊🚫枠なし🚫＊＊＊');
                $filter_category = $row_exam_category !== 'OTHER' ? $row_exam_category : $group_code;
                $can_sheet = ($exam !== 'エコー' && $exam !== 'ECHO' && !$is_empty && !$is_no_waku);
                
                $time_display = $waku;
                if ($is_mismatch) {
                    $time_display = '🚨 迷子 (' . htmlspecialchars($waku, ENT_QUOTES, 'UTF-8') . ')';
                } elseif ($waku === 'MISMATCH' || empty($waku)) {
                    $time_display = '🚨 枠未設定';
                }
                
                $fu_url = '#';
                $fu_label = '登録';
                $fu_class = 'followup';
                $fu_title = 'F/U登録';

                $p_id_str = (string)($row['patient_id'] ?? '');
                if (!$is_empty && !$is_no_waku && !empty($p_id_str)) {
                    $fu_params = '';
                    $fu_params .= 'patient_id=' . urlencode($p_id_str);
                    $fu_params .= '&patient_name=' . urlencode((string)($row['patient_name'] ?? ''));
                    $fu_params .= '&patient_kana=' . urlencode((string)($row['patient_kana'] ?? ''));
                    $fu_params .= '&birthday=' . urlencode((string)($row['birthday'] ?? ''));
                    $fu_params .= '&department_code=' . urlencode($dept);
                    $fu_params .= '&doctor_name=' . urlencode((string)($row['doctor_name'] ?? ''));
                    $fu_params .= '&exam_types=' . urlencode($exam);
                    
                    $fu_url = '/endoscope/followup/edit.php?' . $fu_params;
                    $fu_title = 'F/U登録（新規タブで開きます）';
                }
                
                $modal_date = $selected_date;
                $modal_waku = $waku;
                $modal_dept = $dept;
                $modal_schedule_id = $schedule_id;
                
                $row_class = '';
                if ($is_mismatch) {
                    $row_class = 'mismatch-row';
                } elseif ($is_empty) {
                    $row_class = 'empty-slot-row';
                }

                $age_int = null;
                $age_cls = 'age-under';
                $b_date_clean = '';
                $b_wareki = '';
                if (!$is_empty && !$is_no_waku && !empty($row['birthday'])) {
                    $b_date_clean = substr(trim((string)$row['birthday']), 0, 10);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_date_clean)) {
                        $b_dt = new DateTime($b_date_clean);
                        $t_dt = new DateTime($selected_date);
                        $age_int = $b_dt->diff($t_dt)->y;
                        $age_cls = ($age_int >= 70) ? 'age-senior' : 'age-under';
                        $b_wareki = convertToWareki($b_date_clean);
                    }
                }

                $room_val = trim((string)($row['room_number'] ?? ''));
                $is_room_duplicate = false;
                if (!$is_empty && !$is_no_waku && $room_val !== '' && $room_val !== '未指定' && $room_val !== '-') {
                    if (isset($room_counts[$room_val]) && $room_counts[$room_val] > 1) {
                        $is_room_duplicate = true;
                    }
                }

                $prep_val = trim((string)($row['bowel_prep'] ?? ''));
                $prep_cls = '';
                if ($prep_val === 'モビ') $prep_cls = 'prep-mobi';
                elseif ($prep_val === 'マグP') $prep_cls = 'prep-magp';
                elseif ($prep_val === 'ニフ') $prep_cls = 'prep-nif';

                $created_at_val = $row['created_at'] ?? '';
                $updated_at_val = $row['updated_at'] ?? '';
                $schedule_tooltip = "スケジュールID: {$schedule_id}\n作成日時: {$created_at_val}\n更新日時: {$updated_at_val}";
            ?>
                <tr data-group="<?= htmlspecialchars($filter_category, ENT_QUOTES, 'UTF-8') ?>" 
                    data-reserved="<?= ($is_empty || $is_no_waku) ? 'false' : 'true' ?>"
                    class="<?= $row_class ?>">
                    
                    <!-- 1列目: ➕新規追加 -->
                    <td style="text-align:center; white-space:nowrap;">
                        <?php if (!$is_no_waku && !$is_mismatch): ?>
                            <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', 0, '<?= $modal_dept ?>')" class="icon-btn add" title="この枠に新規予約を追加">➕</a>
                        <?php else: ?>
                            <span class="icon-btn disabled">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- 2列目: 🚫枠なし設定 ／ ⭕通常枠復帰 -->
                    <td style="text-align:center; white-space:nowrap;">
                        <?php if ($is_empty): ?>
                            <a href="?dept=<?=$dept?>&date=<?=$selected_date?>&action=set_no_waku&waku=<?=urlencode($waku)?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="icon-btn" title="この枠を「枠なし」に設定します" style="color:#dc2626; font-size:12px; font-weight:bold;">🚫</a>
                        <?php elseif ($is_no_waku): ?>
                            <a href="?dept=<?=$dept?>&date=<?=$selected_date?>&action=revert_waku&sid=<?=$schedule_id?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&show_empty=<?=$show_empty?'1':'0'?>" class="icon-btn" title="通常枠（空き枠）に戻す" style="color:#2563eb; font-size:13px; font-weight:bold;">⭕</a>
                        <?php else: ?>
                            <span class="icon-btn disabled">-</span>
                        <?php endif; ?>
                    </td>
                    
                    <!-- 3列目: ✏️編集・削除 -->
                    <td style="text-align:center;">
                        <?php if (!$is_empty && !$is_no_waku && $schedule_id > 0): ?>
                            <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', <?= $schedule_id ?>, '<?= $modal_dept ?>')" 
                               class="icon-btn edit" title="この予約を編集・枠再割当・削除">✏️</a>
                        <?php else: ?>
                            <span class="icon-btn disabled">-</span>
                        <?php endif; ?>
                    </td>
                    
                    <!-- 4列目: 時間枠名 -->
                    <td>
                        <?php if ($is_no_waku): ?>
                            <span style="color:#dc2626; text-decoration:line-through; font-weight:bold;"><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <strong style="font-size:13px; color:#1e293b;"><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php endif; ?>
                    </td>
                    
                    <!-- 5列目: 患者No -->
                    <td style="text-align:center;">
                        <?php if ($is_empty || $is_no_waku || empty($p_id_str) || $p_id_str === '---'): ?>
                            <?= ($is_empty || $is_no_waku) ? '' : '---' ?>
                        <?php else: ?>
                            <a href="http://192.168.1.16/karteview/karteview.php?source=new&amp;do_search=1&amp;c_filter=all&amp;show_all_sick=0&amp;show_images=1&amp;show_orders=1&amp;show_dots=1&amp;patient_no=<?= urlencode($p_id_str) ?>" 
                               target="_blank" 
                               rel="noopener noreferrer" 
                               class="karte-link" 
                               title="カルテビューアーを開く (<?= htmlspecialchars($p_id_str, ENT_QUOTES, 'UTF-8') ?>)">
                                <?= htmlspecialchars($p_id_str, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    
                    <!-- 6列目: 患者名・年齢・アラート -->
                    <td>
                        <?php if ($is_empty): ?>
                            <span style="color:#8b5cf6; font-size:11.5px; font-weight:700;">［空き枠］</span>
                        <?php elseif ($is_no_waku): ?>
                            <span style="color:#dc2626; font-size:11.5px; font-weight:bold;">［枠なし設定済］</span>
                        <?php else: ?>
                            <div style="line-height: 1.3;" title="<?= htmlspecialchars($schedule_tooltip, ENT_QUOTES, 'UTF-8') ?>">
                                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:4px;">
                                    <strong style="font-size:13px; cursor:help;"><?= htmlspecialchars((string)$row['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ($age_int !== null): ?>
                                        <span class="age-box <?= $age_cls ?>"><?= $age_int ?>歳</span>
                                    <?php endif; ?>
                                    <?php 
                                    if ($schedule_id > 0 && isset($medical_alerts_map[$schedule_id])) {
                                        $alert_html = renderMedicalAlertBadgesHTML($medical_alerts_map[$schedule_id]);
                                        if ($alert_html !== '') {
                                            echo '<span style="cursor:pointer;" onclick="openMedicalAlertScanModal({schedule_ids: [' . $schedule_id . '], dept: \'' . htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') . '\'})" title="クリックしてこの患者のアラート詳細・変更を開く">' . $alert_html . '</span>';
                                        }
                                    }
                                    ?>
                                </div>
                                <?php if ($b_wareki !== ''): ?>
                                    <div style="font-size:10px; color:#64748b; margin-top:2px;">
                                        🎂 <?= htmlspecialchars($b_wareki, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- 7列目: 担当医師 ＆ 検査種別 -->
                    <td style="text-align:left;">
                        <?php if (!$is_empty && !$is_no_waku): ?>
                            <div style="display:inline-flex; align-items:left; gap:3px; justify-content:left;">
                                <?= getDoctorBadgeHTML((string)($row['doctor_name'] ?? '')) ?>
                                <span class="exam-badge <?=$cls?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- 8列目: 部屋番号 -->
                    <td style="text-align:center;">
                        <?php if (!$is_empty && !$is_no_waku && !empty($room_val) && $room_val !== '未指定' && $room_val !== '-'): ?>
                            <?php if ($is_room_duplicate): ?>
                                <span class="room-duplicate" title="⚠️ 同日に同一の部屋が重複設定されています"><?= htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span style="font-weight:700; color:#334155;"><?= htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- 9列目: 下剤 -->
                    <td style="text-align:center;">
                        <?php if (!$is_empty && !$is_no_waku && !empty($prep_val) && $prep_val !== '未指定'): ?>
                            <span class="prep-badge <?= $prep_cls ?>"><?= htmlspecialchars($prep_val, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- 10列目: 備考 -->
                    <td style="min-width:180px;">
                        <?= renderRemarksRtfHtml($remarks_str) ?>
                    </td>
                    
                    <!-- 11列目: シート印刷 -->
                    <td style="text-align:center;">
                        <?php if ($can_sheet): ?>
                            <a href="month_schedule.php?dept=<?=$dept?>&date=<?=$selected_date?>&sheet_single=<?=$row['schedule_id']?>" target="_blank" class="sheet-btn-small" title="検査シート印刷">📋</a>
                        <?php else: ?>
                            <span style="font-size:8px; color:#cbd5e1;">-</span>
                        <?php endif; ?>
                    </td>

                    <!-- 12列目: F/U登録 -->
                    <td style="text-align:center;">
                        <?php if (!$is_empty && !$is_no_waku && !empty($p_id_str)): ?>
                            <a href="<?= $fu_url ?>" target="_blank" class="sheet-btn-small <?= $fu_class ?>" title="<?= $fu_title ?>">
                                📋 <?= $fu_label ?>
                            </a>
                        <?php else: ?>
                            <span style="font-size:8px; color:#cbd5e1;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>

<!-- ── Modern Toast Notification コンテナ (Popover API) ── -->
<div id="toastContainer" popover="manual" class="modern-toast-container">
    <div id="toastMessage" class="modern-toast info">
        <span>ℹ️</span> <span>お知らせ</span>
    </div>
</div>

<!-- ── 日別カレンダーメモ編集モーダル (<dialog closedby="any">) ── -->
<dialog id="dailyMemoModal" closedby="any" class="modern-dialog" style="max-width: 480px; width: 92%;">
    <div class="dup-modal-header" style="background:#fef3c7; border-bottom:1px solid #fde68a;">
        <div class="dup-modal-title" style="color:#92400e;">
            <span>📝</span> <span id="memoModalTitle">日別カレンダーメモ編集</span>
        </div>
        <button type="button" class="dup-modal-close-btn" onclick="closeDailyMemoModal()" title="閉じる">✕</button>
    </div>
    <div style="padding: 16px 20px;">
        <div style="font-size: 11.5px; color: #64748b; margin-bottom: 10px; line-height: 1.5;">
            当日の診療体制や医師の予定（例：「〇〇先生午後休診」「院内行事のため午前のみ」など）を登録します。予約一覧ヘッダーに表示されます。
        </div>
        <input type="hidden" id="memoModalDate" value="">
        <input type="hidden" id="memoModalDept" value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>">
        <textarea id="memoModalText" rows="3" style="width: 100%; box-sizing: border-box; padding: 10px; font-size: 13.5px; border: 1.5px solid #cbd5e1; border-radius: 6px; resize: vertical; line-height: 1.4;" placeholder="例：〇〇先生午後休診"></textarea>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px;">
            <button type="button" onclick="deleteDailyMemo()" class="cal-toggle-btn" style="background: #fff; color: #dc2626; border-color: #fca5a5;">
                🗑️ メモ削除
            </button>
            <div style="display: flex; gap: 8px;">
                <button type="button" onclick="closeDailyMemoModal()" class="cal-toggle-btn" style="background: #f1f5f9; color: #475569;">
                    キャンセル
                </button>
                <button type="button" onclick="saveDailyMemo()" class="cal-toggle-btn" style="background: #3b82f6; color: #fff; border-color: #3b82f6; font-weight: bold;">
                    💾 保存する
                </button>
            </div>
        </div>
        <div style="margin-top: 12px; padding-top: 8px; border-top: 1px dashed #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <span style="font-size: 11px; color: #64748b;">※ メモは診療科（<?= $dept === 'PROCT' ? '🟢 肛門科' : '🔵 胃腸科' ?>）ごとに独立管理されます</span>
            <a href="furiwake_daymemo.php" target="_blank" style="font-size: 11.5px; color: #0284c7; text-decoration: none; font-weight: 700;">
                🔧 メモ科振分メンテ ➔
            </a>
        </div>
    </div>
</dialog>

<!-- ── 未来予約 重複・併用チェックモーダル (<dialog closedby="any">) ── -->
<dialog id="duplicateCheckModal" closedby="any" class="modern-dialog" style="max-width: 1120px; width: 95%;">
    <div class="dup-modal-header">
        <div class="dup-modal-title">
            <span>⚠️ 未来予約 重複・併用チェック</span>
            <span id="dupTotalBadge" class="header-badge" style="background:#fef3c7; color:#b45309; border-color:#fde68a;">0件検出</span>
        </div>
        <button type="button" class="dup-modal-close-btn" onclick="closeDuplicateCheckModal()" title="閉じる">✕</button>
    </div>

    <div class="dup-tabs-bar">
        <div class="dup-tabs-group">
            <button type="button" class="dup-tab-btn tab-critical active" onclick="switchDupTab('critical')">
                🚨 異常の疑い <span class="dup-tab-badge" id="badgeCritical">0</span>
            </button>
            <button type="button" class="dup-tab-btn tab-sameday" onclick="switchDupTab('sameday')">
                ℹ️ ① 同日併用 <span class="dup-tab-badge" id="badgeSameday">0</span>
            </button>
            <button type="button" class="dup-tab-btn tab-staged" onclick="switchDupTab('staged')">
                📅 ② 別日計画 <span class="dup-tab-badge" id="badgeStaged">0</span>
            </button>
            <button type="button" class="dup-tab-btn tab-all" onclick="switchDupTab('all')">
                📋 すべて <span class="dup-tab-badge" id="badgeAll">0</span>
            </button>
        </div>
    </div>

    <div class="dup-modal-body" id="dupModalBody">
        <div style="text-align:center; padding:40px; color:#64748b;">
            <div style="font-size:24px; margin-bottom:8px;">⏳</div>
            <div>予約データを検索・集計しています...</div>
        </div>
    </div>

    <div class="dup-modal-footer">
        <button type="button" onclick="loadDuplicateData()" class="cal-toggle-btn" style="background:#fff;">
            🔄 最新情報に再読み込み
        </button>
        <button type="button" onclick="closeDuplicateCheckModal()" class="cal-toggle-btn" style="background:#3b82f6; color:#fff; border-color:#3b82f6;">
            閉じる
        </button>
    </div>
</dialog>

<script>
window.currentScheduleDept = '<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>';

// ── キーボードショートカット用ナビゲーションURL＆日付マップ ──
window.scheduleNavUrls = {
    prevWeek:   <?= json_encode("?dept={$dept}&date={$prev_week_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>,
    prevDay:    <?= json_encode("?dept={$dept}&date={$prev_day_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>,
    prevClinic: <?= json_encode("?dept={$dept}&date={$prev_clinic_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>,
    today:      <?= json_encode("?dept={$dept}&date={$today_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>,
    nextClinic: <?= json_encode("?dept={$dept}&date={$next_clinic_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>,
    nextDay:    <?= json_encode("?dept={$dept}&date={$next_day_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>,
    nextWeek:   <?= json_encode("?dept={$dept}&date={$next_week_date}&doctor=" . urlencode($filter_doctor) . "&exam=" . urlencode($filter_exam) . "&show_empty=" . ($show_empty ? '1' : '0')) ?>
};
window.scheduleNavDates = {
    prevWeek:   <?= json_encode($prev_week_date) ?>,
    prevDay:    <?= json_encode($prev_day_date) ?>,
    prevClinic: <?= json_encode($prev_clinic_date) ?>,
    today:      <?= json_encode($today_date) ?>,
    nextClinic: <?= json_encode($next_clinic_date) ?>,
    nextDay:    <?= json_encode($next_day_date) ?>,
    nextWeek:   <?= json_encode($next_week_date) ?>
};

// ── キーボード操作イベントリスナー（誤爆防止ガード付き） ──
document.addEventListener('keydown', function(e) {
    const active = document.activeElement;
    const isInputActive = active && (
        active.tagName === 'INPUT' ||
        active.tagName === 'TEXTAREA' ||
        active.tagName === 'SELECT' ||
        active.isContentEditable
    );
    const isModalOpen = !!document.querySelector('dialog[open]');
    const isFlatpickrOpen = !!document.querySelector('.flatpickr-calendar.open');
    if (isInputActive || isModalOpen || isFlatpickrOpen) {
        return;
    }

    let targetUrl = null;
    let targetDate = null;
    let targetLabel = '';

    // Shift + 矢印: 診療日スキップ移動
    if (e.shiftKey && e.key === 'ArrowLeft') {
        targetUrl = window.scheduleNavUrls.prevClinic;
        targetDate = window.scheduleNavDates.prevClinic;
        targetLabel = '前診療日';
    } else if (e.shiftKey && e.key === 'ArrowRight') {
        targetUrl = window.scheduleNavUrls.nextClinic;
        targetDate = window.scheduleNavDates.nextClinic;
        targetLabel = '次診療日';
    } else if (!e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (e.key === 'ArrowLeft') {
            targetUrl = window.scheduleNavUrls.prevDay;
            targetDate = window.scheduleNavDates.prevDay;
            targetLabel = '前日 (-1日)';
        } else if (e.key === 'ArrowRight') {
            targetUrl = window.scheduleNavUrls.nextDay;
            targetDate = window.scheduleNavDates.nextDay;
            targetLabel = '翌日 (+1日)';
        } else if (e.key === 'ArrowUp') {
            targetUrl = window.scheduleNavUrls.prevWeek;
            targetDate = window.scheduleNavDates.prevWeek;
            targetLabel = '前週 (-7日)';
        } else if (e.key === 'ArrowDown') {
            targetUrl = window.scheduleNavUrls.nextWeek;
            targetDate = window.scheduleNavDates.nextWeek;
            targetLabel = '翌週 (+7日)';
        } else if (e.key === 't' || e.key === 'T' || e.key === 'Home') {
            targetUrl = window.scheduleNavUrls.today;
            targetDate = window.scheduleNavDates.today;
            targetLabel = '今日';
        }
    }

    if (targetUrl) {
        e.preventDefault();
        const currentSelDate = '<?= $selected_date ?>';
        if (targetDate === currentSelDate) {
            if (typeof showToast === 'function') {
                showToast(`⭐ 既に本日（${targetDate}）を表示しています`, 'info');
            }
            return;
        }
        if (typeof showToast === 'function') {
            showToast(`📅 ${targetLabel} (${targetDate}) へ移動中...`, 'info');
        }
        window.location.href = targetUrl;
    }
});
</script>

<!-- 🖨️ 特大Flatpickr ＆ 西暦/和暦ワンクリック切替ロジック -->
<script>
(function() {
    const selectedDateStr = '<?= $selected_date ?>';
    const selDowStr = '<?= $day_str ?>';
    const onokaiHolidays = <?= $onokai_holidays_json ?>;

    const dateValText = document.getElementById('dateValText');
    const dateModeBadge = document.getElementById('dateModeBadge');
    const dateTextToggle = document.getElementById('dateTextToggle');
    const calendarTriggerBtn = document.getElementById('calendarTriggerBtn');
    const flatpickrInput = document.getElementById('mainFlatpickr');

    // 和暦フォーマット関数
    function getWarekiString(dateStr, dowStr) {
        const parts = dateStr.split('-');
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        const d = parseInt(parts[2], 10);

        let g = '令和';
        let gy = y - 2018;
        if (y < 2019) {
            g = '平成';
            gy = y - 1988;
        }
        const gStr = (gy === 1) ? '元' : gy;
        return `${g}${gStr}年${m}/${d} (${dowStr})`;
    }

    // 西暦フォーマット関数
    function getSeirekiString(dateStr, dowStr) {
        const parts = dateStr.split('-');
        const y = parts[0];
        const m = parts[1];
        const d = parts[2];
        return `${y}/${m}/${d} (${dowStr})`;
    }

    // 表示更新
    let currentMode = 'seireki';
    try {
        currentMode = localStorage.getItem('schedule_note_date_mode') || 'seireki';
    } catch (e) {}

    function updateDateDisplay() {
        if (!dateValText || !dateModeBadge) return;
        if (currentMode === 'wareki') {
            dateValText.textContent = getWarekiString(selectedDateStr, selDowStr);
            dateModeBadge.textContent = '和暦';
            dateModeBadge.style.background = '#fef3c7';
            dateModeBadge.style.color = '#92400e';
        } else {
            dateValText.textContent = getSeirekiString(selectedDateStr, selDowStr);
            dateModeBadge.textContent = '西暦';
            dateModeBadge.style.background = '#bfdbfe';
            dateModeBadge.style.color = '#1e40af';
        }
    }
    updateDateDisplay();

    // テキスト部分クリックで「西暦 ⇔ 和暦」ワンクリック切替
    if (dateTextToggle) {
        dateTextToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            currentMode = (currentMode === 'seireki') ? 'wareki' : 'seireki';
            try {
                localStorage.setItem('schedule_note_date_mode', currentMode);
            } catch (e) {}
            updateDateDisplay();
        });
    }

    // Flatpickr初期化（特大カレンダー・小野会カラー）
    if (typeof flatpickr !== 'undefined' && flatpickrInput) {
        const fp = flatpickr(flatpickrInput, {
            locale: 'ja',
            defaultDate: selectedDateStr,
            positionElement: document.getElementById('customDateControl'),
            position: 'auto center',
            monthSelectorType: 'static',
            disableMobile: true, // モバイルUIではなく常に特大カレンダーを表示
            onDayCreate: function(dObj, dStr, fpInstance, dayElem) {
                const dt = dayElem.dateObj;
                const y = dt.getFullYear();
                const m = String(dt.getMonth() + 1).padStart(2, '0');
                const d = String(dt.getDate()).padStart(2, '0');
                const dateKey = `${y}-${m}-${d}`;
                const dow = dt.getDay();

                // 1. 小野会公休（日曜・祝日・年末年始公休）: 赤色（最優先）
                if (dow === 0 || onokaiHolidays[dateKey]) {
                    dayElem.classList.add('onokai-holiday');
                    if (onokaiHolidays[dateKey]) {
                        dayElem.title = onokaiHolidays[dateKey];
                    }
                } else if (dow === 4) {
                    // 2. 木曜: 青色
                    dayElem.classList.add('onokai-thursday');
                }
            },
            onChange: function(selectedDates, dateStr) {
                if (!dateStr || dateStr === selectedDateStr) return;
                const targetUrl = '?dept=<?= urlencode($dept) ?>' +
                    '&date=' + encodeURIComponent(dateStr) +
                    '&doctor=<?= urlencode($filter_doctor) ?>' +
                    '&exam=<?= urlencode($filter_exam) ?>' +
                    '&show_empty=<?= $show_empty ? '1' : '0' ?>';
                window.location.href = targetUrl;
            }
        });

        // 📅 アイコンクリック時のみ特大カレンダーを展開
        if (calendarTriggerBtn) {
            calendarTriggerBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (fp.isOpen) {
                    fp.close();
                } else {
                    fp.open();
                }
            });
        }
    }
})();
</script>
<script src="assets/js/month_schedule.js?v=<?= time() ?>"></script>

<?php include __DIR__ . '/audit_modal_inc.php'; ?>
<?php include __DIR__ . '/medical_alert_modal_inc.php'; ?>

</body>
</html>
