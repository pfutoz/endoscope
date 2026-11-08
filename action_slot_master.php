<?php
/**
 * 生成日時: 2026-09-29 14:34:00 JST
 * 更新日時: 2026-11-06
 * 設置パス: c:\Apache24\htdocs\endoscope\action_slot_master.php
 * ファイル名: action_slot_master.php
 * 概要: 検査枠マスターメンテナンス アクション処理（時間枠世代管理・期間一括変更・監査ログ＆ワンクリック復元対応版）
 * 
 * 修正履歴:
 *   - 2026-09-01: attendance_add / attendance_update で pattern_name が未選択（空）の場合でも空文字代入で NOT NULL 制約違反防止、過去日付登録防止バリデーション
 *   - 2026-09-29: 検査枠マスタ（医師、時間枠、枠数パターン、出勤例外）の変更差分を t_master_audit_log に自動記録する機能、ワンクリック復元（undo_slot_master）、履歴一括削除（clear_slot_audit_logs）を実装
 *   - 2026-11-06: 時間枠世代管理（valid_from 〜 valid_to）に対応。世代期間一括変更（waku_period_bulk_update・前後世代境界日の自動連動）、新世代作成・期間分割（waku_generation_split）、世代一括削除（waku_generation_delete）を実装。
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
        $gen_from        = trim($_POST['gen_from'] ?? '');
        $gen_to          = trim($_POST['gen_to'] ?? '');

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
                        'is_active'       => $act,
                        'valid_from'      => $cur['valid_from'] ?? '1900-01-01',
                        'valid_to'        => $cur['valid_to'] ?? '9999-12-31',
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
                "時間枠一括更新 ({$gen_from}〜{$gen_to}): " . implode('; ', $diff_notes)
            );
        }

        $gen_qs = ($gen_from !== '' && $gen_to !== '') ? "&gen_from=" . urlencode($gen_from) . "&gen_to=" . urlencode($gen_to) : '';
        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}{$gen_qs}&msg=" . urlencode('時間枠を一括更新しました。'));
        exit;
    }

    if ($action === 'waku_add') {
        $group_code    = trim($_POST['group_code'] ?? '');
        $waku_name     = trim($_POST['waku_name'] ?? '');
        $waku_code     = trim($_POST['waku_code'] ?? '');
        $display_order = (int)($_POST['display_order'] ?? 0);
        $valid_from    = trim($_POST['valid_from'] ?? '1900-01-01');
        $valid_to      = trim($_POST['valid_to'] ?? '9999-12-31');
        if ($valid_from === '') { $valid_from = '1900-01-01'; }
        if ($valid_to === '')   { $valid_to = '9999-12-31'; }

        if ($group_code === '' || $waku_name === '') {
            throw new Exception('グループと枠名は必須です。');
        }
        if ($waku_code === '') {
            $waku_code = $group_code . '_' . $display_order;
        }

        $stmt = $pdo->prepare("
            INSERT INTO m2_waku (department_code, group_code, waku_code, waku_name, display_order, is_active, valid_from, valid_to, created_at, updated_at)
            VALUES (:dept, :grp, :code, :name, :disp, TRUE, :vf, :vt, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $stmt->execute([
            ':dept' => $dept,
            ':grp'  => $group_code,
            ':code' => $waku_code,
            ':name' => $waku_name,
            ':disp' => $display_order,
            ':vf'   => $valid_from,
            ':vt'   => $valid_to,
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
            ['id' => $new_id, 'department_code' => $dept, 'group_code' => $group_code, 'waku_code' => $waku_code, 'waku_name' => $waku_name, 'display_order' => $display_order, 'valid_from' => $valid_from, 'valid_to' => $valid_to],
            "時間枠追加: 「{$waku_name}」(科: {$dept}, 期間: {$valid_from}〜{$valid_to}, グループ: {$group_code}, 表示順: {$display_order})"
        );

        $gen_qs = "&gen_from=" . urlencode($valid_from) . "&gen_to=" . urlencode($valid_to);
        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}{$gen_qs}&msg=" . urlencode("時間枠「{$waku_name}」を追加しました。"));
        exit;
    }

    if ($action === 'waku_delete') {
        $id       = (int)($_GET['id'] ?? 0);
        $gen_from = trim($_GET['gen_from'] ?? '');
        $gen_to   = trim($_GET['gen_to'] ?? '');
        if ($id > 0) {
            $stmt_cur = $pdo->prepare("SELECT * FROM m2_waku WHERE id = :id");
            $stmt_cur->execute([':id' => $id]);
            $old_w = $stmt_cur->fetch();

            if ($old_w) {
                if ($gen_from === '') { $gen_from = (string)($old_w['valid_from'] ?? ''); }
                if ($gen_to === '')   { $gen_to   = (string)($old_w['valid_to'] ?? ''); }

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
                    "時間枠削除: 「{$old_w['waku_name']}」(科: {$old_w['department_code']}, 期間: {$old_w['valid_from']}〜{$old_w['valid_to']})"
                );
            }
        }
        $gen_qs = ($gen_from !== '' && $gen_to !== '') ? "&gen_from=" . urlencode($gen_from) . "&gen_to=" . urlencode($gen_to) : '';
        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}{$gen_qs}&msg=" . urlencode('時間枠を削除しました。'));
        exit;
    }

    // 世代期間一括変更（前後世代の境界日自動連動対応）
    if ($action === 'waku_period_bulk_update') {
        $old_vf = trim($_POST['old_valid_from'] ?? '');
        $old_vt = trim($_POST['old_valid_to'] ?? '');
        $new_vf = trim($_POST['new_valid_from'] ?? '');
        $new_vt = trim($_POST['new_valid_to'] ?? '');
        $sync_adjacent = !empty($_POST['sync_adjacent']);

        if ($new_vf === '') { $new_vf = '1900-01-01'; }
        if ($new_vt === '') { $new_vt = '9999-12-31'; }
        if ($new_vt === '9999-09-09' || $new_vt === '9999.9.9') { $new_vt = '9999-12-31'; }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_vf) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_vt)) {
            throw new Exception('有効期間の日付形式が正しくありません（YYYY-MM-DD）。');
        }
        if ($new_vf > $new_vt) {
            throw new Exception("開始日 ({$new_vf}) は終了日 ({$new_vt}) 以前の日付を指定してください。");
        }

        $pdo->beginTransaction();
        try {
            // 変更前の全枠を取得（Undo復元用）
            $stmt_all_before = $pdo->prepare("SELECT * FROM m2_waku WHERE department_code = :dept ORDER BY id ASC");
            $stmt_all_before->execute([':dept' => $dept]);
            $old_all_rows = $stmt_all_before->fetchAll();

            $sync_msgs = [];

            // 前後世代の境界日を自動連動する場合
            if ($sync_adjacent) {
                // 1) 開始日が変更された場合、直前の世代（valid_to == old_vf の前日）の valid_to を new_vf の前日に更新
                if ($new_vf !== $old_vf && $old_vf !== '1900-01-01' && $new_vf !== '1900-01-01') {
                    $old_prev_end = date('Y-m-d', strtotime($old_vf . ' -1 day'));
                    $new_prev_end = date('Y-m-d', strtotime($new_vf . ' -1 day'));
                    $stmt_prev = $pdo->prepare("
                        UPDATE m2_waku
                        SET valid_to = :new_prev_vt, updated_at = CURRENT_TIMESTAMP
                        WHERE department_code = :dept AND valid_to = :old_prev_vt AND valid_from < :new_prev_vt
                    ");
                    $stmt_prev->execute([
                        ':new_prev_vt' => $new_prev_end,
                        ':dept'        => $dept,
                        ':old_prev_vt' => $old_prev_end,
                    ]);
                    if ($stmt_prev->rowCount() > 0) {
                        $sync_msgs[] = "前世代の終了日を {$new_prev_end} に連動更新";
                    }
                }

                // 2) 終了日が変更された場合、直後の世代（valid_from == old_vt の翌日）の valid_from を new_vt の翌日に更新
                if ($new_vt !== $old_vt && $old_vt !== '9999-12-31' && $new_vt !== '9999-12-31') {
                    $old_next_start = date('Y-m-d', strtotime($old_vt . ' +1 day'));
                    $new_next_start = date('Y-m-d', strtotime($new_vt . ' +1 day'));
                    $stmt_next = $pdo->prepare("
                        UPDATE m2_waku
                        SET valid_from = :new_next_vf, updated_at = CURRENT_TIMESTAMP
                        WHERE department_code = :dept AND valid_from = :old_next_vf AND valid_to > :new_next_vf
                    ");
                    $stmt_next->execute([
                        ':new_next_vf' => $new_next_start,
                        ':dept'        => $dept,
                        ':old_next_vf' => $old_next_start,
                    ]);
                    if ($stmt_next->rowCount() > 0) {
                        $sync_msgs[] = "次世代の開始日を {$new_next_start} に連動更新";
                    }
                }
            }

            // 対象世代の期間を一括更新
            $stmt_upd = $pdo->prepare("
                UPDATE m2_waku
                SET valid_from = :new_vf,
                    valid_to   = :new_vt,
                    updated_at = CURRENT_TIMESTAMP
                WHERE department_code = :dept
                  AND valid_from = :old_vf
                  AND valid_to   = :old_vt
            ");
            $stmt_upd->execute([
                ':new_vf' => $new_vf,
                ':new_vt' => $new_vt,
                ':dept'   => $dept,
                ':old_vf' => $old_vf,
                ':old_vt' => $old_vt,
            ]);
            $updated_cnt = $stmt_upd->rowCount();

            // 他世代との期間重複チェック
            $stmt_overlap = $pdo->prepare("
                SELECT valid_from, valid_to, COUNT(*) AS cnt
                FROM m2_waku
                WHERE department_code = :dept
                  AND NOT (valid_from = :new_vf AND valid_to = :new_vt)
                  AND valid_from <= :new_vt
                  AND valid_to >= :new_vf
                GROUP BY valid_from, valid_to
            ");
            $stmt_overlap->execute([
                ':dept'   => $dept,
                ':new_vf' => $new_vf,
                ':new_vt' => $new_vt,
            ]);
            $overlaps = $stmt_overlap->fetchAll();
            if (!empty($overlaps)) {
                $ov = $overlaps[0];
                throw new Exception("他の世代（{$ov['valid_from']} 〜 {$ov['valid_to']}）と期間が重複するため変更できません。「前後の世代の境界日も自動連動する」にチェックを入れて実行するか、期間を調整してください。");
            }

            $stmt_all_after = $pdo->prepare("SELECT * FROM m2_waku WHERE department_code = :dept ORDER BY id ASC");
            $stmt_all_after->execute([':dept' => $dept]);
            $new_all_rows = $stmt_all_after->fetchAll();

            $sync_note = !empty($sync_msgs) ? '（' . implode(' / ', $sync_msgs) . '）' : '';
            recordMasterAuditLog(
                $pdo,
                'waku',
                'update',
                'm2_waku',
                null,
                '時間枠世代期間一括変更',
                $old_all_rows,
                $new_all_rows,
                "世代期間一括変更 [{$dept}]: {$old_vf}〜{$old_vt} ➔ {$new_vf}〜{$new_vt} ({$updated_cnt}枠){$sync_note}"
            );

            $pdo->commit();
            $gen_qs = "&gen_from=" . urlencode($new_vf) . "&gen_to=" . urlencode($new_vt);
            header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}{$gen_qs}&msg=" . urlencode("世代の有効期間を「{$new_vf} 〜 {$new_vt}」に一括変更しました{$sync_note}。"));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // 新世代作成（現世代を複製して指定日で期間分割）
    if ($action === 'waku_generation_split') {
        $src_vf     = trim($_POST['source_valid_from'] ?? '');
        $src_vt     = trim($_POST['source_valid_to'] ?? '');
        $split_date = trim($_POST['split_date'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $split_date)) {
            throw new Exception('新世代の開始日を正しく入力してください（YYYY-MM-DD）。');
        }
        if ($split_date <= $src_vf || $split_date > $src_vt) {
            throw new Exception("新世代の開始日 ({$split_date}) は、分割元の期間 ({$src_vf} より後 〜 {$src_vt} 以下) の範囲内で指定してください。");
        }

        $prev_end_date = date('Y-m-d', strtotime($split_date . ' -1 day'));

        $pdo->beginTransaction();
        try {
            $stmt_src = $pdo->prepare("
                SELECT * FROM m2_waku
                WHERE department_code = :dept AND valid_from = :vf AND valid_to = :vt
                ORDER BY display_order ASC, id ASC
            ");
            $stmt_src->execute([':dept' => $dept, ':vf' => $src_vf, ':vt' => $src_vt]);
            $src_rows = $stmt_src->fetchAll();

            if (empty($src_rows)) {
                throw new Exception('分割元の世代データが見つかりません。');
            }

            // 1. 分割元世代の valid_to を split_date の前日に更新
            $stmt_upd = $pdo->prepare("
                UPDATE m2_waku
                SET valid_to = :prev_end, updated_at = CURRENT_TIMESTAMP
                WHERE department_code = :dept AND valid_from = :vf AND valid_to = :vt
            ");
            $stmt_upd->execute([
                ':prev_end' => $prev_end_date,
                ':dept'     => $dept,
                ':vf'       => $src_vf,
                ':vt'       => $src_vt,
            ]);

            // 2. 新世代（split_date 〜 src_vt）として全枠を複製挿入
            $stmt_ins = $pdo->prepare("
                INSERT INTO m2_waku (department_code, group_code, waku_code, waku_name, display_order, is_active, valid_from, valid_to, created_at, updated_at)
                VALUES (:dept, :grp, :code, :name, :disp, :act, :vf, :vt, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                RETURNING id
            ");
            $inserted_ids = [];
            foreach ($src_rows as $r) {
                $stmt_ins->execute([
                    ':dept' => $r['department_code'],
                    ':grp'  => $r['group_code'],
                    ':code' => $r['waku_code'],
                    ':name' => $r['waku_name'],
                    ':disp' => (int)$r['display_order'],
                    ':act'  => !empty($r['is_active']) ? 'TRUE' : 'FALSE',
                    ':vf'   => $split_date,
                    ':vt'   => $src_vt,
                ]);
                $inserted_ids[] = (int)$stmt_ins->fetchColumn();
            }

            recordMasterAuditLog(
                $pdo,
                'waku',
                'insert',
                'm2_waku',
                null,
                "新世代作成 ({$split_date}〜)",
                $src_rows,
                ['inserted_ids' => $inserted_ids, 'split_date' => $split_date, 'valid_to' => $src_vt],
                "新世代作成（期間分割） [{$dept}]: 旧世代を 〜{$prev_end_date} に区切り、{$split_date}〜{$src_vt} の新世代（" . count($inserted_ids) . "枠）を複製作成"
            );

            $pdo->commit();
            $gen_qs = "&gen_from=" . urlencode($split_date) . "&gen_to=" . urlencode($src_vt);
            header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}{$gen_qs}&msg=" . urlencode("新世代（{$split_date} 〜 {$src_vt}）を作成しました。枠の追加・廃止・名称変更を行ってください。"));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // 世代一括削除
    if ($action === 'waku_generation_delete') {
        $del_vf = trim($_POST['gen_from'] ?? $_GET['gen_from'] ?? '');
        $del_vt = trim($_POST['gen_to'] ?? $_GET['gen_to'] ?? '');

        // 残り世代数を確認（最低1世代は残す）
        $stmt_gens = $pdo->prepare("
            SELECT COUNT(DISTINCT (valid_from::text || '_' || valid_to::text))
            FROM m2_waku
            WHERE department_code = :dept
        ");
        $stmt_gens->execute([':dept' => $dept]);
        $gen_count = (int)$stmt_gens->fetchColumn();
        if ($gen_count <= 1) {
            throw new Exception('最後の1世代は削除できません。');
        }

        $stmt_cur = $pdo->prepare("SELECT * FROM m2_waku WHERE department_code = :dept AND valid_from = :vf AND valid_to = :vt ORDER BY display_order ASC");
        $stmt_cur->execute([':dept' => $dept, ':vf' => $del_vf, ':vt' => $del_vt]);
        $old_rows = $stmt_cur->fetchAll();

        if (!empty($old_rows)) {
            $stmt_del = $pdo->prepare("DELETE FROM m2_waku WHERE department_code = :dept AND valid_from = :vf AND valid_to = :vt");
            $stmt_del->execute([':dept' => $dept, ':vf' => $del_vf, ':vt' => $del_vt]);

            recordMasterAuditLog(
                $pdo,
                'waku',
                'delete',
                'm2_waku',
                null,
                "世代一括削除 ({$del_vf}〜{$del_vt})",
                $old_rows,
                null,
                "時間枠世代一括削除 [{$dept}]: 期間 {$del_vf}〜{$del_vt}（計 " . count($old_rows) . " 枠）を削除"
            );
        }

        header("Location: slot_master_maintenance.php?tab=waku&dept={$dept}&msg=" . urlencode("期間（{$del_vf} 〜 {$del_vt}）の世代を一括削除しました。"));
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
    $err_tab = 'doctor';
    if (strpos($action, 'waku_') === 0) { $err_tab = 'waku'; }
    elseif (strpos($action, 'pattern_') === 0) { $err_tab = 'pattern'; }
    elseif (strpos($action, 'attendance_') === 0) { $err_tab = 'attendance'; }
    header("Location: slot_master_maintenance.php?tab={$err_tab}&dept={$dept}&year={$year}&error=1&msg=" . urlencode('エラー: ' . $err_msg));
    exit;
}