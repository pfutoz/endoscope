<?php
/**
 * 生成日時: 2026-08-28 01:40:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\month_matrix_v2.php
 * ファイル名: month_matrix_v2.php
 * 
 * 内視鏡室 予約管理システム (マトリクス V2) - 統合・一本化版
 
 * 主な改修:
 *   - 予約登録・編集を modal_edit.php に一本化（インラインdialogおよび重複JSを完全撤去）
 *   - 3ヶ月表示時のパフォーマンス大幅改善（N+1問題解消・全期間一括プリフェッチ）
 *   - 日付枠（左端列）の表現を month_schedule.php のカレンダー部と完全統一
 *   - 検査バッジ（エコー）を「US」表記に短縮し、タイル枠崩れを完全解消
 *   - 2026-09-29: 【本物モーダルダイアログ化】modal_edit_runner.js 連携により、別ウィンドウではなく画面内インページモーダル表示に対応
 *   - 2026-09-29: 【アセット統合・ファイル名正規化】modal_edit_runner.js から統合版 assets/js/month_schedule.js へ更新
 *   - 2026-09-29: ツールバー全面刷新（枠別トグルボタン化、タイマー移設＆プログレスバー化、全ボタン高さ24pxツライチ化、削除データ色サーモンコーラル適用）
 *   - modal_edit.php 更新後の親画面自動リフレッシュ（postMessage / focus 監視）
 *   - ドラッグ＆ドロップ移動機能の維持
 *   - PHP 8.5 互換性・型安全対応

 * 修正・追加機能:
 *   - カルテID: onblur + Enter 両方対応
 *   - 空欄・スペースのみの場合はクリア処理
 *   - 日付入力欄に曜日表示（小野会休日ルール連携）
 *   - 基本情報エリア（変更注意）を薄いグレー背景で区別
 *   - 「保存して印刷」ボタン（saveAndPrint関数実装済み）
 *   - サジェスト機能（カルテID/患者名）
 *   - MALL連携（患者情報・メモ自動取得）
 *   - 伏字モード / 起動時移動 / 迷子表示 / 削除表示
 *   - 5分自動更新 / スクロールボタン
 *   - 1ヶ月/3ヶ月表示切替
 *   - 詳細/簡略表示切替
 *   - 枠種別ON/OFF
 *   - ドラッグ＆ドロップ移動
 *   - Enterキー誤動作防止
 *   - PatientBOX表示適正化（2026-08-05）
 *     - ID区切りを全角コロン（：）に変更
 *     - 年齢の「歳」を削除
 *     - 生年月日を短縮表記（S23.5.22形式）
 *     - 医師アイコンを追加（黒バック白文字・インラインスタイル）
 *     - 詳細モード3行表示に凝縮
 *   - モーダル保存後のスクロール位置維持（2026-08-05）
 *   - 存在しない患者ID入力時のワーニングループ防止（2026-08-05）
 *   - 保存後リロードせずセルを更新（2026-08-05）
 *   - 来院時刻 プルダウン＋直接入力 ハイブリッド対応（2026-08-05）
 *   - 保存ボタンもリロードなし対応（2026-08-12）
 *   - ドラッグ＆ドロップ移動後リロードなし対応（2026-08-12）
 *   - buildPatientBox() で背景色強制指定（透過防止）（2026-08-12）
 *   - ヘッダー行の固定（position: sticky）復旧（2026-08-12）
 *   - 表示モード切り替え（使用/最大・残数）対応（2026-08-12）
 *   - 未割当（医師未割当）表示対応（2026-08-12）
 *   - 迷子（枠定義不一致）は専用列にのみ表示（2026-08-12）
 *   - カレンダーセルの医師別枠数表示を month_schedule.php と統一（2026-08-12）
 *   - 未割当表示トグル追加（2026-08-12）
 * 
 * 修正 (2026-08-14):
 *   - セッションで月・診療科を保持（month_schedule.php と共有）
 *   - GETパラメータ > セッション > デフォルト の優先順位
 *   - 相互リンクにGETパラメータ付与
 *   - 「メニューへ」「月間カレンダーへ」リンクを上部に設置
 *   - 患者検索連携（patient_search.php から選択可能）
 *   - 患者不在・連携エラー時に赤文字メッセージ表示
 *   - サジェスト機能を完全削除（カルテID入力欄）
 * 
 * 修正 (2026-08-17):
 *   - モーダル保存時に移動元セルも更新するよう修正（ゴーストタイル解消）
 *   - openEditModal() で変更前の日付/枠を hidden フィールドに保持
 *   - saveAndPrint() で移動元/移動先両方のセルを更新
 * 
 * 修正 (2026-09-29):
 *   - タイル表現カスタマイズ（枠なしタイルの白背景・医師検査非表示化、削除データの警告赤背景・目立つ表示化、検査バッジのスレートグレー統一、パネル部の検査別カラフルパレット適用）
 *   - ツールバーUI全面刷新（枠フィルターのトグルボタン化・枠色連動、伏字〜未割当のトグルボタン化・機能カラー連動、再描画タイマーのホーム左移設・5分/10分/15分セグメント＆進捗バー化、簡略表示ボタン撤去、削除データ色をサーモンコーラルへ最適化）
 * 
 * 修正 (2026-10-09):
 *   - 【医師予定表連携 (yotei API)】一番左の日付列（fixed-date-col）に医師の休診・不在予定（🙅🏼医師名 概要）をコンパクトバッジとして表示（month_schedule.php と統一）
 *   - かわらばん情報や小野会全体会日程は除外し、医師個人の休診・不在予定のみを表示。ホバーで詳細ツールチップ展開、医院休診日は非表示
 */
ini_set('display_errors', "1"); 
error_reporting(E_ALL); 

session_start();

