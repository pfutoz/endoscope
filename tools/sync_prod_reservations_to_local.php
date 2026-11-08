<?php
/**
 * tools/sync_prod_reservations_to_local.php
 * 本番DB (192.168.1.16) の最新予約・トランザクションデータをローカルDBに同期するスクリプト
 */

require_once __DIR__ . '/../lib/db.php';

echo "=== 本番 ➔ ローカル 予約データ同期ツール ===\n\n";

try {
    $pdoLocal = getDbConnection();
    if (!$pdoLocal) {
        throw new Exception("ローカルDBへの接続に失敗しました");
    }

    $pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "✓ 本番DB (192.168.1.16) および ローカルDB への接続に成功しました。\n\n";

    $syncTables = [
        't_endoscopy_schedule',
        't_mall_memo',
        't_daily_memo',
        't_patient_medical_alert',
        'm2_doctor_attendance',
        'm2_doctor_daily_capacity_v2',
        'm2_waku_override'
    ];

    $pdoLocal->beginTransaction();

    foreach ($syncTables as $tableName) {
        echo "--- [テーブル: {$tableName}] ---\n";

        $chkProd = $pdoProd->query("SELECT to_regclass('public.{$tableName}')")->fetchColumn();
        $chkLocal = $pdoLocal->query("SELECT to_regclass('public.{$tableName}')")->fetchColumn();

        if (!$chkProd) {
            echo "  ⚠️ 本番にテーブル {$tableName} が存在しないためスキップします。\n\n";
            continue;
        }
        if (!$chkLocal) {
            echo "  ⚠️ ローカルにテーブル {$tableName} が存在しないためスキップします。\n\n";
            continue;
        }

        // 1. ローカル退避
        $bakTable = "bak_local_{$tableName}_" . date('Ymd_His');
        $pdoLocal->exec("DROP TABLE IF EXISTS {$bakTable}");
        $pdoLocal->exec("CREATE TABLE {$bakTable} AS SELECT * FROM {$tableName}");
        $bakCnt = $pdoLocal->query("SELECT count(*) FROM {$bakTable}")->fetchColumn();
        echo "  ✓ ローカル既存データを退避: {$bakTable} ({$bakCnt}件)\n";

        // カラム定義と型を取得
        $colStmt = $pdoLocal->prepare("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = :t
        ");
        $colStmt->execute([':t' => $tableName]);
        $typeMap = [];
        while ($colRow = $colStmt->fetch()) {
            $typeMap[$colRow['column_name']] = strtolower($colRow['data_type']);
        }

        // 2. 本番からデータ取得
        $prodRows = $pdoProd->query("SELECT * FROM {$tableName}")->fetchAll();
        $prodCnt = count($prodRows);
        echo "  ✓ 本番データ取得: {$prodCnt}件\n";

        // 3. ローカルテーブルをクリア
        $pdoLocal->exec("TRUNCATE TABLE {$tableName} CASCADE");

        // 4. データの一括挿入
        if ($prodCnt > 0) {
            $cols = array_keys($prodRows[0]);
            $colNames = implode(', ', array_map(function($c) { return "\"{$c}\""; }, $cols));
            $placeholders = implode(', ', array_map(function($c) { return ":{$c}"; }, $cols));

            $insertSql = "INSERT INTO {$tableName} ({$colNames}) VALUES ({$placeholders})";
            $stmtInsert = $pdoLocal->prepare($insertSql);

            foreach ($prodRows as $r) {
                foreach ($cols as $c) {
                    $val = $r[$c];
                    $dt = $typeMap[$c] ?? 'text';

                    if ($val === null) {
                        $stmtInsert->bindValue(":{$c}", null, PDO::PARAM_NULL);
                    } elseif ($dt === 'boolean') {
                        $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                        $stmtInsert->bindValue(":{$c}", $boolVal, PDO::PARAM_BOOL);
                    } elseif (strpos($dt, 'int') !== false) {
                        if ($val === '') {
                            $stmtInsert->bindValue(":{$c}", null, PDO::PARAM_NULL);
                        } else {
                            $stmtInsert->bindValue(":{$c}", (int)$val, PDO::PARAM_INT);
                        }
                    } else {
                        $stmtInsert->bindValue(":{$c}", (string)$val, PDO::PARAM_STR);
                    }
                }
                $stmtInsert->execute();
            }
            echo "  ✓ ローカルへインポート完了: {$prodCnt}件\n";
        }

        // 5. シーケンスの修復（auto increment 対応）
        try {
            $pkCol = $pdoLocal->query("
                SELECT a.attname
                FROM   pg_index i
                JOIN   pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                WHERE  i.indrelid = 'public.{$tableName}'::regclass
                AND    i.indisprimary
            ")->fetchColumn();

            if ($pkCol) {
                $seqName = $pdoLocal->query("SELECT pg_get_serial_sequence('public.{$tableName}', '{$pkCol}')")->fetchColumn();
                if ($seqName) {
                    $maxId = $pdoLocal->query("SELECT COALESCE(MAX(\"{$pkCol}\"), 0) FROM \"{$tableName}\"")->fetchColumn();
                    if ($maxId > 0) {
                        $pdoLocal->query("SELECT setval('{$seqName}', {$maxId})");
                        echo "  ✓ シーケンス更新: {$seqName} ➔ {$maxId}\n";
                    }
                }
            }
        } catch (Exception $seqEx) {
            // シーケンス無しのテーブルはスキップ
        }

        echo "\n";
    }

    $pdoLocal->commit();
    echo "🎉 本番データのローカル取り込みが 100% 成功しました！\n";

} catch (Exception $e) {
    if (isset($pdoLocal) && $pdoLocal->inTransaction()) {
        $pdoLocal->rollBack();
    }
    echo "❌ エラー発生: " . $e->getMessage() . "\n";
    exit(1);
}
