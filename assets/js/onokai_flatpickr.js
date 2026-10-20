/**
 * assets/js/onokai_flatpickr.js
 * 医療法人小野会 特大Flatpickr共通初期化スクリプト
 * 生成日時: 2026-10-20 23:35
 * 
 * 機能:
 *   - 完全オフラインでの小野会公休日（日曜・祝日・年末年始公休12/30〜1/3）自動判定（再帰なし安全版）
 *   - 木曜日の青色判定
 *   - 横幅440px特大カレンダーの安定バインド
 *   - カレンダー上部に「令和〇年」和暦元号バッジを表示
 *   - カレンダー下部に「選択日の和暦・曜日・公休情報」リアルタイム表示バー
 *   - 画面端でのカレンダーはみ出し自動補正
 *   - 画面デザイン・ツライチ寸法を1pxも狂わせない安全初期化
 */

(function(window) {
    'use strict';

    /**
     * 春分の日の計算 (1900-2099)
     */
    function getVernalEquinoxDay(year) {
        return Math.floor(20.8431 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4));
    }

    /**
     * 秋分の日の計算 (1900-2099)
     */
    function getAutumnalEquinoxDay(year) {
        return Math.floor(23.2488 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4));
    }

    /**
     * 基本祝日（固定祝日・天文祝日・ハッピーマンデー）の判定（再帰なし）
     */
    function getBaseHoliday(year, month, day) {
        const dt = new Date(year, month - 1, day);
        const dow = dt.getDay(); // 0:日, 1:月, ... 6:土

        // 1. 固定祝日
        if (month === 1 && day === 1) return '元日';
        if (month === 2 && day === 11) return '建国記念の日';
        if (month === 2 && day === 23) return '天皇誕生日';
        if (month === 4 && day === 29) return '昭和の日';
        if (month === 5 && day === 3) return '憲法記念日';
        if (month === 5 && day === 4) return 'みどりの日';
        if (month === 5 && day === 5) return 'こどもの日';
        if (month === 8 && day === 11) return '山の日';
        if (month === 11 && day === 3) return '文化の日';
        if (month === 11 && day === 23) return '勤労感謝の日';

        // 2. 天文計算祝日
        if (month === 3 && day === getVernalEquinoxDay(year)) return '春分の日';
        if (month === 9 && day === getAutumnalEquinoxDay(year)) return '秋分の日';

        // 3. ハッピーマンデー (第N月曜日)
        const nthMonday = Math.floor((day - 1) / 7) + 1;
        if (dow === 1) {
            if (month === 1 && nthMonday === 2) return '成人の日';
            if (month === 7 && nthMonday === 3) return '海の日';
            if (month === 9 && nthMonday === 3) return '敬老の日';
            if (month === 10 && nthMonday === 2) return 'スポーツの日';
        }

        return null;
    }

    /**
     * 日本の祝日および小野会特別公休判定（振替休日・国民の休日含む）
     */
    function checkOnokaiHoliday(year, month, day) {
        // 1. 小野会年末年始公休 (12/30 〜 1/3)
        if ((month === 12 && day >= 30) || (month === 1 && day <= 3)) {
            return '小野会公休';
        }

        // 2. 基本祝日判定
        const base = getBaseHoliday(year, month, day);
        if (base) return base;

        const dt = new Date(year, month - 1, day);
        const dow = dt.getDay();

        // 3. 振替休日 (日曜が基本祝日の場合、その直後の平日が振替休日)
        if (dow === 1) {
            // 月曜日: 前日(日曜)が祝日か
            if (getBaseHoliday(year, month, day - 1)) return '振替休日';
        } else if (month === 5 && day === 6 && (dow === 2 || dow === 3)) {
            // 5月6日の特例 (5/3, 5/4が日曜の場合)
            if (new Date(year, 4, 3).getDay() === 0 || new Date(year, 4, 4).getDay() === 0) {
                return '振替休日';
            }
        }

        // 4. 国民の休日 (祝日と祝日に挟まれた平日：前日と翌日が基本祝日で日曜でない)
        if (dow >= 2 && dow <= 5) {
            const prevD = new Date(year, month - 1, day - 1);
            const nextD = new Date(year, month - 1, day + 1);
            const prevH = getBaseHoliday(prevD.getFullYear(), prevD.getMonth() + 1, prevD.getDate());
            const nextH = getBaseHoliday(nextD.getFullYear(), nextD.getMonth() + 1, nextD.getDate());
            if (prevH && nextH) {
                return '国民の休日';
            }
        }

        return null;
    }

    /**
     * 小野会公休（日曜または祝日）判定
     */
    function isOnokaiHoliday(dateObj) {
        if (!dateObj) return false;
        const dow = dateObj.getDay();
        if (dow === 0) return true; // 日曜
        const reason = checkOnokaiHoliday(dateObj.getFullYear(), dateObj.getMonth() + 1, dateObj.getDate());
        return !!reason;
    }

    /**
     * 小野会木曜日判定 (公休でない木曜)
     */
    function isOnokaiThursday(dateObj) {
        if (!dateObj) return false;
        if (dateObj.getDay() !== 4) return false;
        return !isOnokaiHoliday(dateObj);
    }

    /**
     * 和暦文字列フォーマット (例: 令和8年10月20日 (火))
     */
    function formatToWareki(dateStr, withDow) {
        if (!dateStr || dateStr === '---') return '';
        const parts = dateStr.split('-');
        if (parts.length < 3) return dateStr;
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        const d = parseInt(parts[2], 10);

        let g = '令和';
        let gy = y - 2018;
        if (y < 2019 && y >= 1989) {
            g = '平成';
            gy = y - 1988;
        } else if (y < 1989 && y >= 1926) {
            g = '昭和';
            gy = y - 1925;
        } else if (y < 1926 && y >= 1912) {
            g = '大正';
            gy = y - 1911;
        } else if (y < 1912 && y >= 1868) {
            g = '明治';
            gy = y - 1867;
        }
        const gStr = (gy === 1) ? '元' : gy;
        let res = `${g}${gStr}年${m}月${d}日`;

        if (withDow) {
            const dt = new Date(y, m - 1, d);
            const wNames = ['日', '月', '火', '水', '木', '金', '土'];
            res += ` (${wNames[dt.getDay()]})`;
        }
        return res;
    }

    /**
     * 年から元号表記を取得 (例: 令和8年)
     */
    function getYearGengou(year) {
        let g = '令和';
        let gy = year - 2018;
        if (year < 2019 && year >= 1989) {
            g = '平成';
            gy = year - 1988;
        } else if (year < 1989 && year >= 1926) {
            g = '昭和';
            gy = year - 1925;
        }
        const gStr = (gy === 1) ? '元' : gy;
        return `${g}${gStr}年`;
    }

    /**
     * カレンダー上部ヘッダーに和暦元号バッジを表示
     */
    function updateCalendarHeaderWareki(fpInstance) {
        if (!fpInstance || !fpInstance.calendarContainer) return;
        const monthNav = fpInstance.calendarContainer.querySelector('.flatpickr-current-month');
        if (!monthNav) return;

        let badge = monthNav.querySelector('.onokai-header-wareki');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'onokai-header-wareki';
            monthNav.appendChild(badge);
        }
        badge.textContent = ` (${getYearGengou(fpInstance.currentYear)})`;
    }

    /**
     * カレンダーフッターバーの生成・更新
     */
    function updateCalendarFooter(fpInstance, dateObj) {
        if (!fpInstance || !fpInstance.calendarContainer) return;
        let footer = fpInstance.calendarContainer.querySelector('.onokai-fp-footer');
        if (!footer) {
            footer = document.createElement('div');
            footer.className = 'onokai-fp-footer';
            fpInstance.calendarContainer.appendChild(footer);
        }

        if (!dateObj) {
            footer.innerHTML = '<span style="color:#64748b; font-size:12px;">日付を選択してください</span>';
            return;
        }

        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        const dateStr = `${y}-${m}-${d}`;
        const dowNames = ['日', '月', '火', '水', '木', '金', '土'];
        const dow = dateObj.getDay();
        const dowStr = dowNames[dow];

        const wareki = formatToWareki(dateStr, false);
        const hReason = checkOnokaiHoliday(y, parseInt(m, 10), parseInt(d, 10));

        let badgeHtml = '';
        if (dow === 0 || hReason) {
            badgeHtml = `<span style="background:#fee2e2; color:#dc2626; padding:2px 6px; border-radius:4px; font-size:11px; margin-left:6px; font-weight:bold;">🔴 ${hReason || '日曜休診'}</span>`;
        } else if (dow === 4) {
            badgeHtml = `<span style="background:#dbeafe; color:#1d4ed8; padding:2px 6px; border-radius:4px; font-size:11px; margin-left:6px; font-weight:bold;">🔵 木曜</span>`;
        }

        footer.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; width:100%; font-size:13px; font-weight:bold;">
                <span style="color:#0f172a;">📅 ${y}/${m}/${d} (${dowStr})</span>
                <div>
                    <span style="color:#2563eb; background:#eff6ff; padding:2px 8px; border-radius:4px; border:1px solid #bfdbfe;">${wareki}</span>
                    ${badgeHtml}
                </div>
            </div>
        `;
    }

    /**
     * 既存の input[type="date"] や input[type="text"] に
     * 小野会特大Flatpickrをバインドする共通関数
     * 
     * @param {string|HTMLInputElement} target 要素またはセレクタ
     * @param {Object} options カスタムオプション
     */
    function attachOnokaiDatePicker(target, options) {
        if (typeof flatpickr === 'undefined') {
            console.warn('flatpickr is not loaded');
            return null;
        }

        const el = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!el) return null;

        // 既にFlatpickrがアタッチされていれば既存インスタンスを返す
        if (el._flatpickr) {
            return el._flatpickr;
        }

        options = options || {};

        // 元が type="date" の場合は type="text" に変換（標準ブラウザカレンダーの二重起動防止）
        if (el.getAttribute('type') === 'date' || el.type === 'date') {
            try { 
                el.type = 'text'; 
            } catch(e) {
                el.setAttribute('type', 'text');
            }
        }
        el.setAttribute('autocomplete', 'off');

        // デザイン崩れ防止・カレンダーアイコン付与クラスを追加
        el.classList.add('onokai-date-picker');

        const initialVal = el.value || options.defaultDate || '';

        // タイトル属性に和暦・曜日を初期セット
        if (initialVal) {
            el.title = `${initialVal} 【${formatToWareki(initialVal, true)}】`;
        }

        const fp = flatpickr(el, {
            locale: 'ja',
            defaultDate: initialVal || undefined,
            dateFormat: options.dateFormat || 'Y-m-d',
            monthSelectorType: 'static',
            disableMobile: true,
            position: options.position || 'auto center',
            onDayCreate: function(dObj, dStr, fpInstance, dayElem) {
                const dt = dayElem.dateObj;
                const y = dt.getFullYear();
                const m = dt.getMonth() + 1;
                const d = dt.getDate();

                // 1. 小野会公休（日曜・祝日）最優先: 赤
                const hReason = checkOnokaiHoliday(y, m, d);
                if (dt.getDay() === 0 || hReason) {
                    dayElem.classList.add('onokai-holiday');
                    if (hReason) dayElem.title = hReason;
                } else if (dt.getDay() === 4) {
                    // 2. 木曜: 青
                    dayElem.classList.add('onokai-thursday');
                }
            },
            onReady: function(selectedDates, dateStr, instance) {
                updateCalendarHeaderWareki(instance);
                if (selectedDates && selectedDates.length > 0) {
                    updateCalendarFooter(instance, selectedDates[0]);
                } else {
                    updateCalendarFooter(instance, null);
                }
            },
            onMonthChange: function(selectedDates, dateStr, instance) {
                updateCalendarHeaderWareki(instance);
            },
            onYearChange: function(selectedDates, dateStr, instance) {
                updateCalendarHeaderWareki(instance);
            },
            onOpen: function(selectedDates, dateStr, instance) {
                updateCalendarHeaderWareki(instance);
                if (selectedDates && selectedDates.length > 0) {
                    updateCalendarFooter(instance, selectedDates[0]);
                } else {
                    updateCalendarFooter(instance, null);
                }

                // 画面左端のはみ出し防止補正
                requestAnimationFrame(function() {
                    if (instance.calendarContainer) {
                        const rect = instance.calendarContainer.getBoundingClientRect();
                        if (rect.left < 10) {
                            const curLeft = parseFloat(instance.calendarContainer.style.left) || 0;
                            instance.calendarContainer.style.left = (curLeft + (10 - rect.left)) + 'px';
                        }
                    }
                });

                if (typeof options.onOpen === 'function') {
                    options.onOpen(selectedDates, dateStr, instance);
                }
            },
            onChange: function(selectedDates, dateStr, instance) {
                if (selectedDates && selectedDates.length > 0) {
                    updateCalendarFooter(instance, selectedDates[0]);
                    el.title = `${dateStr} 【${formatToWareki(dateStr, true)}】`;
                }

                if (typeof options.onChange === 'function') {
                    options.onChange(selectedDates, dateStr, instance);
                }

                // 既存の onchange / input イベントをバブリング発火
                el.dispatchEvent(new Event('change', { bubbles: true }));
                el.dispatchEvent(new Event('input', { bubbles: true }));
            },
            onClose: function(selectedDates, dateStr, instance) {
                if (typeof options.onClose === 'function') {
                    options.onClose(selectedDates, dateStr, instance);
                }
            }
        });

        return fp;
    }

    /**
     * .onokai-date-picker クラスを持つ全要素を一括初期化
     */
    function initAllOnokaiDatePickers() {
        try {
            var autoTargets = document.querySelectorAll('.onokai-date-picker');
            autoTargets.forEach(function(el) {
                if (!el._flatpickr) {
                    attachOnokaiDatePicker(el);
                }
            });
        } catch(e) {
            console.warn('OnokaiDatePicker auto-init error:', e);
        }
    }

    // グローバル公開
    window.OnokaiDatePicker = {
        checkHoliday: checkOnokaiHoliday,
        isHoliday: isOnokaiHoliday,
        isThursday: isOnokaiThursday,
        formatWareki: formatToWareki,
        getYearGengou: getYearGengou,
        attach: attachOnokaiDatePicker,
        initAll: initAllOnokaiDatePickers
    };

    // DOMContentLoaded 時に自動初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllOnokaiDatePickers);
    } else {
        initAllOnokaiDatePickers();
    }

})(window);
