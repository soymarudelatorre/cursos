<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Editor de un curso: datos generales, módulos y lecciones (video del servidor, de YouTube o de Vimeo,
 * texto y archivos descargables) y certificado.
 * admin-curso.php?c=slug            → curso y temario
 * admin-curso.php?c=slug&l=leccion  → edición de una lección
 */
panel_exigir_ingreso();

$slug = (string) ($_GET['c'] ?? '');
if (!curso($slug)) {
    header('Location: admin.php', true, 303);
    exit;
}

/** Aplica $cambiar al curso actual (con bloqueo) y guarda. */
function cambiar_curso(string $slug, callable $cambiar): void
{
    cursos_cambiar(function (array $cursos) use ($slug, $cambiar) {
        if (isset($cursos[$slug])) {
            $cursos[$slug] = $cambiar($cursos[$slug]);
        }
        return $cursos;
    });
}

/** Aplica $cambiar a una lección (por referencia dentro de su módulo). */
function cambiar_leccion(string $slug, string $id, callable $cambiar): void
{
    cambiar_curso($slug, function (array $c) use ($id, $cambiar) {
        foreach ($c['modulos'] as &$m) {
            foreach ($m['lecciones'] as &$l) {
                if ($l['id'] === $id) {
                    $l = $cambiar($l);
                }
            }
        }
        return $c;
    });
}

