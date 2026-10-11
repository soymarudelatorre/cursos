<?php
/**
 * Configuración de los cursos.
 *
 * 1. Copiá este archivo como "config.php" (en la misma carpeta, o mejor en
 *    la carpeta cursos-privado fuera de public_html — ver LEEME.md).
 * 2. Completá los valores marcados con  <-- CAMBIAR.
 * 3. Nunca subas config.php a GitHub ni a un lugar público.
 */
return [

    // Dirección pública de los cursos (se usa en los enlaces que llegan por email).
    'url_sitio' => 'https://www.marudelatorre.com/cursos/',

    // Clave del panel: https://www.marudelatorre.com/cursos/admin.php
    // Mínimo 12 caracteres. Vacía = panel desactivado.
    'admin_clave' => '',                              // <-- CAMBIAR

    // Usuarios de prueba: ven todos los cursos publicados sin compra (para revisarlos
    // como los ve una alumna). Su progreso se guarda igual que el de cualquier persona.
    'emails_prueba' => ['marieladlt@gmail.com'],

    // Días que la sesión queda abierta en el navegador de cada alumna.
    'dias_sesion' => 30,

    // Subí este número (2, 3, …) para cerrar TODAS las sesiones abiertas.
    'version_sesiones' => 1,

    // Modo demostración: si la contraseña del correo está vacía, el enlace de acceso
    // se muestra en pantalla en lugar de enviarse. Ponelo en false al publicar.
    'modo_demo' => true,

    // ── Envío de emails (casilla de Ferozo → Email) ───────────────────────
    'smtp' => [
        'servidor'         => 'l0100725.ferozo.com',
        'puerto'           => 465,
        'seguridad'        => 'ssl',                  // 'ssl' (puerto 465) o 'tls' (puerto 587)
        'usuario'          => 'recursos@marudelatorre.com',
        'contrasena'       => '',                     // <-- CAMBIAR: contraseña de la casilla
        'nombre_remitente' => 'Maru de la Torre',
        'responder_a'      => 'contacto@marudelatorre.com',
    ],

    // ── Compras por WooCommerce (webhook) ─────────────────────────────────
    // Ver LEEME.md → «Compras por WooCommerce». Qué producto habilita qué curso se elige
    // en el panel, dentro de cada curso.
    'woocommerce' => [
        'secreto' => '',              // <-- el mismo «Secreto» que pongas en el webhook de WooCommerce
        'quitar_en_reembolso' => true, // reembolsado o cancelado: quita el acceso (el progreso se conserva)
    ],

    // Destino del botón «Backup por email» del panel. Vacío = la misma casilla de envío.
    'email_backup' => '',

    // ── Marca ─────────────────────────────────────────────────────────────
    'marca' => [
        'nombre'    => 'Maru de la Torre',
        'lema'      => 'Mentora y potenciadora en tecnología',
        'sitio'     => 'https://www.marudelatorre.com',
        'logo_url'  => 'assets/logo.png',
        'bajada'    => 'Cursos online de Maru de la Torre.',
        'instagram' => '',
    ],

    // Colores y fuentes de marudelatorre.com (los mismos del portal de guías y del diagnóstico).
    'tema' => [
        'color_marca'      => '#C47E89',
        'color_primario'   => '#891E59',
        'color_primario_2' => '#6B1746',
        'color_secundario' => '#000000',
        'color_acento'     => '#891E59',
        'color_fondo'      => '#F8F7FA',
        'color_superficie' => '#FFFFFF',
        'color_texto'      => '#000000',
        'color_suave'      => '#555058',
        'fuente_titulos'   => 'Poppins',
        'fuente_texto'     => 'Poppins',
        'pesos_fuente'     => '400;500;600;700;800',
        'radio'            => '6px',
    ],
];
