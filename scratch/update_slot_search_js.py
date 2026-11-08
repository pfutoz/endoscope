import re

path = r'c:\Apache24\htdocs\endoscope\slot_search.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# JavaScript ブロックの全面刷新
# <script> const allDoctors = ... から </script> までを置換
js_pattern = r'(<script>\s*const allDoctors = .*?function goToSchedule\(date\)[^}]*\}\s*</script>)'

new_js = '''<script>
const allDoctors = <?= json_encode($all_doctors) ?>;
const doctorMetaMap = <?= json_encode($doctor_meta_map) ?>;
let currentSearchMode = '<?= $search_mode ?>';
let urgentSlotCache = null;
let currentUrgentDoctor = 'all';

// 条件指定検索用状態
let calPreviewCache = {};
let currentCustomViewMode = localStorage.getItem('endoscope_custom_view_mode') || 'table';
let autoSearchTimer = null;

document.addEventListener('DOMContentLoaded', function() {
    initButtons('doctor', '#doctorContainer');
    initButtons('exam', '#examContainer');
    initButtons('weekday', '#weekdayContainer');

    // 初期表示モード適用
    setCustomViewMode(currentCustomViewMode);

    if (currentSearchMode === 'urgent') {
        loadUrgentSlots(false);
    }
});

// ── モード切替（タブ） ──
function switchSearchMode(targetMode) {
    currentSearchMode = targetMode;
    const tabUrgent = document.getElementById('tabModeUrgent');
    const tabCustom = document.getElementById('tabModeCustom');
    const viewUrgent = document.getElementById('urgentViewContainer');
    const viewCustom = document.getElementById('customViewContainer');

    if (targetMode === 'urgent') {
        tabUrgent.classList.add('active');
        tabCustom.classList.remove('active');
        viewUrgent.style.display = 'block';
        viewCustom.style.display = 'none';

        if (!urgentSlotCache) {
            loadUrgentSlots(false);
        }
    } else {
        tabCustom.classList.add('active');
        tabUrgent.classList.remove('active');
        viewCustom.style.display = 'block';
        viewUrgent.style.display = 'none';

        // 条件指定検索タブ初回表示時、もし結果エリアが空なら自動検索
        const resultSection = document.getElementById('customResultSection');
        if (!resultSection || resultSection.style.display === 'none') {
            runCustomSearchAjax();
        }
    }
}

// ── 表示モード切替（テーブル ⇔ コンパクトカード） ──
function setCustomViewMode(mode) {
    currentCustomViewMode = mode;
    localStorage.setItem('endoscope_custom_view_mode', mode);

    const btnTable = document.getElementById('btnViewTable');
    const btnCards = document.getElementById('btnViewCards');
    const containerTable = document.getElementById('customTableContainer');
    const containerCards = document.getElementById('customCardsContainer');
    const noResults = document.getElementById('customNoResults');

    const isNoResults = noResults && noResults.style.display === 'block';

    if (mode === 'cards') {
        if (btnCards) btnCards.classList.add('active');
        if (btnTable) btnTable.classList.remove('active');
        if (containerCards && !isNoResults) containerCards.style.display = 'block';
        if (containerTable) containerTable.style.display = 'none';
    } else {
        if (btnTable) btnTable.classList.add('active');
        if (btnCards) btnCards.classList.remove('active');
        if (containerTable && !isNoResults) containerTable.style.display = 'block';
        if (containerCards) containerCards.style.display = 'none';
    }
}

// ── 条件変更の検知 ＆ 検索ボタンPulse点滅 ＆ デバウンス自動検索 ──
function triggerConditionChange() {
    const btnSearch = document.getElementById('btnSearchCustom');
    if (btnSearch) {
        btnSearch.classList.add('pulse');
    }

    if (autoSearchTimer) {
        clearTimeout(autoSearchTimer);
    }
    // 250ms デバウンスで自動検索
    autoSearchTimer = setTimeout(() => {
        runCustomSearchAjax();
    }, 250);
}

// ── 条件指定 Ajaxリアルタイム検索 ──
async function runCustomSearchAjax() {
    const form = document.getElementById('searchForm');
    if (!form) return;

    const loading = document.getElementById('customSearchLoading');
    const resultSection = document.getElementById('customResultSection');
    const tooManyBox = document.getElementById('customTooManyBox');
    const noResults = document.getElementById('customNoResults');
    const btnSearch = document.getElementById('btnSearchCustom');

    const months = document.getElementById('monthsInput').value;
    const from = document.getElementById('date_from').value;
    const to = document.getElementById('date_to').value;
    if (!months && (!from || !to)) {
        return;
    }

    if (loading) loading.style.display = 'flex';
    if (resultSection && resultSection.style.display === 'block') {
        resultSection.style.opacity = '0.6';
    }

    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.set('ajax', '1');
    params.set('search_clicked', '1');

    try {
        const res = await fetch(`slot_search.php?${params.toString()}&_t=${Date.now()}`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || '検索エラーが発生しました');
        }

        // プレビューキャッシュのマージ
        if (data.calendar_previews) {
            Object.assign(calPreviewCache, data.calendar_previews);
        }

        if (data.too_many) {
            if (tooManyBox) {
                tooManyBox.style.display = 'block';
                const numSpan = document.getElementById('customTooManyNum');
                if (numSpan) numSpan.textContent = data.too_many_count.toLocaleString();
            }
            if (resultSection) resultSection.style.display = 'none';
        } else {
            if (tooManyBox) tooManyBox.style.display = 'none';
            if (resultSection) {
                resultSection.style.display = 'block';
                resultSection.style.opacity = '1';
            }

            const countSpan = document.getElementById('customResultCount');
            if (countSpan) countSpan.textContent = data.count;

            if (data.count === 0) {
                if (noResults) noResults.style.display = 'block';
                const tbl = document.getElementById('customTableContainer');
                const crd = document.getElementById('customCardsContainer');
                if (tbl) tbl.style.display = 'none';
                if (crd) crd.style.display = 'none';
            } else {
                if (noResults) noResults.style.display = 'none';
                renderCustomTable(data.results || []);
                renderCustomCards(data.results || []);
                setCustomViewMode(currentCustomViewMode);
            }
        }

        // URLパラメータ同期（リロード時再現用）
        params.delete('ajax');
        const newUrl = `${window.location.pathname}?${params.toString()}`;
        window.history.replaceState({}, '', newUrl);

    } catch (err) {
        console.error('runCustomSearchAjax error:', err);
    } finally {
        if (loading) loading.style.display = 'none';
        if (resultSection) resultSection.style.opacity = '1';
        if (btnSearch) btnSearch.classList.remove('pulse');
    }
}

// ── テーブル動的描画 ──
function renderCustomTable(results) {
    const tbody = document.getElementById('customTableBody');
    if (!tbody) return;

    if (results.length === 0) {
        tbody.innerHTML = '';
        return;
    }

    let html = '';
    results.forEach(r => {
        const depCls = r.department_code === 'GASTRO' ? 'tag-gastro' : 'tag-proct';
        html += `
            <tr class="clickable" tabindex="0" role="button" onclick="goToSchedule('${r.exam_date}', '${r.department_code}')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault(); goToSchedule('${r.exam_date}', '${r.department_code}');}">
                <td class="tabular-nums">${r.exam_date.replace(/-/g, '/')}</td>
                <td>${r.dow_ja}</td>
                <td><span class="urgent-dept-tag ${depCls}">${escapeHtml(r.department_name)}</span></td>
                <td>${escapeHtml(r.doctor_name)}</td>
                <td><span class="exam-tag ${r.exam_cls}">${r.exam_icon} ${escapeHtml(r.exam_type)}</span></td>
                <td class="tabular-nums">
                    <span class="free-slots ${r.free_color_cls}">${r.free_slots}</span>
                    <span style="color:#64748b; font-size:12px;"> / ${r.max_slots}</span>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

// ── コンパクトカード動的描画 ──
function renderCustomCards(results) {
    const grid = document.getElementById('customCardsGrid');
    if (!grid) return;

    if (results.length === 0) {
        grid.innerHTML = '';
        return;
    }

    let html = '';
    results.forEach(r => {
        let dowCls = 'dow-weekday';
        if (r.dow === 6) dowCls = 'dow-sat';
        else if (r.dow === 0) dowCls = 'dow-sun';

        const depCls = r.department_code === 'GASTRO' ? 'gastro' : 'proct';
        const docShort = r.doctor_short || r.doctor_name.charAt(0);
        const docColor = r.doctor_color || '#334155';

        html += `
            <div class="compact-card">
                <div class="compact-card-header">
                    <div class="compact-card-date">
                        <span>📅 ${r.exam_date.replace(/-/g, '/')}</span>
                        <span class="compact-card-dow ${dowCls}">${r.dow_ja}</span>
                    </div>
                    <span class="compact-card-dept ${depCls}">${escapeHtml(r.department_name)}</span>
                </div>

                <div class="compact-card-body">
                    <div class="compact-doc-info">
                        <span class="compact-doc-avatar" style="background: ${docColor};">${escapeHtml(docShort)}</span>
                        <div>
                            <div class="compact-doc-name">${escapeHtml(r.doctor_name)} 先生</div>
                            <span class="exam-tag ${r.exam_cls}" style="font-size:10px; padding:1px 6px;">
                                ${r.exam_icon} ${escapeHtml(r.exam_type)}
                            </span>
                        </div>
                    </div>

                    <div class="compact-slot-info">
                        <span class="compact-slot-count free-slots ${r.free_color_cls}">残 ${r.free_slots} 枠</span>
                        <span class="compact-slot-max">定員 ${r.max_slots}</span>
                    </div>
                </div>

                <div class="compact-card-footer">
                    <a href="${r.jump_url}" 
                       class="compact-btn compact-btn-cal urgent-btn-cal" 
                       data-cal-preview-date="${r.exam_date}" 
                       title="当日の予約状況をプレビュー (ホバー)">
                        📅 カレンダー
                    </a>
                    <button type="button" 
                            onclick="jumpAndBookSlot('${r.department_code}', ${r.year}, ${r.month}, '${r.exam_date}', '${escapeHtml(r.doctor_name)}')" 
                            class="compact-btn compact-btn-book" 
                            title="新規予約登録へ">
                        ➕ 予約
                    </button>
                </div>
            </div>
        `;
    });
    grid.innerHTML = html;
}

// ── 直近速報データ取得 ──
async function loadUrgentSlots(forceReload = false) {
    const body = document.getElementById('urgentCardsBody');
    const daysSelect = document.getElementById('urgentDaysSelect');
    const days = daysSelect ? daysSelect.value : 30;

    const tabTitle = document.getElementById('urgentTabTitle');
    if (tabTitle) {
        tabTitle.textContent = `⚡ 直近空き枠速報（全科横断・${days}日大腸CF）`;
    }

    if (!urgentSlotCache || forceReload) {
        if (body) {
            body.innerHTML = `
                <div style="text-align:center; padding:50px 20px; color:#64748b;">
                    <div style="font-size:28px; margin-bottom:8px;">⏳</div>
                    <div style="font-size:14px; font-weight:bold;">大腸内視鏡の直近空き枠を計算しています...</div>
                </div>
            `;
        }

        try {
            const res = await fetch(`action_slot_assistant.php?action=get_urgent_slots&days=${days}&_t=${Date.now()}`);
            const data = await res.json();
            if (!data.success) {
                throw new Error(data.error || '空き枠取得エラー');
            }
            urgentSlotCache = data;
            if (data.calendar_previews) {
                Object.assign(calPreviewCache, data.calendar_previews);
            }
            updateUrgentBadges(data);
        } catch (err) {
            console.error('loadUrgentSlots error:', err);
            if (body) {
                body.innerHTML = `
                    <div style="text-align:center; padding:40px 20px; color:#dc2626;">
                        <div style="font-size:26px; margin-bottom:6px;">⚠️</div>
                        <div style="font-weight:bold;">空き枠データの取得に失敗しました</div>
                        <div style="font-size:12px; color:#64748b; margin-top:4px;">${escapeHtml(err.message)}</div>
                    </div>
                `;
            }
            return;
        }
    }

    renderUrgentSlotCards();
}

function updateUrgentBadges(data) {
    const summary = data.summary || {};
    const totalFree = summary.total_free || 0;
    const cancelFree = summary.total_cancel_free || 0;
    const byDoc = summary.by_doctor || {};

    const tabBadge = document.getElementById('tabUrgentBadge');
    if (tabBadge) {
        tabBadge.textContent = totalFree > 0 ? `${totalFree}枠` : '速報';
    }

    const bAll = document.getElementById('badgeUrgentAll');
    if (bAll) bAll.textContent = totalFree;

    const bCancel = document.getElementById('badgeUrgentCancel');
    if (bCancel) bCancel.textContent = cancelFree;

    const bSeigo = document.getElementById('badgeUrgentSeigo');
    if (bSeigo) bSeigo.textContent = byDoc['誠吾'] || 0;

    const bTetsuya = document.getElementById('badgeUrgentTetsuya');
    if (bTetsuya) bTetsuya.textContent = byDoc['哲也'] || 0;

    const bYoshitomo = document.getElementById('badgeUrgentYoshitomo');
    if (bYoshitomo) bYoshitomo.textContent = byDoc['義智'] || 0;

    const bKeitaro = document.getElementById('badgeUrgentKeitaro');
    if (bKeitaro) bKeitaro.textContent = byDoc['慶太郎'] || 0;
}

function filterUrgentByDoctor(doc) {
    currentUrgentDoctor = doc;
    document.querySelectorAll('#urgentDoctorTabs .urgent-doc-btn').forEach(btn => {
        if (btn.getAttribute('data-doctor') === doc) {
            btn.classList.add('active');
            if (doc === '誠吾') btn.classList.add('doc-seigo');
            else if (doc === '哲也') btn.classList.add('doc-tetsuya');
            else if (doc === '義智') btn.classList.add('doc-yoshitomo');
            else if (doc === '慶太郎') btn.classList.add('doc-keitaro');
        } else {
            btn.classList.remove('active', 'doc-seigo', 'doc-tetsuya', 'doc-yoshitomo', 'doc-keitaro');
        }
    });
    renderUrgentSlotCards();
}

function handleUrgentCancelToggle() {
    renderUrgentSlotCards();
}

function handleUrgentDaysChange() {
    loadUrgentSlots(true);
}

function renderUrgentSlotCards() {
    const body = document.getElementById('urgentCardsBody');
    if (!body || !urgentSlotCache) return;

    const onlyCancel = document.getElementById('urgentOnlyCancelToggle')?.checked || false;
    const allSlots = urgentSlotCache.slots || [];

    const filtered = allSlots.filter(s => {
        if (currentUrgentDoctor !== 'all' && s.doctor !== currentUrgentDoctor) {
            return false;
        }
        if (onlyCancel && !s.has_cancel) {
            return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        body.innerHTML = `
            <div style="text-align:center; padding:60px 20px; color:#64748b; background:#fff; border-radius:10px; border:1px dashed #cbd5e1; margin-top:10px;">
                <div style="font-size:36px; margin-bottom:12px;">📋</div>
                <div style="font-size:15px; font-weight:bold; color:#1e293b;">該当する大腸内視鏡の空き枠はありません</div>
                <div style="font-size:12.5px; margin-top:6px; color:#64748b;">
                    ${currentUrgentDoctor !== 'all' ? `担当医「${currentUrgentDoctor}」` : ''} 
                    ${onlyCancel ? 'キャンセル空き枠' : ''} の条件では現在すべて予約済です。
                </div>
                <button type="button" onclick="filterUrgentByDoctor('all'); document.getElementById('urgentOnlyCancelToggle').checked=false; renderUrgentSlotCards();" class="btn" style="margin-top:16px; background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:6px 16px; border-radius:6px; font-weight:700; cursor:pointer;">
                    すべての空き枠を表示する
                </button>
            </div>
        `;
        return;
    }

    let html = '<div class="urgent-cards-grid">';
    filtered.forEach(s => {
        let dowCls = 'dow-weekday';
        if (s.dow === '土') dowCls = 'dow-sat';
        else if (s.dow === '日') dowCls = 'dow-sun';

        let relCls = '';
        if (s.day_diff === 0) relCls = 'today';
        else if (s.day_diff === 1) relCls = 'tomorrow';
        else if (s.day_diff === 2) relCls = 'day2';

        const pct = Math.min(100, Math.round((s.used / s.cap) * 100));

        html += `
        <div class="urgent-card ${s.has_cancel ? 'is-cancel' : ''}">
            <div>
                <div class="urgent-card-header">
                    <div class="urgent-card-date">
                        <span class="urgent-card-date-main">📅 ${s.date}</span>
                        <span class="urgent-card-dow ${dowCls}">${s.dow}</span>
                        <span class="urgent-relative-badge ${relCls}">${s.relative_label}</span>
                    </div>
                    <span class="urgent-dept-tag">${s.dept_name}</span>
                </div>

                <div class="urgent-card-doctor">
                    <span class="urgent-doc-badge-pill" style="background:${s.color};">
                        👨‍⚕️ ${s.doctor} 先生
                    </span>
                    ${s.has_cancel ? '<span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:10.5px; font-weight:800; padding:1px 7px; border-radius:4px;">⚡ キャンセル空き</span>' : ''}
                </div>

                <!-- 空きメーター -->
                <div class="urgent-slot-meter-area">
                    <div class="urgent-meter-labels">
                        <span>予約進捗 (使用: ${s.used} / 定員: ${s.cap})</span>
                        <span class="urgent-free-highlight">残 ${s.free} 枠</span>
                    </div>
                    <div class="urgent-meter-track">
                        <div class="urgent-meter-fill" style="width:${pct}%; background:${s.color};"></div>
                    </div>
                </div>

                <!-- キャンセル詳細 -->
                ${s.has_cancel && s.cancel_details && s.cancel_details.length > 0 ? `
                    <div class="urgent-cancel-notice">
                        <div class="urgent-cancel-title">
                            <span>⚡</span>
                            <span>${escapeHtml(s.cancel_details[0].reason_label || '直前キャンセル')} (${escapeHtml(s.cancel_details[0].time_ago)})</span>
                        </div>
                        <div>${escapeHtml(s.cancel_details[0].patient_name)} 様 (${escapeHtml(s.cancel_details[0].exam_type)}) の取消枠</div>
                    </div>
                ` : ''}
            </div>

            <!-- アクション -->
            <div class="urgent-card-actions">
                <a href="${s.jump_url}" class="urgent-action-btn urgent-btn-cal" data-cal-preview-date="${s.date}" title="該当日のカレンダー・予約一覧へ移動 (ホバーで当日の全科枠状況プレビュー)">
                    📅 カレンダー表示
                </a>
                <button type="button" onclick="jumpAndBookSlot('${s.dept}', ${s.year}, ${s.month}, '${s.date}', '${s.doctor}')" class="urgent-action-btn urgent-btn-book" title="この枠で新規予約登録を開始">
                    ➕ この枠で予約
                </button>
            </div>
        </div>
        `;
    });
    html += '</div>';

    body.innerHTML = html;
}

// ── カレンダー状況ホバープレビュー ツールチップ制御 ──
async function showCalPreviewTooltip(btn, dateStr) {
    const tooltip = document.getElementById('calPreviewTooltip');
    if (!tooltip) return;

    let dayData = calPreviewCache[dateStr];

    // キャッシュにない場合、オンデマンドで取得
    if (!dayData) {
        try {
            const res = await fetch(`action_slot_assistant.php?action=get_calendar_preview&date=${dateStr}&_t=${Date.now()}`);
            const data = await res.json();
            if (data.success && data.calendar_previews && data.calendar_previews[dateStr]) {
                dayData = data.calendar_previews[dateStr];
                calPreviewCache[dateStr] = dayData;
            }
        } catch (e) {
            console.error('Failed to fetch calendar preview on demand:', e);
        }
    }

    if (!dayData) return;

    tooltip.innerHTML = generateCalPreviewHTML(dayData);
    tooltip.classList.add('show');
    tooltip.setAttribute('aria-hidden', 'false');

    // 位置計算
    const rect = btn.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    const padding = 10;

    let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
    if (left < padding) left = padding;
    if (left + tooltipRect.width > window.innerWidth - padding) {
        left = window.innerWidth - tooltipRect.width - padding;
    }

    let top = rect.top - tooltipRect.height - 10;
    if (top < padding) {
        top = rect.bottom + 10;
    }

    tooltip.style.left = `${Math.round(left)}px`;
    tooltip.style.top = `${Math.round(top)}px`;
}

function hideCalPreviewTooltip() {
    const tooltip = document.getElementById('calPreviewTooltip');
    if (tooltip && tooltip.classList.contains('show')) {
        tooltip.classList.remove('show');
        tooltip.setAttribute('aria-hidden', 'true');
    }
}

function generateCalPreviewHTML(dayData) {
    let dowCls = 'weekday';
    if (dayData.dow === '土') dowCls = 'sat';
    else if (dayData.dow === '日') dowCls = 'sun';

    let closedBadge = '';
    if (dayData.is_closed) {
        const hName = dayData.holiday_name ? ` (${escapeHtml(dayData.holiday_name)})` : '';
        closedBadge = `<span class="cpt-closed-badge">⛔ 休診${hName}</span>`;
    }

    let html = `
        <div class="cpt-header">
            <span class="cpt-title">
                <span>📅 ${dayData.date}</span>
                <span class="cpt-dow ${dowCls}">${dayData.dow}</span>
            </span>
            ${closedBadge}
        </div>
    `;

    // 胃腸科と肛門科の2段表示
    const depts = dayData.departments || {};
    const deptOrder = ['GASTRO', 'PROCT'];

    deptOrder.forEach(depCode => {
        const depInfo = depts[depCode];
        if (!depInfo) return;

        const isGastro = depCode === 'GASTRO';
        const deptIcon = isGastro ? '🔵' : '🟢';
        const deptClass = isGastro ? 'gastro' : 'proct';

        html += `
        <div class="cpt-dept-section">
            <div class="cpt-dept-title">
                <span class="cpt-dept-name ${deptClass}">
                    <span>${deptIcon}</span>
                    <span>${escapeHtml(depInfo.name)}</span>
                </span>
            </div>
        `;

        const docs = depInfo.doctors || [];
        if (docs.length === 0) {
            html += `<div style="font-size:10.5px; color:#94a3b8; padding:3px 2px;">枠設定なし / 休診</div>`;
        } else {
            docs.forEach(d => {
                const sGif = d.slots.GIF;
                const sEcho = d.slots.ECHO;
                const sTcf = d.slots.TCF;

                let slotBadges = '';
                if (sGif && (sGif.max > 0 || sGif.used > 0)) {
                    let cls = '';
                    if (sGif.used > sGif.max) cls = 'is-over';
                    else if (sGif.used === sGif.max && sGif.max > 0) cls = 'is-full';
                    slotBadges += `<span class="cpt-slot-pill ${cls}">🔵${sGif.used}/${sGif.max}</span>`;
                }
                if (sEcho && (sEcho.max > 0 || sEcho.used > 0)) {
                    let cls = '';
                    if (sEcho.used > sEcho.max) cls = 'is-over';
                    else if (sEcho.used === sEcho.max && sEcho.max > 0) cls = 'is-full';
                    slotBadges += `<span class="cpt-slot-pill ${cls}">🟠${sEcho.used}/${sEcho.max}</span>`;
                }
                if (sTcf && (sTcf.max > 0 || sTcf.used > 0)) {
                    let cls = '';
                    if (sTcf.used > sTcf.max) cls = 'is-over';
                    else if (sTcf.used === sTcf.max && sTcf.max > 0) cls = 'is-full';
                    slotBadges += `<span class="cpt-slot-pill ${cls}">🟢${sTcf.used}/${sTcf.max}</span>`;
                }

                html += `
                <div class="cpt-doc-row">
                    <span class="cpt-doc-icon ${d.cls}" title="${escapeHtml(d.name)}">${escapeHtml(d.short)}</span>
                    <div class="cpt-slot-badges">
                        ${slotBadges || '<span style="font-size:10px; color:#94a3b8;">枠なし</span>'}
                    </div>
                </div>
                `;
            });

            // 全体集計バー
            const ov = depInfo.overall || {};
            const ovParts = [];
            if (ov.GIF && (ov.GIF.max > 0 || ov.GIF.used > 0)) {
                ovParts.push(`🔵${ov.GIF.used}/${ov.GIF.max}`);
            }
            if (ov.ECHO && (ov.ECHO.max > 0 || ov.ECHO.used > 0)) {
                ovParts.push(`🟠${ov.ECHO.used}/${ov.ECHO.max}`);
            }
            if (ov.TCF && (ov.TCF.max > 0 || ov.TCF.used > 0)) {
                ovParts.push(`🟢${ov.TCF.used}/${ov.TCF.max}`);
            }

            if (ovParts.length > 0) {
                html += `
                <div class="cpt-overall-bar">
                    <span class="overall-label">全体</span>
                    <span>${ovParts.join(' ')}</span>
                </div>
                `;
            }
        }

        html += `</div>`;
    });

    // 日別メモ＆医師予定
    const memos = dayData.memos || {};
    const events = dayData.doctor_events || [];
    const memoKeys = Object.keys(memos);

    if (memoKeys.length > 0 || events.length > 0) {
        html += `<div class="cpt-extras">`;
        memoKeys.forEach(mDep => {
            const mText = memos[mDep];
            if (mText && mText.trim() !== '') {
                const depTag = mDep === 'GASTRO' ? '胃腸科' : '肛門科';
                html += `<div class="cpt-memo-item"><span>📝</span> <span>[${depTag}] ${escapeHtml(mText)}</span></div>`;
            }
        });
        events.forEach(ev => {
            html += `<div class="cpt-event-item"><span>🙅🏼</span> <span>${escapeHtml(ev.label || ev.raw_title || '医師不在')}</span></div>`;
        });
        html += `</div>`;
    }

    return html;
}

// イベントリスナーの登録（マウスオーバー・アウト・スクロール・ESC）
document.addEventListener('mouseover', function(e) {
    const btn = e.target.closest('.urgent-btn-cal');
    if (btn && btn.dataset.calPreviewDate) {
        showCalPreviewTooltip(btn, btn.dataset.calPreviewDate);
    }
});

document.addEventListener('mouseout', function(e) {
    const btn = e.target.closest('.urgent-btn-cal');
    if (btn) {
        hideCalPreviewTooltip();
    }
});

window.addEventListener('scroll', hideCalPreviewTooltip, { passive: true });
window.addEventListener('resize', hideCalPreviewTooltip, { passive: true });
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') hideCalPreviewTooltip();
});

function jumpAndBookSlot(dept, year, month, dateStr, doctorName) {
    window.location.href = `month_schedule.php?dept=${encodeURIComponent(dept)}&year=${year}&month=${month}&date=${encodeURIComponent(dateStr)}&open_new=1&target_doctor=${encodeURIComponent(doctorName)}`;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toggleMonth(btn) {
    btn.classList.toggle('active');
    
    var selected = [];
    document.querySelectorAll('.month-btn.active').forEach(function(b) {
        selected.push(b.dataset.month);
    });
    
    document.getElementById('monthsInput').value = selected.join(',');
    
    var dateFrom = document.getElementById('date_from');
    var dateTo = document.getElementById('date_to');
    
    if (selected.length > 0) {
        selected.sort();
        var first = selected[0];
        var last = selected[selected.length - 1];
        
        var partsFirst = first.split('-');
        var y1 = partsFirst[0], m1 = ('0' + partsFirst[1]).slice(-2);
        dateFrom.value = y1 + '-' + m1 + '-01';
        
        var partsLast = last.split('-');
        var y2 = parseInt(partsLast[0], 10), m2 = parseInt(partsLast[1], 10);
        var lastDay = new Date(y2, m2, 0).getDate();
        dateTo.value = y2 + '-' + ('0' + m2).slice(-2) + '-' + ('0' + lastDay).slice(-2);
        
        dateFrom.disabled = true;
        dateTo.disabled = true;
        if (window.dateFromControl) {
            window.dateFromControl.setDate(dateFrom.value);
            window.dateFromControl.setDisabled(true);
        }
        if (window.dateToControl) {
            window.dateToControl.setDate(dateTo.value);
            window.dateToControl.setDisabled(true);
        }
    } else {
        dateFrom.disabled = false;
        dateTo.disabled = false;
        if (window.dateFromControl) window.dateFromControl.setDisabled(false);
        if (window.dateToControl) window.dateToControl.setDisabled(false);
    }

    triggerConditionChange();
}

// 診療科選択の切り替え
document.querySelectorAll('.btn-dept').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var dept = this.dataset.dept;
        document.querySelectorAll('.btn-dept').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.getElementById('deptInput').value = dept;
        
        var container = document.getElementById('doctorContainer');
        var html = '<button type="button" class="btn active-all" data-group="doctor" data-value="all">全医師</button>';
        
        (allDoctors[dept] || []).forEach(function(doc) {
            var meta = doctorMetaMap[doc] || {};
            var badgeHtml = '';
            if (dept === 'all') {
                var isGastro = meta.dept === 'GASTRO';
                var bCls = isGastro ? 'gastro' : 'proct';
                var bText = isGastro ? '胃' : '肛';
                badgeHtml = ' <span class="doc-dept-badge ' + bCls + '">' + bText + '</span>';
            }
            html += '<button type="button" class="btn" data-group="doctor" data-value="' + doc + '">' + doc + badgeHtml + '</button>';
        });
        
        container.innerHTML = html;
        document.getElementById('doctorInput').value = 'all';
        initButtons('doctor', '#doctorContainer');

        triggerConditionChange();
    });
});

function initButtons(groupName, containerSelector) {
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    container.querySelectorAll('.btn[data-group="' + groupName + '"]').forEach(function(btn) {
        btn.onclick = function() {
            var val = this.dataset.value;
            var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
            var allBtn = container.querySelector('.btn[data-value="all"]');
            var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
            
            if (val === 'all') {
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
                triggerConditionChange();
                return;
            }
            
            allBtn.classList.remove('active-all');
            this.classList.toggle('active');
            
            var selected = [];
            itemBtns.forEach(function(b) {
                if (b.classList.contains('active')) selected.push(b.dataset.value);
            });
            
            if (selected.length === 0 || selected.length === itemBtns.length) {
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else {
                input.value = selected.join(',');
            }

            triggerConditionChange();
        };
    });
}

function toggleLabelGroup(groupName) {
    var containerSelector = '#' + (groupName === 'weekday' ? 'weekday' : groupName) + 'Container';
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
    var allBtn = container.querySelector('.btn[data-value="all"]');
    var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
    
    var isCurrentlyActive = allBtn.classList.contains('active-all') || 
        Array.from(itemBtns).some(function(b) { return b.classList.contains('active'); });
    
    if (isCurrentlyActive) {
        allBtn.classList.remove('active-all');
        itemBtns.forEach(function(b) { b.classList.remove('active'); });
        input.value = '';
    } else {
        allBtn.classList.add('active-all');
        itemBtns.forEach(function(b) { b.classList.remove('active'); });
        input.value = 'all';
    }

    triggerConditionChange();
}

// フォーム送信時はAjax検索を即時実行
document.getElementById('searchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (autoSearchTimer) clearTimeout(autoSearchTimer);
    runCustomSearchAjax();
});

function resetForm() {
    window.location.href = 'slot_search.php?mode=custom';
}

function goToSchedule(date, dept) {
    var d = dept || document.getElementById('deptInput').value || 'GASTRO';
    if (d === 'all') d = 'GASTRO';
    var parts = date.split('-');
    var url = 'month_schedule.php?dept=' + encodeURIComponent(d)
            + '&year=' + encodeURIComponent(parts[0])
            + '&month=' + encodeURIComponent(parseInt(parts[1], 10))
            + '&date=' + encodeURIComponent(date)
            + '&display_mode=remain';
    window.location.href = url;
}
</script>'''

content = re.sub(js_pattern, new_js, content, flags=re.DOTALL)

# 日付ピッカーのOnChangeイベントにも triggerConditionChange をバインド
date_picker_pat = r'(window\.dateFromControl = OnokaiDatePicker\.bindControl\(\'#date_from\', \{\s*onChange: function\(dates, dateStr\) \{\s*if \(window\.dateToControl && window\.dateToControl\.flatpickr\) \{\s*window\.dateToControl\.flatpickr\.set\(\'minDate\', dateStr\);\s*\}\s*)(\}\s*\}\);)'

new_date_picker = r'''\1    triggerConditionChange();
            \2'''
content = re.sub(date_picker_pat, new_date_picker, content)

date_to_pat = r'(window\.dateToControl = OnokaiDatePicker\.bindControl\(\'#date_to\', \{\s*minDate: document\.getElementById\(\'date_from\'\)\.value \|\| undefined\s*)(\}\);)'
new_date_to = r'''\1,
            onChange: function() {
                triggerConditionChange();
            }
        \2'''
content = re.sub(date_to_pat, new_date_to, content)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("JavaScript updated successfully!")
