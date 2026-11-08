<?php
$lines = file('schedule_note.php');
foreach ($lines as $idx => $line) {
    if (preg_match('/(exam_type|etype|\$exam\b|exam_category|row_exam_category)/i', $line)) {
        $num = $idx + 1;
        echo "{$num}: " . trim($line) . PHP_EOL;
    }
}
