<?php
/**
 * 生成日時: 2026-08-05
 * 患者検査票印刷（受付票） - 最終版
 * 
 * 仕様:
 * - タイトル: 「検査予約票」（大きな文字）
 * - 病院名・住所・電話は表示しない
 * - 時間枠は GIF と エコー 時のみ表示（TCF/W/ポリペクは非表示）
 * - 備考・注意事項は表示しない
 * - フッター文言は表示しない
 * - 問い合わせ先は残す（汎用マスタから診療科別に取得）
 * - 担当看護師は空欄（手書き用）を残す
 * - 来院時刻・実施場所が「---」または空欄の場合は非表示
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';

// ============================================================
// パラメータ取得
// ============================================================
$schedule_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($schedule_id <= 0) {
    die("エラー: スケジュールIDが指定されていません。");
}

// ============================================================
// DB接続
// ============================================================
$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// ============================================================
// 予約データ取得
// ============================================================
$sql = "SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $schedule_id]);
$data = $stmt->fetch();

if (!$data) {
    die("エラー: 指定された予約データが見つかりません。");
}

// ============================================================
// 問い合わせ先取得（汎用マスタ）
// ============================================================
function getToiawase($pdo, $dept) {
    $stmt = $pdo->prepare("
        SELECT item_value 
        FROM m_input_master 
        WHERE category = 'toiawase' 
          AND display_order = :order 
        LIMIT 1
    ");
    $order = ($dept === 'PROCT') ? 1 : 0;
    $stmt->execute([':order' => $order]);
    $row = $stmt->fetch();
    return $row ? $row['item_value'] : 'お問い合わせは受付までお願いします。';
}

// ============================================================
// 表示用データ準備
// ============================================================
$age = calculateAge($data['birthday'] ?? '', $data['exam_date'] ?? '');
$wareki = convertToWareki($data['birthday'] ?? '');
$dept_label = ($data['department_code'] === 'PROCT') ? '肛門科' : '胃腸科';
$toiawase = getToiawase($pdo, $data['department_code']);
$weekday = getWeekday($data['exam_date'] ?? '');

// ★ 時間枠表示判定（GIFとエコーの場合のみ表示）
$exam_type = $data['exam_type'] ?? '';
$show_waku = ($exam_type === 'GIF' || $exam_type === 'エコー');

// 来院時刻（--- または空欄の場合は非表示）
$arrival_time = trim($data['arrival_time'] ?? '');
$show_arrival = ($arrival_time !== '' && $arrival_time !== '---');

// 実施場所（--- または空欄の場合は非表示）
$exam_location = trim($data['exam_location'] ?? '');
$show_location = ($exam_location !== '' && $exam_location !== '---');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>検査予約票</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 20mm 15mm;
        }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            font-size: 11pt;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .slip-container {
            max-width: 170mm;
            margin: 0 auto;
            padding: 5mm;
        }
        .title-area {
            text-align: center;
            margin-bottom: 20px;
        }
        .title-area .main-title {
            font-size: 24pt;
            font-weight: bold;
            letter-spacing: 4px;
            color: #1e293b;
            border-bottom: 3px solid #2c3e50;
            display: inline-block;
            padding-bottom: 6px;
        }
        .patient-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 20px;
            margin-bottom: 14px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }
        .patient-info .label {
            font-weight: bold;
            color: #475569;
        }
        .patient-info .value {
            font-weight: bold;
        }
        .exam-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 20px;
            margin-bottom: 14px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        .exam-info .label {
            font-weight: bold;
            color: #475569;
        }
        .exam-info .value {
            font-weight: bold;
            color: #1e293b;
        }
        .nurse-line {
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 11pt;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nurse-line .line {
            flex: 1;
            border-bottom: 1px solid #000;
            height: 1.2em;
            min-width: 100px;
        }
        .toiawase-area {
            margin-top: 14px;
            padding: 10px 14px;
            background: #f0f7ff;
            border: 1px solid #b4c6e7;
            border-radius: 4px;
            font-size: 10pt;
            color: #1e40af;
            text-align: center;
        }

        /* 印刷用ボタン（画面表示時のみ） */
        .no-print {
            display: block;
            text-align: center;
            margin-bottom: 10px;
        }
        .print-btn {
            background: #2c3e50;
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            font-size: 12pt;
            cursor: pointer;
        }
        .print-btn:hover { background: #1a252f; }
        .print-btn-close {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            font-size: 12pt;
            cursor: pointer;
            margin-left: 8px;
        }
        .print-btn-close:hover { background: #5a6268; }

        @media print {
            .no-print { display: none !important; }
            .slip-container { border: none !important; padding: 0 !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body>

<!-- 印刷ボタン（印刷時は非表示） -->
<div class="no-print">
    <button class="print-btn" onclick="window.print()">🖨️ 印刷する</button>
    <button class="print-btn-close" onclick="window.close()">閉じる</button>
</div>

<!-- ============================================================
     帳票本体
     ============================================================ -->
<div class="slip-container">

    <!-- タイトル -->
    <div class="title-area">
        <span class="main-title">検 査 予 約 票</span>
    </div>

    <!-- 患者情報 -->
    <div class="patient-info">
        <div><span class="label">氏名</span> <span class="value"><?=htmlspecialchars($data['patient_name'] ?? '（未登録）', ENT_QUOTES, 'UTF-8')?></span></div>
        <div><span class="label">フリガナ</span> <span><?=htmlspecialchars($data['patient_kana'] ?? '', ENT_QUOTES, 'UTF-8')?></span></div>
        <div><span class="label">生年月日</span> <span><?=htmlspecialchars($wareki, ENT_QUOTES, 'UTF-8')?></span></div>
        <div><span class="label">年齢</span> <span><?=$age?></span></div>
        <div><span class="label">カルテID</span> <span class="value"><?=htmlspecialchars($data['patient_id'] ?? '---', ENT_QUOTES, 'UTF-8')?></span></div>
        <div><span class="label">診療科</span> <span><?=$dept_label?></span></div>
    </div>

    <!-- 検査情報 -->
    <div class="exam-info">
        <div>
            <span class="label">検査日</span>
            <span class="value">
                <?=htmlspecialchars($data['exam_date'] ?? '---', ENT_QUOTES, 'UTF-8')?>
                （<?=$weekday?>）
            </span>
        </div>

        <!-- ★ 時間枠は GIF と エコーの場合のみ表示 -->
        <?php if ($show_waku): ?>
        <div><span class="label">時間枠</span> <span class="value"><?=htmlspecialchars($data['scheduled_time'] ?? '---', ENT_QUOTES, 'UTF-8')?></span></div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>

        <div><span class="label">検査種別</span> <span class="value"><?=htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8')?></span></div>
        <div><span class="label">担当医師</span> <span class="value"><?=htmlspecialchars($data['doctor_name'] ?? '---', ENT_QUOTES, 'UTF-8')?></span></div>

        <!-- 来院時刻（--- または空欄の場合は非表示） -->
        <?php if ($show_arrival): ?>
        <div><span class="label">来院時刻</span> <span><?=htmlspecialchars($arrival_time, ENT_QUOTES, 'UTF-8')?></span></div>
        <?php endif; ?>

        <!-- 実施場所（--- または空欄の場合は非表示） -->
        <?php if ($show_location): ?>
        <div><span class="label">実施場所</span> <span><?=htmlspecialchars($exam_location, ENT_QUOTES, 'UTF-8')?></span></div>
        <?php endif; ?>
    </div>

    <!-- 担当看護師（手書き用） -->
    <div class="nurse-line">
        <span style="font-weight:bold;">担当看護師：</span>
        <span class="line"></span>
    </div>

    <!-- 問い合わせ先（汎用マスタから取得） -->
    <div class="toiawase-area">
        <?=htmlspecialchars($toiawase, ENT_QUOTES, 'UTF-8')?>
    </div>

</div>

</body>
</html>