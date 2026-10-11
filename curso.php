<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Página de un curso: temario a la izquierda y la lección elegida (video, texto,
 * recursos descargables y botón para marcarla como vista).
 * Desde el panel (sesión de administración) se puede ver como vista previa, sin guardar progreso.
 */

$slug = (string) ($_GET['c'] ?? '');
$curso = curso($slug);
$email = sesion();
$vistaPrevia = es_admin() && (!$email || !puede_ver($email, $slug));

if (!$curso || (!$vistaPrevia && (!$email || !puede_ver($email, $slug)))) {
    // Sin sesión: a pedir el enlace. Con sesión pero sin acceso: a «Mis cursos», que explica el estado.
    header('Location: ./', true, 303);
    exit;
}

// Primer ingreso: antes de ver el curso hay que confirmar el nombre para los certificados.
if (!$vistaPrevia && !nombre_confirmado(alumno($email))) {
    header('Location: ./', true, 303);
    exit;
}

$lecciones = curso_lecciones($curso);
$ids = array_column($lecciones, 'id');

// ── Marcar / desmarcar como vista ─────────────────────────────────────────
if (!$vistaPrevia && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    $id = (string) ($_POST['leccion'] ?? '');
    if (in_array($id, $ids, true) && isset($_POST['estrellas'])) {
        // Calificación de la lección (1 a 5): se puede cambiar cuando quiera, vale la última.
        $estrellas = max(1, min(5, (int) $_POST['estrellas']));
        calificar_leccion($email, $slug, $id, $estrellas);
        panel_flash(true, '¡Gracias! Calificaste esta lección con ' . $estrellas . ($estrellas === 1 ? ' estrella.' : ' estrellas.') . ' Podés cambiarlo cuando quieras.');
        header('Location: curso.php?c=' . rawurlencode($slug) . '&l=' . rawurlencode($id), true, 303);
        exit;
    }
    if (in_array($id, $ids, true)) {
        $vista = ($_POST['accion'] ?? '') === 'vista';
        marcar_leccion($email, $curso, $id, $vista);
        $siguiente = $ids[array_search($id, $ids, true) + 1] ?? null;
        $destino = $vista && $siguiente ? $siguiente : $id;
        header('Location: curso.php?c=' . rawurlencode($slug) . '&l=' . rawurlencode($destino) . ($vista ? '&ok=1' : ''), true, 303);
        exit;
    }
}

$alumno = $vistaPrevia ? null : alumno($email);
$vistas = $alumno['progreso'][$slug] ?? [];
$pedida = (string) ($_GET['l'] ?? '');
if (!in_array($pedida, $ids, true)) {
    // Sin lección elegida: la primera que falta ver (o la primera del curso).
    $pedida = '';
    foreach ($ids as $id) {
        if (!isset($vistas[$id])) {
            $pedida = $id;
            break;
        }
    }
    $pedida = $pedida ?: ($ids[0] ?? '');
}
$leccion = $pedida !== '' ? curso_leccion($curso, $pedida) : null;
if ($leccion && !$vistaPrevia && ($alumno['ultima'][$slug] ?? '') !== $leccion['id']) {
    recordar_ultima($email, $slug, $leccion['id']);
}
[$hechas, $total, $pct] = progreso($alumno, $curso);
// Certificado: aparece al final del temario solo con todas las lecciones vistas.
$certificado = (!$vistaPrevia && $total > 0 && $pct === 100) ? asegurar_certificado($email, $curso) : null;
$hayImagenCertificado = !empty($curso['certificado']['imagen']);
$pos = $leccion ? array_search($leccion['id'], $ids, true) : false;
$anterior = $pos !== false ? ($ids[$pos - 1] ?? null) : null;
$posterior = $pos !== false ? ($ids[$pos + 1] ?? null) : null;
$csrf = token_csrf();
$base = 'curso.php?c=' . rawurlencode($slug) . '&l=';

pagina_inicio($curso['titulo']);
echo panel_aviso(panel_leer_flash());
?>
<?php // A. Franja del curso: migas, nombre, progreso y botón del temario, todo en un bloque. ?>
<section class="curso-banda">
    <div class="contenedor curso-banda__fila">
        <div class="curso-banda__titulo">
            <p class="curso-banda__migas"><a href="./">Mis cursos</a> › Curso</p>
            <h1><?= e($curso['titulo']) ?></h1>
        </div>
        <div class="curso-banda__derecha">
            <div class="curso-banda__progreso">
                <span><strong><?= $pct ?> %</strong> · <?= $hechas ?> de <?= $total ?> lecciones</span>
                <div class="curso-banda__barra" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Progreso del curso"><span style="width: <?= $pct ?>%"></span></div>
            </div>
            <button class="curso-banda__boton" type="button" id="alternar-temario" aria-controls="temario" aria-expanded="true">
                <span aria-hidden="true">☰</span> <span class="alternar-temario__texto">Ocultar temario</span>
            </button>
        </div>
    </div>
