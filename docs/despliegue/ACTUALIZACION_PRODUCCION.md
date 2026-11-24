# Actualización de producción: `f26d36f` → versión «Revisión RH»

Guía para actualizar `asistencias.mr-lana.com` desde el commit `f26d36f` (versión en producción) a la versión que integra la Revisión RH (rama `cierre/revision-rh` integrada en `main`; en esta guía, `<COMMIT_NUEVO>`).

> **Reglas de esta actualización**
>
> - **Nunca** ejecute `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` ni `db:seed` con seeders de prueba en producción.
> - No regenere `APP_KEY` ni cambie `INTEGRITY_CHAIN_KEY` / `INTEGRITY_ANCHOR_KEY`: la cadena histórica dejaría de poder verificarse.
> - Ensaye primero en una copia restaurada del respaldo (§3.4). No actualice producción sin un ensayo exitoso.
> - Los comandos usan `APP_DIR=/var/www/jornalia` y el programa de Supervisor `asistencias-worker` de [Despliegue](DESPLIEGUE.md). Ajústelos a la instalación real antes de empezar.

## 1. Qué cambia

| Área | Cambio | Impacto operativo |
|---|---|---|
| Salida automática | Se elimina por completo (`asistencias:cierre-automatico` ya no existe). Los marcajes históricos `source = system` se conservan y ya no cuentan como marcaje del colaborador. | Las jornadas con salida automática histórica se recalculan como omisión de salida; aparecerán en «Omisiones de checada sin rectificar». |
| Rectificaciones | Flujo de 5 pasos con evidencia y firma del colaborador en la app. | Las pendientes vuelven a «Por autorizar». Se necesita la **nueva versión de la app** para firmar. |
| Roles | Roles mínimos con separación de funciones y doble factor. | «Administrador de empresa» pasa a «Administrador de plataforma» **sin permisos operativos**. Quien opere RH necesita «Administrador RH» en **otro usuario**. |
| Doble factor | TOTP obligatorio para roles administrativos. | Al entrar, Sistemas y RH deben configurarlo en Ajustes › Seguridad. |
| Normativa | «Verificada por RH» exige usuario y fecha reales. | Toda la normativa queda «Sin verificar» hasta que RH la valide. |
| Configuración | Datos fiscales, paleta institucional, correo de privacidad real, aviso de privacidad en PDF. | El color fuera de paleta pasa a #225C54; los correos de ejemplo se vacían. |
| Ubicación | Parámetro por puesto. | Todos los puestos quedan en «Solo ubicación al marcar»: el mapa en tiempo real queda vacío hasta que RH marque los puestos de campo con «Seguimiento durante la jornada». |
| Incidencias | Catálogo cerrado. | Los tipos fuera del catálogo se desactivan (sin borrar ni perder historial). |
| App móvil | Nuevos módulos nativos (`expo-document-picker`, `expo-image-picker`). | Requiere compilación nueva (APK/AAB); no basta una actualización OTA. |

Lista detallada de requerimientos: [Trazabilidad](../cierre/TRAZABILIDAD_REVISION_RH.md). Pendientes y aprobaciones: [Cierre técnico](../cierre/CIERRE_TECNICO.md).

## 2. Preparación (días antes)

1. **Ventana de mantenimiento** fuera del horario de marcaje. Durante la ventana la API responde 503 y la app guarda los marcajes sin conexión (si la política lo permite) para enviarlos después.
2. **Usuarios**: identifique quién tiene hoy «Administrador de empresa». Si esa persona también operaba RH, prepare un segundo usuario para RH (se le asigna «Administrador RH» después de migrar). Avise a Sistemas y RH que deberán configurar el doble factor con una app autenticadora.
3. **App**: compile y pruebe la nueva versión (ver README › APK/AAB). Distribúyala antes o al mismo tiempo que la actualización del servidor. Mientras la app nueva no esté instalada, las rectificaciones autorizadas no podrán firmarse y vencerán al terminar el plazo (3 días hábiles; RH puede ampliarlo temporalmente en Configuración › Incidencias, de 1 a 15).
4. **Documentos**: no publique el convenio de registro ni el aviso de privacidad hasta que RH y Jurídico los aprueben. Mientras no haya documentos publicados, la app no bloquea el marcaje.
5. **Ensayo** completo de esta guía en una copia (§3.4).