// ============================================================
// 共通ライブラリ読み込み
// ============================================================
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/patient_helper.php';
require_once __DIR__ . '/lib/calendar_helper.php';
require_once __DIR__ . '/lib/matrix_helper.php';
require_once __DIR__ . '/lib/audit_helper.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

// ============================================================
// パラメータ取得（セッション対応版）
// ============================================================
$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 
        (string)($_SESSION['ui_state']['schedule_dept'] ?? 'PROCT');

$year = isset($_GET['year']) ? (int)$_GET['year'] : 
        (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr((string)$_SESSION['ui_state']['schedule_month'], 0, 4) : (int)date('Y'));
$month = isset($_GET['month']) ? (int)$_GET['month'] : 
         (isset($_SESSION['ui_state']['schedule_month']) ? (int)substr((string)$_SESSION['ui_state']['schedule_month'], 5, 2) : (int)date('n'));

if (!in_array($dept, ['PROCT', 'GASTRO'], true)) { $dept = 'PROCT'; }

if (!isset($_SESSION['ui_state']) || !is_array($_SESSION['ui_state'])) {
    $_SESSION['ui_state'] = [];
}
$_SESSION['ui_state']['schedule_month'] = sprintf('%04d-%02d', $year, $month);
$_SESSION['ui_state']['schedule_dept'] = $dept;

$show_canceled = isset($_GET['show_canceled']) && (string)$_GET['show_canceled'] === '1';
$show_invalid = isset($_GET['show_invalid']) && (string)$_GET['show_invalid'] === '1';
$show_unassigned = isset($_GET['show_unassigned']) && (string)$_GET['show_unassigned'] === '1';
$simple_mode = isset($_GET['simple']) && (string)$_GET['simple'] === '1';
$view_mode = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '1month';
$display_mode = isset($_GET['display_mode']) && is_string($_GET['display_mode']) ? $_GET['display_mode'] : 'use_max';

if (!in_array($view_mode, ['1month', '3month'], true)) { $view_mode = '1month'; }
if (!in_array($display_mode, ['use_max', 'remain'], true)) { $display_mode = 'use_max'; }

// ============================================================
// DB接続
// ============================================================
$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ============================================================
// 表示対象月の決定
// ============================================================
function getTargetMonths(int $year, int $month, string $view_mode): array {
    if ($view_mode === '1month') {
        return [['year' => $year, 'month' => $month]];
    }
    $months = [];
    for ($i = 0; $i < 3; $i++) {
        $y = (int)($year + floor(($month + $i - 1) / 12));
        $m = (int)((($month + $i - 1) % 12) + 1);
        $months[] = ['year' => $y, 'month' => $m];
    }
    return $months;
}

$target_months = getTargetMonths($year, $month, $view_mode);
$first_month = $target_months[0];
$last_month = $target_months[count($target_months) - 1];

$prev_year = $year; $prev_month = $month - 1;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_year = $year; $next_month = $month + 1;
if ($next_month > 12) { $next_month = 1; $next_year++; }

$prev3_year = $year; $prev3_month = $month - 3;
while ($prev3_month < 1) { $prev3_month += 12; $prev3_year--; }
$next3_year = $year; $next3_month = $month + 3;
while ($next3_month > 12) { $next3_month -= 12; $next3_year++; }

// ============================================================
// マスタデータ取得
// ============================================================
$waku_list = getWakuList($pdo, $dept);
if (!is_array($waku_list)) { $waku_list = []; }

$waku_group_map = [];
foreach ($waku_list as $w) {
    $waku_group_map[(string)$w['waku_name']] = (string)$w['group_code'];
}

$doctors_list = getDoctorsForDept($pdo, $dept);
if (!is_array($doctors_list)) { $doctors_list = []; }

// ============================================================
// 高速化：全期間データの一括プリフェッチ
// ============================================================
$start_date = sprintf('%04d-%02d-01', $first_month['year'], $first_month['month']);
$end_date = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $last_month['year'], $last_month['month'])));

// 1. 予約詳細データ
$data_sql = "SELECT 
                s.schedule_id, s.exam_date::text AS exam_date, s.scheduled_time, s.department_code, 
                s.patient_id, s.patient_name, s.patient_kana, s.birthday::text AS birthday,
                s.doctor_name, s.exam_type, s.arrival_time, s.exam_location, 
                s.remarks, s.is_canceled, s.booking_status,
                m.memo_today, m.memo_karte, m.memo_patient
             FROM t_endoscopy_schedule s
             LEFT JOIN t_mall_memo m ON s.patient_id = m.patient_id
             WHERE s.department_code = :dept
               AND s.exam_date BETWEEN :start AND :end";

if (!$show_canceled) { $data_sql .= " AND s.is_canceled = FALSE"; }

$data_stmt = $pdo->prepare($data_sql);
$data_stmt->execute([':dept' => $dept, ':start' => $start_date, ':end' => $end_date]);
$raw_schedules = $data_stmt->fetchAll();

$monthly_data = [];
$reserved_counts_cache = [];
foreach ($raw_schedules as $row) {
    $m_key = date('Y-m', strtotime($row['exam_date']));
    if (!isset($monthly_data[$m_key])) { $monthly_data[$m_key] = []; }
    $monthly_data[$m_key][] = $row;

    $is_canceled = isset($row['is_canceled']) && ($row['is_canceled'] === true || $row['is_canceled'] === 1 || $row['is_canceled'] === 't');
    if (!$is_canceled) {
        $d = (string)$row['exam_date'];
        $doc = (string)($row['doctor_name'] ?? '');
        $etype = (string)($row['exam_type'] ?? '');
        if (!isset($reserved_counts_cache[$d])) { $reserved_counts_cache[$d] = []; }
        if (!isset($reserved_counts_cache[$d][$doc])) { $reserved_counts_cache[$d][$doc] = []; }
        if (!isset($reserved_counts_cache[$d][$doc][$etype])) { $reserved_counts_cache[$d][$doc][$etype] = 0; }
        $reserved_counts_cache[$d][$doc][$etype]++;
    }
}

