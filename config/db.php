<?php


define('DB_HOST', 'sql211.infinityfree.com'); 
define('DB_NAME', 'if0_41672649_vitalzo'); 
define('DB_USER', 'if0_41672649');              
define('DB_PASS', 'kevinamezquita3');             

function getDBConnection() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // En producción es mejor no mostrar detalles técnicos, pero para pruebas:
        die("Error de conexión al servidor de InfinityFree: " . $e->getMessage());
    }
}

// Iniciar sesión global
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>