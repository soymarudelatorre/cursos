<?php
declare(strict_types=1);

/*
 * Ingreso al panel (compartido por admin.php y admin-curso.php).
 * Si no hay sesión de administración, muestra el formulario de la clave y termina.
 */
function panel_exigir_ingreso(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    $claveAdmin = (string) (config()['admin_clave'] ?? '');
    $adminActivo = mb_strlen($claveAdmin) >= 12;

    if ($adminActivo && ($_GET['accion'] ?? '') === 'salir') {
        poner_cookie(COOKIE_ADMIN, '', time() - 3600);
        header('Location: admin.php', true, 303);
        exit;
    }
    if ($adminActivo && es_admin()) {
        return;
    }

    $error = '';
    if ($adminActivo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clave'])) {
        $ip = 'admin:' . ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!csrf_valido()) {
            $error = 'El formulario venció. Volvé a intentarlo.';
        } elseif (limite_alcanzado($ip, 5, 900)) {
            $error = 'Demasiados intentos. Esperá 15 minutos.';
        } elseif (!hash_equals($claveAdmin, (string) $_POST['clave'])) {
            limite_alcanzado($ip, 5, 900, true);
            $error = 'Clave incorrecta.';
        } else {
            poner_cookie(COOKIE_ADMIN, firmar(['t' => 'admin', 'h' => huella_admin(), 'exp' => time() + 8 * 3600]), time() + 8 * 3600);
            header('Location: ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'admin.php')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 303);
            exit;
        }
    }
    $csrf = token_csrf();
    pagina_inicio('Panel');
    ?>
    <section class="contenedor panel">
        <h1>Panel de cursos</h1>
        <?php if (!$adminActivo): ?>
            <p class="aviso">El panel está desactivado. Para usarlo, definí <code>'admin_clave'</code> en <code>config.php</code> con al menos 12 caracteres.</p>
        <?php else: ?>
            <div class="paso panel__ingreso"><div class="paso__cuerpo">
                <?php if ($error): ?><p class="aviso aviso--error" role="alert"><?= e($error) ?></p><?php endif; ?>
                <form method="post" class="formulario">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <label>Clave de administración
                        <input type="password" name="clave" required autocomplete="current-password">
                    </label>
                    <button class="boton" type="submit">Entrar</button>
                </form>
            </div></div>
        <?php endif; ?>
    </section>
    <?php
    pagina_fin();
    exit;
}

