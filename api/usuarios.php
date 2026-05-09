<?php
require_once __DIR__ . '/config.php';

// Verificar autenticación y rol admin
function requireAdmin(): array {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = null;
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = $m[1];
    if (!$token) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT u.* FROM sesiones s JOIN usuarios u ON u.id=s.usuario_id WHERE s.token=? AND s.expira>NOW() AND u.activo=1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Sesión expirada']); exit; }
    if ($user['rol'] !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sin permiso']); exit; }
    return $user;
}

function requireAuth(): array {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = null;
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = $m[1];
    if (!$token) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT u.* FROM sesiones s JOIN usuarios u ON u.id=s.usuario_id WHERE s.token=? AND s.expira>NOW() AND u.activo=1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Sesión expirada']); exit; }
    return $user;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET lista de tecnicos (para el selector de orden - cualquier usuario autenticado)
if ($method === 'GET' && $action === 'tecnicos') {
    requireAuth();
    $db = getDB();
    $stmt = $db->query("SELECT id, nombre FROM usuarios WHERE rol='tecnico' AND activo=1 ORDER BY nombre");
    echo json_encode(['ok'=>true,'tecnicos'=>$stmt->fetchAll()]);
    exit;
}

// GET listar todos los usuarios (solo admin)
if ($method === 'GET') {
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT id,nombre,email,rol,activo,created_at FROM usuarios ORDER BY created_at DESC");
    echo json_encode(['ok'=>true,'usuarios'=>$stmt->fetchAll()]);
    exit;
}

// POST crear usuario (solo admin)
if ($method === 'POST' && $action === 'crear') {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    $nombre = trim($data['nombre'] ?? '');
    $email  = trim($data['email'] ?? '');
    $pass   = $data['password'] ?? '';
    $rol    = $data['rol'] ?? 'viewer';
    if (!$nombre || !$email || !$pass) { echo json_encode(['ok'=>false,'error'=>'Campos requeridos']); exit; }
    if (!in_array($rol, ['admin','tecnico','viewer'])) { echo json_encode(['ok'=>false,'error'=>'Rol inválido']); exit; }
    $db = getDB();
    $check = $db->prepare("SELECT id FROM usuarios WHERE email=?"); $check->execute([$email]);
    if ($check->fetch()) { echo json_encode(['ok'=>false,'error'=>'El email ya está registrado']); exit; }
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO usuarios(nombre,email,password,rol,activo) VALUES(?,?,?,?,1)")->execute([$nombre,$email,$hash,$rol]);
    echo json_encode(['ok'=>true,'id'=>$db->lastInsertId()]);
    exit;
}

// PUT editar usuario (solo admin)
if ($method === 'PUT') {
    requireAdmin();
    $id   = intval($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID requerido']); exit; }
    $db = getDB();
    $fields = [];$params = [];
    if (!empty($data['nombre']))  { $fields[]='nombre=?';  $params[]=$data['nombre']; }
    if (!empty($data['email']))   { $fields[]='email=?';   $params[]=$data['email']; }
    if (!empty($data['password'])){ $fields[]='password=?';$params[]=password_hash($data['password'],PASSWORD_BCRYPT); }
    if (isset($data['rol']))      { $fields[]='rol=?';     $params[]=$data['rol']; }
    if (isset($data['activo']))   { $fields[]='activo=?';  $params[]=(int)$data['activo']; }
    if (empty($fields)) { echo json_encode(['ok'=>false,'error'=>'Nada que actualizar']); exit; }
    $params[]=$id;
    $db->prepare("UPDATE usuarios SET ".implode(',',$fields)." WHERE id=?")->execute($params);
    echo json_encode(['ok'=>true]);
    exit;
}

// DELETE eliminar usuario (solo admin)
if ($method === 'DELETE') {
    requireAdmin();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID requerido']); exit; }
    $db = getDB();
    // No eliminar el único admin
    $admins = $db->query("SELECT COUNT(*) as c FROM usuarios WHERE rol='admin' AND activo=1")->fetch();
    $target = $db->prepare("SELECT rol FROM usuarios WHERE id=?"); $target->execute([$id]); $t=$target->fetch();
    if ($t && $t['rol']==='admin' && $admins['c']<=1) {
        echo json_encode(['ok'=>false,'error'=>'No puedes eliminar el único administrador']); exit;
    }
    $db->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Método no soportado']);
