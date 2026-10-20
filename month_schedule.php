<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\month_schedule.php
 * ファイル名: month_schedule.php
 * 
 * 内視鏡予約 ダッシュボード (month_schedule.php) - Modern Web 最適化版
 * 生成日時: 2026-09-27
 * 修正履歴:
 *   - 【Modern Web ガイダンス準拠】Web標準仕様への全面リファクタリング
 *   - 【Native Dialog API】手製モーダルオーバーレイを HTML5 標準の `<dialog closedby="any">` と `showModal()` に置換（Safari向けフォールバック完備）
 *   - 【Popover API Toast】同期ブロッキングな `alert()` を Popover API (`popover="manual"`) による非侵入型トースト通知に刷新
 *   - 【CSS Scrollbar 標準化】`scrollbar-color` および `scrollbar-width: thin` を導入し、モダンなスクロール外観を実現
 *   - 【CSS Content-Visibility 最適化】カレンダー週行・予約テーブル行の描画負荷を低減する `content-visibility: auto` と `contain-intrinsic-size` を最適化
 *   - 【CSS :has() セレクタ】重複部屋やアラート行の動的スタイリングを CSS `:has()` で宣言的に実装
 *   - 【Modern JS】非同期通信を `async/await` に統一し、堅牢なエラーハンドリングを実装
 * 
 * 修正履歴 (2026-09-28):
 *   - 【モジュール分離・高速化】巨大PHPから CSS (assets/css/month_schedule2.css) および JS (assets/js/month_schedule2.js) を独立分離し、表示速度と保守性を大幅改善
 *   - 【ヘッダーUI崩れ防止】ボタングループの強制改行を防止（文言スリム化・余白最適化）
 *   - 【視認性改善】操作履歴（Undo）モーダル等のダークヘッダー内テキストのコントラスト比向上（白文字強調）
 *   - 【医師別予約件数バッジ安全同期】月跨ぎ・別月表示時でも選択日の予約件数を正確に集計・同期（強制0件バグ解消）
 *   - 【折りたたみ・上下入替バグ解消】重複スクリプト・二重変数宣言 (SyntaxError) の根絶、DOM判定復元、二重イベントバインドによる安定動作の確立
 * 
 * 修正履歴 (2026-09-29):
 *   - 【ツールバーUI刷新・トグルボタン化】「枠:」ラベル付きカプセルへの集約（GIF/エコー/TCF）、各検査枠のテーマカラー連動（エコー水色同期）、チェックボックス廃止、「空き枠表示」の独立トグルボタン化、🎯アイコン撤去、高さ32px完全ツライチ化
 *   - 【カレンダーコマンドパレット整理】カレンダー用フィルター（医師/種別/表示切替）をカレンダー枠内に内包。カレンダー折りたたみ時の非表示連動および上下入替時の追従移動を完全サポート
 *   - 【直近空き枠速報フローティング化】画面右下への常時フローティング配置、医師通知欄スタイルのダーク系半透明グラスモフィズム、ドラッグ移動＆24時間位置・開閉状態保持（localStorage）、✕ボタンでのコンパクトピル最小化に対応
 *   - 【本物モーダルダイアログ化】予約登録・編集画面（modal_edit.php）を別ウィンドウではなく、画面内インページモーダル（modal_edit_runner.js）として表示。暗転すりガラスオーバーレイと自動DOM生成で呼び出し元コード肥大化を完全防止
 *   - 【アセット統合・ファイル名正規化】month_schedule2.js と modal_edit_runner.js を assets/js/month_schedule.js へ統合一本化。CSSも assets/css/month_schedule.css に正規化
 * 
 * 修正履歴 (2026-10-09):
 *   - 【医師予定表連携 (yotei API)】/yotei/api/events.php より医師の休診・不在予定を取得し、カレンダーの各日付セル内にコンパクトバッジ（🙅🏼医師名 概要）として表示
 *   - 【要件準拠】かわらばん情報（memo）や小野会全体会日程は非表示・除外、医師個人の休診・不在予定のみを表示
 *   - 【UI最適化】ホバー時に正式予定タイトル・時間帯・備考等の詳細ツールチップを展開。医院休診日（日・祝等）は非表示とし、全医師の予定を常時一覧可能に実装
 * 
 * 修正履歴 (2026-10-09 夜):
 *   - 【日別メモ 診療科完全独立 ＆ 振り分けメンテ連携 (furiwake_daymemo.php)】
 *     - 日別メモの診療科（PROCT/GASTRO）完全独立を徹底
 *     - ツールバーおよび日別メモ編集モーダル内に振り分けメンテ画面（furiwake_daymemo.php）への直通リンクを新設
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

$year = isset($_GET['year']) ? (int)$_GET['year'] : 
        (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr((string)$_SESSION['ui_state']['schedule_month'], 0, 4) : (int)date('Y'));
$month = isset($_GET['month']) ? (int)$_GET['month'] : 
         (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr((string)$_SESSION['ui_state']['schedule_month'], 5, 2) : (int)date('n'));

$selected_date = isset($_GET['date']) && is_string($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// ★ dateパラメータが指定されている場合、または初期アクセス時にyear/monthを安全同期
if (isset($_GET['date']) && is_string($_GET['date']) && preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $_GET['date'], $d_matches)) {
    if (!isset($_GET['year'])) {
        $year = (int)$d_matches[1];
    }
    if (!isset($_GET['month'])) {
        $month = (int)$d_matches[2];
    }
} elseif (!isset($_GET['year']) && !isset($_GET['month'])) {
    // URLにyear/monthもdateも指定されていない初期表示時は確実に当月・当日に同期
    $year = (int)date('Y');
    $month = (int)date('n');
    $selected_date = date('Y-m-d');
}
$filter_doctor = isset($_GET['doctor']) && is_string($_GET['doctor']) ? trim($_GET['doctor']) : 'all';
$filter_exam   = isset($_GET['exam']) && is_string($_GET['exam']) ? trim($_GET['exam']) : 'all';
$sheet_single  = isset($_GET['sheet_single']) ? (int)$_GET['sheet_single'] : 0;
$display_mode  = isset($_GET['display_mode']) && is_string($_GET['display_mode']) ? $_GET['display_mode'] : 'use_max';

$show_empty = isset($_GET['show_empty']) ? ((string)$_GET['show_empty'] === '1') : true;

// ★ 日別メモ保存・更新 AJAX API
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

// ★ 未来予約 重複・併用チェック用 AJAX API
if (isset($_GET['action']) && $_GET['action'] === 'ajax_check_duplicates') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $sql = "
            SELECT 
                schedule_id,
                exam_date,
                scheduled_time,
                exam_type,
                department_code,
                doctor_name,
                patient_id,
                patient_name,
                patient_kana,
                birthday,
                remarks,
                created_at,
                updated_at
            FROM t_endoscopy_schedule
            WHERE exam_date >= CURRENT_DATE
              AND is_canceled = FALSE
              AND patient_id IS NOT NULL 
              AND TRIM(patient_id) != ''
              AND TRIM(patient_id) != '---'
              AND remarks NOT LIKE '%枠なし%'
            ORDER BY patient_id, exam_date, scheduled_time
        ";
        $stmt = $pdo->query($sql);
        $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $by_patient = [];
        foreach ($all_rows as $row) {
            $pid = trim($row['patient_id']);
            $by_patient[$pid][] = $row;
        }

        $categories = [
            'critical' => [],
            'sameday'  => [],
            'staged'   => [],
        ];

        $today = new DateTime(date('Y-m-d'));

        foreach ($by_patient as $pid => $reservations) {
            if (count($reservations) < 2) continue;

            $has_critical = false;
            $critical_reasons = [];
            $dates = [];

            foreach ($reservations as $r) {
                $dates[$r['exam_date']] = true;
            }
            $distinct_date_count = count($dates);

            for ($i = 0; $i < count($reservations); $i++) {
                for ($j = $i + 1; $j < count($reservations); $j++) {
                    $r1 = $reservations[$i];
                    $r2 = $reservations[$j];

                    $d1 = new DateTime($r1['exam_date']);
                    $d2 = new DateTime($r2['exam_date']);
                    $day_diff = abs((int)$d1->diff($d2)->days);

                    if ($day_diff === 0 && $r1['scheduled_time'] === $r2['scheduled_time']) {
                        $has_critical = true;
                        $critical_reasons[] = "【同日同一枠重複】" . $r1['exam_date'] . " " . $r1['scheduled_time'] . " に重複登録";
                    } elseif ($day_diff === 0 && $r1['exam_type'] === $r2['exam_type'] && !empty($r1['exam_type'])) {
                        $has_critical = true;
                        $critical_reasons[] = "【同日同種別重複】" . $r1['exam_date'] . " に " . $r1['exam_type'] . " が重複登録";
                    } elseif ($day_diff >= 1 && $day_diff <= 3 && $r1['exam_type'] === $r2['exam_type'] && !empty($r1['exam_type'])) {
                        $has_critical = true;
                        $critical_reasons[] = "【連日重複】" . $day_diff . "日差で同一検査（" . $r1['exam_type'] . "）が登録 (" . $r1['exam_date'] . " と " . $r2['exam_date'] . ")";
                    } elseif ($day_diff > 3 && $day_diff <= 14 && $r1['exam_type'] === $r2['exam_type'] && !empty($r1['exam_type'])) {
                        $has_critical = true;
                        $critical_reasons[] = "【短期重複】" . $day_diff . "日差で同一検査（" . $r1['exam_type'] . "）が登録 (" . $r1['exam_date'] . " と " . $r2['exam_date'] . ")";
                    }
                }
            }

            // 年齢計算
            $age_int = null;
            if (!empty($reservations[0]['birthday'])) {
                $b_clean = substr(trim((string)$reservations[0]['birthday']), 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $b_clean)) {
                    $b_dt = new DateTime($b_clean);
                    $age_int = $b_dt->diff($today)->y;
                }
            }

            $formatDateTime = function($val) {
                if (empty($val)) return '-';
                try {
                    $d = new DateTime((string)$val);
                    return $d->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    return substr(trim((string)$val), 0, 19);
                }
            };

            $item = [
                'patient_id' => $pid,
                'patient_name' => $reservations[0]['patient_name'],
                'patient_kana' => $reservations[0]['patient_kana'],
                'age' => $age_int,
                'birthday' => $reservations[0]['birthday'],
                'reservations' => array_map(function($r) use ($formatDateTime) {
                    $dt = new DateTime($r['exam_date']);
                    $weekdays = ['日','月','火','水','木','金','土'];
                    $w_str = $weekdays[(int)$dt->format('w')];
                    return [
                        'schedule_id' => (int)$r['schedule_id'],
                        'exam_date' => $r['exam_date'],
                        'day_of_week' => $w_str,
                        'scheduled_time' => $r['scheduled_time'],
                        'exam_type' => $r['exam_type'],
                        'department_code' => $r['department_code'],
                        'doctor_name' => $r['doctor_name'],
                        'remarks' => $r['remarks'],
                        'remarks_html' => renderRemarksRtfHtml($r['remarks']),
                        'created_at' => $formatDateTime($r['created_at'] ?? null),
                        'updated_at' => $formatDateTime($r['updated_at'] ?? null),
                    ];
                }, $reservations),
                'critical_reasons' => array_values(array_unique($critical_reasons)),
            ];

            if ($has_critical) {
                $categories['critical'][] = $item;
            } elseif ($distinct_date_count === 1) {
                $categories['sameday'][] = $item;
            } else {
                $categories['staged'][] = $item;
            }
        }

        echo json_encode([
            'success' => true,
            'summary' => [
                'critical_count' => count($categories['critical']),
                'sameday_count'  => count($categories['sameday']),
                'staged_count'   => count($categories['staged']),
                'total_patients' => count($categories['critical']) + count($categories['sameday']) + count($categories['staged']),
            ],
            'data' => $categories,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ★ 枠なし設定・解除のDB更新処理
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
            header("Location: month_schedule.php?dept={$dept}&year={$year}&month={$month}&date={$selected_date}");
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
                    'diff_summary' => "枠なし解除・通常枠復帰"
                ]);
            }
            header("Location: month_schedule.php?dept={$dept}&year={$year}&month={$month}&date={$selected_date}");
            exit;
        } catch (PDOException $e) {
            die("枠なし解除SQLエラー: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
    }
}

