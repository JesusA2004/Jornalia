# Instalación y despliegue

## Requisitos

- Ubuntu 24.04 LTS, Nginx, PHP 8.4-FPM (extensiones: pdo_mysql, mbstring, intl, gd, zip, bcmath, openssl, sodium), Composer 2.
- MariaDB 11.4 LTS (InnoDB; `character-set-server=utf8mb4`).
- Node.js 22+ (sólo para compilar assets).
- Supervisor (workers de cola) y cron (scheduler).

## Desarrollo local (Windows + WAMP)

> Causa del error `1071 Specified key was too long; max key length is 1000 bytes`: el servidor de WAMP
> tiene `default_storage_engine=MyISAM`. El proyecto fuerza `DB_ENGINE=InnoDB` en `config/database.php`;
> además usa MariaDB (puerto 3307 en WAMP).

```bash
cd Web
cp .env.example .env        # DB_CONNECTION=mariadb, DB_PORT=3307 en WAMP
composer install && npm install
php artisan key:generate
# Generar INTEGRITY_CHAIN_KEY e INTEGRITY_ANCHOR_KEY:
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php artisan migrate
php artisan asistencias:instalar          # organización + primer administrador (contraseña interactiva)
composer run dev                          # servidor, Vite y logs
php artisan serve --host=0.0.0.0 --port=8000   # accesible desde el teléfono (adb reverse tcp:8000 tcp:8000)
```

Pruebas: crear la BD `jornalia_test` y ejecutar `php artisan test` (usa MariaDB real para probar triggers).

## Producción

### Base de datos

```sql
CREATE DATABASE jornalia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Usuario de migraciones (sólo durante despliegues)
CREATE USER 'jornalia_migrator'@'localhost' IDENTIFIED BY '<secreto>';
GRANT ALL PRIVILEGES ON jornalia.* TO 'jornalia_migrator'@'localhost';
-- Usuario de la aplicación: privilegios mínimos, sin TRIGGER/ALTER/DROP/SUPER
CREATE USER 'jornalia_app'@'localhost' IDENTIFIED BY '<secreto>';
GRANT SELECT, INSERT, UPDATE, DELETE ON jornalia.* TO 'jornalia_app'@'localhost';
```

Los triggers bloquean UPDATE/DELETE en tablas inmutables aunque el usuario tenga esos privilegios.

### Aplicación

```bash
git clone … /var/www/asistencias && cd /var/www/asistencias/Web
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env   # APP_ENV=production, APP_DEBUG=false, claves y BD
php artisan key:generate
DB_USERNAME=jornalia_migrator DB_PASSWORD=… php artisan migrate --force
php artisan asistencias:instalar
php artisan config:cache route:cache view:cache event:cache
chown -R www-data:www-data storage bootstrap/cache
```

### Nginx (extracto)

```nginx
server {
    listen 443 ssl http2;
    server_name asistencias.ejemplo.mx;
    root /var/www/asistencias/Web/public;
    ssl_certificate     /etc/letsencrypt/live/asistencias.ejemplo.mx/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/asistencias.ejemplo.mx/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    client_max_body_size 12M;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.4-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}
```

### Colas (Supervisor)

```ini
[program:asistencias-worker]
command=php /var/www/asistencias/Web/artisan queue:work --sleep=2 --tries=5 --max-time=3600
numprocs=2
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
```

`QUEUE_CONNECTION=database` (o redis). Los jobs de recálculo son idempotentes y únicos por colaborador/fecha.

### Scheduler (cron)

```
* * * * * www-data cd /var/www/asistencias/Web && php artisan schedule:run >> /dev/null 2>&1
```

Tareas: cierre diario de jornadas (01:15), re-evaluación cada 4 h, anclaje de integridad cada hora,
verificación diaria (03:30), limpieza de desafíos y tokens vencidos.

### Respaldos y recuperación

- `mariadb-dump --single-transaction --routines --triggers jornalia | gzip` diario, cifrado y fuera del servidor; retención ≥ 5 años para tablas de asistencia (ver matriz legal).
- Respaldo independiente de `storage/app/anchors`, `storage/app/evidence` y de las claves de integridad.
- Restauración: restaurar BD → `php artisan asistencias:integridad verificar` → comparar contra anclas externas.

### Actualización y rollback

1. `php artisan down --render="errors::503"`  2. `git pull` / nueva release en directorio versionado
3. `composer install --no-dev` · `npm ci && npm run build` · `php artisan migrate --force`
4. `php artisan optimize` · `php artisan queue:restart` · `php artisan up`

Rollback: apuntar el symlink a la release anterior y `php artisan queue:restart`. Las migraciones de tablas
inmutables no se revierten en producción (no hay `down` destructivo sobre datos de asistencia).

### Salud y observabilidad

- `GET /up` (Laravel) y `GET /api/v1/health`.
- Logs estructurados en `storage/logs`; no se registran secretos ni identificadores oficiales.
- Monitorear: jobs fallidos (`php artisan queue:failed`), resultado de la verificación de integridad, colas atrasadas.
