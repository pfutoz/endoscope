/**
 * 設置パス: c:\Apache24\htdocs\endoscope\assets\js\month_schedule2.js
 * ファイル名: month_schedule2.js
 * 概要: 月間カレンダービュー (month_schedule.php) / 予約マトリクス (month_matrix_v2.php) / デイリーノート (schedule_note.php) 統合クライアントロジック (正規統合版)
 * 生成日時: 2026-09-29
 * 改修日時: 2026-10-01
 * 
 * 統合・機能一覧:
 *   - 【本物モーダルダイアログ機能】<dialog> + <iframe> 動的生成によるインページ予約モーダル（modal_edit_runner統合）
 *   - 【双方向 postMessage 連携】予約保存・削除時のダイアログ自動クローズ ＆ 親画面自動リロード
 *   - 【医療安全アラート連携 (2026-10-01)】modal_medical_alert.php からの postMessage (medical_alert_close/saved) 受信と連動
 *   - 【カレンダー折りたたみ & 上下入替】calendarSection（操作バー+パレット+カレンダー枠）一括反転 & localStorage 永続化
 *   - 【枠・空き枠フィルター】GIF/エコー/TCF トグルボタン ＆ 空き枠表示制御
 *   - 【日別メモ & 重複チェック】Native Dialog API による高速モーダル連携
 *   - 【Modern Toast】Popover API による非侵入型トースト通知
 */

