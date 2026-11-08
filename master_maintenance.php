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
 *   - 2026-10-26: 【検査種別マスタ一本化】exam_type を m_exam_type_group と完全一体化。大分類グループ（GIF/TCF/ECHO）と表示順・選択肢表示フラグを1画面で安全に管理可能に改修。3大マスタ統合ヘッダーナビゲーション導入、lib/env_helper.php 読込追加
 *   - 2026-10-26: 【医師マスタ一本化】doctor を m2_doctor へ完全一体化。m_input_master の doctor 参照を廃止し、検査枠マスタへの誘導案内バナーを配備
 *   - 2026-11-01: 【修正インフォメーション連携】system_info を新設管理画面（system_info_maintenance.php）へ誘導案内。統合ヘッダーナビに「📢 修正インフォメーション」タブを追加
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
    require_once __DIR__ . '/lib/env_helper.php';

    // 定義済み有効カテゴリ一覧（英名 ➔ 日本語名・危険度フラグ）
    $category_master_defs = [
        'toilet_room'    => ['label' => 'toilet_room（マイトイレ部屋番号）', 'danger' => false],
        'bowel_prep'     => ['label' => 'bowel_prep（前処置下剤）',      'danger' => false],
        'arrival_time'   => ['label' => 'arrival_time（来院時間）',     'danger' => false],
        'exam_location'  => ['label' => 'exam_location（検査場所）',    'danger' => false],
        'remarks_phrase' => ['label' => 'remarks_phrase（備考パレット定型文）', 'danger' => false],
        'toiawase'       => ['label' => 'toiawase（案内票問い合わせ先）', 'danger' => false],
        'system_info'    => ['label' => '📢 system_info（修正インフォメーション ➔ 専用画面で管理）', 'danger' => false],
        'doctor'         => ['label' => '👨‍⚕️ doctor（医師名 ➔ 検査枠マスタへ一本化済）', 'danger' => false],
        'exam_type'      => ['label' => '🔬 exam_type（検査種別 ➔ 専用画面へ一本化済）', 'danger' => false],
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
            'title'       => '✅ 【一本化完了】検査種別マスタ（m_exam_type_group 連動）',
            'desc'        => '予約管理システム全体の基幹となる検査種別（GIF、TCF、エコー、ポリペク等）の定義および大分類グループ（上部/下部/エコー）の統合マスタです。',
            'impact'      => '予約登録モーダル（modal_edit）、月間カレンダー（month_schedule）、デイリーノート（schedule_note）、印刷、進捗モニター、空き枠検索、統計集計の全てに1箇所で連動します。',
            'risk'        => '【安全に管理可能】検査種別ごとに大分類グループ（GIF/TCF/ECHO）を選択でき、表示順や予約画面での選択可否を1箇所で調整できます。新しい検査種別を追加すると全画面へ自動的に反映されます。',
            'is_critical' => false
        ],
        'doctor' => [
            'title'       => '✅ 【一本化完了】担当医師マスタ（m2_doctor 連動）',
            'desc'        => '予約枠や診療科に紐づく担当医師の定義は、検査枠マスタ（m2_doctor）へ完全一本化されました。',
            'impact'      => '予約画面、月間カレンダー、デイリーノート、当日進捗モニター、空き枠検索など全システムの医師情報は m2_doctor を参照します。',
            'risk'        => '【汎用マスタでの管理不要】医師の追加・所属科設定・表示順・出勤管理は、すべて上部ナビの「⏱️ 検査枠マスタ」画面で一元管理されています。二重管理防止のため、本画面（m_input_master）での登録・編集は停止されました。',
            'is_critical' => false
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
        'system_info' => [
            'title'       => '📢 修正インフォメーションマスタ（専用画面管理）',
            'desc'        => 'ポータル画面（index.php）および index.html に配信するシステムの修正告知・新機能リリース情報です。',
            'impact'      => 'ポータル画面の最下部バナー、および index.html の案内カードに自動同期されます。',
            'risk'        => '【極めて安全】専用画面（system_info_maintenance.php）にて、タイトル、日付、バッジ色、改修項目、公開／非公開を安全に管理できます。',
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
        if ($selected_category === 'exam_type') {
            // m_exam_type_group から取得（一本化マスタ）
            $stmt = $pdo->query("
                SELECT 
                    id AS master_id, 
                    exam_type AS item_value, 
                    slot_group, 
                    display_order, 
                    is_selectable, 
                    is_active 
                FROM m_exam_type_group 
                ORDER BY is_selectable DESC, display_order ASC, id ASC
            ");
            $items = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT master_id, item_value, display_order FROM m_input_master WHERE category = :category ORDER BY display_order ASC, master_id ASC");
            $stmt->execute([':category' => $selected_category]);
            $items = $stmt->fetchAll();
        }
        
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
        /* 3大マスタ統合ヘッダーナビ */
        .master-hub-nav {
            display: flex;
            gap: 6px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            align-items: center;
            flex-wrap: wrap;
        }
        .master-hub-title {
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 6px;
        }
        .hub-tab {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 6px;
            text-decoration: none;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .hub-tab:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .hub-tab.active {
            background: #0284c7;
            color: #fff;
            border-color: #0284c7;
            box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
        }
        .hub-tab.home-link {
            margin-left: auto;
            background: #fff;
            border-color: #cbd5e1;
        }
        .hub-tab.home-link:hover {
            background: #f1f5f9;
        }
    </style>
</head>
<body <?= getBodyEnvClass() ?>>
<?= renderLocalEnvBanner() ?>

<div class="container">

    <!-- 3大マスタ統合ヘッダーナビゲーション -->
    <nav class="master-hub-nav" aria-label="マスタ管理ナビゲーション">
        <span class="master-hub-title">📋 院内マスタ管理:</span>
        <a href="slot_master_maintenance.php" class="hub-tab">⏱️ 検査枠マスタ</a>
        <a href="exam_master_maintenance.php" class="hub-tab">🔬 検査種別マスタ</a>
        <a href="master_maintenance.php" class="hub-tab active">🔧 汎用選択肢マスタ</a>
        <a href="printer_master_maintenance.php" class="hub-tab">🖨️ プリンターマスタ</a>
        <a href="system_info_maintenance.php" class="hub-tab">📢 修正インフォメーション</a>
        <a href="furiwake_daymemo.php" class="hub-tab">📝 日別メモ科振分</a>
        <a href="index.php" class="hub-tab home-link">🏠 ポータルへ</a>
    </nav>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h2 style="margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
            <span>🔧 汎用選択肢マスタメンテナンス</span>
            <code style="font-size: 11px; font-weight: 600; font-family: ui-monospace, monospace; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; border: 1px solid #cbd5e1;" title="PostgreSQL 対象テーブル">TABLE: m_input_master</code>
        </h2>
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

        <?php if ($selected_category === 'exam_type'): ?>
            <!-- 検査種別マスタ専用画面への誘導バナー -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%); border: 2px solid #0284c7; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>
                    <strong style="font-size: 15px; color: #0369a1; display: block; margin-bottom: 4px;">
                        🔬 検査種別は専用画面（m_exam_type_group）へ完全一本化されました
                    </strong>
                    <span style="font-size: 12.5px; color: #334155;">
                        検査種別名、大分類グループ（上部/下部/エコー）、表示順、予約選択肢の管理は、新設の専用画面からより直感的に操作できます。
                    </span>
                </div>
                <a href="exam_master_maintenance.php" class="btn btn-primary" style="padding: 10px 20px; font-size: 13px; font-weight: 800; box-shadow: 0 2px 4px rgba(2, 132, 199, 0.3);">
                    🔬 検査種別マスターメンテナンスを開く ➔
                </a>
            </div>
        <?php endif; ?>

        <?php if ($selected_category === 'doctor'): ?>
            <!-- 担当医師マスタ専用画面への誘導バナー -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%); border: 2px solid #0284c7; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>
                    <strong style="font-size: 15px; color: #0369a1; display: block; margin-bottom: 4px;">
                        👨‍⚕️ 医師マスタは検査枠マスタ（m2_doctor）へ完全一本化されました
                    </strong>
                    <span style="font-size: 12.5px; color: #334155;">
                        医師の所属診療科（肛門科／胃腸科）、表示順、有効/無効、基本枠数パターン、出勤例外の管理は、検査枠マスタ画面から一元管理されています。
                    </span>
                </div>
                <a href="slot_master_maintenance.php?tab=doctor" class="btn btn-primary" style="padding: 10px 20px; font-size: 13px; font-weight: 800; box-shadow: 0 2px 4px rgba(2, 132, 199, 0.3);">
                    ⏱️ 検査枠マスタ（医師タブ）を開く ➔
                </a>
            </div>
        <?php endif; ?>

        <?php if ($selected_category === 'system_info'): ?>
            <!-- 修正インフォメーション専用画面への誘導バナー -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #16a34a; border-radius: 8px; padding: 18px 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div>
                    <strong style="font-size: 15px; color: #15803d; display: block; margin-bottom: 4px;">
                        📢 修正インフォメーションは専用管理画面でリッチに編集できます
                    </strong>
                    <span style="font-size: 12.5px; color: #334155;">
                        タイトル、バージョン、バッジ色、改修項目リストの登録・編集、公開／非公開の切替、および index.html への自動同期を専用画面から安全に行えます。
                    </span>
                </div>
                <a href="system_info_maintenance.php" class="btn btn-success" style="padding: 10px 20px; font-size: 13px; font-weight: 800; box-shadow: 0 2px 4px rgba(22, 163, 74, 0.3);">
                    📢 修正インフォメーション管理を開く ➔
                </a>
            </div>
        <?php endif; ?>

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
                                <th style="width: 60px; text-align: center;">ID</th>
                                <th>値（画面表示名）</th>
                                <?php if ($selected_category === 'exam_type'): ?>
                                    <th style="width: 140px; text-align: center;">大分類グループ</th>
                                    <th style="width: 100px; text-align: center;">予約選択肢</th>
                                <?php endif; ?>
                                <th style="width: 100px; text-align: center;">表示順</th>
                                <th style="width: 90px; text-align: center;">操作</th>
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
                                    <?php if ($selected_category === 'exam_type'): ?>
                                        <td style="text-align: center;">
                                            <select name="items[<?= $row['master_id'] ?>][slot_group]" class="input-text" style="font-weight: bold;">
                                                <option value="GIF" <?= ($row['slot_group'] ?? '') === 'GIF' ? 'selected' : '' ?>>🔵 GIF (上部)</option>
                                                <option value="TCF" <?= ($row['slot_group'] ?? '') === 'TCF' ? 'selected' : '' ?>>🟢 TCF (下部)</option>
                                                <option value="ECHO" <?= ($row['slot_group'] ?? '') === 'ECHO' ? 'selected' : '' ?>>🟡 ECHO (エコー)</option>
                                                <option value="OTHER" <?= ($row['slot_group'] ?? '') === 'OTHER' ? 'selected' : '' ?>>⚪ OTHER (その他)</option>
                                            </select>
                                        </td>
                                        <td style="text-align: center;">
                                            <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: bold; font-size: 11px;">
                                                <input type="checkbox" name="items[<?= $row['master_id'] ?>][is_selectable]" value="1" <?= !empty($row['is_selectable']) ? 'checked' : '' ?>>
                                                <?= !empty($row['is_selectable']) ? '<span style="color:#059669;">表示</span>' : '<span style="color:#94a3b8;">非表示</span>' ?>
                                            </label>
                                        </td>
                                    <?php endif; ?>
                                    <td style="text-align: center;">
                                        <input type="number" name="items[<?= $row['master_id'] ?>][display_order]" value="<?= $row['display_order'] ?>" class="input-text display-order-input" required>
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="action_master.php?action=single_delete&master_id=<?= $row['master_id'] ?>&category=<?= urlencode($selected_category) ?>" class="btn btn-danger" onclick="return confirm('本当に削除しますか？');" style="padding: 3px 8px; font-size: 11px;">削除</a>
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
                    <?php if ($selected_category === 'exam_type'): ?>
                        <div style="margin-bottom: 12px;">
                            <label for="slot_group" style="font-weight: bold; font-size: 12px; display: block; margin-bottom: 4px;">大分類グループ:</label>
                            <select id="slot_group" name="slot_group" class="input-text" style="width: 200px; font-weight: bold;">
                                <option value="GIF">🔵 GIF (上部・胃カメラ)</option>
                                <option value="TCF" selected>🟢 TCF (下部・大腸内視鏡)</option>
                                <option value="ECHO">🟡 ECHO (エコー・超音波)</option>
                                <option value="OTHER">⚪ OTHER (その他)</option>
                            </select>
                            <span style="font-size: 11px; color: #64748b; margin-left: 8px;">※枠判定・色分け・前処置下剤・集計の紐付け先</span>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-weight: bold; font-size: 13px;">
                                <input type="checkbox" name="is_selectable" value="1" checked>
                                <span>予約入力プルダウンの選択肢として表示する</span>
                            </label>
                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">※チェックを外すとプルダウンには表示されず、過去データや表記揺れのグループ判定用として登録されます。</div>
                        </div>
                    <?php endif; ?>
                    <div style="margin-bottom: 20px;">
                        <label for="display_order" style="font-weight: bold; font-size: 12px; display: block; margin-bottom: 4px;">表示順（小さいほど先頭）:</label>
                        <input type="number" id="display_order" name="display_order" class="input-text" value="50" style="width: 100px;" required>
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