<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Certificado de finalización: la imagen que se sube en el panel (por curso) con el nombre,
 * la fecha y el código de verificación escritos encima. Se guarda como PDF desde el navegador
 * («Imprimir» → «Guardar como PDF»). Queda disponible aunque el acceso al curso venza.
 * Desde el panel, ?vista=1 muestra un ejemplo para ajustar las posiciones.
 */

$slug = (string) ($_GET['c'] ?? '');
$curso = curso($slug);
$email = sesion();
$alumno = $email ? alumno($email) : null;
$cert = $alumno['certificado'][$slug] ?? null;
$ejemplo = isset($_GET['vista']) && es_admin();
$imagen = (string) ($curso['certificado']['imagen'] ?? '');
$rutaImagen = $imagen !== '' ? certificados_dir() . '/' . basename($imagen) : '';

if (!$curso || $rutaImagen === '' || !is_file($rutaImagen) || (!$cert && !$ejemplo)) {
    header('Location: ./', true, 303);
    exit;
}

// La imagen de fondo se entrega por este mismo archivo (la carpeta privada no es pública).
if (isset($_GET['imagen'])) {
    $tipo = (string) (getimagesize($rutaImagen)['mime'] ?? 'application/octet-stream');
    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . filesize($rutaImagen));
    header('Cache-Control: private, max-age=86400');
    readfile($rutaImagen);
    exit;
}

// Nombre tal como figura en el certificado (la persona lo puede corregir).
$error = '';
if (!$ejemplo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuevo = trim(preg_replace('/\s+/', ' ', (string) ($_POST['nombre'] ?? '')));
    if (!csrf_valido()) {
        $error = 'El formulario venció. Volvé a intentarlo.';
    } elseif (mb_strlen($nuevo) < 3 || mb_strlen($nuevo) > 80) {
        $error = 'Escribí tu nombre y apellido (entre 3 y 80 caracteres).';
    } else {
        alumno_cambiar($email, function (array $a) use ($nuevo) {
            $a['nombre_certificado'] = $nuevo;
            return $a;
        });
        panel_flash(true, 'Tu nombre quedó guardado así: ' . $nuevo);
        header('Location: certificado.php?c=' . rawurlencode($slug), true, 303);
        exit;
    }
}

$nombre = $ejemplo ? 'Nombre Apellido de Ejemplo'
    : trim((string) (($alumno['nombre_certificado'] ?? '') ?: ($alumno['nombre'] ?? '')));
$fecha = $ejemplo ? date('Y-m-d') : (string) $cert['fecha'];
$codigo = $ejemplo ? 'AB12CD34' : (string) $cert['codigo'];
$ajustes = certificado_ajustes($curso);
[$ancho, $alto] = getimagesize($rutaImagen) ?: [1600, 1131];
$csrf = token_csrf();
$aviso = panel_leer_flash(); // los errores se muestran junto al formulario

pagina_inicio('Certificado · ' . $curso['titulo']);
echo panel_aviso($aviso);
?>
<section class="contenedor certificado-pagina">
    <div class="no-imprimir">
        <p class="hero__etiqueta"><a href="./">Mis cursos</a> › Certificado</p>
        <h1>🏆 ¡Felicitaciones!</h1>
        <?php if ($ejemplo): ?>
            <p class="aviso aviso--mini">👁 Ejemplo desde el panel. · <a href="admin-curso.php?c=<?= e($slug) ?>#certificado">Volver a ajustar</a></p>
        <?php endif; ?>
        <?php if ($nombre === '' && !$ejemplo): ?>
            <p>Antes de ver tu certificado, contanos cómo querés que figure tu nombre.</p>
        <?php else: ?>
            <p>Terminaste <strong><?= e($curso['titulo']) ?></strong>. Para guardarlo como PDF, tocá «Descargar» y elegí <em>Guardar como PDF</em> como impresora.</p>
            <p class="certificado-pagina__acciones">
                <button class="boton" type="button" onclick="window.print()">⬇ Descargar / imprimir</button>
                <?php if (!$ejemplo): ?><button class="enlace-boton" type="button" onclick="document.getElementById('cambiar-nombre').hidden = false">Corregir mi nombre</button><?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (!$ejemplo): ?>
            <form method="post" class="formulario certificado-pagina__nombre" id="cambiar-nombre"<?= $nombre !== '' && !$error ? ' hidden' : '' ?>>
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <?php if ($error): ?><p class="aviso aviso--error" role="alert"><?= e($error) ?></p><?php endif; ?>
                <label>Nombre y apellido, como querés que aparezcan
                    <input type="text" name="nombre" required minlength="3" maxlength="80" value="<?= e($nombre) ?>">
                </label>
                <button class="boton boton--chico" type="submit">Guardar</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($nombre !== '' || $ejemplo): ?>
        <div class="certificado" style="aspect-ratio: <?= (int) $ancho ?> / <?= (int) $alto ?>; color: <?= e($ajustes['color']) ?>;">
            <img src="certificado.php?c=<?= e($slug) ?>&imagen=1<?= $ejemplo ? '&vista=1' : '' ?>" alt="">
            <p class="certificado__nombre" style="top: <?= $ajustes['nombre_y'] ?>%; font-size: <?= $ajustes['nombre_tam'] ?>cqw;"><?= e($nombre) ?></p>
            <p class="certificado__datos" style="top: <?= $ajustes['datos_y'] ?>%;">
                <?= e(fecha_larga($fecha)) ?><br>
                Código de verificación: <?= e($codigo) ?> · <?= e(preg_replace('~^https?://~', '', url_base())) ?>verificar.php
            </p>
        </div>
    <?php endif; ?>
</section>
<style>
    @page { size: <?= $ancho >= $alto ? 'landscape' : 'portrait' ?>; margin: 0; }
</style>
<?php
pagina_fin();
