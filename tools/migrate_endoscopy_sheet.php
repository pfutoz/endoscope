<?php
/**
 * 生成日時: 2026-11-08 16:22 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\tools\migrate_endoscopy_sheet.php
 * ファイル名: migrate_endoscopy_sheet.php
 * 概要: 計画⑥ 内視鏡シート（電子記録票）DB永続化テーブル (t_endoscopy_sheet) を作成するマイグレーションスクリプト
 * 修正履歴:
 *   - 2026-11-08: 初版作成。ローカルDB (localhost) および本番DB (192.168.1.16) へ安全に適用。
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

echo "=======================================================\n";
echo " 📋 内視鏡シートDB永続化テーブル (t_endoscopy_sheet) マイグレーション\n";
echo "=======================================================\n\n";

$sqlFile = __DIR__ . '/migration_20261108_endoscopy_sheet.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    die("❌ SQLファイルの読み込みに失敗しました: {$sqlFile}\n");
}

function applyEndoscopySheetMigration(PDO $pdo, string $envName, string $sql): void {
    echo "--- [{$envName}] テーブル・インデックス作成処理中 ---\n";
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $pdo->commit();
        echo "  ✓ t_endoscopy_sheet テーブルおよびインデックスを確認/作成完了\n";
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
    applyEndoscopySheetMigration($pdoLocal, 'ローカル環境 (localhost)', $sql);
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
    applyEndoscopySheetMigration($pdoProd, '本番環境 (192.168.1.16)', $sql);
} catch (Exception $e) {
    echo "⚠️ 本番DBへの接続/反映スキップ: " . $e->getMessage() . "\n";
}

echo "\n🎉 マイグレーション完了！\n";