(function(window, document) {
    'use strict';

    /* ============================================================
       1. インページ予約モーダルダイアログ機能 (Native Dialog + iframe 動的生成)
       ============================================================ */
    const DIALOG_ID = 'modalEditRunnerDialog';
    const IFRAME_ID = 'modalEditRunnerFrame';

    // モーダル用スタイルの自動注入（親画面のCSS変更不要）
    function ensureModalStyles() {
        if (document.getElementById('modalEditRunnerStyle')) return;
        const style = document.createElement('style');
        style.id = 'modalEditRunnerStyle';
        style.textContent = `
            #${DIALOG_ID} {
                position: fixed;
                inset: 0;
                margin: auto;
                width: 1040px;
                max-width: calc(100vw - 32px);
                height: 88vh;
                max-height: 880px;
                padding: 0;
                border: 1px solid rgba(148, 163, 184, 0.4);
                border-radius: 12px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.1);
                background: #ffffff;
                overflow: hidden;
                z-index: 10000;
                opacity: 0;
                transform: scale(0.96) translateY(10px);
                transition: opacity 0.2s ease-out, transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), overlay 0.2s allow-discrete, display 0.2s allow-discrete;
            }
            #${DIALOG_ID}[open] {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
            #${DIALOG_ID}::backdrop {
                background: rgba(15, 23, 42, 0.65);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                opacity: 0;
                transition: opacity 0.2s ease-out, overlay 0.2s allow-discrete, display 0.2s allow-discrete;
            }
            #${DIALOG_ID}[open]::backdrop {
                opacity: 1;
            }
            @starting-style {
                #${DIALOG_ID}[open] {
                    opacity: 0;
                    transform: scale(0.96) translateY(10px);
                }
                #${DIALOG_ID}[open]::backdrop {
                    opacity: 0;
                }
            }
            .modal-runner-wrapper {
                width: 100%;
                height: 100%;
                display: flex;
                flex-direction: column;
                position: relative;
            }
            .modal-runner-iframe {
                width: 100%;
                height: 100%;
                border: none;
                flex: 1;
                background: #f8fafc;
            }
            .modal-runner-loading {
                position: absolute;
                inset: 0;
                background: rgba(255, 255, 255, 0.88);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 12px;
                color: #475569;
                font-weight: 700;
                font-size: 13px;
                z-index: 5;
                transition: opacity 0.2s;
                pointer-events: none;
            }
            .modal-runner-spinner {
                width: 32px;
                height: 32px;
                border: 3px solid #e2e8f0;
                border-top-color: #3b82f6;
                border-radius: 50%;
                animation: runnerSpin 0.7s linear infinite;
            }
            @keyframes runnerSpin {
                to { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }

    // ダイアログ要素をDOMに自動生成
    function ensureModalElement() {
        ensureModalStyles();
        let dialog = document.getElementById(DIALOG_ID);
        if (dialog) return dialog;

        dialog = document.createElement('dialog');
        dialog.id = DIALOG_ID;
        dialog.innerHTML = `
            <div class="modal-runner-wrapper">
                <div id="modalRunnerLoading" class="modal-runner-loading">
                    <div class="modal-runner-spinner"></div>
                    <div>予約画面を読み込み中...</div>
                </div>
                <iframe id="${IFRAME_ID}" class="modal-runner-iframe" src="about:blank"></iframe>
            </div>
        `;

        document.body.appendChild(dialog);

        const iframe = dialog.querySelector(`#${IFRAME_ID}`);
        const loading = dialog.querySelector('#modalRunnerLoading');

        if (iframe && loading) {
            iframe.addEventListener('load', function() {
                if (iframe.src && iframe.src !== 'about:blank') {
                    loading.style.opacity = '0';
                    setTimeout(() => { loading.style.display = 'none'; }, 200);
                }
            });
        }

        // ダイアログ外（暗転部）クリックで閉じる
        dialog.addEventListener('click', function(e) {
            const rect = dialog.getBoundingClientRect();
            const isInDialog = (
                rect.top <= e.clientY && e.clientY <= rect.bottom &&
                rect.left <= e.clientX && e.clientX <= rect.right
            );
            if (!isInDialog) {
                closeModal(false);
            }
        });

        // ESCキーで閉じた際の後処理
        dialog.addEventListener('cancel', function() {
            closeModal(false);
        });

        return dialog;
    }

    // 予約登録・編集モーダルを開く
    function openModal(date, waku, scheduleId, dept) {
        // マトリクス等のスクロール位置退避
        const matrixContainer = document.getElementById('matrixContainer');
        if (matrixContainer) {
            try {
                sessionStorage.setItem('scroll_top', matrixContainer.scrollTop);
                sessionStorage.setItem('scroll_left', matrixContainer.scrollLeft);
            } catch(e) {}
        }

        const dialog = ensureModalElement();
        const iframe = document.getElementById(IFRAME_ID);
        const loading = document.getElementById('modalRunnerLoading');
        if (!dialog || !iframe) return;

        let url = 'modal_edit.php';
        url += '?date=' + encodeURIComponent(date || '');
        url += '&waku=' + encodeURIComponent(waku || '');
        url += '&dept=' + encodeURIComponent(dept || (window.currentDept || window.currentScheduleDept || 'PROCT'));
        if (scheduleId && parseInt(scheduleId, 10) > 0) {
            url += '&schedule_id=' + encodeURIComponent(scheduleId);
        }

        if (loading) {
            loading.style.display = 'flex';
            loading.style.opacity = '1';
        }

        iframe.src = url;

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    }

    // 予約登録・編集モーダルを閉じる
    function closeModal(needReload) {
        const dialog = document.getElementById(DIALOG_ID);
        const iframe = document.getElementById(IFRAME_ID);
        if (dialog) {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }
        if (iframe) {
            iframe.src = 'about:blank';
        }

        if (needReload) {
            window.location.reload();
        }
    }

    // postMessage 受信ハンドラ
    window.addEventListener('message', function(event) {
        if (!event.data) return;

        const data = event.data;
        if (data.type === 'modal_edit_close') {
            closeModal(false);
        } else if (data.type === 'modal_edit_saved') {
            closeModal(true);
        } else if (data.type === 'medical_alert_close') {
            if (typeof window.closeMedicalAlertRunner === 'function') {
                window.closeMedicalAlertRunner(false);
            }
        } else if (data.type === 'medical_alert_saved') {
            if (typeof window.closeMedicalAlertRunner === 'function') {
                window.closeMedicalAlertRunner(true);
            }
        } else if (data === 'reservation_updated' || data.type === 'reservation_updated') {
            closeModal(true);
        }
    });

    window.addEventListener('focus', function() {
        if (sessionStorage.getItem('need_reload') === '1') {
            sessionStorage.removeItem('need_reload');
            window.location.reload();
        }
    });

    // グローバル公開
    window.openModal = openModal;
    window.openModalRunner = openModal;
    window.closeModal = closeModal;
    window.closeModalRunner = closeModal;

    /* ============================================================
       2. カレンダー操作系 (折りたたみ & 上下入替)
       ============================================================ */
    function toggleCalendar() {
        const wrapper = document.getElementById('calendarWrapper');
        const btn = document.getElementById('btnToggleCalendar') || document.querySelector('.cal-toggle-bar .cal-toggle-btn');
        if (!wrapper) return;
        
        if (wrapper.classList.contains('collapsed')) {
            wrapper.classList.remove('collapsed');
            if (btn) btn.textContent = '🔼 カレンダーを折りたたむ';
            try { localStorage.setItem('calendar_collapsed', 'false'); } catch(e){}
        } else {
            wrapper.classList.add('collapsed');
            if (btn) btn.textContent = '🔽 カレンダーを表示';
            try { localStorage.setItem('calendar_collapsed', 'true'); } catch(e){}
        }
    }
    window.toggleCalendar = toggleCalendar;

    function toggleLayoutOrder() {
        const container = document.getElementById('mainContainer');
        const calSection = document.getElementById('calendarSection') || document.getElementById('calendarWrapper');
        const listWrapper = document.getElementById('listWrapper');

        if (!container || !calSection || !listWrapper) return;

        if (calSection.nextElementSibling === listWrapper) {
            container.insertBefore(listWrapper, calSection);
            try { localStorage.setItem('layout_reversed', 'true'); } catch(e){}
        } else {
            container.insertBefore(calSection, listWrapper);
            try { localStorage.setItem('layout_reversed', 'false'); } catch(e){}
        }
    }
    window.toggleLayoutOrder = toggleLayoutOrder;

    /* ============================================================
       3. フィルター制御系 (枠トグル・空き枠トグル)
       ============================================================ */
    function toggleSlotFilterBtn(group, btn) {
        if (btn) {
            btn.classList.toggle('active');
        }
        applySlotFilter();
    }
    window.toggleSlotFilterBtn = toggleSlotFilterBtn;

    function toggleEmptySlotsBtn(btn) {
        const checked = btn ? !btn.classList.contains('active') : false;
        toggleEmptySlots(checked);
    }
    window.toggleEmptySlotsBtn = toggleEmptySlotsBtn;

    function applySlotFilter() {
        const showGifEl = document.getElementById('show_gif');
        const showEchoEl = document.getElementById('show_echo');
        const showTcfEl = document.getElementById('show_tcf');
        const showEmpty = document.getElementById('btn_toggle_empty') || document.getElementById('show_empty');

        const isElActive = el => !el ? true : (el.tagName === 'INPUT' ? el.checked : el.classList.contains('active'));

        const showGif = isElActive(showGifEl);
        const showEcho = isElActive(showEchoEl);
        const showTcf = isElActive(showTcfEl);
        const showEmptyChecked = isElActive(showEmpty);
        
        document.querySelectorAll('#reservationTable tbody tr').forEach(function(tr) {
            const isReserved = tr.dataset.reserved === 'true';
            const group = tr.dataset.group || 'OTHER';
            
            if (tr.classList.contains('mismatch-row')) {
                tr.style.display = '';
                return;
            }

            if (!isReserved && !showEmptyChecked) {
                tr.style.display = 'none';
                return;
            }

            let show = false;
            if (group === 'GIF') {
                show = showGif;
            } else if (group === 'ECHO') {
                show = showEcho;
            } else if (group === 'TCF') {
                show = showTcf;
            } else {
                show = (showGif && showEcho && showTcf);
            }

            tr.style.display = show ? '' : 'none';
        });
    }
    window.applySlotFilter = applySlotFilter;

    function toggleEmptySlots(checked) {
        const url = new URL(window.location.href);
        url.searchParams.set('show_empty', checked ? '1' : '0');
        window.location.href = url.toString();
    }
    window.toggleEmptySlots = toggleEmptySlots;

    /* ============================================================
       4. 各種ポップアップ・印刷画面起動
       ============================================================ */
    function openSlotSummary(dept, date) {
        var url = 'daily_slot_summary.php';
        url += '?dept=' + encodeURIComponent(dept);
        url += '&date=' + encodeURIComponent(date);
        window.open(url, 'daily_slot_summary', 'width=1100,height=750,scrollbars=yes,resizable=yes');
    }
    window.openSlotSummary = openSlotSummary;

    function openBulkPrepRoomModal(dept, date) {
        var url = 'bulk_edit_prep_room.php?dept=' + encodeURIComponent(dept) + '&date=' + encodeURIComponent(date);
        window.open(url, 'bulk_prep_room', 'width=900,height=750,scrollbars=yes,resizable=yes');
    }
    window.openBulkPrepRoomModal = openBulkPrepRoomModal;

    function openPrintSchedule(dept, date) {
        const showGifEl = document.getElementById('show_gif');
        const showEchoEl = document.getElementById('show_echo');
        const showTcfEl = document.getElementById('show_tcf');
        const showEmpty = document.getElementById('show_empty');

        const selectedExams = [];
        if (showGifEl && showGifEl.checked) selectedExams.push('GIF');
        if (showEchoEl && showEchoEl.checked) selectedExams.push('ECHO');
        if (showTcfEl && showTcfEl.checked) selectedExams.push('TCF');

        let examParam = 'all';
        if (selectedExams.length > 0 && selectedExams.length < 3) {
            examParam = selectedExams.join(',');
        }

        const showEmptyVal = (showEmpty && showEmpty.checked) ? '1' : '0';
        
        const url = 'print_schedule_daily.php?dept=' + encodeURIComponent(dept) 
                  + '&date=' + encodeURIComponent(date) 
                  + '&exam=' + encodeURIComponent(examParam) 
                  + '&show_empty=' + showEmptyVal;

        window.open(url, '_blank');
    }
    window.openPrintSchedule = openPrintSchedule;

    /* ============================================================
       5. Modern Toast Notification 制御 (Popover API)
       ============================================================ */
    let toastTimer = null;
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.getElementById('toastMessage');
        if (!container || !toast) {
            alert(message);
            return;
        }
        toast.className = `modern-toast ${type}`;
        const icon = type === 'success' ? '✅' : (type === 'error' ? '❌' : 'ℹ️');
        toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
        
        if (typeof container.showPopover === 'function') {
            try { container.showPopover(); } catch(e) { container.style.display = 'block'; }
        } else {
            container.style.display = 'block';
        }
        
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            if (typeof container.hidePopover === 'function') {
                try { container.hidePopover(); } catch(e) { container.style.display = 'none'; }
            } else {
                container.style.display = 'none';
            }
        }, 3200);
    }
    window.showToast = showToast;

    /* ============================================================
       6. 日別カレンダーメモ編集モーダル (Native Dialog)
       ============================================================ */
    function openDailyMemoModal(dateStr, currentMemo) {
        const modal = document.getElementById('dailyMemoModal');
        if (!modal) return;
        const deptEl = document.getElementById('memoModalDept');
        const deptCode = deptEl ? deptEl.value : (window.currentScheduleDept || '');
        document.getElementById('memoModalDate').value = dateStr;
        document.getElementById('memoModalText').value = currentMemo || '';
        const deptName = deptCode === 'PROCT' ? '肛門科' : '胃腸科';
        document.getElementById('memoModalTitle').textContent = `📅 ${dateStr} 日別カレンダーメモ (${deptName})`;
        
        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', '');
        }
        
        setTimeout(() => {
            const txt = document.getElementById('memoModalText');
            if (txt) {
                txt.focus();
                txt.setSelectionRange(txt.value.length, txt.value.length);
            }
        }, 50);
    }
    window.openDailyMemoModal = openDailyMemoModal;

    function closeDailyMemoModal() {
        const modal = document.getElementById('dailyMemoModal');
        if (!modal) return;
        if (typeof modal.close === 'function') {
            modal.close();
        } else {
            modal.removeAttribute('open');
        }
    }
    window.closeDailyMemoModal = closeDailyMemoModal;

    async function saveDailyMemo() {
        const dateStr = document.getElementById('memoModalDate').value;
        const memo = document.getElementById('memoModalText').value.trim();
        const deptEl = document.getElementById('memoModalDept');
        const deptCode = deptEl ? deptEl.value : (window.currentScheduleDept || '');
        
        const formData = new FormData();
        formData.append('action', 'ajax_save_daily_memo');
        formData.append('dept', deptCode);
        formData.append('date', dateStr);
        formData.append('memo', memo);
        
        try {
            const res = await fetch('month_schedule.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                closeDailyMemoModal();
                showToast('カレンダーメモを保存しました', 'success');
                setTimeout(() => window.location.reload(), 600);
            } else {
                showToast('保存に失敗しました: ' + (data.error || '不明なエラー'), 'error');
            }
        } catch (err) {
            showToast('通信エラーが発生しました: ' + err, 'error');
        }
    }
    window.saveDailyMemo = saveDailyMemo;

    function deleteDailyMemo() {
        if (!confirm('この日のメモを削除しますか？')) return;
        document.getElementById('memoModalText').value = '';
        saveDailyMemo();
    }
    window.deleteDailyMemo = deleteDailyMemo;

    /* ============================================================
       7. 未来重複予約チェックモーダル (Native Dialog)
       ============================================================ */
    let dupDataCache = null;
    let currentDupTab = 'critical';

    function openDuplicateCheckModal() {
        const modal = document.getElementById('duplicateCheckModal');
        if (!modal) return;
        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', '');
        }
        loadDuplicateData();
    }
    window.openDuplicateCheckModal = openDuplicateCheckModal;

    function closeDuplicateCheckModal() {
        const modal = document.getElementById('duplicateCheckModal');
        if (!modal) return;
        if (typeof modal.close === 'function') {
            modal.close();
        } else {
            modal.removeAttribute('open');
        }
    }
    window.closeDuplicateCheckModal = closeDuplicateCheckModal;

    async function loadDuplicateData() {
        const body = document.getElementById('dupModalBody');
        body.innerHTML = '<div style="text-align:center; padding:40px; color:#64748b;"><div style="font-size:24px; margin-bottom:8px;">⏳</div><div>予約データを検索・集計しています...</div></div>';
        
        try {
            const res = await fetch('month_schedule.php?action=ajax_check_duplicates');
            const data = await res.json();
            if (!data.success) {
                body.innerHTML = '<div style="color:#dc2626; padding:20px; text-align:center;">エラー: ' + (data.error || 'データ取得に失敗しました') + '</div>';
                showToast('データ取得に失敗しました', 'error');
                return;
            }
            dupDataCache = data;
            document.getElementById('dupTotalBadge').textContent = data.summary.total_patients + '名検出';
            document.getElementById('badgeCritical').textContent = data.summary.critical_count;
            document.getElementById('badgeSameday').textContent = data.summary.sameday_count;
            document.getElementById('badgeStaged').textContent = data.summary.staged_count;
            document.getElementById('badgeAll').textContent = data.summary.total_patients;
            
            if (data.summary.critical_count > 0) {
                switchDupTab('critical');
            } else if (data.summary.sameday_count > 0) {
                switchDupTab('sameday');
            } else if (data.summary.staged_count > 0) {
                switchDupTab('staged');
            } else {
                switchDupTab('all');
            }
        } catch (err) {
            body.innerHTML = '<div style="color:#dc2626; padding:20px; text-align:center;">通信エラーが発生しました: ' + err + '</div>';
            showToast('通信エラーが発生しました: ' + err, 'error');
        }
    }
    window.loadDuplicateData = loadDuplicateData;

    function switchDupTab(tabName) {
        currentDupTab = tabName;
        document.querySelectorAll('.dup-tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        const activeBtn = document.querySelector('.dup-tab-btn.tab-' + tabName);
        if (activeBtn) activeBtn.classList.add('active');
        renderDupList();
    }
    window.switchDupTab = switchDupTab;

    function renderDupList() {
        if (!dupDataCache || !dupDataCache.data) return;
        const body = document.getElementById('dupModalBody');
        
        let items = [];
        if (currentDupTab === 'critical') {
            items = dupDataCache.data.critical.map(item => Object.assign({}, item, { category_type: 'critical' }));
        } else if (currentDupTab === 'sameday') {
            items = dupDataCache.data.sameday.map(item => Object.assign({}, item, { category_type: 'sameday' }));
        } else if (currentDupTab === 'staged') {
            items = dupDataCache.data.staged.map(item => Object.assign({}, item, { category_type: 'staged' }));
        } else {
            items = [
                ...dupDataCache.data.critical.map(item => Object.assign({}, item, { category_type: 'critical' })),
                ...dupDataCache.data.sameday.map(item => Object.assign({}, item, { category_type: 'sameday' })),
                ...dupDataCache.data.staged.map(item => Object.assign({}, item, { category_type: 'staged' }))
            ];
        }

        if (items.length === 0) {
            body.innerHTML = '<div style="text-align:center; padding:40px; color:#64748b; font-weight:bold;">該当する予約データはありません</div>';
            return;
        }

        let html = '';
        items.forEach(item => {
            const catClass = 'card-' + item.category_type;
            let badgeHtml = '';
            if (item.category_type === 'critical') {
                badgeHtml = '<span style="background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">🚨 異常の疑い</span>';
            } else if (item.category_type === 'sameday') {
                badgeHtml = '<span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">ℹ️ ① 同日併用</span>';
            } else {
                badgeHtml = '<span style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:11px; font-weight:800; padding:2px 8px; border-radius:9999px;">📅 ② 別日計画</span>';
            }

            const karteUrl = 'http://192.168.1.16/karteview/karteview.php?source=new&do_search=1&c_filter=all&show_all_sick=0&show_images=1&show_orders=1&show_dots=1&patient_no=' + encodeURIComponent(item.patient_id);

            html += '<div class="dup-card ' + catClass + '">';
            html += '  <div class="dup-card-header">';
            html += '    <div class="dup-card-patient">';
            html += '      <a href="' + karteUrl + '" target="_blank" rel="noopener noreferrer" class="karte-link" title="カルテビューアーを開く">';
            html += '        ' + escapeHtml(item.patient_id);
            html += '      </a>';
            html += '      <span class="dup-card-name">' + escapeHtml(item.patient_name) + '</span>';
            if (item.age !== null) {
                html += '      <span class="age-box ' + (item.age >= 70 ? 'age-senior' : 'age-under') + '">' + item.age + '歳</span>';
            }
            html += '    </div>';
            html += '    <div>' + badgeHtml + '</div>';
            html += '  </div>';

            if (item.critical_reasons && item.critical_reasons.length > 0) {
                html += '  <div class="dup-alert-banner">';
                html += '    <span>⚠️</span> <span>' + item.critical_reasons.map(r => escapeHtml(r)).join(' ／ ') + '</span>';
                html += '  </div>';
            }

            html += '  <table class="dup-table">';
            html += '    <thead>';
            html += '      <tr>';
            html += '        <th style="width:125px;">検査日</th>';
            html += '        <th style="width:65px; text-align:center;">科</th>';
            html += '        <th style="width:85px; text-align:center;">種別</th>';
            html += '        <th style="width:115px;">時間枠</th>';
            html += '        <th style="width:80px;">医師</th>';
            html += '        <th>備考</th>';
            html += '        <th style="width:55px; text-align:center;">操作</th>';
            html += '      </tr>';
            html += '    </thead>';
            html += '    <tbody>';

            item.reservations.forEach(r => {
                const dateParts = (r.exam_date || '').split('-');
                const rYear = dateParts[0] || '';
                const rMonth = dateParts[1] ? parseInt(dateParts[1], 10) : '';
                const dateJumpUrl = '?dept=' + encodeURIComponent(r.department_code) + (rYear && rMonth ? '&year=' + encodeURIComponent(rYear) + '&month=' + encodeURIComponent(rMonth) : '') + '&date=' + encodeURIComponent(r.exam_date);
                let examBadgeCls = 'other';
                if (r.exam_type === 'GIF' || (r.exam_type && r.exam_type.indexOf('胃') !== -1)) examBadgeCls = 'gif';
                else if (r.exam_type === 'ECHO' || (r.exam_type && r.exam_type.indexOf('エコー') !== -1)) examBadgeCls = 'echo';
                else if (r.exam_type === 'TCF' || r.exam_type === 'W') examBadgeCls = 'tcf';

                const deptLabel = r.department_code === 'PROCT' ? '<span style="color:#10b981; font-weight:bold;">肛門科</span>' : '<span style="color:#3b82f6; font-weight:bold;">胃腸科</span>';
                const sysTooltip = `スケジュールID:${r.schedule_id}（システムデータ）\n作成日時: ${r.created_at || '-'}\n更新日時: ${r.updated_at || '-'}`;

                html += '      <tr title="' + escapeHtml(sysTooltip) + '">';
                html += '        <td>';
                html += '          <div style="display:flex; align-items:center; gap:4px;">';
                html += '            <a href="' + dateJumpUrl + '" class="dup-date-link" title="' + escapeHtml(sysTooltip) + '\n※クリックでこの日付のカレンダーを表示">';
                html += '              📅 ' + escapeHtml(r.exam_date) + ' (' + escapeHtml(r.day_of_week) + ')';
                html += '            </a>';
                html += '            <span style="cursor:help; font-size:11px; opacity:0.75;" title="' + escapeHtml(sysTooltip) + '">ℹ️</span>';
                html += '          </div>';
                html += '        </td>';
                html += '        <td style="text-align:center;">' + deptLabel + '</td>';
                html += '        <td style="text-align:center;"><span class="exam-badge ' + examBadgeCls + '">' + escapeHtml(r.exam_type) + '</span></td>';
                html += '        <td><strong>' + escapeHtml(r.scheduled_time) + '</strong></td>';
                html += '        <td>' + escapeHtml(r.doctor_name || '-') + '</td>';
                html += '        <td>' + (r.remarks_html || '<span style="color:#cbd5e1;">-</span>') + '</td>';
                html += '        <td style="text-align:center;">';
                html += '          <a href="#" onclick="openModal(\'' + r.exam_date + '\', \'' + escapeHtml(r.scheduled_time) + '\', ' + r.schedule_id + ', \'' + r.department_code + '\')" class="icon-btn edit" title="この予約を編集・変更\n' + escapeHtml(sysTooltip) + '">✏️</a>';
                html += '        </td>';
                html += '      </tr>';
            });

            html += '    </tbody>';
            html += '  </table>';
            html += '</div>';
        });

        body.innerHTML = html;
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
    window.escapeHtml = escapeHtml;

    /* ============================================================
       8. DOMContentLoaded 初期化イベント
       ============================================================ */
    document.addEventListener('DOMContentLoaded', function() {
        // ボタンへの直接イベントバインド
        const calBtn = document.getElementById('btnToggleCalendar');
        if (calBtn) calBtn.onclick = toggleCalendar;

        const layoutBtn = document.getElementById('btnToggleLayout');
        if (layoutBtn) layoutBtn.onclick = toggleLayoutOrder;

        // 折りたたみ状態の復元
        try {
            if (localStorage.getItem('calendar_collapsed') === 'true') {
                const wrapper = document.getElementById('calendarWrapper');
                const btn = document.getElementById('btnToggleCalendar') || document.querySelector('.cal-toggle-bar .cal-toggle-btn');
                if (wrapper && btn) {
                    wrapper.classList.add('collapsed');
                    btn.textContent = '🔽 カレンダーを表示';
                }
            }
        } catch(e){}

        // 上下反転状態の復元
        try {
            if (localStorage.getItem('layout_reversed') === 'true') {
                const container = document.getElementById('mainContainer');
                const calSection = document.getElementById('calendarSection') || document.getElementById('calendarWrapper');
                const listWrapper = document.getElementById('listWrapper');
                if (container && calSection && listWrapper) {
                    container.insertBefore(listWrapper, calSection);
                }
            }
        } catch(e){}

        applySlotFilter();

        // Modern Dialog Light-Dismiss (Safari等向け)
        document.querySelectorAll('dialog.modern-dialog').forEach(dialog => {
            if (!('closedBy' in HTMLDialogElement.prototype)) {
                dialog.addEventListener('click', (event) => {
                    if (event.target !== dialog) return;
                    const rect = dialog.getBoundingClientRect();
                    const isContent = (
                        rect.top <= event.clientY && event.clientY <= rect.top + rect.height &&
                        rect.left <= event.clientX && event.clientX <= rect.left + rect.width
                    );
                    if (!isContent) {
                        dialog.close();
                    }
                });
            }
        });
    });

})(window, document);
