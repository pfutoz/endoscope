<?php
/**
 * ファイル名: action.php
 * 設置パス: /endoscope/followup/action.php
 * 生成日時: 2026-08-17 20:00:00 JST
 * 
 * フォローアップ保存・削除API
 * 
 * 機能:
 *   - フォローアップデータの新規登録（INSERT）
 *   - フォローアップデータの更新（UPDATE）
 *   - フォローアップデータの削除（DELETE）
 *   - F/U項目（t_followup_items）の一括保存（INSERT/UPDATE/DELETE）
 *   - next_visit_displayが空の場合、next_visit_dateから自動生成
 *   - バリデーション（必須項目チェック）
 *   - 保存して印刷フラグ対応
 * 
 * 修正履歴:
 *   - 2026-08-17: 新規作成
 *   - 2026-08-17: 子テーブル（t_followup_items）対応
 *   - 2026-08-17: next_visit_display自動生成機能を追加
 *   - 2026-08-17: 保存して印刷フラグに対応
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
$save_and_print = isset($_POST['save_and_print']) && $_POST['save_and_print'] == '1';

// ============================================================
// 削除
// ============================================================
if ($action === 'delete') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        // 子テーブルはON DELETE CASCADEで自動削除
        $stmt = $pdo->prepare("DELETE FROM t_followup WHERE followup_id = :id");
        $stmt->execute([':id' => $id]);
    }
    header("Location: index.php?msg=" . urlencode('削除しました'));
    exit;
}

// ============================================================
// 保存（INSERT / UPDATE）
// ============================================================
if ($action === 'insert' || $action === 'update') {
    $id = isset($_POST['followup_id']) ? (int)$_POST['followup_id'] : 0;
    
    // ---- 基本情報 ----
    $patient_id = trim($_POST['patient_id'] ?? '');
    $patient_name = trim($_POST['patient_name'] ?? '');
    $patient_kana = trim($_POST['patient_kana'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $department_code = trim($_POST['department_code'] ?? 'GASTRO');
    $doctor_name = trim($_POST['doctor_name'] ?? '');
    
    // ---- 今回の検査 ----
    $exam_types_json = $_POST['exam_types_json'] ?? '[]';
    $exam_types_display = trim($_POST['exam_types_display'] ?? '');
    
    // ---- 次回来院 ----
    $next_visit_date = trim($_POST['next_visit_date'] ?? '');
    $next_visit_display = trim($_POST['next_visit_display'] ?? '');
    $next_visit_date_type = trim($_POST['next_visit_date_type'] ?? 'exact');
    $next_visit_pattern = trim($_POST['next_visit_pattern'] ?? 'none');
    $next_visit_week_num = isset($_POST['next_visit_week_num']) ? (int)$_POST['next_visit_week_num'] : 1;
    $next_visit_day_of_week = isset($_POST['next_visit_day_of_week']) ? (int)$_POST['next_visit_day_of_week'] : 6;
    
    // next_visit_displayが空で、next_visit_dateがある場合、自動生成
    if (empty($next_visit_display) && !empty($next_visit_date) && $next_visit_date !== '0000-00-00') {
        $next_visit_display = date('Y年n月j日（D）', strtotime($next_visit_date));
    }
    
    // ---- F/U項目 ----
    $fu_items_json = $_POST['fu_items_json'] ?? '[]';
    $fu_items = json_decode($fu_items_json, true);
    if (!is_array($fu_items)) {
        $fu_items = [];
    }
    
    // ---- 来院目的・方法 ----
    $visit_purpose = trim($_POST['visit_purpose'] ?? 'next_exam');
    $visit_method = trim($_POST['visit_method'] ?? 'reserved');
    $visit_time = trim($_POST['visit_time'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $issued_date = trim($_POST['issued_date'] ?? date('Y-m-d'));
    
    // ---- バリデーション ----
    if (empty($patient_id) || empty($patient_name) || empty($next_visit_date)) {
        $redirect_id = ($action === 'update') ? $id : 0;
        header("Location: edit.php?id=" . $redirect_id . "&error=1&msg=" . urlencode('必須項目（カルテID・患者名・次回来院日）が入力されていません'));
        exit;
    }
    
    if (empty($fu_items)) {
        $redirect_id = ($action === 'update') ? $id : 0;
        header("Location: edit.php?id=" . $redirect_id . "&error=1&msg=" . urlencode('F/U検査項目を少なくとも1つ登録してください'));
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // ---- メインテーブル保存 ----
        if ($action === 'insert') {
            $sql = "INSERT INTO t_followup (
                patient_id, patient_name, patient_kana, birthday,
                department_code, doctor_name,
                exam_types, exam_types_display,
                next_visit_date, next_visit_display, next_visit_date_type,
                next_visit_pattern, next_visit_week_num, next_visit_day_of_week,
                visit_purpose, visit_method, visit_time,
                notes, issued_date
            ) VALUES (
                :patient_id, :patient_name, :patient_kana, :birthday,
                :department_code, :doctor_name,
                :exam_types, :exam_types_display,
                :next_visit_date, :next_visit_display, :next_visit_date_type,
                :next_visit_pattern, :next_visit_week_num, :next_visit_day_of_week,
                :visit_purpose, :visit_method, :visit_time,
                :notes, :issued_date
            )";
        } else {
            $sql = "UPDATE t_followup SET
                patient_id = :patient_id,
                patient_name = :patient_name,
                patient_kana = :patient_kana,
                birthday = :birthday,
                department_code = :department_code,
                doctor_name = :doctor_name,
                exam_types = :exam_types,
                exam_types_display = :exam_types_display,
                next_visit_date = :next_visit_date,
                next_visit_display = :next_visit_display,
                next_visit_date_type = :next_visit_date_type,
                next_visit_pattern = :next_visit_pattern,
                next_visit_week_num = :next_visit_week_num,
                next_visit_day_of_week = :next_visit_day_of_week,
                visit_purpose = :visit_purpose,
                visit_method = :visit_method,
                visit_time = :visit_time,
                notes = :notes,
                issued_date = :issued_date,
                updated_at = CURRENT_TIMESTAMP
            WHERE followup_id = :id";
        }
        
        $params = [
            ':patient_id' => $patient_id,
            ':patient_name' => $patient_name,
            ':patient_kana' => $patient_kana,
            ':birthday' => $birthday ?: null,
            ':department_code' => $department_code,
            ':doctor_name' => $doctor_name,
            ':exam_types' => $exam_types_json,
            ':exam_types_display' => $exam_types_display,
            ':next_visit_date' => $next_visit_date,
            ':next_visit_display' => $next_visit_display,
            ':next_visit_date_type' => $next_visit_date_type,
            ':next_visit_pattern' => $next_visit_pattern,
            ':next_visit_week_num' => $next_visit_week_num,
            ':next_visit_day_of_week' => $next_visit_day_of_week,
            ':visit_purpose' => $visit_purpose,
            ':visit_method' => $visit_method,
            ':visit_time' => $visit_time,
            ':notes' => $notes,
            ':issued_date' => $issued_date
        ];
        
        if ($action === 'update') {
            $params[':id'] = $id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        if ($action === 'insert') {
            $followup_id = $pdo->lastInsertId();
        } else {
            $followup_id = $id;
        }
        
        // ---- 子テーブル（F/U項目）保存 ----
        // 既存の項目を削除（論理削除ではなく物理削除でシンプルに）
        $stmt = $pdo->prepare("DELETE FROM t_followup_items WHERE followup_id = :id");
        $stmt->execute([':id' => $followup_id]);
        
        // 新規挿入
        $insert_sql = "INSERT INTO t_followup_items (
            followup_id, exam_type, fu_interval, fu_reason, status, display_order
        ) VALUES (
            :followup_id, :exam_type, :fu_interval, :fu_reason, :status, :display_order
        )";
        $insert_stmt = $pdo->prepare($insert_sql);
        
        $display_order = 0;
        foreach ($fu_items as $item) {
            $exam_type = trim($item['exam_type'] ?? '');
            $fu_interval = trim($item['fu_interval'] ?? '');
            $fu_reason = trim($item['fu_reason'] ?? '');
            $status = trim($item['status'] ?? 'planned');
            
            if (empty($exam_type) || empty($fu_interval)) {
                continue;
            }
            
            $insert_stmt->execute([
                ':followup_id' => $followup_id,
                ':exam_type' => $exam_type,
                ':fu_interval' => $fu_interval,
                ':fu_reason' => $fu_reason,
                ':status' => $status,
                ':display_order' => $display_order
            ]);
            $display_order++;
        }
        
        $pdo->commit();
        
        // ---- リダイレクト ----
        if ($save_and_print) {
            // 保存して印刷
            header("Location: print.php?id=" . $followup_id . "&msg=" . urlencode('保存しました'));
        } else {
            $msg = ($action === 'insert') ? '登録しました' : '更新しました';
            header("Location: edit.php?id=" . $followup_id . "&msg=" . urlencode($msg));
        }
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $redirect_id = ($action === 'update') ? $id : 0;
        header("Location: edit.php?id=" . $redirect_id . "&error=1&msg=" . urlencode('DBエラー: ' . $e->getMessage()));
        exit;
    }
}

// ============================================================
// 不明なアクション
// ============================================================
header("Location: index.php?error=1&msg=" . urlencode('不明なアクションです'));
exit;