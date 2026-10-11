<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Entrega el video de una lección alojado en el servidor (privado/videos/), solo a quien
 * tiene acceso vigente al curso (o desde el panel). Soporta pedidos parciales (Range):
 * así el reproductor puede adelantar y retroceder sin descargar el archivo entero.
 */
$slug = (string) ($_GET['c'] ?? '');
$curso = curso($slug);
$email = sesion();
if (!$curso || !(es_admin() || ($email && puede_ver($email, $slug)))) {
    http_response_code(403);
    exit;
}
$leccion = curso_leccion($curso, (string) ($_GET['l'] ?? ''));
$archivo = video_archivo((string) ($leccion['video'] ?? ''));
$ruta = $archivo !== '' ? videos_dir() . '/' . $archivo : '';
if ($ruta === '' || !is_file($ruta)) {
    http_response_code(404);
    exit;
}

$tamano = (int) filesize($ruta);
$inicio = 0;
$fin = $tamano - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {          // «bytes=-500»: los últimos 500 bytes
        $inicio = max(0, $tamano - (int) $m[2]);
    } else {
        $inicio = (int) $m[1];
        $fin = $m[2] !== '' ? min((int) $m[2], $tamano - 1) : $fin;
    }
    if ($inicio > $fin || $inicio >= $tamano) {
        http_response_code(416);
        header("Content-Range: bytes */$tamano");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $inicio-$fin/$tamano");
}

header('Content-Type: ' . VIDEO_TIPOS[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))]);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($fin - $inicio + 1));
header('Content-Disposition: inline');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

// Se libera la sesión de PHP (si la hubiera) y se envía en bloques de 1 MB.
@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}
$fh = fopen($ruta, 'rb');
fseek($fh, $inicio);
$quedan = $fin - $inicio + 1;
while ($quedan > 0 && !feof($fh) && !connection_aborted()) {
    $bloque = fread($fh, (int) min(1048576, $quedan));
    if ($bloque === false || $bloque === '') {
        break;
    }
    echo $bloque;
    flush();
    $quedan -= strlen($bloque);
}
fclose($fh);
