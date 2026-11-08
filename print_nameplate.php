<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\print_nameplate.php
 * ファイル名: print_nameplate.php
 * 概要: 下部内視鏡検査患者用ネームプレート印刷専用モジュール (横147mm×縦70mm A4縦4枚面付け)
 * 生成日時: 2026-10-30 11:25
 * 更新日時: 2026-11-06
 * 修正履歴:
 *   - 2026-11-06: 時間枠世代管理（valid_from 〜 valid_to）に対応。LEFT JOIN m2_waku にて検査日の世代を結合するよう改修。
 *   - 2026-10-30: 【新規作成 & レイアウト徹底洗練: 下部検査ネームプレート印刷モジュール】
 *       1. 横147mm × 縦70mm の高精度カードレイアウト（A4縦に4枚完全面付け: 左右余白各31.5mm、上下各8.5mm）
 *       2. 印字内容の洗練（余計な文字を完全排除）:
 *          - 上段: ［純粋な部屋名（ｲ203等）］を左上、［純粋な下剤名（モビプレップ等）］を右上にグレー（#64748b）で印字
 *          - 中段: ［患者フルネーム］を特大・極太線（46pt, font-weight:900, -webkit-text-stroke:0.5px）で配置
 *          - 下段: ［様］を氏名の下段・右下に大きく太字（24pt）で揃えて配置
 *          - 枠内の「キリトリ」「時間枠名」「患者ID」「部屋:」「下剤:」などの不要文字を完全排除
 *       3. プリンタ出力先の端末保存（localStorage）:
 *          - 選択したプリンタ設定（Windowsローカル印刷 または 院内指定複合機）を端末ごとに記憶・固定
 *       4. タッチデバイス対応:
 *          - 画面上の左右スワイプによる日付前日/翌日移動機能を搭載（← / → キーボード連動）
 *       5. Windows端末印刷 ⇔ タブレット用サーバー直接印刷（Edge headless + SumatraPDF）の完全ハイブリッド
 *       6. 白紙ページ排出防止完全対策: @page margin:0 と .nameplate-sheet の297mm完全固定・overflow:hidden・break-inside:avoid により、各ページの間に生じていた余分な白紙（1シートあたり1枚の白紙）の発生原因を根絶
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/printer_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// パラメータ取得
$dept     = isset($_GET['dept']) ? (string)$_GET['dept'] : 'ALL';
$date_str = isset($_GET['date']) && is_string($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$sort_by  = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'room_asc';
$font_key = isset($_GET['font']) ? trim((string)$_GET['font']) : 'biz-ud';

if (!in_array($dept, ['PROCT', 'GASTRO', 'ALL'], true)) {
    $dept = 'ALL';
}
if (!in_array($sort_by, ['room_asc', 'time'], true)) {
    $sort_by = 'room_asc';
}
if (!in_array($font_key, ['biz-ud', 'meiryo', 'yu-gothic', 'ud-kyokasho'], true)) {
    $font_key = 'biz-ud';
}

// 診療科のSQL条件
if ($dept === 'ALL') {
    $dept_label = '全診療科';
    $dept_sql   = "1=1";
} elseif ($dept === 'PROCT') {
    $dept_label = '肛門科';
    $dept_sql   = "s.department_code = 'PROCT'";
} else {
    $dept_label = '胃腸科';
    $dept_sql   = "s.department_code = 'GASTRO'";
}

// 下剤ラベルマッピング
$prep_labels = [
    'マグP' => 'マグコロールP',
    'モビ'  => 'モビプレップ',
    'ニフ'  => 'ニフレック'
];

/**
 * 下剤表示用テキストの取得（略称を正式名称に変換）
 */
function formatPrepName(?string $prep, array $prep_labels): string {
    $trimmed = trim((string)$prep);
    if ($trimmed === '') return '下剤: 未指定';
    return $prep_labels[$trimmed] ?? $trimmed;
}

/**
 * ネームプレート用表示氏名の生成（苗字のみ抽出 ＆ 同姓時は名前の1文字目を括弧付きで自動識別）
 * 
 * 仕様:
 * 1. スペース（全角・半角）がある場合: 先頭の苗字を抽出
 * 2. スペースがない場合: 安全のためフルネームのまま表示
 * 3. 同日に同じ苗字の患者がいる場合: 「苗字＋（名前の1文字目）」（例: 佐藤（健））で自動識別
 * 4. 同姓かつ名前の1文字目も被る場合: 「苗字＋（名前の2文字目まで）」（例: 高橋（翔太））
 */
function resolveNameplateDisplayName(string $rawName, array $allNamesOnDate): string {
    $rawName = trim($rawName);
    if ($rawName === '') return '';

    // 全角・半角スペースで分割
    $parts = preg_split('/[\s　]+/u', $rawName, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) <= 1) {
        // スペースがない場合は安全のためフルネームのまま
        return $rawName;
    }

    $sei = $parts[0];
    $mei = $parts[1] ?? '';

    // 当日の同姓重複カウント
    $dupCount = 0;
    $sameFirstCharCount = 0;
    $myFirstChar = mb_substr($mei, 0, 1, 'UTF-8');

    foreach ($allNamesOnDate as $otherRaw) {
        $oParts = preg_split('/[\s　]+/u', trim((string)$otherRaw), -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($oParts) && $oParts[0] === $sei) {
            $dupCount++;
            $oMei = $oParts[1] ?? '';
            if (mb_substr($oMei, 0, 1, 'UTF-8') === $myFirstChar) {
                $sameFirstCharCount++;
            }
        }
    }

    // 同姓が複数名いる場合: 苗字＋（名前の1文字目）
    if ($dupCount > 1 && $mei !== '') {
        // 名前の1文字目まで重複している場合は2文字目まで抽出
        if ($sameFirstCharCount > 1 && mb_strlen($mei, 'UTF-8') >= 2) {
            $sub = mb_substr($mei, 0, 2, 'UTF-8');
            return "{$sei}（{$sub}）";
        }
        return "{$sei}（{$myFirstChar}）";
    }

    return $sei;
}

