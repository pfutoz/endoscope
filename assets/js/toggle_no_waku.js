/**
 * 設置パス: c:\Apache24\htdocs\endoscope\assets\js\toggle_no_waku.js
 * ファイル名: toggle_no_waku.js
 * 概要: 枠なし（休止）設定／通常枠復帰 非同期トグル制御モジュール
 * 生成日時: 2026-10-23 21:25
 * 修正履歴:
 *   - 2026-10-23: 新規作成。month_schedule.php および schedule_note.php の 🚫⭕ 操作を
 *                 画面リロードなし（非同期Ajax）で行い、該当行の即時切り替え、➕ボタン活性制御、
 *                 上部枠数カウンターおよびカレンダーマス目のバッジを完全連動更新する。
 */

(function() {
    'use strict';

    /**
     * 文字列HTMLエスケープ
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * カレンダーマス目の残枠・空き枠表示バッジを更新
     */
    function updateCalendarSlotBadge(dateStr, stats) {
        if (!dateStr || !stats) return;
        const cell = document.querySelector(`td[data-date="${dateStr}"]`);
        if (!cell) return;

        // セル内の .day-available 要素を検索
        const availEl = cell.querySelector('.day-available');
        if (availEl) {
            if (availEl.classList.contains('avail-closed')) {
                // 休診日は変更しない
                return;
            }
            const emptyCount = parseInt(stats.empty_slots, 10);
            if (emptyCount > 0) {
                availEl.className = 'day-available avail-ok';
                availEl.textContent = `空き ${emptyCount}`;
            } else {
                availEl.className = 'day-available avail-full';
                availEl.textContent = '満枠';
            }
        }
    }

    /**
     * 枠なし / 通常枠復帰の非同期トグルハンドラー
     * 
     * @param {HTMLElement} btn クリックされた 🚫 / ⭕ アイコンボタン
     * @param {Event} e クリックイベント
     */
    async function toggleNoWakuAsync(btn, e) {
        if (e && typeof e.preventDefault === 'function') {
            e.preventDefault();
        }
        if (!btn || btn.dataset.busy === '1') return;

        btn.dataset.busy = '1';
        const origOpacity = btn.style.opacity || '1';
        btn.style.opacity = '0.35';
        btn.style.pointerEvents = 'none';

        const action = btn.dataset.action;
        const dept = btn.dataset.dept || 'PROCT';
        const date = btn.dataset.date || '';
        const waku = btn.dataset.waku || '';
        const baseId = btn.dataset.baseId || '0';
        const origName = btn.dataset.origName || '';
        const sid = btn.dataset.sid || '0';
        const modalWaku = btn.dataset.modalWaku || waku;
        const modalDate = btn.dataset.modalDate || date;
        const modalDept = btn.dataset.modalDept || dept;

        try {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('dept', dept);
            formData.append('date', date);
            formData.append('waku', waku);
            formData.append('base_id', baseId);
            formData.append('orig_name', origName);
            formData.append('sid', sid);

            const res = await fetch('api_toggle_no_waku.php', {
                method: 'POST',
                body: formData
            });

            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                throw new Error(errData.error || `HTTP ${res.status}`);
            }

            const data = await res.json();
            if (!data.success) {
                alert('操作エラー: ' + (data.error || '不明なエラーが発生しました'));
                return;
            }

            // ── DOM行更新 ──
            const tr = btn.closest('tr');
            if (tr) {
                const addCell = tr.querySelector('.cell-add-btn') || tr.cells[0];
                const wakuLabel = tr.querySelector('.waku-name-label');
                const patientCell = tr.querySelector('.cell-patient-name') || tr.cells[5];
                const remarksCell = tr.querySelector('.cell-remarks') || tr.cells[9];

                if (data.action === 'set_no_waku') {
                    // 枠なし状態へ遷移
                    btn.dataset.action = 'revert_waku';
                    btn.dataset.sid = String(data.schedule_id);
                    btn.textContent = '⭕';
                    btn.title = '枠なし設定を解除して通常枠に復帰';
                    btn.style.color = '#2563eb';
                    btn.style.fontSize = '13px';
                    btn.style.fontWeight = 'bold';

                    // 1列目: ➕ボタンを disabled（-）化
                    if (addCell) {
                        addCell.innerHTML = '<span class="icon-btn disabled">-</span>';
                    }

                    // 4列目: 枠名を赤字打消し線スタイルに
                    if (wakuLabel) {
                        wakuLabel.outerHTML = `<span class="waku-name-label" style="color:#dc2626; text-decoration:line-through; font-weight:bold;">${escapeHtml(waku)}</span>`;
                    }

                    // 6列目: 患者名セルに［枠なし設定済］
                    if (patientCell) {
                        patientCell.innerHTML = '<span style="color:#dc2626; font-size:11px; font-weight:bold;">［枠なし設定済］</span>';
                    }

                    // 10列目: 備考セルに 🚫枠なし マーカー
                    if (remarksCell) {
                        remarksCell.innerHTML = '<span style="color:#dc2626; font-size:11px; font-weight:bold;">＊＊＊🚫枠なし🚫＊＊＊</span>';
                    }

                    tr.dataset.reserved = 'false';
                    tr.classList.remove('empty-row');
                    tr.classList.add('no-waku-row');
                    tr.style.backgroundColor = '#fef2f2';

                } else {
                    // 通常枠復帰へ遷移
                    btn.dataset.action = 'set_no_waku';
                    btn.dataset.sid = '0';
                    btn.textContent = '🚫';
                    btn.title = 'この枠を枠なし（予約不可）に設定';
                    btn.style.color = '#dc2626';
                    btn.style.fontSize = '12px';
                    btn.style.fontWeight = 'bold';

                    // 1列目: ➕ボタンを復活
                    if (addCell) {
                        addCell.innerHTML = `<a href="#" onclick="openModal('${escapeHtml(modalDate)}', '${escapeHtml(modalWaku)}', 0, '${escapeHtml(modalDept)}')" class="icon-btn add" title="この枠に新規予約を追加">➕</a>`;
                    }

                    // 4列目: 枠名を通常太字スタイルに
                    if (wakuLabel) {
                        wakuLabel.outerHTML = `<strong class="waku-name-label" style="color:#1e293b;">${escapeHtml(waku)}</strong>`;
                    }

                    // 6列目: 患者名セルに［空き枠］
                    if (patientCell) {
                        patientCell.innerHTML = '<span style="color:#8b5cf6; font-size:11px; font-weight:600;">［空き枠］</span>';
                    }

                    // 10列目: 備考セルをクリア
                    if (remarksCell) {
                        remarksCell.innerHTML = '';
                    }

                    tr.dataset.reserved = 'false';
                    tr.classList.remove('no-waku-row');
                    tr.classList.add('empty-row');
                    tr.style.backgroundColor = '#faf5ff';
                }
            }

            // ── 上部サマリーカウンター更新 ──
            const usageCounter = document.getElementById('listWakuUsageCounter');
            if (usageCounter && data.stats) {
                usageCounter.textContent = `(${data.stats.used_slots}/${data.stats.total_slots}枠 使用中)`;
            }
            const noteEmptyCounter = document.getElementById('noteEmptySlotCounter');
            if (noteEmptyCounter && data.stats) {
                noteEmptyCounter.textContent = `${data.stats.empty_slots} 枠`;
            }

            // ── カレンダーマス目バッジ更新 ──
            if (data.stats && data.date) {
                updateCalendarSlotBadge(data.date, data.stats);
            }

            // ── トースト通知 ──
            if (typeof window.showToast === 'function') {
                window.showToast(data.message, 'success');
            } else {
                console.log(data.message);
            }

        } catch (err) {
            console.error('toggleNoWakuAsync error:', err);
            alert('通信エラーが発生しました: ' + err.message);
        } finally {
            btn.dataset.busy = '0';
            btn.style.opacity = origOpacity;
            btn.style.pointerEvents = 'auto';
        }
    }

    // グローバル公開
    window.toggleNoWakuAsync = toggleNoWakuAsync;
    window.updateCalendarSlotBadge = updateCalendarSlotBadge;
})();
