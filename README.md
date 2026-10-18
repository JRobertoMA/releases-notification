# Sistema de Notificaciones de Releases por Telegram

Sistema web desarrollado en PHP para monitorear y notificar automáticamente sobre nuevas versiones de repositorios de GitHub, GitLab, Docker Hub y Registro OCI mediante Telegram.

## Características

- **Monitoreo Multi-Plataforma**: Soporta repositorios de GitHub, GitLab, Docker Hub y registros OCI (ghcr.io, lscr.io, quay.io…)
- **Notificaciones Automáticas**: Envía alertas a Telegram cuando se detecta una nueva versión
- **Seguimiento por Digest**: Detecta actualizaciones de tags flotantes (`latest`, `stable`) comparando el digest SHA-256, no solo el nombre del tag
- **Patrones de Tag con Comodín**: `#.#-apache` rastrea `8.5-apache`, `8.6-apache`, `9.0-apache`, etc., sin fijar la versión
- **Etiquetas de Repositorio**: Clasifica repos con etiquetas personalizadas (soportan espacios) y filtra la tabla por ellas
- **Versión Instalada**: Registra qué versión tienes instalada y muestra visualmente cuándo hay una actualización pendiente
- **Verificación Individual o en Segundo Plano**: un repo al momento; varios o todos en segundo plano, con barra de progreso y sin bloquear la página
- **Alta pegando una URL**: `github.com/…`, `hub.docker.com/…`, `ghcr.io/…:tag` — el tipo y el nombre se detectan solos
- **Vista Previa del Patrón**: mientras escribes el patrón ves qué tags coinciden y cuál se seguirá; si no coincide ninguno, propone uno que sí
- **Acciones Masivas**: seleccionar varios repos para sincronizar, verificar o etiquetar a la vez
- **Salud del Cron**: la portada avisa si la verificación automática dejó de ejecutarse
- **Configuración Asistida**: probar el token del bot, detectar el chat sin copiar IDs, enviar un mensaje de prueba y comprobar el GitHub Token
- **Modo Oscuro/Claro**: botón en la barra superior (automático / claro / oscuro), guardado para cada usuario
- **Gestión Completa**: agregar, editar y eliminar repositorios desde la interfaz web; versión móvil en tarjetas
- **Acceso con Usuarios**: inicio de sesión, roles (Administrador / Editor / Lector), verificación en dos pasos
  (obligatoria para administradores), «Recordarme» y bloqueo tras intentos fallidos

## Requisitos

- PHP 8.1 o superior, con las extensiones `curl` y `pdo_mysql`
- MariaDB 11.8 o superior
- Composer
- Apache con `mod_rewrite` y `AllowOverride All` (todo lo que no sea `public/` se protege por `.htaccess`)
- Un bot de Telegram
- Acceso a internet para consultar las APIs

## Estructura

Solo **`public/`** se sirve por web: páginas, `api/` y `assets/`. Todo lo demás queda fuera: código, `vendor/`,
datos, configuración, `cli/`, el cron y `.git/`.
- **En Web Hosting**, el subdominio apunta directamente a `public/`.
- **En local**, el `.htaccess` de la raíz reescribe todo a `public/`, así que la URL sigue siendo
  `http://localhost:81/releases-notification/`.

## Configuración: `.env` y Configuración

Lo de cada entorno y todos los secretos van en el **`.env`** (plantilla comentada: `.env.example`). **La web solo lo
lee**: Configuración muestra si cada valor está definido y cómo termina, y permite probarlo, pero cambiarlo se hace
editando el fichero por SSH.

| `.env` | Para qué |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MariaDB |
| `TELEGRAM_BOT_TOKEN` | token del bot (@BotFather) |
| `GITHUB_TOKEN` | opcional; con `read:packages`, también ghcr.io |
| `PHP_CLI` | PHP de consola para «Verificar» y el cron (vacío = autodetectar) |
| `APP_TIMEZONE` | zona horaria de las fechas (por defecto `America/Mexico_City`) |
| `FORCE_HTTPS` | opcional; `false` desactiva la redirección automática a HTTPS |
| `APP_KEY` | cifra los secretos de la verificación en dos pasos. **No la escribas**: la genera `cli/migrate.php`. Guarda una copia: si se pierde, hay que restablecer el 2FA de todos |