// 下部検査（CF系）の予約患者データを取得（空き枠・キャンセル除外）
$sql = "
    SELECT 
        s.schedule_id,
        s.exam_date,
        s.scheduled_time,
        s.department_code,
        s.patient_id,
        s.patient_name,
        s.patient_kana,
        s.doctor_name,
        s.exam_type,
        s.room_number,
        s.bowel_prep,
        COALESCE(w.display_order, 9999) AS waku_display_order
    FROM t_endoscopy_schedule s
    LEFT JOIN m2_waku w 
        ON s.department_code = w.department_code 
        AND s.scheduled_time = w.waku_name
        AND w.is_active = TRUE
        AND s.exam_date BETWEEN w.valid_from AND w.valid_to
    WHERE {$dept_sql}
      AND s.exam_date = :date
      AND s.is_canceled = FALSE
      AND s.patient_name IS NOT NULL
      AND TRIM(s.patient_name) != ''
      AND (
          s.exam_type IN ('TCF', 'W', 'ポリペク', 'CF') 
          OR s.exam_type LIKE '%CF%' 
          OR w.group_code = 'TCF'
      )
    ORDER BY s.department_code ASC, COALESCE(w.display_order, 9999) ASC, s.schedule_id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':date' => $date_str]);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ソート適用（部屋番号順 または 時間枠順）