if (!in_array($dept, ['PROCT', 'GASTRO'], true)) { $dept = 'PROCT'; }
if (!in_array($display_mode, ['use_max', 'remain', 'count'], true)) { $display_mode = 'use_max'; }

if (!isset($_SESSION['ui_state']) || !is_array($_SESSION['ui_state'])) {
    $_SESSION['ui_state'] = [];
}
$_SESSION['ui_state']['schedule_month'] = sprintf('%04d-%02d', $year, $month);
$_SESSION['ui_state']['schedule_dept'] = $dept;

$first_day_timestamp = strtotime("$year-$month-01");
$days_in_month = (int)date('t', $first_day_timestamp);
$start_weekday = (int)date('w', $first_day_timestamp);

$start_date_month = sprintf('%04d-%02d-01', $year, $month);
$end_date_month   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$prev_month_ts = strtotime("-1 month", $first_day_timestamp);
$prev_year = (int)date('Y', $prev_month_ts);
$prev_month = (int)date('n', $prev_month_ts);
$next_month_ts = strtotime("+1 month", $first_day_timestamp);
$next_year = (int)date('Y', $next_month_ts);
$next_month = (int)date('n', $next_month_ts);

$quick_months = [];
$current_ts = strtotime(date('Y-m-01'));
for ($m_idx = 0; $m_idx < 12; $m_idx++) {
    $target_ts = strtotime("+$m_idx month", $current_ts);
    $q_year = (int)date('Y', $target_ts);
    $q_month = (int)date('n', $target_ts);
    $quick_months[] = [
        'year' => $q_year,
        'month' => $q_month,
        'is_active' => ($q_year === $year && $q_month === $month),
        'is_new_year' => ($q_month === 1 && $m_idx > 0)
    ];
}

$doctors_list = getDoctorsForDept($pdo, $dept);
if (!is_array($doctors_list)) { $doctors_list = []; }

