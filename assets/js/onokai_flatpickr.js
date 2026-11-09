/**
 * ============================================================================
 * 設置パス: c:\Apache24\htdocs\api\v1\calendar\onokai_flatpickr.js
 * ファイル名: onokai_flatpickr.js
 * 概要: 医療法人小野会 特大Flatpickr共通初期化スクリプト（共通カレンダーAPI連携・拡張オプション対応）
 * 更新日時: 2026-11-08 19:30
 * 修正履歴:
 *   - 2026-10-20: 初版作成（小野会公休判定・特大Flatpickr共通初期化）
 *   - 2026-10-22: 【小野会日付ピッカー（Onokai Date Picker）簡単呼び出しAPI新設】
 *       1. OnokaiDatePicker.bindControl(target, options): 既存の input をワンコールで小野会角丸ピル日付ピッカーに自動変換
 *       2. 「西暦 (YYYY/MM/DD)」⇔「和暦 (令和X年M/D)」のワンクリック切替（localStorage記憶・画面全同期）
 *       3. HTML5 <dialog> Top Layer での裏隠れ防止（static: true & appendTo 自動解決）
 *       4. disabled 状態・minDate/maxDate 連動・change/input イベント透過発火を完備
 *   - 2026-11-04: 院内共通カレンダーAPI（/api/v1/calendar/holidays.php）非同期連携（OnokaiHolidayAPI）追加
 *   - 2026-11-06: テンプレートリテラル修正・年変更時（onYearChange）およびフッター表示のAPIキャッシュ連動を強化
 *   - 2026-11-08: 【UI機能強化＆表示オプション追加】
 *       1. カレンダー内フッターに「本日」日付移動ボタン（ワンクリックで今日を選択）を追加
 *       2. カレンダー内フッターに「小野会 / 一般」カレンダーモードのコンパクト切替ボタンを追加
 *          - 小野会モード（デフォルト）: 日曜・祝日・年末年始を赤、木曜を青でハイライト
 *          - 一般カレンダーモード: 日曜・国民の祝日を赤、土曜を青（木曜は通常平日）でハイライト
 *       3. JS引数（options）および data-* 属性で指定可能な3つの表示オプションを追加（デフォルトは従来通り）:
 *          - compact: true         (カレンダーコンパクト表示: 幅310px・36pxセル)
 *          - dark: true            (ダークモード表示)
 *          - circle: true          (日付セル〇（正円）表示モード: border-radius 50%)
 *          - calendarMode: 'onokai' | 'general' (初期カレンダーモード指定)
 * ============================================================================
 */

