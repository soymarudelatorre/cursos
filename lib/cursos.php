<?php
declare(strict_types=1);

/*
 * Catálogo de cursos (privado/cursos.json).
 *
 * Curso:   slug => [titulo, descripcion, acceso ('anual'|'ilimitado'), productos (IDs o SKU
 *          de WooCommerce), publicado, creado, modulos[]]
 * Módulo:  [id, titulo, lecciones[]]
 * Lección: [id, titulo, video (URL de YouTube o Vimeo, o «archivo:nombre.mp4» del servidor), texto, recursos[]]
 * Recurso: [id, nombre (el que ve la persona), archivo (nombre en privado/recursos/), tamano]
 */

const CURSOS_ARCHIVO = 'cursos.json';
const CURSOS_DIR_RECURSOS = 'recursos';
const CURSOS_EXTENSIONES = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'webp', 'mp3'];

function cursos_todos(): array
{
    return datos_json(CURSOS_ARCHIVO);
}

function curso(string $slug): ?array
{
    $c = cursos_todos()[$slug] ?? null;
    return $c ? $c + ['slug' => $slug] : null;
}

/** Modifica el catálogo con bloqueo. $cambiar recibe y devuelve el arreglo de cursos. */
function cursos_cambiar(callable $cambiar): void
{
    datos_json(CURSOS_ARCHIVO, fn (array $cursos) => [$cambiar($cursos), null]);
}

/** Convierte un título en un identificador para la URL: «Excel desde cero» → excel-desde-cero. */
function crear_slug(string $titulo, array $existentes): string
{
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-',
        (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo)), '-'));
    $s = substr($s !== '' ? $s : 'curso', 0, 60);
    $base = $s;
    for ($i = 2; isset($existentes[$s]); $i++) {
        $s = "$base-$i";
    }
    return $s;
}

/** Todas las lecciones del curso en orden, con el título del módulo. */
function curso_lecciones(array $curso): array
{
    $lista = [];
    foreach ($curso['modulos'] ?? [] as $m) {
        foreach ($m['lecciones'] ?? [] as $l) {
            $lista[] = $l + ['modulo' => $m['titulo'], 'modulo_id' => $m['id']];
        }
    }
    return $lista;
}

function curso_leccion(array $curso, string $id): ?array
{
    foreach (curso_lecciones($curso) as $l) {
        if ($l['id'] === $id) {
            return $l;
        }
    }
    return null;
}

/** IDs de producto/SKU de WooCommerce → slug del curso. */
function cursos_por_producto(): array
{
    $mapa = [];
    foreach (cursos_todos() as $slug => $c) {
        foreach ($c['productos'] ?? [] as $p) {
            $mapa[(string) $p] = $slug;
        }
    }
    return $mapa;
}

// ── Videos ────────────────────────────────────────────────────────────────

/**
 * URL del reproductor para insertar en la página, a partir del enlace que se pega en el panel.
 * YouTube: youtu.be/ID, youtube.com/watch?v=ID, /embed/ID, /shorts/ID, /live/ID.
 * Vimeo: vimeo.com/NUM o vimeo.com/NUM/HASH (videos no listados).
 * Devuelve '' si no se reconoce.
 */
function video_embed(string $url): string
{
    $url = trim($url);
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', $url, $m)) {
        // youtube-nocookie: no guarda cookies de seguimiento hasta que la persona da play.
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)(?:/([0-9a-f]+))?~', $url, $m)) {
        $h = $m[2] ?? '';
        if ($h === '' && preg_match('~[?&]h=([0-9a-f]+)~', $url, $mh)) {
            $h = $mh[1];
        }
        return 'https://player.vimeo.com/video/' . $m[1] . ($h !== '' ? '?h=' . $h : '');
    }
    return '';
}

// ── Videos alojados en el propio servidor (privado/videos/) ──────────────
// Se suben por FTP o con el administrador de archivos del hosting y se entregan con
// video.php solo a quien tiene acceso vigente: copiar el enlace no sirve sin sesión.

const CURSOS_DIR_VIDEOS = 'videos';
const VIDEO_PREFIJO = 'archivo:';
const VIDEO_TIPOS = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm'];

function videos_dir(): string
{
    $dir = PRIVADO . '/' . CURSOS_DIR_VIDEOS;
    asegurar_directorio($dir);
    return $dir;
}

/** Videos subidos a la carpeta privada: nombre => tamaño en bytes, ordenados por nombre. */
function videos_disponibles(): array
{
    $lista = [];
    foreach (scandir(videos_dir()) ?: [] as $f) {
        if (isset(VIDEO_TIPOS[strtolower(pathinfo($f, PATHINFO_EXTENSION))]) && is_file(videos_dir() . "/$f")) {
            $lista[$f] = (int) filesize(videos_dir() . "/$f");
        }
    }
    uksort($lista, 'strnatcasecmp');
    return $lista;
}

