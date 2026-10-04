<?php
/**
 * 生成日時: 2026-09-29 14:34:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\action_slot_master.php
 * ファイル名: action_slot_master.php
 * 
 * 検査枠マスターメンテナンス アクション処理（監査ログ＆ワンクリック復元・履歴一括削除対応版）
 * 修正履歴:
 *   - 2026-09-01: attendance_add / attendance_update で pattern_name が未選択（空）の場合でも空文字代入で NOT NULL 制約違反防止、過去日付登録防止バリデーション
 *   - 2026-09-29: 検査枠マスタ（医師、時間枠、枠数パターン、出勤例外）の変更差分を t_master_audit_log に自動記録する機能、ワンクリック復元（undo_slot_master）、履歴一括削除（clear_slot_audit_logs）を実装
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

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
} catch (PDOException $e) {
    die("データベース接続失敗: " . $e->getMessage());
}

require_once __DIR__ . '/lib/master_audit_helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$dept   = $_POST['dept'] ?? $_GET['dept'] ?? 'GASTRO';
if (!in_array($dept, ['PROCT', 'GASTRO'], true)) { $dept = 'GASTRO'; }

$year = isset($_POST['year']) ? (int)$_POST['year'] : (isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y'));
$doctor = isset($_POST['doctor']) ? trim($_POST['doctor']) : (isset($_GET['doctor']) ? trim($_GET['doctor']) : '');

try {
    // ============================================================
    // 1. 医師管理アクション (doctor_*)
    // ============================================================
    if ($action === 'doctor_update') {
        $doctor_ids       = $_POST['doctor_id'] ?? [];
        $department_codes = $_POST['department_code'] ?? [];
        $display_orders   = $_POST['display_order'] ?? [];
        $is_actives       = $_POST['is_active'] ?? [];

        // 更新前の状態を取得
        $stmt_cur = $pdo->query("SELECT * FROM m2_doctor");
        $cur_docs = [];
        foreach ($stmt_cur->fetchAll() as $r) {
            $cur_docs[$r['id']] = $r;
        }

        $stmt = $pdo->prepare("
            UPDATE m2_doctor
            SET department_code = :dept_code,
                display_order   = :disp_order,
                is_active       = :is_active,
                updated_at      = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $old_data = [];
        $new_data = [];
        $diff_notes = [];

        for ($i = 0; $i < count($doctor_ids); $i++) {
            $id = (int)$doctor_ids[$i];
            $d_code = $department_codes[$i] ?? $dept;
            $disp   = (int)($display_orders[$i] ?? 0);
            $act    = !empty($is_actives[$i]) ? 'TRUE' : 'FALSE';
            $act_bool = !empty($is_actives[$i]);

            if (isset($cur_docs[$id])) {
                $cur = $cur_docs[$id];
                $has_change = ($cur['department_code'] !== $d_code || (int)$cur['display_order'] !== $disp || (bool)$cur['is_active'] !== $act_bool);

                if ($has_change) {
                    $old_data[] = $cur;
                    $new_data[] = [
                        'id'              => $id,
                        'doctor_name'     => $cur['doctor_name'],
                        'department_code' => $d_code,
                        'display_order'   => $disp,
                        'is_active'       => $act_bool
                    ];
                    $diff_notes[] = "{$cur['doctor_name']}(科:{$cur['department_code']}➔{$d_code}, 順:{$cur['display_order']}➔{$disp}, 有効:{$cur['is_active']}➔{$act_bool})";
                }
            }

            $stmt->execute([
                ':dept_code'   => $d_code,
                ':disp_order'  => $disp,
                ':is_active'   => $act,
                ':id'          => $id
            ]);
        }

        if (!empty($old_data)) {
            recordMasterAuditLog(
                $pdo,
                'doctor',
                'update',
                'm2_doctor',
                null,
                '医師設定一括更新',
                $old_data,
                $new_data,
                "医師設定一括更新: " . implode('; ', $diff_notes)
            );
        }

        header("Location: slot_master_maintenance.php?tab=doctor&dept={$dept}&msg=" . urlencode('医師設定を一括更新しました。'));
        exit;
    }

    if ($action === 'doctor_add') {
        $doctor_name = trim($_POST['doctor_name'] ?? '');
        if ($doctor_name === '') {
            throw new Exception('医師名を選択してください。');
        }

        $stmt_max = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM m2_doctor WHERE department_code = :dept");
        $stmt_max->execute([':dept' => $dept]);
        $next_order = (int)$stmt_max->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO m2_doctor (doctor_name, department_code, display_order, is_active, created_at, updated_at)
            VALUES (:name, :dept, :disp, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            ':name' => $doctor_name,
            ':dept' => $dept,
            ':disp' => $next_order
        ]);
        $new_id = (int)$stmt->fetchColumn();

        recordMasterAuditLog(
            $pdo,
            'doctor',
            'insert',
            'm2_doctor',
            (string)$new_id,
            $doctor_name,
            null,
            ['id' => $new_id, 'doctor_name' => $doctor_name, 'department_code' => $dept, 'display_order' => $next_order, 'is_active' => true],
            "医師追加: 「{$doctor_name}」先生 (科: {$dept}, 表示順: {$next_order})"
        );

        header("Location: slot_master_maintenance.php?tab=doctor&dept={$dept}&msg=" . urlencode("{$doctor_name} 先生を追加しました。"));
        exit;
    }

    if ($action === 'doctor_delete') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt_cur = $pdo->prepare("SELECT * FROM m2_doctor WHERE id = :id");
            $stmt_cur->execute([':id' => $id]);
            $old_doc = $stmt_cur->fetch();

            if ($old_doc) {
                $doc_name = $old_doc['doctor_name'];

                $stmt = $pdo->prepare("DELETE FROM m2_doctor WHERE id = :id");
                $stmt->execute([':id' => $id]);

                $stmt_pat = $pdo->prepare("DELETE FROM m2_doctor_slot_pattern WHERE doctor_name = :name AND department_code = :dept");
                $stmt_pat->execute([':name' => $doc_name, ':dept' => $dept]);

                recordMasterAuditLog(
                    $pdo,
                    'doctor',
                    'delete',
                    'm2_doctor',
                    (string)$id,
                    $doc_name,
                    $old_doc,
                    null,
                    "医師削除: 「{$doc_name}」先生 (科: {$old_doc['department_code']})"
                );
            }
        }
        header("Location: slot_master_maintenance.php?tab=doctor&dept={$dept}&msg=" . urlencode('医師を削除しました。'));
        exit;
    }

    // ============================================================
    // 2. 時間枠管理アクション (waku_*)
    // ============================================================
    if ($action === 'waku_update') {
        $waku_ids        = $_POST['waku_id'] ?? [];
        $group_codes     = $_POST['group_code'] ?? [];
        $waku_names      = $_POST['waku_name'] ?? [];
        $display_orders  = $_POST['display_order'] ?? [];
        $is_actives      = $_POST['is_active'] ?? [];

        $stmt_cur = $pdo->prepare("SELECT * FROM m2_waku WHERE department_code = :dept");
        $stmt_cur->execute([':dept' => $dept]);
        $cur_waku = [];
        foreach ($stmt_cur->fetchAll() as $r) {
            $cur_waku[$r['id']] = $r;
        }

        $stmt = $pdo->prepare("
            UPDATE m2_waku
            SET group_code    = :grp,
                waku_name     = :name,
                display_order = :disp,
                is_active     = :act,
                updated_at    = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $old_data = [];
        $new_data = [];
        $diff_notes = [];

        for ($i = 0; $i < count($waku_ids); $i++) {
            $id = (int)$waku_ids[$i];
            $grp  = $group_codes[$i] ?? 'OTHER';
            $name = trim($waku_names[$i] ?? '');
            $disp = (int)($display_orders[$i] ?? 0);
            $act  = !empty($is_actives[$i]);

            if (isset($cur_waku[$id])) {
                $cur = $cur_waku[$id];
                $has_change = ($cur['group_code'] !== $grp || $cur['waku_name'] !== $name || (int)$cur['display_order'] !== $disp || (bool)$cur['is_active'] !== $act);

                if ($has_change) {
                    $old_data[] = $cur;
                    $new_data[] = [
                        'id'              => $id,
                        'department_code' => $dept,
                        'group_code'      => $grp,
                        'waku_name'       => $name,
                        'display_order'   => $disp,
                        'is_active'       => $act
                    ];
                    $diff_notes[] = "「{$cur['waku_name']}」➔「{$name}」(G:{$grp}, 順:{$disp}, 有効:{$act})";
                }
            }

            $stmt->execute([
                ':grp'  => $grp,
                ':name' => $name,
                ':disp' => $disp,
                ':act'  => $act ? 'TRUE' : 'FALSE',
                ':id'   => $id
            ]);
        }

        if (!empty($old_data)) {
            recordMasterAuditLog(
                $pdo,
                'waku',
                'update',
                'm2_waku',
                null,
                '時間枠一括更新',
                $old_data,
                $new_data,
                "時間枠一括更新: " . implode('; ', $diff_notes)
            );
        }

        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}&msg=" . urlencode('時間枠を一括更新しました。'));
        exit;
    }

    if ($action === 'waku_add') {
        $group_code    = trim($_POST['group_code'] ?? '');
        $waku_name     = trim($_POST['waku_name'] ?? '');
        $waku_code     = trim($_POST['waku_code'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);

        if ($group_code === '' || $waku_name === '') {
            throw new Exception('グループと枠名は必須です。');
        }

        $stmt = $pdo->prepare("
            INSERT INTO m2_waku (department_code, group_code, waku_code, waku_name, display_order, is_active, created_at, updated_at)
            VALUES (:dept, :grp, :code, :name, :disp, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            ':dept' => $dept,
            ':grp'  => $group_code,
            ':code' => $waku_code,
            ':name' => $waku_name,
            ':disp' => $display_order
        ]);
        $new_id = (int)$stmt->fetchColumn();

        recordMasterAuditLog(
            $pdo,
            'waku',
            'insert',
            'm2_waku',
            (string)$new_id,
            $waku_name,
            null,
            ['id' => $new_id, 'department_code' => $dept, 'group_code' => $group_code, 'waku_code' => $waku_code, 'waku_name' => $waku_name, 'display_order' => $display_order],
            "時間枠追加: 「{$waku_name}」(科: {$dept}, グループ: {$group_code}, 表示順: {$display_order})"
        );

        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}&msg=" . urlencode("時間枠「{$waku_name}」を追加しました。"));
        exit;
    }

    if ($action === 'waku_delete') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt_cur = $pdo->prepare("SELECT * FROM m2_waku WHERE id = :id");
            $stmt_cur->execute([':id' => $id]);
            $old_w = $stmt_cur->fetch();

            if ($old_w) {
                $stmt = $pdo->prepare("DELETE FROM m2_waku WHERE id = :id");
                $stmt->execute([':id' => $id]);

                recordMasterAuditLog(
                    $pdo,
                    'waku',
                    'delete',
                    'm2_waku',
                    (string)$id,
                    $old_w['waku_name'],
                    $old_w,
                    null,
                    "時間枠削除: 「{$old_w['waku_name']}」(科: {$old_w['department_code']})"
                );
            }
        }
        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}&msg=" . urlencode('時間枠を削除しました。'));
        exit;
    }

    // ============================================================
    // 3. 枠数パターンアクション (pattern_*)
    // ============================================================
    if ($action === 'pattern_update') {
        $doctor_name   = trim($_POST['doctor_name'] ?? '');
        $pattern_names = $_POST['pattern_name'] ?? [];
        $days_of_week  = $_POST['day_of_week'] ?? [];
        $gif_slots     = $_POST['gif_slots'] ?? [];
        $echo_slots    = $_POST['echo_slots'] ?? [];
        $tcf_slots     = $_POST['tcf_slots'] ?? [];

        // 更新前の該当医師・パターンの全レコードを取得
        $stmt_cur = $pdo->prepare("SELECT * FROM m2_doctor_slot_pattern WHERE doctor_name = :doc AND department_code = :dept");
        $stmt_cur->execute([':doc' => $doctor_name, ':dept' => $dept]);
        $old_patterns = $stmt_cur->fetchAll();

        $stmt_del = $pdo->prepare("
            DELETE FROM m2_doctor_slot_pattern
            WHERE doctor_name = :doc
              AND department_code = :dept
              AND pattern_name = :pat
              AND day_of_week = :dow
        ");

        $stmt_ins = $pdo->prepare("
            INSERT INTO m2_doctor_slot_pattern (
                doctor_name, department_code, pattern_name, day_of_week, group_code, max_slots, gif_slots, echo_slots, tcf_slots, is_active, created_at, updated_at
            ) VALUES (
                :doc, :dept, :pat, :dow, :grp, :max, :gif, :echo, :tcf, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $new_patterns = [];
        $diff_notes = [];

        for ($i = 0; $i < count($pattern_names); $i++) {
            $p_name = trim($pattern_names[$i] ?? '');
            $dow    = (int)($days_of_week[$i] ?? 0);
            $g_val  = (int)($gif_slots[$i] ?? 0);
            $e_val  = (int)($echo_slots[$i] ?? 0);
            $t_val  = (int)($tcf_slots[$i] ?? 0);

            $stmt_del->execute([':doc' => $doctor_name, ':dept' => $dept, ':pat' => $p_name, ':dow' => $dow]);

            $groups = [
                'GIF'  => $g_val,
                'ECHO' => $e_val,
                'TCF'  => $t_val
            ];

            foreach ($groups as $g_code => $slots) {
                $stmt_ins->execute([
                    ':doc'  => $doctor_name,
                    ':dept' => $dept,
                    ':pat'  => $p_name,
                    ':dow'  => $dow,
                    ':grp'  => $g_code,
                    ':max'  => $slots,
                    ':gif'  => $g_val,
                    ':echo' => $e_val,
                    ':tcf'  => $t_val
                ]);

                $new_patterns[] = [
                    'doctor_name'     => $doctor_name,
                    'department_code' => $dept,
                    'pattern_name'    => $p_name,
                    'day_of_week'     => $dow,
                    'group_code'      => $g_code,
                    'max_slots'       => $slots,
                    'gif_slots'       => $g_val,
                    'echo_slots'      => $e_val,
                    'tcf_slots'       => $t_val
                ];
            }
            $diff_notes[] = "{$p_name}(曜:{$dow}, GIF:{$g_val}, ECHO:{$e_val}, TCF:{$t_val})";
        }

        recordMasterAuditLog(
            $pdo,
            'pattern',
            'update',
            'm2_doctor_slot_pattern',
            null,
            "{$doctor_name} 枠パターン一括更新",
            $old_patterns,
            $new_patterns,
            "枠パターン更新 [{$doctor_name}]: " . implode('; ', $diff_notes)
        );

        header("Location: slot_master_maintenance.php?tab=pattern&dept={$dept}&doctor=" . urlencode($doctor_name) . "&msg=" . urlencode('枠数パターンを更新しました。'));
        exit;
    }

    if ($action === 'pattern_add') {
        $doctor_name  = trim($_POST['doctor_name'] ?? '');
        $pattern_name = trim($_POST['pattern_name'] ?? '');
        $day_of_week  = (int)($_POST['day_of_week'] ?? 0);
        $gif          = (int)($_POST['gif_slots'] ?? 0);
        $echo         = (int)($_POST['echo_slots'] ?? 0);
        $tcf          = (int)($_POST['tcf_slots'] ?? 0);

        if ($doctor_name === '' || $pattern_name === '') {
            throw new Exception('医師名とパターン名は必須です。');
        }

        $stmt_del = $pdo->prepare("
            DELETE FROM m2_doctor_slot_pattern
            WHERE doctor_name = :doc AND department_code = :dept AND pattern_name = :pat AND day_of_week = :dow
        ");
        $stmt_del->execute([':doc' => $doctor_name, ':dept' => $dept, ':pat' => $pattern_name, ':dow' => $day_of_week]);

        $stmt_ins = $pdo->prepare("
            INSERT INTO m2_doctor_slot_pattern (
                doctor_name, department_code, pattern_name, day_of_week, group_code, max_slots, gif_slots, echo_slots, tcf_slots, is_active, created_at, updated_at
            ) VALUES (
                :doc, :dept, :pat, :dow, :grp, :max, :gif, :echo, :tcf, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $groups = ['GIF' => $gif, 'ECHO' => $echo, 'TCF' => $tcf];
        foreach ($groups as $g_code => $slots) {
            $stmt_ins->execute([
                ':doc'  => $doctor_name,
                ':dept' => $dept,
                ':pat'  => $pattern_name,
                ':dow'  => $day_of_week,
                ':grp'  => $g_code,
                ':max'  => $slots,
                ':gif'  => $gif,
                ':echo' => $echo,
                ':tcf'  => $tcf
            ]);
        }

        recordMasterAuditLog(
            $pdo,
            'pattern',
            'insert',
            'm2_doctor_slot_pattern',
            null,
            "{$doctor_name} パターン追加「{$pattern_name}」",
            null,
            ['doctor_name' => $doctor_name, 'department_code' => $dept, 'pattern_name' => $pattern_name, 'day_of_week' => $day_of_week, 'gif_slots' => $gif, 'echo_slots' => $echo, 'tcf_slots' => $tcf],
            "枠パターン追加: {$doctor_name}「{$pattern_name}」(曜:{$day_of_week}, GIF:{$gif}, ECHO:{$echo}, TCF:{$tcf})"
        );

        header("Location: slot_master_maintenance.php?tab=pattern&dept={$dept}&doctor=" . urlencode($doctor_name) . "&msg=" . urlencode("パターン「{$pattern_name}」を追加しました。"));
        exit;
    }

    if ($action === 'pattern_delete') {
        $doctor_name  = trim($_GET['doctor'] ?? '');
        $pattern_name = trim($_GET['pattern_name'] ?? '');

        if ($doctor_name !== '' && $pattern_name !== '') {
            $stmt_old = $pdo->prepare("
                SELECT * FROM m2_doctor_slot_pattern
                WHERE doctor_name = :doc AND department_code = :dept AND pattern_name = :pat
            ");
            $stmt_old->execute([':doc' => $doctor_name, ':dept' => $dept, ':pat' => $pattern_name]);
            $old_rows = $stmt_old->fetchAll();

            if (!empty($old_rows)) {
                $stmt_del = $pdo->prepare("
                    DELETE FROM m2_doctor_slot_pattern
                    WHERE doctor_name = :doc AND department_code = :dept AND pattern_name = :pat
                ");
                $stmt_del->execute([':doc' => $doctor_name, ':dept' => $dept, ':pat' => $pattern_name]);

                recordMasterAuditLog(
                    $pdo,
                    'pattern',
                    'delete',
                    'm2_doctor_slot_pattern',
                    null,
                    "{$doctor_name} パターン削除「{$pattern_name}」",
                    $old_rows,
                    null,
                    "枠パターン削除: {$doctor_name}「{$pattern_name}」(計 " . count($old_rows) . " 件の枠設定)"
                );
            }
        }
        header("Location: slot_master_maintenance.php?tab=pattern&dept={$dept}&doctor=" . urlencode($doctor_name) . "&msg=" . urlencode('パターンを削除しました。'));
        exit;
    }

    // ============================================================
    // 4. 出勤例外アクション (attendance_*)
    // ============================================================
    if ($action === 'attendance_add') {
        $target_date = trim($_POST['target_date'] ?? '');
        $doctor_name = trim($_POST['doctor_name'] ?? '');
        $pattern_name = isset($_POST['pattern_name']) && trim($_POST['pattern_name']) !== '' ? trim($_POST['pattern_name']) : '';
        $reason      = trim($_POST['reason'] ?? '');
        $gif_slots   = (int)($_POST['gif_slots'] ?? 0);
        $echo_slots  = (int)($_POST['echo_slots'] ?? 0);
        $tcf_slots   = (int)($_POST['tcf_slots'] ?? 0);

        if ($target_date === '' || $doctor_name === '') {
            throw new Exception('日付と医師名は必須です。');
        }

        if ($target_date < date('Y-m-d')) {
            throw new Exception('過去の日付（昨日以前）は登録できません。');
        }

        $target_year = (int)substr($target_date, 0, 4);

        $stmt_chk = $pdo->prepare("
            SELECT * FROM m2_doctor_attendance
            WHERE target_date = :date AND doctor_name = :doc AND department_code = :dept
        ");
        $stmt_chk->execute([
            ':date' => $target_date,
            ':doc'  => $doctor_name,
            ':dept' => $dept
        ]);
        $existing = $stmt_chk->fetch();

        if ($existing) {
            $stmt_upd = $pdo->prepare("
                UPDATE m2_doctor_attendance
                SET pattern_name = :pattern,
                    reason       = :reason,
                    gif_slots    = :gif,
                    echo_slots   = :echo,
                    tcf_slots    = :tcf,
                    updated_at   = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt_upd->execute([
                ':pattern' => $pattern_name,
                ':reason'  => $reason,
                ':gif'     => $gif_slots,
                ':echo'    => $echo_slots,
                ':tcf'     => $tcf_slots,
                ':id'      => $existing['id']
            ]);

            recordMasterAuditLog(
                $pdo,
                'attendance',
                'update',
                'm2_doctor_attendance',
                (string)$existing['id'],
                "{$target_date} {$doctor_name}",
                $existing,
                ['id' => $existing['id'], 'target_date' => $target_date, 'doctor_name' => $doctor_name, 'pattern_name' => $pattern_name, 'reason' => $reason, 'gif_slots' => $gif_slots, 'echo_slots' => $echo_slots, 'tcf_slots' => $tcf_slots],
                "出勤例外更新: {$target_date} {$doctor_name}(パタ:{$pattern_name}, G:{$gif_slots}, E:{$echo_slots}, T:{$tcf_slots})"
            );

            $msg = "{$target_date} {$doctor_name} 先生の出勤例外を更新しました。";
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO m2_doctor_attendance (
                    doctor_name, department_code, target_date, pattern_name, reason,
                    gif_slots, echo_slots, tcf_slots, created_at, updated_at
                ) VALUES (
                    :doc, :dept, :date, :pattern, :reason,
                    :gif, :echo, :tcf, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                RETURNING id
            ");
            $stmt_ins->execute([
                ':doc'     => $doctor_name,
                ':dept'    => $dept,
                ':date'    => $target_date,
                ':pattern' => $pattern_name,
                ':reason'  => $reason,
                ':gif'     => $gif_slots,
                ':echo'    => $echo_slots,
                ':tcf'     => $tcf_slots
            ]);
            $new_id = (int)$stmt_ins->fetchColumn();

            recordMasterAuditLog(
                $pdo,
                'attendance',
                'insert',
                'm2_doctor_attendance',
                (string)$new_id,
                "{$target_date} {$doctor_name}",
                null,
                ['id' => $new_id, 'target_date' => $target_date, 'doctor_name' => $doctor_name, 'department_code' => $dept, 'pattern_name' => $pattern_name, 'reason' => $reason, 'gif_slots' => $gif_slots, 'echo_slots' => $echo_slots, 'tcf_slots' => $tcf_slots],
                "出勤例外追加: {$target_date} {$doctor_name}(パタ:{$pattern_name}, G:{$gif_slots}, E:{$echo_slots}, T:{$tcf_slots}, 理由:{$reason})"
            );

            $msg = "{$target_date} {$doctor_name} 先生の出勤例外を追加しました。";
        }

        header("Location: slot_master_maintenance.php?tab=attendance&dept={$dept}&year={$target_year}&msg=" . urlencode($msg));
        exit;
    }

    if ($action === 'attendance_update') {
        $update_row_id = isset($_POST['update_row']) ? (int)$_POST['update_row'] : 0;

        $attendance_ids = $_POST['attendance_id'] ?? [];
        $gif_slots      = $_POST['gif_slots'] ?? [];
        $echo_slots     = $_POST['echo_slots'] ?? [];
        $tcf_slots      = $_POST['tcf_slots'] ?? [];
        $pattern_names  = $_POST['pattern_name'] ?? [];
        $reasons        = $_POST['reason'] ?? [];

        $stmt_cur = $pdo->prepare("SELECT * FROM m2_doctor_attendance WHERE department_code = :dept");
        $stmt_cur->execute([':dept' => $dept]);
        $cur_att = [];
        foreach ($stmt_cur->fetchAll() as $r) {
            $cur_att[$r['id']] = $r;
        }

        $stmt = $pdo->prepare("
            UPDATE m2_doctor_attendance
            SET gif_slots    = :gif,
                echo_slots   = :echo,
                tcf_slots    = :tcf,
                pattern_name = :pattern,
                reason       = :reason,
                updated_at   = CURRENT_TIMESTAMP
            WHERE id = :id AND department_code = :dept
        ");

        $old_data = [];
        $new_data = [];
        $diff_notes = [];

        for ($i = 0; $i < count($attendance_ids); $i++) {
            $id = (int)$attendance_ids[$i];

            if ($update_row_id > 0 && $id !== $update_row_id) {
                continue;
            }

            $pat    = isset($pattern_names[$i]) && trim($pattern_names[$i]) !== '' ? trim($pattern_names[$i]) : '';
            $reason = trim($reasons[$i] ?? '');
            $g      = (int)($gif_slots[$i] ?? 0);
            $e      = (int)($echo_slots[$i] ?? 0);
            $t      = (int)($tcf_slots[$i] ?? 0);

            if (isset($cur_att[$id])) {
                $cur = $cur_att[$id];
                $has_change = ((int)$cur['gif_slots'] !== $g || (int)$cur['echo_slots'] !== $e || (int)$cur['tcf_slots'] !== $t || ($cur['pattern_name'] ?? '') !== $pat || ($cur['reason'] ?? '') !== $reason);

                if ($has_change) {
                    $old_data[] = $cur;
                    $new_data[] = [
                        'id'           => $id,
                        'target_date'  => $cur['target_date'],
                        'doctor_name'  => $cur['doctor_name'],
                        'pattern_name' => $pat,
                        'reason'       => $reason,
                        'gif_slots'    => $g,
                        'echo_slots'   => $e,
                        'tcf_slots'    => $t
                    ];
                    $diff_notes[] = "{$cur['target_date']} {$cur['doctor_name']}(GIF:{$cur['gif_slots']}➔{$g}, E:{$cur['echo_slots']}➔{$e}, T:{$cur['tcf_slots']}➔{$t})";
                }
            }

            $stmt->execute([
                ':gif'     => $g,
                ':echo'    => $e,
                ':tcf'     => $t,
                ':pattern' => $pat,
                ':reason'  => $reason,
                ':id'      => $id,
                ':dept'    => $dept
            ]);
        }

        if (!empty($old_data)) {
            recordMasterAuditLog(
                $pdo,
                'attendance',
                'update',
                'm2_doctor_attendance',
                null,
                '出勤例外一括更新',
                $old_data,
                $new_data,
                "出勤例外更新: " . implode('; ', $diff_notes)
            );
        }

        $msg = ($update_row_id > 0) ? '出勤例外を1件保存しました。' : '出勤例外を一括保存しました。';
        header("Location: slot_master_maintenance.php?tab=attendance&dept={$dept}&year={$year}&msg=" . urlencode($msg));
        exit;
    }

    if ($action === 'attendance_delete') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt_cur = $pdo->prepare("SELECT * FROM m2_doctor_attendance WHERE id = :id AND department_code = :dept");
            $stmt_cur->execute([':id' => $id, ':dept' => $dept]);
            $old_att = $stmt_cur->fetch();

            if ($old_att) {
                $stmt = $pdo->prepare("DELETE FROM m2_doctor_attendance WHERE id = :id AND department_code = :dept");
                $stmt->execute([':id' => $id, ':dept' => $dept]);

                recordMasterAuditLog(
                    $pdo,
                    'attendance',
                    'delete',
                    'm2_doctor_attendance',
                    (string)$id,
                    "{$old_att['target_date']} {$old_att['doctor_name']}",
                    $old_att,
                    null,
                    "出勤例外削除: {$old_att['target_date']} {$old_att['doctor_name']} (科: {$dept})"
                );
            }
        }
        header("Location: slot_master_maintenance.php?tab=attendance&dept={$dept}&year={$year}&msg=" . urlencode('出勤例外を削除しました。'));
        exit;
    }

    // ============================================================
    // 5. 検査枠マスタ ワンクリック復元（Undo）
    // ============================================================
    if ($action === 'undo_slot_master') {
        $log_id = isset($_GET['log_id']) ? (int)$_GET['log_id'] : 0;
        $tab    = isset($_GET['tab']) ? trim($_GET['tab']) : 'doctor';

        if ($log_id <= 0) {
            header("Location: slot_master_maintenance.php?tab={$tab}&dept={$dept}&year={$year}");
            exit;
        }

        $pdo->beginTransaction();
        $restore_msg = undoMasterAuditLog($pdo, $log_id);
        $pdo->commit();

        $msg = "↩️ 復元完了: " . $restore_msg;
        header("Location: slot_master_maintenance.php?tab={$tab}&dept={$dept}&year={$year}&doctor=" . urlencode($doctor) . "&msg=" . urlencode($msg));
        exit;
    }

    // ============================================================
    // 6. 検査枠マスタ 変更履歴一括削除（クリア）
    // ============================================================
    if ($action === 'clear_slot_audit_logs') {
        $tab = isset($_POST['tab']) ? trim($_POST['tab']) : 'doctor';

        $deleted_count = clearMasterAuditLogs($pdo, 'slot_master');
        $msg = "検査枠マスタの変更履歴を一括削除しました（計 {$deleted_count} 件クリア）。";
        header("Location: slot_master_maintenance.php?tab={$tab}&dept={$dept}&year={$year}&doctor=" . urlencode($doctor) . "&msg=" . urlencode($msg));
        exit;
    }

    // 未知のアクション
    header("Location: slot_master_maintenance.php?dept={$dept}");
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $err_msg = $e->getMessage();
    header("Location: slot_master_maintenance.php?tab={$action}&dept={$dept}&year={$year}&error=1&msg=" . urlencode('エラー: ' . $err_msg));
    exit;
}