if ($sort_by === 'room_asc') {
    usort($patients, function ($a, $b) {
        $rA = trim((string)($a['room_number'] ?? ''));
        $rB = trim((string)($b['room_number'] ?? ''));
        
        // 部屋未設定を後ろに配置（部屋あり優先）
        if ($rA === '' && $rB !== '') return 1;
        if ($rA !== '' && $rB === '') return -1;
        if ($rA === '' && $rB === '') {
            return ($a['waku_display_order'] <=> $b['waku_display_order']);
        }
        
        // 自然順（ﾏ101 < ﾏ102 < ﾏ201）
        $cmp = strnatcasecmp($rA, $rB);
        if ($cmp !== 0) return $cmp;
        
        return ($a['waku_display_order'] <=> $b['waku_display_order']);
    });
} else {
    // 時間枠順
    usort($patients, function ($a, $b) {
        if ($a['waku_display_order'] !== $b['waku_display_order']) {
            return ($a['waku_display_order'] <=> $b['waku_display_order']);
        }
        return ($a['schedule_id'] <=> $b['schedule_id']);
    });
}

// 1ページあたり4枚でチャンク分割
$sheets = array_chunk($patients, 4);
$total_patients = count($patients);
$total_sheets   = count($sheets);

// 当日の全患者氏名リスト（同姓判定用）
$all_patient_names = array_column($patients, 'patient_name');

// 日付フォーマット（例: 2026年10月30日(金)）
$ts = strtotime($date_str);
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$formatted_date = date('Y年n月j日', $ts) . '(' . $weekdays[(int)date('w', $ts)] . ')';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>ネームプレート印刷 <?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?> (<?= $dept_label ?>)</title>
<style>
/* ==========================================================================
   リセット & ベース設定（完全オフライン対応・Windows標準フォント）
   ========================================================================== */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: "BIZ UDPGothic", "BIZ UDGothic", "Meiryo", "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
    background-color: #0f172a;
    color: #1e293b;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* フォントバリエーション（すべてWindows標準搭載・オフライン完全対応） */
.font-biz-ud, .font-biz-ud .nameplate-card {
    font-family: "BIZ UDPGothic", "BIZ UDGothic", "Meiryo", sans-serif !important;
}
.font-meiryo, .font-meiryo .nameplate-card {
    font-family: "Meiryo", "メイリオ", "BIZ UDPGothic", sans-serif !important;
}
.font-yu-gothic, .font-yu-gothic .nameplate-card {
    font-family: "Yu Gothic", "游ゴシック", "YuGothic", "Meiryo", sans-serif !important;
}
.font-ud-kyokasho, .font-ud-kyokasho .nameplate-card {
    font-family: "UD DigiKyoPro-B", "UD デジタル 教科書体 NK-B", "BIZ UDPGothic", "Meiryo", sans-serif !important;
}

/* ==========================================================================
   画面上部 操作バー (非印刷)
   ========================================================================== */
.no-print-bar {
    position: sticky;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    background: #1e293b;
    color: #f8fafc;
    border-bottom: 2px solid #334155;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    padding: 8px 16px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.no-print-bar-left,
.no-print-bar-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.bar-title {
    font-size: 14px;
    font-weight: 700;
    color: #38bdf8;
    display: flex;
    align-items: center;
    gap: 6px;
}

.bar-badge {
    background: #334155;
    color: #e2e8f0;
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 600;
}

.bar-form-group {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #cbd5e1;
}

.bar-form-group select,
.bar-form-group input[type="date"] {
    background: #0f172a;
    color: #f8fafc;
    border: 1px solid #475569;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12px;
    cursor: pointer;
    min-height: 32px;
}

.bar-btn-nav {
    background: #334155;
    color: #f8fafc;
    border: 1px solid #475569;
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12px;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    transition: background 0.15s;
    min-height: 32px;
}
.bar-btn-nav:hover {
    background: #475569;
}

/* サーバー直接印刷セレクター & コントロール */
.printer-select-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #0f172a;
    border: 1px solid #475569;
    border-radius: 6px;
    padding: 3px 8px;
    height: 34px;
    color: #cbd5e1;
    font-size: 12px;
    font-weight: 600;
}
.printer-select-pill select {
    background: transparent;
    color: #38bdf8;
    border: none;
    outline: none;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    max-width: 175px;
}
.printer-select-pill select option {
    background: #1e293b;
    color: #f8fafc;
}

