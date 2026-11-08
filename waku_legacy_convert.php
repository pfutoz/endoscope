<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\waku_legacy_convert.php
 * ファイル名: waku_legacy_convert.php
 * 概要: 従来枠なし・休止データ コンバーター
 * 生成日時: 2026-10-23 10:55
 * 修正履歴:
 *   - 2026-10-23: 新規作成。従来の予約テーブル（t_endoscopy_schedule）に埋め込まれていた「※枠なし」「※仮予約」などのダミー休止データを、新方式の時間枠オーバーライド（m2_waku_override）へ安全に一括/個別コンバートするツール画面。
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/env_helper.php';
require_once __DIR__ . '/lib/master_audit_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// パラメータ取得
$filter_dept = $_GET['dept'] ?? 'ALL'; // ALL, GASTRO, PROCT
$filter_status = $_GET['status'] ?? 'pending'; // all, pending, converted, unmatched
$filter_period = $_GET['period'] ?? 'future'; // all, future (本日以降), current_month
$message = $_GET['msg'] ?? '';
$error_msg = $_GET['err'] ?? '';

// ============================================================
// POST処理: コンバート実行
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $selected_ids = $_POST['schedule_ids'] ?? [];
    $cancel_original = !empty($_POST['cancel_original']);
    $default_reason = trim($_POST['default_reason'] ?? '※枠なし (旧予約より移行)');

    if ($action === 'convert' && !empty($selected_ids)) {
        try {
            $pdo->beginTransaction();
            $converted_count = 0;
            $skipped_count = 0;

            // 対象レコードの取得
            $in_clause = implode(',', array_map('intval', $selected_ids));
            $sql = "
                SELECT 
                    s.schedule_id,
                    s.exam_date::text AS exam_date,
                    s.department_code,
                    s.scheduled_time,
                    s.patient_name,
                    s.remarks,
                    w.id AS base_waku_id,
                    w.waku_name AS matched_waku_name,
                    w.group_code,
                    w.display_order
                FROM t_endoscopy_schedule s
                LEFT JOIN m2_waku w ON w.department_code = s.department_code 
                                    AND w.waku_name = s.scheduled_time 
                                    AND w.is_active = TRUE
                WHERE s.schedule_id IN ({$in_clause})
            ";
            $targets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $stmt_check = $pdo->prepare("
                SELECT override_id FROM m2_waku_override
                WHERE department_code = :dept
                  AND target_date = :target_date
                  AND base_waku_id = :base_waku_id
                  AND override_type = 'DATE'
                  AND is_active = TRUE
            ");

            $stmt_insert = $pdo->prepare("
                INSERT INTO m2_waku_override (
                    department_code, base_waku_id, override_type, target_date,
                    display_waku_name, group_code, display_order, is_suspended, reason, is_active
                ) VALUES (
                    :dept, :base_waku_id, 'DATE', :target_date,
                    :waku_name, :group_code, :display_order, TRUE, :reason, TRUE
                )
            ");

            $stmt_cancel_sched = $pdo->prepare("
                UPDATE t_endoscopy_schedule 
                SET is_canceled = TRUE,
                    remarks = CASE 
                        WHEN remarks IS NULL OR remarks = '' THEN '[新・枠休止オーバーライドへ移行済]'
                        WHEN remarks NOT LIKE '%[新・枠休止オーバーライドへ移行済]%' THEN remarks || ' [新・枠休止オーバーライドへ移行済]'
                        ELSE remarks 
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE schedule_id = :id
            ");

            foreach ($targets as $row) {
                if (empty($row['base_waku_id'])) {
                    $skipped_count++;
                    continue; // マスタ不一致はスキップ
                }

                // 既に休止設定があるか確認
                $stmt_check->execute([
                    ':dept' => $row['department_code'],
                    ':target_date' => $row['exam_date'],
                    ':base_waku_id' => $row['base_waku_id']
                ]);
                $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    $reason = !empty(trim(strip_tags($row['remarks'] ?? ''))) ? trim(strip_tags($row['remarks'])) : $default_reason;
                    if (mb_strlen($reason) > 50) {
                        $reason = mb_substr($reason, 0, 50);
                    }

                    $stmt_insert->execute([
                        ':dept' => $row['department_code'],
                        ':base_waku_id' => $row['base_waku_id'],
                        ':target_date' => $row['exam_date'],
                        ':waku_name' => $row['matched_waku_name'],
                        ':group_code' => $row['group_code'],
                        ':display_order' => $row['display_order'],
                        ':reason' => $reason
                    ]);
                }

                // 元の予約レコードをキャンセル扱いにする
                if ($cancel_original) {
                    $stmt_cancel_sched->execute([':id' => $row['schedule_id']]);
                }

                $converted_count++;
            }

            $pdo->commit();

            $msg = "{$converted_count} 件の中止データを新方式（m2_waku_override）へコンバートしました！";
            if ($skipped_count > 0) {
                $msg .= " （マスタ不一致 {$skipped_count} 件はスキップ）";
            }
            header("Location: waku_legacy_convert.php?dept={$filter_dept}&status={$filter_status}&period={$filter_period}&msg=" . urlencode($msg));
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $err = "コンバート処理中にエラーが発生しました: " . $e->getMessage();
            header("Location: waku_legacy_convert.php?dept={$filter_dept}&status={$filter_status}&period={$filter_period}&err=" . urlencode($err));
            exit;
        }
    }
}

// ============================================================
// データ検索クエリの構築
// ============================================================
$today = date('Y-m-d');
$month_start = date('Y-m-01');

$where_clauses = [
    "(s.patient_name = '※枠なし' OR (s.patient_name = '※仮予約' AND (s.remarks ~ '枠なし' OR s.remarks ~ '休止' OR s.remarks ~ '休診')) OR s.remarks ~ '＊＊＊🚫枠なし🚫＊＊＊')"
];
$params = [];

if ($filter_dept !== 'ALL') {
    $where_clauses[] = "s.department_code = :dept";
    $params[':dept'] = $filter_dept;
}

if ($filter_period === 'future') {
    $where_clauses[] = "s.exam_date >= :today";
    $params[':today'] = $today;
} elseif ($filter_period === 'current_month') {
    $where_clauses[] = "s.exam_date >= :m_start";
    $params[':m_start'] = $month_start;
}

$where_sql = implode(' AND ', $where_clauses);

$sql_all = "
    SELECT 
        s.schedule_id,
        s.exam_date::text AS exam_date,
        s.department_code,
        s.scheduled_time,
        s.waku_no,
        s.patient_id,
        s.patient_name,
        s.remarks,
        s.is_canceled,
        s.created_at,
        w.id AS base_waku_id,
        w.waku_name AS matched_waku_name,
        w.group_code,
        w.display_order,
        ov.override_id,
        ov.is_suspended AS already_suspended
    FROM t_endoscopy_schedule s
    LEFT JOIN m2_waku w ON w.department_code = s.department_code 
                        AND w.waku_name = s.scheduled_time 
                        AND w.is_active = TRUE
    LEFT JOIN m2_waku_override ov ON ov.department_code = s.department_code
                                  AND ov.target_date = s.exam_date
                                  AND ov.base_waku_id = w.id
                                  AND ov.override_type = 'DATE'
                                  AND ov.is_suspended = TRUE
                                  AND ov.is_active = TRUE
    WHERE {$where_sql}
    ORDER BY s.exam_date ASC, s.department_code, s.scheduled_time
";

$stmt = $pdo->prepare($sql_all);
$stmt->execute($params);
$all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 集計
$total_count = count($all_records);
$pending_count = 0;
$converted_count = 0;
$unmatched_count = 0;

$filtered_records = [];

foreach ($all_records as $r) {
    $is_converted = !empty($r['override_id']);
    $is_unmatched = empty($r['base_waku_id']);
    $is_pending = (!$is_converted && !$is_unmatched);

    if ($is_converted) $converted_count++;
    if ($is_unmatched) $unmatched_count++;
    if ($is_pending) $pending_count++;

    // フィルタ適用
    if ($filter_status === 'pending' && !$is_pending) continue;
    if ($filter_status === 'converted' && !$is_converted) continue;
    if ($filter_status === 'unmatched' && !$is_unmatched) continue;

    $filtered_records[] = $r;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>従来枠なし・休止データ コンバーター - 医療法人小野会</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+JP:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-sub: #64748b;
        }
        body {
            font-family: 'Inter', 'Noto Sans JP', sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 24px 20px 60px 20px;
        }
        .converter-header {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .converter-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 6px 0;
        }
        .converter-sub {
            font-size: 13px;
            color: var(--text-sub);
            margin: 0;
            line-height: 1.5;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-nav {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }
        .btn-nav:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        /* 統計カード */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-card.pending {
            border-left: 4px solid #f59e0b;
            background: #fffdf5;
        }
        .stat-card.converted {
            border-left: 4px solid #10b981;
            background: #f0fdf4;
        }
        .stat-card.unmatched {
            border-left: 4px solid #ef4444;
            background: #fef2f2;
        }
        .stat-card.total {
            border-left: 4px solid #3b82f6;
        }
        .stat-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-sub);
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: baseline;
            gap: 4px;
        }
        .stat-unit {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-sub);
        }

        /* フィルターバー */
        .filter-panel {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-label {
            font-size: 12.5px;
            font-weight: 700;
            color: #475569;
        }
        .select-filter {
            font-size: 13px;
            font-weight: 600;
            padding: 6px 12px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            background: #ffffff;
            color: #0f172a;
        }

        /* アクションバー */
        .action-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1.5px solid #93c5fd;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
        }
        .action-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            font-weight: 700;
            color: #1e40af;
        }
        .action-controls {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .btn-convert-all {
            background: #2563eb;
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
            padding: 9px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(37,99,235,0.25);
            transition: all 0.15s;
        }
        .btn-convert-all:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
        .btn-convert-all:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* テーブル */
        .table-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .convert-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .convert-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 800;
            text-align: left;
            padding: 12px 14px;
            border-bottom: 1.5px solid var(--border);
            white-space: nowrap;
        }
        .convert-table td {
            padding: 11px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .convert-table tr:hover {
            background: #f8fafc;
        }
        .convert-table tr.is-converted {
            background: #fcfdfd;
            opacity: 0.8;
        }
        .convert-table tr.is-unmatched {
            background: #fffbfa;
        }

        /* バッジ */
        .badge {
            font-size: 11px;
            font-weight: 800;
            padding: 2.5px 8px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .badge-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-converted { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-unmatched { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .dept-pill {
            font-size: 11px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 4px;
        }
        .dept-proct { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .dept-gastro { background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; }

        .msg-box {
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .msg-success { background: #dcfce7; border: 1.5px solid #86efac; color: #166534; }
        .msg-error { background: #fee2e2; border: 1.5px solid #fca5a5; color: #991b1b; }
    </style>
</head>
<body <?= getBodyEnvClass() ?>>
<?= renderLocalEnvBanner() ?>

<div class="container">

    <!-- ── ヘッダー ── -->
    <div class="converter-header">
        <div>
            <h1 class="converter-title">
                <span>🔄</span> 従来枠なし・休止データ コンバーター
            </h1>
            <p class="converter-sub">
                過去の予約テーブル（<code>t_endoscopy_schedule</code>）にダミー予約として登録されていた「※枠なし」「※仮予約」等の休止情報を検出し、<br>
                新方式の時間枠オーバーライド（<code>m2_waku_override</code>）へ安全・確実に一括コンバートします。
            </p>
        </div>
        <div class="header-actions">
            <a href="waku_console_proto.php?dept=<?= ($filter_dept === 'GASTRO' ? 'GASTRO' : 'PROCT') ?>" class="btn-nav">
                <span>⏱️</span> 時間枠統合コンソールへ戻る
            </a>
            <a href="month_schedule.php" class="btn-nav">
                <span>📅</span> カレンダーへ戻る
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="msg-box msg-success">
            <span>🎉</span> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="msg-box msg-error">
            <span>❌</span> <?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ── 統計サマリー ── -->
    <div class="stats-grid">
        <div class="stat-card pending">
            <div class="stat-label">⚡ 即座に移行可能（未移行）</div>
            <div class="stat-value" style="color:#d97706;">
                <?= $pending_count ?><span class="stat-unit">件</span>
            </div>
        </div>
        <div class="stat-card converted">
            <div class="stat-label">✅ 移行完了済み</div>
            <div class="stat-value" style="color:#16a34a;">
                <?= $converted_count ?><span class="stat-unit">件</span>
            </div>
        </div>
        <div class="stat-card unmatched">
            <div class="stat-label">⚠️ マスタ不一致（旧枠名など）</div>
            <div class="stat-value" style="color:#dc2626;">
                <?= $unmatched_count ?><span class="stat-unit">件</span>
            </div>
        </div>
        <div class="stat-card total">
            <div class="stat-label">📊 検出総件数</div>
            <div class="stat-value" style="color:#2563eb;">
                <?= $total_count ?><span class="stat-unit">件</span>
            </div>
        </div>
    </div>

    <!-- ── フィルターパネル ── -->
    <div class="filter-panel">
        <div class="filter-group">
            <span class="filter-label">診療科:</span>
            <select class="select-filter" onchange="applyFilter('dept', this.value)">
                <option value="ALL" <?= $filter_dept === 'ALL' ? 'selected' : '' ?>>すべての診療科</option>
                <option value="PROCT" <?= $filter_dept === 'PROCT' ? 'selected' : '' ?>>🟢 肛門科</option>
                <option value="GASTRO" <?= $filter_dept === 'GASTRO' ? 'selected' : '' ?>>⚪ 胃腸科</option>
            </select>

            <span class="filter-label" style="margin-left: 12px;">対象期間:</span>
            <select class="select-filter" onchange="applyFilter('period', this.value)">
                <option value="future" <?= $filter_period === 'future' ? 'selected' : '' ?>>本日以降（おすすめ）</option>
                <option value="current_month" <?= $filter_period === 'current_month' ? 'selected' : '' ?>>今月以降（<?= date('Y年n月') ?>〜）</option>
                <option value="all" <?= $filter_period === 'all' ? 'selected' : '' ?>>全期間（過去分すべて）</option>
            </select>

            <span class="filter-label" style="margin-left: 12px;">移行状態:</span>
            <select class="select-filter" onchange="applyFilter('status', this.value)">
                <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>未移行のみ (<?= $pending_count ?>件)</option>
                <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>すべて (<?= $total_count ?>件)</option>
                <option value="converted" <?= $filter_status === 'converted' ? 'selected' : '' ?>>移行完了済みのみ (<?= $converted_count ?>件)</option>
                <option value="unmatched" <?= $filter_status === 'unmatched' ? 'selected' : '' ?>>マスタ不一致のみ (<?= $unmatched_count ?>件)</option>
            </select>
        </div>
        <div style="font-size:12.5px; color:#64748b; font-weight:600;">
            現在表示中: <strong style="color:#0f172a;"><?= count($filtered_records) ?></strong> 件
        </div>
    </div>

    <!-- ── 一括コンバート実行フォーム ── -->
    <form method="POST" id="convertForm" action="waku_legacy_convert.php?dept=<?= $filter_dept ?>&status=<?= $filter_status ?>&period=<?= $filter_period ?>">
        <input type="hidden" name="action" value="convert">

        <div class="action-banner">
            <div class="action-info">
                <span>⚡</span>
                <span>チェックした項目を新方式オーバーライド（休止）へ一括コンバートします</span>
            </div>
            <div class="action-controls">
                <label style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; color:#1e3a8a; cursor:pointer;">
                    <input type="checkbox" name="cancel_original" value="1" checked style="width:16px; height:16px; cursor:pointer;">
                    <span>元のダミー予約レコードを取消（キャンセル扱い）にする（推奨）</span>
                </label>
                <button type="submit" class="btn-convert-all" id="btnSubmit" onclick="return confirmConvert();" <?= ($pending_count === 0 && empty($filtered_records)) ? 'disabled' : '' ?>>
                    <span>🚀</span> <span>選択項目をコンバート実行 (<span id="selectedCount">0</span>件)</span>
                </button>
            </div>
        </div>

        <!-- ── 一覧テーブル ── -->
        <div class="table-card">
            <table class="convert-table">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="checkAll" onclick="toggleCheckAll(this.checked)" style="width:16px; height:16px; cursor:pointer;">
                        </th>
                        <th style="width: 90px;">状態</th>
                        <th style="width: 110px;">検査日</th>
                        <th style="width: 80px;">科</th>
                        <th style="width: 130px;">時間枠名 (旧)</th>
                        <th>マスタ照合状況</th>
                        <th>旧予約データ (患者名 / 備考)</th>
                        <th style="width: 180px;">移行先設定</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($filtered_records)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 36px; color: #94a3b8; font-weight: 600;">
                                条件に一致する枠なし・中止データはありません。
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filtered_records as $r): 
                            $is_converted = !empty($r['override_id']);
                            $is_unmatched = empty($r['base_waku_id']);
                            $is_pending = (!$is_converted && !$is_unmatched);
                            
                            $row_cls = $is_converted ? 'is-converted' : ($is_unmatched ? 'is-unmatched' : '');
                            $dow = date('w', strtotime($r['exam_date']));
                            $dow_names = ['日', '月', '火', '水', '木', '金', '土'];
                            $dow_label = $dow_names[$dow];
                            $dow_color = ($dow === 0) ? '#ef4444' : (($dow === 6) ? '#2563eb' : '#475569');
                        ?>
                            <tr class="<?= $row_cls ?>">
                                <td style="text-align: center;">
                                    <?php if ($is_pending): ?>
                                        <input type="checkbox" name="schedule_ids[]" value="<?= $r['schedule_id'] ?>" class="row-checkbox" onchange="updateSelectedCount()" checked style="width:16px; height:16px; cursor:pointer;">
                                    <?php else: ?>
                                        <input type="checkbox" disabled style="opacity: 0.3;">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_converted): ?>
                                        <span class="badge badge-converted">✅ 移行済</span>
                                    <?php elseif ($is_unmatched): ?>
                                        <span class="badge badge-unmatched">⚠️ 不一致</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">⚡ 未移行</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 700; white-space: nowrap;">
                                    <?= htmlspecialchars($r['exam_date'], ENT_QUOTES, 'UTF-8') ?>
                                    <span style="color: <?= $dow_color ?>;">(<?= $dow_label ?>)</span>
                                </td>
                                <td>
                                    <?php if ($r['department_code'] === 'PROCT'): ?>
                                        <span class="dept-pill dept-proct">肛門科</span>
                                    <?php else: ?>
                                        <span class="dept-pill dept-gastro">胃腸科</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 800; color: #1e293b;">
                                    <?= htmlspecialchars($r['scheduled_time'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['base_waku_id'])): ?>
                                        <span style="font-weight: 700; color: #047857; display: flex; align-items: center; gap: 4px;">
                                            <span>🟢 一致:</span>
                                            <code><?= htmlspecialchars($r['matched_waku_name'], ENT_QUOTES, 'UTF-8') ?></code>
                                            <span style="font-size: 11px; color: #64748b;">(<?= $r['group_code'] ?> / ID:<?= $r['base_waku_id'] ?>)</span>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #b91c1c; font-size: 12px; font-weight: 600;">
                                            ❌ 基本枠マスタに同名枠なし
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: #334155;">
                                        <?= htmlspecialchars($r['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($r['is_canceled']): ?>
                                            <span style="font-size: 10.5px; color: #94a3b8; font-weight: normal;">(元取消済)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($r['remarks'])): ?>
                                        <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                            <?= htmlspecialchars(strip_tags($r['remarks']), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_converted): ?>
                                        <span style="font-size: 12px; color: #15803d; font-weight: 700;">
                                            オーバーライド反映済
                                        </span>
                                    <?php elseif ($is_pending): ?>
                                        <span style="font-size: 12px; color: #d97706; font-weight: 700;">
                                            🛑 休止 (CANCEL) 登録
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size: 11.5px; color: #94a3b8;">
                                            移行対象外
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

</div>

<script>
function applyFilter(key, val) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, val);
    window.location.href = url.toString();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;
    const countEl = document.getElementById('selectedCount');
    if (countEl) countEl.textContent = count;
    
    const btn = document.getElementById('btnSubmit');
    if (btn) {
        btn.disabled = (count === 0);
    }
}

function toggleCheckAll(checked) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => { cb.checked = checked; });
    updateSelectedCount();
}

function confirmConvert() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;
    if (count === 0) {
        alert('コンバート対象が選択されていません。');
        return false;
    }
    return confirm(`選択された ${count} 件の中止データを新方式の時間枠オーバーライドへコンバートしますか？\n（元の予約レコードは安全にキャンセル扱いとなります）`);
}

document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
});
</script>
<script src="assets/js/env_detector.js"></script>
</body>
</html>