/** Mueve el elemento $id una posición arriba (-1) o abajo (+1) dentro de $lista. */
function mover(array $lista, string $id, int $dir): array
{
    $i = array_search($id, array_column($lista, 'id'), true);
    $j = $i === false ? -1 : $i + $dir;
    if ($j >= 0 && $j < count($lista)) {
        [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
    }
    return $lista;
}

$leccionId = (string) ($_GET['l'] ?? '');
$volver = 'admin-curso.php?c=' . rawurlencode($slug);
$aviso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (string) ($_POST['id'] ?? '');
    $destino = $volver;
    try {
        if (!csrf_valido()) {
            throw new RuntimeException('El formulario venció. Volvé a intentarlo.');
        }
        switch ($accion) {
            case 'guardar_curso':
                $productos = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) $_POST['productos'])))));
                // Un producto de WooCommerce solo puede habilitar un curso.
                foreach (cursos_por_producto() as $p => $otro) {
                    if ($otro !== $slug && in_array((string) $p, $productos, true)) {
                        throw new RuntimeException("El producto $p ya está asociado a otro curso («" . curso($otro)['titulo'] . '»).');
                    }
                }
                cambiar_curso($slug, function (array $c) use ($productos) {
                    $c['titulo'] = trim(mb_substr((string) $_POST['titulo'], 0, 120)) ?: $c['titulo'];
                    $c['descripcion'] = trim(mb_substr((string) $_POST['descripcion'], 0, 400));
                    $c['acceso'] = ($_POST['acceso'] ?? '') === 'ilimitado' ? 'ilimitado' : 'anual';
                    $c['productos'] = $productos;
                    $c['publicado'] = !empty($_POST['publicado']);
                    return $c;
                });
                $aviso = 'Curso guardado.';
                break;

            case 'agregar_modulo':
                $titulo = trim(mb_substr((string) $_POST['titulo'], 0, 120));
                if ($titulo === '') {
                    throw new RuntimeException('Escribí el nombre del módulo.');
                }
                cambiar_curso($slug, function (array $c) use ($titulo) {
                    $c['modulos'][] = ['id' => nuevo_id(), 'titulo' => $titulo, 'lecciones' => []];
                    return $c;
                });
                $aviso = 'Módulo agregado.';
                break;

            case 'renombrar_modulo':
                $titulo = trim(mb_substr((string) $_POST['titulo'], 0, 120));
                cambiar_curso($slug, function (array $c) use ($id, $titulo) {
                    foreach ($c['modulos'] as &$m) {
                        if ($m['id'] === $id && $titulo !== '') {
                            $m['titulo'] = $titulo;
                        }
                    }
                    return $c;
                });
                $aviso = 'Módulo guardado.';
                break;

            case 'mover_modulo':
                cambiar_curso($slug, function (array $c) use ($id) {
                    $c['modulos'] = mover($c['modulos'], $id, ($_POST['dir'] ?? '') === 'arriba' ? -1 : 1);
                    return $c;
                });
                $aviso = 'Orden de los módulos actualizado.';
                break;

            case 'borrar_modulo':
                cambiar_curso($slug, function (array $c) use ($id) {
                    foreach ($c['modulos'] as $i => $m) {
                        if ($m['id'] === $id) {
                            if ($m['lecciones']) {
                                throw new RuntimeException('Para borrar un módulo, primero borrá o mové sus lecciones.');
                            }
                            if (!empty($m['recursos'])) {
                                throw new RuntimeException('Para borrar un bono, primero borrá sus archivos.');
                            }
                            array_splice($c['modulos'], $i, 1);
                        }
                    }
                    return $c;
                });
                $aviso = 'Módulo borrado.';
                break;

            case 'tipo_modulo':
                // Bono: módulo sin lecciones, solo archivos para descargar. No cuenta para el progreso.
                $esBono = ($_POST['bono'] ?? '') === '1';
                cambiar_curso($slug, function (array $c) use ($id, $esBono) {
                    foreach ($c['modulos'] as &$m) {
                        if ($m['id'] !== $id) {
                            continue;
                        }
                        if ($esBono && $m['lecciones']) {
                            throw new RuntimeException('Para convertirlo en bono, el módulo no puede tener lecciones: borralas o movelas a otro módulo.');
                        }
                        if (!$esBono && !empty($m['recursos'])) {
                            throw new RuntimeException('Para volverlo un módulo normal, primero borrá los archivos del bono.');
                        }
                        $m['bono'] = $esBono;
                        $m['recursos'] = $m['recursos'] ?? [];
                    }
                    return $c;
                });
                $aviso = $esBono ? 'Ahora es un bono. Subí los archivos para descargar.' : 'Volvió a ser un módulo con lecciones.';
                break;

            case 'subir_recurso_bono':
                $recurso = recurso_guardar_subida($_FILES['archivo'] ?? []);
                $nombreVisible = trim(mb_substr((string) ($_POST['nombre'] ?? ''), 0, 150));
                if ($nombreVisible !== '') {
                    $recurso['nombre'] = $nombreVisible . (pathinfo($nombreVisible, PATHINFO_EXTENSION) === '' ? '.' . pathinfo($recurso['archivo'], PATHINFO_EXTENSION) : '');
                }
                cambiar_curso($slug, function (array $c) use ($id, $recurso) {
                    foreach ($c['modulos'] as &$m) {
                        if ($m['id'] === $id && !empty($m['bono'])) {
                            $m['recursos'][] = $recurso;
                        }
                    }
                    return $c;
                });
                $aviso = 'Archivo agregado al bono: ' . $recurso['nombre'];
                break;

            case 'borrar_recurso_bono':
                $recursoId = (string) ($_POST['recurso'] ?? '');
                $borrado = null;
                cambiar_curso($slug, function (array $c) use ($id, $recursoId, &$borrado) {
                    foreach ($c['modulos'] as &$m) {
                        foreach ($m['recursos'] ?? [] as $i => $r) {
                            if ($m['id'] === $id && $r['id'] === $recursoId) {
                                $borrado = $r;
                                array_splice($m['recursos'], $i, 1);
                            }
                        }
                    }
                    return $c;
                });
                if ($borrado) {
                    recurso_borrar_archivo($borrado);
                }
                $aviso = 'Archivo borrado del bono.';
                break;

            case 'agregar_leccion':
                $titulo = trim(mb_substr((string) $_POST['titulo'], 0, 150));
                if ($titulo === '') {
                    throw new RuntimeException('Escribí el título de la lección.');
                }
                $nueva = nuevo_id();
                cambiar_curso($slug, function (array $c) use ($id, $titulo, $nueva) {
                    foreach ($c['modulos'] as &$m) {
                        if ($m['id'] === $id) {
                            $m['lecciones'][] = ['id' => $nueva, 'titulo' => $titulo, 'video' => '', 'texto' => '', 'recursos' => []];
                        }
                    }
                    return $c;
                });
                $destino = $volver . '&l=' . $nueva;
                $aviso = 'Lección creada. Elegí el video y guardá.';
                break;

            case 'mover_leccion':
                cambiar_curso($slug, function (array $c) use ($id) {
                    foreach ($c['modulos'] as &$m) {
                        $m['lecciones'] = mover($m['lecciones'], $id, ($_POST['dir'] ?? '') === 'arriba' ? -1 : 1);
                    }
                    return $c;
                });
                $aviso = 'Orden de las lecciones actualizado.';
                break;

            case 'cambiar_modulo_leccion':
                $moduloNuevo = (string) ($_POST['modulo'] ?? '');
                $nombreDestino = '';
                cambiar_curso($slug, function (array $c) use ($id, $moduloNuevo, &$nombreDestino) {
                    foreach ($c['modulos'] as $md) {
                        if ($md['id'] === $moduloNuevo) {
                            if (!empty($md['bono'])) {
                                throw new RuntimeException('Un bono no puede tener lecciones: elegí un módulo común.');
                            }
                            $nombreDestino = $md['titulo'];
                        }
                    }
                    if ($nombreDestino === '') {
                        throw new RuntimeException('No encontré el módulo de destino. Recargá la página y volvé a intentarlo.');
                    }
                    $mover = null;
                    foreach ($c['modulos'] as &$m) {
                        foreach ($m['lecciones'] as $i => $l) {
                            if ($l['id'] === $id && $m['id'] !== $moduloNuevo) {
                                $mover = $l;
                                array_splice($m['lecciones'], $i, 1);
                            }
                        }
                    }
                    unset($m);
                    foreach ($c['modulos'] as &$m) {
                        if ($mover && $m['id'] === $moduloNuevo) {
                            $m['lecciones'][] = $mover;
                        }
                    }
                    return $c;
                });
                // Desde el temario se vuelve al temario (al módulo de destino); desde la lección, a la lección.
                $destino = ($_POST['volver'] ?? '') === 'temario' ? $volver . '#modulo-' . rawurlencode($moduloNuevo) : $volver . '&l=' . rawurlencode($id);
                $aviso = 'Lección movida al final de «' . $nombreDestino . '». Con las flechas ↑ ↓ la ubicás donde quieras.';
                break;

            case 'guardar_leccion':
                $video = trim(mb_substr((string) ($_POST['video'] ?? ''), 0, 300));
                $archivoVideo = basename((string) ($_POST['video_archivo'] ?? ''));
                if ($archivoVideo !== '') {
                    // Un video del servidor tiene prioridad sobre el enlace.
                    if (!isset(videos_disponibles()[$archivoVideo])) {
                        throw new RuntimeException('Ese video ya no está en la carpeta de videos del servidor.');
                    }
                    $video = VIDEO_PREFIJO . $archivoVideo;
                } elseif ($video !== '' && video_embed($video) === '') {
                    throw new RuntimeException('No reconozco ese enlace de video. Pegá el enlace de YouTube (youtu.be/… o youtube.com/watch?v=…) o de Vimeo.');
                }
                cambiar_leccion($slug, $id, function (array $l) use ($video) {
                    $l['titulo'] = trim(mb_substr((string) $_POST['titulo'], 0, 150)) ?: $l['titulo'];
                    $l['video'] = $video;
                    $l['texto'] = trim(mb_substr((string) $_POST['texto'], 0, 20000));
                    return $l;
                });
                $destino = $volver . '&l=' . rawurlencode($id);
                $aviso = 'Lección guardada.';
                break;

            case 'borrar_leccion':
                $borrados = [];
                cambiar_curso($slug, function (array $c) use ($id, &$borrados) {
                    foreach ($c['modulos'] as &$m) {
                        foreach ($m['lecciones'] as $i => $l) {
                            if ($l['id'] === $id) {
                                $borrados = $l['recursos'];
                                array_splice($m['lecciones'], $i, 1);
                            }
                        }
                    }
                    return $c;
                });
                array_map('recurso_borrar_archivo', $borrados);
                $aviso = 'Lección borrada.';
                break;

            case 'subir_recurso':
                $destino = $volver . '&l=' . rawurlencode($id);
                $recurso = recurso_guardar_subida($_FILES['archivo'] ?? []);
                $nombreVisible = trim(mb_substr((string) ($_POST['nombre'] ?? ''), 0, 150));
                if ($nombreVisible !== '') {
                    $recurso['nombre'] = $nombreVisible . (pathinfo($nombreVisible, PATHINFO_EXTENSION) === '' ? '.' . pathinfo($recurso['archivo'], PATHINFO_EXTENSION) : '');
                }
                cambiar_leccion($slug, $id, function (array $l) use ($recurso) {
                    $l['recursos'][] = $recurso;
                    return $l;
                });
                $aviso = 'Archivo agregado: ' . $recurso['nombre'];
                break;

            case 'borrar_recurso':
                $destino = $volver . '&l=' . rawurlencode($id);
                $recursoId = (string) ($_POST['recurso'] ?? '');
                $borrado = null;
                cambiar_leccion($slug, $id, function (array $l) use ($recursoId, &$borrado) {
                    foreach ($l['recursos'] as $i => $r) {
                        if ($r['id'] === $recursoId) {
                            $borrado = $r;
                            array_splice($l['recursos'], $i, 1);
                        }
                    }
                    return $l;
                });
                if ($borrado) {
                    recurso_borrar_archivo($borrado);
                }
                $aviso = 'Archivo borrado.';
                break;

            case 'certificado':
                $imagenNueva = !empty($_FILES['imagen']['name']) ? certificado_guardar_imagen($_FILES['imagen']) : null;
                $anterior = (string) (curso($slug)['certificado']['imagen'] ?? '');
                cambiar_curso($slug, function (array $c) use ($imagenNueva) {
                    $cert = (array) ($c['certificado'] ?? []);
                    if ($imagenNueva) {
                        $cert['imagen'] = $imagenNueva;
                    }
                    $cert['textos'] = array_replace_recursive((array) ($cert['textos'] ?? []), certificado_ajustes_de_post($_POST));
                    unset($cert['nombre_y'], $cert['nombre_tam'], $cert['datos_y']); // formato anterior
                    if (isset($_POST['color'])) {
                        $cert['color'] = (string) $_POST['color'];
                    }
                    $c['certificado'] = $cert;
                    return $c;
                });
                if ($imagenNueva && $anterior !== '' && is_file(certificados_dir() . '/' . basename($anterior))) {
                    unlink(certificados_dir() . '/' . basename($anterior));
                }
                // Desde el ejemplo con arrastre se vuelve al ejemplo; desde el editor, al editor.
                $destino = !empty($_POST['volver_ejemplo']) ? 'certificado.php?c=' . rawurlencode($slug) . '&vista=1' : $volver . '#certificado';
                $aviso = !empty($_POST['volver_ejemplo']) ? 'Posiciones del certificado guardadas.' : 'Certificado guardado.';
                break;

            case 'quitar_certificado':
                $anterior = (string) (curso($slug)['certificado']['imagen'] ?? '');
                cambiar_curso($slug, function (array $c) {
                    unset($c['certificado']['imagen']);
                    return $c;
                });
                if ($anterior !== '' && is_file(certificados_dir() . '/' . basename($anterior))) {
                    unlink(certificados_dir() . '/' . basename($anterior));
                }
                $destino = $volver . '#certificado';
                $aviso = 'Este curso ya no entrega certificado.';
                break;

            case 'borrar_curso':
                $conAcceso = array_filter(alumnos_todos(), fn ($a) => isset($a['cursos'][$slug]));
                if ($conAcceso) {
                    throw new RuntimeException('No se puede borrar: hay ' . count($conAcceso) . ' personas con acceso a este curso. Despublicalo en su lugar.');
                }
                if (trim((string) ($_POST['confirmar'] ?? '')) !== 'BORRAR') {
                    throw new RuntimeException('Para borrar el curso escribí BORRAR en mayúsculas.');
                }
                $c = curso($slug);
                foreach (curso_lecciones($c) as $l) {
                    array_map('recurso_borrar_archivo', $l['recursos']);
                }
                foreach ($c['modulos'] as $m) {
                    array_map('recurso_borrar_archivo', $m['recursos'] ?? []);
                }
                cursos_cambiar(function (array $cursos) use ($slug) {
                    unset($cursos[$slug]);
                    return $cursos;
                });
                panel_flash(true, 'Curso «' . $c['titulo'] . '» borrado.');
                header('Location: admin.php', true, 303);
                exit;

            default:
                throw new RuntimeException('Acción desconocida. Recargá la página y volvé a intentarlo.');
        }
        panel_flash(true, $aviso ?: 'Cambios guardados.');
        header('Location: ' . $destino, true, 303);
        exit;
    } catch (Throwable $ex) {
        $aviso = [false, $ex->getMessage()];
    }
}