## 3. Validaciones y respaldo (día de la actualización)

### 3.1 Estado actual

```bash
APP_DIR=/var/www/jornalia
cd "$APP_DIR"
git rev-parse --short HEAD            # debe mostrar f26d36f
git status --short                    # sin cambios locales
php -v                                # PHP 8.4.x
php -m | grep -Ei 'pdo_mysql|mbstring|intl|gd|zip|bcmath|openssl|sodium'
mariadb --version                     # MariaDB 11.4
df -h "$APP_DIR" /var/backups

cd "$APP_DIR/Web"
php artisan migrate:status | tail -3  # última aplicada: 2026_11_18_300100_grant_communications_permissions
php artisan queue:failed
php artisan asistencias:integridad verificar   # debe terminar con código 0; si falla, DETÉNGASE
```

Si la verificación de integridad falla, no actualice: investigue primero (ver [Integridad del registro](../seguridad/INTEGRIDAD_REGISTRO.md)).

### 3.2 Línea base (para comparar después)

Ejecute con el cliente de MariaDB (usuario de sólo lectura o el de migraciones) y guarde la salida:

```sql
SELECT COUNT(*) AS marcajes, MAX(id) AS max_id FROM attendance_punches;
SELECT COUNT(*) AS ajustes FROM attendance_adjustments;
SELECT source, COUNT(*) FROM attendance_punches GROUP BY source;          -- 'system' = salidas automáticas históricas
SELECT status, COUNT(*) FROM rectification_requests GROUP BY status;
SELECT r.name, COUNT(m.model_id) AS usuarios
  FROM roles r LEFT JOIN model_has_roles m ON m.role_id = r.id
  GROUP BY r.organization_id, r.name;
SELECT COUNT(*) AS disparadores FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();
SELECT COUNT(*) AS jobs_pendientes FROM jobs;
SELECT DATEDIFF(CURDATE(), MIN(DATE(server_time))) AS dias_operacion FROM attendance_punches;
```

### 3.3 Mantenimiento y respaldo

```bash
cd "$APP_DIR/Web"
php artisan down --render="errors::503" --retry=60          # el scheduler no ejecuta tareas en mantenimiento
sudo supervisorctl stop 'asistencias-worker:*'
```

Espere a que `jobs_pendientes` sea 0 antes de detener los workers si es posible; los jobs pendientes son idempotentes y se pueden reprocesar.

```bash
TS=$(date +%Y%m%d-%H%M)
BK=/var/backups/asistencias/$TS-f26d36f
sudo install -d -m 700 "$BK"

# Base de datos con disparadores, rutinas y eventos (usuario de migraciones; la contraseña se pide al ejecutar)
mariadb-dump --single-transaction --routines --triggers --events --hex-blob \
  --default-character-set=utf8mb4 -u jornalia_migrator -p jornalia | gzip > "$BK/jornalia.sql.gz"
gunzip -t "$BK/jornalia.sql.gz"
zgrep -c 'CREATE.*TRIGGER' "$BK/jornalia.sql.gz"   # debe coincidir con «disparadores» de §3.2

# Archivos: evidencias, expedientes, documentos, reportes, logotipos y anclas
tar -czf "$BK/storage-app.tgz" -C "$APP_DIR/Web" storage/app
# Si INTEGRITY_ANCHOR_PATH apunta fuera de storage/app, respáldelo también:
grep '^INTEGRITY_ANCHOR_PATH=' "$APP_DIR/Web/.env"

# Configuración y secretos (cifrados; nunca en texto plano fuera del servidor)
sudo cp "$APP_DIR/Web/.env" "$BK/env" && sudo chmod 600 "$BK/env"
gpg --symmetric --cipher-algo AES256 -o "$BK/env.gpg" "$BK/env" && shred -u "$BK/env"

# Huella de las claves de integridad (compárela con la registrada en el gestor de secretos; no imprima las claves)
grep -E '^(APP_KEY|INTEGRITY_CHAIN_KEY|INTEGRITY_ANCHOR_KEY)=' "$APP_DIR/Web/.env" | sha256sum

git -C "$APP_DIR" rev-parse HEAD > "$BK/commit-anterior.txt"
```

