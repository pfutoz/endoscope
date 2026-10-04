<?php
/**
 * lib/patient_helper.php - 患者ボックス・検査バッジ・未割当バッジ描画関数
 * 生成日時: 2026-09-29 15:06:00 JST
 * 修正履歴:
 *   - 2026-08-28: ステップ2（未割当バッジ生成関数 renderUnassignedBadgeHTML の一元化）
 *   - 2026-09-07: 備考欄のプレーン/リッチテキスト両対応描画関数 renderRemarksHtml を追加
 *   - 2026-09-29: タイル表現カスタマイズ（枠なしタイルの白背景・医師検査非表示化、削除データの警告赤背景・目立つ表示化、検査バッジのスレートグレー統一、パネル部の検査別カラフルパレット適用）
 */

require_once __DIR__ . '/date_helper.php';

/**
 * 検査種別アイコンを返す（共通）
 * GIF: 🔵, ECHO/エコー: 🟠, TCF: 🟢
 */
function getExamIcon($exam_type) {
    $map = [
        'GIF'    => '🔵',
        'ECHO'   => '🟠',
        'エコー' => '🟠',
        'TCF'    => '🟢',
        'ポリペク' => '🟣',
        'W'      => '🟢'
    ];
    return $map[$exam_type] ?? '●';
}

/**
 * 未割当（時間枠未割当 / 医師未割当）バッジHTMLを生成（共通）
 * @param array $data 検査種別ごとの件数連想配列 ['GIF'=>1, 'TCF'=>2] または 予約行の配列
 * @return string HTML文字列
 */