Lo ajustable desde la web se guarda en la base de datos (**Configuración**, solo admin): el chat de Telegram
(«Detectar chat»), el intervalo del cron, los registros OCI adicionales y el tema por defecto.

## Instalación local (Docker)

```bash
cd htdocs && git clone https://github.com/JRobertoMA/releases-notification.git
cd releases-notification && cp .env.example .env && chmod 600 .env   # rellena DB_* (DB_HOST=mariadb) y los tokens
docker exec -u www-data -w /var/www/html/releases-notification php-apache composer install
docker exec -u www-data php-apache php /var/www/html/releases-notification/cli/migrate.php
docker exec -it -u www-data php-apache php /var/www/html/releases-notification/cli/user.php create <usuario>
```

Después abre `http://localhost:81/releases-notification/`, inicia sesión, ve a **Configuración**, pulsa **Detectar
chat**, elige el chat, pulsa **Enviar prueba** y guarda.

## Instalación en web Hosting

1. **En el panel:**
   - subdominio con document root `~/notificador/public`;
   - PHP 8.1 o superior;
   - SSL activo;
   - una base de datos MariaDB.
2. **Por SSH, la primera vez:**
   ```bash
   cd ~ && git clone https://github.com/JRobertoMA/releases-notification.git notificador
   cd ~/notificador && git checkout vX.Y.Z
   cp .env.example .env && chmod 600 .env && nano .env      # DB_*, TELEGRAM_BOT_TOKEN, GITHUB_TOKEN, PHP_CLI=/usr/bin/php8.5-cli
   /usr/bin/php8.5-cli ~/composer/composer.phar install --no-dev --optimize-autoloader
   /usr/bin/php8.5-cli cli/migrate.php
   /usr/bin/php8.5-cli cli/user.php create <usuario>
   ```
3. **En la web:** inicia sesión y elige el chat en Configuración, como en local.
4. **Crontab** (`crontab -e`): copia la línea que muestra Configuración.
5. **Para actualizar a otra versión:**
   ```bash
   git fetch --tags && git checkout vX.Y.Z \
     && /usr/bin/php8.5-cli ~/composer/composer.phar install --no-dev --optimize-autoloader \
     && /usr/bin/php8.5-cli cli/migrate.php
   ```

### Venir de una versión con ficheros JSON (≤ 1.0.x)

Desde la 1.1.0 los datos están en MariaDB. Tras crear el `.env` con la base de datos y ejecutar `cli/migrate.php`, lanza
una sola vez:

```bash
/usr/bin/php8.5-cli cli/import_json.php        # Docker: docker exec -u www-data php-apache php …/cli/import_json.php
```

- **Qué importa:** repos (mismos ids y orden), historial, log y usuarios (con sus sesiones «Recordarme» vigentes).
- **Secretos:** pasa los tokens de `config.json` al `.env`, solo si allí faltan y sin mostrarlos. El resto de
  `config.json` va a la base de datos.
- **Los JSON:** los renombra a `*.imported`. **Borra `config.json.imported`** cuando compruebes que todo funciona,
  porque todavía contiene tus tokens.

### Configuración por entorno

Mismo código en los dos sitios. Lo que cambia está en el `.env` de cada uno:

| Clave | Docker local | IONOS |
|---|---|---|
| `DB_HOST` | `mariadb` | el host del panel de IONOS |
| `PHP_CLI` | vacío: se autodetecta | `/usr/bin/php8.5-cli` |
| `APP_TIMEZONE` | `America/Mexico_City` | `America/Mexico_City` (el servidor está en New York) |
| HTTPS | no se fuerza en `localhost` ni en IPs privadas | se fuerza solo (301 + HSTS) |
| Crontab | en el host: `docker exec -u www-data php-apache php …/check_releases.php` | por SSH: `/usr/bin/php8.5-cli -f ~/notificador/check_releases.php` |

### Publicar una versión

IONOS ejecuta siempre un **tag**, nunca `main`, así que producción solo cambia cuando tú lo decides:

