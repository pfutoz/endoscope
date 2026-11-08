import re

path = r'c:\Apache24\htdocs\endoscope\slot_search.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. CSSの追加（</style>の直前に挿入）
css_to_add = '''
        /* ── 検索ボタン フワフワ点滅アニメーション ── */
        @keyframes searchBtnPulse {
            0% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.7);
            }
            50% {
                transform: scale(1.04);
                box-shadow: 0 0 0 10px rgba(37, 99, 235, 0);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0);
            }
        }
        .btn-search.pulse {
            animation: searchBtnPulse 1.6s infinite ease-in-out;
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            position: relative;
        }

        /* ── 医師ボタン内の診療科バッジ ── */
        .doc-dept-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
            margin-left: 4px;
            vertical-align: middle;
        }
        .doc-dept-badge.proct {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .doc-dept-badge.gastro {
            background: #e0f2fe;
            color: #075985;
            border: 1px solid #bae6fd;
        }

        /* ── 表示切替トグル（テーブル ⇔ コンパクトカード） ── */
        .custom-view-toggle {
            display: inline-flex;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 3px;
            gap: 3px;
        }
        .custom-view-btn {
            border: none;
            background: transparent;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .custom-view-btn:hover {
            color: #0f172a;
            background: #e2e8f0;
        }
        .custom-view-btn.active {
            background: #ffffff;
            color: #1d4ed8;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* ── コンパクトカード用 スタイル ── */
        .compact-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(235px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        .compact-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
            transition: all 0.15s ease;
            position: relative;
        }
        .compact-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            border-color: #94a3b8;
        }
        .compact-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 6px;
        }
        .compact-card-date {
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 700;
            color: #1e293b;
        }
        .compact-card-dow {
            font-size: 11px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 4px;
        }
        .compact-card-dept {
            font-size: 10px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .compact-card-dept.gastro {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .compact-card-dept.proct {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .compact-card-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .compact-doc-info {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .compact-doc-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 12px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .compact-doc-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #1e293b;
        }

        .compact-slot-info {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
        }
        .compact-slot-count {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.1;
        }
        .compact-slot-max {
            font-size: 10.5px;
            color: #64748b;
            font-weight: 600;
        }

        .compact-card-footer {
            display: flex;
            gap: 6px;
            margin-top: 4px;
            padding-top: 6px;
            border-top: 1px solid #f8fafc;
        }
        .compact-btn {
            flex: 1;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            line-height: 1.2;
        }
        .compact-btn-cal {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #334155;
        }
        .compact-btn-cal:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .compact-btn-book {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .compact-btn-book:hover {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1e40af;
        }

        /* リアルタイム検索ローディング表示 */
        .custom-search-loading {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            color: #2563eb;
            font-weight: 700;
            font-size: 13px;
            background: #f8fafc;
            border-radius: 8px;
            margin-top: 12px;
            border: 1px dashed #cbd5e1;
        }
        .spinner-dot {
            width: 8px;
            height: 8px;
            background: #2563eb;
            border-radius: 50%;
            animation: spinnerDot 0.8s infinite alternate;
        }
        @keyframes spinnerDot {
            0% { transform: scale(0.6); opacity: 0.4; }
            100% { transform: scale(1.2); opacity: 1; }
        }
'''

content = content.replace('    </style>', css_to_add + '    </style>')

# 2. 診療科ボタングループのHTML更新（「全科」ボタンを追加）
dept_btn_pat = r'(<div class="filter-row">\s*<span class="group-label-btn"[^>]*>診療科</span>\s*<div class="btn-group-items">\s*<button type="button" class="btn btn-dept[^"]*" data-dept="PROCT">肛門科</button>\s*<button type="button" class="btn btn-dept[^"]*" data-dept="GASTRO">胃腸科</button>\s*<input type="hidden" name="dept" id="deptInput" value="<\?= \$selected_dept \?>">\s*</div>\s*</div>)'

dept_btn_replacement = '''<div class="filter-row">
            <span class="group-label-btn" style="cursor:default;">診療科</span>
            <div class="btn-group-items">
                <button type="button" class="btn btn-dept <?= $selected_dept === 'PROCT' ? 'active' : '' ?>" data-dept="PROCT">肛門科</button>
                <button type="button" class="btn btn-dept <?= $selected_dept === 'GASTRO' ? 'active' : '' ?>" data-dept="GASTRO">胃腸科</button>
                <button type="button" class="btn btn-dept <?= $selected_dept === 'all' ? 'active' : '' ?>" data-dept="all">全科</button>
                <input type="hidden" name="dept" id="deptInput" value="<?= $selected_dept ?>">
            </div>
        </div>'''

