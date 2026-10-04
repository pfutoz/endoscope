<?php
/**
 * ファイル名: followup_print.php
 * 設置パス: /endoscope/followup_print.php
 * 生成日時: 2026-08-17 17:00:00 JST
 * 
 * フォローアップ印刷シート（A4・患者手渡し用）
 * 
 * 機能:
 *   - A4用紙に収まるシンプルなレイアウト
 *   - 次回来院日・目的・来院方法を明確に表示
 *   - 診療科別の電話番号を表示
 *   - 検査別フォローアップ期間を表示（GIF=1年、TCF=3年など）
 *   - 発行日・担当医師名を表示
 *   - 印刷ボタン・閉じるボタン
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成（たたき台）
 *   - 2026-08-17: 日付表示ロジックを修正（next_visit_display優先、なければnext_visit_dateから生成）
 *   - 2026-08-17: 検査別フォローアップ項目を表示
 *   - 2026-08-17: 診療科別電話番号を表示
 *   - 2026-08-17: 印刷済みフラグを更新
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("パラメータエラー");
}

$stmt = $pdo->prepare("SELECT * FROM t_followup WHERE followup_id = :id");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    die("データが見つかりません");
}

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

// ★★★ 次回来院日の表示（next_visit_display優先、なければnext_visit_dateから生成） ★★★
$date_display = '';
if (!empty($row['next_visit_display'])) {
    $date_display = $row['next_visit_display'];
} elseif (!empty($row['next_visit_date']) && $row['next_visit_date'] !== '0000-00-00') {
    $date_display = date('Y年n月j日（D）', strtotime($row['next_visit_date']));
} else {
    $date_display = '（未設定）';
}

$time_display = $row['visit_time'] ?? '';

// 検査表示
$exam_display = $row['exam_types_display'] ?? '';
if (empty($exam_display)) {
    $exam_types = json_decode($row['exam_types'] ?? '[]', true);
    $exam_display = implode(' + ', $exam_types);
    if (in_array('GIF', $exam_types) && in_array('TCF', $exam_types)) {
        $exam_display .= '（ダブル）';
    }
}

// フォローアップ項目
$followup_items = json_decode($row['followup_items'] ?? '[]', true);
$followup_html = '';
if (!empty($followup_items)) {
    $followup_html = '<div style="margin-top:10px; background:#f8fafc; padding:10px 14px; border-radius:6px; border:1px solid #e2e8f0;">';
    $followup_html .= '<div style="font-weight:bold; font-size:12px; color:#475569; margin-bottom:6px;">📋 今後のフォローアップ予定</div>';
    foreach ($followup_items as $item) {
        $exam = $item['exam'] ?? '';
        $interval = $item['interval'] ?? '';
        $next_date = $item['next_date'] ?? '';
        $date_str = $next_date ? date('Y年n月j日', strtotime($next_date)) : '（調整中）';
        $followup_html .= '<div style="display:flex; gap:20px; font-size:13px; padding:3px 0; border-bottom:1px dotted #e2e8f0;">';
        $followup_html .= '<span style="font-weight:bold; min-width:80px;">' . htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') . '</span>';
        $followup_html .= '<span style="color:#1e40af;">→ ' . htmlspecialchars($interval, ENT_QUOTES, 'UTF-8') . '後（' . $date_str . '）</span>';
        $followup_html .= '</div>';
    }
    $followup_html .= '</div>';
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
            margin: 15mm 18mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
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
            margin-bottom: 16px;
        }
        .clinic-header .name {
            font-size: 18pt;
            font-weight: bold;
            letter-spacing: 2px;
        }
        .clinic-header .sub {
            font-size: 10pt;
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
            padding: 10px 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 6px 16px;
        }
        .patient-info .name {
            font-size: 16pt;
            font-weight: bold;
        }
        .patient-info .id {
            font-size: 11pt;
            color: #475569;
        }
        .patient-info .doctor {
            font-size: 11pt;
            color: #475569;
        }
        
        /* メインエリア */
        .main-box {
            border: 2px solid #1a1a2e;
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 16px;
            background: #ffffff;
        }
        .main-box .title {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin-bottom: 14px;
            color: #1a1a2e;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px dotted #e2e8f0;
        }
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .info-row .label {
            font-weight: bold;
            min-width: 80px;
            color: #475569;
            font-size: 11pt;
        }
        .info-row .value {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
        }
        .info-row .value.large {
            font-size: 16pt;
            color: #1e40af;
        }
        
        /* 目的バッジ */
        .purpose-badge {
            display: inline-block;
            padding: 2px 16px;
            border-radius: 16px;
            font-weight: bold;
            font-size: 12pt;
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* 特記事項 */
        .notes-area {
            background: #f8fafc;
            padding: 10px 14px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 10px;
            min-height: 40px;
            font-size: 11pt;
        }
        .notes-area .label {
            font-weight: bold;
            color: #475569;
            font-size: 10pt;
        }
        
        /* フッター */
        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            font-size: 9pt;
            color: #94a3b8;
        }
        .footer .note {
            font-size: 9pt;
            color: #64748b;
            font-weight: bold;
        }
        .footer .stamp {
            font-size: 11pt;
            font-weight: bold;
            color: #1a1a2e;
            border: 1px solid #ccc;
            padding: 4px 20px;
            border-radius: 4px;
            background: #fafafa;
        }
        
        .exam-tag {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 11pt;
            margin-right: 4px;
        }
        .exam-tag.gif { background: #0ea5e9; color: #fff; }
        .exam-tag.tcf { background: #10b981; color: #fff; }
        .exam-tag.echo { background: #8b5cf6; color: #fff; }
        .exam-tag.polypec { background: #f59e0b; color: #fff; }
        .exam-tag.w { background: #22c55e; color: #fff; }
        .exam-tag.other { background: #e2e8f0; color: #475569; }
        
        @media print {
            .no-print { display: none !important; }
            .sheet { margin: 0; }
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

    <!-- メイン -->
    <div class="main-box">
        <div class="title">📋 次回のご来院についてのお知らせ</div>

        <!-- 日時 -->
        <div class="info-row">
            <span class="label">📅 日時</span>
            <span class="value large">
                <?= htmlspecialchars($date_display, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($time_display): ?>
                    <span style="font-size:13pt;"> <?= htmlspecialchars($time_display, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </span>
        </div>

        <!-- 目的 -->
        <div class="info-row">
            <span class="label">🎯 目的</span>
            <span class="value">
                <span class="purpose-badge"><?= $purpose_labels[$row['visit_purpose']] ?? $row['visit_purpose'] ?></span>
                <?php if ($row['visit_purpose'] === 'next_exam' && $exam_display): ?>
                    <span style="font-size:11pt; margin-left:6px; color:#475569;">（<?= htmlspecialchars($exam_display, ENT_QUOTES, 'UTF-8') ?>）</span>
                <?php endif; ?>
            </span>
        </div>

        <!-- 来院方法 -->
        <div class="info-row">
            <span class="label">🚗 来院方法</span>
            <span class="value" style="font-size:12pt;"><?= $method_labels[$row['visit_method']] ?? $row['visit_method'] ?></span>
        </div>

        <!-- 実施済み検査 -->
        <?php if ($exam_display): ?>
        <div class="info-row">
            <span class="label">🔬 今回の検査</span>
            <span class="value">
                <?php
                $exam_types = json_decode($row['exam_types'] ?? '[]', true);
                foreach ($exam_types as $e):
                    $cls = '';
                    if ($e === 'GIF') $cls = 'gif';
                    elseif ($e === 'TCF') $cls = 'tcf';
                    elseif ($e === 'エコー') $cls = 'echo';
                    elseif ($e === 'ポリペク') $cls = 'polypec';
                    elseif ($e === 'W') $cls = 'w';
                    else $cls = 'other';
                ?>
                    <span class="exam-tag <?= $cls ?>"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
                <?php if (in_array('GIF', $exam_types) && in_array('TCF', $exam_types)): ?>
                    <span style="font-size:10pt; color:#475569; margin-left:4px;">（ダブル）</span>
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- フォローアップ項目 -->
        <?= $followup_html ?>
    </div>

    <!-- 特記事項 -->
    <?php if (!empty($row['notes'])): ?>
    <div class="notes-area">
        <div class="label">📝 特記事項</div>
        <div style="margin-top:4px;"><?= nl2br(htmlspecialchars($row['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
    </div>
    <?php endif; ?>

    <!-- フッター -->
    <div class="footer">
        <div>
            <div class="note">※ 次回受診の際はこの用紙をお持ちください</div>
            <div style="margin-top:2px; font-size:8pt; color:#94a3b8;">
                発行日：<?= date('Y年 n月 j日', strtotime($row['issued_date'] ?? 'now')) ?>
                <?php if ($row['is_printed']): ?>
                    ｜印刷済み
                <?php endif; ?>
            </div>
        </div>
        <div class="stamp">担当医師 印</div>
    </div>

</div>

<div class="no-print" style="text-align:center; margin-top:20px; padding:10px; border-top:1px solid #e2e8f0;">
    <button onclick="window.print()" style="padding:10px 30px; background:#3b82f6; color:#fff; border:none; border-radius:4px; font-size:14px; cursor:pointer;">🖨️ 印刷する</button>
    <button onclick="window.close()" style="padding:10px 30px; background:#6c757d; color:#fff; border:none; border-radius:4px; font-size:14px; cursor:pointer; margin-left:10px;">閉じる</button>
</div>
</body>
</html>