1. Actualiza `VERSION` y `CHANGELOG.md`.
2. `git add -A && git commit`. Con `-A` se registran también los ficheros movidos o borrados.
3. `git tag -a vX.Y.Z -m "…"` y `git push && git push --tags`. Si quieres, crea también un Release en GitHub.
4. En IONOS: el paso 5 de arriba (incluye `cli/migrate.php` por si la versión trae migraciones).

La versión que corre se ve en el pie de página.

## Usuarios

- **Solo se crean por consola.** La web no ofrece alta, para que nadie que encuentre la URL pueda crear el primer
  usuario.
  ```bash
  php cli/user.php create <usuario> [--role=admin|editor|viewer]   # el primero siempre es admin; sin --role, lector
  php cli/user.php password <usuario>    # cambiar o recuperar una contraseña
  php cli/user.php role <usuario> <rol>  # admin | editor | viewer
  php cli/user.php 2fa-reset <usuario>   # quitar su verificación en dos pasos (móvil perdido)
  php cli/user.php delete <usuario>
  php cli/user.php list
  ```
  En IONOS, `php` es `/usr/bin/php8.5-cli`; en Docker, `docker exec -it -u www-data php-apache php …`.
- **Roles:**

  | Rol | Puede |
  |---|---|
  | Lector | ver repos, historial, logs y dashboard; su propia cuenta |
  | Editor | además añadir, editar, borrar, sincronizar, etiquetar y verificar repos |
  | Administrador | además Usuarios y Configuración |

  Nunca puede quedar el sistema sin un administrador.
- La página **Usuarios** (solo administradores) permite añadir usuarios con su rol, cambiar roles y contraseñas,
  restablecer la verificación en dos pasos de otro y eliminar.
- **Mi cuenta** (menú de usuario, todos los roles): tu contraseña y tu verificación en dos pasos.
- **Verificación en dos pasos (2FA):**
  - Al entrar, además de la contraseña, se pide el código de 6 dígitos de una app de autenticación (Google
    Authenticator, Aegis, 2FAS, Bitwarden…).
  - Es **obligatoria para los administradores**: hasta activarla solo pueden usar «Mi cuenta». Para el resto es
    opcional.
  - Al activarla se muestran **10 códigos de recuperación** de un solo uso, por si pierdes el móvil. Guárdalos fuera
    del móvil.
  - Si se pierden también los códigos, otro administrador puede restablecerla, o se usa `cli/user.php 2fa-reset`.
- **Recordarme** mantiene la sesión 30 días en ese navegador. Sin ella, la sesión dura 2 h sin actividad.
- **Intentos fallidos:**
  - 5 en 15 minutos desde la misma red bloquean esa red un rato (IPv6 cuenta por bloque `/64`);
  - por usuario, cada fallo a partir del 5.º añade una espera creciente, de 30 s hasta 5 min.

## Verificación automática (cron)

La verificación automática es una línea de crontab que ejecuta `check_releases.php` **por consola**. Ya no existe la
verificación por URL con token. Configuración → «Línea para tu crontab» la da lista para copiar con tu ruta y tu PHP.
Se actualiza mientras cambias el intervalo y explica a qué horas se ejecutará. Al cambiarlo, guarda la configuración
**y** sustituye la línea de tu crontab: la app no puede editar el crontab del servidor. Ejemplos:

```cron
# IONOS (crontab -e por SSH). MAILTO para recibir los errores por correo
MAILTO=<tu correo>
0 */6 * * * /usr/bin/php8.5-cli -f /<ruta>/notificador/check_releases.php > /dev/null

# Docker (crontab del host), como www-data para que los ficheros sigan siendo de Apache
0 */6 * * * docker exec -u www-data php-apache php /var/www/html/releases-notification/check_releases.php > /dev/null 2>&1

# Solo algunos repositorios, por su id (el que aparece en la URL de Editar: edit_repo.php?id=…)
php check_releases.php --repo=a1b2c3,d4e5f6
```

## Uso

### Agregar un Repositorio

Pulsa **+ Agregar** y pega la URL del repositorio o de la imagen: el tipo se detecta solo. También puedes escribir
el nombre y elegir el tipo a mano, como en los ejemplos siguientes.

#### GitHub / GitLab
```
Repositorio: owner/nombre-repo
Tipo: GitHub / GitLab
Etiquetas: gaming, self-hosted   (opcional, separadas por coma)
```