content = re.sub(dept_btn_pat, dept_btn_replacement, content, flags=re.DOTALL)

# 3. 検索ボタンに id="btnSearchCustom" を付与
search_btn_pat = r'(<button type="submit" class="btn-search">🔍 検索</button>)'
content = re.sub(search_btn_pat, r'<button type="submit" class="btn-search" id="btnSearchCustom">🔍 検索</button>', content)

# 4. 結果表示エリアの改修（ローディング、件数ヘッダー、切替トグル、テーブルコンテナ、カードコンテナ）
result_area_pat = r'(<\?php if \(\$too_many_count > 0\): \?>.*?<\?php endif; \?>\s*<\?php if \(\$search_clicked && \$too_many_count === 0\): \?>.*?<\?php endif; \?>\s*</div><!-- /#customViewContainer -->)'

result_area_replacement = '''<!-- ローディング表示 -->
        <div id="customSearchLoading" class="custom-search-loading">
            <div class="spinner-dot"></div>
            <span>条件に一致する空き枠を検索しています...</span>
        </div>

        <div id="customTooManyBox" class="error-box" style="display: <?= $too_many_count > 0 ? 'block' : 'none' ?>;">
            <h3 id="customTooManyTitle">⚠️ 検索結果が300件を超えました（<span id="customTooManyNum"><?= number_format($too_many_count) ?></span> 件）</h3>
            <p>条件（医師・曜日・月など）を絞り込んで再検索してください。</p>
        </div>

        <div id="customResultSection" style="display: <?= ($search_clicked && $too_many_count === 0) ? 'block' : 'none' ?>;">
            <div class="result-header">
                <div class="count">📊 検索結果: <span id="customResultCount"><?= $result_count ?></span> 件</div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div class="custom-view-toggle" role="group" aria-label="表示形式切替">
                        <button type="button" class="custom-view-btn" id="btnViewTable" onclick="setCustomViewMode('table')" title="表形式で詳細確認">
                            📄 テーブル
                        </button>
                        <button type="button" class="custom-view-btn" id="btnViewCards" onclick="setCustomViewMode('cards')" title="高密度カード形式で一覧確認">
                            🗂️ コンパクトカード
                        </button>
                    </div>
                </div>
            </div>

            <!-- 0件表示 -->
            <div id="customNoResults" class="no-results" style="display: <?= ($search_clicked && $too_many_count === 0 && empty($results)) ? 'block' : 'none' ?>;">
                該当する空き枠がありませんでした。
            </div>

            <!-- テーブルコンテナ -->
            <div id="customTableContainer" style="display: none;">
                <table class="result-table" aria-label="空き枠検索結果一覧">
                    <thead>
                        <tr>
                            <th scope="col">日付</th>
                            <th scope="col">曜日</th>
                            <th scope="col">診療科</th>
                            <th scope="col">医師</th>
                            <th scope="col">検査種別</th>
                            <th scope="col">残枠 / 定員</th>
                        </tr>
                    </thead>
                    <tbody id="customTableBody">
                        <?php foreach ($results as $row): 
                            $exam = (string)($row['exam_type'] ?? '');
                            $date_str = (string)$row['exam_date'];
                            $dow = (int)date('w', strtotime($date_str));
                            $dep_name = (string)($row['department_name'] ?? '');
                            $dep_tag_cls = (string)($row['dept_tag_cls'] ?? 'tag-gastro');
                        ?>
                            <tr class="clickable" tabindex="0" role="button" onclick="goToSchedule('<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string)($row['department_code'] ?? 'GASTRO'), ENT_QUOTES, 'UTF-8') ?>')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); goToSchedule('<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars((string)($row['department_code'] ?? 'GASTRO'), ENT_QUOTES, 'UTF-8') ?>');}">
                                <td class="tabular-nums"><?= date('Y/m/d', strtotime($date_str)) ?></td>
                                <td><?= $week_ja[$dow] ?? '' ?></td>
                                <td><span class="urgent-dept-tag <?= $dep_tag_cls ?>"><?= htmlspecialchars($dep_name, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string)($row['doctor_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="exam-tag <?= getExamClass($exam) ?>"><?= getExamIcon($exam) ?> <?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="tabular-nums">
                                    <span class="free-slots <?= getFreeColorClass((int)$row['free_slots']) ?>"><?= (int)$row['free_slots'] ?></span>
                                    <span style="color:#64748b; font-size:12px;"> / <?= (int)$row['max_slots'] ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- コンパクトカードコンテナ -->
            <div id="customCardsContainer" style="display: none;">
                <div class="compact-cards-grid" id="customCardsGrid">
                    <?php foreach ($results as $row): 
                        $exam = (string)($row['exam_type'] ?? '');
                        $date_str = (string)$row['exam_date'];
                        $dow = (int)date('w', strtotime($date_str));
                        $dow_ja_str = $week_ja[$dow] ?? '';
                        $dow_cls = ($dow === 6) ? 'dow-sat' : (($dow === 0) ? 'dow-sun' : 'dow-weekday');
                        $dep_code = (string)($row['department_code'] ?? 'GASTRO');
                        $dep_name = (string)($row['department_name'] ?? '胃腸科');
                        $dep_cls = ($dep_code === 'GASTRO') ? 'gastro' : 'proct';
                        $doc_name = (string)($row['doctor_name'] ?? '');
                        $doc_short = (string)($row['doctor_short'] ?? mb_substr($doc_name, 0, 1));
                        $doc_color = (string)($row['doctor_color'] ?? '#334155');
                        $free_cls = getFreeColorClass((int)$row['free_slots']);
                        $y = date('Y', strtotime($date_str));
                        $m = (int)date('m', strtotime($date_str));
                    ?>
                        <div class="compact-card">
                            <div class="compact-card-header">
                                <div class="compact-card-date">
                                    <span>📅 <?= date('Y/m/d', strtotime($date_str)) ?></span>
                                    <span class="compact-card-dow <?= $dow_cls ?>"><?= $dow_ja_str ?></span>
                                </div>
                                <span class="compact-card-dept <?= $dep_cls ?>"><?= htmlspecialchars($dep_name, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>

                            <div class="compact-card-body">
                                <div class="compact-doc-info">
                                    <span class="compact-doc-avatar" style="background: <?= $doc_color ?>;"><?= htmlspecialchars($doc_short, ENT_QUOTES, 'UTF-8') ?></span>
                                    <div>
                                        <div class="compact-doc-name"><?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?> 先生</div>
                                        <span class="exam-tag <?= getExamClass($exam) ?>" style="font-size:10px; padding:1px 6px;">
                                            <?= getExamIcon($exam) ?> <?= htmlspecialchars($exam, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="compact-slot-info">
                                    <span class="compact-slot-count free-slots <?= $free_cls ?>">残 <?= (int)$row['free_slots'] ?> 枠</span>
                                    <span class="compact-slot-max">定員 <?= (int)$row['max_slots'] ?></span>
                                </div>
                            </div>

                            <div class="compact-card-footer">
                                <a href="month_schedule.php?dept=<?= urlencode($dep_code) ?>&year=<?= $y ?>&month=<?= $m ?>&date=<?= urlencode($date_str) ?>&display_mode=remain" 
                                   class="compact-btn compact-btn-cal urgent-btn-cal" 
                                   data-cal-preview-date="<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>" 
                                   title="当日の予約状況をプレビュー (ホバー)">
                                    📅 カレンダー
                                </a>
                                <button type="button" 
                                        onclick="jumpAndBookSlot('<?= htmlspecialchars($dep_code, ENT_QUOTES, 'UTF-8') ?>', <?= $y ?>, <?= $m ?>, '<?= htmlspecialchars($date_str, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?>')" 
                                        class="compact-btn compact-btn-book" 
                                        title="新規予約登録へ">
                                    ➕ 予約
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($debug_mode && !empty($executed_sql)): ?>
            <div class="sql-box"><?= htmlspecialchars($executed_sql, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div><!-- /#customViewContainer -->'''

content = re.sub(result_area_pat, result_area_replacement, content, flags=re.DOTALL)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Frontend HTML/CSS updated successfully!")
