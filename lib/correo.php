<?php
declare(strict_types=1);

/**
 * Envío de emails por SMTP (sin librerías externas).
 * Usa los datos de la casilla configurados en config.php → 'smtp'.
 */

function smtp_leer($conexion): string
{
    $respuesta = '';
    while (($linea = fgets($conexion, 1024)) !== false) {
        $respuesta .= $linea;
        if (strlen($linea) < 4 || $linea[3] === ' ') {
            break; // última línea de la respuesta (código + espacio)
        }
    }
    return $respuesta;
}

/**
 * Envía un comando y valida el código de respuesta. $etiqueta es lo que se muestra
 * en los errores: nunca el contenido real de usuario/contraseña.
 */
function smtp_comando($conexion, string $comando, array $codigosOk, ?string $etiqueta = null): string
{
    if ($comando !== '') {
        fwrite($conexion, $comando . "\r\n");
    }
    $r = smtp_leer($conexion);
    if (!in_array((int) substr($r, 0, 3), $codigosOk, true)) {
        $visible = $etiqueta ?? ($comando === '' ? 'respuesta del servidor' : $comando);
        throw new RuntimeException("El servidor de correo rechazó «{$visible}»: " . trim($r));
    }
    return $r;
}

function codificar_encabezado(string $texto): string
{
    return preg_match('/[^\x20-\x7E]/', $texto) ? '=?UTF-8?B?' . base64_encode($texto) . '?=' : $texto;
}

/**
 * Envía un email con versión HTML y versión de texto, y adjuntos opcionales
 * (['nombre.csv' => contenido]). Lanza excepción si falla.
 */
function enviar_email(string $para, string $nombrePara, string $asunto, string $html, string $texto, array $adjuntos = []): void
{
    $smtp = config()['smtp'];
    $seguridad = $smtp['seguridad'] ?? 'ssl';
    $destino = ($seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $smtp['servidor'] . ':' . (int) $smtp['puerto'];
    $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    $c = @stream_socket_client($destino, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $contexto);
    if (!$c) {
        throw new RuntimeException("No se pudo conectar al servidor de correo: $errstr ($errno)");
    }
    stream_set_timeout($c, 20);

    try {
        $dominio = substr(strrchr($smtp['usuario'], '@') ?: '@localhost', 1);
        smtp_comando($c, '', [220]);
        smtp_comando($c, "EHLO $dominio", [250]);
        if ($seguridad === 'tls') {
            smtp_comando($c, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar TLS con el servidor de correo.');
            }
            smtp_comando($c, "EHLO $dominio", [250]);
        }
        smtp_comando($c, 'AUTH LOGIN', [334]);
        smtp_comando($c, base64_encode($smtp['usuario']), [334], 'usuario de la casilla');
        smtp_comando($c, base64_encode($smtp['contrasena']), [235], 'contraseña de la casilla (¿es la correcta?)');

        $remitente = $smtp['usuario'];
        smtp_comando($c, "MAIL FROM:<$remitente>", [250]);
        smtp_comando($c, "RCPT TO:<$para>", [250, 251]);
        smtp_comando($c, 'DATA', [354]);

        $limite = 'b' . bin2hex(random_bytes(12));
        $encabezados = [
            'Date: ' . date('r'),
            'From: ' . codificar_encabezado($smtp['nombre_remitente'] ?? '') . " <$remitente>",
            'To: ' . codificar_encabezado($nombrePara) . " <$para>",
            'Subject: ' . codificar_encabezado($asunto),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$dominio>",
            'MIME-Version: 1.0',
            $adjuntos ? "Content-Type: multipart/mixed; boundary=\"m$limite\"" : "Content-Type: multipart/alternative; boundary=\"$limite\"",
        ];
        if (!empty($smtp['responder_a'])) {
            $encabezados[] = 'Reply-To: <' . $smtp['responder_a'] . '>';
        }
        $cuerpo = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto))
            . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--$limite--\r\n";
        if ($adjuntos) {
            $mixto = "--m$limite\r\nContent-Type: multipart/alternative; boundary=\"$limite\"\r\n\r\n" . $cuerpo;
            foreach ($adjuntos as $nombre => $contenido) {
                $nombre = preg_replace('/[^\w.\-]/', '_', (string) $nombre);
                $tipo = str_ends_with($nombre, '.json') ? 'application/json' : 'text/csv';
                $mixto .= "--m$limite\r\nContent-Type: $tipo; charset=UTF-8; name=\"$nombre\"\r\n"
                    . "Content-Disposition: attachment; filename=\"$nombre\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                    . chunk_split(base64_encode($contenido));
            }
            $cuerpo = $mixto . "--m$limite--\r\n";
        }

        // El cuerpo va en base64, así que ninguna línea empieza con "." (no hace falta escaparlo).
        fwrite($c, implode("\r\n", $encabezados) . "\r\n\r\n" . $cuerpo . ".\r\n");
        smtp_comando($c, '', [250]);
        smtp_comando($c, 'QUIT', [221]);
    } finally {
        fclose($c);
    }
}

