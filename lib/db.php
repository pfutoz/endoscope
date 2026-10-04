<?php
/**
 * lib/db.php - データベース接続
 */

function getDbConnection() {
    $host = 'localhost';
    $dbname = 'endoscope';
    $user = 'postgres';
    $password = 'postgres';

    try {
        $dsn = "pgsql:host=$host;dbname=$dbname;";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}