function getMasterItems(PDO $pdo, string $category, array $default_items = []): array {
    try {
        $stmt = $pdo->prepare("
            SELECT item_value 
            FROM m_input_master 
            WHERE category = :cat 
            ORDER BY display_order ASC, master_id ASC
        ");
        $stmt->execute([':cat' => $category]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return (!empty($rows)) ? $rows : $default_items;
    } catch (Exception $e) {
        return $default_items;
    }
}
$exam_types_master = getMasterItems($pdo, 'exam_type', ['TCF', '浣腸CF', 'GIF', 'ポリペク', 'W', 'エコー', '◆胃がん検診◆']);

// 1. 月間確定予約数（厳格に「＊＊＊🚫枠なし🚫＊＊＊」以外をカウント対象とする）
$stmt_pref = $pdo->prepare("
    SELECT 
        exam_date::text AS exam_date,
        COALESCE(doctor_name, '') AS doctor_name,
        COALESCE(exam_type, '') AS exam_type,
        COUNT(*) AS cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date >= :start_date
      AND exam_date <= :end_date
      AND is_canceled = FALSE
      AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
    GROUP BY exam_date, doctor_name, exam_type
");
$stmt_pref->execute([
    ':dept' => $dept,
    ':start_date' => $start_date_month,
    ':end_date' => $end_date_month
]);

$month_reserved_cache = [];
while ($row = $stmt_pref->fetch(PDO::FETCH_ASSOC)) {
    $d = (string)$row['exam_date'];
    $doc = (string)$row['doctor_name'];
    $etype = (string)$row['exam_type'];
    $cnt = (int)$row['cnt'];
    if (!isset($month_reserved_cache[$d])) { $month_reserved_cache[$d] = []; }
    if (!isset($month_reserved_cache[$d][$doc])) { $month_reserved_cache[$d][$doc] = []; }
    $month_reserved_cache[$d][$doc][$etype] = $cnt;
}

// 2. 月間未割当予約データ
$stmt_unassigned = $pdo->prepare("
    SELECT 
        exam_date::text AS exam_date,
        COALESCE(exam_type, 'その他') AS exam_type,
        COUNT(*) AS cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date >= :start_date
      AND exam_date <= :end_date
      AND is_canceled = FALSE
      AND (remarks IS NULL OR remarks != '＊＊＊🚫枠なし🚫＊＊＊')
      AND (scheduled_time IS NULL OR scheduled_time = '' OR scheduled_time = 'MISMATCH')
    GROUP BY exam_date, exam_type
");
$stmt_unassigned->execute([
    ':dept' => $dept,
    ':start_date' => $start_date_month,
    ':end_date' => $end_date_month
]);

$month_unassigned_cache = [];
while ($row = $stmt_unassigned->fetch(PDO::FETCH_ASSOC)) {
    $d = (string)$row['exam_date'];
    $etype = (string)($row['exam_type'] ?? 'その他');
    if (!isset($month_unassigned_cache[$d])) { $month_unassigned_cache[$d] = []; }
    $month_unassigned_cache[$d][$etype] = (int)$row['cnt'];
}

// 2.5 月間日別メモプリフェッチ（t_daily_memo）
$stmt_memo = $pdo->prepare("
    SELECT target_date::text AS target_date, memo 
    FROM t_daily_memo 
    WHERE department_code = :dept 
      AND target_date >= :start_date 
      AND target_date <= :end_date
");
$stmt_memo->execute([':dept' => $dept, ':start_date' => $start_date_month, ':end_date' => $end_date_month]);
$month_daily_memo_cache = $stmt_memo->fetchAll(PDO::FETCH_KEY_PAIR);

// 選択日のメモ（当月外が選ばれた場合のフォールバック含む）
$selected_day_memo = $month_daily_memo_cache[$selected_date] ?? null;
if ($selected_day_memo === null) {
    $stmt_single_memo = $pdo->prepare("SELECT memo FROM t_daily_memo WHERE department_code = :dept AND target_date = :date LIMIT 1");
    $stmt_single_memo->execute([':dept' => $dept, ':date' => $selected_date]);
    $selected_day_memo = $stmt_single_memo->fetchColumn() ?: '';
}

// 3. 枠マスター群プリフェッチ
$slot_master_cache = [
    'attendance' => [],
    'daily_capacity' => [],
    'patterns' => [],
    'waku_map' => []
];

$stmt_att = $pdo->prepare("
    SELECT doctor_name, target_date::text AS target_date, gif_slots, echo_slots, tcf_slots, reason, pattern_name
    FROM m2_doctor_attendance
    WHERE department_code = :dept
      AND target_date >= :start_date
      AND target_date <= :end_date
");
$stmt_att->execute([':dept' => $dept, ':start_date' => $start_date_month, ':end_date' => $end_date_month]);
while ($row = $stmt_att->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['attendance'][(string)$row['target_date']][(string)$row['doctor_name']] = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0),
        'reason' => (string)($row['reason'] ?? $row['pattern_name'] ?? '出勤例外')
    ];
}

$stmt_cap = $pdo->prepare("
    SELECT doctor_name, target_date::text AS target_date, gif_slots, echo_slots, tcf_slots, memo_jp
    FROM m2_doctor_daily_capacity_v2
    WHERE department_code = :dept
      AND target_date >= :start_date
      AND target_date <= :end_date
");
$stmt_cap->execute([':dept' => $dept, ':start_date' => $start_date_month, ':end_date' => $end_date_month]);
while ($row = $stmt_cap->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['daily_capacity'][(string)$row['target_date']][(string)$row['doctor_name']] = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0),
        'reason' => (string)($row['memo_jp'] ?? '日別上書き')
    ];
}

$stmt_pat = $pdo->prepare("
    SELECT 
        doctor_name, 
        day_of_week, 
        MAX(gif_slots) as gif_slots, 
        MAX(echo_slots) as echo_slots, 
        MAX(tcf_slots) as tcf_slots
    FROM m2_doctor_slot_pattern
    WHERE department_code = :dept
      AND is_active = TRUE
    GROUP BY doctor_name, day_of_week
");
$stmt_pat->execute([':dept' => $dept]);
while ($row = $stmt_pat->fetch(PDO::FETCH_ASSOC)) {
    $w_idx = (int)$row['day_of_week'];
    $slot_master_cache['patterns'][$w_idx][(string)$row['doctor_name']] = [
        'GIF' => (int)($row['gif_slots'] ?? 0),
        'ECHO' => (int)($row['echo_slots'] ?? 0),
        'TCF' => (int)($row['tcf_slots'] ?? 0)
    ];
}

$stmt_waku = $pdo->prepare("
    SELECT waku_name, group_code
    FROM m2_waku
    WHERE department_code = :dept
      AND is_active = TRUE
    ORDER BY display_order ASC
");
$stmt_waku->execute([':dept' => $dept]);
while ($row = $stmt_waku->fetch(PDO::FETCH_ASSOC)) {
    $slot_master_cache['waku_map'][(string)$row['waku_name']] = (string)$row['group_code'];
}

if (!function_exists('fetchDoctorEventsFromYotei')) {
/**
 * 医師予定表 API (GET /yotei/api/events.php) より予定一覧を取得
 */
function fetchDoctorEventsFromYotei(string $startDate, string $endDate): array {
    $url = "http://127.0.0.1/yotei/api/events.php?start_date=" . urlencode($startDate) . "&end_date=" . urlencode($endDate) . "&expand_period=1";
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 200 && $res) {
            $json = json_decode($res, true);
            if (isset($json['status']) && $json['status'] === 'success' && !empty($json['data']) && is_array($json['data'])) {
                return $json['data'];
            }
        }
    } else {
        $ctx = stream_context_create([
            'http' => ['timeout' => 2]
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res) {
            $json = json_decode($res, true);
            if (isset($json['status']) && $json['status'] === 'success' && !empty($json['data']) && is_array($json['data'])) {
                return $json['data'];
            }
        }
    }
    return [];
}
}

if (!function_exists('formatDoctorEventSummary')) {
/**
 * 医師予定をカレンダー用のコンパクト表示形式（🙅🏼医師名 概要）に整形
 */
function formatDoctorEventSummary(array $ev): array {
    $docShort = $ev['doctor']['short_name'] ?? $ev['doctor']['name'] ?? '医師';
    $rawTitle = trim((string)($ev['title'] ?? ''));
    
    // タイトルから医師名や役職を除去して概要を抽出
    $clean = $rawTitle;
    $clean = preg_replace('/^(小野|矢野)?[二三雄誠吾義智哲也太郎]+(医師|院長|副院長)?\s*/u', '', $clean);
    $clean = preg_replace('/(医師|院長|副院長)/u', '', $clean);
    
    // 長い説明文（全角スペースやカンマ以降の注記など）をカット
    $parts = preg_split('/[\s　、,]/u', $clean);
    $summary = $parts[0] ?? $clean;
    
    // 代表的な短縮ルール
    $summary = preg_replace('/[：:]00以降不在/u', '時~不在', $summary);
    $summary = preg_replace('/以降不在/u', '~不在', $summary);
    $summary = preg_replace('/午前休診/u', '午前休', $summary);
    $summary = preg_replace('/午後休診/u', '午後休', $summary);
    if (mb_strpos($summary, '休診') !== false && mb_strlen($summary) > 6) {
        $summary = str_replace(['休診', '～'], ['休', '~'], $summary);
    }
    
    // 時間帯情報
    $timeStr = '';
    if (!empty($ev['start_time']) && !empty($ev['end_time'])) {
        $timeStr = substr($ev['start_time'], 0, 5) . '~' . substr($ev['end_time'], 0, 5);
    } elseif (!empty($ev['start_time'])) {
        $timeStr = substr($ev['start_time'], 0, 5) . '~';
    }

    // 表示用ラベル: 🙅🏼 + 医師略称 + 概要
    $label = "🙅🏼{$docShort} {$summary}";
    if (mb_strlen($label) > 13) {
        $label = mb_substr($label, 0, 12) . '…';
    }

    // ホバー用詳細テキスト (title属性)
    $tooltip = "【医師予定】{$ev['doctor']['name']}";
    if (!empty($ev['doctor']['department_name'])) {
        $tooltip .= " ({$ev['doctor']['department_name']})";
    }
    $tooltip .= "\n予定: {$rawTitle}";
    if ($timeStr !== '') {
        $tooltip .= "\n時間: {$timeStr}";
    }
    if (!empty($ev['note'])) {
        $tooltip .= "\n備考: {$ev['note']}";
    }

    return [
        'doc_name' => $ev['doctor']['name'] ?? '',
        'doc_short' => $docShort,
        'label' => $label,
        'tooltip' => $tooltip,
        'time' => $timeStr,
        'raw_title' => $rawTitle
    ];
}
}

// 4. 月間医師予定プリフェッチ（yotei連携: かわらばん・全体会除外、医師個人予定のみ抽出）
$raw_doctor_events = fetchDoctorEventsFromYotei($start_date_month, $end_date_month);
$month_doctor_events_cache = [];
foreach ($raw_doctor_events as $ev) {
    if (empty($ev['doctor']) || empty($ev['doctor']['id'])) continue;
    $ev_type = $ev['event_type'] ?? '';
    // かわらばん（memo）や小野会全体会（meeting）は除外
    if ($ev_type === 'meeting' || $ev_type === 'memo') continue;
    $d_key = $ev['date'] ?? $ev['start_date'] ?? '';
    if ($d_key !== '') {
        $month_doctor_events_cache[$d_key][] = formatDoctorEventSummary($ev);
    }
}

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

function getDoctorStatusFromCache(string $doctor_name, string $date_str, int $w, array $master_cache): array {
    if (isset($master_cache['attendance'][$date_str][$doctor_name])) {
        $data = $master_cache['attendance'][$date_str][$doctor_name];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => true,
            'reason' => $data['reason'],
            'is_attendance' => true
        ];
    }
    if (isset($master_cache['daily_capacity'][$date_str][$doctor_name])) {
        $data = $master_cache['daily_capacity'][$date_str][$doctor_name];
        $slots = ['GIF' => $data['GIF'], 'ECHO' => $data['ECHO'], 'TCF' => $data['TCF']];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => true,
            'reason' => $data['reason'],
            'is_attendance' => false
        ];
    }
    if (isset($master_cache['patterns'][$w][$doctor_name])) {
        $slots = $master_cache['patterns'][$w][$doctor_name];
        return [
            'present' => array_sum($slots) > 0,
            'slots' => $slots,
            'is_exception' => false,
            'reason' => null,
            'is_attendance' => false
        ];
    }
    return [
        'present' => false,
        'slots' => ['GIF' => 0, 'ECHO' => 0, 'TCF' => 0],
        'is_exception' => false,
        'reason' => null,
        'is_attendance' => false
    ];
}

function getDoctorCountsFast(array $doctors, string $date_str, array $cache): array {
    $counts = [];
    $day_cache = $cache[$date_str] ?? [];
    foreach ($doctors as $doctor) {
        $doc_cache = $day_cache[$doctor] ?? [];
        $counts[$doctor] = is_array($doc_cache) ? array_sum($doc_cache) : 0;
    }
    return $counts;
}

function getDayDataFast(string $dept, string $date_str, int $w, array $doctors, array $reserved_cache, array $master_cache, string $filter_doctor = 'all', string $filter_exam = 'all'): array {
    $result = [];
    $total_used = 0;
    $total_max = 0;
    $gif_group = ['GIF', '◆胃がん検診◆', '胃がん検診'];
    $echo_group = ['ECHO', 'エコー'];
    $tcf_group = ['TCF', 'W', 'ポリペク'];
    
    $day_cache = $reserved_cache[$date_str] ?? [];

    foreach ($doctors as $doctor_name) {
        $status = getDoctorStatusFromCache($doctor_name, $date_str, $w, $master_cache);
        if ($filter_doctor !== 'all' && $filter_doctor !== $doctor_name) { continue; }
        
        $doc_exams = $day_cache[$doctor_name] ?? [];
        
        $used_gif = 0;
        foreach ($gif_group as $g_exam) { $used_gif += (int)($doc_exams[$g_exam] ?? 0); }
        $used_echo = 0;
        foreach ($echo_group as $e_exam) { $used_echo += (int)($doc_exams[$e_exam] ?? 0); }
        $used_tcf = 0;
        foreach ($tcf_group as $t_exam) { $used_tcf += (int)($doc_exams[$t_exam] ?? 0); }
        
        $has_reservation = ($used_gif > 0 || $used_echo > 0 || $used_tcf > 0);
        
        if (!$status['present'] && !$has_reservation) {
            continue;
        }
        
        $doctor_data = ['name' => $doctor_name, 'slots' => []];
        $has_any_slot = false;
        
        if ($filter_exam === 'all' || $filter_exam === 'GIF') {
            $max = (int)($status['slots']['GIF'] ?? 0);
            if ($max > 0 || $used_gif > 0) {
                $doctor_data['slots']['GIF'] = ['used' => $used_gif, 'max' => $max];
                $total_used += $used_gif;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($filter_exam === 'all' || $filter_exam === 'ECHO') {
            $max = (int)($status['slots']['ECHO'] ?? 0);
            if ($max > 0 || $used_echo > 0) {
                $doctor_data['slots']['ECHO'] = ['used' => $used_echo, 'max' => $max];
                $total_used += $used_echo;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($filter_exam === 'all' || $filter_exam === 'TCF') {
            $max = (int)($status['slots']['TCF'] ?? 0);
            if ($max > 0 || $used_tcf > 0) {
                $doctor_data['slots']['TCF'] = ['used' => $used_tcf, 'max' => $max];
                $total_used += $used_tcf;
                $total_max += $max;
                $has_any_slot = true;
            }
        }
        
        if ($has_any_slot) {
            $result[] = $doctor_data;
        }
    }
    
    return ['doctors' => $result, 'total_used' => $total_used, 'total_max' => $total_max];
}

/**
 * MALL3 RTF構文変換
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

function renderRemarksRtfHtml(?string $raw): string {
    if ($raw === null || $raw === '') return '';
    $trimmed = trim($raw);
    
    // 枠なし専用デザイン（厳格な完全一致判定）
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

function getDailyReservationsWithMismatch(PDO $pdo, string $dept, string $date_str, string $filter_doctor = 'all', string $filter_exam = 'all', bool $show_empty = true): array {
    if ($show_empty) {
        $sql = "
        WITH 
        all_slots AS (
            SELECT 
                waku_name,
                display_order,
                group_code
            FROM m2_waku
            WHERE department_code = :dept 
              AND is_active = TRUE
        ),
        reserved_data AS (
            SELECT 
                s.schedule_id,
                s.exam_date,
                s.scheduled_time,
                s.waku_no,
                s.patient_id,
                s.patient_name,
                s.patient_kana,
                s.birthday,
                s.doctor_name,
                s.exam_type,
                s.arrival_time,
                s.exam_location,
                s.remarks,
                s.room_number,
                s.bowel_prep,
                s.is_canceled,
                s.booking_status,
                s.department_code,
                s.created_at,
                s.updated_at,
                w.display_order AS waku_display_order,
                w.group_code,
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

$dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';

if ($sheet_single > 0) {
    $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id AND department_code = :dept AND is_canceled = FALSE");
    $stmt->execute([':id' => $sheet_single, ':dept' => $dept]);
    $p = $stmt->fetch();
    
    if (!$p) { die("指定された予約が見つかりません。"); }
    
    $exam = (string)($p['exam_type'] ?? '');
    if ($exam === 'エコー' || $exam === 'ECHO') {
        die("エコーはシート印刷の対象外です。");
    }
    
    $exam_date = (string)($p['exam_date'] ?? '');
    $b_day = (string)($p['birthday'] ?? '');
    $age_str = calculateAge($b_day, $exam_date);
    $is_proct = ($p['department_code'] === 'PROCT');
    $wareki = convertToWareki($b_day);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>内視鏡検査シート</title>
        <style>
            @page { size: A4 portrait; margin: 10mm 12mm; }
            body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 10pt; color: #000; background: #fff; }
            .sheet-page { padding-bottom: 10mm; }
            .sheet-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 8px; }
            .clinic-title { font-size: 14pt; font-weight: bold; }
            .dept-checkboxes { font-size: 10pt; font-weight: bold; }
            .sheet-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 9pt; }
            .sheet-table th, .sheet-table td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
            .sheet-table th { background: #f2f2f2; text-align: center; font-weight: bold; }
            .highlight-yellow { background: #ffff00 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .section-title { background: #e6e6e6; font-weight: bold; padding: 3px 8px; border: 1px solid #000; border-bottom: none; font-size: 9pt; margin-top: 6px; }
            .footer-line { display: flex; justify-content: space-between; font-size: 7pt; color: #555; margin-top: 5px; }
        </style>
    </head>
    <body>
        <div class="sheet-page">
            <div class="sheet-header">
                <div class="clinic-title">おの肛門科胃腸科内視鏡クリニック</div>
                <div class="dept-checkboxes">[ <?= $is_proct ? '☑' : '□' ?> ] 肛門科 &nbsp;&nbsp;&nbsp; [ <?= !$is_proct ? '☑' : '□' ?> ] 胃腸科</div>
            </div>
            <table class="sheet-table">
                <tr>
                    <th style="width:12%;">患者No</th>
                    <td style="width:23%; font-weight:bold; font-size:12pt; text-align:center;" class="highlight-yellow"><?= htmlspecialchars((string)($p['patient_id'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td>
                    <th style="width:10%;">氏名</th>
                    <td style="width:35%; font-weight:bold; font-size:13pt;" class="highlight-yellow"><?= htmlspecialchars((string)($p['patient_name'] ?? '（氏名未登録）'), ENT_QUOTES, 'UTF-8') ?></td>
                    <th style="width:8%;">性別</th>
                    <td style="width:12%; text-align:center;"><?= htmlspecialchars((string)($p['gender'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>生年月日</th>
                    <td style="text-align:center;"><?= htmlspecialchars($wareki, ENT_QUOTES, 'UTF-8') ?></td>
                    <th>年齢</th>
                    <td style="text-align:center; font-weight:bold;"><?= $age_str ?></td>
                    <th>検査日</th>
                    <td style="text-align:center; font-weight:bold;"><?= htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            </table>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><td style="width:50%;"><strong>付添：</strong>無 ・ 有（     ）<br><strong>迎え・タクシー・車：</strong>（      ）</td>
                    <td style="width:50%;"><strong>荷物：</strong>無 ・ 有（     ）<br><strong>内服：</strong>無 ・ 有（     ）<br><strong>TVセット・入院セット：</strong> 特( ) 個( ) 大( )</td></tr>
            </table>
            <div class="section-title">■ 前処置・下剤チェック欄</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><th style="width:35%;">薬剤名 / 処置</th><th style="width:15%;">数量・規格</th><th>実施状況・特記事項</th></tr>
                <tr><td>グリセリン浣腸液 50%</td><td style="text-align:center;">60ml / 120ml</td><td><span class="highlight-yellow" style="padding:2px 10px;">[ ] 実施 &nbsp;&nbsp;&nbsp; 時間：(  :  )</span></td></tr>
                <tr><td>高圧浣腸 グリセリン</td><td style="text-align:center;">100ml</td><td>[ ] 実施</td></tr>
                <tr><td>マグコロール散 68%</td><td style="text-align:center;">50g / 100g / 150g</td><td>服薬状況：(            )</td></tr>
                <tr><td>ニフレック / モビプレップ</td><td style="text-align:center;">1袋 / 2L</td><td>排便状況：(            )</td></tr>
            </table>
            <div class="section-title">■ 問診・安全確認事項</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><td style="width:50%;">・排便回数：昨日（ ）・今日（ ） 最終（   ）<br>・便秘： 無 ・ 有 / 難聴： 無 ・ 有<br>・感染症： HB（ ）・HC（ ）・RPR（ ）□未</td>
                    <td style="width:50%;">・抗凝固薬： □無 □有 （休薬： / ～ ）<br>・糖尿病薬： □無 □有 （休薬： / ～ ）<br>・インスリン：□無 □有 （休薬： / ～ ）<br>・シャント・乳腺手術：□無 □有（右・左）</td></tr>
                <tr><td colspan="2"><strong>当日内服・血圧：</strong> 血圧： 済 ・ 未 &nbsp; BP（   /   ） &nbsp;&nbsp; <strong>その他特記事項：</strong> <?= htmlspecialchars((string)($p['remarks'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            </table>
            <div class="section-title">■ 検査・薬剤投与記録（当日）</div>
            <table class="sheet-table" style="font-size:8pt;">
                <tr><th style="width:35%;">項目</th><th style="width:20%; text-align:center;">予定 / 数量</th><th>実施記録・サイン</th></tr>
                <tr><td>検査種別</td><td style="text-align:center; font-weight:bold;" class="highlight-yellow"><?= htmlspecialchars((string)($p['exam_type'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></td><td>場所: <strong><?= htmlspecialchars((string)($p['exam_location'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></strong> / 担当医: <strong><?= htmlspecialchars((string)($p['doctor_name'] ?? '---'), ENT_QUOTES, 'UTF-8') ?></strong></td></tr>
                <tr><td>プロフォール 1％</td><td style="text-align:center;"> mg</td><td></td></tr>
                <tr><td>ジアゼパム 10mg</td><td style="text-align:center;"> ml</td><td></td></tr>
                <tr><td>ブスコパン 20mg / グルカゴン</td><td style="text-align:center;"> 1ml / 1V</td><td>採血：あり ・ なし / アル綿禁：[ ]</td></tr>
            </table>
            <div class="section-title">■ 所見・メモ・特記事項</div>
            <div style="border:1px solid #000; height:30mm; padding:4px; font-size:8pt;">
                <?php if (!empty($p['remarks'])): ?>予約時備考: <?= renderRemarksRtfHtml((string)$p['remarks']) ?><?php endif; ?>
            </div>
            <div class="footer-line">
                <div>スケジュールID: #<?= (int)$p['schedule_id'] ?> (枠: <?= htmlspecialchars((string)($p['scheduled_time'] ?? '---'), ENT_QUOTES, 'UTF-8') ?>)</div>
                <div>おの肛門科胃腸科内視鏡クリニック 診療記録シート</div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$sel_ts = strtotime($selected_date);
$sel_w = (int)date('w', $sel_ts);

// 下ペイン用曜日文字列生成（要件4）
$w_map = ['日', '月', '火', '水', '木', '金', '土'];
$day_of_week_str = '(' . $w_map[$sel_w] . ')';

$sel_year = (int)date('Y', $sel_ts);
$sel_month = (int)date('n', $sel_ts);
$is_same_month = ($sel_year === $year && $sel_month === $month);

if ($is_same_month) {
    $day_data = getDayDataFast($dept, $selected_date, $sel_w, $doctors_list, $month_reserved_cache, $slot_master_cache, $filter_doctor, $filter_exam);
    $doctor_counts = getDoctorCountsFast($doctors_list, $selected_date, $month_reserved_cache);
    $list_data = getDailyReservationsWithMismatch($pdo, $dept, $selected_date, 'all', 'all', $show_empty);
} else {
    // 月跨ぎやカレンダー外日付の場合でも、選択日の予約一覧と医師別件数を確実に取得
    $day_data = getDayDataFast($dept, $selected_date, $sel_w, $doctors_list, $month_reserved_cache, $slot_master_cache, $filter_doctor, $filter_exam);
    $list_data = getDailyReservationsWithMismatch($pdo, $dept, $selected_date, 'all', 'all', $show_empty);
    
    // 選択日の医師別件数を集計（キャッシュまたは直接集計）
    $doctor_counts = [];
    if (isset($month_reserved_cache[$selected_date])) {
        $doctor_counts = getDoctorCountsFast($doctors_list, $selected_date, $month_reserved_cache);
    } else {
        foreach ($list_data as $row_item) {
            if (empty($row_item['is_empty'])) {
                $dname = trim((string)($row_item['doctor_name'] ?? ''));
                if ($dname !== '') {
                    $doctor_counts[$dname] = ($doctor_counts[$dname] ?? 0) + 1;
                }
            }
        }
    }
}

// 選択日の大腸CF件数（胃腸科・肛門科・TCF/W/ポリペク内訳）を取得（部屋・下剤一括ホバーサマリ用）
$cf_counts = getDailyCfCounts($pdo, $selected_date);
$cf_tooltip_plain = "【" . date('n/j', $sel_ts) . $day_of_week_str . " 大腸CF件数】全体: {$cf_counts['total_cf']}件\n"
    . "・🔵 胃腸科: {$cf_counts['gastro']['total']}件 (TCF: {$cf_counts['gastro']['tcf']}, W: {$cf_counts['gastro']['w']}, ポリペク: {$cf_counts['gastro']['polypep']})\n"
    . "・🟢 肛門科: {$cf_counts['proct']['total']}件 (TCF: {$cf_counts['proct']['tcf']}, W: {$cf_counts['proct']['w']}, ポリペク: {$cf_counts['proct']['polypep']})\n"
    . "※クリックで部屋割り・下剤の一括設定画面を開きます";

$room_counts = [];
foreach ($list_data as $r_item) {
    if (empty($r_item['is_empty'])) {
        $rm = trim((string)($r_item['room_number'] ?? ''));
        if ($rm !== '' && $rm !== '未指定' && $rm !== '-') {
            $room_counts[$rm] = ($room_counts[$rm] ?? 0) + 1;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📅 月間カレンダービュー | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="assets/css/month_schedule.css?v=<?= time() ?>">
</head>
<body>

<div class="container" id="mainContainer">

    <div class="top-toolbar">
        <div class="top-left-group">
            <div class="top-title-area">
                <h2>📅 内視鏡予約 ダッシュボード</h2>
                <span class="header-badge" style="background:#eff6ff; color:#1d4ed8; border-color:#93c5fd; font-weight:700;">🚀 HTML5最適化版</span>
            </div>
            <div class="dept-tabs-inline">
                <a href="?dept=PROCT&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab-inline <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛門科</a>
                <a href="?dept=GASTRO&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="dept-tab-inline <?=$dept==='GASTRO'?'active-GASTRO':''?>">🔵 胃腸科</a>
            </div>
        </div>
        <div class="header-nav">
            <a href="index.php" class="nav-btn nav-btn-home">
                <span>🏠</span> 
            </a>
            <a href="progress_monitor.php?dept=<?=$dept?>" class="nav-btn nav-btn-whiteboard">
                <span>📊</span> 当日ホワイトボード
            </a>
            <a href="schedule_note.php?dept=<?=$dept?>&date=<?=$selected_date?>" class="nav-btn" style="background: linear-gradient(135deg, #0d9488, #0f766e); color: #ffffff; font-weight: 700;" title="カレンダー非表示・紙のノート感覚で全幅一覧できる超高速モード">
                <span>📖</span> ノート表示
            </a>

            <a href="month_matrix_v2.php?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>" class="nav-btn nav-btn-matrix">
                <span>📊</span> 予約マトリクス
            </a>
            <a href="slot_search.php?dept=<?=$dept?>" class="nav-btn nav-btn-search">
                <span>🔍</span> 空き枠検索
            </a>
            <button type="button" onclick="openDuplicateCheckModal()" class="nav-btn nav-btn-duplicate" title="同一患者の未来複数予約・重複チェック">
                <span>⚠️</span> 重複チェック
            </button>
            <button type="button" onclick="openAuditLogModal()" class="nav-btn nav-btn-audit" title="直近の操作履歴・誤操作ワンクリック復元（Undo）">
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

    <!-- ── 直近空き枠速報 フローティングコンテナ（画面右下・ドラッグ移動可能・24H保持・半透明グラスモフィズム） ── -->
    <div id="urgentSlotFloatingContainer" class="urgent-slot-floating-container" style="display:none;">
        <!-- フローティング速報パネル（展開時） -->
        <div id="urgentSlotQuickBar" class="urgent-slot-quick-bar">
            <div class="urgent-bar-header" id="urgentSlotDragHandle" title="ドラッグして画面内の自由な位置へ移動できます">
                <div class="urgent-bar-header-left">
                    <span class="urgent-bar-badge">📢 直近空き枠速報</span>
                    <span class="urgent-drag-indicator">⠿ 移動</span>
                </div>
                <div class="urgent-bar-header-right">
                    <button type="button" class="urgent-bar-close-btn" onclick="toggleUrgentQuickBar(false)" title="パネルを最小化">✕</button>
                </div>
            </div>
            <div class="urgent-bar-content">
                <div class="urgent-bar-text" id="urgentQuickBarText">大腸(TCF)空き枠を集計中...</div>
                <div class="urgent-bar-actions">
                    <button type="button" class="urgent-bar-btn" onclick="openUrgentSlotModal()">
                        ⚡ 空き枠一覧 ＆ スマート予約アシストを開く
                    </button>
                </div>
            </div>
        </div>

        <!-- 最小化時トリガーボタン（コンパクトピル） -->
        <button type="button" id="urgentSlotTriggerBtn" class="urgent-slot-trigger-btn" onclick="toggleUrgentQuickBar(true)" style="display:none;" title="クリックして空き枠速報を展開">
            <span>📢 空き枠速報</span>
            <span id="urgentSlotTriggerBadge" class="urgent-trigger-badge">0枠</span>
        </button>
    </div>

    <!-- カレンダーセクション（操作バー、カレンダー本体、カレンダー操作コマンドパレット） -->
    <div class="calendar-section" id="calendarSection">
        <!-- カレンダー折りたたみ用コントロールバー（上下入れ替えボタンを追加） -->
        <div class="cal-toggle-bar">
            <button type="button" id="btnToggleCalendar" class="cal-toggle-btn" onclick="toggleCalendar()">🔼 カレンダーを折りたたむ</button>
            <button type="button" id="btnToggleLayout" class="cal-toggle-btn" onclick="toggleLayoutOrder()" title="カレンダーと一覧の上下を入れ替え">↕️ 上下入替</button>
        </div>

        <!-- カレンダー部分 -->
        <div class="calendar-wrapper" id="calendarWrapper">
            <!-- カレンダー用コマンドパレット（カレンダーの表示制御・医師/種別フィルター） -->
            <div class="filters-compact">
                <div class="filter-row-inline">
                    <div class="filter-group-inline">
                        <span class="filter-label">👨‍⚕️ 医師:</span>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=all&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor==='all'?'active-all':''?>">全医師</a>
                        <?php foreach ($doctors_list as $doc): 
                            $doc_str = (string)$doc;
                        ?>
                            <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($doc_str)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_doctor===$doc_str?'active':''?>">
                                <?=htmlspecialchars($doc_str, ENT_QUOTES, 'UTF-8')?> <span class="badge"><?=(int)($doctor_counts[$doc_str] ?? 0)?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="filter-group-inline" style="margin-left: 8px;">
                        <span class="filter-label">🔬 種別:</span>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=all&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='all'?'active-all':''?>">全種別</a>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=GIF&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='GIF'?'active':''?>">🔵 GIF</a>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=ECHO&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='ECHO'?'active':''?>">🟠 エコー</a>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=TCF&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="filter-toggle <?=$filter_exam==='TCF'?'active':''?>">🟢 TCF</a>
                    </div>
                </div>

                <div class="filter-group-inline">
                    <span class="filter-label">📊 表示:</span>
                    <div class="display-mode-toggle">
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=use_max&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='use_max'?'active':''?>">📊 使用/最大</a>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=remain&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='remain'?'active':''?>">📉 残数</a>
                        <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=count&show_empty=<?=$show_empty?'1':'0'?>" class="mode-btn <?=$display_mode==='count'?'active':''?>">🔢 検査数</a>
                    </div>
                </div>
            </div>
        <div class="cal-nav-bar">
            <div class="cal-primary-controls">
                <span class="cal-title"><?=$year?>年 <?=$month?>月</span>
                <div class="cal-btn-group">
                    <a href="?dept=<?=$dept?>&year=<?=$prev_year?>&month=<?=$prev_month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-arrow" title="前月">◀</a>
                    <a href="?dept=<?=$dept?>&year=<?=date('Y')?>&month=<?=date('n')?>&date=<?=date('Y-m-d')?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-today">📅 今日</a>
                    <a href="?dept=<?=$dept?>&year=<?=$next_year?>&month=<?=$next_month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" class="cal-nav-arrow" title="次月">▶</a>
                </div>
            </div>

            <div class="cal-quick-months">
                <span class="quick-month-label">月ジャンプ:</span>
                <?php foreach ($quick_months as $qm): ?>
                    <?php 
                        $pill_class = 'month-pill' . ($qm['is_active'] ? ' active' : '') . ($qm['is_new_year'] ? ' year-edge' : '');
                        $pill_label = $qm['is_new_year'] ? ("'" . substr((string)$qm['year'], 2) . '/' . $qm['month']) : $qm['month'];
                    ?>
                    <a href="?dept=<?=$dept?>&year=<?=$qm['year']?>&month=<?=$qm['month']?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>" 
                       class="<?=$pill_class?>" 
                       title="<?=$qm['year']?>年<?=$qm['month']?>月">
                        <?=$pill_label?><span class="month-pill-sub"><?=$qm['is_new_year'] ? '' : '月'?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <table class="cal-table">
            <thead>
                <tr>
                    <th style="color:#ef4444;">日</th>
                    <th>月</th>
                    <th>火</th>
                    <th>水</th>
                    <th style="color:#1e40af;">木</th>
                    <th>金</th>
                    <th>土</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                <?php
                for ($i = 0; $i < $start_weekday; $i++) {
                    echo "<td style='background:#fafafa;'></td>";
                }

                $today_str = date('Y-m-d');
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $ts = strtotime($date_str);
                    $w = (int)date('w', $ts);
                    $day_check = check_onokai_calendar($date_str, null);
                    $is_closed = $day_check['is_closed'];
                    $is_today = ($date_str === $today_str);
                    $is_selected = ($date_str === $selected_date);
                    $is_past = ($date_str < $today_str);

                    $has_exception = !empty($slot_master_cache['attendance'][$date_str]) || !empty($slot_master_cache['daily_capacity'][$date_str]);

                    $has_reservation_on_closed = false;
                    if ($is_closed && isset($month_reserved_cache[$date_str]) && is_array($month_reserved_cache[$date_str])) {
                        $count = 0;
                        foreach ($month_reserved_cache[$date_str] as $doc_counts) {
                            $count += is_array($doc_counts) ? array_sum($doc_counts) : 0;
                        }
                        $has_reservation_on_closed = ($count > 0);
                    }

                    $w_count_total = 0;
                    if ($dept === 'PROCT' && isset($month_reserved_cache[$date_str]) && is_array($month_reserved_cache[$date_str])) {
                        foreach ($month_reserved_cache[$date_str] as $doc_exams) {
                            if (is_array($doc_exams)) {
                                $w_count_total += (int)($doc_exams['W'] ?? 0);
                            }
                        }
                    }
                    $is_w_over = ($dept === 'PROCT' && !$is_closed && $w_count_total >= 3);

                    $day_data_cell = getDayDataFast($dept, $date_str, $w, $doctors_list, $month_reserved_cache, $slot_master_cache, $filter_doctor, $filter_exam);
                    
                    if (!$is_closed) {
                        if ($day_data_cell['total_max'] > 0) {
                            $has_available = ($day_data_cell['total_used'] < $day_data_cell['total_max']);
                        }
                    }

                    $tot_used_check = 0;
                    $tot_max_check = 0;
                    foreach ($day_data_cell['doctors'] as $d) {
                        foreach (['GIF', 'ECHO', 'TCF'] as $ex) {
                            if (isset($d['slots'][$ex])) {
                                $tot_used_check += (int)$d['slots'][$ex]['used'];
                                $tot_max_check += (int)$d['slots'][$ex]['max'];
                            }
                        }
                    }
                    $has_total_negative = (!$is_closed && $tot_max_check > 0 && $tot_used_check > $tot_max_check);
                    $has_total_exact = (!$is_closed && $tot_max_check > 0 && $tot_used_check === $tot_max_check && !$has_total_negative);

                    $cell_class = 'day-cell';
                    if ($is_selected) $cell_class .= ' selected';
                    if ($is_today) $cell_class .= ' today';
                    if ($is_closed) $cell_class .= ' closed';
                    if ($is_past) $cell_class .= ' past';
                    if ($is_w_over) $cell_class .= ' has-w-over';
                    
                    if ($has_total_negative) {
                        $cell_class .= ' has-total-negative';
                    } elseif ($has_total_exact) {
                        $cell_class .= ' has-total-exact';
                    }

                    if (isset($has_available) && $has_available && !$is_past && !$is_closed) $cell_class .= ' has-available';
                    if ($has_reservation_on_closed) {
                        $cell_class .= ' has-reservation-on-closed';
                    }

                    $unassigned_html = isset($month_unassigned_cache[$date_str]) ? renderUnassignedBadgeHTML($month_unassigned_cache[$date_str]) : '';

                    $has_data = $day_data_cell['total_max'] > 0 || !empty($day_data_cell['doctors']);
                    $avail_class = 'avail-closed';
                    $avail_text = '';
                    if (!$is_closed && $has_data && $display_mode !== 'count') {
                        $total_gif_used = 0; $total_gif_max = 0;
                        $total_echo_used = 0; $total_echo_max = 0;
                        $total_tcf_used = 0; $total_tcf_max = 0;
                        foreach ($day_data_cell['doctors'] as $d) {
                            if (isset($d['slots']['GIF'])) {
                                $total_gif_used += (int)$d['slots']['GIF']['used'];
                                $total_gif_max += (int)$d['slots']['GIF']['max'];
                            }
                            if (isset($d['slots']['ECHO'])) {
                                $total_echo_used += (int)$d['slots']['ECHO']['used'];
                                $total_echo_max += (int)$d['slots']['ECHO']['max'];
                            }
                            if (isset($d['slots']['TCF'])) {
                                $total_tcf_used += (int)$d['slots']['TCF']['used'];
                                $total_tcf_max += (int)$d['slots']['TCF']['max'];
                            }
                        }
                        
                        $doctor_count = count($day_data_cell['doctors']);
                        if ($doctor_count >= 2) {
                            $overall_parts = [];
                            if ($display_mode === 'use_max') {
                                if ($total_gif_max > 0 || $total_gif_used > 0) {
                                    $overall_parts[] = '🔵' . $total_gif_used . '/' . $total_gif_max;
                                }
                                if ($total_echo_max > 0 || $total_echo_used > 0) {
                                    $overall_parts[] = '🟠' . $total_echo_used . '/' . $total_echo_max;
                                }
                                if ($total_tcf_max > 0 || $total_tcf_used > 0) {
                                    $overall_parts[] = '🟢' . $total_tcf_used . '/' . $total_tcf_max;
                                }
                            } else {
                                if ($total_gif_max > 0 || $total_gif_used > 0) {
                                    $remain = $total_gif_max - $total_gif_used;
                                    $overall_parts[] = '🔵' . $remain;
                                }
                                if ($total_echo_max > 0 || $total_echo_used > 0) {
                                    $remain = $total_echo_max - $total_echo_used;
                                    $overall_parts[] = '🟠' . $remain;
                                }
                                if ($total_tcf_max > 0 || $total_tcf_used > 0) {
                                    $remain = $total_tcf_max - $total_tcf_used;
                                    $overall_parts[] = '🟢' . $remain;
                                }
                            }
                            $avail_text = '全体 ' . implode(' ', $overall_parts);
                            $avail_class = 'avail-green';
                        }
                    } elseif (!$is_closed && $has_data && $display_mode === 'count') {
                        $day_exams_total = [];
                        if (isset($month_reserved_cache[$date_str]) && is_array($month_reserved_cache[$date_str])) {
                            foreach ($month_reserved_cache[$date_str] as $doc_name => $doc_exams) {
                                if ($filter_doctor !== 'all' && $filter_doctor !== $doc_name) continue;
                                if (is_array($doc_exams)) {
                                    foreach ($exam_types_master as $etype) {
                                        $c = (int)($doc_exams[$etype] ?? 0);
                                        if ($c > 0) {
                                            $day_exams_total[$etype] = ($day_exams_total[$etype] ?? 0) + $c;
                                        }
                                    }
                                }
                            }
                        }
                        if (!empty($day_exams_total)) {
                            $parts = [];
                            foreach ($day_exams_total as $et => $c) {
                                $parts[] = "{$et}:{$c}";
                            }
                            $avail_text = '合計 ' . implode(' ', $parts);
                            $avail_class = 'avail-green';
                        }
                    }

                    $show_empty_val = $show_empty ? '1' : '0';
                    $filter_doc_enc = urlencode($filter_doctor);
                    $filter_exam_enc = urlencode($filter_exam);
                    $cell_y = (int)substr($date_str, 0, 4);
                    $cell_m = (int)substr($date_str, 5, 2);
                    echo "<td class='{$cell_class}' onclick=\"location.href='?dept={$dept}&year={$cell_y}&month={$cell_m}&date={$date_str}&doctor={$filter_doc_enc}&exam={$filter_exam_enc}&display_mode={$display_mode}&show_empty={$show_empty_val}'\">";
                    
                    $day_style = '';
                    if (!$is_closed) {
                        if ($w === 4) {
                            $day_style = 'color: #1e40af;';
                        }
                    }

                    $day_memo = trim((string)($month_daily_memo_cache[$date_str] ?? ''));
                    $memo_badge_html = '';
                    if ($day_memo !== '') {
                        $memo_title = htmlspecialchars($day_memo, ENT_QUOTES, 'UTF-8');
                        $memo_js_arg = htmlspecialchars(json_encode($day_memo, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $memo_badge_html = "<span class='day-memo-badge' title='【日別カレンダーメモ】{$memo_title}（クリックで編集）' onclick=\"event.stopPropagation(); openDailyMemoModal('{$date_str}', {$memo_js_arg});\">📝 {$memo_title}</span>";
                    }

                    $exception_badge_class = $has_exception ? 'date-num-badge has-exception' : 'date-num-badge';
                    $exception_title = $has_exception ? ' title="出勤例外設定あり"' : '';
                    echo "<div class='day-number' style='{$day_style}'><span class='{$exception_badge_class}'{$exception_title}>{$day}</span>{$memo_badge_html}</div>";

                    // 医師予定バッジ（yotei連携: 医院休診日は非表示、セル崩れを防ぐため最大2件＋超過時は件数バッジ）
                    if (!$is_closed && !empty($month_doctor_events_cache[$date_str])) {
                        $day_events = $month_doctor_events_cache[$date_str];
                        echo "<div class='cal-doctor-events'>";
                        $max_display = 2;
                        $displayed_count = 0;
                        foreach ($day_events as $ev_item) {
                            if ($displayed_count < $max_display) {
                                $label = htmlspecialchars($ev_item['label'], ENT_QUOTES, 'UTF-8');
                                $tooltip = htmlspecialchars($ev_item['tooltip'], ENT_QUOTES, 'UTF-8');
                                echo "<span class='cal-doctor-event-badge' title='{$tooltip}'>{$label}</span>";
                                $displayed_count++;
                            }
                        }
                        if (count($day_events) > $max_display) {
                            $rest = count($day_events) - $max_display;
                            $all_tooltips = [];
                            foreach ($day_events as $ev_item) {
                                $all_tooltips[] = $ev_item['tooltip'];
                            }
                            $more_tooltip = htmlspecialchars(implode("\n---\n", $all_tooltips), ENT_QUOTES, 'UTF-8');
                            echo "<span class='cal-doctor-event-badge more' title='{$more_tooltip}'>他{$rest}件</span>";
                        }
                        echo "</div>";
                    }

                    if ($is_closed) {
                        echo "<div class='day-available avail-closed'>⛔休</div>";
                    } elseif ($has_data) {
                        echo "<div class='day-slots'>";
                        foreach ($day_data_cell['doctors'] as $d) {
                            $doc_name = (string)$d['name'];
                            
                            if ($display_mode === 'count') {
                                $doc_exams = $month_reserved_cache[$date_str][$doc_name] ?? [];
                                $has_any_cnt = false;
                                foreach ($exam_types_master as $etype) {
                                    if ((int)($doc_exams[$etype] ?? 0) > 0) {
                                        $has_any_cnt = true;
                                        break;
                                    }
                                }
                                if (!$has_any_cnt) continue;
                            }

                            echo "<div class='doctor-slot-row'>";
                            echo getDoctorBadgeHTML($doc_name);

                            if ($display_mode === 'count') {
                                $doc_exams = $month_reserved_cache[$date_str][$doc_name] ?? [];
                                foreach ($exam_types_master as $etype) {
                                    if ($filter_exam !== 'all' && $filter_exam !== $etype) continue;
                                    $cnt = (int)($doc_exams[$etype] ?? 0);
                                    if ($cnt > 0) {
                                        echo "<span class='slot-item' style='background:#f1f5f9; padding:0 3px; border-radius:3px; margin-right:2px;'>{$etype}:{$cnt}</span>";
                                    }
                                }
                            } else {
                                foreach (['GIF', 'ECHO', 'TCF'] as $exam) {
                                    if ($filter_exam !== 'all' && $filter_exam !== $exam) continue;
                                    if (isset($d['slots'][$exam])) {
                                        $used = (int)$d['slots'][$exam]['used'];
                                        $max = (int)$d['slots'][$exam]['max'];
                                        
                                        if ($max > 0 || $used > 0) {
                                            $is_negative = ($used > $max);
                                            $is_exact = ($used === $max && $max > 0);
                                            $exam_icon = getExamIcon($exam);

                                            if ($display_mode === 'use_max') {
                                                $val_text = "{$used}/{$max}";
                                                if ($is_negative) {
                                                    echo "<span class='slot-item negative'>{$exam_icon}{$val_text}</span>";
                                                } elseif ($is_exact) {
                                                    echo "<span class='slot-item exact'>{$exam_icon}{$val_text}</span>";
                                                } else {
                                                    echo "<span class='slot-item'>{$exam_icon}{$val_text}</span>";
                                                }
                                            } else {
                                                $remain = $max - $used;
                                                if ($is_negative) {
                                                    echo "<span class='slot-item negative'>{$exam_icon}{$remain}</span>";
                                                } else {
                                                    $cls = ($remain === 0) ? 'full' : '';
                                                    echo "<span class='slot-item {$cls}'>{$exam_icon}{$remain}</span>";
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            echo "</div>";
                        }
                        echo "</div>";
                        
                        if ($is_w_over) {
                            echo "<div style='font-size:9px; font-weight:bold; color:#fff; background:#ea580c; border-radius:2px; padding:1px 2px; margin-top:2px; text-align:center;' title='肛門科W制限超過 (制限2件に対して現在 {$w_count_total}件)'>⚠️ W超過:{$w_count_total}件</div>";
                        } elseif (!empty($avail_text)) {
                            echo "<div class='day-available {$avail_class}'>{$avail_text}</div>";
                        }

                        if (!empty($unassigned_html)) {
                            echo $unassigned_html;
                        }
                    } else {
                        echo "<div class='empty-cell-text'>-</div>";
                    }

                    echo "</td>";

                    if ($w == 6) {
                        echo "</tr><tr>";
                    }
                }

                while ($start_weekday + $days_in_month <= 35 || ($start_weekday + $days_in_month) % 7 != 0) {
                    echo "<td style='background:#fafafa;'></td>";
                    $start_weekday++;
                }
                ?>
                </tr>
            </tbody>
        </table>
    </div>
    </div><!-- /.calendar-section -->

    <!-- 予約一覧部分 -->
    <div class="list-wrapper" id="listWrapper">
        <div class="list-header">
            <div class="list-title">
                <?php if ($is_same_month): ?>
                    📋 <?=date('Y.m.d', strtotime($selected_date))?><?=$day_of_week_str?> の予約一覧
                    <span style="font-size:11px; color:#64748b; font-weight:normal; margin-left:6px;">
                        (<?=$day_data['total_used']?>/<?=$day_data['total_max']?>枠 使用中)
                    </span>
                    <?php if (!empty($selected_day_memo)): ?>
                        <span class="list-title-memo" onclick="openDailyMemoModal('<?= $selected_date ?>', <?= htmlspecialchars(json_encode($selected_day_memo, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)" title="クリックして日別カレンダーメモを編集">
                            📢 <?= htmlspecialchars($selected_day_memo, ENT_QUOTES, 'UTF-8') ?> <span style="font-size:10px; opacity:0.75;">✏️</span>
                        </span>
                    <?php else: ?>
                        <button type="button" class="btn-memo-add" onclick="openDailyMemoModal('<?= $selected_date ?>', '')" title="この日に日別カレンダーメモ・連絡事項を登録">
                            📝 メモ追加
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    📋 予約一覧
                <?php endif; ?>
                <?php if ($display_mode === 'empty'): ?>
                    <span style="font-size:11px; color:#8b5cf6; font-weight:bold; margin-left:8px;">［空き枠表示モード］</span>
                <?php endif; ?>
            </div>
            <div class="list-actions">
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
                                <span class="cf-hover-date"><?= date('n/j', $sel_ts) ?><?= $day_of_week_str ?></span>
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
                
                <button type="button" onclick="openDuplicateCheckModal()" class="duplicate-btn-modern" title="未来の重複予約（同一患者の複数予約）を検出・確認">
                    ⚠️ 重複チェック
                </button>
                
                <button type="button" onclick="openMedicalAlertScanModal({date: '<?= $selected_date ?>', dept: '<?= $dept ?>'})" class="summary-btn-modern" style="background:#e11d48;" title="当日の抗血栓薬・アレルギー・感染症・風邪アラートスキャン">
                    🛡️ アラートスキャン
                </button>

                <button type="button" onclick="openPrintSchedule('<?= $dept ?>', '<?= $selected_date ?>')" class="print-btn-modern" title="当日の内視鏡予約一覧を別タブで印刷">
                    🖨️ 一覧印刷
                </button>
            </div>
        </div>

        <?php 
        $all_list_sids = [];
        if (!empty($list_data)) {
            foreach ($list_data as $r_item) {
                $sid = (int)($r_item['schedule_id'] ?? 0);
                if ($sid > 0) {
                    $all_list_sids[] = $sid;
                }
            }
        }
        $medical_alerts_map = !empty($all_list_sids) ? getMedicalAlertsMap($pdo, $all_list_sids) : [];
        ?>

        <?php if (!$is_same_month): ?>
            <div class="empty-msg" style="background:#f8fafc; color:#475569; font-weight:bold; padding:35px; font-size:13px; border:1px dashed #cbd5e1; border-radius:6px;">
                📅 カレンダ部で日を選択してください
            </div>
        <?php elseif (empty($list_data)): ?>
            <div class="empty-msg">この日の予約はありません</div>
        <?php else: ?>
            <table class="list-table" id="reservationTable">
                <thead>
                    <tr>
                        <th style="width:28px; text-align:center;" title="新規追加">➕</th>
                        <th style="width:32px; text-align:center;" title="枠なし設定・復帰">🚫</th>
                        <th style="width:28px; text-align:center;" title="編集・削除">✏️</th>
                        <th style="width:110px;">時間枠</th>
                        <th style="width:85px; text-align:center;">患者No</th>
                        <th style="width:190px;">患者名</th>
                        <th style="width:88px; text-align:center;">種別</th>
                        <th style="width:65px; text-align:center;">部屋</th>
                        <th style="width:52px; text-align:center;">下剤</th>
                        <th>備考</th>
                        <th style="width:45px; text-align:center;">シート</th>
                        <th style="width:60px; text-align:center;">F/U</th>
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
                    
                    // ★ 備考欄が「＊＊＊🚫枠なし🚫＊＊＊」と完全一致する場合のみ枠なしとする
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
                            
                            if ($age_int >= 70) {
                                $age_cls = 'age-senior';
                            } else {
                                $age_cls = 'age-under';
                            }
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
                    if ($prep_val === 'モビ') {
                        $prep_cls = 'prep-mobi';
                    } elseif ($prep_val === 'マグP') {
                        $prep_cls = 'prep-magp';
                    } elseif ($prep_val === 'ニフ') {
                        $prep_cls = 'prep-nif';
                    }

                    $created_at_val = $row['created_at'] ?? '';
                    $updated_at_val = $row['updated_at'] ?? '';
                    $created_at_str = '-';
                    if (!empty($created_at_val)) {
                        try {
                            $created_at_str = (new DateTime((string)$created_at_val))->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            $created_at_str = substr(trim((string)$created_at_val), 0, 19);
                        }
                    }
                    $updated_at_str = '-';
                    if (!empty($updated_at_val)) {
                        try {
                            $updated_at_str = (new DateTime((string)$updated_at_val))->format('Y-m-d H:i:s');
                        } catch (Exception $e) {
                            $updated_at_str = substr(trim((string)$updated_at_val), 0, 19);
                        }
                    }
                    $schedule_tooltip = "スケジュールID:{$schedule_id}（システムデータ）\n作成日時: {$created_at_str}\n更新日時: {$updated_at_str}";
                ?>
                    <tr data-group="<?= htmlspecialchars($filter_category, ENT_QUOTES, 'UTF-8') ?>" 
                        data-reserved="<?= ($is_empty || $is_no_waku) ? 'false' : 'true' ?>"
                        class="<?= $row_class ?>"
                        <?= ($is_empty && !$is_mismatch) ? 'style="background-color:#faf5ff;"' : '' ?>>
                        
                        <!-- 1列目: ➕新規追加（枠なし状態以外で常時表示） -->
                        <td style="text-align:center; white-space:nowrap;">
                            <?php if (!$is_no_waku && !$is_mismatch): ?>
                                <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', 0, '<?= $modal_dept ?>')" class="icon-btn add" title="この枠に新規予約を追加">➕</a>
                            <?php else: ?>
                                <span class="icon-btn disabled">-</span>
                            <?php endif; ?>
                        </td>

                        <!-- 2列目: 🚫枠なし設定 ／ ⭕通常枠復帰（独立列） -->
                        <td style="text-align:center; white-space:nowrap;">
                            <?php if ($is_empty): ?>
                                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&action=set_no_waku&waku=<?=urlencode($waku)?>" class="icon-btn" title="この枠を「枠なし」に設定します" style="color:#dc2626; font-size:12px; font-weight:bold;">🚫</a>
                            <?php elseif ($is_no_waku): ?>
                                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&action=revert_waku&sid=<?=$schedule_id?>" class="icon-btn" title="通常枠（空き枠）に戻す" style="color:#2563eb; font-size:13px; font-weight:bold;">⭕</a>
                            <?php else: ?>
                                <span class="icon-btn disabled">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 3列目: ✏️編集・削除（通常予約行のみ） -->
                        <td style="text-align:center;">
                            <?php if (!$is_empty && !$is_no_waku && $schedule_id > 0): ?>
                            <a href="#" onclick="openModal('<?= $modal_date ?>', '<?= htmlspecialchars($modal_waku, ENT_QUOTES, 'UTF-8') ?>', <?= $schedule_id ?>, '<?= $modal_dept ?>')" 
                               class="icon-btn edit" title="この予約を編集・枠再割当・削除">✏️</a>
                            <?php else: ?>
                            <span class="icon-btn disabled">-</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 時間枠名（枠なしの場合は赤字・打消し） -->
                        <td>
                            <?php if ($is_no_waku): ?>
                                <span style="color:#dc2626; text-decoration:line-through; font-weight:bold;"><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <strong><?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php endif; ?>
                        </td>
                        
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
                        
                        <td>
                            <?php if ($is_empty): ?>
                                <span style="color:#8b5cf6; font-size:11px; font-weight:600;">［空き枠］</span>
                            <?php elseif ($is_no_waku): ?>
                                <span style="color:#dc2626; font-size:11px; font-weight:bold;">［枠なし設定済］</span>
                            <?php else: ?>
                                <div style="line-height: 1.25;" title="<?= htmlspecialchars($schedule_tooltip, ENT_QUOTES, 'UTF-8') ?>">
                                    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:3px;">
                                        <strong style="cursor:help;"><?= htmlspecialchars((string)$row['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong>
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
                                        <div style="font-size:9.5px; color:#64748b; margin-top:2px;">
                                            🎂 <?= htmlspecialchars($b_wareki, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <!-- 医師検査種別（左寄せ） --> 
                        <td style="text-align:left;">
                            <?php if (!$is_empty && !$is_no_waku): ?>
                                <div style="display:inline-flex; align-items:left; gap:4px; justify-content:left;">
                                    <?= getDoctorBadgeHTML((string)($row['doctor_name'] ?? '')) ?>
                                    <span class="exam-badge <?=$cls?>"><?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:center;">
                            <?php if (!$is_empty && !$is_no_waku && !empty($room_val) && $room_val !== '未指定'): ?>
                                <span class="room-badge <?= $is_room_duplicate ? 'room-duplicate' : '' ?>" title="<?= $is_room_duplicate ? '⚠️ 同一日で同じ部屋が重複しています！' : '' ?>">
                                    <?= $is_room_duplicate ? '⚠️ ' : '' ?><?= htmlspecialchars($room_val, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">-</span>
                            <?php endif; ?>
                        </td>

                        <td style="text-align:center;">
                            <?php if (!$is_empty && !$is_no_waku && !empty($prep_val) && $prep_val !== '未指定'): ?>
                                <span class="prep-badge <?= $prep_cls ?>"><?= htmlspecialchars($prep_val, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">-</span>
                            <?php endif; ?>
                        </td>

                        <td style="min-width:180px;">
                            <?= renderRemarksRtfHtml($remarks_str) ?>
                        </td>
                        
                        <td style="text-align:center;">
                            <?php if ($can_sheet): ?>
                                <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&date=<?=$selected_date?>&doctor=<?=urlencode($filter_doctor)?>&exam=<?=urlencode($filter_exam)?>&display_mode=<?=$display_mode?>&show_empty=<?=$show_empty?'1':'0'?>&sheet_single=<?=$row['schedule_id']?>" target="_blank" class="sheet-btn-small" title="検査シート印刷">📋</a>
                            <?php else: ?>
                                <span style="font-size:8px; color:#94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if (!$is_empty && !$is_no_waku && !empty($p_id_str)): ?>
                                <a href="<?= $fu_url ?>" target="_blank" class="sheet-btn-small <?= $fu_class ?>" title="<?= $fu_title ?>">
                                    📋 <?= $fu_label ?>
                                </a>
                            <?php else: ?>
                                <span style="font-size:8px; color:#94a3b8;">-</span>
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
            当日の診療体制や医師の予定（例：「〇〇先生午後休診」「院内行事のため午前のみ」など）を登録します。カレンダー部および予約一覧ヘッダーに表示されます。
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
    </div>
</div>

<script>
window.currentScheduleDept = '<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>';
</script>
<script src="assets/js/month_schedule.js?v=<?= time() ?>"></script>

<?php include __DIR__ . '/audit_modal_inc.php'; ?>
<?php include __DIR__ . '/medical_alert_modal_inc.php'; ?>
<?php include __DIR__ . '/urgent_slot_modal_inc.php'; ?>

</body>
</html>