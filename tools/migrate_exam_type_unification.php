<?php
/**
 * tools/migrate_exam_type_unification.php
 * 生成日時: 2026-10-26
 * 目的: 検査種別マスタを m_exam_type_group へ完全一本化するためのマイグレーション
 * （ローカル環境専用）
 */

$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

echo "=== 1. m_exam_type_group にカラム追加 ===" . PHP_EOL;

// display_order 列の追加
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'm_exam_type_group'")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('display_order', $cols, true)) {
    $pdo->exec("ALTER TABLE m_exam_type_group ADD COLUMN display_order INTEGER DEFAULT 100");
    echo "  + Added column 'display_order'" . PHP_EOL;
} else {
    echo "  - Column 'display_order' already exists." . PHP_EOL;
}

// is_selectable 列の追加（プルダウンに表示するかどうか）
if (!in_array('is_selectable', $cols, true)) {
    $pdo->exec("ALTER TABLE m_exam_type_group ADD COLUMN is_selectable BOOLEAN DEFAULT TRUE");
    echo "  + Added column 'is_selectable'" . PHP_EOL;
} else {
    echo "  - Column 'is_selectable' already exists." . PHP_EOL;
}

echo PHP_EOL . "=== 2. データの整理と初期値設定 ===" . PHP_EOL;

// 正式選択肢の表示順とグループ設定
$official_items = [
    'TCF'        => ['slot_group' => 'TCF',  'display_order' => 10, 'is_selectable' => true],
    '浣腸CF'     => ['slot_group' => 'TCF',  'display_order' => 15, 'is_selectable' => true],
    'GIF'        => ['slot_group' => 'GIF',  'display_order' => 20, 'is_selectable' => true],
    'W'          => ['slot_group' => 'TCF',  'display_order' => 30, 'is_selectable' => true],
    'ポリペク'    => ['slot_group' => 'TCF',  'display_order' => 40, 'is_selectable' => true],
    'エコー'      => ['slot_group' => 'ECHO', 'display_order' => 45, 'is_selectable' => true],
    '◆胃がん検診◆' => ['slot_group' => 'GIF',  'display_order' => 80, 'is_selectable' => true],
];

// 表記揺れ・過去データ用（プルダウンには出さないが、大分類判定用に吸着）
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
        echo "  Updated: {$etype} (Order: {$attr['display_order']}, Selectable: " . ($attr['is_selectable'] ? 'Y' : 'N') . ")" . PHP_EOL;
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
        echo "  Inserted: {$etype} (Order: {$attr['display_order']}, Selectable: " . ($attr['is_selectable'] ? 'Y' : 'N') . ")" . PHP_EOL;
    }
}

echo PHP_EOL . "=== 3. マイグレーション後の m_exam_type_group 一覧 ===" . PHP_EOL;
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

echo PHP_EOL . "マイグレーション完了！" . PHP_EOL;
