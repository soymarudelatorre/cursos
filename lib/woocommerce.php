<?php
declare(strict_types=1);

/*
 * Compras por WooCommerce: el webhook avisa cada cambio de un pedido.
 * - Pedido pagado (Procesando o Completado) → se habilitan los cursos de sus productos
 *   desde la fecha de pago y se envía la invitación. Una sola vez por pedido.
 * - Pedido reembolsado o cancelado después de habilitado → se quita el acceso a esos cursos
 *   (si 'quitar_en_reembolso' está activo). El progreso y el certificado se conservan.
 */

const WOO_ARCHIVO_PEDIDOS = 'woocommerce-pedidos.json';
const WOO_ARCHIVO_AVISOS = 'woocommerce-avisos.csv';
const WOO_PAGADO = ['processing', 'completed'];
const WOO_ANULADO = ['refunded', 'cancelled'];

function woo_config(): array
{
    return (array) (config()['woocommerce'] ?? []) + ['secreto' => '', 'quitar_en_reembolso' => true];
}

/** La firma de WooCommerce es base64(HMAC-SHA256(cuerpo, secreto)). */
function woo_firma_valida(string $cuerpo, string $firma, string $secreto): bool
{
    return $secreto !== '' && $firma !== ''
        && hash_equals(base64_encode(hash_hmac('sha256', $cuerpo, $secreto, true)), $firma);
}

/** Cursos incluidos en el pedido (por ID de producto, de variación o SKU, según el panel). */
function woo_cursos_del_pedido(array $pedido): array
{
    $mapa = cursos_por_producto();
    $slugs = [];
    foreach ((array) ($pedido['line_items'] ?? []) as $item) {
        foreach ([(string) ($item['product_id'] ?? ''), (string) ($item['variation_id'] ?? ''), (string) ($item['sku'] ?? '')] as $id) {
            if ($id !== '' && $id !== '0' && isset($mapa[$id])) {
                $slugs[$mapa[$id]] = true;
            }
        }
    }
    return array_keys($slugs);
}

/** Fecha de pago del pedido (Y-m-d). Si WooCommerce no la manda, la de hoy. */
function woo_fecha_pago(array $pedido): string
{
    $fecha = (string) ($pedido['date_paid'] ?? '') ?: (string) ($pedido['date_completed'] ?? '');
    $t = $fecha !== '' ? strtotime($fecha) : false;
    return $t ? date('Y-m-d', $t) : date('Y-m-d');
}

/**
 * Procesa un pedido y devuelve qué se hizo (texto para el registro).
 * El registro de pedidos (con bloqueo) evita habilitar dos veces el mismo pedido
 * aunque WooCommerce avise varias veces (pedido creado, actualizado, completado…).
 */
function woo_procesar(array $pedido): string
{
    $id = (string) ($pedido['id'] ?? '');
    $estado = (string) ($pedido['status'] ?? '');
    $email = normalizar_email((string) ($pedido['billing']['email'] ?? ''));
    $nombre = mb_substr(trim(($pedido['billing']['first_name'] ?? '') . ' ' . ($pedido['billing']['last_name'] ?? '')), 0, 100);

    // Un webhook con el tema equivocado («Producto …» en vez de «Pedido …») manda el producto, no el pedido.
    if (!isset($pedido['billing']) && !isset($pedido['line_items']) && (isset($pedido['permalink']) || isset($pedido['type']) || ($pedido['status'] ?? '') === 'publish')) {
        return 'ignorado: llegó un PRODUCTO, no un pedido. En WooCommerce → Webhooks, el tema tiene que ser «Pedido creado» o «Pedido actualizado»';
    }
    if ($id === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'ignorado: pedido sin número o sin email válido';
    }
    $slugs = woo_cursos_del_pedido($pedido);
    if (!$slugs) {
        return 'ignorado: no incluye productos asociados a un curso';
    }

    $invitar = [];
    $texto = datos_json(WOO_ARCHIVO_PEDIDOS, function (array $pedidos) use ($id, $estado, $email, $nombre, $slugs, $pedido, &$invitar) {
        $previo = $pedidos[$id]['accion'] ?? '';
        if (in_array($estado, WOO_PAGADO, true) && $previo === '') {
            $fecha = woo_fecha_pago($pedido);
            foreach ($slugs as $slug) {
                alumno_habilitar($email, $nombre, $slug, $fecha, 'woocommerce', $id);
            }
            $invitar = $slugs;
            $pedidos[$id] = ['email' => $email, 'cursos' => $slugs, 'accion' => 'habilitado', 'fecha' => date('Y-m-d H:i:s')];
            return [$pedidos, 'habilitado: ' . implode(', ', $slugs) . " (pagado el $fecha)"];
        }
        if (in_array($estado, WOO_ANULADO, true) && $previo === 'habilitado') {
            $pedidos[$id]['accion'] = 'anulado';
            $pedidos[$id]['fecha_anulado'] = date('Y-m-d H:i:s');
            if (!woo_config()['quitar_en_reembolso']) {
                return [$pedidos, 'pedido anulado: no se quitó el acceso (quitar_en_reembolso desactivado)'];
            }
            $quitados = [];
            foreach ((array) $pedidos[$id]['cursos'] as $slug) {
                // Solo si el acceso vigente vino de este pedido (no de otra compra o de una carga manual).
                if ((string) (alumno($email)['cursos'][$slug]['pedido'] ?? '') === $id) {
                    alumno_revocar($email, $slug);
                    $quitados[] = $slug;
                }
            }
            return [$pedidos, 'pedido anulado: ' . ($quitados ? 'se quitó el acceso a ' . implode(', ', $quitados) : 'no había accesos de este pedido para quitar')];
        }
        return [$pedidos, $previo === '' ? "sin cambios (estado «{$estado}»: todavía no pagado)" : "sin cambios (ya estaba $previo)"];
    });

    if ($invitar) {
        try {
            foreach ($invitar as $slug) {
                enviar_invitacion($email, $nombre, curso($slug), alumno($email)['cursos'][$slug]['hasta'] ?? null);
            }
            $texto .= ' y se envió la invitación';
        } catch (Throwable $ex) {
            $texto .= ' · no se pudo enviar la invitación: ' . $ex->getMessage();
        }
    }
    return $texto;
}

function woo_registrar(string $pedido, string $estado, string $email, string $resultado): void
{
    registrar(WOO_ARCHIVO_AVISOS, ['fecha' => date('Y-m-d H:i:s'), 'pedido' => $pedido, 'estado' => $estado, 'email' => $email, 'resultado' => $resultado]);
}
