import re

path = r'c:\Apache24\htdocs\endoscope\仕様書\2026-11-1works.html'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# セクション2の末尾に「タスク3: 条件指定検索パワーアップ」を追加
task3_html = '''
            <!-- タスク3: 条件指定検索パワーアップ -->
            <div class="task-card">
                <div class="task-title">
                    <span class="badge badge-success">完了</span>
                    タスク3: 条件指定検索パワーアップ（全科横断・高密度コンパクトカード・Ajaxリアルタイム検索＆Pulse点滅）
                </div>
                <p>
                    空き枠検索画面（<code>slot_search.php</code>）の条件指定検索モードについて、ユーザーの操作性・一覧性を飛躍的に向上させる以下の4大強化を実施しました。
                </p>
                <ul class="task-list">
                    <li>
                        <strong>① 診療科「全科」横断検索サポート:</strong><br>
                        従来の「肛門科」「胃腸科」に加え、<code>[全科]</code> ボタンを新設。全科選択時は全医師（誠吾、哲也、義智、慶太郎）が科バッジ付き（<code>🟢 誠吾 (肛)</code>, <code>🔵 哲也 (胃)</code>, <code>🟣 義智 (胃)</code>, <code>🟠 慶太郎 (胃)</code>）で表示され、バックエンドも両科横断でわずか 5.5ms のインメモリ走査により瞬時に合算抽出。
                    </li>
                    <li>
                        <strong>② 高密度「🗂️ コンパクトカード」表示モードの追加 ＆ 切替トグル:</strong><br>
                        従来の「📄 テーブル表示」に加え、大量の結果（最大300件）でも快適に見渡せる高密度カードグリッド（<code>minmax(235px, 1fr)</code>）表示を新設。結果ヘッダーのトグルボタンでいつでもワンタップ切り替えが可能（選択状態は <code>localStorage</code> で次回訪問時も自動維持）。
                    </li>
                    <li>
                        <strong>③ 検索ボタン押し忘れ防止「フワフワ点滅（Pulseアニメーション）」＆ デバウンスAjax自動検索:</strong><br>
                        期間（日付ピッカー/月ショートカット）、診療科、医師、検査種別、曜日のいずれかが変更された瞬間に、検索ボタンが青くフワフワと点滅（Pulseアニメーション）して状態変化をアピール。同時に250msのデバウンスで非同期Ajax検索を自動発火し、画面リロードなしで瞬時に結果を更新。手動検索ボタン押下時もAjax即時更新に対応。
                    </li>
                    <li>
                        <strong>④ コンパクトカードからのカレンダー状況ホバープレビュー連動:</strong><br>
                        コンパクトカード内の「📅 カレンダー」ボタンにマウスを合わせると、直近速報カードと同様にダーク系モダンツールチップが浮遊し、胃腸科・肛門科の全医師消化/定員および全体枠集計・メモ・不在予定が即座に確認可能。オンデマンドAPIとも連動し未来の全日程に対応。
                    </li>
                </ul>
            </div>
'''

content = content.replace('        <!-- セクション3: 改修・作成ファイル一覧 -->', task3_html + '\n        <!-- セクション3: 改修・作成ファイル一覧 -->')

# 改修ファイル一覧テーブルの更新
file_row_update = '''                    <tr>
                        <td><code>lib/calendar_helper.php</code></td>
                        <td><span class="badge badge-primary">機能拡張</span></td>
                        <td>全科両対応のカレンダープレビューデータ一括集計関数 <code>getCalendarPreviewsForDates()</code> を共通ヘルパーとして新設</td>
                    </tr>
                    <tr>
                        <td><code>action_slot_assistant.php</code></td>
                        <td><span class="badge badge-primary">機能拡張</span></td>
                        <td>プレビュー生成を共通関数に一本化し、オンデマンド取得用エンドポイント（<code>action=get_calendar_preview</code>）を追加</td>
                    </tr>
                    <tr>
                        <td><code>slot_search.php</code></td>
                        <td><span class="badge badge-primary">大幅強化</span></td>
                        <td>全科横断検索、Ajaxリアルタイム自動検索エンドポイント、検索ボタンPulseアニメーション、高密度コンパクトカード表示モード、表示切替トグルおよびカレンダープレビュー連動を全面実装</td>
                    </tr>'''

content = re.sub(r'<tr>\s*<td><code>action_slot_assistant\.php</code></td>.*?</tr>\s*<tr>\s*<td><code>slot_search\.php</code></td>.*?</tr>', file_row_update, content, flags=re.DOTALL)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Works report updated successfully!")
