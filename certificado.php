<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Certificado de finalización: la imagen que se sube en el panel (por curso) con el nombre
 * y la fecha escritos encima. Se guarda como PDF desde el navegador
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

// El nombre lo confirma la alumna en su primer ingreso; después solo se cambia desde el panel.
if (!$ejemplo && !nombre_confirmado($alumno)) {
    header('Location: ./', true, 303);
    exit;
}

$nombre = $ejemplo ? 'Nombre Apellido de Ejemplo' : nombre_certificado($alumno);
$smtp = (array) (config()['smtp'] ?? []);
$emailContacto = (string) (($smtp['responder_a'] ?? '') ?: ($smtp['usuario'] ?? ''));
$fecha = $ejemplo ? date('Y-m-d') : (string) $cert['fecha'];
$ajustes = certificado_ajustes($curso);
[$ancho, $alto] = getimagesize($rutaImagen) ?: [1600, 1131];
$csrf = token_csrf();
$aviso = panel_leer_flash();

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
        <p>Terminaste <strong><?= e($curso['titulo']) ?></strong>. Para guardarlo como PDF, tocá «Descargar» y elegí <em>Guardar como PDF</em> como impresora.</p>
        <p class="certificado-pagina__acciones">
            <button class="boton" type="button" onclick="window.print()">⬇ Descargar / imprimir</button>
        </p>
        <?php if (!$ejemplo && $emailContacto !== ''): ?>
            <p class="certificado-pagina__nota">¿Ves un error en tu nombre? Escribime a <a href="mailto:<?= e($emailContacto) ?>"><?= e($emailContacto) ?></a> y lo corrijo.</p>
        <?php endif; ?>
    </div>

    <?php if ($nombre !== '' || $ejemplo):
        $textosCert = [
            'curso'  => $curso['titulo'],
            'nombre' => $nombre,
            'fecha'  => fecha_larga($fecha),
        ];
        ?>
        <?php if ($ejemplo): ?>
            <form method="post" action="admin-curso.php?c=<?= e($slug) ?>" class="cert-editor no-imprimir" id="cert-editor">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="accion" value="certificado">
                <input type="hidden" name="volver_ejemplo" value="1">
                <?php foreach ($ajustes['textos'] as $campo => $t): foreach (['x', 'y', 'tam', 'color'] as $dato): ?>
                    <input type="hidden" name="<?= $campo ?>_<?= $dato ?>" value="<?= e((string) $t[$dato]) ?>">
                <?php endforeach; endforeach; ?>
                <p class="cert-editor__ayuda"><strong>✋ Arrastrá cada texto</strong> hasta su lugar. Tocá uno para elegirlo y cambiá su tamaño:</p>
                <div class="cert-editor__controles">
                    <label>Texto
                        <select id="cert-campo">
                            <?php foreach (CERT_TEXTOS as $campo => $def): ?><option value="<?= $campo ?>"><?= e($def['etiqueta']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Tamaño <input type="range" id="cert-tam" min="0.5" max="10" step="0.05"></label>
                    <label>Color del texto elegido <input type="color" id="cert-color"></label>
                    <button class="boton boton--mini" type="submit">Guardar posiciones</button>
                </div>
            </form>
        <?php endif; ?>
        <div class="certificado<?= $ejemplo ? ' certificado--editable' : '' ?>" id="certificado" style="aspect-ratio: <?= (int) $ancho ?> / <?= (int) $alto ?>; color: <?= e($ajustes['color']) ?>;">
            <?php /* v = nombre del archivo: al subir otra imagen cambia la dirección y el navegador no muestra la vieja guardada. */ ?>
            <img src="certificado.php?c=<?= e($slug) ?>&imagen=1&v=<?= e(pathinfo($imagen, PATHINFO_FILENAME)) ?><?= $ejemplo ? '&vista=1' : '' ?>" alt="" draggable="false">
            <?php foreach ($ajustes['textos'] as $campo => $t): ?>
                <p class="certificado__texto certificado__<?= $campo ?>" data-campo="<?= $campo ?>"
                    style="left: <?= $t['x'] ?>%; top: <?= $t['y'] ?>%; font-size: <?= $t['tam'] ?>cqw; color: <?= e($t['color']) ?>;"><?= e($textosCert[$campo]) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<style>
    @page { size: <?= $ancho >= $alto ? 'landscape' : 'portrait' ?>; margin: 0; }
</style>
<?php
// Ejemplo desde el panel: los textos se arrastran con el mouse (o el dedo) y se guardan.
pagina_fin($ejemplo ? <<<'JS'
<script>
(function () {
    var cert = document.getElementById('certificado'), form = document.getElementById('cert-editor');
    if (!cert || !form) return;
    var selector = document.getElementById('cert-campo'), tam = document.getElementById('cert-tam'), color = document.getElementById('cert-color');
    function campo(nombre, dato) { return form.querySelector('[name="' + nombre + '_' + dato + '"]'); }
    function elegir(nombre) {
        cert.querySelectorAll('[data-campo]').forEach(function (t) { t.classList.toggle('certificado__texto--elegido', t.dataset.campo === nombre); });
        selector.value = nombre;
        tam.value = campo(nombre, 'tam').value;
        color.value = campo(nombre, 'color').value;
    }
    cert.querySelectorAll('[data-campo]').forEach(function (texto) {
        texto.addEventListener('pointerdown', function (ev) {
            ev.preventDefault();
            elegir(texto.dataset.campo);
            var caja = cert.getBoundingClientRect(), r = texto.getBoundingClientRect();
            // Distancia entre el puntero y el centro del texto, para que no «salte» al agarrarlo.
            var dx = ev.clientX - (r.left + r.width / 2), dy = ev.clientY - (r.top + r.height / 2);
            function mover(e) {
                var x = Math.min(100, Math.max(0, (e.clientX - dx - caja.left) / caja.width * 100));
                var y = Math.min(100, Math.max(0, (e.clientY - dy - caja.top) / caja.height * 100));
                texto.style.left = x.toFixed(1) + '%'; texto.style.top = y.toFixed(1) + '%';
                campo(texto.dataset.campo, 'x').value = x.toFixed(1);
                campo(texto.dataset.campo, 'y').value = y.toFixed(1);
            }
            // El movimiento se escucha en toda la ventana: así el texto sigue al puntero aunque se mueva rápido.
            function soltar() { window.removeEventListener('pointermove', mover); window.removeEventListener('pointerup', soltar); window.removeEventListener('pointercancel', soltar); }
            window.addEventListener('pointermove', mover);
            window.addEventListener('pointerup', soltar);
            window.addEventListener('pointercancel', soltar);
        });
    });
    selector.addEventListener('change', function () { elegir(selector.value); });
    tam.addEventListener('input', function () {
        campo(selector.value, 'tam').value = tam.value;
        cert.querySelector('[data-campo="' + selector.value + '"]').style.fontSize = tam.value + 'cqw';
    });
    color.addEventListener('input', function () {
        campo(selector.value, 'color').value = color.value;
        cert.querySelector('[data-campo="' + selector.value + '"]').style.color = color.value;
    });
    elegir('nombre');
})();
</script>
JS : '');
