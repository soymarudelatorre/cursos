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
