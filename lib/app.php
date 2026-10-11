<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

/*
 * Carpeta externa opcional, FUERA de public_html (al lado de esa carpeta):
 *   …/cursos-privado/config.php   ← configuración y contraseñas
 *   …/cursos-privado/…            ← cursos, alumnos, archivos, secreto
 * Si existe, se usa en lugar de config.php y privado/ dentro de la carpeta del sitio.
 * Así las actualizaciones nunca pisan la configuración ni borran los datos,
 * y esos archivos quedan fuera del alcance del navegador.
 * Se busca en las carpetas superiores a la del sitio (hasta 4 niveles), así funciona
 * aunque el sitio esté en public_html/cursos/ o en otra subcarpeta.
 * En Ferozo (DonWeb) va dentro de storagedir: /home/USUARIO/storagedir/cursos-privado/
 */
function carpetas_externas_posibles(): array
{
    $lista = [];
    $dir = dirname(__DIR__); // carpeta del sitio
    // Nunca dentro de la carpeta pública (public_html): ahí se podría abrir desde el navegador.
    $publica = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    for ($i = 0; $i < 4; $i++) {
        $padre = dirname($dir);
        if ($padre === $dir) {
            break;
        }
        $dir = $padre;
        if ($publica !== '' && str_starts_with($dir . '/', rtrim($publica, '/') . '/')) {
            continue;
        }
        $lista[] = $dir . '/cursos-privado';
        // Ferozo (DonWeb) solo deja leer fuera de public_html dentro de "storagedir".
        $lista[] = $dir . '/storagedir/cursos-privado';
    }
    return $lista;
}

/**
 * Si hay config.php dentro de la carpeta del sitio, se usa ese (y privado/ del sitio).
 * Si no, se busca la carpeta externa cursos-privado.
 */
function buscar_externa(): string
{
    if (is_file(APP_ROOT . '/config.php')) {
        return '';
    }
    foreach (carpetas_externas_posibles() as $c) {
        if (@is_dir($c) && @is_file($c . '/config.php')) {
            return $c;
        }
    }
    return '';
}

define('EXTERNA', buscar_externa());
define('USA_EXTERNA', EXTERNA !== '');
define('PRIVADO', USA_EXTERNA ? EXTERNA : APP_ROOT . '/privado');

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $ruta = USA_EXTERNA ? EXTERNA . '/config.php' : APP_ROOT . '/config.php';
        if (!is_file($ruta)) {
            // Solo se ve durante la instalación: indica dónde se buscó config.php.
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            exit(explicar_instalacion());
        }
        try {
            $cfg = require $ruta;
        } catch (ParseError $ex) {
            // Un error de tipeo en config.php (una coma o una comilla) daba un «Error 500» sin explicación.
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Hay un error de escritura en config.php, línea ' . $ex->getLine() . ': ' . $ex->getMessage()
                . "\nRevisá esa línea y la anterior: suele faltar una coma al final, una comilla o un corchete.");
        }
        if (!is_array($cfg)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('config.php no tiene el formato esperado: tiene que empezar con «<?php» y luego «return [».');
        }
    }
    return $cfg;
}

/**
 * Texto que explica por qué no se encontró config.php: qué hay en cada carpeta donde
 * se buscó (y, si la carpeta existe pero no tiene config.php, qué archivos tiene).
 */
function explicar_instalacion(): string
{
    $estado = function (string $dir): string {
        if (!@is_dir($dir)) {
            return @file_exists(dirname($dir)) ? 'no existe' : 'no existe (o el servidor no deja leerla)';
        }
        if (@is_file($dir . '/config.php')) {
            return @is_readable($dir . '/config.php') ? 'tiene config.php ✓' : 'tiene config.php pero el servidor no lo puede leer (permisos)';
        }
        $archivos = @scandir($dir);
        $archivos = $archivos === false ? null : array_values(array_diff($archivos, ['.', '..']));
        return 'existe pero NO tiene config.php · contiene: '
            . ($archivos === null ? '(no se puede leer)' : ($archivos ? implode(', ', $archivos) : '(vacía)'));
    };
    $lineas = [];
    foreach (array_merge(carpetas_externas_posibles(), [realpath(APP_ROOT) ?: APP_ROOT]) as $dir) {
        $lineas[] = "  - $dir → " . $estado($dir);
    }
    $texto = "Falta config.php. Se buscó en estas carpetas:\n" . implode("\n", $lineas)
        . "\n\nLa carpeta cursos-privado (con config.php adentro) tiene que estar en una de las primeras.";
    // Contenido de las carpetas permitidas fuera de la carpeta pública (por ejemplo storagedir),
    // para detectar un nombre mal escrito.
    $publica = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    foreach (array_filter(explode(PATH_SEPARATOR, (string) ini_get('open_basedir'))) as $permitida) {
        $permitida = rtrim($permitida, '/');
        if (str_contains($permitida, 'storagedir') && $permitida !== $publica && @is_dir($permitida)) {
            $items = array_values(array_diff(@scandir($permitida) ?: [], ['.', '..']));
            $texto .= "\n\nContenido de $permitida: " . ($items ? implode(', ', $items) : '(vacía)');
        }
    }
    $restriccion = (string) ini_get('open_basedir');
    if ($restriccion !== '') {
        $texto .= "\n\nEl servidor solo permite leer estas rutas (open_basedir): $restriccion";
    }
    return $texto;
}

