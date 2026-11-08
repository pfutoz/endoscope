import os

html_content = '''<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>医療法人小野会 共通APIプラットフォーム＆テスター</title>
    <!-- 共通ピッカー CSS -->
    <link rel="stylesheet" href="/api/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="/api/v1/calendar/onokai_flatpickr.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --surface: #ffffff;
            --bg: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Hiragino Sans', 'Noto Sans JP', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 60px;
        }

        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #fff;
            padding: 24px 32px;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header .badge {
            background: rgba(255, 255, 255, 0.2);
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 9999px;
            letter-spacing: 0.5px;
        }
        .header-links {
            display: flex;
            gap: 12px;
        }
        .btn-header {
            background: #ffffff;
            color: var(--primary-dark);
            text-decoration: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-header:hover {
            background: #f1f5f9;
            transform: translateY(-1px);
        }

        .container {
            max-width: 1400px;
            margin: 28px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 24px;
        }

        .card {
            background: var(--surface);
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 24px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid var(--primary-light);
            padding-bottom: 8px;
        }

        .form-group {
            margin-bottom: 14px;
        }
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 9px 12px;
            font-size: 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            transition: border-color 0.2s;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .btn-primary {
            width: 100%;
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
        }

        /* プレビューグリッド */
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            margin-top: 14px;
        }
        .calendar-cell {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px 6px;
            min-height: 72px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-size: 12px;
            transition: transform 0.1s;
        }
        .calendar-cell:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.06);
            z-index: 2;
        }
        .calendar-header-day {
            font-weight: bold;
            text-align: center;
            padding: 6px 0;
            background: #f1f5f9;
            border-radius: 4px;
            font-size: 13px;
        }
        .calendar-header-day.sun { color: var(--danger); background: #fee2e2; }
        .calendar-header-day.sat { color: var(--primary); background: #dbeafe; }

        .cell-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cell-date { font-weight: 700; font-size: 13px; }
        .cell-dow { font-size: 11px; color: var(--text-muted); }
        .cell-badge {
            font-size: 10px;
            padding: 2px 4px;
            border-radius: 4px;
            margin-top: 4px;
            font-weight: bold;
            display: inline-block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cell-badge.closed { background: #fee2e2; color: #dc2626; }
        .cell-badge.thursday { background: #e0f2fe; color: #0284c7; }

        /* JSON表示エリア */
        .json-viewer {
            background: #0f172a;
            color: #38bdf8;
            padding: 16px;
            border-radius: 8px;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 12px;
            max-height: 480px;
            overflow: auto;
            white-space: pre-wrap;
            line-height: 1.4;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        .stat-val { font-size: 22px; font-weight: 800; color: var(--text-main); }
        .stat-label { font-size: 11px; color: var(--text-muted); font-weight: 600; margin-top: 2px; }

        /* ピッカーテスト用コンテナ */
        .picker-demo-box {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-top: 10px;
        }

        .tab-nav {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 16px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
    </style>
</head>
<body>

    <header class="header">
        <div>
            <h1>🏥 医療法人小野会 共通APIプラットフォーム <span class="badge">v1.0.0</span></h1>
            <p style="font-size:12px; opacity:0.85; margin-top:4px;">全院内Webシステム（内視鏡 / 勤怠 / かわらばん / 予定表）統一基盤</p>
        </div>
        <div class="header-links">
            <a href="/api/仕様書/" class="btn-header">📖 API仕様書を見る</a>
            <a href="/endoscope/" class="btn-header" style="background:rgba(255,255,255,0.15); color:#fff;">🔬 内視鏡システムへ</a>
        </div>
    </header>

    <div class="container">
        <!-- 左サイド: テストコントローラー -->
        <aside>
            <div class="card">
                <div class="card-title">⚙️ APIリクエスト設定</div>
                
                <div class="form-group">
                    <label class="form-label">判定モード</label>
                    <select id="selMode" class="form-select">
                        <option value="month_standard">📅 暦月（当月1日〜末日）★標準</option>
                        <option value="month_payroll">💼 小野会給与月（前月21日〜当月20日）</option>
                        <option value="range">↔ 任意期間指定 (start 〜 end)</option>
                        <option value="single">🎯 単一日付判定 (date)</option>
                    </select>
                </div>

                <!-- 年月指定用 -->
                <div id="grpMonth">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                        <div class="form-group">
                            <label class="form-label">年 (Year)</label>
                            <input type="number" id="inpYear" class="form-control" value="2026" min="2020" max="2030">
                        </div>
                        <div class="form-group">
                            <label class="form-label">月 (Month)</label>
                            <input type="number" id="inpMonth" class="form-control" value="11" min="1" max="12">
                        </div>
                    </div>
                </div>

                <!-- 任意期間指定用 -->
                <div id="grpRange" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">開始日 (start)</label>
                        <input type="date" id="inpStart" class="form-control" value="2026-11-01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">終了日 (end)</label>
                        <input type="date" id="inpEnd" class="form-control" value="2026-11-15">
                    </div>
                </div>

                <!-- 単一日付指定用 -->
                <div id="grpSingle" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">指定日付 (date)</label>
                        <input type="date" id="inpDate" class="form-control" value="2026-11-03">
                    </div>
                    <div class="form-group">
                        <label class="form-label">時間枠 (任意 HH:MM)</label>
                        <input type="text" id="inpTime" class="form-control" placeholder="14:00">
                    </div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                    <label class="form-label">送信URL</label>
                    <div id="txtApiUrl" style="font-size:11px; color:#2563eb; background:#eff6ff; padding:8px; border-radius:6px; word-break:break-all; font-family:monospace;">
                        /api/v1/calendar/holidays.php?year=2026&month=11
                    </div>
                </div>

                <button id="btnExecute" class="btn-primary">⚡ APIを実行してテスト</button>
            </div>

            <!-- 特大Flatpickr共通ピッカーデモ -->
            <div class="card">
                <div class="card-title">🌟 特大Flatpickr共通ピッカー</div>
                <p style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">
                    この共通APIから休診日データを自動取得（キャッシュ＋フォールバック）する新ピッカーの動作確認です。
                </p>
                <div class="picker-demo-box">
                    <input type="text" id="demoPicker" class="onokai-date-picker" value="2026-11-04" style="display:none;">
                </div>
            </div>
        </aside>

        <!-- 右メイン: 結果プレビュー＆JSON -->
        <main>
            <div class="card">
                <div class="card-title" style="justify-content:space-between;">
                    <span>📊 API実行結果プレビュー</span>
                    <span id="txtStatusBadge" style="background:#dcfce7; color:#15803d; font-size:12px; padding:3px 10px; border-radius:6px; font-weight:700;">HTTP 200 OK</span>
                </div>

                <!-- サマリー統計 -->
                <div class="summary-stats" id="summaryStats">
                    <div class="stat-card">
                        <div class="stat-val" id="statTotal">--</div>
                        <div class="stat-label">対象総日数</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val" id="statClosed" style="color:#ef4444;">--</div>
                        <div class="stat-label">休診日数 (日曜/祝日)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val" id="statClinic" style="color:#10b981;">--</div>
                        <div class="stat-label">診療日数</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-val" id="statHolidays" style="color:#f59e0b;">--</div>
                        <div class="stat-label">祝日数</div>
                    </div>
                </div>

                <!-- ビュー切替タブ -->
                <div class="tab-nav">
                    <button class="tab-btn active" data-tab="tabCalendar">📅 カレンダー視覚ビュー</button>
                    <button class="tab-btn" data-tab="tabJson">💻 JSON 生レスポンス</button>
                </div>

                <!-- カレンダー表示タブ -->
                <div id="tabCalendar">
                    <div class="preview-grid" id="calHeaders">
                        <div class="calendar-header-day sun">日</div>
                        <div class="calendar-header-day">月</div>
                        <div class="calendar-header-day">火</div>
                        <div class="calendar-header-day">水</div>
                        <div class="calendar-header-day">木</div>
                        <div class="calendar-header-day">金</div>
                        <div class="calendar-header-day sat">土</div>
                    </div>
                    <div class="preview-grid" id="calBody" style="margin-top:6px;">
                        <!-- 動的生成 -->
                    </div>
                </div>

                <!-- JSON表示タブ -->
                <div id="tabJson" style="display:none;">
                    <pre class="json-viewer" id="jsonOutput"></pre>
                </div>
            </div>
        </main>
    </div>

    <!-- 共通ピッカー スクリプト -->
    <script src="/api/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="/api/vendor/flatpickr/ja.js"></script>
    <script src="/api/v1/calendar/onokai_flatpickr.js"></script>

    <script>
        // DOM要素
        const selMode = document.getElementById('selMode');
        const grpMonth = document.getElementById('grpMonth');
        const grpRange = document.getElementById('grpRange');
        const grpSingle = document.getElementById('grpSingle');
        const inpYear = document.getElementById('inpYear');
        const inpMonth = document.getElementById('inpMonth');
        const inpStart = document.getElementById('inpStart');
        const inpEnd = document.getElementById('inpEnd');
        const inpDate = document.getElementById('inpDate');
        const inpTime = document.getElementById('inpTime');
        const txtApiUrl = document.getElementById('txtApiUrl');
        const btnExecute = document.getElementById('btnExecute');
        const jsonOutput = document.getElementById('jsonOutput');
        const calBody = document.getElementById('calBody');

        // モード切替
        function updateFormUI() {
            const mode = selMode.value;
            grpMonth.style.display = (mode === 'month_standard' || mode === 'month_payroll') ? 'block' : 'none';
            grpRange.style.display = (mode === 'range') ? 'block' : 'none';
            grpSingle.style.display = (mode === 'single') ? 'block' : 'none';

            let url = '/api/v1/calendar/holidays.php';
            if (mode === 'month_standard') {
                url += `?year=${inpYear.value}&month=${inpMonth.value}`;
            } else if (mode === 'month_payroll') {
                url += `?year=${inpYear.value}&month=${inpMonth.value}&mode=payroll`;
            } else if (mode === 'range') {
                url += `?start=${inpStart.value}&end=${inpEnd.value}`;
            } else if (mode === 'single') {
                url += `?date=${inpDate.value}`;
                if (inpTime.value) url += `&time=${encodeURIComponent(inpTime.value)}`;
            }
            txtApiUrl.textContent = url;
        }

        selMode.addEventListener('change', updateFormUI);
        [inpYear, inpMonth, inpStart, inpEnd, inpDate, inpTime].forEach(el => {
            el.addEventListener('input', updateFormUI);
        });

        // タブ切替
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const targetTab = btn.dataset.tab;
                document.getElementById('tabCalendar').style.display = (targetTab === 'tabCalendar') ? 'block' : 'none';
                document.getElementById('tabJson').style.display = (targetTab === 'tabJson') ? 'block' : 'none';
            });
        });

        // API実行
        async function runApi() {
            updateFormUI();
            const url = txtApiUrl.textContent.trim();
            btnExecute.textContent = '読込中...';
            btnExecute.disabled = true;

            try {
                const res = await fetch(url);
                const json = await res.json();
                jsonOutput.textContent = JSON.stringify(json, null, 2);

                // サマリー更新
                if (json.summary) {
                    document.getElementById('statTotal').textContent = json.summary.total_days;
                    document.getElementById('statClosed').textContent = json.summary.closed_days;
                    document.getElementById('statClinic').textContent = json.summary.clinic_days;
                    document.getElementById('statHolidays').textContent = json.summary.holiday_count;
                } else if (json.data && json.type === 'single_date') {
                    document.getElementById('statTotal').textContent = '1';
                    document.getElementById('statClosed').textContent = json.data.is_closed ? '1' : '0';
                    document.getElementById('statClinic').textContent = json.data.is_closed ? '0' : '1';
                    document.getElementById('statHolidays').textContent = json.data.is_holiday ? '1' : '0';
                }

                // カレンダー描画
                renderCalendarPreview(json);

            } catch (err) {
                jsonOutput.textContent = 'エラーが発生しました: ' + err.message;
            } finally {
                btnExecute.textContent = '⚡ APIを実行してテスト';
                btnExecute.disabled = false;
            }
        }

        btnExecute.addEventListener('click', runApi);

        // カレンダーグリッド描画
        function renderCalendarPreview(json) {
            calBody.innerHTML = '';
            let dateMap = {};
            if (json.data && json.type === 'single_date') {
                dateMap[json.data.date] = json.data;
            } else if (json.data) {
                dateMap = json.data;
            }

            const dates = Object.keys(dateMap).sort();
            if (dates.length === 0) {
                calBody.innerHTML = '<div style="grid-column:1/-1; padding:20px; text-align:center; color:#94a3b8;">データがありません</div>';
                return;
            }

            // 最初の日の曜日合わせ（空白セル）
            const firstDateStr = dates[0];
            const firstDow = dateMap[firstDateStr].day_of_week_num;
            for (let i = 0; i < firstDow; i++) {
                const empty = document.createElement('div');
                empty.style.background = 'transparent';
                calBody.appendChild(empty);
            }

            dates.forEach(dStr => {
                const item = dateMap[dStr];
                const cell = document.createElement('div');
                cell.className = 'calendar-cell';
                
                // 背景色と文字色
                if (item.style) {
                    cell.style.backgroundColor = item.style.bg;
                    cell.style.color = item.style.fg;
                }

                let badgeHtml = '';
                if (item.is_closed || item.reason) {
                    badgeHtml = `<span class="cell-badge closed" title="${item.reason}">${item.reason || '休診'}</span>`;
                } else if (item.day_of_week_num === 4) {
                    badgeHtml = `<span class="cell-badge thursday">木曜</span>`;
                }

                const dNum = parseInt(dStr.split('-')[2], 10);
                cell.innerHTML = `
                    <div class="cell-top">
                        <span class="cell-date">${dNum}</span>
                        <span class="cell-dow">${item.day_of_week}</span>
                    </div>
                    <div>${badgeHtml}</div>
                `;
                calBody.appendChild(cell);
            });
        }

        // 初期化実行
        document.addEventListener('DOMContentLoaded', () => {
            updateFormUI();
            runApi();

            // 特大Flatpickrピッカーの初期化
            if (typeof attachOnokaiDatePicker === 'function') {
                attachOnokaiDatePicker('#demoPicker');
            }
        });
    </script>
</body>
</html>'''

with open(r'c:\Apache24\htdocs\api\index.html', 'w', encoding='utf-8') as f:
    f.write(html_content)

print('Done writing c:\\Apache24\\htdocs\\api\\index.html')
