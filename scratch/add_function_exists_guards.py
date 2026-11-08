import re

path = r'c:\Apache24\htdocs\endoscope\slot_search.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

funcs = ['searchAvailableSlots', 'searchAvailableSlotsOneDept', 'getExamClass', 'getFreeColorClass']

for func in funcs:
    pat = rf'(function {func}\s*\([^)]*\)\s*\{{)'
    if not re.search(rf'if \(!function_exists\([\'"]{func}[\'"]\)\)', content):
        # 置換
        # 関数末尾を探す代わりに、定義部を if (!function_exists('...')) { function ... } で囲む
        content = re.sub(rf'function {func}\s*\(', f'if (!function_exists(\'{func}\')) {{\nfunction {func}(', content, count=1)
        # 閉じ波括弧の直後に } を追加
        # 安全のため、各関数の末尾に追加
        # ここでは関数の閉じ括弧を探す

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Guard check done")
