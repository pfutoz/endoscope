import re

path = r'c:\Apache24\htdocs\endoscope\action_slot_assistant.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# 433行付近のプレビュー処理から echo json_encode 直前までを綺麗に置換
pattern = r'(// ── 4\. カレンダープレビューデータ一括集計[^\n]*\n)(.*?)(echo json_encode\(\[)'
replacement = r'''\1        $eval_dates = [];
        for ($i = 0; $i < $days; $i++) {
            $eval_dates[] = date('Y-m-d', strtotime("$today +$i days"));
        }
        $calendar_previews = getCalendarPreviewsForDates($pdo, $eval_dates);

        \3'''

new_content = re.sub(pattern, replacement, content, flags=re.DOTALL)

# get_calendar_preview アクションの追加
action_pattern = r'(throw new Exception\("無効なアクションです: \{\$action\}"\);)'
action_replacement = r'''if ($action === 'get_calendar_preview') {
        $dates_param = $_GET['dates'] ?? $_GET['date'] ?? '';
        $dates = array_filter(array_map('trim', explode(',', (string)$dates_param)));
        $previews = getCalendarPreviewsForDates($pdo, $dates);
        echo json_encode([
            'success' => true,
            'calendar_previews' => $previews
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    \1'''

new_content = re.sub(action_pattern, action_replacement, new_content)

with open(path, 'w', encoding='utf-8') as f:
    f.write(new_content)

print("Updated successfully!")
