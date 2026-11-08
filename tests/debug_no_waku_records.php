<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/slot_helper.php';

$pdo = getDbConnection();
$raw_data = getDailyReservationsWithEffectiveSlots($pdo, 'ALL', '2026-10-26', 'all', 'all', false);

echo "raw_data count: " . count($raw_data) . "\n";
foreach ($raw_data as $row) {
    if (strpos($row['patient_name'] ?? '', '枠なし') !== false || !empty($row['is_suspended'])) {
        echo sprintf(
            "ID: %d | Time: %s | Name: %s | is_empty: %s | is_suspended: %s | override_reason: %s\n",
            $row['schedule_id'] ?? 0,
            $row['scheduled_time'] ?? '',
            $row['patient_name'] ?? '',
            empty($row['is_empty']) ? 'false' : 'true',
            empty($row['is_suspended']) ? 'false' : 'true',
            $row['override_reason'] ?? ''
        );
    }
}
