<?php
/**
 * 設置パス: c:\Apache24\htdocs\endoscope\inc_system_info.php
 * ファイル名: inc_system_info.php
 * 概要: 院内内視鏡システム 修正インフォメーション・リリース情報管理モジュール
 * 更新日時: 2026-11-08 (v1108 リリース / 内視鏡シートDB永続化・ホワイトボード双方向データ統合・時間枠カラー・世代管理対応)
 */

if (!function_exists('getSystemInfoPdo')) {
    /**
     * システムインフォ用PDO接続取得（グローバルPDOの流用または安全な新規生成）
     */
    function getSystemInfoPdo() {
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        try {
            $host = 'localhost';
            $port = 5432;
            $dbname = 'endoscope';
            $user = 'postgres';
            $pass = 'postgres';
            return new PDO("pgsql:host={$host};port={$port};dbname={$dbname};", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 2
            ]);
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('getSystemInformations')) {
    /**
     * 修正インフォメーション一覧取得
     * DB (m_system_info) ➔ config/system_info.json ➔ フォールバック配列の順でフォールバック
     */
    function getSystemInformations($pdo = null, $include_inactive = false) {
        if ($pdo === null) {
            $pdo = getSystemInfoPdo();
        }

        // 1. DBから取得
        if ($pdo instanceof PDO) {
            try {
                $where = $include_inactive ? "" : "WHERE is_active = TRUE";
                $sql = "
                    SELECT 
                        id, 
                        info_date, 
                        version_tag, 
                        badge_label, 
                        badge_cls, 
                        title, 
                        summary, 
                        detail_items, 
                        display_order, 
                        is_active,
                        created_at,
                        updated_at
                    FROM m_system_info
                    {$where}
                    ORDER BY display_order ASC, info_date DESC, id DESC
                ";
                $rows = $pdo->query($sql)->fetchAll();
                if (!empty($rows)) {
                    $results = [];
                    foreach ($rows as $r) {
                        $items = [];
                        if (!empty($r['detail_items'])) {
                            $lines = explode("\n", str_replace("\r", "", $r['detail_items']));
                            foreach ($lines as $l) {
                                $trimL = trim($l);
                                if ($trimL !== '') {
                                    $items[] = $trimL;
                                }
                            }
                        }
                        $results[] = [
                            'id'        => (int)$r['id'],
                            'date'      => $r['info_date'],
                            'ver'       => $r['version_tag'],
                            'badge'     => $r['badge_label'],
                            'badge_cls' => $r['badge_cls'],
                            'title'     => $r['title'],
                            'summary'   => $r['summary'],
                            'items'     => $items,
                            'display_order' => (int)$r['display_order'],
                            'is_active' => (bool)$r['is_active']
                        ];
                    }
                    return $results;
                }
            } catch (Exception $e) {
                // DBエラー時は下位へフォールバック
            }
        }

        // 2. config/system_info.json から取得
        $jsonFile = __DIR__ . '/config/system_info.json';
        if (file_exists($jsonFile)) {
            $jsonContent = @file_get_contents($jsonFile);
            if ($jsonContent) {
                $arr = json_decode($jsonContent, true);
                if (is_array($arr) && !empty($arr)) {
                    if (!$include_inactive) {
                        $arr = array_filter($arr, function($item) {
                            return !isset($item['is_active']) || $item['is_active'];
                        });
                    }
                    return array_values($arr);
                }
            }
        }

        // 3. 静的フォールバック配列
        return [
            [
                'id'        => 4,
                'date'      => '2026-11-08',
                'ver'       => 'v1108',
                'badge'     => '大型機能統合',
                'badge_cls' => 'badge-success',
                'title'     => '🚀 v1108 リリース：内視鏡シートDB永続化＆ホワイトボード双方向統合・枠カラー設定・世代管理対応',
                'summary'   => 'マルチ端末での内視鏡シート同時リレー入力と電子ホワイトボードの双方向同期、医事課チェック連携、および時間枠カラー・期間世代管理を実装しました。',
                'items'     => [
                    '【内視鏡シート ⇔ ホワイトボード双方向統合】内視鏡シート（endoscopy_sheet.php）をDB永続化（t_endoscopy_sheet）し、受付・前処置室iPad・検査室PC・診察室・医事課PCでのマルチ端末リレー入力（キー単位差分自動保存・6秒同期）に対応。',
                    '【進捗ステータス自動連動＆医事課連携】シートでの開始/終了/起床/説明時刻打刻や［回復・待合室・OK］スタンプでホワイトボード進捗（4.検査中／5.リカバリ中／6.説明）が自動進捗。ホワイトボード各行に「📋シート」「🧾処置・薬剤サマリ」「🏥医事入力済✔」ボタンと会計待フィルタを統合。',
                    '【基本時間枠カラー設定】検査枠マスターメンテで時間枠ごとの背景色を設定可能にし、月間カレンダー・デイリーノート・A4当日予約印刷・前処置室一括設定へ統一反映。',
                    '【時間枠マスタ期間（世代）管理＆画面UX改善】2027年新枠切替を見据えた期間世代管理、マトリクスV2・月間カレンダーの医師定員差し引き・CF集中モード、月間カレンダー患者名列の3医師横並び対応、MALL3患者一致時の仮想テンキー自動クローズに対応。'
                ],
                'display_order' => 1,
                'is_active' => true
            ],
            [
                'id'        => 1,
                'date'      => '2026-11-01',
                'ver'       => 'v1101',
                'badge'     => '重要・本番稼働開始',
                'badge_cls' => 'badge-primary',
                'title'     => '🎉 内視鏡予約・運用管理システム v1101 本番稼働を開始しました',
                'summary'   => '現場運用開始に向けたUI大幅スリム化、コントロール統合、空き枠検索の強化を実施しました。',
                'items'     => [
                    '【進捗モニター】ヘッダーボタンを「📂 その他メニュー」へコンパクト集約、自動更新に「🔄 今すぐ更新」アイコン設置、テーブル／タイル2択化（常時高密度）、検査種別タブ内リアルタイム件数合体、サマリー「6.説明(待/中)」統合。',
                    '【デイリーノート】日付バーの重複表示を完全解消し、西暦/和暦切替＋特大Flatpickr起動ができる中央ピッカーに一本化。休診バッジ・今日へボタンと高さ32pxツライチ統一、左右ステッパーをセグメント連結ボタングループ化。',
                    '【空き枠検索】複数医師の自由な同時選択を完全サポート、高密度コンパクトカード表示モード新設、初期表示時（条件指定）の即時SSR自動検索対応（待ち時間ゼロ）、デバウンスAjax自動検索＆Pulse点滅。',
                    '【カレンダー連携】空き枠速報各カードからの「📅 カレンダー状況ホバープレビュー」機能を全面実装。'
                ],
                'display_order' => 2,
                'is_active' => true
            ]
        ];
    }
}

if (!function_exists('saveSystemInfoRecord')) {
    /**
     * 修正インフォメーションの新規登録または更新
     */
    function saveSystemInfoRecord($pdo, $data) {
        if (!($pdo instanceof PDO)) {
            throw new Exception("データベース接続が無効です。");
        }

        $id           = !empty($data['id']) ? (int)$data['id'] : 0;
        $info_date    = trim($data['info_date'] ?? date('Y-m-d'));
        $version_tag  = trim($data['version_tag'] ?? '');
        $badge_label  = trim($data['badge_label'] ?? '');
        $badge_cls    = trim($data['badge_cls'] ?? 'badge-primary');
        $title        = trim($data['title'] ?? '');
        $summary      = trim($data['summary'] ?? '');
        $detail_items = trim($data['detail_items'] ?? '');
        $display_order= isset($data['display_order']) ? (int)$data['display_order'] : 10;
        $is_active    = !empty($data['is_active']) ? 'TRUE' : 'FALSE';

        if ($title === '') {
            throw new Exception("タイトルは必須項目です。");
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE m_system_info SET
                    info_date = :info_date,
                    version_tag = :version_tag,
                    badge_label = :badge_label,
                    badge_cls = :badge_cls,
                    title = :title,
                    summary = :summary,
                    detail_items = :detail_items,
                    display_order = :display_order,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'           => $id,
                ':info_date'    => $info_date,
                ':version_tag'  => $version_tag,
                ':badge_label'  => $badge_label,
                ':badge_cls'    => $badge_cls,
                ':title'        => $title,
                ':summary'      => $summary,
                ':detail_items' => $detail_items,
                ':display_order'=> $display_order,
                ':is_active'    => $is_active,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO m_system_info (
                    info_date, version_tag, badge_label, badge_cls, title, summary, detail_items, display_order, is_active
                ) VALUES (
                    :info_date, :version_tag, :badge_label, :badge_cls, :title, :summary, :detail_items, :display_order, :is_active
                ) RETURNING id
            ");
            $stmt->execute([
                ':info_date'    => $info_date,
                ':version_tag'  => $version_tag,
                ':badge_label'  => $badge_label,
                ':badge_cls'    => $badge_cls,
                ':title'        => $title,
                ':summary'      => $summary,
                ':detail_items' => $detail_items,
                ':display_order'=> $display_order,
                ':is_active'    => $is_active,
            ]);
            $id = (int)$stmt->fetchColumn();
        }

        // JSONおよびindex.htmlとの同期
        syncSystemInfoToJsonAndHtml($pdo);

        return $id;
    }
}

if (!function_exists('toggleSystemInfoActive')) {
    /**
     * 表示/非表示の切り替え
     */
    function toggleSystemInfoActive($pdo, $id) {
        if (!($pdo instanceof PDO)) {
            throw new Exception("データベース接続が無効です。");
        }
        $id = (int)$id;
        $stmt = $pdo->prepare("UPDATE m_system_info SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $id]);

        syncSystemInfoToJsonAndHtml($pdo);
    }
}

if (!function_exists('deleteSystemInfoRecord')) {
    /**
     * 修正インフォメーションの削除
     */
    function deleteSystemInfoRecord($pdo, $id) {
        if (!($pdo instanceof PDO)) {
            throw new Exception("データベース接続が無効です。");
        }
        $id = (int)$id;
        $stmt = $pdo->prepare("DELETE FROM m_system_info WHERE id = :id");
        $stmt->execute([':id' => $id]);

        syncSystemInfoToJsonAndHtml($pdo);
    }
}

if (!function_exists('syncSystemInfoToJsonAndHtml')) {
    /**
     * DBの最新修正インフォメーションを config/system_info.json および index.html に自動同期
     */
    function syncSystemInfoToJsonAndHtml($pdo) {
        if (!($pdo instanceof PDO)) return false;

        try {
            $allRows = $pdo->query("
                SELECT id, info_date, version_tag, badge_label, badge_cls, title, summary, detail_items, display_order, is_active
                FROM m_system_info
                ORDER BY display_order ASC, info_date DESC, id DESC
            ")->fetchAll();

            $jsonList = [];
            foreach ($allRows as $r) {
                $items = [];
                if (!empty($r['detail_items'])) {
                    $lines = explode("\n", str_replace("\r", "", $r['detail_items']));
                    foreach ($lines as $l) {
                        $trimL = trim($l);
                        if ($trimL !== '') $items[] = $trimL;
                    }
                }
                $jsonList[] = [
                    'id'            => (int)$r['id'],
                    'date'          => $r['info_date'],
                    'ver'           => $r['version_tag'],
                    'badge'         => $r['badge_label'],
                    'badge_cls'     => $r['badge_cls'],
                    'title'         => $r['title'],
                    'summary'       => $r['summary'],
                    'items'         => $items,
                    'display_order' => (int)$r['display_order'],
                    'is_active'     => (bool)$r['is_active']
                ];
            }

            // 1. config/system_info.json 更新
            $cfgDir = __DIR__ . '/config';
            if (!is_dir($cfgDir)) {
                @mkdir($cfgDir, 0777, true);
            }
            $cfgPath = $cfgDir . '/system_info.json';
            @file_put_contents($cfgPath, json_encode($jsonList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 2. index.html への自動同期
            $htmlPath = __DIR__ . '/index.html';
            if (file_exists($htmlPath) && is_writable($htmlPath)) {
                $htmlContent = file_get_contents($htmlPath);
                
                // 有効な最新インフォメーションを1件抽出
                $activeList = array_values(array_filter($jsonList, function($x) { return !empty($x['is_active']); }));
                if (!empty($activeList)) {
                    $top = $activeList[0];
                    $infoDate = htmlspecialchars($top['date'], ENT_QUOTES, 'UTF-8');
                    $infoTitle = htmlspecialchars($top['title'], ENT_QUOTES, 'UTF-8');
                    $infoVer = htmlspecialchars($top['ver'] ?: 'v1101', ENT_QUOTES, 'UTF-8');

                    $itemsHtml = '';
                    foreach ($top['items'] as $item) {
                        $itemsHtml .= "                <li>" . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . "</li>\n";
                    }

                    $replacement = "<!-- START_SYSTEM_INFO -->\n"
                        . "        <div class=\"info-box\">\n"
                        . "            <div class=\"info-title\">\n"
                        . "                <span>📢 最新修正インフォメーション ({$infoDate})</span>\n"
                        . "            </div>\n"
                        . "            <p style=\"margin: 0 0 6px 0; color: #166534; font-weight: 700;\">\n"
                        . "                {$infoTitle}\n"
                        . "            </p>\n"
                        . "            <ul style=\"margin: 0; padding-left: 20px; color: #334155; font-size: 12px;\">\n"
                        . $itemsHtml
                        . "            </ul>\n"
                        . "        </div>\n"
                        . "        <!-- END_SYSTEM_INFO -->";

                    if (strpos($htmlContent, '<!-- START_SYSTEM_INFO -->') !== false && strpos($htmlContent, '<!-- END_SYSTEM_INFO -->') !== false) {
                        $htmlContent = preg_replace(
                            '/<!-- START_SYSTEM_INFO -->.*?<!-- END_SYSTEM_INFO -->/s',
                            $replacement,
                            $htmlContent
                        );
                    } else {
                        // 初回マーカー置換
                        $htmlContent = preg_replace(
                            '/<div class="info-box">.*?<\/div>\s*(?=<a href="index\.php")/s',
                            $replacement . "\n        ",
                            $htmlContent
                        );
                    }

                    // バージョンバッジおよびタイトルタグも同期
                    $badgeReplacement = "<span class=\"ver-badge\">{$infoVer} 本番稼働</span>";
                    $htmlContent = preg_replace('/<span class="ver-badge">.*?<\/span>/', $badgeReplacement, $htmlContent);
                    $titleReplacement = "<title>🔬 医療法人小野会 内視鏡予約システム ({$infoVer} 本番稼働版)</title>";
                    $htmlContent = preg_replace('/<title>.*?<\/title>/', $titleReplacement, $htmlContent);

                    @file_put_contents($htmlPath, $htmlContent);
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('renderSystemInfoBanner')) {
    /**
     * ポータル・各画面用インフォメーションバナー描画
     */
    function renderSystemInfoBanner($is_compact = false) {
        $info_list = getSystemInformations();
        if (empty($info_list)) return '';

        $latest = $info_list[0];
        $history = array_slice($info_list, 1);

        ob_start();
        ?>
        <section class="system-info-section" aria-label="システム修正インフォメーション">
            <style>
                .system-info-banner {
                    background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 50%, #eff6ff 100%);
                    border: 1.5px solid #86efac;
                    border-radius: 8px;
                    padding: 8px 14px;
                    margin-bottom: 12px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
                    font-size: 12.5px;
                }
                .system-info-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-bottom: 6px;
                }
                .system-info-title-group {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex-wrap: wrap;
                }
                .system-info-main-title {
                    font-size: 13.5px;
                    font-weight: 800;
                    color: #065f46;
                    display: inline-flex;
                    align-items: center;
                    gap: 5px;
                }
                .system-info-badge {
                    display: inline-block;
                    font-size: 11px;
                    font-weight: 800;
                    padding: 2px 8px;
                    border-radius: 12px;
                    line-height: 1.2;
                }
                .system-info-badge.badge-success {
                    background: #dcfce7;
                    color: #15803d;
                    border: 1px solid #86efac;
                }
                .system-info-badge.badge-primary {
                    background: #dbeafe;
                    color: #1d4ed8;
                    border: 1px solid #93c5fd;
                }
                .system-info-badge.badge-secondary {
                    background: #f1f5f9;
                    color: #475569;
                    border: 1px solid #cbd5e1;
                }
                .system-info-badge.badge-warning {
                    background: #fef3c7;
                    color: #b45309;
                    border: 1px solid #fcd34d;
                }
                .system-info-badge.badge-danger {
                    background: #fee2e2;
                    color: #b91c1c;
                    border: 1px solid #fca5a5;
                }
                .system-info-date {
                    font-size: 11.5px;
                    font-weight: 700;
                    color: #64748b;
                }
                .system-info-controls {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .system-info-toggle-btn {
                    background: #ffffff;
                    border: 1px solid #cbd5e1;
                    border-radius: 4px;
                    padding: 2px 8px;
                    font-size: 11px;
                    font-weight: 700;
                    color: #334155;
                    cursor: pointer;
                    transition: all 0.15s ease;
                    text-decoration: none;
                }
                .system-info-toggle-btn:hover {
                    background: #f1f5f9;
                    border-color: #94a3b8;
                    color: #0f172a;
                }
                .system-info-content {
                    background: rgba(255, 255, 255, 0.85);
                    border: 1px solid #e2e8f0;
                    border-radius: 6px;
                    padding: 8px 12px;
                    margin-top: 4px;
                }
                .system-info-heading {
                    font-weight: 800;
                    color: #0f172a;
                    margin-bottom: 4px;
                    display: flex;
                    align-items: baseline;
                    gap: 6px;
                }
                .system-info-summary {
                    color: #334155;
                    margin-bottom: 6px;
                    font-weight: 600;
                }
                .system-info-items {
                    margin: 0;
                    padding-left: 20px;
                    color: #475569;
                    line-height: 1.5;
                }
                .system-info-items li {
                    margin-bottom: 3px;
                }
                .system-info-items li:last-child {
                    margin-bottom: 0;
                }
                .system-info-history-container {
                    display: none;
                    margin-top: 8px;
                    padding-top: 8px;
                    border-top: 1px dashed #cbd5e1;
                }
                .system-info-history-container.is-open {
                    display: block;
                }
                .system-info-history-item {
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 6px;
                    padding: 8px 12px;
                    margin-bottom: 6px;
                }
            </style>

            <div class="system-info-banner">
                <div class="system-info-header">
                    <div class="system-info-title-group">
                        <span class="system-info-main-title">
                            📢 修正インフォメーション
                        </span>
                        <span class="system-info-badge <?= htmlspecialchars($latest['badge_cls'] ?? 'badge-primary', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($latest['badge'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if (!empty($latest['ver'])): ?>
                            <span style="font-family: ui-monospace, monospace; font-size: 11px; font-weight: 800; color: #0369a1; background: #e0f2fe; padding: 1px 6px; border-radius: 4px; border: 1px solid #bae6fd;">
                                <?= htmlspecialchars($latest['ver'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                        <span class="system-info-date">
                            （<?= htmlspecialchars($latest['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>）
                        </span>
                    </div>
                    <div class="system-info-controls">
                        <a href="system_info_maintenance.php" class="system-info-toggle-btn" title="インフォメーションの追加・編集・表示切替マスターを開く">
                            ⚙️ メンテ
                        </a>
                        <?php if (!empty($history)): ?>
                            <button type="button" class="system-info-toggle-btn" id="sysInfoHistoryToggleBtn" onclick="toggleSystemInfoHistory()" aria-expanded="false">
                                過去の修正履歴（<?= count($history) ?>件）▼
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="system-info-content">
                    <div class="system-info-heading">
                        <span><?= htmlspecialchars($latest['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if (!empty($latest['summary'])): ?>
                        <div class="system-info-summary">
                            <?= htmlspecialchars($latest['summary'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($latest['items'])): ?>
                        <ul class="system-info-items">
                            <?php foreach ($latest['items'] as $item): ?>
                                <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <?php if (!empty($history)): ?>
                    <div class="system-info-history-container" id="sysInfoHistoryContainer">
                        <div style="font-size: 11.5px; font-weight: 800; color: #64748b; margin-bottom: 6px;">
                            📜 過去の更新履歴
                        </div>
                        <?php foreach ($history as $h): ?>
                            <div class="system-info-history-item">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                                    <span class="system-info-badge <?= htmlspecialchars($h['badge_cls'] ?? 'badge-secondary', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($h['badge'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($h['ver'])): ?>
                                        <span style="font-family: ui-monospace, monospace; font-size: 10.5px; font-weight: 700; color: #475569; background: #f1f5f9; padding: 1px 5px; border-radius: 4px;">
                                            <?= htmlspecialchars($h['ver'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                    <strong style="color: #1e293b;"><?= htmlspecialchars($h['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span style="font-size: 11px; color: #64748b; margin-left: auto;">
                                        <?= htmlspecialchars($h['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                                <?php if (!empty($h['summary'])): ?>
                                    <div style="font-size: 12px; color: #475569; margin-bottom: 4px;">
                                        <?= htmlspecialchars($h['summary'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($h['items'])): ?>
                                    <ul class="system-info-items" style="font-size: 12px;">
                                        <?php foreach ($h['items'] as $it): ?>
                                            <li><?= htmlspecialchars($it, ENT_QUOTES, 'UTF-8') ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                function toggleSystemInfoHistory() {
                    var container = document.getElementById('sysInfoHistoryContainer');
                    var btn = document.getElementById('sysInfoHistoryToggleBtn');
                    if (!container || !btn) return;
                    var isOpen = container.classList.toggle('is-open');
                    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    btn.textContent = isOpen ? '過去の修正履歴を閉じる ▲' : '過去の修正履歴（<?= count($history) ?>件）▼';
                }
            </script>
        </section>
        <?php
        return ob_get_clean();
    }
}
