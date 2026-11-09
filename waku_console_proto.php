<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\waku_console_proto.php
 * ファイル名: waku_console_proto.php
 * 概要: 時間枠統合管理コンソール（プロトタイプ版）
 * 生成日時: 2026-10-22 20:20
 * 修正履歴:
 *   - 2026-10-23: 【従来中止データ コンバーター連携】従来の予約テーブル（※枠なし等）で埋められていた休止データを新オーバーライドへ一括移行できるコンバーターへの導線・件数バッジ・案内バナーを新設
 *   - 2026-10-23: 【モーダル内蔵＆誤操作防止対応】waku_override_modal_inc.php を直接内蔵し、コンソール画面から離脱せず直接時間枠設定・期間設定を編集・即時反映できるよう改善。ローカル環境外側黒背景にも対応
 *   - 2026-10-22: 初版作成（後任事務長・管理者向け 枠カスタム管理コンソール）
 *       1. カレンダー全体俯瞰ダッシュボード（月間カレンダー上で基本枠・休止・臨時・期間設定を色分け一望）
 *       2. 3層レイヤー構造インスペクター（基本枠マスタ vs オーバーライド vs 最終合成結果の可視化）
 *       3. 日別・期間別オーバーライドの即時シミュレーション＆登録・解除
 *       4. 予約安全インスペクター（確定予約との衝突検知）
 *       5. 後任事務長向け「クリニック枠設計思想・引き継ぎガイド」内蔵
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/env_helper.php';
require_once __DIR__ . '/calendar_helper_onokai.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// パラメータ取得
$dept = isset($_GET['dept']) && in_array($_GET['dept'], ['PROCT', 'GASTRO'], true) ? $_GET['dept'] : 'PROCT';
$year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// 前月・翌月リンク計算
$prev_dt = new DateTime("{$year}-{$month}-01 -1 month");
$prev_year = (int)$prev_dt->format('Y');
$prev_month = (int)$prev_dt->format('n');

$next_dt = new DateTime("{$year}-{$month}-01 +1 month");
$next_year = (int)$next_dt->format('Y');
$next_month = (int)$next_dt->format('n');

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end   = date('Y-m-t', strtotime($month_start));

// 選択中の日付（デフォルトは本日、当月外なら月初）
$today = date('Y-m-d');
$default_selected = ($today >= $month_start && $today <= $month_end) ? $today : $month_start;
$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : $default_selected;