Respalde también, si existen, la cuenta de servicio de Firebase (`FCM_CREDENCIALES_RUTA`) y `services/face/.env`. Copie el directorio `$BK` fuera del servidor (almacenamiento cifrado) antes de continuar. Si las claves de integridad no están en el gestor de secretos, guárdelas ahí ahora.

### 3.4 Ensayo en copia (obligatorio antes de producción)

En un servidor de ensayo (o en la misma instancia con otra base y otro directorio):

```bash
mariadb -u root -p -e "CREATE DATABASE jornalia_ensayo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$BK/jornalia.sql.gz" | mariadb -u root -p jornalia_ensayo
```

Con un checkout de `<COMMIT_NUEVO>` apuntando a `jornalia_ensayo` (y las mismas claves de integridad), ejecute las secciones 4 a 7 y valide §8. Registre la duración de las migraciones y del recálculo.

## 4. Código y dependencias

```bash
cd "$APP_DIR"
git fetch origin
git checkout <COMMIT_NUEVO>            # o: git pull --ff-only origin main
cd Web
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build                          # el plugin de Wayfinder ejecuta PHP (artisan) durante el build
php artisan optimize:clear
```

Variables de entorno: no hay variables obligatorias nuevas. Revise estas (todas tienen valor por omisión en `config/`):

| Variable | Valor recomendado | Nota |
|---|---|---|
| `DEVICE_AUTO_APPROVE_FIRST` | `false` | Todo teléfono nuevo queda pendiente hasta que RH lo autoriza (pantalla 11). |
| `SECURITY_ADMIN_TWO_FACTOR` | `true` (omisión) | Doble factor obligatorio para roles administrativos. |
| `SECURITY_INACTIVE_AFTER_DAYS` | `90` (omisión) | Revisión trimestral de accesos. |
| `REPORTS_MEAL_PAUSE` | `0` hasta que RH defina | Qué pausa del día es la comida en reportes. |
| `REPORTS_SYNC_MAX_ROWS`, `REPORTS_PDF_MAX_ROWS`, `REPORTS_PDF_MEMORY_LIMIT` | omisión | Reportes grandes van a la cola. |

## 5. Migraciones

```bash
cd "$APP_DIR/Web"
php artisan migrate:status | grep -i pending
DB_USERNAME=jornalia_migrator DB_PASSWORD='…' php artisan migrate --force
php artisan migrate:status | grep -ci pending      # debe ser 0
```

El usuario de migraciones necesita el privilegio `TRIGGER` (y, con registro binario activo, `log_bin_trust_function_creators=1` o `SUPER`), igual que en la instalación original. El usuario de la aplicación sigue sin esos privilegios.

Migraciones nuevas (en orden) y su efecto sobre los datos existentes:

