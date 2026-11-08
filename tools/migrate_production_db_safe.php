<?php
/**
 * tools/migrate_production_db_safe.php
 * 本番データベース (192.168.1.16:5432/endoscope) 安全マイグレーションスクリプト
 * 
 * 実行手順:
 * 1. pg_dump による本番DB全体のフルダンプバックアップ取得
 * 2. 本番DB内でのバックアップテーブル (bak_prod_*) 作成（二重安全策）
 * 3. m_exam_type_group へのカラム追加 (display_order, is_selectable)
 * 4. m_exam_type_group のデータ適正化（正式選択肢と表記揺れ吸着）
 * 5. m_input_master への waku_suspend_reason, system_config 追加
 * 6. マイグレーション結果の完全検証
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "====================================================================\n";
echo "🏥 本番データベース (192.168.1.16:5432/endoscope) 安全マイグレーション\n";
echo "実行日時: " . date('Y-m-d H:i:s') . "\n";
echo "====================================================================\n\n";

// ── 1. 本番DB接続 ──
$dsn = 'pgsql:host=192.168.1.16;port=5432;dbname=endoscope;';
$user = 'postgres';
$pass = 'postgres';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "✓ [STEP 1] 本番DB接続成功\n";
} catch (Exception $e) {
    die("❌ エラー: 本番DB接続失敗: " . $e->getMessage() . "\n");
}

// ── 2. pg_dump によるフルダンプバックアップ ──
echo "\n--- [STEP 2] pg_dump によるフルバックアップ実行 ---\n";
require_once __DIR__ . '/backup_production_db.php';

// ── 3. 本番DB内でのバックアップテーブル作成 ──
echo "\n--- [STEP 3] DB内バックアップテーブル作成 ---\n";
$ts = date('Ymd_His');
$bakExamTable = "bak_prod_m_exam_type_group_{$ts}";
$bakInputTable = "bak_prod_m_input_master_{$ts}";

$pdo->exec("CREATE TABLE {$bakExamTable} AS SELECT * FROM m_exam_type_group;");
echo "  ✓ バックアップテーブル作成: {$bakExamTable}\n";

$pdo->exec("CREATE TABLE {$bakInputTable} AS SELECT * FROM m_input_master;");
echo "  ✓ バックアップテーブル作成: {$bakInputTable}\n";

// ── 4. m_exam_type_group マイグレーション（トランザクション内実行） ──
echo "\n--- [STEP 4] m_exam_type_group スキーマ拡張 ＆ データ適正化 ---\n";
$pdo->beginTransaction();
try {
    // カラム確認
    $cols = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = 'm_exam_type_group'
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('display_order', $cols, true)) {
        $pdo->exec("ALTER TABLE m_exam_type_group ADD COLUMN display_order INTEGER DEFAULT 100");
        echo "  + カラム追加: display_order (INTEGER DEFAULT 100)\n";
    } else {
        echo "  - カラム既存: display_order\n";
    }

    if (!in_array('is_selectable', $cols, true)) {
        $pdo->exec("ALTER TABLE m_exam_type_group ADD COLUMN is_selectable BOOLEAN DEFAULT TRUE");
        echo "  + カラム追加: is_selectable (BOOLEAN DEFAULT TRUE)\n";
    } else {
        echo "  - カラム既存: is_selectable\n";
    }

    // 正式選択肢の定義（表示順・グループ・プルダウン表示有効）
    $official_items = [
        'TCF'        => ['slot_group' => 'TCF',  'display_order' => 10, 'is_selectable' => true],
        '浣腸CF'     => ['slot_group' => 'TCF',  'display_order' => 15, 'is_selectable' => true],
        'GIF'        => ['slot_group' => 'GIF',  'display_order' => 20, 'is_selectable' => true],
        'W'          => ['slot_group' => 'TCF',  'display_order' => 30, 'is_selectable' => true],
        'ポリペク'    => ['slot_group' => 'TCF',  'display_order' => 40, 'is_selectable' => true],
        'エコー'      => ['slot_group' => 'ECHO', 'display_order' => 45, 'is_selectable' => true],
        '◆胃がん検診◆' => ['slot_group' => 'GIF',  'display_order' => 80, 'is_selectable' => true],
    ];

    // 表記揺れ・過去データ用（プルダウン非表示、集計・大分類吸着のみ）
    $variant_items = [
        '胃がん検診'  => ['slot_group' => 'GIF',  'display_order' => 81,  'is_selectable' => false],
        'ECHO'       => ['slot_group' => 'ECHO', 'display_order' => 46,  'is_selectable' => false],
        '浣腸F'      => ['slot_group' => 'TCF',  'display_order' => 90,  'is_selectable' => false],
        '◆◆◆'       => ['slot_group' => 'TCF',  'display_order' => 999, 'is_selectable' => false],
    ];

    $all_target_items = array_merge($official_items, $variant_items);

    foreach ($all_target_items as $etype => $attr) {
        $stmt = $pdo->prepare("SELECT id FROM m_exam_type_group WHERE exam_type = :t");
        $stmt->execute([':t' => $etype]);
        $row = $stmt->fetch();
        if ($row) {
            $upd = $pdo->prepare("
                UPDATE m_exam_type_group 
                SET slot_group = :grp, display_order = :ord, is_selectable = :sel, is_active = TRUE 
                WHERE id = :id
            ");
            $upd->execute([
                ':grp' => $attr['slot_group'],
                ':ord' => $attr['display_order'],
                ':sel' => $attr['is_selectable'] ? 1 : 0,
                ':id'  => $row['id']
            ]);
            echo "  ✓ 更新: {$etype} (順序: {$attr['display_order']}, 選択肢: " . ($attr['is_selectable'] ? '有効' : '非表示') . ")\n";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO m_exam_type_group (exam_type, slot_group, display_order, is_selectable, is_active, created_at)
                VALUES (:t, :grp, :ord, :sel, TRUE, CURRENT_TIMESTAMP)
            ");
            $ins->execute([
                ':t'   => $etype,
                ':grp' => $attr['slot_group'],
                ':ord' => $attr['display_order'],
                ':sel' => $attr['is_selectable'] ? 1 : 0,
            ]);
            echo "  ✓ 新規登録: {$etype} (順序: {$attr['display_order']}, 選択肢: " . ($attr['is_selectable'] ? '有効' : '非表示') . ")\n";
        }
    }

    $pdo->commit();
    echo "✓ [STEP 4] m_exam_type_group コミット完了\n";
} catch (Exception $e) {
    $pdo->rollBack();
    die("❌ エラー: m_exam_type_group マイグレーション失敗、ロールバックしました: " . $e->getMessage() . "\n");
}

// ── 5. m_input_master への必要な設定値追加 ──
echo "\n--- [STEP 5] m_input_master データ同期 ---\n";
$pdo->beginTransaction();
try {
    // 枠中止理由マスタ (category = 'waku_suspend_reason')
    $suspendReasons = [
        ['val' => '臨時減枠',     'order' => 4],
        ['val' => '午後休診',     'order' => 5],
        ['val' => '誠吾院長不在', 'order' => 6],
        ['val' => '副院長不在',   'order' => 7],
        ['val' => '哲也先生不在', 'order' => 8],
        ['val' => '医師多忙',     'order' => 9],
    ];

    foreach ($suspendReasons as $r) {
        $check = $pdo->prepare("SELECT master_id FROM m_input_master WHERE category = 'waku_suspend_reason' AND item_value = :v");
        $check->execute([':v' => $r['val']]);
        if (!$check->fetch()) {
            $ins = $pdo->prepare("
                INSERT INTO m_input_master (category, item_value, display_order, created_at)
                VALUES ('waku_suspend_reason', :v, :ord, CURRENT_TIMESTAMP)
            ");
            $ins->execute([':v' => $r['val'], ':ord' => $r['order']]);
            echo "  + 追加: waku_suspend_reason -> '{$r['val']}' (順序: {$r['order']})\n";
        } else {
            echo "  - 既存: waku_suspend_reason -> '{$r['val']}'\n";
        }
    }

    // システム設定マスタ (category = 'system_config', item_value = 'allow_period_override:0')
    $checkSys = $pdo->query("SELECT master_id FROM m_input_master WHERE category = 'system_config' AND item_value = 'allow_period_override:0'")->fetch();
    if (!$checkSys) {
        $pdo->exec("
            INSERT INTO m_input_master (category, item_value, display_order, created_at)
            VALUES ('system_config', 'allow_period_override:0', 1, CURRENT_TIMESTAMP)
        ");
        echo "  + 追加: system_config -> 'allow_period_override:0'\n";
    } else {
        echo "  - 既存: system_config -> 'allow_period_override:0'\n";
    }

    $pdo->commit();
    echo "✓ [STEP 5] m_input_master コミット完了\n";
} catch (Exception $e) {
    $pdo->rollBack();
    die("❌ エラー: m_input_master 同期失敗、ロールバックしました: " . $e->getMessage() . "\n");
}

// ── 6. マイグレーション後検証 ──
echo "\n====================================================================\n";
echo "【検証】マイグレーション後の本番 m_exam_type_group 一覧\n";
echo "====================================================================\n";
$stmt = $pdo->query("SELECT * FROM m_exam_type_group ORDER BY is_selectable DESC, display_order ASC, id ASC");
foreach ($stmt->fetchAll() as $r) {
    echo sprintf(
        "ID:%-3d | ExamType: %-14s | Group: %-5s | Order: %-4d | Selectable: %-5s | Active: %-5s\n",
        $r['id'],
        $r['exam_type'],
        $r['slot_group'],
        $r['display_order'],
        ($r['is_selectable'] ? 'true' : 'false'),
        ($r['is_active'] ? 'true' : 'false')
    );
}

echo "\n🎉 本番データベース安全マイグレーションが 100% 正常に完了しました！\n";
