<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = trim($data['email'] ?? '');
    $pass  = $data['password'] ?? '';
    if (!$email || !$pass) { echo json_encode(['ok'=>false,'error'=>'Completa todos los campos']); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email=? AND activo=1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($pass, $user['password'])) {
        echo json_encode(['ok'=>false,'error'=>'Credenciales incorrectas']); exit;
    }
    $token  = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+8 hours'));
    $db->prepare("DELETE FROM sesiones WHERE usuario_id=?")->execute([$user['id']]);
    $db->prepare("INSERT INTO sesiones(usuario_id,token,expira) VALUES(?,?,?)")->execute([$user['id'],$token,$expira]);
    unset($user['password']);
    echo json_encode(['ok'=>true,'token'=>$token,'usuario'=>$user]);
    exit;
}

if ($method === 'GET' && $action === 'check') {
    $token = getBearerToken();
    if (!$token) { echo json_encode(['ok'=>false]); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT u.id,u.nombre,u.email,u.rol FROM sesiones s JOIN usuarios u ON u.id=s.usuario_id WHERE s.token=? AND s.expira>NOW() AND u.activo=1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) { echo json_encode(['ok'=>false]); exit; }
    echo json_encode(['ok'=>true,'usuario'=>$user]);
    exit;
}

if ($method === 'POST' && $action === 'logout') {
    $token = getBearerToken();
    if ($token) { $db=getDB(); $db->prepare("DELETE FROM sesiones WHERE token=?")->execute([$token]); }
    echo json_encode(['ok'=>true]);
    exit;
}

function getBearerToken(): ?string {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return $m[1];
    return null;
}
echo json_encode(['ok'=>false,'error'=>'Acción no válida']);
