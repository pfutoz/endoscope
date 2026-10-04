<?php
/**
 * lib/progress_db.php
 * 当日進捗ステータスモニタ用 データベース初期化・テーブル自動生成モジュール
 */

require_once __DIR__ . '/db.php';

function initProgressTables(?PDO $pdo = null): bool {
    if ($pdo === null) {
        $pdo = getDbConnection();
    }
    if (!$pdo) {
        return false;
    }

    try {
        // 1. ステータスマスタ (m_progress_status)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS m_progress_status (
                status_id INT PRIMARY KEY,
                status_code VARCHAR(30) NOT NULL UNIQUE,
                status_name VARCHAR(50) NOT NULL,
                short_name VARCHAR(20) NOT NULL,
                step_order INT NOT NULL,
                color_theme VARCHAR(30) NOT NULL,
                primary_role VARCHAR(20) NOT NULL,
                visible_roles TEXT NOT NULL,
                action_roles TEXT NOT NULL,
                required_action VARCHAR(50) DEFAULT 'NONE',
                prev_status_id INT,
                next_status_id INT,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // 初期マスタデータ投入（未登録の場合のみ）
        $stmt = $pdo->query("SELECT COUNT(*) FROM m_progress_status");
        if ((int)$stmt->fetchColumn() === 0) {
            $initial_statuses = [
                [
                    'status_id' => 1,
                    'status_code' => 'CHECKIN',
                    'status_name' => '来院・受付済',
                    'short_name' => '受付済',
                    'step_order' => 10,
                    'color_theme' => 'blue',
                    'primary_role' => 'clerk',
                    'visible_roles' => 'all',
                    'action_roles' => 'clerk,nurse,assistant',
                    'required_action' => 'NONE',
                    'prev_status_id' => null,
                    'next_status_id' => 2
                ],
                [
                    'status_id' => 2,
                    'status_code' => 'PREP_GUIDE',
                    'status_name' => '前処置準備 (マイトイレ案内)',
                    'short_name' => 'マイトイレ',
                    'step_order' => 20,
                    'color_theme' => 'amber',
                    'primary_role' => 'assistant',
                    'visible_roles' => 'all',
                    'action_roles' => 'assistant,nurse',
                    'required_action' => 'PREP_GUIDE',
                    'prev_status_id' => 1,
                    'next_status_id' => 3
                ],
                [
                    'status_id' => 3,
                    'status_code' => 'PREP_DONE',
                    'status_name' => '前処置完了 (検査待ち)',
                    'short_name' => '検査待ち',
                    'step_order' => 30,
                    'color_theme' => 'emerald',
                    'primary_role' => 'nurse',
                    'visible_roles' => 'all',
                    'action_roles' => 'nurse,assistant',
                    'required_action' => 'NONE',
                    'prev_status_id' => 2,
                    'next_status_id' => 4
                ],
                [
                    'status_id' => 4,
                    'status_code' => 'EXAM',
                    'status_name' => '検査中',
                    'short_name' => '検査中',
                    'step_order' => 40,
                    'color_theme' => 'rose',
                    'primary_role' => 'doctor',
                    'visible_roles' => 'all',
                    'action_roles' => 'doctor,nurse',
                    'required_action' => 'NONE',
                    'prev_status_id' => 3,
                    'next_status_id' => 5
                ],
                [
                    'status_id' => 5,
                    'status_code' => 'RECOVERY',
                    'status_name' => 'リカバリ中',
                    'short_name' => 'リカバリ',
                    'step_order' => 50,
                    'color_theme' => 'indigo',
                    'primary_role' => 'nurse',
                    'visible_roles' => 'all',
                    'action_roles' => 'nurse,assistant',
                    'required_action' => 'BIOPSY_VERIFY_AND_SCOPE',
                    'prev_status_id' => 4,
                    'next_status_id' => 6
                ],
                [
                    'status_id' => 6,
                    'status_code' => 'EXPLAIN',
                    'status_name' => '説明 (結果説明)',
                    'short_name' => '説明',
                    'step_order' => 60,
                    'color_theme' => 'purple',
                    'primary_role' => 'doctor',
                    'visible_roles' => 'all',
                    'action_roles' => 'doctor,nurse',
                    'required_action' => 'NONE',
                    'prev_status_id' => 5,
                    'next_status_id' => 7
                ],
                [
                    'status_id' => 7,
                    'status_code' => 'DONE',
                    'status_name' => '帰宅・完了',
                    'short_name' => '完了',
                    'step_order' => 70,
                    'color_theme' => 'slate',
                    'primary_role' => 'clerk',
                    'visible_roles' => 'all',
                    'action_roles' => 'clerk',
                    'required_action' => 'NONE',
                    'prev_status_id' => 6,
                    'next_status_id' => null
                ],
            ];

            $ins = $pdo->prepare("
                INSERT INTO m_progress_status (
                    status_id, status_code, status_name, short_name, step_order,
                    color_theme, primary_role, visible_roles, action_roles,
                    required_action, prev_status_id, next_status_id
                ) VALUES (
                    :status_id, :status_code, :status_name, :short_name, :step_order,
                    :color_theme, :primary_role, :visible_roles, :action_roles,
                    :required_action, :prev_status_id, :next_status_id
                )
            ");
            foreach ($initial_statuses as $st) {
                $ins->execute($st);
            }
        }

        // 2. 当日進捗テーブル (t_today_progress)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS t_today_progress (
                schedule_id INT PRIMARY KEY,
                exam_date DATE NOT NULL,
                current_status_id INT NOT NULL DEFAULT 1,
                is_arrived BOOLEAN NOT NULL DEFAULT FALSE,
                arrival_time VARCHAR(10),
                toilet_room VARCHAR(50),
                prep_start_time VARCHAR(10),
                prep_done_time VARCHAR(10),
                exam_start_time VARCHAR(10),
                exam_end_time VARCHAR(10),
                recovery_start_time TIMESTAMP,
                recovery_end_time TIMESTAMP,
                recovery_target_minutes INT DEFAULT 30,
                is_explain_waiting BOOLEAN DEFAULT FALSE,
                explain_start_time VARCHAR(10),
                done_time VARCHAR(10),
                prep_med_status VARCHAR(20) DEFAULT 'NONE',
                scope_cleaning_status VARCHAR(20) DEFAULT 'NONE',
                scope_cleaning_ordered_at TIMESTAMP,
                scope_cleaning_done_at TIMESTAMP,
                has_biopsy BOOLEAN DEFAULT FALSE,
                biopsy_count INT DEFAULT 0,
                biopsy_sites TEXT,
                biopsy_verified BOOLEAN DEFAULT FALSE,
                biopsy_verified_by VARCHAR(50),
                biopsy_verified_at TIMESTAMP,
                has_sedation BOOLEAN DEFAULT TRUE,
                sedation_type VARCHAR(30) DEFAULT 'NONE',
                is_prep_skipped BOOLEAN DEFAULT FALSE,
                is_exam_ready BOOLEAN DEFAULT FALSE,
                wait_start_time TIMESTAMP,
                progress_memo TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_today_progress_date ON t_today_progress(exam_date);
            CREATE INDEX IF NOT EXISTS idx_today_progress_status ON t_today_progress(current_status_id);

            ALTER TABLE t_today_progress ADD COLUMN IF NOT EXISTS has_sedation BOOLEAN DEFAULT TRUE;
            ALTER TABLE t_today_progress ADD COLUMN IF NOT EXISTS sedation_type VARCHAR(30) DEFAULT 'NONE';
            ALTER TABLE t_today_progress ADD COLUMN IF NOT EXISTS is_prep_skipped BOOLEAN DEFAULT FALSE;
            ALTER TABLE t_today_progress ADD COLUMN IF NOT EXISTS is_exam_ready BOOLEAN DEFAULT FALSE;
            ALTER TABLE t_today_progress ADD COLUMN IF NOT EXISTS wait_start_time TIMESTAMP;

            -- 予約テーブル (t_endoscopy_schedule) への鎮静・自主運転注意カラム
            ALTER TABLE t_endoscopy_schedule ADD COLUMN IF NOT EXISTS has_sedation BOOLEAN;
            ALTER TABLE t_endoscopy_schedule ADD COLUMN IF NOT EXISTS has_drive_caution BOOLEAN;
        ");

        // 3. 進捗変更ログテーブル (t_progress_status_log)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS t_progress_status_log (
                log_id SERIAL PRIMARY KEY,
                schedule_id INT NOT NULL,
                exam_date DATE NOT NULL,
                from_status_id INT,
                to_status_id INT NOT NULL,
                action_type VARCHAR(20) NOT NULL DEFAULT 'FORWARD',
                revert_reason VARCHAR(100),
                operator_name VARCHAR(50),
                operator_role VARCHAR(20),
                comment TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_progress_log_sched ON t_progress_status_log(schedule_id);
            CREATE INDEX IF NOT EXISTS idx_progress_log_date ON t_progress_status_log(exam_date);
        ");

        return true;
    } catch (Exception $e) {
        error_log("initProgressTables error: " . $e->getMessage());
        return false;
    }
}
