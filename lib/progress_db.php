<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\lib\progress_db.php
 * ファイル名: progress_db.php
 * 概要: 当日進捗ステータスモニタ ＆ 内視鏡シート（t_endoscopy_sheet）データベース初期化・双方向同期ヘルパー
 * 修正履歴:
 *   - 2026-11-08: 計画⑥ 内視鏡シート（t_endoscopy_sheet）テーブル自動生成、ホワイトボード⇔内視鏡シート双方向データ統合ヘルパー、および医事・処置サマリ抽出関数を追加
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

            -- 4. 内視鏡シートDB永続化テーブル (t_endoscopy_sheet) [計画⑥]
            CREATE TABLE IF NOT EXISTS t_endoscopy_sheet (
                schedule_id INT PRIMARY KEY,
                patient_id VARCHAR(50),
                exam_date DATE NOT NULL,
                bp_sys SMALLINT,
                bp_dia SMALLINT,
                exam_start_time VARCHAR(10),
                exam_end_time VARCHAR(10),
                wake_time VARCHAR(10),
                explain_time VARCHAR(10),
                post_destination VARCHAR(20),
                doctor_sign VARCHAR(64),
                is_ready_accounting BOOLEAN NOT NULL DEFAULT FALSE,
                is_billing_completed BOOLEAN NOT NULL DEFAULT FALSE,
                billing_completed_at TIMESTAMP,
                billing_completed_by VARCHAR(64),
                sheet_state_json JSONB NOT NULL DEFAULT '{}'::jsonb,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by_ip VARCHAR(64)
            );
            CREATE INDEX IF NOT EXISTS idx_endoscopy_sheet_date ON t_endoscopy_sheet(exam_date);
            CREATE INDEX IF NOT EXISTS idx_endoscopy_sheet_patient ON t_endoscopy_sheet(patient_id);
            CREATE INDEX IF NOT EXISTS idx_endoscopy_sheet_accounting ON t_endoscopy_sheet(exam_date, is_ready_accounting, is_billing_completed);
        ");

        return true;
    } catch (Exception $e) {
        error_log("initProgressTables error: " . $e->getMessage());
        return false;
    }
}

/**
 * ホワイトボード（progress_monitor.php / action_progress.php）での変更を
 * 内視鏡シート（t_endoscopy_sheet）へ双方向同期するヘルパー関数
 *
 * @param PDO   $pdo
 * @param int   $schedule_id
 * @param array $changes ['exam_start_time' => 'HH:MM', 'exam_end_time' => 'HH:MM', 'wake_time' => 'HH:MM', 'explain_time' => 'HH:MM', 'post_destination' => 'RECOVERY'|'WAITING'|'OK', 'is_ready_accounting' => bool, 'has_sedation' => bool, 'sedation_type' => string, 'has_biopsy' => bool, 'biopsy_sites' => string]
 */
