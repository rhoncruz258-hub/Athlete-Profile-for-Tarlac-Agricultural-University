<?php
// db_config.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'athlete_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Test connection
    $pdo->query("SELECT 1");
    
} catch(PDOException $e) {
    // If database doesn't exist, show setup link
    if ($e->getCode() == 1049) {
        die("Database not found. Please run <a href='setup.php'>setup.php</a> first.");
    }
    die("Connection failed: " . $e->getMessage());
}
?>