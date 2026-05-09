<?php
require_once __DIR__ . '/config.php';

/**
 * PUNTO TIRE — Guardar PDF en servidor
 * Recibe el PDF en base64 desde el frontend,
 * lo guarda en /pdfs/ y devuelve la URL pública.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['pdf_base64']) || empty($data['folio'])) {
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
    exit;
}

// ── Directorio de PDFs ──
$pdfDir = __DIR__ . '/../pdfs/';
if (!is_dir($pdfDir)) {
    mkdir($pdfDir, 0755, true);
}

// ── Limpiar PDFs viejos (+48 horas) para no acumular espacio ──
foreach (glob($pdfDir . '*.pdf') as $file) {
    if (filemtime($file) < time() - 172800) {
        unlink($file);
    }
}

// ── Decodificar y guardar ──
$pdfBase64 = $data['pdf_base64'];
// Quitar el header data:application/pdf;base64, si viene incluido
if (strpos($pdfBase64, ',') !== false) {
    $pdfBase64 = explode(',', $pdfBase64)[1];
}

$pdfBytes = base64_decode($pdfBase64);
if ($pdfBytes === false || strlen($pdfBytes) < 100) {
    echo json_encode(['ok' => false, 'error' => 'PDF inválido']);
    exit;
}

// Nombre único: folio + token corto
$folio   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['folio']);
$nombre  = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $data['nombre'] ?? 'cliente');
$nombre  = str_replace(' ', '_', trim($nombre));
$token   = substr(md5(uniqid()), 0, 8);
$filename = "OrdenServicio_{$folio}_{$nombre}_{$token}.pdf";

file_put_contents($pdfDir . $filename, $pdfBytes);

// ── URL pública ──
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
// Detectar la ruta base de la app automáticamente
$scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME'])); // sube de /api a /punto_tire
$baseUrl  = $protocol . '://' . $host . rtrim($scriptDir, '/');
$pdfUrl   = $baseUrl . '/pdfs/' . $filename;

echo json_encode([
    'ok'       => true,
    'url'      => $pdfUrl,
    'filename' => $filename,
]);
