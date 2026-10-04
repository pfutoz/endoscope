<?php
/**
 * 生成日時: 2026-09-02 16:05:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\modal_edit.php
 * ファイル名: modal_edit.php
 * 
 * 予約登録・編集・削除 モーダルダイアログ
 * 修正: 2026-09-02
 *   - MALL電子カルテ SOAPデータ照会ビュー（patient_soap_view.php）を開くテスト用リンク「📄 SOAP」を設置
 *   - 備考定型文パレット（フローティングウィンドウ）実装済み
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

// -------------------------------------------------------------
// 非同期での定型文マスタ登録API (category='remarks_phrase')
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action']) && $_POST['api_action'] === 'add_remarks_phrase') {
    header('Content-Type: application/json; charset=utf-8');
    $phrase = trim($_POST['phrase'] ?? '');
    if ($phrase === '') {
        echo json_encode(['success' => false, 'message' => '登録する文字列が空です。']);
        exit;
    }
    try {
        $stmt_chk = $pdo->prepare("SELECT master_id FROM m_input_master WHERE category = 'remarks_phrase' AND item_value = :val");
        $stmt_chk->execute([':val' => $phrase]);
        if ($stmt_chk->fetch()) {
            echo json_encode(['success' => false, 'message' => '既に登録されている定型文です。']);
            exit;
        }

        $stmt_max = $pdo->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM m_input_master WHERE category = 'remarks_phrase'");
        $next_order = (int)$stmt_max->fetchColumn();

        $stmt_ins = $pdo->prepare("
            INSERT INTO m_input_master (category, item_value, display_order, created_at)
            VALUES ('remarks_phrase', :val, :disp, CURRENT_TIMESTAMP)
        ");
        $stmt_ins->execute([':val' => $phrase, ':disp' => $next_order]);

        echo json_encode(['success' => true, 'phrase' => $phrase]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$debug_mode = false;
$config_file = __DIR__ . '/debug_config.json';
if (file_exists($config_file)) {
    $conf = json_decode(file_get_contents($config_file), true);
    if (!empty($conf['debug_mode'])) {
        $debug_mode = true;
    }
}
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $debug_mode = true;
}

$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'GASTRO';
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$waku = isset($_GET['waku']) ? (string)$_GET['waku'] : '';

if (!in_array($dept, ['PROCT', 'GASTRO'], true)) { $dept = 'GASTRO'; }

$reservation = null;
if ($schedule_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id");
    $stmt->execute([':id' => $schedule_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reservation) {
        $dept = (string)$reservation['department_code'];
        $date = (string)$reservation['exam_date'];
        $waku = (string)$reservation['scheduled_time'];
    }
}

$waku_list = getWakuList($pdo, $dept);

function getMasterItems(PDO $pdo, string $category, array $default_items = []): array {
    try {
        $stmt = $pdo->prepare("
            SELECT item_value 
            FROM m_input_master 
            WHERE category = :cat 
            ORDER BY display_order ASC, master_id ASC
        ");
        $stmt->execute([':cat' => $category]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return (!empty($rows)) ? $rows : $default_items;
    } catch (Exception $e) {
        return $default_items;
    }
}

$exam_types_master      = getMasterItems($pdo, 'exam_type', ['GIF', 'エコー', 'TCF', 'ポリペク', 'W', '◆胃がん検診◆']);
$exam_locations_master  = getMasterItems($pdo, 'exam_location', ['指定なし']);
$remarks_phrases_master = getMasterItems($pdo, 'remarks_phrase', [
    '休薬確認済み', '生検あり', '車椅子来院', '前処置自宅', '同意書回収済み',
    '鎮静剤希望', '感染症あり', '朝食抜き確認済', 'ポリペク予定', '家族同伴'
]);

$curr_exam = '';
$curr_loc = '';
$curr_doctor = $reservation ? (string)($reservation['doctor_name'] ?? '') : '';

if ($reservation) {
    $curr_exam = (string)$reservation['exam_type'];
    $curr_loc = (string)$reservation['exam_location'];
} else {
    if (mb_strpos($waku, 'エコー') !== false || stripos($waku, 'ECHO') !== false) {
        $curr_exam = 'エコー';
    } elseif (mb_strpos($waku, 'TCF') !== false || mb_strpos($waku, '大腸') !== false) {
        $curr_exam = 'TCF';
    } elseif (mb_strpos($waku, 'GIF') !== false || mb_strpos($waku, '胃') !== false) {
        $curr_exam = 'GIF';
    } else {
        $curr_exam = '';
    }
    $curr_loc = '';
}

$mall_memo = null;
if ($reservation && !empty($reservation['patient_id'])) {
    $stmt_m = $pdo->prepare("SELECT * FROM t_mall_memo WHERE patient_id = :pid");
    $stmt_m->execute([':pid' => $reservation['patient_id']]);
    $mall_memo = $stmt_m->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $schedule_id > 0 ? '予約編集' : '新規予約登録' ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 8px 12px;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 12px;
            overflow-x: hidden;
        }
        .main-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 10px 14px;
            max-width: 980px;
            margin: 0 auto;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .header-title-area {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header-title-area h2 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
        }
        .dept-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            color: #fff;
        }
        .dept-badge.PROCT { background: #3b82f6; }
        .dept-badge.GASTRO { background: #10b981; }

        .schedule-id-badge {
            background: #334155;
            color: #f8fafc;
            font-family: Consolas, monospace;
            font-size: 11px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .btn-group-top {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .btn {
            padding: 4px 12px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-purple { background: #8b5cf6; color: #fff; }
        .btn-purple:hover { background: #7c3aed; }
        .btn-danger { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }
        .btn-danger:hover { background: #fca5a5; color: #991b1b; }
        .btn-secondary { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-teal { background: #0d9488; color: #fff; }
        .btn-teal:hover { background: #0f766e; }

        .panel-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 6px;
            position: relative;
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .grid-2col {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 10px;
        }

        .form-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
        }
        .form-row:last-child { margin-bottom: 0; }
        .form-label {
            width: 70px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            flex-shrink: 0;
        }
        .form-control {
            padding: 3px 6px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 11px;
            background: #fff;
            height: 26px;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.15);
        }
        textarea.form-control {
            height: auto;
            resize: none;
        }

        .patient-flex-row {
            display: flex;
            align-items: center;
            gap: 5px;
            width: 100%;
        }
        .attr-flex-row {
            display: flex;
            align-items: center;
            gap: 6px;
            width: 100%;
            margin-top: 6px;
        }

        .mall-memo-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
            margin-top: 4px;
        }
        .mall-memo-block {
            background: #fafaf9;
            border: 1px solid #e7e5e4;
            border-radius: 4px;
            padding: 4px 6px;
            height: 96px;
            display: flex;
            flex-direction: column;
        }
        .mall-memo-header {
            font-size: 10px;
            font-weight: 700;
            color: #78716c;
            border-bottom: 1px solid #e7e5e4;
            padding-bottom: 2px;
            margin-bottom: 2px;
        }
        .mall-memo-content {
            flex: 1;
            font-size: 10.5px;
            color: #292524;
            line-height: 1.35;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }

        .duplicate-alert-box {
            display: none;
            background: #fef2f2;
            border: 1.5px solid #dc2626;
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 6px;
            color: #991b1b;
            font-size: 11px;
            font-weight: 600;
        }

        .error-log-box {
            display: none;
            background: #fff1f2;
            border: 1.5px solid #e11d48;
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 8px;
            color: #9f1239;
            font-size: 11px;
            line-height: 1.4;
        }
        .error-log-header {
            font-weight: 800;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .error-log-body {
            font-family: Consolas, Monaco, monospace;
            background: #ffe4e6;
            padding: 6px;
            border-radius: 4px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 120px;
            overflow-y: auto;
        }

        .footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 6px;
            padding-top: 4px;
            border-top: 1px solid #e2e8f0;
        }

        /* 備考定型文 ポップアップパレット（ドラッグ対応・最前面） */
        .remarks-palette {
            display: none;
            position: fixed;
            top: 110px;
            right: 30px;
            width: 380px;
            background: #ffffff;
            border: 1.5px solid #3b82f6;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3), 0 8px 10px -6px rgba(0,0,0,0.2);
            z-index: 1000;
            padding: 8px 10px;
        }
        .palette-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 800;
            color: #1e40af;
            font-size: 11px;
            border-bottom: 1px solid #dbeafe;
            padding-bottom: 4px;
            margin-bottom: 6px;
            cursor: move;
            user-select: none;
            background: #f8fafc;
            margin: -8px -10px 6px -10px;
            padding: 6px 10px;
            border-radius: 6px 6px 0 0;
        }
        .palette-selection-bar {
            background: #eff6ff;
            border: 1px dashed #3b82f6;
            border-radius: 4px;
            padding: 4px 6px;
            margin-bottom: 6px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .palette-selection-text {
            font-weight: 700;
            color: #1d4ed8;
            font-size: 10.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .palette-search-input {
            width: 100%;
            height: 24px;
            padding: 2px 6px;
            font-size: 10.5px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            margin-bottom: 6px;
        }
        .palette-list-box {
            max-height: 140px;
            overflow-y: auto;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            padding: 4px;
            border: 1px solid #f1f5f9;
            border-radius: 4px;
            background: #f8fafc;
            margin-bottom: 6px;
        }
        .phrase-chip {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 2px 6px;
            font-size: 10.5px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            transition: all 0.1s ease;
            user-select: none;
        }
        .phrase-chip:hover {
            background: #dbeafe;
            border-color: #3b82f6;
            color: #1d4ed8;
        }
        .phrase-chip-copy {
            margin-left: 4px;
            color: #94a3b8;
            font-size: 10px;
            padding: 0 2px;
            cursor: pointer;
        }
        .phrase-chip-copy:hover {
            color: #2563eb;
        }
        .palette-add-bar {
            display: flex;
            gap: 4px;
            align-items: center;
            border-top: 1px solid #f1f5f9;
            padding-top: 6px;
        }
    </style>
</head>
<body>

<div class="main-card">
    <div class="header-bar">
        <div class="header-title-area">
            <span class="dept-badge <?= $dept ?>"><?= $dept === 'PROCT' ? '肛門科' : '胃腸科' ?></span>
            <h2><?= $schedule_id > 0 ? '予約編集' : '新規予約登録' ?></h2>
            <?php if ($debug_mode): ?>
                <span class="schedule-id-badge" title="t_endoscopy_schedule.schedule_id">[schedule_id:#<?= $schedule_id > 0 ? $schedule_id : '新規' ?>]</span>
            <?php endif; ?>
        </div>
        <div class="btn-group-top">
            <?php if ($schedule_id > 0): ?>
                <button type="button" class="btn btn-danger" onclick="deleteReservation(<?= $schedule_id ?>)">🗑️ 削除</button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" onclick="saveReservation(false)">💾 保存する</button>
            <button type="button" class="btn btn-secondary" onclick="window.close()">✕ 閉じる</button>
        </div>
    </div>

    <div id="duplicateAlert" class="duplicate-alert-box"></div>

    <div id="errorLogBox" class="error-log-box">
        <div class="error-log-header">
            <span>🚨 処理エラー詳細 (デバッグログ)</span>
            <button type="button" onclick="document.getElementById('errorLogBox').style.display='none'" style="border:none; background:none; cursor:pointer; font-weight:bold; color:#9f1239;">✕</button>
        </div>
        <div id="errorLogContent" class="error-log-body"></div>
    </div>

    <form id="editForm">
        <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
        <input type="hidden" name="department_code" id="f_dept" value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>">

        <!-- 上段：基本情報 + 備考 -->
        <div class="grid-2col">
            <div class="panel-box">
                <div class="panel-header">📌 基本情報 <span style="font-size:9px; color:#94a3b8;">変更注意</span></div>
                <div class="form-row">
                    <span class="form-label">検査日 *</span>
                    <input type="date" name="exam_date" id="exam_date" class="form-control" style="flex:1;" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>" required onchange="handleDateChange()">
                    <span id="day_of_week_label" style="font-weight:bold; font-size:11px; width:28px; text-align:center; color:#1e40af;"></span>
                </div>
                <div class="form-row">
                    <span class="form-label">時間枠 *</span>
                    <select name="scheduled_time" id="scheduled_time" class="form-control" style="flex:1;" required onchange="checkDuplicateAndLimits()">
                        <option value="">-- 選択 --</option>
                        <?php 
                        $waku_found = false;
                        foreach ($waku_list as $w): 
                            $w_name = (string)$w['waku_name'];
                            $sel = ($w_name === $waku) ? 'selected' : '';
                            if ($sel) $waku_found = true;
                        ?>
                            <option value="<?= htmlspecialchars($w_name, ENT_QUOTES, 'UTF-8') ?>" <?= $sel ?>><?= htmlspecialchars($w_name, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                        <?php if (!empty($waku) && !$waku_found): ?>
                            <option value="<?= htmlspecialchars($waku, ENT_QUOTES, 'UTF-8') ?>" selected>🚨 [未定義] <?= htmlspecialchars($waku, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-row">
                    <span class="form-label">診療科 *</span>
                    <div style="flex:1; display:flex; flex-direction:column;">
                        <select name="department_code_select" class="form-control" style="width:100%;" onchange="changeDept(this.value)">
                            <option value="PROCT" <?= $dept==='PROCT'?'selected':'' ?>>🟢 肛門科 (PROCT)</option>
                            <option value="GASTRO" <?= $dept==='GASTRO'?'selected':'' ?>>🔵 胃腸科 (GASTRO)</option>
                        </select>
                        <span style="font-size:8px; color:#dc2626; margin-top:2px;">※診療科の変更は暫定的にできない仕様です</span>
                    </div>
                </div>
            </div>

            <div class="panel-box">
                <div class="panel-header">
                    <span>📝 備考・特記事項</span>
                    <button type="button" class="btn btn-teal" style="padding:1px 8px; font-size:10px; height:20px;" onclick="toggleRemarksPalette()" title="定型文の入力・選択テキストのマスタ登録">📋 定型文パレット</button>
                </div>
                <textarea name="remarks" id="remarks" class="form-control" style="width:100%; height:98px;" placeholder="予約備考・連絡事項・特記事項・休薬・前処置などを入力"><?= htmlspecialchars((string)($reservation['remarks'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <!-- 中段：患者情報・検査割当 -->
        <div class="panel-box">
            <div class="panel-header">👤 患者情報・検査割当 <span style="font-size:9px; color:#dc2626;">必須項目</span></div>
            
            <div class="patient-flex-row">
                <input type="text" name="patient_id" id="patient_id" class="form-control" style="width:95px; flex-shrink:0;" value="<?= htmlspecialchars((string)($reservation['patient_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="例: 74819" onblur="lookupMallPatient(this.value)">
                
                <button type="button" class="btn" style="background:#f59e0b; color:#fff; width:62px; height:26px; padding:0; font-size:10.5px;" onclick="setTemporaryPatient()" title="仮予約として入力">⚡仮予約</button>
                
                <!-- ★テスト用：MALL電子カルテ SOAPデータ照会リンクボタン -->
                <button type="button" class="btn" style="background:#0284c7; color:#fff; width:64px; height:26px; padding:0; font-size:10.5px;" onclick="openMallSoapViewer()" title="MALL電子カルテのSOAP記録を表示 (テスト用)">📄 SOAP</button>
                
                <input type="text" name="patient_name" id="patient_name" class="form-control" style="width:140px; flex-shrink:0;" value="<?= htmlspecialchars((string)($reservation['patient_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="患者氏名" required>
                
                <button type="button" class="btn btn-primary" style="width:58px; height:26px; padding:0; font-size:10.5px;" onclick="openPatientSearch()">🔍 検索</button>
                
                <input type="text" name="patient_kana" id="patient_kana" class="form-control" style="flex:1; min-width:90px;" value="<?= htmlspecialchars((string)($reservation['patient_kana'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="フリガナ">
                
                <input type="date" name="birthday" id="birthday" class="form-control" style="width:120px; flex-shrink:0;" value="<?= htmlspecialchars((string)($reservation['birthday'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="attr-flex-row">
                <select name="doctor_name" id="doctor_name" class="form-control" style="width:140px; flex-shrink:0;" onchange="checkDuplicateAndLimits()">
                    <option value="">-- 指定なし（未定） --</option>
                    <?php if (!empty($curr_doctor)): ?>
                        <option value="<?= htmlspecialchars($curr_doctor, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($curr_doctor, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endif; ?>
                </select>

                <select name="exam_type" id="exam_type" class="form-control" style="width:140px; flex-shrink:0;" onchange="handleExamTypeChange(this.value)">
                    <option value="">-- 検査種別 --</option>
                    <?php foreach ($exam_types_master as $etype): 
                        $sel = ($curr_exam === $etype) ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($etype, ENT_QUOTES, 'UTF-8') ?>" <?= $sel ?>><?= htmlspecialchars($etype, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <?php if (!empty($curr_exam) && !in_array($curr_exam, $exam_types_master, true)): ?>
                        <option value="<?= htmlspecialchars($curr_exam, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($curr_exam, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endif; ?>
                </select>

                <input type="text" name="arrival_time" id="arrival_time" class="form-control" style="width:130px; flex-shrink:0;" value="<?= htmlspecialchars((string)($reservation['arrival_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="来院時刻 (例: 8:45)">

                <select name="exam_location" id="exam_location" class="form-control" style="flex:1;">
                    <option value="">-- 実施場所 --</option>
                    <?php foreach ($exam_locations_master as $loc): 
                        $sel = ($curr_loc === $loc) ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?>" <?= $sel ?>><?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <?php if (!empty($curr_loc) && !in_array($curr_loc, $exam_locations_master, true)): ?>
                        <option value="<?= htmlspecialchars($curr_loc, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($curr_loc, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <!-- 下段：MALL同期メモ -->
        <div class="panel-box" style="margin-bottom:0;">
            <div class="panel-header">
                <span>📋 MALL同期メモ (電子カルテ連携)</span>
                <button type="button" class="btn btn-secondary" style="padding:1px 6px; font-size:10px;" onclick="reloadMallMemo()">🔄 再読込</button>
            </div>
            
            <div class="mall-memo-container">
                <div class="mall-memo-block">
                    <div class="mall-memo-header">本日メモ</div>
                    <div class="mall-memo-content" id="memo_today"><?= !empty($mall_memo['memo_today']) ? htmlspecialchars($mall_memo['memo_today'], ENT_QUOTES, 'UTF-8') : '<span style="color:#94a3b8;">なし</span>' ?></div>
                </div>
                <div class="mall-memo-block">
                    <div class="mall-memo-header">カルテメモ</div>
                    <div class="mall-memo-content" id="memo_karte"><?= !empty($mall_memo['memo_karte']) ? htmlspecialchars($mall_memo['memo_karte'], ENT_QUOTES, 'UTF-8') : '<span style="color:#94a3b8;">なし</span>' ?></div>
                </div>
                <div class="mall-memo-block">
                    <div class="mall-memo-header">患者メモ</div>
                    <div class="mall-memo-content" id="memo_patient"><?= !empty($mall_memo['memo_patient']) ? htmlspecialchars($mall_memo['memo_patient'], ENT_QUOTES, 'UTF-8') : '<span style="color:#94a3b8;">なし</span>' ?></div>
                </div>
            </div>
        </div>

        <!-- フッター操作エリア -->
        <div class="footer-bar">
            <button type="button" class="btn btn-secondary" onclick="window.close()">✕ 閉じる</button>
            <div style="display:flex; gap:6px;">
                <button type="button" class="btn btn-purple" onclick="saveReservation(true)">🖨️ 保存して印刷</button>
                <button type="button" class="btn btn-primary" onclick="saveReservation(false)">💾 保存する</button>
            </div>
        </div>
    </form>
</div>

<!-- 備考定型文ポップアップパレット（ドラッグ移動対応） -->
<div id="remarksPalette" class="remarks-palette">
    <div class="palette-header" id="paletteDragHeader">
        <span>📋 備考定型文パレット <span style="font-size:9.5px; font-weight:normal; color:#64748b;">(ドラッグ移動可)</span></span>
        <button type="button" onclick="closeRemarksPalette()" style="border:none; background:none; cursor:pointer; font-weight:bold; color:#64748b; font-size:13px;">✕</button>
    </div>

    <div id="paletteSelectionBar" class="palette-selection-bar" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:10px; color:#475569;">💡 選択中のテキスト:</span>
            <button type="button" class="btn btn-primary" style="padding:1px 6px; font-size:9.5px; height:18px;" onclick="saveSelectedTextAsMaster()">➕ マスタ登録</button>
        </div>
        <div id="paletteSelectionText" class="palette-selection-text"></div>
    </div>

    <input type="text" id="paletteSearch" class="palette-search-input" placeholder="🔍 定型文を絞り込み..." oninput="filterPhrases(this.value)">

    <div id="paletteListBox" class="palette-list-box">
        <?php foreach ($remarks_phrases_master as $phrase): ?>
            <span class="phrase-chip" data-phrase="<?= htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') ?>" onclick="insertPhrase(this.getAttribute('data-phrase'))">
                ➕ <?= htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') ?>
                <span class="phrase-chip-copy" onclick="copyPhraseToInput(event, this.parentElement.getAttribute('data-phrase'))" title="新規登録欄へコピー">📋</span>
            </span>
        <?php endforeach; ?>
    </div>

    <div class="palette-add-bar">
        <input type="text" id="newPhraseInput" class="form-control" style="flex:1; height:24px; font-size:10.5px;" placeholder="新しい定型文を直接登録...">
        <button type="button" class="btn btn-primary" style="height:24px; padding:0 8px; font-size:10.5px;" onclick="addNewPhraseFromInput()">登録</button>
    </div>
</div>

<script>
const weekJa = ['日', '月', '火', '水', '木', '金', '土'];
let currentSelectedDoctor = <?= json_encode($curr_doctor, JSON_UNESCAPED_UNICODE) ?>;
let selectedRemarksText = '';

// ★テスト用：MALL SOAPデータ照会ウィンドウ表示
function openMallSoapViewer() {
    const pidInput = document.getElementById('patient_id');
    const pid = pidInput ? pidInput.value.trim() : '';
    if (!pid) {
        alert('カルテ番号を入力または検索してから「SOAP」ボタンを押してください。');
        if (pidInput) pidInput.focus();
        return;
    }
    const url = 'patient_soap_view.php?patient_no=' + encodeURIComponent(pid);
    window.open(url, 'mall_soap_view', 'width=920,height=800,scrollbars=yes,resizable=yes');
}

function showScreenError(title, detail) {
    const box = document.getElementById('errorLogBox');
    const content = document.getElementById('errorLogContent');
    content.innerText = `[${new Date().toLocaleTimeString()}] ${title}\n` + (typeof detail === 'object' ? JSON.stringify(detail, null, 2) : detail);
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updateDayOfWeek() {
    const val = document.getElementById('exam_date').value;
    const label = document.getElementById('day_of_week_label');
    if (!val) { label.innerText = ''; return; }
    const d = new Date(val);
    if (!isNaN(d)) {
        const w = d.getDay();
        label.innerText = `(${weekJa[w]})`;
        label.style.color = (w === 0) ? '#ef4444' : (w === 4 ? '#1e40af' : '#1e293b');
    }
}

function handleDateChange() {
    updateDayOfWeek();
    updateDoctorDropdown();
    checkDuplicateAndLimits();
}

function updateDoctorDropdown() {
    const date = document.getElementById('exam_date').value;
    const dept = document.getElementById('f_dept').value || 'GASTRO';
    const select = document.getElementById('doctor_name');
    const keepVal = select.value || currentSelectedDoctor || '';

    if (!date) {
        select.innerHTML = '<option value="">-- 指定なし（未定） --</option>';
        return;
    }

    fetch(`get_present_doctors.php?date=${encodeURIComponent(date)}&dept=${encodeURIComponent(dept)}`)
        .then(async res => {
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch(e) {
                data = { doctors: [] };
            }

            let doctors = (data && Array.isArray(data.doctors)) ? data.doctors : [];
            let optionsHtml = '<option value="">-- 指定なし（未定） --</option>';
            let valueExists = false;

            doctors.forEach(d => {
                if (d) {
                    const isSel = (d === keepVal);
                    if (isSel) valueExists = true;
                    optionsHtml += `<option value="${escapeHtml(d)}" ${isSel ? 'selected' : ''}>${escapeHtml(d)}</option>`;
                }
            });

            if (keepVal && !valueExists) {
                optionsHtml += `<option value="${escapeHtml(keepVal)}" selected>⚠️ [マスタ外/不在] ${escapeHtml(keepVal)}</option>`;
            }

            select.innerHTML = optionsHtml;
        })
        .catch(() => {
            let fallbackHtml = '<option value="">-- 指定なし（未定） --</option>';
            if (keepVal) {
                fallbackHtml += `<option value="${escapeHtml(keepVal)}" selected>${escapeHtml(keepVal)}</option>`;
            }
            select.innerHTML = fallbackHtml;
        });
}

function setTemporaryPatient() {
    document.getElementById('patient_id').value = '';
    document.getElementById('patient_name').value = '※仮予約';
    document.getElementById('patient_kana').value = 'カリヨヤク';
    document.getElementById('birthday').value = '';
}

function handleExamTypeChange(etype) {}

function changeDept(newDept) {
    const url = new URL(window.location.href);
    url.searchParams.set('dept', newDept);
    window.location.href = url.toString();
}

function openPatientSearch() {
    window.open('patient_search.php', '_blank', 'width=1000,height=700,scrollbars=yes,resizable=yes');
}

function selectPatientFromSearch(patientNo, patientName, birthday, kana) {
    document.getElementById('patient_id').value = patientNo || '';
    document.getElementById('patient_name').value = patientName || '';
    document.getElementById('patient_kana').value = kana || '';
    document.getElementById('birthday').value = (birthday && birthday.length >= 10) ? birthday.substring(0, 10) : (birthday || '');
    
    reloadMallMemo();
    checkDuplicateAndLimits();
}

function lookupMallPatient(pid) {
    if (!pid || pid.trim() === '') return;
    const cleanPid = pid.trim();
    
    fetch(`get_mall_patient.php?patient_no=${encodeURIComponent(cleanPid)}`)
        .then(async res => {
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                showScreenError('MALL患者照会 JSONパースエラー', raw);
                return;
            }
            if (data && data.success) {
                if (data.patient_name) document.getElementById('patient_name').value = data.patient_name;
                if (data.patient_kana) document.getElementById('patient_kana').value = data.patient_kana;
                if (data.birthday) document.getElementById('birthday').value = (data.birthday.length >= 10) ? data.birthday.substring(0, 10) : data.birthday;
                reloadMallMemo();
                checkDuplicateAndLimits();
            }
        })
        .catch(err => {
            showScreenError('MALL患者照会 Fetch通信エラー', err.toString());
        });
}

function reloadMallMemo() {
    const pid = document.getElementById('patient_id').value.trim();
    if (!pid) return;
    fetch(`sync_mall_memo.php?patient_id=${encodeURIComponent(pid)}`)
        .then(async res => {
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (e) { return; }
            if (data && data.success) {
                document.getElementById('memo_today').innerHTML = data.memo_today ? escapeHtml(data.memo_today) : '<span style="color:#94a3b8;">なし</span>';
                document.getElementById('memo_karte').innerHTML = data.memo_karte ? escapeHtml(data.memo_karte) : '<span style="color:#94a3b8;">なし</span>';
                document.getElementById('memo_patient').innerHTML = data.memo_patient ? escapeHtml(data.memo_patient) : '<span style="color:#94a3b8;">なし</span>';
            }
        })
        .catch(() => {});
}

function checkDuplicateAndLimits() {
    const pid = document.getElementById('patient_id').value.trim();
    const date = document.getElementById('exam_date').value;
    const sid = document.querySelector('input[name="schedule_id"]').value;
    if (!pid || !date) return;

    fetch(`check_duplicate_ajax.php?patient_id=${encodeURIComponent(pid)}&exam_date=${encodeURIComponent(date)}&exclude_id=${encodeURIComponent(sid)}`)
        .then(async res => {
            const raw = await res.text();
            let data = null;
            try {
                data = JSON.parse(raw);
            } catch (e) { return; }
            const alertBox = document.getElementById('duplicateAlert');
            if (data && data.has_duplicate) {
                alertBox.innerText = `⚠️ 同日に別予約が存在します: ${data.detail}`;
                alertBox.style.display = 'block';
            } else {
                alertBox.style.display = 'none';
            }
        })
        .catch(() => {});
}

// -------------------------------------------------------------
// 備考・特記事項 定型文パレット操作 & ドラッグ移動
// -------------------------------------------------------------
function toggleRemarksPalette() {
    const palette = document.getElementById('remarksPalette');
    if (palette.style.display === 'block') {
        palette.style.display = 'none';
        return;
    }

    const textarea = document.getElementById('remarks');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    selectedRemarksText = textarea.value.substring(start, end).trim();

    const selectionBar = document.getElementById('paletteSelectionBar');
    const selectionTextEl = document.getElementById('paletteSelectionText');
    if (selectedRemarksText.length > 0) {
        selectionTextEl.innerText = selectedRemarksText;
        selectionBar.style.display = 'flex';
    } else {
        selectionBar.style.display = 'none';
    }

    palette.style.display = 'block';
    document.getElementById('paletteSearch').value = '';
    filterPhrases('');
}

function closeRemarksPalette() {
    document.getElementById('remarksPalette').style.display = 'none';
}

function insertPhrase(phrase) {
    if (!phrase) return;
    const textarea = document.getElementById('remarks');
    const current = textarea.value.trim();
    if (current === '') {
        textarea.value = phrase;
    } else {
        textarea.value = current + '\n' + phrase;
    }
    textarea.focus();
}

function copyPhraseToInput(event, phrase) {
    event.stopPropagation();
    const input = document.getElementById('newPhraseInput');
    input.value = phrase;
    input.focus();
}

function filterPhrases(keyword) {
    const kw = keyword.toLowerCase().trim();
    const chips = document.querySelectorAll('#paletteListBox .phrase-chip');
    chips.forEach(chip => {
        const p = (chip.getAttribute('data-phrase') || '').toLowerCase();
        if (kw === '' || p.includes(kw)) {
            chip.style.display = 'inline-flex';
        } else {
            chip.style.display = 'none';
        }
    });
}

function saveSelectedTextAsMaster() {
    if (!selectedRemarksText) return;
    registerNewPhraseToDb(selectedRemarksText);
}

function addNewPhraseFromInput() {
    const input = document.getElementById('newPhraseInput');
    const val = input.value.trim();
    if (!val) {
        alert('定型文を入力してください。');
        return;
    }
    registerNewPhraseToDb(val);
}

function registerNewPhraseToDb(phrase) {
    const fd = new FormData();
    fd.append('api_action', 'add_remarks_phrase');
    fd.append('phrase', phrase);

    fetch('modal_edit.php', { method: 'POST', body: fd })
        .then(async res => {
            const data = await res.json();
            if (data && data.success) {
                const listBox = document.getElementById('paletteListBox');
                const chip = document.createElement('span');
                chip.className = 'phrase-chip';
                chip.setAttribute('data-phrase', data.phrase);
                chip.onclick = function() { insertPhrase(data.phrase); };
                chip.innerHTML = `➕ ${escapeHtml(data.phrase)} <span class="phrase-chip-copy" title="新規登録欄へコピー">📋</span>`;
                chip.querySelector('.phrase-chip-copy').onclick = function(e) {
                    copyPhraseToInput(e, data.phrase);
                };
                listBox.appendChild(chip);

                document.getElementById('newPhraseInput').value = '';
                document.getElementById('paletteSelectionBar').style.display = 'none';
                selectedRemarksText = '';
                alert(`「${data.phrase}」をマスタに登録しました。`);
            } else {
                alert('マスタ登録失敗: ' + (data.message || 'エラーが発生しました'));
            }
        })
        .catch(err => {
            alert('通信エラーが発生しました: ' + err.toString());
        });
}

// パレットのドラッグ＆ドロップ移動処理
(function initDraggablePalette() {
    const palette = document.getElementById('remarksPalette');
    const dragHeader = document.getElementById('paletteDragHeader');
    let isDragging = false;
    let startX, startY, initialLeft, initialTop;

    dragHeader.addEventListener('mousedown', function(e) {
        if (e.target.tagName === 'BUTTON') return;
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;

        const rect = palette.getBoundingClientRect();
        initialLeft = rect.left;
        initialTop = rect.top;

        palette.style.right = 'auto';
        palette.style.left = initialLeft + 'px';
        palette.style.top = initialTop + 'px';

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    });

    function onMouseMove(e) {
        if (!isDragging) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        palette.style.left = (initialLeft + dx) + 'px';
        palette.style.top = (initialTop + dy) + 'px';
    }

    function onMouseUp() {
        isDragging = false;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
    }
})();

function saveReservation(andPrint) {
    const form = document.getElementById('editForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    const fd = new FormData(form);
    const sid = parseInt(form.schedule_id.value, 10) || 0;
    
    const actionName = (sid > 0) ? 'update' : 'insert';
    fd.append('action_type', actionName);
    fd.append('action', actionName);

    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(async res => {
            const rawText = await res.text();
            let data = null;
            try {
                data = JSON.parse(rawText);
            } catch (e) {
                showScreenError('JSONパースエラー (PHP構文エラー等の生出力)', rawText);
                alert('サーバーから不正なレスポンスが返されました。画面上のエラーログを確認してください。');
                return;
            }

            if (data && data.success) {
                const targetSid = data.schedule_id || sid;
                if (andPrint && targetSid > 0) {
                    window.open(`print_patient_slip.php?id=${targetSid}`, '_blank');
                }
                notifyParentAndClose();
            } else {
                const errMsg = (data && data.message) ? data.message : '詳細不明のエラー';
                showScreenError('保存エラー (サーバー応答)', data || rawText);
                alert('保存に失敗しました: ' + errMsg);
            }
        })
        .catch(err => {
            showScreenError('Fetch 通信エラー', err.toString());
            alert('保存通信エラーが発生しました。');
        });
}

function deleteReservation(sid) {
    if (!confirm('この予約を削除してもよろしいですか？')) return;
    const fd = new FormData();
    fd.append('action_type', 'delete');
    fd.append('action', 'delete');
    fd.append('schedule_id', sid);

    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(async res => {
            const rawText = await res.text();
            let data = null;
            try {
                data = JSON.parse(rawText);
            } catch (e) {
                showScreenError('削除時 JSONパースエラー', rawText);
                return;
            }

            if (data && data.success) {
                notifyParentAndClose();
            } else {
                showScreenError('削除エラー (サーバー応答)', data || rawText);
                alert('削除に失敗しました: ' + (data.message || '不明なエラー'));
            }
        })
        .catch(err => {
            showScreenError('削除 Fetch 通信エラー', err.toString());
            alert('削除通信エラーが発生しました');
        });
}

function notifyParentAndClose() {
    sessionStorage.setItem('need_reload', '1');
    if (window.opener && !window.opener.closed) {
        try {
            window.opener.postMessage('reservation_updated', '*');
            window.opener.location.reload();
        } catch(e) {}
    }
    window.close();
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
    updateDayOfWeek();
    updateDoctorDropdown();
    
    const pidInput = document.getElementById('patient_id');
    pidInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            lookupMallPatient(this.value);
        }
    });

    if (pidInput.value) {
        checkDuplicateAndLimits();
    }
});
</script>

</body>
</html>