</section>

<section class="contenedor aula">
    <?php if ($vistaPrevia): ?>
        <p class="aviso aviso--mini aula__aviso">👁 Vista previa desde el panel: el progreso no se guarda. · <a href="admin-curso.php?c=<?= e($slug) ?>">Volver a editar</a></p>
    <?php endif; ?>

    <?php if (isset($_GET['ok'])): ?>
        <p class="aviso aula__hito" role="status"><?= e(mensaje_hito($pct, $hayImagenCertificado)) ?>
            <?php if ($pct === 100 && !empty($curso['certificado']['imagen'])): ?> <a href="certificado.php?c=<?= e($slug) ?>">Ver mi certificado</a><?php endif; ?></p>
    <?php endif; ?>

    <div class="aula__columnas">
        <?php // B. Temario: el color solo en el módulo donde está la alumna. ?>
        <nav class="temario" id="temario" aria-label="Contenido del curso">
            <p class="temario__titulo">Contenido del curso</p>
            <?php foreach ($curso['modulos'] ?? [] as $i => $m):
                $idsModulo = array_column($m['lecciones'] ?? [], 'id');
                $vistasModulo = count(array_intersect($idsModulo, array_keys($vistas)));
                $actual = $leccion && $leccion['modulo_id'] === $m['id'];
                $completo = $idsModulo && $vistasModulo === count($idsModulo);
                ?>
                <details class="modulo<?= $actual ? ' modulo--actual' : '' ?><?= $completo ? ' modulo--completo' : '' ?>"<?= $actual ? ' open' : '' ?>>
                    <summary>
                        <span class="modulo__num" aria-hidden="true"><?= $completo ? '✓' : $i + 1 ?></span>
                        <span class="modulo__nombre"><?= e($m['titulo']) ?>
                            <small><?= $idsModulo ? $vistasModulo . ' de ' . count($idsModulo) . (count($idsModulo) === 1 ? ' vista' : ' vistas') : 'Sin lecciones todavía' ?></small></span>
                    </summary>
                    <ol>
                        <?php foreach ($m['lecciones'] ?? [] as $l):
                            $esActual = $leccion && $l['id'] === $leccion['id'];
                            $vista = isset($vistas[$l['id']]);
                            ?>
                            <li>
                                <a href="<?= e($base . rawurlencode($l['id'])) ?>"<?= $esActual ? ' aria-current="page"' : '' ?>>
                                    <span class="temario__check<?= $vista ? ' temario__check--vista' : ($esActual ? ' temario__check--actual' : '') ?>" aria-label="<?= $vista ? 'Vista' : 'Pendiente' ?>"><?= $vista ? '✓' : '' ?></span>
                                    <?= e($l['titulo']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endforeach; ?>
            <?php if ($certificado): ?>
                <div class="temario__certificado">
                    <?php if ($hayImagenCertificado): ?>
                        <a href="certificado.php?c=<?= e($slug) ?>"><span aria-hidden="true">🏆</span> <span><strong>Tu certificado</strong><small>Completaste todas las lecciones</small></span></a>
                    <?php else: /* curso sin certificado: solo el reconocimiento */ ?>
                        <p><span aria-hidden="true">🎉</span> <span><strong>¡Completaste el curso!</strong><small>Viste todas las lecciones</small></span></p>
                    <?php endif; ?>
                </div>
            <?php elseif ($vistaPrevia && $hayImagenCertificado): ?>
                <div class="temario__certificado temario__certificado--vista">
                    <a href="certificado.php?c=<?= e($slug) ?>&vista=1" target="_blank"><span aria-hidden="true">🏆</span> <span><strong>Certificado</strong><small>Vista previa: la alumna lo ve acá al completar todas las lecciones</small></span></a>
                </div>
            <?php endif; ?>
        </nav>

        <article class="leccion">
            <?php if (!$leccion): ?>
                <p class="aviso">Este curso todavía no tiene lecciones.</p>
            <?php else: ?>
                <p class="leccion__modulo">Lección <?= $pos + 1 ?> de <?= $total ?> <span>· <?= e($leccion['modulo']) ?></span></p>
                <h2><?= e($leccion['titulo']) ?></h2>
                <?php $embed = video_embed((string) ($leccion['video'] ?? '')); ?>
                <?php if (video_archivo((string) ($leccion['video'] ?? '')) !== ''): ?>
                    <div class="leccion__video">
                        <?php /* Sin botón de descarga ni menú del botón derecho: desalienta la descarga, no la impide. */ ?>
                        <video src="video.php?c=<?= e($slug) ?>&l=<?= e($leccion['id']) ?>" controls preload="metadata" playsinline
                            controlslist="nodownload" disablepictureinpicture oncontextmenu="return false"></video>
                    </div>
                <?php elseif ($embed !== ''): ?>
                    <div class="leccion__video">
                        <iframe src="<?= e($embed) ?>" title="<?= e($leccion['titulo']) ?>" loading="lazy"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    </div>
                <?php endif; ?>
                <?php if (trim((string) ($leccion['texto'] ?? '')) !== ''): ?>
                    <div class="leccion__texto"><?= texto_html((string) $leccion['texto']) ?></div>
                <?php endif; ?>
                <?php if (!empty($leccion['recursos'])): ?>
                    <div class="leccion__recursos">
                        <h3>Material para descargar</h3>
                        <ul>
                            <?php foreach ($leccion['recursos'] as $r): ?>
                                <li><a href="recurso.php?c=<?= e($slug) ?>&l=<?= e($leccion['id']) ?>&r=<?= e($r['id']) ?>">⬇ <?= e($r['nombre']) ?></a> <small><?= e(tamano_legible((int) $r['tamano'])) ?></small></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php // C. Una sola barra: estrellas a la izquierda, navegación a la derecha. ?>
                <div class="leccion__barra">
                <?php $miVoto = $vistaPrevia ? 0 : (int) ($alumno['calificaciones'][$slug][$leccion['id']] ?? 0); ?>
                    <form method="post" class="calificar<?= $vistaPrevia ? ' calificar--vista' : '' ?>">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="leccion" value="<?= e($leccion['id']) ?>">
                        <span class="calificar__texto" id="calificar-titulo"><?= $miVoto ? 'Tu calificación:' : '¿Qué te pareció?' ?></span>
                        <span class="calificar__estrellas" role="group" aria-labelledby="calificar-titulo">
                            <?php for ($n = 5; $n >= 1; $n--): /* orden inverso: el CSS pinta las anteriores al pasar el mouse */ ?>
                                <button type="submit" name="estrellas" value="<?= $n ?>" class="calificar__estrella<?= $n <= $miVoto ? ' calificar__estrella--llena' : '' ?>"
                                    aria-label="<?= $n ?> de 5 estrellas"<?= $n === $miVoto ? ' aria-pressed="true"' : '' ?> title="<?= $n ?> de 5"<?= $vistaPrevia ? ' disabled' : '' ?>>★</button>
                            <?php endfor; ?>
                        </span>
                        <?php if ($vistaPrevia): ?><small class="calificar__nota">Vista previa: desde el panel no se vota.</small><?php endif; ?>
                    </form>

                <div class="leccion__acciones">
                    <?php if ($anterior): ?><a class="boton boton--contorno boton--chico" href="<?= e($base . rawurlencode($anterior)) ?>">← Anterior</a><?php endif; ?>
                    <?php if (!$vistaPrevia): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="leccion" value="<?= e($leccion['id']) ?>">
                            <?php if (isset($vistas[$leccion['id']])): ?>
                                <span class="leccion__vista">✓ Lección vista</span>
                                <button class="enlace-boton" type="submit" name="accion" value="pendiente">Marcar como pendiente</button>
                            <?php else: ?>
                                <button class="boton boton--chico" type="submit" name="accion" value="vista"><?= $posterior ? 'Marcar como vista y seguir →' : 'Marcar como vista ✓' ?></button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if ($posterior && ($vistaPrevia || isset($vistas[$leccion['id']]))): ?><a class="boton boton--chico" href="<?= e($base . rawurlencode($posterior)) ?>">Siguiente →</a><?php endif; ?>
                </div>
                </div>
            <?php endif; ?>
        </article>
    </div>
</section>
<?php
// Temario plegable: más espacio para el video. La preferencia queda guardada en el navegador.
pagina_fin(<<<'JS'
<script>
(function () {
    var aula = document.querySelector('.aula'), boton = document.getElementById('alternar-temario');
    if (!aula || !boton) return;
    function aplicar(oculto) {
        aula.classList.toggle('aula--sin-temario', oculto);
        boton.setAttribute('aria-expanded', String(!oculto));
        boton.querySelector('.alternar-temario__texto').textContent = oculto ? 'Mostrar temario' : 'Ocultar temario';
    }
    var guardado = null;
    try { guardado = localStorage.getItem('cursos_temario_oculto'); } catch (e) {}
    // Estado guardado: se aplica sin animación y recién después se habilitan las transiciones.
    aula.classList.add('aula--sin-animacion');
    aplicar(guardado === '1');
    requestAnimationFrame(function () { requestAnimationFrame(function () { aula.classList.remove('aula--sin-animacion'); }); });
    boton.addEventListener('click', function () {
        var oculto = !aula.classList.contains('aula--sin-temario');
        aplicar(oculto);
        try { localStorage.setItem('cursos_temario_oculto', oculto ? '1' : '0'); } catch (e) {}
    });
})();
</script>
JS);
