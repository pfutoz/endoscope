<?php
/**
 * 生成日時: 2026-07-16 00:25:00
 * 内視鏡シート印刷モジュール（schedule_id 修正・完全版）
 */
ini_set('display_errors', "1");
error_reporting(E_ALL); 

// 1. データベース接続設定 (内視鏡予約用 PostgreSQL)
$host     = 'localhost'; 
$dbname   = 'endoscope'; 
$user     = 'postgres'; 
$password = 'postgres'; 

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;"; 
    $pdo = new PDO($dsn, $user, $password, [ 
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, 
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, 
    ]); 
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage()); 
}

// 外部化した小野会専用カレンダーヘルパーを読み込み
require_once 'calendar_helper_onokai.php'; 

// 2. 渡された schedule_id の取得（カンマ区切りまたは単一ID）
$id_param = isset($_GET['id']) ? trim($_GET['id']) : '';
if (empty($id_param)) {
    die("エラー: 印刷対象のスケジュールIDが指定されていません。");
}

// カンマ区切りの文字列を安全な整数の配列に変換
$raw_ids = explode(',', $id_param);
$schedule_ids = [];
foreach ($raw_ids as $val) {
    $clean_id = (int)trim($val);
    if ($clean_id > 0) {
        $schedule_ids[] = $clean_id;
    }
}

if (empty($schedule_ids)) {
    die("エラー: 有効なスケジュールIDがありません。");
}

// 3. データベースから該当する予約データを取得（schedule_id でIN句検索）
$placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
$sql = "SELECT * FROM t_endoscopy_schedule WHERE schedule_id IN ($placeholders) ORDER BY exam_date ASC, waku_no ASC, scheduled_time ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($schedule_ids);
$patients = $stmt->fetchAll();

if (empty($patients)) {
    die("エラー: 指定されたIDの予約データが見つかりませんでした。");
}

