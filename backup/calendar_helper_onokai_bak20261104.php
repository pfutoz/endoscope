<?php
/**
 * 医療法人小野会 専用 カレンダー・休診判定ヘルパー（シンプル曜日固定＋年末午後版）
 */

/**
 * 指定された日付・時間枠が小野会の休診（または祝日）にあたるかを判定する
 */
function check_onokai_calendar($date_str, $waku_time = null) {
    $ts    = strtotime($date_str);
    $year  = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $day   = (int)date('j', $ts);
    $w     = (int)date('w', $ts); // 0:日, 1:月, ..., 4:木, 6:土
    
    $res = [
        'is_holiday' => false,
        'is_closed'  => false,
        'reason'     => ''
    ];

    // 1. 日曜日（基本休診）
    if ($w === 0) {
        $res['is_closed'] = true;
        $res['reason']    = '日祝休診';
        return $res;
    }

    // 2. 医療法人小野会 特別年末年始休診（12/31〜1/3）
    $m_d = date('m-d', $ts);
    if ($m_d === '12-31' || $m_d === '01-01' || $m_d === '01-02' || $m_d === '01-03') {
        $res['is_closed'] = true;
        $res['reason']    = '年末年始休診';
        return $res;
    }

    // 3. 日本の祝日ロジック判定
    $holiday_name = null;
    $nth_week = (int)ceil($day / 7);

    switch ($month) {
        case 1:
            if ($day === 1) $holiday_name = "元日";
            if ($w === 1 && $nth_week === 2) $holiday_name = "成人の日";
            break;
        case 2:
            if ($day === 11) $holiday_name = "建国記念の日";
            if ($day === 23) $holiday_name = "天皇誕生日";
            break;
        case 3:
            $shunbun = (int)(20.8431 + 0.242194 * ($year - 1980) - (int)(($year - 1980) / 4));
            if ($day === $shunbun) $holiday_name = "春分の日";
            break;
        case 4:
            if ($day === 29) $holiday_name = "昭和の日";
            break;
        case 5:
            if ($day === 3) $holiday_name = "憲法記念日";
            if ($day === 4) $holiday_name = "みどりの日";
            if ($day === 5) $holiday_name = "こどもの日";
            break;
        case 7:
            if ($w === 1 && $nth_week === 3) $holiday_name = "海の日";
            break;
        case 8:
            if ($day === 11) $holiday_name = "山の日";
            break;
        case 9:
            // 1. 敬老の日（第3月曜日）
            if ($w === 1 && $nth_week === 3) $holiday_name = "敬老の日";
            
            // 2. 秋分の日（計算式）
            $shubun = (int)(23.2488 + 0.242194 * ($year - 1980) - (int)(($year - 1980) / 4));
            if ($day === $shubun) $holiday_name = "秋分の日";
            
            // ★★★ 3. 国民の休日（敬老の日と秋分の日が挟む日） ★★★
            // 9月だけチェックすればOK
            if (!$holiday_name && $w >= 2 && $w <= 4) { // 火・水・木
                // 敬老の日（第3月曜日）を算出
                $keiro_day = 0;
                for ($d = 1; $d <= 7; $d++) {
                    if ((int)date('w', strtotime("$year-09-$d")) === 1) {
                        $keiro_day = $d + 14; // 第3月曜日
                        break;
                    }
                }
                
                // 敬老の日と秋分の日の間の日をチェック
                if ($keiro_day > 0) {
                    for ($d = $keiro_day + 1; $d < $shubun; $d++) {
                        if ($day === $d) {
                            $holiday_name = "国民の休日";
                            break;
                        }
                    }
                }
            }
            break;
        case 10:
            if ($w === 1 && $nth_week === 2) $holiday_name = "スポーツの日";
            break;
        case 11:
            if ($day === 3) $holiday_name = "文化の日";
            if ($day === 23) $holiday_name = "勤労感謝の日";
            break;
    }

    if (!$holiday_name && $w === 1) {
        $prev_ts = strtotime("-1 day", $ts);
        $prev_res = check_onokai_calendar(date('Y-m-d', $prev_ts), null);
        if ($prev_res['is_holiday']) { $holiday_name = "振替休日"; }
    }
    if (!$holiday_name && $month === 5 && $day === 6 && ($w === 2 || $w === 3)) {
        $sun_check3 = (int)date('w', strtotime("$year-05-03"));
        $sun_check4 = (int)date('w', strtotime("$year-05-04"));
        if ($sun_check3 === 0 || $sun_check4 === 0) { $holiday_name = "振替休日"; }
    }

    if ($holiday_name) {
        $res['is_holiday'] = true;
        $res['is_closed']  = true;
        $res['reason']     = $holiday_name;
        return $res;
    }

    return $res;
}

