<?php
/**
 * 生成日時: 2026-09-29 14:33:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\master_maintenance.php
 * ファイル名: master_maintenance.php
 * 
 * 汎用選択肢マスタメンテナンス（監査ログ＆ワンクリック復元・破綻防止ガイド搭載版）
 * 修正履歴:
 *   - 2026-07-15: 初版作成（シーケンス修復機能搭載）
 *   - 2026-09-01: 操作履歴クリーンアップツール搭載
 *   - 2026-09-29: 
 *       1. 右上ナビを slot_master_maintenance.php 準拠（h2内 home-btn）に刷新
 *       2. 関連マスタ（検査枠マスタ）への独立リンクを新設
 *       3. 新規カテゴリ作成フォームを廃止し、プルダウンを「英名（日本語補足）」に整理
 *       4. カテゴリ選択直下に「影響範囲と破綻リスク案内パネル」（exam_type/doctorは警告強調）を新設
 *       5. 削除確認ダイアログを「削除すると既存データと不整合が起きる可能性があります」に強化
 *       6. 画面最下部に「📜 マスタ変更履歴・復元（直近20件）」および「🗑️ 履歴一括削除」機能を新設
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

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

    require_once __DIR__ . '/lib/master_audit_helper.php';

    // 定義済み有効カテゴリ一覧（英名 ➔ 日本語名・危険度フラグ）
    $category_master_defs = [
        'exam_type'      => ['label' => 'exam_type（検査種別）',        'danger' => true],
        'doctor'         => ['label' => 'doctor（医師名）',           'danger' => true],
        'toilet_room'    => ['label' => 'toilet_room（マイトイレ部屋番号）', 'danger' => false],
        'bowel_prep'     => ['label' => 'bowel_prep（前処置下剤）',      'danger' => false],
        'arrival_time'   => ['label' => 'arrival_time（来院時間）',     'danger' => false],
        'exam_location'  => ['label' => 'exam_location（検査場所）',    'danger' => false],
        'remarks_phrase' => ['label' => 'remarks_phrase（備考パレット定型文）', 'danger' => false],
        'toiawase'       => ['label' => 'toiawase（案内票問い合わせ先）', 'danger' => false],
    ];

    // DB内に存在するカテゴリを取得
    $db_categories = $pdo->query("SELECT DISTINCT category FROM m_input_master WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
    
    // 定義済み順序を優先しつつ、DB内の全カテゴリを網羅
    $categories = [];
    foreach ($category_master_defs as $k => $info) {
        if (in_array($k, $db_categories, true) || true) {
            $categories[$k] = $info['label'];
        }
    }
    // 未定義のカテゴリがもしDBにあれば追加
    foreach ($db_categories as $dbc) {
        if (!isset($categories[$dbc])) {
            $categories[$dbc] = $dbc . '（その他）';
        }
    }

    // カテゴリ別の説明・影響範囲・システム破綻リスクの案内文辞書
    $category_guides = [
        'exam_type' => [
            'title'       => '⚠️ 【極めて危険・変更注意】検査種別マスタ',
            'desc'        => '予約管理システム全体の基幹となる検査種別（GIF、TCF、エコー、ポリペク等）の定義です。',
            'impact'      => '予約登録モーダル（modal_edit）、月間予約枠カレンダー（month_schedule）、日次進行一覧、患者案内票、統計集計など全システムに直結しています。',
            'risk'        => 'システム内部のプログラムコードで「GIF」「TCF」「エコー」などの文字列が直接参照され、枠数計算や色分け処理が行われています。既存の種別名称を書き換えたり削除すると、予約枠の計算不良やカレンダー上の表示消滅など重大なシステム破綻を招きます。新規種別の追加や表示順の調整にとどめてください。',
            'is_critical' => true
        ],
        'doctor' => [
            'title'       => '⚠️ 【極めて危険・変更注意】担当医師マスタ',
            'desc'        => '予約枠や診療科に紐づく担当医師の表示名マスタです。',
            'impact'      => '予約画面、月間カレンダー、および検査枠マスタ（m2_doctor、m2_doctor_slot_pattern）と完全連動しています。',
            'risk'        => 'ここで医師名を安易に変更・削除すると、「検査枠マスタメンテナンス」に登録されている枠数パターンや過去・未来の予約データとの紐付けが切れてデータ不整合が発生します。医師の登録・名称管理は、ページ上部の「⏱️ 検査枠マスタメンテナンス」画面を基準として行ってください。',
            'is_critical' => true
        ],
        'toilet_room' => [
            'title'       => 'ℹ️ マイトイレ部屋番号マスタ',
            'desc'        => '大腸内視鏡検査等の患者に割り当てる専用個室・トイレ部屋番号（ﾏ101〜ﾏ207等）の選択肢です。',
            'impact'      => '当日進行管理モニター（progress_monitor）、部屋番号一括割当画面（bulk_edit_prep_room）、予約編集モーダルに影響します。',
            'risk'        => '使用中の部屋番号を削除したり書き換えると、既存予約の部屋割り表示と食い違いが生じます。新しい部屋番号の追加や、優先表示順（並び順）の変更は安全に行えます。',
            'is_critical' => false
        ],
        'bowel_prep' => [
            'title'       => 'ℹ️ 前処置下剤マスタ',
            'desc'        => '検査前に患者が服用する前処置用下剤（モビ、マグP、ニフ、ビジクリア等）の選択肢です。',
            'impact'      => '予約編集モーダル、部屋・下剤一括編集画面、日次印刷一覧に影響します。',
            'risk'        => '既存の下剤名を削除すると、予約データ上でその下剤が選ばれている場合に不整合を起こします。新しい下剤の追加や、よく使う順への並べ替え（表示順）は安全に行えます。',
            'is_critical' => false
        ],
        'arrival_time' => [
            'title'       => 'ℹ️ 来院時間マスタ',
            'desc'        => '予約登録時に患者へ案内する標準来院時間（来院8：30、来院9：00等）の選択肢です。',
            'impact'      => '予約登録・編集モーダル、患者案内票印刷（print_patient_slip）の選択肢に影響します。',
            'risk'        => '選択肢の追加や表示順の調整は安全に行えます。既存の選択肢を変更・削除してもシステム全体の破綻はありませんが、案内票の印字内容に反映されます。',
            'is_critical' => false
        ],
        'exam_location' => [
            'title'       => 'ℹ️ 検査場所マスタ',
            'desc'        => '検査を実施する部屋（内視鏡室、胃腸科1、胃腸科2、op室等）の選択肢です。',
            'impact'      => '予約登録・編集モーダル、内視鏡検査表印刷、患者案内票に影響します。',
            'risk'        => '新規場所の追加や表示順の変更は安全です。既存場所を削除・変更すると、既存予約の場所表示が変わるためご注意ください。',
            'is_critical' => false
        ],
        'remarks_phrase' => [
            'title'       => '✅ 備考パレット定型文マスタ（安全に編集可能）',
            'desc'        => '予約編集モーダルの備考欄下に並ぶ、ワンクリック入力用の定型文チップ（感染症あり、車いす、禁：ブスコパン等）です。',
            'impact'      => '予約登録・編集モーダル（modal_edit）の備考パレットにのみ影響します。',
            'risk'        => '【極めて安全】追加・文言変更・削除を自由に行ってもシステムロジックへの悪影響はありません。（※予約入力画面の備考欄横にある「✏️ 編集」「🗑️ 削除」から直接編集することも可能です）',
            'is_critical' => false
        ],
        'toiawase' => [
            'title'       => '✅ 案内票問い合わせ先マスタ（安全に編集可能）',
            'desc'        => '患者案内票（A4用紙）の最下部に印刷される、各診療科の直通電話番号・案内メッセージです。',
            'impact'      => '患者案内票印刷（print_patient_slip）の問い合わせ先欄にのみ影響します。',
            'risk'        => '【極めて安全】電話番号の変更や診療受付時間の文言修正を安全に行うことができます。',
            'is_critical' => false
        ],
    ];

    // 操作履歴（Audit Log）の集計情報
    $audit_total_count = 0;
    $audit_old_count = 0;
    try {
        $audit_total_count = (int)$pdo->query("SELECT COUNT(*) FROM t_schedule_audit_log")->fetchColumn();
        $audit_old_count   = (int)$pdo->query("SELECT COUNT(*) FROM t_schedule_audit_log WHERE created_at < CURRENT_TIMESTAMP - INTERVAL '1 year'")->fetchColumn();
    } catch (Exception $e) {
        // 無視
    }

    $selected_category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $op_mode = isset($_GET['op_mode']) ? trim($_GET['op_mode']) : 'edit';
    
    // アイテム取得
    $items = [];
    if (!empty($selected_category)) {
        $stmt = $pdo->prepare("SELECT master_id, item_value, display_order FROM m_input_master WHERE category = :category ORDER BY display_order ASC, master_id ASC");
        $stmt->execute([':category' => $selected_category]);
        $items = $stmt->fetchAll();
        
        if (empty($items) && $op_mode !== 'add') {
            $op_mode = 'add';
        }
    }

    if ($op_mode !== 'edit' && $op_mode !== 'add') { $op_mode = 'edit'; }

    // 汎用マスタの最新変更履歴を取得（直近20件）
    $recent_master_logs = getMasterAuditLogs($pdo, 'input_master', 20);

} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

$message = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$is_error = isset($_GET['error']) ? (int)$_GET['error'] : 0;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>汎用選択肢マスタメンテナンス</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; margin: 20px; background-color: #f4f6f9; color: #333; font-size: 13px; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        
        /* slot_master_maintenance.php 準拠のヘッダー */
        h2 { margin-top: 0; color: #2c3e50; border-bottom: 2px solid #34495e; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .header-nav { display: flex; gap: 10px; align-items: center; }
        .home-btn { background: #2c3e50; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: bold; }
        .home-btn:hover { background: #34495e; }
        
        h3 { margin-top: 0; color: #2980b9; font-size: 15px; border-left: 4px solid #3498db; padding-left: 10px; }
        .step-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 18px; margin-bottom: 20px; }
        .flex-group { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .mode-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #cbd5e1; }
        .mode-tab { padding: 9px 22px; text-decoration: none; font-weight: bold; border-radius: 6px 6px 0 0; background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; border-bottom: none; margin-bottom: -2px; font-size: 13px; transition: all 0.2s; }
        .mode-tab:hover { background: #e2e8f0; }
        .mode-tab.active { background: #fff; color: #0f172a; border-top: 3px solid #3b82f6; border-bottom: 2px solid #fff; padding-bottom: 10px; z-index: 2; }
        
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 10px; font-size: 13px; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 12px; text-align: left; }
        th { background-color: #f1f5f9; color: #334155; font-weight: bold; }
        tr:hover { background-color: #f8fafc; }
        
        .btn { padding: 6px 14px; cursor: pointer; border: none; border-radius: 4px; font-weight: bold; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; gap: 4px; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-secondary:hover { background: #475569; }
        
        .input-text { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100%; box-sizing: border-box; font-size: 13px; }
        .display-order-input { width: 80px; text-align: center; }
        .msg { padding: 12px 16px; border-radius: 4px; font-weight: bold; font-size: 13px; margin-bottom: 18px; }
        .msg-success { background-color: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background-color: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        
        /* 破綻防止案内パネル */
        .guide-box { border-radius: 6px; padding: 16px; margin-bottom: 20px; font-size: 13px; line-height: 1.6; }
        .guide-box.critical { background: #fef2f2; border: 2px solid #ef4444; color: #7f1d1d; }
        .guide-box.normal { background: #eff6ff; border: 1px solid #93c5fd; color: #1e3a8a; }
        .guide-title { font-weight: bold; font-size: 14px; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .guide-section { margin-top: 6px; }
        .guide-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-danger { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
        .badge-info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
        
        /* 履歴・復元セクション */
        .audit-section { margin-top: 40px; border-top: 2px solid #e2e8f0; padding-top: 20px; }
        .audit-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .audit-table th { background: #f8fafc; font-size: 12px; }
        .audit-table td { font-size: 12px; vertical-align: middle; }
        .badge-action { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .badge-insert { background: #d1fae5; color: #065f46; }
        .badge-update { background: #dbeafe; color: #1e40af; }
        .badge-delete { background: #fee2e2; color: #991b1b; }
        .badge-restore { background: #fef3c7; color: #92400e; }
        .badge-undone { background: #f1f5f9; color: #94a3b8; text-decoration: line-through; }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <span>🔧 汎用選択肢マスタメンテナンス</span>
        <div class="header-nav">
            <a href="index.php" class="home-btn">🏠 メニューへ</a>
        </div>
    </h2>

    <!-- 関連マスタリンク -->
    <div style="margin-bottom: 15px; display: flex; justify-content: flex-end;">
        <a href="slot_master_maintenance.php" class="btn" style="background: #2563eb; color: #fff; text-decoration: none; padding: 6px 14px; font-size: 12px; border-radius: 4px;">
            ⏱️ 関連マスタ：検査枠マスタメンテナンスへ ➔
        </a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error === 1 ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- 🛠️ システム保守ツール：予約IDシーケンス修復パネル -->
    <div style="background: #fffbeb; border: 1px solid #f59e0b; padding: 15px; border-radius: 6px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <strong style="color: #b45309; font-size: 14px;">🛠️ システム保守ツール（予約ID重複対策）</strong>
            <p style="margin: 4px 0 0 0; font-size: 12px; color: #78350f;">もし予約登録時に「一意性制約違反（ID重複エラー）」が発生した場合、下のボタンを押すと自動採番カウンタを正しい最大値に修復できます。</p>
        </div>
        <form action="action_master.php" method="POST" onsubmit="return confirm('予約IDの自動採番カウンタ（シーケンス）を正しい最大値に修復しますか？');">
            <input type="hidden" name="action" value="fix_sequence">
            <button type="submit" class="btn" style="background: #f59e0b; color: #fff;">🔄 予約IDシーケンス修復</button>
        </form>
    </div>

    <!-- 🧹 操作履歴データ保守ツール（1年経過ログクリーンアップ） -->
    <div style="background: #f0fdf4; border: 1px solid #10b981; padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <strong style="color: #047857; font-size: 14px;">🧹 操作履歴（Audit Log）データ保守ツール</strong>
            <p style="margin: 4px 0 0 0; font-size: 12px; color: #065f46;">
                現在の蓄積件数: <strong><?= number_format($audit_total_count) ?> 件</strong>
                （うち 1年以上経過した古いログ: <strong style="color: <?= $audit_old_count > 0 ? '#b91c1c' : '#047857' ?>;"><?= number_format($audit_old_count) ?> 件</strong>）<br>
                業務に影響のない1年以上前の古い操作ログを安全にパージ（削除）し、データベースの軽量化を保ちます。
            </p>
        </div>
        <form action="action_master.php" method="POST" onsubmit="return confirm('1年以上前の古い操作履歴（<?= number_format($audit_old_count) ?>件）を削除しますか？\n※直近1年分の履歴や現在の予約データは安全に保持されます。');">
            <input type="hidden" name="action" value="purge_old_audit_logs">
            <button type="submit" class="btn" style="background: #10b981; color: #fff;" <?= $audit_old_count === 0 ? 'title="削除対象の古いログはありません"' : '' ?>>
                🧹 1年前のログを削除 (<?= number_format($audit_old_count) ?>件)
            </button>
        </form>
    </div>

    <!-- STEP 1: カテゴリ選択 -->
    <div class="step-box">
        <h3>STEP 1: 編集するマスタカテゴリを指定してください</h3>
        <div class="flex-group">
            <form method="GET" action="master_maintenance.php" id="category-form" style="display: flex; gap: 10px; align-items: center;">
                <input type="hidden" name="op_mode" value="<?= htmlspecialchars($op_mode, ENT_QUOTES, 'UTF-8') ?>">
                <select name="category" onchange="this.form.submit()" style="padding: 8px 12px; font-size: 13px; font-weight: bold; border-radius: 4px; border: 1px solid #cbd5e1; min-width: 320px;">
                    <option value="">-- カテゴリを選択してください --</option>
                    <?php foreach ($categories as $cat_key => $cat_label): ?>
                        <option value="<?= htmlspecialchars($cat_key, ENT_QUOTES, 'UTF-8') ?>" <?= $selected_category === $cat_key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat_label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <span style="font-size: 12px; color: #64748b;">※プルダウンからカテゴリを選択すると、該当マスタの解説とアイテム一覧が表示されます。</span>
        </div>
    </div>

    <?php if (!empty($selected_category)): ?>
        <?php 
            $guide = $category_guides[$selected_category] ?? [
                'title'       => "ℹ️ 「{$selected_category}」カテゴリ",
                'desc'        => '汎用選択肢マスタのアイテムです。',
                'impact'      => '予約システム内の該当プルダウンまたは選択肢に影響します。',
                'risk'        => '既存の値を変更・削除すると、既に登録されている予約データの表示内容が変わる場合があります。',
                'is_critical' => false
            ];
        ?>

        <!-- 破綻リスク・影響範囲案内パネル -->
        <div class="guide-box <?= $guide['is_critical'] ? 'critical' : 'normal' ?>">
            <div class="guide-title">
                <?= htmlspecialchars($guide['title'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="guide-section">
                <strong>【用途・概要】</strong> <?= htmlspecialchars($guide['desc'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="guide-section">
                <strong>【影響画面】</strong> <?= htmlspecialchars($guide['impact'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="guide-section" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed <?= $guide['is_critical'] ? '#fca5a5' : '#bfdbfe' ?>;">
                <strong style="color: <?= $guide['is_critical'] ? '#991b1b' : '#1e40af' ?>;">【⚠️ システム破綻・不整合リスク】</strong><br>
                <?= htmlspecialchars($guide['risk'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <div class="mode-tabs">
            <a href="?category=<?= urlencode($selected_category) ?>&op_mode=edit" class="mode-tab <?= $op_mode === 'edit' ? 'active' : '' ?>">✏️ 修正モード</a>
            <a href="?category=<?= urlencode($selected_category) ?>&op_mode=add" class="mode-tab <?= $op_mode === 'add' ? 'active' : '' ?>">✨ 追加モード</a>
        </div>

        <?php if ($op_mode === 'edit'): ?>
            <form action="action_master.php" method="POST">
                <input type="hidden" name="action" value="bulk_update">
                <input type="hidden" name="category" value="<?= htmlspecialchars($selected_category, ENT_QUOTES, 'UTF-8') ?>">
                
                <?php if (empty($items)): ?>
                    <p style="color: #64748b; padding: 20px 0; font-weight: bold;">アイテムがありません。上のタブから「追加モード」に切り替えて最初のアイテムを追加してください。</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 70px; text-align: center;">ID</th>
                                <th>値（画面表示名）</th>
                                <th style="width: 120px; text-align: center;">表示順</th>
                                <th style="width: 100px; text-align: center;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $row): ?>
                                <tr>
                                    <td style="text-align: center; color: #64748b; font-weight: bold;">
                                        <?= $row['master_id'] ?>
                                        <input type="hidden" name="items[<?= $row['master_id'] ?>][id]" value="<?= $row['master_id'] ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="items[<?= $row['master_id'] ?>][item_value]" value="<?= htmlspecialchars($row['item_value'], ENT_QUOTES, 'UTF-8') ?>" class="input-text" required>
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="number" name="items[<?= $row['master_id'] ?>][display_order]" value="<?= $row['display_order'] ?>" class="input-text display-order-input" required>
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="action_master.php?action=single_delete&master_id=<?= $row['master_id'] ?>&category=<?= urlencode($selected_category) ?>" class="btn btn-danger" onclick="return confirm('削除すると既存データと不整合が起きる可能性があります。本当に削除しますか？');" style="padding: 3px 8px; font-size: 11px;">削除</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 12px; color: #64748b;">※表示順の数値を変更して一括保存すると、各画面のプルダウン内の並び順が更新されます。</span>
                        <button type="submit" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;">⚡ 修正内容を一括保存する</button>
                    </div>
                <?php endif; ?>
            </form>
        <?php else: ?>
            <div style="background: #fdfdfd; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px;">
                <h3 style="margin-top: 0; color: #059669; border-left-color: #10b981;">「<?= htmlspecialchars($categories[$selected_category] ?? $selected_category, ENT_QUOTES, 'UTF-8') ?>」へ新規アイテム追加</h3>
                <form action="action_master.php" method="POST" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="insert_item">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($selected_category, ENT_QUOTES, 'UTF-8') ?>">
                    
                    <div style="margin-bottom: 12px;">
                        <label style="font-weight: bold; font-size: 12px; display: block; margin-bottom: 4px;">対象カテゴリ:</label>
                        <input type="text" value="<?= htmlspecialchars($selected_category, ENT_QUOTES, 'UTF-8') ?>" class="input-text" style="background-color: #f1f5f9; color: #64748b; font-weight: bold; width: 300px;" readonly>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label for="item_value" style="font-weight: bold; font-size: 12px; display: block; margin-bottom: 4px;">値（画面表示名）:</label>
                        <input type="text" id="item_value" name="item_value" class="input-text" style="max-width: 450px;" required autofocus placeholder="追加する名称を入力...">
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label for="display_order" style="font-weight: bold; font-size: 12px; display: block; margin-bottom: 4px;">表示順（小さいほど先頭）:</label>
                        <input type="number" id="display_order" name="display_order" class="input-text" value="0" style="width: 100px;" required>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-success" style="padding: 8px 18px; font-size: 13px;">➕ このカテゴリに新しく追加</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p style="color: #64748b; text-align: center; padding: 35px 0; border: 1px dashed #cbd5e1; border-radius: 6px; background: #f8fafc; font-weight: bold;">
            上のプルダウンから編集したいマスタカテゴリを選択してください。
        </p>
    <?php endif; ?>

    <!-- 📜 汎用マスタ 変更履歴＆ワンクリック復元パネル -->
    <div class="audit-section">
        <div class="audit-header">
            <div>
                <strong style="font-size: 14px; color: #1e293b;">📜 汎用マスタ変更履歴・復元（直近20件）</strong>
                <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">直近のマスタ変更履歴を記録しています。誤って編集・削除した場合でも「↩️ 元に戻す」で直前の状態に復元できます。</p>
            </div>
            <?php if (!empty($recent_master_logs)): ?>
                <form action="action_master.php" method="POST" onsubmit="return confirm('本当に汎用マスタの変更履歴を一括削除しますか？\n※マスタ本体のデータは削除されません。');">
                    <input type="hidden" name="action" value="clear_audit_logs">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($selected_category, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-secondary" style="padding: 4px 10px; font-size: 11px;">🗑️ 履歴を一括削除</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($recent_master_logs)): ?>
            <p style="color: #94a3b8; font-size: 12px; text-align: center; padding: 15px; background: #f8fafc; border-radius: 4px; border: 1px solid #e2e8f0;">
                記録された変更履歴はありません。
            </p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th style="width: 130px;">日時</th>
                        <th style="width: 110px;">カテゴリ</th>
                        <th style="width: 70px; text-align: center;">操作</th>
                        <th>変更内容（差分サマリ）</th>
                        <th style="width: 100px;">端末IP</th>
                        <th style="width: 90px; text-align: center;">復元操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_master_logs as $log): ?>
                        <?php 
                            $badge_class = 'badge-insert';
                            $action_label = '追加';
                            if ($log['action_type'] === 'update') {
                                $badge_class = 'badge-update';
                                $action_label = '更新';
                            } elseif ($log['action_type'] === 'delete') {
                                $badge_class = 'badge-delete';
                                $action_label = '削除';
                            } elseif ($log['action_type'] === 'restore') {
                                $badge_class = 'badge-restore';
                                $action_label = '復元';
                            }
                        ?>
                        <tr>
                            <td style="color: #64748b;"><?= htmlspecialchars(substr($log['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><strong><?= htmlspecialchars($log['category_or_table'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td style="text-align: center;">
                                <span class="badge-action <?= $badge_class ?>"><?= $action_label ?></span>
                            </td>
                            <td><?= htmlspecialchars($log['diff_summary'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="color: #64748b; font-size: 11px;"><?= htmlspecialchars($log['client_ip'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="text-align: center;">
                                <?php if (!empty($log['is_undone'])): ?>
                                    <span class="badge-action badge-undone">復元済</span>
                                <?php elseif ($log['action_type'] === 'restore'): ?>
                                    <span style="color: #94a3b8; font-size: 11px;">-</span>
                                <?php else: ?>
                                    <a href="action_master.php?action=undo_master&log_id=<?= $log['log_id'] ?>&category=<?= urlencode($selected_category) ?>" 
                                       class="btn btn-secondary" 
                                       onclick="return confirm('この操作を取り消して直前の状態に戻しますか？\n\n対象: <?= htmlspecialchars(addslashes($log['diff_summary']), ENT_QUOTES, 'UTF-8') ?>');" 
                                       style="padding: 2px 6px; font-size: 11px; background: #475569;">
                                        ↩️ 戻す
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>