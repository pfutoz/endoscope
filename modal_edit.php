<?php
/**
 * 生成日時: 2026-09-09 20:30:00 JST
 * 設置パス: c:\Apache24\htdocs\endoscope\modal_edit.php
 * ファイル名: modal_edit.php
 * 
 * 修正:
 *   - 2026-09-28: 新検査種別「浣腸CF」対応
 *       1. 検査種別マスタ・フォールバックに「浣腸CF」を追加
 *       2. GIF枠/TCF枠いずれでも「浣腸CF」を柔軟に登録可能に制御
 *       3. 浣腸CF選択時の下剤・部屋番号自動リセット（下剤不要・マイトイレ部屋なし）
 *   - 2026-09-28: Windows 7 / 院内オフライン端末 (Supermium等) 向け互換性・堅牢性の強化
 *       1. Quillエディタ関連の外部CDN依存（jsdelivr）を解消し、ローカル（assets/vendor/quill/）から配信
 *       2. Quill初期化エラー時でもカルテID入力（Enterキー検索）や画面機能が巻き添え停止しないよう完全なtry-catch保護とフォールバックを実装
 *       3. カルテID入力欄にインラインonkeydownを追加し、Enterキー検索の多重化・即応性を確保
 *       4. Quill未動作時でも備考欄のテキスト入力・保存および定型文挿入が確実に動作するフォールバック処理を追加
 *   - 備考入力欄およびMALL3メモ欄にワンクリック拡縮機能を追加
 *   - 定型文登録機能（選択文字列からのマスタ登録 ＆ 新規定型文の直接登録・絞り込み・パレットドラッグ移動）を実装
 *   - 右上に「保存して印刷」ボタンを集約、下部フッターバーを撤去
 *   - Firefox 115等におけるQuillエディタツールバーの表示崩れ防止CSSを強化
 *   - 2026-09-29: 【本物モーダルダイアログ連携】親画面（month_schedule/month_matrix_v2）のインページモーダル化に伴い、window.parent postMessage 連携を実装。別ウィンドウ時（window.opener）の互換性を100%維持しながら、保存・削除・閉じる時のスムーズなダイアログクローズと親画面自動リロードを実現
 *   - 2026-10-04: 【病棟管理連動】初登録前のリアルタイム表示対応（TCF/W選択時即表示、ポリペク選択時即表示＆入院予定ON、上記以外選択時入院予定OFF＆非表示化）
 *   - 2026-10-20: 【特大Flatpickr導入】検査日・生年月日入力欄に小野会カレンダー（公休赤色・木曜青色判定）を完全バインド
 *   - 2026-10-22: 【時間枠オーバーライド完全連動 ＆ 臨時枠登録対応】
 *       1. getEffectiveWakuList による当日有効枠（臨時枠・枠名変更・休止枠）の完全取得・反映
 *       2. 臨時枠（エコー枠・特別枠等）への直接予約登録を完全保証
 *   - 2026-10-24: 【RS-Base 検査実績タイムライン(キャンバス)連携】
 *       1. 患者情報エリアに「🗺️ 検査実績」ボタンを新設し、編集中の患者の検査実績キャンバスを別タブ起動可能に
 *   - 2026-10-26: 【検査種別グループ判定共通化】
 *       1. 下剤・部屋番号・鎮静の初期表示判定を resolveExamGroup() に連動し、m_exam_type_group と一元化
 *   - 2026-10-30: 【modal_edit 操作性向上DX ＆ 電カル連携強化】
 *       1. MALL3メモ（本日メモ・カルテメモ・患者メモ）ワンクリック備考・特記事項転記機能（クリップボード同時コピー対応）新設
 *       2. 患者No入力用ソフトウェアキーボード（タッチパネル・タブレット・マウス対応テンキーパッド）新設
 *       3. 最重要入力フィールド（患者No・検査種別）の背景色薄黄ハイライト化、および仮予約ボタンの標準グレー統一
 *       4. 電カルボタンの独立右寄せブロック化 ＆ 最終受診日・受診科のリアルタイム表示（本日受診の赤色強調バッジ対応）
 *       5. 最上段空エリアへのレコード作成日時・更新日時のコンパクト表示（🕒 登録: YYYY/MM/DD HH:mm | 更新: ...）
 *       6. 担当医師名プルダウンの背景色を検査種別と同一の薄い黄色（#fefce8）へハイライト統一
 *       7. 患者情報カードの電話番号表記から「TEL:」を撤去し「📞」アイコンのみにシンプル化（コピー機能連動）
 *       8. 時間枠および診療科の背景色・枠線を検査日ピッカーと同一の薄いソフトブルー（#eff6ff / #93c5fd）へ統一
 *       9. 備考Quillエディタの文字色（厳選8色）・網掛け蛍光色（厳選8色）パレットの大幅拡充
 *   - 2026-11-07: 【時間枠プルダウン患者名表示 ＆ 当日医師別残枠・重複案内対応】
 *       1. 時間枠（#scheduled_time）のリストボックス候補に、既に登録されている予約の患者名・担当医（例: TCF13:30① 【山田 太郎(哲也)】）を初期表示・日付変更時の双方で表示
 *       2. 1枠複数予約（イレギュラー対応）を許容しつつ、既存予約がある枠を選択した際に #duplicateAlert で登録済み患者をリアルタイム案内
 *       3. 基本情報パネル下部に「📊 当日医師別枠状況（CF/GIF 使用/上限）」サマリーバーを新設（ワンクリック医師選択連動）
 *   - 2026-11-08: 【患者Noテンキー自動クローズ対応】
 *       1. 患者No入力後のEnter確定や同期実行（doSyncPatient）でMALL3の患者がマッチした際、患者Noソフトウェアテンキー（#patientVirtualKeyboard）を自動で閉じ、入力欄のフォーカスを外すよう改善
 */
ini_set('display_errors', "1");
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/date_helper.php';
require_once __DIR__ . '/lib/slot_helper.php';
require_once __DIR__ . '/lib/medical_alert_helper.php';
require_once __DIR__ . '/lib/canvas_helper.php';