| Migración | Qué hace | Datos existentes |
|---|---|---|
| `2026_11_24_100000_remove_auto_clock_out_configuration` | Retira `auto_clock_out_at` de políticas, puestos y asignaciones, y `auto_clock_out_at`/`after_cutoff_minutes` de `workday_summaries`. | Se pierden las horas máximas configuradas (quedan sólo en el respaldo). Los marcajes `system` no se tocan. |
| `2026_11_24_100100_add_sync_times_to_attendance_punches` | Agrega `received_at` y `synced_at`. | Registros previos quedan en `null` (se interpretan con `server_time`). No cambia el sello hash. |
| `2026_11_24_110000_create_electronic_signatures_table` | Tabla `electronic_signatures` (append-only con triggers) y columnas del NIP en `users`. | Sin datos previos. Cada colaborador define su NIP en la app. |
| `2026_11_24_110100_rectification_signature_workflow` | Columnas del flujo de 5 pasos y `attachments.category`. | `pending` → «Por autorizar» (debe recorrer todo el flujo, incluida la firma; sin evidencia no se puede autorizar: se rechaza y se pide de nuevo). `approved` → «Aplicada» con `legacy_without_signature` (sin firma retroactiva; se muestra «Previa al flujo»). `rejected` → «Rechazada». `cancelled` → «Rechazada» (cancelada). |
| `2026_11_24_110200_incidence_closed_catalog` | Evidencia, folio y ruta por tipo; cancelación con motivo; identificadores de People. Sincroniza el catálogo cerrado. | Crea los 10 tipos del catálogo y **desactiva** (no borra) los demás; las incidencias históricas se conservan. |
| `2026_11_24_120000_restructure_roles_with_segregation_of_duties` | Aplica `RoleStructure` a cada organización. | «Administrador de empresa» → «Administrador de plataforma» sin permisos operativos; «Recursos Humanos» → «Administrador RH»; «Auditor de cumplimiento» → «Contraloría» (sólo lectura). Crea los roles faltantes. Cada cambio queda en la bitácora. Sin reversa automática. |
| `2026_11_24_120100_add_two_factor_columns_to_users_table` | Columnas TOTP de Fortify (si faltan). | Nadie tiene 2FA: se les pedirá configurarlo. |
| `2026_11_24_120200_create_user_access_reviews_table` | Revisión trimestral de accesos. | Sin datos previos. |
| `2026_11_24_122000_add_people_identifiers_and_employer_registration` | Identificador externo en sucursales, centros, departamentos y puestos; registro patronal por sucursal. | Columnas nulas; no se inventan datos. |
| `2026_11_24_124000_create_kiosks_table` | Tabla `kiosks`, `attendance_punches.kiosk_id`, señales de integridad en `devices`. | Sin cambios de datos. |
| `2026_11_24_127000_registration_agreements_and_schedule_changes` | Cambios de horario programados con autorización. Quita el único (colaborador, fecha). | Las excepciones existentes quedan «approved» (ya surtían efecto). |
| `2026_11_24_130000_legal_versions_rh_verification` | `verified_by`/`verified_at` y tabla append-only `legal_version_revisions`. | **Toda la normativa pasa a «Sin verificar»** hasta que RH la valide. |
| `2026_11_24_130100_audit_logs_values_and_device` | `old_values`, `new_values`, `device_id` en la bitácora. | Registros previos sin esos valores. |
| `2026_11_24_130200_policy_documents_pdf_file` | Archivo PDF versionado de documentos. | Documentos previos sin PDF; el aviso de privacidad no podrá publicarse sin PDF. |
| `2026_11_24_130300_location_tracking_and_retention` | `positions.location_tracking` (`on_punch` por omisión) y trigger de `location_points` que sólo admite la depuración legal. | **Todos los puestos quedan en «Solo ubicación al marcar».** |
| `2026_11_24_130400_announcements_read_confirmation` | Confirmación de lectura de comunicados. | Comunicados previos sin confirmación requerida. |
| `2026_11_24_130500_institutional_brand_and_privacy_contact` | Color institucional y limpieza de correos de ejemplo. | Color fuera de la paleta → #225C54; correos `@empresa.mx`, `@example.*`, `@ejemplo.*` → vacío. Sin reversa. |
| `2026_11_24_140000_add_folio_to_report_exports` | Folio `REP-AAAA-NNNNNN`. | Las exportaciones previas reciben folio a partir de su id. |
| `2026_11_25_100000_add_rh_adjustments_to_holidays` | Ajustes de RH a festivos: `replaces_holiday_id`, `change_reason`, `notes`, `updated_by` e índice único. | Sólo columnas nulas; los festivos existentes no cambian. |

Si la versión final incluye migraciones posteriores de la fase 2 (bloques `2026_11_24_15xxxx` y `2026_11_24_16xxxx`), revíselas en [Base de datos](../arquitectura/BASE_DE_DATOS.md) y en `php artisan migrate:status` antes de ejecutar.

