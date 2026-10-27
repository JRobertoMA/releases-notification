# Changelog

Versiones publicadas (tags `vX.Y.Z`). En PROD se despliega siempre un tag, nunca `main`
(ver «Publicar una versión» en el README).

## 1.4.0 — 2026-10-26

### Botones en los avisos de Telegram
- Con el backend del bot, cada aviso trae botones:
  - **✅ Ya actualicé:** lo mismo que «Sincronizar» en la web;
  - **🔕 Ignorar:** esa versión deja de contar como pendiente hasta que salga otra;
  - **↗ Ver:** el release o la imagen;
  - **📋 copiar el `docker pull`**, en Docker y OCI.
- Tras pulsar uno, el teclado cambia a «✅ 10.11.2 instalada · usuario», o «🔕 … ignorada», para que en el chat se
  vea quién hizo qué.
- Sin backend del bot (envío directo con el token) el aviso sigue como antes: sin botones, con el enlace y el
  `docker pull` en el texto.

### Vincular la cuenta de Telegram
- **Mi cuenta → Telegram → Vincular Telegram** genera un código `REL-XXXXXXXX` (un solo uso, 10 minutos) que se
  envía al bot con `/vincular`. También se puede desvincular desde ahí.
- Una cuenta de Telegram solo puede estar vinculada a un usuario, y viceversa.
- **Permisos:** los botones actúan con el rol que el usuario tenga en ese momento.
  - Un Lector no puede cambiar nada.
  - Un administrador que aún debe activar el 2FA tampoco puede actuar.
  - Sin vincular, el bot pide vincular.
- Cada acción desde Telegram queda en Logs como «[bot] usuario sincronizó …».
- **Usuarios:** columna Telegram, con la opción de desvincular a cualquier usuario.

### Ignorar una versión desde la web
- En el menú `⋯` de cada fila: «Ignorar X» y «Dejar de ignorar X». La fila muestra la etiqueta «Ignorada».
- Se anula sola en cuanto sale otra versión. Sincronizar también la quita.

### Correcciones
- El `docker pull` de las imágenes oficiales salía como `docker pull _/php:…`, que no es válido. Ahora sale
  `docker pull php:…`.

### Actualizar
1. `git checkout v1.4.0`, `composer install` y `cli/migrate.php`. La migración `003` añade las tablas de vínculos y
   la columna `repos.ignored_release`.
2. En el bot, el proyecto `releases` necesita las rutas `callback rel` y `link REL` (`cli/project.php list`).

Ver `SECURITY_AUDIT.md` §5.

## 1.3.0 — 2026-10-26

### Avisos a través del backend compartido del bot
- Los avisos, «Probar», «Detectar chat» y «Enviar prueba» van por el **backend del bot** (proyecto `telegram-bot`),
  que es quien tiene el webhook de Telegram. Lo activan dos claves nuevas en el `.env`: `BOT_BACKEND_URL` y
  `BOT_PROJECT_KEY`. Hay una tercera opcional, `BOT_PROJECT`, por defecto `releases`.
- **«Detectar chat» vuelve a funcionar:** con el webhook activo, Telegram rechazaba `getUpdates` (409). Ahora los
  chats salen del registro del bot.
- `TELEGRAM_BOT_TOKEN` queda solo de respaldo: sin las claves `BOT_*` se envía directo, como antes. Cuando el bot
  funcione se puede borrar del `.env`, y así el token vive solo en el bot.
- Nuevo `api/bot.php`, que recibe los eventos del bot. No usa sesión, y las peticiones van firmadas con HMAC y
  caducan a los 5 minutos. En esta versión solo responde al `ping` del autotest del bot (`cli/selftest.php`).
- **Configuración:** nueva fila «Backend del bot» con el modo de envío. Si el token sigue en el `.env` aunque ya no
  se use, se avisa.

### Cambios en los avisos
- La fecha 📅 sale en la hora local de la app. Antes se cortaba la fecha UTC de la API, el mismo fallo que corrigió
  la 1.2.1 en «Pendiente».
- Por el bot, los avisos van sin vista previa de enlaces.
- Si un aviso no se puede enviar, queda un aviso en Logs. Antes solo iba al log de errores del servidor.

