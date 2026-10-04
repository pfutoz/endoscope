<?php
echo "<h2>🕒 サーバー時刻確認テスト</h2>";
echo "<p><strong>現在のタイムゾーン設定 (date_default_timezone_get):</strong> " . date_default_timezone_get() . "</p>";
echo "<p><strong>date('Y-m-d H:i:s'):</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><strong>gmdate('Y-m-d H:i:s') (UTC基準):</strong> " . gmdate('Y-m-d H:i:s') . "</p>";
echo "<p><strong>リクエスト時刻 (\$_SERVER['REQUEST_TIME']):</strong> " . date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) . "</p>";
?>