const fs = require('fs');
const path = require('path');

const filePath = 'c:\\Apache24\\htdocs\\ip\\inf.php';
let content = fs.readFileSync(filePath, 'utf8');

const sectionHtml = `
    <!-- ─── 4. クライアント常駐ヘルスチェック監視ツール ─── -->
    <h2>4. 💻 クライアント常駐ヘルスチェック監視ツール (10分周期)</h2>
    <div class="card" style="border-top: 4px solid #7c3aed;">
        <p style="font-size:14px; margin-top:0;">
            受付端末、ドクター診察室PC、外注検査・院内検査端末などの重要PCに導入することで、<strong>SSD/HDDのSMART健康状態（故障予兆・残存寿命%・温度）やCドライブ空き容量、CPU・メモリ負荷を10分ごとに自動収集し、この台帳サーバーへ自動送信・集約</strong>します。<br>
            院内の重要端末の突然のディスククラッシュや容量枯渇を未然に察知・予防できます。
        </p>

        <div style="background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div>
                    <h3 style="margin:0 0 5px; color:#5b21b6; font-size:16px;">📦 クライアント用ヘルス監視エージェント一式 (ZIP)</h3>
                    <p style="margin:0; font-size:13px; color:#6b7280;">10分毎にバックグラウンド自動実行（画面表示なし・サイレント・超低負荷）</p>
                </div>
                <div>
                    <a href="download_agent.php" class="btn" style="background:#7c3aed; color:#fff; font-size:14px; font-weight:bold; padding:10px 22px; text-decoration:none; border-radius:6px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 4px rgba(124,58,237,0.3);">
                        📥 ZIPファイルをダウンロード
                    </a>
                </div>
            </div>
        </div>

        <h3 style="font-size:15px; color:#333; margin-bottom:8px;">導入手順（たったの3ステップ）</h3>
        <ol style="font-size:13px; line-height:1.7; color:#334155; margin-left: 20px; padding-left: 0;">
            <li>上記の <strong>[ZIPファイルをダウンロード]</strong> ボタンを押して、監視したいPCに保存します。</li>
            <li>ダウンロードした <code>onokai_client_health_agent.zip</code> を右クリックし、<strong>「すべて展開」</strong> を選択してフォルダを展開します。</li>
            <li>展開したフォルダの中にある <code>register_client_health_task.bat</code> を <strong>右クリック ➔ 「管理者として実行」</strong> します。</li>
        </ol>

        <div style="margin-top: 12px; background: #e0f2fe; border-left: 4px solid #0284c7; padding: 10px 14px; border-radius: 4px; font-size: 13px; color: #0369a1;">
            <strong>💡 動作仕様と安心設計:</strong>
            <ul style="margin: 5px 0 0 16px; padding: 0;">
                <li>タスクスケジューラに「10分間隔・最下位優先度・完全非表示」で登録されるため、受付や診察業務中の画面に黒い画面（コマンドプロンプト）がポップアップすることは一切ありません。</li>
                <li>収集所要時間はわずか1〜2秒、CPU負荷も軽微です。</li>
                <li>登録と同時に1回目のヘルス情報が自動送信され、管理表に 🟢 SMART健康状態 や SSD残存寿命% が反映されます。</li>
                <li>もし監視を停止・削除したい場合は、同じフォルダ内の <code>unregister_client_health_task.bat</code> を「管理者として実行」するだけで完全に解除されます。</li>
            </ul>
        </div>
    </div>
`;

if (content.includes('4. 💻 クライアント常駐ヘルスチェック監視ツール')) {
    console.log('inf.php already contains health section.');
} else {
    // <script> の直前にある最後の </div> の前に挿入
    const scriptIndex = content.lastIndexOf('<script>');
    if (scriptIndex === -1) {
        console.error('Could not find <script>');
        process.exit(1);
    }
    const beforeScript = content.substring(0, scriptIndex);
    const lastDivIndex = beforeScript.lastIndexOf('</div>');
    if (lastDivIndex === -1) {
        console.error('Could not find </div> before script');
        process.exit(1);
    }
    const newContent = beforeScript.substring(0, lastDivIndex) + sectionHtml + '\n' + beforeScript.substring(lastDivIndex) + content.substring(scriptIndex);
    fs.writeFileSync(filePath, newContent, 'utf8');
    console.log('Successfully updated inf.php!');
}