function renderUnassignedBadgeHTML($data) {
    if (empty($data) || !is_array($data)) return '';
    
    $counts = [];
    
    // 形式判定（予約行配列か、件数集計済み連想配列か）
    if (isset($data[0]) && is_array($data[0])) {
        // 予約レコードの配列の場合
        foreach ($data as $row) {
            $type = $row['exam_type'] ?? 'その他';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
    } else {
        // ['GIF' => 1, 'TCF' => 2] のような件数配列の場合
        foreach ($data as $type => $cnt) {
            $cnt_int = (int)$cnt;
            if ($cnt_int > 0) {
                $counts[$type] = $cnt_int;
            }
        }
    }
    
    $parts = [];
    foreach ($counts as $type => $cnt) {
        if ($cnt > 0) {
            $icon = getExamIcon($type);
            $parts[] = $icon . $cnt;
        }
    }
    
    if (empty($parts)) return '';
    
    return '<div class="unassigned-badge" style="display:inline-block; background-color:#fef2f2; border:1px solid #fca5a5; border-radius:4px; padding:1px 6px; font-size:9px; color:#991b1b; margin-top:2px;">⚠️ 未割当: ' . implode(' ', $parts) . '</div>';
}

/**
 * 検査種別バッジHTMLを返す
 * 全検査（GIF, TCF, ポリペク, W, US等）を上品なスレートグレー（#475569、白文字）で統一
 * ※マトリクスのコンパクト枠崩れ防止のため、エコー/ECHO は「US」と表示
 */
function getExamBadgeHTML($exam_type) {
    if (empty($exam_type)) return '';
    $display_label = (string)$exam_type;

    if ($exam_type === 'エコー' || $exam_type === 'ECHO') {
        $display_label = 'US';
    }
    
    // スレートグレー（#475569、白文字）で統一
    $style = "padding:1px 5px; border-radius:10px; font-size:9px; background-color:#475569; color:#ffffff; font-weight:bold; white-space:nowrap; display:inline-block; line-height:1.2;";
    return "<span class='exam-badge' style='{$style}'>" . htmlspecialchars($display_label, ENT_QUOTES, 'UTF-8') . "</span>";
}

function renderPatientBox($data, $date_str, $prefix = "", $simple_mode = false) {
    $is_canceled = isset($data['is_canceled']) && ($data['is_canceled'] === true || $data['is_canceled'] === 1 || $data['is_canceled'] === 't');
    $pname_raw = (string)($data['patient_name'] ?? '');
    $is_waku_nashi = (strpos($pname_raw, '枠なし') !== false);
    $is_temporary = (!$is_waku_nashi && strpos($pname_raw, '※仮予約') !== false);
    $booking_status = $data['booking_status'] ?? 'normal';
    
    $box_class = 'patient-box';
    $custom_style = '';
    
    if ($is_waku_nashi) {
        // 「枠なし」予約：完全な白背景、控えめなグレー枠線
        $box_class .= ' is-waku-nashi-box';
        $custom_style = 'background-color: #ffffff !important; border: 1px solid #e2e8f0 !important; color: #64748b;';
    } elseif ($is_canceled) {
        // 削除データ：バックが赤く目立つように（サーモンコーラル #fed4c8、赤枠線 #f87171、適度な半透明 opacity: 0.85）
        $box_class .= ' is-canceled-box';
        $custom_style = 'background-color: #fed4c8 !important; border: 1px solid #f87171 !important; opacity: 0.85;';
    } elseif ($is_temporary) {
        $box_class .= ' is-temporary-box';
        $custom_style = 'background-color: #fff2cc !important; border: 1px solid #ffcc66 !important; color: #b25900;';
    } elseif ($booking_status === 'duplicate') {
        $box_class .= ' duplicate';
        $custom_style = 'background-color: #fef2f2 !important; border: 2px solid #dc2626 !important;';
    } elseif ($booking_status === 'out_of_slot') {
        $box_class .= ' out-of-slot';
        $custom_style = 'background-color: #ffedd5 !important; border: 2px solid #f59e0b !important;';
    } else {
        // 通常の有効予約：検査種別ごとのカラフルで分かりやすい配色パレット
        $color_palette = [
            'GIF'    => ['bg' => '#dbeafe', 'border' => '#93c5fd'], // 爽やかな青系
            'TCF'    => ['bg' => '#dcfce7', 'border' => '#86efac'], // 鮮やかな緑系
            'ポリペク' => ['bg' => '#f3e8ff', 'border' => '#d8b4fe'], // 明るい紫系
            'W'      => ['bg' => '#fef3c7', 'border' => '#fde047'], // 視認性の高いオレンジ/琥珀系
            'US'     => ['bg' => '#cffafe', 'border' => '#67e8f9'], // 水色/シアン系
            'エコー'  => ['bg' => '#cffafe', 'border' => '#67e8f9'],
            'ECHO'   => ['bg' => '#cffafe', 'border' => '#67e8f9'],
            '浣腸CF'  => ['bg' => '#e0e7ff', 'border' => '#a5b4fc'], // インディゴ系
        ];

        $exam_type = (string)($data['exam_type'] ?? '');
        if (isset($color_palette[$exam_type])) {
            $bg = $color_palette[$exam_type]['bg'];
            $border = $color_palette[$exam_type]['border'];
            $custom_style = "background-color: {$bg} !important; border: 1px solid {$border} !important;";
        } else {
            $custom_style = "background-color: #f1f5f9 !important; border: 1px solid #cbd5e1 !important;";
        }
    }
    
    $draggable = ($is_canceled || $is_waku_nashi) ? 'false' : 'true';
    $tooltip = "【検査】" . ($data['exam_type'] ?? '') . "\n【医師】" . ($data['doctor_name'] ?? '') . "\n【ヨミ】" . ($data['patient_kana'] ?? '未登録') . "\n【備考】\n" . ($data['remarks'] ?? '');
    
    global $GLOBAL_MEDICAL_ALERTS_MAP;
    $sid = (int)($data['schedule_id'] ?? 0);
    $alert_icons_html = '';
    if ($sid > 0 && !empty($GLOBAL_MEDICAL_ALERTS_MAP[$sid]) && function_exists('renderMedicalAlertBadgesHTML')) {
        $a = $GLOBAL_MEDICAL_ALERTS_MAP[$sid];
        $alert_icons_html = renderMedicalAlertBadgesHTML($a, 'icon_only');
        $alert_descs = [];
        if (!empty($a['has_antithrombotic'])) $alert_descs[] = '抗血栓薬(' . (!empty($a['antithrombotic_verified']) ? '休薬確認済' : '要休薬確認!') . ')';
        if (!empty($a['has_diabetes'])) $alert_descs[] = '糖尿病薬(絶食・低血糖注意)';
        if (!empty($a['has_psychotropic'])) $alert_descs[] = '向精神薬・睡眠薬(鎮静注意)';
        if (!empty($a['has_nsaids'])) $alert_descs[] = 'NSAIDs鎮痛薬(潰瘍・出血注意)';
        if (!empty($a['has_allergy'])) $alert_descs[] = '局麻アレルギー注意';
        if (!empty($a['has_infection'])) $alert_descs[] = '感染症注意';
        if (!empty($a['has_cold_fever'])) $alert_descs[] = '風邪症状・発熱';
        if (!empty($alert_descs)) {
            $tooltip .= "\n【⚠️医療アラート】 " . implode(', ', $alert_descs);
        }
    }

    $age = calculateAge($data['birthday'] ?? null, $date_str);
    $age_badge = getAgeBadgeHTML($age);
    $wareki_short = convertToShortWareki($data['birthday'] ?? '');
    $exam_badge = getExamBadgeHTML($data['exam_type'] ?? '');
    $doctor_icon = getDoctorIcon($data['doctor_name'] ?? '');
    
    echo "<div class='{$box_class}' style='{$custom_style}' draggable='{$draggable}' title='" . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . "' data-schedule-id='" . htmlspecialchars((string)($data['schedule_id'] ?? ''), ENT_QUOTES, 'UTF-8') . "' data-json='" . htmlspecialchars(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . "' ondragstart='dragStart(this, event)'>";
    
    if ($booking_status === 'duplicate') { echo "<span class='dup-badge' style='display:inline-block; background:#dc2626; color:#fff; font-size:7px; font-weight:bold; padding:0 4px; border-radius:2px; margin-right:2px;'>⛔重複</span>"; }
    elseif ($booking_status === 'out_of_slot') { echo "<span class='oos-badge' style='display:inline-block; background:#f59e0b; color:#fff; font-size:7px; font-weight:bold; padding:0 4px; border-radius:2px; margin-right:2px;'>⚠️枠外</span>"; }
    
    // ==========================================
    // 1. 「枠なし」予約の場合（医師・検査名非表示、白タイル）
    // ==========================================
    if ($is_waku_nashi) {
        if ($simple_mode) {
            echo "<div class='waku-nashi-row' style='text-align:center; padding: 2px; font-weight:bold; color:#64748b; font-size:10px; cursor:pointer;' onclick='openEditModalWithCtrlCheck(this, event)'>";
            echo htmlspecialchars($pname_raw, ENT_QUOTES, 'UTF-8');
            echo "</div>";
        } else {
            ?>
            <div class='pid-row' style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px dotted rgba(0,0,0,0.1); padding-bottom:1px; margin-bottom:2px;'>
                <span style='font-weight:700; color:#94a3b8; font-size:8px;'>ID:</span>
            </div>
            <div class='waku-nashi-row' style='text-align:center; padding: 6px 0; font-weight:bold; color:#64748b; font-size:11px; cursor:pointer;' onclick='openEditModalWithCtrlCheck(this, event)'>
                <?= htmlspecialchars($pname_raw, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php
        }
    // ==========================================
    // 2. 通常の患者予約・削除データの場合
    // ==========================================
    } elseif ($simple_mode) {
        echo "<div class='pid-row' style='display:flex; justify-content:space-between; align-items:center;'>";
        echo "  <span style='font-weight:800; color:#1e293b; font-size:9px;'>ID:" . htmlspecialchars((string)($data['patient_id'] ?? ''), ENT_QUOTES, 'UTF-8') . "</span>";
        echo "  <span class='pname' style='font-weight:bold; font-size:10px; cursor:pointer; flex:1; margin:0 4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' onclick='openEditModalWithCtrlCheck(this, event)'>";
        echo "    <span class='real-pname'>" . htmlspecialchars($pname_raw, ENT_QUOTES, 'UTF-8') . "</span>" . $alert_icons_html;
        echo "    <span class='masked-pname' style='display:none; color:#777; font-style:italic;'>●●●●</span>";
        echo "  </span>";
        echo "  " . $exam_badge;
        echo "</div>";
    } else {
        ?>
        <div class='pid-row' style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px dotted rgba(0,0,0,0.15); padding-bottom:2px; margin-bottom:3px;'>
            <span style='font-weight:800; color:#1e293b; font-size:9px;'>ID：<?= htmlspecialchars((string)($data['patient_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= $is_canceled ? ' <span style="color:#dc2626; font-weight:bold;">[削除]</span>' : '' ?></span>
            <?= $age_badge ?>
        </div>
        
        <div class='pname-row' style='font-weight:bold; font-size:10px; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' onclick='openEditModalWithCtrlCheck(this, event)'>
            <?= $prefix ?>
            <span class='real-pname'><?= htmlspecialchars($pname_raw, ENT_QUOTES, 'UTF-8') ?></span><?= $alert_icons_html ?>
            <span class='masked-pname' style='display:none; color:#777; font-style:italic;'>●●●●</span>
        </div>
        
        <div class='pinfo-row' style='display:flex; justify-content:space-between; align-items:center; margin-top:3px; padding-top:2px;'>
            <span class='pbirthday' style='font-size:9px; color:#64748b;'><?= htmlspecialchars($wareki_short, ENT_QUOTES, 'UTF-8') ?></span>
            <span style='display:inline-flex; align-items:center; gap:2px;'>
                <?php if ($doctor_icon): ?>
                <span style='display:inline-block; background:#1a1a2e; color:#ffffff; font-weight:bold; font-size:8px; padding:0px 3px; border-radius:10px; min-width:16px; text-align:center; line-height:1.4;'><?= $doctor_icon ?></span>
                <?php endif; ?>
                <?= $exam_badge ?>
            </span>
        </div>
        <?php
    }
    echo "</div>";
}

/**
 * 備考テキスト（プレーン/リッチテキスト両対応）安全描画関数
 * @param string|null $text 備考欄文字列
 * @return string 安全にエスケープ・サニタイズされたHTML
 */
if (!function_exists('renderRemarksHtml')) {
    function renderRemarksHtml(?string $text): string {
        if ($text === null || trim($text) === '') {
            return '';
        }

        // HTMLタグが含まれているか判定
        if ($text !== strip_tags($text)) {
            // リッチテキストの場合：安全な装飾タグのみ許可し、XSSスクリプトを遮断
            $allowed_tags = '<strong><b><span><p><br><em><u><ul><ol><li>';
            $cleaned = strip_tags($text, $allowed_tags);
            
            // on* 属性（onclick等）を除去
            $cleaned = preg_replace('/<([a-z][a-z0-9]*)[^>]*?(\bon[a-z]+\s*=\s*[\'"][^\'"]*[\'"])[^>]*?>/i', '<$1>', $cleaned);
            return '<div class="rich-remarks">' . $cleaned . '</div>';
        } else {
            // 従来のプレーンテキストの場合：エスケープした上で改行を反映
            return '<div class="plain-remarks">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
        }
    }
}

/**
 * 新規登録時に古い schedule_id の混入を完全にシャットアウトする共通ガード
 */
if (!function_exists('sanitizeActionTypeAndId')) {
    function sanitizeActionTypeAndId($actionType, $scheduleId) {
        $action = trim((string)$actionType);
        $id = (int)$scheduleId;
        if ($action === 'insert') {
            $id = 0;
        }
        return [$action, $id];
    }
}

/**
 * 予約保存・カルテID変更時に、MALL3から最新メモを取得して t_mall_memo へ保存する共通関数
 */
if (!function_exists('syncAndSaveMallMemo')) {
    function syncAndSaveMallMemo($pdo, $patientId) {
        if (empty($patientId) || $patientId === '---' || !is_numeric($patientId)) {
            return;
        }

        try {
            $patient_url = "http://localhost/endoscope/get_mall_patient.php?patient_no=" . urlencode((string)$patientId);
            $patient_json = @file_get_contents($patient_url);
            $patient_name = '';
            $birth_date = null;

            if ($patient_json !== false) {
                $p_res = json_decode($patient_json, true);
                if (isset($p_res['success']) && $p_res['success']) {
                    $patient_name = isset($p_res['patient_name']) ? trim($p_res['patient_name']) : '';
                    $birth_date = !empty($p_res['birthday']) ? $p_res['birthday'] : null;
                }
            }

            $api_url = "http://localhost/endoscope/get_mall_memos.php?patient_no=" . urlencode((string)$patientId);
            $json_data = @file_get_contents($api_url);
            if ($json_data === false) {
                return;
            }

            $res = json_decode($json_data, true);
            if (!$res || !isset($res['success']) || !$res['success']) {
                return;
            }

            $memo_today   = null;
            $memo_karte   = null;
            $memo_patient = null;

            foreach ($res['memos'] as $memo) {
                $div_id = (int)($memo['div_id'] ?? 0);
                if ($div_id === 1)  $memo_today   = $memo['note'] ?? null;
                if ($div_id === 2)  $memo_karte   = $memo['note'] ?? null;
                if ($div_id === 3)  $memo_patient = $memo['note'] ?? null;
            }

            $sync_sql = "INSERT INTO public.t_mall_memo 
                            (patient_id, patient_name, birth_date, memo_today, memo_karte, memo_patient) 
                         VALUES 
                            (:pid, :pname, :birth, :today, :karte, :patient)
                         ON CONFLICT (patient_id) 
                         DO UPDATE SET 
                            patient_name = EXCLUDED.patient_name,
                            birth_date   = EXCLUDED.birth_date,
                            memo_today   = EXCLUDED.memo_today,
                            memo_karte   = EXCLUDED.memo_karte,
                            memo_patient = EXCLUDED.memo_patient";
                            
            $stmt = $pdo->prepare($sync_sql);
            $stmt->execute([
                ':pid'     => $patientId,
                ':pname'   => $patient_name,
                ':birth'   => $birth_date,
                ':today'   => $memo_today,
                ':karte'   => $memo_karte,
                ':patient' => $memo_patient
            ]);
        } catch (Exception $ex) {
            error_log("Mall memo sync failed: " . $ex->getMessage());
        }
    }
}