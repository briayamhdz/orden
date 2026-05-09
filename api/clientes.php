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

// Detecta si existe canvas_firma_entrega
function tieneColumnaFirmaEntrega(PDO $db): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $r = $db->query("SHOW COLUMNS FROM ordenes LIKE 'canvas_firma_entrega'");
        $cache = ($r && $r->rowCount() > 0);
    } catch(Exception $e) { $cache = false; }
    return $cache;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET historial de órdenes de un cliente ──
if ($method === 'GET' && $action === 'historial') {
    requireAuth();
    $cliente_id = intval($_GET['cliente_id'] ?? 0);
    if (!$cliente_id) { echo json_encode(['ok'=>false,'error'=>'cliente_id requerido']); exit; }
    $db = getDB();

    $conFirmaEntrega = tieneColumnaFirmaEntrega($db);
    $firmaEntregaCol = $conFirmaEntrega ? ', o.canvas_firma_entrega' : '';

    $stmt = $db->prepare("
        SELECT o.id, o.folio, o.fecha, o.total_iva, o.tecnico, o.recibido_por,
               o.diagnostico, o.hora_recepcion, o.hora_entrega, o.hora_salida,
               o.hora_inicio, o.hora_recibe, o.llantas_usadas,
               o.ll_der_del, o.ll_izq_del, o.ll_der_tras, o.ll_izq_tras,
               o.ll_s_der_del, o.ll_s_izq_del, o.ll_s_der_tras, o.ll_s_izq_tras,
               o.presion_salida, o.km, o.dado_seguridad,
               o.canvas_vehiculo, o.canvas_firma,
               o.subtotal_ref, o.subtotal_mo,
               o.proximo_servicio, o.recordar_servicio
               $firmaEntregaCol,
               v.marca, v.modelo, v.color, v.placas, v.serie, v.tipo,
               c.nombre, c.tel, c.email, c.rfc, c.direccion
        FROM ordenes o
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN vehiculos v ON v.id = o.vehiculo_id
        WHERE o.cliente_id = ?
        ORDER BY o.fecha DESC, o.id DESC
        LIMIT 50
    ");
    $stmt->execute([$cliente_id]);
    $ordenes = $stmt->fetchAll();

    foreach ($ordenes as &$orden) {
        // Servicios
        $s = $db->prepare("SELECT cantidad, descripcion, refacciones, mano_obra FROM orden_servicios WHERE orden_id=?");
        $s->execute([$orden['id']]);
        $orden['servicios'] = $s->fetchAll();

        // Inspección → objeto {item: valor}
        $i = $db->prepare("SELECT item, valor FROM orden_inspeccion WHERE orden_id=?");
        $i->execute([$orden['id']]);
        $insp = [];
        foreach ($i->fetchAll() as $row) $insp[$row['item']] = $row['valor'];
        $orden['inspeccion'] = $insp;

        // Dots → objeto {dot_id: nivel}
        $d = $db->prepare("SELECT dot_id, nivel FROM orden_diagnostico_dots WHERE orden_id=?");
        $d->execute([$orden['id']]);
        $dots = [];
        foreach ($d->fetchAll() as $row) $dots[$row['dot_id']] = intval($row['nivel']);
        $orden['dots'] = $dots;

        // Asegurar que canvas_firma_entrega exista como clave aunque sea null
        if (!array_key_exists('canvas_firma_entrega', $orden)) {
            $orden['canvas_firma_entrega'] = null;
        }
    }

    echo json_encode(['ok' => true, 'ordenes' => $ordenes]);
    exit;
}

// ── GET listar clientes ──
if ($method === 'GET') {
    requireAuth();
    $db = getDB();
    $q = $_GET['q'] ?? '';
    $where = '1=1';
    $params = [];

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where = "(c.nombre LIKE ? OR c.tel LIKE ? OR c.email LIKE ? OR v.placas LIKE ? OR v.marca LIKE ? OR o.folio LIKE ?)";
        $params = [$like, $like, $like, $like, $like, $like];
    }

    $stmt = $db->prepare("
        SELECT c.id, c.nombre, c.tel, c.email, c.rfc,
               v.marca, v.modelo, v.placas,
               MAX(o.fecha) AS ultimo_servicio,
               MAX(o.proximo_servicio) AS proximo_servicio,
               MAX(o.recordar_servicio) AS recordar_servicio,
               MAX(o.folio) AS ultimo_folio,
               SUM(CASE WHEN o.id IS NOT NULL THEN 1 ELSE 0 END) AS total_ordenes,
               MAX(o.total_iva) AS ultimo_total
        FROM clientes c
        LEFT JOIN vehiculos v ON v.cliente_id = c.id
        LEFT JOIN ordenes o ON o.cliente_id = c.id
        WHERE $where
        GROUP BY c.id, v.id
        ORDER BY ultimo_servicio DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    echo json_encode(['ok' => true, 'clientes' => $stmt->fetchAll()]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Método no soportado']);
