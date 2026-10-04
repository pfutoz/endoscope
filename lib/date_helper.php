<?php
/**
 * lib/date_helper.php - 日付関連関数
 * 修正：PatientBOX表示適正化（2026-08-05）
 *   - getAgeBadgeHTML(): 「歳」を削除して数値のみ表示
 *   - convertToShortWareki(): 生年月日を短縮表記（S23.5.22形式）
 *   - getDoctorIcon(): 担当医→1文字アイコン変換
 */

function calculateAge($birthday, $examDate) {
    if (empty($birthday) || $birthday === '---') return null;
    try {
        $birth = new DateTime($birthday);
        $exam = new DateTime($examDate);
        if ($birth > $exam) return null;
        return $exam->diff($birth)->y;
    } catch (Exception $e) { return null; }
}

/**
 * 年齢バッジ（「歳」なし・数値のみ）
 */
function getAgeBadgeHTML($age) {
    if ($age === null || $age === '') return '';
    
    if ($age < 65) {
        $style = "background-color: #e0f2fe; color: #0369a1;";
    } else if ($age >= 65 && $age <= 74) {
        $style = "background-color: #fef08a; color: #713f12; font-weight: bold;";
    } else if ($age >= 75 && $age <= 85) {
        $style = "background-color: #fca5a5; color: #991b1b; font-weight: bold;";
    } else {
        $style = "background-color: #ef4444; color: #ffffff; font-weight: bold;";
    }
    // 「歳」を削除して数値のみ表示
    return "<span class='age-badge' style='display:inline-block; padding:1px 5px; font-size:10px; border-radius:4px; line-height:1.1; {$style}'>{$age}</span>";
}

/**
 * 生年月日を短縮元号表記に変換（S23.5.22 形式）
 */
function convertToShortWareki($birthday) {
    if (empty($birthday) || $birthday === '---' || $birthday === '0000-00-00') {
        return '';
    }
    
    $ts = strtotime($birthday);
    if ($ts === false) return '';
    
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $day = (int)date('j', $ts);
    
    if ($year >= 2019) {
        $wareki = 'R' . ($year - 2018);
    } elseif ($year >= 1989) {
        $wareki = 'H' . ($year - 1988);
    } elseif ($year >= 1926) {
        $wareki = 'S' . ($year - 1925);
    } elseif ($year >= 1912) {
        $wareki = 'T' . ($year - 1911);
    } else {
        $wareki = 'M' . ($year - 1867);
    }
    
    return $wareki . '.' . $month . '.' . $day;
}

/**
 * 担当医→1文字アイコン変換
 */
function getDoctorIcon($doctor_name) {
    if (empty($doctor_name)) return '？';
    
    $map = [
        '誠吾' => '誠',
        '哲也' => '哲',
        '義智' => '義',
        '誠'   => '誠',
        '哲'   => '哲',
        '義'   => '義',
    ];
    
    foreach ($map as $key => $icon) {
        if (strpos($doctor_name, $key) !== false) {
            return $icon;
        }
    }
    
    return mb_substr($doctor_name, 0, 1, 'UTF-8');
}

/**
 * 生年月日→和暦変換（従来の表示用・例：昭和23年5月22日）
 */
function convertToWareki($birthday) {
    if (empty($birthday) || $birthday === '---') return '---';
    try {
        $date = new DateTime($birthday);
        $y = (int)$date->format('Y');
        $m = (int)$date->format('n');
        $d = (int)$date->format('j');
        if ($y >= 2019) {
            $era = '令和';
            $year = $y - 2018;
        } else if ($y >= 1989) {
            $era = '平成';
            $year = $y - 1988;
        } else if ($y >= 1926) {
            $era = '昭和';
            $year = $y - 1925;
        } else if ($y >= 1912) {
            $era = '大正';
            $year = $y - 1911;
        } else {
            $era = '明治';
            $year = $y - 1867;
        }
        if ($year == 1) {
            return $era . '元年' . $m . '月' . $d . '日';
        }
        return $era . $year . '年' . $m . '月' . $d . '日';
    } catch (Exception $e) {
        return $birthday;
    }
}

/**
 * 生年月日を西暦(和暦)-月-日 形式（例: 1967(S42)-04-28）に変換
 * 1桁年も2桁ゼロ埋め（例: 2021(R03)-05-12）
 */
function formatBirthdayWithWareki($birthday) {
    if (empty($birthday) || $birthday === '---' || $birthday === '0000-00-00') {
        return '';
    }
    try {
        $date = new DateTime($birthday);
        $ymd = $date->format('Y-m-d');
        $y = (int)$date->format('Y');
        $m = (int)$date->format('m');
        $d = (int)$date->format('d');

        if ($ymd >= '2019-05-01') {
            $era = 'R';
            $wYear = $y - 2018;
        } else if ($ymd >= '1989-01-08') {
            $era = 'H';
            $wYear = $y - 1988;
        } else if ($ymd >= '1926-12-25') {
            $era = 'S';
            $wYear = $y - 1925;
        } else if ($ymd >= '1912-07-30') {
            $era = 'T';
            $wYear = $y - 1911;
        } else {
            $era = 'M';
            $wYear = $y - 1867;
        }

        $wYearStr = str_pad((string)$wYear, 2, '0', STR_PAD_LEFT);
        $mStr = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        $dStr = str_pad((string)$d, 2, '0', STR_PAD_LEFT);

        return "{$y}({$era}{$wYearStr})-{$mStr}-{$dStr}";
    } catch (Exception $e) {
        return $birthday;
    }
}

/**
 * 曜日取得関数
 */
function getWeekday($date_str) {
    if (empty($date_str)) return '';
    $days = ['日', '月', '火', '水', '木', '金', '土'];
    $ts = strtotime($date_str);
    if ($ts === false) return '';
    return $days[(int)date('w', $ts)];
}