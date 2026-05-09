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

// Auto-migrar columnas de llantas salida si no existen
function migrateColumnas(PDO $db): void {
    static $done = false;
    if($done) return;
    $cols = ['ll_s_der_del','ll_s_izq_del','ll_s_der_tras','ll_s_izq_tras'];
    foreach($cols as $col){
        $r = $db->query("SHOW COLUMNS FROM ordenes LIKE '$col'");
        if(!$r || $r->rowCount()===0){
            try{ $db->exec("ALTER TABLE ordenes ADD COLUMN $col VARCHAR(20) AFTER ll_izq_tras"); }catch(Exception $e){}
        }
    }
    $done = true;
}

// Detecta si la columna canvas_firma_entrega existe en la tabla ordenes
function tieneColumnaFirmaEntrega(PDO $db): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $r = $db->query("SHOW COLUMNS FROM ordenes LIKE 'canvas_firma_entrega'");
        $cache = ($r && $r->rowCount() > 0);
    } catch(Exception $e) { $cache = false; }
    return $cache;
}

// Agrega la columna si no existe
function agregarColumnaFirmaEntrega(PDO $db): void {
    try {
        $db->exec("ALTER TABLE ordenes ADD COLUMN canvas_firma_entrega MEDIUMTEXT AFTER canvas_firma");
    } catch(Exception $e) { /* ya existe */ }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET folio / lista ──
if ($method === 'GET') {
    requireAuth();
    $db = getDB();

    if ($action === 'folio') {
        $stmt = $db->query("SELECT valor FROM config WHERE clave='ultimo_folio'");
        $row  = $stmt->fetch();
        $next = intval($row['valor']) + 1;
        echo json_encode(['ok' => true, 'folio' => str_pad($next, 4, '0', STR_PAD_LEFT)]);
        exit;
    }

    if ($action === 'one' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT o.*, c.nombre, c.rfc, c.direccion, c.tel, c.email, v.marca, v.tipo, v.modelo, v.color, v.placas, v.serie FROM ordenes o JOIN clientes c ON c.id=o.cliente_id LEFT JOIN vehiculos v ON v.id=o.vehiculo_id WHERE o.id=?");
        $stmt->execute([$_GET['id']]);
        $orden = $stmt->fetch();
        if (!$orden) { echo json_encode(['ok'=>false,'error'=>'No encontrada']); exit; }
        $s=$db->prepare("SELECT * FROM orden_servicios WHERE orden_id=?"); $s->execute([$_GET['id']]); $orden['servicios']=$s->fetchAll();
        $i=$db->prepare("SELECT item, valor FROM orden_inspeccion WHERE orden_id=?"); $i->execute([$_GET['id']]); $orden['inspeccion']=$i->fetchAll();
        echo json_encode(['ok'=>true,'orden'=>$orden]); exit;
    }

    $where='1=1'; $params=[];
    if (!empty($_GET['q'])) {
        $q='%'.$_GET['q'].'%';
        $where="(c.nombre LIKE ? OR c.tel LIKE ? OR v.placas LIKE ? OR v.marca LIKE ?)";
        $params=[$q,$q,$q,$q];
    }
    $stmt=$db->prepare("SELECT o.id,o.folio,o.fecha,o.total_iva,o.proximo_servicio,o.recordar_servicio,c.nombre,c.tel,c.email,v.marca,v.modelo,v.placas,v.color FROM ordenes o JOIN clientes c ON c.id=o.cliente_id LEFT JOIN vehiculos v ON v.id=o.vehiculo_id WHERE $where ORDER BY o.created_at DESC LIMIT 200");
    $stmt->execute($params);
    echo json_encode(['ok'=>true,'ordenes'=>$stmt->fetchAll()]); exit;
}

// ── POST crear orden ──
if ($method === 'POST' && $action === '') {
    requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }

    $db = getDB();
    migrateColumnas($db);

    // Auto-crear columna si no existe
    if (!tieneColumnaFirmaEntrega($db)) agregarColumnaFirmaEntrega($db);
    $conFirmaEntrega = tieneColumnaFirmaEntrega($db);

    $db->beginTransaction();
    try {
        $db->exec("UPDATE config SET valor = valor + 1 WHERE clave='ultimo_folio'");
        $row   = $db->query("SELECT valor FROM config WHERE clave='ultimo_folio'")->fetch();
        $folio = str_pad($row['valor'], 4, '0', STR_PAD_LEFT);

        $stmt=$db->prepare("SELECT id FROM clientes WHERE tel=?"); $stmt->execute([$data['tel']??'']); $cliente=$stmt->fetch();
        if ($cliente) {
            $cid=$cliente['id'];
            $db->prepare("UPDATE clientes SET nombre=?,rfc=?,direccion=?,email=? WHERE id=?")->execute([$data['nombre']??'',$data['rfc']??'',$data['direccion']??'',$data['email']??'',$cid]);
        } else {
            $db->prepare("INSERT INTO clientes(nombre,rfc,direccion,tel,email) VALUES(?,?,?,?,?)")->execute([$data['nombre']??'',$data['rfc']??'',$data['direccion']??'',$data['tel']??'',$data['email']??'']);
            $cid=$db->lastInsertId();
        }

        $vid=null;
        if (!empty($data['placas'])) {
            $sv=$db->prepare("SELECT id FROM vehiculos WHERE placas=? AND cliente_id=?"); $sv->execute([$data['placas'],$cid]); $veh=$sv->fetch();
            if ($veh) {
                $vid=$veh['id'];
                $db->prepare("UPDATE vehiculos SET marca=?,tipo=?,modelo=?,color=?,serie=? WHERE id=?")->execute([$data['marca']??'',$data['tipo']??'',$data['modelo']??'',$data['color']??'',$data['serie']??'',$vid]);
            } else {
                $db->prepare("INSERT INTO vehiculos(cliente_id,marca,tipo,modelo,color,placas,serie) VALUES(?,?,?,?,?,?,?)")->execute([$cid,$data['marca']??'',$data['tipo']??'',$data['modelo']??'',$data['color']??'',$data['placas']??'',$data['serie']??'']);
                $vid=$db->lastInsertId();
            }
        }

        if ($conFirmaEntrega) {
            $sql = "INSERT INTO ordenes (folio,cliente_id,vehiculo_id,km,dado_seguridad,presion_del,presion_tras,fecha,hora_recepcion,recibido_por,hora_inicio,tecnico,llantas_usadas,ll_der_del,ll_izq_del,ll_der_tras,ll_izq_tras,ll_s_der_del,ll_s_izq_del,ll_s_der_tras,ll_s_izq_tras,presion_salida,diagnostico,hora_salida,hora_entrega,hora_recibe,recordar_servicio,proximo_servicio,subtotal_ref,subtotal_mo,total_iva,canvas_vehiculo,canvas_firma,canvas_firma_entrega) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $params = [$folio,$cid,$vid,$data['km']??null,isset($data['dado_seguridad'])?(int)$data['dado_seguridad']:0,null,null,$data['fecha']??null,$data['hora_recepcion']??null,$data['recibido_por']??null,$data['hora_inicio']??null,$data['tecnico']??null,isset($data['llantas_usadas'])?(int)$data['llantas_usadas']:0,$data['ll_der_del']??null,$data['ll_izq_del']??null,$data['ll_der_tras']??null,$data['ll_izq_tras']??null,$data['ll_s_der_del']??null,$data['ll_s_izq_del']??null,$data['ll_s_der_tras']??null,$data['ll_s_izq_tras']??null,$data['presion_salida']??null,$data['diagnostico']??null,$data['hora_salida']??null,$data['hora_entrega']??null,$data['hora_recibe']??null,isset($data['recordar_servicio'])?(int)$data['recordar_servicio']:0,$data['proximo_servicio']??null,$data['subtotal_ref']??0,$data['subtotal_mo']??0,$data['total_iva']??0,$data['canvas_vehiculo']??null,$data['canvas_firma']??null,$data['canvas_firma_entrega']??null];
        } else {
            $sql = "INSERT INTO ordenes (folio,cliente_id,vehiculo_id,km,dado_seguridad,presion_del,presion_tras,fecha,hora_recepcion,recibido_por,hora_inicio,tecnico,llantas_usadas,ll_der_del,ll_izq_del,ll_der_tras,ll_izq_tras,ll_s_der_del,ll_s_izq_del,ll_s_der_tras,ll_s_izq_tras,presion_salida,diagnostico,hora_salida,hora_entrega,hora_recibe,recordar_servicio,proximo_servicio,subtotal_ref,subtotal_mo,total_iva,canvas_vehiculo,canvas_firma) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $params = [$folio,$cid,$vid,$data['km']??null,isset($data['dado_seguridad'])?(int)$data['dado_seguridad']:0,null,null,$data['fecha']??null,$data['hora_recepcion']??null,$data['recibido_por']??null,$data['hora_inicio']??null,$data['tecnico']??null,isset($data['llantas_usadas'])?(int)$data['llantas_usadas']:0,$data['ll_der_del']??null,$data['ll_izq_del']??null,$data['ll_der_tras']??null,$data['ll_izq_tras']??null,$data['ll_s_der_del']??null,$data['ll_s_izq_del']??null,$data['ll_s_der_tras']??null,$data['ll_s_izq_tras']??null,$data['presion_salida']??null,$data['diagnostico']??null,$data['hora_salida']??null,$data['hora_entrega']??null,$data['hora_recibe']??null,isset($data['recordar_servicio'])?(int)$data['recordar_servicio']:0,$data['proximo_servicio']??null,$data['subtotal_ref']??0,$data['subtotal_mo']??0,$data['total_iva']??0,$data['canvas_vehiculo']??null,$data['canvas_firma']??null];
        }
        $db->prepare($sql)->execute($params);
        $oid=$db->lastInsertId();

        if (!empty($data['servicios'])) {
            $ins=$db->prepare("INSERT INTO orden_servicios(orden_id,cantidad,descripcion,refacciones,mano_obra) VALUES(?,?,?,?,?)");
            foreach ($data['servicios'] as $s) $ins->execute([$oid,$s['cantidad']??null,$s['descripcion']??'',$s['refacciones']??0,$s['mano_obra']??0]);
        }
        if (!empty($data['inspeccion'])) {
            $ins=$db->prepare("INSERT INTO orden_inspeccion(orden_id,item,valor) VALUES(?,?,?)");
            foreach ($data['inspeccion'] as $item=>$val) $ins->execute([$oid,$item,$val]);
        }
        if (!empty($data['dots'])) {
            $ins=$db->prepare("INSERT INTO orden_diagnostico_dots(orden_id,dot_id,nivel) VALUES(?,?,?)");
            foreach ($data['dots'] as $dotId=>$nivel) $ins->execute([$oid,$dotId,$nivel]);
        }

        $db->commit();
        echo json_encode(['ok'=>true,'folio'=>$folio,'orden_id'=>$oid,'cliente_id'=>$cid]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── POST action=update ──
if ($method === 'POST' && $action === 'update' && isset($_GET['id'])) {
    requireAuth();
    $id   = intval($_GET['id']);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !$id) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }

    $db = getDB();
    migrateColumnas($db);

    // Auto-crear columna si no existe
    if (!tieneColumnaFirmaEntrega($db)) agregarColumnaFirmaEntrega($db);
    $conFirmaEntrega = tieneColumnaFirmaEntrega($db);

    $db->beginTransaction();
    try {
        // Actualizar cliente
        $stmt=$db->prepare("SELECT id FROM clientes WHERE tel=?"); $stmt->execute([$data['tel']??'']); $cliente=$stmt->fetch();
        if ($cliente) {
            $db->prepare("UPDATE clientes SET nombre=?,rfc=?,direccion=?,email=? WHERE id=?")->execute([$data['nombre']??'',$data['rfc']??'',$data['direccion']??'',$data['email']??'',$cliente['id']]);
        }

        // Actualizar vehículo
        if (!empty($data['placas'])) {
            $sv=$db->prepare("SELECT id FROM vehiculos WHERE placas=?"); $sv->execute([$data['placas']]); $veh=$sv->fetch();
            if ($veh) {
                $db->prepare("UPDATE vehiculos SET marca=?,tipo=?,modelo=?,color=?,serie=? WHERE id=?")->execute([$data['marca']??'',$data['tipo']??'',$data['modelo']??'',$data['color']??'',$data['serie']??'',$veh['id']]);
            }
        }

        // Actualizar orden
        if ($conFirmaEntrega) {
            $db->prepare("UPDATE ordenes SET km=?,dado_seguridad=?,presion_del=NULL,presion_tras=NULL,fecha=?,hora_recepcion=?,recibido_por=?,hora_inicio=?,tecnico=?,llantas_usadas=?,ll_der_del=?,ll_izq_del=?,ll_der_tras=?,ll_izq_tras=?,ll_s_der_del=?,ll_s_izq_del=?,ll_s_der_tras=?,ll_s_izq_tras=?,presion_salida=?,diagnostico=?,hora_salida=?,hora_entrega=?,hora_recibe=?,recordar_servicio=?,proximo_servicio=?,subtotal_ref=?,subtotal_mo=?,total_iva=?,canvas_vehiculo=?,canvas_firma=?,canvas_firma_entrega=? WHERE id=?")
            ->execute([$data['km']??null,isset($data['dado_seguridad'])?(int)$data['dado_seguridad']:0,$data['fecha']??null,$data['hora_recepcion']??null,$data['recibido_por']??null,$data['hora_inicio']??null,$data['tecnico']??null,isset($data['llantas_usadas'])?(int)$data['llantas_usadas']:0,$data['ll_der_del']??null,$data['ll_izq_del']??null,$data['ll_der_tras']??null,$data['ll_izq_tras']??null,$data['ll_s_der_del']??null,$data['ll_s_izq_del']??null,$data['ll_s_der_tras']??null,$data['ll_s_izq_tras']??null,$data['presion_salida']??null,$data['diagnostico']??null,$data['hora_salida']??null,$data['hora_entrega']??null,$data['hora_recibe']??null,isset($data['recordar_servicio'])?(int)$data['recordar_servicio']:0,$data['proximo_servicio']??null,$data['subtotal_ref']??0,$data['subtotal_mo']??0,$data['total_iva']??0,$data['canvas_vehiculo']??null,$data['canvas_firma']??null,$data['canvas_firma_entrega']??null,$id]);
        } else {
            $db->prepare("UPDATE ordenes SET km=?,dado_seguridad=?,presion_del=NULL,presion_tras=NULL,fecha=?,hora_recepcion=?,recibido_por=?,hora_inicio=?,tecnico=?,llantas_usadas=?,ll_der_del=?,ll_izq_del=?,ll_der_tras=?,ll_izq_tras=?,ll_s_der_del=?,ll_s_izq_del=?,ll_s_der_tras=?,ll_s_izq_tras=?,presion_salida=?,diagnostico=?,hora_salida=?,hora_entrega=?,hora_recibe=?,recordar_servicio=?,proximo_servicio=?,subtotal_ref=?,subtotal_mo=?,total_iva=?,canvas_vehiculo=?,canvas_firma=? WHERE id=?")
            ->execute([$data['km']??null,isset($data['dado_seguridad'])?(int)$data['dado_seguridad']:0,$data['fecha']??null,$data['hora_recepcion']??null,$data['recibido_por']??null,$data['hora_inicio']??null,$data['tecnico']??null,isset($data['llantas_usadas'])?(int)$data['llantas_usadas']:0,$data['ll_der_del']??null,$data['ll_izq_del']??null,$data['ll_der_tras']??null,$data['ll_izq_tras']??null,$data['ll_s_der_del']??null,$data['ll_s_izq_del']??null,$data['ll_s_der_tras']??null,$data['ll_s_izq_tras']??null,$data['presion_salida']??null,$data['diagnostico']??null,$data['hora_salida']??null,$data['hora_entrega']??null,$data['hora_recibe']??null,isset($data['recordar_servicio'])?(int)$data['recordar_servicio']:0,$data['proximo_servicio']??null,$data['subtotal_ref']??0,$data['subtotal_mo']??0,$data['total_iva']??0,$data['canvas_vehiculo']??null,$data['canvas_firma']??null,$id]);
        }

        // Reemplazar servicios
        $db->prepare("DELETE FROM orden_servicios WHERE orden_id=?")->execute([$id]);
        if (!empty($data['servicios'])) {
            $ins=$db->prepare("INSERT INTO orden_servicios(orden_id,cantidad,descripcion,refacciones,mano_obra) VALUES(?,?,?,?,?)");
            foreach ($data['servicios'] as $s) $ins->execute([$id,$s['cantidad']??null,$s['descripcion']??'',$s['refacciones']??0,$s['mano_obra']??0]);
        }

        // Reemplazar inspección
        $db->prepare("DELETE FROM orden_inspeccion WHERE orden_id=?")->execute([$id]);
        if (!empty($data['inspeccion'])) {
            $ins=$db->prepare("INSERT INTO orden_inspeccion(orden_id,item,valor) VALUES(?,?,?)");
            foreach ($data['inspeccion'] as $item=>$val) $ins->execute([$id,$item,$val]);
        }

        // Reemplazar dots
        $db->prepare("DELETE FROM orden_diagnostico_dots WHERE orden_id=?")->execute([$id]);
        if (!empty($data['dots'])) {
            $ins=$db->prepare("INSERT INTO orden_diagnostico_dots(orden_id,dot_id,nivel) VALUES(?,?,?)");
            foreach ($data['dots'] as $dotId=>$nivel) $ins->execute([$id,$dotId,$nivel]);
        }

        $db->commit();
        echo json_encode(['ok'=>true,'id'=>$id]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── DELETE ──
if ($method === 'DELETE' && isset($_GET['id'])) {
    requireAuth();
    $db=getDB();
    $db->prepare("DELETE FROM ordenes WHERE id=?")->execute([$_GET['id']]);
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Método no soportado']);
