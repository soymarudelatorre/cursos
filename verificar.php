<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

// Verificación pública de un certificado por su código (lo que figura impreso en el certificado).
$codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['codigo'] ?? '')));
$hallado = null;
if ($codigo !== '' && !limite_alcanzado('cursos-verificar:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 3600, true)) {
    foreach (alumnos_todos() as $a) {
        foreach ($a['certificado'] ?? [] as $slug => $cert) {
            if (hash_equals((string) $cert['codigo'], $codigo)) {
                $hallado = ['nombre' => ($a['nombre_certificado'] ?? '') ?: $a['nombre'], 'curso' => curso($slug)['titulo'] ?? $slug, 'fecha' => $cert['fecha']];
            }
        }
    }
}

pagina_inicio('Verificar certificado');
?>
<section class="contenedor mensaje">
    <p class="hero__etiqueta">Certificados</p>
    <h1>Verificar un certificado</h1>
    <div class="paso"><div class="paso__cuerpo">
        <?php if ($codigo !== '' && $hallado): ?>
            <p class="aviso">✅ <strong>Certificado válido.</strong> <?= e($hallado['nombre']) ?> completó
                «<?= e($hallado['curso']) ?>» el <?= e(fecha_larga($hallado['fecha'])) ?>.</p>
        <?php elseif ($codigo !== ''): ?>
            <p class="aviso aviso--error">No encontramos un certificado con el código <strong><?= e($codigo) ?></strong>. Revisá que esté bien escrito.</p>
        <?php endif; ?>
        <form method="get" class="formulario">
            <label>Código de verificación
                <input type="text" name="codigo" required maxlength="20" value="<?= e($codigo) ?>" autocomplete="off">
            </label>
            <button class="boton" type="submit">Verificar</button>
        </form>
    </div></div>
</section>
<?php
pagina_fin();