// 1. 基本枠マスタ (m2_waku) 取得
$stmt_base = $pdo->prepare("
    SELECT id, department_code, group_code, waku_code, waku_name, display_order, is_active
    FROM m2_waku
    WHERE department_code = :dept AND is_active = TRUE
    ORDER BY display_order ASC
");
$stmt_base->execute([':dept' => $dept]);
$base_wakus = $stmt_base->fetchAll(PDO::FETCH_ASSOC);

// 2. 当月のオーバーライド一覧取得 (m2_waku_override)
$stmt_ov = $pdo->prepare("
    SELECT 
        o.override_id,
        o.department_code,
        o.base_waku_id,
        o.override_type,
        o.target_date::text AS target_date,
        o.start_date::text AS start_date,
        o.end_date::text AS end_date,
        o.display_waku_name,
        o.group_code,
        o.display_order,
        o.is_suspended,
        o.reason,
        w.waku_name AS base_waku_name
    FROM m2_waku_override o
    LEFT JOIN m2_waku w ON o.base_waku_id = w.id
    WHERE o.department_code = :dept
      AND o.is_active = TRUE
      AND (
          (o.override_type = 'DATE' AND o.target_date BETWEEN :start AND :end)
          OR
          (o.override_type = 'PERIOD' AND o.start_date <= :end AND o.end_date >= :start)
      )
    ORDER BY o.override_id ASC
");
$stmt_ov->execute([':dept' => $dept, ':start' => $month_start, ':end' => $month_end]);
$all_overrides = $stmt_ov->fetchAll(PDO::FETCH_ASSOC);

// 日付ごとのオーバーライドをマッピング
$daily_overrides = [];
foreach ($all_overrides as $ov) {
    if ($ov['override_type'] === 'DATE') {
        $d = $ov['target_date'];
        $daily_overrides[$d][] = $ov;
    } elseif ($ov['override_type'] === 'PERIOD') {
        $cur = max($ov['start_date'], $month_start);
        $last = min($ov['end_date'], $month_end);
        while ($cur <= $last) {
            $daily_overrides[$cur][] = $ov;
            $cur = date('Y-m-d', strtotime("{$cur} +1 day"));
        }
    }
}

// 3. 当月の予約件数集計
$stmt_res_cnt = $pdo->prepare("
    SELECT exam_date::text AS exam_date, scheduled_time, COUNT(*) as cnt
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date BETWEEN :start AND :end
      AND is_canceled = FALSE
      AND patient_name != '※枠なし'
    GROUP BY exam_date, scheduled_time
");
$stmt_res_cnt->execute([':dept' => $dept, ':start' => $month_start, ':end' => $month_end]);
$res_counts_raw = $stmt_res_cnt->fetchAll(PDO::FETCH_ASSOC);

$daily_res_totals = [];
$slot_res_counts = [];
foreach ($res_counts_raw as $r) {
    $d = $r['exam_date'];
    $t = $r['scheduled_time'];
    $c = (int)$r['cnt'];
    $daily_res_totals[$d] = ($daily_res_totals[$d] ?? 0) + $c;
    $slot_res_counts[$d][$t] = $c;
}

// カレンダー週計算
$first_day_ts = strtotime($month_start);
$first_dow = (int)date('w', $first_day_ts); // 0(日) - 6(土)
$days_in_month = (int)date('t', $first_day_ts);

// 期間オーバーライドの一覧（下部パネル用）
$period_overrides = array_filter($all_overrides, function($o) {
    return $o['override_type'] === 'PERIOD';
});

// 従来の枠なし未移行件数チェック
$stmt_legacy_cnt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM t_endoscopy_schedule s
    JOIN m2_waku w ON w.department_code = s.department_code 
                  AND w.waku_name = s.scheduled_time 
                  AND w.is_active = TRUE
    LEFT JOIN m2_waku_override ov ON ov.department_code = s.department_code
                                  AND ov.target_date = s.exam_date
                                  AND ov.base_waku_id = w.id
                                  AND ov.override_type = 'DATE'
                                  AND ov.is_suspended = TRUE
                                  AND ov.is_active = TRUE
    WHERE s.department_code = :dept
      AND (
          s.patient_name = '※枠なし' 
          OR (s.patient_name = '※仮予約' AND (s.remarks ~ '枠なし' OR s.remarks ~ '休止' OR s.remarks ~ '休診'))
          OR s.remarks ~ '＊＊＊🚫枠なし🚫＊＊＊'
      )
      AND ov.override_id IS NULL
");
$stmt_legacy_cnt->execute([':dept' => $dept]);
$legacy_pending_cnt = (int)$stmt_legacy_cnt->fetchColumn();

$dept_name = ($dept === 'PROCT') ? '肛門科' : '胃腸科';
$dept_theme = ($dept === 'PROCT') ? '#10b981' : '#0284c7';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>時間枠統合管理コンソール (プロトタイプ) - 医療法人小野会</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?= $dept_theme ?>;
            --primary-dark: #0f172a;
            --primary-light: #f0fdf4;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --badge-suspend-bg: #fee2e2;
            --badge-suspend-text: #b91c1c;
            --badge-extra-bg: #dcfce7;
            --badge-extra-text: #15803d;
            --badge-period-bg: #e0f2fe;
            --badge-period-text: #0369a1;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            padding: 16px 24px;
        }

        /* ── ヘッダー ── */
        .console-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            border-radius: 12px;
            padding: 16px 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-md);
            flex-wrap: wrap;
            gap: 16px;
        }
        .header-title-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .header-logo-badge {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 24px;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header-title {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.01em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .proto-pill {
            background: #f59e0b;
            color: #78350f;
            font-size: 10.5px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 9999px;
            text-transform: uppercase;
        }
        .header-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .dept-switcher {
            display: inline-flex;
            background: rgba(255, 255, 255, 0.1);
            padding: 3px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .dept-btn {
            color: #cbd5e1;
            text-decoration: none;
            padding: 5px 14px;
            font-size: 12.5px;
            font-weight: 700;
            border-radius: 6px;
            transition: all 0.15s;
        }
        .dept-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .month-nav {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 3px;
            gap: 2px;
        }
        .month-nav-btn {
            color: #e2e8f0;
            text-decoration: none;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 5px;
            transition: all 0.15s;
        }
        .month-nav-btn:hover { background: rgba(255, 255, 255, 0.2); color: #fff; }
        .month-title {
            font-size: 14px;
            font-weight: 800;
            padding: 0 8px;
            color: #ffffff;
            min-width: 90px;
            text-align: center;
        }

        .guide-toggle-btn {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border: 1px solid rgba(147, 197, 253, 0.3);
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .guide-toggle-btn:hover {
            background: rgba(59, 130, 246, 0.35);
            color: #ffffff;
        }

        /* ── 引き継ぎガイドパネル ── */
        .guide-panel {
            background: #ffffff;
            border: 1px solid #bfdbfe;
            border-left: 5px solid #2563eb;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            display: none;
            animation: fadeIn 0.2s ease-out;
        }
        .guide-panel.active { display: block; }
        .guide-panel h4 {
            color: #1e3a8a;
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .guide-panel p {
            font-size: 12.5px;
            color: #334155;
            margin-bottom: 10px;
            line-height: 1.6;
        }
        .guide-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }
        .guide-step-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
        }
        .guide-step-title {
            font-size: 12.5px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .guide-step-desc {
            font-size: 11.5px;
            color: #64748b;
            line-height: 1.5;
        }

        /* ── メイン 2カラム レイアウト ── */
        .console-main-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 1080px) {
            .console-main-grid { grid-template-columns: 1fr; }
        }

        /* ── 左カラム: カレンダー俯瞰マップ ── */
        .calendar-card {
            background: var(--bg-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
        }
        .card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        .card-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .legend-row {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 11px;
            color: #64748b;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .cal-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .cal-th {
            padding: 8px 4px;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            border-bottom: 1px solid var(--border-color);
        }
        .cal-th.sun { color: #ef4444; }
        .cal-th.sat { color: #3b82f6; }

        .cal-cell {
            height: 78px;
            border: 1px solid #f1f5f9;
            padding: 6px;
            vertical-align: top;
            cursor: pointer;
            transition: all 0.15s;
            position: relative;
            background: #ffffff;
        }
        .cal-cell:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        .cal-cell.is-selected {
            background: #eff6ff !important;
            border: 2px solid #3b82f6 !important;
            box-shadow: 0 0 0 1px #3b82f6;
            z-index: 2;
        }
        .cal-cell.is-today {
            background: #fafaf9;
        }
        .cal-cell.is-other-month {
            opacity: 0.35;
            background: #f8fafc;
            cursor: default;
        }
        .cal-day-num {
            font-size: 13px;
            font-weight: 800;
            color: #1e293b;
            display: inline-block;
            margin-bottom: 4px;
        }
        .cal-cell.sun .cal-day-num { color: #ef4444; }
        .cal-cell.sat .cal-day-num { color: #3b82f6; }

        .cal-badge-stack {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .cal-pill {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .pill-normal { background: #f1f5f9; color: #475569; }
        .pill-suspend { background: var(--badge-suspend-bg); color: var(--badge-suspend-text); border: 1px solid #fecaca; }
        .pill-extra { background: var(--badge-extra-bg); color: var(--badge-extra-text); border: 1px solid #bbf7d0; }
        .pill-period { background: var(--badge-period-bg); color: var(--badge-period-text); border: 1px solid #bae6fd; }

        .cal-res-count {
            position: absolute;
            bottom: 4px;
            right: 6px;
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
        }

        /* ── 右カラム: 枠構造インスペクター ＆ 操作シミュレーター ── */
        .inspector-card {
            background: var(--bg-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
        }
        .inspector-date-header {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .inspect-date-text {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .inspect-dept-pill {
            font-size: 11px;
            font-weight: 700;
            background: #e2e8f0;
            color: #334155;
            padding: 2px 8px;
            border-radius: 4px;
        }

        /* 3層レイヤーの重なりビジュアル */
        .layer-structure-box {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 16px;
        }
        .layer-box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            background: #ffffff;
            position: relative;
        }
        .layer-box.level-1 { border-left: 4px solid #64748b; background: #fafafa; }
        .layer-box.level-2 { border-left: 4px solid #f59e0b; background: #fffbeb; }
        .layer-box.level-3 { border-left: 4px solid #10b981; background: #f0fdf4; }

        .layer-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .layer-label {
            font-size: 12px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .layer-tag {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .tag-base { background: #e2e8f0; color: #475569; }
        .tag-override { background: #fde68a; color: #92400e; }
        .tag-result { background: #bbf7d0; color: #166534; }

        .slot-pill-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .slot-chip {
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
        }
        .slot-chip.is-suspended {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
            text-decoration: line-through;
        }
        .slot-chip.is-extra {
            background: #dcfce7;
            color: #15803d;
            border-color: #86efac;
        }
        .slot-chip.is-changed {
            background: #fef3c7;
            color: #b45309;
            border-color: #fde68a;
        }

        /* アクション操作バー */
        .inspect-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }
        .btn-act {
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.15s;
            text-decoration: none;
        }
        .btn-act-suspend { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .btn-act-suspend:hover { background: #fecaca; }
        .btn-act-extra { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .btn-act-extra:hover { background: #bbf7d0; }
        .btn-act-modal { background: #2563eb; color: #ffffff; grid-column: span 2; }
        .btn-act-modal:hover { background: #1d4ed8; }
        .btn-act-master { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; grid-column: span 2; }
        .btn-act-master:hover { background: #e2e8f0; color: #0f172a; }

        /* 安全インスペクター警告 */
        .safety-alert-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 12px;
            font-size: 11.5px;
            color: #92400e;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        /* ── 下部セクション: 期間オーバーライド一覧 ── */
        .period-card {
            background: var(--bg-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            padding: 18px 20px;
        }
        .period-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .period-table th {
            background: #f8fafc;
            padding: 8px 12px;
            font-size: 12px;
            color: #475569;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .period-table td {
            padding: 10px 12px;
            font-size: 12.5px;
            border-bottom: 1px solid #f1f5f9;
        }
        .period-table tr:hover { background: #fafaf9; }
    </style>
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css?v=<?= filemtime(__DIR__ . '/assets/css/onokai_flatpickr.css') ?>">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/js/env_detector.js"></script>
<body class="<?= getBodyEnvClass() ?>">
<?= renderLocalEnvBanner() ?>

    <!-- ── ヘッダー ── -->
    <header class="console-header">
        <div class="header-title-wrap">
            <div class="header-logo-badge">⏱️</div>
            <div>
                <h1 class="header-title">
                    時間枠統合カスタム管理コンソール
                    <span class="proto-pill">PROTOTYPE</span>
                </h1>
                <div class="header-sub">
                    基本時間枠（通年）と日別・期間オーバーライドを一体化し、誰でも直感的に把握・制御できる統合ダッシュボード
                </div>
            </div>
        </div>

        <div class="header-controls">
            <!-- 診療科セレクター -->
            <div class="dept-switcher">
                <a href="?dept=PROCT&year=<?= $year ?>&month=<?= $month ?>&date=<?= $selected_date ?>" class="dept-btn <?= $dept === 'PROCT' ? 'active' : '' ?>">🟢 肛門科</a>
                <a href="?dept=GASTRO&year=<?= $year ?>&month=<?= $month ?>&date=<?= $selected_date ?>" class="dept-btn <?= $dept === 'GASTRO' ? 'active' : '' ?>">⚪ 胃腸科</a>
            </div>

            <!-- 年月ナビゲーション -->
            <div class="month-nav">
                <a href="?dept=<?= $dept ?>&year=<?= $prev_year ?>&month=<?= $prev_month ?>&date=<?= sprintf('%04d-%02d-01', $prev_year, $prev_month) ?>" class="month-nav-btn" title="前月">◀</a>
                <span class="month-title"><?= $year ?>年<?= $month ?>月</span>
                <a href="?dept=<?= $dept ?>&year=<?= $next_year ?>&month=<?= $next_month ?>&date=<?= sprintf('%04d-%02d-01', $next_year, $next_month) ?>" class="month-nav-btn" title="翌月">▶</a>
            </div>

            <!-- 🔄 従来の中止データ移行ボタン -->
            <a href="waku_legacy_convert.php?dept=<?= $dept ?>" class="guide-toggle-btn" style="background:#fffbeb; color:#92400e; border-color:#fde68a; font-weight:800; text-decoration:none;" title="旧予約テーブル（※枠なし等）の中止データを新オーバーライドへ一括変換・反映します">
                <span>🔄</span> <span>従来の中止データを移行</span>
                <?php if ($legacy_pending_cnt > 0): ?>
                    <span style="background:#dc2626; color:#fff; font-size:10.5px; padding:1px 7px; border-radius:9999px; margin-left:2px; font-weight:800;"><?= $legacy_pending_cnt ?></span>
                <?php endif; ?>
            </a>

            <!-- 後任事務長向け引き継ぎガイド トグルボタン -->
            <button type="button" class="guide-toggle-btn" onclick="toggleGuidePanel()">
                <span>💡</span> <span>引き継ぎガイド</span>
            </button>

            <!-- 戻るリンク -->
            <a href="month_schedule.php?dept=<?= $dept ?>" style="color:#94a3b8; font-size:12px; text-decoration:none; padding:4px 8px;">カレンダーへ戻る ➔</a>
        </div>
    </header>

    <!-- ── 引き継ぎガイドパネル（開閉式） ── -->
    <div id="guidePanel" class="guide-panel">
        <h4>💡 後任事務長・管理者向け「小野会 予約枠アーキテクチャ」引き継ぎガイド</h4>
        <p>
            当院の内視鏡予約システムは、<strong>「基本マスタ（m2_waku）は一切傷つけず、特定日・期間の変更はオーバーライド（m2_waku_override）で差分適用する」</strong>という二層防御方式を採用しています。これにより、過去の予約データやカルテ連携を100%安全に保護しながら、急な運用変更にも柔軟に対応できます。
        </p>
        <div class="guide-steps">
            <div class="guide-step-card">
                <div class="guide-step-title">🏛️ 1. 基本時間枠マスタ (m2_waku)</div>
                <div class="guide-step-desc">
                    医院の恒久的な時間枠（月曜〜土曜の通年スケジュール）。新年度の大幅改編などで「全日共通の枠構造」を根本から変えたい時だけ触ります。
                </div>
            </div>
            <div class="guide-step-card">
                <div class="guide-step-title">📆 2. 期間オーバーライド (PERIOD)</div>
                <div class="guide-step-desc">
                    「年末年始の休止」「2027年1月〜3月の木曜午後休止」など、一定期間だけ適用したい枠変更。期間が終われば自動で通常枠へ戻ります。
                </div>
            </div>
            <div class="guide-step-card">
                <div class="guide-step-title">⚡ 3. 日別オーバーライド (DATE)</div>
                <div class="guide-step-desc">
                    「機器故障で明日だけ1枠休止」「この日だけ臨時増枠」など、特定日だけのピンポイント調整。カレンダーやポップアップからワンクリックで設定可能。
                </div>
            </div>
        </div>
    </div>

    <?php if ($legacy_pending_cnt > 0): ?>
    <!-- ── 従来の枠なしデータ移行 案内バナー ── -->
    <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1.5px solid #fde68a; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">💡</span>
            <div>
                <strong style="color: #92400e; font-size: 13.5px;">従来の予約テーブルに「※枠なし」として登録された休止データが <?= $legacy_pending_cnt ?>件 あります</strong>
                <div style="font-size: 12px; color: #78350f; margin-top: 2px;">
                    新方式の時間枠オーバーライドへコンバートすると、このカレンダーや予約画面に正式な休止枠（🛑）として正しく可視化・集計されます。
                </div>
            </div>
        </div>
        <a href="waku_legacy_convert.php?dept=<?= $dept ?>" style="background: #d97706; color: #ffffff; font-size: 12.5px; font-weight: 800; padding: 7px 16px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); transition: all 0.15s;">
            <span>🔄</span> <span>今すぐコンバート画面を開く（未移行: <?= $legacy_pending_cnt ?>件）➔</span>
        </a>
    </div>
    <?php endif; ?>

    <!-- ── メイン 2カラム レイアウト ── -->
    <div class="console-main-grid">

        <!-- 左カラム: 月間カレンダー俯瞰マップ -->
        <div class="calendar-card">
            <div class="card-header-row">
                <div class="card-title">
                    <span>📅</span> 月間カレンダー枠構造マップ（<?= $year ?>年<?= $month ?>月）
                </div>
                <div class="legend-row">
                    <span class="legend-item"><span class="legend-dot" style="background:#64748b;"></span> 通常</span>
                    <span class="legend-item"><span class="legend-dot" style="background:#ef4444;"></span> 休止あり</span>
                    <span class="legend-item"><span class="legend-dot" style="background:#10b981;"></span> 臨時枠</span>
                    <span class="legend-item"><span class="legend-dot" style="background:#0284c7;"></span> 期間設定</span>
                </div>
            </div>

            <table class="cal-table">
                <thead>
                    <tr>
                        <th class="cal-th sun">日</th>
                        <th class="cal-th">月</th>
                        <th class="cal-th">火</th>
                        <th class="cal-th">水</th>
                        <th class="cal-th">木</th>
                        <th class="cal-th">金</th>
                        <th class="cal-th sat">土</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cur_day = 1;
                    $day_count = 0;
                    $total_cells = ceil(($first_dow + $days_in_month) / 7) * 7;

                    for ($cell = 0; $cell < $total_cells; $cell++):
                        $dow = $cell % 7;
                        if ($dow === 0) echo "<tr>";

                        if ($cell < $first_dow || $cur_day > $days_in_month):
                            // 前月・翌月の空白セル
                            echo "<td class='cal-cell is-other-month'></td>";
                        else:
                            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $cur_day);
                            $is_selected = ($date_str === $selected_date);
                            $is_today = ($date_str === $today);
                            $dow_cls = ($dow === 0) ? 'sun' : (($dow === 6) ? 'sat' : '');
                            $selected_cls = $is_selected ? 'is-selected' : '';
                            $today_cls = $is_today ? 'is-today' : '';

                            // この日のオーバーライド状況を集計
                            $ovs = $daily_overrides[$date_str] ?? [];
                            $susp_cnt = 0;
                            $extra_cnt = 0;
                            $has_period = false;

                            foreach ($ovs as $o) {
                                if ($o['override_type'] === 'PERIOD') $has_period = true;
                                if (!empty($o['is_suspended'])) $susp_cnt++;
                                if (empty($o['base_waku_id'])) $extra_cnt++;
                            }

                            $res_cnt = $daily_res_totals[$date_str] ?? 0;
                    ?>
                        <td class="cal-cell <?= $dow_cls ?> <?= $selected_cls ?> <?= $today_cls ?>" 
                            onclick="selectDate('<?= $date_str ?>')">
                            <span class="cal-day-num"><?= $cur_day ?></span>
                            
                            <div class="cal-badge-stack">
                                <?php if ($susp_cnt > 0): ?>
                                    <span class="cal-pill pill-suspend" title="休止枠: <?= $susp_cnt ?>件">🛑 休止:<?= $susp_cnt ?></span>
                                <?php endif; ?>
                                <?php if ($extra_cnt > 0): ?>
                                    <span class="cal-pill pill-extra" title="臨時枠: <?= $extra_cnt ?>件">➕ 臨時:<?= $extra_cnt ?></span>
                                <?php endif; ?>
                                <?php if ($has_period): ?>
                                    <span class="cal-pill pill-period" title="期間オーバーライド適用中">📆 期間適用</span>
                                <?php endif; ?>
                                <?php if ($susp_cnt === 0 && $extra_cnt === 0 && !$has_period): ?>
                                    <span class="cal-pill pill-normal">通常(<?= count($base_wakus) ?>枠)</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($res_cnt > 0): ?>
                                <span class="cal-res-count" title="確定患者予約数">予約:<?= $res_cnt ?></span>
                            <?php endif; ?>
                        </td>
                    <?php
                            $cur_day++;
                        endif;

                        if ($dow === 6) echo "</tr>";
                    endfor;
                    ?>
                </tbody>
            </table>
        </div>

        <!-- 右カラム: 枠構造インスペクター ＆ 操作シミュレーター -->
        <?php
        // 選択された日付の枠詳細を計算
        $selected_dow = date('w', strtotime($selected_date));
        $dow_names = ['日', '月', '火', '水', '木', '金', '土'];
        $selected_dow_name = $dow_names[$selected_dow];

        // slot_helper で合成した有効枠を取得
        $effective_slots = getEffectiveWakuList($pdo, $dept, $selected_date);
        $sel_overrides = $daily_overrides[$selected_date] ?? [];
        $sel_res_cnt = $daily_res_totals[$selected_date] ?? 0;
        ?>
        <div class="inspector-card">
            <div class="card-header-row">
                <div class="card-title">
                    <span>🔍</span> 枠構造インスペクター ＆ 操作
                </div>
                <span class="inspect-dept-pill"><?= $dept_name ?></span>
            </div>

            <div class="inspector-date-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <!-- 🌟 小野会日付ピッカー（直接日付選択・西暦和暦切替） -->
                    <?= render_onokai_date_control('protoDateInput', 'date', $selected_date) ?>
                </div>
                <span style="font-size:12px; font-weight:700; color:#475569;">
                    確定予約: <strong style="color:#2563eb;"><?= $sel_res_cnt ?></strong> 件
                </span>
            </div>

            <!-- 3層レイヤーの重なりビジュアル -->
            <div class="layer-structure-box">
                <!-- レイヤー1: 基本枠マスタ (m2_waku) -->
                <div class="layer-box level-1">
                    <div class="layer-title-row">
                        <span class="layer-label"><span>🏛️</span> [階層1] 基本時間枠マスタ (m2_waku)</span>
                        <span class="layer-tag tag-base">通年ベース (<?= count($base_wakus) ?>枠)</span>
                    </div>
                    <div class="slot-pill-list">
                        <?php foreach ($base_wakus as $bw): ?>
                            <span class="slot-chip" title="基本枠ID: <?= $bw['id'] ?>">
                                <?= htmlspecialchars($bw['waku_name'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- レイヤー2: 適用中オーバーライド (m2_waku_override) -->
                <div class="layer-box level-2">
                    <div class="layer-title-row">
                        <span class="layer-label"><span>⚡</span> [階層2] 適用中オーバーライド</span>
                        <span class="layer-tag tag-override"><?= count($sel_overrides) > 0 ? count($sel_overrides) . '件の差分あり' : '差分なし (通常枠)' ?></span>
                    </div>
                    <?php if (empty($sel_overrides)): ?>
                        <div style="font-size:11.5px; color:#64748b;">
                            この日はオーバーライド設定がありません。基本枠がそのまま適用されます。
                        </div>
                    <?php else: ?>
                        <div class="slot-pill-list">
                            <?php foreach ($sel_overrides as $so): ?>
                                <?php
                                $cls = $so['is_suspended'] ? 'is-suspended' : (empty($so['base_waku_id']) ? 'is-extra' : 'is-changed');
                                $prefix = $so['is_suspended'] ? '🚫休止: ' : (empty($so['base_waku_id']) ? '➕臨時: ' : '✏️変更: ');
                                ?>
                                <span class="slot-chip <?= $cls ?>" title="理由: <?= htmlspecialchars($so['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?= $prefix ?><?= htmlspecialchars($so['display_waku_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <small>(<?= $so['override_type'] ?>)</small>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- レイヤー3: 最終合成結果（現場で有効な時間枠） -->
                <div class="layer-box level-3">
                    <div class="layer-title-row">
                        <span class="layer-label"><span>🖥️</span> [階層3] 最終有効枠（カレンダー・予約画面の表示）</span>
                        <span class="layer-tag tag-result">現場の稼働枠 (<?= count($effective_slots) ?>枠)</span>
                    </div>
                    <div class="slot-pill-list">
                        <?php foreach ($effective_slots as $es): ?>
                            <?php
                            $slot_cls = $es['is_suspended'] ? 'is-suspended' : ($es['is_override'] ? (empty($es['base_waku_id']) ? 'is-extra' : 'is-changed') : '');
                            $res_c = $slot_res_counts[$selected_date][$es['waku_name']] ?? 0;
                            ?>
                            <span class="slot-chip <?= $slot_cls ?>">
                                <?= $es['is_suspended'] ? '🚫 ' : '' ?><?= htmlspecialchars($es['waku_name'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($res_c > 0): ?>
                                    <span style="background:#2563eb; color:#fff; border-radius:10px; padding:0 5px; font-size:10px;"><?= $res_c ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 安全インスペクター警告 -->
            <?php if ($sel_res_cnt > 0): ?>
                <div class="safety-alert-box">
                    <span>🛡️</span>
                    <div>
                        <strong>予約保護ガード作動中:</strong> この日には確定患者予約が <?= $sel_res_cnt ?> 件存在します。予約が入っている枠は休止にできません（誤休止防止ブロック）。
                    </div>
                </div>
            <?php endif; ?>

            <!-- クイック操作ボタンエリア -->
            <div class="inspect-actions">
                <button type="button" class="btn-act btn-act-modal" onclick="openWakuOverrideModal('<?= $selected_date ?>', '<?= $dept ?>')">
                    <span>⚙️</span> この日の枠簡単設定ポップアップを開く
                </button>
                <button type="button" class="btn-act btn-act-extra" onclick="openWakuOverrideModal('<?= $selected_date ?>', '<?= $dept ?>', 'manage')">
                    <span>📆</span> 期間オーバーライド新規登録
                </button>
                <a href="slot_master_maintenance.php?tab=waku&dept=<?= $dept ?>" class="btn-act btn-act-master" target="_blank">
                    <span>🏛️</span> [階層1] 基本時間枠マスタ（通年設定）の保守画面を開く ↗
                </a>
            </div>
        </div>

    </div>

    <!-- ── 下部セクション: 現在稼働中の「期間指定オーバーライド」一覧 ── -->
    <div class="period-card">
        <div class="card-header-row">
            <div class="card-title">
                <span>📆</span> 現在設定されている「期間オーバーライド」一覧（<?= $dept_name ?>）
            </div>
            <button type="button" class="btn-act btn-act-extra" style="font-size:11.5px; padding:4px 10px; cursor:pointer;" onclick="openWakuOverrideModal('<?= $selected_date ?>', '<?= $dept ?>', 'manage')">
                ＋ 期間設定を追加
            </button>
        </div>

        <?php if (empty($period_overrides)): ?>
            <div style="font-size:12.5px; color:#64748b; padding:20px 0; text-align:center;">
                現在、当月に適用中の期間オーバーライド（年末年始休止・シーズン枠など）はありません。
            </div>
        <?php else: ?>
            <table class="period-table">
                <thead>
                    <tr>
                        <th>適用期間</th>
                        <th>対象枠</th>
                        <th>設定種別</th>
                        <th>理由 / メモ</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($period_overrides as $po): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($po['start_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                                〜 
                                <strong><?= htmlspecialchars($po['end_date'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($po['display_waku_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td>
                                <?php if ($po['is_suspended']): ?>
                                    <span class="cal-pill pill-suspend">🛑 休止</span>
                                <?php else: ?>
                                    <span class="cal-pill pill-period">✏️ 枠名変更</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($po['reason'] ?: '未指定', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <button type="button" class="btn-act btn-act-suspend" style="padding:3px 8px; font-size:11px;" onclick="deleteOverride(<?= $po['override_id'] ?>)">
                                    解除
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        function toggleGuidePanel() {
            const panel = document.getElementById('guidePanel');
            if (panel) {
                panel.classList.toggle('active');
            }
        }

        function selectDate(dateStr) {
            const url = new URL(window.location.href);
            url.searchParams.set('date', dateStr);
            window.location.href = url.toString();
        }

        function deleteOverride(overrideId) {
            if (!confirm(`期間オーバーライド（ID: ${overrideId}）を解除しますか？`)) return;

            const fd = new FormData();
            fd.append('action', 'delete_override');
            fd.append('override_id', overrideId);

            fetch('action_waku_override.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    alert('期間オーバーライドを解除しました');
                    window.location.reload();
                } else {
                    alert('解除失敗: ' + (data.error || 'エラー'));
                }
            })
            .catch(err => {
                alert('通信エラー: ' + err.toString());
            });
        }
    // 小野会日付ピッカー（インスペクター日付直接選択）
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.bindControl('#protoDateInput', {
            onChange: function(dates, dateStr) {
                if (dateStr && dateStr !== '<?= $selected_date ?>') {
                    const y = dateStr.substring(0, 4);
                    const m = parseInt(dateStr.substring(5, 7), 10);
                    window.location.href = `?dept=<?= $dept ?>&year=${y}&month=${m}&date=${dateStr}`;
                }
            }
        });
    }
    </script>
<?php require_once __DIR__ . '/waku_override_modal_inc.php'; ?>
</body>
</html>
