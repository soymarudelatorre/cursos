<?php
declare(strict_types=1);

/** Cabecera HTML común (fuentes, colores de config.php, logo y menú). */
function pagina_inicio(string $titulo, bool $indexar = false): void
{
    $cfg   = config();
    $marca = $cfg['marca'];
    $tema  = $cfg['tema'];

    header('X-Frame-Options: DENY');
    // YouTube necesita saber desde qué sitio se inserta el video (si no, muestra un error); solo se envía el dominio.
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $fuentes = array_unique([$tema['fuente_titulos'], $tema['fuente_texto']]);
    $urlFuentes = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(
        fn ($f) => 'family=' . str_replace(' ', '+', $f) . ':wght@' . ($tema['pesos_fuente'] ?? '400;700'),
        $fuentes
    )) . '&display=swap';
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · <?= e($marca['nombre']) ?></title>
    <meta name="description" content="<?= e($marca['bajada']) ?>">
    <?php if (!$indexar): ?><meta name="robots" content="noindex"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e($urlFuentes) ?>">
    <link rel="stylesheet" href="assets/estilos.css?v=17">
    <?php if ($marca['logo_url']): ?>
        <link rel="icon" href="<?= e($marca['logo_url'] === 'assets/logo.png' ? 'assets/favicon.png' : $marca['logo_url']) ?>">
    <?php endif; ?>
    <style>
        :root {
            --marca: <?= e($tema['color_marca'] ?? $tema['color_acento']) ?>;
            --primario: <?= e($tema['color_primario']) ?>;
            --primario-2: <?= e($tema['color_primario_2']) ?>;
            --secundario: <?= e($tema['color_secundario'] ?? '#000000') ?>;
            --acento: <?= e($tema['color_acento']) ?>;
            --fondo: <?= e($tema['color_fondo']) ?>;
            --superficie: <?= e($tema['color_superficie']) ?>;
            --texto: <?= e($tema['color_texto']) ?>;
            --suave: <?= e($tema['color_suave']) ?>;
            --fuente-titulos: '<?= e($tema['fuente_titulos']) ?>', Georgia, serif;
            --fuente-texto: '<?= e($tema['fuente_texto']) ?>', system-ui, sans-serif;
            --radio: <?= e($tema['radio']) ?>;
        }
    </style>
</head>
<body>
<header class="cabecera">
    <div class="contenedor cabecera__fila">
        <a class="marca" href="<?= e($marca['sitio']) ?>">
            <?php if ($marca['logo_url']): ?>
                <img src="<?= e($marca['logo_url']) ?>" alt="" width="64" height="64">
            <?php endif; ?>
            <span class="marca__texto">
                <span class="marca__nombre"><?= e($marca['nombre']) ?></span>
                <?php if (!empty($marca['lema'])): ?><span class="marca__lema"><?= e($marca['lema']) ?></span><?php endif; ?>
            </span>
        </a>
        <nav class="cabecera__nav">
            <?php if (function_exists('sesion') && sesion()): ?>
                <a href="./">Mis cursos</a>
                <a href="./?salir=1">Salir</a>
            <?php else: ?>
                <a href="<?= e($marca['sitio']) ?>">Volver al sitio</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
<?php
}

function pagina_fin(string $scripts = ''): void
{
    $marca = config()['marca'];
    ?>
</main>
<footer class="pie">
    <div class="contenedor">
        <p>© <?= date('Y') ?> <a href="<?= e($marca['sitio']) ?>"><?= e($marca['nombre']) ?></a><?php if ($marca['instagram']): ?> · <a href="<?= e($marca['instagram']) ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?></p>
    </div>
</footer>
<?= $scripts ?>
</body>
</html>
<?php
}
