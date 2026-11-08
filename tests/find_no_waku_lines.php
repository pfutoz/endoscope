<?php
$html = file_get_contents('http://localhost/endoscope/print_schedule_daily.php?dept=ALL&date=2026-10-26&show_empty=0');
$lines = explode("\n", $html);
foreach ($lines as $i => $line) {
    if (strpos($line, '※枠なし') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
        for ($k = max(0, $i - 5); $k <= min(count($lines) - 1, $i + 5); $k++) {
            echo "  [" . ($k + 1) . "] " . trim($lines[$k]) . "\n";
        }
        echo "----------------------------------------\n";
    }
}
