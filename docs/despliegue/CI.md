# Integración continua (GitHub Actions)

Los workflows viven en `.github/workflows/` de la **raíz** del monorepo (GitHub ignora
`.github/` dentro de subcarpetas como `Web/`). Cada uno se ejecuta sólo cuando cambian
sus rutas, en `push` a `main`, en `pull_request` y bajo demanda (`workflow_dispatch`).

| Workflow | Rutas | Jobs | Valida |
|---|---|---|---|
| `backend.yml` | `Web/**`, `packages/**` | `analisis`, `pruebas` | `composer validate --strict`, `composer install` desde `composer.lock`, Pint, PHPStan (nivel de `phpstan.neon`), migraciones desde cero en MariaDB 11.4, motor InnoDB, triggers de inmutabilidad, suite Pest completa con reporte JUnit. |
| `web.yml` | `Web/**` | `calidad`, `e2e` | `npm ci`, Wayfinder, formato y lint (`vp check`), `vue-tsc --noEmit`, `npm run build`; E2E con Playwright contra la app servida con assets de producción. |
| `mobile.yml` | `Mobile/**`, `packages/**` | `calidad`, `android` | `npm ci`, `expo-doctor`, `expo config --type public`, `expo lint` sin advertencias, `tsc --noEmit` (incluye el contrato), contrato aislado en modo estricto, Jest; build Android debug. |
| `face.yml` | `services/**` | `pruebas` | Python 3.12, dependencias fijadas, `pip check`, descarga y sha256 de modelos oficiales, manifest de modelos, pytest. |

Reglas comunes:

- Acciones de terceros fijadas por SHA de commit (con la versión en comentario). Dependabot
  (`.github/dependabot.yml`) propone las actualizaciones semanalmente.
- `permissions: contents: read` y `persist-credentials: false` en el checkout.
- `concurrency`: una corrida nueva cancela la anterior de la misma rama o PR. En `main` no se
  cancela (se valida cada commit).
- Sin `continue-on-error`: cualquier verificación que falle marca el job en rojo. Los pasos
  que publican artefactos usan `if: !cancelled()` para subir reportes también cuando hay fallas.
- Runner fijo `ubuntu-24.04` para builds reproducibles.

## Base de datos en CI

Servicio `mariadb:11.4` (misma versión LTS que producción), contenedor efímero del runner:
`root` sin contraseña (`MARIADB_ALLOW_EMPTY_ROOT_PASSWORD`), expuesto en `127.0.0.1:3307`
como en WAMP, así coincide con `phpunit.xml`. No es una credencial: el contenedor sólo existe
durante el job.

Después de `php artisan migrate --force` el job `pruebas` verifica que:

1. Todas las tablas usen InnoDB (falla si alguna tiene otro motor).
2. Existan los triggers `attendance_punches_no_update` y `attendance_punches_no_delete`
   (marcajes append-only también en BD). El log lista todos los triggers creados.

La suite Pest vuelve a migrar con `RefreshDatabase` y ejecuta las pruebas de inmutabilidad
sobre los triggers reales (no SQLite).

Comando exacto de pruebas (la suite no cabe en 128 MB):

```bash
php -d memory_limit=2G vendor/pestphp/pest/bin/pest --compact --log-junit storage/logs/junit/pest.xml
```

El JUnit se publica como artefacto `backend-junit` (14 días).

## Web: calidad y E2E

### `calidad`

Necesita MariaDB porque Wayfinder lee el esquema para tipar los parámetros de ruta; sin BD
genera tipos más laxos (`string | number`) y `vue-tsc` dejaría de detectar errores reales.
Orden: `composer install` → `.env` de CI → `migrate` → `npm ci` →
`php artisan wayfinder:generate --with-form` → `npm run check` (`vp check`: formato oxfmt +
lint oxlint con tipos, advertencias = error) → `npx vue-tsc --noEmit` → `npm run build`.

### `e2e`

Usa el entorno E2E aislado de `Web/playwright.config.ts` (`APP_ENV=e2e`, BD `jornalia_e2e`,
puerto 8020):

1. Genera `Web/.env.e2e` a partir de `.env.example` con la BD del servicio, `APP_DEBUG=false`,
   cola `sync`, correo `log`, push y verificación facial deshabilitados, y claves
   `APP_KEY`, `INTEGRITY_CHAIN_KEY` e `INTEGRITY_ANCHOR_KEY` aleatorias en cada corrida.
2. `php artisan migrate --force` y `php artisan e2e:preparar` (siembra la demostración y
   escribe `tests/e2e/.auth/cuentas.json`). La contraseña y los secretos TOTP sembrados se
   enmascaran en los logs.
3. `npm run build` (assets de producción) y `php artisan serve --port=8020 --no-reload`
   con 4 workers; espera a que `/login` responda.
4. `npx playwright install --with-deps` y `npx playwright test --project=<navegador>-*`.

