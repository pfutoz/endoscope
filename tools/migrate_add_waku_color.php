<?php
/**
 * 生成日時: 2026-11-08 15:06 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\tools\migrate_add_waku_color.php
 * ファイル名: migrate_add_waku_color.php
 * 概要: m2_waku テーブルへ waku_color カラムを追加するマイグレーションスクリプト
 * 修正履歴:
 *   - 2026-11-08: 初版作成。ローカルDB (localhost) および本番DB (192.168.1.16) へ安全に適用。
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

echo "=======================================================\n";
echo " 🎨 基本時間枠マスタ (m2_waku.waku_color) カラム追加マイグレーション\n";
echo "=======================================================\n\n";

$sql = "
ALTER TABLE m2_waku
    ADD COLUMN IF NOT EXISTS waku_color VARCHAR(20) NOT NULL DEFAULT '';
";

function applyWakuColorMigration(PDO $pdo, string $envName, string $sql): void {
    echo "--- [{$envName}] カラム追加処理中 ---\n";
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $pdo->commit();
        echo "  ✓ m2_waku.waku_color カラムを確認/追加完了（初期値: 空文字＝色なし）\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// 1. ローカルDB (localhost)
try {
    $pdoLocal = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    applyWakuColorMigration($pdoLocal, 'ローカル環境 (localhost)', $sql);
} catch (Exception $e) {
    echo "❌ ローカルDBエラー: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. 本番DB (192.168.1.16)
try {
    $pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    applyWakuColorMigration($pdoProd, '本番環境 (192.168.1.16)', $sql);
} catch (Exception $e) {
    echo "⚠️ 本番DBへの接続/反映スキップ: " . $e->getMessage() . "\n";
}

echo "\n🎉 マイグレーション完了！\n";

