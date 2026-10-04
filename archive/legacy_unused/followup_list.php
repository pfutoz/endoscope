<?php
/**
 * ファイル名: followup_list.php
 * 設置パス: /endoscope/followup_list.php
 * 生成日時: 2026-08-17 17:00:00 JST
 * 
 * フォローアップ一覧画面
 * 
 * 機能:
 *   - フォローアップデータの一覧表示（テーブル形式）
 *   - 患者名・来院日・目的による検索フィルタ
 *   - 新規登録・編集・印刷・削除の各操作ボタン
 *   - 来院目的に応じたバッジ表示
 *   - 検査種別タグ表示（GIF/TCF/エコー/ポリペク/W）
 *   - 次回来院日の表示（next_visit_display優先、なければnext_visit_dateから生成）
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成（たたき台）
 *   - 2026-08-17: 検索フィルタを実装
 *   - 2026-08-17: 来院目的バッジを追加
 *   - 2026-08-17: 検査種別タグを追加
 *   - 2026-08-17: 日付表示ロジックを修正（next_visit_display優先、なければnext_visit_dateから生成）
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// 検索パラメータ
$search_name = isset($_GET['name']) ? trim($_GET['name']) : '';
$search_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$search_date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$search_purpose = isset($_GET['purpose']) ? trim($_GET['purpose']) : '';

// 一覧取得
$sql = "SELECT * FROM t_followup WHERE 1=1";
$params = [];

if ($search_name !== '') {
    $sql .= " AND patient_name LIKE :name";
    $params[':name'] = '%' . $search_name . '%';
}
if ($search_date_from !== '') {
    $sql .= " AND next_visit_date >= :date_from";
    $params[':date_from'] = $search_date_from;
}
if ($search_date_to !== '') {
    $sql .= " AND next_visit_date <= :date_to";
    $params[':date_to'] = $search_date_to;
}
if ($search_purpose !== '') {
    $sql .= " AND visit_purpose = :purpose";
    $params[':purpose'] = $search_purpose;
}

$sql .= " ORDER BY next_visit_date ASC, patient_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll();

$purpose_labels = [
    'followup' => '経過観察',
    'tissue_result' => '組織結果説明',
    'next_exam' => '次回検査',
    'medication' => '薬の調整',
    'other' => 'その他'
];

$method_labels = [
    'reserved' => '予約済み',
    'phone_required' => '事前電話要',
    'contact_when_ready' => '都合連絡',
    'walk_in' => '直接来院',
    'adjustment_needed' => '要調整'
];

$week_ja = ['日', '月', '火', '水', '木', '金', '土'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>フォローアップ管理</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #333;
            font-size: 13px;
        }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
        h2 { margin-top: 0; border-bottom: 2px solid #34495e; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header-links { display: flex; gap: 8px; }
        .header-links a { padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 3px 10px; font-size: 11px; border-radius: 3px; text-decoration: none; display: inline-block; }
        .btn { padding: 6px 16px; border: none; border-radius: 4px; font-weight: bold; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-block; }

        .search-bar {
            display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
            background: #f8fafc; padding: 12px 16px; border-radius: 6px;
            border: 1px solid #e2e8f0; margin-bottom: 20px;
        }
        .search-bar label { font-weight: bold; font-size: 12px; color: #475569; }
        .search-bar input, .search-bar select {
            padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;
        }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; vertical-align: middle; }
        th { background: #f1f5f9; font-weight: bold; white-space: nowrap; }
        tr:hover { background: #f8fafc; }
        .empty-msg { text-align: center; color: #94a3b8; padding: 40px; }

        .purpose-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: bold; }
        .purpose-badge.followup { background: #dbeafe; color: #1e40af; }
        .purpose-badge.tissue_result { background: #fef3c7; color: #92400e; }
        .purpose-badge.next_exam { background: #d1fae5; color: #065f46; }
        .purpose-badge.medication { background: #fce7f3; color: #9d174d; }
        .purpose-badge.other { background: #e2e8f0; color: #475569; }

        .exam-tag { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 10px; font-weight: bold; margin-right: 2px; }
        .exam-tag.gif { background: #0ea5e9; color: #fff; }
        .exam-tag.tcf { background: #10b981; color: #fff; }
        .exam-tag.echo { background: #8b5cf6; color: #fff; }
        .exam-tag.polypec { background: #f59e0b; color: #fff; }
        .exam-tag.w { background: #22c55e; color: #fff; }

        .footer { text-align: center; font-size: 11px; color: #94a3b8; margin-top: 20px; padding-top: 10px; border-top: 1px solid #e2e8f0; }
        @media print { .no-print { display: none !important; } }
        @media (max-width: 768px) {
            .search-bar { flex-direction: column; align-items: stretch; }
            .search-bar form { flex-direction: column; }
            table { font-size: 11px; }
            th, td { padding: 4px 5px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h2>
        📋 フォローアップ管理
        <div class="header-links">
            <a href="index.php" class="btn-primary" style="color:#fff;">🏠 メニューへ</a>
            <a href="followup_edit.php" class="btn-success" style="color:#fff;">➕ 新規登録</a>
        </div>
    </h2>

    <!-- 検索バー -->
    <div class="search-bar no-print">
        <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; width:100%;">
            <label>患者名</label>
            <input type="text" name="name" value="<?= htmlspecialchars($search_name, ENT_QUOTES, 'UTF-8') ?>" placeholder="山田">
            <label>来院日</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($search_date_from, ENT_QUOTES, 'UTF-8') ?>">
            〜
            <input type="date" name="date_to" value="<?= htmlspecialchars($search_date_to, ENT_QUOTES, 'UTF-8') ?>">
            <label>目的</label>
            <select name="purpose">
                <option value="">すべて</option>
                <?php foreach ($purpose_labels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $search_purpose === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">🔍 検索</button>
            <a href="followup_list.php" class="btn" style="background:#e2e8f0;color:#475569;">クリア</a>
        </form>
    </div>

    <!-- 一覧 -->
    <?php if (empty($list)): ?>
        <div class="empty-msg">📝 フォローアップデータがありません</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th style="width:150px;">患者名</th>
                    <th style="width:130px;">次回来院日</th>
                    <th style="width:100px;">目的</th>
                    <th style="width:120px;">検査</th>
                    <th style="width:80px;">来院方法</th>
                    <th style="width:150px;">特記事項</th>
                    <th style="width:160px; text-align:center;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($list as $row): 
                    $exam_display = $row['exam_types_display'] ?? '';
                    $exam_types = json_decode($row['exam_types'] ?? '[]', true);
                    $exam_tags = '';
                    if (!empty($exam_types)) {
                        foreach ($exam_types as $e) {
                            $cls = '';
                            if ($e === 'GIF') $cls = 'gif';
                            elseif ($e === 'TCF') $cls = 'tcf';
                            elseif ($e === 'エコー') $cls = 'echo';
                            elseif ($e === 'ポリペク') $cls = 'polypec';
                            elseif ($e === 'W') $cls = 'w';
                            $exam_tags .= '<span class="exam-tag ' . $cls . '">' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                    }
                    
                    // ★★★ 次回来院日の表示（next_visit_display優先、なければnext_visit_dateから生成） ★★★
                    $date_display = '';
                    if (!empty($row['next_visit_display'])) {
                        $date_display = $row['next_visit_display'];
                    } elseif (!empty($row['next_visit_date']) && $row['next_visit_date'] !== '0000-00-00') {
                        $date_display = date('Y年n月j日（D）', strtotime($row['next_visit_date']));
                    } else {
                        $date_display = '（未設定）';
                    }
                ?>
                    <tr>
                        <td><?= $row['followup_id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['patient_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <span style="font-size:10px; color:#94a3b8;">ID: <?= htmlspecialchars($row['patient_id'], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td><?= htmlspecialchars($date_display, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="purpose-badge <?= $row['visit_purpose'] ?>"><?= $purpose_labels[$row['visit_purpose']] ?? $row['visit_purpose'] ?></span></td>
                        <td><?= $exam_tags ?></td>
                        <td><?= $method_labels[$row['visit_method']] ?? $row['visit_method'] ?></td>
                        <td style="font-size:11px; color:#64748b;"><?= htmlspecialchars(mb_substr($row['notes'] ?? '', 0, 20), ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="text-align:center; white-space:nowrap;">
                            <a href="followup_edit.php?id=<?= $row['followup_id'] ?>" class="btn-sm btn-primary" style="color:#fff;">✏️</a>
                            <a href="followup_print.php?id=<?= $row['followup_id'] ?>" target="_blank" class="btn-sm btn-warning" style="color:#fff;">🖨️</a>
                            <a href="action_followup.php?action=delete&id=<?= $row['followup_id'] ?>" class="btn-sm btn-danger" style="color:#fff;" onclick="return confirm('本当に削除しますか？')">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:10px; font-size:12px; color:#94a3b8;">全 <?= count($list) ?> 件</div>
    <?php endif; ?>

    <div class="footer">おの肛門科胃腸科内視鏡クリニック | フォローアップ管理システム v1.0</div>
</div>
</body>
</html>