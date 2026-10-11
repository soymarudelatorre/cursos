# Cursos online — Maru de la Torre

Plataforma de cursos en PHP, sin WordPress ni base de datos, con la misma estética y el
mismo acceso por email que el portal de guías y el Diagnóstico Tech. Reemplaza a Masteriyo.

## Qué hace (versión 1)

- **Cursos con módulos y lecciones.** Cada lección tiene un video (alojado en tu hosting y
  protegido, o de YouTube/Vimeo), un texto opcional y archivos para descargar.
- **Acceso sin contraseña.** La alumna escribe su email y recibe un enlace que vale 24 horas.
  La sesión queda abierta 30 días (`dias_sesion`).
- **Duración del acceso por curso:** 12 meses desde la fecha de pago, o sin vencimiento
  («mientras el curso esté disponible»). Al vencer, el curso se ve en «Mis cursos» como
  vencido y no se puede abrir.
- **Progreso guardado.** Cada lección se marca como vista. Hay una barra de avance, la opción
  «Continuar» donde quedó y mensajes de hito al 25, 50, 75 y 100 %, que reconocen el avance
  sin apurar: no hay rachas ni recordatorios.
- **Certificado (opcional por curso)** al completar el 100 %: tu imagen de fondo con el nombre y
  la fecha escritos encima, que acomodás arrastrándolos en el ejemplo. Aparece al final del
  temario y en «Mis cursos». Se guarda como PDF desde el navegador y queda disponible aunque el
  acceso al curso venza. Si el curso no tiene imagen, no entrega certificado.
- **Calificación con estrellas** (1 a 5) en cada lección y ranking general en el panel.
- **Compras por WooCommerce** (webhook): el pago habilita el curso y envía el email de
  bienvenida. Un reembolso o una cancelación quitan el acceso, pero el progreso y el
  certificado se conservan.
- **Panel** (`admin.php`) para crear cursos, cargar lecciones y archivos, habilitar o quitar
  accesos, cambiar vencimientos, ver el avance de cada alumna, exportar a CSV y enviar un
  backup por email.

## Videos: dónde alojarlos

Cada lección acepta **un video del servidor** (recomendado) o un enlace de YouTube o Vimeo.

### Videos en tu hosting (protegidos, sin costo extra)

- El video se entrega con `video.php` **solo a quien tiene acceso vigente**. Si alguien copia
  la dirección, sin sesión recibe un error 403. El reproductor no muestra el botón de descarga
  y permite adelantar y retroceder.
- No hay protección total: una alumna con acceso puede grabar la pantalla o, con
  conocimientos técnicos, descargar el archivo. Eso no lo evita ninguna plataforma.
- **Cómo subirlos:** por FTP o con el administrador de archivos de Ferozo, a la carpeta
  `videos/` dentro de la carpeta privada:
  - `public_html/cursos/privado/videos/`, si `config.php` está dentro de `cursos/`.
  - `cursos-privado/videos/` (en `storagedir`), si usás la carpeta externa. Es lo más seguro,
    porque está fuera de la web.

  Después, en la lección, elegís el archivo en «Video del servidor».
