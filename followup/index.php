<?php
/**
 * 設置パス: /endoscope/followup/index.php
 * ファイル名: index.php
 * 概要: RS-Base 検査実績タイムライン・キャンバス連携ポータル
 * 生成日時: 2026-10-24 23:10:00 JST
 * 
 * 修正履歴:
 *   - 2026-08-17: 旧フォローアップ一覧画面として新規作成
 *   - 2026-10-24: 【内容全面刷新・RS-Baseキャンバス連携ポータル化】
 *       1. 実運用のなかった旧フォローアップ（次回受診予定・シート印刷・t_followup）を完全撤退
 *       2. RS-Base「検査タイムライン・キャンバス (canvas_view.php)」と連携した検査実績ポータルへ刷新
 *       3. 患者番号・氏名クイック検索バーから、ワンクリックで該当患者のタイムライン（内視鏡ES／エコーUS／全件ALL）を別タブ起動
 *       4. 当日および直近の予約患者一覧からダイレクトにキャンバスを起動できるクイックアクセスリストを装備
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/date_helper.php';
require_once __DIR__ . '/../lib/canvas_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// 検索パラメータ
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$dept = isset($_GET['dept']) && in_array($_GET['dept'], ['PROCT', 'GASTRO'], true) ? $_GET['dept'] : 'PROCT';

// 予約テーブル（t_endoscopy_schedule）から患者一覧を取得
$sql = "
    SELECT 
        schedule_id,
        exam_date,
        scheduled_time,
        patient_id,
        patient_name,
        patient_kana,
        exam_type,
        doctor_name,
        department_code
    FROM t_endoscopy_schedule
    WHERE is_canceled = FALSE
      AND patient_name != '※枠なし'
      AND patient_id IS NOT NULL
      AND TRIM(patient_id) != ''
      AND TRIM(patient_id) != '---'
";

$params = [];

if ($search_query !== '') {
    $sql .= " AND (patient_id LIKE :q OR patient_name LIKE :q OR patient_kana LIKE :q)";
    $params[':q'] = '%' . $search_query . '%';
} else {
    // 検索クエリがない場合は対象日（既定: 今日）の前後3日を表示
    $sql .= " AND exam_date BETWEEN :date_start AND :date_end";
    $dt_cur = new DateTime($search_date);
    $dt_start = (clone $dt_cur)->modify('-3 days')->format('Y-m-d');
    $dt_end = (clone $dt_cur)->modify('+3 days')->format('Y-m-d');
    $params[':date_start'] = $dt_start;
    $params[':date_end'] = $dt_end;
}

$sql .= " ORDER BY exam_date DESC, scheduled_time ASC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_count = count($reservations);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>検査実績タイムライン・キャンバス - 内視鏡予約システム</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --surface: #ffffff;
            --background: #f8fafc;
            --text-main: #0f172a;
            --text-sub: #64748b;
            --border: #e2e8f0;
            --radius: 8px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--background);
            color: var(--text-main);
            padding: 20px;
            font-size: 13px;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
        }
        .header-bar {
            background: #ffffff;
            padding: 16px 20px;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }
        .header-title-group h1 {
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header-title-group p {
            font-size: 12px;
            color: var(--text-sub);
            margin-top: 3px;
        }
        .header-nav {
            display: flex;
            gap: 8px;
        }
        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: #334155;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        .nav-btn:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .nav-btn.primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .nav-btn.primary:hover {
            background: var(--primary-hover);
        }

        /* 検索パネル */
        .search-panel {
            background: #ffffff;
            padding: 16px 20px;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 8px 12px;
            font-size: 13px;
            border: 1px solid var(--border);
            border-radius: 6px;
            outline: none;
            transition: border-color 0.15s;
        }
        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(37,99,235,0.15);
        }
        .search-btn {
            padding: 8px 16px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .search-btn:hover {
            background: var(--primary-hover);
        }
        .direct-open-box {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: var(--text-sub);
        }
        .direct-open-box input {
            width: 140px;
            padding: 5px 8px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 12px;
        }

        /* 一覧テーブル */
        .list-card {
            background: #ffffff;
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .list-header {
            padding: 12px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .list-header h2 {
            font-size: 14px;
            font-weight: 700;
            color: #334155;
        }
        .count-badge {
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-main);
            vertical-align: middle;
        }
        tr:hover {
            background: #f8fafc;
        }
        .exam-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #ffffff;
            background: #475569;
        }
        .exam-gif { background: #2563eb; }
        .exam-tcf { background: #16a34a; }
        .exam-echo { background: #0891b2; }
        .exam-poly { background: #9333ea; }
        .exam-w { background: #d97706; }

        .btn-launch-group {
            display: inline-flex;
            gap: 4px;
        }
        .btn-launch {
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 11.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            border: 1px solid transparent;
            transition: all 0.15s;
        }
        .btn-launch-es {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }
        .btn-launch-es:hover {
            background: #dbeafe;
        }
        .btn-launch-us {
            background: #ecfeff;
            color: #0e7490;
            border-color: #a5f3fc;
        }
        .btn-launch-us:hover {
            background: #cffafe;
        }
        .btn-launch-all {
            background: #f1f5f9;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn-launch-all:hover {
            background: #e2e8f0;
        }
        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-sub);
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="container">

    <!-- ── ヘッダー ── -->
    <div class="header-bar">
        <div class="header-title-group">
            <h1>🗺️ 検査実績タイムライン・キャンバス</h1>
            <p>RS-Base 検査タイムライン（JSON Canvas v1.0）連携ポータル</p>
        </div>
        <div class="header-nav">
            <a href="../schedule_note.php?dept=<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>" class="nav-btn primary">
                📖 デイリーノート
            </a>
            <a href="../month_schedule.php?dept=<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>" class="nav-btn">
                📅 カレンダー表示
            </a>
            <a href="../index.php" class="nav-btn">
                🏠 ホーム
            </a>
        </div>
    </div>

    <!-- ── 検索・ダイレクト起動パネル ── -->
    <div class="search-panel">
        <form method="GET" action="index.php" class="search-form">
            <input type="hidden" name="dept" value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>">
            <input type="text" name="q" class="search-input" value="<?= htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8') ?>" placeholder="患者No、患者氏名、カナで検索..." autofocus>
            <button type="submit" class="search-btn">🔍 検索</button>
            <?php if ($search_query !== ''): ?>
                <a href="index.php?dept=<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>" class="nav-btn">クリア</a>
            <?php endif; ?>
        </form>

        <div class="direct-open-box">
            <span>⚡ 患者No直接指定起動:</span>
            <input type="text" id="directPidInput" placeholder="患者No入力" onkeydown="if(event.key==='Enter'){openDirectCanvas('ES');}">
            <button type="button" class="btn-launch btn-launch-es" onclick="openDirectCanvas('ES')">🗺️ 内視鏡(ES)</button>
            <button type="button" class="btn-launch btn-launch-us" onclick="openDirectCanvas('US')">🟠 エコー(US)</button>
            <button type="button" class="btn-launch btn-launch-all" onclick="openDirectCanvas('ALL')">🌐 全検査(ALL)</button>
        </div>
    </div>

    <!-- ── 一覧リスト ── -->
    <div class="list-card">
        <div class="list-header">
            <h2>
                <?= $search_query !== '' ? '🔍 検索結果' : '📋 直近の予約患者一覧 (前後3日間)' ?>
            </h2>
            <span class="count-badge"><?= $total_count ?> 件</span>
        </div>

        <?php if (empty($reservations)): ?>
            <div class="empty-state">
                📭 該当する患者データは見つかりませんでした
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 100px;">検査日</th>
                        <th style="width: 90px;">時間枠</th>
                        <th style="width: 90px;">患者No</th>
                        <th style="width: 160px;">患者名</th>
                        <th style="width: 90px;">種別</th>
                        <th style="width: 90px;">担当医師</th>
                        <th>キャンバス起動（別タブ）</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservations as $r): 
                    $pid = (string)($r['patient_id'] ?? '');
                    $etype = (string)($r['exam_type'] ?? '');
                    $auto_modality = getCanvasModality($etype);
                    $cls = 'exam-badge';
                    if (strpos($etype, 'GIF') !== false) $cls .= ' exam-gif';
                    elseif (strpos($etype, 'TCF') !== false) $cls .= ' exam-tcf';
                    elseif (strpos($etype, 'エコー') !== false || strpos($etype, 'ECHO') !== false) $cls .= ' exam-echo';
                    elseif (strpos($etype, 'ポリペク') !== false) $cls .= ' exam-poly';
                    elseif (strpos($etype, 'W') !== false) $cls .= ' exam-w';
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string)$r['exam_date'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td><?= htmlspecialchars((string)($r['scheduled_time'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <strong style="color: #2563eb;"><?= htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') ?></strong>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars((string)($r['patient_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($r['patient_kana'])): ?>
                                <span style="font-size: 10px; color: #64748b;">(<?= htmlspecialchars((string)$r['patient_kana'], ENT_QUOTES, 'UTF-8') ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= $cls ?>"><?= htmlspecialchars($etype, ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td><?= htmlspecialchars((string)($r['doctor_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div class="btn-launch-group">
                                <!-- 自動判定起動（主ボタン） -->
                                <a href="<?= htmlspecialchars(getCanvasUrl($pid, $etype), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-launch btn-launch-es" title="予約種別(<?= $auto_modality ?>)のキャンバスを開く">
                                    🗺️ 実績 (<?= $auto_modality ?>)
                                </a>
                                <!-- 全件表示 -->
                                <a href="<?= htmlspecialchars(getCanvasUrl($pid, 'ALL'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-launch btn-launch-all" title="全検査のキャンバスを開く">
                                    全件(ALL)
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<script>
function openDirectCanvas(modality) {
    const input = document.getElementById('directPidInput');
    const pid = input ? input.value.trim() : '';
    if (!pid) {
        alert('患者Noを入力してください');
        if (input) input.focus();
        return;
    }
    const targetUrl = `../../rsbase/canvas_view.php?patient_no=${encodeURIComponent(pid)}&modality=${encodeURIComponent(modality)}&limit=5`;
    window.open(targetUrl, '_blank');
}
</script>
</body>
</html>