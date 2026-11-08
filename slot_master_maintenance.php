<?php
/**
 * 生成日時: 2026-09-29 14:35:00 JST
 * 更新日時: 2026-11-06
 * 設置パス: c:\Apache24\htdocs\endoscope\slot_master_maintenance.php
 * ファイル名: slot_master_maintenance.php
 * 概要: 検査枠マスターメンテナンス（時間枠世代管理 valid_from〜valid_to・期間一括変更・監査ログ＆ワンクリック復元対応版）
 * 
 * 修正履歴:
 *   - 2026-09-18: 枠数パターンの横持ち入力を縦持ちテーブルへ確実にするUPSERT処理、出勤例外タブ復元
 *   - 2026-09-29: 検査枠マスタ変更履歴（t_master_audit_log）の自動差分記録、ワンクリックUndo（復元）、履歴一括削除パネルを新設
 *   - 2026-10-20: 特大Flatpickr（小野会カレンダー・公休日色分け）日付ピッカー導入
 *   - 2026-10-22: 時間枠オーバーライド（m2_waku_override）連携導線および安全ガイドバナーを新設
 *   - 2026-10-23: lib/slot_helper.php との getWakuList 関数二重定義エラーを解消、ローカル環境（192.168.1.111）の誤操作防止UI（外側黒背景＆警告バナー）を適用
 *   - 2026-10-26: 3大マスタ統合ヘッダーナビゲーション導入、検査種別マスタ(exam_master_maintenance.php)連携
 *   - 2026-11-01: 統合ヘッダーナビに「📢 修正インフォメーション」タブを追加
 *   - 2026-11-06: 【時間枠世代管理対応】基本時間枠タブ（?tab=waku）に世代（valid_from〜valid_to）切替タブ、世代期間一括変更（前後世代の境界日自動連動対応）、新世代作成（複製・期間分割）、世代別枠追加フォームを実装。
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// ============================================================
// 1. データベース接続
// ============================================================
$host = 'localhost';
$dbname = 'endoscope';
$user = 'postgres';
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
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/env_helper.php';

// ============================================================
// 1.5. POST処理（一括保存などのバックエンド処理）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $dept_post = $_POST['dept'] ?? 'GASTRO';
    $doctor_post = $_POST['doctor_name'] ?? '';

    if ($action === 'update_override_permission') {
        $allowed = !empty($_POST['allow_period_override']);
        $res = setPeriodOverrideAllowed($pdo, $allowed);
        if ($res) {
            header("Location: slot_master_maintenance.php?tab=waku&dept={$dept_post}&msg=" . urlencode("時間枠オーバーライド運用設定を更新しました（期間指定: " . ($allowed ? '許可' : '禁止 [現場保護・単一日のみ]') . "）"));
            exit;
        } else {
            header("Location: slot_master_maintenance.php?tab=waku&dept={$dept_post}&msg=" . urlencode("設定保存エラー") . "&error=1");
            exit;
        }
    }

    if ($action === 'pattern_update') {
        try {
            $pdo->beginTransaction();

            $dow_list = $_POST['day_of_week'] ?? [];
            $pat_names = $_POST['pattern_name'] ?? [];
            $gif_vals = $_POST['gif_slots'] ?? [];
            $echo_vals = $_POST['echo_slots'] ?? [];
            $tcf_vals = $_POST['tcf_slots'] ?? [];

            // 変更前の状態を取得
            $stmt_cur = $pdo->prepare("SELECT * FROM m2_doctor_slot_pattern WHERE doctor_name = :doc AND department_code = :dept");
            $stmt_cur->execute([':doc' => $doctor_post, ':dept' => $dept_post]);
            $old_patterns = $stmt_cur->fetchAll();

            $stmt_upsert = $pdo->prepare("
                INSERT INTO m2_doctor_slot_pattern 
                (doctor_name, department_code, pattern_name, day_of_week, group_code, max_slots, gif_slots, echo_slots, tcf_slots, is_active, updated_at)
                VALUES (:doc, :dept, :pat, :dow, :grp, :slots, :gif, :echo, :tcf, TRUE, CURRENT_TIMESTAMP)
                ON CONFLICT (doctor_name, department_code, pattern_name, day_of_week, group_code)
                DO UPDATE SET 
                    max_slots = EXCLUDED.max_slots, 
                    gif_slots = EXCLUDED.gif_slots, 
                    echo_slots = EXCLUDED.echo_slots, 
                    tcf_slots = EXCLUDED.tcf_slots, 
                    updated_at = CURRENT_TIMESTAMP
            ");

            $new_patterns = [];
            $diff_notes = [];

            for ($i = 0; $i < count($dow_list); $i++) {
                $p_name = $pat_names[$i];
                $dow = (int)$dow_list[$i];
                $g_val = (int)($gif_vals[$i] ?? 0);
                $e_val = (int)($echo_vals[$i] ?? 0);
                $t_val = (int)($tcf_vals[$i] ?? 0);

                // GIF
                $stmt_upsert->execute([
                    ':doc' => $doctor_post, ':dept' => $dept_post, ':pat' => $p_name, ':dow' => $dow, 
                    ':grp' => 'GIF', ':slots' => $g_val, ':gif' => $g_val, ':echo' => 0, ':tcf' => 0
                ]);
                // ECHO
                $stmt_upsert->execute([
                    ':doc' => $doctor_post, ':dept' => $dept_post, ':pat' => $p_name, ':dow' => $dow, 
                    ':grp' => 'ECHO', ':slots' => $e_val, ':gif' => 0, ':echo' => $e_val, ':tcf' => 0
                ]);
                // TCF
                $stmt_upsert->execute([
                    ':doc' => $doctor_post, ':dept' => $dept_post, ':pat' => $p_name, ':dow' => $dow, 
                    ':grp' => 'TCF', ':slots' => $t_val, ':gif' => 0, ':echo' => 0, ':tcf' => $t_val
                ]);

                $new_patterns[] = ['doctor_name' => $doctor_post, 'department_code' => $dept_post, 'pattern_name' => $p_name, 'day_of_week' => $dow, 'gif_slots' => $g_val, 'echo_slots' => $e_val, 'tcf_slots' => $t_val];
                $diff_notes[] = "{$p_name}(曜:{$dow}, G:{$g_val}, E:{$e_val}, T:{$t_val})";
            }

            // 監査ログ記録
            recordMasterAuditLog(
                $pdo,
                'pattern',
                'update',
                'm2_doctor_slot_pattern',
                null,
                "{$doctor_post} 枠パターン一括更新",
                $old_patterns,
                $new_patterns,
                "枠パターン更新 [{$doctor_post}]: " . implode('; ', $diff_notes)
            );

            $pdo->commit();
            header("Location: slot_master_maintenance.php?tab=pattern&dept={$dept_post}&doctor=" . urlencode($doctor_post) . "&msg=" . urlencode("枠数パターンを正常に保存しました"));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: slot_master_maintenance.php?tab=pattern&dept={$dept_post}&doctor=" . urlencode($doctor_post) . "&msg=" . urlencode("保存エラー: " . $e->getMessage()) . "&error=1");
            exit;
        }
    }
}

// ============================================================
// 2. パラメータ取得
// ============================================================
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'doctor';
$dept = isset($_GET['dept']) ? $_GET['dept'] : 'GASTRO';
$doctor = isset($_GET['doctor']) ? $_GET['doctor'] : '';

// 出勤例外用パラメータ（年単位）
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$show_past = isset($_GET['show_past']) && $_GET['show_past'] === '1';

// 年ナビゲーション用
$prev_year = $year - 1;
$next_year = $year + 1;

// 有効なタブ
$tabs = ['doctor', 'waku', 'pattern', 'attendance'];
if (!in_array($tab, $tabs)) { $tab = 'doctor'; }
if (!in_array($dept, ['PROCT', 'GASTRO'])) { $dept = 'GASTRO'; }

$tab_table_map = [
    'doctor'     => 'm2_doctor',
    'waku'       => 'm2_waku',
    'pattern'    => 'm2_doctor_slot_pattern',
    'attendance' => 'm2_doctor_attendance',
];
$current_table = $tab_table_map[$tab] ?? 'm2_doctor';

$message = isset($_GET['msg']) ? trim($_GET['msg']) : '';
$is_error = isset($_GET['error']) ? (int)$_GET['error'] : 0;

// ============================================================
// 3. データ取得関数
// ============================================================
function getDoctors($pdo, $dept = null) {
    $sql = "SELECT * FROM m2_doctor WHERE is_active = TRUE";
    if ($dept) {
        $sql .= " AND department_code = :dept";
    }
    $sql .= " ORDER BY department_code, display_order";
    $stmt = $pdo->prepare($sql);
    if ($dept) {
        $stmt->execute([':dept' => $dept]);
    } else {
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

function getSlotGroups($pdo, $dept) {
    $stmt = $pdo->prepare("
        SELECT * FROM m2_slot_group 
        WHERE department_code = :dept AND is_active = TRUE
        ORDER BY display_order
    ");
    $stmt->execute([':dept' => $dept]);
    return $stmt->fetchAll();
}


function getDoctorPatterns($pdo, $dept, $doctor_name) {
    $stmt = $pdo->prepare("
        SELECT 
            doctor_name,
            department_code,
            pattern_name,
            day_of_week,
            MAX(CASE WHEN group_code = 'GIF' THEN max_slots ELSE 0 END) as gif_slots,
            MAX(CASE WHEN group_code = 'ECHO' THEN max_slots ELSE 0 END) as echo_slots,
            MAX(CASE WHEN group_code = 'TCF' THEN max_slots ELSE 0 END) as tcf_slots,
            BOOL_OR(is_active) as is_active,
            MIN(created_at) as created_at,
            MAX(updated_at) as updated_at
        FROM m2_doctor_slot_pattern 
        WHERE department_code = :dept
          AND doctor_name = :doctor 
          AND is_active = TRUE
        GROUP BY doctor_name, department_code, pattern_name, day_of_week
        ORDER BY pattern_name, day_of_week
    ");
    $stmt->execute([':dept' => $dept, ':doctor' => $doctor_name]);
    return $stmt->fetchAll();
}

function getPatternNames($pdo, $dept, $doctor_name) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT pattern_name FROM m2_doctor_slot_pattern 
        WHERE department_code = :dept AND doctor_name = :doctor AND is_active = TRUE
        ORDER BY pattern_name
    ");
    $stmt->execute([':dept' => $dept, ':doctor' => $doctor_name]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// 出勤例外一覧取得（年単位・過去分フィルタ対応）
function getAttendanceList($pdo, $dept, $year, $show_past = true) {
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    
    $sql = "
        SELECT * FROM m2_doctor_attendance 
        WHERE department_code = :dept 
          AND target_date BETWEEN :start AND :end
    ";
    
    if (!$show_past) {
        $sql .= " AND target_date >= CURRENT_DATE";
    }
    
    $sql .= " ORDER BY target_date ASC, doctor_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':dept' => $dept, ':start' => $start, ':end' => $end]);
    return $stmt->fetchAll();
}

// ============================================================
// 5. データ取得
// ============================================================
$doctors = getDoctors($pdo, null);
$slot_groups = getSlotGroups($pdo, $dept);

// 時間枠世代（valid_from 〜 valid_to）一覧と選択世代の決定
$waku_generations = getWakuGenerations($pdo, $dept);
$req_gen_from = isset($_GET['gen_from']) ? trim($_GET['gen_from']) : '';
$req_gen_to   = isset($_GET['gen_to']) ? trim($_GET['gen_to']) : '';

$selected_gen = null;
if ($req_gen_from !== '' && $req_gen_to !== '') {
    foreach ($waku_generations as $g) {
        if ($g['valid_from'] === $req_gen_from && $g['valid_to'] === $req_gen_to) {
            $selected_gen = $g;
            break;
        }
    }
}
if ($selected_gen === null) {
    foreach ($waku_generations as $g) {
        if (!empty($g['is_current'])) {
            $selected_gen = $g;
            break;
        }
    }
}
if ($selected_gen === null && !empty($waku_generations)) {
    $selected_gen = $waku_generations[0];
}
if ($selected_gen === null) {
    $selected_gen = [
        'gen_index'    => 1,
        'valid_from'   => '1900-01-01',
        'valid_to'     => '9999-12-31',
        'total_count'  => 0,
        'active_count' => 0,
        'is_current'   => true,
        'label'        => '全期間（〜 9999.9.9）',
    ];
}

$waku_list = getWakuListForGeneration($pdo, $dept, $selected_gen['valid_from'], $selected_gen['valid_to']);
$pattern_data = $doctor ? getDoctorPatterns($pdo, $dept, $doctor) : [];
$pattern_names = $doctor ? getPatternNames($pdo, $dept, $doctor) : [];
$attendance_list = getAttendanceList($pdo, $dept, $year, $show_past);

$dept_label = ($dept === 'PROCT') ? '肛門科' : '胃腸科';

$doctor_options = [];
foreach ($doctors as $d) {
    if ($d['department_code'] === $dept) {
        $doctor_options[] = $d;
    }
}
$week_ja = ['日', '月', '火', '水', '木', '金', '土'];

// 検査枠マスタの直近変更履歴を取得（直近20件）
$recent_slot_logs = getMasterAuditLogs($pdo, 'slot_master', 20);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>検査枠マスターメンテナンス</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            margin: 20px;
            background-color: #f4f6f9;
            color: #333;
            font-size: 13px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        h2 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 2px solid #34495e;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .home-btn {
            background: #2c3e50;
            color: #fff;
            padding: 6px 14px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
        }
        .home-btn:hover { background: #34495e; }
        
        /* 3大マスタ統合ヘッダーナビ */
        .master-hub-nav {
            display: flex;
            gap: 6px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            align-items: center;
            flex-wrap: wrap;
        }
        .master-hub-title {
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 6px;
        }
        .hub-tab {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 6px;
            text-decoration: none;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .hub-tab:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .hub-tab.active {
            background: #0284c7;
            color: #fff;
            border-color: #0284c7;
            box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3);
        }
        .hub-tab.home-link {
            margin-left: auto;
            background: #fff;
            border-color: #cbd5e1;
        }
        .hub-tab.home-link:hover {
            background: #f1f5f9;
        }

        .tabs {
            display: flex;
            gap: 2px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        .tab {
            padding: 10px 24px;
            text-decoration: none;
            font-weight: bold;
            border-radius: 6px 6px 0 0;
            color: #64748b;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-bottom: none;
            margin-bottom: -2px;
            font-size: 13px;
            transition: all 0.2s;
        }
        .tab:hover { background: #e2e8f0; }
        .tab.active {
            background: #fff;
            color: #0f172a;
            border-top: 3px solid #3b82f6;
            border-bottom: 2px solid #fff;
            padding-bottom: 11px;
        }
        
        .filter-bar {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }
        .filter-bar label {
            font-weight: bold;
            font-size: 12px;
            color: #475569;
        }
        .filter-bar select,
        .filter-bar input[type="text"] {
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 13px;
            background: #fff;
        }
        .filter-bar .btn {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 4px 10px; font-size: 11px; }
        
        .msg {
            padding: 12px 16px;
            border-radius: 4px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .msg-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #f1f5f9;
            font-weight: bold;
            color: #334155;
            white-space: nowrap;
        }
        tr:hover { background: #f8fafc; }
        
        .input-text {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
            width: 100%;
            box-sizing: border-box;
        }
        .input-number {
            width: 55px;
            text-align: center;
            padding: 5px 4px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
        }
        .input-date {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .empty-msg {
            color: #94a3b8;
            text-align: center;
            padding: 30px 0;
        }
        
        .form-inline {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 15px;
        }
        .form-inline label {
            font-weight: bold;
            font-size: 12px;
            color: #475569;
            white-space: nowrap;
        }

        .pattern-summary {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
        }
        .pattern-summary.total-green { color: #16a34a; }
        .pattern-summary.total-red { color: #dc2626; }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            background: #d1fae5;
            color: #065f46;
        }
        
        /* 年ナビゲーション用 */
        .year-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .year-nav .year-display {
            font-weight: bold;
            font-size: 18px;
            padding: 0 10px;
            min-width: 80px;
            text-align: center;
        }
        .year-nav .nav-btn {
            padding: 4px 12px;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .year-nav .nav-btn.arrow { background: #e2e8f0; color: #333; }
        .year-nav .nav-btn.arrow:hover { background: #cbd5e1; }
        .year-nav .nav-btn.this-year { background: #3b82f6; color: #fff; }
        .year-nav .nav-btn.this-year:hover { background: #2563eb; }
        .year-nav .nav-btn.next-year { background: #10b981; color: #fff; }
        .year-nav .nav-btn.next-year:hover { background: #059669; }
        
        .past-toggle {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-left: 16px;
            cursor: pointer;
            font-weight: bold;
            color: #475569;
        }
        .past-toggle:hover { color: #0f172a; }
        .past-toggle input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }

        .mt-10 { margin-top: 10px; }
        .text-right { text-align: right; }
        .nowrap { white-space: nowrap; }

        /* 履歴・復元セクション */
        .audit-section { margin-top: 40px; border-top: 2px solid #e2e8f0; padding-top: 20px; }
        .audit-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .audit-table th { background: #f8fafc; font-size: 12px; }
        .audit-table td { font-size: 12px; vertical-align: middle; }
        .badge-action { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .badge-insert { background: #d1fae5; color: #065f46; }
        .badge-update { background: #dbeafe; color: #1e40af; }
        .badge-delete { background: #fee2e2; color: #991b1b; }
        .badge-restore { background: #fef3c7; color: #92400e; }
        .badge-undone { background: #f1f5f9; color: #94a3b8; text-decoration: line-through; }
    </style>
    <script>
        // 医師選択時にパターンを非同期で絞り込む
        function loadPatterns(doctorName) {
            const dept = '<?= $dept ?>';
            const patternSelect = document.querySelector('select[name="pattern_name"]');
            if (!patternSelect) return;
            
            if (!doctorName) {
                patternSelect.innerHTML = '<option value="">-- なし --</option>';
                return;
            }
            
            fetch(`get_doctor_patterns.php?doctor=${encodeURIComponent(doctorName)}&dept=${encodeURIComponent(dept)}`)
                .then(res => res.json())
                .then(data => {
                    patternSelect.innerHTML = '<option value="">-- なし --</option>';
                    if (data.success && data.patterns) {
                        data.patterns.forEach(pattern => {
                            const opt = document.createElement('option');
                            opt.value = pattern;
                            opt.textContent = pattern;
                            patternSelect.appendChild(opt);
                        });
                    }
                })
                .catch(() => {
                    patternSelect.innerHTML = '<option value="">-- なし --</option>';
                });
        }
        
        // 合計枠数の動的更新
        function updateTotal() {
            const gif = parseInt(document.querySelector('input[name="gif_slots"]').value) || 0;
            const echo = parseInt(document.querySelector('input[name="echo_slots"]').value) || 0;
            const tcf = parseInt(document.querySelector('input[name="tcf_slots"]').value) || 0;
            const totalEl = document.getElementById('total_slots_display');
            if (totalEl) totalEl.textContent = gif + echo + tcf;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const doctorSelect = document.querySelector('select[name="doctor_name"]');
            if (doctorSelect) {
                doctorSelect.addEventListener('change', function() {
                    loadPatterns(this.value);
                });
            }
            
            const slots = document.querySelectorAll('input[name="gif_slots"], input[name="echo_slots"], input[name="tcf_slots"]');
            slots.forEach(input => {
                input.addEventListener('input', updateTotal);
                input.addEventListener('change', updateTotal);
            });
        });
    </script>
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css?v=<?= filemtime(__DIR__ . '/assets/css/onokai_flatpickr.css') ?>">
    <link rel="stylesheet" href="assets/css/theme.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>
</head>
<body <?= getBodyEnvClass() ?>>
<?= renderLocalEnvBanner() ?>

<div class="container">

    <!-- 3大マスタ統合ヘッダーナビゲーション -->
    <nav class="master-hub-nav" aria-label="マスタ管理ナビゲーション">
        <span class="master-hub-title">📋 院内マスタ管理:</span>
        <a href="slot_master_maintenance.php" class="hub-tab active">⏱️ 検査枠マスタ</a>
        <a href="exam_master_maintenance.php" class="hub-tab">🔬 検査種別マスタ</a>
        <a href="master_maintenance.php" class="hub-tab">🔧 汎用選択肢マスタ</a>
        <a href="printer_master_maintenance.php" class="hub-tab">🖨️ プリンターマスタ</a>
        <a href="system_info_maintenance.php" class="hub-tab">📢 修正インフォメーション</a>
        <a href="furiwake_daymemo.php" class="hub-tab">📝 日別メモ科振分</a>
        <a href="index.php" class="hub-tab home-link">🏠 ポータルへ</a>
    </nav>

    <h2 style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
        <span style="display: inline-flex; align-items: center; gap: 8px;">
            <span>⚙️ 検査枠マスターメンテナンス</span>
            <code style="font-size: 11px; font-weight: 600; font-family: ui-monospace, monospace; background: #f1f5f9; color: #475569; padding: 2px 7px; border-radius: 4px; border: 1px solid #cbd5e1;" title="PostgreSQL 対象テーブル (選択中タブ連動)">TABLE: <?= htmlspecialchars($current_table) ?></code>
        </span>
        <div class="header-nav">
            <a href="index.php" class="home-btn">🏠 メニューへ</a>
        </div>
    </h2>

    <?php if (!empty($message)): ?>
        <div class="msg <?= $is_error ? 'msg-error' : 'msg-success' ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?tab=doctor&dept=<?= $dept ?>" class="tab <?= $tab === 'doctor' ? 'active' : '' ?>" title="対象テーブル: m2_doctor">👨‍⚕️ 医師 <small style="font-size:10.5px; opacity:0.75; font-family:monospace;">(m2_doctor)</small></a>
        <a href="?tab=waku&dept=<?= $dept ?>" class="tab <?= $tab === 'waku' ? 'active' : '' ?>" title="対象テーブル: m2_waku">🕐 基本時間枠 <small style="font-size:10.5px; opacity:0.75; font-family:monospace;">(m2_waku)</small></a>
        <a href="?tab=pattern&dept=<?= $dept ?>&doctor=<?= urlencode($doctor) ?>" class="tab <?= $tab === 'pattern' ? 'active' : '' ?>" title="対象テーブル: m2_doctor_slot_pattern">📊 枠数パターン <small style="font-size:10.5px; opacity:0.75; font-family:monospace;">(m2_pattern)</small></a>
        <a href="?tab=attendance&dept=<?= $dept ?>&year=<?= $year ?>&show_past=<?= $show_past?'1':'0' ?>" class="tab <?= $tab === 'attendance' ? 'active' : '' ?>" title="対象テーブル: m2_doctor_attendance">📅 出勤例外 <small style="font-size:10.5px; opacity:0.75; font-family:monospace;">(m2_attendance)</small></a>
        <a href="month_schedule.php?open_override=manage" class="tab" style="background: #f0f9ff; color: #0284c7; border: 1.5px solid #bae6fd; font-weight: 700; margin-left: auto;" title="特定日や期間の時間枠休止・臨時枠オーバーライド管理画面を開きます (テーブル: m2_waku_override)">⏱️ 臨時・期間枠オーバーライド ↗</a>
    </div>

    <div class="filter-bar">
        <label>診療科:</label>
        <select onchange="location.href='?tab=<?= $tab ?>&dept='+this.value+'&doctor=<?= urlencode($doctor) ?>&year=<?= $year ?>&show_past=<?= $show_past?'1':'0' ?>'">
            <option value="PROCT" <?= $dept === 'PROCT' ? 'selected' : '' ?>>🟢 肛門科</option>
            <option value="GASTRO" <?= $dept === 'GASTRO' ? 'selected' : '' ?>>⚪ 胃腸科</option>
        </select>

        <?php if ($tab === 'pattern'): ?>
            <label>医師:</label>
            <select onchange="location.href='?tab=pattern&dept=<?= $dept ?>&doctor='+this.value">
                <option value="">-- 選択 --</option>
                <?php foreach ($doctor_options as $d): ?>
                    <option value="<?= htmlspecialchars($d['doctor_name'], ENT_QUOTES, 'UTF-8') ?>" <?= $doctor === $d['doctor_name'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['doctor_name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ($tab === 'attendance'): ?>
            <div class="year-nav">
                <a href="?tab=attendance&dept=<?= $dept ?>&year=<?= $prev_year ?>&show_past=<?= $show_past?'1':'0' ?>" class="nav-btn arrow">◀</a>
                <span class="year-display"><?= $year ?>年</span>
                <a href="?tab=attendance&dept=<?= $dept ?>&year=<?= $next_year ?>&show_past=<?= $show_past?'1':'0' ?>" class="nav-btn arrow">▶</a>
                <a href="?tab=attendance&dept=<?= $dept ?>&year=<?= date('Y') ?>&show_past=<?= $show_past?'1':'0' ?>" class="nav-btn this-year">📅 今年</a>
                <a href="?tab=attendance&dept=<?= $dept ?>&year=<?= date('Y') + 1 ?>&show_past=<?= $show_past?'1':'0' ?>" class="nav-btn next-year">➡ 来年</a>
                
                <label class="past-toggle">
                    <input type="checkbox" <?= $show_past ? 'checked' : '' ?> 
                           onchange="location.href='?tab=attendance&dept=<?= $dept ?>&year=<?= $year ?>&show_past='+(this.checked?'1':'0')">
                    過去分を表示
                </label>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($tab === 'doctor'): ?>
        <h3>👨‍⚕️ 医師管理</h3>
        <form method="POST" action="action_slot_master.php">
            <input type="hidden" name="action" value="doctor_update">
            <input type="hidden" name="dept" value="<?= $dept ?>">
            <table>
                <thead>
                    <tr>
                        <th style="width:30px;">No</th>
                        <th>医師名</th>
                        <th style="width:100px;">所属科</th>
                        <th style="width:80px;">表示順</th>
                        <th style="width:80px;">状態</th>
                        <th style="width:120px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $doctor_list = getDoctors($pdo, $dept);
                    if (empty($doctor_list)):
                    ?>
                        <tr><td colspan="6" class="empty-msg">医師が登録されていません</td></tr>
                    <?php else: ?>
                        <?php foreach ($doctor_list as $i => $d): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td>
                                    <input type="hidden" name="doctor_id[]" value="<?= $d['id'] ?>">
                                    <input type="text" name="doctor_name[]" value="<?= htmlspecialchars($d['doctor_name'], ENT_QUOTES, 'UTF-8') ?>" class="input-text" style="width:150px;" readonly>
                                </td>
                                <td>
                                    <select name="department_code[]" class="input-text" style="width:100px;">
                                        <option value="PROCT" <?= $d['department_code'] === 'PROCT' ? 'selected' : '' ?>>肛門科</option>
                                        <option value="GASTRO" <?= $d['department_code'] === 'GASTRO' ? 'selected' : '' ?>>胃腸科</option>
                                    </select>
                                </td>
                                <td><input type="number" name="display_order[]" value="<?= $d['display_order'] ?>" class="input-number" style="width:60px;"></td>
                                <td>
                                    <select name="is_active[]" class="input-text" style="width:80px;">
                                        <option value="1" <?= $d['is_active'] ? 'selected' : '' ?>>有効</option>
                                        <option value="0" <?= !$d['is_active'] ? 'selected' : '' ?>>無効</option>
                                    </select>
                                </td>
                                <td>
                                    <a href="action_slot_master.php?action=doctor_delete&id=<?= $d['id'] ?>&dept=<?= $dept ?>" class="btn btn-danger btn-sm" onclick="return confirm('医師を削除しますか？')">削除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="text-right mt-10">
                <button type="submit" class="btn btn-primary">💾 一括保存</button>
            </div>
        </form>

    <?php elseif ($tab === 'waku'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
            <h3 style="margin:0;">🕐 基本時間枠マスタ・世代（期間）管理（<?= $dept_label ?>）</h3>
            <a href="month_schedule.php?open_override=manage" class="btn" style="background:#0284c7; color:#fff; font-size:12px; text-decoration:none; padding:5px 12px; border-radius:6px; font-weight:bold;">
                ⏱️ 特定日・期間の枠休止や臨時枠はこちら ➔
            </a>
        </div>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#166534; line-height:1.5;">
            <strong>💡 時間枠の世代（期間）管理とオーバーライドの使い分け:</strong><br>
            ・<strong>世代（期間）管理（この画面）</strong>: 「2026.12.31までは旧枠、2027.1.1（または2027.2.1）からは新枠」のように、<strong>ある境界日を境に基本時間枠の構成・名称・枠数を恒久的に切り替える場合</strong>に使用します。<br>
            ・<strong>日別・期間別オーバーライド（右上ボタン）</strong>: 世代内の特定日だけ枠を休止（🚫）したり臨時枠を追加する場合は、基本枠を書き換えずオーバーライド機能を使用してください。
        </div>

        <!-- 🗓️ 時間枠世代（期間）切替・一括管理パネル -->
        <div style="background:#f8fafc; border:1.5px solid #cbd5e1; border-radius:10px; padding:14px 18px; margin-bottom:18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span style="font-weight:800; font-size:13px; color:#1e293b;">🗓️ 世代（有効期間）切替:</span>
                    <?php foreach ($waku_generations as $g):
                        $is_sel = ($g['valid_from'] === $selected_gen['valid_from'] && $g['valid_to'] === $selected_gen['valid_to']);
                        $tab_bg = $is_sel ? '#1e40af' : '#ffffff';
                        $tab_col = $is_sel ? '#ffffff' : '#334155';
                        $tab_bdr = $is_sel ? '#1e40af' : '#cbd5e1';
                    ?>
                        <a href="?tab=waku&dept=<?= $dept ?>&gen_from=<?= urlencode($g['valid_from']) ?>&gen_to=<?= urlencode($g['valid_to']) ?>"
                           style="display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px; background:<?= $tab_bg ?>; color:<?= $tab_col ?>; border:1.5px solid <?= $tab_bdr ?>; box-shadow:<?= $is_sel ? '0 2px 6px rgba(30,64,175,0.25)' : 'none' ?>;">
                            <span>第<?= (int)$g['gen_index'] ?>世代: <?= htmlspecialchars($g['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span style="font-size:11px; padding:1px 6px; border-radius:10px; background:<?= $is_sel ? 'rgba(255,255,255,0.22)' : '#f1f5f9' ?>; color:<?= $is_sel ? '#fff' : '#475569' ?>;">
                                有効<?= (int)$g['active_count'] ?>枠 / 計<?= (int)$g['total_count'] ?>枠
                            </span>
                            <?php if (!empty($g['is_current'])): ?>
                                <span style="font-size:10px; padding:1px 6px; border-radius:4px; background:#dcfce7; color:#166534; font-weight:800;">現在適用中</span>
                            <?php elseif ($g['valid_from'] > date('Y-m-d')): ?>
                                <span style="font-size:10px; padding:1px 6px; border-radius:4px; background:#fef3c7; color:#92400e; font-weight:800;">次期世代</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn" style="background:#0f766e; color:#fff; font-size:12px; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer;"
                        onclick="var el=document.getElementById('genSplitBox'); el.style.display=(el.style.display==='none'?'block':'none');">
                    ➕ 新世代を作成（現世代を複製して期間分割）
                </button>
            </div>

            <!-- ➕ 新世代作成（期間分割）折りたたみフォーム -->
            <div id="genSplitBox" style="display:none; background:#f0fdfa; border:1.5px solid #5eead4; border-radius:8px; padding:12px 16px; margin-bottom:12px;">
                <form method="POST" action="action_slot_master.php" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;" onsubmit="return confirm('選択中の世代を複製し、指定した開始日から新しい世代を作成しますか？');">
                    <input type="hidden" name="action" value="waku_generation_split">
                    <input type="hidden" name="dept" value="<?= $dept ?>">
                    <input type="hidden" name="source_valid_from" value="<?= htmlspecialchars($selected_gen['valid_from'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="source_valid_to" value="<?= htmlspecialchars($selected_gen['valid_to'], ENT_QUOTES, 'UTF-8') ?>">
                    <span style="font-weight:bold; color:#0f766e; font-size:12.5px;">🌱 新世代の開始日（切替日）:</span>
                    <input type="date" name="split_date" value="2027-02-01" class="input-date" required style="font-weight:bold;">
                    <span style="font-size:12px; color:#115e59;">
                        ※現在の「<?= htmlspecialchars($selected_gen['label'], ENT_QUOTES, 'UTF-8') ?>」の終了日を前日に自動短縮し、開始日以降の新世代として全枠をコピーします。
                    </span>
                    <button type="submit" class="btn btn-success" style="margin-left:auto;">✨ この日付から新世代を分割作成</button>
                </form>
            </div>

            <!-- 📅 選択中世代の期間対応一括変更バー -->
            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <form method="POST" action="action_slot_master.php" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;">
                    <input type="hidden" name="action" value="waku_period_bulk_update">
                    <input type="hidden" name="dept" value="<?= $dept ?>">
                    <input type="hidden" name="old_valid_from" value="<?= htmlspecialchars($selected_gen['valid_from'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="old_valid_to" value="<?= htmlspecialchars($selected_gen['valid_to'], ENT_QUOTES, 'UTF-8') ?>">

                    <span style="font-weight:bold; font-size:12.5px; color:#1e293b;">
                        📅 表示中（第<?= (int)$selected_gen['gen_index'] ?>世代）の有効期間一括変更:
                    </span>
                    <label style="font-size:12px; color:#475569; display:inline-flex; align-items:center; gap:4px;">
                        開始日:
                        <input type="date" id="bulk_new_vf" name="new_valid_from" value="<?= htmlspecialchars($selected_gen['valid_from'], ENT_QUOTES, 'UTF-8') ?>" class="input-date" required>
                    </label>
                    <button type="button" class="btn btn-sm" style="background:#e2e8f0; color:#334155;" onclick="document.getElementById('bulk_new_vf').value='1900-01-01';" title="開始日を過去全域（1900-01-01）に設定">〜過去全域</button>

                    <span style="font-weight:bold; color:#64748b;">〜</span>

                    <label style="font-size:12px; color:#475569; display:inline-flex; align-items:center; gap:4px;">
                        終了日:
                        <input type="date" id="bulk_new_vt" name="new_valid_to" value="<?= htmlspecialchars($selected_gen['valid_to'], ENT_QUOTES, 'UTF-8') ?>" class="input-date" required>
                    </label>
                    <button type="button" class="btn btn-sm" style="background:#e2e8f0; color:#334155;" onclick="document.getElementById('bulk_new_vt').value='9999-12-31';" title="終了日を無期限（9999.9.9 / DB内部値 9999-12-31）に設定">無期限(9999.9.9)</button>

                    <label style="display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:bold; color:#0f172a; cursor:pointer; background:#eff6ff; padding:4px 8px; border-radius:6px; border:1px solid #bfdbfe;"
                           title="例: 第2世代の開始日を 2027-01-01 から 2027-02-01 に変更すると、第1世代の終了日も 2026-12-31 から 2027-01-31 へ自動で連動更新します">
                        <input type="checkbox" name="sync_adjacent" value="1" checked style="cursor:pointer;">
                        <span>前後の世代の境界日も自動連動する</span>
                    </label>

                    <button type="submit" class="btn btn-primary btn-sm" style="padding:6px 12px; font-size:12px;">📅 期間を一括変更</button>
                </form>

                <?php if (count($waku_generations) > 1): ?>
                    <form method="POST" action="action_slot_master.php" style="margin:0;" onsubmit="return confirm('表示中の世代（<?= htmlspecialchars($selected_gen['label'], ENT_QUOTES, 'UTF-8') ?>）の全時間枠を一括削除しますか？\n※下部の変更履歴からワンクリックで復元可能です。');">
                        <input type="hidden" name="action" value="waku_generation_delete">
                        <input type="hidden" name="dept" value="<?= $dept ?>">
                        <input type="hidden" name="gen_from" value="<?= htmlspecialchars($selected_gen['valid_from'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="gen_to" value="<?= htmlspecialchars($selected_gen['valid_to'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-danger btn-sm" style="padding:5px 10px;">🗑️ この世代を一括削除</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="action_slot_master.php">
            <input type="hidden" name="action" value="waku_update">
            <input type="hidden" name="dept" value="<?= $dept ?>">
            <input type="hidden" name="gen_from" value="<?= htmlspecialchars($selected_gen['valid_from'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="gen_to" value="<?= htmlspecialchars($selected_gen['valid_to'], ENT_QUOTES, 'UTF-8') ?>">
            <table>
                <thead>
                    <tr>
                        <th style="width:30px;">No</th>
                        <th style="width:120px;">グループ</th>
                        <th>枠名（表示名）</th>
                        <th style="width:160px;">適用期間（世代）</th>
                        <th style="width:80px;">表示順</th>
                        <th style="width:80px;">状態</th>
                        <th style="width:100px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($waku_list)): ?>
                        <tr><td colspan="7" class="empty-msg">この世代には時間枠が登録されていません</td></tr>
                    <?php else: ?>
                        <?php foreach ($waku_list as $i => $w): ?>
                            <tr style="<?= !$w['is_active'] ? 'opacity:0.55; background:#f8fafc;' : '' ?>">
                                <td><?= $i+1 ?></td>
                                <td>
                                    <select name="group_code[]" class="input-text" style="width:100px;">
                                        <?php foreach ($slot_groups as $g): ?>
                                            <option value="<?= htmlspecialchars($g['group_code'], ENT_QUOTES, 'UTF-8') ?>" <?= $w['group_code'] === $g['group_code'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($g['group_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="hidden" name="waku_id[]" value="<?= $w['id'] ?>">
                                    <input type="text" name="waku_name[]" value="<?= htmlspecialchars($w['waku_name'], ENT_QUOTES, 'UTF-8') ?>" class="input-text" required>
                                </td>
                                <td>
                                    <span style="display:inline-block; font-size:11.5px; font-weight:700; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; padding:2px 8px; border-radius:6px;">
                                        <?= htmlspecialchars($selected_gen['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><input type="number" name="display_order[]" value="<?= $w['display_order'] ?>" class="input-number" style="width:60px;"></td>
                                <td>
                                    <select name="is_active[]" class="input-text" style="width:80px;">
                                        <option value="1" <?= $w['is_active'] ? 'selected' : '' ?>>有効</option>
                                        <option value="0" <?= !$w['is_active'] ? 'selected' : '' ?>>無効</option>
                                    </select>
                                </td>
                                <td>
                                    <a href="action_slot_master.php?action=waku_delete&id=<?= $w['id'] ?>&dept=<?= $dept ?>&gen_from=<?= urlencode($selected_gen['valid_from']) ?>&gen_to=<?= urlencode($selected_gen['valid_to']) ?>" class="btn btn-danger btn-sm" onclick="return confirm('時間枠「<?= htmlspecialchars($w['waku_name'], ENT_QUOTES, 'UTF-8') ?>」を削除しますか？')">削除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="text-right mt-10">
                <button type="submit" class="btn btn-primary">💾 表示中の世代（<?= htmlspecialchars($selected_gen['label'], ENT_QUOTES, 'UTF-8') ?>）を一括保存</button>
            </div>
        </form>

        <!-- ➕ 表示中の世代への時間枠新規追加フォーム -->
        <form method="POST" action="action_slot_master.php" class="form-inline" style="margin-top:16px; background:#f0f9ff; border-color:#bae6fd;">
            <input type="hidden" name="action" value="waku_add">
            <input type="hidden" name="dept" value="<?= $dept ?>">
            <input type="hidden" name="valid_from" value="<?= htmlspecialchars($selected_gen['valid_from'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="valid_to" value="<?= htmlspecialchars($selected_gen['valid_to'], ENT_QUOTES, 'UTF-8') ?>">
            <label style="color:#0369a1;">➕ 第<?= (int)$selected_gen['gen_index'] ?>世代（<?= htmlspecialchars($selected_gen['label'], ENT_QUOTES, 'UTF-8') ?>）に時間枠を追加:</label>
            <label>グループ:
                <select name="group_code" class="input-text" style="width:110px;" required>
                    <?php foreach ($slot_groups as $g): ?>
                        <option value="<?= htmlspecialchars($g['group_code'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($g['group_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>枠名（表示名）:
                <input type="text" name="waku_name" class="input-text" style="width:180px;" placeholder="例: TCF13:30① / GIF 10:30" required>
            </label>
            <label>表示順:
                <input type="number" name="display_order" class="input-number" style="width:70px;" value="100" required>
            </label>
            <button type="submit" class="btn btn-success">➕ 枠を追加</button>
        </form>

        <!-- ⚙️ 時間枠オーバーライド運用・安全設定パネル -->
        <?php $is_period_allowed = isPeriodOverrideAllowed($pdo); ?>
        <div style="margin-top:28px; background:#f8fafc; border:1.5px solid #cbd5e1; border-radius:10px; padding:18px 22px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h4 style="margin:0; font-size:15px; color:#1e293b; display:flex; align-items:center; gap:8px;">
                    <span>🛡️ 時間枠オーバーライド機能の運用・安全制御</span>
                    <?php if ($is_period_allowed): ?>
                        <span style="font-size:11px; background:#fef3c7; color:#b45309; padding:2px 8px; border-radius:4px; font-weight:bold;">期間指定: 許可中（注意）</span>
                    <?php else: ?>
                        <span style="font-size:11px; background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-weight:bold;">期間指定: 禁止中（安全・推奨）</span>
                    <?php endif; ?>
                </h4>
            </div>
            <p style="font-size:12.5px; color:#475569; line-height:1.6; margin-bottom:14px;">
                現場スタッフの誤操作防止およびシンプル運用の観点から、通常は<strong>単一日のみの操作（🚫で枠なし、⭕で通常復帰、⚙️で枠名称変更）</strong>に限定することを強く推奨します。<br>
                年末年始や年度替わりなど、管理者が期間一括設定（単日バルク展開）を行う場合のみ、一時的に「期間指定オーバーライド機能」を許可してください。
            </p>
            <form method="POST" action="slot_master_maintenance.php?tab=waku&dept=<?= $dept ?>" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 18px;">
                <input type="hidden" name="action" value="update_override_permission">
                <input type="hidden" name="dept" value="<?= $dept ?>">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:13px; font-weight:bold; color:#0f172a;">
                    <input type="checkbox" name="allow_period_override" value="1" <?= $is_period_allowed ? 'checked' : '' ?> style="width:18px; height:18px; cursor:pointer;">
                    <span>期間指定オーバーライド機能の利用を許可する（チェックなし推奨: 単一日のみ利用可能）</span>
                </label>
                <div style="margin-top:12px; text-align:right;">
                    <button type="submit" class="btn" style="background:#3b82f6; color:#ffffff; font-weight:bold; padding:6px 16px; border-radius:6px; cursor:pointer;">設定を保存する</button>
                </div>
            </form>
        </div>

    <?php elseif ($tab === 'pattern'): ?>
        <h3>📊 医師別枠数パターン（<?= $dept_label ?>）</h3>

        <?php if (empty($doctor)): ?>
            <div class="empty-msg">👈 上のフィルターで医師を選択してください</div>
        <?php else: ?>

            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:15px;">
                <div>
                    <strong style="font-size:16px;"><?= htmlspecialchars($doctor, ENT_QUOTES, 'UTF-8') ?></strong>
                    <span style="color:#64748b; font-size:13px;">（<?= $dept_label ?>）</span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <span style="font-size:12px; color:#64748b;">パターン:</span>
                    <?php foreach ($pattern_names as $pn): ?>
                        <span class="status-badge"><?= htmlspecialchars($pn, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="POST" action="slot_master_maintenance.php?tab=pattern&dept=<?= $dept ?>&doctor=<?= urlencode($doctor) ?>">
                <input type="hidden" name="action" value="pattern_update">
                <input type="hidden" name="dept" value="<?= $dept ?>">
                <input type="hidden" name="doctor_name" value="<?= htmlspecialchars($doctor, ENT_QUOTES, 'UTF-8') ?>">

                <table>
                    <thead>
                        <tr>
                            <th style="width:120px;">パターン</th>
                            <th style="width:70px;">曜日</th>
                            <th style="width:70px;">GIF</th>
                            <th style="width:70px;">エコー</th>
                            <th style="width:70px;">TCF</th>
                            <th style="width:70px;">合計</th>
                            <th style="width:100px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pattern_data)): ?>
                            <tr><td colspan="7" class="empty-msg">枠数パターンが登録されていません</td></tr>
                        <?php else: ?>
                            <?php
                            $current_pattern = '';
                            foreach ($pattern_data as $row):
                                if ($row['pattern_name'] !== $current_pattern):
                                    $current_pattern =$row['pattern_name'];
                            ?>
                                <tr style="background:#f8fafc; border-top:2px solid #cbd5e1;">
                                    <td colspan="7" style="font-weight:bold; color:#1e40af;">
                                        📌 パターン: <?= htmlspecialchars($row['pattern_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="pattern_name[]" value="<?= htmlspecialchars($row['pattern_name'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="day_of_week[]" value="<?= $row['day_of_week'] ?>">
                                        <?= htmlspecialchars($row['pattern_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= $week_ja[$row['day_of_week']] ?></td>
                                    <td>
                                        <input type="number" name="gif_slots[]" value="<?= $row['gif_slots'] ?>" class="input-number" style="width:50px;" min="0">
                                    </td>
                                    <td>
                                        <input type="number" name="echo_slots[]" value="<?= $row['echo_slots'] ?>" class="input-number" style="width:50px;" min="0">
                                    </td>
                                    <td>
                                        <input type="number" name="tcf_slots[]" value="<?= $row['tcf_slots'] ?>" class="input-number" style="width:50px;" min="0">
                                    </td>
                                    <td class="pattern-summary <?= ($row['gif_slots'] + $row['echo_slots'] +$row['tcf_slots']) > 0 ? 'total-green' : 'total-red' ?>">
                                        <?= $row['gif_slots'] + $row['echo_slots'] +$row['tcf_slots'] ?>
                                    </td>
                                    <td>
                                        <a href="action_slot_master.php?action=pattern_delete&pattern_name=<?= urlencode($row['pattern_name']) ?>&doctor=<?= urlencode($doctor) ?>&dept=<?=$dept ?>" class="btn btn-danger btn-sm" onclick="return confirm('このパターンを削除しますか？')">削除</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="text-right mt-10">
                    <button type="submit" class="btn btn-primary">💾 一括保存</button>
                </div>
            </form>

        <?php endif; ?>

    <?php elseif ($tab === 'attendance'): ?>
        <h3>📅 出勤例外管理（<?= $dept_label ?> / <?= $year ?>年）</h3>
        <p style="color:#64748b; font-size:12px; margin-top:-5px;">
            💡 デフォルトは全医師・全日出勤です。休診・代診などのイレギュラーだけを設定してください。<br>
            <span style="color:#f59e0b;">✏️ GIF/エコー/TCF の数値は直接編集して「保存」ボタンを押すと更新されます。</span>
        </p>

        <form method="POST" action="action_slot_master.php">
            <input type="hidden" name="action" value="attendance_update">
            <input type="hidden" name="dept" value="<?= $dept ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <table>
                <thead>
                    <tr>
                        <th style="width:30px;">No</th>
                        <th style="width:100px;">日付</th>
                        <th style="width:100px;">医師</th>
                        <th style="width:60px;">GIF</th>
                        <th style="width:60px;">エコー</th>
                        <th style="width:60px;">TCF</th>
                        <th style="width:60px;">合計</th>
                        <th style="width:130px;">パターン</th>
                        <th style="width:150px;">理由</th>
                        <th style="width:140px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendance_list)): ?>
                        <tr><td colspan="10" class="empty-msg">この年の出勤例外はありません</td></tr>
                    <?php else: ?>
                        <?php foreach ($attendance_list as $i =>$a): 
                            $total = ($a['gif_slots'] ?? 0) + ($a['echo_slots'] ?? 0) + ($a['tcf_slots'] ?? 0);
                            $total_class = ($total > 0) ? 'total-green' : 'total-red';
                        ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td><?= htmlspecialchars($a['target_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($a['doctor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <input type="hidden" name="attendance_id[]" value="<?= $a['id'] ?>">
                                    <input type="number" name="gif_slots[]" value="<?= $a['gif_slots'] ?? 0 ?>" class="input-number" style="width:50px;" min="0">
                                </td>
                                <td>
                                    <input type="number" name="echo_slots[]" value="<?= $a['echo_slots'] ?? 0 ?>" class="input-number" style="width:50px;" min="0">
                                </td>
                                <td>
                                    <input type="number" name="tcf_slots[]" value="<?= $a['tcf_slots'] ?? 0 ?>" class="input-number" style="width:50px;" min="0">
                                </td>
                                <td class="pattern-summary <?= $total_class ?>"><?= $total ?></td>
                                <td>
                                    <select name="pattern_name[]" class="input-text" style="width:120px;">
                                        <option value="">-- なし --</option>
                                        <?php 
                                        $patterns = getPatternNames($pdo, $dept,$a['doctor_name']);
                                        foreach ($patterns as$p): 
                                        ?>
                                            <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>" <?= $a['pattern_name'] ===$p ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="reason[]" value="<?= htmlspecialchars($a['reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="input-text" style="width:130px;">
                                </td>
                                <td class="nowrap">
                                    <button type="submit" name="update_row" value="<?= $a['id'] ?>" class="btn btn-primary btn-sm">💾 保存</button>
                                    <a href="action_slot_master.php?action=attendance_delete&id=<?= $a['id'] ?>&dept=<?= $dept ?>&year=<?= $year ?>" class="btn btn-danger btn-sm" onclick="return confirm('例外を削除しますか？')">削除</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="text-right mt-10">
                <button type="submit" class="btn btn-primary">💾 一括保存</button>
            </div>
        </form>

        <!-- 新規例外追加フォーム（復元） -->
        <div class="form-inline">
            <span style="font-weight:bold;">＋ 新規例外追加</span>
            <form method="POST" action="action_slot_master.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="action" value="attendance_add">
                <input type="hidden" name="dept" value="<?= $dept ?>">
                <input type="hidden" name="year" value="<?= $year ?>">
                
                <label style="font-weight: 700; color: #475569;">日付:</label>
                <input type="text" name="target_date" id="target_date" class="onokai-date-picker" required autocomplete="off" placeholder="クリックして選択">
                
                <label>医師:</label>
                <select name="doctor_name" class="input-text" style="width:120px;" required>
                    <option value="">-- 選択 --</option>
                    <?php foreach ($doctor_options as$d): ?>
                        <option value="<?= htmlspecialchars($d['doctor_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($d['doctor_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                
                <label>パターン:</label>
                <select name="pattern_name" class="input-text" style="width:130px;">
                    <option value="">-- なし --</option>
                </select>
                
                <label>GIF:</label>
                <input type="number" name="gif_slots" class="input-number" style="width:50px;" value="0" min="0">
                <label>エコー:</label>
                <input type="number" name="echo_slots" class="input-number" style="width:50px;" value="0" min="0">
                <label>TCF:</label>
                <input type="number" name="tcf_slots" class="input-number" style="width:50px;" value="0" min="0">
                <span style="font-weight:bold; color:#1e40af;">合計: <span id="total_slots_display">0</span></span>
                
                <label>理由:</label>
                <input type="text" name="reason" placeholder="例：学会参加" class="input-text" style="width:120px;">
                
                <button type="submit" class="btn btn-success">➕ 追加</button>
            </form>
        </div>

    <?php endif; ?>

    <!-- 📜 検査枠マスタ 変更履歴＆ワンクリック復元パネル -->
    <div class="audit-section">
        <div class="audit-header">
            <div>
                <strong style="font-size: 14px; color: #1e293b;">📜 検査枠マスタ変更履歴・復元（直近20件）</strong>
                <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">
                    直近の枠マスタ（医師・時間枠・枠数パターン・出勤例外）の変更履歴を記録しています。誤って編集・削除した場合でも「↩️ 元に戻す」で直前の状態に復元できます。
                </p>
            </div>
            <?php if (!empty($recent_slot_logs)): ?>
                <form action="action_slot_master.php" method="POST" onsubmit="return confirm('本当に検査枠マスタの変更履歴を一括削除しますか？\n※マスタ本体のデータは削除されません。');">
                    <input type="hidden" name="action" value="clear_slot_audit_logs">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="dept" value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <input type="hidden" name="doctor" value="<?= htmlspecialchars($doctor, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-secondary" style="padding: 4px 10px; font-size: 11px; background: #64748b; color: #fff;">🗑️ 履歴を一括削除</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($recent_slot_logs)): ?>
            <p style="color: #94a3b8; font-size: 12px; text-align: center; padding: 15px; background: #f8fafc; border-radius: 4px; border: 1px solid #e2e8f0;">
                記録された変更履歴はありません。
            </p>
        <?php else: ?>
            <table class="audit-table">
                <thead>
                    <tr>
                        <th style="width: 130px;">日時</th>
                        <th style="width: 100px;">対象マスタ</th>
                        <th style="width: 60px; text-align: center;">操作</th>
                        <th>変更内容（差分サマリ）</th>
                        <th style="width: 100px;">端末IP</th>
                        <th style="width: 80px; text-align: center;">復元操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_slot_logs as $log): ?>
                        <?php 
                            $badge_class = 'badge-insert';
                            $action_label = '追加';
                            if ($log['action_type'] === 'update') {
                                $badge_class = 'badge-update';
                                $action_label = '更新';
                            } elseif ($log['action_type'] === 'delete') {
                                $badge_class = 'badge-delete';
                                $action_label = '削除';
                            } elseif ($log['action_type'] === 'restore') {
                                $badge_class = 'badge-restore';
                                $action_label = '復元';
                            }

                            $type_label = '枠マスタ';
                            if ($log['master_type'] === 'doctor') {
                                $type_label = '👨‍⚕️ 医師';
                            } elseif ($log['master_type'] === 'waku') {
                                $type_label = '🕐 時間枠';
                            } elseif ($log['master_type'] === 'pattern') {
                                $type_label = '📊 パターン';
                            } elseif ($log['master_type'] === 'attendance') {
                                $type_label = '📅 出勤例外';
                            }
                        ?>
                        <tr>
                            <td style="color: #64748b;"><?= htmlspecialchars(substr($log['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><strong><?= $type_label ?></strong></td>
                            <td style="text-align: center;">
                                <span class="badge-action <?= $badge_class ?>"><?= $action_label ?></span>
                            </td>
                            <td><?= htmlspecialchars($log['diff_summary'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="color: #64748b; font-size: 11px;"><?= htmlspecialchars($log['client_ip'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="text-align: center;">
                                <?php if (!empty($log['is_undone'])): ?>
                                    <span class="badge-action badge-undone">復元済</span>
                                <?php elseif ($log['action_type'] === 'restore'): ?>
                                    <span style="color: #94a3b8; font-size: 11px;">-</span>
                                <?php else: ?>
                                    <a href="action_slot_master.php?action=undo_slot_master&log_id=<?= $log['log_id'] ?>&tab=<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>&dept=<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>&year=<?= $year ?>&doctor=<?= urlencode($doctor) ?>" 
                                       class="btn btn-secondary" 
                                       onclick="return confirm('この操作を取り消して直前の状態に戻しますか？\n\n対象: <?= htmlspecialchars(addslashes($log['diff_summary']), ENT_QUOTES, 'UTF-8') ?>');" 
                                       style="padding: 2px 6px; font-size: 11px; background: #475569; color: #fff; text-decoration: none; border-radius: 3px;">
                                        ↩️ 戻す
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>


<script>
function initSlotMasterDatePicker() {
    if (window.OnokaiDatePicker) {
        // 🌟 小野会日付ピッカー（角丸・西暦/和暦切替・特大Flatpickr）を出勤例外フォームにバインド
        OnokaiDatePicker.bindControl('#target_date');
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSlotMasterDatePicker);
} else {
    initSlotMasterDatePicker();
}
</script>
<script src="assets/js/env_detector.js"></script>
</body>
</html>