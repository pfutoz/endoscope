<?php
header('Content-Type: application/json; charset=UTF-8');

$host     = 'localhost';
$dbname   = 'endoscope'; 
$user     = 'postgres';
$password = 'postgres';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'データが空か、形式が不正です。']);
    exit;
}

try {
    $dsn = "pgsql:host=$host;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdo->beginTransaction();

    $sql = "UPDATE m_input_master SET display_order = :display_order WHERE master_id = :master_id";
    $stmt = $pdo->prepare($sql);

    foreach ($data as $item) {
        $master_id = (int)($item['master_id'] ?? 0);
        $display_order = (int)($item['display_order'] ?? 0);

        if ($master_id > 0) {
            $stmt->bindValue(':display_order', $display_order, PDO::PARAM_INT);
            $stmt->bindValue(':master_id', $master_id, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success']);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}