Por defecto corre sólo Chromium (todas las anchuras, flujos y accesibilidad). Para Firefox o
WebKit: *Actions → web → Run workflow* con `navegadores` = `chromium firefox webkit`.

Artefacto `e2e-reporte` (7 días): `tests/e2e/.resultados/` (JSON de resultados, capturas y
contexto de fallas) y `tests/e2e/.capturas/` (capturas y `hallazgos.jsonl`). **Se excluyen**
`tests/e2e/.auth/` (cuentas, sesiones, TOTP) y las trazas (`trace.zip`), que contienen
cookies y lo tecleado en el inicio de sesión. Los datos de las capturas son los de la
demostración E2E, nunca datos reales.

Si el job falla, imprime el final de la bitácora de `php artisan serve` y de
`storage/logs/laravel.log`.

## Móvil

### `calidad`

`npm ci` → `npx expo-doctor` → `npx expo config --type public --json` (evalúa
`app.config.ts` y comprueba `android.package`) → `npx expo lint --max-warnings 0` →
`npx tsc --noEmit` (el `tsconfig.json` incluye `../packages/contracts/src`) → compilación
aislada del contrato con `--strict --noUncheckedIndexedAccess --exactOptionalPropertyTypes`
(TypeScript 6 exige `--ignoreConfig` al pasar archivos por línea de comandos) →
`npx jest --ci --maxWorkers=2`.

### `android`

Sólo en `push` a `main` y bajo demanda (la compilación nativa completa tarda 20–30 min):
JDK 17 (Temurin), `npx expo prebuild --platform android --no-install --clean` (la carpeta
`android/` no se versiona) y `./gradlew assembleDebug -PreactNativeArchitectures=arm64-v8a`
(una ABI basta para validar la compilación y reduce el tiempo). El SDK de Android viene en la
imagen del runner; Gradle descarga los componentes faltantes que pida React Native
(`compileSdk` 37, build-tools 37.0.0, NDK 27.1.12297006). Sin `google-services.json` el build
no incluye Firebase. Los builds firmados (APK preview y AAB de Play Store) siguen en EAS
(`npx eas-cli build`), fuera de este workflow.

## Microservicio facial

Python 3.12 (la versión del entorno de desarrollo), caché de pip por `requirements*.txt`,
`pip install -r requirements-dev.txt` (versiones fijadas, sin torch) y `pip check`.
`python -I tools/download_models.py` descarga SFace (no versionado, 38 MB) y los `.pth` de
origen desde los repositorios oficiales y aborta si algún sha256 no coincide; luego
`git diff --exit-code -- models/manifest.json` exige que el manifest versionado sea idéntico
al regenerado. Las pruebas (`pytest`) verifican además el sha256 de cada ONNX y que un modelo
alterado impida arrancar. Los modelos se guardan en caché por el hash del script de descarga.

## Secretos y variables

Hoy **ningún workflow requiere secretos ni variables** de repositorio: las claves de
aplicación e integridad se generan en cada corrida y las BD son contenedores efímeros.

Si en el futuro se agregan builds de EAS desde CI, harían falta (configurarlos en
*Settings → Secrets and variables → Actions*, nunca en el repositorio): `EXPO_TOKEN`
(token de EAS) y, para incluir Firebase, el `google-services.json` como secreto de archivo
de EAS (`GOOGLE_SERVICES_JSON`, ver `app.config.ts`).

## Checks obligatorios en la rama protegida

Los workflows tienen filtro de rutas: un PR que no toca `Mobile/**` no ejecuta `mobile.yml`.
Si se marcan como *required status checks*, GitHub dejaría esos PR en espera. Marque como
obligatorios sólo los jobs que siempre aplican a los PR de cada área, o agregue un job
agregador sin filtro de rutas antes de exigirlos.

## Reproducir localmente

Con la BD propia `jornalia_test_ci` (MariaDB de WAMP en 3307, nunca la BD de desarrollo):

```bash
cd Web
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=2G
export DB_DATABASE=jornalia_test_ci DB_PORT=3307 DB_USERNAME=root DB_PASSWORD= DB_ENGINE=InnoDB
php artisan migrate --force
php -d memory_limit=2G vendor/pestphp/pest/bin/pest --compact --log-junit storage/logs/junit/pest.xml

npm ci && php artisan wayfinder:generate --with-form
npm run check && npx vue-tsc --noEmit && npm run build

cd ../Mobile
npm ci && npx expo-doctor && npx expo config --type public
npx expo lint --max-warnings 0 && npx tsc --noEmit && npx jest --ci --maxWorkers=2

cd ../services/face
python -m venv .venv && .venv/Scripts/python -m pip install -r requirements-dev.txt
.venv/Scripts/python -I tools/download_models.py && .venv/Scripts/python -m pytest -q
```

Validación de sintaxis de los workflows: `actionlint` (con `shellcheck` para los scripts).
