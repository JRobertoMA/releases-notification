# Sistema de Notificaciones de Releases por Telegram

Sistema web desarrollado en PHP para monitorear y notificar automáticamente sobre nuevas versiones de repositorios de GitHub, GitLab y Docker Hub mediante Telegram.

## Características

- **Monitoreo Multi-Plataforma**: Soporta repositorios de GitHub, GitLab y Docker Hub
- **Notificaciones Automáticas**: Envía alertas a Telegram cuando se detecta una nueva versión
- **Seguimiento por Digest**: Detecta actualizaciones de tags flotantes (`latest`, `stable`) comparando el digest SHA-256, no solo el nombre del tag
- **Patrones de Tag con Comodín**: `#.#-apache` rastrea `8.5-apache`, `8.6-apache`, `9.0-apache`, etc., sin fijar la versión
- **Etiquetas de Repositorio**: Clasifica repos con etiquetas personalizadas (soportan espacios) y filtra la tabla por ellas
- **Versión Instalada**: Registra qué versión tienes instalada y muestra visualmente cuándo hay una actualización pendiente
- **Verificación Individual**: Botón por repositorio para verificar solo ese repo sin afectar al resto
- **Modo Oscuro/Claro**: Toggle persistido en localStorage, sin flash al navegar entre páginas
- **Gestión Completa**: Agregar, editar y eliminar repositorios desde la interfaz web

## Requisitos

- PHP 7.4 o superior
- Composer (gestor de dependencias PHP)
- Servidor web (Apache, Nginx, etc.)
- Bot de Telegram configurado
- Acceso a internet para consultar las APIs

## Instalación

### 1. Clonar o descargar el proyecto

```bash
cd /var/www/html
git clone https://github.com/JRobertoMA/releases-notification.git releases-notification
cd releases-notification
```

### 2. Instalar dependencias

```bash
composer install
```

### 3. Configurar el Bot de Telegram

1. Crear un bot en Telegram mediante [@BotFather](https://t.me/botfather)
2. Obtener el **Bot Token**
3. Obtener tu **Chat ID** (puedes usar [@userinfobot](https://t.me/userinfobot))

### 4. Configuración inicial

1. Acceder a la aplicación web: `http://tu-dominio/releases-notification/`
2. Se redirigirá automáticamente a la página de configuración
3. Ingresar el **Token del Bot** y el **ID del Chat**
4. Guardar la configuración

### 5. Configurar el script de verificación automática

```bash
crontab -e
```

```cron
# Verifica cada hora (todos los repositorios) — vía PHP CLI
0 * * * * /usr/bin/php /ruta/completa/releases-notification/check_releases.php >> /var/log/telegram-notifier.log 2>&1

# Verificar un repositorio concreto por su índice en repositories.json
0 * * * * /usr/bin/php /ruta/completa/releases-notification/check_releases.php --id=0 >> /var/log/telegram-notifier.log 2>&1

# Verifica cada 6 horas vía HTTP (útil cuando el cron corre en otra máquina, p.ej. Raspberry Pi)
# Requiere el CRON_SECRET configurado en settings.php
0 */6 * * * curl -s "http://tu-dominio.local:81/releases-notification/check_releases.php?token=TU_CRON_SECRET" > /dev/null 2>&1
```

## Uso

### Agregar un Repositorio

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

### Patrón de Tag para Docker Hub

El campo **Patrón de tag** controla qué variante de imagen se monitorea:

| Patrón | Semántica | Encuentra |
|---|---|---|
| `#.#-apache` | `#` = versión (dígitos y puntos) | `8.5-apache`, `8.5.3-apache`, `9.0-apache` |
| `#.#-fpm` | | `8.5-fpm`, `8.5.3-fpm`, `9.1-fpm` |
| `alpine` | subcadena | cualquier tag que contenga `alpine` |
| `latest` | subcadena + digest | rastrea cambios de contenido aunque el nombre no cambie |
| *(vacío)* | sin filtro | el tag más reciente (excluye `latest`) |

Al agregar o editar un repositorio Docker, el botón **Cargar variantes** descarga hasta 100 tags reales de Docker Hub para que elijas el patrón con un clic.

### Detección de Tags Flotantes (`latest`, `stable`)

Para imágenes que solo publican bajo `latest` o `stable`, el sistema compara el **digest SHA-256** en cada verificación. Si el contenido cambia (aunque el nombre del tag no), se envía notificación.

### Registrar tu Versión Instalada

En el formulario **Editar** de cada repositorio puedes indicar:
- **Versión instalada**: la versión que tienes corriendo actualmente.
- **Fecha de actualización**: cuándo la actualizaste por última vez.

El dashboard mostrará la versión en naranja con ⚠ cuando haya una versión más reciente disponible. Para tags flotantes, se compara la fecha del último cambio detectado con tu fecha de actualización.

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

**Límites sin autenticación:** GitHub 60 req/hora; Docker Hub ~100 req/6h por IP. Añade `GITHUB_TOKEN` en la configuración para elevar el límite de GitHub a 5 000 req/hora.

## Archivos de Configuración

### `config.json`

```json
{
    "TELEGRAM_BOT_TOKEN": "tu_bot_token",
    "TELEGRAM_CHAT_ID": "tu_chat_id",
    "GITHUB_TOKEN": "",
    "CRON_SECRET": "token_secreto_generado_automaticamente",
    "REPOSITORIES_FILE": "repositories.json"
}
```

`CRON_SECRET` se genera automáticamente al abrir `settings.php` por primera vez, o si el campo está vacío en un config existente. Desde esa misma página puedes:

- **↻ Regenerar** — crea un nuevo token sin recargar la página.
- **Copiar** — copia la URL completa lista para pegar en el crontab.

La URL generada tiene esta forma:

```
http://tu-servidor/releases-notification/check_releases.php?token=TU_CRON_SECRET
```

Úsala en un cron externo con curl:

```cron
0 */6 * * * curl -s "http://tu-servidor/releases-notification/check_releases.php?token=TU_CRON_SECRET" > /dev/null 2>&1
```

### `repositories.json`

Cada repositorio almacena:

```json
{
    "name": "owner/repo",
    "type": "github",
    "tags": ["gaming", "Nintendo Switch"],
    "tag_pattern": "",
    "last_seen_release": "v1.0.0",
    "last_seen_digest": null,
    "last_release_date": "2025-03-15T12:00:00Z",
    "last_checked": "2026-04-28 17:54:24",
    "check_status": "ok",
    "my_version": "v0.9.5",
    "my_updated_at": "2025-01-10"
}
```

## Permisos de Archivos (producción)

```bash
chmod 600 config.json
chmod 664 repositories.json app_log.json release_history.json
chown www-data:www-data config.json repositories.json app_log.json release_history.json
```

## Solución de Problemas

### No se reciben notificaciones
1. Verificar Token y Chat ID en `settings.php`
2. Comprobar el cron: `crontab -l`
3. Revisar logs en la pestaña **Logs** del dashboard
4. Probar manualmente: `php /ruta/check_releases.php`

### Un repositorio Docker siempre da error
- Usar el botón **Verificar** individual del repo para aislar el problema
- Comprobar el nombre exacto de la imagen en Docker Hub
- Para imágenes como `_/php` con muchas variantes, definir un **Patrón de tag** (ej: `#.#-apache`)
- Si el error persiste, puede ser rate-limiting de Docker Hub; esperar unos minutos y reintentar

### `config.json` no encontrado
Acceder a `settings.php` primero para crearlo.

### Error de permisos
```bash
chmod 664 repositories.json app_log.json release_history.json
chown www-data:www-data repositories.json app_log.json release_history.json
```