// 医療安全アラート一括取得
$matrix_sids = [];
foreach ($raw_schedules as $r) {
    $sid = (int)($r['schedule_id'] ?? 0);
    if ($sid > 0) $matrix_sids[] = $sid;
}
$GLOBAL_MEDICAL_ALERTS_MAP = !empty($matrix_sids) ? getMedicalAlertsMap($pdo, $matrix_sids) : [];

// 2. 枠マスター群プリフェッチ
$slot_master_cache = [
    'attendance' => [],
    'daily_capacity' => [],
    'patterns' => [],
    'daily_memo' => []
];

// 日別メモプリフェッチ（t_daily_memo）
$stmt_memo = $pdo->prepare("
    SELECT target_date::text AS target_date, memo 
    FROM t_daily_memo 
    WHERE department_code = :dept 
      AND target_date >= :start_date 
      AND target_date <= :end_date
");
$stmt_memo->execute([':dept' => $dept, ':start_date' => $start_date, ':end_date' => $end_date]);
$slot_master_cache['daily_memo'] = $stmt_memo->fetchAll(PDO::FETCH_KEY_PAIR);

// 医師予定プリフェッチ（yotei連携: 全期間一括取得、かわらばん・全体会除外）
$slot_master_cache['doctor_events'] = [];
$raw_doctor_events = fetchDoctorEventsFromYotei($start_date, $end_date);
foreach ($raw_doctor_events as $ev) {
    if (empty($ev['doctor']) || empty($ev['doctor']['id'])) continue;
    $ev_type = $ev['event_type'] ?? '';
    if ($ev_type === 'meeting' || $ev_type === 'memo') continue;
    $d_key = $ev['date'] ?? $ev['start_date'] ?? '';
    if ($d_key !== '') {
        $slot_master_cache['doctor_events'][$d_key][] = formatDoctorEventSummary($ev);
    }
}

$stmt_att = $pdo->prepare("
    SELECT doctor_name, target_date::text AS target_date, gif_slots, echo_slots, tcf_slots, reason, pattern_name
    FROM m2_doctor_attendance
    WHERE department_code = :dept
      AND target_date >= :start_date
      AND target_date <= :end_date
");
$stmt_att->execute([':dept' => $dept, ':start_date' => $start_date, ':end_date' => $end_date]);
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
$stmt_cap->execute([':dept' => $dept, ':start_date' => $start_date, ':end_date' => $end_date]);
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

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 予約マトリクス (縦型V2) | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: var(--font-sans);
            margin: 15px;
            background-color: var(--color-bg);
            color: var(--color-text-main);
            font-size: 13px;
        }
        .container { max-width: 1680px; margin: 0 auto; }

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .top-header h2 { margin: 0; font-size: 18px; }
        .top-header .header-links { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .header-link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            box-sizing: border-box;
            gap: 4px;
            padding: 0 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            transition: all 0.2s;
        }
        .header-link-btn.menu { background: #2c3e50; color: #fff; padding: 0 10px; font-size: 15px; }
        .header-link-btn.menu:hover { background: #34495e; }
        .header-link-btn.calendar { background: #3b82f6; color: #fff; }
        .header-link-btn.calendar:hover { background: #2563eb; }
        .header-link-btn.search { background: #0d9488; color: #fff; }
        .header-link-btn.search:hover { background: #0f766e; }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px 16px;
            background: #f8fafc;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 15px;
        }
        .top-bar-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .top-bar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .dept-tabs { display: inline-flex; gap: 3px; }
        .dept-tab {
            padding: 4px 10px;
            text-decoration: none;
            font-weight: bold;
            border-radius: 4px;
            border: 1px solid #ccc;
            background: #e6e6e6;
            color: #333;
            font-size: 11px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }
        .dept-tab.active-PROCT { background: #3498db; color: #fff; border-color: #2980b9; }
        .dept-tab.active-GASTRO { background: #2ecc71; color: #fff; border-color: #27ae60; }

        .month-nav-btn {
            padding: 4px 10px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            height: 30px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }
        .month-display {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-weight: bold;
            background-color: #e6f2ff;
            padding: 4px 14px;
            font-size: 14px;
            border: 1px solid #adc5f5;
            height: 30px;
            border-radius: 4px;
            color: #1e3a8a;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        .month-display:hover { background-color: #d1e1f0; }
        .month-display .month-item { padding: 0 2px; }

        .scroll-capsule {
            display: inline-flex;
            background: #e0e0e0;
            padding: 2px;
            border-radius: 16px;
            border: 1px solid #ccc;
        }
        .scroll-arrow-btn {
            background: none;
            border: none;
            font-size: 14px;
            cursor: pointer;
            width: 28px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: #444;
            transition: all 0.15s ease;
        }
        .scroll-arrow-btn:hover { background: #fff; color: #000; }

        /* 再描画タイマー カプセル (ホームボタン左) */
        .auto-refresh-capsule {
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            height: 32px;
            box-sizing: border-box;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 2px 6px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            vertical-align: middle;
        }
        .refresh-capsule-main {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn-refresh-now {
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 3px;
            transition: all 0.25s ease;
        }
        .btn-refresh-now:hover {
            background: #f1f5f9;
            transform: rotate(180deg);
        }
        .refresh-segment-group {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 4px;
            padding: 1px;
            gap: 2px;
            border: 1px solid #e2e8f0;
        }
        .btn-refresh-seg {
            background: transparent;
            border: none;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            padding: 2px 7px;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-refresh-seg:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .btn-refresh-seg.active {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);
        }
        .refresh-progress-track {
            height: 3px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 2px;
            width: 100%;
        }
        .refresh-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #3b82f6, #10b981);
            transition: width 0.9s linear;
        }

        /* 枠トグルボタングループ */
        .slot-toggle-group {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 0 6px;
            height: 32px;
            box-sizing: border-box;
            border-radius: 6px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .toolbar-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            margin-right: 3px;
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }
        .btn-slot-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 24px;
            box-sizing: border-box;
            line-height: 1;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-slot-toggle:hover {
            opacity: 0.85;
        }
        /* 各枠ON時のスタイル（枠背景色・枠線・濃い文字） */
        .btn-slot-toggle.btn-slot-gif.active {
            background: #dbeafe;
            color: #1e40af;
            border-color: #93c5fd;
            box-shadow: 0 1px 2px rgba(30, 64, 175, 0.12);
        }
        .btn-slot-toggle.btn-slot-echo.active {
            background: #cffafe;
            color: #0e7490;
            border-color: #67e8f9;
            box-shadow: 0 1px 2px rgba(14, 116, 144, 0.12);
        }
        .btn-slot-toggle.btn-slot-tcf.active {
            background: #dcfce7;
            color: #166534;
            border-color: #86efac;
            box-shadow: 0 1px 2px rgba(22, 101, 52, 0.12);
        }
        .btn-slot-toggle.btn-slot-mismatch.active {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fca5a5;
            box-shadow: 0 1px 2px rgba(153, 27, 27, 0.12);
        }

        /* 機能トグルボタン（伏字〜未割当） */
        .setting-group {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0 6px;
            height: 32px;
            box-sizing: border-box;
            border-radius: 6px;
            background: #fff;
            border: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .btn-feature-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            height: 24px;
            box-sizing: border-box;
            line-height: 1;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 0 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            background: #f1f5f9;
            color: #94a3b8;
            border-color: #e2e8f0;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-feature-toggle:hover {
            opacity: 0.85;
        }
        /* 各機能ON時のスタイル */
        .btn-feature-toggle.btn-feature-mask.active {
            background: #ede9fe;
            color: #6d28d9;
            border-color: #c4b5fd;
            box-shadow: 0 1px 2px rgba(109, 40, 217, 0.12);
        }
        .btn-feature-toggle.btn-feature-auto.active {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
            box-shadow: 0 1px 2px rgba(29, 78, 216, 0.12);
        }
        .btn-feature-toggle.btn-feature-invalid.active {
            background: #fef3c7;
            color: #b45309;
            border-color: #fde047;
            box-shadow: 0 1px 2px rgba(180, 83, 9, 0.12);
        }
        .btn-feature-toggle.btn-feature-canceled.active {
            background: #fed4c8;
            color: #9a3412;
            border-color: #f87171;
            box-shadow: 0 1px 2px rgba(154, 52, 18, 0.12);
        }
        .btn-feature-toggle.btn-feature-unassigned.active {
            background: #fce7f3;
            color: #be185d;
            border-color: #f9a8d4;
            box-shadow: 0 1px 2px rgba(190, 24, 93, 0.12);
        }

        .display-mode-toggle {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            background: #f1f5f9;
            border-radius: 4px;
            padding: 1px;
            height: 24px;
            box-sizing: border-box;
            border: 1px solid #e2e8f0;
            margin-left: 2px;
        }
        .display-mode-toggle .mode-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 20px;
            box-sizing: border-box;
            line-height: 1;
            padding: 0 8px;
            border: none;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            background: transparent;
            color: #64748b;
            transition: all 0.15s;
            text-decoration: none;
            white-space: nowrap;
        }
        .display-mode-toggle .mode-btn:hover { background: #e2e8f0; }
        .display-mode-toggle .mode-btn.active { background: #3b82f6; color: #fff; }

        .matrix-container {
            overflow: auto;
            max-height: 720px;
            border: 1px solid #cbd5e1;
            background: #fff;
            border-radius: 4px;
            position: relative;
        }
        .matrix-table {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            min-width: 800px;
            margin-bottom: 10px;
        }
        .matrix-table thead tr.header-row th {
            position: sticky;
            top: 0;
            background-color: #e6f2ff;
            color: #2c3e50;
            font-weight: bold;
            z-index: 20;
            height: 36px;
            border-bottom: 2px solid #b4c6e7;
            border-right: 1px solid #cbd5e1;
            padding: 3px 4px;
            font-size: 10px;
            text-align: center;
        }

        /* 日付枠（一番左の列）：month_schedule.php と完全統一 */
        .matrix-table td.fixed-date-col {
            position: sticky;
            left: 0;
            z-index: 10;
            width: 140px !important;
            min-width: 140px;
            max-width: 140px;
            border-right: 2px solid #94a3b8;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
            vertical-align: top;
            padding: 4px 4px;
            font-size: 9px;
            background-color: #ffffff;
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .day-memo-badge {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-size: 8.5px;
            font-weight: 700;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            border-radius: 3px;
            padding: 0 3px;
            height: 15px;
            line-height: 15px;
            max-width: 62px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
            margin-left: 3px;
            box-sizing: border-box;
        }
        .day-memo-badge:hover {
            background: #fde68a;
            border-color: #f59e0b;
            color: #78350f;
        }
        .matrix-table th.fixed-date-col {
            position: sticky;
            top: 0;
            left: 0;
            background-color: #d1e1f0 !important;
            z-index: 30;
            border-right: 2px solid #94a3b8;
            border-bottom: 2px solid #b4c6e7;
        }
        .matrix-table td.fixed-date-col.has-available { background-color: #fef9c3 !important; }
        .matrix-table td.fixed-date-col.today { background-color: #ffffe0 !important; }
        .matrix-table td.fixed-date-col.closed { background-color: #fef2f2 !important; color: #ef4444; }
        .matrix-table td.fixed-date-col.past { background-color: #d1d5db !important; opacity: 0.8; color: #6b7280; }
        .matrix-table td.fixed-date-col.has-negative { border-left: 3px solid #dc2626 !important; }
        .matrix-table td.fixed-date-col.has-reservation-on-closed { border: 2px solid #dc2626 !important; }

        /* ── 医師予定バッジ (yotei連携: 一番左列) ── */
        .cal-doctor-events { display: flex; flex-direction: column; gap: 2px; margin: 2px 0 3px 0; padding: 0 1px; }
        .cal-doctor-event-badge {
            display: block;
            font-size: 8.5px;
            font-weight: 700;
            line-height: 1.35;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 3px;
            padding: 1px 3px;
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: help;
            box-sizing: border-box;
            transition: all 0.15s ease;
        }
        .cal-doctor-event-badge:hover {
            background-color: #fee2e2;
            border-color: #f87171;
            color: #7f1d1d;
            box-shadow: 0 1px 2px rgba(220, 38, 38, 0.15);
        }
        .cal-doctor-event-badge.more {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
            text-align: center;
            font-size: 8px;
            padding: 0 2px;
        }
        .cal-doctor-event-badge.more:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .matrix-table td {
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            padding: 2px;
            vertical-align: top;
            min-width: 65px;
            width: 65px;
        }

        .month-section { margin-bottom: 20px; }
        .month-header {
            font-weight: bold;
            font-size: 14px;
            padding: 6px 10px;
            background: #e6f2ff;
            border: 1px solid #b4c6e7;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            color: #1e3a8a;
        }
        .month-section .matrix-table {
            border-top: none;
            border-radius: 0 0 4px 4px;
        }

        .waku-cell {
            min-height: 60px;
            height: auto;
            cursor: pointer;
            background-color: #fff;
            transition: background-color 0.2s;
            position: relative;
        }
        .waku-cell.dragover { background-color: #fff3cd !important; border: 2px dashed #ffc107 !important; }
        .waku-cell.slot-duplicate { background-color: #fef2f2 !important; border: 2px solid #dc2626 !important; }
        .waku-cell.slot-out-of-slot { background-color: #ffedd5 !important; border: 2px solid #f59e0b !important; }
        .waku-cell.slot-unavailable { background-color: #f1f5f9 !important; border: 1px dashed #94a3b8 !important; }
        .waku-cell.slot-past { background-color: #f1f5f9 !important; opacity: 0.7; }

        .patient-box {
            border: 1px solid #b4c6e7;
            border-radius: 4px;
            padding: 3px 4px;
            text-align: left;
            font-size: 10px;
            cursor: grab;
            margin-bottom: 2px;
            display: block;
            line-height: 1.2;
            position: relative;
        }
        .patient-box:active { cursor: grabbing; }
        .patient-box.is-temporary-box { background-color: #fff2cc !important; border: 1px solid #ffcc66 !important; color: #b25900; }
        .patient-box.is-canceled-box { background-color: #fed4c8 !important; border: 1px solid #f87171 !important; opacity: 0.85; }
        .patient-box.is-canceled-box .pname-row, .patient-box.is-canceled-box .pname { text-decoration: line-through; color: #9a3412 !important; }
        .patient-box.is-waku-nashi-box { background-color: #ffffff !important; border: 1px solid #e2e8f0 !important; color: #64748b; }
        .patient-box.is-waku-nashi-box .waku-nashi-row { color: #64748b; }
        .patient-box.duplicate { background-color: #fef2f2 !important; border: 2px solid #dc2626 !important; border-left: 3px solid #dc2626 !important; }
        .patient-box.out-of-slot { background-color: #ffedd5 !important; border: 2px solid #f59e0b !important; border-left: 3px solid #f59e0b !important; }

        .invalid-cell { background-color: #fff5f5 !important; }

        body.mask-active .real-pname { display: none; }
        body.mask-active .masked-pname { display: inline !important; }
    </style>
</head>
<body>

<div class="container">

    <!-- 上部ヘッダー -->
    <div class="top-header">
        <h2>内視鏡室 予約管理システム (MatrixView Vertical Date)</h2>
        <div class="header-links">
            <!-- 再描画タイマー (セグメントボタン形式: 5分 / 10分 / 15分 + プログレスバー) -->
            <div class="auto-refresh-capsule" id="autoRefreshCapsule" title="自動再読込タイマー">
                <div class="refresh-capsule-main">
                    <button type="button" class="btn-refresh-now" onclick="manualRefresh()" title="今すぐデータを最新に再読込">
                        <span class="refresh-icon">🔄</span>
                    </button>
                    <div class="refresh-segment-group">
                        <button type="button" class="btn-refresh-seg active" data-interval="5" onclick="setRefreshInterval(5)">5分</button>
                        <button type="button" class="btn-refresh-seg" data-interval="10" onclick="setRefreshInterval(10)">10分</button>
                        <button type="button" class="btn-refresh-seg" data-interval="15" onclick="setRefreshInterval(15)">15分</button>
                    </div>
                </div>
                <div class="refresh-progress-track" title="次回自動更新までの進捗">
                    <div class="refresh-progress-bar" id="refreshProgressBar"></div>
                </div>
            </div>

            <a href="index.php" class="header-link-btn menu" title="ポータルメニュー">🏠</a>
            <a href="progress_monitor.php?dept=<?=$dept?>" class="header-link-btn" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; font-weight: bold;">📊 当日ホワイトボード</a>

            <a href="month_schedule.php?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>" class="header-link-btn calendar">📅 月間カレンダーへ</a>
            <a href="slot_search.php?dept=<?=$dept?>" class="header-link-btn search">🔍 空き枠検索</a>
            <button type="button" onclick="openAuditLogModal()" class="header-link-btn audit-log-btn" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #fff; border: none; cursor: pointer; font-weight: bold;" title="直近の操作履歴・誤操作ワンクリック復元（Undo）">
                <span>🕒</span> 操作履歴(Undo)
            </button>
            <button type="button" onclick="openMedicalAlertScanModal({year: <?=$year?>, month: <?=$month?>, dept: '<?=$dept?>'})" class="header-link-btn" style="background: linear-gradient(135deg, #e11d48, #be123c); color: #fff; border: none; cursor: pointer; font-weight: bold;" title="当月の抗血栓薬・アレルギー・感染症・風邪アラートスキャン">
                <span>🛡️</span> アラートスキャン
            </button>
        </div>
    </div>

    <!-- トップバー -->
    <div class="top-bar">
        <div class="top-bar-left">
            <div class="dept-tabs">
                <a href="?dept=PROCT&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=<?=$display_mode?>" class="dept-tab <?=$dept==='PROCT'?'active-PROCT':''?>">🟢 肛</a>
                <a href="?dept=GASTRO&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=<?=$display_mode?>" class="dept-tab <?=$dept==='GASTRO'?'active-GASTRO':''?>">🔵 胃</a>
            </div>

            <?php if ($view_mode === '1month'): ?>
                <a href="?dept=<?=$dept?>&year=<?=$prev_year?>&month=<?=$prev_month?>&view=1month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">◀</a>
                <div class="month-display" onclick="toggleMonthView()">
                    <span class="month-item">[<?=$year?>.<?=$month?>]</span>
                    <span style="font-size:10px; color:#94a3b8;">▼</span>
                </div>
                <a href="?dept=<?=$dept?>&year=<?=$next_year?>&month=<?=$next_month?>&view=1month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">▶</a>
            <?php else: ?>
                <a href="?dept=<?=$dept?>&year=<?=$prev3_year?>&month=<?=$prev3_month?>&view=3month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">◀</a>
                <div class="month-display" onclick="toggleMonthView()">
                    <?php foreach ($target_months as $m): ?>
                        <span class="month-item">[<?=$m['year']?>.<?=$m['month']?>]</span>
                    <?php endforeach; ?>
                    <span style="font-size:10px; color:#94a3b8;">▲</span>
                </div>
                <a href="?dept=<?=$dept?>&year=<?=$next3_year?>&month=<?=$next3_month?>&view=3month&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=<?=$display_mode?>" class="month-nav-btn">▶</a>
            <?php endif; ?>

            <div class="scroll-capsule">
                <button class="scroll-arrow-btn" onclick="smoothScroll('left')">⬅</button>
                <button class="scroll-arrow-btn" onclick="smoothScroll('up')">⬆</button>
                <button class="scroll-arrow-btn" onclick="smoothScroll('down')">⬇</button>
                <button class="scroll-arrow-btn" onclick="smoothScroll('right')">➡</button>
            </div>
        </div>

        <div class="top-bar-right">
            <!-- 枠トグルボタングループ (チェックボックス廃止・各枠背景色連動) -->
            <div class="slot-toggle-group">
                <span class="toolbar-label">枠:</span>
                <button type="button" class="btn-slot-toggle btn-slot-gif active" onclick="toggleSlotBtn('GIF', this)" title="GIF(胃カメラ)枠の表示切替">GIF</button>
                <button type="button" class="btn-slot-toggle btn-slot-echo active" onclick="toggleSlotBtn('ECHO', this)" title="エコー(US)枠の表示切替">エコー</button>
                <button type="button" class="btn-slot-toggle btn-slot-tcf active" onclick="toggleSlotBtn('TCF', this)" title="TCF(大腸)枠の表示切替">TCF</button>
                <button type="button" class="btn-slot-toggle btn-slot-mismatch active" onclick="toggleSlotBtn('MISMATCH', this)" title="迷子(枠外予約)の表示切替">迷子</button>
            </div>

            <!-- 機能トグルボタン (チェックボックス廃止・機能カラー連動) & 表示切替 -->
            <div class="setting-group">
                <button type="button" id="btn_toggle_mask" class="btn-feature-toggle btn-feature-mask" onclick="toggleMaskBtn(this)" title="患者氏名を伏字表示">🥷 伏字</button>
                <button type="button" id="btn_toggle_auto" class="btn-feature-toggle btn-feature-auto active" onclick="toggleAutoScrollBtn(this)" title="起動時に本日位置へ自動スクロール">📅 起動時</button>
                <button type="button" class="btn-feature-toggle btn-feature-invalid <?=$show_invalid?'active':''?>" onclick="toggleInvalidBtn(<?=$show_invalid?'false':'true'?>)" title="枠外・エラー予約の表示切替">⚠️ 迷子</button>
                <button type="button" class="btn-feature-toggle btn-feature-canceled <?=$show_canceled?'active':''?>" onclick="toggleCanceledBtn(<?=$show_canceled?'false':'true'?>)" title="削除済み予約の表示切替">🗑️ 削除</button>
                <button type="button" class="btn-feature-toggle btn-feature-unassigned <?=$show_unassigned?'active':''?>" onclick="toggleUnassignedBtn(<?=$show_unassigned?'false':'true'?>)" title="医師未割当枠の表示切替">📌 未割当</button>

                <div class="display-mode-toggle">
                    <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=use_max" class="mode-btn <?=$display_mode==='use_max'?'active':''?>">📊 使用/最大</a>
                    <a href="?dept=<?=$dept?>&year=<?=$year?>&month=<?=$month?>&view=<?=$view_mode?>&show_invalid=<?=$show_invalid?'1':'0'?>&show_canceled=<?=$show_canceled?'1':'0'?>&show_unassigned=<?=$show_unassigned?'1':'0'?>&display_mode=remain" class="mode-btn <?=$display_mode==='remain'?'active':''?>">📉 残数</a>
                </div>
            </div>
        </div>
    </div>

    <!-- マトリクス本体 -->
    <div class="matrix-container" id="matrixContainer">
        <?php
        foreach ($target_months as $m) {
            $m_key = sprintf('%04d-%02d', $m['year'], $m['month']);
            $month_data = $monthly_data[$m_key] ?? [];
            $month_matrix = [];
            foreach ($month_data as $row) {
                $date_str = (string)$row['exam_date'];
                $waku_name = (string)$row['scheduled_time'];
                $waku_exists = isset($waku_group_map[$waku_name]);
                $key = $waku_exists ? $waku_name : 'MISMATCH';
                $month_matrix[$date_str][$key][] = $row;
            }
            renderMonthMatrixFast($pdo, $m['year'], $m['month'], $dept, $waku_list, $month_matrix, $week_ja, $show_canceled, $show_invalid, $show_unassigned, $simple_mode, $doctors_list, $reserved_counts_cache, $slot_master_cache);
        }
        ?>
    </div>

</div>

<script>
const matrixContainer = document.getElementById('matrixContainer');
const currentDept = '<?= $dept ?>';

function toggleMonthView() {
    const url = new URL(window.location.href);
    const currentView = url.searchParams.get('view') || '1month';
    const newView = currentView === '1month' ? '3month' : '1month';
    url.searchParams.set('view', newView);
    window.location.href = url.toString();
}

// ============================================================
// 再描画タイマー（セグメントボタン形式: 5分 / 10分 / 15分 + プログレスバー）
// ============================================================
let refreshMinutes = parseInt(localStorage.getItem('onokai_refresh_interval') || '5', 10);
if (![5, 10, 15].includes(refreshMinutes)) refreshMinutes = 5;
let refreshTotalSec = refreshMinutes * 60;
let refreshElapsedSec = 0;
let refreshTimerId = null;

function updateRefreshSegUI() {
    document.querySelectorAll('.btn-refresh-seg').forEach(btn => {
        const m = parseInt(btn.dataset.interval, 10);
        btn.classList.toggle('active', m === refreshMinutes);
    });
}

function setRefreshInterval(minutes) {
    refreshMinutes = parseInt(minutes, 10);
    localStorage.setItem('onokai_refresh_interval', refreshMinutes);
    refreshTotalSec = refreshMinutes * 60;
    refreshElapsedSec = 0;
    updateRefreshSegUI();
    const bar = document.getElementById('refreshProgressBar');
    if (bar) bar.style.width = '0%';
}

function tickRefreshTimer() {
    refreshElapsedSec++;
    const bar = document.getElementById('refreshProgressBar');
    if (bar) {
        const pct = Math.min(100, (refreshElapsedSec / refreshTotalSec) * 100);
        bar.style.width = pct + '%';
    }
    if (refreshElapsedSec >= refreshTotalSec) {
        window.location.reload();
    }
}

function manualRefresh() {
    const icon = document.querySelector('.refresh-icon');
    if (icon) icon.style.transform = 'rotate(360deg)';
    setTimeout(() => {
        window.location.reload();
    }, 150);
}

// 枠トグルボタン
function toggleSlotBtn(group, btn) {
    const active = !btn.classList.contains('active');
    btn.classList.toggle('active', active);
    toggleSlotGroup(group, active);
}

function toggleSlotGroup(group, checked) {
    document.querySelectorAll(`.slot-header[data-group="${group}"]`).forEach(el => {
        el.style.display = checked ? '' : 'none';
    });
    document.querySelectorAll(`td[data-group="${group}"]`).forEach(el => {
        el.style.display = checked ? '' : 'none';
    });
}

// 伏字トグルボタン
function toggleMaskBtn(btn) {
    const active = !btn.classList.contains('active');
    btn.classList.toggle('active', active);
    toggleMask(active);
}

function toggleMask(checked) {
    document.body.classList.toggle('mask-active', checked);
    localStorage.setItem('onokai_mask', checked ? '1' : '0');
}

// 起動時スクロールトグルボタン
function toggleAutoScrollBtn(btn) {
    const active = !btn.classList.contains('active');
    btn.classList.toggle('active', active);
    saveAutoScroll(active);
}

function saveAutoScroll(checked) {
    localStorage.setItem('onokai_auto_scroll', checked ? '1' : '0');
}

function smoothScroll(dir) {
    const el = matrixContainer;
    if (!el) return;
    const x = dir === 'left' ? -300 : dir === 'right' ? 300 : 0;
    const y = dir === 'up' ? -150 : dir === 'down' ? 150 : 0;
    el.scrollBy({ left: x, top: y, behavior: 'smooth' });
}

function scrollToToday() {
    const today = document.getElementById('row-today');
    if (today && matrixContainer) {
        matrixContainer.scrollTop = today.offsetTop - 60;
    }
}

// 迷子・削除・未割当トグル
function toggleInvalidBtn(checked) {
    toggleInvalid(checked);
}
function toggleInvalid(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_invalid', checked ? '1' : '0');
    window.location.href = url.toString();
}

function toggleCanceledBtn(checked) {
    toggleCanceled(checked);
}
function toggleCanceled(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_canceled', checked ? '1' : '0');
    window.location.href = url.toString();
}

function toggleUnassignedBtn(checked) {
    toggleUnassigned(checked);
}
function toggleUnassigned(checked) {
    const url = new URL(window.location.href);
    url.searchParams.set('show_unassigned', checked ? '1' : '0');
    window.location.href = url.toString();
}

// ============================================================
// ★★★ 予約登録・編集モーダル起動（modal_edit.php 一本化） ★★★
// ============================================================
function openModal(date, waku, scheduleId, dept) {
    // スクロール位置を一時退避
    if (matrixContainer) {
        try {
            sessionStorage.setItem('scroll_top', matrixContainer.scrollTop);
            sessionStorage.setItem('scroll_left', matrixContainer.scrollLeft);
        } catch(e) {}
    }

    if (typeof window.openModalRunner === 'function') {
        window.openModalRunner(date, waku, scheduleId, dept || currentDept);
        return;
    }

    var url = 'modal_edit.php';
    url += '?date=' + encodeURIComponent(date);
    url += '&waku=' + encodeURIComponent(waku);
    url += '&dept=' + encodeURIComponent(dept || currentDept);
    if (scheduleId && scheduleId > 0) {
        url += '&schedule_id=' + encodeURIComponent(scheduleId);
    }
    window.open(url, '_blank', 'width=1000,height=780,scrollbars=yes,resizable=yes');
}

function openInsertModal(cell, event) {
    if (event && event.target !== cell) return;
    const date = cell.dataset.date;
    const waku = cell.dataset.waku;
    if (date && waku) {
        openModal(date, waku, 0, currentDept);
    }
}

function openEditModalWithCtrlCheck(el, event) {
    event.stopPropagation();
    const box = el.closest('.patient-box');
    if (!box) return;
    const data = JSON.parse(box.dataset.json || '{}');
    if (event.ctrlKey && data.patient_id) {
        window.open(`http://192.168.1.164/~rsn/2000.cgi?show=${encodeURIComponent(data.patient_id)}`, '_blank');
        return;
    }
    
    const cell = box.closest('.waku-cell');
    const date = data.exam_date || (cell ? cell.dataset.date : '');
    const waku = data.scheduled_time || (cell ? cell.dataset.waku : '');
    const sid = data.schedule_id || parseInt(box.dataset.scheduleId, 10) || 0;
    
    if (sid > 0) {
        openModal(date, waku, sid, data.department_code || currentDept);
    }
}

// ============================================================
// ★★★ ドラッグ＆ドロップ移動機能 ★★★
// ============================================================
let draggedBox = null;

function dragStart(box, e) {
    draggedBox = box;
    e.dataTransfer.setData('text/plain', String(box.dataset.scheduleId));
}

function allowDrop(e) { e.preventDefault(); }
function dragEnter(cell) { cell.classList.add('dragover'); }
function dragLeave(cell) { cell.classList.remove('dragover'); }

function dropWaku(cell, e) {
    e.preventDefault();
    cell.classList.remove('dragover');
    const sid = e.dataTransfer.getData('text/plain');
    if (!sid || sid === 'undefined' || !draggedBox) return;
    
    const date = cell.dataset.date;
    const waku = cell.dataset.waku;
    
    if (matrixContainer) {
        sessionStorage.setItem('scroll_top', matrixContainer.scrollTop);
        sessionStorage.setItem('scroll_left', matrixContainer.scrollLeft);
    }
    
    const fd = new FormData();
    fd.append('action_type', 'move');
    fd.append('schedule_id', sid);
    fd.append('exam_date', date);
    fd.append('scheduled_time', waku);
    
    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('移動に失敗しました: ' + (data.message || '不明なエラー'));
                window.location.reload();
            }
        })
        .catch(() => {
            alert('移動処理中にエラーが発生しました');
            window.location.reload();
        })
        .finally(() => { draggedBox = null; });
}

// ============================================================
// ★★★ modal_edit.php 更新後の親画面自動リフレッシュ連携 ★★★
// ============================================================
window.addEventListener('message', function(event) {
    if (event.data && (event.data === 'reservation_updated' || event.data.type === 'reservation_updated')) {
        window.location.reload();
    }
});

window.addEventListener('focus', function() {
    if (sessionStorage.getItem('need_reload') === '1') {
        sessionStorage.removeItem('need_reload');
        window.location.reload();
    }
});

// ============================================================
// 初期化 & スクロール位置復元
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // 伏字モード初期化
    const mask = localStorage.getItem('onokai_mask') || '0';
    const btnMask = document.getElementById('btn_toggle_mask');
    if (btnMask) btnMask.classList.toggle('active', mask === '1');
    if (mask === '1') document.body.classList.add('mask-active');

    // 起動時スクロール初期化（デフォルト1）
    const auto = localStorage.getItem('onokai_auto_scroll') !== null ? localStorage.getItem('onokai_auto_scroll') : '1';
    const btnAuto = document.getElementById('btn_toggle_auto');
    if (btnAuto) btnAuto.classList.toggle('active', auto === '1');

    // 再描画タイマー初期化
    updateRefreshSegUI();
    if (refreshTimerId) clearInterval(refreshTimerId);
    refreshTimerId = setInterval(tickRefreshTimer, 1000);

    const scrollTop = sessionStorage.getItem('scroll_top');
    const scrollLeft = sessionStorage.getItem('scroll_left');
    if (scrollTop !== null && matrixContainer) {
        matrixContainer.scrollTop = parseInt(scrollTop, 10);
        matrixContainer.scrollLeft = parseInt(scrollLeft, 10);
        sessionStorage.removeItem('scroll_top');
        sessionStorage.removeItem('scroll_left');
    } else if (auto === '1') {
        setTimeout(scrollToToday, 150);
    }
});
</script>
<script src="assets/js/month_schedule.js?v=<?= time() ?>"></script>

<?php include __DIR__ . '/audit_modal_inc.php'; ?>
<?php include __DIR__ . '/medical_alert_modal_inc.php'; ?>

</body>
</html>