function syncProgressToSheet(PDO $pdo, int $schedule_id, array $changes): void {
    if ($schedule_id <= 0 || empty($changes)) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_sheet WHERE schedule_id = :id");
        $stmt->execute([':id' => $schedule_id]);
        $sheet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sheet) {
            $sched_stmt = $pdo->prepare("SELECT patient_id, exam_date FROM t_endoscopy_schedule WHERE schedule_id = :id");
            $sched_stmt->execute([':id' => $schedule_id]);
            $sched = $sched_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sched) {
                return;
            }
            $state = ['checks' => [], 'circles' => [], 'inputs' => []];
            $patient_id = (string)($sched['patient_id'] ?? '');
            $exam_date  = (string)($sched['exam_date'] ?? date('Y-m-d'));
            $exam_start_time = null;
            $exam_end_time   = null;
            $wake_time       = null;
            $explain_time    = null;
            $post_dest       = null;
            $doctor_sign     = null;
            $is_ready_acc    = false;
        } else {
            $decoded = json_decode((string)($sheet['sheet_state_json'] ?? '{}'), true);
            $state = is_array($decoded) ? $decoded : [];
            if (!isset($state['checks']) || !is_array($state['checks'])) $state['checks'] = [];
            if (!isset($state['circles']) || !is_array($state['circles'])) $state['circles'] = [];
            if (!isset($state['inputs']) || !is_array($state['inputs'])) $state['inputs'] = [];

            $patient_id      = (string)($sheet['patient_id'] ?? '');
            $exam_date       = (string)($sheet['exam_date'] ?? date('Y-m-d'));
            $exam_start_time = $sheet['exam_start_time'];
            $exam_end_time   = $sheet['exam_end_time'];
            $wake_time       = $sheet['wake_time'];
            $explain_time    = $sheet['explain_time'];
            $post_dest       = $sheet['post_destination'];
            $doctor_sign     = $sheet['doctor_sign'];
            $is_ready_acc    = !empty($sheet['is_ready_accounting']);
        }

        // 1. 検査開始時刻の同期
        if (!empty($changes['exam_start_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$changes['exam_start_time'], $m)) {
            $exam_start_time = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $state['inputs']['t_start_h'] = sprintf('%02d', (int)$m[1]);
            $state['inputs']['t_start_m'] = sprintf('%02d', (int)$m[2]);
        }

        // 2. 検査終了時刻の同期
        if (!empty($changes['exam_end_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$changes['exam_end_time'], $m)) {
            $exam_end_time = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $state['inputs']['t_end_h'] = sprintf('%02d', (int)$m[1]);
            $state['inputs']['t_end_m'] = sprintf('%02d', (int)$m[2]);
        }

        // 3. 起床時刻の同期
        if (!empty($changes['wake_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$changes['wake_time'], $m)) {
            $wake_time = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $state['inputs']['t_wake_h'] = sprintf('%02d', (int)$m[1]);
            $state['inputs']['t_wake_m'] = sprintf('%02d', (int)$m[2]);
        }

        // 4. 説明時刻の同期
        if (!empty($changes['explain_time']) && preg_match('/^(\d{1,2}):(\d{2})$/', (string)$changes['explain_time'], $m)) {
            $explain_time = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $state['inputs']['t_exp_h'] = sprintf('%02d', (int)$m[1]);
            $state['inputs']['t_exp_m'] = sprintf('%02d', (int)$m[2]);
        }

        // 5. 検査後誘導スタンプ（OK / 回復 / 待合室）の同期
        if (!empty($changes['post_destination'])) {
            $dest = strtoupper((string)$changes['post_destination']);
            $post_dest = $dest;
            $state['circles']['stamp_ok']       = ($dest === 'OK');
            $state['circles']['stamp_recovery'] = ($dest === 'RECOVERY');
            $state['circles']['stamp_waiting']  = ($dest === 'WAITING');
        }

        // 6. 会計準備完了フラグの同期
        if (array_key_exists('is_ready_accounting', $changes)) {
            $is_ready_acc = !empty($changes['is_ready_accounting']);
            $state['checks']['go_accounting'] = $is_ready_acc;
        }

        // 7. 鎮静剤種別の同期
        if (array_key_exists('has_sedation', $changes)) {
            $has_sed = !empty($changes['has_sedation']);
            $sed_type = strtoupper((string)($changes['sedation_type'] ?? 'NONE'));
            if (!$has_sed || $sed_type === 'NONE') {
                $state['checks']['drug_propofol'] = false;
                $state['checks']['drug_diazepam'] = false;
            } elseif ($sed_type === 'PROPOFOL') {
                $state['checks']['drug_propofol'] = true;
                $state['checks']['drug_diazepam'] = false;
            } elseif ($sed_type === 'DIAZEPAM') {
                $state['checks']['drug_diazepam'] = true;
                $state['checks']['drug_propofol'] = false;
            }
        }

        // 8. 組織採取（生検部位）の同期
        if (array_key_exists('has_biopsy', $changes)) {
            $has_b = !empty($changes['has_biopsy']);
            $sites_str = (string)($changes['biopsy_sites'] ?? '');
            if ($has_b && $sites_str !== '') {
                $cf_sites_map = ['直腸' => 0, 'Ｓ状結腸' => 1, 'S状結腸' => 1, '下行結腸' => 2, '横行結腸' => 3, '上行結腸' => 4, '盲腸' => 5];
                $gif_sites_map = ['胃' => 0, '食道' => 1, '十二指腸' => 2];

                $matched_cf = false;
                foreach ($cf_sites_map as $s_name => $idx) {
                    if (mb_strpos($sites_str, $s_name) !== false) {
                        $state['circles']['cf_biopsy_' . $idx] = true;
                        $matched_cf = true;
                    }
                }
                if ($matched_cf) {
                    $state['checks']['cf_biopsy'] = true;
                }

                $matched_gif = false;
                foreach ($gif_sites_map as $s_name => $idx) {
                    if (mb_strpos($sites_str, $s_name) !== false) {
                        $state['circles']['gif_biopsy_' . $idx] = true;
                        $matched_gif = true;
                    }
                }
                if ($matched_gif) {
                    $state['checks']['gif_biopsy'] = true;
                }
            }
        }

        // 9. 抗血栓薬休薬確認済フラグの同期
        if (array_key_exists('antithrombotic_verified', $changes)) {
            $ver = !empty($changes['antithrombotic_verified']);
            if ($ver) {
                $state['checks']['anti_yes'] = true;
                $state['checks']['anti_no']  = false;
                if (empty($state['checks']['anti_stop_no'])) {
                    $state['checks']['anti_stop_yes'] = true;
                }
            } else {
                $state['checks']['anti_stop_yes'] = false;
                $state['checks']['anti_stop_no']  = false;
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $json_str = json_encode($state, JSON_UNESCAPED_UNICODE);

        $upsert = $pdo->prepare("
            INSERT INTO t_endoscopy_sheet (
                schedule_id, patient_id, exam_date,
                exam_start_time, exam_end_time, wake_time, explain_time,
                post_destination, doctor_sign, is_ready_accounting,
                sheet_state_json, updated_at, updated_by_ip
            ) VALUES (
                :schedule_id, :patient_id, :exam_date,
                :exam_start_time, :exam_end_time, :wake_time, :explain_time,
                :post_destination, :doctor_sign, :is_ready_accounting,
                :sheet_state_json, CURRENT_TIMESTAMP, :updated_by_ip
            )
            ON CONFLICT (schedule_id) DO UPDATE SET
                exam_start_time     = COALESCE(EXCLUDED.exam_start_time, t_endoscopy_sheet.exam_start_time),
                exam_end_time       = COALESCE(EXCLUDED.exam_end_time, t_endoscopy_sheet.exam_end_time),
                wake_time           = COALESCE(EXCLUDED.wake_time, t_endoscopy_sheet.wake_time),
                explain_time        = COALESCE(EXCLUDED.explain_time, t_endoscopy_sheet.explain_time),
                post_destination    = COALESCE(EXCLUDED.post_destination, t_endoscopy_sheet.post_destination),
                is_ready_accounting = EXCLUDED.is_ready_accounting,
                sheet_state_json    = EXCLUDED.sheet_state_json,
                updated_at          = CURRENT_TIMESTAMP,
                updated_by_ip       = EXCLUDED.updated_by_ip
        ");
        $upsert->execute([
            ':schedule_id'         => $schedule_id,
            ':patient_id'          => $patient_id,
            ':exam_date'           => $exam_date,
            ':exam_start_time'     => $exam_start_time,
            ':exam_end_time'       => $exam_end_time,
            ':wake_time'           => $wake_time,
            ':explain_time'        => $explain_time,
            ':post_destination'    => $post_dest,
            ':doctor_sign'         => $doctor_sign,
            ':is_ready_accounting' => $is_ready_acc ? 1 : 0,
            ':sheet_state_json'    => $json_str,
            ':updated_by_ip'       => $ip,
        ]);
    } catch (Exception $e) {
        error_log("syncProgressToSheet error: " . $e->getMessage());
    }
}

/**
 * 内視鏡シート（t_endoscopy_sheet）レコードから、
 * ホワイトボード（progress_monitor.php）および医事課チェック用の構造化サマリを生成する
 *
 * @param array|null $sheetRow t_endoscopy_sheet の1行
 * @return array
 */
function buildSheetSummaryForMonitor(?array $sheetRow): array {
    if (!$sheetRow) {
        return [
            'has_sheet'            => false,
            'bp_sys'               => null,
            'bp_dia'               => null,
            'bp_label'             => '',
            'stool_label'          => '',
            'exam_start_time'      => '',
            'exam_end_time'        => '',
            'wake_time'            => '',
            'explain_time'         => '',
            'post_destination'     => '',
            'doctor_sign'          => '',
            'is_ready_accounting'  => false,
            'is_billing_completed' => false,
            'billing_completed_at' => '',
            'drugs_summary'        => [],
            'procedures_summary'   => [],
            'post_summary'         => [],
            'updated_at'           => '',
        ];
    }

    $decoded = json_decode((string)($sheetRow['sheet_state_json'] ?? '{}'), true);
    $state   = is_array($decoded) ? $decoded : [];
    $checks  = isset($state['checks']) && is_array($state['checks']) ? $state['checks'] : [];
    $circles = isset($state['circles']) && is_array($state['circles']) ? $state['circles'] : [];
    $inputs  = isset($state['inputs']) && is_array($state['inputs']) ? $state['inputs'] : [];

    // 1. 血圧ラベル
    $bp_sys = $sheetRow['bp_sys'] ?? ($inputs['bp_sys'] ?? '');
    $bp_dia = $sheetRow['bp_dia'] ?? ($inputs['bp_dia'] ?? '');
    $bp_label = '';
    if (trim((string)$bp_sys) !== '' || trim((string)$bp_dia) !== '') {
        $bp_label = trim((string)$bp_sys) . '/' . trim((string)$bp_dia);
    }

    // 2. 排便回数ラベル
    $stool_parts = [];
    if (trim((string)($inputs['stool_yest'] ?? '')) !== '') {
        $stool_parts[] = '昨' . trim((string)$inputs['stool_yest']);
    }
    if (trim((string)($inputs['stool_today'] ?? '')) !== '') {
        $stool_parts[] = '今' . trim((string)$inputs['stool_today']);
    }
    if (trim((string)($inputs['stool_last'] ?? '')) !== '') {
        $stool_parts[] = '終' . trim((string)$inputs['stool_last']);
    }
    $stool_label = implode('・', $stool_parts);

    // 3. 使用薬剤・前処置薬サマリ（医事課・看護師確認用）
    $drugs = [];
    if (!empty($checks['drug_propofol']) || trim((string)($inputs['drug_propofol_mg'] ?? '')) !== '') {
        $mg = trim((string)($inputs['drug_propofol_mg'] ?? ''));
        $drugs[] = 'プロポフォール1%' . ($mg !== '' ? " {$mg}mg" : '');
    }
    if (!empty($checks['drug_diazepam']) || trim((string)($inputs['drug_diazepam_ml'] ?? '')) !== '') {
        $ml = trim((string)($inputs['drug_diazepam_ml'] ?? ''));
        $drugs[] = 'ジアゼパム10mg' . ($ml !== '' ? " {$ml}ml" : '');
    }
    if (!empty($checks['drug_pethilorfan']) || trim((string)($inputs['drug_pethi_ml'] ?? '')) !== '') {
        $ml = trim((string)($inputs['drug_pethi_ml'] ?? ''));
        $p_type = !empty($circles['pethi_weak']) ? '弱ペチロルファン' : 'ペチロルファン';
        $drugs[] = $p_type . ($ml !== '' ? " {$ml}ml" : '');
    }
    if (!empty($checks['drug_buscopan']) || !empty($circles['buscopan_1ml'])) {
        $b_note = trim((string)($inputs['buscopan_note'] ?? ''));
        $drugs[] = 'ブスコパン20mg 1ml' . ($b_note !== '' ? "({$b_note})" : '');
    }
    if (!empty($checks['drug_glucagon']) || !empty($circles['glucagon_1v'])) {
        $drugs[] = 'グルカゴン1V';
    }
    if (!empty($checks['drug_saline']) || !empty($circles['saline_20']) || !empty($circles['saline_100']) || !empty($circles['saline_500'])) {
        $s_vols = [];
        if (!empty($circles['saline_20'])) $s_vols[] = '20ml';
        if (!empty($circles['saline_100'])) $s_vols[] = '100ml';
        if (!empty($circles['saline_500'])) $s_vols[] = '500ml';
        $drugs[] = '生食' . (!empty($s_vols) ? ' ' . implode('/', $s_vols) : '');
    }
    // CF前処置薬・資材
    if (!empty($checks['cf_xylo'])) $drugs[] = 'キシロカインゼリー10ml';
    if (!empty($checks['cf_dimeth'])) $drugs[] = 'CFジメチコン5ml';
    if (!empty($checks['cf_pants'])) $drugs[] = '検査用パンツ1個';
    if (!empty($checks['cf_pad'])) $drugs[] = '尿パッド';
    // GIF前処置薬
    if (!empty($checks['gif_pronase'])) $drugs[] = 'プロナーゼMS 0.5g';
    if (!empty($checks['gif_bicarb'])) $drugs[] = '重曹1.0g';
    if (!empty($checks['gif_dimeth'])) $drugs[] = 'GIFジメチコン5ml';
    if (!empty($checks['gif_lido_sp'])) $drugs[] = 'リドカインSP 2g';
    if (!empty($checks['gif_lido_vis'])) $drugs[] = 'リドカインビスカス5ml';

    // 4. 手技・加算・生検・処置サマリ（医事課・レセプト算定用）
    $procs = [];
    $cf_sites = ['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸'];
    $cf_poly_u2_sites = ['直腸', 'Ｓ状結腸', '下行結腸', '横行結腸', '上行結腸', '盲腸', '回腸'];
    $gif_sites = ['胃', '食道', '十二指腸'];

    // CF到達部位
    $reach_list = [];
    foreach ($cf_sites as $idx => $s_name) {
        if (!empty($circles['cf_reach_' . $idx])) {
            $reach_list[] = $s_name;
        }
    }
    if (!empty($reach_list)) {
        $procs[] = 'CF到達:' . implode('・', $reach_list);
    }
    if (!empty($checks['cf_dye'])) $procs[] = 'CF色素法';
    if (!empty($checks['cf_nbi'])) $procs[] = 'CF狭帯域光強調加算(NBI)';
    if (!empty($checks['cf_tattoo'])) $procs[] = 'CF点墨法';

    // CFポリープ切除(<2cm)
    $u2_list = [];
    foreach ($cf_poly_u2_sites as $idx => $s_name) {
        if (!empty($circles['cf_poly_u2_' . $idx])) {
            $u2_list[] = $s_name;
        }
    }
    if (!empty($checks['cf_poly_u2']) || !empty($u2_list)) {
        $procs[] = 'CFポリペク<2cm' . (!empty($u2_list) ? '(' . implode('・', $u2_list) . ')' : '');
    }

    // CFポリープ切除(>=2cm)
    $o2_list = [];
    foreach ($cf_sites as $idx => $s_name) {
        if (!empty($circles['cf_poly_o2_' . $idx])) {
            $o2_list[] = $s_name;
        }
    }
    if (!empty($checks['cf_poly_o2']) || !empty($o2_list)) {
        $procs[] = 'CFポリペク≧2cm' . (!empty($o2_list) ? '(' . implode('・', $o2_list) . ')' : '');
    }

    // CF生検
    $cfb_list = [];
    foreach ($cf_sites as $idx => $s_name) {
        if (!empty($circles['cf_biopsy_' . $idx])) {
            $cfb_list[] = $s_name;
        }
    }
    if (!empty($checks['cf_biopsy']) || !empty($cfb_list)) {
        $procs[] = 'CF生検' . (!empty($cfb_list) ? '(' . implode('・', $cfb_list) . ' / ' . count($cfb_list) . '部位)' : '');
    }

    if (!empty($checks['cf_emr_saline'])) $procs[] = 'EMR生食20ml';
    if (!empty($checks['cf_muco'])) $procs[] = 'ムコアップ1V';
    if (!empty($checks['cf_hemo'])) $procs[] = 'CF止血術';
    if (!empty($checks['cf_foreign'])) $procs[] = 'CF異物除去術';

    // GIF手技
    if (!empty($checks['gif_dye'])) $procs[] = 'GIF色素法';
    if (!empty($checks['gif_nbi'])) $procs[] = 'GIF狭帯域光強調加算(NBI)';

    $gifb_list = [];
    foreach ($gif_sites as $idx => $s_name) {
        if (!empty($circles['gif_biopsy_' . $idx])) {
            $gifb_list[] = $s_name;
        }
    }
    if (!empty($checks['gif_biopsy']) || !empty($gifb_list)) {
        $procs[] = 'GIF生検' . (!empty($gifb_list) ? '(' . implode('・', $gifb_list) . ' / ' . count($gifb_list) . '臓器)' : '');
    }
    if (!empty($checks['gif_poly'])) $procs[] = 'GIFポリープ切除術';
    if (!empty($checks['gif_hemo'])) $procs[] = 'GIF止血術';
    if (!empty($checks['gif_foreign'])) $procs[] = 'GIF異物除去術';
    if (!empty($checks['gif_urease'])) $procs[] = '迅速ウレアーゼ試験';

    // 5. 検査後説明・手紙・書類サマリ
    $post = [];
    if (!empty($checks['post_gif_exp'])) $post[] = '胃カメラ説明';
    if (!empty($checks['post_cf_exp'])) $post[] = '大腸カメラ説明';
    if (!empty($checks['post_blood'])) $post[] = '検査後採血あり';
    if (!empty($checks['post_letter']) || !empty($circles['letter_self']) || !empty($circles['letter_nakazawa'])) {
        $l_detail = [];
        if (!empty($circles['letter_self'])) {
            $l_detail[] = '本人渡し' . (!empty($circles['letter_self_done']) ? '(スミ)' : '');
        }
        if (!empty($circles['letter_nakazawa'])) {
            $l_detail[] = '中澤' . (!empty($circles['letter_nakazawa_done']) ? '(スミ)' : '');
        }
        $post[] = '手紙' . (!empty($l_detail) ? ':' . implode('/', $l_detail) : '');
    }
    if (trim((string)($inputs['post_other_note'] ?? '')) !== '') {
        $post[] = '他:' . trim((string)$inputs['post_other_note']);
    }

    $doctor_sign = trim((string)($sheetRow['doctor_sign'] ?? ($inputs['doctor_sign'] ?? '')));
    $post_dest   = trim((string)($sheetRow['post_destination'] ?? ''));
    if ($post_dest === '') {
        if (!empty($circles['stamp_recovery'])) $post_dest = 'RECOVERY';
        elseif (!empty($circles['stamp_waiting'])) $post_dest = 'WAITING';
        elseif (!empty($circles['stamp_ok'])) $post_dest = 'OK';
    }

    $is_ready_acc  = !empty($sheetRow['is_ready_accounting']) || !empty($checks['go_accounting']);
    $is_billing_ok = !empty($sheetRow['is_billing_completed']);

    return [
        'has_sheet'            => true,
        'bp_sys'               => $bp_sys !== '' ? (int)$bp_sys : null,
        'bp_dia'               => $bp_dia !== '' ? (int)$bp_dia : null,
        'bp_label'             => $bp_label,
        'stool_label'          => $stool_label,
        'exam_start_time'      => (string)($sheetRow['exam_start_time'] ?? ''),
        'exam_end_time'        => (string)($sheetRow['exam_end_time'] ?? ''),
        'wake_time'            => (string)($sheetRow['wake_time'] ?? ''),
        'explain_time'         => (string)($sheetRow['explain_time'] ?? ''),
        'post_destination'     => $post_dest,
        'doctor_sign'          => $doctor_sign,
        'is_ready_accounting'  => $is_ready_acc,
        'is_billing_completed' => $is_billing_ok,
        'billing_completed_at' => !empty($sheetRow['billing_completed_at']) ? date('H:i', strtotime((string)$sheetRow['billing_completed_at'])) : '',
        'drugs_summary'        => $drugs,
        'procedures_summary'   => $procs,
        'post_summary'         => $post,
        'updated_at'           => !empty($sheetRow['updated_at']) ? date('H:i:s', strtotime((string)$sheetRow['updated_at'])) : '',
    ];
}