/**
 * 日付・時間枠に応じた背景色・文字色カラーコードを直接返却する関数
 */
function get_onokai_cell_style($date_str, $waku_time = null) {
    $day_check = check_onokai_calendar($date_str, null);
    $ts = strtotime($date_str);
    $w  = (int)date('w', $ts);
    
    // 今日、および過去の基準判定
    $today_str = date('Y-m-d');
    $is_past = ($date_str < $today_str);

    // 🎨 小野会標準カラーパレットの定義
    $bg = "#ffffff"; // 通常平日（白）
    $fg = "#333333"; // 通常文字（濃いグレー）

    // 1. 曜日・祝日の基本色判定
    if ($day_check['is_holiday'] || $w === 0) {
        $bg = "#ffe6e6"; // 日曜・祝日（薄赤）
        $fg = "#d9383a"; // 日曜・祝日文字（赤）
    } elseif ($w === 4) {
        $bg = "#e6f2ff"; // 木曜日（水色）
        $fg = "#2471a3"; 
    }

    // 2. 12月30日の午後枠を水色にする判定
    if ($waku_time !== null && date('m-d', $ts) === '12-30') {
        if (strpos($waku_time, '09:') === false && strpos($waku_time, '10:') === false && strpos($waku_time, '11:') === false) {
            $bg = "#e6f2ff"; 
            $fg = "#2471a3";
        }
    }

    // 3. 💡【最優先】昨日以前の過去日付はすべて上からグレーで強制上書き！！
    if ($is_past) {
        $bg = "#f0f0f0"; // 過去日（ライトグレー）
        $fg = "#888888"; // 過去文字（マイルドグレー）
    }
    
    return [
        'bg' => $bg,
        'fg' => $fg,
        'css' => "background-color: {$bg} !important; color: {$fg} !important;"
    ];
}

/**
 * 🌟 医療法人小野会 特大Flatpickr共通日付ピッカー（角丸ピル形状・西暦和暦切替・📅仕切りアイコン）HTML生成
 * 
 * @param string $id input要素のID
 * @param string $name input要素のname
 * @param string $value 日付文字列 (YYYY-MM-DD)
 * @param array $options オプション [width => '195px', required => true, class => '...', style => '...']
 * @return string 生成されたHTML
 */
function render_onokai_date_control($id, $name, $value = '', $options = []) {
    $ts = !empty($value) ? strtotime($value) : time();
    $val_str = !empty($value) ? date('Y-m-d', $ts) : date('Y-m-d');
    
    $dow_map = ['日', '月', '火', '水', '木', '金', '土'];
    $dow_char = $dow_map[(int)date('w', $ts)];
    $disp_text = date('Y/m/d', $ts) . " ({$dow_char})";

    $required = !empty($options['required']) ? 'required' : '';
    $ctrl_id = !empty($options['control_id']) ? $options['control_id'] : ($id . '_control');
    $custom_style = !empty($options['width']) ? "width: {$options['width']}; " : '';
    if (!empty($options['style'])) {
        $custom_style .= $options['style'];
    }

    $html = <<<HTML
<div class="onokai-date-control" id="{$ctrl_id}" style="{$custom_style}" title="クリックで特大カレンダー展開、文字部クリックで西暦/和暦切替">
    <div class="onokai-date-text-toggle" title="クリックで西暦／和暦 表示切替">
        <span class="onokai-date-val-text">{$disp_text}</span>
        <span class="onokai-date-mode-badge">西暦</span>
    </div>
    <div class="onokai-date-calendar-icon-btn" title="特大カレンダーを開く">📅</div>
    <input type="text" id="{$id}" name="{$name}" value="{$val_str}" class="onokai-date-control-hidden" {$required} autocomplete="off" readonly>
</div>
HTML;
    return $html;
}

/**
 * 部屋番号に応じたカラーバッジ用CSSクラスを取得
 * (ﾏ: 緑 room-ma, ｲ: 青 room-i, ｺ: ベージュ room-ko)
 * 
 * @param string $roomName 部屋名
 * @return string CSSクラス名
 */
function getRoomBadgeClass(string $roomName): string {
    $trimmed = trim($roomName);
    if ($trimmed === '' || $trimmed === '-' || $trimmed === '未指定') return '';
    if (preg_match('/^(?:ﾏ|マ)/u', $trimmed)) return 'room-ma';
    if (preg_match('/^(?:ｲ|イ)/u', $trimmed)) return 'room-i';
    if (preg_match('/^(?:ｺ|コ)/u', $trimmed)) return 'room-ko';
    return 'room-other';
}