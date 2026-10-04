<?php
/**
 * ファイル名: followup_edit.php
 * 設置パス: /endoscope/followup_edit.php
 * 生成日時: 2026-08-17 17:00:00 JST
 * 
 * フォローアップ登録・編集画面
 * 
 * 機能:
 *   - フォローアップデータの新規登録・編集
 *   - 患者検索連携（patient_search.php）
 *   - 実施済み検査の複数選択（GIF/TCF/エコー/ポリペク/その他）
 *   - 検査別フォローアップ期間の設定（GIF=1年、TCF=3年など）
 *   - 次回来院日の柔軟な入力（確定日/〜以降/〜頃/第○週/最終/未定）
 *   - 来院目的・来院方法の選択
 *   - 特記事項（患者への伝達事項）の入力
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成（たたき台）
 *   - 2026-08-17: 患者検索連携を実装
 *   - 2026-08-17: 検査別フォローアップ項目を動的生成
 *   - 2026-08-17: 日付タイプ切替機能を実装
 *   - 2026-08-17: 編集画面で既存データを正しく表示するよう修正
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

// メッセージ
$msg = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$error = isset($_GET['error']) ? (int)$_GET['error'] : 0;

$data = [
    'followup_id' => 0,
    'patient_id' => '',
    'patient_name' => '',
    'patient_kana' => '',
    'birthday' => '',
    'department_code' => 'GASTRO',
    'doctor_name' => '',
    'exam_types' => '[]',
    'exam_types_display' => '',
    'next_visit_date' => '',
    'next_visit_display' => '',
    'next_visit_date_type' => 'exact',
    'next_visit_pattern' => 'none',
    'next_visit_week_num' => 1,
    'next_visit_day_of_week' => 6,
    'followup_items' => '[]',
    'visit_purpose' => 'next_exam',
    'visit_method' => 'reserved',
    'visit_time' => '',
    'notes' => '',
    'issued_date' => date('Y-m-d'),
    'is_printed' => false
];

if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM t_followup WHERE followup_id = :id");
    $stmt->execute([':id' => $id]);
    $fetched = $stmt->fetch();
    if ($fetched) {
        $data = $fetched;
    } else {
        $is_edit = false;
    }
}

$purpose_labels = [
    'followup' => '経過観察',
    'tissue_result' => '組織検査結果説明',
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
$exam_types = json_decode($data['exam_types'] ?? '[]', true);
$followup_items = json_decode($data['followup_items'] ?? '[]', true);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_edit ? 'フォローアップ編集' : 'フォローアップ新規登録' ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #333;
            font-size: 13px;
        }
        .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; }
        h2 { margin-top: 0; border-bottom: 2px solid #34495e; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .header-links { display: flex; gap: 8px; }
        .header-links a { padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-secondary { background: #6c757d; color: #fff; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn { padding: 6px 16px; border: none; border-radius: 4px; font-weight: bold; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-block; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: span 2; }
        .form-group label { font-weight: bold; margin-bottom: 4px; font-size: 12px; color: #444; }
        .form-group input, .form-group select, .form-group textarea {
            padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; box-sizing: border-box;
        }
        .form-group textarea { resize: vertical; font-family: inherit; }
        .form-group .help-text { font-size: 10px; color: #94a3b8; margin-top: 3px; }

        .exam-checkbox-group { display: flex; gap: 12px; flex-wrap: wrap; padding: 4px 0; }
        .exam-checkbox-group label { font-weight: normal; display: flex; align-items: center; gap: 4px; cursor: pointer; }
        .exam-checkbox-group label input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }

        .followup-item-row { display: flex; align-items: center; gap: 12px; padding: 6px 8px; background: #f8fafc; border-radius: 4px; margin-bottom: 6px; border: 1px solid #e2e8f0; flex-wrap: wrap; }
        .followup-item-row select, .followup-item-row input { padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; }

        .btn-row { display: flex; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0; flex-wrap: wrap; }

        .info-box { background: #f8fafc; padding: 12px 16px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px; }
        .info-box .label { font-weight: bold; color: #475569; }

        .msg { padding: 10px 16px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .msg-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
            .followup-item-row { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<div class="container">
    <h2>
        <?= $is_edit ? '✏️ フォローアップ編集' : '➕ フォローアップ新規登録' ?>
        <div class="header-links">
            <a href="followup_list.php" class="btn-secondary" style="color:#fff;">📋 一覧へ</a>
            <a href="index.php" class="btn-primary" style="color:#fff;">🏠 メニューへ</a>
        </div>
    </h2>

    <?php if ($msg): ?>
        <div class="msg <?= $error ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="action_followup.php">
        <input type="hidden" name="followup_id" value="<?= $data['followup_id'] ?>">
        <input type="hidden" name="action" value="<?= $is_edit ? 'update' : 'insert' ?>">

        <!-- 患者情報 -->
        <div class="info-box">
            <div class="label">👤 患者情報</div>
            <div class="form-grid" style="margin-top:8px;">
                <div class="form-group">
                    <label>カルテID <span style="color:#dc2626;">*</span></label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" name="patient_id" id="f_pid" value="<?= htmlspecialchars($data['patient_id'], ENT_QUOTES, 'UTF-8') ?>" required style="flex:1;">
                        <button type="button" onclick="openPatientSearch()" style="padding:5px 14px; background:#3b82f6; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px; white-space:nowrap;">🔍 検索</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>患者氏名 <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="patient_name" id="f_pname" value="<?= htmlspecialchars($data['patient_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="form-group">
                    <label>患者ヨミガナ</label>
                    <input type="text" name="patient_kana" id="f_kana" value="<?= htmlspecialchars($data['patient_kana'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label>生年月日</label>
                    <input type="date" name="birthday" id="f_birthday" value="<?= htmlspecialchars($data['birthday'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label>診療科</label>
                    <select name="department_code">
                        <option value="PROCT" <?= ($data['department_code'] ?? '') === 'PROCT' ? 'selected' : '' ?>>肛門科</option>
                        <option value="GASTRO" <?= ($data['department_code'] ?? '') === 'GASTRO' ? 'selected' : '' ?>>胃腸科</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>担当医師</label>
                    <input type="text" name="doctor_name" value="<?= htmlspecialchars($data['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例：誠吾">
                </div>
            </div>
        </div>

        <!-- 実施済み検査 -->
        <div class="form-group full-width">
            <label>実施済み検査</label>
            <div class="exam-checkbox-group" id="examCheckboxGroup">
                <label><input type="checkbox" name="exam_types[]" value="GIF" <?= in_array('GIF', $exam_types) ? 'checked' : '' ?> onchange="updateFollowupItems()"> GIF</label>
                <label><input type="checkbox" name="exam_types[]" value="TCF" <?= in_array('TCF', $exam_types) ? 'checked' : '' ?> onchange="updateFollowupItems()"> TCF</label>
                <label><input type="checkbox" name="exam_types[]" value="エコー" <?= in_array('エコー', $exam_types) ? 'checked' : '' ?> onchange="updateFollowupItems()"> エコー</label>
                <label><input type="checkbox" name="exam_types[]" value="ポリペク" <?= in_array('ポリペク', $exam_types) ? 'checked' : '' ?> onchange="updateFollowupItems()"> ポリペク</label>
                <label><input type="checkbox" name="exam_types[]" value="その他" <?= in_array('その他', $exam_types) ? 'checked' : '' ?> onchange="updateFollowupItems()"> その他</label>
            </div>
            <div id="examDisplayPreview" style="font-size:12px; color:#1e40af; font-weight:bold; margin-top:4px;"></div>
            <input type="hidden" name="exam_types_json" id="f_exam_types_json" value="<?= htmlspecialchars($data['exam_types'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="exam_types_display" id="f_exam_types_display" value="<?= htmlspecialchars($data['exam_types_display'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <!-- 検査別フォローアップ -->
        <div class="form-group full-width" id="followupItemsArea" style="display:<?= empty($exam_types) ? 'none' : 'block' ?>;">
            <label>📋 検査別フォローアップ期間</label>
            <div id="followupItemsContainer">
                <?php foreach ($followup_items as $item): ?>
                    <div class="followup-item-row" data-exam="<?= htmlspecialchars($item['exam'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <span style="font-weight:bold; min-width:60px;"><?= htmlspecialchars($item['exam'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="followup_interval_<?= htmlspecialchars($item['exam'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px;">
                            <option value="3ヶ月" <?= ($item['interval'] ?? '') === '3ヶ月' ? 'selected' : '' ?>>3ヶ月後</option>
                            <option value="6ヶ月" <?= ($item['interval'] ?? '') === '6ヶ月' ? 'selected' : '' ?>>6ヶ月後</option>
                            <option value="1年" <?= ($item['interval'] ?? '') === '1年' ? 'selected' : '' ?>>1年後</option>
                            <option value="2年" <?= ($item['interval'] ?? '') === '2年' ? 'selected' : '' ?>>2年後</option>
                            <option value="3年" <?= ($item['interval'] ?? '') === '3年' ? 'selected' : '' ?>>3年後</option>
                            <option value="5年" <?= ($item['interval'] ?? '') === '5年' ? 'selected' : '' ?>>5年後</option>
                            <option value="カスタム" <?= ($item['interval'] ?? '') === 'カスタム' ? 'selected' : '' ?>>カスタム</option>
                        </select>
                        <input type="date" name="followup_date_<?= htmlspecialchars($item['exam'] ?? '', ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($item['next_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; width:140px;">
                        <span style="font-size:10px; color:#94a3b8;">（空の場合は計算）</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="followup_items_json" id="f_followup_items_json" value="<?= htmlspecialchars($data['followup_items'] ?? '[]', ENT_QUOTES, 'UTF-8') ?>">
            <div class="help-text">※ 各検査ごとに次回の目安期間を設定してください</div>
        </div>

        <!-- 次回来院 -->
        <div class="form-group full-width" style="margin-top:10px;">
            <label>次回来院日 <span style="color:#dc2626;">*</span></label>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:6px;">
                <select id="visitDateType" name="next_visit_date_type" onchange="toggleDateInput(this.value)" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px;">
                    <option value="exact" <?= ($data['next_visit_date_type'] ?? 'exact') === 'exact' ? 'selected' : '' ?>>確定日</option>
                    <option value="after" <?= ($data['next_visit_date_type'] ?? '') === 'after' ? 'selected' : '' ?>>〜以降</option>
                    <option value="around" <?= ($data['next_visit_date_type'] ?? '') === 'around' ? 'selected' : '' ?>>〜頃（アバウト）</option>
                    <option value="weekly" <?= ($data['next_visit_date_type'] ?? '') === 'weekly' ? 'selected' : '' ?>>毎週○曜日</option>
                    <option value="monthly_week" <?= ($data['next_visit_date_type'] ?? '') === 'monthly_week' ? 'selected' : '' ?>>第○週○曜日</option>
                    <option value="monthly_last" <?= ($data['next_visit_date_type'] ?? '') === 'monthly_last' ? 'selected' : '' ?>>最終○曜日</option>
                    <option value="undecided" <?= ($data['next_visit_date_type'] ?? '') === 'undecided' ? 'selected' : '' ?>>未定（後日調整）</option>
                </select>
            </div>

            <!-- 日付ピッカー（exact / after） -->
            <div id="datePickerArea">
                <input type="date" name="next_visit_date" id="f_next_date" value="<?= htmlspecialchars($data['next_visit_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px;">
                <span id="dateSuffix" style="font-size:13px; color:#64748b;"></span>
            </div>

            <!-- 曜日パターン（weekly / monthly_week / monthly_last） -->
            <div id="weekdayPatternArea" style="display:none; display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:6px;">
                <span style="font-size:12px;">毎月</span>
                <select id="fWeekNum" name="next_visit_week_num" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px;">
                    <option value="1" <?= ($data['next_visit_week_num'] ?? 1) == 1 ? 'selected' : '' ?>>第1</option>
                    <option value="2" <?= ($data['next_visit_week_num'] ?? 1) == 2 ? 'selected' : '' ?>>第2</option>
                    <option value="3" <?= ($data['next_visit_week_num'] ?? 1) == 3 ? 'selected' : '' ?>>第3</option>
                    <option value="4" <?= ($data['next_visit_week_num'] ?? 1) == 4 ? 'selected' : '' ?>>第4</option>
                    <option value="5" <?= ($data['next_visit_week_num'] ?? 1) == 5 ? 'selected' : '' ?>>第5</option>
                </select>
                <select id="fDayOfWeek" name="next_visit_day_of_week" style="padding:6px 10px; border:1px solid #ccc; border-radius:4px;">
                    <option value="0" <?= ($data['next_visit_day_of_week'] ?? 6) == 0 ? 'selected' : '' ?>>日曜日</option>
                    <option value="1" <?= ($data['next_visit_day_of_week'] ?? 6) == 1 ? 'selected' : '' ?>>月曜日</option>
                    <option value="2" <?= ($data['next_visit_day_of_week'] ?? 6) == 2 ? 'selected' : '' ?>>火曜日</option>
                    <option value="3" <?= ($data['next_visit_day_of_week'] ?? 6) == 3 ? 'selected' : '' ?>>水曜日</option>
                    <option value="4" <?= ($data['next_visit_day_of_week'] ?? 6) == 4 ? 'selected' : '' ?>>木曜日</option>
                    <option value="5" <?= ($data['next_visit_day_of_week'] ?? 6) == 5 ? 'selected' : '' ?>>金曜日</option>
                    <option value="6" <?= ($data['next_visit_day_of_week'] ?? 6) == 6 ? 'selected' : '' ?>>土曜日</option>
                </select>
                <span id="weekdayPreview" style="font-size:12px; color:#1e40af; font-weight:bold;"></span>
            </div>

            <!-- テキスト入力（around / undecided） -->
            <div id="dateTextArea" style="display:none; margin-top:6px;">
                <input type="text" name="next_visit_display" id="f_next_display" value="<?= htmlspecialchars($data['next_visit_display'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width:100%; padding:6px 10px; border:1px solid #ccc; border-radius:4px;" placeholder="例：2029年3月頃">
            </div>
            <input type="hidden" name="next_visit_pattern" id="f_next_pattern" value="<?= htmlspecialchars($data['next_visit_pattern'] ?? 'none', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <!-- 来院目的・方法 -->
        <div class="form-grid">
            <div class="form-group">
                <label>来院目的 <span style="color:#dc2626;">*</span></label>
                <select name="visit_purpose">
                    <?php foreach ($purpose_labels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($data['visit_purpose'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>来院方法 <span style="color:#dc2626;">*</span></label>
                <select name="visit_method">
                    <?php foreach ($method_labels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($data['visit_method'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>来院時間（任意）</label>
                <input type="text" name="visit_time" value="<?= htmlspecialchars($data['visit_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例：9:00">
            </div>
            <div class="form-group">
                <label>発行日</label>
                <input type="date" name="issued_date" value="<?= htmlspecialchars($data['issued_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <!-- 特記事項 -->
        <div class="form-group full-width">
            <label>特記事項（患者への伝達事項など）</label>
            <textarea name="notes" rows="3" style="width:100%;"><?= htmlspecialchars($data['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <!-- ボタン -->
        <div class="btn-row">
            <button type="submit" class="btn btn-success">💾 保存する</button>
            <button type="button" class="btn btn-secondary" onclick="history.back()">戻る</button>
            <?php if ($is_edit): ?>
                <a href="followup_print.php?id=<?= $data['followup_id'] ?>" target="_blank" class="btn btn-warning" style="color:#fff;">🖨️ 印刷</a>
                <a href="action_followup.php?action=delete&id=<?= $data['followup_id'] ?>" class="btn btn-danger" onclick="return confirm('本当に削除しますか？')">🗑️ 削除</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
// 患者検索
function openPatientSearch() {
    const url = 'patient_search.php';
    window.open(url, '_blank', 'width=1000,height=700,scrollbars=yes,resizable=yes');
}

// 検索画面からのコールバック
function selectPatientFromSearch(patientNo, patientName, birthday, kana) {
    document.getElementById('f_pid').value = patientNo || '';
    document.getElementById('f_pname').value = patientName || '';
    document.getElementById('f_kana').value = kana || '';
    document.getElementById('f_birthday').value = birthday || '';
}

// 検査種別選択 → フォローアップ項目を動的生成
function updateFollowupItems() {
    const checks = document.querySelectorAll('input[name="exam_types[]"]:checked');
    const area = document.getElementById('followupItemsArea');
    const container = document.getElementById('followupItemsContainer');
    
    if (checks.length === 0) {
        area.style.display = 'none';
        document.getElementById('examDisplayPreview').textContent = '（選択なし）';
        document.getElementById('f_exam_types_json').value = '[]';
        document.getElementById('f_exam_types_display').value = '';
        document.getElementById('f_followup_items_json').value = '[]';
        return;
    }
    
    area.style.display = 'block';
    container.innerHTML = '';
    
    const values = Array.from(checks).map(c => c.value);
    document.getElementById('f_exam_types_json').value = JSON.stringify(values);
    
    // 表示テキスト
    let display = values.join(' + ');
    if (values.includes('GIF') && values.includes('TCF')) {
        display += '（ダブル）';
    }
    document.getElementById('examDisplayPreview').textContent = '→ ' + display;
    document.getElementById('f_exam_types_display').value = display;
    
    // フォローアップ項目
    const items = [];
    checks.forEach(chk => {
        const exam = chk.value;
        const div = document.createElement('div');
        div.className = 'followup-item-row';
        div.dataset.exam = exam;
        div.innerHTML = `
            <span style="font-weight:bold; min-width:60px;">${exam}</span>
            <select name="followup_interval_${exam}" data-exam="${exam}" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px;">
                <option value="3ヶ月">3ヶ月後</option>
                <option value="6ヶ月">6ヶ月後</option>
                <option value="1年" selected>1年後</option>
                <option value="2年">2年後</option>
                <option value="3年">3年後</option>
                <option value="5年">5年後</option>
                <option value="カスタム">カスタム</option>
            </select>
            <input type="date" name="followup_date_${exam}" data-exam="${exam}" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; width:140px;" placeholder="任意">
            <span style="font-size:10px; color:#94a3b8;">（空の場合は計算）</span>
        `;
        container.appendChild(div);
        
        // 初期値（既存データがあれば設定）
        const existing = <?= json_encode($followup_items) ?>;
        if (existing && Array.isArray(existing)) {
            const found = existing.find(item => item.exam === exam);
            if (found) {
                const select = div.querySelector('select');
                const input = div.querySelector('input[type="date"]');
                if (select && found.interval) select.value = found.interval;
                if (input && found.next_date) input.value = found.next_date;
            }
        }
    });
    
    // hiddenに保存（送信時に再生成）
    document.getElementById('f_followup_items_json').value = JSON.stringify(items);
}

// 日付タイプ切替
function toggleDateInput(type) {
    const picker = document.getElementById('datePickerArea');
    const textArea = document.getElementById('dateTextArea');
    const patternArea = document.getElementById('weekdayPatternArea');
    const dateInput = document.getElementById('f_next_date');
    const textInput = document.getElementById('f_next_display');
    const suffix = document.getElementById('dateSuffix');
    const patternInput = document.getElementById('f_next_pattern');
    const preview = document.getElementById('weekdayPreview');
    
    picker.style.display = 'none';
    textArea.style.display = 'none';
    patternArea.style.display = 'none';
    dateInput.required = false;
    textInput.required = false;
    suffix.textContent = '';
    
    switch(type) {
        case 'exact':
            picker.style.display = 'block';
            dateInput.required = true;
            patternInput.value = 'none';
            break;
        case 'after':
            picker.style.display = 'block';
            dateInput.required = true;
            suffix.textContent = '以降';
            patternInput.value = 'none';
            break;
        case 'weekly':
            patternArea.style.display = 'flex';
            patternInput.value = 'weekly';
            updateWeekdayPreview();
            break;
        case 'monthly_week':
            patternArea.style.display = 'flex';
            patternInput.value = 'monthly_week';
            updateWeekdayPreview();
            break;
        case 'monthly_last':
            patternArea.style.display = 'flex';
            patternInput.value = 'monthly_last';
            updateWeekdayPreview();
            break;
        case 'around':
            textArea.style.display = 'block';
            textInput.required = true;
            textInput.placeholder = '例：2029年3月頃';
            patternInput.value = 'none';
            break;
        case 'undecided':
            textArea.style.display = 'block';
            textInput.required = true;
            textInput.placeholder = '例：後日調整';
            if (!textInput.value) textInput.value = '後日調整';
            patternInput.value = 'none';
            break;
    }
}

function updateWeekdayPreview() {
    const weekNum = document.getElementById('fWeekNum').value;
    const dow = document.getElementById('fDayOfWeek').value;
    const days = ['日', '月', '火', '水', '木', '金', '土'];
    const type = document.getElementById('f_next_pattern').value;
    const preview = document.getElementById('weekdayPreview');
    
    let text = '';
    switch(type) {
        case 'weekly':
            text = '毎週 ' + days[dow] + '曜日';
            break;
        case 'monthly_week':
            text = '毎月 第' + weekNum + days[dow] + '曜日';
            break;
        case 'monthly_last':
            text = '毎月 最終' + days[dow] + '曜日';
            break;
        default:
            text = '';
    }
    preview.textContent = text ? '→ ' + text : '';
}

// 初期化
document.addEventListener('DOMContentLoaded', function() {
    // 検査選択状態を反映
    updateFollowupItems();
    
    // 日付タイプを反映
    const typeSelect = document.getElementById('visitDateType');
    if (typeSelect) {
        toggleDateInput(typeSelect.value);
    }
    
    // 曜日パターン変更イベント
    document.getElementById('fWeekNum')?.addEventListener('change', updateWeekdayPreview);
    document.getElementById('fDayOfWeek')?.addEventListener('change', updateWeekdayPreview);
});

// 送信前にフォローアップ項目をJSON化
document.querySelector('form').addEventListener('submit', function(e) {
    const items = [];
    document.querySelectorAll('.followup-item-row').forEach(row => {
        const exam = row.dataset.exam || row.querySelector('span')?.textContent?.trim() || '';
        const select = row.querySelector('select');
        const input = row.querySelector('input[type="date"]');
        if (exam && select) {
            items.push({
                exam: exam,
                interval: select.value,
                next_date: input ? input.value : ''
            });
        }
    });
    document.getElementById('f_followup_items_json').value = JSON.stringify(items);
});
</script>
</body>
</html>