#### Docker Hub

```
Repositorio: nginx            ← imagen oficial
Repositorio: _/nginx          ← notación alternativa para imágenes oficiales
Repositorio: linuxserver/plex ← imagen de usuario
Tipo: Docker Hub
Patrón de tag: #.#-apache     ← opcional, ver sección siguiente
```

#### Registro OCI (ghcr.io, lscr.io, quay.io…)

```
Repositorio: ghcr.io/linuxserver/jellyfin       ← host + owner + imagen
Repositorio: https://ghcr.io/home-assistant/home-assistant:stable   ← también vale pegado: se normaliza
Repositorio: quay.io/prometheus/node-exporter
Tipo: Registro OCI
```

El nombre se guarda como `host/owner/imagen` (sin `https://`, `:tag` ni `@sha256:`). Si no lleva host se asume
`ghcr.io`. `lscr.io` es un alias de `ghcr.io/linuxserver` y se consulta igual.

Cómo se consulta:

- **ghcr.io / lscr.io con GitHub Token con `read:packages`** (recomendado): API de Packages de GitHub, con las
  versiones ordenadas por fecha. Funciona igual que Docker Hub: gana el tag más reciente que cumpla el patrón y,
  sin patrón, el tag más reciente que no sea `latest` ni de una arquitectura concreta (`amd64-…`, `arm64v8-…`).
  Se ignoran las firmas (`sha256-…`) y las versiones sin tag.
- **Otros hosts, o ghcr.io sin token**: registro OCI anónimo, cuya lista de tags no trae fechas. Por eso:
  - sin patrón se sigue **`latest`** por digest;
  - con un tag exacto (`stable`) se sigue ese tag por digest;
  - con comodines gana la **versión numéricamente más alta** (`v1.12.1` > `v1.9.0`).
  - La fecha del release es la de construcción de la imagen.

### Patrón de Tag (Docker Hub y OCI)

El campo **Patrón de tag** controla qué variante de imagen se monitorea:

| Patrón | Semántica | Encuentra |
|---|---|---|
| `#.#-apache` | cada `#` = un segmento numérico | `8.5-apache`, `9.0-apache` (no `8.5.3-apache`) |
| `#.#.#-apache` | 3 comodines = 3 segmentos | `8.5.3-apache`, `9.0.1-apache` |
| `#.#-fpm` | cada `#` = un segmento numérico | `8.5-fpm`, `9.1-fpm` (no `8.5.3-fpm`) |
| `stable*` | `*` = cualquier texto | `stable`, `stable-fpm-alpine` |
| `*-slim` | `*` = cualquier texto | `lts-trixie-slim`, `22-slim` |
| `alpine` | exacto (sin comodines) | solo el tag `alpine` — usa `*alpine*` para «contiene» |
| `latest` | exacto + digest | rastrea cambios de contenido aunque el nombre no cambie |
| *(vacío)* | sin filtro | el tag más reciente (excluye `latest`) |

La cantidad de `#` debe coincidir exactamente con la cantidad de segmentos numéricos del tag — usa tantos comodines como segmentos esperes.

Al agregar o editar un repositorio Docker u OCI, el botón **Cargar variantes** descarga hasta 100 tags reales del registro para que elijas el patrón con un clic.

### Detección de Tags Flotantes (`latest`, `stable`)

Para imágenes que solo publican bajo `latest` o `stable`, el sistema compara el **digest SHA-256** en cada verificación. Si el contenido cambia (aunque el nombre del tag no), se envía notificación.

### Registrar tu Versión Instalada

En el formulario **Editar** de cada repositorio puedes indicar:
- **Versión instalada**: la versión que tienes corriendo actualmente.
- **Fecha de actualización**: cuándo la actualizaste por última vez.

La tabla marca el repo como **Pendiente** (⚠) cuando hay una versión más reciente que la instalada. Para tags flotantes se compara la fecha del último cambio detectado con tu fecha de actualización.

Cuando actualices, pulsa **Sincronizar** en la fila (o selecciona varios repos y usa la barra de acciones masivas):
la versión instalada pasa a ser la última detectada, con la fecha de hoy.

### Estados