## 6. Comandos posteriores

Antes de reabrir (no requieren la cola):

```bash
cd "$APP_DIR/Web"
php artisan asistencias:roles                       # idempotente; confirma la estructura de roles
php artisan incidencias:catalogo                    # idempotente; confirma el catálogo cerrado
php artisan asistencias:datos-oficiales --simular   # muestra qué cambiaría (agregue --organizacion=ID si hay varias)
php artisan asistencias:datos-oficiales             # carga razón social, RFC, domicilio fiscal y nombre comercial
#   Si reporta conflictos con valores ya capturados, NO use --forzar sin aprobación de RH (el cambio queda en bitácora).
php artisan asistencias:festivos                    # descanso obligatorio art. 74, año actual y siguiente
php artisan asistencias:aviso-ubicacion             # publica el aviso UBICACION donde falte (idempotente)
php artisan storage:link                            # sólo si public/storage no existe
php artisan optimize                                # config, rutas, vistas y eventos en caché
php artisan schedule:list                           # no debe aparecer asistencias:cierre-automatico
```

Revise el crontab del servidor (`crontab -l -u www-data` y `/etc/cron.d/`): sólo debe existir `schedule:run`. Elimine cualquier línea que llame directamente a `asistencias:cierre-automatico`.

## 7. Reinicio y reapertura

```bash
php artisan queue:restart
sudo supervisorctl start 'asistencias-worker:*'
sudo supervisorctl status
sudo systemctl reload php8.4-fpm
php artisan up
```

Después de reabrir (usan la cola; los workers deben estar activos):

```bash
# Recalcula las proyecciones sin hora de corte. N = «dias_operacion» de §3.2 (+1).
php artisan asistencias:cerrar-dia --dias=N
php artisan cumplimiento:omisiones                  # alertas de omisiones sin rectificar
php artisan asistencias:integridad diaria           # ancla y verifica; alerta crítica si falla
php artisan asistencias:revision-usuarios           # abre la revisión trimestral de accesos
```

`asistencias:cerrar-dia` encola un recálculo por colaborador **activo** y día (jobs únicos e idempotentes). Con muchos días, vigile la cola (`SELECT COUNT(*) FROM jobs;`). Los colaboradores dados de baja conservan su proyección anterior.

Espere a que la cola termine antes de `cumplimiento:omisiones`. Ese comando revisa los últimos 60 días: las jornadas que antes cerraba la salida automática aparecerán como omisiones sin rectificar y generarán alertas. Es el comportamiento esperado; RH las resuelve con rectificaciones firmadas.

## 8. Comprobación

### 8.1 Integridad y datos

```bash
php artisan asistencias:integridad verificar        # código 0
php artisan migrate:status | grep -ci pending       # 0
curl -fsS https://asistencias.mr-lana.com/api/v1/health
curl -fsS -o /dev/null -w '%{http_code}\n' https://asistencias.mr-lana.com/up
```

```sql
SELECT COUNT(*), MAX(id) FROM attendance_punches;   -- igual a la línea base (append-only)
SELECT COUNT(*) FROM attendance_adjustments;        -- igual a la línea base
SELECT stage, COUNT(*) FROM rectification_requests GROUP BY stage;
SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE();  -- línea base + 4
SELECT code, verification_status, verified_by FROM legal_versions;                   -- todas sin verificar
```

Los cuatro disparadores nuevos son `electronic_signatures_no_update/no_delete` y `legal_version_revisions_no_update/no_delete`; `location_points_no_delete` se reemplaza.

### 8.2 Pruebas funcionales posteriores