### Actualizar
1. `git checkout v1.3.0` y `composer install`. No hay migraciones.
2. Añade `BOT_BACKEND_URL` y `BOT_PROJECT_KEY` al `.env`. La clave la da `cli/project.php add releases …` en el
   bot.
3. Comprueba la conexión con `cli/selftest.php releases`, en el bot.

Ver `SECURITY_AUDIT.md` §5.

## 1.2.1 - 1.2.2 - 1.2.3 — 2026-10-21

### Correcciones
- **Un repo sincronizado el mismo día seguía «Pendiente»** si el release salió por la tarde o noche. La fecha del
  release llega en UTC y se comparaba tal cual con la fecha local de «instalada»: en México, todo lo publicado
  después de las 18:00 cae al día siguiente en UTC. Ahora se convierte a la zona de la app antes de comparar.

### Configuración
- Nueva clave opcional `REQUIRE_ADMIN_2FA=false` en el `.env`, que hace opcional el 2FA de los administradores.
  Pensada **solo para el Docker local**. Sin ella, o con cualquier otro valor, sigue siendo obligatorio.
  Configuración muestra cuál está activo.

## 1.2.0 — 2026-10-15

### Roles
- Tres roles:
  - **Administrador:** usuarios, Configuración y repos.
  - **Editor:** añadir, editar, borrar, sincronizar, etiquetar y verificar repos.
  - **Lector:** solo ver.
- Lo comprueba el servidor en cada página y endpoint, y la interfaz oculta lo que tu rol no permite.
- Los usuarios existentes pasan a **Administrador**. Nunca puede quedar el sistema sin un administrador.
- **Usuarios** es solo para administradores: alta con rol, cambio de rol y contraseña, restablecer el 2FA y borrar.
- Nueva página **Mi cuenta** para tu contraseña y tu 2FA.
- `cli/user.php`: `create --role=…`, `role <usuario> <rol>`, `2fa-reset <usuario>`; `list` muestra rol y 2FA.
- El **tema** es ahora de cada usuario. El de Configuración queda como tema por defecto.

### Verificación en dos pasos (TOTP)
- Código de 6 dígitos de una app de autenticación, con QR para darlo de alta.
- **Obligatoria para administradores:** hasta activarla solo pueden usar «Mi cuenta». Opcional para el resto.
- 10 códigos de recuperación de un solo uso.
- Un código ya usado no vuelve a servir.
- El segundo paso comparte el límite de intentos del login.
- «Recordarme» solo se concede tras el segundo paso.
- El secreto se guarda cifrado con `APP_KEY`, que `cli/migrate.php` genera y añade al `.env`.

### Configuración
- La línea de crontab se actualiza **al cambiar el intervalo**, sin guardar antes. Explica a qué horas se ejecutará
  y avisa de que falta guardar.
- Intervalos de 48 h o más ya no se quedan en «una vez al día»: cada N días, o semanal con 168 h.

### Correcciones
- Los mensajes de «Registro no permitido» todavía decían que se añadiera el registro en `config.json`.

### Actualizar
`git checkout v1.2.0`, `composer install` y `cli/migrate.php`. Después, en la web, activa tu 2FA (ver
`SECURITY_AUDIT.md` §5).

## 1.1.0 — 2026-10-14

### Datos en MariaDB
- Los repos, el historial, el log, los usuarios y sus sesiones «Recordarme», los intentos de login, las verificaciones
  en segundo plano y los ajustes pasan de ficheros JSON a **MariaDB**: 11.8 en PROD, contenedor `mariadb` en local.
- `cli/migrate.php` aplica `database/migrations/*.sql` (idempotente). `database/database.sql` es el esquema de
  referencia.
- `cli/import_json.php` importa una sola vez los JSON de la versión anterior y los renombra a `*.imported`.
- Las clases conservan su API (`RepoStore`, `Users`, `LoginThrottle`, `Jobs`, `RunHealth`), así que las páginas
  apenas cambian. La protección de concurrencia pasa de `flock` a `GET_LOCK` + transacciones: una edición durante una
  verificación sigue sin perderse (probado).
- El log se poda a 90 días; el historial ya no tiene tope de 500.