- **Formato:** MP4 (H.264 + AAC), 720p. Con [HandBrake](https://handbrake.fr/) (gratis):
  preset *Fast 720p30* y la casilla **Web Optimized** marcada, que hace que empiece a
  reproducirse antes de bajar todo. Ocupa unos 5 a 10 MB por minuto.
- **Nombres:** usá nombres claros con número de orden, como `01 Bienvenida.mp4`.
- **Espacio:** con 50 GB de hosting entran decenas de horas de video. Revisá el espacio usado
  en el panel de Ferozo. El backup por email **no** incluye los videos: guardá los originales.
- **Comprobación obligatoria:** abrí `https://www.marudelatorre.com/cursos/privado/videos/`
  más el nombre de un video. Tiene que dar **403**. Si se reproduce, el `.htaccess` no está
  funcionando: avisá antes de vender.
- Una transferencia «ilimitada» en hosting compartido suele estar sujeta a uso razonable.
  Para pocas decenas de alumnas no debería ser un problema. Si el curso crece mucho, conviene
  pasar a un servicio de video (Bunny o Vimeo).

### YouTube o Vimeo

- Un video **no listado** de YouTube **no está protegido**: cualquiera con el enlace lo ve, y
  el reproductor tiene enlaces a youtube.com que no se pueden ocultar sin violar las
  políticas de YouTube. Si igual lo usás, activá «Permitir inserción» en el video.
- Vimeo (planes pagos) permite restringir el video a tu dominio.

## Instalación

1. Subí el contenido del repositorio a `public_html/cursos/`.
2. Copiá `config.example.php` como `config.php` y completalo. Puede ir en uno de estos dos
   lugares:
   - **Dentro de `cursos/`**: es lo más simple. Los datos quedan en `cursos/privado/`,
     protegidos por `.htaccess`. Para actualizar, nunca borres la carpeta: reemplazá los
     archivos.
   - En una carpeta **`cursos-privado`** fuera de `public_html`. En DonWeb/Ferozo tiene que
     estar dentro de `storagedir`, la única carpeta que el servidor deja leer. Solo se usa si
     **no** hay un `config.php` dentro de `cursos/`.
3. Definí `admin_clave` (mínimo 12 caracteres) y la contraseña de la casilla en `smtp`.
4. Entrá a `https://www.marudelatorre.com/cursos/admin.php` y creá tu primer curso.
5. Probá el circuito con tu email: tus `emails_prueba` ven todos los cursos publicados sin
   compra. Cuando funcione, poné `'modo_demo' => false`.
6. Comprobá que estas direcciones den **403** o una página en blanco, nunca contenido:
   `/cursos/config.php`, `/cursos/privado/cursos.json` y `/cursos/lib/app.php`.

Requisitos: PHP 8.0 o superior, con `openssl`, `mbstring` e `iconv`, y Apache o LiteSpeed con
`.htaccess`.

**Archivos grandes:** el máximo por archivo depende del hosting (`upload_max_filesize`; el
panel lo muestra). Si necesitás subir archivos más pesados, subí ese valor y `post_max_size`
en Ferozo → Configuración de PHP.

## Cómo cargar un curso

1. Panel → **+ Crear curso**.
2. Agregá los **módulos** (por ejemplo «Introducción») y dentro de cada uno, las **lecciones**.
3. En cada lección: pegá el enlace del video, escribí el texto (opcional) y subí los archivos
   descargables. Las flechas ↑ ↓ cambian el orden.
4. En **Datos del curso**: duración del acceso, productos de WooCommerce y **Publicado**. Las
   alumnas solo ven los cursos publicados.
5. En **Certificado**: subí tu diseño como imagen, **sin** nombre ni fecha. Con «Ver un
   ejemplo» ajustás las alturas hasta que el texto caiga en su lugar.
6. «👁 Ver como alumna» muestra el curso tal como lo ve una alumna. En esa vista previa el
   progreso no se guarda.

**Cambios en un curso ya vendido:** si agregás lecciones, el porcentaje de quienes ya lo
habían terminado baja, pero el certificado que ya obtuvieron se conserva. Si borrás una
lección, deja de contar en el progreso.

## Compras por WooCommerce

1. **Producto:** WooCommerce → Productos → Añadir nuevo. Producto simple, marcado como
   **Virtual**. El **ID** se ve en la lista de productos al pasar el mouse. En la pestaña
   *Avanzado* → *Nota de compra*, poné el enlace `https://www.marudelatorre.com/cursos/`:
   aparece en el email de la compra, como respaldo.
2. **Panel del curso → Datos del curso:** escribí el ID (o el SKU) en «Productos de
   WooCommerce». Un producto solo puede habilitar un curso.
3. **config.php:** en `woocommerce` → `secreto`, poné un texto largo inventado por vos.
4. **Webhooks:** WooCommerce → Ajustes → Avanzado → Webhooks → Añadir webhook. Creá dos
   webhooks iguales que solo cambian en el tema: «Pedido creado» y «Pedido actualizado».
   - Estado: Activo.
   - URL de entrega: `https://www.marudelatorre.com/cursos/woocommerce.php`
   - Secreto: el mismo de config.php.
   - Versión de la API: WP REST API Integration v3.
5. **Probar:** comprá con un cupón del 100 % y revisá en el panel la tabla «Compras por
   WooCommerce». Tiene que aparecer en verde, como «habilitado … y se envió la invitación».

Reglas:
- El acceso de 12 meses se cuenta desde la **fecha de pago** del pedido.
- Cada pedido se procesa una sola vez, aunque WooCommerce avise varias veces.
- Si la misma persona vuelve a comprar el curso, el acceso se reemplaza por el nuevo, salvo
  que el que tiene dure más. Las renovaciones que suman tiempo quedan para una fase 2.
- Si el webhook responde con error 5 veces seguidas, WooCommerce lo desactiva. La tabla del
  panel muestra el último aviso recibido.

## Datos y respaldo

Todo se guarda en archivos dentro de la carpeta privada:

| Archivo | Contenido |
|---|---|
| `cursos.json` | Cursos, módulos, lecciones, enlaces de video y ajustes del certificado |
| `alumnos.json` | Accesos, vencimientos, lecciones vistas y certificados de cada persona |
| `videos/` | Videos de las lecciones (subidos por FTP) |
| `recursos/` | Archivos descargables |
| `certificados/` | Imágenes de fondo de los certificados |
| `woocommerce-pedidos.json`, `woocommerce-avisos.csv` | Pedidos procesados y avisos recibidos |

- **No se suben a GitHub:** `.gitignore` excluye `config.php` y `privado/`.
- **«Backup por email»** envía los JSON y el CSV. Los archivos descargables no van en ese
  correo: guardá aparte los originales.
- Cada escritura bloquea el archivo, así dos cambios simultáneos no se pisan. Para cientos de
  alumnas alcanza. Si el volumen crece mucho, los datos se pueden pasar a MySQL.

## Legales (Argentina) — revisalo con tu contador o abogado

- Las condiciones del acceso («12 meses desde la compra» o «sin vencimiento mientras el curso
  esté disponible») tienen que figurar en la página del producto **antes** del pago.
- La venta online exige el **botón de arrepentimiento** (Resolución 424/2020 de la Secretaría
  de Comercio Interior) en tu sitio de WordPress.
- Datos personales: la Ley 25.326 incluye la inscripción de bases de datos ante la AAIP.

## Estructura

```
index.php          Pedido del enlace de acceso y «Mis cursos»
curso.php          Temario y lección (video, texto, archivos, marcar como vista)
recurso.php        Entrega los archivos solo a quien tiene acceso vigente
video.php          Entrega los videos del servidor solo a quien tiene acceso vigente
certificado.php    Certificado (imagen + nombre y fecha) y ajuste por arrastre desde el panel
woocommerce.php    Recibe los webhooks de WooCommerce
admin.php          Panel: cursos, accesos, avance, avisos de WooCommerce, CSV, backup
admin-curso.php    Editor de un curso, sus lecciones y su certificado
arranque.php       Carga común de las páginas
lib/               Lógica (bloqueada desde el navegador)
privado/           Datos (bloqueada desde el navegador)
```

## Próximas fases (ideas)

- Renovaciones que suman 12 meses al vencimiento vigente.
- Aviso por email 30 días antes del vencimiento.
- Insignias por módulo terminado.
- Certificado generado como PDF en el servidor.
