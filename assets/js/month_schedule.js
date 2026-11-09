/**
 * 設置パス: c:\Apache24\htdocs\endoscope\assets\js\month_schedule.js
 * ファイル名: month_schedule.js
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
    function openModal(date, waku, scheduleId, dept, doctor) {
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

        const sidInt = scheduleId ? parseInt(scheduleId, 10) : 0;
        let url = 'modal_edit.php';
        url += '?date=' + encodeURIComponent(date || '');
        url += '&waku=' + encodeURIComponent(waku || '');
        url += '&dept=' + encodeURIComponent(dept || (window.currentDept || window.currentScheduleDept || 'PROCT'));
        if (sidInt > 0) {
            url += '&schedule_id=' + encodeURIComponent(sidInt);
        } else {
            const targetDoc = doctor || ((window.currentFilterDoctor && window.currentFilterDoctor !== 'all' && window.currentFilterDoctor !== 'undecided') ? window.currentFilterDoctor : '');
            if (targetDoc) {
                url += '&doctor=' + encodeURIComponent(targetDoc);
            }
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
       3. フィルター制御系 (枠トグル・空き枠トグル・CF集中モード)
       ============================================================ */
    function toggleSlotFilterBtn(group, btn) {
        if (btn) {
            btn.classList.toggle('active');
        }
        try {
            const showGifEl = document.getElementById('show_gif');
            const showEchoEl = document.getElementById('show_echo');
            const showTcfEl = document.getElementById('show_tcf');
            const isCfOnly = showGifEl && showEchoEl && showTcfEl
                && !showGifEl.classList.contains('active')
                && !showEchoEl.classList.contains('active')
                && showTcfEl.classList.contains('active');
            if (window.currentFilterExam === 'all') {
                if (isCfOnly) {
                    sessionStorage.setItem('schedule_cf_focus', '1');
                } else {
                    sessionStorage.removeItem('schedule_cf_focus');
                }
            }
        } catch(e) {}
        applySlotFilter();
    }
    window.toggleSlotFilterBtn = toggleSlotFilterBtn;

    function toggleCfFocusMode(btn) {
        const showGifEl = document.getElementById('show_gif');
        const showEchoEl = document.getElementById('show_echo');
        const showTcfEl = document.getElementById('show_tcf');
        if (!showGifEl || !showEchoEl || !showTcfEl) return;

        const isCurrentlyCfOnly = !showGifEl.classList.contains('active')
            && !showEchoEl.classList.contains('active')
            && showTcfEl.classList.contains('active');

        if (isCurrentlyCfOnly) {
            showGifEl.classList.add('active');
            showEchoEl.classList.add('active');
            showTcfEl.classList.add('active');
            try { sessionStorage.removeItem('schedule_cf_focus'); } catch(e) {}
            if (typeof showToast === 'function') {
                showToast('📋 全枠（GIF・エコー・TCF）表示に戻しました', 'info');
            }
        } else {
            showGifEl.classList.remove('active');
            showEchoEl.classList.remove('active');
            showTcfEl.classList.add('active');
            try {
                if (window.currentFilterExam === 'all') {
                    sessionStorage.setItem('schedule_cf_focus', '1');
                }
            } catch(e) {}
            if (typeof showToast === 'function') {
                showToast('🎯 CF集中モード：TCF枠のみ表示しています', 'info');
            }
        }
        applySlotFilter();
    }
    window.toggleCfFocusMode = toggleCfFocusMode;

    function toggleEmptySlotsBtn(btn) {
        const checked = btn ? !btn.classList.contains('active') : false;
        toggleEmptySlots(checked);
    }
    window.toggleEmptySlotsBtn = toggleEmptySlotsBtn;

    function applySlotFilter() {
        const showGifEl = document.getElementById('show_gif');
        const showEchoEl = document.getElementById('show_echo');
        const showTcfEl = document.getElementById('show_tcf');
        const cfFocusBtn = document.getElementById('btn_cf_focus');
        const showEmpty = document.getElementById('btn_toggle_empty') || document.getElementById('show_empty');

        const isElActive = el => !el ? true : (el.tagName === 'INPUT' ? el.checked : el.classList.contains('active'));

        const showGif = isElActive(showGifEl);
        const showEcho = isElActive(showEchoEl);
        const showTcf = isElActive(showTcfEl);
        const showEmptyChecked = isElActive(showEmpty);

        if (cfFocusBtn) {
            if (!showGif && !showEcho && showTcf) {
                cfFocusBtn.classList.add('active');
            } else {
                cfFocusBtn.classList.remove('active');
            }
        }
        
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
       5. Modern Toast Notification 制御 (Popover API) & モーダル通知ウインドウ
       ============================================================ */
    let toastTimer = null;
    function ensureToastElements() {
        let container = document.getElementById('toastContainer');
        let toast = document.getElementById('toastMessage');
        if (!container || !toast) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.setAttribute('popover', 'manual');
            container.className = 'modern-toast-container';
            container.style.cssText = 'position:fixed; top:24px; right:24px; margin:0; padding:0; border:none; background:transparent; z-index:100050;';
            toast = document.createElement('div');
            toast.id = 'toastMessage';
            toast.className = 'modern-toast info';
            container.appendChild(toast);
            document.body.appendChild(container);
        }
        return { container, toast };
    }

    function showToast(message, type = 'info') {
        const { container, toast } = ensureToastElements();
        toast.className = `modern-toast ${type}`;
        const bgMap = {
            success: 'background:#ecfdf5; color:#065f46; border:1.5px solid #a7f3d0;',
            error:   'background:#fef2f2; color:#991b1b; border:1.5px solid #fecaca;',
            info:    'background:#eff6ff; color:#1e40af; border:1.5px solid #bfdbfe;'
        };
        toast.style.cssText = 'display:inline-flex; align-items:center; gap:10px; padding:12px 20px; border-radius:10px; font-size:13.5px; font-weight:700; box-shadow:0 10px 25px -5px rgba(0,0,0,0.18), 0 8px 10px -6px rgba(0,0,0,0.1); ' + (bgMap[type] || bgMap.info);
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

    let slotOrderModalTimer = null;
    function closeSlotOrderModal() {
        clearTimeout(slotOrderModalTimer);
        const modal = document.getElementById('slotOrderNotifyModal');
        if (!modal) return;
        if (typeof modal.close === 'function' && modal.open) {
            try { modal.close(); } catch (e) { modal.removeAttribute('open'); }
        } else {
            modal.removeAttribute('open');
            modal.style.display = 'none';
        }
    }
    window.closeSlotOrderModal = closeSlotOrderModal;

    function showSlotOrderModal(message, orderedNames = [], type = 'success') {
        let modal = document.getElementById('slotOrderNotifyModal');
        if (!modal) {
            modal = document.createElement('dialog');
            modal.id = 'slotOrderNotifyModal';
            modal.setAttribute('closedby', 'any');
            modal.style.cssText = 'border:none; border-radius:12px; padding:0; box-shadow:0 20px 45px rgba(15,23,42,0.35), 0 0 0 1px rgba(15,23,42,0.1); max-width:380px; width:90vw; background:#ffffff; color:#0f172a; overflow:hidden; z-index:100100; margin:auto; font-family:-apple-system,BlinkMacSystemFont,"BIZ UDPGothic","Meiryo",sans-serif;';
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeSlotOrderModal();
            });
            document.body.appendChild(modal);

            if (!document.getElementById('slotOrderNotifyModalStyle')) {
                const st = document.createElement('style');
                st.id = 'slotOrderNotifyModalStyle';
                st.textContent = `
                    #slotOrderNotifyModal::backdrop {
                        background: rgba(15, 23, 42, 0.32);
                        backdrop-filter: blur(2px);
                    }
                    #slotOrderNotifyModal[open] {
                        animation: slotModalPopIn 0.16s cubic-bezier(0.16, 1, 0.3, 1);
                    }
                    @keyframes slotModalPopIn {
                        from { opacity: 0; transform: scale(0.94) translateY(-6px); }
                        to   { opacity: 1; transform: scale(1) translateY(0); }
                    }
                    @keyframes slotModalBarShrink {
                        from { width: 100%; }
                        to   { width: 0%; }
                    }
                `;
                document.head.appendChild(st);
            }
        }

        const isError = (type === 'error');
        const headerBg = isError
            ? 'linear-gradient(135deg, #b91c1c 0%, #dc2626 100%)'
            : 'linear-gradient(135deg, #1e40af 0%, #2563eb 100%)';
        const headerTitle = isError
            ? '⚠️ 順番の保存エラー'
            : (String(message).includes('採番') ? '🔢 枠内番号の採番完了' : '🔢 枠内順番の入れ替え');
        const btnBg = isError ? '#dc2626' : '#2563eb';

        let listHtml = '';
        if (Array.isArray(orderedNames) && orderedNames.length > 0) {
            listHtml = '<div style="margin-top:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 10px; display:flex; flex-direction:column; gap:5px;">';
            orderedNames.forEach((name, i) => {
                const seq = i + 1;
                listHtml += `
                    <div style="display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:#1e293b;">
                        <span style="display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 3px; border:1.5px solid #2563eb; border-radius:3px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:900; line-height:1;">${seq}</span>
                        <span>${escapeHtml(name)}</span>
                    </div>
                `;
            });
            listHtml += '</div>';
        }

        const autoCloseMs = isError ? 0 : 1500;
        const progressHtml = autoCloseMs > 0
            ? `<div style="height:3px; width:100%; background:#e2e8f0; overflow:hidden;"><div style="height:100%; background:#3b82f6; animation:slotModalBarShrink ${autoCloseMs}ms linear forwards;"></div></div>`
            : '';

        modal.innerHTML = `
            <div style="background:${headerBg}; color:#ffffff; padding:9px 14px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:800; font-size:13.5px; display:flex; align-items:center; gap:6px;">${headerTitle}</span>
                <button type="button" onclick="closeSlotOrderModal()" style="background:none; border:none; color:#ffffff; font-size:18px; cursor:pointer; line-height:1; padding:0 4px; opacity:0.9;">&times;</button>
            </div>
            ${progressHtml}
            <div style="padding:14px 16px 12px 16px;">
                <div style="font-size:13.5px; font-weight:700; color:${isError ? '#b91c1c' : '#0f172a'}; display:flex; align-items:center; gap:6px;">
                    <span>${escapeHtml(message)}</span>
                </div>
                ${listHtml}
                <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                    <button type="button" id="slotOrderModalOkBtn" onclick="closeSlotOrderModal()" style="background:${btnBg}; color:#ffffff; border:none; border-radius:6px; padding:6px 18px; font-size:12.5px; font-weight:800; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.2);">OK</button>
                </div>
            </div>
        `;

        clearTimeout(slotOrderModalTimer);
        if (typeof modal.showModal === 'function') {
            if (!modal.open) {
                try { modal.showModal(); } catch (e) { modal.setAttribute('open', ''); modal.style.display = 'block'; }
            }
        } else {
            modal.setAttribute('open', '');
            modal.style.display = 'block';
        }

        const okBtn = document.getElementById('slotOrderModalOkBtn');
        if (okBtn) {
            try { okBtn.focus(); } catch (e) {}
        }

        if (autoCloseMs > 0) {
            slotOrderModalTimer = setTimeout(() => {
                closeSlotOrderModal();
            }, autoCloseMs);
        }
    }
    window.showSlotOrderModal = showSlotOrderModal;

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

        // 上段が「全種別」の場合、下段のCF集中モード(sessionStorage)を復元
        try {
            if (window.currentFilterExam === 'all' && sessionStorage.getItem('schedule_cf_focus') === '1') {
                const showGifEl = document.getElementById('show_gif');
                const showEchoEl = document.getElementById('show_echo');
                const showTcfEl = document.getElementById('show_tcf');
                if (showGifEl && showEchoEl && showTcfEl) {
                    showGifEl.classList.remove('active');
                    showEchoEl.classList.remove('active');
                    showTcfEl.classList.add('active');
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

    /* ============================================================
       9. 同一時間枠内の複数予約 順番入れ替え (クリック & 行ドラッグ&ドロップ)
       ============================================================ */
    let draggedSlotRow = null;

    function getPeerSlotRows(tr) {
        if (!tr) return [];
        const groupKey = tr.getAttribute('data-slot-group') || '';
        const tbody = tr.parentNode;
        if (!groupKey || !tbody) return [];
        return Array.from(tbody.querySelectorAll('tr[data-slot-group]')).filter(row => {
            if ((row.getAttribute('data-slot-group') || '') !== groupKey) return false;
            const sid = parseInt(row.getAttribute('data-schedule-id') || '0', 10);
            return sid > 0;
        });
    }

    function applySlotRowOrderAndSave(orderedRows, subAction = 'reorder') {
        if (!orderedRows || orderedRows.length < 2) return;
        const parent = orderedRows[0].parentNode;
        if (!parent) return;

        // テーブル内の最も先頭にある対象行の位置にマーカーを置き、新しい順序で再配置
        const allChildren = Array.from(parent.children);
        let firstDomRow = orderedRows[0];
        let minDomIdx = allChildren.indexOf(firstDomRow);
        orderedRows.forEach(r => {
            const idx = allChildren.indexOf(r);
            if (idx !== -1 && (minDomIdx === -1 || idx < minDomIdx)) {
                minDomIdx = idx;
                firstDomRow = r;
            }
        });

        const marker = document.createComment('slot-order-marker');
        parent.insertBefore(marker, firstDomRow);
        orderedRows.forEach(r => {
            parent.insertBefore(r, marker);
        });
        parent.removeChild(marker);

        const orderedIds = orderedRows
            .map(r => parseInt(r.getAttribute('data-schedule-id') || '0', 10))
            .filter(id => id > 0);
        const total = orderedRows.length;

        orderedRows.forEach((r, idx) => {
            // 未採番ミニボタンがあれば消去
            r.querySelectorAll('.slot-assign-seq-btn').forEach(btn => btn.remove());
            const seq = idx + 1;
            const badge = r.querySelector('.slot-seq-badge');
            if (badge) {
                badge.style.display = 'inline-flex';
                badge.setAttribute('data-slot-seq', String(seq));
                badge.setAttribute('data-slot-total', String(total));
                badge.setAttribute('data-slot-peers', orderedIds.join(','));
                const numEl = badge.querySelector('.slot-seq-num');
                if (numEl) numEl.textContent = String(seq);
            }
        });

        const orderedNames = orderedRows.map(r => {
            const st = r.querySelector('.cell-patient-name strong, .col-pname strong');
            return st ? st.textContent.trim() : '';
        }).filter(Boolean);

        const fd = new FormData();
        fd.append('action_type', 'reorder_slot');
        fd.append('sub_action', subAction);
        fd.append('ordered_ids', orderedIds.join(','));
        if (orderedIds.length > 0) {
            fd.append('schedule_id', String(orderedIds[0]));
        }

        const modalMsg = (subAction === 'assign_initial')
            ? '🔢 枠内の予約に番号を採番しました'
            : '🔢 枠内の順番を入れ替えました';

        fetch('action_schedule.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(res => res.json())
        .then(data => {
            if (!data || !data.success) {
                showSlotOrderModal('順番の保存に失敗しました: ' + ((data && data.message) || 'エラー'), [], 'error');
            } else {
                showSlotOrderModal(modalMsg, orderedNames, 'success');
            }
        })
        .catch(err => {
            console.error('reorder_slot error:', err);
            showSlotOrderModal('順番保存中に通信エラーが発生しました', [], 'error');
        });
    }

    function assignSlotNumbers(btnEl, event) {
        if (event) {
            event.stopPropagation();
            event.preventDefault();
        }
        if (!btnEl) return;

        // 1. マトリックス画面 (.waku-cell 内)
        const cell = btnEl.closest('.waku-cell');
        if (cell && typeof window.getActiveCellBoxes === 'function' && typeof window.applyCellBoxOrderAndSave === 'function') {
            const boxes = window.getActiveCellBoxes(cell);
            if (boxes.length >= 2) {
                window.applyCellBoxOrderAndSave(cell, boxes, 'assign_initial');
            }
            return;
        }

        // 2. カレンダー画面・ノート画面 (tr[data-slot-group] 内)
        const tr = btnEl.closest('tr[data-slot-group]');
        if (tr) {
            const rows = getPeerSlotRows(tr);
            if (rows.length >= 2) {
                applySlotRowOrderAndSave(rows, 'assign_initial');
            }
        }
    }
    window.assignSlotNumbers = assignSlotNumbers;

    function cycleSlotOrder(badgeEl, event) {
        if (event) {
            event.stopPropagation();
            event.preventDefault();
        }
        if (!badgeEl) return;
        // マトリックス画面（.patient-box 内）の場合はマトリックス用ハンドラへ委譲
        if (badgeEl.closest('.patient-box') && typeof window.cycleMatrixSlotOrder === 'function') {
            window.cycleMatrixSlotOrder(badgeEl, event);
            return;
        }
        const tr = badgeEl.closest('tr[data-slot-group]');
        if (!tr) return;
        const rows = getPeerSlotRows(tr);
        if (rows.length < 2) return;

        let orderedRows;
        if (rows.length === 2) {
            orderedRows = [rows[1], rows[0]];
        } else {
            const idx = rows.indexOf(tr);
            if (idx === -1) return;
            const nextIdx = (idx + 1) % rows.length;
            orderedRows = rows.slice();
            const [moved] = orderedRows.splice(idx, 1);
            orderedRows.splice(nextIdx, 0, moved);
        }
        applySlotRowOrderAndSave(orderedRows);
    }
    window.cycleSlotOrder = cycleSlotOrder;

    function onSlotRowDragStart(tr, event) {
        if (!tr || !event) return;
        const target = event.target;
        if (target && target.closest && target.closest('a, button, input, select, textarea')) {
            event.preventDefault();
            return;
        }
        draggedSlotRow = tr;
        tr.classList.add('slot-row-dragging');
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            try {
                event.dataTransfer.setData('text/plain', tr.getAttribute('data-schedule-id') || '');
            } catch (e) {}
        }
    }
    window.onSlotRowDragStart = onSlotRowDragStart;

    function onSlotRowDragOver(tr, event) {
        if (!draggedSlotRow || !tr || draggedSlotRow === tr) return;
        const srcGroup = draggedSlotRow.getAttribute('data-slot-group') || '';
        const dstGroup = tr.getAttribute('data-slot-group') || '';
        if (!srcGroup || srcGroup !== dstGroup) return;
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
        tr.classList.add('slot-row-dragover');
    }
    window.onSlotRowDragOver = onSlotRowDragOver;

    function onSlotRowDragLeave(tr, event) {
        if (!tr) return;
        tr.classList.remove('slot-row-dragover');
    }
    window.onSlotRowDragLeave = onSlotRowDragLeave;

    function onSlotRowDrop(targetTr, event) {
        if (targetTr) targetTr.classList.remove('slot-row-dragover');
        if (!draggedSlotRow || !targetTr || draggedSlotRow === targetTr) return;
        const srcGroup = draggedSlotRow.getAttribute('data-slot-group') || '';
        const dstGroup = targetTr.getAttribute('data-slot-group') || '';
        if (!srcGroup || srcGroup !== dstGroup) return;

        event.preventDefault();
        event.stopPropagation();

        const rows = getPeerSlotRows(targetTr);
        const fromIdx = rows.indexOf(draggedSlotRow);
        const toIdx = rows.indexOf(targetTr);
        if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) return;

        const orderedRows = rows.slice();
        orderedRows.splice(fromIdx, 1);
        orderedRows.splice(toIdx, 0, draggedSlotRow);
        applySlotRowOrderAndSave(orderedRows);
    }
    window.onSlotRowDrop = onSlotRowDrop;

    function onSlotRowDragEnd(tr, event) {
        if (tr) tr.classList.remove('slot-row-dragging');
        document.querySelectorAll('tr.slot-row-dragover').forEach(el => el.classList.remove('slot-row-dragover'));
        draggedSlotRow = null;
    }
    window.onSlotRowDragEnd = onSlotRowDragEnd;

})(window, document);