| Quién | Qué validar |
|---|---|
| Sistemas | Inicia sesión, configura el doble factor, ve «Administrador de plataforma» sin botones de aprobar ni validar. Crea o ajusta el usuario de RH con «Administrador RH» y alcance. |
| RH | Configura 2FA. Panel: «Rectificaciones por firmar», «Tiempo extra registrado», «Marcajes sin conexión», «Por resolver» con firmas y omisiones. Asistencias: filtro «Sólo omisiones de checada», columna «Origen del marcaje», leyenda. Rectificaciones: cinco pestañas. Cumplimiento: tres pestañas. Configuración › Empresa (datos oficiales, correo de privacidad vacío), Normativa («Sin verificar»), Incidencias (plazo 3 días hábiles, cierre de nómina), Ubicación (puestos). |
| RH | Exporta un reporte en PDF, Excel y CSV: encabezado institucional con folio; la exportación aparece en la bitácora. |
| Gerente | Sólo ve su sucursal; puede autorizar una rectificación de su personal y no la propia. |
| Colaborador | Con la app nueva: define su NIP, marca entrada, recibe el acuse en la bandeja; solicita una corrección con evidencia y la firma cuando el jefe la autoriza. |

Si algo falla y los datos están íntegros, corrija hacia adelante (fix-forward). Si hay pérdida o corrupción de datos, vea §9.

## 9. Recuperación y rollback

**Antes de `php artisan up`** (nadie ha operado con la versión nueva) se puede volver a `f26d36f`:

1. `php artisan down` y detenga los workers.
2. Restaure la base en una base **nueva** (sin borrar la actual, para análisis):
   ```bash
   mariadb -u root -p -e "CREATE DATABASE jornalia_restaurada CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   gunzip -c "$BK/jornalia.sql.gz" | mariadb -u root -p jornalia_restaurada
   ```
   Otorgue a `jornalia_app` y `jornalia_migrator` los mismos privilegios sobre `jornalia_restaurada` y apunte `DB_DATABASE` a ella en `.env`.
3. Código anterior: `git checkout f26d36f`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan optimize`.
4. Archivos: `tar -xzf "$BK/storage-app.tgz" -C "$APP_DIR/Web"` (no borra archivos nuevos).
5. `php artisan asistencias:integridad verificar`, `php artisan queue:restart`, inicie los workers y `php artisan up`.

**Después de reabrir** ya existen marcajes, firmas y bitácora nuevos que son registros legales append-only. Restaurar el respaldo los perdería. En ese caso **no se hace rollback**: se corrige hacia adelante. Si fuera inevitable, primero exporte íntegros los registros con `id` mayor al de la línea base (`attendance_punches`, `attendance_adjustments`, `electronic_signatures`, `audit_logs`, `integrity_anchors`), documente el incidente y obtenga autorización de RH y Jurídico.

No use `php artisan migrate:rollback` en producción: `120000` y `130500` no tienen reversa, `110100` eliminaría el estado del flujo de firmas, `127000` falla si ya existen dos cambios para el mismo colaborador y fecha, y `100000` sólo recrea columnas vacías. Además, el código de `f26d36f` volvería a crear salidas automáticas.

## 10. Tareas de RH después de la actualización

> **Importante: documentos exigidos para marcar.** En cuanto RH publique el convenio de registro (`CONVENIO_REGISTRO`) o el aviso de privacidad (`AVISO_PRIVACIDAD`), el servidor rechazará los marcajes **en línea** de quien no los haya firmado o aceptado (código `documents_pending`). Los marcajes sin conexión se registran y generan una alerta. En el kiosco sólo bloquea el convenio, que puede adjuntarse en papel. Publique esos documentos únicamente cuando el personal ya pueda firmarlos en la app (versión con la pantalla «Documentos»), o adjunte el convenio en papel a quien use kiosco. Mientras no estén publicados no se exige nada y se muestra una alerta de configuración en Cumplimiento.

Ver [Cierre técnico › Aprobaciones](../cierre/CIERRE_TECNICO.md#4-aprobaciones-necesarias-de-rh-y-jurídico): validar la normativa 2027, capturar el correo de privacidad, publicar el aviso y el convenio, confirmar el plazo de firma y el cierre de nómina, elegir los puestos con seguimiento, confirmar la conservación de recorridos, capturar las claves NOI y definir la pausa de comida.
