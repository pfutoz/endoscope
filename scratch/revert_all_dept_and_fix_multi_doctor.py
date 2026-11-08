import re

path = r'c:\Apache24\htdocs\endoscope\slot_search.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. $selected_dept から 'all' を外す
content = re.sub(
    r"if \(!in_array\(\$selected_dept, \['PROCT', 'GASTRO', 'all'\], true\)\) \{ \$selected_dept = 'GASTRO'; \}",
    "if (!in_array($selected_dept, ['PROCT', 'GASTRO'], true)) { $selected_dept = 'GASTRO'; }",
    content
)

# 2. $all_doctors から 'all' キーを削除
content = re.sub(
    r"\$all_doctors\['all'\] = \['誠吾', '哲也', '義智', '慶太郎'\];\s*",
    "",
    content
)

# 3. HTMLの診療科ボタンから「全科」ボタンを削除
old_dept_html = r'''<div class="filter-row">
            <span class="group-label-btn" style="cursor:default;">診療科</span>
            <div class="btn-group-items">
                <button type="button" class="btn btn-dept <?= $selected_dept === 'PROCT' ? 'active' : '' ?>" data-dept="PROCT">肛門科</button>
                <button type="button" class="btn btn-dept <?= $selected_dept === 'GASTRO' ? 'active' : '' ?>" data-dept="GASTRO">胃腸科</button>
                <button type="button" class="btn btn-dept <?= $selected_dept === 'all' ? 'active' : '' ?>" data-dept="all">全科</button>
                <input type="hidden" name="dept" id="deptInput" value="<?= $selected_dept ?>">
            </div>
        </div>'''

new_dept_html = '''<div class="filter-row">
            <span class="group-label-btn" style="cursor:default;">診療科</span>
            <div class="btn-group-items">
                <button type="button" class="btn btn-dept <?= $selected_dept === 'PROCT' ? 'active' : '' ?>" data-dept="PROCT">肛門科</button>
                <button type="button" class="btn btn-dept <?= $selected_dept === 'GASTRO' ? 'active' : '' ?>" data-dept="GASTRO">胃腸科</button>
                <input type="hidden" name="dept" id="deptInput" value="<?= $selected_dept ?>">
            </div>
        </div>'''

content = content.replace(old_dept_html, new_dept_html)

# 4. searchAvailableSlots 関数の整理（単一科専用・高速）
old_search_func = r'''function searchAvailableSlots\(\$pdo, \$dept, \$doctors, \$exams, \$weekdays, \$months, \$date_from, \$date_to, \$is_month_mode, &\$executed_sql\) \{.*?return \$all_results;\s*\}'''

new_search_func = '''function searchAvailableSlots($pdo, $dept, $doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, &$executed_sql) {
    global $doctor_meta_map, $week_ja;
    $week_ja_map = ['日', '月', '火', '水', '木', '金', '土'];
    
    // 対象科の医師一覧
    $dept_doctors = getDoctorsForDept($pdo, $dept);
    $target_doctors = [];
    if (in_array('all', $doctors, true)) {
        $target_doctors = ['all'];
    } else {
        $target_doctors = array_values(array_intersect($doctors, $dept_doctors));
        if (empty($target_doctors)) {
            $executed_sql = "-- 対象科に一致する医師がありません";
            return [];
        }
    }
    
    $res = searchAvailableSlotsOneDept($pdo, $dept, $target_doctors, $exams, $weekdays, $months, $date_from, $date_to, $is_month_mode, $executed_sql);
    
    $dep_name = ($dept === 'GASTRO') ? '胃腸科' : '肛門科';
    $all_results = [];
    foreach ($res as $row) {
        $d_name = $row['doctor_name'];
        $meta = $doctor_meta_map[$d_name] ?? [
            'dept' => $dept,
            'dept_name' => $dep_name,
            'short' => mb_substr($d_name, 0, 1),
            'cls' => '',
            'color' => '#334155',
            'badge_cls' => 'bg-slate',
            'dept_tag_cls' => ($dept === 'GASTRO') ? 'tag-gastro' : 'tag-proct'
        ];
        $dow = (int)date('w', strtotime($row['exam_date']));
        $parts = explode('-', $row['exam_date']);
        $y = $parts[0] ?? date('Y');
        $m = (int)($parts[1] ?? date('m'));
        
        $all_results[] = [
            'exam_date' => $row['exam_date'],
            'year' => (int)$y,
            'month' => $m,
            'dow' => $dow,
            'dow_ja' => $week_ja_map[$dow] ?? '',
            'department_code' => $dept,
            'department_name' => $dep_name,
            'doctor_name' => $d_name,
            'doctor_short' => $meta['short'],
            'doctor_cls' => $meta['cls'],
            'doctor_color' => $meta['color'],
            'doctor_badge_cls' => $meta['badge_cls'],
            'dept_tag_cls' => $meta['dept_tag_cls'],
            'exam_type' => $row['exam_type'],
            'exam_cls' => getExamClass($row['exam_type']),
            'exam_icon' => getExamIcon($row['exam_type']),
            'free_slots' => (int)$row['free_slots'],
            'max_slots' => (int)$row['max_slots'],
            'used_slots' => (int)$row['max_slots'] - (int)$row['free_slots'],
            'free_color_cls' => getFreeColorClass((int)$row['free_slots']),
            'jump_url' => "month_schedule.php?dept=" . urlencode($dept) . "&year={$y}&month={$m}&date=" . urlencode($row['exam_date']) . "&display_mode=remain"
        ];
    }
    
    $total_count = count($all_results);
    if ($total_count > 300) {
        $executed_sql .= "\\n-- 空き枠検索結果が300件を超えました（{$total_count}件）";
        return ['error' => 'too_many', 'count' => $total_count];
    }
    
    usort($all_results, function($a, $b) {
        if ($a['exam_date'] !== $b['exam_date']) return strcmp($a['exam_date'], $b['exam_date']);
        if ($a['doctor_name'] !== $b['doctor_name']) return strcmp($a['doctor_name'], $b['doctor_name']);
        $order = ['GIF' => 0, 'エコー' => 1, 'TCF' => 2];
        return ($order[$a['exam_type']] ?? 99) - ($order[$b['exam_type']] ?? 99);
    });
    
    return $all_results;
}'''

