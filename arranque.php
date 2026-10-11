<?php
declare(strict_types=1);

/*
 * Carga común de todas las páginas. Si falta algún archivo (zip subido a medias),
 * se dice cuál en lugar de un «Error 500».
 */
foreach (['app', 'plantilla', 'correo', 'cursos', 'alumnos', 'woocommerce', 'panel'] as $archivoLib) {
    if (!is_file(__DIR__ . "/lib/$archivoLib.php")) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        exit("Falta el archivo lib/$archivoLib.php en el servidor. Subí el zip completo de los cursos y reemplazá los archivos.");
    }
    require __DIR__ . "/lib/$archivoLib.php";
}
