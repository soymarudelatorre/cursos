<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Panel de cursos: crear cursos, habilitar accesos a mano, ver el avance de cada alumna,
 * revisar los avisos de WooCommerce y exportar los datos.
 */
panel_exigir_ingreso();

$aviso = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) {
        $aviso = [false, 'El formulario venció. Volvé a intentarlo.'];
    } elseif (isset($_POST['nuevo_curso'])) {
        $titulo = trim(mb_substr((string) $_POST['nuevo_curso'], 0, 120));
        if ($titulo === '') {
            $aviso = [false, 'Escribí el nombre del curso.'];
        } else {
            $slug = '';
            cursos_cambiar(function (array $cursos) use ($titulo, &$slug) {
                $slug = crear_slug($titulo, $cursos);
                $cursos[$slug] = ['titulo' => $titulo, 'descripcion' => '', 'acceso' => 'anual', 'productos' => [],
                    'publicado' => false, 'creado' => date('Y-m-d H:i:s'), 'modulos' => [], 'certificado' => []];
                return $cursos;
            });
            panel_flash(true, "Curso «{$titulo}» creado. Agregá los módulos y las lecciones.");
            header('Location: admin-curso.php?c=' . rawurlencode($slug), true, 303);
            exit;
        }
    } elseif (isset($_POST['habilitar'])) {
        // Una línea por persona: "email", "email, nombre", "nombre, email" o "Nombre <email>".
        $slug = (string) ($_POST['curso'] ?? '');
        $fecha = fecha_valida((string) ($_POST['fecha'] ?? '')) ?? date('Y-m-d');
        $curso = curso($slug);
        $agregados = [];
        $invalidas = [];
        if (!$curso) {
            $aviso = [false, 'Elegí un curso.'];
        } else {
            foreach (preg_split('/\R/', (string) $_POST['habilitar']) as $linea) {
                $linea = trim($linea);
                if ($linea === '') {
                    continue;
                }
                if (!preg_match('/[^\s,;<>"]+@[^\s,;<>"]+/', $linea, $m) || !filter_var($m[0], FILTER_VALIDATE_EMAIL)) {
                    $invalidas[] = $linea;
                    continue;
                }
                $nombre = mb_substr(trim(str_replace($m[0], '', $linea), " \t,;<>\""), 0, 100);
                alumno_habilitar($m[0], $nombre, $slug, $fecha, 'manual');
                $agregados[normalizar_email($m[0])] = $nombre;
            }
            $texto = 'Habilitados en «' . $curso['titulo'] . '»: ' . count($agregados) . '.';
            $ok = !$invalidas;
            if (!empty($_POST['invitar']) && $agregados) {
                $fallaron = [];
                foreach ($agregados as $em => $nombre) {
                    $a = alumno($em);
                    try {
                        enviar_invitacion($em, $nombre !== '' ? $nombre : (string) ($a['nombre'] ?? ''), $curso, $a['cursos'][$slug]['hasta'] ?? null);
                    } catch (Throwable $ex) {
                        $fallaron[] = "$em (" . $ex->getMessage() . ')';
                    }
                }
                $texto .= ' Invitaciones enviadas: ' . (count($agregados) - count($fallaron)) . '.'
                    . ($fallaron ? ' No se pudieron enviar: ' . implode(' · ', $fallaron) : '');
                $ok = $ok && !$fallaron;
            }
            if (!empty($curso) && empty($curso['publicado'])) {
                $texto .= ' Ojo: el curso todavía no está publicado, así que no lo pueden abrir.';
            }
            $aviso = [$ok, $texto . ($invalidas ? ' Sin email válido (no se cargaron): ' . implode(' · ', $invalidas) : '')];
        }
    } elseif (isset($_POST['acceso_email'], $_POST['acceso_curso'])) {
        $em = normalizar_email((string) $_POST['acceso_email']);
        $slug = (string) $_POST['acceso_curso'];
        if (isset($_POST['revocar'])) {
            alumno_revocar($em, $slug, true);
            $aviso = [true, "Se quitó el acceso de $em. Su progreso se conserva."];
        } elseif (isset($_POST['restaurar'])) {
            alumno_revocar($em, $slug, false);
            $aviso = [true, "Se restauró el acceso de $em."];
        } elseif (isset($_POST['hasta'])) {
            $nueva = trim((string) $_POST['hasta']);
            if ($nueva === '') {
                alumno_cambiar_hasta($em, $slug, null);
                $aviso = [true, "$em ahora tiene acceso sin vencimiento."];
            } elseif ($f = fecha_valida($nueva)) {
                alumno_cambiar_hasta($em, $slug, $f);
                $aviso = [true, "Nuevo vencimiento de $em: " . fecha_corta($f) . '.'];
            } else {
                $aviso = [false, 'La fecha no es válida.'];
            }
        }
    } elseif (isset($_POST['enviar_backup'])) {
        $smtp = config()['smtp'];
        $destino = (string) (config()['email_backup'] ?? '') ?: (string) $smtp['usuario'];
        $adjuntos = [];
        foreach ([CURSOS_ARCHIVO, ALUMNOS_ARCHIVO, WOO_ARCHIVO_PEDIDOS, WOO_ARCHIVO_AVISOS] as $archivo) {
            if (is_file(PRIVADO . '/' . $archivo)) {
                $adjuntos[date('Y-m-d') . "-$archivo"] = (string) file_get_contents(PRIVADO . '/' . $archivo);
            }
        }
        if (empty($smtp['contrasena'])) {
            $aviso = [false, 'No se pudo enviar el backup: falta la contraseña de la casilla en config.php.'];
        } elseif (!$adjuntos) {
            $aviso = [false, 'Todavía no hay datos para respaldar.'];
        } else {
            try {
                enviar_email($destino, 'Backup cursos', 'Backup de cursos · ' . date('d/m/Y'),
                    '<p>Adjunto el backup de los cursos (catálogo, alumnas, progreso y pedidos). Los videos están en YouTube y los archivos descargables en la carpeta privada del servidor: no van en este correo.</p>',
                    "Adjunto el backup de los cursos (catálogo, alumnas, progreso y pedidos).\nLos archivos descargables están en la carpeta privada del servidor: no van en este correo.",
                    $adjuntos);
                $aviso = [true, "Backup enviado a $destino."];
            } catch (Throwable $ex) {
                $aviso = [false, 'No se pudo enviar el backup: ' . $ex->getMessage()];
            }
        }
    }
}