/* トースト通知 */
.server-print-toast {
    position: fixed;
    top: 54px;
    right: 20px;
    z-index: 99999;
    background: #0f172a;
    color: #f8fafc;
    border: 1px solid #38bdf8;
    border-radius: 8px;
    padding: 10px 16px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 600;
    transform: translateY(-20px);
    opacity: 0;
    pointer-events: none;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
}
.server-print-toast.show {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
}
.server-print-toast.toast-success { border-color: #10b981; }
.server-print-toast.toast-error { border-color: #ef4444; }

/* 印刷実行ボタン (タブレット向けタップ領域大) */
.btn-print-action {
    background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
    color: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 6px 16px;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 6px rgba(79, 70, 229, 0.4);
    touch-action: manipulation;
    user-select: none;
    transition: transform 0.1s, box-shadow 0.15s;
    min-height: 36px;
}
.btn-print-action:hover {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.6);
}
.btn-print-action:active {
    transform: scale(0.97);
}

.btn-secondary {
    background: #334155;
    color: #f8fafc;
    border: 1px solid #64748b;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    user-select: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-height: 36px;
}
.btn-secondary:hover {
    background: #475569;
}

/* ==========================================================================
   用紙プレビュー領域 (画面表示時)
   ========================================================================== */
.preview-container {
    padding: 24px 0 60px 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
}

/* ==========================================================================
   A4用紙シート (A4縦: 210mm × 297mm)
   ========================================================================== */
.nameplate-sheet {
    width: 210mm;
    min-height: 297mm;
    background: #ffffff;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.5);
    margin: 0 auto;
    padding: 8.5mm 0; /* 上下8.5mm余白で70mm×4=280mmがピッタリ収まる */
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    box-sizing: border-box;
    position: relative;
    page-break-after: always;
    break-after: page;
}

/* シート上部・下部のハサミ切り取り補助ガイド (画面および印刷時) */
.cut-guide-vertical-left,
.cut-guide-vertical-right {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 0;
    border-left: 1px dashed #cbd5e1;
    pointer-events: none;
}
.cut-guide-vertical-left {
    left: 31.5mm; /* (210 - 147) / 2 */
}
.cut-guide-vertical-right {
    right: 31.5mm;
}

/* ==========================================================================
   ネームプレート単体 (横147mm × 縦70mm)
   ========================================================================== */
.nameplate-card {
    width: 147mm;
    height: 70mm;
    box-sizing: border-box;
    border: 1.5px dashed #475569; /* ハサミ切り取り線 */
    margin-bottom: -1.5px; /* 上下のボーダーを重ねて1本の破線にする */
    position: relative;
    background: #ffffff;
    padding: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    overflow: hidden;
}

/* 左上・右上エリア: 部屋名 (左) ＆ 下剤 (右) - プレートの端へおいやり＆薄いグレー */
.nameplate-top-row {
    position: absolute;
    top: 2mm;
    left: 3.5mm;
    right: 3.5mm;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    pointer-events: none;
    z-index: 2;
}

/* 部屋名表示 (純粋部屋名・薄いグレー・控えめサイズ) */
.room-val {
    font-size: 18pt;
    font-weight: 800;
    letter-spacing: 0.5px;
    line-height: 1;
    color: #94a3b8;
}

/* 下剤表示 (純粋下剤名・薄いグレー・控えめサイズ) */
.prep-val {
    font-size: 13.5pt;
    font-weight: 700;
    line-height: 1;
    color: #94a3b8;
    text-align: right;
}

/* 中央エリア: 患者名（苗字特大サイズ・天地中央・完全センタリング） */
.nameplate-name-row {
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    width: 100%;
    padding: 0 4mm;
    z-index: 1;
}

.patient-fullname {
    font-size: 65pt;
    font-weight: 800;
    color: #000000;
    line-height: 1.1;
    letter-spacing: 20px;
    margin-right: -20px; /* letter-spacingによる右余白を相殺して完全中央揃え */
    white-space: nowrap;
}

/* 下段エリア: 「様」を右下に配置（位置はそのまま、サイズを拡大） */
.nameplate-bottom-row {
    position: absolute;
    bottom: 2mm;
    right: 5mm;
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
    pointer-events: none;
    z-index: 2;
}

.honorific-title {
    font-size: 33pt;
    font-weight: 900;
    color: #0f172a;
    line-height: 1;
    letter-spacing: 0;
}

/* 余白プレート (手書き用実枠・様のみ印字) */
.nameplate-card.blank-card {
    background: #ffffff;
}

/* ==========================================================================
   印刷用スタイル (@media print)
   ========================================================================== */
@page {
    size: A4 portrait;
    margin: 0; /* ブラウザ標準余白をゼロにし、.nameplate-sheet 側で正確に制御 */
}

@media print {
    html, body {
        width: 210mm !important;
        height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        color: #000000 !important;
    }

    .no-print-bar {
        display: none !important;
    }

    .preview-container {
        padding: 0 !important;
        margin: 0 !important;
        gap: 0 !important;
        display: block !important;
    }

    /* A4用紙1枚（210mm × 297mm）に完全一致させ、はみ出しによる自動改ページを完全防止 */
    .nameplate-sheet {
        width: 210mm !important;
        height: 297mm !important;
        min-height: 297mm !important;
        max-height: 297mm !important;
        box-shadow: none !important;
        box-sizing: border-box !important;
        margin: 0 auto !important;
        padding: 8.5mm 0 !important; /* 上下8.5mm余白: 8.5 + 280 + 8.5 = 297mm ピッタリ一致 */
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: flex-start !important;
        overflow: hidden !important; /* ピクセル端数丸め誤差による余分なページ送りを完全防御 */
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: always !important;
        break-after: page !important;
    }

    .nameplate-sheet:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
    }

    /* 印刷時のハサミ切り取り線ガイドを鮮明に */
    .nameplate-card {
        border-color: #334155 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    .nameplate-card:last-child {
        margin-bottom: 0 !important;
    }
}
</style>
</head>
<body>

