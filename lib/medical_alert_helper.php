<?php
/**
 * lib/medical_alert_helper.php
 * 医療安全アラート（抗血栓薬・アレルギー・感染症・風邪症状）判定＆管理エンジン
 * 
 * 仕様:
 *   - 予約備考（remarks）に「確定タグ」があれば問答無用でON
 *   - MALLメモや平文備考からキーワードを検知し、判断材料（前後テキスト）を抽出
 *   - ユーザー確認プレビューモーダルでスタッフが最終判断して保存
 *   - 当日ホワイトボード等での休薬確認済（✅）・風邪発熱トグル対応
 */

require_once __DIR__ . '/db.php';

// ── 確定タグ正規表現（予約備考のみ有効・問答無用でON） ──
define('ALERT_TAG_ANTITHROMBOTIC', '/(?:【(?:抗血栓|休薬|抗凝固)】|[#＃](?:抗血栓|休薬)|💊\s*(?:抗血栓|要?休薬))/ui');
define('ALERT_TAG_DIABETES',       '/(?:【(?:糖尿病|インスリン|血糖)】|[#＃](?:糖尿病|インスリン)|💉\s*(?:糖尿病|インスリン))/ui');
define('ALERT_TAG_PSYCHOTROPIC',   '/(?:【(?:向精神薬|睡眠薬|抗不安薬|抗うつ薬|精神薬)】|[#＃](?:向精神薬|睡眠薬|精神薬)|🧠\s*(?:向精神薬|睡眠薬))/ui');
define('ALERT_TAG_NSAIDS',         '/(?:【(?:NSAIDs|鎮痛薬|解熱鎮痛|消炎鎮痛)】|[#＃](?:NSAIDs|鎮痛薬|消炎鎮痛)|🩹\s*(?:NSAIDs|鎮痛薬))/ui');
define('ALERT_TAG_ALLERGY',        '/(?:【(?:アレルギー|局麻注意|局麻禁|麻酔注意)】|[#＃](?:アレルギー|局麻)|⚠️\s*(?:アレルギー|局麻))/ui');
define('ALERT_TAG_INFECTION',      '/(?:【(?:感染症|肝炎|梅毒|B型肝炎|C型肝炎)】|[#＃](?:感染症|肝炎|梅毒)|🔴\s*(?:感染症|肝炎))/ui');
define('ALERT_TAG_COLD_FEVER',     '/(?:【(?:風邪|発熱|有症状|感冒|コロナ)】|[#＃](?:風邪|発熱|コロナ)|😷\s*(?:風邪|感冒)|🌡️\s*発熱)/ui');

// ── 平文キーワード正規表現（MALLメモおよび予約備考の平文） ──
define('ALERT_KW_ANTITHROMBOTIC', '/(?:バイアスピリン|アスピリン|プラビックス|クロピドグレル|エフィエント|プラスグレル|パナルジン|プレタール|シロスタゾール|イグザレルト|リバーロキサバン|エリキュース|アピキサバン|リクシアナ|エドキサバン|プラザキサ|ダビガトラン|ワーファリン|ワルファリン|ヘパリン|抗血小板|抗凝固|抗血栓|休薬)/ui');
define('ALERT_KW_DIABETES',       '/(?:糖尿病|インスリン|メトホルミン|ジャヌビア|フォシーガ|カナグル|ジャディアンス|スーグラ|エクア|トラゼンタ|テネリア|アマリール|グリメピリド|グリクラジド|低血糖)/ui');
define('ALERT_KW_PSYCHOTROPIC',   '/(?:向精神薬|睡眠薬|抗不安薬|抗うつ薬|マイスリー|ゾルピデム|デエビゴ|レンボレキサント|ベルソムラ|スボレキサント|ハルシオン|トリアゾラム|レンドルミン|ブロチゾラム|メイラックス|ロラゼパム|ワイパックス|ソラナックス|デパス|エチゾラム|セルシン|ジアゼパム|リボトリール|ドグマチール|ジェイゾロフト|レクサプロ|サインバルタ|パキシル)/ui');
define('ALERT_KW_NSAIDS',         '/(?:ロキソニン|ロキソプロフェン|ボルタレン|ジクロフェナク|セレコックス|セレコキシブ|ナイキサン|ナプロキセン|モービック|メロキシカム|ポンタール|カロナール|アセトアミノフェン|NSAIDs|非ステロイド性抗炎症)/ui');
define('ALERT_KW_ALLERGY',        '/(?:キシロカイン|リドカイン|プロピトカイン|プロポフォール|局麻|局所麻酔|ショック|アナフィラキシー|アレルギー)/ui');
define('ALERT_KW_INFECTION',      '/(?:B型肝炎|HBs抗原|HBs|HBV|C型肝炎|HCV抗体|HCV|梅毒|TPHA|RPR|HIV|MRSA|緑膿菌)/ui');
define('ALERT_KW_COLD_FEVER',     '/(?:発熱|微熱|風邪|かぜ|コロナ|COVID|せき|咳|咽頭痛|のど痛|倦怠感)/ui');

/**
 * オンライン資格確認 薬剤情報・リスク患者判定API（api_onshi_yakuzai.php）を並行バッチ照会
 * @param array $patientNos 患者番号配列
 * @param int|null $months 対象月数 (nullまたは0で全期間)
 * @param string $source カルテ環境 (new / ono)
 * @return array 患者番号をキーとするAPIレスポンス連想配列
 */
