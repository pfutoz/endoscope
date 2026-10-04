<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\action_slot_assistant.php
 * ファイル名: action_slot_assistant.php
 * 
 * 直近空き枠速報 ＆ スマート予約アシスト API
 * 医療法人小野会 内視鏡予約管理システム
 * 
 * 主な機能:
 * 1. 本日〜1か月以内（30日間）の大腸内視鏡（CF/TCF/W/ポリペク）空き枠を全科横断・全医師横断でリアルタイム集計
 * 2. 直近7日以内にキャンセル・移動が発生した「⚡直前キャンセル空き枠」を検知して優先フラグ＆メタ情報付与
 * 3. 枠が埋まった瞬間にリアルタイム自動非表示（手動消込不要・二重予約防止）
 * 4. 医師指名（誠吾・哲也・義智・慶太郎）に対応した絞り込み＆カラーコード連携
 * 5. カレンダー・予約モーダル直接起動用のジャンプURL生成
 */

ini_set('display_errors', "0");
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/slot_helper.php';

try {
    $pdo = getDbConnection();
    if (!$pdo) {
        throw new Exception("データベース接続に失敗しました");
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? 'get_urgent_slots';

    if ($action === 'get_urgent_slots') {
        $doctor_filter = isset($_GET['doctor']) ? trim((string)$_GET['doctor']) : 'all';
        $days = isset($_GET['days']) ? max(1, min(60, (int)$_GET['days'])) : 30;
        $only_cancel = isset($_GET['only_cancel']) && ($_GET['only_cancel'] === '1' || $_GET['only_cancel'] === 'true');

        $today = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("$today +" . ($days - 1) . " days"));

        // 医師定義とカラーマッピング
        $doctor_meta = [
            '誠吾' => [
                'dept' => 'PROCT',
                'dept_name' => '肛門科',
                'badge_class' => 'bg-emerald',
                'color' => '#059669',
                'light_bg' => '#ecfdf5',
                'border_color' => '#10b981',
                'order' => 1
            ],
            '哲也' => [
                'dept' => 'GASTRO',
                'dept_name' => '胃腸科',
                'badge_class' => 'bg-indigo',
                'color' => '#4f46e5',
                'light_bg' => '#eef2ff',
                'border_color' => '#6366f1',
                'order' => 2
            ],
            '義智' => [
                'dept' => 'GASTRO',
                'dept_name' => '胃腸科',
                'badge_class' => 'bg-purple',
                'color' => '#7c3aed',
                'light_bg' => '#f5f3ff',
                'border_color' => '#8b5cf6',
                'order' => 3
            ],
            '慶太郎' => [
                'dept' => 'GASTRO',
                'dept_name' => '胃腸科',
                'badge_class' => 'bg-amber',
                'color' => '#d97706',
                'light_bg' => '#fffbeb',
                'border_color' => '#f59e0b',
                'order' => 4
            ],
        ];

        $doctors_by_dept = [
            'PROCT'  => ['誠吾'],
            'GASTRO' => ['哲也', '義智', '慶太郎']
        ];

        // 1. 本日〜指定期間の大腸内視鏡（TCF/W/ポリペク）予約数を一括取得
        $sql_reserved = "
            SELECT 
                department_code,
                exam_date,
                doctor_name,
                COUNT(*) as used_count
            FROM t_endoscopy_schedule
            WHERE exam_date >= :today
              AND exam_date <= :end_date
              AND exam_type IN ('TCF', 'W', 'ポリペク')
              AND is_canceled = FALSE
              AND remarks NOT LIKE '%枠なし%'
            GROUP BY department_code, exam_date, doctor_name
        ";
        $stmt_res = $pdo->prepare($sql_reserved);
        $stmt_res->execute([':today' => $today, ':end_date' => $end_date]);
        $reserved_map = [];
        while ($row = $stmt_res->fetch(PDO::FETCH_ASSOC)) {
            $d = $row['exam_date'];
            $dep = $row['department_code'];
            $doc = $row['doctor_name'];
            $reserved_map[$d][$dep][$doc] = (int)$row['used_count'];
        }

        // 2. 直近7日以内の監査ログから大腸予約のキャンセル・日程変更履歴を一括取得
        $sql_cancel_logs = "
            SELECT 
                log_id,
                action_type,
                department_code,
                patient_name,
                old_exam_date,
                new_exam_date,
                COALESCE(old_data->>'doctor_name', new_data->>'doctor_name') as doc_name,
                COALESCE(old_data->>'exam_type', new_data->>'exam_type') as exam_name,
                COALESCE(new_data->>'cancel_reason', old_data->>'cancel_reason', '') as cancel_reason,
                old_data->>'created_at' as schedule_created_at,
                diff_summary,
                created_at
            FROM t_schedule_audit_log
            WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
              AND old_exam_date >= :today
              AND old_exam_date <= :end_date
              AND (
                  action_type = 'delete'
                  OR (action_type = 'move' AND old_exam_date != new_exam_date)
                  OR (action_type = 'update' AND old_data->>'is_canceled' = 'false' AND new_data->>'is_canceled' = 'true')
              )
              AND (
                  old_data->>'exam_type' IN ('TCF', 'W', 'ポリペク')
                  OR new_data->>'exam_type' IN ('TCF', 'W', 'ポリペク')
              )
            ORDER BY created_at DESC
        ";
        $stmt_cancel = $pdo->prepare($sql_cancel_logs);
        $stmt_cancel->execute([':today' => $today, ':end_date' => $end_date]);
        $cancel_map = [];
        $now_dt = new DateTime();

        while ($c_row = $stmt_cancel->fetch(PDO::FETCH_ASSOC)) {
            $c_reason = $c_row['cancel_reason'] ?? '';

            // ★ 1. 明示的な誤登録・即時取消は速報から除外
            if ($c_reason === 'mistake' || $c_reason === 'auto_mistake') {
                continue;
            }

            // ★ 2. 予約作成から10分以内の削除・取消は「誤登録の即時取消・打ち直し」とみなして除外
            $sch_created_str = $c_row['schedule_created_at'] ?? '';
            if (!empty($sch_created_str)) {
                $sch_ts = strtotime($sch_created_str);
                $log_ts = strtotime($c_row['created_at']);
                $diff_sec = $log_ts - $sch_ts;
                if ($diff_sec >= 0 && $diff_sec <= 600) {
                    continue; // 10分以内の即時削除はキャンセル速報から除外！
                }
            }

            // ★ 3. 仮予約・テスト入力・ダミーの削除も除外
            $p_name = trim((string)($c_row['patient_name'] ?? ''));
            if ($p_name === '' || $p_name === '---' || mb_strpos($p_name, '仮') !== false || mb_strpos($p_name, 'テスト') !== false) {
                continue;
            }

            $c_date = $c_row['old_exam_date'];
            $c_dept = $c_row['department_code'];
            $c_doc  = $c_row['doc_name'] ?? '';

            // 相対時間計算（例: "3時間前", "昨日", "2日前"）
            $created_dt = new DateTime($c_row['created_at']);
            $diff_seconds = $now_dt->getTimestamp() - $created_dt->getTimestamp();
            if ($diff_seconds < 3600) {
                $time_ago = max(1, floor($diff_seconds / 60)) . "分前";
            } elseif ($diff_seconds < 86400) {
                $time_ago = floor($diff_seconds / 3600) . "時間前";
            } elseif ($diff_seconds < 172800) {
                $time_ago = "昨日";
            } else {
                $time_ago = floor($diff_seconds / 86400) . "日前";
            }

            // キャンセル理由ラベル
            $reason_label = '直前キャンセル';
            if ($c_reason === 'patient_cancel') {
                $reason_label = '患者都合キャンセル';
            } elseif ($c_reason === 'reschedule' || $c_row['action_type'] === 'move') {
                $reason_label = '日程変更・別日振替';
            }

            $cancel_info = [
                'log_id' => (int)$c_row['log_id'],
                'patient_name' => $c_row['patient_name'],
                'exam_type' => $c_row['exam_name'],
                'action_type' => $c_row['action_type'],
                'cancel_reason' => $c_reason,
                'reason_label' => $reason_label,
                'created_at' => $c_row['created_at'],
                'time_ago' => $time_ago,
                'diff_summary' => $c_row['diff_summary']
            ];

            $cancel_map[$c_date][$c_dept][$c_doc][] = $cancel_info;
            // 医師未定または医師一致用キー
            $cancel_map[$c_date][$c_dept]['_all_'][] = $cancel_info;
        }

        // 3. スロット計算
        $slots = [];
        $dow_names = ['日', '月', '火', '水', '木', '金', '土'];

        $summary_counts = [
            'total_free' => 0,
            'total_cancel_free' => 0,
            'by_doctor' => [
                '誠吾' => 0,
                '哲也' => 0,
                '義智' => 0,
                '慶太郎' => 0
            ],
            'by_dept' => [
                'PROCT' => 0,
                'GASTRO' => 0
            ],
            'nearest_date' => null,
            'nearest_doctor' => null
        ];

        for ($i = 0; $i < $days; $i++) {
            $cur_date = date('Y-m-d', strtotime("$today +$i days"));
            $dow = (int)date('w', strtotime($cur_date));
            if ($dow === 0) continue; // 日曜休診

            foreach ($doctors_by_dept as $dept => $doc_list) {
                // 科全体の空き枠を算出
                $avail = getSlotAvailability($pdo, $dept, $cur_date);
                $dept_tcf_free = $avail['free']['TCF'] ?? 0;

                // 科全体で大腸枠の空きがなければスキップ
                if ($dept_tcf_free <= 0) {
                    continue;
                }

                foreach ($doc_list as $doc) {
                    // 医師フィルター判定
                    if ($doctor_filter !== 'all' && $doctor_filter !== $doc) {
                        continue;
                    }

                    $doc_status = $avail['doctors'][$doc] ?? null;
                    if (!$doc_status || empty($doc_status['present'])) {
                        continue;
                    }

                    $cap = (int)($doc_status['slots']['TCF'] ?? 0);
                    if ($cap <= 0) {
                        continue;
                    }

                    $used = (int)($reserved_map[$cur_date][$dept][$doc] ?? 0);
                    $free = $cap - $used;

                    // 空きがある場合のみ採用（枠が埋まったら即時非表示）
                    if ($free > 0) {
                        // 直前キャンセル有無
                        $c_list = $cancel_map[$cur_date][$dept][$doc] ?? ($cancel_map[$cur_date][$dept]['_all_'] ?? []);
                        $has_cancel = !empty($c_list);

                        if ($only_cancel && !$has_cancel) {
                            continue;
                        }

                        $meta = $doctor_meta[$doc] ?? [
                            'dept' => $dept,
                            'dept_name' => $dept === 'PROCT' ? '肛門科' : '胃腸科',
                            'badge_class' => 'bg-slate',
                            'color' => '#475569',
                            'light_bg' => '#f8fafc',
                            'border_color' => '#cbd5e1',
                            'order' => 99
                        ];

                        $dt_obj = new DateTime($cur_date);
                        $today_obj = new DateTime($today);
                        $day_diff = (int)$today_obj->diff($dt_obj)->days;
                        $relative_label = '';
                        if ($day_diff === 0) $relative_label = '本日';
                        elseif ($day_diff === 1) $relative_label = '明日';
                        elseif ($day_diff === 2) $relative_label = '明後日';
                        else $relative_label = "{$day_diff}日後";

                        // カレンダーへの遷移URL
                        $cal_year = (int)$dt_obj->format('Y');
                        $cal_month = (int)$dt_obj->format('n');
                        $jump_url = "month_schedule.php?dept={$dept}&year={$cal_year}&month={$cal_month}&date={$cur_date}&open_new=1";

                        $slot_item = [
                            'date' => $cur_date,
                            'year' => $cal_year,
                            'month' => $cal_month,
                            'day' => (int)$dt_obj->format('j'),
                            'dow' => $dow_names[$dow],
                            'day_diff' => $day_diff,
                            'relative_label' => $relative_label,
                            'dept' => $dept,
                            'dept_name' => $meta['dept_name'],
                            'doctor' => $doc,
                            'doctor_order' => $meta['order'],
                            'badge_class' => $meta['badge_class'],
                            'color' => $meta['color'],
                            'light_bg' => $meta['light_bg'],
                            'border_color' => $meta['border_color'],
                            'cap' => $cap,
                            'used' => $used,
                            'free' => $free,
                            'dept_total_free' => $dept_tcf_free,
                            'has_cancel' => $has_cancel,
                            'cancel_count' => count($c_list),
                            'cancel_details' => $c_list,
                            'jump_url' => $jump_url,
                        ];

                        $slots[] = $slot_item;

                        // サマリー集計
                        $summary_counts['total_free'] += $free;
                        if ($has_cancel) {
                            $summary_counts['total_cancel_free'] += 1;
                        }
                        if (isset($summary_counts['by_doctor'][$doc])) {
                            $summary_counts['by_doctor'][$doc] += $free;
                        }
                        if (isset($summary_counts['by_dept'][$dept])) {
                            $summary_counts['by_dept'][$dept] += $free;
                        }
                        if ($summary_counts['nearest_date'] === null) {
                            $summary_counts['nearest_date'] = $cur_date;
                            $summary_counts['nearest_doctor'] = $doc;
                            $summary_counts['nearest_label'] = "{$cur_date} ({$dow_names[$dow]}) {$relative_label}";
                        }
                    }
                }
            }
        }

        // ソート順: 
        // 1. 直前キャンセルあり優先 (キャンセルあり > なし) ※オプションやデフォルト挙動
        // 2. 日付昇順 (近い日付から)
        // 3. 医師順 (誠吾 -> 哲也 -> 義智 -> 慶太郎)
        usort($slots, function($a, $b) {
            if ($a['date'] !== $b['date']) {
                return strcmp($a['date'], $b['date']);
            }
            if ($a['has_cancel'] !== $b['has_cancel']) {
                return $a['has_cancel'] ? -1 : 1;
            }
            return $a['doctor_order'] <=> $b['doctor_order'];
        });

        echo json_encode([
            'success' => true,
            'summary' => $summary_counts,
            'slots' => $slots,
            'queried_at' => date('Y-m-d H:i:s'),
            'filter_doctor' => $doctor_filter,
            'days' => $days
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception("無効なアクションです: {$action}");

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
