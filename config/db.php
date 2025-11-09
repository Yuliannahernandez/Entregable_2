<?php
$host = '127.0.0.1';
$port = '3306';        
$db   = 'reloj_marcador_db'; 
$user = 'root';       
$pass = '1234';     

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo "Error de conexión a la BD: " . $e->getMessage();
    exit;
}
?>