/** Nombre del archivo si la lección usa un video del servidor; '' si usa YouTube/Vimeo o no tiene video. */
function video_archivo(string $video): string
{
    return str_starts_with($video, VIDEO_PREFIJO) ? basename(substr($video, strlen(VIDEO_PREFIJO))) : '';
}

// ── Recursos descargables ─────────────────────────────────────────────────

function recursos_dir(): string
{
    $dir = PRIVADO . '/' . CURSOS_DIR_RECURSOS;
    asegurar_directorio($dir);
    return $dir;
}

/**
 * Guarda un archivo subido desde el panel y devuelve los datos del recurso.
 * Lanza excepción con un mensaje para mostrar si algo falla.
 */
function recurso_guardar_subida(array $archivo): array
{
    $codigo = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($codigo === UPLOAD_ERR_INI_SIZE || $codigo === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('El archivo supera el máximo que permite el servidor (' . ini_get('upload_max_filesize') . ').');
    }
    if ($codigo !== UPLOAD_ERR_OK || !is_uploaded_file((string) $archivo['tmp_name'])) {
        throw new RuntimeException('No se recibió el archivo. Volvé a elegirlo.');
    }
    $nombre = trim(preg_replace('/[\x00-\x1F\/\\\\]+/', '', (string) $archivo['name']));
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (!in_array($ext, CURSOS_EXTENSIONES, true)) {
        throw new RuntimeException('Tipo de archivo no permitido. Se aceptan: ' . implode(', ', CURSOS_EXTENSIONES) . '.');
    }
    $id = nuevo_id();
    $destino = recursos_dir() . "/$id.$ext";
    if (!move_uploaded_file((string) $archivo['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar el archivo en el servidor (revisá los permisos de la carpeta privada).');
    }
    return ['id' => $id, 'nombre' => mb_substr($nombre, 0, 150), 'archivo' => "$id.$ext", 'tamano' => (int) filesize($destino)];
}

function recurso_borrar_archivo(array $recurso): void
{
    $ruta = recursos_dir() . '/' . basename((string) $recurso['archivo']);
    if (is_file($ruta)) {
        unlink($ruta);
    }
}

function tamano_legible(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

/** Texto de la lección: párrafos separados por una línea en blanco; los enlaces se vuelven clicables. */
function texto_html(string $texto): string
{
    $html = '';
    foreach (preg_split('/\R{2,}/', trim($texto)) as $parrafo) {
        if (trim($parrafo) === '') {
            continue;
        }
        $p = preg_replace('~https?://[^\s<]+[^\s<.,;:!?)\]]~', '<a href="$0" target="_blank" rel="noopener">$0</a>', e($parrafo));
        $html .= '<p>' . nl2br((string) $p, false) . '</p>';
    }
    return $html;
}

// ── Certificado ───────────────────────────────────────────────────────────

const CURSOS_DIR_CERTIFICADOS = 'certificados';

function certificados_dir(): string
{
    $dir = PRIVADO . '/' . CURSOS_DIR_CERTIFICADOS;
    asegurar_directorio($dir);
    return $dir;
}

/** Posiciones del texto sobre la imagen (en % del alto) y tamaño del nombre (en % del ancho). */
function certificado_ajustes(array $curso): array
{
    $c = (array) ($curso['certificado'] ?? []);
    $color = (string) ($c['color'] ?? '');
    return [
        'nombre_y'   => min(95, max(0, (float) ($c['nombre_y'] ?? 45))),
        'nombre_tam' => min(10, max(1, (float) ($c['nombre_tam'] ?? 4))),
        'datos_y'    => min(98, max(0, (float) ($c['datos_y'] ?? 72))),
        'color'      => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#000000',
    ];
}

/** Guarda la imagen del certificado subida desde el panel; devuelve el nombre del archivo. */
function certificado_guardar_imagen(array $archivo): string
{
    if ((int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $archivo['tmp_name'])) {
        throw new RuntimeException('No se recibió la imagen (o supera el máximo del servidor: ' . ini_get('upload_max_filesize') . ').');
    }
    $info = @getimagesize((string) $archivo['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
    if ($ext === '') {
        throw new RuntimeException('La imagen del certificado tiene que ser PNG, JPG o WEBP.');
    }
    $nombre = nuevo_id() . ".$ext";
    if (!move_uploaded_file((string) $archivo['tmp_name'], certificados_dir() . "/$nombre")) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
    }
    return $nombre;
}

/** «11 de octubre de 2026» */
function fecha_larga(string $fecha): string
{
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($fecha) ?: time();
    return date('j', $t) . ' de ' . $meses[(int) date('n', $t) - 1] . ' de ' . date('Y', $t);
}
