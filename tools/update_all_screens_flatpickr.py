# -*- coding: utf-8 -*-
"""
tools/update_all_screens_flatpickr.py
各画面の input[type="date"] を input[type="text" class="... onokai-date-picker"] に更新し、
特大Flatpickr（小野会カレンダー）が確実に開くようにするスクリプト
"""
import os
import re

def update_file(filepath, replacements):
    if not os.path.exists(filepath):
        print(f"File not found: {filepath}")
        return False
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    modified = False
    for target, repl in replacements:
        if target in content:
            content = content.replace(target, repl, 1)
            modified = True
        else:
            print(f"Warning: target not found in {filepath}: {target[:40]}...")

    if modified:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Updated: {filepath}")
    else:
        print(f"No changes made to: {filepath}")
    return modified

# 1. modal_edit.php
update_file('modal_edit.php', [
    (
        '<input type="date" name="exam_date" id="exam_date" class="form-control"',
        '<input type="text" name="exam_date" id="exam_date" class="form-control onokai-date-picker" autocomplete="off"'
    ),
    (
        '<input type="date" id="manual_birthday" class="form-control"',
        '<input type="text" id="manual_birthday" class="form-control onokai-date-picker" autocomplete="off"'
    )
])

# 2. stats.php
update_file('stats.php', [
    (
        '<input type="date" name="start_date" id="date_from" class="date-input ',
        '<input type="text" name="start_date" id="date_from" autocomplete="off" class="date-input onokai-date-picker '
    ),
    (
        '<input type="date" name="end_date" id="date_to" class="date-input ',
        '<input type="text" name="end_date" id="date_to" autocomplete="off" class="date-input onokai-date-picker '
    )
])

# 3. slot_search.php
update_file('slot_search.php', [
    (
        '<input type="date" name="date_from" id="date_from" class="date-input"',
        '<input type="text" name="date_from" id="date_from" autocomplete="off" class="date-input onokai-date-picker"'
    ),
    (
        '<input type="date" name="date_to" id="date_to" class="date-input"',
        '<input type="text" name="date_to" id="date_to" autocomplete="off" class="date-input onokai-date-picker"'
    )
])

# 4. slot_master_maintenance.php
update_file('slot_master_maintenance.php', [
    (
        '<input type="date" name="target_date" class="input-date" required>',
        '<input type="text" name="target_date" id="target_date" class="input-date onokai-date-picker" required autocomplete="off">'
    )
])

# 5. furiwake_daymemo.php
update_file('furiwake_daymemo.php', [
    (
        '<input type="date" id="newDate" class="form-input" required',
        '<input type="text" id="newDate" class="form-input onokai-date-picker" required autocomplete="off"'
    )
])

# 6. followup/index.php
update_file('followup/index.php', [
    (
        '<input type="date" name="date_from"',
        '<input type="text" name="date_from" class="onokai-date-picker" autocomplete="off"'
    ),
    (
        '<input type="date" name="date_to"',
        '<input type="text" name="date_to" class="onokai-date-picker" autocomplete="off"'
    )
])

# 7. followup/edit.php
update_file('followup/edit.php', [
    (
        '<input type="date" name="birthday" id="f_birthday"',
        '<input type="text" name="birthday" id="f_birthday" class="onokai-date-picker" autocomplete="off"'
    ),
    (
        '<input type="date" name="next_visit_date" id="f_next_date"',
        '<input type="text" name="next_visit_date" id="f_next_date" class="onokai-date-picker" autocomplete="off"'
    ),
    (
        '<input type="date" name="issued_date"',
        '<input type="text" name="issued_date" class="onokai-date-picker" autocomplete="off"'
    )
])

print("All screen replacements completed.")
