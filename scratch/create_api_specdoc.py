import os

spec_content = '''<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小野会共通APIプラットフォーム 機能・技術仕様書</title>
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-accent: #2563eb;
            --primary-light: #eff6ff;
            --secondary: #0f766e;
            --surface: #ffffff;
            --bg: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #475569;
            --border: #e2e8f0;
            --border-dark: #cbd5e1;
            --success: #15803d;
            --success-bg: #dcfce7;
            --warning: #b45309;
            --warning-bg: #fef3c7;
            --danger: #b91c1c;
            --danger-bg: #fee2e2;
            --code-bg: #0f172a;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Hiragino Sans", "Noto Sans JP", sans-serif;
            background: var(--bg);
            color: var(--text-main);
            line-height: 1.7;
            font-size: 15px;
            padding-bottom: 80px;
        }

        .header {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 50%, #2563eb 100%);
            color: #fff;
            padding: 40px 32px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.2);
        }
        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-meta {
            margin-top: 8px;
            font-size: 13px;
            opacity: 0.9;
            display: flex;
            gap: 20px;
        }
        .badge {
            background: rgba(255, 255, 255, 0.2);
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }
        .btn-header {
            background: #ffffff;
            color: var(--primary);
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-header:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }

        .main-container {
            max-width: 1200px;
            margin: 32px auto;
            padding: 0 24px;
        }

        .alert-box {
            background: #ffffff;
            border-left: 5px solid var(--primary-accent);
            padding: 18px 22px;
            border-radius: 8px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid var(--border);
            border-left-width: 5px;
        }
        .alert-box.important {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }
        .alert-box.important .alert-title {
            color: #b45309;
        }
        .alert-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 6px;
            color: var(--primary-accent);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .section-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            border-bottom: 2px solid var(--primary-light);
            padding-bottom: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        h3 {
            font-size: 16px;
            font-weight: 700;
            margin: 24px 0 12px 0;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        p, ul, ol {
            color: var(--text-muted);
            margin-bottom: 16px;
        }
        ul, ol {
            padding-left: 24px;
        }
        li { margin-bottom: 6px; }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0 24px 0;
            font-size: 14px;
        }
        th, td {
            padding: 12px 16px;
            border: 1px solid var(--border);
            text-align: left;
        }
        th {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
        }
        tr:hover td {
            background: #f1f5f9;
        }

        .code-block {
            background: var(--code-bg);
            color: #e2e8f0;
            padding: 16px 20px;
            border-radius: 8px;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.5;
            overflow-x: auto;
            margin: 16px 0 24px 0;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.3);
        }
        .code-inline {
            background: #e2e8f0;
            color: #0f172a;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            font-weight: 600;
        }

        .method-badge {
            background: #10b981;
            color: #ffffff;
            font-weight: 800;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: 0.5px;
        }

        .endpoint-card {
            background: #f8fafc;
            border: 1px solid var(--border-dark);
            border-radius: 8px;
            padding: 18px 22px;
            margin-bottom: 20px;
        }
        .endpoint-header {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: monospace;
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
        }

        .step-list {
            list-style: none;
            padding-left: 0;
            counter-reset: step-counter;
        }
        .step-item {
            position: relative;
            padding-left: 48px;
            margin-bottom: 20px;
        }
        .step-item::before {
            counter-increment: step-counter;
            content: counter(step-counter);
            position: absolute;
            left: 0;
            top: 0;
            width: 32px;
            height: 32px;
            background: var(--primary-accent);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
        }
        .step-title {
            font-weight: 700;
            font-size: 16px;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .roadmap-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .roadmap-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px;
            background: #fff;
        }
        .roadmap-card.done {
            border-color: #86efac;
            background: #f0fdf4;
        }
        .roadmap-status {
            font-size: 11px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-bottom: 8px;
        }
        .roadmap-status.done { background: #bbf7d0; color: #166534; }
        .roadmap-status.next { background: #fed7aa; color: #9a3412; }
        .roadmap-status.plan { background: #e2e8f0; color: #475569; }
    </style>
</head>
<body>

    <header class="header">
        <div class="header-inner">
            <div>
                <h1>🏥 小野会共通APIプラットフォーム 仕様書 <span class="badge">v1.0.0</span></h1>
                <div class="header-meta">
                    <span>制定日: 2026年11月4日</span>
                    <span>所管: 医療法人小野会 システム開発室</span>
                    <span>設置パス: <code>C:\\Apache24\\htdocs\\api\\</code></span>
                </div>
            </div>
            <div class="header-actions">
                <a href="/api/" class="btn-header">⚡ APIテスター画面を開く</a>
            </div>
        </div>
    </header>

    <div class="main-container">

        <!-- 移行の基本方針アラート -->
        <div class="alert-box important">
            <div class="alert-title">⚠️ 共通API化における基本方針と安全対策（絶対無停止・非破壊）</div>
            <p style="margin-bottom:0;">
                本共通APIプラットフォーム（<code>/htdocs/api/</code>）への移行作業は、各システム（内視鏡、勤怠、かわらばん、予定表）が現在進行系で診療運用中のため、<strong>既存コードを破壊せず、ラッパー関数を通じて完全後方互換性を維持しながら段階的に移行</strong>します。
            </p>
        </div>

        <!-- 1. 背景と目的 -->
        <section class="section">
            <h2 class="section-title">1. 背景と制定目的</h2>
            <p>
                医療法人小野会の各Webシステム（内視鏡予約 <code>endoscope</code>、勤怠管理 <code>ono</code>、院内掲示板・サイネージ <code>kawara</code>、医師予定表 <code>yotei</code> など）では、カレンダー・休診日判定や日付ピッカーUIが各リポジトリにコピーされ、独自に保守されていました。
            </p>
            <p>
                これにより、<strong>「祝日・休診日ロジックの修正が各システムに波及しない」「勤怠システムは20日締め固定だが、内視鏡やサイネージは暦月（1日〜末日）が必要」</strong>といった重複・食い違いの課題が発生していました。
            </p>
            <p>
                本プラットフォームは、Apacheのルート直下（<code>/htdocs/api/</code>）に院内共通のREST API・PHPライブラリ・共通JavaScript/CSSアセットを集約し、<strong>全システム・全クライアントから単一の統一仕様で利用可能にすること</strong>を目的としています。
            </p>
        </section>

        <!-- 2. アーキテクチャと配置構成 -->
        <section class="section">
            <h2 class="section-title">2. アーキテクチャとディレクトリ構成</h2>
            <div class="code-block">C:\Apache24\htdocs\api\
├── index.html                  # APIテスター＆ビジュアルプレビュー画面
├── 仕様書\
│   └── index.html              # 本技術仕様書
├── vendor\
│   └── flatpickr\             # 院内共通Flatpickrライブラリ (JS/CSS/日本語ロケール)
└── v1\
    └── calendar\
        ├── holidays.php        # 【REST API】休診日・祝日算出エンドポイント (JSON返却)
        ├── helper.php          # 【PHPコアライブラリ】OnokaiCalendarHelper クラス
        ├── onokai_flatpickr.js # 【共通ピッカーJS】API連携・キャッシュ・フォールバック内蔵
        └── onokai_flatpickr.css# 【共通ピッカーCSS】52px特大マス・角丸ピル形状スタイル</div>

            <h3>提供する3つの利用形態</h3>
            <table>
                <thead>
                    <tr>
                        <th style="width:25%;">利用形態</th>
                        <th style="width:35%;">呼び出しコード例</th>
                        <th>主な利用用途</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>① HTTP REST API</strong><br>(JSON返却)</td>
                        <td><code>fetch('/api/v1/calendar/holidays.php?year=2026&month=11')</code></td>
                        <td>ブラウザフロントエンド、Ajax、外部クライアント連携</td>
                    </tr>
                    <tr>
                        <td><strong>② PHPライブラリ</strong><br>(直接Include)</td>
                        <td><code>require_once 'C:/Apache24/htdocs/api/v1/calendar/helper.php';</code><br><code>OnokaiCalendarHelper::check($date);</code></td>
                        <td>PHPサーバーサイド処理（ゼロ通信遅延での高速判定）</td>
                    </tr>
                    <tr>
                        <td><strong>③ 共通ピッカーUI</strong><br>(JS / CSS)</td>
                        <td><code>&lt;script src="/api/v1/calendar/onokai_flatpickr.js"&gt;&lt;/script&gt;</code><br><code>attachOnokaiDatePicker('#myDate');</code></td>
                        <td>カルテ・受付端末向け特大日付入力UIの全システム展開</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- 3. 休診日・祝日算出API 詳細仕様 -->
        <section class="section">
            <h2 class="section-title">3. 休診日・祝日算出API (v1/calendar/holidays.php)</h2>
            
            <div class="endpoint-card">
                <div class="endpoint-header">
                    <span class="method-badge">GET</span>
                    <span>/api/v1/calendar/holidays.php</span>
                </div>
            </div>

            <h3>リクエストパラメータ</h3>
            <table>
                <thead>
                    <tr>
                        <th>パラメータ名</th>
                        <th>型</th>
                        <th>必須</th>
                        <th>デフォルト</th>
                        <th>説明</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>year</code></td>
                        <td>Integer</td>
                        <td>任意</td>
                        <td>当年の西暦</td>
                        <td>対象年 (1970〜2099)</td>
                    </tr>
                    <tr>
                        <td><code>month</code></td>
                        <td>Integer</td>
                        <td>任意</td>
                        <td>当月</td>
                        <td>対象月 (1〜12)</td>
                    </tr>
                    <tr>
                        <td><code>mode</code></td>
                        <td>String</td>
                        <td>任意</td>
                        <td><code>month</code></td>
                        <td>
                            <strong><code>month</code></strong>: 一般的な暦月 (当月1日〜末日)<br>
                            <strong><code>payroll</code></strong>: 小野会給与月 (前月21日〜当月20日)
                        </td>
                    </tr>
                    <tr>
                        <td><code>cutoff</code></td>
                        <td>Integer</td>
                        <td>任意</td>
                        <td>-</td>
                        <td><code>cutoff=20</code> を指定した場合、<code>mode=payroll</code> と同等に処理</td>
                    </tr>
                    <tr>
                        <td><code>start</code> / <code>end</code></td>
                        <td>String</td>
                        <td>任意</td>
                        <td>-</td>
                        <td>任意期間の一括取得 (形式: <code>YYYY-MM-DD</code>)</td>
                    </tr>
                    <tr>
                        <td><code>date</code></td>
                        <td>String</td>
                        <td>任意</td>
                        <td>-</td>
                        <td>単一日付の即時判定 (形式: <code>YYYY-MM-DD</code>)</td>
                    </tr>
                    <tr>
                        <td><code>time</code></td>
                        <td>String</td>
                        <td>任意</td>
                        <td>-</td>
                        <td>時間枠 (<code>HH:MM</code>)。12/30午後や木曜午後の半休判定に使用</td>
                    </tr>
                </tbody>
            </table>

            <h3>レスポンス仕様（JSON）</h3>
            <p>月間指定（暦月モード）の成功レスポンス例：</p>
            <div class="code-block">{
  "status": "success",
  "type": "month",
  "mode": "month",
  "year": 2026,
  "month": 11,
  "period": {
    "start": "2026-11-01",
    "end": "2026-11-30"
  },
  "summary": {
    "total_days": 30,
    "closed_days": 6,
    "clinic_days": 24,
    "holiday_count": 2
  },
  "data": {
    "2026-11-01": {
      "date": "2026-11-01",
      "day_of_week": "日",
      "day_of_week_num": 0,
      "is_holiday": false,
      "is_closed": true,
      "reason": "日曜休診",
      "is_half_off": false,
      "style": { "bg": "#ffe6e6", "fg": "#d9383a" }
    },
    "2026-11-03": {
      "date": "2026-11-03",
      "day_of_week": "火",
      "day_of_week_num": 2,
      "is_holiday": true,
      "is_closed": true,
      "reason": "文化の日",
      "is_half_off": false,
      "style": { "bg": "#ffe6e6", "fg": "#d9383a" }
    },
    "2026-11-04": {
      "date": "2026-11-04",
      "day_of_week": "水",
      "day_of_week_num": 3,
      "is_holiday": false,
      "is_closed": false,
      "reason": "",
      "is_half_off": false,
      "style": { "bg": "#ffffff", "fg": "#333333" }
    }
  }
}</div>

            <h3>小野会カレンダー判定ルール詳細</h3>
            <ul>
                <li><strong>日曜休診:</strong> 曜日番号 <code>0</code>。終日休診（<code>is_closed: true</code>）。</li>
                <li><strong>年末年始休診:</strong> <code>12月31日 〜 1月3日</code> の4日間は終日休診。</li>
                <li><strong>12月30日午後:</strong> 日付単体では診療日。時間枠 <code>time >= 13:00</code> が渡された場合は午後休診（<code>is_half_off: true</code>）。カレンダー上は水色ハイライト。</li>
                <li><strong>木曜日午後:</strong> 日付単体では診療日。時間枠 <code>time >= 13:00</code> が渡された場合は午後休診（<code>is_half_off: true</code>）。カレンダー上は木曜水色ハイライト。</li>
                <li><strong>国民の祝日:</strong> 固定祝日（元日、建国記念、天皇誕生日、昭和の日、憲法、みどり、こども、山、文化、勤労感謝）、ハッピーマンデー（成人、海、敬老、スポーツ）、春分・秋分（天文計算式）。</li>
                <li><strong>振替休日:</strong> 日曜日が祝日の場合、その直後の平日（通常は月曜、5/3または5/4が日曜の場合は5/6）。</li>
                <li><strong>国民の休日:</strong> 祝日と祝日に挟まれた平日（敬老の日と秋分の日の間など）。</li>
            </ul>
        </section>

        <!-- 4. 特大Flatpickr共通ピッカー連携仕様 -->
        <section class="section">
            <h2 class="section-title">4. 特大Flatpickr共通ピッカー連携仕様</h2>
            <p>
                <code>/api/v1/calendar/onokai_flatpickr.js</code> は、小野会専用の特大カレンダーコントロールを各システムで統一展開するための共通スクリプトです。
            </p>
            <h3>主な特徴</h3>
            <ul>
                <li><strong>ゼロレイテンシ（即座表示）:</strong> 内蔵計算ロジックによるフォールバック機構を備え、通信遅延なしで瞬時にカレンダーを表示。</li>
                <li><strong>共通API自動連携＆キャッシュ:</strong> カレンダー初期化時・月めくり時に <code>/api/v1/calendar/holidays.php</code> から該当月の最新データを自動取得し、ブラウザ内メモリにキャッシュ。</li>
                <li><strong>西暦・和暦ワンクリック切替:</strong> 和暦（令和X年）と西暦の表示切り替えを全画面・端末間で同期記憶（<code>localStorage</code>）。</li>
                <li><strong>ダイアログ＆見切れ完全補正:</strong> HTML5 <code>&lt;dialog&gt;</code> の裏隠れを防止し、画面端での左右はみ出しを自動補正。</li>
            </ul>

            <h3>他システムでの導入コード（例: kawara / yotei / ono）</h3>
            <div class="code-block">&lt;!-- 共通ピッカー CSS --&gt;
&lt;link rel="stylesheet" href="/api/vendor/flatpickr/flatpickr.min.css"&gt;
&lt;link rel="stylesheet" href="/api/v1/calendar/onokai_flatpickr.css"&gt;

&lt;!-- 対象の input 要素 --&gt;
&lt;input type="text" id="targetDate" name="target_date" value="2026-11-04"&gt;

&lt;!-- 共通ピッカー スクリプト --&gt;
&lt;script src="/api/vendor/flatpickr/flatpickr.min.js"&gt;&lt;/script&gt;
&lt;script src="/api/vendor/flatpickr/ja.js"&gt;&lt;/script&gt;
&lt;script src="/api/v1/calendar/onokai_flatpickr.js"&gt;&lt;/script&gt;
&lt;script&gt;
    // ワンコールで小野会角丸ピル特大ピッカーに自動バインド！
    attachOnokaiDatePicker('#targetDate');
&lt;/script&gt;</div>
        </section>

        <!-- 5. 移行手順と安全性担保（危険性低減対策） -->
        <section class="section">
            <h2 class="section-title">5. 既存システムの安全な移行手順（危険性低減対策）</h2>
            <p>
                本改修では既存システムへの影響を極小化するため、<strong>「ラッパー方式」</strong>を採用します。既存ファイルを削除・置換するのではなく、内部で共通ライブラリを呼び出す構造にすることで、100%の後方互換性を保証します。
            </p>

            <ul class="step-list">
                <li class="step-item">
                    <div class="step-title">共通基盤（/htdocs/api/）の先行構築と単体テスト（完了）</div>
                    <p>共通API、ライブラリ、テスター画面、仕様書を作成し、HTTPおよびCLIでテストを実施して全パラメータでの正常稼働を検証。</p>
                </li>
                <li class="step-item">
                    <div class="step-title">内視鏡側 helper（calendar_helper_onokai.php）のラッパー化</div>
                    <p><code>endoscope/calendar_helper_onokai.php</code> の先頭で共通APIの <code>helper.php</code> を <code>require_once</code> し、共通クラスのメソッドへ移譲。関数のシグネチャ・戻り値構造を完全維持。</p>
                </li>
                <li class="step-item">
                    <div class="step-title">内視鏡側既存テスト群（tests/）の一括検証</div>
                    <p>内視鏡システムの予約枠計算、月間カレンダー表示、休診日スキップ等の既存テストを実行し、一切のデグレがないことを確認。</p>
                </li>
                <li class="step-item">
                    <div class="step-title">他システム（ono, kawara, yotei）への順次展開</div>
                    <p>勤怠（<code>ono</code>）やサイネージ（<code>kawara</code>）の各システムに対しても同様にラッパー方式で順次適用。</p>
                </li>
            </ul>
        </section>

        <!-- 6. 今後の共通API化ロードマップ -->
        <section class="section">
            <h2 class="section-title">6. 今後の共通API化ロードマップ</h2>
            <p>小野会イントラネット全体の保守効率向上のため、以下の機能を順次 <code>/htdocs/api/</code> へ統合予定です。</p>

            <div class="roadmap-grid">
                <div class="roadmap-card done">
                    <span class="roadmap-status done">完了（フェーズ1）</span>
                    <h4 style="margin-bottom:6px; color:#1e293b;">📅 カレンダー・休診日API</h4>
                    <p style="font-size:12px; margin-bottom:0;">祝日・休診日算出、歴月/締日対応、特大ピッカー共通化</p>
                </div>
                <div class="roadmap-card">
                    <span class="roadmap-status next">次期着手予定</span>
                    <h4 style="margin-bottom:6px; color:#1e293b;">👨‍⚕️ 医師予定・不在マスタAPI</h4>
                    <p style="font-size:12px; margin-bottom:0;"><code>yotei</code> システムと連携した医師の休診・不在情報の共通配信</p>
                </div>
                <div class="roadmap-card">
                    <span class="roadmap-status plan">計画中</span>
                    <h4 style="margin-bottom:6px; color:#1e293b;">📢 院内通知・サイネージAPI</h4>
                    <p style="font-size:12px; margin-bottom:0;"><code>kawara</code>（かわらばん）の休診告知や緊急連絡の一元取得</p>
                </div>
                <div class="roadmap-card">
                    <span class="roadmap-status plan">計画中</span>
                    <h4 style="margin-bottom:6px; color:#1e293b;">🔐 共通認証・セッションAPI</h4>
                    <p style="font-size:12px; margin-bottom:0;"><code>auth_db</code> と連動したシングルサインオンおよび職員権限判定</p>
                </div>
            </div>
        </section>

    </div>

</body>
</html>'''

with open(r'c:\Apache24\htdocs\api\仕様書\index.html', 'w', encoding='utf-8') as f:
    f.write(spec_content)

print('Done writing c:\\Apache24\\htdocs\\api\\仕様書\\index.html')