(function(window) {
    'use strict';

    /**
     * 拡張スタイル（本日ボタン・小野会/一般モード切替・コンパクト・ダーク・〇モード）の自動注入
     * 旧CSSキャッシュ残存環境やJS単体更新時でも確実にスタイルを適用する
     */
    function injectOnokaiEnhancedStyles() {
        if (typeof document === 'undefined') return;
        if (document.getElementById('onokai-flatpickr-enhanced-styles')) return;

        const style = document.createElement('style');
        style.id = 'onokai-flatpickr-enhanced-styles';
        style.textContent = `
/* 一般カレンダーモード時は木曜曜日ヘッダーを平日色に、土曜セルを青に */
.flatpickr-calendar.onokai-mode-general span.flatpickr-weekday:nth-child(5) { color: #475569 !important; }
.flatpickr-day.onokai-saturday {
    background: #e6f2ff !important;
    color: #2471a3 !important;
    border-color: #bfdbfe !important;
}
.flatpickr-day.onokai-saturday:hover { background: #dbeafe !important; }

/* フッター内レイアウト（日付情報＋本日ボタン＋小野会/一般切替） */
.onokai-fp-footer-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    width: 100%;
    font-size: 13px;
    font-weight: 700;
}
.onokai-fp-footer-info {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 0;
}
.onokai-fp-date-label {
    color: #0f172a;
    font-weight: 800;
    white-space: nowrap;
}
.onokai-fp-wareki-badge {
    color: #2563eb;
    background: #eff6ff;
    padding: 1px 7px;
    border-radius: 4px;
    border: 1px solid #bfdbfe;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.onokai-fp-status-badge {
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.onokai-fp-status-badge.holiday { background: #fee2e2; color: #dc2626; }
.onokai-fp-status-badge.sub-blue { background: #dbeafe; color: #1d4ed8; }
.onokai-fp-footer-actions {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-left: auto;
    flex-shrink: 0;
}
.onokai-fp-today-btn {
    appearance: none;
    border: 1px solid #3b82f6;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 800;
    padding: 2px 8px;
    height: 22px;
    line-height: 1;
    border-radius: 5px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    transition: all 0.15s ease;
    white-space: nowrap;
    font-family: inherit;
}
.onokai-fp-today-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #1d4ed8;
}
.onokai-fp-mode-switch {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    padding: 1px;
    height: 22px;
    box-sizing: border-box;
}
.onokai-fp-mode-btn {
    appearance: none;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 10px;
    font-weight: 800;
    padding: 0 6px;
    height: 18px;
    line-height: 18px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    font-family: inherit;
}
.onokai-fp-mode-btn:hover { color: #0f172a; }
.onokai-fp-mode-btn.active {
    background: #2563eb;
    color: #ffffff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.15);
}

/* オプション1: カレンダーコンパクト表示 (.onokai-compact) */
.flatpickr-calendar.onokai-compact {
    width: 310px !important;
    padding: 6px !important;
    border-radius: 10px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-months {
    height: 36px !important;
    padding-bottom: 2px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-current-month {
    font-size: 14px !important;
    padding: 2px 0 !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-monthDropdown-months,
.flatpickr-calendar.onokai-compact .flatpickr-current-month input.cur-year {
    font-size: 14px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-current-month .onokai-header-wareki {
    font-size: 12px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-prev-month,
.flatpickr-calendar.onokai-compact .flatpickr-next-month {
    padding: 4px 8px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-prev-month svg,
.flatpickr-calendar.onokai-compact .flatpickr-next-month svg {
    width: 14px !important;
    height: 14px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-weekdays {
    height: 28px !important;
    margin-top: 2px !important;
}
.flatpickr-calendar.onokai-compact span.flatpickr-weekday {
    font-size: 12px !important;
}
.flatpickr-calendar.onokai-compact .flatpickr-day {
    height: 36px !important;
    line-height: 34px !important;
    max-width: 40px !important;
    font-size: 13px !important;
    border-radius: 6px !important;
    margin: 1px !important;
}
.flatpickr-calendar.onokai-compact .onokai-fp-footer {
    margin-top: 5px;
    padding-top: 6px;
}
.flatpickr-calendar.onokai-compact .onokai-fp-footer-row {
    font-size: 11px;
    gap: 4px;
}
.flatpickr-calendar.onokai-compact .onokai-fp-wareki-badge,
.flatpickr-calendar.onokai-compact .onokai-fp-status-badge {
    font-size: 10px;
    padding: 1px 5px;
}

/* オプション2: 日付表示〇（正円）モード (.onokai-circle) */
.flatpickr-calendar.onokai-circle .flatpickr-day {
    border-radius: 50% !important;
    width: 50px !important;
    height: 50px !important;
    line-height: 48px !important;
    max-width: 50px !important;
    margin: 2px auto !important;
    padding: 0 !important;
}
.flatpickr-calendar.onokai-compact.onokai-circle .flatpickr-day {
    border-radius: 50% !important;
    width: 36px !important;
    height: 36px !important;
    line-height: 34px !important;
    max-width: 36px !important;
    margin: 1px auto !important;
    padding: 0 !important;
}

/* オプション3: ダークモード (.onokai-dark) */
.flatpickr-calendar.onokai-dark {
    background: #0f172a !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.65) !important;
    color: #f8fafc !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-months {
    border-bottom-color: #334155 !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-current-month,
.flatpickr-calendar.onokai-dark .flatpickr-current-month .cur-month,
.flatpickr-calendar.onokai-dark .flatpickr-monthDropdown-months,
.flatpickr-calendar.onokai-dark .flatpickr-current-month input.cur-year {
    color: #f8fafc !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-monthDropdown-months {
    background: #1e293b !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-current-month .onokai-header-wareki {
    color: #60a5fa !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-prev-month svg,
.flatpickr-calendar.onokai-dark .flatpickr-next-month svg {
    fill: #60a5fa !important;
}
.flatpickr-calendar.onokai-dark span.flatpickr-weekday { color: #94a3b8 !important; }
.flatpickr-calendar.onokai-dark span.flatpickr-weekday:first-child { color: #f87171 !important; }
.flatpickr-calendar.onokai-dark span.flatpickr-weekday:nth-child(5),
.flatpickr-calendar.onokai-dark span.flatpickr-weekday:last-child { color: #60a5fa !important; }
.flatpickr-calendar.onokai-dark.onokai-mode-general span.flatpickr-weekday:nth-child(5) { color: #94a3b8 !important; }
.flatpickr-calendar.onokai-dark .flatpickr-day {
    color: #e2e8f0 !important;
    background: transparent !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.prevMonthDay,
.flatpickr-calendar.onokai-dark .flatpickr-day.nextMonthDay,
.flatpickr-calendar.onokai-dark .flatpickr-day.flatpickr-disabled {
    color: #475569 !important;
    opacity: 0.45 !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day:hover {
    background: #1e293b !important;
    border-color: #38bdf8 !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.onokai-holiday {
    background: rgba(239, 68, 68, 0.22) !important;
    color: #fca5a5 !important;
    border-color: rgba(248, 113, 113, 0.45) !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.onokai-holiday:hover {
    background: rgba(239, 68, 68, 0.35) !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.onokai-thursday,
.flatpickr-calendar.onokai-dark .flatpickr-day.onokai-saturday {
    background: rgba(59, 130, 246, 0.22) !important;
    color: #93c5fd !important;
    border-color: rgba(96, 165, 250, 0.45) !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.onokai-thursday:hover,
.flatpickr-calendar.onokai-dark .flatpickr-day.onokai-saturday:hover {
    background: rgba(59, 130, 246, 0.35) !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.today:not(.selected) {
    border-color: #38bdf8 !important;
    background: rgba(56, 189, 248, 0.16) !important;
    color: #38bdf8 !important;
}
.flatpickr-calendar.onokai-dark .flatpickr-day.selected,
.flatpickr-calendar.onokai-dark .flatpickr-day.selected:hover {
    background: #2563eb !important;
    color: #ffffff !important;
    border-color: #60a5fa !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.55) !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-footer { border-top-color: #334155 !important; }
.flatpickr-calendar.onokai-dark .onokai-fp-date-label { color: #f1f5f9 !important; }
.flatpickr-calendar.onokai-dark .onokai-fp-wareki-badge {
    background: #1e293b !important;
    color: #93c5fd !important;
    border-color: #3b82f6 !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-status-badge.holiday {
    background: rgba(239, 68, 68, 0.25) !important;
    color: #fca5a5 !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-status-badge.sub-blue {
    background: rgba(59, 130, 246, 0.25) !important;
    color: #93c5fd !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-today-btn {
    background: #1e293b !important;
    color: #93c5fd !important;
    border-color: #3b82f6 !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-today-btn:hover {
    background: #2563eb !important;
    color: #ffffff !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-mode-switch {
    background: #1e293b !important;
    border-color: #334155 !important;
}
.flatpickr-calendar.onokai-dark .onokai-fp-mode-btn { color: #94a3b8 !important; }
.flatpickr-calendar.onokai-dark .onokai-fp-mode-btn:hover { color: #f8fafc !important; }
.flatpickr-calendar.onokai-dark .onokai-fp-mode-btn.active {
    background: #2563eb !important;
    color: #ffffff !important;
}
.onokai-date-control.onokai-dark {
    background: #1e293b;
    border-color: #3b82f6;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
}
.onokai-date-control.onokai-dark:hover {
    background: #334155;
    border-color: #60a5fa;
}
.onokai-date-control.onokai-dark .onokai-date-val-text { color: #f8fafc; }
.onokai-date-control.onokai-dark .onokai-date-calendar-icon-btn { border-left-color: #334155; }
.onokai-date-control.onokai-dark .onokai-date-calendar-icon-btn:hover { background: #475569; }
`;
        (document.head || document.documentElement).appendChild(style);
    }

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
     * 一般カレンダーモード用: 日本の国民の祝日判定（振替休日・国民の休日含む、小野会独自休診は除外）
     */
    function checkGeneralHoliday(year, month, day) {
        // 1. 基本祝日判定
        const base = getBaseHoliday(year, month, day);
        if (base) return base;

        const dt = new Date(year, month - 1, day);
        const dow = dt.getDay();

        // 2. 振替休日 (日曜が基本祝日の場合、その直後の平日が振替休日)
        if (dow === 1) {
            if (getBaseHoliday(year, month, day - 1)) return '振替休日';
        } else if (month === 5 && day === 6 && (dow === 2 || dow === 3)) {
            if (new Date(year, 4, 3).getDay() === 0 || new Date(year, 4, 4).getDay() === 0) {
                return '振替休日';
            }
        }

        // 3. 国民の休日 (祝日と祝日に挟まれた平日：前日と翌日が基本祝日で日曜でない)
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
     * 小野会モード用: 日本の祝日および小野会特別公休判定（振替休日・国民の休日・年末年始含む）
     */
    function checkOnokaiHoliday(year, month, day) {
        // 1. 小野会年末年始公休 (12/30 〜 1/3)
        if ((month === 12 && day >= 30) || (month === 1 && day <= 3)) {
            if (month === 1 && day === 1) return '元日';
            return '小野会公休';
        }

        // 2. 一般の国民の祝日・振替休日・国民の休日
        return checkGeneralHoliday(year, month, day);
    }

    /**
     * 小野会共通カレンダーAPI連携＆インメモリキャッシュ
     */
    const OnokaiHolidayAPI = {
        _cache: {},
        _fetching: {},

        async fetchMonth(year, month) {
            const mStr = String(month).padStart(2, '0');
            const key = year + '-' + mStr;
            if (this._cache[key]) return this._cache[key];
            if (this._fetching[key]) return this._fetching[key];

            this._fetching[key] = (async () => {
                try {
                    const url = '/api/v1/calendar/holidays.php?year=' + encodeURIComponent(year) + '&month=' + encodeURIComponent(month);
                    const res = await fetch(url);
                    if (res.ok) {
                        const json = await res.json();
                        if (json.status === 'success' && json.data) {
                            this._cache[key] = json.data;
                            return json.data;
                        }
                    }
                } catch (e) {
                    // API到達不能時は内蔵ロジックへフォールバック
                } finally {
                    delete this._fetching[key];
                }
                return null;
            })();

            return this._fetching[key];
        },

        getHolidayInfo(year, month, day, calendarMode) {
            if (calendarMode === 'general') {
                return checkGeneralHoliday(year, month, day);
            }
            const mStr = String(month).padStart(2, '0');
            const dStr = String(day).padStart(2, '0');
            const key = year + '-' + mStr;
            const dKey = key + '-' + dStr;
            if (this._cache[key] && this._cache[key][dKey]) {
                const item = this._cache[key][dKey];
                return item.reason || (item.is_closed ? '日曜休診' : null);
            }
            return checkOnokaiHoliday(year, month, day);
        }
    };
    window.OnokaiHolidayAPI = OnokaiHolidayAPI;

    /**
     * 小野会公休（日曜または祝日）判定
     */
    function isOnokaiHoliday(dateObj) {
        if (!dateObj) return false;
        const dow = dateObj.getDay();
        if (dow === 0) return true; // 日曜
        const reason = OnokaiHolidayAPI.getHolidayInfo(dateObj.getFullYear(), dateObj.getMonth() + 1, dateObj.getDate(), 'onokai');
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
     * オプションおよび data-* 属性の正規化解決
     * デフォルトはすべて false / 'onokai'（現在の状態を完全維持）
     */
    function resolveOnokaiOptions(el, options) {
        options = options || {};
        const ds = (el && el.dataset) ? el.dataset : {};

        const isTrue = function(val) {
            return val === true || val === 'true' || val === '1' || val === '';
        };

        // 1. カレンダーコンパクト表示
        const compact = Boolean(
            options.compact === true ||
            options.compactCalendar === true ||
            options.size === 'compact' ||
            (ds.compact !== undefined && isTrue(ds.compact)) ||
            (ds.calendarCompact !== undefined && isTrue(ds.calendarCompact)) ||
            (el && el.classList && el.classList.contains('onokai-calendar-compact'))
        );

        // 2. ダークモード
        const dark = Boolean(
            options.dark === true ||
            options.darkMode === true ||
            options.theme === 'dark' ||
            (ds.dark !== undefined && isTrue(ds.dark)) ||
            (ds.darkMode !== undefined && isTrue(ds.darkMode)) ||
            ds.theme === 'dark' ||
            (el && el.classList && el.classList.contains('onokai-dark'))
        );

        // 3. 日付表示〇（正円）モード
        const circle = Boolean(
            options.circle === true ||
            options.circleDays === true ||
            options.roundDays === true ||
            options.dayShape === 'circle' ||
            (ds.circle !== undefined && isTrue(ds.circle)) ||
            (ds.circleDays !== undefined && isTrue(ds.circleDays)) ||
            ds.dayShape === 'circle' ||
            (el && el.classList && el.classList.contains('onokai-circle'))
        );

        // 4. カレンダーモード ('onokai' または 'general')
        let calendarMode = 'onokai';
        const rawMode = options.calendarMode || ds.calendarMode || (options.generalMode === true ? 'general' : '');
        if (rawMode === 'general' || rawMode === 'standard' || rawMode === 'normal') {
            calendarMode = 'general';
        }

        return {
            compact: compact,
            dark: dark,
            circle: circle,
            calendarMode: calendarMode
        };
    }

    /**
     * カレンダーコンテナにオプション用のCSSクラスを反映
     */
    function applyCalendarContainerClasses(fpInstance) {
        if (!fpInstance || !fpInstance.calendarContainer) return;
        const cfg = fpInstance._onokaiConfig || {
            compact: false,
            dark: false,
            circle: false,
            calendarMode: 'onokai'
        };
        const cal = fpInstance.calendarContainer;
        cal.classList.toggle('onokai-compact', !!cfg.compact);
        cal.classList.toggle('onokai-dark', !!cfg.dark);
        cal.classList.toggle('onokai-circle', !!cfg.circle);
        cal.classList.toggle('onokai-mode-general', cfg.calendarMode === 'general');
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
     * カレンダーフッターバー（日付・和暦・休診バッジ ＋ 本日ボタン ＋ 小野会/一般モード切替ボタン）の生成・更新
     */
    function updateCalendarFooter(fpInstance, dateObj) {
        if (!fpInstance || !fpInstance.calendarContainer) return;
        let footer = fpInstance.calendarContainer.querySelector('.onokai-fp-footer');
        if (!footer) {
            footer = document.createElement('div');
            footer.className = 'onokai-fp-footer';
            fpInstance.calendarContainer.appendChild(footer);
        }

        const cfg = fpInstance._onokaiConfig || { calendarMode: 'onokai' };
        const calMode = cfg.calendarMode || 'onokai';

        // 左側: 選択中日付情報
        let infoHtml = '';
        if (!dateObj) {
            infoHtml = '<span style="color:#64748b; font-size:12px;">日付を選択してください</span>';
        } else {
            const y = dateObj.getFullYear();
            const m = String(dateObj.getMonth() + 1).padStart(2, '0');
            const d = String(dateObj.getDate()).padStart(2, '0');
            const dateStr = `${y}-${m}-${d}`;
            const dowNames = ['日', '月', '火', '水', '木', '金', '土'];
            const dow = dateObj.getDay();
            const dowStr = dowNames[dow];

            const wareki = formatToWareki(dateStr, false);
            const hReason = OnokaiHolidayAPI.getHolidayInfo(y, parseInt(m, 10), parseInt(d, 10), calMode);

            let badgeHtml = '';
            if (dow === 0 || hReason) {
                const defaultSunLabel = (calMode === 'general') ? '日曜' : '日曜休診';
                badgeHtml = `<span class="onokai-fp-status-badge holiday">🔴 ${hReason || defaultSunLabel}</span>`;
            } else if (calMode === 'onokai' && dow === 4) {
                badgeHtml = `<span class="onokai-fp-status-badge sub-blue">🔵 木曜</span>`;
            } else if (calMode === 'general' && dow === 6) {
                badgeHtml = `<span class="onokai-fp-status-badge sub-blue">🔵 土曜</span>`;
            }

            infoHtml = `
                <span class="onokai-fp-date-label">📅 ${y}/${m}/${d} (${dowStr})</span>
                <span class="onokai-fp-wareki-badge">${wareki}</span>
                ${badgeHtml}
            `;
        }

        // 右側: 本日ボタン ＆ 小野会/一般モード切替ボタン（コンパクト）
        const isOnokaiActive = (calMode === 'onokai') ? 'active' : '';
        const isGeneralActive = (calMode === 'general') ? 'active' : '';

        footer.innerHTML = `
            <div class="onokai-fp-footer-row">
                <div class="onokai-fp-footer-info">
                    ${infoHtml}
                </div>
                <div class="onokai-fp-footer-actions">
                    <button type="button" class="onokai-fp-today-btn" title="本日の日付を選択">本日</button>
                    <div class="onokai-fp-mode-switch" title="小野会モード（木曜青・年末年始休診）と一般カレンダーモード（土曜青・国民の祝日）を切替">
                        <button type="button" class="onokai-fp-mode-btn ${isOnokaiActive}" data-cal-mode="onokai">小野会</button>
                        <button type="button" class="onokai-fp-mode-btn ${isGeneralActive}" data-cal-mode="general">一般</button>
                    </div>
                </div>
            </div>
        `;

        // イベントバインド: 本日ボタン
        const todayBtn = footer.querySelector('.onokai-fp-today-btn');
        if (todayBtn) {
            todayBtn.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
            });
            todayBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const now = new Date();
                const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                fpInstance.setDate(today, true);
                fpInstance.jumpToDate(today);
                updateCalendarHeaderWareki(fpInstance);
                updateCalendarFooter(fpInstance, today);
            });
        }

        // イベントバインド: 小野会モード / 一般カレンダーモード切替ボタン
        const modeBtns = footer.querySelectorAll('.onokai-fp-mode-btn');
        modeBtns.forEach(function(btn) {
            btn.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
            });
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const targetMode = btn.getAttribute('data-cal-mode');
                if (!targetMode || fpInstance._onokaiConfig.calendarMode === targetMode) return;

                fpInstance._onokaiConfig.calendarMode = targetMode;
                applyCalendarContainerClasses(fpInstance);
                fpInstance.redraw();
                const curDate = (fpInstance.selectedDates && fpInstance.selectedDates.length > 0) ? fpInstance.selectedDates[0] : null;
                updateCalendarFooter(fpInstance, curDate);

                if (typeof fpInstance._onokaiOptions?.onCalendarModeChange === 'function') {
                    fpInstance._onokaiOptions.onCalendarModeChange(targetMode, fpInstance);
                }
            });
        });
    }

    /**
     * 内部用: 素の input に特大Flatpickrのみをバインドする関数（生年月日等で使用）
     */
    function createRawFlatpickr(target, options) {
        injectOnokaiEnhancedStyles();

        if (typeof flatpickr === 'undefined') {
            console.warn('flatpickr is not loaded');
            return null;
        }

        const el = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!el) return null;

        if (el._flatpickr) {
            return el._flatpickr;
        }

        options = options || {};
        const resolvedConfig = resolveOnokaiOptions(el, options);

        const inDialog = el.closest ? el.closest('dialog') : null;
        const appendTarget = (options.appendTo !== undefined) ? options.appendTo : (inDialog ? inDialog : undefined);
        const isStatic = (options.static !== undefined) ? options.static : (inDialog ? true : false);

        if (el.getAttribute('type') === 'date' || el.type === 'date') {
            try { 
                el.type = 'text'; 
            } catch(e) {
                el.setAttribute('type', 'text');
            }
        }
        el.setAttribute('autocomplete', 'off');

        const initialVal = el.value || options.defaultDate || '';

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
             positionElement: options.positionElement || undefined,
            minDate: options.minDate || undefined,
            maxDate: options.maxDate || undefined,
            appendTo: appendTarget,
            static: isStatic,
            onDayCreate: function(dObj, dStr, fpInstance, dayElem) {
                const dt = dayElem.dateObj;
                const y = dt.getFullYear();
                const m = dt.getMonth() + 1;
                const d = dt.getDate();
                const dow = dt.getDay();

                const cfg = fpInstance._onokaiConfig || resolvedConfig;
                const calMode = cfg.calendarMode || 'onokai';

                const hReason = OnokaiHolidayAPI.getHolidayInfo(y, m, d, calMode);
                if (dow === 0 || hReason) {
                    dayElem.classList.add('onokai-holiday');
                    if (hReason) dayElem.title = hReason;
                } else if (calMode === 'onokai' && dow === 4) {
                    dayElem.classList.add('onokai-thursday');
                } else if (calMode === 'general' && dow === 6) {
                    dayElem.classList.add('onokai-saturday');
                }
            },
            onReady: function(selectedDates, dateStr, instance) {
                instance._onokaiConfig = instance._onokaiConfig || resolvedConfig;
                instance._onokaiOptions = options;
                applyCalendarContainerClasses(instance);
                updateCalendarHeaderWareki(instance);
                if (typeof OnokaiHolidayAPI !== 'undefined') {
                    OnokaiHolidayAPI.fetchMonth(instance.currentYear, instance.currentMonth + 1).then(data => {
                        if (data && instance.calendarContainer) {
                            instance.redraw();
                            const curDate = (instance.selectedDates && instance.selectedDates.length > 0) ? instance.selectedDates[0] : null;
                            updateCalendarFooter(instance, curDate);
                        }
                    });
                }
                if (selectedDates && selectedDates.length > 0) {
                    updateCalendarFooter(instance, selectedDates[0]);
                } else {
                    updateCalendarFooter(instance, null);
                }
            },
            onMonthChange: function(selectedDates, dateStr, instance) {
                updateCalendarHeaderWareki(instance);
                if (typeof OnokaiHolidayAPI !== 'undefined') {
                    OnokaiHolidayAPI.fetchMonth(instance.currentYear, instance.currentMonth + 1).then(data => {
                        if (data && instance.calendarContainer) {
                            instance.redraw();
                        }
                    });
                }
            },
            onYearChange: function(selectedDates, dateStr, instance) {
                updateCalendarHeaderWareki(instance);
                if (typeof OnokaiHolidayAPI !== 'undefined') {
                    OnokaiHolidayAPI.fetchMonth(instance.currentYear, instance.currentMonth + 1).then(data => {
                        if (data && instance.calendarContainer) {
                            instance.redraw();
                        }
                    });
                }
            },
            onOpen: function(selectedDates, dateStr, instance) {
                if (instance.currentYear <= 1900 || instance.currentYear >= 9999) {
                    instance.jumpToDate(new Date());
                }
                applyCalendarContainerClasses(instance);
                updateCalendarHeaderWareki(instance);
                if (selectedDates && selectedDates.length > 0) {
                    updateCalendarFooter(instance, selectedDates[0]);
                } else {
                    updateCalendarFooter(instance, null);
                }

                // 画面およびモーダル枠内での左右はみ出し防止自動補正（見切れ完全追放）
                requestAnimationFrame(function() {
                    if (instance.calendarContainer) {
                        const rect = instance.calendarContainer.getBoundingClientRect();
                        const pEl = instance.calendarContainer.parentElement;
                        const pRect = (pEl && pEl !== document.body) ? pEl.getBoundingClientRect() : { left: 0, right: window.innerWidth };
                        
                        const minLeft = Math.max(10, pRect.left + 10);
                        const maxRight = Math.min(window.innerWidth - 10, pRect.right - 10);

                        let curLeft = parseFloat(instance.calendarContainer.style.left) || rect.left;

                        if (rect.right > maxRight) {
                            curLeft -= (rect.right - maxRight);
                            instance.calendarContainer.style.left = curLeft + 'px';
                        }
                        if (rect.left < minLeft) {
                            curLeft += (minLeft - rect.left);
                            instance.calendarContainer.style.left = curLeft + 'px';
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

                el.dispatchEvent(new Event('change', { bubbles: true }));
                el.dispatchEvent(new Event('input', { bubbles: true }));
            },
            onClose: function(selectedDates, dateStr, instance) {
                if (typeof options.onClose === 'function') {
                    options.onClose(selectedDates, dateStr, instance);
                }
            }
        });

        fp._onokaiConfig = resolvedConfig;
        fp._onokaiOptions = options;
        applyCalendarContainerClasses(fp);

        /**
         * ランタイムで表示オプション（compact, dark, circle, calendarMode）を動的に変更するメソッド
         */
        fp.setOnokaiOptions = function(newOpts) {
            if (!newOpts) return;
            if (newOpts.compact !== undefined || newOpts.compactCalendar !== undefined || newOpts.size !== undefined) {
                fp._onokaiConfig.compact = Boolean(newOpts.compact || newOpts.compactCalendar || newOpts.size === 'compact');
            }
            if (newOpts.dark !== undefined || newOpts.darkMode !== undefined || newOpts.theme !== undefined) {
                fp._onokaiConfig.dark = Boolean(newOpts.dark || newOpts.darkMode || newOpts.theme === 'dark');
            }
            if (newOpts.circle !== undefined || newOpts.circleDays !== undefined || newOpts.roundDays !== undefined || newOpts.dayShape !== undefined) {
                fp._onokaiConfig.circle = Boolean(newOpts.circle || newOpts.circleDays || newOpts.roundDays || newOpts.dayShape === 'circle');
            }
            if (newOpts.calendarMode !== undefined || newOpts.generalMode !== undefined) {
                const m = newOpts.calendarMode || (newOpts.generalMode ? 'general' : 'onokai');
                fp._onokaiConfig.calendarMode = (m === 'general' || m === 'standard' || m === 'normal') ? 'general' : 'onokai';
            }
            applyCalendarContainerClasses(fp);
            fp.redraw();
            const curDate = (fp.selectedDates && fp.selectedDates.length > 0) ? fp.selectedDates[0] : null;
            updateCalendarFooter(fp, curDate);
        };

        return fp;
    }

    /**
     * 既存の input[type="date"] や input[type="text"] に
     * 小野会日付ピッカー（角丸ピル形状・西暦和暦バッジ・仕切り線📅アイコン）をバインドする共通関数
     * 
     * @param {string|HTMLInputElement} target 要素またはセレクタ
     * @param {Object} options カスタムオプション
     *   - compact: boolean (カレンダーコンパクト表示)
     *   - dark: boolean (ダークモード)
     *   - circle: boolean (日付セル〇正円モード)
     *   - calendarMode: 'onokai' | 'general' (初期カレンダーモード)
     */
    function attachOnokaiDatePicker(target, options) {
        options = options || {};
        const el = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!el) return null;

        // 生年月日等、素の input のまま使用したい場合（options.raw === true または data-no-pill 属性）
        if (options.raw === true || el.dataset.noPill !== undefined || el.classList.contains('no-pill') || el.classList.contains('raw-input')) {
            return createRawFlatpickr(el, options);
        }

        // それ以外はすべて「小野会日付ピッカー（角丸ピル形状・西暦和暦切替・📅仕切りアイコン）」に自動変換！
        const ctrl = bindOnokaiDateControl(el, options);
        return ctrl ? ctrl.flatpickr : null;
    }

    /**
     * 和暦フォーマット文字列を生成
     * @param {string} dateStr 'YYYY-MM-DD'
     * @param {string} dowStr '木' など
     * @returns {string} 例: '令和8年10/22 (木)'
     */
    function formatWarekiShort(dateStr, dowStr) {
        if (!dateStr || !dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) return dateStr || '';
        if (dateStr <= '1900-01-01') return '過去全域 (1900/01/01)';
        if (dateStr >= '9999-01-01') return '無期限 (9999.9.9)';
        const parts = dateStr.split('-');
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
        const dowPart = dowStr ? ` (${dowStr})` : '';
        return `${g}${gStr}年${m}/${d}${dowPart}`;
    }

    /**
     * 西暦フォーマット文字列を生成
     * @param {string} dateStr 'YYYY-MM-DD'
     * @param {string} dowStr '木' など
     * @returns {string} 例: '2026/10/22 (木)'
     */
    function formatSeirekiShort(dateStr, dowStr) {
        if (!dateStr || !dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) return dateStr || '';
        if (dateStr <= '1900-01-01') return '過去全域 (1900/01/01)';
        if (dateStr >= '9999-01-01') return '無期限 (9999.9.9)';
        const parts = dateStr.split('-');
        const dowPart = dowStr ? ` (${dowStr})` : '';
        return `${parts[0]}/${parts[1]}/${parts[2]}${dowPart}`;
    }

    /**
     * 日付文字列から曜日文字（'日'〜'土'）を取得
     */
    function getDowChar(dateStr) {
        if (!dateStr || !dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) return '';
        const dt = new Date(dateStr + 'T00:00:00');
        const dowArr = ['日', '月', '火', '水', '木', '金', '土'];
        return dowArr[dt.getDay()] || '';
    }

    /**
     * 院内共通の現在の表示モード（西暦または和暦）
     */
    function getDateDisplayMode() {
        try {
            return localStorage.getItem('onokai_date_picker_mode') || localStorage.getItem('schedule_note_date_mode') || 'seireki';
        } catch(e) {
            return 'seireki';
        }
    }

    function setDateDisplayMode(mode) {
        try {
            localStorage.setItem('onokai_date_picker_mode', mode);
            localStorage.setItem('schedule_note_date_mode', mode);
            localStorage.setItem('waku_modal_date_mode', mode);
        } catch(e) {}
    }

    /**
     * 既存の input 要素（またはセレクタ）を「小野会日付ピッカー」UIに自動変換・バインドする
     * 
     * @param {string|HTMLInputElement} target input要素またはセレクタ
     * @param {Object} options
     *   - onChange: function(selectedDates, dateStr, instance)
     *   - minDate, maxDate
     *   - static: true/false
     *   - appendTo: 要素
     *   - width: string
     *   - compact: boolean (カレンダーコンパクト表示)
     *   - dark: boolean (ダークモード)
     *   - circle: boolean (日付表示が〇モード)
     *   - calendarMode: 'onokai' | 'general' (初期カレンダーモード)
     */
    function bindOnokaiDateControl(target, options) {
        injectOnokaiEnhancedStyles();

        const input = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!input) return null;

        // 既に小野会コントロールがバインドされていればオプション反映の上で返す
        if (input._onokaiControl) {
            if (options && typeof input._onokaiControl.setOptions === 'function') {
                input._onokaiControl.setOptions(options);
            }
            return input._onokaiControl;
        }

        // 既に旧Flatpickrが存在する場合は安全に破棄して小野会コントロール用に再構築
        if (input._flatpickr) {
            try { input._flatpickr.destroy(); } catch(e) {}
            input._flatpickr = null;
        }
        input.classList.remove('onokai-date-picker');

        options = options || {};
        const resolvedConfig = resolveOnokaiOptions(input, options);

        // 既存のピルコンテナが既に存在するかチェック（事前レンダリング・既存手動HTML対応・二重生成絶対防止）
        let control = input.closest ? input.closest('.onokai-date-control, .wom-custom-date-control, .custom-date-control') : null;
        let textToggle = control ? control.querySelector('.onokai-date-text-toggle, .wom-date-text-toggle, .date-text-toggle') : null;
        let valText = control ? control.querySelector('.onokai-date-val-text, .wom-date-val-text, .date-val-text') : null;
        let modeBadge = control ? control.querySelector('.onokai-date-mode-badge, .wom-date-mode-badge, .date-mode-badge') : null;
        let iconBtn = control ? control.querySelector('.onokai-date-calendar-icon-btn, .wom-date-calendar-icon-btn, .date-calendar-icon-btn, #calendarTriggerBtn, #womCalendarTriggerBtn') : null;

        if (!control) {
            // 1. ラッパーコンテナの作成
            control = document.createElement('div');
            control.className = 'onokai-date-control';
            if (input.disabled) control.classList.add('disabled');
            if (options.width) control.style.width = options.width;
            control.title = 'クリックでカレンダー展開、文字部クリックで西暦/和暦切替';

            // 2. テキスト＋バッジ部分
            textToggle = document.createElement('div');
            textToggle.className = 'onokai-date-text-toggle';
            textToggle.title = 'クリックで西暦／和暦 表示切替';

            valText = document.createElement('span');
            valText.className = 'onokai-date-val-text';

            modeBadge = document.createElement('span');
            modeBadge.className = 'onokai-date-mode-badge';

            textToggle.appendChild(valText);
            textToggle.appendChild(modeBadge);

            // 3. カレンダーアイコン部分
            iconBtn = document.createElement('div');
            iconBtn.className = 'onokai-date-calendar-icon-btn';
            iconBtn.title = 'カレンダーを開く';
            iconBtn.textContent = '📅';

            // 4. 元のinputをコントロール内に隠し配置
            input.classList.add('onokai-date-control-hidden');
            input.setAttribute('autocomplete', 'off');
            input.readOnly = true;

            // DOM挿入（inputの位置にcontrolを置き、中にinputを入れる）
            if (input.parentNode) {
                input.parentNode.insertBefore(control, input);
            }
            control.appendChild(textToggle);
            control.appendChild(iconBtn);
            control.appendChild(input);
        } else {
            if (options.width) control.style.width = options.width;
            input.classList.add('onokai-date-control-hidden');
            input.setAttribute('autocomplete', 'off');
            input.readOnly = true;
        }

        if (resolvedConfig.dark && options.darkControl === true) {
            control.classList.add('onokai-dark');
        }

        // 5. 表示更新関数
        function updateDisplay() {
            const val = input.value || '';
            const dow = getDowChar(val);
            const mode = getDateDisplayMode();

            if (!val) {
                valText.textContent = '日付を選択';
                modeBadge.style.display = 'none';
                return;
            }

            modeBadge.style.display = 'inline-block';
            if (mode === 'wareki') {
                valText.textContent = formatWarekiShort(val, dow);
                modeBadge.textContent = '和暦';
                modeBadge.style.background = '#fef3c7';
                modeBadge.style.color = '#92400e';
            } else {
                valText.textContent = formatSeirekiShort(val, dow);
                modeBadge.textContent = '西暦';
                modeBadge.style.background = '#bfdbfe';
                modeBadge.style.color = '#1e40af';
            }
        }
        updateDisplay();

        // 6. テキストクリックで西暦/和暦切替
        textToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const curMode = getDateDisplayMode();
            const newMode = (curMode === 'seireki') ? 'wareki' : 'seireki';
            setDateDisplayMode(newMode);
            // ページ上の全小野会日付ピッカーの表示を同期更新
            document.querySelectorAll('.onokai-date-control, .wom-custom-date-control, .custom-date-control').forEach(function(ctrl) {
                if (ctrl._updateDisplay) ctrl._updateDisplay();
            });
        });

        // 7. 特大Flatpickrのバインド
        const inDialog = control.closest ? control.closest('dialog') : null;
        const appendTarget = (options.appendTo !== undefined) ? options.appendTo : (inDialog ? inDialog : document.body);
        const isStatic = (options.static !== undefined) ? options.static : false;

        const fpOpts = Object.assign({}, options, {
            defaultDate: input.value || undefined,
            positionElement: control,
            position: options.position || 'auto center',
            appendTo: appendTarget,
            static: isStatic,
            onChange: function(selectedDates, dateStr, instance) {
                input.value = dateStr;
                updateDisplay();
                if (typeof options.onChange === 'function') {
                    options.onChange(selectedDates, dateStr, instance);
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        const fp = createRawFlatpickr(input, fpOpts);

        // 8. コントロール全体またはアイコンクリックでカレンダー展開
        control.addEventListener('click', function(e) {
            if (control.classList.contains('disabled')) return;
            if (e.target === textToggle || textToggle.contains(e.target)) return;
            if (fp) fp.open();
        });

        // 9. オブジェクト参照保持
        const api = {
            control: control,
            input: input,
            flatpickr: fp,
            updateDisplay: updateDisplay,
            setDate: function(newDate, triggerChange) {
                input.value = newDate;
                if (fp) fp.setDate(newDate, false);
                updateDisplay();
                if (triggerChange) {
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            },
            setDisabled: function(disabled) {
                input.disabled = !!disabled;
                if (disabled) {
                    control.classList.add('disabled');
                } else {
                    control.classList.remove('disabled');
                }
            },
            setOptions: function(newOpts) {
                if (fp && typeof fp.setOnokaiOptions === 'function') {
                    fp.setOnokaiOptions(newOpts);
                }
                if (newOpts && newOpts.darkControl !== undefined) {
                    control.classList.toggle('onokai-dark', !!newOpts.darkControl);
                }
            }
        };

        control._updateDisplay = updateDisplay;
        control._onokaiControl = api;
        input._onokaiControl = api;

        return api;
    }

    /**
     * .onokai-date-picker または .onokai-date-control-auto クラスを持つ全要素を一括初期化
     */
    function initAllOnokaiDatePickers() {
        try {
            var autoTargets = document.querySelectorAll('.onokai-date-picker, .onokai-date-control-auto');
            autoTargets.forEach(function(el) {
                if (!el._onokaiControl && !el._flatpickr) {
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
        checkGeneralHoliday: checkGeneralHoliday,
        isHoliday: isOnokaiHoliday,
        isThursday: isOnokaiThursday,
        formatWareki: formatToWareki,
        formatWarekiShort: formatWarekiShort,
        formatSeirekiShort: formatSeirekiShort,
        getYearGengou: getYearGengou,
        attach: attachOnokaiDatePicker,
        bindControl: bindOnokaiDateControl,
        createControl: bindOnokaiDateControl,
        initAll: initAllOnokaiDatePickers
    };
    window.attachOnokaiDatePicker = attachOnokaiDatePicker;
    window.bindOnokaiDateControl = bindOnokaiDateControl;

    // DOMContentLoaded 時に自動初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllOnokaiDatePickers);
    } else {
        initAllOnokaiDatePickers();
    }

})(window);