<!-- 画面上部 操作バー (非印刷) -->
<header class="no-print-bar">
    <div class="no-print-bar-left">
        <div class="bar-badge">
            横147mm × 縦70mm (A4 4枚面付け)
        </div>
        <div class="bar-badge" style="background:#0284c7; color:#ffffff;">
            対象: <?= $total_patients ?>名 (<?= $total_sheets ?>ページ)
        </div>
    </div>

    <div class="no-print-bar-right">
        <!-- 絞り込みフォーム -->
        <form id="filterForm" method="GET" style="display:flex; align-items:center; gap:6px;">
            <input type="hidden" name="date" value="<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>">

            <!-- 診療科 -->
            <label class="bar-form-group">
                <select name="dept" onchange="this.form.submit()">
                    <option value="ALL" <?= $dept === 'ALL' ? 'selected' : '' ?>>全診療科</option>
                    <option value="PROCT" <?= $dept === 'PROCT' ? 'selected' : '' ?>>肛門科</option>
                    <option value="GASTRO" <?= $dept === 'GASTRO' ? 'selected' : '' ?>>胃腸科</option>
                </select>
            </label>

            <!-- 並び順 -->
            <label class="bar-form-group">
                <select name="sort" onchange="this.form.submit()">
                    <option value="room_asc" <?= $sort_by === 'room_asc' ? 'selected' : '' ?>>🚪 部屋順</option>
                    <option value="time" <?= $sort_by === 'time' ? 'selected' : '' ?>>⏰ 時間枠順</option>
                </select>
            </label>
        </form>

        <!-- 🔤 フォント選択（完全オフライン・Windows標準搭載） -->
        <label class="printer-select-pill" title="ネームプレートのフォントを切り替えます（すべてWindows標準搭載・オフライン対応）">
            <span>🔤 フォント:</span>
            <select id="selectCardFont" onchange="changeCardFont(this.value)">
                <option value="biz-ud">BIZ UDPゴシック (推奨・収まり抜群)</option>
                <option value="meiryo">メイリオ (ふっくら肉厚)</option>
                <option value="yu-gothic">游ゴシック (端正)</option>
                <option value="ud-kyokasho">UD教科書体 (手書きの温かみ)</option>
            </select>
        </label>

        <!-- 🖨️ 出力先プリンタ選択（端末印刷 ⇔ サーバー直印刷） -->
        <label class="printer-select-pill" title="印刷出力先を選択します（端末の通常印刷、またはサーバーから院内プリンタへの直接印刷）">
            <span>🖨️ 出力先:</span>
            <select id="selectPrintDestination" onchange="onPrintDestinationChange(this.value)">
                <?= renderPrinterSelectOptions() ?>
            </select>
        </label>

        <!-- 印刷実行 ボタン -->
        <button type="button" id="btnNameplatePrintExecute" class="btn-print-action" onclick="executeNameplatePrint()">
            <span id="btnNameplatePrintText">🖨️ ネームプレート印刷</span>
        </button>

        <!-- 閉じる ボタン -->
        <button type="button" class="btn-secondary" onclick="window.close()">✕ 閉じる</button>
    </div>

    <!-- サーバー印刷 トースト通知 -->
    <div id="serverPrintToast" class="server-print-toast">
        <span id="toastIcon">ℹ️</span>
        <span id="toastMessage">メッセージ</span>
    </div>
