<?php
$html = file_get_contents('http://localhost/endoscope/print_schedule_daily.php?dept=GASTRO&date=' . date('Y-m-d'));
$lines = explode("\n", $html);
foreach ($lines as $i => $line) {
    if (stripos($line, 'warning') !== false) {
        echo "Line " . ($i + 1) . ": " . strip_tags($line) . "\n";
    }
}