// Después de una acción: se redirige con el resultado (así F5 no repite la acción).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    panel_flash($aviso[0] ?? false, $aviso[1] ?? 'No se reconoció la acción. Recargá la página y volvé a intentarlo.');
    header('Location: admin.php', true, 303);
    exit;
}
$aviso = panel_leer_flash();

$cursos = cursos_todos();
$alumnos = alumnos_todos();

// Una fila por persona y curso.
$filas = [];
foreach ($alumnos as $em => $a) {
    foreach ($a['cursos'] ?? [] as $slug => $acceso) {
        $c = isset($cursos[$slug]) ? $cursos[$slug] + ['slug' => $slug] : null;
        [$hechas, $total, $pct] = $c ? progreso($a, $c) : [0, 0, 0];
        $vistas = $a['progreso'][$slug] ?? [];
        $filas[] = [
            'email' => $em, 'nombre' => (string) ($a['nombre'] ?? ''), 'slug' => $slug,
            'curso' => $c['titulo'] ?? "($slug, borrado)", 'desde' => (string) $acceso['desde'], 'hasta' => $acceso['hasta'],
            'origen' => (string) $acceso['origen'], 'pedido' => (string) ($acceso['pedido'] ?? ''), 'estado' => acceso_estado($acceso),
            'hechas' => $hechas, 'total' => $total, 'pct' => $pct, 'ultima_actividad' => $vistas ? max($vistas) : '',
            'certificado' => (string) ($a['certificado'][$slug]['codigo'] ?? ''),
        ];
    }
}
usort($filas, fn ($x, $y) => strcmp($y['desde'], $x['desde']));

// Exportación CSV (una fila por persona y curso).
if (($_GET['exportar'] ?? '') === 'alumnos') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cursos-alumnos-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel reconoce los acentos
    fputcsv($out, ['email', 'nombre', 'curso', 'desde', 'hasta', 'estado', 'origen', 'pedido', 'lecciones vistas', 'total lecciones', 'progreso %', 'última actividad', 'certificado'], ',', '"', '');
    foreach ($filas as $f) {
        fputcsv($out, array_map(fn ($v) => preg_replace('/^([=+\-@\t\r])/', "'$1", (string) $v), [
            $f['email'], $f['nombre'], $f['curso'], $f['desde'], $f['hasta'] ?? 'sin vencimiento', $f['estado'], $f['origen'],
            $f['pedido'], $f['hechas'], $f['total'], $f['pct'], $f['ultima_actividad'], $f['certificado'],
        ]), ',', '"', '');
    }
    exit;
}

