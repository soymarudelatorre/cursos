<?php
declare(strict_types=1);

require __DIR__ . '/arranque.php';

/*
 * Entrada de los cursos: pedido del enlace de acceso (sin contraseña) y, con la sesión
 * abierta, «Mis cursos» con el progreso y el vencimiento de cada uno.
 */

$cfg  = config();
$demo = !empty($cfg['modo_demo']);

// Enlace recibido por email.
if (isset($_GET['t'])) {
    $email = leer_enlace((string) $_GET['t']);
    if ($email !== null) {
        abrir_sesion($email);
        header('Location: ./', true, 303);
        exit;
    }
    http_response_code(400);
    pagina_inicio('Enlace vencido');
    ?>
    <section class="contenedor mensaje">
        <div class="paso"><div class="paso__cuerpo">
            <h1>Este enlace ya no es válido</h1>
            <p>Los enlaces de acceso vencen a las 24 horas. Pedí uno nuevo con tu email: llega en un minuto.</p>
            <a class="boton" href="./">Pedir un enlace nuevo</a>
        </div></div>
    </section>
    <?php
    pagina_fin();
    exit;
}

if (isset($_GET['salir'])) {
    cerrar_sesion();
    header('Location: ./', true, 303);
    exit;
}

$email = sesion();
$error = '';
$enviado = false;
$enlaceDemo = '';
$emailPedido = '';

// ── Pedido del enlace de acceso ───────────────────────────────────────────
if (!$email && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailPedido = normalizar_email(mb_substr((string) ($_POST['email'] ?? ''), 0, 120));
    $ip = 'cursos-ip:' . ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!csrf_valido()) {
        $error = 'El formulario venció. Volvé a intentarlo.';
    } elseif (($_POST['sitio_web'] ?? '') !== '') {
        $enviado = true; // campo trampa: robot
    } elseif (!filter_var($emailPedido, FILTER_VALIDATE_EMAIL)) {
        $error = 'Revisá tu email: parece que tiene un error.';
    } elseif (limite_alcanzado($ip, 8, 3600) || limite_alcanzado('cursos-email:' . $emailPedido, 3, 3600)) {
        $error = 'Ya pediste varios enlaces. Revisá tu bandeja (y la carpeta de spam) o probá de nuevo en una hora.';
    } else {
        limite_alcanzado($ip, 8, 3600, true);
        $enviado = true; // mismo mensaje haya o no compra: no se revela quién compró
        $alumno = alumno($emailPedido);
        if (!empty($alumno['cursos']) || es_prueba($emailPedido)) {
            limite_alcanzado('cursos-email:' . $emailPedido, 3, 3600, true);
            $enlace = crear_enlace($emailPedido);
            if ($demo && empty($cfg['smtp']['contrasena'])) {
                $enlaceDemo = $enlace;
            } else {
                try {
                    enviar_email_boton(
                        (string) ($alumno['nombre'] ?? ''),
                        $emailPedido,
                        $enlace,
                        'Tu acceso a los cursos',
                        'Tocá el botón para entrar a tus cursos. El enlace vale por 24 horas.',
                        'Entrar a mis cursos'
                    );
                } catch (Throwable $ex) {
                    error_log('[cursos] Error al enviar email: ' . $ex->getMessage());
                    $enviado = false;
                    $error = 'No pudimos enviarte el correo en este momento. Probá de nuevo en unos minutos.';
                }
            }
        }
    }
}

$csrf = token_csrf();

// ── Sin sesión: pedido de acceso ──────────────────────────────────────────
if (!$email) {
    pagina_inicio('Mis cursos');
    ?>
    <section class="contenedor mensaje">
        <p class="hero__etiqueta">Cursos online</p>
        <h1>Entrá a tus cursos</h1>
        <div class="paso"><div class="paso__cuerpo">
            <?php if ($enviado): ?>
                <h2>Revisá tu email 📬</h2>
                <p>Si tu email tiene un curso habilitado, te enviamos un enlace para entrar. Vale por 24 horas.
                    Si no llega en unos minutos, revisá la carpeta de spam o promociones.</p>
                <?php if ($enlaceDemo): ?>
                    <p class="aviso">Modo demostración (sin correo configurado): <a href="<?= e($enlaceDemo) ?>">entrar con este enlace</a>.</p>
                <?php endif; ?>
                <a href="./">Usar otro email</a>
            <?php else: ?>
                <p>Escribí el email con el que compraste y te enviamos un enlace para entrar. No hace falta contraseña.</p>
                <?php if ($error): ?><p class="aviso aviso--error" role="alert"><?= e($error) ?></p><?php endif; ?>
                <form method="post" class="formulario">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <label>Email
                        <input type="email" name="email" required autocomplete="email" value="<?= e($emailPedido) ?>">
                    </label>
                    <div class="trampa" aria-hidden="true"><label>Sitio web <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>
                    <button class="boton" type="submit">Enviarme el enlace</button>
                </form>
            <?php endif; ?>
        </div></div>
    </section>
    <?php
    pagina_fin();
    exit;
}