</header>

<!-- プレビュー＆印刷用紙コンテナ -->
<main class="preview-container font-<?= htmlspecialchars($font_key, ENT_QUOTES, 'UTF-8') ?>">
    <?php if (empty($patients)): ?>
        <div style="background:#1e293b; color:#94a3b8; padding:40px; border-radius:12px; text-align:center; max-width:600px; margin-top:40px; border:1px solid #334155;">
            <p style="font-size:18px; font-weight:700; color:#f1f5f9; margin-bottom:8px;">対象の下部検査予約がありません</p>
            <p style="font-size:14px;">日付: <?= htmlspecialchars($formatted_date, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($dept_label, ENT_QUOTES, 'UTF-8') ?>)</p>
            <p style="font-size:12px; margin-top:12px; color:#64748b;">※患者名が登録されているTCF・ポリペク・W等の検査レコードが自動抽出されます。</p>
        </div>
    <?php else: ?>
        <?php foreach ($sheets as $sheet_idx => $sheet_patients): ?>
            <!-- A4用紙シート (縦4枚) -->
            <section class="nameplate-sheet" data-sheet-index="<?= $sheet_idx + 1 ?>">
                <!-- 縦切り取り補助ガイド線 (左右各31.5mm) -->
                <div class="cut-guide-vertical-left"></div>
                <div class="cut-guide-vertical-right"></div>

                <?php 
                // 4枠分ループ（患者データがある枠は印字、ない枠は空枠）
                for ($slot_idx = 0; $slot_idx < 4; $slot_idx++): 
                    $patient = $sheet_patients[$slot_idx] ?? null;
                    if ($patient):
                        $room_raw     = trim((string)($patient['room_number'] ?? ''));
                        $room_display = mb_convert_kana($room_raw, 'KV', 'UTF-8');
                        $prep_display = formatPrepName($patient['bowel_prep'] ?? '', $prep_labels);
                        $patient_name = trim((string)($patient['patient_name'] ?? ''));
                        $patient_id   = trim((string)($patient['patient_id'] ?? ''));
                        $display_name = resolveNameplateDisplayName($patient_name, $all_patient_names);
                ?>
                    <!-- ネームプレート (横147mm × 縦70mm) -->
                    <article class="nameplate-card">
                        <!-- 1. 上段: 部屋名 (左) ＆ 下剤 (右) - ラベルなし純粋グレー -->
                        <div class="nameplate-top-row">
                            <span class="room-val"><?= htmlspecialchars($room_display, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="prep-val"><?= htmlspecialchars($prep_display, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>

                        <!-- 2. 中段: 苗字表示 (特大フォント・同姓時は名前頭文字付与) -->
                        <div class="nameplate-name-row">
                            <span class="patient-fullname" title="患者氏名: <?= htmlspecialchars($patient_name, ENT_QUOTES, 'UTF-8') ?> (ID: <?= htmlspecialchars($patient_id, ENT_QUOTES, 'UTF-8') ?>)"><?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>

                        <!-- 3. 下段: 「様」を右下に揃えて配置 -->
                        <div class="nameplate-bottom-row">
                            <span class="honorific-title">様</span>
                        </div>
                    </article>
                <?php 
                    else: 
                ?>
                    <!-- 余白プレート (手書き対応用・様のみ印字) -->
                    <article class="nameplate-card blank-card" title="手書き用予備枠（様のみ印字されます）">
                        <!-- 下段: 「様」を右下に揃えて印字 -->
                        <div class="nameplate-bottom-row">
                            <span class="honorific-title">様</span>
                        </div>
                    </article>
                <?php 
                    endif;
                endfor; 
                ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<script>

/**
 * 端末判別 & 出力先プリンタ制御（Windows: 従来通り / タブレット: サーバー直印刷）
 */
var isWindows = navigator.platform.indexOf('Win') > -1 || navigator.userAgent.indexOf('Windows') > -1;
var isTablet = /iPad|iPhone|iPod|Android/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

document.addEventListener('DOMContentLoaded', function() {
    var selectDest = document.getElementById('selectPrintDestination');
    if (selectDest) {
        var savedDest = localStorage.getItem('endoscope_print_dest_nameplate');
        // Windowsなら従来通り 'local'、タブレットなら 'おの_C2570 複合機' がデフォルト
        var defaultDest = isWindows ? 'local' : 'おの_C2570 複合機';
        var activeDest = savedDest || defaultDest;

        var hasOption = Array.from(selectDest.options).some(function(opt) { return opt.value === activeDest; });
        if (hasOption) {
            selectDest.value = activeDest;
        } else {
            selectDest.value = 'local';
        }
        updatePrintButtonLabel(selectDest.value);
    }
});

function onPrintDestinationChange(val) {
    localStorage.setItem('endoscope_print_dest_nameplate', val);
    updatePrintButtonLabel(val);
}

function updatePrintButtonLabel(dest) {
    var btnText = document.getElementById('btnNameplatePrintText');
    if (!btnText) return;
    if (dest === 'local') {
        btnText.textContent = '🖨️ ネームプレート印刷';
    } else {
        btnText.textContent = '🖨️ サーバ直で印刷';
    }
}

/**
 * ネームプレート印刷実行（Windows端末印刷 ⇔ サーバー直接印刷の自動ハイブリッド）
 */
function executeNameplatePrint() {
    var selectDest = document.getElementById('selectPrintDestination');
    var dest = selectDest ? selectDest.value : 'local';

    if (dest === 'local') {
        // 【従来通り】ブラウザ標準の印刷ダイアログ
        setTimeout(function() {
            window.print();
        }, 50);
        return;
    }

    // 【サーバー直接印刷】指定プリンタへジョブ送信
    var btn = document.getElementById('btnNameplatePrintExecute');
    var btnText = document.getElementById('btnNameplatePrintText');
    var origText = btnText ? btnText.textContent : '🖨️ サーバ直で印刷';

    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
    }
    if (btnText) {
        btnText.textContent = '⏳ 送信中...';
    }

    var selectFont = document.getElementById('selectCardFont');
    var activeFont = selectFont ? selectFont.value : 'biz-ud';

    var formData = new FormData();
    formData.append('action', 'print');
    formData.append('target', 'nameplate');
    formData.append('printer_name', dest);
    formData.append('date', '<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>');
    formData.append('dept', '<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>');
    formData.append('sort', '<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>');
    formData.append('font', activeFont);

    fetch('api_server_print.php', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            showServerPrintToast(true, data.message || '印刷指示を送信しました');
        } else {
            showServerPrintToast(false, data.message || '印刷送信に失敗しました');
        }
    })
    .catch(function(err) {
        showServerPrintToast(false, '通信エラー: ' + err.message);
    })
    .finally(function() {
        if (btn) {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
        if (btnText) {
            btnText.textContent = origText;
        }
    });
}

