<?php
declare(strict_types=1);

/*
 * Alumnos, accesos y progreso (privado/alumnos.json), por email:
 *   nombre, alta,
 *   cursos:     slug => [desde (fecha de pago), hasta (null = sin vencimiento), origen ('woocommerce'|'manual'),
 *                        pedido, revocado (bool)]
 *   progreso:   slug => [id de lección => fecha en que la marcó como vista]
 *   ultima:     slug => id de la última lección abierta (para «Continuar»)
 *   certificado: slug => [codigo, fecha] (se genera al llegar al 100 %)
 */

const ALUMNOS_ARCHIVO = 'alumnos.json';
const COOKIE_ALUMNO = 'cursos_sesion';
const DIAS_INVITACION = 7;
const MESES_ACCESO_ANUAL = 12;

function alumnos_todos(): array
{
    return datos_json(ALUMNOS_ARCHIVO);
}

function alumno(string $email): ?array
{
    return alumnos_todos()[normalizar_email($email)] ?? null;
}

/** Modifica un alumno con bloqueo. $cambiar recibe el alumno (o uno nuevo) y lo devuelve. */
function alumno_cambiar(string $email, callable $cambiar): void
{
    $email = normalizar_email($email);
    datos_json(ALUMNOS_ARCHIVO, function (array $lista) use ($email, $cambiar) {
        $a = $lista[$email] ?? ['nombre' => '', 'alta' => date('Y-m-d H:i:s'), 'cursos' => [], 'progreso' => [], 'ultima' => [], 'certificado' => []];
        $lista[$email] = $cambiar($a);
        return [$lista, null];
    });
}

/** Emails de prueba (config.php → 'emails_prueba'): ven todos los cursos sin compra. */
function es_prueba(string $email): bool
{
    return in_array(normalizar_email($email), array_map('normalizar_email', (array) (config()['emails_prueba'] ?? [])), true);
}

/** Vencimiento para un curso pagado en $fechaPago (Y-m-d): null si el curso no vence. */
function calcular_hasta(array $curso, string $fechaPago): ?string
{
    if (($curso['acceso'] ?? 'anual') === 'ilimitado') {
        return null;
    }
    return (new DateTimeImmutable($fechaPago))->modify('+' . MESES_ACCESO_ANUAL . ' months')->format('Y-m-d');
}

/**
 * Habilita un curso. Si ya tenía acceso, se reemplaza por el nuevo (fase 1: sin renovaciones
 * acumulativas) salvo que el actual dure más.
 */
function alumno_habilitar(string $email, string $nombre, string $slug, string $fechaPago, string $origen, string $pedido = ''): void
{
    $curso = curso($slug);
    if (!$curso) {
        throw new RuntimeException("El curso «{$slug}» no existe.");
    }
    $hasta = calcular_hasta($curso, $fechaPago);
    alumno_cambiar($email, function (array $a) use ($nombre, $slug, $fechaPago, $hasta, $origen, $pedido) {
        if ($nombre !== '') {
            $a['nombre'] = $nombre;
        }
        $previo = $a['cursos'][$slug] ?? null;
        $mantener = $previo && empty($previo['revocado'])
            && ($previo['hasta'] === null || ($hasta !== null && $previo['hasta'] > $hasta));
        if (!$mantener) {
            $a['cursos'][$slug] = ['desde' => $fechaPago, 'hasta' => $hasta, 'origen' => $origen, 'pedido' => $pedido, 'revocado' => false];
        }
        return $a;
    });
}

function alumno_revocar(string $email, string $slug, bool $revocar = true): void
{
    alumno_cambiar($email, function (array $a) use ($slug, $revocar) {
        if (isset($a['cursos'][$slug])) {
            $a['cursos'][$slug]['revocado'] = $revocar;
        }
        return $a;
    });
}

/** Cambia la fecha de vencimiento a mano desde el panel (null = sin vencimiento). */
function alumno_cambiar_hasta(string $email, string $slug, ?string $hasta): void
{
    alumno_cambiar($email, function (array $a) use ($slug, $hasta) {
        if (isset($a['cursos'][$slug])) {
            $a['cursos'][$slug]['hasta'] = $hasta;
        }
        return $a;
    });
}

/** 'activo', 'vencido', 'revocado' o 'sin acceso'. */
function acceso_estado(?array $acceso): string
{
    if (!$acceso) {
        return 'sin acceso';
    }
    if (!empty($acceso['revocado'])) {
        return 'revocado';
    }
    return ($acceso['hasta'] === null || $acceso['hasta'] >= date('Y-m-d')) ? 'activo' : 'vencido';
}

/** Cursos que ve la persona: los que compró (activos o no) y, si es de prueba, todos los publicados. */
function cursos_de(string $email, ?array $alumno): array
{
    $lista = [];
    foreach (cursos_todos() as $slug => $c) {
        $acceso = $alumno['cursos'][$slug] ?? null;
        if (es_prueba($email) && !empty($c['publicado'])) {
            $acceso = ['desde' => date('Y-m-d'), 'hasta' => null, 'origen' => 'prueba', 'pedido' => '', 'revocado' => false];
        }
        if ($acceso) {
            $lista[$slug] = ['curso' => $c + ['slug' => $slug], 'acceso' => $acceso, 'estado' => acceso_estado($acceso)];
        }
    }
    return $lista;
}