// ── Con sesión: mis cursos ────────────────────────────────────────────────
$alumno = alumno($email);
$misCursos = cursos_de($email, $alumno);
$nombre = trim((string) ($alumno['nombre'] ?? ''));
$orden = ['activo' => 0, 'vencido' => 1, 'revocado' => 2];
uasort($misCursos, fn ($a, $b) => ($orden[$a['estado']] ?? 9) <=> ($orden[$b['estado']] ?? 9));

pagina_inicio('Mis cursos');
?>
<section class="contenedor cursos">
    <p class="hero__etiqueta">Mis cursos</p>
    <h1><?= $nombre !== '' ? '¡Hola, ' . e(explode(' ', $nombre)[0]) . '!' : '¡Hola!' ?></h1>
    <p class="cursos__bajada">Avanzá a tu ritmo: tu progreso se guarda solo.</p>

    <?php if (!$misCursos): ?>
        <p class="aviso">Todavía no tenés cursos habilitados con <strong><?= e($email) ?></strong>.
            Si ya compraste, puede tardar unos minutos. Si compraste con otro email, <a href="./?salir=1">entrá con ese</a>.</p>
    <?php endif; ?>

    <ul class="cursos__lista">
        <?php foreach ($misCursos as $slug => $item):
            $c = $item['curso'];
            [$hechas, $total, $pct] = progreso($alumno, $c);
            $activo = $item['estado'] === 'activo' && !empty($c['publicado']);
            $hasta = $item['acceso']['hasta'];
            $ultima = (string) ($alumno['ultima'][$slug] ?? '');
            ?>
            <li class="curso-tarjeta<?= $activo ? '' : ' curso-tarjeta--inactiva' ?>">
                <div class="curso-tarjeta__cuerpo">
                    <h2><?= e($c['titulo']) ?></h2>
                    <?php if (!empty($c['descripcion'])): ?><p><?= e($c['descripcion']) ?></p><?php endif; ?>
                    <div class="barra" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Progreso">
                        <span style="width: <?= $pct ?>%"></span>
                    </div>
                    <p class="curso-tarjeta__meta">
                        <strong><?= $pct ?> %</strong> · <?= $hechas ?> de <?= $total ?> lecciones
                        · <?php if ($item['estado'] === 'revocado'): ?>Acceso dado de baja
                        <?php elseif ($item['estado'] === 'vencido'): ?>El acceso venció el <?= e(fecha_corta($hasta)) ?>
                        <?php elseif (empty($c['publicado'])): ?>Curso en preparación
                        <?php elseif ($hasta === null): ?>Acceso sin vencimiento
                        <?php else: ?>Disponible hasta el <?= e(fecha_corta($hasta)) ?><?php endif; ?>
                    </p>
                    <?php if ($activo): ?><p class="curso-tarjeta__hito"><?= e(mensaje_hito($pct, !empty($c['certificado']['imagen']))) ?></p><?php endif; ?>
                </div>
                <div class="curso-tarjeta__acciones">
                    <?php if ($activo): ?>
                        <a class="boton" href="curso.php?c=<?= e($slug) ?><?= $ultima !== '' ? '&l=' . e($ultima) : '' ?>"><?= $hechas === 0 ? 'Empezar' : ($pct === 100 ? 'Repasar' : 'Continuar') ?></a>
                    <?php endif; ?>
                    <?php if (!empty($alumno['certificado'][$slug]) && !empty($c['certificado']['imagen'])): ?>
                        <a class="boton boton--contorno" href="certificado.php?c=<?= e($slug) ?>">🏆 Ver certificado</a>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="panel__nota">Sesión iniciada como <?= e($email) ?> · <a href="./?salir=1">Salir</a></p>
</section>
<?php
pagina_fin();