// Aviso que viene de la acción anterior (después de redirigir).
$aviso = $aviso ?? panel_leer_flash();

$curso = curso($slug);
$leccion = $leccionId !== '' ? curso_leccion($curso, $leccionId) : null;
$resumenCalif = resumen_calificaciones();
$csrf = token_csrf();
$campoCsrf = '<input type="hidden" name="csrf" value="' . e($csrf) . '">';

pagina_inicio('Editar · ' . $curso['titulo']);
?>
<section class="contenedor panel tablero editor">
    <?php panel_barra($curso['titulo'], '<a class="boton boton--contorno boton--mini" href="curso.php?c=' . e($slug) . ($leccion ? '&l=' . e($leccion['id']) : '') . '" target="_blank">👁 Ver como alumna</a>'); ?>
    <?= panel_aviso($aviso) ?>

<?php if ($leccion): ?>
    <!-- ── Edición de una lección ─────────────────────────────────────── -->
    <p><a href="<?= e($volver) ?>">← Volver al temario</a> · Módulo: <strong><?= e($leccion['modulo']) ?></strong></p>
    <div class="tablero__columnas">
        <div class="tarjeta-panel">
            <h2>Lección</h2>
            <form method="post" class="formulario">
                <?= $campoCsrf ?>
                <input type="hidden" name="accion" value="guardar_leccion">
                <input type="hidden" name="id" value="<?= e($leccion['id']) ?>">
                <label>Título <input type="text" name="titulo" required maxlength="150" value="<?= e($leccion['titulo']) ?>"></label>
                <?php $archivoActual = video_archivo($leccion['video']); $disponibles = videos_disponibles(); ?>
                <div class="editor__video-opciones">
                    <label>Video del servidor <small>(protegido: solo lo ven alumnas con acceso)</small>
                        <select name="video_archivo">
                            <option value="">— Ninguno (usar el enlace de abajo) —</option>
                            <?php foreach ($disponibles as $nombreVideo => $bytes): ?>
                                <option value="<?= e($nombreVideo) ?>"<?= $nombreVideo === $archivoActual ? ' selected' : '' ?>><?= e($nombreVideo) ?> · <?= e(tamano_legible($bytes)) ?></option>
                            <?php endforeach; ?>
                            <?php if ($archivoActual !== '' && !isset($disponibles[$archivoActual])): ?>
                                <option value="" selected>⚠ <?= e($archivoActual) ?> (ya no está en el servidor)</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <?php if (!$disponibles): ?>
                        <p>Todavía no hay videos. Subilos por FTP o con el administrador de archivos a
                            <code><?= e((USA_EXTERNA ? EXTERNA : 'cursos/privado') . '/' . CURSOS_DIR_VIDEOS . '/') ?></code> (MP4) y recargá esta página.</p>
                    <?php endif; ?>
                    <label>O enlace de YouTube / Vimeo <small>(un video no listado de YouTube se puede compartir)</small>
                        <input type="url" name="video" maxlength="300" value="<?= e($archivoActual === '' ? $leccion['video'] : '') ?>" placeholder="https://youtu.be/…">
                    </label>
                </div>
                <?php if ($archivoActual !== '' && isset($disponibles[$archivoActual])): ?>
                    <div class="leccion__video leccion__video--mini"><video src="video.php?c=<?= e($slug) ?>&l=<?= e($leccion['id']) ?>" controls preload="metadata"></video></div>
                <?php elseif ($leccion['video'] !== '' && ($embed = video_embed($leccion['video'])) !== ''): ?>
                    <div class="leccion__video leccion__video--mini"><iframe src="<?= e($embed) ?>" title="Vista previa" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
                <?php endif; ?>
                <label>Texto de la lección <small>(opcional: una línea en blanco separa párrafos; los enlaces se vuelven clicables)</small>
                    <textarea name="texto" rows="8"><?= e($leccion['texto']) ?></textarea>
                </label>
                <button class="boton boton--mini" type="submit">Guardar lección</button>
            </form>
        </div>
        <div class="tablero__lateral">
            <div class="tarjeta-panel">
                <h2>Archivos descargables</h2>
                <?php if (!$leccion['recursos']): ?><p class="vacio">Sin archivos.</p><?php endif; ?>
                <ul class="lista-simple">
                    <?php foreach ($leccion['recursos'] as $r): ?>
                        <li class="fila-accion">
                            <a href="recurso.php?c=<?= e($slug) ?>&l=<?= e($leccion['id']) ?>&r=<?= e($r['id']) ?>"><?= e($r['nombre']) ?></a>
                            <small><?= e(tamano_legible((int) $r['tamano'])) ?></small>
                            <form method="post" onsubmit="return confirm('¿Borrar este archivo?')">
                                <?= $campoCsrf ?>
                                <input type="hidden" name="accion" value="borrar_recurso">
                                <input type="hidden" name="id" value="<?= e($leccion['id']) ?>">
                                <input type="hidden" name="recurso" value="<?= e($r['id']) ?>">
                                <button class="enlace-boton enlace-boton--peligro" type="submit">Borrar</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" enctype="multipart/form-data" class="formulario">
                    <?= $campoCsrf ?>
                    <input type="hidden" name="accion" value="subir_recurso">
                    <input type="hidden" name="id" value="<?= e($leccion['id']) ?>">
                    <label>Archivo <small>(máx. <?= e((string) ini_get('upload_max_filesize')) ?>: <?= e(implode(', ', CURSOS_EXTENSIONES)) ?>)</small>
                        <input type="file" name="archivo" required>
                    </label>
                    <label>Nombre que ve la alumna <small>(opcional)</small> <input type="text" name="nombre" maxlength="150" placeholder="Plantilla de práctica"></label>
                    <button class="boton boton--contorno boton--mini" type="submit">Subir archivo</button>
                </form>
            </div>
            <div class="tarjeta-panel">
                <h2>Mover o borrar</h2>
                <form method="post" class="formulario">
                    <?= $campoCsrf ?>
                    <input type="hidden" name="accion" value="cambiar_modulo_leccion">
                    <input type="hidden" name="id" value="<?= e($leccion['id']) ?>">
                    <label>Pasar a otro módulo
                        <select name="modulo">
                            <?php foreach ($curso['modulos'] as $m): if (!empty($m['bono'])) { continue; } ?>
                                <option value="<?= e($m['id']) ?>"<?= $m['id'] === $leccion['modulo_id'] ? ' selected' : '' ?>><?= e($m['titulo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="boton boton--contorno boton--mini" type="submit">Mover</button>
                </form>
                <form method="post" onsubmit="return confirm('¿Borrar la lección y sus archivos? Quienes ya la vieron dejan de tenerla en su progreso.')">
                    <?= $campoCsrf ?>
                    <input type="hidden" name="accion" value="borrar_leccion">
                    <input type="hidden" name="id" value="<?= e($leccion['id']) ?>">
                    <p><button class="enlace-boton enlace-boton--peligro" type="submit">Borrar esta lección</button></p>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ── Curso y temario ─────────────────────────────────────────────── -->
    <div class="tablero__columnas">
        <div class="tarjeta-panel">
            <h2>Temario</h2>
            <?php if (!$curso['modulos']): ?><p class="vacio">Empezá agregando un módulo (por ejemplo «Introducción»).</p><?php endif; ?>
            <?php $numModulo = 0; foreach ($curso['modulos'] as $i => $m): $esBono = !empty($m['bono']); if (!$esBono) { $numModulo++; } ?>
                <div class="editor__modulo<?= $esBono ? ' editor__modulo--bono' : '' ?>" id="modulo-<?= e($m['id']) ?>">
                    <div class="fila-accion">
                        <form method="post" class="formulario--en-linea editor__titulo">
                            <?= $campoCsrf ?>
                            <input type="hidden" name="accion" value="renombrar_modulo">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <span class="editor__num"><?= $esBono ? '🎁 Bono' : 'Módulo ' . $numModulo ?></span>
                            <input type="text" name="titulo" value="<?= e($m['titulo']) ?>" maxlength="120" aria-label="Nombre del módulo">
                            <button class="boton boton--contorno boton--mini" type="submit">Guardar</button>
                        </form>
                        <form method="post" class="editor__orden">
                            <?= $campoCsrf ?>
                            <input type="hidden" name="accion" value="mover_modulo">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <button class="enlace-boton" type="submit" name="dir" value="arriba" title="Subir"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                            <button class="enlace-boton" type="submit" name="dir" value="abajo" title="Bajar"<?= $i === count($curso['modulos']) - 1 ? ' disabled' : '' ?>>↓</button>
                        </form>
                        <?php if (!$m['lecciones'] && empty($m['recursos'])): ?>
                            <form method="post">
                                <?= $campoCsrf ?>
                                <input type="hidden" name="accion" value="borrar_modulo">
                                <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                                <button class="enlace-boton enlace-boton--peligro" type="submit">Borrar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($esBono): ?>
                        <p class="editor__bono-ayuda">Solo archivos para descargar. Se ve en el temario con 🎁 y <strong>no cuenta</strong> para el progreso ni para el certificado.</p>
                        <ul class="editor__lecciones editor__bono-archivos">
                            <?php foreach ($m['recursos'] ?? [] as $r): ?>
                                <li class="fila-accion">
                                    <a href="recurso.php?c=<?= e($slug) ?>&m=<?= e($m['id']) ?>&r=<?= e($r['id']) ?>">⬇ <?= e($r['nombre']) ?></a>
                                    <small><?= e(tamano_legible((int) $r['tamano'])) ?></small>
                                    <form method="post" onsubmit="return confirm('¿Borrar este archivo del bono?')">
                                        <?= $campoCsrf ?>
                                        <input type="hidden" name="accion" value="borrar_recurso_bono">
                                        <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                                        <input type="hidden" name="recurso" value="<?= e($r['id']) ?>">
                                        <button class="enlace-boton enlace-boton--peligro" type="submit">Borrar</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($m['recursos'])): ?><li class="vacio">Todavía no tiene archivos.</li><?php endif; ?>
                        </ul>
                        <form method="post" enctype="multipart/form-data" class="formulario--en-linea editor__bono-subir">
                            <?= $campoCsrf ?>
                            <input type="hidden" name="accion" value="subir_recurso_bono">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <input type="file" name="archivo" required aria-label="Archivo del bono">
                            <input type="text" name="nombre" maxlength="150" placeholder="Nombre que ve la alumna (opcional)" aria-label="Nombre que ve la alumna">
                            <button class="boton boton--contorno boton--mini" type="submit">+ Subir archivo</button>
                        </form>
                        <?php if (empty($m['recursos'])): ?>
                            <form method="post" class="editor__tipo">
                                <?= $campoCsrf ?>
                                <input type="hidden" name="accion" value="tipo_modulo">
                                <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                                <button class="enlace-boton" type="submit" name="bono" value="0">Volver a módulo con lecciones</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                    <ol class="editor__lecciones">
                        <?php foreach ($m['lecciones'] as $j => $l): ?>
                            <li class="fila-accion">
                                <a href="<?= e($volver . '&l=' . rawurlencode($l['id'])) ?>"><?= e($l['titulo']) ?></a>
                                <?php $calif = $resumenCalif[$slug . '|' . $l['id']] ?? null; ?>
                                <?php if ($calif): ?><small class="editor__calif" title="<?= $calif['votos'] ?> votos">★ <?= e(number_format($calif['promedio'], 1, ',', '')) ?> (<?= $calif['votos'] ?>)</small><?php endif; ?>
                                <small><?= video_archivo($l['video']) !== '' ? '🔒🎬' : ($l['video'] !== '' ? '🎬' : '⚠ sin video') ?><?= $l['recursos'] ? ' · 📎 ' . count($l['recursos']) : '' ?></small>
                                <form method="post" class="editor__orden">
                                    <?= $campoCsrf ?>
                                    <input type="hidden" name="accion" value="mover_leccion">
                                    <input type="hidden" name="id" value="<?= e($l['id']) ?>">
                                    <button class="enlace-boton" type="submit" name="dir" value="arriba" title="Subir"<?= $j === 0 ? ' disabled' : '' ?>>↑</button>
                                    <button class="enlace-boton" type="submit" name="dir" value="abajo" title="Bajar"<?= $j === count($m['lecciones']) - 1 ? ' disabled' : '' ?>>↓</button>
                                </form>
                                <?php $otrosModulos = array_filter($curso['modulos'], fn ($om) => $om['id'] !== $m['id'] && empty($om['bono'])); ?>
                                <?php if ($otrosModulos): ?>
                                    <form method="post" class="editor__mover">
                                        <?= $campoCsrf ?>
                                        <input type="hidden" name="accion" value="cambiar_modulo_leccion">
                                        <input type="hidden" name="id" value="<?= e($l['id']) ?>">
                                        <input type="hidden" name="volver" value="temario">
                                        <select name="modulo" aria-label="Mover «<?= e($l['titulo']) ?>» a otro módulo" onchange="if (this.value) this.form.submit()">
                                            <option value="">Mover a…</option>
                                            <?php foreach ($otrosModulos as $om): ?><option value="<?= e($om['id']) ?>"><?= e($om['titulo']) ?></option><?php endforeach; ?>
                                        </select>
                                        <noscript><button class="enlace-boton" type="submit">Mover</button></noscript>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <form method="post" class="formulario--en-linea">
                        <?= $campoCsrf ?>
                        <input type="hidden" name="accion" value="agregar_leccion">
                        <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                        <input type="text" name="titulo" placeholder="Título de la lección nueva" required maxlength="150" aria-label="Título de la lección nueva">
                        <button class="boton boton--contorno boton--mini" type="submit">+ Crear lección</button>
                    </form>
                    <?php if (!$m['lecciones']): ?>
                        <form method="post" class="editor__tipo">
                            <?= $campoCsrf ?>
                            <input type="hidden" name="accion" value="tipo_modulo">
                            <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                            <button class="enlace-boton" type="submit" name="bono" value="1">🎁 Convertir en bono (solo archivos para descargar)</button>
                        </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <form method="post" class="editor__nuevo-modulo">
                <?= $campoCsrf ?>
                <input type="hidden" name="accion" value="agregar_modulo">
                <label for="nuevo-modulo">➕ Agregar el módulo <?= $numModulo + 1 ?>
                    <small>Escribí el nombre y tocá «Crear módulo» (o Enter). Después le agregás las lecciones, o lo convertís en bono.</small></label>
                <div class="formulario--en-linea">
                    <input type="text" id="nuevo-modulo" name="titulo" placeholder="Ej.: De la meta al hábito" required maxlength="120">
                    <button class="boton boton--mini" type="submit">Crear módulo</button>
                </div>
            </form>
        </div>

        <div class="tablero__lateral">
            <div class="tarjeta-panel">
                <h2>Datos del curso</h2>
                <form method="post" class="formulario">
                    <?= $campoCsrf ?>
                    <input type="hidden" name="accion" value="guardar_curso">
                    <label>Nombre <input type="text" name="titulo" required maxlength="120" value="<?= e($curso['titulo']) ?>"></label>
                    <label>Descripción corta <small>(se ve en «Mis cursos»)</small>
                        <textarea name="descripcion" rows="3" maxlength="400"><?= e($curso['descripcion']) ?></textarea>
                    </label>
                    <label>Duración del acceso
                        <select name="acceso">
                            <option value="anual"<?= ($curso['acceso'] ?? 'anual') === 'anual' ? ' selected' : '' ?>><?= MESES_ACCESO_ANUAL ?> meses desde la fecha de pago</option>
                            <option value="ilimitado"<?= ($curso['acceso'] ?? '') === 'ilimitado' ? ' selected' : '' ?>>Mientras el curso esté disponible (sin vencimiento)</option>
                        </select>
                    </label>
                    <label>Productos de WooCommerce que habilitan este curso <small>(ID o SKU, separados por coma)</small>
                        <input type="text" name="productos" value="<?= e(implode(', ', $curso['productos'] ?? [])) ?>" placeholder="123">
                    </label>
                    <label class="check"><input type="checkbox" name="publicado" value="1"<?= !empty($curso['publicado']) ? ' checked' : '' ?>> Publicado (las alumnas con acceso lo pueden abrir)</label>
                    <button class="boton boton--mini" type="submit">Guardar</button>
                </form>
                <p class="nota">Cambiar la duración no modifica los accesos ya dados: cada uno conserva su fecha.</p>
            </div>

            <div class="tarjeta-panel" id="certificado">
                <h2>Certificado</h2>
                <?php $aj = certificado_ajustes($curso); $tieneImagen = !empty($curso['certificado']['imagen']); ?>
                <p class="editor__estado-cert <?= $tieneImagen ? 'editor__estado-cert--si' : '' ?>">
                    <?= $tieneImagen ? '✓ Este curso entrega certificado al completar el 100 % de las lecciones.' : 'Este curso no entrega certificado. Es opcional: subí una imagen solo si querés darlo.' ?></p>
                <p class="nota">Subí tu diseño como imagen (PNG o JPG, idealmente 2000 px de ancho) <strong>sin</strong> nombre ni fecha:
                    se escriben encima.</p>
                <form method="post" enctype="multipart/form-data" class="formulario">
                    <?= $campoCsrf ?>
                    <input type="hidden" name="accion" value="certificado">
                    <label><?= $tieneImagen ? 'Reemplazar imagen' : 'Imagen del certificado (opcional)' ?>
                        <input type="file" name="imagen" accept="image/png,image/jpeg,image/webp"<?= $tieneImagen ? '' : ' required' ?>>
                    </label>
                    <?php if ($tieneImagen): ?>
                        <p><a class="boton boton--mini" href="certificado.php?c=<?= e($slug) ?>&vista=1" target="_blank">✋ Acomodar los textos arrastrándolos</a></p>
                    <?php endif; ?>
                    <details class="editor__ajustes-detalle">
                        <summary>Ajuste fino con números</summary>
                        <p class="nota">Posición en % de la imagen (el texto queda centrado en ese punto). Tamaño en % del ancho.</p>
                        <table class="editor__ajustes-tabla">
                            <thead><tr><th></th><th>Horizontal %</th><th>Vertical %</th><th>Tamaño</th><th>Color</th></tr></thead>
                            <tbody>
                            <?php foreach (CERT_TEXTOS as $campo => $def): $t = $aj['textos'][$campo]; ?>
                                <tr>
                                    <th><?= e($def['etiqueta']) ?></th>
                                    <td><input type="number" name="<?= $campo ?>_x" min="0" max="100" step="0.1" value="<?= e((string) $t['x']) ?>" aria-label="<?= e($def['etiqueta']) ?>: horizontal"></td>
                                    <td><input type="number" name="<?= $campo ?>_y" min="0" max="100" step="0.1" value="<?= e((string) $t['y']) ?>" aria-label="<?= e($def['etiqueta']) ?>: vertical"></td>
                                    <td><input type="number" name="<?= $campo ?>_tam" min="0.5" max="10" step="0.05" value="<?= e((string) $t['tam']) ?>" aria-label="<?= e($def['etiqueta']) ?>: tamaño"></td>
                                    <td><input type="color" name="<?= $campo ?>_color" value="<?= e($t['color']) ?>" aria-label="<?= e($def['etiqueta']) ?>: color"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                    <button class="boton boton--mini" type="submit">Guardar certificado</button>
                </form>
                <?php if ($tieneImagen): ?>
                    <p><a href="certificado.php?c=<?= e($slug) ?>&vista=1" target="_blank">👁 Ver un ejemplo con nombre ficticio</a></p>
                    <form method="post" onsubmit="return confirm('¿Dejar de entregar certificado en este curso? Quienes ya lo obtuvieron dejan de verlo hasta que vuelvas a subir una imagen.')">
                        <?= $campoCsrf ?>
                        <input type="hidden" name="accion" value="quitar_certificado">
                        <button class="enlace-boton enlace-boton--peligro" type="submit">Quitar el certificado de este curso</button>
                    </form>
                <?php endif; ?>
            </div>

            <details class="herramientas">
                <summary>Borrar el curso</summary>
                <form method="post" class="formulario">
                    <?= $campoCsrf ?>
                    <input type="hidden" name="accion" value="borrar_curso">
                    <p class="nota">Solo se puede borrar si nadie tiene acceso. Si ya vendiste, despublicalo.</p>
                    <label>Escribí BORRAR para confirmar <input type="text" name="confirmar" autocomplete="off"></label>
                    <button class="enlace-boton enlace-boton--peligro" type="submit">Borrar curso y sus archivos</button>
                </form>
            </details>
        </div>
    </div>
<?php endif; ?>
</section>
<?php
pagina_fin();
