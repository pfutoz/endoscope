<?php
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

    $selected_dept = isset($_GET['dept']) ? trim($_GET['dept']) : 'PROCT';
    if ($selected_dept !== 'PROCT' && $selected_dept !== 'GASTRO') { $selected_dept = 'PROCT'; }

    $op_mode = isset($_GET['op_mode']) ? trim($_GET['op_mode']) : 'edit';
    if ($op_mode !== 'edit' && $op_mode !== 'add') { $op_mode = 'edit'; }

    $waku_masters = [];
    $sql = "SELECT waku_id, department_code, waku_code, waku_name, display_order 
            FROM m_waku_master 
            WHERE department_code = :dept 
            ORDER BY display_order ASC, waku_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':dept' => $selected_dept]);
    $waku_masters = $stmt->fetchAll();
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
    <title>時間枠マスタメンテナンス</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f6f9; color: #333; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid #ccc; padding: 30px; border-radius: 6px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .header-nav { position: fixed; top: 15px; right: 15px; z-index: 100; display: flex; gap: 8px; }
        .home-icon-btn { background-color: #2c3e50; color: white; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.2); text-decoration: none; }
        .home-icon-btn:hover { background-color: #34495e; transform: scale(1.05); }
        .nav-link-btn { background-color: #27ae60; color: white; border: none; padding: 0 15px; height: 40px; border-radius: 20px; font-size: 13px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.2); text-decoration: none; }
        .nav-link-btn:hover { background-color: #2ecc71; transform: scale(1.02); }
        
        h2 { border-bottom: 2px solid #2c3e50; padding-bottom: 10px; color: #2c3e50; margin-top: 0; }
        h3 { margin-top: 0; color: #2980b9; font-size: 16px; border-left: 4px solid #3498db; padding-left: 10px; }
        .step-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 20px; margin-bottom: 20px; }
        .flex-group { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .mode-tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #cbd5e1; }
        .mode-tab { padding: 10px 25px; text-decoration: none; font-weight: bold; border-radius: 5px 5px 0 0; background: #e2e8f0; color: #64748b; border: 1px solid #cbd5e1; border-bottom: none; margin-bottom: -2px; font-size: 14px; }
        .mode-tab.active { background: #fff; color: #0f172a; border-top: 3px solid #3b82f6; border-bottom: 2px solid #fff; padding-bottom: 11px; z-index: 2; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 10px; font-size: 14px; }
        th, td { border: 1px solid #cbd5e1; padding: 10px; text-align: left; }
        th { background-color: #f1f5f9; color: #334155; }
        .btn { padding: 8px 16px; cursor: pointer; border: none; border-radius: 4px; font-weight: bold; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .input-text { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100%; box-sizing: border-box; font-size: 13px; }
        .display-order-input { width: 80px; text-align: center; }
        .msg { padding: 12px; border-radius: 4px; font-weight: bold; font-size: 13px; margin-bottom: 20px; }
        .msg-success { background-color: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background-color: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    </style>
</head>
<body>

<div class="header-nav">
    <a href="master_maintenance.php" class="nav-link-btn" title="汎用選択肢マスタメンテナンス画面へ移動">📋 汎用マスタへ</a>
    <a href="index.php" class="home-icon-btn" title="システムメニューに戻る">🏠</a>
</div>

<div class="container">
    <h2>⏱️ 時間枠マスタメンテナンス</h2>

    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error === 1 ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="step-box">
        <h3>STEP 1: 対象の診療科を指定してください</h3>
        <form method="GET" action="waku_maintenance.php" class="flex-group">
            <input type="hidden" name="op_mode" value="<?= htmlspecialchars($op_mode, ENT_QUOTES, 'UTF-8') ?>">
            <select name="dept" onchange="this.form.submit()" style="padding: 8px 12px; font-size: 14px; font-weight: bold; border-radius: 4px; border: 1px solid #cbd5e1; min-width: 250px;">
                <option value="PROCT" <?= $selected_dept === 'PROCT' ? 'selected' : '' ?>>🟢 肛門科 (PROCT)</option>
                <option value="GASTRO" <?= $selected_dept === 'GASTRO' ? 'selected' : '' ?>>⚪ 胃腸科 (GASTRO)</option>
            </select>
        </form>
    </div>

    <div class="mode-tabs">
        <a href="?dept=<?= $selected_dept ?>&op_mode=edit" class="mode-tab <?= $op_mode === 'edit' ? 'active' : '' ?>">✏️ 修正モード</a>
        <a href="?dept=<?= $selected_dept ?>&op_mode=add" class="mode-tab <?= $op_mode === 'add' ? 'active' : '' ?>">✨ 追加モード</a>
    </div>

    <?php if ($op_mode === 'edit'): ?>
        <form action="action_waku.php" method="POST">
            <input type="hidden" name="action" value="bulk_update">
            <input type="hidden" name="department_code" value="<?= htmlspecialchars($selected_dept, ENT_QUOTES, 'UTF-8') ?>">
            
            <?php if (empty($waku_masters)): ?>
                <p style="color: #64748b; padding: 20px 0;">現在、この科に時間枠データはありません。</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 80px; text-align: center;">枠ID</th>
                            <th style="width: 150px;">内部管理コード</th>
                            <th>画面表示名 (時間枠名)</th>
                            <th style="width: 120px; text-align: center;">表示順</th>
                            <th style="width: 100px; text-align: center;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($waku_masters as $row): ?>
                            <tr>
                                <td style="text-align: center; color: #64748b; font-weight: bold;">
                                    <?= $row['waku_id'] ?>
                                    <input type="hidden" name="waku[<?= $row['waku_id'] ?>][id]" value="<?= $row['waku_id'] ?>">
                                </td>
                                <td>
                                    <input type="text" value="<?= htmlspecialchars($row['waku_code'], ENT_QUOTES, 'UTF-8') ?>" class="input-text" style="background-color: #f1f5f9; color: #64748b; font-family: monospace; font-weight: bold;" readonly>
                                </td>
                                <td>
                                    <input type="text" name="waku[<?= $row['waku_id'] ?>][waku_name]" value="<?= htmlspecialchars($row['waku_name'], ENT_QUOTES, 'UTF-8') ?>" class="input-text" required>
                                </td>
                                <td style="text-align: center;">
                                    <input type="number" name="waku[<?= $row['waku_id'] ?>][display_order]" value="<?= $row['display_order'] ?>" class="input-text display-order-input" required>
                                </td>
                                <td style="text-align: center;">
                                    <a href="action_waku.php?action=single_delete&waku_id=<?= $row['waku_id'] ?>&dept=<?= $selected_dept ?>" class="btn btn-danger" onclick="return confirm('時間枠を削除すると既存予約が迷子になります。本当に削除しますか？');" style="padding: 4px 10px; font-size: 11px;">削除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="btn btn-primary">⚡ 修正内容を一括保存する</button>
                </div>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <div style="background: #fdfdfd; border: 1px solid #e2e8f0; border-radius: 6px; padding: 25px;">
            <h3 style="margin-top: 0; color: #059669; border-left-color: #10b981;">「<?= $selected_dept === 'PROCT' ? '肛門科' : '胃腸科' ?>」へ新規時間枠の登録</h3>
            <form action="action_waku.php" method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="insert_waku">
                <input type="hidden" name="department_code" value="<?= htmlspecialchars($selected_dept, ENT_QUOTES, 'UTF-8') ?>">
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; font-size: 13px; display: block; margin-bottom: 6px;">対象診療科:</label>
                    <input type="text" value="<?= $selected_dept ?>" class="input-text" style="background-color: #f1f5f9; color: #64748b; font-weight: bold;" readonly>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="waku_code" style="font-weight: bold; font-size: 13px; display: block; margin-bottom: 6px;">内部管理コード (重複不可):</label>
                    <input type="text" id="waku_code" name="waku_code" class="input-text" placeholder="例: P13 や G15" style="font-family: monospace; font-weight: bold;" required autofocus>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="waku_name" style="font-weight: bold; font-size: 13px; display: block; margin-bottom: 6px;">画面表示名 (時間枠名):</label>
                    <input type="text" id="waku_name" name="waku_name" placeholder="例: 14:30" required>
                </div>
                <div style="margin-bottom: 25px;">
                    <label for="display_order" style="font-weight: bold; font-size: 13px; display: block; margin-bottom: 6px;">表示順:</label>
                    <input type="number" id="display_order" name="display_order" class="input-text" value="0" style="width: 120px;" required>
                </div>
                <div style="text-align: right;">
                    <button type="submit" class="btn btn-success">➕ 新規時間枠として追加</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>