| Estado | Significado |
|---|---|
| Al día | La última verificación no encontró nada nuevo |
| Nuevo | La última verificación detectó una versión nueva |
| Sin coincidencias | El patrón de tag no encontró ningún tag: revisa el patrón (el editor sugiere uno) |
| Error | La API no respondió o el repositorio no existe; mira los Logs |
| Pendiente | Además del estado: tu versión instalada no es la última detectada |

Las tarjetas de la portada filtran la tabla por estado; los filtros se recuerdan entre visitas.

### Enlace Directo a la Capa de Docker Hub

Para repositorios Docker con digest almacenado, el botón de enlace en Editar apunta directamente a la capa exacta (`hub.docker.com/layers/...`), lo que permite verificar manualmente el contenido de la imagen detectada.

## Formato de Notificaciones

```
🔔 Nuevo Release Detectado
──────────────────────
🐙 microsoft/vscode
📦 v1.97.0
📅 2025-03-15

→ Ver Release
```

```
🔔 Nuevo Release Detectado
──────────────────────
🐋 _/php
📦 8.5.3-apache
📅 2025-03-15

docker pull _/php:8.5.3-apache
```

Los emojis varían por tipo: 🐙 GitHub · 🦊 GitLab · 🐋 Docker Hub.

## APIs utilizadas

| Fuente | Endpoint |
|---|---|
| GitHub | `api.github.com/repos/{owner}/{repo}/releases/latest` |
| GitLab | `gitlab.com/api/v4/projects/{repo}/releases` |
| Docker Hub | `hub.docker.com/v2/repositories/{repo}/tags/` |

**Límites sin autenticación:** GitHub 60 req/hora; Docker Hub ~100 req/6h por IP. Añade `GITHUB_TOKEN` en la configuración para elevar el límite de GitHub a 5 000 req/hora. Si el token es clásico y tiene el scope `read:packages`, también se usa para consultar imágenes de ghcr.io (ver «Registro OCI»).

## Datos

Todo está en MariaDB. El esquema completo de referencia está en `database/database.sql`, y los cambios se aplican con
`cli/migrate.php` (`database/migrations/`):

- **Tablas:** repos, historial de releases, log, usuarios y sus sesiones «Recordarme», intentos de login, verificaciones
  en segundo plano, ajustes de Configuración y estado.
- **Copias de seguridad:** `mariadb-dump` de la base (en IONOS, además, las copias del panel) y el `.env`.

## Permisos de Archivos (producción)

```bash
chmod 600 .env
chmod 700 data data/sessions
```

La app crea sus propios ficheros (sesiones, bloqueo del worker) ya privados. En Docker deben ser de `www-data`.

## Solución de Problemas

### No se reciben notificaciones
1. Configuración: el token del bot debe aparecer como «Definido» (`.env`) y el chat debe estar elegido; pulsa **Probar** y **Enviar prueba**
2. Comprobar el cron: `crontab -l`
3. Revisar logs en la pestaña **Logs** del dashboard
4. Probar manualmente: `/usr/bin/php8.5-cli /ruta/check_releases.php` (IONOS) o el `docker exec` de arriba
5. En IONOS, comprobar en Configuración → «PHP de consola» que no es el PHP 4.4

### Un repositorio Docker siempre da error
- Usar el botón **Verificar** individual del repo para aislar el problema
- Comprobar el nombre exacto de la imagen en Docker Hub
- Para imágenes como `_/php` con muchas variantes, definir un **Patrón de tag** (ej: `#.#-apache`)
- Si el error persiste, puede ser rate-limiting de Docker Hub; esperar unos minutos y reintentar

### «Falta configurar la base de datos» o «Faltan las tablas»
Falta el `.env` o las credenciales no son correctas, o no se ha ejecutado `cli/migrate.php`. El detalle del error de
conexión está en el log de errores del servidor, nunca en la página.

### Me cierra la sesión enseguida
La sesión dura 2 h sin actividad y se guarda en `data/sessions/`, que debe poder escribirse (`chmod 700 data`). Marca
«Recordarme» para 30 días.

### Error de permisos
En Docker, si algo lo creó root: `docker exec php-apache chown -R www-data:www-data /var/www/html/releases-notification/data`.
