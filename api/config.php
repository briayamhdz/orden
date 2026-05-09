<?php
// ══════════════════════════════════════════════
//  PUNTO TIRE — Configuración de Base de Datos
//  Edita estos 4 valores con los datos de tu
//  hosting cPanel antes de subir.
// ══════════════════════════════════════════════

define('DB_HOST', 'localhost');          // Casi siempre es "localhost" en cPanel
define('DB_NAME', 'u745663338_ordenpt'); // Nombre de tu base de datos en cPanel
define('DB_USER', 'u745663338_admin');        // Usuario de la base de datos
define('DB_PASS', 'Scoby1299871&');       // Contraseña de la base de datos
define('DB_CHARSET', 'utf8mb4');

// ── Conexión PDO ──
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// ── Headers CORS y JSON ──
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