/**
 * Email con logo, saludo, un párrafo, un botón y una nota chica debajo.
 * $intro y $nota van en texto (se escapan). El enlace largo queda escondido detrás de «tocá acá»,
 * solo como respaldo si el botón no abre.
 */
function enviar_email_boton(string $nombre, string $email, string $enlace, string $asunto, string $intro, string $textoBoton, string $nota = '', string $pie = 'Si no pediste este correo, podés ignorarlo.'): void
{
    $cfg = config();
    $marca = $cfg['marca'];
    $color = $cfg['tema']['color_primario'];
    $saludo = $nombre !== '' ? '¡Hola, ' . e($nombre) . '!' : '¡Hola!';
    $url = e($enlace);
    $sitio = e($marca['nombre']);
    $lema = e((string) ($marca['lema'] ?? ''));
    $parrafo = e($intro);
    $boton = e($textoBoton);
    $notaHtml = $nota !== '' ? '<p style="margin:0 0 10px;font-size:14px;line-height:1.6;color:#555058;">' . e($nota) . '</p>' : '';
    $pieHtml = e($pie);

    // El logo tiene que ser una dirección completa (https://…): el email se lee fuera del sitio.
    $logo = (string) ($marca['logo_url'] ?? '');
    if ($logo !== '' && !preg_match('~^https?://~', $logo)) {
        $logo = url_base() . ltrim($logo, '/');
    }
    $cabecera = $logo !== ''
        ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;"><tr>'
            . '<td style="padding-right:12px;vertical-align:middle;"><img src="' . e($logo) . '" width="52" height="52" alt="' . $sitio . '" style="display:block;border:0;border-radius:50%;"></td>'
            . '<td style="vertical-align:middle;"><p style="margin:0;font-size:16px;font-weight:bold;color:#000;">' . $sitio . '</p>'
            . ($lema !== '' ? '<p style="margin:2px 0 0;font-size:13px;color:#555058;">' . $lema . '</p>' : '') . '</td>'
            . '</tr></table>'
        : '<p style="margin:0 0 20px;font-size:13px;letter-spacing:1px;text-transform:uppercase;color:' . $color . ';font-weight:bold;">' . $sitio . '</p>';

    $html = <<<HTML
<!doctype html>
<html lang="es"><body style="margin:0;background:#F8F7FA;font-family:Arial,Helvetica,sans-serif;color:#000;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F8F7FA;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#fff;border-radius:6px;border-top:4px solid {$color};padding:32px 32px 28px;">
<tr><td>
{$cabecera}
<h1 style="margin:0 0 14px;font-size:24px;line-height:1.3;">{$saludo}</h1>
<p style="margin:0 0 26px;font-size:16px;line-height:1.6;">{$parrafo}</p>
<p style="margin:0 0 26px;"><a href="{$url}" style="display:inline-block;background:{$color};color:#fff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:6px;">{$boton}</a></p>
{$notaHtml}
<p style="margin:22px 0 0;padding-top:14px;border-top:1px solid #EEE;font-size:11px;line-height:1.5;color:#8A858C;">Si el botón no abre, <a href="{$url}" style="color:#8A858C;">tocá acá</a>. {$pieHtml}</p>
</td></tr></table>
</td></tr></table>
</body></html>
HTML;

    $texto = ($nombre !== '' ? "¡Hola, $nombre!" : '¡Hola!') . "\n\n$intro\n\n$textoBoton:\n$enlace\n\n"
        . ($nota !== '' ? "$nota\n\n" : '') . "$pie\n\n" . $marca['nombre'];

    enviar_email($email, $nombre, $asunto, $html, $texto);
}