### Configuración: `.env` + base de datos; adiós a `config.json`
- **Secretos y entorno en el `.env`**, que la web solo lee: `TELEGRAM_BOT_TOKEN`, `GITHUB_TOKEN`, `DB_*`, `PHP_CLI`,
  `APP_TIMEZONE`, `FORCE_HTTPS`. Configuración muestra su estado («termina en …») y permite probarlos, pero no
  cambiarlos. Plantilla: `.env.example` (sustituye a `config.example.json`).
- Ajustes web (chat de Telegram, intervalo, registros OCI extra, tema) en la tabla `settings`.
- La importación pasa los tokens de `config.json` al `.env` sin mostrarlos.
- Página clara («Falta configurar la base de datos» / «Faltan las tablas») en lugar de un error 500, y aviso en la
  portada si falta el token o el chat.
- `.env` añadido a `.gitignore`.

### Correcciones
- La «salud del cron» volvía a «sin registro» si la última verificación era de un solo repo; ahora busca la última
  verificación completa.

## 1.0.1 — 2026-10-14

### Seguridad
- **Ficheros privados**: todo lo que crea la app (`config.json`, `data/`, sesiones, JSON de datos, bloqueos y jobs)
  nace con permisos `600` (ficheros) o `700` (carpetas).
  - En PROD el checkout queda legible por «otros» y el servidor es compartido.
  - Se aplica con `umask(0077)` en `includes/bootstrap.php`, `check_releases.php` y `cli/user.php`, y con un `chmod`
    explícito en `JsonFile::claimNew()`: con ACL por defecto en la carpeta, Linux ignora la umask.
- Los ficheros ya creados en PROD necesitan un `chmod` de una sola vez (ver `SECURITY_AUDIT.md` §5).

## 1.0.0 — 2026-10-14

Primera versión preparada para publicarse en internet (PROD Web Hosting). Aplica los arreglos de
`SECURITY_AUDIT.md`.

### Cambia la estructura
- Las páginas, `api/` y `assets/` viven ahora en **`public/`**, que es lo único que se sirve. Código, datos,
  configuración, `vendor/` y `.git/` quedan fuera.
- El `.htaccess` de la raíz reescribe todo a `public/`: en local la URL sigue siendo
  `http://localhost:81/telegram-notification/`.
- Cada carpeta privada (`includes/`, `src/`, `data/`, `cli/`, `tools/`) tiene su propio `Require all denied`.

### Seguridad
- **Usuarios solo por consola** (`cli/user.php create|password|delete|list`). La web ya no ofrece «Crear
  administrador».
- **HTTPS forzado** fuera de `localhost` y de las IPs privadas, más HSTS, `X-Frame-Options`/`frame-ancestors`,
  `nosniff`, `Referrer-Policy`, `X-Robots-Tag: noindex` y `Cache-Control: no-store`. Nuevo `robots.txt`.
- **Configuración ya no muestra los tokens**: solo cómo terminan. Un campo vacío conserva el valor guardado.
- **Límite de intentos de login**:
  - las IPv6 se agrupan por `/64`;
  - por usuario, retardo creciente (30 s → 5 min) en lugar de un bloqueo de 15 min;
  - tope de 2000 entradas.
- **Registros OCI en lista blanca** (`OCI_REGISTRIES`). El `realm` de autenticación tiene que ser HTTPS y del mismo
  dominio, y las redirecciones solo pueden ir a HTTPS.
- El token del bot ya no aparece en el log de errores.
- Los datos insertados en `<script>` se escapan con `js()`.

### PROD y cron
- **Se elimina el cron por HTTP** (`check_releases.php?token=`) y con él `CRON_SECRET`. `check_releases.php` solo
  funciona por consola, y Configuración muestra la línea de crontab lista para copiar.
- **`PHP_CLI`**: ruta del PHP de consola. La autodetección nunca elige un PHP < 8 (en PROD `php` es PHP 4.4).
- **`APP_TIMEZONE`**: la zona horaria la fija la app (web y cron), no el servidor (PROD: America/New_York).
- **Sesiones en `data/sessions`** con 2 h de inactividad real. En PROD, las de `/tmp` se borraban a los 24 min.
- Un 429 (límite de peticiones de Docker Hub o de un registro) ya no marca el repo como «Error»: se reintenta.
- `composer.lock` pasa a versionarse y `.claude/` a ignorarse.
- `VERSION` + versión en el pie de página.