$activos = count(array_filter($filas, fn ($f) => $f['estado'] === 'activo'));
$certificados = count(array_filter($filas, fn ($f) => $f['certificado'] !== ''));
$avisosWoo = array_slice(array_reverse(leer_registros(WOO_ARCHIVO_AVISOS)), 0, 15);
$csrf = token_csrf();

pagina_inicio('Panel');
?>
<section class="contenedor panel tablero">
    <?php panel_barra('Panel de cursos', '<a class="boton boton--contorno boton--mini" href="admin.php?exportar=alumnos">Exportar CSV</a>'); ?>

    <?= panel_aviso($aviso) ?>
    <?php if (!empty(config()['modo_demo'])): ?>
        <p class="aviso aviso--mini">Modo demostración activo: si falta la contraseña del correo, el enlace de acceso se muestra en pantalla. Ponelo en <code>false</code> al publicar.</p>
    <?php endif; ?>

    <ul class="kpis">
        <li><span>Cursos</span><strong><?= count($cursos) ?></strong></li>
        <li><span>Personas</span><strong><?= count($alumnos) ?></strong></li>
        <li><span>Accesos vigentes</span><strong><?= $activos ?></strong></li>
        <li><span>Certificados</span><strong><?= $certificados ?></strong></li>
    </ul>

    <div class="tablero__columnas">
        <div class="tarjeta-panel">
            <h2>Cursos</h2>
            <?php if (!$cursos): ?><p class="vacio">Todavía no hay cursos. Creá el primero acá abajo.</p><?php endif; ?>
            <ul class="lista-simple">
                <?php foreach ($cursos as $slug => $c):
                    $n = count(curso_lecciones($c));
                    $personas = count(array_filter($filas, fn ($f) => $f['slug'] === $slug && $f['estado'] === 'activo'));
                    ?>
                    <li class="curso-fila">
                        <a href="admin-curso.php?c=<?= e($slug) ?>"><strong><?= e($c['titulo']) ?></strong></a>
                        <?= empty($c['publicado']) ? '<span class="etiqueta etiqueta--gris">Borrador</span>' : '<span class="etiqueta">Publicado</span>' ?>
                        <small><?= $n ?> lecciones · <?= $personas ?> con acceso vigente · <?= ($c['acceso'] ?? 'anual') === 'ilimitado' ? 'sin vencimiento' : MESES_ACCESO_ANUAL . ' meses' ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" class="formulario formulario--en-linea">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="text" name="nuevo_curso" placeholder="Nombre del curso nuevo" required maxlength="120" aria-label="Nombre del curso nuevo">
                <button class="boton boton--mini" type="submit">+ Crear curso</button>
            </form>
        </div>

        <div class="tarjeta-panel">
            <h2>Habilitar acceso a mano</h2>
            <?php if (!$cursos): ?>
                <p class="vacio">Primero creá un curso.</p>
            <?php else: ?>
                <form method="post" class="formulario">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <label>Curso
                        <select name="curso" required>
                            <?php foreach ($cursos as $slug => $c): ?><option value="<?= e($slug) ?>"><?= e($c['titulo']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Personas <small>(una por línea: email, o «email, nombre»)</small>
                        <textarea name="habilitar" rows="3" required placeholder="ana@ejemplo.com, Ana Pérez"></textarea>
                    </label>
                    <label>Fecha de pago <small>(el vencimiento se cuenta desde acá)</small>
                        <input type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>" required>
                    </label>
                    <label class="check"><input type="checkbox" name="invitar" value="1" checked> Enviarles el email de bienvenida con el enlace</label>
                    <button class="boton boton--mini" type="submit">Habilitar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="tarjeta-panel">
        <div class="tarjeta-panel__cabecera">
            <h2>Alumnas y avance <small><?= count($filas) ?> accesos</small></h2>
            <input class="buscador" type="search" id="filtro" placeholder="Buscar por email, nombre o curso" aria-label="Buscar">
        </div>
        <?php if (!$filas): ?>
            <p class="vacio">Todavía no hay accesos.</p>
        <?php else: ?>
            <div class="tabla tabla--compacta tabla--scroll">
                <table>
                    <thead><tr><th>Persona</th><th>Curso</th><th>Avance</th><th>Acceso</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ($filas as $f): ?>
                        <tr data-buscar="<?= e(mb_strtolower($f['email'] . ' ' . $f['nombre'] . ' ' . $f['curso'])) ?>">
                            <td><?= e($f['nombre'] ?: '—') ?><br><small><?= e($f['email']) ?></small></td>
                            <td><?= e($f['curso']) ?><br><small><?= $f['origen'] === 'woocommerce' ? 'WooCommerce #' . e($f['pedido']) : 'Manual' ?></small></td>
                            <td><strong><?= $f['pct'] ?> %</strong> <small>(<?= $f['hechas'] ?>/<?= $f['total'] ?>)</small>
                                <?= $f['certificado'] !== '' ? '<br><small>🏆 ' . e($f['certificado']) . '</small>' : '' ?>
                                <?= $f['ultima_actividad'] !== '' ? '<br><small>Últ.: ' . e(fecha_corta($f['ultima_actividad'])) . '</small>' : '' ?></td>
                            <td><?= ['activo' => '🟢 Vigente', 'vencido' => '⚪ Vencido', 'revocado' => '🔴 Dado de baja'][$f['estado']] ?? e($f['estado']) ?>
                                <br><small>Desde <?= e(fecha_corta($f['desde'])) ?> · <?= $f['hasta'] === null ? 'sin vencimiento' : 'hasta ' . e(fecha_corta($f['hasta'])) ?></small></td>
                            <td>
                                <form method="post" class="tabla__acciones">
                                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="acceso_email" value="<?= e($f['email']) ?>">
                                    <input type="hidden" name="acceso_curso" value="<?= e($f['slug']) ?>">
                                    <input type="date" name="hasta" value="<?= e((string) $f['hasta']) ?>" aria-label="Vencimiento" title="Vencimiento (vacío = sin vencimiento)">
                                    <button class="boton boton--contorno boton--mini" type="submit">Guardar fecha</button>
                                    <?php if ($f['estado'] === 'revocado'): ?>
                                        <button class="boton boton--contorno boton--mini" type="submit" name="restaurar" value="1">Restaurar</button>
                                    <?php else: ?>
                                        <button class="boton boton--contorno boton--mini" type="submit" name="revocar" value="1" onclick="return confirm('¿Quitar el acceso? El progreso se conserva.')">Quitar acceso</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="tablero__columnas">
        <div class="tarjeta-panel">
            <h2>Compras por WooCommerce <small>últimos avisos</small></h2>
            <?php if (!$avisosWoo): ?>
                <p class="vacio">Todavía no llegó ningún aviso. Ver LEEME.md → «Compras por WooCommerce».</p>
            <?php else: ?>
                <div class="tabla tabla--compacta">
                    <table>
                        <thead><tr><th>Fecha</th><th>Pedido</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($avisosWoo as $w):
                            $clase = str_starts_with($w['resultado'], 'rechazado') || str_contains($w['resultado'], 'no se pudo') ? 'woo--mal' : (str_starts_with($w['resultado'], 'habilitado') ? 'woo--ok' : 'woo--pendiente'); ?>
                            <tr class="<?= $clase ?>"><td><?= e($w['fecha']) ?></td><td>#<?= e($w['pedido']) ?> <small><?= e($w['estado']) ?><br><?= e($w['email']) ?></small></td><td><?= e($w['resultado']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="tarjeta-panel">
            <h2>Respaldo</h2>
            <p class="nota">Envía por email el catálogo, las alumnas, el progreso y los pedidos. Hacelo seguido: es tu copia de seguridad.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button class="boton boton--contorno boton--mini" type="submit" name="enviar_backup" value="1">Backup por email</button>
            </form>
            <p class="nota">Datos guardados en: <code><?= e(USA_EXTERNA ? EXTERNA : 'privado/ (dentro de la carpeta del sitio)') ?></code></p>
        </div>
    </div>
</section>
<script>
document.getElementById('filtro')?.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('tr[data-buscar]').forEach(tr => { tr.hidden = q !== '' && !tr.dataset.buscar.includes(q); });
});
</script>
<?php
pagina_fin();
