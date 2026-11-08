import re

path = r'c:\Apache24\htdocs\endoscope\仕様書\specdoc.html'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# バージョン番号の昇格（ver1013 -> ver1014）
content = content.replace('ver1013', 'ver1014')
content = content.replace('2026-11-01 (ver1013)', '2026-11-01 (ver1014)')

# 第32章の追加
sec32_html = '''
        <!-- 32. 空き枠検索 条件指定検索パワーアップ（全科横断・コンパクトカード・Ajaxリアルタイム検索） -->
        <section class="spec-section" id="cat-slot-search-powerup">
            <h2 class="section-title">
                <span>32. 空き枠検索 条件指定検索パワーアップ（全科横断・コンパクトカード・Ajaxリアルタイム検索）</span>
                <span class="badge badge-success">2026-11-01 新設</span>
            </h2>
            <div class="card">
                <div class="card-header">
                    <span class="screen-id">機能識別: FEATURE-SLOT-SEARCH-POWERUP</span>
                    <span class="screen-name">条件指定検索 全科横断・高密度コンパクトカード・Ajaxリアルタイム検索 ＆ Pulse点滅</span>
                </div>
                <div class="card-body">
                    <p class="screen-desc">空き枠検索画面（<code>slot_search.php</code>）の条件指定検索モードにおいて、全科（胃腸科・肛門科）の横断検索、検索結果の「高密度コンパクトカード表示」モード新設、検索ボタン押し忘れを防ぐ「Pulse点滅アニメーション」および「デバウンスAjax自動検索」を導入し、操作性と一覧性を最大化しました。</p>
                    <div class="feature-title">📌 主な機能仕様</div>
                    <ul class="feature-list">
                        <li><strong>診療科「全科」横断検索:</strong> <code>[肛門科] [胃腸科] [全科]</code> ボタンを新設。「全科」選択時は全医師（誠吾、哲也、義智、慶太郎）が科バッジ（<code>🟢 誠吾 (肛)</code>, <code>🔵 哲也 (胃)</code>, <code>🟣 義智 (胃)</code>, <code>🟠 慶太郎 (胃)</code>）付きで選択可能。胃腸科・肛門科の両方をわずか 5.5ms で合算検索。</li>
                        <li><strong>高密度「🗂️ コンパクトカード」表示モード:</strong> 従来の「📄 テーブル表示」に加え、省スペースなカードグリッド（<code>minmax(235px, 1fr)</code>）表示を新設。日付、曜日、科バッジ、医師アバター＋名前、検査バッジ、残枠メーター、カレンダーボタン、予約ボタンが1枚に凝縮。</li>
                        <li><strong>表示切替トグル ＆ 状態永続化:</strong> 結果ヘッダー右上の <code>[📄 テーブル] [🗂️ コンパクトカード]</code> トグルでいつでも瞬時に表示形式を切り替え可能。ユーザーの選択状態は <code>localStorage</code> に保存され、次回アクセス時も自動復元。</li>
                        <li><strong>検索ボタン「フワフワ点滅（Pulseアニメーション）」:</strong> 期間（日付・月）、診療科、医師、検査種別、曜日のいずれかが変更された瞬間に、検索ボタンが青く点滅して変更状態を視覚的にアピール。</li>
                        <li><strong>デバウンス（250ms）非同期Ajax自動検索:</strong> 条件変更から 250ms 後に画面リロードなしの非同期通信（<code>ajax=1</code>）で即時自動検索を実行。手動で検索ボタンを押した場合もAjax即時更新で滑らかに動作。</li>
                        <li><strong>コンパクトカードからのカレンダー状況ホバープレビュー連動:</strong> 各カードの「📅 カレンダー」ボタンにマウスを合わせると、第31章のカレンダーホバープレビューが起動し、胃腸科・肛門科の全医師消化/定員および全体枠集計・メモ・医師予定をその場で確認可能。</li>
                    </ul>
                </div>
            </div>
        </section>
'''

content = content.replace('</main>', sec32_html + '\n</main>')

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Specdoc updated successfully!")