content = re.sub(old_search_func, new_search_func, content, flags=re.DOTALL)

# 5. initButtons の複数選択ロジックを全面刷新（全員選択時に勝手に消去されるバグを根絶）
old_init_buttons = r'''function initButtons\(groupName, containerSelector\) \{.*?triggerConditionChange\(\);\s*\};\s*\}\);\s*\}'''

new_init_buttons = '''function initButtons(groupName, containerSelector) {
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    container.querySelectorAll('.btn[data-group="' + groupName + '"]').forEach(function(btn) {
        btn.onclick = function() {
            var val = this.dataset.value;
            var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
            var allBtn = container.querySelector('.btn[data-value="all"]');
            var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
            
            if (val === 'all') {
                // 「全医師/全種別/全曜日」ボタンをクリックした時
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                allBtn.classList.add('active-all');
                input.value = 'all';
                triggerConditionChange();
                return;
            }
            
            // 個別ボタンをクリックした時
            if (allBtn.classList.contains('active-all')) {
                // 「全」選択状態から個別ボタンを押した場合、そのボタンだけを選択！
                allBtn.classList.remove('active-all');
                itemBtns.forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
            } else {
                // 個別選択中ならトグル（複数選択の追加/解除）
                this.classList.toggle('active');
            }
            
            var selected = [];
            itemBtns.forEach(function(b) {
                if (b.classList.contains('active')) selected.push(b.dataset.value);
            });
            
            if (selected.length === 0) {
                // すべて解除されて0人になった場合は自動で「全選択」に戻す
                allBtn.classList.add('active-all');
                input.value = 'all';
            } else {
                // 1人以上選択されている場合はその選択を維持（※全員選ばれても勝手に解除しない！）
                allBtn.classList.remove('active-all');
                input.value = selected.join(',');
            }

            triggerConditionChange();
        };
    });
}'''

content = re.sub(old_init_buttons, new_init_buttons, content, flags=re.DOTALL)

# 6. toggleLabelGroup の改善
old_toggle_group = r'''function toggleLabelGroup\(groupName\) \{.*?triggerConditionChange\(\);\s*\}'''

new_toggle_group = '''function toggleLabelGroup(groupName) {
    var containerSelector = '#' + (groupName === 'weekday' ? 'weekday' : groupName) + 'Container';
    var container = document.querySelector(containerSelector);
    if (!container) return;
    
    var input = document.getElementById(groupName === 'weekday' ? 'weekdaysInput' : groupName + 'Input');
    var allBtn = container.querySelector('.btn[data-value="all"]');
    var itemBtns = container.querySelectorAll('.btn[data-group="' + groupName + '"]:not([data-value="all"])');
    
    var isCurrentlyAll = allBtn.classList.contains('active-all');
    
    if (isCurrentlyAll) {
        // 「全」状態からトグル押下 ➔ 全員の個別ボタンをONにして複数選択状態にする
        allBtn.classList.remove('active-all');
        var selected = [];
        itemBtns.forEach(function(b) { 
            b.classList.add('active'); 
            selected.push(b.dataset.value);
        });
        input.value = selected.join(',');
    } else {
        // 個別選択中からトグル押下 ➔ 「全」に戻す
        itemBtns.forEach(function(b) { b.classList.remove('active'); });
        allBtn.classList.add('active-all');
        input.value = 'all';
    }

    triggerConditionChange();
}'''

content = re.sub(old_toggle_group, new_toggle_group, content, flags=re.DOTALL)

# 7. 科切り替え時のJS（全科ロジックを撤廃し、各科の医師を展開）
old_dept_js = r'''document\.querySelectorAll\('\.btn-dept'\)\.forEach\(function\(btn\) \{.*?triggerConditionChange\(\);\s*\}\);\s*\}\);'''

new_dept_js = '''document.querySelectorAll('.btn-dept').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var dept = this.dataset.dept;
        document.querySelectorAll('.btn-dept').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        document.getElementById('deptInput').value = dept;
        
        var container = document.getElementById('doctorContainer');
        var html = '<button type="button" class="btn active-all" data-group="doctor" data-value="all">全医師</button>';
        (allDoctors[dept] || []).forEach(function(doc) {
            html += '<button type="button" class="btn" data-group="doctor" data-value="' + doc + '">' + doc + '</button>';
        });
        container.innerHTML = html;
        document.getElementById('doctorInput').value = 'all';
        initButtons('doctor', '#doctorContainer');

        triggerConditionChange();
    });
});'''

content = re.sub(old_dept_js, new_dept_js, content, flags=re.DOTALL)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Reverted all dept and fixed multi doctor selection successfully!")