function showServerPrintToast(isSuccess, message) {
    var toast = document.getElementById('serverPrintToast');
    var msgEl = document.getElementById('toastMessage');
    var iconEl = document.getElementById('toastIcon');
    if (!toast || !msgEl) {
        alert(message);
        return;
    }

    msgEl.textContent = message;
    iconEl.textContent = isSuccess ? '✅' : '⚠️';
    toast.className = 'server-print-toast ' + (isSuccess ? 'toast-success' : 'toast-error') + ' show';

    setTimeout(function() {
        toast.className = 'server-print-toast';
    }, 4000);
}

/**
 * フォント切り替え制御（Windows標準オフラインフォント・localStorage記憶）
 */
function changeCardFont(fontKey) {
    var preview = document.querySelector('.preview-container');
    if (!preview) return;
    preview.classList.remove('font-biz-ud', 'font-meiryo', 'font-yu-gothic', 'font-ud-kyokasho');
    preview.classList.add('font-' + fontKey);
    localStorage.setItem('endoscope_nameplate_font', fontKey);
}

document.addEventListener('DOMContentLoaded', function() {
    var selectFont = document.getElementById('selectCardFont');
    if (selectFont) {
        var savedFont = localStorage.getItem('endoscope_nameplate_font') || 'biz-ud';
        selectFont.value = savedFont;
        changeCardFont(savedFont);
    }
});

