<?php
$html = file_get_contents('http://localhost/endoscope/progress_monitor.php?dept=GASTRO&date=' . date('Y-m-d'));
$lines = explode("\n", $html);
foreach ($lines as $i => $line) {
    if (stripos($line, 'warning') !== false || stripos($line, 'fatal') !== false || stripos($line, 'error') !== false) {
        echo "Line " . ($i + 1) . ": " . strip_tags($line) . "\n";
    }
}
