# MR LANA Asistencias

Sistema integral de control de asistencias, jornadas y cumplimiento laboral mexicano para Corporativo MR LANA.

| Componente | Ruta | Tecnología |
|---|---|---|
| Web administrativa + API REST `/api/v1` | `Web/` | Laravel 13, PHP 8.4, Inertia 3, Vue 3, TypeScript, Tailwind 4, shadcn-vue |
| Aplicación móvil | `Mobile/` | Expo SDK 58, React Native 0.88, Expo Router, TypeScript |
| Contrato API compartido | `packages/contracts/` | Tipos TypeScript |
| Base de datos | — | MariaDB 11.4 (InnoDB) |
| Documentación | `docs/` | Arquitectura, legal, API, seguridad, pruebas, despliegue, identidad |

## Inicio rápido (desarrollo)

```bash
# Backend + web
cd Web
composer install && npm install
cp .env.example .env && php artisan key:generate   # configurar BD MariaDB y claves de integridad
php artisan migrate
php artisan asistencias:instalar                     # organización y primer administrador
composer run dev                                     # http://localhost:8000

# Móvil (dispositivo Android conectado por USB)
cd Mobile
npm install
adb reverse tcp:8000 tcp:8000 && adb reverse tcp:8081 tcp:8081
npx expo run:android
```

## Comandos

| Tarea | Comando |
|---|---|
| Pruebas backend | `cd Web && php artisan test` (BD `jornalia_test` en MariaDB) |
| Tipos y build web | `cd Web && npm run types:check && npm run build` |
| Lint web | `cd Web && npm run check` |
| Tipos/lint móvil | `cd Mobile && npx tsc --noEmit && npx expo lint` |
| Pruebas móvil | `cd Mobile && npm test` |
| APK de pruebas | `cd Mobile && npx eas-cli build -p android --profile preview` |
| AAB Play Store | `cd Mobile && npx eas-cli build -p android --profile production` |
| Verificar integridad | `cd Web && php artisan asistencias:integridad verificar` |
| Cierre diario | `cd Web && php artisan asistencias:cerrar-dia` |

## Documentos clave

- `AGENTS.md` — convenciones y coordinación.
- `docs/PROGRESO_IMPLEMENTACION.md` — estado real por módulo.
- `docs/arquitectura/ARQUITECTURA.md` — arquitectura y decisiones.
- `docs/legal/MATRIZ_CUMPLIMIENTO.md` — matriz legal con fuentes primarias (DOF, Cámara de Diputados).
- `docs/seguridad/INTEGRIDAD_REGISTRO.md`, `docs/seguridad/PRIVACIDAD_DATOS.md`.
- `docs/despliegue/DESPLIEGUE.md` — instalación, producción, colas, scheduler, respaldos, rollback.
- `docs/identidad/GUIA_MARCA_DIGITAL.md` — aplicación del manual de identidad MR LANA.

> El sistema implementa reglas laborales verificadas en fuentes oficiales, pero no constituye asesoría jurídica:
> los puntos marcados como "requiere criterio jurídico" en la matriz deben validarse con el área legal.
