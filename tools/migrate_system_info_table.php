<?php
/**
 * tools/migrate_system_info_table.php
 * 修正インフォメーション管理テーブル (m_system_info) のマイグレーション
 * 
 * ローカルDB (localhost) および本番DB (192.168.1.16) にテーブルを作成し、
 * 初期データ（v1101 本番稼働版 含む3件）を投入します。
 * また、config/system_info.json を自動生成・同期します。
 */

ini_set('display_errors', "1");
error_reporting(E_ALL);

echo "=======================================================\n";
echo " 📢 修正インフォメーションテーブル (m_system_info) マイグレーション\n";
echo "=======================================================\n\n";

$tableSql = "
CREATE TABLE IF NOT EXISTS m_system_info (
    id SERIAL PRIMARY KEY,
    info_date DATE NOT NULL,
    version_tag VARCHAR(32) NOT NULL DEFAULT '',
    badge_label VARCHAR(64) NOT NULL DEFAULT '',
    badge_cls VARCHAR(32) NOT NULL DEFAULT 'badge-primary',
    title VARCHAR(255) NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    detail_items TEXT NOT NULL DEFAULT '',
    display_order INT NOT NULL DEFAULT 10,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_m_system_info_active_order ON m_system_info (is_active, display_order, info_date DESC);
";

$initialData = [
    [
        'info_date'    => '2026-11-01',
        'version_tag'  => 'v1101',
        'badge_label'  => '重要・本番稼働開始',
        'badge_cls'    => 'badge-success',
        'title'        => '🎉 内視鏡予約・運用管理システム v1101 本番稼働を開始しました',
        'summary'      => '現場運用開始に向けたUI大幅スリム化、コントロール統合、空き枠検索の強化を実施しました。',
        'detail_items' => implode("\n", [
            '【進捗モニター】ヘッダーボタンを「📂 その他メニュー」へコンパクト集約、自動更新に「🔄 今すぐ更新」アイコン設置、テーブル／タイル2択化（常時高密度）、検査種別タブ内リアルタイム件数合体、サマリー「6.説明(待/中)」統合。',
            '【デイリーノート】日付バーの重複表示を完全解消し、西暦/和暦切替＋特大Flatpickr起動ができる中央ピッカーに一本化。休診バッジ・今日へボタンと高さ32pxツライチ統一、左右ステッパーをセグメント連結ボタングループ化。',
            '【空き枠検索】複数医師の自由な同時選択を完全サポート、高密度コンパクトカード表示モード新設、初期表示時（条件指定）の即時SSR自動検索対応（待ち時間ゼロ）、デバウンスAjax自動検索＆Pulse点滅。',
            '【カレンダー連携】空き枠速報各カードからの「📅 カレンダー状況ホバープレビュー」機能を全面実装。'
        ]),
        'display_order' => 1,
        'is_active'    => true
    ],
    [
        'info_date'    => '2026-10-30',
        'version_tag'  => 'ver1030',
        'badge_label'  => '印刷DX',
        'badge_cls'    => 'badge-primary',
        'title'        => '院内プリンターマスターメンテ新設 ＆ 下部検査ネームプレート印刷対応',
        'summary'      => 'プリンター出力先の統合管理と専用ネームプレート印刷機能を配備しました。',
        'detail_items' => implode("\n", [
            '院内プリンターマスターメンテ（printer_master_maintenance.php）新設に伴い、各端末・各帳票の出力先プリンターを柔軟設定可能に。',
            '下部内視鏡検査用ネームプレート印刷（横147×縦70mm A4面付け）に対応。'
        ]),
        'display_order' => 2,
        'is_active'    => true
    ],
    [
        'info_date'    => '2026-10-27',
        'version_tag'  => 'ver1027',
        'badge_label'  => '機能強化',
        'badge_cls'    => 'badge-secondary',
        'title'        => 'A4当日予約一覧印刷DX ＆ 前処置室重複リアルタイム検知',
        'summary'      => 'A4印刷画面での操作性向上と前処置室・下剤の一括設定UXを強化しました。',
        'detail_items' => implode("\n", [
            'A4印刷画面での左右キー日付移動、年号省略日付、前処置室・下剤の一括設定UX強化。'
        ]),
        'display_order' => 3,
        'is_active'    => true
    ]
];

function applyMigrationToPdo($pdo, $envName, $tableSql, $initialData) {
    echo "--- [{$envName}] テーブル作成 & 初期データ同期中 ---\n";
    $pdo->exec($tableSql);
    echo "  ✓ m_system_info テーブル定義を確認/作成完了\n";

    $count = (int)$pdo->query("SELECT COUNT(*) FROM m_system_info")->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO m_system_info (
                info_date, version_tag, badge_label, badge_cls, title, summary, detail_items, display_order, is_active
            ) VALUES (
                :info_date, :version_tag, :badge_label, :badge_cls, :title, :summary, :detail_items, :display_order, :is_active
            )
        ");
        foreach ($initialData as $row) {
            $stmt->execute([
                ':info_date'    => $row['info_date'],
                ':version_tag'  => $row['version_tag'],
                ':badge_label'  => $row['badge_label'],
                ':badge_cls'    => $row['badge_cls'],
                ':title'        => $row['title'],
                ':summary'      => $row['summary'],
                ':detail_items' => $row['detail_items'],
                ':display_order'=> $row['display_order'],
                ':is_active'    => $row['is_active'] ? 'TRUE' : 'FALSE',
            ]);
        }
        echo "  ✓ 初期データ " . count($initialData) . " 件を投入しました\n";
    } else {
        echo "  ℹ 既存データが {$count} 件存在するため、初期投入をスキップしました\n";
    }
}

// 1. ローカルDB
try {
    $pdoLocal = new PDO('pgsql:host=localhost;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    applyMigrationToPdo($pdoLocal, 'ローカル環境 (localhost)', $tableSql, $initialData);
} catch (Exception $e) {
    echo "❌ ローカルDBエラー: " . $e->getMessage() . "\n";
}

// 2. 本番DB
try {
    $pdoProd = new PDO('pgsql:host=192.168.1.16;port=5432;dbname=endoscope;', 'postgres', 'postgres', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    applyMigrationToPdo($pdoProd, '本番環境 (192.168.1.16)', $tableSql, $initialData);
} catch (Exception $e) {
    echo "⚠️ 本番DBへの接続/反映をスキップ: " . $e->getMessage() . "\n";
}

// 3. config/system_info.json のエクスポート生成
$configFile = dirname(__DIR__) . '/config/system_info.json';
$exportData = [];
foreach ($initialData as $item) {
    $itemsArr = array_filter(array_map('trim', explode("\n", $item['detail_items'])));
    $exportData[] = [
        'date'      => $item['info_date'],
        'ver'       => $item['version_tag'],
        'badge'     => $item['badge_label'],
        'badge_cls' => $item['badge_cls'],
        'title'     => $item['title'],
        'summary'   => $item['summary'],
        'items'     => array_values($itemsArr),
        'display_order' => $item['display_order'],
        'is_active' => $item['is_active']
    ];
}
file_put_contents($configFile, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n✓ {$configFile} を生成・同期しました。\n";
echo "\n🎉 マイグレーション完了！\n";