/** Barra superior del panel con el título y los accesos rápidos. */
function panel_barra(string $titulo, string $extra = ''): void
{
    ?>
    <div class="tablero__barra">
        <h1><?= e($titulo) ?></h1>
        <div class="tablero__acciones">
            <?= $extra ?>
            <a class="boton boton--contorno boton--mini" href="admin.php">Panel</a>
            <a class="tablero__salir" href="admin.php?accion=salir">Salir</a>
        </div>
    </div>
    <script>
    // Después de guardar, la página se recarga: se vuelve a la misma altura en vez de saltar
    // arriba de todo. Solo si se vuelve a la misma dirección y sin ancla (#…), que tiene prioridad.
    (function () {
        var clave = 'cursos_panel_scroll', aqui = location.pathname + location.search;
        try {
            var g = JSON.parse(sessionStorage.getItem(clave) || 'null');
            sessionStorage.removeItem(clave);
            if (g && g.url === aqui && !location.hash && Date.now() - g.t < 60000) {
                if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
                document.addEventListener('DOMContentLoaded', function () { window.scrollTo(0, g.y); });
            }
        } catch (e) {}
        // Cada tarjeta se pliega tocando su título; se recuerda en este navegador.
        document.addEventListener('DOMContentLoaded', function () {
            var guardadas = {};
            try { guardadas = JSON.parse(localStorage.getItem('cursos_panel_plegadas') || '{}'); } catch (e) {}
            document.querySelectorAll('.tarjeta-panel').forEach(function (tarjeta, i) {
                var cabecera = tarjeta.querySelector(':scope > .tarjeta-panel__cabecera, :scope > h2');
                if (!cabecera) return;
                var titulo = (cabecera.matches('h2') ? cabecera : cabecera.querySelector('h2') || cabecera).firstChild.textContent.trim();
                var id = location.pathname.split('/').pop() + ':' + (tarjeta.id || titulo || i);
                var boton = document.createElement('button');
                boton.type = 'button'; boton.className = 'plegar'; boton.title = 'Mostrar u ocultar';
                boton.innerHTML = '<span aria-hidden="true">▾</span>';
                cabecera.classList.add('plegable'); cabecera.appendChild(boton);
                function poner(cerrada) {
                    tarjeta.classList.toggle('tarjeta-panel--cerrada', cerrada);
                    boton.setAttribute('aria-expanded', cerrada ? 'false' : 'true');
                    boton.setAttribute('aria-label', (cerrada ? 'Mostrar ' : 'Ocultar ') + titulo);
                }
                poner(!!guardadas[id]);
                cabecera.addEventListener('click', function (ev) {
                    // Los campos y botones propios de la cabecera (buscar, crear curso…) siguen funcionando.
                    if (ev.target.closest('input, select, textarea, a, form, .segmentos, button:not(.plegar)')) return;
                    var cerrada = !tarjeta.classList.contains('tarjeta-panel--cerrada');
                    poner(cerrada);
                    if (cerrada) guardadas[id] = 1; else delete guardadas[id];
                    try { localStorage.setItem('cursos_panel_plegadas', JSON.stringify(guardadas)); } catch (e) {}
                });
            });
        });
        document.addEventListener('submit', function (ev) {
            if (ev.defaultPrevented) return;
            try { sessionStorage.setItem(clave, JSON.stringify({ url: aqui, y: window.scrollY, t: Date.now() })); } catch (e) {}
        });
    })();
    </script>
    <?php
}

/** Fecha Y-m-d válida, o null. */
function fecha_valida(string $fecha): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha ? $fecha : null;
}

// ── Avisos después de guardar ─────────────────────────────────────────────
// Después de cada acción se redirige (así F5 no repite la acción) y el resultado viaja
// en una cookie de un solo uso. Se muestra como aviso flotante, visible aunque la página
// haya bajado a otra sección.

const COOKIE_AVISO = 'cursos_aviso';

function panel_flash(bool $ok, string $texto): void
{
    poner_cookie(COOKIE_AVISO, ($ok ? '1' : '0') . mb_substr($texto, 0, 600), 0);
}

/** Aviso pendiente de la acción anterior ([ok, texto]) o null. Se borra al leerlo. */
function panel_leer_flash(): ?array
{
    $valor = (string) ($_COOKIE[COOKIE_AVISO] ?? '');
    if ($valor === '') {
        return null;
    }
    poner_cookie(COOKIE_AVISO, '', time() - 3600);
    unset($_COOKIE[COOKIE_AVISO]);
    return [$valor[0] === '1', mb_substr($valor, 1, 600)];
}

/** HTML del aviso flotante. Los de éxito se cierran solos; los de error quedan hasta cerrarlos. */
function panel_aviso(?array $aviso): string
{
    if (!$aviso) {
        return '';
    }
    [$ok, $texto] = $aviso;
    $clase = $ok ? 'toast toast--ok' : 'toast toast--error';
    $rol = $ok ? 'status' : 'alert';
    $icono = $ok ? '✓' : '⚠';
    $titulo = $ok ? 'Listo' : 'No se pudo guardar';
    return '<div class="' . $clase . '" role="' . $rol . '"' . ($ok ? ' data-autocierre' : '') . '>'
        . '<span class="toast__icono" aria-hidden="true">' . $icono . '</span>'
        . '<div class="toast__cuerpo"><strong>' . $titulo . '</strong><p>' . e($texto) . '</p></div>'
        . '<button type="button" class="toast__cerrar" aria-label="Cerrar aviso" onclick="this.parentNode.remove()">×</button>'
        . '</div>'
        . '<script>(function(){var t=document.querySelector(".toast[data-autocierre]");if(t)setTimeout(function(){t.classList.add("toast--saliendo");setTimeout(function(){t.remove()},400)},6000);})();</script>';
}
