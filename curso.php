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

$lecciones = curso_lecciones($curso);
$ids = array_column($lecciones, 'id');

// ── Marcar / desmarcar como vista ─────────────────────────────────────────
if (!$vistaPrevia && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    $id = (string) ($_POST['leccion'] ?? '');
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
$pos = $leccion ? array_search($leccion['id'], $ids, true) : false;
$anterior = $pos !== false ? ($ids[$pos - 1] ?? null) : null;
$posterior = $pos !== false ? ($ids[$pos + 1] ?? null) : null;
$csrf = token_csrf();
$base = 'curso.php?c=' . rawurlencode($slug) . '&l=';

pagina_inicio($curso['titulo']);
?>
<section class="contenedor aula">
    <?php if ($vistaPrevia): ?>
        <p class="aviso aviso--mini">👁 Vista previa desde el panel: el progreso no se guarda. · <a href="admin-curso.php?c=<?= e($slug) ?>">Volver a editar</a></p>
    <?php endif; ?>
    <div class="aula__cabecera">
        <div>
            <p class="hero__etiqueta"><a href="./">Mis cursos</a> › Curso</p>
            <h1><?= e($curso['titulo']) ?></h1>
        </div>
        <div class="aula__progreso">
            <div class="barra" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Progreso del curso"><span style="width: <?= $pct ?>%"></span></div>
            <small><strong><?= $pct ?> %</strong> · <?= $hechas ?> de <?= $total ?> lecciones</small>
        </div>
    </div>

    <?php if (isset($_GET['ok'])): ?>
        <p class="aviso aula__hito" role="status"><?= e(mensaje_hito($pct)) ?>
            <?php if ($pct === 100 && !empty($curso['certificado']['imagen'])): ?> <a href="certificado.php?c=<?= e($slug) ?>">Ver mi certificado</a><?php endif; ?></p>
    <?php endif; ?>

    <div class="aula__columnas">
        <nav class="temario" aria-label="Temario">
            <?php foreach ($curso['modulos'] ?? [] as $i => $m): ?>
                <details class="temario__modulo" <?= ($leccion && $leccion['modulo_id'] === $m['id']) || count($curso['modulos']) <= 3 ? 'open' : '' ?>>
                    <summary><span>Módulo <?= $i + 1 ?></span> <?= e($m['titulo']) ?></summary>
                    <ol>
                        <?php foreach ($m['lecciones'] ?? [] as $l): ?>
                            <li>
                                <a href="<?= e($base . rawurlencode($l['id'])) ?>"<?= $leccion && $l['id'] === $leccion['id'] ? ' aria-current="page"' : '' ?>>
                                    <span class="temario__check<?= isset($vistas[$l['id']]) ? ' temario__check--vista' : '' ?>" aria-label="<?= isset($vistas[$l['id']]) ? 'Vista' : 'Pendiente' ?>"><?= isset($vistas[$l['id']]) ? '✓' : '' ?></span>
                                    <?= e($l['titulo']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endforeach; ?>
        </nav>

        <article class="leccion">
            <?php if (!$leccion): ?>
                <p class="aviso">Este curso todavía no tiene lecciones.</p>
            <?php else: ?>
                <p class="leccion__modulo"><?= e($leccion['modulo']) ?></p>
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
                    <?php if ($posterior && ($vistaPrevia || isset($vistas[$leccion['id']]))): ?><a class="boton boton--contorno boton--chico" href="<?= e($base . rawurlencode($posterior)) ?>">Siguiente →</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </article>
    </div>
</section>
<?php
pagina_fin();
