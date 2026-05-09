<?php
require_once __DIR__ . '/config.php';

function requireAuth(): void {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = null;
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = $m[1];
    if (!$token) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT u.id FROM sesiones s JOIN usuarios u ON u.id=s.usuario_id WHERE s.token=? AND s.expira>NOW() AND u.activo=1");
    $stmt->execute([$token]);
    if (!$stmt->fetch()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Sesión expirada']); exit; }
}

function ensureTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS orden_fotos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        orden_id     INT NOT NULL,
        foto_data    MEDIUMTEXT NOT NULL,
        posicion     TINYINT DEFAULT 0,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (orden_id) REFERENCES ordenes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Agregar columna anotaciones si no existe
    try {
        $r = $db->query("SHOW COLUMNS FROM orden_fotos LIKE 'anotaciones'");
        if (!$r || $r->rowCount() === 0) {
            $db->exec("ALTER TABLE orden_fotos ADD COLUMN anotaciones TEXT AFTER foto_data");
        }
    } catch(Exception $e) {}

    // Tabla de anotaciones de fotos por orden (un registro por orden)
    $db->exec("CREATE TABLE IF NOT EXISTS orden_fotos_meta (
        orden_id     INT PRIMARY KEY,
        anotaciones  TEXT,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (orden_id) REFERENCES ordenes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$method = $_SERVER['REQUEST_METHOD'];

// POST — guardar fotos + anotaciones
if ($method === 'POST') {
    requireAuth();
    $data      = json_decode(file_get_contents('php://input'), true);
    $orden_id  = intval($data['orden_id'] ?? 0);
    $fotos     = $data['fotos']             ?? [];
    $anotacion = trim($data['fotos_anotaciones'] ?? '');

    if (!$orden_id) { echo json_encode(['ok'=>false,'error'=>'orden_id requerido']); exit; }

    $db = getDB();
    ensureTable($db);

    // Guardar fotos
    $db->prepare("DELETE FROM orden_fotos WHERE orden_id=?")->execute([$orden_id]);
    if (!empty($fotos)) {
        $ins = $db->prepare("INSERT INTO orden_fotos(orden_id, foto_data, posicion) VALUES(?,?,?)");
        foreach ($fotos as $i => $foto) {
            if (!$foto) continue;
            $ins->execute([$orden_id, $foto, $i]);
        }
    }

    // Guardar anotaciones
    $db->prepare("INSERT INTO orden_fotos_meta(orden_id, anotaciones) VALUES(?,?) ON DUPLICATE KEY UPDATE anotaciones=VALUES(anotaciones)")
       ->execute([$orden_id, $anotacion]);

    echo json_encode(['ok'=>true, 'guardadas'=>count($fotos)]);
    exit;
}

// GET — obtener fotos + anotaciones
if ($method === 'GET' && isset($_GET['orden_id'])) {
    requireAuth();
    $orden_id = intval($_GET['orden_id']);
    $db = getDB();
    ensureTable($db);

    $stmt = $db->prepare("SELECT foto_data FROM orden_fotos WHERE orden_id=? ORDER BY posicion ASC");
    $stmt->execute([$orden_id]);
    $fotos = array_column($stmt->fetchAll(), 'foto_data');

    $meta = $db->prepare("SELECT anotaciones FROM orden_fotos_meta WHERE orden_id=?");
    $meta->execute([$orden_id]);
    $row = $meta->fetch();
    $anotaciones = $row ? ($row['anotaciones'] ?? '') : '';

    echo json_encode(['ok'=>true, 'fotos'=>$fotos, 'fotos_anotaciones'=>$anotaciones]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Método no soportado']);
