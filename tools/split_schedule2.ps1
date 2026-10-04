$srcPath = 'c:\Apache24\htdocs\endoscope\month_schedule2.php'
$bakPath = 'c:\Apache24\htdocs\endoscope\month_schedule2.php.bak'
$cssPath = 'c:\Apache24\htdocs\endoscope\assets\css\month_schedule2.css'
$jsPath  = 'c:\Apache24\htdocs\endoscope\assets\js\month_schedule2.js'

Copy-Item $srcPath $bakPath -Force

$lines = [System.IO.File]::ReadAllLines($srcPath, [System.Text.Encoding]::UTF8)

# CSS: lines 1070 to 1663 (0-indexed: 1069 to 1662)
# CSS content inside <style>: 1071 to 1662 (0-indexed: 1070 to 1661)
$cssLines = $lines[1070..1661]
[System.IO.File]::WriteAllLines($cssPath, $cssLines, [System.Text.Encoding]::UTF8)

# JS: lines 2535 to 3032 (0-indexed: 2534 to 3031)
# JS content inside <script>: 2536 to 3031 (0-indexed: 2535 to 3030)
$jsLines = $lines[2535..3030]
for ($i = 0; $i -lt $jsLines.Count; $i++) {
    if ($jsLines[$i] -match 'const currentScheduleDept =') {
        $jsLines[$i] = 'const currentScheduleDept = window.currentScheduleDept || "";'
    }
}
[System.IO.File]::WriteAllLines($jsPath, $jsLines, [System.Text.Encoding]::UTF8)

# Reconstruct month_schedule2.php
$newLines = [System.Collections.Generic.List[string]]::new()

# Part 1: before style (indices 0 to 1068)
for ($i = 0; $i -le 1068; $i++) {
    $newLines.Add($lines[$i])
}
# Insert CSS link
$newLines.Add('    <link rel="stylesheet" href="assets/css/month_schedule2.css">')

# Part 2: between style and script (indices 1663 to 2533)
for ($i = 1663; $i -le 2533; $i++) {
    $newLines.Add($lines[$i])
}

# Insert JS tags
$newLines.Add('<script>')
$newLines.Add('const currentScheduleDept = ''<?= htmlspecialchars($dept, ENT_QUOTES, ''UTF-8'') ?>'';')
$newLines.Add('</script>')
$newLines.Add('<script src="assets/js/month_schedule2.js"></script>')

# Part 3: after script (indices 3032 to end)
for ($i = 3032; $i -lt $lines.Count; $i++) {
    $newLines.Add($lines[$i])
}

[System.IO.File]::WriteAllLines($srcPath, $newLines, [System.Text.Encoding]::UTF8)

Write-Output "CSS size: $((Get-Item $cssPath).Length) bytes"
Write-Output "JS size: $((Get-Item $jsPath).Length) bytes"
Write-Output "New PHP line count: $($newLines.Count) lines"