/**
 * 文字数に応じたフォントサイズ＆文字間隔の動的最適化（苗字特大化・中央バランス調整）
 */
window.addEventListener('DOMContentLoaded', function() {
    var cards = document.querySelectorAll('.nameplate-card:not(.blank-card)');
    cards.forEach(function(card) {
        var nameEl = card.querySelector('.patient-fullname');
        var samaEl = card.querySelector('.honorific-title');
        if (!nameEl) return;
        var text = nameEl.textContent.trim().replace(/\s+/g, '');
        var len = text.length;
        if (len <= 2) {
            nameEl.style.fontSize = '65pt';
            nameEl.style.letterSpacing = '20px';
            nameEl.style.marginRight = '-20px';
            if (samaEl) samaEl.style.fontSize = '33pt';
        } else if (len === 3) {
            nameEl.style.fontSize = '60pt';
            nameEl.style.letterSpacing = '14px';
            nameEl.style.marginRight = '-14px';
            if (samaEl) samaEl.style.fontSize = '33pt';
        } else if (len === 4) {
            nameEl.style.fontSize = '53pt';
            nameEl.style.letterSpacing = '8px';
            nameEl.style.marginRight = '-8px';
            if (samaEl) samaEl.style.fontSize = '31pt';
        } else if (len === 5) {
            nameEl.style.fontSize = '48pt';
            nameEl.style.letterSpacing = '5px';
            nameEl.style.marginRight = '-5px';
            if (samaEl) samaEl.style.fontSize = '29pt';
        } else {
            nameEl.style.fontSize = '41pt';
            nameEl.style.letterSpacing = '3px';
            nameEl.style.marginRight = '-3px';
            if (samaEl) samaEl.style.fontSize = '26pt';
        }
    });
});

</script>

</body>
</html>