function fetchOnshiRiskBatch(array $patientNos, ?int $months = 6, string $source = 'new'): array {
    $patientNos = array_values(array_unique(array_filter(array_map('trim', $patientNos))));
    if (empty($patientNos)) return [];

    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    $base = "http://127.0.0.1/karteview/api_onshi_yakuzai.php";
    $mParam = ($months !== null && $months > 0) ? "&months={$months}" : "";
    $sParam = ($source !== 'new') ? "&source=" . urlencode($source) : "";

    foreach ($patientNos as $pno) {
        $url = "{$base}?patient_no=" . urlencode($pno) . "&action=category{$mParam}{$sParam}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_multi_add_handle($mh, $ch);
        $handles[$pno] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);

    foreach ($handles as $pno => $ch) {
        $content = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 200 && !empty($content)) {
            $json = json_decode($content, true);
            if ($json && !empty($json['success'])) {
                $results[$pno] = $json;
            }
        }
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    return $results;
}

/**
 * テキストからマッチ箇所の前後スニペット（判断材料）を抽出
 */
function extractAlertSnippet(string $text, string $pattern, int $surroundLen = 18): ?array {
    $cleanText = strip_tags($text);
    if (!preg_match($pattern, $cleanText, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $matchedWord = $matches[0][0];
    $matchOffset = $matches[0][1];
    
    // 前後の文字位置計算（マルチバイト対応）
    $beforeStr = mb_strcut($cleanText, 0, $matchOffset);
    $beforeLen = mb_strlen($beforeStr);
    $fullMbText = $cleanText;
    
    $start = max(0, $beforeLen - $surroundLen);
    $length = mb_strlen($matchedWord) + ($surroundLen * 2);
    $snippet = mb_substr($fullMbText, $start, $length);
    
    $prefix = ($start > 0) ? '…' : '';
    $suffix = (mb_strlen($fullMbText) > ($start + $length)) ? '…' : '';

    return [
        'word'    => $matchedWord,
        'snippet' => $prefix . trim($snippet) . $suffix
    ];
}

/**
 * 特定患者のMALLメモおよびオンライン資格確認からアラート材料を抽出（新規予約・modal_edit用）
 * @param PDO $pdo
 * @param string $patientId
 * @param array $options ['include_onshi' => bool, 'months' => int|null, 'source' => string]
 * @return array
 */
function scanMedicalAlertsForPatientId(PDO $pdo, string $patientId, array $options = []): array {
    $patientId = trim($patientId);
    if ($patientId === '') return [];

    $stmt = $pdo->prepare("SELECT patient_id, memo_today, memo_karte, memo_patient FROM t_mall_memo WHERE patient_id = :pid");
    $stmt->execute([':pid' => $patientId]);
    $memo = $stmt->fetch(PDO::FETCH_ASSOC);

    $reasons = [
        'antithrombotic' => [],
        'diabetes'       => [],
        'psychotropic'   => [],
        'nsaids'         => [],
        'allergy'        => [],
        'infection'      => [],
        'cold_fever'     => []
    ];
    $hasAnti = false;
    $hasDiabetes = false;
    $hasPsychotropic = false;
    $hasNsaids = false;
    $hasAllergy = false;
    $hasInf = false;
    $hasCold = false;

    if ($memo) {
        $memoTexts = [
            '本日メモ'   => (string)($memo['memo_today'] ?? ''),
            'カルテメモ' => (string)($memo['memo_karte'] ?? ''),
            '患者メモ'   => (string)($memo['memo_patient'] ?? '')
        ];

        foreach ($memoTexts as $sourceLabel => $text) {
            if ($text === '') continue;
            if ($snip = extractAlertSnippet($text, ALERT_KW_ANTITHROMBOTIC)) {
                $hasAnti = true;
                $reasons['antithrombotic'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
            if ($snip = extractAlertSnippet($text, ALERT_KW_DIABETES)) {
                $hasDiabetes = true;
                $reasons['diabetes'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
            if ($snip = extractAlertSnippet($text, ALERT_KW_PSYCHOTROPIC)) {
                $hasPsychotropic = true;
                $reasons['psychotropic'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
            if ($snip = extractAlertSnippet($text, ALERT_KW_NSAIDS)) {
                $hasNsaids = true;
                $reasons['nsaids'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
            if ($snip = extractAlertSnippet($text, ALERT_KW_ALLERGY)) {
                $hasAllergy = true;
                $reasons['allergy'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
            if ($snip = extractAlertSnippet($text, ALERT_KW_INFECTION)) {
                $hasInf = true;
                $reasons['infection'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
            if ($snip = extractAlertSnippet($text, ALERT_KW_COLD_FEVER)) {
                $hasCold = true;
                $reasons['cold_fever'][] = ['type' => 'kw', 'is_tag' => false, 'source' => $sourceLabel, 'word' => $snip['word'], 'snippet' => $snip['snippet']];
            }
        }
    }

    $onshi_info = null;
    if (!empty($options['include_onshi'])) {
        $months = isset($options['months']) ? (int)$options['months'] : 6;
        $onshiMap = fetchOnshiRiskBatch([$patientId], $months, $options['source'] ?? 'new');
        if (!empty($onshiMap[$patientId])) {
            $oData = $onshiMap[$patientId];
            $onshi_info = [
                'has_consent'    => (bool)($oData['has_consent'] ?? false),
                'has_records'    => (bool)($oData['has_records'] ?? false),
                'has_risk_drugs' => (bool)($oData['has_risk_drugs'] ?? false),
                'viewer_url'     => (string)($oData['viewer_url'] ?? ''),
                'filter_months'  => $oData['filter_months'] ?? null
            ];
            $riskSummary = $oData['risk_summary'] ?? [];
            $categories = $oData['categories'] ?? [];

            foreach (['antithrombotic', 'diabetes', 'psychotropic', 'nsaids'] as $ck) {
                if (!empty($riskSummary[$ck]['has_risk'])) {
                    if ($ck === 'antithrombotic') $hasAnti = true;
                    if ($ck === 'diabetes') $hasDiabetes = true;
                    if ($ck === 'psychotropic') $hasPsychotropic = true;
                    if ($ck === 'nsaids') $hasNsaids = true;

                    $cDrugs = $categories[$ck] ?? [];
                    foreach ($cDrugs as $d) {
                        $dName = $d['name'] ?? '';
                        $dDate = $d['date'] ?? '';
                        $dChozai = $d['chozainame'] ?? ($d['hospname'] ?? '');
                        $snip = "{$dDate}処方 {$dName}" . ($dChozai ? " ({$dChozai})" : "");
                        $reasons[$ck][] = [
                            'type'       => 'onshi',
                            'source'     => 'オン資処方',
                            'word'       => $dName,
                            'snippet'    => $snip,
                            'date'       => $dDate,
                            'chozai'     => $dChozai,
                            'seibun'     => $d['seibun'] ?? '',
                            'yoho'       => $d['yoho'] ?? '',
                            'shiji'      => $d['shiji'] ?? '',
                            'is_tag'     => false,
                            'is_onshi'   => true,
                            'viewer_url' => $oData['viewer_url'] ?? ''
                        ];
                    }
                }
            }
        }
    }

    return [
        'patient_id'         => $patientId,
        'has_antithrombotic' => $hasAnti,
        'has_diabetes'       => $hasDiabetes,
        'has_psychotropic'   => $hasPsychotropic,
        'has_nsaids'         => $hasNsaids,
        'has_allergy'        => $hasAllergy,
        'has_infection'      => $hasInf,
        'has_cold_fever'     => $hasCold,
        'reasons'            => $reasons,
        'onshi_info'         => $onshi_info
    ];
}

/**
 * 予約データ、MALLメモ、およびオンライン資格確認APIからアラート候補をスキャン
 * @param PDO $pdo
 * @param array $options
 * @return array
 */
function scanMedicalAlertsForSchedules(PDO $pdo, array $options = []): array {
    $where = ["s.is_canceled = FALSE"];
    $params = [];

    if (!empty($options['patient_id'])) {
        $where[] = "s.patient_id = :patient_id";
        $params[':patient_id'] = trim($options['patient_id']);
    } elseif (!empty($options['schedule_ids']) && is_array($options['schedule_ids'])) {
        $inPlaceholders = [];
        foreach ($options['schedule_ids'] as $idx => $id) {
            $ph = ":sid_{$idx}";
            $inPlaceholders[] = $ph;
            $params[$ph] = (int)$id;
        }
        $where[] = "s.schedule_id IN (" . implode(',', $inPlaceholders) . ")";
    } elseif (!empty($options['date'])) {
        $where[] = "s.exam_date = :date";
        $params[':date'] = $options['date'];
        if (!empty($options['dept']) && $options['dept'] !== 'ALL') {
            $where[] = "s.department_code = :dept";
            $params[':dept'] = $options['dept'];
        }
    } elseif (!empty($options['year']) && !empty($options['month'])) {
        $start = sprintf('%04d-%02d-01', $options['year'], $options['month']);
        $end = date('Y-m-t', strtotime($start));
        $where[] = "s.exam_date BETWEEN :start AND :end";
        $params[':start'] = $start;
        $params[':end'] = $end;
        if (!empty($options['dept']) && $options['dept'] !== 'ALL') {
            $where[] = "s.department_code = :dept";
            $params[':dept'] = $options['dept'];
        }
    }

    $where_sql = implode(' AND ', $where);

    $sql = "
        SELECT 
            s.schedule_id,
            s.exam_date,
            s.scheduled_time,
            s.department_code,
            s.patient_id,
            s.patient_name,
            s.doctor_name,
            s.exam_type,
            s.remarks,
            m.memo_today,
            m.memo_karte,
            m.memo_patient,
            a.has_antithrombotic,
            a.has_diabetes,
            a.has_psychotropic,
            a.has_nsaids,
            a.has_allergy,
            a.has_infection,
            a.has_cold_fever,
            a.antithrombotic_verified,
            a.manual_overridden,
            a.detection_sources,
            a.verified_by
        FROM t_endoscopy_schedule s
        LEFT JOIN t_mall_memo m ON s.patient_id = m.patient_id
        LEFT JOIN t_patient_medical_alert a ON s.schedule_id = a.schedule_id
        WHERE {$where_sql}
        ORDER BY s.exam_date ASC, s.scheduled_time ASC, s.schedule_id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // オンライン資格確認APIの並行一括照会
    $includeOnshi = $options['include_onshi'] ?? true;
    $months = isset($options['months']) ? (int)$options['months'] : 6;
    if ($months <= 0) $months = null; // 0またはマイナスは全期間

    $onshiBatchResults = [];
    if ($includeOnshi && !empty($rows)) {
        $pids = [];
        foreach ($rows as $r) {
            $p = trim((string)($r['patient_id'] ?? ''));
            if ($p !== '') $pids[] = $p;
        }
        if (!empty($pids)) {
            $onshiBatchResults = fetchOnshiRiskBatch($pids, $months, $options['source'] ?? 'new');
        }
    }

    $candidates = [];

    foreach ($rows as $r) {
        $sid = (int)$r['schedule_id'];
        $pid = trim((string)($r['patient_id'] ?? ''));
        $remarks = (string)($r['remarks'] ?? '');
        $memoToday = (string)($r['memo_today'] ?? '');
        $memoKarte = (string)($r['memo_karte'] ?? '');
        $memoPatient = (string)($r['memo_patient'] ?? '');

        // 判定理由リスト（7系統）
        $reasons = [
            'antithrombotic' => [],
            'diabetes'       => [],
            'psychotropic'   => [],
            'nsaids'         => [],
            'allergy'        => [],
            'infection'      => [],
            'cold_fever'     => []
        ];

        // 1. 予約備考（remarks）の確定タグ判定（問答無用でON）
        $tag_anti = extractAlertSnippet($remarks, ALERT_TAG_ANTITHROMBOTIC);
        if ($tag_anti) {
            $reasons['antithrombotic'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_anti['word'], 'snippet' => $tag_anti['snippet'], 'is_tag' => true];
        }
        $tag_diabetes = extractAlertSnippet($remarks, ALERT_TAG_DIABETES);
        if ($tag_diabetes) {
            $reasons['diabetes'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_diabetes['word'], 'snippet' => $tag_diabetes['snippet'], 'is_tag' => true];
        }
        $tag_psychotropic = extractAlertSnippet($remarks, ALERT_TAG_PSYCHOTROPIC);
        if ($tag_psychotropic) {
            $reasons['psychotropic'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_psychotropic['word'], 'snippet' => $tag_psychotropic['snippet'], 'is_tag' => true];
        }
        $tag_nsaids = extractAlertSnippet($remarks, ALERT_TAG_NSAIDS);
        if ($tag_nsaids) {
            $reasons['nsaids'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_nsaids['word'], 'snippet' => $tag_nsaids['snippet'], 'is_tag' => true];
        }
        $tag_allergy = extractAlertSnippet($remarks, ALERT_TAG_ALLERGY);
        if ($tag_allergy) {
            $reasons['allergy'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_allergy['word'], 'snippet' => $tag_allergy['snippet'], 'is_tag' => true];
        }
        $tag_infection = extractAlertSnippet($remarks, ALERT_TAG_INFECTION);
        if ($tag_infection) {
            $reasons['infection'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_infection['word'], 'snippet' => $tag_infection['snippet'], 'is_tag' => true];
        }
        $tag_cold = extractAlertSnippet($remarks, ALERT_TAG_COLD_FEVER);
        if ($tag_cold) {
            $reasons['cold_fever'][] = ['type' => 'tag', 'source' => '予約備考（確定タグ）', 'word' => $tag_cold['word'], 'snippet' => $tag_cold['snippet'], 'is_tag' => true];
        }

        // 2. 予約備考（remarks）の平文キーワード判定
        if (!$tag_anti && ($kw = extractAlertSnippet($remarks, ALERT_KW_ANTITHROMBOTIC))) {
            $reasons['antithrombotic'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }
        if (!$tag_diabetes && ($kw = extractAlertSnippet($remarks, ALERT_KW_DIABETES))) {
            $reasons['diabetes'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }
        if (!$tag_psychotropic && ($kw = extractAlertSnippet($remarks, ALERT_KW_PSYCHOTROPIC))) {
            $reasons['psychotropic'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }
        if (!$tag_nsaids && ($kw = extractAlertSnippet($remarks, ALERT_KW_NSAIDS))) {
            $reasons['nsaids'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }
        if (!$tag_allergy && ($kw = extractAlertSnippet($remarks, ALERT_KW_ALLERGY))) {
            $reasons['allergy'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }
        if (!$tag_infection && ($kw = extractAlertSnippet($remarks, ALERT_KW_INFECTION))) {
            $reasons['infection'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }
        if (!$tag_cold && ($kw = extractAlertSnippet($remarks, ALERT_KW_COLD_FEVER))) {
            $reasons['cold_fever'][] = ['type' => 'kw', 'source' => '予約備考', 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
        }

        // 3. MALLメモの平文キーワード判定（本日・カルテ・患者）
        $memosToCheck = [
            'MALL本日メモ'   => $memoToday,
            'MALLカルテメモ' => $memoKarte,
            'MALL患者メモ'   => $memoPatient
        ];

        foreach ($memosToCheck as $srcName => $memoText) {
            if (empty($memoText)) continue;

            if ($kw = extractAlertSnippet($memoText, ALERT_KW_ANTITHROMBOTIC)) {
                $reasons['antithrombotic'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
            if ($kw = extractAlertSnippet($memoText, ALERT_KW_DIABETES)) {
                $reasons['diabetes'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
            if ($kw = extractAlertSnippet($memoText, ALERT_KW_PSYCHOTROPIC)) {
                $reasons['psychotropic'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
            if ($kw = extractAlertSnippet($memoText, ALERT_KW_NSAIDS)) {
                $reasons['nsaids'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
            if ($kw = extractAlertSnippet($memoText, ALERT_KW_ALLERGY)) {
                $reasons['allergy'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
            if ($kw = extractAlertSnippet($memoText, ALERT_KW_INFECTION)) {
                $reasons['infection'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
            if ($kw = extractAlertSnippet($memoText, ALERT_KW_COLD_FEVER)) {
                $reasons['cold_fever'][] = ['type' => 'kw', 'source' => $srcName, 'word' => $kw['word'], 'snippet' => $kw['snippet'], 'is_tag' => false];
            }
        }

        // 4. オンライン資格確認APIからの処方薬剤リスク反映
        $onshi_info = null;
        $hasOnshiDetection = false;
        if (!empty($onshiBatchResults[$pid])) {
            $oData = $onshiBatchResults[$pid];
            $onshi_info = [
                'has_consent'    => (bool)($oData['has_consent'] ?? false),
                'has_records'    => (bool)($oData['has_records'] ?? false),
                'has_risk_drugs' => (bool)($oData['has_risk_drugs'] ?? false),
                'viewer_url'     => (string)($oData['viewer_url'] ?? ''),
                'filter_months'  => $oData['filter_months'] ?? null
            ];
            $riskSummary = $oData['risk_summary'] ?? [];
            $categories = $oData['categories'] ?? [];

            foreach (['antithrombotic', 'diabetes', 'psychotropic', 'nsaids'] as $ck) {
                if (!empty($riskSummary[$ck]['has_risk'])) {
                    $hasOnshiDetection = true;
                    $cDrugs = $categories[$ck] ?? [];
                    foreach ($cDrugs as $d) {
                        $dName = $d['name'] ?? '';
                        $dDate = $d['date'] ?? '';
                        $dChozai = $d['chozainame'] ?? ($d['hospname'] ?? '');
                        $snip = "{$dDate}処方 {$dName}" . ($dChozai ? " ({$dChozai})" : "");
                        $reasons[$ck][] = [
                            'type'       => 'onshi',
                            'source'     => 'オン資処方',
                            'word'       => $dName,
                            'snippet'    => $snip,
                            'date'       => $dDate,
                            'chozai'     => $dChozai,
                            'seibun'     => $d['seibun'] ?? '',
                            'yoho'       => $d['yoho'] ?? '',
                            'shiji'      => $d['shiji'] ?? '',
                            'is_tag'     => false,
                            'is_onshi'   => true,
                            'viewer_url' => $oData['viewer_url'] ?? ''
                        ];
                    }
                }
            }
        }

        // 検知されたかどうか（タグ・メモ・オン資いずれか）
        $hasAnyDetection = (
            !empty($reasons['antithrombotic']) ||
            !empty($reasons['diabetes']) ||
            !empty($reasons['psychotropic']) ||
            !empty($reasons['nsaids']) ||
            !empty($reasons['allergy']) ||
            !empty($reasons['infection']) ||
            !empty($reasons['cold_fever'])
        );
        
        // 保存済みデータがあるか
        $isSaved = ($r['has_antithrombotic'] !== null);
        $manualOverridden = (bool)($r['manual_overridden'] ?? false);

        // 推奨フラグ（タグ/メモ/オン資があればTRUE）
        $rec_anti         = !empty($reasons['antithrombotic']);
        $rec_diabetes     = !empty($reasons['diabetes']);
        $rec_psychotropic = !empty($reasons['psychotropic']);
        $rec_nsaids       = !empty($reasons['nsaids']);
        $rec_allergy      = !empty($reasons['allergy']);
        $rec_infection    = !empty($reasons['infection']);
        $rec_cold         = !empty($reasons['cold_fever']);

        // 画面に返す現在値（手動変更済みなら手動値を反映、保存済みなら保存値、未保存なら推奨値）
        $curr_anti         = $manualOverridden ? (bool)$r['has_antithrombotic'] : ($isSaved ? (bool)$r['has_antithrombotic'] : $rec_anti);
        $curr_diabetes     = $manualOverridden ? (bool)($r['has_diabetes'] ?? false) : ($isSaved ? (bool)($r['has_diabetes'] ?? false) : $rec_diabetes);
        $curr_psychotropic = $manualOverridden ? (bool)($r['has_psychotropic'] ?? false) : ($isSaved ? (bool)($r['has_psychotropic'] ?? false) : $rec_psychotropic);
        $curr_nsaids       = $manualOverridden ? (bool)($r['has_nsaids'] ?? false) : ($isSaved ? (bool)($r['has_nsaids'] ?? false) : $rec_nsaids);
        $curr_allergy      = $manualOverridden ? (bool)$r['has_allergy'] : ($isSaved ? (bool)$r['has_allergy'] : $rec_allergy);
        $curr_infection    = $manualOverridden ? (bool)$r['has_infection'] : ($isSaved ? (bool)$r['has_infection'] : $rec_infection);
        $curr_cold         = $manualOverridden ? (bool)$r['has_cold_fever'] : ($isSaved ? (bool)$r['has_cold_fever'] : $rec_cold);

        // 何らかの注意があるか、もしくは既存設定がある患者のみリストアップ
        if ($hasAnyDetection || $isSaved || $curr_anti || $curr_diabetes || $curr_psychotropic || $curr_nsaids || $curr_allergy || $curr_infection || $curr_cold) {
            $candidates[] = [
                'schedule_id'             => $sid,
                'exam_date'               => $r['exam_date'],
                'scheduled_time'          => $r['scheduled_time'],
                'department_code'         => $r['department_code'],
                'patient_id'              => $r['patient_id'] ?? '',
                'patient_name'            => $r['patient_name'] ?? '患者名未設定',
                'doctor_name'             => $r['doctor_name'] ?? '',
                'exam_type'               => $r['exam_type'] ?? '',
                'has_antithrombotic'      => $curr_anti,
                'has_diabetes'            => $curr_diabetes,
                'has_psychotropic'        => $curr_psychotropic,
                'has_nsaids'              => $curr_nsaids,
                'has_allergy'             => $curr_allergy,
                'has_infection'           => $curr_infection,
                'has_cold_fever'          => $curr_cold,
                'antithrombotic_verified' => (bool)($r['antithrombotic_verified'] ?? false),
                'reasons'                 => $reasons,
                'has_tag_detection'       => (!empty($tag_anti) || !empty($tag_diabetes) || !empty($tag_psychotropic) || !empty($tag_nsaids) || !empty($tag_allergy) || !empty($tag_infection) || !empty($tag_cold)),
                'has_onshi_detection'     => $hasOnshiDetection,
                'onshi_info'              => $onshi_info,
                'manual_overridden'       => $manualOverridden,
                'is_saved'                => $isSaved
            ];
        }
    }

    return $candidates;
}

/**
 * ユーザーが確認・決定したアラートを一括保存
 */
function saveMedicalAlertDecisions(PDO $pdo, array $alerts, ?string $operator = null): bool {
    if (empty($alerts)) return true;

    $sql = "
        INSERT INTO t_patient_medical_alert (
            schedule_id, patient_id, patient_name, exam_date, department_code,
            has_antithrombotic, has_diabetes, has_psychotropic, has_nsaids,
            has_allergy, has_infection, has_cold_fever,
            antithrombotic_verified, detection_sources, manual_overridden, verified_by, updated_at
        ) VALUES (
            :schedule_id, :patient_id, :patient_name, :exam_date, :department_code,
            :has_antithrombotic, :has_diabetes, :has_psychotropic, :has_nsaids,
            :has_allergy, :has_infection, :has_cold_fever,
            :antithrombotic_verified, :detection_sources, TRUE, :verified_by, CURRENT_TIMESTAMP
        )
        ON CONFLICT (schedule_id) DO UPDATE SET
            has_antithrombotic      = EXCLUDED.has_antithrombotic,
            has_diabetes            = EXCLUDED.has_diabetes,
            has_psychotropic        = EXCLUDED.has_psychotropic,
            has_nsaids              = EXCLUDED.has_nsaids,
            has_allergy             = EXCLUDED.has_allergy,
            has_infection           = EXCLUDED.has_infection,
            has_cold_fever          = EXCLUDED.has_cold_fever,
            antithrombotic_verified = EXCLUDED.antithrombotic_verified,
            detection_sources       = EXCLUDED.detection_sources,
            manual_overridden       = TRUE,
            verified_by             = EXCLUDED.verified_by,
            updated_at              = CURRENT_TIMESTAMP
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($alerts as $item) {
        $sid = (int)($item['schedule_id'] ?? 0);
        if ($sid <= 0) continue;

        $stmt->execute([
            ':schedule_id'             => $sid,
            ':patient_id'              => $item['patient_id'] ?? null,
            ':patient_name'            => $item['patient_name'] ?? null,
            ':exam_date'               => $item['exam_date'] ?? date('Y-m-d'),
            ':department_code'         => $item['department_code'] ?? 'PROCT',
            ':has_antithrombotic'      => !empty($item['has_antithrombotic']) ? 'TRUE' : 'FALSE',
            ':has_diabetes'            => !empty($item['has_diabetes']) ? 'TRUE' : 'FALSE',
            ':has_psychotropic'        => !empty($item['has_psychotropic']) ? 'TRUE' : 'FALSE',
            ':has_nsaids'              => !empty($item['has_nsaids']) ? 'TRUE' : 'FALSE',
            ':has_allergy'             => !empty($item['has_allergy']) ? 'TRUE' : 'FALSE',
            ':has_infection'           => !empty($item['has_infection']) ? 'TRUE' : 'FALSE',
            ':has_cold_fever'          => !empty($item['has_cold_fever']) ? 'TRUE' : 'FALSE',
            ':antithrombotic_verified' => !empty($item['antithrombotic_verified']) ? 'TRUE' : 'FALSE',
            ':detection_sources'       => json_encode($item['reasons'] ?? [], JSON_UNESCAPED_UNICODE),
            ':verified_by'             => $operator ?: ($_SERVER['REMOTE_ADDR'] ?? 'staff')
        ]);
    }

    return true;
}

/**
 * schedule_id 群のアラート状態マップを取得
 */
function getMedicalAlertsMap(PDO $pdo, array $schedule_ids): array {
    if (empty($schedule_ids)) return [];

    $placeholders = [];
    $params = [];
    foreach ($schedule_ids as $idx => $id) {
        $ph = ":sid_{$idx}";
        $placeholders[] = $ph;
        $params[$ph] = (int)$id;
    }

    $sql = "
        SELECT 
            schedule_id,
            patient_id,
            has_antithrombotic,
            has_diabetes,
            has_psychotropic,
            has_nsaids,
            has_allergy,
            has_infection,
            has_cold_fever,
            antithrombotic_verified,
            detection_sources
        FROM t_patient_medical_alert
        WHERE schedule_id IN (" . implode(',', $placeholders) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $sid = (int)$r['schedule_id'];
        $map[$sid] = [
            'has_antithrombotic'      => (bool)$r['has_antithrombotic'],
            'has_diabetes'            => (bool)($r['has_diabetes'] ?? false),
            'has_psychotropic'        => (bool)($r['has_psychotropic'] ?? false),
            'has_nsaids'              => (bool)($r['has_nsaids'] ?? false),
            'has_allergy'             => (bool)$r['has_allergy'],
            'has_infection'           => (bool)$r['has_infection'],
            'has_cold_fever'          => (bool)$r['has_cold_fever'],
            'antithrombotic_verified' => (bool)$r['antithrombotic_verified'],
            'sources'                 => json_decode($r['detection_sources'] ?? '[]', true)
        ];
    }

    return $map;
}

/**
 * アラート表示用HTMLバッジを生成
 */
function renderMedicalAlertBadgesHTML(array $alertData, string $context = 'badge'): string {
    $hasAnti = !empty($alertData['has_antithrombotic']);
    $hasDiabetes = !empty($alertData['has_diabetes']);
    $hasPsychotropic = !empty($alertData['has_psychotropic']);
    $hasNsaids = !empty($alertData['has_nsaids']);
    $hasAllergy = !empty($alertData['has_allergy']);
    $hasInfection = !empty($alertData['has_infection']);
    $hasCold = !empty($alertData['has_cold_fever']);
    $isAntiVerified = !empty($alertData['antithrombotic_verified']);

    if (!$hasAnti && !$hasDiabetes && !$hasPsychotropic && !$hasNsaids && !$hasAllergy && !$hasInfection && !$hasCold) {
        return '';
    }

    if ($context === 'icon_only' || $context === 'compact') {
        $icons = [];
        if ($hasAnti) {
            $icons[] = $isAntiVerified 
                ? '<span style="color:#059669; font-weight:bold; cursor:help;" title="💊 抗血栓薬（当日休薬確認済）">💊<span style="font-size:8px; vertical-align:top; color:#059669;">✔</span></span>'
                : '<span style="color:#dc2626; font-weight:bold; cursor:help;" title="💊 抗血栓薬（要休薬確認！）">💊</span>';
        }
        if ($hasAllergy) {
            $icons[] = '<span style="cursor:help;" title="⚠️ アレルギー注意（局麻・薬剤ショック等）">⚠️</span>';
        }
        if ($hasInfection) {
            $icons[] = '<span style="cursor:help;" title="🔴 感染症注意（肝炎/梅毒等：防護具・専用洗浄要）">🔴</span>';
        }
        if ($hasCold) {
            $icons[] = '<span style="cursor:help;" title="😷 風邪症状・発熱（有症状・飛沫対策）">😷</span>';
        }
        return '<span class="med-alerts-icons" style="display:inline-flex; align-items:center; gap:2px; vertical-align:middle; font-size:10px;">' . implode('', $icons) . '</span>';
    }

    $badges = [];

    // 1. 抗血栓薬
    if ($hasAnti) {
        if ($isAntiVerified) {
            $badges[] = '<span class="med-alert-chip chip-anti-verified" title="💊 抗血栓薬内服中（当日休薬確認済）">💊 休薬確認済</span>';
        } else {
            $badges[] = '<span class="med-alert-chip chip-anti" title="💊 抗血栓薬内服中（要休薬確認！）">💊 休薬確認要</span>';
        }
    }

    // 2. アレルギー
    if ($hasAllergy) {
        $badges[] = '<span class="med-alert-chip chip-allergy" title="⚠️ アレルギー注意（局麻・薬剤ショック等）">⚠️ 局麻注意</span>';
    }

    // 3. 感染症
    if ($hasInfection) {
        $badges[] = '<span class="med-alert-chip chip-infection" title="🔴 感染症注意（肝炎/梅毒等：防護具・専用洗浄要）">🔴 感染症注意</span>';
    }

    // 4. 風邪症状・発熱
    if ($hasCold) {
        $badges[] = '<span class="med-alert-chip chip-cold" title="😷 風邪症状・発熱（有症状・飛沫対策）">😷 風邪・発熱</span>';
    }

    return '<span class="med-alerts-wrapper">' . implode('', $badges) . '</span>';
}

/**
 * 予約保存時に当該患者のアラートを安全に同期保存（手動ON/OFF反映 ＆ 備考更新抜け防止）
 */
function syncMedicalAlertOnScheduleSave(PDO $pdo, int $scheduleId, ?string $patientId, string $remarks = '', array $postData = []): void {
    if ($scheduleId <= 0) return;

    $operator = $postData['operator_name'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'staff');

    // 1. modal_edit 等から明示的にフラグが指定されている場合 (manual_alert_set = '1')
    if (isset($postData['manual_alert_set']) && (string)$postData['manual_alert_set'] === '1') {
        $hasAnti = !empty($postData['has_antithrombotic']);
        $hasDiabetes = !empty($postData['has_diabetes']);
        $hasPsychotropic = !empty($postData['has_psychotropic']);
        $hasNsaids = !empty($postData['has_nsaids']);
        $hasAllergy = !empty($postData['has_allergy']);
        $hasInf = !empty($postData['has_infection']);
        $hasCold = !empty($postData['has_cold_fever']);

        // 既存レコードがあれば休薬確認済みステータスを保持
        $stmtEx = $pdo->prepare("SELECT antithrombotic_verified FROM t_patient_medical_alert WHERE schedule_id = :id");
        $stmtEx->execute([':id' => $scheduleId]);
        $existingAntiVer = (bool)$stmtEx->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO t_patient_medical_alert (
                schedule_id, patient_id, exam_date, department_code,
                has_antithrombotic, has_diabetes, has_psychotropic, has_nsaids,
                has_allergy, has_infection, has_cold_fever,
                antithrombotic_verified, manual_overridden, detection_sources, verified_by, updated_at
            )
            SELECT 
                s.schedule_id, s.patient_id, s.exam_date, s.department_code,
                :anti, :diabetes, :psychotropic, :nsaids,
                :allergy, :inf, :cold,
                :anti_ver, TRUE, :sources, :op, CURRENT_TIMESTAMP
            FROM t_endoscopy_schedule s
            WHERE s.schedule_id = :sid
            ON CONFLICT (schedule_id) DO UPDATE SET
                patient_id = EXCLUDED.patient_id,
                exam_date = EXCLUDED.exam_date,
                department_code = EXCLUDED.department_code,
                has_antithrombotic = EXCLUDED.has_antithrombotic,
                has_diabetes = EXCLUDED.has_diabetes,
                has_psychotropic = EXCLUDED.has_psychotropic,
                has_nsaids = EXCLUDED.has_nsaids,
                has_allergy = EXCLUDED.has_allergy,
                has_infection = EXCLUDED.has_infection,
                has_cold_fever = EXCLUDED.has_cold_fever,
                antithrombotic_verified = EXCLUDED.antithrombotic_verified,
                manual_overridden = TRUE,
                verified_by = EXCLUDED.verified_by,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':anti'         => $hasAnti ? 'TRUE' : 'FALSE',
            ':diabetes'     => $hasDiabetes ? 'TRUE' : 'FALSE',
            ':psychotropic' => $hasPsychotropic ? 'TRUE' : 'FALSE',
            ':nsaids'       => $hasNsaids ? 'TRUE' : 'FALSE',
            ':allergy'      => $hasAllergy ? 'TRUE' : 'FALSE',
            ':inf'          => $hasInf ? 'TRUE' : 'FALSE',
            ':cold'         => $hasCold ? 'TRUE' : 'FALSE',
            ':anti_ver'     => ($hasAnti && $existingAntiVer) ? 'TRUE' : 'FALSE',
            ':sources'      => json_encode(['manual' => true, 'updated_by' => $operator], JSON_UNESCAPED_UNICODE),
            ':op'           => $operator,
            ':sid'          => $scheduleId
        ]);
        return;
    }

    // 2. 明示的なフラグ指定がない場合（旧画面からの更新やドラッグ移動等）：
    // 最新の予約備考とMALLメモをもとに判定エンジンを実行し、自動同期保存
    $cands = scanMedicalAlertsForSchedules($pdo, ['schedule_ids' => [$scheduleId]]);
    if (!empty($cands)) {
        saveMedicalAlertDecisions($pdo, $cands, $operator);
    }
}

/**
 * 監査ログの巻き戻し（Undo）用：指定 schedule_id のアラート状態を復元
 */
function restoreMedicalAlertFromAudit(PDO $pdo, int $scheduleId, array $alertData, ?string $operator = null): bool {
    if ($scheduleId <= 0) return false;
    $op = $operator ?: ($_SERVER['REMOTE_ADDR'] ?? 'staff-undo');

    $hasAnti  = !empty($alertData['has_antithrombotic']);
    $hasDiabetes = !empty($alertData['has_diabetes']);
    $hasPsychotropic = !empty($alertData['has_psychotropic']);
    $hasNsaids = !empty($alertData['has_nsaids']);
    $hasAllergy = !empty($alertData['has_allergy']);
    $hasInf   = !empty($alertData['has_infection']);
    $hasCold  = !empty($alertData['has_cold_fever']);
    $antiVer  = !empty($alertData['antithrombotic_verified']);

    $stmt = $pdo->prepare("
        INSERT INTO t_patient_medical_alert (
            schedule_id, patient_id, exam_date, department_code,
            has_antithrombotic, has_diabetes, has_psychotropic, has_nsaids,
            has_allergy, has_infection, has_cold_fever,
            antithrombotic_verified, manual_overridden, detection_sources, verified_by, updated_at
        )
        SELECT 
            s.schedule_id, s.patient_id, s.exam_date, s.department_code,
            :anti, :diabetes, :psychotropic, :nsaids,
            :allergy, :inf, :cold,
            :anti_ver, TRUE, :sources, :op, CURRENT_TIMESTAMP
        FROM t_endoscopy_schedule s
        WHERE s.schedule_id = :sid
        ON CONFLICT (schedule_id) DO UPDATE SET
            patient_id = EXCLUDED.patient_id,
            exam_date = EXCLUDED.exam_date,
            department_code = EXCLUDED.department_code,
            has_antithrombotic = EXCLUDED.has_antithrombotic,
            has_diabetes = EXCLUDED.has_diabetes,
            has_psychotropic = EXCLUDED.has_psychotropic,
            has_nsaids = EXCLUDED.has_nsaids,
            has_allergy = EXCLUDED.has_allergy,
            has_infection = EXCLUDED.has_infection,
            has_cold_fever = EXCLUDED.has_cold_fever,
            antithrombotic_verified = EXCLUDED.antithrombotic_verified,
            manual_overridden = TRUE,
            verified_by = EXCLUDED.verified_by,
            updated_at = CURRENT_TIMESTAMP
    ");

    return $stmt->execute([
        ':anti'         => $hasAnti ? 'TRUE' : 'FALSE',
        ':diabetes'     => $hasDiabetes ? 'TRUE' : 'FALSE',
        ':psychotropic' => $hasPsychotropic ? 'TRUE' : 'FALSE',
        ':nsaids'       => $hasNsaids ? 'TRUE' : 'FALSE',
        ':allergy'      => $hasAllergy ? 'TRUE' : 'FALSE',
        ':inf'          => $hasInf ? 'TRUE' : 'FALSE',
        ':cold'         => $hasCold ? 'TRUE' : 'FALSE',
        ':anti_ver'     => $antiVer ? 'TRUE' : 'FALSE',
        ':sources'      => json_encode(['restored_from_undo' => true, 'restored_at' => date('Y-m-d H:i:s'), 'operator' => $op], JSON_UNESCAPED_UNICODE),
        ':op'           => $op,
        ':sid'          => $scheduleId
    ]);
}