function e(string|int|float|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function asegurar_directorio(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

function es_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Secreto del servidor para firmar cookies; se genera solo la primera vez. */
function secreto(): string
{
    $archivo = PRIVADO . '/secreto.key';
    if (!is_file($archivo)) {
        asegurar_directorio(PRIVADO);
        file_put_contents($archivo, bin2hex(random_bytes(32)), LOCK_EX);
        chmod($archivo, 0600);
    }
    return trim((string) file_get_contents($archivo));
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/'));
}

function poner_cookie(string $nombre, string $valor, int $expira): void
{
    setcookie($nombre, $valor, [
        'expires'  => $expira,
        'path'     => '/',
        'secure'   => es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── Firmas (enlaces y cookies) ─────────────────────────────────────────────

/** Firma datos con el secreto del servidor: "datos.firma". */
function firmar(array $datos): string
{
    $d = b64url(json_encode($datos, JSON_UNESCAPED_UNICODE));
    return $d . '.' . b64url(hash_hmac('sha256', $d, secreto(), true));
}

/** Verifica la firma y la fecha de vencimiento; devuelve los datos o null. */
function verificar_firma(string $valor): ?array
{
    if (substr_count($valor, '.') !== 1) {
        return null;
    }
    [$d, $firma] = explode('.', $valor);
    if (!hash_equals(b64url(hash_hmac('sha256', $d, secreto(), true)), $firma)) {
        return null;
    }
    $datos = json_decode(b64url_dec($d), true);
    return is_array($datos) && ($datos['exp'] ?? 0) >= time() ? $datos : null;
}

/** Cambiar 'version_sesiones' en config.php cierra todas las sesiones abiertas. */
function version_sesiones(): int
{
    return (int) (config()['version_sesiones'] ?? 1);
}

function normalizar_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

/** URL de la carpeta del sitio, por ejemplo https://www.marudelatorre.com/cursos/ */
function url_base(): string
{
    $configurada = (string) (config()['url_sitio'] ?? '');
    if ($configurada !== '') {
        return rtrim($configurada, '/') . '/';
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (es_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

// ── Sesión del panel de administración ──────────────────────────────────

const COOKIE_ADMIN = 'cursos_admin';

function es_admin(): bool
{
    $d = verificar_firma($_COOKIE[COOKIE_ADMIN] ?? '');
    return $d !== null && ($d['t'] ?? '') === 'admin' && ($d['h'] ?? '') === huella_admin();
}

/** Si cambia la clave de administración, se cierran las sesiones del panel. */
function huella_admin(): string
{
    return substr(hash_hmac('sha256', (string) (config()['admin_clave'] ?? ''), secreto()), 0, 16);
}

// ── Límites de uso y CSRF ─────────────────────────────────────────────────

/**
 * Límite de eventos por clave en una ventana de tiempo (por ejemplo, emails
 * enviados por IP). Con $registrar = true cuenta un evento nuevo.
 * Devuelve true si ya se alcanzó el máximo.
 */
function limite_alcanzado(string $clave, int $maximo, int $segundos, bool $registrar = false): bool
{
    $dir = PRIVADO . '/cache/limites';
    asegurar_directorio($dir);
    $archivo = $dir . '/' . hash_hmac('sha256', $clave, secreto());
    $ahora = time();
    $eventos = is_file($archivo) ? (json_decode((string) file_get_contents($archivo), true) ?: []) : [];
    $eventos = array_values(array_filter($eventos, fn ($t) => $t > $ahora - $segundos));
    if ($registrar) {
        $eventos[] = $ahora;
        file_put_contents($archivo, json_encode($eventos), LOCK_EX);
    }
    return count($eventos) >= $maximo;
}

function token_csrf(): string
{
    static $token = null;
    if ($token === null) {
        $token = $_COOKIE['cursos_csrf'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $token = bin2hex(random_bytes(16));
            poner_cookie('cursos_csrf', $token, 0);
        }
    }
    return $token;
}

function csrf_valido(): bool
{
    $cookie = $_COOKIE['cursos_csrf'] ?? '';
    return $cookie !== '' && hash_equals($cookie, (string) ($_POST['csrf'] ?? ''));
}

// ── Registros (CSV en privado/) ───────────────────────────────────────────

function registrar(string $archivo, array $fila): void
{
    asegurar_directorio(PRIVADO);
    $ruta = PRIVADO . '/' . $archivo;
    // Si cambiaron las columnas: cuando solo se agregaron columnas al final, se completan
    // las filas existentes; si no, el archivo anterior se guarda aparte.
    if (is_file($ruta) && ($fh = fopen($ruta, 'r+'))) {
        flock($fh, LOCK_EX);
        $encabezado = fgetcsv($fh, null, ',', '"', '');
        $nuevas = array_keys($fila);
        if ($encabezado !== $nuevas) {
            if (is_array($encabezado) && $encabezado === array_slice($nuevas, 0, count($encabezado))) {
                $filas = [];
                while (($f = fgetcsv($fh, null, ',', '"', '')) !== false) {
                    $filas[] = array_pad($f, count($nuevas), '');
                }
                ftruncate($fh, 0);
                rewind($fh);
                fputcsv($fh, $nuevas, ',', '"', '');
                foreach ($filas as $f) {
                    fputcsv($fh, $f, ',', '"', '');
                }
            } else {
                flock($fh, LOCK_UN);
                fclose($fh);
                $fh = null;
                rename($ruta, preg_replace('/\.csv$/', '-anterior-' . date('YmdHis') . '.csv', $ruta));
            }
        }
        if ($fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
    $nuevo = !is_file($ruta);
    $fh = fopen($ruta, 'a');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    if ($nuevo) {
        fputcsv($fh, array_keys($fila), ',', '"', '');
    }
    // Evita inyección de fórmulas al abrir el CSV en Excel / Google Sheets.
    fputcsv($fh, array_map(fn ($v) => preg_replace('/^([=+\-@\t\r])/', "'$1", (string) $v), $fila), ',', '"', '');
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Lee un CSV de privado/ como lista de filas asociativas. */
function leer_registros(string $archivo): array
{
    $ruta = PRIVADO . '/' . $archivo;
    if (!is_file($ruta) || !($fh = fopen($ruta, 'r'))) {
        return [];
    }
    $filas = [];
    $encabezado = fgetcsv($fh, null, ',', '"', '');
    while (($f = fgetcsv($fh, null, ',', '"', '')) !== false) {
        if ($encabezado && count($f) === count($encabezado)) {
            $filas[] = array_combine($encabezado, $f);
        }
    }
    fclose($fh);
    return $filas;
}

// ── Datos en JSON (privado/) ──────────────────────────────────────────────

/**
 * Lee un archivo JSON de privado/. Con $cambiar, lo modifica con bloqueo exclusivo:
 * $cambiar recibe los datos y devuelve [datos nuevos, resultado]; se devuelve el resultado.
 * Así dos pedidos simultáneos (por ejemplo, dos avisos de WooCommerce) no se pisan.
 */
function datos_json(string $archivo, ?callable $cambiar = null): mixed
{
    asegurar_directorio(PRIVADO);
    $fh = fopen(PRIVADO . '/' . $archivo, 'c+');
    if ($fh === false) {
        throw new RuntimeException("No se pudo abrir $archivo");
    }
    try {
        flock($fh, $cambiar ? LOCK_EX : LOCK_SH);
        $datos = json_decode((string) stream_get_contents($fh), true) ?: [];
        if (!$cambiar) {
            return $datos;
        }
        [$datos, $resultado] = $cambiar($datos);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fflush($fh);
        return $resultado;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Identificador corto y aleatorio (módulos, lecciones, archivos). */
function nuevo_id(): string
{
    return bin2hex(random_bytes(5));
}

/** Fecha guardada (Y-m-d o Y-m-d H:i:s) en formato dd/mm/aaaa. */
function fecha_corta(?string $fecha): string
{
    return $fecha ? date('d/m/Y', strtotime($fecha)) : '';
}
