# -*- coding: utf-8 -*-
"""
tools/apply_flatpickr.py
全画面への特大Flatpickr（小野会カレンダー）自動バインドスクリプト
"""
import os
import re

def update_modal_edit():
    path = 'modal_edit.php'
    if not os.path.exists(path):
        return
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    # 1. head 内にアセット追加
    assets_tag = """    <!-- 🖨️ 完全ローカル・オフライン動作 特大Flatpickr -->
    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js"></script>
</head>"""

    if 'onokai_flatpickr.js' not in content and '</head>' in content:
        content = content.replace('</head>', assets_tag, 1)

    # 2. 初期化スクリプト追加
    init_script = """
<script>
// 🖨️ 特大Flatpickr小野会カレンダーバインド (検査日 & 生年月日)
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('#exam_date', {
            onChange: function(dates, dateStr) {
                if (typeof handleDateChange === 'function') {
                    handleDateChange();
                }
            }
        });
        OnokaiDatePicker.attach('#manual_birthday', {
            onChange: function(dates, dateStr) {
                if (typeof syncManualToHidden === 'function') {
                    syncManualToHidden();
                }
            }
        });
    }
});
</script>
</body>"""

    if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
        content = content.replace('</body>', init_script, 1)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("✓ modal_edit.php updated")

def update_stats():
    path = 'stats.php'
    if not os.path.exists(path):
        return
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    assets_tag = """    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js"></script>
</head>"""

    if 'onokai_flatpickr.js' not in content and '</head>' in content:
        content = content.replace('</head>', assets_tag, 1)

    init_script = """
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('#date_from');
        OnokaiDatePicker.attach('#date_to');
    }
});
</script>
</body>"""

    if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
        content = content.replace('</body>', init_script, 1)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("✓ stats.php updated")

def update_slot_search():
    path = 'slot_search.php'
    if not os.path.exists(path):
        return
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    assets_tag = """    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js"></script>
</head>"""

    if 'onokai_flatpickr.js' not in content and '</head>' in content:
        content = content.replace('</head>', assets_tag, 1)

    init_script = """
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('#date_from');
        OnokaiDatePicker.attach('#date_to');
    }
});
</script>
</body>"""

    if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
        content = content.replace('</body>', init_script, 1)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("✓ slot_search.php updated")

def update_slot_master_maintenance():
    path = 'slot_master_maintenance.php'
    if not os.path.exists(path):
        return
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    assets_tag = """    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js"></script>
</head>"""

    if 'onokai_flatpickr.js' not in content and '</head>' in content:
        content = content.replace('</head>', assets_tag, 1)

    init_script = """
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('input[name="target_date"]');
    }
});
</script>
</body>"""

    if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
        content = content.replace('</body>', init_script, 1)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("✓ slot_master_maintenance.php updated")

def update_furiwake_daymemo():
    path = 'furiwake_daymemo.php'
    if not os.path.exists(path):
        return
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    assets_tag = """    <link rel="stylesheet" href="assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/onokai_flatpickr.css">
    <script src="assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="assets/vendor/flatpickr/ja.js"></script>
    <script src="assets/js/onokai_flatpickr.js"></script>
</head>"""

    if 'onokai_flatpickr.js' not in content and '</head>' in content:
        content = content.replace('</head>', assets_tag, 1)

    init_script = """
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('#newDate');
    }
});
</script>
</body>"""

    if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
        content = content.replace('</body>', init_script, 1)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("✓ furiwake_daymemo.php updated")

def update_followup():
    # followup/index.php
    p_idx = 'followup/index.php'
    if os.path.exists(p_idx):
        with open(p_idx, 'r', encoding='utf-8') as f:
            content = f.read()
        assets_tag = """    <link rel="stylesheet" href="../assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="../assets/css/onokai_flatpickr.css">
    <script src="../assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="../assets/vendor/flatpickr/ja.js"></script>
    <script src="../assets/js/onokai_flatpickr.js"></script>
</head>"""
        if 'onokai_flatpickr.js' not in content and '</head>' in content:
            content = content.replace('</head>', assets_tag, 1)
        init_script = """
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('input[name="date_from"]');
        OnokaiDatePicker.attach('input[name="date_to"]');
    }
});
</script>
</body>"""
        if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
            content = content.replace('</body>', init_script, 1)
        with open(p_idx, 'w', encoding='utf-8') as f:
            f.write(content)
        print("✓ followup/index.php updated")

    # followup/edit.php
    p_edit = 'followup/edit.php'
    if os.path.exists(p_edit):
        with open(p_edit, 'r', encoding='utf-8') as f:
            content = f.read()
        assets_tag = """    <link rel="stylesheet" href="../assets/vendor/flatpickr/flatpickr.min.css">
    <link rel="stylesheet" href="../assets/css/onokai_flatpickr.css">
    <script src="../assets/vendor/flatpickr/flatpickr.min.js"></script>
    <script src="../assets/vendor/flatpickr/ja.js"></script>
    <script src="../assets/js/onokai_flatpickr.js"></script>
</head>"""
        if 'onokai_flatpickr.js' not in content and '</head>' in content:
            content = content.replace('</head>', assets_tag, 1)
        init_script = """
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (window.OnokaiDatePicker) {
        OnokaiDatePicker.attach('#f_birthday');
        OnokaiDatePicker.attach('#f_next_date');
        OnokaiDatePicker.attach('input[name="issued_date"]');
    }
});
</script>
</body>"""
        if 'OnokaiDatePicker.attach' not in content and '</body>' in content:
            content = content.replace('</body>', init_script, 1)
        with open(p_edit, 'w', encoding='utf-8') as f:
            f.write(content)
        print("✓ followup/edit.php updated")

if __name__ == '__main__':
    update_modal_edit()
    update_stats()
    update_slot_search()
    update_slot_master_maintenance()
    update_furiwake_daymemo()
    update_followup()
    print("=== All updates completed successfully ===")
