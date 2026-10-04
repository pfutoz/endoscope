<?php
/**
 * ファイル名: print.php
 * 設置パス: /endoscope/followup/print.php
 * 生成日時: 2026-08-18 09:30:00 JST
 * 
 * フォローアップ印刷シート（A4・患者手渡し用）
 * 
 * 機能:
 *   - A4用紙に収まるシンプルなレイアウト
 *   - 次回来院日・目的・来院方法を明確に表示
 *   - 診療科別の電話番号を表示
 *   - F/U検査項目を表形式で表示（検査・期間・理由）
 *   - 今回の検査（実施済み）を表示
 *   - 発行日・担当医師名を表示
 * 
 * ボタン動作:
 *   - 「印刷する」: 印刷実行 → 印刷後 index.php（一覧）に戻る
 *   - 「編集に戻る」: edit.php に戻る
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成
 *   - 2026-08-17: F/U項目を表形式で表示
 *   - 2026-08-17: ステータスが「予定」のもののみ表示
 *   - 2026-08-17: 日付表示ロジックを修正
 *   - 2026-08-18: 担当医師印を削除
 *   - 2026-08-18: 閉じるボタンを「編集に戻る」に変更
 *   - 2026-08-18: 印刷後に index.php に戻るよう修正
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("パラメータエラー");
}

// メインデータ取得
$stmt = $pdo->prepare("SELECT * FROM t_followup WHERE followup_id = :id");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    die("データが見つかりません");
}

// F/U項目取得（予定のもののみ）
$stmt = $pdo->prepare("
    SELECT * FROM t_followup_items 
    WHERE followup_id = :id 
      AND status = 'planned'
    ORDER BY display_order, created_at
");
$stmt->execute([':id' => $id]);
$items = $stmt->fetchAll();

// 診療科別電話番号
$PHONES = [
    'PROCT' => ['tel' => '088-XXX-XXXX', 'name' => '肛門科'],
    'GASTRO' => ['tel' => '088-YYY-YYYY', 'name' => '胃腸科']
];
$dept = $row['department_code'] ?? 'GASTRO';
$phone = $PHONES[$dept]['tel'] ?? '088-XXX-XXXX';
$dept_name = $PHONES[$dept]['name'] ?? '';

$purpose_labels = [
    'followup' => '経過観察',
    'tissue_result' => '組織検査結果説明',
    'next_exam' => '次回検査',
    'medication' => '薬の調整',
    'other' => 'その他'
];

$method_labels = [
    'reserved' => '✅ 予約済み（このままご来院ください）',
    'phone_required' => '📞 事前に電話でご連絡のうえご来院ください',
    'contact_when_ready' => '📞 ご都合が決まりましたらご連絡ください',
    'walk_in' => '🚶 予約なしで直接お越しください',
    'adjustment_needed' => '🔄 調整後、別途ご連絡します'
];

$interval_display = [
    '3ヶ月' => '3ヶ月後',
    '6ヶ月' => '6ヶ月後',
    '1年' => '1年後',
    '2年' => '2年後',
    '3年' => '3年後',
    '5年' => '5年後'
];

// 日付表示
$date_display = '';
if (!empty($row['next_visit_display'])) {
    $date_display = $row['next_visit_display'];
} elseif (!empty($row['next_visit_date']) && $row['next_visit_date'] !== '0000-00-00') {
    $date_display = date('Y年n月j日（D）', strtotime($row['next_visit_date']));
} else {
    $date_display = '（未設定）';
}

$time_display = $row['visit_time'] ?? '';

// 今回の検査表示
$exam_display = $row['exam_types_display'] ?? '';
if (empty($exam_display)) {
    $exam_types = json_decode($row['exam_types'] ?? '[]', true);
    $exam_display = implode(' + ', $exam_types);
    if (in_array('GIF', $exam_types) && in_array('TCF', $exam_types)) {
        $exam_display .= '（ダブル）';
    }
}

// 印刷済みフラグ更新
if (!$row['is_printed']) {
    $stmt = $pdo->prepare("UPDATE t_followup SET is_printed = TRUE, printed_at = CURRENT_TIMESTAMP WHERE followup_id = :id");
    $stmt->execute([':id' => $id]);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>フォローアップシート</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            font-size: 11pt;
            color: #1a1a2e;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .sheet {
            max-width: 100%;
            margin: 0 auto;
        }

        /* ヘッダー */
        .clinic-header {
            text-align: center;
            border-bottom: 2px solid #1a1a2e;
            padding-bottom: 8px;
            margin-bottom: 14px;
        }
        .clinic-header .name {
            font-size: 18pt;
            font-weight: bold;
            letter-spacing: 2px;
        }
        .clinic-header .sub {
            font-size: 9pt;
            color: #475569;
            margin-top: 2px;
        }
        .clinic-header .phone {
            font-size: 11pt;
            font-weight: bold;
            color: #1e40af;
            margin-top: 4px;
        }

        /* 患者情報 */
        .patient-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 4px 12px;
        }
        .patient-info .name {
            font-size: 16pt;
            font-weight: bold;
        }
        .patient-info .id {
            font-size: 10pt;
            color: #475569;
        }
        .patient-info .doctor {
            font-size: 10pt;
            color: #475569;
        }

        /* メインエリア */
        .main-box {
            border: 2px solid #1a1a2e;
            border-radius: 8px;
            padding: 16px 18px;
            margin-bottom: 14px;
            background: #ffffff;
        }
        .main-box .title {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin-bottom: 12px;
            color: #1a1a2e;
        }

        .info-row {
            display: flex;
            margin-bottom: 6px;
            padding-bottom: 5px;
            border-bottom: 1px dotted #e2e8f0;
        }
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .info-row .label {
            font-weight: bold;
            min-width: 72px;
            color: #475569;
            font-size: 10.5pt;
        }
        .info-row .value {
            font-size: 11.5pt;
            font-weight: bold;
            color: #0f172a;
        }
        .info-row .value.large {
            font-size: 15pt;
            color: #1e40af;
        }
        .info-row .value .sub-text {
            font-weight: normal;
            font-size: 10pt;
            color: #64748b;
        }

        /* 目的バッジ */
        .purpose-badge {
            display: inline-block;
            padding: 2px 14px;
            border-radius: 16px;
            font-weight: bold;
            font-size: 11pt;
            background: #dbeafe;
            color: #1e40af;
        }

        /* F/Uテーブル */
        .fu-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 10.5pt;
        }
        .fu-table th {
            background: #f1f5f9;
            padding: 5px 10px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #dce2ec;
            font-size: 9.5pt;
        }
        .fu-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #e9edf4;
            vertical-align: middle;
        }
        .fu-table tr:last-child td {
            border-bottom: none;
        }

        .exam-tag {
            display: inline-block;
            padding: 1px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 10pt;
        }
        .exam-tag.gif { background: #0ea5e9; color: #fff; }
        .exam-tag.tcf { background: #10b981; color: #fff; }
        .exam-tag.echo { background: #8b5cf6; color: #fff; }
        .exam-tag.polypec { background: #f59e0b; color: #fff; }
        .exam-tag.other { background: #e2e8f0; color: #475569; }

        /* 特記事項 */
        .notes-area {
            background: #f8fafc;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 10px;
            min-height: 32px;
            font-size: 10.5pt;
        }
        .notes-area .label {
            font-weight: bold;
            color: #475569;
            font-size: 9.5pt;
        }

        /* フッター */
        .footer {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-top: 16px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 8.5pt;
            color: #94a3b8;
        }
        .footer .note {
            font-size: 9pt;
            color: #64748b;
            font-weight: bold;
            margin-right: auto;
        }

        .no-print {
            text-align: center;
            margin-top: 20px;
            padding: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .no-print button, .no-print a {
            padding: 10px 30px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            margin: 0 6px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-print { background: #3b82f6; color: #fff; }
        .btn-print:hover { background: #2563eb; }
        .btn-edit { background: #6c757d; color: #fff; }
        .btn-edit:hover { background: #5a6268; }

        @media print {
            .no-print { display: none !important; }
            .sheet { margin: 0; }
            .clinic-header .phone { color: #1a1a2e !important; }
        }
        @media (max-width: 600px) {
            .patient-info { flex-direction: column; align-items: flex-start; }
            .fu-table { font-size: 9pt; }
            .fu-table th, .fu-table td { padding: 3px 6px; }
            .footer { flex-direction: column; align-items: stretch; text-align: center; gap: 6px; }
        }
    </style>
</head>
<body>
<div class="sheet">

    <!-- ヘッダー -->
    <div class="clinic-header">
        <div class="name">おの肛門科胃腸科内視鏡クリニック</div>
        <div class="sub">〒780-0000 高知県高知市〇〇町 1-2-3</div>
        <div class="phone">📞 <?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>（<?= htmlspecialchars($dept_name, ENT_QUOTES, 'UTF-8') ?>直通）</div>
    </div>

    <!-- 患者情報 -->
    <div class="patient-info">
        <span class="name"><?= htmlspecialchars($row['patient_name'], ENT_QUOTES, 'UTF-8') ?> 様</span>
        <span class="id">カルテID：<?= htmlspecialchars($row['patient_id'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="doctor">担当医：<?= htmlspecialchars($row['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <!-- ============================================================
    メイン: 次回のご来院について
    ============================================================ -->
    <div class="main-box">
        <div class="title">📋 次回のご来院についてのお知らせ</div>

        <!-- 日時 -->
        <div class="info-row">
            <span class="label">📅 日時</span>
            <span class="value large">
                <?= htmlspecialchars($date_display, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($time_display): ?>
                    <span style="font-size:12pt;"> <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </span>
        </div>

        <!-- 目的 -->
        <div class="info-row">
            <span class="label">🎯 目的</span>
            <span class="value">
                <span class="purpose-badge"><?= $purpose_labels[$row['visit_purpose']] ?? $row['visit_purpose'] ?></span>
                <?php if ($row['visit_purpose'] === 'next_exam' && !empty($items)): ?>
                    <span class="sub-text">（下記F/U検査のため）</span>
                <?php endif; ?>
            </span>
        </div>

        <!-- 来院方法 -->
        <div class="info-row">
            <span class="label">🚗 来院方法</span>
            <span class="value" style="font-size:11.5pt;"><?= $method_labels[$row['visit_method']] ?? $row['visit_method'] ?></span>
        </div>

        <!-- 今回の検査（実施済み） -->
        <?php if ($exam_display): ?>
        <div class="info-row" style="border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 8px;">
            <span class="label">🔬 今回の検査</span>
            <span class="value" style="font-size:11pt;">
                <?php
                $exam_types = json_decode($row['exam_types'] ?? '[]', true);
                foreach ($exam_types as $e):
                    $cls = '';
                    if ($e === 'GIF') $cls = 'gif';
                    elseif ($e === 'TCF') $cls = 'tcf';
                    elseif ($e === 'エコー') $cls = 'echo';
                    elseif ($e === 'ポリペク') $cls = 'polypec';
                    else $cls = 'other';
                ?>
                    <span class="exam-tag <?= $cls ?>"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
                <?php if (in_array('GIF', $exam_types) && in_array('TCF', $exam_types)): ?>
                    <span style="font-size:9pt; color:#64748b; margin-left:4px;">（ダブル）</span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- ============================================================
        F/U検査項目（予定のもののみ表示）
        ============================================================ -->
        <?php if (!empty($items)): ?>
        <div style="margin-top:6px;">
            <div style="font-weight:bold; font-size:11pt; color:#1e40af; margin-bottom:6px;">
                📋 次回F/U検査（予定）
            </div>
            <table class="fu-table">
                <thead>
                    <tr>
                        <th style="width:20%;">検査種別</th>
                        <th style="width:25%;">F/U期間</th>
                        <th style="width:55%;">理由（特記事項）</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $cls = strtolower($item['exam_type'] ?? '');
                        if ($cls === 'エコー') $cls = 'echo';
                        if ($cls === 'ポリペク') $cls = 'polypec';
                        $interval = $interval_display[$item['fu_interval']] ?? $item['fu_interval'];
                    ?>
                        <tr>
                            <td><span class="exam-tag <?= $cls ?>"><?= htmlspecialchars($item['exam_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars($interval, ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="font-size:10.5pt; color:#334155;"><?= htmlspecialchars($item['fu_reason'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="font-size:9pt; color:#94a3b8; margin-top:4px;">
                ※ 上記の検査が次回のフォローアップ予定です
            </div>
        </div>
        <?php else: ?>
        <div style="margin-top:6px; padding:8px 12px; background:#f8fafc; border-radius:6px; border:1px dashed #d0d8e3; text-align:center; color:#94a3b8; font-size:11pt;">
            📝 次回F/U検査の予定はありません
        </div>
        <?php endif; ?>
    </div>

    <!-- 特記事項 -->
    <?php if (!empty($row['notes'])): ?>
    <div class="notes-area">
        <div class="label">📝 特記事項</div>
        <div style="margin-top:3px;"><?= nl2br(htmlspecialchars($row['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
    </div>
    <?php endif; ?>

    <!-- フッター（担当医師印は削除） -->
    <div class="footer">
        <div class="note">※ 次回受診の際はこの用紙をお持ちください</div>
        <div style="font-size:8pt; color:#94a3b8;">
            発行日：<?= date('Y年 n月 j日', strtotime($row['issued_date'] ?? 'now')) ?>
            <?php if ($row['is_printed']): ?>
                ｜印刷済み
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- 印刷ボタン -->
<div class="no-print">
    <button class="btn-print" onclick="doPrint()">🖨️ 印刷する</button>
    <a href="edit.php?id=<?= $id ?>" class="btn-edit">✏️ 編集に戻る</a>
</div>

<script>
function doPrint() {
    // 印刷実行
    window.print();
    // 印刷後（または印刷ダイアログを閉じた後）一覧に戻る
    setTimeout(function() {
        window.location.href = 'index.php?msg=' + encodeURIComponent('印刷しました');
    }, 500);
}
</script>

</body>
</html>