// 年齢計算関数
function calculateAge($birthday, $examDate) {
    if (empty($birthday) || $birthday === '---') return '---'; 
    try {
        $birth = new DateTime($birthday); 
        $exam = !empty($examDate) ? new DateTime($examDate) : new DateTime(); 
        if ($birth > $exam) return '---'; 
        return $exam->diff($birth)->y . '歳'; 
    } catch (Exception $e) { return '---'; } 
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>内視鏡検査シート印刷</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            color: #000;
            background-color: #fff;
            margin: 0;
            padding: 0;
            font-size: 11pt;
            line-height: 1.3;
        }
        
        .sheet-page {
            width: 190mm;
            min-height: 281mm;
            margin: 0 auto;
            box-sizing: border-box;
            page-break-after: always;
            position: relative;
            background: #fff;
            padding-bottom: 10mm;
        }
        .sheet-page:last-child {
            page-break-after: auto;
        }

        .no-print-bar {
            background: #2c3e50;
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: sans-serif;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .print-btn {
            background-color: #27ae60;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }
        .print-btn:hover { background-color: #219653; }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }
        .clinic-title {
            font-size: 14pt;
            font-weight: bold;
        }
        .dept-checkboxes {
            font-size: 11pt;
            font-weight: bold;
        }

        .sheet-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 10pt;
        }
        .sheet-table th, .sheet-table td {
            border: 1px solid #000;
            padding: 5px 6px;
            vertical-align: middle;
        }
        .sheet-table th {
            background-color: #f2f2f2;
            text-align: center;
            font-weight: bold;
        }

        .highlight-yellow {
            background-color: #ffff00 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .section-title {
            background-color: #e6e6e6;
            font-weight: bold;
            padding: 4px 8px;
            border: 1px solid #000;
            border-bottom: none;
            font-size: 10pt;
            margin-top: 8px;
        }

        @media print {
            .no-print-bar { display: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div>
        <strong>📋 内視鏡シート印刷プレビュー</strong> 
        （対象件数: <strong><?=count($patients)?>件</strong>）
    </div>
    <button type="button" class="print-btn" onclick="window.print();">🖨️ この内容で印刷する</button>
</div>

<?php foreach ($patients as $p): 
    $exam_date = $p['exam_date'] ?? '';
    $age_str = calculateAge($p['birthday'] ?? '', $exam_date);
    $is_proct = ($p['department_code'] === 'PROCT');
?>
<div class="sheet-page">
    
    <div class="sheet-header">
        <div class="clinic-title">おの肛門科胃腸科内視鏡クリニック</div>
        <div class="dept-checkboxes">
            [ <?= $is_proct ? '☑' : '□' ?> ] 肛門科 &nbsp;&nbsp;&nbsp; 
            [ <?= !$is_proct ? '☑' : '□' ?> ] 胃腸科
        </div>
    </div>

    <table class="sheet-table">
        <tr>
            <th style="width: 12%;">患者No</th>
            <td style="width: 23%; font-weight: bold; font-size: 12pt; text-align: center;" class="highlight-yellow">
                <?= htmlspecialchars($p['patient_id'] ?? '---', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <th style="width: 10%;">氏名</th>
            <td style="width: 35%; font-weight: bold; font-size: 13pt;" class="highlight-yellow">
                <?= htmlspecialchars($p['patient_name'] ?? '（氏名未登録）', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <th style="width: 8%;">性別</th>
            <td style="width: 12%; text-align: center;">
                <?= htmlspecialchars($p['gender'] ?? '---', ENT_QUOTES, 'UTF-8') ?>
            </td>
        </tr>
        <tr>
            <th>生年月日</th>
            <td style="text-align: center;">
                <?= htmlspecialchars($p['birthday'] ?? '---', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <th>年齢</th>
            <td style="text-align: center; font-weight: bold;">
                <?= $age_str ?>
            </td>
            <th>検査日</th>
            <td style="text-align: center; font-weight: bold;">
                <?= htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8') ?>
            </td>
        </tr>
    </table>

    <table class="sheet-table" style="font-size: 9.5pt;">
        <tr>
            <td style="width: 50%;">
                <strong>付添：</strong>無 ・ 有（     ）<br>
                <strong>迎え・タクシー・車：</strong>（      ）
            </td>
            <td style="width: 50%;">
                <strong>荷物：</strong>無 ・ 有（     ）<br>
                <strong>内服：</strong>無 ・ 有（     ）<br>
                <strong>TVセット・入院セット：</strong> 特( ) 個( ) 大( )
            </td>
        </tr>
    </table>

    <div class="section-title">■ 前処置・下剤チェック欄</div>
    <table class="sheet-table">
        <tr>
            <th style="width: 35%;">薬剤名 / 処置</th>
            <th style="width: 15%;">数量・規格</th>
            <th>実施状況・特記事項</th>
        </tr>
        <tr>
            <td>グリセリン浣腸液 50%</td>
            <td style="text-align: center;">60ml / 120ml</td>
            <td><span class="highlight-yellow" style="padding: 2px 10px;">[ ] 実施 &nbsp;&nbsp;&nbsp; 時間：(  :  )</span></td>
        </tr>
        <tr>
            <td>高圧浣腸 グリセリン</td>
            <td style="text-align: center;">100ml</td>
            <td>[ ] 実施</td>
        </tr>
        <tr>
            <td>マグコロール散 68%</td>
            <td style="text-align: center;">50g / 100g / 150g</td>
            <td>服薬状況：(            )</td>
        </tr>
        <tr>
            <td>ニフレック / モビプレップ</td>
            <td style="text-align: center;">1袋 / 2L</td>
            <td>排便状況：(            )</td>
        </tr>
    </table>

    <div class="section-title">■ 問診・安全確認事項</div>
    <table class="sheet-table" style="font-size: 9pt;">
        <tr>
            <td style="width: 50%;">
                ・排便回数：昨日（ ）・今日（ ） 最終（   ）<br>
                ・便秘： 無 ・ 有 / 難聴： 無 ・ 有<br>
                ・感染症： HB（ ）・HC（ ）・RPR（ ）□未
            </td>
            <td style="width: 50%;">
                ・抗凝固薬： □無 □有 （休薬： / ～ ）<br>
                ・糖尿病薬： □無 □有 （休薬： / ～ ）<br>
                ・インスリン：□無 □有 （休薬： / ～ ）<br>
                ・シャント・乳腺手術：□無 □有（右・左）
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <strong>当日内服・血圧：</strong> 血圧： 済 ・ 未 &nbsp; BP（   /   ） &nbsp;&nbsp; 
                <strong>その他特記事項：</strong> <?= htmlspecialchars($p['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            </td>
        </tr>
    </table>

    <div class="section-title">■ 検査・薬剤投与記録（当日）</div>
    <table class="sheet-table">
        <tr>
            <th style="width: 35%;">項目</th>
            <th style="width: 20%; text-align: center;">予定 / 数量</th>
            <th>実施記録・サイン</th>
        </tr>
        <tr>
            <td>検査種別</td>
            <td style="text-align: center; font-weight: bold;" class="highlight-yellow">
                <?= htmlspecialchars($p['exam_type'] ?? '---', ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td>場所: <strong><?= htmlspecialchars($p['exam_location'] ?? '---', ENT_QUOTES, 'UTF-8') ?></strong> / 担当医: <strong><?= htmlspecialchars($p['doctor_name'] ?? '---', ENT_QUOTES, 'UTF-8') ?></strong></td>
        </tr>
        <tr>
            <td>プロフォール 1％</td>
            <td style="text-align: center;"> mg</td>
            <td></td>
        </tr>
        <tr>
            <td>ジアゼパム 10mg</td>
            <td style="text-align: center;"> ml</td>
            <td></td>
        </tr>
        <tr>
            <td>ブスコパン 20mg / グルカゴン</td>
            <td style="text-align: center;"> 1ml / 1V</td>
            <td>採血：あり ・ なし / アル綿禁：[ ]</td>
        </tr>
    </table>

    <div class="section-title">■ 所見・メモ・特記事項</div>
    <div style="border: 1px solid #000; height: 35mm; padding: 5px; font-size: 9pt;">
        <?php if (!empty($p['remarks'])): ?>
            予約時備考: <?= htmlspecialchars($p['remarks'], ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </div>

    <div style="display: flex; justify-content: space-between; font-size: 8pt; margin-top: 5px; color: #555;">
        <div>スケジュールID: #<?= (int)$p['schedule_id'] ?> (枠番: <?= htmlspecialchars($p['waku_no'] ?? '---', ENT_QUOTES, 'UTF-8') ?>)</div>
        <div>おの肛門科胃腸科内視鏡クリニック 診療記録シート</div>
    </div>

</div>
<?php endforeach; ?>

</body>
</html>