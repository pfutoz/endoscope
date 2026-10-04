<?php
/**
 * 生成日時: 2026-07-15 23:15:00
 * 患者全体データ検索システム（左右分割マスター・ディテールレイアウト対応版）
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

// 1. データベース接続設定
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

$keyword      = isset($_GET['keyword'])      ? trim($_GET['keyword'])      : '';
$target_pid   = isset($_GET['patient_id'])   ? trim($_GET['patient_id'])   : '';
$search_notes = isset($_GET['search_notes']) ? (int)$_GET['search_notes']  : 0;

// デフォルトは「未来のみ (future_only)」。選択肢: 'future_only' | 'all'
$scope = isset($_GET['scope']) ? trim($_GET['scope']) : 'future_only';
if ($scope !== 'future_only' && $scope !== 'all') {
    $scope = 'future_only';
}

// 右ペインのタイムライン選択（デフォルトは 'future'）
$timeline = isset($_GET['timeline']) ? trim($_GET['timeline']) : 'future';
if ($timeline !== 'future' && $timeline !== 'past') {
    $timeline = 'future';
}

$today_str = date('Y-m-d');

// 2. 全体検索結果を取得（左ペイン用：キーワードにヒットする患者一覧）
$search_results = [];
if (!empty($keyword)) {
    $kw_clean = mb_convert_kana($keyword, 'asKV', 'UTF-8');
    $kw_clean = preg_replace('/\s+/', ' ', $kw_clean);
    $kw_clean = trim($kw_clean);
    
    preg_match_all('/\([^)]+\)|[^\s()]+/u', $kw_clean, $matches);
    
    $and_clauses = [];
    $params = [];
    $param_index = 0;
    
    if (!empty($matches[0])) {
        foreach ($matches[0] as $token) {
            $token = trim($token);
            if ($token === '') continue;
            
            if (strpos($token, '(') === 0 && substr($token, -1) === ')') {
                $or_content = trim(substr($token, 1, -1));
                $or_words = array_filter(explode(' ', $or_content), function($val) { return $val !== ''; });
                
                if (!empty($or_words)) {
                    $or_sub_clauses = [];
                    foreach ($or_words as $word) {
                        $p_name = ":kw_" . $param_index++;
                        if ($search_notes === 1) {
                            $or_sub_clauses[] = "(CAST(patient_id AS TEXT) ILIKE $p_name OR patient_name ILIKE $p_name OR remarks ILIKE $p_name)";
                        } else {
                            $or_sub_clauses[] = "(CAST(patient_id AS TEXT) ILIKE $p_name OR patient_name ILIKE $p_name)";
                        }
                        $params[$p_name] = '%' . $word . '%';
                    }
                    $and_clauses[] = '(' . implode(' OR ', $or_sub_clauses) . ')';
                }
            } else {
                $p_name = ":kw_" . $param_index++;
                if ($search_notes === 1) {
                    $and_clauses[] = "(CAST(patient_id AS TEXT) ILIKE $p_name OR patient_name ILIKE $p_name OR remarks ILIKE $p_name)";
                } else {
                    $and_clauses[] = "(CAST(patient_id AS TEXT) ILIKE $p_name OR patient_name ILIKE $p_name)";
                }
                $params[$p_name] = '%' . $token . '%';
            }
        }
    }
    
    // スコープが 'future_only' の場合は、本日以降の検査予定がある患者に限定して検索
    $scope_condition = "";
    if ($scope === 'future_only') {
        $scope_condition = " AND exam_date >= :scope_today ";
        $params[':scope_today'] = $today_str;
    }

    $search_sql = "SELECT DISTINCT ON (COALESCE(patient_id, ''), patient_name) patient_id, patient_name, department_code, exam_date, doctor_name
                   FROM t_endoscopy_schedule 
                   WHERE is_canceled = FALSE" . $scope_condition;
                   
    if (!empty($and_clauses)) {
        $search_sql .= " AND " . implode(' AND ', $and_clauses);
    }
    
    $search_sql .= " ORDER BY COALESCE(patient_id, ''), patient_name, exam_date DESC";
    
    $search_stmt = $pdo->prepare($search_sql);
    $search_stmt->execute($params);
    $search_results = $search_stmt->fetchAll();
}

// 3. 選択された個別患者の履歴データ＆氏名を取得（右ペイン用）
$patient_history = [];
$patient_display_name = '';
if (!empty($target_pid)) {
    if ($timeline === 'future') {
        $hist_sql = "SELECT * FROM t_endoscopy_schedule 
                     WHERE patient_id = :pid 
                       AND is_canceled = FALSE 
                       AND exam_date >= :today 
                     ORDER BY exam_date ASC, scheduled_time ASC";
    } else {
        $hist_sql = "SELECT * FROM t_endoscopy_schedule 
                     WHERE patient_id = :pid 
                       AND is_canceled = FALSE 
                       AND exam_date < :today 
                     ORDER BY exam_date DESC, scheduled_time DESC";
    }

    $hist_stmt = $pdo->prepare($hist_sql);
    $hist_stmt->execute([':pid' => $target_pid, ':today' => $today_str]);
    $patient_history = $hist_stmt->fetchAll();

    $name_sql = "SELECT patient_name FROM t_endoscopy_schedule WHERE patient_id = :pid LIMIT 1";
    $name_stmt = $pdo->prepare($name_sql);
    $name_stmt->execute([':pid' => $target_pid]);
    $n_row = $name_stmt->fetch();
    $patient_display_name = $n_row ? ($n_row['patient_name'] ?? '') : '';
}

// もし患者IDが未選択で、検索結果に患者が1件以上いる場合、最初の患者を自動選択状態にする（利便性向上）
if (empty($target_pid) && !empty($search_results)) {
    // 自動選択はあえてせず、ユーザーに選ばせる場合はここをスキップ（今回はリストから選ぶ挙動にします）
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔎 予約済患者検索 | 医療法人小野会</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <style>
        body { font-family: var(--font-sans); margin: 15px; background-color: var(--color-bg); color: var(--color-text-main); font-size: 13px; }
        .container { max-width: 1400px; margin: 0 auto; background: var(--color-surface); border: 1px solid var(--color-border); padding: 20px 24px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); }
        
        .home-icon-btn {
            position: fixed; top: 15px; right: 15px; z-index: 100; background-color: #2c3e50; color: white;
            border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            text-decoration: none;
        }
        .home-icon-btn:hover { background-color: #34495e; transform: scale(1.05); }

        .search-box { background: #f8f9fa; padding: 12px 15px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; }
        .search-box form { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        
        .input-wrapper { display: flex; align-items: center; position: relative; flex: 1; min-width: 280px; }
        .input-wrapper input[type="text"] { padding: 8px 35px 8px 12px; font-size: 14px; border: 1px solid #ccc; border-radius: 3px; width: 100%; box-sizing: border-box; }
        
        .btn-clear { position: absolute; right: 10px; background: none; border: none; color: #999; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; padding: 0; text-decoration: none; }
        .btn-clear:hover { background-color: #e2e8f0; color: #333; }

        .search-box button[type="submit"] { padding: 8px 18px; font-size: 14px; font-weight: bold; background: #34495e; color: #fff; border: none; border-radius: 3px; cursor: pointer; }
        .search-box button[type="submit"]:hover { background: #2c3e50; }

        .search-options { display: flex; gap: 20px; align-items: center; width: 100%; margin-top: 8px; font-size: 13px; font-weight: bold; border-top: 1px dashed #ddd; padding-top: 8px; }
        .checkbox-label, .radio-label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; user-select: none; font-weight: normal; }

        /* 💡 左右分割レイアウト (Master-Detail Pane) */
        .workspace { display: flex; gap: 20px; align-items: stretch; margin-top: 15px; }
        .pane-left { flex: 1; border: 1px solid #cbd5e1; border-radius: 6px; background: #fafafa; padding: 12px; max-height: 72vh; overflow-y: auto; }
        .pane-right { flex: 1.4; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; padding: 12px; max-height: 72vh; overflow-y: auto; }

        .data-table { width: 100%; border-collapse: collapse; margin-top: 5px; font-size: 13px; background: #fff; }
        .data-table th, .data-table td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
        .data-table th { background: #e6f2ff; position: sticky; top: 0; z-index: 2; }
        
        .clickable-row { cursor: pointer; }
        .clickable-row:hover { background-color: #f1f8ff; }
        .clickable-row.selected { background-color: #e2e8f0 !important; font-weight: bold; border-left: 4px solid #3b82f6; }

        .timeline-bar { display: flex; gap: 15px; align-items: center; background: #f8fafc; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 4px; margin-bottom: 12px; font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>

<a href="index.php" class="home-icon-btn" title="システムメニューに戻る">🏠</a>

<div class="container">
    <h2>🔍 患者全体データ検索システム</h2>
    
    <!-- 検索フォームエリア -->
    <div class="search-box">
        <form method="GET" action="search_patient.php">
            <input type="hidden" name="patient_id" value="<?=htmlspecialchars($target_pid, ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="timeline" value="<?=htmlspecialchars($timeline, ENT_QUOTES, 'UTF-8')?>">
            <input type="hidden" name="scope" value="<?=htmlspecialchars($scope, ENT_QUOTES, 'UTF-8')?>">
            
            <label for="keyword" style="font-weight: bold; font-size: 13px;">キーワード：</label>
            <div class="input-wrapper">
                <input type="text" id="keyword" name="keyword" value="<?=htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8')?>" placeholder="例: 山田 （ポリープ 生検） ※カッコ内はOR検索">
                <?php if (!empty($keyword) || $search_notes === 1 || !empty($target_pid) || $scope !== 'future_only'): ?>
                    <a href="search_patient.php" class="btn-clear" title="検索条件をすべてクリアしてリロード">✕</a>
                <?php endif; ?>
            </div>
            
            <button type="submit">全体検索</button>

            <div class="search-options">
                <span style="color: #555;">検索対象スコープ：</span>
                <label class="radio-label"><input type="radio" name="scope" value="future_only" <?=$scope==='future_only'?'checked':''?>> 📅 未来の予約のみ (デフォルト)</label>
                <label class="radio-label"><input type="radio" name="scope" value="all" <?=$scope==='all'?'checked':''?>> 📂 すべての履歴（過去含む）</label>
                
                <label class="checkbox-label" style="margin-left: auto;">
                    <input type="checkbox" name="search_notes" value="1" <?=$search_notes===1?'checked':''?>>
                    備考欄も検索対象に含める
                </label>
            </div>
        </form>
    </div>

    <!-- 💡 左右分割のマスター・ディテールワークスペース -->
    <div class="workspace">
        <!-- 【左ペイン】検索結果の患者一覧 -->
        <div class="pane-left">
            <h3 style="margin-top: 0; font-size: 14px; color: #2c3e50; border-bottom: 2px solid #cbd5e1; padding-bottom: 6px;">
                📋 検索結果患者一覧 
                <?php if (!empty($keyword)): ?><span style="font-weight:normal; font-size:12px;">(<?=count($search_results)?>件)</span><?php endif; ?>
            </h3>

            <?php if (empty($keyword)): ?>
                <p style="color: #666; font-size: 13px; text-align: center; margin-top: 30px;">上のフォームにキーワードを入力して検索してください。</p>
            <?php elseif (empty($search_results)): ?>
                <p style="color: #666; font-size: 13px; text-align: center; margin-top: 30px;">一致する患者データが見つかりませんでした。</p>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 75px;">ID</th>
                            <th>患者名</th>
                            <th style="width: 80px;">直近診療科</th>
                            <th style="width: 95px;">直近検査日</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($search_results as $p): 
                            $safe_pid = $p['patient_id'] ?? '';
                            $safe_pname = $p['patient_name'] ?? '';
                            $is_selected = ($safe_pid === $target_pid);
                            
                            $row_url = sprintf(
                                'search_patient.php?keyword=%s&patient_id=%s&search_notes=%d&scope=%s&timeline=%s',
                                urlencode($keyword),
                                urlencode($safe_pid),
                                $search_notes,
                                $scope,
                                $timeline
                            );
                        ?>
                            <tr class="clickable-row <?=$is_selected?'selected':''?>" onclick="location.href='<?=$row_url?>'">
                                <td style="font-weight: bold;"><?=htmlspecialchars($safe_pid, ENT_QUOTES, 'UTF-8')?></td>
                                <td><?=htmlspecialchars($safe_pname, ENT_QUOTES, 'UTF-8')?></td>
                                <td><?=htmlspecialchars($p['department_code'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                                <td><?=htmlspecialchars($p['exam_date'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- 【右ペイン】選択された患者の検査詳細・履歴 -->
        <div class="pane-right">
            <h3 style="margin-top: 0; font-size: 14px; color: #2c3e50; border-bottom: 2px solid #cbd5e1; padding-bottom: 6px;">
                📊 患者検査詳細ビュー
            </h3>

            <?php if (empty($target_pid)): ?>
                <div style="color: #888; font-size: 13px; text-align: center; margin-top: 60px; padding: 20px; border: 2px dashed #e2e8f0; border-radius: 6px;">
                    👈 左側のリストから患者をクリックすると、ここに詳細な検査スケジュール・履歴が表示されます。
                </div>
            <?php else: ?>
                <div style="background: #eef7ff; padding: 10px 14px; border: 1px solid #b4c6e7; border-radius: 4px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="font-size: 12px; color: #555; display: block;">選択中の患者</span>
                        <strong style="font-size: 16px; color: #1e3a8a;"><?=htmlspecialchars($patient_display_name, ENT_QUOTES, 'UTF-8')?></strong> 
                        <span style="font-size: 13px; color: #333; margin-left: 6px;">(ID: <?=htmlspecialchars($target_pid, ENT_QUOTES, 'UTF-8')?>)</span>
                    </div>
                </div>

                <!-- タイムライン切替バー -->
                <form method="GET" action="search_patient.php" id="timelineForm">
                    <input type="hidden" name="keyword" value="<?=htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="patient_id" value="<?=htmlspecialchars($target_pid, ENT_QUOTES, 'UTF-8')?>">
                    <input type="hidden" name="search_notes" value="<?=$search_notes?>">
                    <input type="hidden" name="scope" value="<?=htmlspecialchars($scope, ENT_QUOTES, 'UTF-8')?>">
                    
                    <div class="timeline-bar">
                        <span>表示切替：</span>
                        <label class="radio-label"><input type="radio" name="timeline" value="future" <?=$timeline==='future'?'checked':''?> onchange="this.form.submit()"> 未来の検査予定（昇順）</label>
                        <label class="radio-label"><input type="radio" name="timeline" value="past" <?=$timeline==='past'?'checked':''?> onchange="this.form.submit()"> 過去の検査履歴（降順）</label>
                    </div>
                </form>

                <?php if (empty($patient_history)): ?>
                    <p style="color: #666; font-size: 13px; text-align: center; padding: 30px;"><?= $timeline === 'future' ? 'これ以降の未来の検査予定はありません。' : '過去の検査履歴はありません。' ?></p>
                <?php else: ?>
                    <p style="font-size: 11px; color: #555; margin-bottom: 6px;">💡 日付をクリックすると、その日のカレンダービュー（`month_schedule.php`）へジャンプします。</p>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 100px;">検査日</th>
                                <th style="width: 50px;">科</th>
                                <th style="width: 50px;">枠番</th>
                                <th style="width: 70px;">予定時刻</th>
                                <th style="width: 60px;">種別</th>
                                <th style="width: 80px;">担当医</th>
                                <th>備考</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patient_history as $row): 
                                $exam_date = $row['exam_date'];
                                $year  = date('Y', strtotime($exam_date));
                                $month = date('n', strtotime($exam_date));
                                $dept  = $row['department_code'] ?? '';
                                $jump_url = "month_schedule.php?dept={$dept}&year={$year}&month={$month}&date={$exam_date}";
                            ?>
                                <tr class="clickable-row" onclick="location.href='<?=$jump_url?>'" title="クリックしてカレンダーのこの日に移動">
                                    <td><a href="<?=$jump_url?>" style="color:#2980b9; font-weight:bold; text-decoration:none;"><?=htmlspecialchars($exam_date, ENT_QUOTES, 'UTF-8')?> 🔗</a></td>
                                    <td><?=htmlspecialchars($dept, ENT_QUOTES, 'UTF-8')?></td>
                                    <td><?=htmlspecialchars($row['waku_no'] ?? '---', ENT_QUOTES, 'UTF-8')?></td>
                                    <td><?=htmlspecialchars($row['scheduled_time'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                                    <td><?=htmlspecialchars($row['exam_type'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                                    <td><?=htmlspecialchars($row['doctor_name'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                                    <td><span style="font-size:11px; color:#555;"><?=htmlspecialchars($row['remarks'] ?? '', ENT_QUOTES, 'UTF-8')?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>