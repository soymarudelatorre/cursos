<?php
declare(strict_types=1);

/*
 * Recibe los webhooks de WooCommerce (WooCommerce → Ajustes → Avanzado → Webhooks).
 * URL de entrega: https://www.marudelatorre.com/cursos/woocommerce.php
 * Ver LEEME.md → «Compras por WooCommerce».
 */

require __DIR__ . '/arranque.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Receptor de webhooks de WooCommerce para los cursos.');
}

$cuerpo = (string) file_get_contents('php://input');

// Al crear el webhook, WooCommerce envía una prueba («webhook_id=…») y espera una respuesta 200.
if (preg_match('/^webhook_id=\d+$/', trim($cuerpo))) {
    woo_registrar('', 'prueba', '', 'WooCommerce conectó el webhook');
    exit('ok');
}

$secreto = (string) woo_config()['secreto'];
if (!woo_firma_valida($cuerpo, (string) ($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? ''), $secreto)) {
    http_response_code(401);
    woo_registrar('', '', '', $secreto === '' ? 'rechazado: falta el secreto en config.php' : 'rechazado: firma inválida');
    exit('firma inválida');
}

$pedido = json_decode($cuerpo, true);
if (!is_array($pedido)) {
    woo_registrar('', '', '', 'ignorado: el aviso no es un pedido');
    exit('ok');
}

$resultado = woo_procesar($pedido);
woo_registrar((string) ($pedido['id'] ?? ''), (string) ($pedido['status'] ?? ''), (string) ($pedido['billing']['email'] ?? ''), $resultado);
exit('ok'); // siempre 200 a un aviso válido: WooCommerce desactiva el webhook tras 5 fallos seguidos
