# Instalación y despliegue

Instalación nueva de producción y referencia de operación. Para actualizar la instalación existente desde `f26d36f` use [Actualización de producción](ACTUALIZACION_PRODUCCION.md). Integración continua: [CI](CI.md). Desarrollo local: [README raíz](../../README.md#instalación-local).

## Requisitos del servidor

- Ubuntu 24.04 LTS, Nginx, PHP 8.4-FPM (extensiones: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `openssl`, `sodium`, `curl`, `xml`), Composer 2.
- MariaDB 11.4 LTS (InnoDB; `character-set-server=utf8mb4`, `collation-server=utf8mb4_unicode_ci`).
- Node.js 22 o superior para compilar los assets (el build ejecuta `php artisan` por el plugin de Wayfinder).
- Supervisor (workers de cola) y cron (scheduler).
- Opcional: Python 3.12 para el microservicio facial (`services/face`).

## Base de datos

```sql
CREATE DATABASE jornalia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Usuario de migraciones (sólo durante despliegues; necesita TRIGGER para crear los disparadores)
CREATE USER 'jornalia_migrator'@'localhost' IDENTIFIED BY '<secreto>';
GRANT ALL PRIVILEGES ON jornalia.* TO 'jornalia_migrator'@'localhost';
-- Usuario de la aplicación: privilegios mínimos, sin TRIGGER/ALTER/DROP/SUPER
CREATE USER 'jornalia_app'@'localhost' IDENTIFIED BY '<secreto>';
GRANT SELECT, INSERT, UPDATE, DELETE ON jornalia.* TO 'jornalia_app'@'localhost';
```

Los triggers bloquean UPDATE/DELETE en tablas inmutables aunque el usuario tenga esos privilegios. Con registro binario activo, crear triggers requiere `log_bin_trust_function_creators=1` o `SUPER` para el usuario de migraciones. Detalle de tablas y triggers: [Base de datos](../arquitectura/BASE_DE_DATOS.md).

## Aplicación

```bash
sudo git clone <repositorio> /var/www/asistencias && cd /var/www/asistencias/Web
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
cp .env.example .env
#   APP_ENV=production, APP_DEBUG=false, APP_URL=https://asistencias.mr-lana.com
#   DB_* con jornalia_app; DEVICE_AUTO_APPROVE_FIRST=false
php artisan key:generate                      # SÓLO en una instalación nueva
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # dos veces: INTEGRITY_CHAIN_KEY e INTEGRITY_ANCHOR_KEY
DB_USERNAME=jornalia_migrator DB_PASSWORD='…' php artisan migrate --force
php artisan asistencias:instalar              # organización + primer usuario (Administrador de plataforma)
php artisan asistencias:datos-oficiales       # razón social, RFC, domicilio fiscal y nombre comercial
php artisan asistencias:festivos
php artisan storage:link
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
```

`APP_KEY`, `INTEGRITY_CHAIN_KEY` e `INTEGRITY_ANCHOR_KEY` se guardan en un gestor de secretos con respaldo cifrado fuera del servidor. Nunca se regeneran en una instalación con datos. Después de instalar, el administrador de plataforma configura su doble factor y crea el usuario de RH con «Administrador RH» (separación de funciones).

## Nginx (extracto)

```nginx
server {
    listen 443 ssl http2;
    server_name asistencias.mr-lana.com;
    root /var/www/asistencias/Web/public;
    ssl_certificate     /etc/letsencrypt/live/asistencias.mr-lana.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/asistencias.mr-lana.com/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    client_max_body_size 55M;    # evidencias: hasta 5 archivos de 10 MB
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}
server { listen 80; server_name asistencias.mr-lana.com; return 301 https://$host$request_uri; }
```

Ajuste `upload_max_filesize` y `post_max_size` de PHP-FPM al mismo límite.

## Colas (Supervisor)

`/etc/supervisor/conf.d/asistencias-worker.conf`:

```ini
[program:asistencias-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/asistencias/Web/artisan queue:work --sleep=2 --tries=5 --max-time=3600
numprocs=2
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/log/asistencias-worker.log
```

`QUEUE_CONNECTION=database` (o `redis`). Jobs: recálculo de jornada (`RecomputeWorkday`, idempotente y único por colaborador y fecha), reportes grandes (`GenerateReportExport`), comunicados y push. Los workers no procesan jobs en modo mantenimiento.

## Scheduler (cron)

```
* * * * * www-data cd /var/www/asistencias/Web && php artisan schedule:run >> /dev/null 2>&1
```

Tareas definidas en `Web/routes/console.php` (lista completa en [Arquitectura](../arquitectura/ARQUITECTURA.md#procesos-programados)). Ninguna crea marcajes. Verificar con `php artisan schedule:list`.

## Almacenamiento

| Ruta | Contenido | Respaldo |
|---|---|---|
| `storage/app/private/evidence/{org}/…` | Evidencias de rectificaciones e incidencias (con SHA-256) | Sí, diario |
| `storage/app/private/expedientes/{org}/convenios` | Convenios firmados en papel | Sí |
| `storage/app/private/policy-documents/{org}` | PDF del aviso de privacidad y del convenio (inmutables) | Sí |
| `storage/app/private/reports/{org}` | Exportaciones de reportes | Opcional (se regeneran) |
| `storage/app/public/brand` | Logotipos cargados (`php artisan storage:link`) | Sí |
| `storage/app/anchors` o `INTEGRITY_ANCHOR_PATH` | Anclas de integridad | Sí, fuera del servidor (ideal: WORM) |

## Microservicio facial (opcional)

`services/face` (FastAPI) escucha sólo en `127.0.0.1:8090` con token compartido (`FACE_SERVICE_URL`, `FACE_SERVICE_TOKEN`). Instalación, unidad systemd y calibración: [Biometría §8](../seguridad/BIOMETRIA.md#8-cómo-correr-el-servicio).

## Respaldos y recuperación

- Diario: `mariadb-dump --single-transaction --routines --triggers --events --hex-blob jornalia | gzip`, cifrado y fuera del servidor. Retención: la que defina Jurídico (referencia ≥ 5 años para asistencia).
- Respaldo independiente de `storage/app` (o `INTEGRITY_ANCHOR_PATH`), `.env` cifrado, credenciales de Firebase y claves de integridad.
- Restauración: restaurar en una base nueva → apuntar `DB_DATABASE` → `php artisan asistencias:integridad verificar` → comparar contra las anclas externas.
- Probar la restauración al menos cada trimestre.

## Actualización y rollback

Procedimiento completo, validaciones y recuperación: [Actualización de producción](ACTUALIZACION_PRODUCCION.md). Resumen:

1. `php artisan down` y detener workers. 2. Respaldo (BD con triggers, `storage/app`, `.env`). 3. `git checkout <commit>`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`. 4. `php artisan migrate --force` con el usuario de migraciones. 5. `php artisan optimize`, `php artisan queue:restart`, iniciar workers, `php artisan up`.

Nunca `migrate:fresh`, `migrate:refresh`, `db:wipe` ni `migrate:rollback` en producción. Una vez que los usuarios operan con la versión nueva, se corrige hacia adelante: los registros de asistencia son append-only.

## Salud y observabilidad

- `GET /up` (Laravel) y `GET /api/v1/health`.
- Logs en `storage/logs`; no se registran secretos ni identificadores oficiales. Vista en desarrollo: `php artisan pail`.
- Monitorear: jobs fallidos (`php artisan queue:failed`), alertas críticas de integridad (llegan a RH y Sistemas), tamaño de la cola, espacio en disco y vencimiento del certificado TLS.