function puede_ver(string $email, string $slug): bool
{
    $c = cursos_de($email, alumno($email))[$slug] ?? null;
    return $c && $c['estado'] === 'activo' && !empty($c['curso']['publicado']);
}

// ── Progreso ──────────────────────────────────────────────────────────────

/** [vistas, total, porcentaje] de un curso, solo con las lecciones que existen hoy. */
function progreso(?array $alumno, array $curso): array
{
    $vistas = $alumno['progreso'][$curso['slug']] ?? [];
    $ids = array_column(curso_lecciones($curso), 'id');
    $hechas = count(array_intersect($ids, array_keys($vistas)));
    $total = count($ids);
    return [$hechas, $total, $total ? (int) floor($hechas * 100 / $total) : 0];
}

/** Marca o desmarca una lección como vista. Al llegar al 100 % se genera el código del certificado. */
function marcar_leccion(string $email, array $curso, string $leccionId, bool $vista): void
{
    $slug = $curso['slug'];
    alumno_cambiar($email, function (array $a) use ($curso, $slug, $leccionId, $vista) {
        if ($vista) {
            $a['progreso'][$slug][$leccionId] ??= date('Y-m-d H:i:s');
        } else {
            unset($a['progreso'][$slug][$leccionId]);
        }
        [, $total, $porcentaje] = progreso($a, $curso);
        if ($total > 0 && $porcentaje === 100 && empty($a['certificado'][$slug])) {
            $a['certificado'][$slug] = ['codigo' => strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)), 'fecha' => date('Y-m-d')];
        }
        return $a;
    });
}

function recordar_ultima(string $email, string $slug, string $leccionId): void
{
    alumno_cambiar($email, function (array $a) use ($slug, $leccionId) {
        $a['ultima'][$slug] = $leccionId;
        return $a;
    });
}

/** Mensaje de hito según el porcentaje: reconoce el avance sin apurar. */
function mensaje_hito(int $porcentaje): string
{
    return match (true) {
        $porcentaje >= 100 => '🏆 ¡Terminaste el curso! Tu certificado ya está disponible.',
        $porcentaje >= 75  => '🔥 Ya pasaste las tres cuartas partes. Lo que aprendiste ya es tuyo.',
        $porcentaje >= 50  => '⭐ Mitad del camino: cada lección suma.',
        $porcentaje >= 25  => '🌱 Ya hiciste un cuarto del curso. Buen ritmo, el tuyo.',
        $porcentaje > 0    => '✨ Arrancaste. Avanzá a tu ritmo: tu progreso queda guardado.',
        default            => 'Empezá cuando quieras: tu progreso se guarda solo.',
    };
}

// ── Acceso por email (sin contraseñas) ────────────────────────────────────

function crear_enlace(string $email, int $dias = 1): string
{
    $token = firmar(['t' => 'cursos_enlace', 'e' => normalizar_email($email), 'exp' => time() + 86400 * $dias]);
    return url_base() . '?t=' . rawurlencode($token);
}

function leer_enlace(string $token): ?string
{
    $d = verificar_firma($token);
    return ($d && ($d['t'] ?? '') === 'cursos_enlace') ? (string) $d['e'] : null;
}

function abrir_sesion(string $email): void
{
    $dias = max(1, (int) (config()['dias_sesion'] ?? 30));
    $expira = time() + 86400 * $dias;
    poner_cookie(COOKIE_ALUMNO, firmar(['t' => 'alumno', 'e' => $email, 'exp' => $expira, 'v' => version_sesiones()]), $expira);
}

function cerrar_sesion(): void
{
    poner_cookie(COOKIE_ALUMNO, '', time() - 3600);
}

/** Email de la sesión abierta, o null. */
function sesion(): ?string
{
    $s = verificar_firma($_COOKIE[COOKIE_ALUMNO] ?? '');
    return ($s && ($s['t'] ?? '') === 'alumno' && ($s['v'] ?? 0) === version_sesiones()) ? (string) $s['e'] : null;
}

/** Email de bienvenida al habilitar un curso: enlace directo que vale 7 días. Lanza excepción si falla. */
function enviar_invitacion(string $email, string $nombre, array $curso, ?string $hasta): void
{
    if (empty(config()['smtp']['contrasena'])) {
        throw new RuntimeException("falta la contraseña de la casilla en config.php ('smtp' → 'contrasena')");
    }
    $vence = $hasta === null ? 'Tu acceso no vence.' : 'Tu acceso está disponible hasta el ' . fecha_corta($hasta) . '.';
    enviar_email_boton(
        $nombre,
        $email,
        crear_enlace($email, DIAS_INVITACION),
        'Ya tenés acceso a «' . $curso['titulo'] . '»',
        'Ya podés empezar «' . $curso['titulo'] . '». Avanzá a tu ritmo: tu progreso se guarda solo. ' . $vence
            . ' Este botón vale por ' . DIAS_INVITACION . ' días; después podés entrar siempre pidiendo un enlace con tu email en ' . url_base(),
        'Empezar el curso'
    );
}