$pdo = getDbConnection();
if (!$pdo) {
    die("データベース接続失敗");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['api_action'];

    // 1. 定型文の新規登録
    if ($action === 'add_remarks_phrase') {
        $phrase = trim($_POST['phrase'] ?? '');
        if ($phrase === '') {
            echo json_encode(['success' => false, 'message' => '登録する文字列が空です。']);
            exit;
        }
        try {
            $stmt_chk = $pdo->prepare("SELECT master_id FROM m_input_master WHERE category = 'remarks_phrase' AND item_value = :val");
            $stmt_chk->execute([':val' => $phrase]);
            if ($stmt_chk->fetch()) {
                echo json_encode(['success' => false, 'message' => '既に登録されている定型文です。']);
                exit;
            }

            $stmt_max = $pdo->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM m_input_master WHERE category = 'remarks_phrase'");
            $next_order = (int)$stmt_max->fetchColumn();

            $stmt_ins = $pdo->prepare("
                INSERT INTO m_input_master (category, item_value, display_order, created_at)
                VALUES ('remarks_phrase', :val, :disp, CURRENT_TIMESTAMP)
                RETURNING master_id
            ");
            $stmt_ins->execute([':val' => $phrase, ':disp' => $next_order]);
            $new_master_id = (int)$stmt_ins->fetchColumn();

            echo json_encode(['success' => true, 'master_id' => $new_master_id, 'phrase' => $phrase]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // 2. 定型文の編集
    if ($action === 'edit_remarks_phrase') {
        $master_id = (int)($_POST['master_id'] ?? 0);
        $new_phrase = trim($_POST['phrase'] ?? '');
        if ($master_id <= 0 || $new_phrase === '') {
            echo json_encode(['success' => false, 'message' => '定型文またはIDが不正です。']);
            exit;
        }
        try {
            $stmt_upd = $pdo->prepare("
                UPDATE m_input_master 
                SET item_value = :val
                WHERE master_id = :id AND category = 'remarks_phrase'
            ");
            $stmt_upd->execute([':val' => $new_phrase, ':id' => $master_id]);
            echo json_encode(['success' => true, 'master_id' => $master_id, 'phrase' => $new_phrase]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }

    // 3. 定型文の削除
    if ($action === 'delete_remarks_phrase') {
        $master_id = (int)($_POST['master_id'] ?? 0);
        if ($master_id <= 0) {
            echo json_encode(['success' => false, 'message' => '削除対象IDが不正です。']);
            exit;
        }
        try {
            $stmt_del = $pdo->prepare("DELETE FROM m_input_master WHERE master_id = :id AND category = 'remarks_phrase'");
            $stmt_del->execute([':id' => $master_id]);
            echo json_encode(['success' => true, 'master_id' => $master_id]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}

$debug_mode = false;
$config_file = __DIR__ . '/debug_config.json';
if (file_exists($config_file)) {
    $conf = json_decode(file_get_contents($config_file), true);
    if (!empty($conf['debug_mode'])) {
        $debug_mode = true;
    }
}
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $debug_mode = true;
}

$schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$dept = isset($_GET['dept']) ? (string)$_GET['dept'] : 'GASTRO';
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$waku = isset($_GET['waku']) ? (string)$_GET['waku'] : '';

if (!in_array($dept, ['PROCT', 'GASTRO'], true)) { $dept = 'GASTRO'; }

$reservation = null;
$is_within_10min = false;
$created_diff_seconds = 999999;

$current_alert = [
    'has_antithrombotic'      => false,
    'has_diabetes'            => false,
    'has_psychotropic'        => false,
    'has_nsaids'              => false,
    'has_allergy'             => false,
    'has_infection'           => false,
    'has_cold_fever'          => false,
    'antithrombotic_verified' => false,
    'sources'                 => []
];

$record_created_at_fmt = '';
$record_updated_at_fmt = '';

if ($schedule_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM t_endoscopy_schedule WHERE schedule_id = :id");
    $stmt->execute([':id' => $schedule_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($reservation) {
        $dept = (string)$reservation['department_code'];
        $date = (string)$reservation['exam_date'];
        $waku = (string)$reservation['scheduled_time'];

        // ★ レコード作成日・更新日のフォーマット（最上段ヘッダー表示用）
        if (!empty($reservation['created_at'])) {
            $record_created_at_fmt = date('Y/m/d H:i', strtotime($reservation['created_at']));
        }
        if (!empty($reservation['updated_at'])) {
            $record_updated_at_fmt = date('Y/m/d H:i', strtotime($reservation['updated_at']));
        }
    }

    // ★ 作成からの経過時間判定（10分以内なら即時取消・打ち直し扱い）
    if ($reservation && !empty($reservation['created_at'])) {
        $c_ts = strtotime($reservation['created_at']);
        $created_diff_seconds = time() - $c_ts;
        if ($created_diff_seconds >= 0 && $created_diff_seconds <= 600) {
            $is_within_10min = true;
        }
    }

    $alertsMap = getMedicalAlertsMap($pdo, [$schedule_id]);
    if (isset($alertsMap[$schedule_id])) {
        $current_alert = $alertsMap[$schedule_id];
    }
}

// 👤 患者情報カード初期化用データ
$p_id_initial     = (string)($reservation['patient_id'] ?? '');
$p_name_initial   = (string)($reservation['patient_name'] ?? '');
$p_kana_initial   = (string)($reservation['patient_kana'] ?? '');
$p_bday_initial   = (string)($reservation['birthday'] ?? '');
$p_wareki_initial = '';
$p_age_int        = null;
$p_age_cls        = 'age-under';
$p_is_vip         = !empty($reservation['is_vip']);

if (!empty($p_bday_initial)) {
    $clean_b = substr(trim($p_bday_initial), 0, 10);
    $p_wareki_initial = formatBirthdayWithWareki($clean_b);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean_b)) {
        try {
            $b_dt = new DateTime($clean_b);
            $t_dt = new DateTime($date);
            $p_age_int = $b_dt->diff($t_dt)->y;
            $p_age_cls = ($p_age_int >= 70) ? 'age-senior' : 'age-under';
        } catch (Exception $e) {}
    }
}

// 時間枠リストの取得: 当日のオーバーライド（臨時枠・枠名変更・休止枠）を適用した有効枠を取得
$effective_waku_list = getEffectiveWakuList($pdo, $dept, $date);

// 当日の確定予約データを取得（時間枠プルダウンへの患者名表示・重複案内用）
$stmt_day_res = $pdo->prepare("
    SELECT schedule_id, scheduled_time, patient_id, patient_name, exam_type, doctor_name, exam_location, remarks
    FROM t_endoscopy_schedule
    WHERE department_code = :dept
      AND exam_date = :date
      AND is_canceled = FALSE
    ORDER BY schedule_id ASC
");
$stmt_day_res->execute([':dept' => $dept, ':date' => $date]);
$raw_day_reservations = $stmt_day_res->fetchAll(PDO::FETCH_ASSOC);

$valid_day_reservations = [];
foreach ($raw_day_reservations as $dr) {
    $pn = trim((string)($dr['patient_name'] ?? ''));
    $rm = trim((string)($dr['remarks'] ?? ''));
    if ($pn === '※枠なし' || strpos($rm, '＊＊＊🚫枠なし') !== false) {
        continue;
    }
    $valid_day_reservations[] = [
        'schedule_id'    => (int)$dr['schedule_id'],
        'scheduled_time' => trim((string)($dr['scheduled_time'] ?? '')),
        'patient_id'     => trim((string)($dr['patient_id'] ?? '')),
        'patient_name'   => $pn,
        'exam_type'      => trim((string)($dr['exam_type'] ?? '')),
        'doctor_name'    => trim((string)($dr['doctor_name'] ?? '')),
        'exam_location'  => trim((string)($dr['exam_location'] ?? '')),
    ];
}

// 既存予約やURLパラメータの指定枠、または当日予約が存在する旧世代枠がリストにない場合のフォールバック（迷子化防止）
$fallback_waku_candidates = [];
if ($waku !== '') {
    $fallback_waku_candidates[] = $waku;
}
foreach ($valid_day_reservations as $vdr) {
    if ($vdr['scheduled_time'] !== '' && !in_array($vdr['scheduled_time'], $fallback_waku_candidates, true)) {
        $fallback_waku_candidates[] = $vdr['scheduled_time'];
    }
}

foreach ($fallback_waku_candidates as $cand_waku) {
    $has_cand = false;
    foreach ($effective_waku_list as $ew) {
        if ($ew['waku_name'] === $cand_waku || (!empty($ew['original_name']) && $ew['original_name'] === $cand_waku) || findEffectiveWaku([$ew], $cand_waku, false) !== null) {
            $has_cand = true;
            break;
        }
    }
    if (!$has_cand) {
        $effective_waku_list[] = [
            'waku_id'            => null,
            'base_waku_id'       => null,
            'override_id'        => null,
            'waku_name'          => $cand_waku,
            'original_name'      => $cand_waku,
            'group_code'         => (stripos($cand_waku, 'GIF') !== false) ? 'GIF' : ((stripos($cand_waku, 'エコー') !== false || stripos($cand_waku, 'ECHO') !== false) ? 'ECHO' : 'TCF'),
            'department_code'    => $dept,
            'display_order'      => 9999,
            'is_suspended'       => false,
            'is_override'        => false,
            'is_legacy_fallback' => true,
            'reason'             => null
        ];
    }
}

// 各枠へ当日の登録済み患者情報を紐付け
$day_slot_reservations_map = [];
foreach ($effective_waku_list as &$ew_item) {
    $wn = (string)$ew_item['waku_name'];
    $on = (string)($ew_item['original_name'] ?? $wn);
    $dummy_slot = ['waku_name' => $wn, 'original_name' => $on];
    $slot_res_list = [];
    foreach ($valid_day_reservations as $vdr) {
        $rt = $vdr['scheduled_time'];
        if ($rt === $wn || $rt === $on || findEffectiveWaku([$dummy_slot], $rt, false) !== null) {
            $slot_res_list[] = $vdr;
        }
    }
    $ew_item['reservations'] = $slot_res_list;
    $day_slot_reservations_map[$wn] = $slot_res_list;
}
unset($ew_item);

$waku_list = $effective_waku_list;

function getMasterItems(PDO $pdo, string $category, array $default_items = []): array {
    try {
        $stmt = $pdo->prepare("
            SELECT item_value 
            FROM m_input_master 
            WHERE category = :cat 
            ORDER BY display_order ASC, master_id ASC
        ");
        $stmt->execute([':cat' => $category]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return (!empty($rows)) ? $rows : $default_items;
    } catch (Exception $e) {
        return $default_items;
    }
}

function getRemarksPhrasesList(PDO $pdo, array $default_items = []): array {
    try {
        $stmt = $pdo->query("
            SELECT master_id, item_value 
            FROM m_input_master 
            WHERE category = 'remarks_phrase' 
            ORDER BY display_order ASC, master_id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return $rows;
        }

        $res = [];
        $stmt_ins = $pdo->prepare("
            INSERT INTO m_input_master (category, item_value, display_order, created_at)
            VALUES ('remarks_phrase', :val, :disp, CURRENT_TIMESTAMP)
            RETURNING master_id
        ");
        foreach ($default_items as $idx => $val) {
            $stmt_ins->execute([':val' => $val, ':disp' => $idx + 1]);
            $mid = (int)$stmt_ins->fetchColumn();
            $res[] = ['master_id' => $mid, 'item_value' => $val];
        }
        return $res;
    } catch (Exception $e) {
        $fallback = [];
        foreach ($default_items as $i => $val) {
            $fallback[] = ['master_id' => $i + 1, 'item_value' => $val];
        }
        return $fallback;
    }
}

$exam_types_master      = getExamTypeOptions($pdo);
$exam_locations_master  = getMasterItems($pdo, 'exam_location', ['指定なし']);
$remarks_phrases_master = getRemarksPhrasesList($pdo, [
    '休薬確認済み', '生検あり', '車椅子来院', '前処置自宅', '同意書回収済み',
    '鎮静剤希望', '感染症あり', '朝食抜き確認済', 'ポリペク予定', '家族同伴'
]);

$rooms_master = getMasterItems($pdo, 'toilet_room', [
    'ﾏ101', 'ﾏ102', 'ﾏ103', 'ﾏ104', 'ﾏ105', 'ﾏ106', 'ﾏ107',
    'ﾏ201', 'ﾏ202', 'ﾏ203', 'ﾏ204', 'ﾏ205', 'ﾏ206', 'ﾏ207',
    'ﾏ障害', 'ｲ203', 'ｲ205', 'ｲ206', 'ｲ207',
    'ｺ205', 'ｺ206', 'ｺ207', 'ｺ211', 'ｺ212'
]);
$preps_master = getMasterItems($pdo, 'bowel_prep', ['マグP', 'モビ', 'ニフ']);

function calculateArrivalBefore30Min(?string $slotName): string {
    if (!$slotName) return '';
    if (preg_match('/(\d{1,2}):(\d{2})/', $slotName, $matches)) {
        $hour   = (int)$matches[1];
        $minute = (int)$matches[2];
        $totalMinutes = ($hour * 60 + $minute) - 30;
        if ($totalMinutes < 0) {
            $totalMinutes += 24 * 60;
        }
        $calcHour = (int)floor($totalMinutes / 60);
        $calcMin  = $totalMinutes % 60;
        return sprintf('%02d:%02d', $calcHour, $calcMin);
    }
    return '';
}

// =========================================================================
// 診療科別：枠名による検査種別（GIF）自動デフォルトセット設定
// 将来簡単にON/OFFできるように定義（true: 自動セット有効 / false: 無効）
// =========================================================================
$enable_auto_gif_by_dept = [
    'GASTRO' => true, // 胃腸科: GIF枠選択時に検査種別を「GIF」に自動セット
    'PROCT'  => true, // 肛門科: GIF枠選択時に検査種別を「GIF」に自動セット (OFFにする場合は false)
];

if ($reservation) {
    $curr_exam   = (string)$reservation['exam_type'];
    $curr_loc    = (string)$reservation['exam_location'];
    $curr_doctor = (string)($reservation['doctor_name'] ?? '');
    $curr_room   = (string)($reservation['room_number'] ?? '');
    $curr_prep   = (string)($reservation['bowel_prep'] ?? '');
    $curr_arrival= (string)($reservation['arrival_time'] ?? '');
    $raw_remarks = (string)($reservation['remarks'] ?? '');
} else {
    $curr_room   = '';
    $curr_prep   = '';
    $raw_remarks = '';

    $req_doctor = isset($_GET['doctor']) ? trim((string)$_GET['doctor']) : '';
    if ($req_doctor === 'all' || $req_doctor === 'undecided' || $req_doctor === '◆未定◆' || $req_doctor === '--担当未定--') {
        $req_doctor = '';
    }

    $is_gif_waku = (stripos($waku, 'GIF') !== false);
    $auto_gif_enabled = !empty($enable_auto_gif_by_dept[$dept]) && $is_gif_waku;

    if ($dept === 'PROCT') {
        $curr_doctor  = ($req_doctor !== '') ? $req_doctor : '誠吾';
        $curr_loc     = '内視鏡室（おの）';
        $curr_arrival = $auto_gif_enabled ? calculateArrivalBefore30Min($waku) : '9:00';
        $curr_exam    = $auto_gif_enabled ? 'GIF' : '';
    } else {
        $curr_doctor  = $req_doctor;
        $default_first_loc = $exam_locations_master[0] ?? '';
        if ($auto_gif_enabled) {
            $curr_exam    = 'GIF';
            $curr_arrival = calculateArrivalBefore30Min($waku);
            $curr_loc     = $default_first_loc;
        } elseif (stripos($waku, 'TCF') !== false) {
            $curr_exam    = '';
            $curr_arrival = '';
            $curr_loc     = $default_first_loc;
        } else {
            $curr_exam    = '';
            $curr_arrival = '';
            $curr_loc     = '';
        }
    }
}

// 💉 鎮静（セデーション）および 🚗 自主運転注意の初期値判定
$raw_remarks_clean = strip_tags($raw_remarks ?? '');
$has_sedation_initial = false;
$has_drive_initial = false;

// 1. DBに明示的に保存された値があれば最優先
if ($reservation && isset($reservation['has_sedation']) && $reservation['has_sedation'] !== null) {
    $has_sedation_initial = !empty($reservation['has_sedation']);
} else {
    // 備考欄テキストからの推測判定
    if (mb_strpos($raw_remarks_clean, '注なし') !== false || mb_strpos($raw_remarks_clean, '注射なし') !== false || mb_strpos($raw_remarks_clean, '鎮静なし') !== false) {
        $has_sedation_initial = false;
    } elseif (mb_strpos($raw_remarks_clean, '注あり') !== false || mb_strpos($raw_remarks_clean, '注射あり') !== false || mb_strpos($raw_remarks_clean, '鎮静') !== false || mb_strpos($raw_remarks_clean, 'プロポ') !== false || mb_strpos($raw_remarks_clean, 'ジアゼ') !== false) {
        $has_sedation_initial = true;
    }
}

if ($reservation && isset($reservation['has_drive_caution']) && $reservation['has_drive_caution'] !== null) {
    $has_drive_initial = !empty($reservation['has_drive_caution']);
} else {
    if (mb_strpos($raw_remarks_clean, '運転') !== false || mb_strpos($raw_remarks_clean, '車来院') !== false || mb_strpos($raw_remarks_clean, '送迎') !== false) {
        $has_drive_initial = true;
    }
}

// 検査種別に応じた下剤・部屋・鎮静の初期表示判定（m_exam_type_group連動）
$curr_exam_clean = trim($curr_exam ?? '');
$curr_exam_group = resolveExamGroup($curr_exam_clean, $pdo);
$is_tcf_like_initial = ($curr_exam_group === 'TCF' && $curr_exam_clean !== '浣腸CF' && $curr_exam_clean !== '浣腸F');
$is_echo_initial = ($curr_exam_group === 'ECHO');
$is_gastric_screening_initial = (mb_strpos($curr_exam_clean, '胃がん') !== false);

// エコー時は鎮静・自主運転フラグを強制OFF
if ($is_echo_initial) {
    $has_sedation_initial = false;
    $has_drive_initial = false;
}

// 胃がん検診時（新規登録時またはDB未保存の場合）は市町村契約により原則鎮静剤不使用（明示的な「注あり」「鎮静あり」が備考にない限り初期OFF）
if ($is_gastric_screening_initial && (!$reservation || $reservation['has_sedation'] === null) && mb_strpos($raw_remarks_clean, '注あり') === false && mb_strpos($raw_remarks_clean, '鎮静あり') === false) {
    $has_sedation_initial = false;
    $has_drive_initial = false;
}


// 🏥 入院予定フラグの初期値判定（検査種別は W / TCF のみ、ポリペクは無条件確定）
$is_polypep_initial = (mb_strpos($curr_exam_clean, "ポリペク") !== false);
$is_w_or_tcf_initial = ($curr_exam_clean === "TCF" || $curr_exam_clean === "W");

$has_admission_initial = false;
$admission_suggest_reason = "";

if ($is_polypep_initial) {
    $has_admission_initial = true;
    $admission_suggest_reason = "ポリペク確定";
} elseif ($is_w_or_tcf_initial) {
    if ($reservation && isset($reservation["is_admission"]) && $reservation["is_admission"] !== null) {
        $has_admission_initial = !empty($reservation["is_admission"]);
        $admission_suggest_reason = $has_admission_initial ? "手動設定済(入院あり)" : "手動設定済(入院なし)";
    } else {
        // 備考テキストからのサジェスト
        if (mb_strpos($raw_remarks_clean, "ポリペクなし") !== false || mb_strpos($raw_remarks_clean, "日帰り") !== false || mb_strpos($raw_remarks_clean, "入院なし") !== false) {
            $has_admission_initial = false;
            $admission_suggest_reason = "メモ「ポリペクなし/日帰り」検知";
        } elseif (mb_strpos($raw_remarks_clean, "ポリペクあり") !== false || mb_strpos($raw_remarks_clean, "同意書：ポリペク入院可") !== false || mb_strpos($raw_remarks_clean, "ポリペク入院") !== false || mb_strpos($raw_remarks_clean, "入院可") !== false || mb_strpos($raw_remarks_clean, "入院") !== false) {
            $has_admission_initial = true;
            $admission_suggest_reason = "メモ「ポリペクあり/入院可」検知";
        }
    }
}

// 鎮静剤ラベル用（肛門科: プロポ / 胃腸科: ジアゼパム）
$sedation_drug_name = ($dept === 'PROCT') ? 'プロポ' : 'ジアゼパム';
$sedation_drug_desc = ($dept === 'PROCT') ? 'プロポフォール / 安静目安20分' : 'ジアゼパム / 安静目安30分';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $schedule_id > 0 ? '予約編集' : '新規予約登録' ?></title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link href="assets/vendor/quill/quill.snow.css?v=20260928" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            padding: 8px 12px;
            background-color: #f1f5f9;
            color: #1e293b;
            font-size: 12px;
            overflow-x: hidden;
        }
        .main-card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 10px 14px;
            max-width: 980px;
            margin: 0 auto;
        }
        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px;
            margin-bottom: 8px;
            gap: 12px;
        }
        .header-record-meta {
            font-size: 11px;
            color: #64748b;
            font-family: Consolas, "Liberation Mono", Menlo, monospace, sans-serif;
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 3px 10px;
            border-radius: 6px;
            white-space: nowrap;
        }
        .header-record-meta .meta-sep {
            color: #cbd5e1;
        }
        .header-record-meta .meta-new {
            color: #0284c7;
            font-weight: 600;
        }
        .header-title-area {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .header-title-area h2 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
        }
        .dept-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            color: #fff;
        }
        .dept-badge.PROCT { background: #3b82f6; }
        .dept-badge.GASTRO { background: #10b981; }

        .schedule-id-badge {
            background: #334155;
            color: #f8fafc;
            font-family: Consolas, monospace;
            font-size: 11px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .btn-group-top {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .btn {
            padding: 4px 12px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-purple { background: #8b5cf6; color: #fff; }
        .btn-purple:hover { background: #7c3aed; }
        .btn-danger { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }
        .btn-danger:hover { background: #fca5a5; color: #991b1b; }
        .btn-secondary { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-teal { background: #0d9488; color: #fff; }
        .btn-teal:hover { background: #0f766e; }

        .btn-expand-toggle {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 10px;
            font-weight: bold;
            color: #475569;
            cursor: pointer;
            line-height: 1.4;
            transition: all 0.15s;
            user-select: none;
        }
        .btn-expand-toggle:hover {
            background: #e2e8f0;
            color: #0f172a;
            border-color: #94a3b8;
        }

        .panel-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 8px;
            position: relative;
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
            padding-bottom: 2px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .grid-2col {
            display: grid;
            grid-template-columns: 310px 1fr;
            gap: 10px;
            margin-bottom: 8px;
        }
        .grid-2col > .panel-box {
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
        }

        .form-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 5px;
        }
        .form-row:last-child { margin-bottom: 0; }
        .form-label {
            width: 65px;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
            flex-shrink: 0;
        }
        .form-control {
            padding: 3px 6px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 11px;
            background: #fff;
            height: 26px;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59,130,246,0.15);
        }

        .patient-flex-row, .attr-flex-row {
            display: flex;
            align-items: center;
            gap: 5px;
            width: 100%;
        }
        .attr-flex-row {
            margin-top: 6px;
            flex-wrap: wrap;
        }

        /* 👤 患者情報カード（画像2デザイン準拠） */
        .patient-card-container {
            display: flex;
            align-items: stretch;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 10px;
            margin-bottom: 8px;
            min-height: 52px;
            gap: 12px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .patient-sync-left {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
            min-width: 175px;
            flex-shrink: 0;
            position: relative; /* ソフトウェアキーボードのポップオーバー基準位置 */
        }

        .patient-sync-title {
            font-size: 11px;
            font-weight: 700;
            color: #1e3a8a;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .patient-sync-input-row {
            display: flex;
            align-items: center;
        }

        /* 入力要素の高い患者No（薄い黄色ハイライト） */
        .patient-id-input {
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
            width: 95px !important;
            height: 28px !important;
            font-weight: bold;
            font-size: 13.5px;
            letter-spacing: 0.5px;
            padding: 2px 6px;
            background-color: #fefce8 !important; /* 薄い黄色 */
            border: 1px solid #fde047 !important; /* 黄色枠線 */
            color: #0f172a;
            transition: background-color 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        .patient-id-input:focus {
            background-color: #fef9c3 !important;
            border-color: #eab308 !important;
            box-shadow: 0 0 0 2px rgba(234, 179, 8, 0.25) !important;
            outline: none;
        }

        /* ソフトウェアキーボード開閉ボタンスタイル */
        .btn-keyboard-toggle {
            background: #fffbeb;
            color: #854d0e;
            font-size: 13px;
            height: 28px;
            padding: 0 5px;
            border: 1px solid #fde047;
            border-left: none;
            border-right: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            user-select: none;
        }
        .btn-keyboard-toggle:hover {
            background: #fef08a;
            color: #713f12;
        }
        .btn-keyboard-toggle.is-active {
            background: #fde047;
            color: #713f12;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }

        .btn-sync {
            background: #0284c7;
            color: #ffffff;
            font-weight: 700;
            font-size: 11.5px;
            height: 28px;
            padding: 0 9px;
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
            border: 1px solid #0284c7;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s;
            white-space: nowrap;
        }
        .btn-sync:hover {
            background: #0369a1;
            border-color: #0369a1;
        }

        /* ⌨️ 患者No ソフトウェアテンキー ポップオーバースタイル */
        .virtual-numpad-popover {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            width: 226px;
            background: #ffffff;
            border: 1px solid #94a3b8;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.15);
            padding: 7px;
            z-index: 1050;
            animation: numpadFadeIn 0.15s ease-out;
        }
        @keyframes numpadFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .numpad-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding-bottom: 3px;
            border-bottom: 1px solid #e2e8f0;
        }
        .numpad-title {
            font-size: 11px;
            font-weight: 700;
            color: #1e3a8a;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .numpad-close-btn {
            background: none;
            border: none;
            font-size: 13px;
            font-weight: bold;
            color: #94a3b8;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
            border-radius: 3px;
            transition: all 0.15s;
        }
        .numpad-close-btn:hover {
            color: #ef4444;
            background: #fee2e2;
        }
        .numpad-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 5px;
        }
        .numpad-key {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            height: 38px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
            user-select: none;
            transition: background 0.1s, transform 0.05s, border-color 0.1s;
        }
        .numpad-key:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .numpad-key:active {
            transform: scale(0.95);
            background: #e2e8f0;
        }
        .numpad-bs {
            background: #fef2f2 !important;
            color: #dc2626 !important;
            border-color: #fecaca !important;
            font-size: 16px !important;
        }
        .numpad-bs:hover {
            background: #fee2e2 !important;
            border-color: #fca5a5 !important;
        }
        .numpad-clr {
            background: #f8fafc !important;
            color: #64748b !important;
            border-color: #cbd5e1 !important;
            font-size: 12px !important;
            font-weight: 800 !important;
        }
        .numpad-clr:hover {
            background: #e2e8f0 !important;
            color: #334155 !important;
        }
        .numpad-sync {
            background: #0284c7 !important;
            color: #ffffff !important;
            border-color: #0284c7 !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            grid-row: span 2;
            height: 100% !important;
            box-shadow: 0 2px 4px rgba(2, 132, 199, 0.3) !important;
        }
        .numpad-sync:hover {
            background: #0369a1 !important;
            border-color: #0369a1 !important;
        }

        .patient-action-links {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 1px;
        }

        .btn-sub-action {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 600;
            color: #475569;
            padding: 2px 5px;
            cursor: pointer;
            line-height: 1.1;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .btn-sub-action:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }

        /* 仮予約の色を通常のグレーに統一 */
        .btn-sub-temp {
            background: #f8fafc;
            color: #475569;
            border-color: #cbd5e1;
        }
        .btn-sub-temp:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }

        /* 入力要素の高い検査種別・医師名（薄い黄色ハイライト） */
        #exam_type,
        #doctor_name {
            background-color: #fefce8 !important; /* 薄い黄色 */
            border: 1px solid #fde047 !important; /* 黄色枠線 */
            color: #0f172a !important;
            font-weight: 700 !important;
            transition: background-color 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        #exam_type:focus,
        #doctor_name:focus {
            background-color: #fef9c3 !important;
            border-color: #eab308 !important;
            box-shadow: 0 0 0 2px rgba(234, 179, 8, 0.25) !important;
            outline: none;
        }

        /* 時間枠・診療科（検査日ピッカーと統一された薄いソフトブルー） */
        #scheduled_time,
        #department_code_select {
            background-color: #eff6ff !important; /* 薄いソフトブルー */
            border: 1px solid #93c5fd !important; /* ソフトブルー枠線 */
            color: #0f172a !important;
            font-weight: 700 !important;
            transition: background-color 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        #scheduled_time:hover,
        #department_code_select:hover {
            border-color: #60a5fa !important;
        }
        #scheduled_time:focus,
        #department_code_select:focus {
            background-color: #dbeafe !important;
            border-color: #2563eb !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2) !important;
            outline: none;
        }

        .patient-card-divider {
            width: 1px;
            background: #cbd5e1;
            align-self: stretch;
            margin: 1px 0;
            flex-shrink: 0;
        }

        .patient-card-right {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .patient-info-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 48px;
            gap: 12px;
            width: 100%;
        }

        .patient-card-main-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 3px;
        }

        /* 患者情報カード右側：独立した電カルボタン ＆ 最終受診情報エリア */
        .patient-card-side-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            gap: 4px;
            flex-shrink: 0;
            padding-left: 12px;
            border-left: 1px dashed #cbd5e1;
            min-width: 145px;
        }

        .patient-info-row1 {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            line-height: 1.35;
        }

        .patient-card-name {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
        }

        .patient-card-kana {
            font-size: 10px;
            color: #64748b;
            font-weight: 500;
            margin-right: 2px;
        }

        .patient-card-id-badge {
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 10.5px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 10px;
            border: 1px solid #bfdbfe;
            white-space: nowrap;
        }

        .gender-tag {
            font-size: 10.5px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 3px;
            line-height: 1.1;
            white-space: nowrap;
        }
        .gender-male {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .gender-female {
            background: #fce7f3;
            color: #be185d;
            border: 1px solid #fbcfe8;
        }

        .age-box {
            display: inline-block;
            color: #ffffff !important;
            font-size: 10px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 3px;
            vertical-align: middle;
            white-space: nowrap;
            line-height: 1.15;
        }
        .age-box.age-senior { background-color: #ea580c !important; }
        .age-box.age-under  { background-color: #2563eb !important; }

        .patient-card-birthday {
            font-size: 11.5px;
            color: #334155;
            font-weight: 600;
            font-family: Consolas, "Liberation Mono", Menlo, monospace, sans-serif;
            white-space: nowrap;
        }

        .btn-karte-link-prominent {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 5px;
            border: 1px solid #0284c7;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 1px 3px rgba(2, 132, 199, 0.25);
            transition: all 0.15s;
            white-space: nowrap;
            line-height: 1.25;
        }
        .btn-karte-link-prominent:hover {
            background: linear-gradient(135deg, #0369a1, #075985);
            border-color: #075985;
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(2, 132, 199, 0.35);
        }

        .patient-last-visit-badge {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }

        .visit-badge {
            border-radius: 4px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }

        /* 本日受診の強調バッジ（赤系ハイライト） */
        .visit-today {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #f87171;
            font-size: 10.5px;
            font-weight: 800;
            padding: 1px 6px;
            letter-spacing: 0.3px;
            box-shadow: 0 1px 2px rgba(220, 38, 38, 0.2);
            animation: pulseToday 2.2s infinite ease-in-out;
        }
        @keyframes pulseToday {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }

        .visit-past {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            font-size: 10px;
            font-weight: 600;
            padding: 1px 6px;
        }

        .visit-none {
            color: #94a3b8;
            font-size: 9.5px;
        }

        .patient-info-row2 {
            margin-top: 3px;
            font-size: 11px;
            color: #334155;
            display: flex;
            align-items: center;
        }

        .patient-address-click {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 1px 6px;
            border-radius: 4px;
            transition: background 0.15s;
            position: relative;
        }
        .patient-address-click:hover {
            background: #e2e8f0;
            color: #0f172a;
            text-decoration: underline;
        }

        .patient-tel-click {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 1px 6px;
            border-radius: 4px;
            transition: background 0.15s;
            position: relative;
            margin-left: 8px;
        }
        .patient-tel-click:hover {
            background: #e2e8f0;
            color: #0f172a;
            text-decoration: underline;
        }

        .patient-empty-guide {
            font-size: 11.5px;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.4;
        }

        .patient-error-guide {
            font-size: 11.5px;
            color: #dc2626;
            display: flex;
            flex-direction: column;
            gap: 2px;
            line-height: 1.35;
        }

        .patient-loading-guide {
            font-size: 11.5px;
            color: #0284c7;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }

        /* ✏️ 手入力モードフォーム */
        .patient-manual-input-box {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 4px;
        }

        .manual-input-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 10px;
        }

        .manual-input-fields {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .manual-field-group {
            display: flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            font-weight: 600;
            color: #334155;
        }

        /* 住所・電話等のインラインコピー通知トースト */
        .inline-copy-toast {
            display: inline-block;
            background: #1e293b;
            color: #ffffff;
            font-size: 10px;
            padding: 1px 5px;
            border-radius: 3px;
            margin-left: 6px;
            animation: inlineFadeInOut 1.5s forwards;
        }
        @keyframes inlineFadeInOut {
            0% { opacity: 0; transform: translateY(-2px); }
            15% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }

        .remarks-editor-wrapper {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        /* --- Firefox 115等でのツールバー崩れ防止対応 CSS --- */
        #quill-toolbar {
            border: 1px solid #cbd5e1;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            padding: 3px 6px;
            background: #f8fafc;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            align-items: center;
            box-sizing: border-box;
        }
        #quill-toolbar .ql-formats {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            margin-right: 6px;
            flex-shrink: 0;
        }
        #quill-toolbar button, #quill-toolbar select {
            box-sizing: border-box;
            padding: 2px 4px;
            height: 24px;
            flex-shrink: 0;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            cursor: pointer;
        }
        #quill-toolbar select {
            font-size: 11px;
            height: 24px;
        }
        /* ---------------------------------------------------- */

        #quill-editor {
            flex: 1;
            min-height: 95px;
            max-height: 400px;
            overflow-y: auto;
            resize: vertical;
            border: 1px solid #cbd5e1;
            border-radius: 0 0 4px 4px;
            font-size: 11.5px;
            background: #fff;
            transition: min-height 0.2s ease-in-out;
        }
        #quill-editor.is-expanded {
            min-height: 260px !important;
        }
        .ql-editor { padding: 4px 6px; line-height: 1.35; }
        .ql-editor p { margin: 0; line-height: 1.35; }

        .mall-memo-container {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 6px;
            margin-top: 4px;
        }
        .mall-memo-block {
            background: #fafaf9;
            border: 1px solid #e7e5e4;
            border-radius: 4px;
            padding: 4px 6px;
            height: 98px;
            display: flex;
            flex-direction: column;
            transition: height 0.2s ease-in-out;
        }
        .mall-memo-block.is-expanded {
            height: 260px !important;
        }
        .mall-memo-header {
            font-size: 10px;
            font-weight: 700;
            color: #78716c;
            border-bottom: 1px solid #e7e5e4;
            padding-bottom: 2px;
            margin-bottom: 2px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .copy-trigger-title {
            cursor: pointer;
            padding: 1px 4px;
            border-radius: 3px;
            transition: background 0.15s, color 0.15s;
            user-select: none;
        }
        .copy-trigger-title:hover {
            background: #e2e8f0;
            color: #0284c7;
        }
        .mall-memo-btn-group {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .btn-memo-copy {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            font-size: 9px;
            color: #475569;
            padding: 1px 5px;
            cursor: pointer;
            line-height: 1.3;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-memo-copy:hover {
            background: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
        }
        /* ワンクリック備考・特記事項ペースト（転記）ボタン */
        .btn-memo-paste {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            border-radius: 3px;
            font-size: 9px;
            font-weight: 700;
            color: #047857;
            padding: 1px 5px;
            cursor: pointer;
            line-height: 1.3;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-memo-paste:hover {
            background: #10b981;
            border-color: #059669;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(16, 185, 129, 0.25);
        }
        .mall-memo-content {
            flex: 1;
            font-size: 10.5px;
            color: #292524;
            line-height: 1.35;
            overflow-y: auto;
            word-break: break-all;
            cursor: pointer;
            padding: 2px 3px;
            border-radius: 3px;
            transition: background-color 0.15s, box-shadow 0.15s;
        }
        .mall-memo-content:hover {
            background-color: #f0fdf4;
            box-shadow: inset 0 0 0 1px #bbf7d0;
        }

        /* ── 共通トースト通知スタイル (画面上部中央) ── */
        .copy-toast {
            position: fixed;
            top: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(-30px);
            background: rgba(15, 23, 42, 0.95);
            color: #ffffff;
            padding: 9px 20px;
            border-radius: 24px;
            font-size: 13px;
            font-weight: bold;
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s cubic-bezier(0.18, 0.89, 0.32, 1.28), opacity 0.25s ease;
            box-shadow: 0 4px 16px rgba(0,0,0,0.35);
            display: flex;
            align-items: center;
            gap: 8px;
            animation: none !important;
        }
        .copy-toast.show {
            opacity: 1 !important;
            transform: translateX(-50%) translateY(0) !important;
            pointer-events: auto;
        }

        .duplicate-alert-box {
            display: none;
            background: #fef2f2;
            border: 1.5px solid #dc2626;
            border-radius: 4px;
            padding: 4px 8px;
            margin-bottom: 6px;
            color: #991b1b;
            font-size: 11px;
            font-weight: 600;
        }

        .error-log-box {
            display: none;
            background: #fff1f2;
            border: 1.5px solid #e11d48;
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 8px;
            color: #9f1239;
            font-size: 11px;
        }
        .error-log-body {
            font-family: Consolas, monospace;
            background: #ffe4e6;
            padding: 6px;
            border-radius: 4px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 120px;
            overflow-y: auto;
        }

        .remarks-palette {
            display: none;
            position: fixed;
            top: 110px;
            right: 30px;
            width: 420px;
            height: 480px;
            min-width: 320px;
            min-height: 250px;
            max-width: 95vw;
            max-height: 90vh;
            background: #ffffff;
            border: 1.5px solid #3b82f6;
            border-radius: 8px;
            box-shadow: 0 12px 32px rgba(0,0,0,0.3);
            z-index: 2500;
            padding: 8px 10px;
            resize: both;
            overflow: hidden;
            box-sizing: border-box;
            flex-direction: column;
        }
        .palette-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 800;
            color: #1e40af;
            font-size: 11.5px;
            border-bottom: 1.5px solid #dbeafe;
            cursor: move;
            user-select: none;
            background: #f1f5f9;
            margin: -8px -10px 8px -10px;
            padding: 7px 10px;
            border-radius: 6px 6px 0 0;
            flex-shrink: 0;
        }
        .palette-tags-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 5px 8px;
            margin-bottom: 6px;
            flex-shrink: 0;
        }
        .palette-tag-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            user-select: none;
            transition: transform 0.1s ease;
        }
        .palette-tag-btn:hover {
            transform: translateY(-1px);
            filter: brightness(0.95);
        }
        .palette-tag-btn.tag-anti { background: #fee2e2; color: #b91c1c; border-color: #f87171; }
        .palette-tag-btn.tag-allergy { background: #fef3c7; color: #b45309; border-color: #fcd34d; }
        .palette-tag-btn.tag-infection { background: #ffe4e6; color: #be123c; border-color: #fda4af; }
        .palette-tag-btn.tag-cold { background: #e0f2fe; color: #0369a1; border-color: #7dd3fc; }

        /* 胃腸科専用: 特殊枠コメント（エコー検査予定） */
        .palette-echo-box {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 6px;
            padding: 5px 8px;
            margin-bottom: 6px;
            flex-shrink: 0;
        }
        .palette-echo-btn {
            background-color: #166534;
            color: #ff4d4f;
            font-weight: 800;
            border: 1px solid #14532d;
            padding: 3px 9px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            user-select: none;
            transition: all 0.12s ease;
        }
        .palette-echo-btn:hover {
            background-color: #15803d;
            color: #ffffff;
            border-color: #166534;
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.25);
        }
        .palette-echo-btn:active {
            transform: translateY(0);
        }

        .palette-list-box {
            flex: 1;
            min-height: 120px;
            overflow-y: auto;
            display: flex;
            flex-wrap: wrap;
            align-content: flex-start;
            gap: 5px;
            padding: 6px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #f8fafc;
            margin-bottom: 6px;
        }
        .phrase-chip {
            display: inline-flex;
            align-items: center;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            padding: 2px 5px;
            font-size: 11px;
            font-weight: 600;
            color: #1e293b;
            user-select: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            transition: all 0.15s ease;
        }
        .phrase-chip:hover {
            border-color: #3b82f6;
            background: #eff6ff;
            box-shadow: 0 2px 4px rgba(59,130,246,0.15);
        }
        .chip-insert-btn {
            cursor: pointer;
            padding: 1px 3px;
        }
        .chip-insert-btn:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }
        .chip-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 17px;
            height: 17px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 10px;
            color: #64748b;
            border: none;
            background: transparent;
            padding: 0;
            margin-left: 2px;
            transition: all 0.1s ease;
        }
        .chip-action-btn:hover {
            background: #cbd5e1;
            color: #0f172a;
        }
        .chip-action-btn.btn-del:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        /* 医療安全アラート意識的設定エリア */
        .medical-alert-control-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px 10px;
            margin-top: 8px;
        }
        .alert-checks-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }
        .alert-toggle-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
            user-select: none;
            transition: all 0.15s ease;
        }
        .alert-toggle-label:hover {
            filter: brightness(0.96);
        }
        .alert-toggle-label input[type="checkbox"] {
            margin: 0;
            cursor: pointer;
            width: 14px;
            height: 14px;
        }
        .alert-toggle-label.alert-anti:has(input:checked),
        .alert-toggle-label.alert-anti.is-checked {
            background: #fee2e2;
            border-color: #ef4444;
            color: #b91c1c;
        }
        .alert-toggle-label.alert-diabetes:has(input:checked),
        .alert-toggle-label.alert-diabetes.is-checked {
            background: #ede9fe;
            border-color: #8b5cf6;
            color: #6d28d9;
        }
        .alert-toggle-label.alert-psychotropic:has(input:checked),
        .alert-toggle-label.alert-psychotropic.is-checked {
            background: #f3e8ff;
            border-color: #a855f7;
            color: #7e22ce;
        }
        .alert-toggle-label.alert-nsaids:has(input:checked),
        .alert-toggle-label.alert-nsaids.is-checked {
            background: #ffedd5;
            border-color: #f97316;
            color: #c2410c;
        }
        .alert-toggle-label.alert-allergy:has(input:checked),
        .alert-toggle-label.alert-allergy.is-checked {
            background: #fef3c7;
            border-color: #f59e0b;
            color: #b45309;
        }
        .alert-toggle-label.alert-infection:has(input:checked),
        .alert-toggle-label.alert-infection.is-checked {
            background: #ffe4e6;
            border-color: #f43f5e;
            color: #be123c;
        }
        .alert-toggle-label.alert-cold:has(input:checked),
        .alert-toggle-label.alert-cold.is-checked {
            background: #e0f2fe;
            border-color: #0ea5e9;
            color: #0369a1;
        }
        .alert-toggle-label.alert-sedation:has(input:checked),
        .alert-toggle-label.alert-sedation.is-checked {
            background: #f5f3ff;
            border-color: #8b5cf6;
            color: #6d28d9;
        }
        .alert-toggle-label.alert-drive:has(input:checked),
        .alert-toggle-label.alert-drive.is-checked {
            background: #fffbeb;
            border-color: #d97706;
            color: #b45309;
        }
        .alert-toggle-label.alert-admission:has(input:checked),
        .alert-toggle-label.alert-admission.is-checked {
            background: #dbeafe !important;
            border-color: #2563eb !important;
            color: #1d4ed8 !important;
            font-weight: 700 !important;
        }

        /* 削除理由選択モーダル */
        .delete-reason-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(2px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .delete-reason-overlay.active {
            display: flex;
        }
        .delete-reason-modal {
            background: #ffffff;
            width: 100%;
            max-width: 480px;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25), 0 8px 10px -6px rgba(0,0,0,0.2);
            overflow: hidden;
            animation: deleteModalPop 0.18s ease-out;
        }
        @keyframes deleteModalPop {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .delete-reason-header {
            background: #fee2e2;
            border-bottom: 1px solid #fecaca;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .delete-reason-title {
            font-size: 14.5px;
            font-weight: 800;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .delete-reason-body {
            padding: 18px;
        }
        .delete-reason-opt-btn {
            width: 100%;
            text-align: left;
            padding: 10px 14px;
            margin-bottom: 10px;
            border-radius: 8px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.15s ease;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .delete-reason-opt-btn:hover {
            border-color: #3b82f6;
            background: #eff6ff;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .delete-opt-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .delete-opt-desc {
            font-size: 11px;
            color: #64748b;
        }
        .delete-opt-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
        }
    </style>
    <!-- 🖨️ 完全ローカル・オフライン動作 特大Flatpickr -->
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css?v=<?= filemtime(__DIR__ . '/assets/css/onokai_flatpickr.css') ?>">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js?v=<?= filemtime(__DIR__ . '/assets/js/onokai_flatpickr.js') ?>"></script>
</head>
<body>

<div class="main-card">
    <div class="header-bar">
        <div class="header-title-area">
            <span class="dept-badge <?= $dept ?>"><?= $dept === 'PROCT' ? '肛門科' : '胃腸科' ?></span>
            <h2><?= $schedule_id > 0 ? '予約編集' : '新規予約登録' ?></h2>
            <?php if ($debug_mode): ?>
                <span class="schedule-id-badge">[schedule_id:#<?= $schedule_id > 0 ? $schedule_id : '新規' ?>]</span>
            <?php endif; ?>
        </div>
        <!-- 🕒 レコード作成・更新日時表示（最上段空エリア） -->
        <div class="header-record-meta">
            <?php if ($schedule_id > 0): ?>
                <span>🕒 登録: <?= !empty($record_created_at_fmt) ? htmlspecialchars($record_created_at_fmt, ENT_QUOTES, 'UTF-8') : '---' ?></span>
                <span class="meta-sep">|</span>
                <span>更新: <?= !empty($record_updated_at_fmt) ? htmlspecialchars($record_updated_at_fmt, ENT_QUOTES, 'UTF-8') : (!empty($record_created_at_fmt) ? htmlspecialchars($record_created_at_fmt, ENT_QUOTES, 'UTF-8') : '---') ?></span>
            <?php else: ?>
                <span class="meta-new">🕒 新規予約作成</span>
            <?php endif; ?>
        </div>
        <div class="btn-group-top">
            <?php if ($schedule_id > 0): ?>
                <button type="button" class="btn btn-danger" onclick="deleteReservation(<?= $schedule_id ?>)">🗑️ 削除</button>
            <?php endif; ?>
            <button type="button" class="btn btn-purple" onclick="saveReservation(true)">🖨️ 保存して印刷</button>
            <button type="button" class="btn btn-primary" onclick="saveReservation(false)">💾 保存する</button>
            <button type="button" class="btn btn-secondary" onclick="closeModalWindow(false)">✕ 閉じる</button>
        </div>
    </div>

    <div id="duplicateAlert" class="duplicate-alert-box"></div>

    <div id="errorLogBox" class="error-log-box">
        <div style="font-weight:bold; margin-bottom:4px; display:flex; justify-content:space-between;">
            <span>🚨 処理エラー詳細 (デバッグログ)</span>
            <button type="button" onclick="document.getElementById('errorLogBox').style.display='none'" style="border:none; background:none; cursor:pointer; font-weight:bold;">✕</button>
        </div>
        <div id="errorLogContent" class="error-log-body"></div>
    </div>

    <form id="editForm" novalidate>
        <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
        <input type="hidden" name="department_code" id="f_dept" value="<?= htmlspecialchars($dept, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="remarks" id="f_remarks" value="<?= htmlspecialchars($raw_remarks, ENT_QUOTES, 'UTF-8') ?>">

        <!-- 上段：基本情報 + 備考（食い込み解消・ワンクリック拡縮対応） -->
        <div class="grid-2col">
            <div class="panel-box">
                <div class="panel-header">📌 基本情報</div>
                <div class="form-row" style="align-items: center;">
                    <label for="exam_date" class="form-label">検査日 <span style="color:#e11d48;" aria-hidden="true">*</span></label>
                    <input type="text" name="exam_date" id="exam_date" class="onokai-date-picker" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?>" required aria-required="true" autocomplete="off" onchange="handleDateChange()">
                    <span id="day_of_week_label" style="display:none;"></span>
                </div>
                <div class="form-row">
                    <label for="scheduled_time" class="form-label">時間枠 <span style="color:#e11d48;" aria-hidden="true">*</span></label>
                    <select name="scheduled_time" id="scheduled_time" class="form-control" style="flex:1;" required aria-required="true" onchange="handleWakuChange(this.value)">
                        <option value="">-- 選択 --</option>
                        <?php foreach ($waku_list as $w): 
                            $w_name = (string)$w['waku_name']; 
                            $is_susp = !empty($w['is_suspended']);
                            $is_extra = (!empty($w['is_override']) && empty($w['base_waku_id']));
                            $is_legacy = !empty($w['is_legacy_fallback']);
                            $is_selected = ($w_name === $waku);
                            $badge_str = '';
                            if ($is_susp) {
                                $badge_str = ' [🚫休止中]';
                            } elseif ($is_extra) {
                                $badge_str = ' [臨時枠]';
                            } elseif ($is_legacy) {
                                $badge_str = ' [旧枠/指定枠]';
                            }

                            $slot_res = $w['reservations'] ?? [];
                            $patient_labels = [];
                            $has_other_res = false;
                            $has_self_res = false;
                            foreach ($slot_res as $sr) {
                                $sr_sid = (int)($sr['schedule_id'] ?? 0);
                                $sr_pname = trim((string)($sr['patient_name'] ?? ''));
                                if ($sr_pname === '') {
                                    $sr_pname = 'ID:' . ($sr['patient_id'] ?? '?');
                                }
                                $sr_doc = trim((string)($sr['doctor_name'] ?? ''));
                                $doc_part = ($sr_doc !== '' && $sr_doc !== '◆未定◆') ? "({$sr_doc})" : '';
                                if ($schedule_id > 0 && $sr_sid === $schedule_id) {
                                    $has_self_res = true;
                                    $patient_labels[] = "★{$sr_pname}{$doc_part}";
                                } else {
                                    $has_other_res = true;
                                    $patient_labels[] = "{$sr_pname}{$doc_part}";
                                }
                            }
                            $patient_suffix = !empty($patient_labels) ? ' 【' . implode('・', $patient_labels) . '】' : '';

                            $opt_style = '';
                            if ($is_susp && !$is_selected) {
                                $opt_style = 'color:#94a3b8; background-color:#fef2f2;';
                            } elseif ($has_other_res) {
                                $opt_style = 'background-color:#fffbeb; color:#92400e; font-weight:600;';
                            } elseif ($has_self_res) {
                                $opt_style = 'background-color:#dbeafe; color:#1e40af; font-weight:700;';
                            }
                        ?>
                            <option value="<?= htmlspecialchars($w_name, ENT_QUOTES, 'UTF-8') ?>" 
                                <?= $is_selected ? 'selected' : '' ?>
                                <?= ($is_susp && !$is_selected) ? 'disabled' : '' ?>
                                <?= $opt_style !== '' ? 'style="' . $opt_style . '"' : '' ?>>
                                <?= htmlspecialchars($w_name . $badge_str . $patient_suffix, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="department_code_select" class="form-label">診療科 <span style="color:#e11d48;" aria-hidden="true">*</span></label>
                    <select name="department_code_select" id="department_code_select" class="form-control" style="flex:1;" required aria-required="true" onchange="changeDept(this.value)">
                        <option value="PROCT" <?= $dept==='PROCT'?'selected':'' ?>>🟢 肛門科 (PROCT)</option>
                        <option value="GASTRO" <?= $dept==='GASTRO'?'selected':'' ?>>🔵 胃腸科 (GASTRO)</option>
                    </select>
                </div>
                <div id="dayCapacitySummaryBar" style="margin-top:4px; padding:4px 7px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:5px; font-size:10.5px; color:#334155; line-height:1.45; display:none;"></div>
            </div>

            <div class="panel-box">
                <div class="panel-header">
                    <span>📝 備考・特記事項</span>
                    <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
                        <button type="button" class="btn-expand-toggle" onclick="toggleRemarksExpand(this)" title="備考入力欄の拡大・縮小" aria-label="備考入力欄の拡大・縮小">↕ 拡大</button>
                        <button type="button" class="btn btn-teal" style="padding:1px 6px; font-size:11px; height:20px;" onclick="toggleRemarksPalette()" aria-label="定型文パレットを開く">📋 定型文</button>
                    </div>
                </div>
                <div class="remarks-editor-wrapper">
                    <div id="quill-toolbar">
                        <button class="ql-bold" title="太字" aria-label="太字"></button>
                        <button class="ql-italic" title="斜体" aria-label="斜体"></button>
                        <select class="ql-color" title="文字色" aria-label="文字色">
                            <option selected></option>
                            <option value="#dc2626"></option>
                            <option value="#e11d48"></option>
                            <option value="#d97706"></option>
                            <option value="#16a34a"></option>
                            <option value="#0284c7"></option>
                            <option value="#2563eb"></option>
                            <option value="#9333ea"></option>
                            <option value="#475569"></option>
                        </select>
                        <select class="ql-background" title="蛍光ペン（網掛け）" aria-label="背景蛍光色">
                            <option selected></option>
                            <option value="#fef08a"></option>
                            <option value="#fed7aa"></option>
                            <option value="#bbf7d0"></option>
                            <option value="#e0f2fe"></option>
                            <option value="#fce7f3"></option>
                            <option value="#f3e8ff"></option>
                            <option value="#fee2e2"></option>
                            <option value="#f1f5f9"></option>
                        </select>
                        <button class="ql-list" value="bullet" title="箇条書き" aria-label="箇条書き"></button>
                        <button class="ql-clean" title="書式クリア" aria-label="書式クリア"></button>
                    </div>
                    <div id="quill-editor" role="textbox" aria-multiline="true" aria-label="備考特記事項エディタ"><?= $raw_remarks ?></div>
                </div>
            </div>
        </div>

        <!-- 中段：患者情報・検査割当 -->
        <div class="panel-box">
            <div class="panel-header" style="justify-content: flex-start; gap: 8px;">
                <span id="vipHeaderTitle" ondblclick="revealVipCheckbox()" style="cursor:default; user-select:none;" title="ダブルクリックで注目検査（🅅）設定を表示">👤 患者情報・検査割当</span>
                <span id="vipContainer" style="display:<?= $p_is_vip ? 'inline-flex' : 'none' ?>; align-items:center; gap:4px; vertical-align:middle; background:#fef9c3; border:1px solid #eab308; padding:1px 6px; border-radius:4px; margin-left:6px;">
                    <label style="display:inline-flex; align-items:center; gap:4px; cursor:pointer; font-size:11px; font-weight:700; color:#1e293b; margin:0; user-select:none;">
                        <input type="hidden" name="is_vip" value="0">
                        <input type="checkbox" name="is_vip" id="is_vip" value="1" <?= $p_is_vip ? 'checked' : '' ?> onchange="updateVipBadgePreview()" style="cursor:pointer; width:13px; height:13px; accent-color:#7e22ce;">
                        <span class="vip-badge-icon" style="display:inline-block; background:#fde047; color:#6b21a8; border:1.5px solid #000000; font-weight:bold; font-size:10px; padding:0 3px; border-radius:2px; line-height:1.2;">V</span>
                        <span>注目検査</span>
                    </label>
                </span>
            </div>

            <!-- hiddenフィールド群（フォーム送信互換性を100%維持） -->
            <input type="hidden" name="patient_name" id="patient_name" value="<?= htmlspecialchars($p_name_initial, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="patient_kana" id="patient_kana" value="<?= htmlspecialchars($p_kana_initial, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="birthday" id="birthday" value="<?= htmlspecialchars($p_bday_initial, ENT_QUOTES, 'UTF-8') ?>">

            <!-- 👤 患者情報カードエリア（画像2デザイン準拠） -->
            <div class="patient-card-container">
                <!-- 左側：患者No入力・同期＆操作アクション -->
                <div class="patient-sync-left">
                    <div class="patient-sync-title">
                        <span>🔍 患者No (カルテ同期)</span>
                    </div>
                    <div class="patient-sync-input-row">
                        <input type="text" name="patient_id" id="patient_id" class="form-control patient-id-input" 
                               value="<?= htmlspecialchars($p_id_initial, ENT_QUOTES, 'UTF-8') ?>" 
                               placeholder="患者No" aria-label="患者No" <?= empty($p_id_initial) ? 'autofocus' : '' ?>
                               onkeydown="if(event.key==='Enter'||event.keyCode===13){event.preventDefault();doSyncPatient();}"
                               onclick="showVirtualKeyboard()"
                               autocomplete="off">
                        <button type="button" id="btnToggleKeyboard" class="btn btn-keyboard-toggle" onclick="toggleVirtualKeyboard(event)" title="ソフトウェアキーボード（テンキー）の開閉">⌨️</button>
                        <button type="button" id="btnSyncPatient" class="btn btn-sync" onclick="doSyncPatient()" title="MALL3から患者情報を同期">同期</button>
                    </div>

                    <!-- ⌨️ 患者No ソフトウェアテンキー（タブレット・マウス対応） -->
                    <div id="patientVirtualKeyboard" class="virtual-numpad-popover" style="display:none;" onclick="event.stopPropagation()">
                        <div class="numpad-header">
                            <span class="numpad-title">⌨️ 患者No テンキー</span>
                            <button type="button" class="numpad-close-btn" onclick="closeVirtualKeyboard()" title="閉じる">✕</button>
                        </div>
                        <div class="numpad-grid">
                            <button type="button" class="numpad-key" onclick="pressNumpad('7')">7</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('8')">8</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('9')">9</button>
                            <button type="button" class="numpad-key numpad-action numpad-bs" onclick="pressNumpad('BS')" title="1文字消去">⌫</button>

                            <button type="button" class="numpad-key" onclick="pressNumpad('4')">4</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('5')">5</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('6')">6</button>
                            <button type="button" class="numpad-key numpad-action numpad-clr" onclick="pressNumpad('CLEAR')" title="全消去">C</button>

                            <button type="button" class="numpad-key" onclick="pressNumpad('1')">1</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('2')">2</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('3')">3</button>
                            <button type="button" class="numpad-key numpad-action numpad-sync" onclick="pressNumpad('SYNC')" title="同期実行（確定）">🔍同期</button>

                            <button type="button" class="numpad-key" onclick="pressNumpad('0')">0</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('00')">00</button>
                            <button type="button" class="numpad-key" onclick="pressNumpad('-')">-</button>
                        </div>
                    </div>

                    <div class="patient-action-links">
                        <button type="button" class="btn-sub-action" onclick="openPatientSearch()" title="電子カルテ患者検索子画面を開く">🔍 氏名検索</button>
                        <button type="button" class="btn-sub-action btn-sub-temp" onclick="setTemporaryPatient()" title="仮予約を設定">⚡ 仮予約</button>
                        <button type="button" class="btn-sub-action" onclick="toggleManualInput(true)" title="患者Noがない場合の直接手入力">✏️ 手入力</button>
                        <button type="button" class="btn-sub-action btn-sub-canvas" onclick="openCanvasTimelineFromModal()" title="この患者のRS-Base検査実績タイムライン・キャンバスを開く" style="background:#e0e7ff; color:#3730a3; border-color:#c7d2fe; font-weight:700;">🗺️ 検査実績</button>
                    </div>
                </div>

                <!-- 縦の区切りバー -->
                <div class="patient-card-divider"></div>

                <!-- 右側：患者情報カード（通常表示モード） -->
                <div class="patient-card-right" id="patientCardDisplay">
                    <div id="patientInfoContent" class="patient-info-content">
                        <?php if (!empty($p_name_initial)): ?>
                            <div class="patient-card-main-info">
                                <div class="patient-info-row1">
                                    <span class="patient-card-name"><?= htmlspecialchars($p_name_initial, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($p_kana_initial)): ?>
                                        <span class="patient-card-kana">(<?= htmlspecialchars($p_kana_initial, ENT_QUOTES, 'UTF-8') ?>)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p_id_initial)): ?>
                                        <span class="patient-card-id-badge">No: <?= htmlspecialchars($p_id_initial, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <span id="cardGenderTag"></span>
                                    <?php if ($p_age_int !== null): ?>
                                        <span style="color:#94a3b8;">/</span>
                                        <span class="age-box <?= $p_age_cls ?>"><?= $p_age_int ?>歳</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p_wareki_initial)): ?>
                                        <span style="color:#94a3b8;">/</span>
                                        <span class="patient-card-birthday">(生年月日: <?= htmlspecialchars($p_wareki_initial, ENT_QUOTES, 'UTF-8') ?>)</span>

                                    <?php endif; ?>
                                </div>
                                <div class="patient-info-row2">
                                    <span class="patient-address-click" id="cardAddressDisplay" onclick="copyPatientAddress(this)" title="クリックして住所をコピー">
                                        🏠 住所: <span style="color:#94a3b8;">(照会中...)</span>
                                    </span>
                                    <span class="patient-tel-click" id="cardTelDisplay" onclick="copyPatientTel(this)" title="クリックして電話番号をコピー">
                                        📞 <span style="color:#94a3b8;">(照会中...)</span>
                                    </span>
                                </div>
                            </div>
                            <!-- 右側独立アクション（電カルボタン＆最終受診日・科） -->
                            <div class="patient-card-side-actions" id="cardSideActions">
                                <button type="button" class="btn btn-karte-link-prominent" onclick="openMallSoapViewer()" title="Webカルテビューアを表示">📄 電カル ↗</button>
                                <div class="patient-last-visit-badge" id="cardLastVisitBadge" title="電子カルテ上の最終受診実績">
                                    <span class="visit-badge visit-none">照会中...</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="patient-empty-guide">
                                <span>👈 左側で <strong>患者No</strong> を入力して <strong>[同期]</strong> または <strong>Enter</strong> を押してください。</span>
                                <span style="color:#64748b; font-size:10.5px;">（仮の予約は [⚡ 仮予約]、直接入力は [✏️ 手入力]）</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 右側：手入力フォーム（手入力・仮予約モード時） -->
                <div class="patient-manual-input-box" id="patientManualInputBox" style="display:none;">
                    <div class="manual-input-header">
                        <span style="font-weight:700; color:#1e293b; font-size:11px;">✏️ 手動患者情報入力 <span style="font-weight:normal; color:#64748b;">(カルテ同期を行わず登録)</span></span>
                        <button type="button" class="btn-sub-action" onclick="finishManualInput()" style="background:#e0f2fe; color:#0369a1; border-color:#7dd3fc; font-weight:700;">✓ 入力完了（カード表示に戻る）</button>
                    </div>
                    <div class="manual-input-fields">
                        <div class="manual-field-group">
                            <label for="manual_patient_name">氏名<span style="color:#ef4444;">*</span>:</label>
                            <input type="text" id="manual_patient_name" class="form-control" style="width:130px; height:27px; font-size:12px;" placeholder="患者氏名" value="<?= htmlspecialchars($p_name_initial, ENT_QUOTES, 'UTF-8') ?>" oninput="syncManualToHidden()">
                        </div>
                        <div class="manual-field-group">
                            <label for="manual_patient_kana">カナ:</label>
                            <input type="text" id="manual_patient_kana" class="form-control" style="width:130px; height:27px; font-size:12px;" placeholder="フリガナ" value="<?= htmlspecialchars($p_kana_initial, ENT_QUOTES, 'UTF-8') ?>" oninput="syncManualToHidden()">
                        </div>
                        <div class="manual-field-group">
                            <label for="manual_birthday">生年月日:</label>
                            <input type="text" id="manual_birthday" class="form-control onokai-date-picker" data-no-pill="true" style="width:125px; height:27px; font-size:12px;" value="<?= htmlspecialchars($p_bday_initial, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" onchange="syncManualToHidden()">
                            <span id="manualAgeBadge"></span>

                        </div>
                    </div>
                </div>
            </div>

            <div class="attr-flex-row">
                <div style="display:inline-flex; align-items:center; gap:3px;">
                    <select name="doctor_name" id="doctor_name" class="form-control" style="width:115px;" data-initial-doctor="<?= htmlspecialchars($curr_doctor, ENT_QUOTES, 'UTF-8') ?>" onchange="checkDuplicateAndLimits(); renderDayCapacitySummaryBar();" aria-label="担当医師">
                        <option value="">-- 担当未定 --</option>
                        <?php if (!empty($curr_doctor)): ?>
                            <option value="<?= htmlspecialchars($curr_doctor, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($curr_doctor, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endif; ?>
                    </select>
                    <button type="button" id="btnToggleAllDoctors" class="btn" style="padding:2px 5px; font-size:10px; height:28px; line-height:1; white-space:nowrap; border-radius:4px; background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; font-weight:600;" onclick="toggleAllDoctorsMode()" title="クリックで全医師（他科・非出勤含む）と当日枠あり医師を切り替えます">
                        👥 全員
                    </button>
                </div>

                <select name="exam_type" id="exam_type" class="form-control" style="width:110px;" onchange="handleExamTypeChange(this.value)" aria-label="検査種別">
                    <option value="">-- 検査種別 --</option>
                    <?php foreach ($exam_types_master as $etype): ?>
                        <option value="<?= htmlspecialchars($etype, ENT_QUOTES, 'UTF-8') ?>" <?= ($curr_exam === $etype) ? 'selected' : '' ?>><?= htmlspecialchars($etype, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="arrival_time" id="arrival_time" class="form-control" style="width:100px;" value="<?= htmlspecialchars($curr_arrival, ENT_QUOTES, 'UTF-8') ?>" placeholder="来院時刻" aria-label="来院時刻">

                <select name="exam_location" id="exam_location" class="form-control" style="width:100px;" aria-label="実施場所">
                    <option value="">-- 実施場所 --</option>
                    <?php 
                    $has_curr_loc = false;
                    foreach ($exam_locations_master as $loc): 
                        if ($curr_loc === $loc) $has_curr_loc = true;
                    ?>
                        <option value="<?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?>" <?= ($curr_loc === $loc) ? 'selected' : '' ?>><?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <?php if (!empty($curr_loc) && !$has_curr_loc): ?>
                        <option value="<?= htmlspecialchars($curr_loc, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($curr_loc, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endif; ?>
                </select>

                <select name="room_number" id="room_number" class="form-control" style="width:130px; background:#eff6ff; border-color:#93c5fd; font-weight:600; <?= $is_tcf_like_initial ? '' : 'display:none;' ?>" aria-label="前処置室・マイトイレ">
                    <option value="">🚪 部屋: 未指定</option>
                    <?php foreach ($rooms_master as $rm): ?>
                        <option value="<?= htmlspecialchars($rm, ENT_QUOTES, 'UTF-8') ?>" <?= ($curr_room === $rm) ? 'selected' : '' ?>>🚪 <?= htmlspecialchars($rm, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="bowel_prep" id="bowel_prep" class="form-control" style="flex:1; min-width:120px; background:#f0fdf4; border-color:#86efac; font-weight:600; <?= $is_tcf_like_initial ? '' : 'display:none;' ?>" aria-label="前処置下剤">
                    <option value="">💊 下剤: 未指定</option>
                    <?php foreach ($preps_master as $prep): ?>
                        <option value="<?= htmlspecialchars($prep, ENT_QUOTES, 'UTF-8') ?>" <?= ($curr_prep === $prep) ? 'selected' : '' ?>>💊 <?= htmlspecialchars($prep, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 🛡️ 医療安全アラート意識的設定エリア -->
            <div class="medical-alert-control-box">
                <input type="hidden" name="manual_alert_set" value="1">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span style="font-weight:700; color:#334155; font-size:11px;">🛡️ 医療安全・検査安全設定 <span style="font-size:10px; font-weight:normal; color:#64748b;">(スタッフによる意識的ON/OFF)</span></span>
                        <button type="button" id="btnQueryOnshiEdit" onclick="queryOnshiForEditModal()" style="display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:4px; border:1px solid #3b82f6; background:#eff6ff; color:#1d4ed8; font-size:10.5px; font-weight:700; cursor:pointer; transition:all 0.15s;" title="オンライン資格確認（薬剤情報・リスク患者判定API）から最新の処方歴・リスク薬を簡易照会して自動チェックします">
                            <span id="btnQueryOnshiEditIcon">🌐</span>
                            <span id="btnQueryOnshiEditText">オン資照会</span>
                        </button>
                        <button type="button" id="btnOpenMedicalAlertDetail" onclick="openMedicalAlertFromEditModal()" style="display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:4px; border:1px solid #0f766e; background:#f0fdfa; color:#0f766e; font-size:10.5px; font-weight:700; cursor:pointer; transition:all 0.15s;" title="医療安全アラート判定モーダル（modal_medical_alert.php）を開き、全処方歴・カルテメモ根拠を詳細確認しながら判定・反映します">
                            <span>🛡️ 詳細判定</span>
                        </button>
                    </div>
                    <span style="font-size:10px; color:#64748b;">※保存時に予約・メモと連動して記録されます</span>
                </div>
                <div class="alert-checks-row">
                    <label class="alert-toggle-label alert-anti <?= !empty($current_alert['has_antithrombotic']) ? 'is-checked' : '' ?>" title="抗血栓薬（バイアスピリン/イグザレルト等）内服中の患者。要休薬確認">
                        <input type="checkbox" name="has_antithrombotic" id="has_antithrombotic" value="1" <?= !empty($current_alert['has_antithrombotic']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>💊 抗血栓薬 (要休薬確認)</span>
                    </label>

                    <label class="alert-toggle-label alert-diabetes <?= !empty($current_alert['has_diabetes']) ? 'is-checked' : '' ?>" title="糖尿病薬（インスリン・DPP-4阻害薬・SGLT2等）内服中の患者。検査当日の休薬・低血糖注意">
                        <input type="checkbox" name="has_diabetes" id="has_diabetes" value="1" <?= !empty($current_alert['has_diabetes']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>💉 糖尿病薬 (低血糖注意)</span>
                    </label>

                    <label class="alert-toggle-label alert-psychotropic <?= !empty($current_alert['has_psychotropic']) ? 'is-checked' : '' ?>" title="向精神薬・睡眠薬（ベンゾジアゼピン系・抗不安薬等）内服中の患者。鎮静時の相互作用・呼吸抑制注意">
                        <input type="checkbox" name="has_psychotropic" id="has_psychotropic" value="1" <?= !empty($current_alert['has_psychotropic']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>🧠 向精神薬・睡眠薬 (鎮静注意)</span>
                    </label>

                    <label class="alert-toggle-label alert-nsaids <?= !empty($current_alert['has_nsaids']) ? 'is-checked' : '' ?>" title="NSAIDs（ロキソニン等消炎鎮痛薬）常用患者。潰瘍・出血リスク注意">
                        <input type="checkbox" name="has_nsaids" id="has_nsaids" value="1" <?= !empty($current_alert['has_nsaids']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>💊 NSAIDs消炎鎮痛 (潰瘍/出血)</span>
                    </label>

                    <label class="alert-toggle-label alert-allergy <?= !empty($current_alert['has_allergy']) ? 'is-checked' : '' ?>" title="キシロカイン等の局所麻酔薬や薬剤アレルギーがある患者">
                        <input type="checkbox" name="has_allergy" id="has_allergy" value="1" <?= !empty($current_alert['has_allergy']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>⚠️ 局麻・アレルギー注意</span>
                    </label>

                    <label class="alert-toggle-label alert-infection <?= !empty($current_alert['has_infection']) ? 'is-checked' : '' ?>" title="B型・C型肝炎、梅毒等の感染症陽性患者。防護具・専用洗浄要">
                        <input type="checkbox" name="has_infection" id="has_infection" value="1" <?= !empty($current_alert['has_infection']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>🔴 感染症注意</span>
                    </label>

                    <label class="alert-toggle-label alert-cold <?= !empty($current_alert['has_cold_fever']) ? 'is-checked' : '' ?>" title="当日発熱や風邪症状（咳・咽頭痛・有熱等）がある患者">
                        <input type="checkbox" name="has_cold_fever" id="has_cold_fever" value="1" <?= !empty($current_alert['has_cold_fever']) ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>😷 風邪症状・発熱</span>
                    </label>

                    <label class="alert-toggle-label alert-sedation <?= $has_sedation_initial ? 'is-checked' : '' ?>" id="labelSedationToggle" style="<?= $is_echo_initial ? 'display:none;' : '' ?>" title="鎮静剤（<?= htmlspecialchars($sedation_drug_desc, ENT_QUOTES, 'UTF-8') ?>）の使用希望。進捗モニタのリカバリ動線と連動">
                        <input type="checkbox" name="has_sedation" id="has_sedation" value="1" <?= $has_sedation_initial ? 'checked' : '' ?> onchange="handleSedationChange(this)">
                        <span id="sedationToggleText">💉 鎮静希望 (注あり:<?= htmlspecialchars($sedation_drug_name, ENT_QUOTES, 'UTF-8') ?>)</span>
                    </label>

                    <label class="alert-toggle-label alert-drive <?= $has_drive_initial ? 'is-checked' : '' ?>" id="labelDriveToggle" style="<?= $is_echo_initial ? 'display:none;' : '' ?>" title="自家用車を自分で運転して来院していないかの確認（鎮静時は運転禁止・要家族送迎）">
                        <input type="checkbox" name="has_drive_caution" id="has_drive_caution" value="1" <?= $has_drive_initial ? 'checked' : '' ?> onchange="this.parentElement.classList.toggle('is-checked', this.checked)">
                        <span>🚗 自主運転注意 (要送迎確認)</span>
                    </label>
                    </span>
                </div>
                <!-- 胃がん検診での鎮静剤使用警告バッジ -->
                <div id="gastricSedationWarning" style="<?= ($is_gastric_screening_initial && $has_sedation_initial) ? 'display:block;' : 'display:none;' ?> margin-top:5px; padding:4px 10px; background:#fff1f2; border:1px solid #fecdd3; border-radius:4px; color:#be123c; font-size:11px; font-weight:700;">
                    ⚠️【胃がん検診】市町村契約により原則鎮静剤不使用です（※医師の指示・自費確認がある場合のみ許可）
                </div>
                <!-- 電カル・メモ検知ヒントエリア -->
                <div id="alertDetectHint" style="display:none; margin-top:5px; font-size:10.5px; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; border-radius:4px; padding:3px 8px;"></div>
                <!-- オン資照会結果・処方ヒントエリア -->
                <div id="onshiDetectHint" style="display:none; margin-top:5px; font-size:10.5px; color:#1e40af; background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px; padding:4px 8px;"></div>
            </div>
        </div>

        
        <!-- 中下段：病棟管理システム連動用カテゴリ（コンパクト1行デザイン） -->
        <div class="panel-box" id="panelWardIntegration" style="<?= ($is_w_or_tcf_initial || $is_polypep_initial) ? '' : 'display:none;' ?> margin-bottom:8px; background:#f0fdf4; border:1px solid #86efac; border-radius:5px; padding:6px 12px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="font-weight:700; color:#166534; font-size:11.5px; white-space:nowrap;">🏥 病棟管理システム連動用:</span>
                    <label id="labelAdmissionToggle" style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; background:<?= $has_admission_initial ? '#dcfce7' : '#ffffff' ?>; border:1px solid <?= $has_admission_initial ? '#16a34a' : '#86efac' ?>; padding:3px 10px; border-radius:4px; font-size:12px; font-weight:700; color:<?= $has_admission_initial ? '#15803d' : '#166534' ?>; margin:0; user-select:none; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                        <input type="checkbox" name="is_admission" id="is_admission" value="1" <?= $has_admission_initial ? 'checked' : '' ?> onchange="handleAdmissionChange(this)" style="width:15px; height:15px; cursor:pointer; accent-color:#16a34a; margin:0;">
                        <span id="admissionToggleText"><?= $is_polypep_initial ? '入院予定 (ポリペク確定)' : '入院予定' ?></span>
                    </label>
                    <span id="admissionSuggestBadge" style="<?= !empty($admission_suggest_reason) ? 'display:inline-block;' : 'display:none;' ?> font-size:10.5px; padding:1px 6px; border-radius:3px; background:#dcfce7; color:#15803d; border:1px solid #86efac; font-weight:700;">
                        💡 <span id="admissionSuggestReasonText"><?= htmlspecialchars($admission_suggest_reason, ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </div>
                <div style="font-size:10px; color:#15803d; text-align:right; line-height:1.35;">
                    <div>※ 検査種別が W / TCF の時は必要に応じてチェック、ポリペク時は自動で入院確定となります。</div>
                    <div>※ 厨房の週間食事予測（朝・昼・夕）へ即座に反映されます。</div>
                </div>
            </div>
        </div>

        <!-- 下段：MALL3メモ表示 (ワンクリック拡縮・タイトルクリックコピー・備考転記対応) -->
        <div class="panel-box" style="margin-bottom:0;">
            <div class="panel-header">
                <span>📋 MALL3メモ表示</span>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" class="btn-expand-toggle" onclick="toggleMemosExpand(this)" title="メモ表示枠の拡大・縮小">↕ 拡大</button>
                    <span style="font-size:9.5px; color:#64748b;">※患者No入力時に自動取得（クリックで備考へ転記 / 📋でコピー）</span>
                </div>
            </div>
            
            <div class="mall-memo-container">
                <div class="mall-memo-block" id="memo_block_1">
                    <div class="mall-memo-header">
                        <span class="copy-trigger-title" onclick="copyElementContent('memo_today', '本日メモ')" title="クリックで本日メモをクリップボードにコピー">本日メモ 📋</span>
                        <div class="mall-memo-btn-group">
                            <button type="button" class="btn-memo-copy" onclick="copyElementContent('memo_today', '本日メモ')" title="クリップボードにコピー">📋 コピー</button>
                            <button type="button" class="btn-memo-paste" onclick="pasteMemoToRemarks('memo_today', '本日メモ')" title="備考・特記事項欄に直接転記（コピーペースト）します">📝 備考へ転記</button>
                        </div>
                    </div>
                    <div class="mall-memo-content" id="memo_today" onclick="handleMemoContentClick(event, 'memo_today', '本日メモ')" title="クリックで備考・特記事項欄に直接転記（コピーペースト）できます"><span style="color:#94a3b8;">なし</span></div>
                </div>
                <div class="mall-memo-block" id="memo_block_2">
                    <div class="mall-memo-header">
                        <span class="copy-trigger-title" onclick="copyElementContent('memo_karte', 'カルテメモ')" title="クリックでカルテメモをクリップボードにコピー">カルテメモ 📋</span>
                        <div class="mall-memo-btn-group">
                            <button type="button" class="btn-memo-copy" onclick="copyElementContent('memo_karte', 'カルテメモ')" title="クリップボードにコピー">📋 コピー</button>
                            <button type="button" class="btn-memo-paste" onclick="pasteMemoToRemarks('memo_karte', 'カルテメモ')" title="備考・特記事項欄に直接転記（コピーペースト）します">📝 備考へ転記</button>
                        </div>
                    </div>
                    <div class="mall-memo-content" id="memo_karte" onclick="handleMemoContentClick(event, 'memo_karte', 'カルテメモ')" title="クリックで備考・特記事項欄に直接転記（コピーペースト）できます"><span style="color:#94a3b8;">なし</span></div>
                </div>
                <div class="mall-memo-block" id="memo_block_3">
                    <div class="mall-memo-header">
                        <span class="copy-trigger-title" onclick="copyElementContent('memo_patient', '患者メモ')" title="クリックで患者メモをクリップボードにコピー">患者メモ（連絡メモ） 📋</span>
                        <div class="mall-memo-btn-group">
                            <button type="button" class="btn-memo-copy" onclick="copyElementContent('memo_patient', '患者メモ')" title="クリップボードにコピー">📋 コピー</button>
                            <button type="button" class="btn-memo-paste" onclick="pasteMemoToRemarks('memo_patient', '患者メモ')" title="備考・特記事項欄に直接転記（コピーペースト）します">📝 備考へ転記</button>
                        </div>
                    </div>
                    <div class="mall-memo-content" id="memo_patient" onclick="handleMemoContentClick(event, 'memo_patient', '患者メモ')" title="クリックで備考・特記事項欄に直接転記（コピーペースト）できます"><span style="color:#94a3b8;">なし</span></div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- 定型文パレット（マスタ新規登録・絞り込み・ドラッグ移動・枠可変・編集削除対応） -->
<div id="remarksPalette" class="remarks-palette">
    <div class="palette-header" id="paletteDragHeader">
        <span style="display:flex; align-items:center; gap:4px;">
            <span>✥</span>
            <span>📋 備考定型文パレット</span>
            <span style="font-size:9.5px; font-weight:normal; color:#64748b;">(ドラッグ移動可・右下枠可変)</span>
        </span>
        <button type="button" onclick="closeRemarksPalette()" style="border:none; background:none; cursor:pointer; font-weight:bold; color:#64748b; font-size:14px; line-height:1;" title="閉じる">✕</button>
    </div>

    <!-- 医療安全アラート確定タグ（ワンクリック挿入） -->
    <div class="palette-tags-box">
        <div style="font-size:10px; font-weight:700; color:#475569; margin-bottom:3px;">🛡️ 医療安全アラートタグ挿入:</div>
        <div style="display:flex; flex-wrap:wrap; gap:4px;">
            <button type="button" class="palette-tag-btn tag-anti" onclick="insertPhraseToQuill('【抗血栓】')" title="備考に【抗血栓】タグを挿入">💊 【抗血栓】</button>
            <button type="button" class="palette-tag-btn tag-allergy" onclick="insertPhraseToQuill('【アレルギー】')" title="備考に【アレルギー】タグを挿入">⚠️ 【アレルギー】</button>
            <button type="button" class="palette-tag-btn tag-infection" onclick="insertPhraseToQuill('【感染症】')" title="備考に【感染症】タグを挿入">🔴 【感染症】</button>
            <button type="button" class="palette-tag-btn tag-cold" onclick="insertPhraseToQuill('【風邪】')" title="備考に【風邪】タグを挿入">😷 【風邪】</button>
        </div>
    </div>

    <?php if ($dept === 'GASTRO'): ?>
    <!-- 胃腸科専用: 特殊枠コメント挿入（エコー検査予定） -->
    <div id="paletteEchoBox" class="palette-echo-box">
        <div style="font-size:10px; font-weight:700; color:#166534; margin-bottom:4px; display:flex; align-items:center; gap:4px;">
            <span>🟢 胃腸科 特殊枠コメント:</span>
            <span style="font-size:9.5px; font-weight:normal; color:#64748b;">(※太字・赤文字・濃緑網掛け)</span>
        </div>
        <div style="display:flex; flex-wrap:wrap; gap:6px;">
            <button type="button" class="palette-echo-btn" onclick="insertSpecialEchoPhrase('10：00 エコー検査予定')" title="「10：00 エコー検査予定」を太字・赤文字・濃い緑網掛けで挿入">
                ⚡ 10：00 エコー検査予定
            </button>
            <button type="button" class="palette-echo-btn" onclick="insertSpecialEchoPhrase('10：30 エコー検査予定')" title="「10：30 エコー検査予定」を太字・赤文字・濃い緑網掛けで挿入">
                ⚡ 10：30 エコー検査予定
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- 選択中テキストのマスタ登録バー -->
    <div id="paletteSelectionBar" style="display:none; background:#eff6ff; border:1px dashed #3b82f6; border-radius:4px; padding:4px 6px; margin-bottom:6px; flex-direction:column; gap:3px; flex-shrink:0;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:10px; color:#475569;">💡 選択中のテキスト:</span>
            <button type="button" class="btn btn-primary" style="padding:1px 6px; font-size:9.5px; height:18px;" onclick="saveSelectedTextAsMaster()">➕ マスタ登録</button>
        </div>
        <div id="paletteSelectionText" style="font-weight:700; color:#1d4ed8; font-size:10.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></div>
    </div>

    <!-- 絞り込み検索 -->
    <input type="text" id="paletteSearch" style="width:100%; height:24px; padding:2px 6px; font-size:10.5px; border:1px solid #cbd5e1; border-radius:4px; margin-bottom:6px; flex-shrink:0;" placeholder="🔍 定型文を絞り込み..." oninput="filterPhrases(this.value)">

    <!-- チップ一覧（挿入・編集・削除対応） -->
    <div id="paletteListBox" class="palette-list-box">
        <?php foreach ($remarks_phrases_master as $item): 
            $mid = (int)$item['master_id'];
            $phrase = (string)$item['item_value'];
        ?>
            <span class="phrase-chip" id="phrase_chip_<?= $mid ?>" data-master-id="<?= $mid ?>" data-phrase="<?= htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') ?>">
                <span class="chip-insert-btn" onclick="insertPhraseToQuill('<?= htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') ?>')" title="クリックで本文に挿入">➕ <?= htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') ?></span>
                <button type="button" class="chip-action-btn btn-edit" onclick="editPhrase(event, <?= $mid ?>)" title="定型文を編集">✏️</button>
                <button type="button" class="chip-action-btn btn-del" onclick="deletePhrase(event, <?= $mid ?>)" title="定型文を削除">🗑️</button>
            </span>
        <?php endforeach; ?>
    </div>

    <!-- 新規定型文の手動直接登録バー -->
    <div style="display:flex; gap:4px; align-items:center; border-top:1px solid #f1f5f9; padding-top:6px; flex-shrink:0;">
        <input type="text" id="newPhraseInput" class="form-control" style="flex:1; height:24px; font-size:10.5px;" placeholder="新しい定型文を直接登録...">
        <button type="button" class="btn btn-primary" style="height:24px; padding:0 8px; font-size:10.5px;" onclick="addNewPhraseFromInput()">登録</button>
    </div>
</div>

<!-- ── karteview.php 準拠のコピートースト通知要素 ── -->
<div id="copyToast" class="copy-toast">
    <span id="copyToastMsg"></span>
</div>

<script src="assets/vendor/quill/quill.js?v=20260928"></script>
<script>
let quill = null;
let quillAvailable = false;
let selectedRemarksText = '';
const isNew = <?= $schedule_id === 0 ? 'true' : 'false' ?>;
const currentScheduleId = <?= (int)$schedule_id ?>;
let currentDaySlotReservationsMap = <?= json_encode($day_slot_reservations_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentDoctorCapacityMap = {};
let currentSlotSummary = null;
// 診療科別：枠名による検査種別（GIF）自動セット設定（PHP設定から連動）
const ENABLE_AUTO_GIF_BY_DEPT = <?= json_encode($enable_auto_gif_by_dept) ?>;

// Quillエディタの安全な初期化とフォールバック
function initQuillSafely() {
    const editorEl = document.getElementById('quill-editor');
    if (!editorEl) return;

    if (typeof Quill !== 'undefined') {
        try {
            quill = new Quill('#quill-editor', {
                modules: { toolbar: '#quill-toolbar' },
                theme: 'snow',
                placeholder: '予約備考・特記事項を入力...'
            });
            quillAvailable = true;
            quill.on('text-change', function() {
                try {
                    checkAdmissionSuggestion(quill.getText());
                } catch (e) {}
            });
        } catch (e) {
            console.error('Quillの初期化に失敗しました。フォールバックを適用します:', e);
            setupFallbackEditor(editorEl);
        }
    } else {
        console.warn('Quillライブラリが利用できません。フォールバックを適用します。');
        setupFallbackEditor(editorEl);
    }
}

function setupFallbackEditor(editorEl) {
    quillAvailable = false;
    const toolbar = document.getElementById('quill-toolbar');
    if (toolbar) {
        toolbar.style.display = 'none';
    }
    editorEl.setAttribute('contenteditable', 'true');
    editorEl.style.minHeight = '95px';
    editorEl.style.padding = '6px';
    editorEl.style.border = '1px solid #cbd5e1';
    editorEl.style.borderRadius = '4px';
    editorEl.style.background = '#fff';
    editorEl.style.outline = 'none';
    editorEl.style.overflowY = 'auto';
    editorEl.style.lineHeight = '1.35';
}

document.addEventListener('DOMContentLoaded', function() {
    // 1. 基幹機能（曜日更新・医師ドロップダウン）を最優先で初期化
    try {
        updateDayOfWeek();
    } catch (e) {
        console.error('updateDayOfWeek error:', e);
    }

    try {
        updateDoctorDropdown();
    } catch (e) {
        console.error('updateDoctorDropdown error:', e);
    }

    // 2. 患者No入力欄のEnterキー検索イベント登録＆初期値反映
    try {
        const pidInput = document.getElementById('patient_id');
        if (pidInput) {
            pidInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    doSyncPatient();
                }
            });

            // フォーカス時にもソフトウェアキーボードを表示
            pidInput.addEventListener('focus', function() {
                showVirtualKeyboard();
            });

            if (pidInput.value) {
                checkDuplicateAndLimits();
                reloadMallMemo();
                checkMedicalAlertForPatient(pidInput.value);
                // 既存予約の場合、MALL3から最新の性別・住所・TELを取得してカードに補完
                fetchPatientDetailsAndUpdateCard(pidInput.value);
            } else {
                // 新規予約時は患者No入力欄へ自動で入力フォーカスをセット
                setTimeout(function() {
                    try {
                        pidInput.focus();
                        pidInput.select();
                    } catch (e) {}
                }, 100);
            }

            // ソフトウェアキーボードの外部クリック/Esc閉じるリスナー
            document.addEventListener('click', function(e) {
                const kb = document.getElementById('patientVirtualKeyboard');
                const input = document.getElementById('patient_id');
                const btn = document.getElementById('btnToggleKeyboard');
                if (kb && kb.style.display !== 'none') {
                    if (!kb.contains(e.target) && e.target !== input && e.target !== btn && !(btn && btn.contains(e.target))) {
                        closeVirtualKeyboard();
                    }
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeVirtualKeyboard();
                }
            });
        }
    } catch (e) {
        console.error('patient_id setup error:', e);
    }

    // 3. 検査種別に応じた下剤・部屋・鎮静トグルの初期表示反映
    try {
        const curEtype = document.getElementById('exam_type') ? document.getElementById('exam_type').value : '';
        updateFormFieldsByExamType(curEtype, false);
    } catch (e) {
        console.error('updateFormFieldsByExamType init error:', e);
    }

    // 4. Quillエディタの安全な初期化
    try {
        initQuillSafely();
    } catch (e) {
        console.error('initQuillSafely error:', e);
    }

    // 5. 初期選択枠の重複・医師残枠チェック
    try {
        checkDuplicateAndLimits();
    } catch (e) {
        console.error('checkDuplicateAndLimits init error:', e);
    }
});

// 備考欄エディタのワンクリック拡縮
function toggleRemarksExpand(btn) {
    const editor = document.getElementById('quill-editor');
    if (!editor) return;
    const isExpanded = editor.classList.toggle('is-expanded');
    btn.textContent = isExpanded ? '↕ 縮小' : '↕ 拡大';
}

// MALL3メモ表示欄（3ブロック同時）のワンクリック拡縮
function toggleMemosExpand(btn) {
    const blocks = document.querySelectorAll('.mall-memo-block');
    let isExpanded = false;
    blocks.forEach(b => {
        isExpanded = b.classList.toggle('is-expanded');
    });
    btn.textContent = isExpanded ? '↕ 縮小' : '↕ 拡大';
}

// ── karteview.php 準拠のコピー ＆ トースト関数 ──
function copyElementContent(elementId, labelName) {
    const el = document.getElementById(elementId);
    if (!el) {
        showToast('⚠️ コピー対象が見つかりません');
        return;
    }

    const textContent = el.innerText.trim();
    if (textContent === '' || textContent === 'なし' || textContent.includes('登録はありません')) {
        showToast('⚠️ コピー対象のデータがありません');
        return;
    }

    // クリップボードへコピー（navigator.clipboard 優先、フォールバック完備）
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(textContent).then(() => {
            showToast(`📋 ${labelName} をコピーしました`);
        }).catch(() => {
            fallbackCopyElementText(textContent, labelName);
        });
    } else {
        fallbackCopyElementText(textContent, labelName);
    }
}

function fallbackCopyElementText(text, labelName) {
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        ta.style.left = '-9999px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const successful = document.execCommand('copy');
        document.body.removeChild(ta);

        if (successful) {
            showToast(`📋 ${labelName} をコピーしました`);
        } else {
            showToast('❌ コピーに失敗しました');
        }
    } catch (err) {
        showToast('❌ コピーエラー: ' + err.message);
    }
}

let toastTimer = null;
function showToast(msg) {
    const toast = document.getElementById('copyToast');
    const msgEl = document.getElementById('copyToastMsg');
    if (!toast || !msgEl) return;

    msgEl.textContent = msg;
    toast.classList.add('show');

    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 2200);
}

// ── MALL3メモ ワンクリック備考・特記事項転記 ＆ コピー関数 ──
function pasteMemoToRemarks(elementId, labelName) {
    const el = document.getElementById(elementId);
    if (!el) {
        showToast('⚠️ メモ対象が見つかりません');
        return;
    }

    const textContent = el.innerText.trim();
    if (textContent === '' || textContent === 'なし' || textContent.includes('登録はありません')) {
        showToast('⚠️ 転記対象のメモデータがありません');
        return;
    }

    // 1. クリップボードへも同時コピー（外部ソフトや他画面等での再利用を保証）
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(textContent).catch(() => {});
    }

    // 2. 備考・特記事項エディタへ直接挿入
    if (quill && quillAvailable) {
        try {
            quill.focus();
            let range = quill.getSelection();
            if (!range) {
                const len = quill.getLength();
                range = { index: Math.max(0, len - 1), length: 0 };
            }
            const idx = range.index;

            // 直前が改行でない場合は改行を挟む
            let prefix = '';
            if (idx > 0) {
                const prevChar = quill.getText(idx - 1, 1);
                if (prevChar !== '\n') {
                    prefix = '\n';
                }
            }

            const insertStr = prefix + textContent + '\n';
            quill.insertText(idx, insertStr, 'user');
            quill.setSelection(idx + insertStr.length, 0);

            showToast(`📝 ${labelName} を備考・特記事項に転記しました`);
            return;
        } catch (e) {
            console.warn('pasteMemoToRemarks error, using fallback:', e);
        }
    }

    // Quill未動作時のフォールバック
    const editor = document.getElementById('quill-editor');
    if (editor) {
        const p = document.createElement('p');
        p.textContent = textContent;
        editor.appendChild(p);
        showToast(`📝 ${labelName} を備考・特記事項に転記しました`);
    }
}

function handleMemoContentClick(e, elementId, labelName) {
    // ユーザーがテキストを選択（ドラッグ）している最中はクリック転記を発動しない
    const selection = window.getSelection();
    if (selection && selection.toString().length > 0) {
        return;
    }
    pasteMemoToRemarks(elementId, labelName);
}

// ── ⌨️ 患者No ソフトウェアテンキーボード制御 ──
function toggleVirtualKeyboard(e) {
    if (e) {
        e.stopPropagation();
        e.preventDefault();
    }
    const kb = document.getElementById('patientVirtualKeyboard');
    const btn = document.getElementById('btnToggleKeyboard');
    if (!kb) return;

    if (kb.style.display === 'none' || kb.style.display === '') {
        showVirtualKeyboard();
    } else {
        closeVirtualKeyboard();
    }
}

function showVirtualKeyboard() {
    const kb = document.getElementById('patientVirtualKeyboard');
    const btn = document.getElementById('btnToggleKeyboard');
    if (!kb) return;
    kb.style.display = 'block';
    if (btn) btn.classList.add('is-active');
}

function closeVirtualKeyboard() {
    const kb = document.getElementById('patientVirtualKeyboard');
    const btn = document.getElementById('btnToggleKeyboard');
    if (!kb) return;
    kb.style.display = 'none';
    if (btn) btn.classList.remove('is-active');
}

function pressNumpad(key) {
    const input = document.getElementById('patient_id');
    if (!input) return;

    if (key === 'SYNC') {
        closeVirtualKeyboard();
        doSyncPatient();
        return;
    }

    let val = input.value;
    const start = (typeof input.selectionStart === 'number') ? input.selectionStart : val.length;
    const end = (typeof input.selectionEnd === 'number') ? input.selectionEnd : val.length;

    if (key === 'BS') {
        if (start === end && start > 0) {
            val = val.substring(0, start - 1) + val.substring(end);
            input.value = val;
            input.setSelectionRange(start - 1, start - 1);
        } else if (start !== end) {
            val = val.substring(0, start) + val.substring(end);
            input.value = val;
            input.setSelectionRange(start, start);
        }
    } else if (key === 'CLEAR') {
        input.value = '';
        input.setSelectionRange(0, 0);
    } else {
        // 数字 (0-9, 00) または記号 (-)
        val = val.substring(0, start) + key + val.substring(end);
        input.value = val;
        const newPos = start + key.length;
        input.setSelectionRange(newPos, newPos);
    }

    input.focus();
}

// -------------------------------------------------------------
// 定型文パレット制御＆マスタ登録・編集・削除
// -------------------------------------------------------------
function toggleRemarksPalette() {
    const palette = document.getElementById('remarksPalette');
    if (palette.style.display === 'flex') {
        palette.style.display = 'none';
        return;
    }

    selectedRemarksText = '';
    if (quill && quillAvailable) {
        try {
            const range = quill.getSelection();
            if (range && range.length > 0) {
                selectedRemarksText = quill.getText(range.index, range.length).trim();
            }
        } catch (e) {}
    } else {
        const sel = window.getSelection();
        if (sel && sel.toString().trim()) {
            selectedRemarksText = sel.toString().trim();
        }
    }

    const selectionBar = document.getElementById('paletteSelectionBar');
    const selectionTextEl = document.getElementById('paletteSelectionText');
    if (selectedRemarksText.length > 0) {
        selectionTextEl.innerText = selectedRemarksText;
        selectionBar.style.display = 'flex';
    } else {
        selectionBar.style.display = 'none';
    }

    palette.style.display = 'flex';
    document.getElementById('paletteSearch').value = '';
    filterPhrases('');

    // 初回表示時のスマート配置（画面内右側）
    if (!palette.dataset.placed) {
        const rect = palette.getBoundingClientRect();
        const topPos = Math.max(80, Math.min(120, window.innerHeight - 500));
        const leftPos = Math.max(20, window.innerWidth - (rect.width || 420) - 40);
        palette.style.top = topPos + 'px';
        palette.style.left = leftPos + 'px';
        palette.style.right = 'auto';
        palette.dataset.placed = '1';
    }
}

function closeRemarksPalette() {
    document.getElementById('remarksPalette').style.display = 'none';
}

function insertPhraseToQuill(phrase) {
    if (quill && quillAvailable) {
        try {
            const range = quill.getSelection(true);
            quill.insertText(range.index, phrase + '\n');
            quill.setSelection(range.index + phrase.length + 1);
            return;
        } catch (e) {
            console.warn('insertPhraseToQuill error, trying fallback:', e);
        }
    }
    const editor = document.getElementById('quill-editor');
    if (!editor) return;
    editor.appendChild(document.createTextNode(phrase + '\n'));
}

// 胃腸科専用: 特殊枠コメント（太字・赤文字・濃い緑網掛け）挿入
function insertSpecialEchoPhrase(phrase) {
    if (quill && quillAvailable) {
        try {
            quill.focus();
            let range = quill.getSelection();
            if (!range) {
                const len = quill.getLength();
                range = { index: Math.max(0, len - 1), length: 0 };
            }
            const idx = range.index;

            // 1. 本文を「太字・赤文字(#ff4d4f)・濃い緑網掛け(#166534)」で挿入
            quill.insertText(idx, phrase, {
                bold: true,
                color: '#ff4d4f',
                background: '#166534'
            }, 'user');

            // 2. 続く改行は書式なし（通常）に戻して挿入
            quill.insertText(idx + phrase.length, '\n', {
                bold: false,
                color: false,
                background: false
            }, 'user');

            // 3. カーソルを改行の直後に移動し、以後の入力書式をリセット
            const nextIdx = idx + phrase.length + 1;
            quill.setSelection(nextIdx, 0);
            quill.format('bold', false);
            quill.format('color', false);
            quill.format('background', false);

            showToast(`➕ 「${phrase}」を挿入しました`);
            return;
        } catch (e) {
            console.warn('insertSpecialEchoPhrase error, trying fallback:', e);
        }
    }
    // Quill未稼働時のフォールバック
    const editor = document.getElementById('quill-editor');
    if (!editor) return;
    const p = document.createElement('p');
    p.innerHTML = `<strong style="color: #ff4d4f; background-color: #166534; padding: 1px 4px; border-radius: 2px;">${escapeHtml(phrase)}</strong>`;
    editor.appendChild(p);
    showToast(`➕ 「${phrase}」を挿入しました`);
}

function filterPhrases(keyword) {
    const kw = keyword.toLowerCase().trim();
    const chips = document.querySelectorAll('#paletteListBox .phrase-chip');
    chips.forEach(chip => {
        const p = (chip.getAttribute('data-phrase') || '').toLowerCase();
        chip.style.display = (kw === '' || p.includes(kw)) ? 'inline-flex' : 'none';
    });
}

function saveSelectedTextAsMaster() {
    if (!selectedRemarksText) return;
    registerNewPhraseToDb(selectedRemarksText);
}

function addNewPhraseFromInput() {
    const input = document.getElementById('newPhraseInput');
    const val = input.value.trim();
    if (!val) {
        alert('定型文を入力してください。');
        return;
    }
    registerNewPhraseToDb(val);
}

function registerNewPhraseToDb(phrase) {
    const fd = new FormData();
    fd.append('api_action', 'add_remarks_phrase');
    fd.append('phrase', phrase);

    fetch('modal_edit.php', { method: 'POST', body: fd })
        .then(async res => {
            const data = await res.json();
            if (data && data.success) {
                const listBox = document.getElementById('paletteListBox');
                const mid = data.master_id;
                const chip = document.createElement('span');
                chip.className = 'phrase-chip';
                chip.id = `phrase_chip_${mid}`;
                chip.setAttribute('data-master-id', mid);
                chip.setAttribute('data-phrase', data.phrase);
                chip.innerHTML = `
                    <span class="chip-insert-btn" title="クリックで本文に挿入">➕ ${escapeHtml(data.phrase)}</span>
                    <button type="button" class="chip-action-btn btn-edit" title="定型文を編集">✏️</button>
                    <button type="button" class="chip-action-btn btn-del" title="定型文を削除">🗑️</button>
                `;
                chip.querySelector('.chip-insert-btn').onclick = function() { insertPhraseToQuill(data.phrase); };
                chip.querySelector('.btn-edit').onclick = function(e) { editPhrase(e, mid); };
                chip.querySelector('.btn-del').onclick = function(e) { deletePhrase(e, mid); };
                listBox.appendChild(chip);

                document.getElementById('newPhraseInput').value = '';
                document.getElementById('paletteSelectionBar').style.display = 'none';
                selectedRemarksText = '';
                showToast(`➕ 「${data.phrase}」を登録しました`);
            } else {
                alert('マスタ登録失敗: ' + (data.message || 'エラーが発生しました'));
            }
        })
        .catch(err => {
            alert('通信エラーが発生しました: ' + err.toString());
        });
}

function editPhrase(event, masterId) {
    event.stopPropagation();
    const chip = document.getElementById(`phrase_chip_${masterId}`);
    if (!chip) return;
    const oldPhrase = chip.getAttribute('data-phrase') || '';
    const newPhrase = prompt('定型文の内容を編集してください:', oldPhrase);
    if (newPhrase === null) return;
    const trimmed = newPhrase.trim();
    if (!trimmed) {
        alert('定型文が空です。');
        return;
    }
    if (trimmed === oldPhrase) return;

    const fd = new FormData();
    fd.append('api_action', 'edit_remarks_phrase');
    fd.append('master_id', masterId);
    fd.append('phrase', trimmed);

    fetch('modal_edit.php', { method: 'POST', body: fd })
        .then(async res => {
            const data = await res.json();
            if (data && data.success) {
                chip.setAttribute('data-phrase', data.phrase);
                const insertBtn = chip.querySelector('.chip-insert-btn');
                if (insertBtn) {
                    insertBtn.textContent = `➕ ${data.phrase}`;
                    insertBtn.onclick = function() { insertPhraseToQuill(data.phrase); };
                }
                showToast(`✏️ 「${data.phrase}」に更新しました`);
            } else {
                alert('編集失敗: ' + (data.message || 'エラーが発生しました'));
            }
        })
        .catch(err => alert('通信エラー: ' + err.toString()));
}

function deletePhrase(event, masterId) {
    event.stopPropagation();
    const chip = document.getElementById(`phrase_chip_${masterId}`);
    if (!chip) return;
    const phrase = chip.getAttribute('data-phrase') || '';
    if (!confirm(`定型文「${phrase}」を削除しますか？`)) return;

    const fd = new FormData();
    fd.append('api_action', 'delete_remarks_phrase');
    fd.append('master_id', masterId);

    fetch('modal_edit.php', { method: 'POST', body: fd })
        .then(async res => {
            const data = await res.json();
            if (data && data.success) {
                chip.style.transition = 'all 0.2s ease';
                chip.style.opacity = '0';
                chip.style.transform = 'scale(0.8)';
                setTimeout(() => { chip.remove(); }, 200);
                showToast(`🗑️ 「${phrase}」を削除しました`);
            } else {
                alert('削除失敗: ' + (data.message || 'エラーが発生しました'));
            }
        })
        .catch(err => alert('通信エラー: ' + err.toString()));
}

(function initDraggablePalette() {
    const palette = document.getElementById('remarksPalette');
    const dragHeader = document.getElementById('paletteDragHeader');
    if (!palette || !dragHeader) return;
    let isDragging = false;
    let startX = 0, startY = 0;
    let startLeft = 0, startTop = 0;

    dragHeader.addEventListener('mousedown', function(e) {
        if (e.target.tagName === 'BUTTON' || e.target.closest('button')) return;
        e.preventDefault();
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;

        const rect = palette.getBoundingClientRect();
        startLeft = rect.left;
        startTop = rect.top;

        palette.style.right = 'auto';
        palette.style.bottom = 'auto';
        palette.style.left = startLeft + 'px';
        palette.style.top = startTop + 'px';

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    });

    function onMouseMove(e) {
        if (!isDragging) return;
        e.preventDefault();
        let newLeft = startLeft + (e.clientX - startX);
        let newTop = startTop + (e.clientY - startY);

        const maxLeft = Math.max(0, window.innerWidth - 60);
        const maxTop = Math.max(0, window.innerHeight - 40);
        if (newLeft < 0) newLeft = 0;
        if (newLeft > maxLeft) newLeft = maxLeft;
        if (newTop < 0) newTop = 0;
        if (newTop > maxTop) newTop = maxTop;

        palette.style.left = newLeft + 'px';
        palette.style.top = newTop + 'px';
    }

    function onMouseUp() {
        isDragging = false;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
    }
})();

// -------------------------------------------------------------
// 枠連携＆初期値算出
// -------------------------------------------------------------
function calculateBefore30Min(slotName) {
    if (!slotName) return '';
    const m = slotName.match(/(\d{1,2}):(\d{2})/);
    if (!m) return '';

    let h = parseInt(m[1], 10);
    let min = parseInt(m[2], 10);

    let total = (h * 60 + min) - 30;
    if (total < 0) total += 24 * 60;

    const resH = String(Math.floor(total / 60)).padStart(2, '0');
    const resM = String(total % 60).padStart(2, '0');
    return `${resH}:${resM}`;
}

function handleWakuChange(wakuName) {
    checkDuplicateAndLimits();
    if (!isNew) return;

    const dept = document.getElementById('f_dept').value;
    const examSelect = document.getElementById('exam_type');
    const arrivalInput = document.getElementById('arrival_time');
    const locSelect = document.getElementById('exam_location');

    const isGif = wakuName.includes('GIF');
    const isTcf = wakuName.includes('TCF');
    const autoGifEnabled = !!(ENABLE_AUTO_GIF_BY_DEPT && ENABLE_AUTO_GIF_BY_DEPT[dept]);

    if (isGif && autoGifEnabled) {
        // 既に「浣腸CF」が選ばれている場合は「GIF」へ上書きしない
        if (!examSelect.value) {
            examSelect.value = 'GIF';
            updateFormFieldsByExamType('GIF', true);
        }
        if (examSelect.value === 'GIF' || examSelect.value === '浣腸CF') {
            arrivalInput.value = calculateBefore30Min(wakuName);
        }
    } else if (isTcf && dept === 'GASTRO') {
        if (!examSelect.value) {
            examSelect.value = 'TCF';
            updateFormFieldsByExamType('TCF', true);
        }
    }

    const isEcho = wakuName.includes('エコー') || wakuName.toUpperCase().includes('ECHO');
    if (isEcho) {
        if (!examSelect.value) {
            examSelect.value = 'エコー';
            updateFormFieldsByExamType('エコー', true);
        }
    }

    // GIF枠またはTCF枠の場合、実施場所は一番上の選択肢を選択（胃腸科のみ、またはエコー枠等は未選択）
    if (dept === 'GASTRO') {
        if (isGif || isTcf) {
            if (locSelect && locSelect.options.length > 1) {
                locSelect.selectedIndex = 1;
            }
        } else {
            if (locSelect) {
                locSelect.value = '';
            }
        }
    }
}

function updateFormFieldsByExamType(etype, isUserChange = true) {
    const prepSelect = document.getElementById('bowel_prep');
    const roomSelect = document.getElementById('room_number');
    const arrivalInput = document.getElementById('arrival_time');
    const sedationLabel = document.getElementById('labelSedationToggle');
    const driveLabel = document.getElementById('labelDriveToggle');
    const sedationInput = document.getElementById('has_sedation');
    const driveInput = document.getElementById('has_drive_caution');
    const admissionLabel = document.getElementById('labelAdmissionToggle');
    const admissionInput = document.getElementById('is_admission');
    const admissionBadge = document.getElementById('admissionSuggestBadge');
    const admissionReason = document.getElementById('admissionSuggestReasonText');
    const admissionText = document.getElementById('admissionToggleText');
    const deptEl = document.getElementById('f_dept');
    const dept = deptEl ? deptEl.value : 'PROCT';

    etype = (etype || '').trim();
    const isTcfLike = (etype === 'TCF' || etype === 'W' || etype === 'ポリペク' || etype.includes('TCF') || etype.includes('ポリペク'));
    const isEcho = (etype === 'エコー' || etype.includes('エコー'));

    // 1. マイトイレ部屋番号: TCF系のみ表示（エコー・GIF・浣腸CFは非表示）
    if (roomSelect) {
        if (isTcfLike) {
            roomSelect.style.display = 'inline-block';
        } else {
            roomSelect.style.display = 'none';
            if (isUserChange) {
                roomSelect.value = '';
            }
        }
    }

    // 2. 下剤: TCF系のみ表示（エコー・GIF・浣腸CFは非表示）
    if (prepSelect) {
        if (isTcfLike) {
            prepSelect.style.display = 'inline-block';
        } else {
            prepSelect.style.display = 'none';
            if (isUserChange) {
                prepSelect.value = '';
            }
        }
    }

    // 3. 鎮静希望 & 自主運転注意: エコーは完全不要（非表示＆OFF）
    if (sedationLabel) {
        if (isEcho) {
            sedationLabel.style.display = 'none';
            if (isUserChange && sedationInput) {
                sedationInput.checked = false;
                sedationLabel.classList.remove('is-checked');
            }
        } else {
            sedationLabel.style.display = 'inline-flex';
            // 科ごとの鎮静剤ラベル（肛門科: プロポ / 胃腸科: ジアゼパム）
            const sedText = document.getElementById('sedationToggleText');
            if (sedText) {
                const drugName = (dept === 'PROCT') ? 'プロポ' : 'ジアゼパム';
                sedText.textContent = `💉 鎮静希望 (注あり:${drugName})`;
            }
            const drugDesc = (dept === 'PROCT') ? 'プロポフォール / 安静目安20分' : 'ジアゼパム / 安静目安30分';
            sedationLabel.title = `鎮静剤（${drugDesc}）の使用希望。進捗モニタのリカバリ動線と連動`;
        }
    }
    if (driveLabel) {
        if (isEcho) {
            driveLabel.style.display = 'none';
            if (isUserChange && driveInput) {
                driveInput.checked = false;
                driveLabel.classList.remove('is-checked');
            }
        } else {
            driveLabel.style.display = 'inline-flex';
        }
    }

    // 4. 来院時刻制御（ユーザー操作による新規枠変更時）
    const isGastric = isGastricCancerScreening(etype);
    if (isNew && isUserChange) {
        const waku = document.getElementById('scheduled_time') ? document.getElementById('scheduled_time').value : '';
        if (isEcho) {
            if (arrivalInput && arrivalInput.value) {
                arrivalInput.value = '';
            }
        } else if (etype === 'GIF' || etype === '浣腸CF' || isGastric) {
            if (waku && arrivalInput && !arrivalInput.value) {
                arrivalInput.value = calculateBefore30Min(waku);
            }
        }
    }

    // 5. 胃がん検診時の鎮静制御（市町村契約で原則不使用）
    if (isGastric) {
        if (isUserChange && sedationInput && sedationInput.checked) {
            sedationInput.checked = false;
            if (sedationLabel) sedationLabel.classList.remove('is-checked');
            if (driveInput) {
                driveInput.checked = false;
                if (driveLabel) driveLabel.classList.remove('is-checked');
            }
        }
    }
    updateGastricScreeningWarning();

    // 6. 🏥 病棟管理システム連動（入院予定）パネルの即時連動制御
    const panelWard = document.getElementById('panelWardIntegration');
    const isPolypep = (etype === 'ポリペク' || etype.includes('ポリペク'));
    const isWOrTcf = (etype === 'TCF' || etype === 'W');

    if (panelWard) {
        if (isPolypep) {
            // ・ポリペク⇒即表示、さらに入院予定ON
            panelWard.style.display = '';
            window.userModifiedAdmission = false; // ポリペク確定を優先
            updateAdmissionUI(true, 'ポリペク確定');
        } else if (isWOrTcf) {
            // ・TCF/W選択⇒即表示
            panelWard.style.display = '';
            if (isUserChange) {
                // 備考テキストからサジェスト判定
                let remText = '';
                if (typeof quill !== 'undefined' && quill) {
                    try {
                        remText = quill.getText ? quill.getText() : (quill.root ? quill.root.innerText : '');
                    } catch (e) {}
                }
                if (!remText) {
                    const fRem = document.getElementById('f_remarks');
                    remText = fRem ? fRem.value : '';
                }
                checkAdmissionSuggestion(remText);
            }
        } else {
            // ・検査種別が上記以外が選択されたら入院予定OFF/非表示化
            panelWard.style.display = 'none';
            window.userModifiedAdmission = false;
            updateAdmissionUI(false, '');
        }
    }
}

function isGastricCancerScreening(etype) {
    if (!etype) return false;
    return etype.includes('胃がん') || etype.includes('検診');
}

window.userModifiedAdmission = false;

function handleAdmissionChange(checkbox) {
    window.userModifiedAdmission = true;
    updateAdmissionUI(checkbox.checked, checkbox.checked ? "手動選択(入院ON)" : "手動解除(入院OFF)");
}

function updateAdmissionUI(isChecked, reason) {
    const admInput = document.getElementById("is_admission");
    const admLabel = document.getElementById("labelAdmissionToggle");
    const admText = document.getElementById("admissionToggleText");
    const admBadge = document.getElementById("admissionSuggestBadge");
    const reasonText = document.getElementById("admissionSuggestReasonText");
    const examSelect = document.getElementById("exam_type");
    const etype = (examSelect ? examSelect.value : "").trim();
    const isPolypep = (etype === "ポリペク" || etype.includes("ポリペク"));

    if (admInput) admInput.checked = isChecked;
    if (admLabel) {
        admLabel.style.background = isChecked ? "#dcfce7" : "#ffffff";
        admLabel.style.borderColor = isChecked ? "#16a34a" : "#86efac";
        admLabel.style.color = isChecked ? "#15803d" : "#166534";
    }
    if (admText) {
        admText.textContent = isPolypep ? "入院予定 (ポリペク確定)" : "入院予定";
    }
    if (admBadge && reasonText) {
        if (reason) {
            admBadge.style.display = "inline-block";
            reasonText.textContent = reason;
        } else {
            admBadge.style.display = "none";
        }
    }
}

function checkAdmissionSuggestion(text) {
    if (window.userModifiedAdmission) return;
    const examSelect = document.getElementById("exam_type");
    const etype = (examSelect ? examSelect.value : "").trim();
    const isPolypep = (etype === "ポリペク" || etype.includes("ポリペク"));
    const isWOrTcf = (etype === "TCF" || etype === "W");

    if (!isPolypep && !isWOrTcf) return;

    if (isPolypep) {
        updateAdmissionUI(true, "ポリペク確定");
        const admText = document.getElementById("admissionToggleText");
        if (admText) admText.textContent = "入院予定 (ポリペク確定)";
        return;
    }

    // TCF / W のサジェスト判定
    const cleanText = (text || "").replace(/<[^>]*>/g, "");
    if (cleanText.includes("ポリペクなし") || cleanText.includes("日帰り") || cleanText.includes("入院なし")) {
        updateAdmissionUI(false, "メモ「ポリペクなし/日帰り」検知");
    } else if (cleanText.includes("ポリペクあり") || cleanText.includes("同意書：ポリペク入院可") || cleanText.includes("ポリペク入院") || cleanText.includes("入院可") || cleanText.includes("入院")) {
        updateAdmissionUI(true, "メモ「ポリペクあり/入院可」検知");
    } else {
        updateAdmissionUI(false, "");
    }
}

function handleSedationChange(el) {
    const etype = document.getElementById('exam_type') ? document.getElementById('exam_type').value : '';
    const isGastric = isGastricCancerScreening(etype);

    if (el.checked && isGastric) {
        const confirmed = confirm(
            "⚠️【胃がん検診の鎮静剤注意】\n\n" +
            "胃がん検診は市町村との契約により、原則として鎮静剤（セデーション）は不使用となっています。\n\n" +
            "自費診療への切り替え、または医師からの明確な特別指示がある場合のみ許可されます。\n\n" +
            "本当に鎮静希望を「ON」にしてよろしいですか？"
        );
        if (!confirmed) {
            el.checked = false;
            el.parentElement.classList.remove('is-checked');
            updateGastricScreeningWarning();
            return;
        }
    }

    el.parentElement.classList.toggle('is-checked', el.checked);
    updateGastricScreeningWarning();
}

function updateGastricScreeningWarning() {
    const etype = document.getElementById('exam_type') ? document.getElementById('exam_type').value : '';
    const sedationInput = document.getElementById('has_sedation');
    const warningBox = document.getElementById('gastricSedationWarning');
    if (!warningBox) return;

    if (isGastricCancerScreening(etype) && sedationInput && sedationInput.checked) {
        warningBox.style.display = 'block';
    } else {
        warningBox.style.display = 'none';
    }
}

function handleExamTypeChange(etype) {
    updateFormFieldsByExamType(etype, true);
}

function openMallSoapViewer() {
    const pidInput = document.getElementById('patient_id');
    const pid = pidInput ? pidInput.value.trim() : '';
    if (!pid) {
        alert('患者Noを入力または検索してから「電カル」ボタンを押してください。');
        if (pidInput) pidInput.focus();
        return;
    }
    const url = '../karteview/karteview.php?patient_no=' 
              + encodeURIComponent(pid) 
              + '&source=new&do_search=1';

    window.open(url, 'karte_soap_view', 'width=1200,height=850,scrollbars=yes,resizable=yes');
}

function reloadMallMemo() {
    const pid = document.getElementById('patient_id').value.trim();
    if (!pid) {
        clearMallMemoDisplay();
        return;
    }
    fetch(`get_mall_memos.php?patient_no=${encodeURIComponent(pid)}`)
        .then(async res => {
            const data = await res.json();
            if (data && data.success) {
                document.getElementById('memo_today').innerHTML = data.memo_today || '<span style="color:#94a3b8;">なし</span>';
                document.getElementById('memo_karte').innerHTML = data.memo_karte || '<span style="color:#94a3b8;">なし</span>';
                document.getElementById('memo_patient').innerHTML = data.memo_patient || '<span style="color:#94a3b8;">なし</span>';
            } else {
                clearMallMemoDisplay();
            }
        })
        .catch(() => { clearMallMemoDisplay(); });
}

function clearMallMemoDisplay() {
    document.getElementById('memo_today').innerHTML = '<span style="color:#94a3b8;">なし</span>';
    document.getElementById('memo_karte').innerHTML = '<span style="color:#94a3b8;">なし</span>';
    document.getElementById('memo_patient').innerHTML = '<span style="color:#94a3b8;">なし</span>';
}

// モーダル・別ウィンドウ統合クローズ処理
function closeModalWindow(needReload = false) {
    if (needReload) {
        try { sessionStorage.setItem('need_reload', '1'); } catch(e) {}
    }
    // 1. iframe モーダルランナー（親画面）で動作している場合
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({
            type: needReload ? 'modal_edit_saved' : 'modal_edit_close'
        }, '*');
        return;
    }
    // 2. 従来の別ウィンドウ（ポップアップ）で動作している場合
    if (window.opener && !window.opener.closed) {
        if (needReload) {
            try { window.opener.location.reload(); } catch(e) {}
        }
    }
    window.close();
}


// 🅅 注目検査（隠し機能）制御
function revealVipCheckbox() {
    const vc = document.getElementById("vipContainer");
    if (vc) {
        vc.style.display = "inline-flex";
        const chk = document.getElementById("is_vip");
        if (chk && !chk.checked) {
            chk.checked = true;
            updateVipBadgePreview();
        }
    }
}

function updateVipBadgePreview() {
    const chk = document.getElementById("is_vip");
    const badge = document.querySelector("#vipContainer .vip-badge-icon");
    if (chk && badge) {
        if (chk.checked) {
            badge.style.opacity = "1";
            badge.style.boxShadow = "0 0 4px rgba(107, 33, 168, 0.4)";
        } else {
            badge.style.opacity = "0.4";
            badge.style.boxShadow = "none";
        }
    }
}

function saveReservation(andPrint) {
    const form = document.getElementById('editForm');
    const examDate = form.exam_date.value.trim();
    const scheduledTime = form.scheduled_time.value.trim();
    const deptCode = (document.getElementById('f_dept').value || '').trim();
    const patientName = form.patient_name.value.trim();

    if (!examDate || !scheduledTime || !deptCode) {
        alert('「検査日」「時間枠」「診療科」は必須項目です。必ず指定してください。');
        if (!examDate) form.exam_date.focus();
        else if (!scheduledTime) form.scheduled_time.focus();
        return;
    }

    const isTemp = (patientName === '' || patientName.startsWith('※仮予約'));

    if (isTemp) {
        if (!confirm('必須項目が設定されていませんがこのまま保存しますか？')) {
            return;
        }
        if (!patientName) {
            form.patient_name.value = '※仮予約';
            if (!form.patient_kana.value.trim()) {
                form.patient_kana.value = 'カリヨヤク';
            }
        }
    }

    // 胃がん検診で鎮静希望がONの場合の最終確認
    const examTypeVal = form.exam_type ? form.exam_type.value : '';
    const sedationChecked = form.has_sedation ? form.has_sedation.checked : false;
    if (isGastricCancerScreening(examTypeVal) && sedationChecked) {
        if (!confirm("⚠️【確認: 胃がん検診の鎮静剤使用】\n\n検査種別「胃がん検診」で「鎮静希望」がONになっています。\n\n市町村契約により原則不使用ですが、医師の指示・自費確認のうえでこのまま保存してよろしいですか？")) {
            return;
        }
    }

    let rawHtml = '';
    if (quill && quillAvailable) {
        try {
            rawHtml = quill.root.innerHTML;
        } catch (e) {
            const editorEl = document.getElementById('quill-editor');
            rawHtml = editorEl ? editorEl.innerHTML : '';
        }
    } else {
        const editorEl = document.getElementById('quill-editor');
        rawHtml = editorEl ? editorEl.innerHTML : '';
    }
    document.getElementById('f_remarks').value = (rawHtml === '<p><br></p>' || rawHtml.trim() === '') ? '' : rawHtml;

    const fd = new FormData(form);
    const sid = parseInt(form.schedule_id.value, 10) || 0;
    const actionName = (sid > 0) ? 'update' : 'insert';
    fd.append('action_type', actionName);
    fd.append('action', actionName);
    const vipEl = document.getElementById('is_vip');
    if (vipEl) {
        fd.set('is_vip', vipEl.checked ? '1' : '0');
    }

    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(async res => {
            const data = await res.json();
            if (data && data.success) {
                const targetSid = data.schedule_id || sid;
                if (andPrint && targetSid > 0) {
                    window.open(`print_patient_slip.php?id=${targetSid}`, '_blank');
                }
                closeModalWindow(true);
            } else {
                alert('保存失敗: ' + (data.message || 'エラー'));
            }
        })
        .catch(err => alert('通信エラー: ' + err.toString()));
}

function updateDayOfWeek() {
    const val = document.getElementById('exam_date').value;
    const label = document.getElementById('day_of_week_label');
    if (!val) { label.innerText = ''; return; }
    const d = new Date(val);
    if (!isNaN(d)) {
        const week = ['日', '月', '火', '水', '木', '金', '土'];
        const w = d.getDay();
        label.innerText = `(${week[w]})`;
        label.style.color = (w === 0) ? '#ef4444' : (w === 4 ? '#1e40af' : '#1e293b');
    }
}

function handleDateChange() { 
    updateDayOfWeek(); 
    updateDoctorDropdown(); 
    updateWakuDropdown();
}

function formatSlotOptionReservationInfo(reservations) {
    if (!Array.isArray(reservations) || reservations.length === 0) {
        return { suffix: '', hasOther: false, hasSelf: false };
    }
    const labels = [];
    let hasOther = false;
    let hasSelf = false;
    reservations.forEach(sr => {
        const sid = parseInt(sr.schedule_id || 0, 10);
        let pName = (sr.patient_name || '').trim();
        if (!pName) pName = 'ID:' + (sr.patient_id || '?');
        const doc = (sr.doctor_name || '').trim();
        const docPart = (doc && doc !== '◆未定◆') ? `(${doc})` : '';
        if (currentScheduleId > 0 && sid === currentScheduleId) {
            hasSelf = true;
            labels.push(`★${pName}${docPart}`);
        } else {
            hasOther = true;
            labels.push(`${pName}${docPart}`);
        }
    });
    return {
        suffix: labels.length > 0 ? ` 【${labels.join('・')}】` : '',
        hasOther: hasOther,
        hasSelf: hasSelf
    };
}

function updateWakuDropdown() {
    const dateInput = document.getElementById('exam_date');
    const deptEl = document.getElementById('f_dept');
    const wakuSelect = document.getElementById('scheduled_time');
    if (!dateInput || !wakuSelect) return;

    const date = dateInput.value;
    const dept = deptEl ? deptEl.value : 'GASTRO';
    if (!date || !date.match(/^\d{4}-\d{2}-\d{2}$/)) return;

    const curSelected = wakuSelect.value;

    fetch(`action_waku_override.php?action=get_day_slots&dept=${encodeURIComponent(dept)}&date=${encodeURIComponent(date)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !Array.isArray(data.effective_slots)) return;

            let optionsHtml = '<option value="">-- 選択 --</option>';
            let hasCurrent = false;
            const newMap = {};
            const matchedScheduleIds = new Set();

            data.effective_slots.forEach(slot => {
                const wName = slot.waku_name || '';
                const isSusp = !!slot.is_suspended;
                const isExtra = (!slot.base_waku_id && !!slot.is_override);
                const isSel = (wName === curSelected);
                if (isSel) hasCurrent = true;

                let badgeStr = '';
                if (isSusp) {
                    badgeStr = ' [🚫休止中]';
                } else if (isExtra) {
                    badgeStr = ' [臨時枠]';
                }

                const resList = Array.isArray(slot.reservations) ? slot.reservations : [];
                newMap[wName] = resList;
                resList.forEach(r => {
                    if (r.schedule_id) matchedScheduleIds.add(parseInt(r.schedule_id, 10));
                });

                const resInfo = formatSlotOptionReservationInfo(resList);
                let styleStr = '';
                let disAttr = '';
                if (isSusp && !isSel) {
                    disAttr = 'disabled';
                    styleStr = 'color:#94a3b8; background-color:#fef2f2;';
                } else if (resInfo.hasOther) {
                    styleStr = 'background-color:#fffbeb; color:#92400e; font-weight:600;';
                } else if (resInfo.hasSelf) {
                    styleStr = 'background-color:#dbeafe; color:#1e40af; font-weight:700;';
                }

                const styleAttr = styleStr ? `style="${styleStr}"` : '';
                optionsHtml += `<option value="${escapeHtml(wName)}" ${isSel ? 'selected' : ''} ${disAttr} ${styleAttr}>${escapeHtml(wName + badgeStr + resInfo.suffix)}</option>`;
            });

            // 当日予約のうち、有効枠に含まれない旧世代・未定義枠の予約を集約
            const unmatchedByWaku = {};
            if (Array.isArray(data.day_reservations)) {
                data.day_reservations.forEach(dr => {
                    const sid = parseInt(dr.schedule_id || 0, 10);
                    const sTime = (dr.scheduled_time || '').trim();
                    if (sTime && !matchedScheduleIds.has(sid)) {
                        if (!unmatchedByWaku[sTime]) unmatchedByWaku[sTime] = [];
                        unmatchedByWaku[sTime].push(dr);
                    }
                });
            }

            // 既存の選択値または未定義枠の予約がある場合はフォールバック追加
            const fallbackKeys = Object.keys(unmatchedByWaku);
            if (curSelected && !hasCurrent && !fallbackKeys.includes(curSelected)) {
                fallbackKeys.unshift(curSelected);
            }
            fallbackKeys.forEach(fbWaku => {
                const fbRes = unmatchedByWaku[fbWaku] || [];
                newMap[fbWaku] = fbRes;
                const isSel = (fbWaku === curSelected);
                const resInfo = formatSlotOptionReservationInfo(fbRes);
                let styleStr = '';
                if (resInfo.hasOther) {
                    styleStr = 'background-color:#fffbeb; color:#92400e; font-weight:600;';
                } else if (resInfo.hasSelf) {
                    styleStr = 'background-color:#dbeafe; color:#1e40af; font-weight:700;';
                }
                const styleAttr = styleStr ? `style="${styleStr}"` : '';
                optionsHtml += `<option value="${escapeHtml(fbWaku)}" ${isSel ? 'selected' : ''} ${styleAttr}>${escapeHtml(fbWaku + ' [旧枠/指定枠]' + resInfo.suffix)}</option>`;
            });

            currentDaySlotReservationsMap = newMap;
            wakuSelect.innerHTML = optionsHtml;
            checkDuplicateAndLimits();
        })
        .catch(() => {});
}

let cachedPresentDoctors = [];
let cachedAllDoctors = [];
let isAllDoctorsMode = false;

function updateDoctorDropdown() {
    const date = document.getElementById('exam_date').value;
    const dept = document.getElementById('f_dept').value || 'GASTRO';
    const select = document.getElementById('doctor_name');
    const initialDoctor = select.getAttribute('data-initial-doctor') || '';
    if (!date) return;

    fetch(`get_present_doctors.php?date=${encodeURIComponent(date)}&dept=${encodeURIComponent(dept)}`)
        .then(res => res.json())
        .then(data => {
            let presentDocs = (data && Array.isArray(data.present_doctors)) ? data.present_doctors : ((data && Array.isArray(data.doctors)) ? data.doctors : []);
            let allDocs = (data && Array.isArray(data.all_doctors)) ? data.all_doctors : [];
            currentDoctorCapacityMap = (data && data.doctor_capacity) ? data.doctor_capacity : {};
            currentSlotSummary = (data && data.slot_summary) ? data.slot_summary : null;

            if (dept === 'PROCT' && !presentDocs.includes('誠吾')) {
                presentDocs.unshift('誠吾');
            }
            if (initialDoctor && !presentDocs.includes(initialDoctor)) {
                presentDocs.push(initialDoctor);
            }

            cachedPresentDoctors = presentDocs;
            cachedAllDoctors = allDocs.length > 0 ? allDocs : presentDocs;

            renderDoctorDropdownOptions();
            renderDayCapacitySummaryBar();
            checkDuplicateAndLimits();
        }).catch(() => {});
}

function selectDoctorFromSummaryChip(docName) {
    const select = document.getElementById('doctor_name');
    if (!select) return;
    let found = false;
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value === docName) {
            found = true;
            break;
        }
    }
    if (!found) {
        const opt = document.createElement('option');
        opt.value = docName;
        opt.textContent = docName;
        select.appendChild(opt);
    }
    select.value = docName;
    renderDayCapacitySummaryBar();
    checkDuplicateAndLimits();
}

function renderDayCapacitySummaryBar() {
    const bar = document.getElementById('dayCapacitySummaryBar');
    if (!bar) return;
    const docKeys = Object.keys(currentDoctorCapacityMap || {});
    if (docKeys.length === 0) {
        bar.style.display = 'none';
        bar.innerHTML = '';
        return;
    }

    const curDoc = document.getElementById('doctor_name') ? document.getElementById('doctor_name').value : '';
    let chipsHtml = '';
    docKeys.forEach(docName => {
        const info = currentDoctorCapacityMap[docName];
        if (!info) return;
        const max = info.max || {};
        const used = info.used || {};
        const parts = [];
        ['TCF', 'GIF', 'ECHO'].forEach(grp => {
            const m = parseInt(max[grp] || 0, 10);
            const u = parseInt(used[grp] || 0, 10);
            if (m > 0 || u > 0) {
                const label = (grp === 'TCF') ? 'CF' : ((grp === 'ECHO') ? 'US' : 'GIF');
                const rem = m - u;
                let color = '#15803d';
                if (rem <= 0 && m > 0) color = '#dc2626';
                else if (rem === 1) color = '#b45309';
                parts.push(`<span style="color:${color}; font-weight:700;">${label}:${u}/${m}</span>`);
            }
        });
        if (parts.length === 0) return;

        const isSelectedDoc = (curDoc === docName);
        const bg = isSelectedDoc ? '#fef9c3' : '#ffffff';
        const border = isSelectedDoc ? '1.5px solid #eab308' : '1px solid #cbd5e1';
        chipsHtml += `<button type="button" onclick="selectDoctorFromSummaryChip('${escapeHtml(docName)}')" title="クリックで担当医「${escapeHtml(docName)}」を選択" style="display:inline-flex; align-items:center; gap:4px; padding:1px 6px; margin:1px 3px 1px 0; border-radius:4px; background:${bg}; border:${border}; font-size:10.5px; cursor:pointer; color:#1e293b;">
            <strong>👨‍⚕️${escapeHtml(docName)}</strong> [${parts.join(' ')}]
        </button>`;
    });

    if (!chipsHtml) {
        bar.style.display = 'none';
        return;
    }

    bar.innerHTML = `<span style="font-weight:700; color:#475569; margin-right:4px;">📊 当日枠(使用/上限):</span>${chipsHtml}`;
    bar.style.display = 'block';
}

function renderDoctorDropdownOptions() {
    const select = document.getElementById('doctor_name');
    if (!select) return;
    const currentVal = select.value || select.getAttribute('data-initial-doctor') || '';
    const btn = document.getElementById('btnToggleAllDoctors');

    const list = isAllDoctorsMode ? cachedAllDoctors : cachedPresentDoctors;

    let html = '<option value="">-- 担当未定 --</option>';
    list.forEach(d => {
        if (!d || d === '◆未定◆') return;
        const isSelected = (d === currentVal) ? 'selected' : '';
        html += `<option value="${escapeHtml(d)}" ${isSelected}>${escapeHtml(d)}</option>`;
    });

    // 現在の選択値がリストに含まれていなければ先頭に維持
    if (currentVal && !list.includes(currentVal) && currentVal !== '◆未定◆') {
        html += `<option value="${escapeHtml(currentVal)}" selected>${escapeHtml(currentVal)}</option>`;
    }

    select.innerHTML = html;

    // ボタンのスタイルと文言を更新
    if (btn) {
        if (isAllDoctorsMode) {
            btn.style.background = '#eff6ff';
            btn.style.borderColor = '#3b82f6';
            btn.style.color = '#1d4ed8';
            btn.style.fontWeight = 'bold';
            btn.textContent = '👥 枠あり';
            btn.title = 'クリックで当日の検査枠提供医師のみに戻します';
        } else {
            btn.style.background = '#f1f5f9';
            btn.style.borderColor = '#cbd5e1';
            btn.style.color = '#475569';
            btn.style.fontWeight = '600';
            btn.textContent = '👥 全員';
            btn.title = 'クリックで全医師（他科・非出勤含む）を表示します';
        }
    }
}

function toggleAllDoctorsMode() {
    isAllDoctorsMode = !isAllDoctorsMode;
    renderDoctorDropdownOptions();
}


// 生年月日を西暦(和暦)-月-日 形式（例: 1967(S42)-04-28）に変換（JS版）
function formatBirthdayWithWarekiJS(birthdayStr) {
    if (!birthdayStr) return '';
    const clean = birthdayStr.substring(0, 10);
    const parts = clean.split('-');
    if (parts.length !== 3) return birthdayStr;
    const y = parseInt(parts[0], 10);
    const m = parts[1];
    const d = parts[2];
    if (isNaN(y)) return birthdayStr;

    let era = 'M';
    let wYear = y - 1867;
    if (clean >= '2019-05-01') {
        era = 'R';
        wYear = y - 2018;
    } else if (clean >= '1989-01-08') {
        era = 'H';
        wYear = y - 1988;
    } else if (clean >= '1926-12-25') {
        era = 'S';
        wYear = y - 1925;
    } else if (clean >= '1912-07-30') {
        era = 'T';
        wYear = y - 1911;
    }
    const wYearStr = String(wYear).padStart(2, '0');
    return `${y}(${era}${wYearStr})-${m}-${d}`;
}

// 年齢計算とバッジ生成（検査予約日基準）
function calculateAgeAndBadge(birthdayStr) {
    if (!birthdayStr) return { age: null, badgeHtml: '' };
    const bClean = birthdayStr.substring(0, 10);
    const parts = bClean.split('-');
    if (parts.length !== 3) return { age: null, badgeHtml: '' };
    const bDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    
    const examDateInput = document.getElementById('exam_date');
    const examDateVal = examDateInput ? examDateInput.value : '';
    let eDate = new Date();
    if (examDateVal) {
        const eParts = examDateVal.substring(0, 10).split('-');
        if (eParts.length === 3) {
            eDate = new Date(parseInt(eParts[0], 10), parseInt(eParts[1], 10) - 1, parseInt(eParts[2], 10));
        }
    }
    
    let age = eDate.getFullYear() - bDate.getFullYear();
    const m = eDate.getMonth() - bDate.getMonth();
    if (m < 0 || (m === 0 && eDate.getDate() < bDate.getDate())) {
        age--;
    }
    if (age < 0) age = 0;
    const cls = (age >= 70) ? 'age-senior' : 'age-under';
    const badgeHtml = `<span class="age-box ${cls}">${age}歳</span>`;
    return { age, cls, badgeHtml };
}

// 住所クリックコピー
function copyPatientAddress(el) {
    const raw = el.getAttribute('data-address') || (el.innerText || '').replace(/^🏠\s*住所:\s*/, '').trim();
    if (!raw || raw.includes('照会中') || raw.includes('登録なし')) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(raw).then(() => {
            showAddressCopiedToast(el);
        }).catch(() => {
            fallbackCopyAddress(raw, el);
        });
    } else {
        fallbackCopyAddress(raw, el);
    }
}

// 電話番号クリックコピー
function copyPatientTel(el) {
    const raw = el.getAttribute('data-tel') || (el.innerText || '').replace(/^📞\s*(TEL:\s*)?/, '').trim();
    if (!raw || raw.includes('照会中') || raw.includes('登録なし')) return;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(raw).then(() => {
            showAddressCopiedToast(el);
        }).catch(() => {
            fallbackCopyAddress(raw, el);
        });
    } else {
        fallbackCopyAddress(raw, el);
    }
}

function showAddressCopiedToast(el) {
    let oldToast = el.querySelector('.inline-copy-toast');
    if (oldToast) oldToast.remove();

    const toast = document.createElement('span');
    toast.className = 'inline-copy-toast';
    toast.textContent = 'コピー完了！';
    el.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 1500);
}

function fallbackCopyAddress(text, el) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        showAddressCopiedToast(el);
    } catch(e) {}
    document.body.removeChild(ta);
}

// 患者情報カード描画
function renderPatientCard(data) {
    const container = document.getElementById('patientInfoContent');
    if (!container) return;

    if (!data || !data.patient_name) {
        container.innerHTML = `
            <div class="patient-empty-guide">
                <span>👈 左側で <strong>患者No</strong> を入力して <strong>[同期]</strong> または <strong>Enter</strong> を押してください。</span>
                <span style="color:#64748b; font-size:10.5px;">（仮の予約は [⚡ 仮予約]、直接入力は [✏️ 手入力]）</span>
            </div>
        `;
        return;
    }

    const name = escapeHtml(data.patient_name);
    const kana = data.patient_kana ? `<span class="patient-card-kana">(${escapeHtml(data.patient_kana)})</span>` : '';
    const pidBadge = data.patient_id ? `<span class="patient-card-id-badge">No: ${escapeHtml(data.patient_id)}</span>` : '';
    
    let genderTag = '';
    if (data.gender) {
        const gShort = data.gender.includes('女') ? '女' : (data.gender.includes('男') ? '男' : data.gender);
        const gCls = gShort === '女' ? 'gender-female' : 'gender-male';
        genderTag = `<span class="gender-tag ${gCls}">${escapeHtml(gShort)}</span>`;
    }

    let agePart = '';
    let bdayPart = '';
    if (data.birthday) {
        const ageInfo = calculateAgeAndBadge(data.birthday);
        if (ageInfo.badgeHtml) {
            agePart = `<span style="color:#94a3b8;">/</span> ${ageInfo.badgeHtml}`;
        }
        const warekiStr = formatBirthdayWithWarekiJS(data.birthday);
        if (warekiStr) {
            bdayPart = `<span style="color:#94a3b8;">/</span> <span class="patient-card-birthday">(生年月日: ${escapeHtml(warekiStr)})</span>`;
        }
    }

    const addrStr = data.address ? escapeHtml(data.address) : '<span style="color:#94a3b8;">(登録なし)</span>';
    const rawAddrForCopy = data.address || '';

    const telStr = data.tel ? escapeHtml(data.tel) : '<span style="color:#94a3b8;">(登録なし)</span>';
    const rawTelForCopy = data.tel || '';

    // 最終受診日・診療科バッジ生成
    let lastVisitBadgeHtml = '';
    if (data.last_visit_date) {
        const deptStr = data.last_dept_name ? ` (${escapeHtml(data.last_dept_name)})` : '';
        if (data.is_today_visit) {
            lastVisitBadgeHtml = `<span class="visit-badge visit-today" title="本日電子カルテで受診実績があります">🔴 本日受診${deptStr}</span>`;
        } else {
            const dStr = escapeHtml(data.last_visit_date.replace(/-/g, '/'));
            lastVisitBadgeHtml = `<span class="visit-badge visit-past" title="最終受診日: ${dStr}${deptStr}">📅 最終: ${dStr}${deptStr}</span>`;
        }
    } else if (data.last_visit_date === null && data.patient_id) {
        lastVisitBadgeHtml = `<span class="visit-badge visit-none" title="過去の電子カルテ受診実績なし（新患）">最終: なし(新患)</span>`;
    } else {
        lastVisitBadgeHtml = `<span class="visit-badge visit-none">照会中...</span>`;
    }

    container.innerHTML = `
        <div class="patient-card-main-info">
            <div class="patient-info-row1">
                <span class="patient-card-name">${name}</span>
                ${kana}
                ${pidBadge}
                ${genderTag}
                ${agePart}
                ${bdayPart}

            </div>
            <div class="patient-info-row2">
                <span class="patient-address-click" data-address="${escapeHtml(rawAddrForCopy)}" onclick="copyPatientAddress(this)" title="クリックして住所をコピー">
                    🏠 住所: ${addrStr}
                </span>
                <span class="patient-tel-click" data-tel="${escapeHtml(rawTelForCopy)}" onclick="copyPatientTel(this)" title="クリックして電話番号をコピー">
                    📞 ${telStr}
                </span>
            </div>
        </div>
        <!-- 右側独立アクション（電カルボタン＆最終受診日・科） -->
        <div class="patient-card-side-actions" id="cardSideActions">
            <button type="button" class="btn btn-karte-link-prominent" onclick="openMallSoapViewer()" title="Webカルテビューアを表示">📄 電カル ↗</button>
            <div class="patient-last-visit-badge" id="cardLastVisitBadge" title="電子カルテ上の最終受診実績">
                ${lastVisitBadgeHtml}
            </div>
        </div>
    `;
}

// カルテ同期実行（[同期]ボタン押下 or Enterキー押下）
function doSyncPatient() {
    const pidInput = document.getElementById('patient_id');
    const pid = pidInput ? pidInput.value.trim() : '';

    toggleManualInput(false);

    if (!pid) {
        document.getElementById('patient_name').value = '';
        document.getElementById('patient_kana').value = '';
        document.getElementById('birthday').value = '';
        renderPatientCard(null);
        clearMallMemoDisplay();
        checkMedicalAlertForPatient('');
        return;
    }

    // ローディング表示
    const container = document.getElementById('patientInfoContent');
    if (container) {
        container.innerHTML = `
            <div class="patient-loading-guide">
                <span class="spinner-small" style="display:inline-block; width:12px; height:12px; border:2px solid #0284c7; border-top-color:transparent; border-radius:50%; animation:spin 0.8s linear infinite;"></span>
                <span>MALL3から患者情報を照会中... (No: ${escapeHtml(pid)})</span>
            </div>
        `;
    }

    fetch(`get_mall_patient.php?patient_no=${encodeURIComponent(pid)}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                closeVirtualKeyboard();
                if (pidInput && document.activeElement === pidInput) {
                    pidInput.blur();
                }

                document.getElementById('patient_name').value = data.patient_name || '';
                document.getElementById('patient_kana').value = data.patient_kana || '';
                document.getElementById('birthday').value = data.birthday ? data.birthday.substring(0, 10) : '';

                renderPatientCard({
                    patient_id: pid,
                    patient_name: data.patient_name,
                    patient_kana: data.patient_kana,
                    birthday: data.birthday,
                    gender: data.gender,
                    address: data.address,
                    tel: data.tel,
                    last_visit_date: data.last_visit_date,
                    last_dept_name: data.last_dept_name,
                    is_today_visit: !!data.is_today_visit
                });

                reloadMallMemo();
                checkMedicalAlertForPatient(pid);
                checkDuplicateAndLimits();
            } else {
                if (container) {
                    container.innerHTML = `
                        <div class="patient-error-guide">
                            <span style="font-weight:700;">⚠️ 該当する患者が見つかりませんでした (No: ${escapeHtml(pid)})</span>
                            <span style="color:#64748b; font-size:10.5px;">番号をお確かめいただくか、[🔍 氏名検索] または [✏️ 手入力] をご利用ください。</span>
                        </div>
                    `;
                }
            }
        })
        .catch(err => {
            if (container) {
                container.innerHTML = `
                    <div class="patient-error-guide">
                        <span style="font-weight:700;">⚠️ 通信エラーが発生しました</span>
                        <span style="color:#64748b; font-size:10.5px;">電子カルテとの通信に失敗しました。再試行するか [✏️ 手入力] をご利用ください。</span>
                    </div>
                `;
            }
        });
}

// 既存予約表示時に住所・性別のみを非同期補完する関数
function fetchPatientDetailsAndUpdateCard(pid) {
    if (!pid) return;
    fetch(`get_mall_patient.php?patient_no=${encodeURIComponent(pid)}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                const pName = document.getElementById('patient_name').value || data.patient_name;
                const pKana = document.getElementById('patient_kana').value || data.patient_kana;
                const pBday = document.getElementById('birthday').value || data.birthday;

                renderPatientCard({
                    patient_id: pid,
                    patient_name: pName,
                    patient_kana: pKana,
                    birthday: pBday,
                    gender: data.gender,
                    address: data.address,
                    tel: data.tel,
                    last_visit_date: data.last_visit_date,
                    last_dept_name: data.last_dept_name,
                    is_today_visit: !!data.is_today_visit
                });
            }
        })
        .catch(() => {});
}

// 互換性のための lookupMallPatient
function lookupMallPatient(pid) {
    doSyncPatient();
}

// 手入力モードの切り替え
function toggleManualInput(show) {
    const cardDisplay = document.getElementById('patientCardDisplay');
    const manualBox = document.getElementById('patientManualInputBox');
    if (!cardDisplay || !manualBox) return;

    if (show) {
        closeVirtualKeyboard();
        cardDisplay.style.display = 'none';
        manualBox.style.display = 'flex';
        document.getElementById('manual_patient_name').value = document.getElementById('patient_name').value;
        document.getElementById('manual_patient_kana').value = document.getElementById('patient_kana').value;
        document.getElementById('manual_birthday').value = document.getElementById('birthday').value;
        updateManualAgeBadge();
        document.getElementById('manual_patient_name').focus();
    } else {
        cardDisplay.style.display = 'flex';
        manualBox.style.display = 'none';
    }
}

// 手入力完了（カード表示に戻る）
function finishManualInput() {
    syncManualToHidden();
    const pName = document.getElementById('patient_name').value.trim();
    const pKana = document.getElementById('patient_kana').value.trim();
    const pBday = document.getElementById('birthday').value.trim();
    const pid = document.getElementById('patient_id').value.trim();

    toggleManualInput(false);

    if (pName) {
        renderPatientCard({
            patient_id: pid,
            patient_name: pName,
            patient_kana: pKana,
            birthday: pBday,
            gender: '',
            address: '',
            tel: ''
        });
    } else {
        renderPatientCard(null);
    }
}

// 手入力フォームから hidden への同期
function syncManualToHidden() {
    const name = document.getElementById('manual_patient_name').value;
    const kana = document.getElementById('manual_patient_kana').value;
    const bday = document.getElementById('manual_birthday').value;

    document.getElementById('patient_name').value = name;
    document.getElementById('patient_kana').value = kana;
    document.getElementById('birthday').value = bday;

    updateManualAgeBadge();
}

function updateManualAgeBadge() {
    const bday = document.getElementById('manual_birthday').value;
    const badgeSpan = document.getElementById('manualAgeBadge');
    if (!badgeSpan) return;
    if (bday) {
        const info = calculateAgeAndBadge(bday);
        badgeSpan.innerHTML = info.badgeHtml;
    } else {
        badgeSpan.innerHTML = '';
    }
}

// 仮予約設定
function setTemporaryPatient() {
    document.getElementById('patient_id').value = '';
    document.getElementById('patient_name').value = '※仮予約';
    document.getElementById('patient_kana').value = 'カリヨヤク';
    document.getElementById('birthday').value = '';

    toggleManualInput(true);
    document.getElementById('manual_patient_name').value = '※仮予約';
    document.getElementById('manual_patient_kana').value = 'カリヨヤク';
    document.getElementById('manual_birthday').value = '';
    updateManualAgeBadge();

    clearMallMemoDisplay();
    resetMedicalAlertControls();
}

function changeDept(d) {
    const u = new URL(window.location.href);
    u.searchParams.set('dept', d);
    window.location.href = u.toString();
}

function openPatientSearch() {
    window.open('patient_search.php', '_blank', 'width=1000,height=700,scrollbars=yes,resizable=yes');
}

function selectPatientFromSearch(patientNo, patientName, birthday, kana) {
    closeVirtualKeyboard();
    document.getElementById('patient_id').value = patientNo || '';
    document.getElementById('patient_name').value = patientName || '';
    document.getElementById('patient_kana').value = kana || '';
    document.getElementById('birthday').value = (birthday && birthday.length >= 10) ? birthday.substring(0, 10) : (birthday || '');
    
    toggleManualInput(false);
    if (patientNo) {
        doSyncPatient();
    } else {
        renderPatientCard({
            patient_id: '',
            patient_name: patientName,
            patient_kana: kana,
            birthday: birthday,
            gender: '',
            address: '',
            tel: ''
        });
        reloadMallMemo();
        checkDuplicateAndLimits();
    }
}

function resetMedicalAlertControls() {
    ['has_antithrombotic', 'has_diabetes', 'has_psychotropic', 'has_nsaids', 'has_allergy', 'has_infection', 'has_cold_fever', 'has_sedation', 'has_drive_caution'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.checked = false;
            if (el.parentElement) el.parentElement.classList.remove('is-checked');
        }
    });
    const hint = document.getElementById('alertDetectHint');
    if (hint) {
        hint.style.display = 'none';
        hint.innerHTML = '';
    }
    const onshiHint = document.getElementById('onshiDetectHint');
    if (onshiHint) {
        onshiHint.style.display = 'none';
        onshiHint.innerHTML = '';
    }
}

function setAlertCheckbox(id, checked) {
    const el = document.getElementById(id);
    if (!el) return;
    el.checked = checked;
    if (el.parentElement) {
        el.parentElement.classList.toggle('is-checked', checked);
    }
}

function checkMedicalAlertForPatient(pid) {
    const hint = document.getElementById('alertDetectHint');
    if (!pid || pid === '---') {
        if (hint) {
            hint.style.display = 'none';
            hint.innerHTML = '';
        }
        if (isNew) resetMedicalAlertControls();
        return;
    }

    fetch(`action_medical_alert.php?action=scan&patient_id=${encodeURIComponent(pid)}`)
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success || !data.candidates || data.candidates.length === 0) {
                if (hint) {
                    hint.style.display = 'none';
                    hint.innerHTML = '';
                }
                return;
            }

            const cand = data.candidates[0];
            const detectedItems = [];

            // 新規予約時は検知されたアラートを自動で初期チェックONにする
            if (isNew) {
                if (cand.has_antithrombotic) {
                    setAlertCheckbox('has_antithrombotic', true);
                }
                if (cand.has_diabetes) {
                    setAlertCheckbox('has_diabetes', true);
                }
                if (cand.has_psychotropic) {
                    setAlertCheckbox('has_psychotropic', true);
                }
                if (cand.has_nsaids) {
                    setAlertCheckbox('has_nsaids', true);
                }
                if (cand.has_allergy) {
                    setAlertCheckbox('has_allergy', true);
                }
                if (cand.has_infection) {
                    setAlertCheckbox('has_infection', true);
                }
                if (cand.has_cold_fever) {
                    setAlertCheckbox('has_cold_fever', true);
                }
            }

            // 根拠キーワードのヒント組み立て
            if (cand.reasons) {
                if (cand.reasons.antithrombotic && cand.reasons.antithrombotic.length > 0) {
                    const snips = cand.reasons.antithrombotic.map(r => r.word).join(', ');
                    detectedItems.push(`💊抗血栓 [${escapeHtml(snips)}]`);
                }
                if (cand.reasons.diabetes && cand.reasons.diabetes.length > 0) {
                    const snips = cand.reasons.diabetes.map(r => r.word).join(', ');
                    detectedItems.push(`💉糖尿病 [${escapeHtml(snips)}]`);
                }
                if (cand.reasons.psychotropic && cand.reasons.psychotropic.length > 0) {
                    const snips = cand.reasons.psychotropic.map(r => r.word).join(', ');
                    detectedItems.push(`🧠向精神薬 [${escapeHtml(snips)}]`);
                }
                if (cand.reasons.nsaids && cand.reasons.nsaids.length > 0) {
                    const snips = cand.reasons.nsaids.map(r => r.word).join(', ');
                    detectedItems.push(`💊NSAIDs [${escapeHtml(snips)}]`);
                }
                if (cand.reasons.allergy && cand.reasons.allergy.length > 0) {
                    const snips = cand.reasons.allergy.map(r => r.word).join(', ');
                    detectedItems.push(`⚠️アレルギー [${escapeHtml(snips)}]`);
                }
                if (cand.reasons.infection && cand.reasons.infection.length > 0) {
                    const snips = cand.reasons.infection.map(r => r.word).join(', ');
                    detectedItems.push(`🔴感染症 [${escapeHtml(snips)}]`);
                }
                if (cand.reasons.cold_fever && cand.reasons.cold_fever.length > 0) {
                    const snips = cand.reasons.cold_fever.map(r => r.word).join(', ');
                    detectedItems.push(`😷風邪・発熱 [${escapeHtml(snips)}]`);
                }
            }

            if (hint) {
                if (detectedItems.length > 0) {
                    hint.innerHTML = `<strong>💡 電カル・メモ検知情報:</strong> ${detectedItems.join(' / ')} <span style="font-weight:normal; color:#64748b;">(必要に応じてチェックを調整してください)</span>`;
                    hint.style.display = 'block';
                } else {
                    hint.style.display = 'none';
                    hint.innerHTML = '';
                }
            }
        })
        .catch(() => {
            if (hint) {
                hint.style.display = 'none';
                hint.innerHTML = '';
            }
        });
}

/**
 * 🌐 オン資照会（任意実行アクション）
 * オンライン資格確認APIから直近処方薬・リスクを照会し、チェックボックスを推奨ON＆根拠を表示
 */
function queryOnshiForEditModal() {
    const pidInput = document.getElementById('patient_id');
    const pid = pidInput ? pidInput.value.trim() : '';
    if (!pid || pid === '---') {
        alert('先に患者Noを入力（またはカルテ同期）してください。');
        if (pidInput) pidInput.focus();
        return;
    }

    const btn = document.getElementById('btnQueryOnshiEdit');
    const icon = document.getElementById('btnQueryOnshiEditIcon');
    const text = document.getElementById('btnQueryOnshiEditText');
    const onshiHint = document.getElementById('onshiDetectHint');

    if (btn) btn.disabled = true;
    if (icon) icon.textContent = '⏳';
    if (text) text.textContent = '照会中...';

    fetch(`action_medical_alert.php?action=scan&patient_id=${encodeURIComponent(pid)}&include_onshi=1&months=6`)
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success || !data.candidates || data.candidates.length === 0) {
                if (onshiHint) {
                    onshiHint.style.display = 'block';
                    onshiHint.innerHTML = '🌐 <strong>オン資照会:</strong> 該当する処方データが取得できませんでした。';
                }
                return;
            }

            const cand = data.candidates[0];
            const onshiInfo = cand.onshi_info || null;
            const detectedMedRisks = [];

            // オン資から検出されたリスクがあればチェックボックスをONにし、通知リストに追加
            if (cand.has_antithrombotic) {
                setAlertCheckbox('has_antithrombotic', true);
                detectedMedRisks.push('💊抗血栓薬');
            }
            if (cand.has_diabetes) {
                setAlertCheckbox('has_diabetes', true);
                detectedMedRisks.push('💉糖尿病薬');
            }
            if (cand.has_psychotropic) {
                setAlertCheckbox('has_psychotropic', true);
                detectedMedRisks.push('🧠向精神薬・睡眠薬');
            }
            if (cand.has_nsaids) {
                setAlertCheckbox('has_nsaids', true);
                detectedMedRisks.push('💊NSAIDs消炎鎮痛');
            }

            // オン資の詳細（同意状況、検出薬剤、KarteViewリンク）の組み立て
            let html = '';
            if (onshiInfo) {
                let statusBadge = '';
                if (!onshiInfo.has_consent) {
                    statusBadge = '<span style="display:inline-block; padding:1px 5px; border-radius:3px; background:#f1f5f9; color:#475569; font-size:10px; font-weight:700; border:1px solid #cbd5e1;">⚠️ 同意なし/未確認</span>';
                } else if (!onshiInfo.has_records) {
                    statusBadge = '<span style="display:inline-block; padding:1px 5px; border-radius:3px; background:#f1f5f9; color:#475569; font-size:10px; font-weight:700; border:1px solid #cbd5e1;">処方データなし</span>';
                } else {
                    statusBadge = '<span style="display:inline-block; padding:1px 5px; border-radius:3px; background:#dcfce7; color:#166534; font-size:10px; font-weight:700; border:1px solid #86efac;">✅ 照会成功</span>';
                }

                const kvUrl = onshiInfo.viewer_url || `http://127.0.0.1/karteview/onshi_yakuzai.php?patient_no=${encodeURIComponent(pid)}&source=new`;
                const kvLink = `<a href="${escapeHtml(kvUrl)}" target="_blank" rel="noopener noreferrer" style="color:#2563eb; text-decoration:underline; font-weight:700; margin-left:8px;" title="オンライン資格確認 薬剤情報ビューアを開く (別タブ)">📋 オン資処方一覧 ↗</a>`;

                html += `<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
                    <div>🌐 <strong>オン資照会結果 (直近6ヶ月):</strong> ${statusBadge} ${kvLink}</div>
                </div>`;

                if (detectedMedRisks.length > 0) {
                    // 検出された薬剤のハイライト表示
                    const drugNames = [];
                    if (cand.reasons) {
                        ['antithrombotic', 'diabetes', 'psychotropic', 'nsaids'].forEach(grp => {
                            if (cand.reasons[grp]) {
                                cand.reasons[grp].forEach(r => {
                                    if (r.is_onshi || r.type === 'onshi') {
                                        const dStr = r.date ? ` (${r.date})` : '';
                                        const cStr = r.chozai ? ` [${escapeHtml(r.chozai)}]` : '';
                                        drugNames.push(`<strong>${escapeHtml(r.word)}</strong>${dStr}${cStr}`);
                                    }
                                });
                            }
                        });
                    }
                    html += `<div style="color:#b91c1c; font-weight:600;">⚠️ 検出リスク: ${detectedMedRisks.join(' / ')}</div>`;
                    if (drugNames.length > 0) {
                        html += `<div style="font-size:10.5px; color:#475569; margin-top:2px; line-height:1.4;">処方薬剤: ${drugNames.slice(0, 5).join(', ')}${drugNames.length > 5 ? ' ほか' : ''}</div>`;
                    }
                } else if (onshiInfo.has_records) {
                    html += `<div style="color:#15803d;">✅ リスク薬剤（抗血栓薬、糖尿病薬、向精神薬、NSAIDs）は検出されませんでした。</div>`;
                }
            } else {
                html += `<div>🌐 <strong>オン資照会:</strong> 処方データはありませんでした。</div>`;
            }

            if (onshiHint) {
                onshiHint.innerHTML = html;
                onshiHint.style.display = 'block';
            }
        })
        .catch(err => {
            console.error('オン資照会エラー:', err);
            if (onshiHint) {
                onshiHint.innerHTML = '⚠️ オン資照会中にエラーが発生しました（通信失敗等）。';
                onshiHint.style.display = 'block';
            }
        })
        .finally(() => {
            if (btn) btn.disabled = false;
            if (icon) icon.textContent = '🌐';
            if (text) text.textContent = 'オン資照会';
        });
}

/**
 * 🛡️ modal_edit.php から modal_medical_alert.php を開く (単一患者詳細判定)
 */
function openMedicalAlertFromEditModal() {
    const pidInput = document.getElementById('patient_id');
    const pid = pidInput ? pidInput.value.trim() : '';
    if (!pid || pid === '---') {
        alert('先に患者Noを入力（またはカルテ同期）してください。');
        if (pidInput) pidInput.focus();
        return;
    }

    const examDate = document.getElementById('exam_date')?.value || '';
    const dept = document.getElementById('department_code')?.value || 'GI';
    const sid = parseInt('<?= (int)$schedule_id ?>', 10);

    let url = `modal_medical_alert.php?patient_id=${encodeURIComponent(pid)}&dept=${encodeURIComponent(dept)}&date=${encodeURIComponent(examDate)}`;
    if (sid > 0) {
        url += `&schedule_ids=${sid}`;
    }

    let dlg = document.getElementById('modalEditSubMedicalAlertDlg');
    if (!dlg) {
        dlg = document.createElement('dialog');
        dlg.id = 'modalEditSubMedicalAlertDlg';
        dlg.style.cssText = `
            width: 94vw;
            max-width: 980px;
            height: 90vh;
            border: none;
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            overflow: hidden;
            background: #ffffff;
            z-index: 99999;
        `;
        document.body.appendChild(dlg);
    }

    dlg.innerHTML = `
        <iframe src="${url}" style="width:100%; height:100%; border:none; display:block;"></iframe>
    `;

    dlg.addEventListener('close', () => {
        dlg.innerHTML = '';
    }, { once: true });

    dlg.showModal();
}

// modal_medical_alert.php からの postMessage 受信リスナー
window.addEventListener('message', function(event) {
    if (!event.data || typeof event.data !== 'object') return;

    if (event.data.type === 'medical_alert_close') {
        const dlg = document.getElementById('modalEditSubMedicalAlertDlg');
        if (dlg && dlg.open) dlg.close();
    } else if (event.data.type === 'medical_alert_saved') {
        const dlg = document.getElementById('modalEditSubMedicalAlertDlg');
        if (dlg && dlg.open) dlg.close();

        // 反映されたアラートデータを受信して modal_edit 側のチェックボックスを同期更新
        const alertItem = event.data.firstAlert || (event.data.alerts && event.data.alerts[0]);
        if (alertItem) {
            setAlertCheckbox('has_antithrombotic', !!alertItem.has_antithrombotic);
            setAlertCheckbox('has_diabetes', !!alertItem.has_diabetes);
            setAlertCheckbox('has_psychotropic', !!alertItem.has_psychotropic);
            setAlertCheckbox('has_nsaids', !!alertItem.has_nsaids);
            setAlertCheckbox('has_allergy', !!alertItem.has_allergy);
            setAlertCheckbox('has_infection', !!alertItem.has_infection);
            setAlertCheckbox('has_cold_fever', !!alertItem.has_cold_fever);

            const onshiHint = document.getElementById('onshiDetectHint');
            if (onshiHint) {
                onshiHint.innerHTML = '🛡️ <strong>アラート詳細判定:</strong> 判定結果をフォームに同期反映しました。';
                onshiHint.style.display = 'block';
            }
        }
    }
});

const isWithin10Min = <?= !empty($is_within_10min) ? 'true' : 'false' ?>;

function selectQuickWaku(wakuName) {
    const wakuSelect = document.getElementById('scheduled_time');
    if (!wakuSelect) return;
    wakuSelect.value = wakuName;
    handleWakuChange(wakuName);
    if (typeof showToast === 'function') {
        showToast(`✅ 時間枠を「${wakuName}」へ変更しました`);
    }
}

function checkDuplicateAndLimits() {
    const alertBox = document.getElementById('duplicateAlert');
    if (!alertBox) return;

    const wakuSelect = document.getElementById('scheduled_time');
    const selectedWaku = wakuSelect ? wakuSelect.value : '';
    const messages = [];

    // 0. 旧世代枠・未定義枠（[旧枠/指定枠]）が選択されている場合、新枠へのワンクリック振替候補を提示
    if (wakuSelect && selectedWaku && wakuSelect.selectedIndex >= 0) {
        const selOpt = wakuSelect.options[wakuSelect.selectedIndex];
        const selText = selOpt ? (selOpt.text || '') : '';
        if (selText.includes('[旧枠/指定枠]') || selText.includes('(未定義/指定枠)')) {
            const timeMatch = selectedWaku.match(/(\d{1,2}:\d{2})/);
            const timePrefix = timeMatch ? timeMatch[1] : '';
            const isTcfOld = selectedWaku.toUpperCase().includes('TCF') || selectedWaku.toUpperCase().includes('CF');
            const isGifOld = selectedWaku.toUpperCase().includes('GIF');

            const candidateSlots = [];
            for (let i = 0; i < wakuSelect.options.length; i++) {
                const opt = wakuSelect.options[i];
                const val = opt.value || '';
                const txt = opt.text || '';
                if (!val || opt.disabled || txt.includes('[旧枠/指定枠]') || txt.includes('(未定義/指定枠)')) continue;
                if (timePrefix && val.includes(timePrefix)) {
                    candidateSlots.push(val);
                } else if (!timePrefix && ((isTcfOld && val.toUpperCase().includes('TCF')) || (isGifOld && val.toUpperCase().includes('GIF')))) {
                    candidateSlots.push(val);
                }
            }

            let quickBtnsHtml = '';
            if (candidateSlots.length > 0) {
                quickBtnsHtml = candidateSlots.map(cand => {
                    const resArr = (currentDaySlotReservationsMap && Array.isArray(currentDaySlotReservationsMap[cand])) ? currentDaySlotReservationsMap[cand] : [];
                    const others = resArr.filter(r => parseInt(r.schedule_id || 0, 10) !== currentScheduleId);
                    const isEmpty = (others.length === 0);
                    const btnBg = isEmpty ? '#dcfce7' : '#fef3c7';
                    const btnBorder = isEmpty ? '#22c55e' : '#f59e0b';
                    const btnColor = isEmpty ? '#166534' : '#92400e';
                    const statusLabel = isEmpty ? '空' : (`${others[0].patient_name || '予約あり'}`);
                    return `<button type="button" onclick="selectQuickWaku('${escapeHtml(cand)}')" style="display:inline-flex; align-items:center; gap:3px; padding:1px 7px; margin:1px 3px; border-radius:4px; background:${btnBg}; border:1px solid ${btnBorder}; color:${btnColor}; font-weight:700; font-size:11px; cursor:pointer;">👉 ${escapeHtml(cand)} (${escapeHtml(statusLabel)})</button>`;
                }).join('');
            }
            messages.push(`🔄 旧世代枠「<strong>${escapeHtml(selectedWaku)}</strong>」が選択されています。${quickBtnsHtml ? '新枠へワンクリック振替: ' + quickBtnsHtml : '時間枠リストから新枠を選択して振り替え可能です。'}`);
        }
    }

    // 1. 選択した時間枠に既に別の患者が登録されているかチェック
    if (selectedWaku && currentDaySlotReservationsMap && Array.isArray(currentDaySlotReservationsMap[selectedWaku])) {
        const others = currentDaySlotReservationsMap[selectedWaku].filter(r => parseInt(r.schedule_id || 0, 10) !== currentScheduleId);
        if (others.length > 0) {
            const names = others.map(r => {
                const pn = (r.patient_name || '').trim() || ('ID:' + (r.patient_id || '?'));
                const doc = (r.doctor_name || '').trim();
                const et = (r.exam_type || '').trim();
                const meta = [et, (doc && doc !== '◆未定◆') ? doc : ''].filter(Boolean).join('/');
                return meta ? `<strong>${escapeHtml(pn)} 様</strong> (${escapeHtml(meta)})` : `<strong>${escapeHtml(pn)} 様</strong>`;
            });
            messages.push(`ℹ️ 時間枠「<strong>${escapeHtml(selectedWaku)}</strong>」には既に ${names.join('・')} が登録されています <span style="font-size:11px; color:#64748b;">（※イレギュラー等の複数枠予約としてそのまま保存可能です）</span>`);
        }
    }

    // 2. 担当医師の当日枠上限チェック
    const docSelect = document.getElementById('doctor_name');
    const examSelect = document.getElementById('exam_type');
    const selectedDoc = docSelect ? docSelect.value : '';
    const selectedExam = examSelect ? examSelect.value : '';
    if (selectedDoc && selectedDoc !== '◆未定◆' && currentDoctorCapacityMap && currentDoctorCapacityMap[selectedDoc]) {
        const cap = currentDoctorCapacityMap[selectedDoc];
        let grp = 'GIF';
        if (['TCF', 'W', 'ポリペク', '浣腸CF'].includes(selectedExam)) {
            grp = 'TCF';
        } else if (selectedExam === 'エコー' || selectedExam.toUpperCase() === 'ECHO' || selectedExam.toUpperCase() === 'US') {
            grp = 'ECHO';
        }
        const maxCnt = parseInt((cap.max && cap.max[grp]) || 0, 10);
        const usedCnt = parseInt((cap.used && cap.used[grp]) || 0, 10);
        // 新規予約（または別医師への変更）で既に上限に達している場合
        const initialDoc = docSelect ? (docSelect.getAttribute('data-initial-doctor') || '') : '';
        const isAddingToDoc = (currentScheduleId === 0 || selectedDoc !== initialDoc);
        if (isAddingToDoc && maxCnt > 0 && usedCnt >= maxCnt) {
            const grpLabel = (grp === 'TCF') ? 'CF' : ((grp === 'ECHO') ? 'エコー' : 'GIF');
            messages.push(`⚠️ <strong>${escapeHtml(selectedDoc)}</strong> 先生の当日${grpLabel}枠は上限に達しています（現在 <strong>${usedCnt}/${maxCnt}件</strong>・枠外超過として保存可能）`);
        }
    }

    if (messages.length > 0) {
        alertBox.innerHTML = messages.join('<br>');
        alertBox.style.display = 'block';
    } else {
        alertBox.innerHTML = '';
        alertBox.style.display = 'none';
    }
}

function deleteReservation(sid) {
    if (isWithin10Min) {
        // 10分以内の即時取消・打ち直し
        if (!confirm('この予約は登録から10分以内のため【誤登録・即時取消】として削除します。\n（※キャンセル速報には通知されません）\n\nよろしいですか？')) {
            return;
        }
        executeDelete(sid, 'auto_mistake');
    } else {
        // 10分超経過の確定予約は削除理由選択モーダルを表示
        openDeleteReasonModal(sid);
    }
}

function openDeleteReasonModal(sid) {
    const modal = document.getElementById('deleteReasonModal');
    if (!modal) return;
    document.getElementById('deleteTargetSid').value = sid;
    modal.classList.add('active');
}

function closeDeleteReasonModal() {
    const modal = document.getElementById('deleteReasonModal');
    if (modal) modal.classList.remove('active');
}

function handleDeleteOverlayClick(e) {
    if (e.target.id === 'deleteReasonModal') {
        closeDeleteReasonModal();
    }
}

function selectDeleteReason(reason) {
    const sid = document.getElementById('deleteTargetSid').value;
    if (!sid) return;
    closeDeleteReasonModal();
    executeDelete(sid, reason);
}

function executeDelete(sid, reason) {
    const btn = document.querySelector('.btn-danger');
    if (btn) {
        btn.disabled = true;
        btn.textContent = '⏳ 削除中...';
    }

    const fd = new FormData();
    fd.append('action_type', 'delete');
    fd.append('schedule_id', sid);
    fd.append('cancel_reason', reason);

    fetch('action_schedule.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(d => {
            if (d && d.success) {
                closeModalWindow(true);
            } else {
                alert('削除に失敗しました: ' + (d.message || '不明なエラー'));
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '🗑️ 削除';
                }
            }
        })
        .catch(err => {
            alert('通信エラーが発生しました: ' + err.message);
            if (btn) {
                btn.disabled = false;
                btn.textContent = '🗑️ 削除';
            }
        });
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<!-- ── 予約削除理由 選択モーダル ── -->
<div id="deleteReasonModal" class="delete-reason-overlay" onclick="handleDeleteOverlayClick(event)">
    <div class="delete-reason-modal" onclick="event.stopPropagation()">
        <div class="delete-reason-header">
            <div class="delete-reason-title">
                <span>🗑️</span> 予約削除理由の選択
            </div>
            <button type="button" class="chip-action-btn" onclick="closeDeleteReasonModal()" style="font-size:16px;">✕</button>
        </div>
        <div class="delete-reason-body">
            <div style="font-size: 12px; color: #475569; margin-bottom: 12px; line-height: 1.4;">
                登録から時間が経過している確定予約です。<br>
                削除理由を選択してください（選択内容は直近空き枠速報や監査ログに正確に反映されます）。
            </div>
            <input type="hidden" id="deleteTargetSid" value="">
            
            <button type="button" class="delete-reason-opt-btn" onclick="selectDeleteReason('patient_cancel')">
                <div class="delete-opt-title">
                    <span>🔴 患者都合キャンセル</span>
                    <span class="delete-opt-badge" style="background:#fee2e2; color:#b91c1c;">⚡ 空き枠速報に掲載</span>
                </div>
                <div class="delete-opt-desc">急用、体調不良、自己都合、他院受診などによる予約の中止</div>
            </button>

            <button type="button" class="delete-reason-opt-btn" onclick="selectDeleteReason('reschedule')">
                <div class="delete-opt-title">
                    <span>🟡 日程変更・別日振替</span>
                    <span class="delete-opt-badge" style="background:#fef3c7; color:#b45309;">⚡ 空き枠速報に掲載</span>
                </div>
                <div class="delete-opt-desc">別の日程に変更・移動するため、本日の枠を取り消す</div>
            </button>

            <button type="button" class="delete-reason-opt-btn" onclick="selectDeleteReason('mistake')">
                <div class="delete-opt-title">
                    <span>⚪ 誤登録・重複・テスト</span>
                    <span class="delete-opt-badge" style="background:#f1f5f9; color:#475569;">速報には載せない</span>
                </div>
                <div class="delete-opt-desc">入力誤り、重複登録の解消、テスト入力など（キャンセル速報には出しません）</div>
            </button>

            <div style="text-align: right; margin-top: 14px;">
                <button type="button" onclick="closeDeleteReasonModal()" class="btn btn-secondary" style="font-size:11.5px; padding:4px 12px;">
                    削除をやめる（戻る）
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// 🖨️ 特大Flatpickr小野会カレンダーバインド (検査日 & 生年月日)
function initModalDatePicker() {
    if (window.OnokaiDatePicker) {
        // 🌟 小野会日付ピッカー（角丸・西暦/和暦切替・特大Flatpickr）を検査日にバインド
        OnokaiDatePicker.bindControl('#exam_date', {
            onChange: function(dates, dateStr) {
                if (typeof handleDateChange === 'function') {
                    handleDateChange();
                }
            }
        });
        OnokaiDatePicker.attach('#manual_birthday', {
            onChange: function(dates, dateStr) {
                if (typeof syncManualToHidden === 'function') {
                    syncManualToHidden();
                }
            }
        });
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initModalDatePicker);
} else {
    initModalDatePicker();
}

// 🗺️ RS-Base 検査実績タイムライン・キャンバス起動
function openCanvasTimelineFromModal() {
    const pidInput = document.getElementById('patient_id');
    const pid = pidInput ? pidInput.value.trim() : '';
    if (!pid || pid === '---') {
        if (typeof showCustomToast === 'function') {
            showCustomToast('患者Noが入力されていません', 'warning');
        } else {
            alert('患者Noを入力してください');
        }
        return;
    }
    const examSelect = document.getElementById('exam_type');
    const examType = examSelect ? examSelect.value : '';
    let modality = 'ES';
    if (examType.includes('エコー') || examType.toUpperCase().includes('ECHO') || examType.toUpperCase() === 'US') {
        modality = 'US';
    } else if (examType.toUpperCase().includes('ECG') || examType.includes('心電図')) {
        modality = 'ECG';
    }
    const targetUrl = `../rsbase/canvas_view.php?patient_no=${encodeURIComponent(pid)}&modality=${encodeURIComponent(modality)}&limit=5`;
    window.open(targetUrl, '_blank');
}
</script>
</body>
</html>