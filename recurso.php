<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

// Entrega un recurso descargable solo a quien tiene acceso vigente al curso (o desde el panel).
$slug = (string) ($_GET['c'] ?? '');
$curso = curso($slug);
$email = sesion();
if (!$curso || !(es_admin() || ($email && puede_ver($email, $slug)))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No tenés acceso a este archivo. Entrá a tus cursos y volvé a intentarlo.');
}

// Archivo de una lección (l) o de un módulo bono (m).
$archivos = [];
if (isset($_GET['m'])) {
    foreach ($curso['modulos'] ?? [] as $m) {
        if ($m['id'] === (string) $_GET['m'] && !empty($m['bono'])) {
            $archivos = $m['recursos'] ?? [];
        }
    }
} else {
    $archivos = curso_leccion($curso, (string) ($_GET['l'] ?? ''))['recursos'] ?? [];
}
$recurso = null;
foreach ($archivos as $r) {
    if ($r['id'] === (string) ($_GET['r'] ?? '')) {
        $recurso = $r;
    }
}
$ruta = $recurso ? recursos_dir() . '/' . basename((string) $recurso['archivo']) : '';
if (!$recurso || !is_file($ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No encontramos este archivo.');
}

$nombre = (string) $recurso['nombre'];
$ascii = preg_replace('/[^\w.\- ]/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre)) ?: 'archivo';
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
