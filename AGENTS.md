# AGENTS.md — MR LANA Asistencias

Convenciones del proyecto y coordinación entre agentes. Léelo completo antes de editar.

## Estructura

| Carpeta | Contenido | Notas |
|---|---|---|
| `Web/` | Laravel 13 + Inertia 3 + Vue 3 + TS + Tailwind 4 + shadcn-vue. Contiene también la API REST `/api/v1`. | Windows no distingue mayúsculas: `Web/` = `web/`. |
| `Mobile/` | Expo SDK 58 + Expo Router + React Native 0.88 + TS estricto. | Lee `Mobile/AGENTS.md` (docs versionadas de Expo). |
| `packages/contracts/` | Contrato API v1 en TypeScript (`src/api.ts`). | Fuente única de verdad web↔móvil. |
| `docs/` | Arquitectura, legal, API, seguridad, pruebas, despliegue. | En español. |

## Reglas no negociables

1. **Los marcajes (`attendance_punches`) son append-only.** Ningún modelo, controlador, API ni UI puede actualizarlos o borrarlos. El modelo `Punch` lanza `ImmutableRecordException` en `updating`/`deleting`; existen triggers de BD que bloquean UPDATE/DELETE. Correcciones = `rectifications` aprobadas que generan proyecciones, nunca sobrescritura.
2. **Toda la lógica laboral/legal vive en el backend** (`Web/app/Domain/**`). Vue y React Native sólo presentan.
3. **Aislamiento multiempresa en backend**: todo modelo con `organization_id` usa el trait `BelongsToOrganization` (scope global por organización activa). Nunca confiar en filtros del cliente.
4. Almacenamiento en **UTC**; conversión explícita a la zona del centro de trabajo para mostrar/calcular la fecha civil.
5. Duraciones en **minutos enteros**; importes en `decimal` / string. Prohibido `float` para dinero.
6. Sin datos ficticios en seeders de producción. Las factories sólo se usan en pruebas.
7. Sin credenciales en código. Secretos en `.env`.
8. Nada de rastro visual de "Laravel" en la UI (títulos, logos, favicons, páginas de bienvenida).
9. Textos de UI, documentación y commits en **español**. Nombres técnicos en inglés según convención del framework.
10. No declarar algo funcional sin haberlo probado. Registrar el estado real en `docs/PROGRESO_IMPLEMENTACION.md`.
11. **Nada se elimina físicamente.** Sin rutas DELETE ni botones "Eliminar": sólo baja lógica (SoftDeletes) con reactivación y auditoría. `App\Support\NoPhysicalDeletes` rechaza cualquier borrado físico de modelos de dominio.
12. **Poco texto.** Títulos cortos, descripciones de una línea. Las explicaciones van sólo en la ayuda "?" de cada módulo (web y app).
13. La app móvil es sólo asistencia + ubicación de trabajo en tiempo real (modelo de `C:\wamp64\www\auditoria\mobile\src\lib\recorrido-nativo.ts`), muy visual.
14. Identidad oficial: `docs/identidad/GUIA_MARCA_DIGITAL.md`. El logotipo nunca se modifica.
15. Existe el rol "Autoridad laboral" (sólo lectura) para inspectores.

## Backend (Web/app)

- `app/Domain/<Contexto>/` — servicios y acciones de dominio (`Attendance`, `Legal`, `Scheduling`, `Vacations`, `Incidences`, `Organization`, `Reports`, `Security`).
- `app/Models/` — Eloquent. Relaciones tipadas, `casts()`.
- `app/Http/Controllers/Web/` — controladores Inertia (delgados; delegan al dominio).
- `app/Http/Controllers/Api/V1/` — controladores API; respuestas vía `app/Http/Resources/V1/`.
- `app/Policies/` — autorización por módulo/acción con permisos Spatie (`teams` = `organization_id`).
- Permisos: `modulo.accion` (p. ej. `employees.view`, `punches.view`, `rectifications.approve`). Catálogo en `App\Domain\Security\PermissionCatalog`.
- Pruebas: Pest en `tests/Feature` y `tests/Unit`. Base de pruebas: MariaDB `jornalia_test` (ver `phpunit.xml`).

## Frontend web (Web/resources/js)

- Componentes shadcn-vue en `components/ui/*` (instalar con `npx shadcn-vue@latest add <comp>`). No usar `<select>`, `<input type=date>` ni gráficas/calendarios ajenos a shadcn-vue.
- Componentes propios de producto en `components/app/*`.
- Páginas Inertia en `pages/<modulo>/*.vue`. Tipos de props en `types/*.ts`.
- Tokens de diseño en `resources/css/app.css` (`:root` y `.dark`). Ver `docs/arquitectura/SISTEMA_DISENO.md`.
- Rutas tipadas con Wayfinder (`@/routes`, `@/actions`).

## Móvil (Mobile/)

- Rutas en `src/app/`; lógica en `src/{api,features,services,storage,hooks,components,theme,types}`.
- Tipos de API importados/copiados de `packages/contracts/src/api.ts` (no redefinir a mano).
- Cola offline persistente; idempotencia por `client_uuid`.

## Propiedad de archivos (coordinación)

Cada agente edita sólo su zona. Cambios fuera de su zona se coordinan con el arquitecto.

| Agente | Zona |
|---|---|
| Arquitecto | `packages/contracts/**`, `Web/database/migrations/**`, `Web/app/Models/**`, `Web/app/Domain/Attendance/**`, `Web/app/Domain/Legal/**`, `Web/app/Support/**`, `AGENTS.md` |
| Diseño web | `Web/resources/css/**`, `Web/resources/js/{components,layouts,composables,lib}/**`, `Web/resources/js/pages/auth/**`, `Web/resources/js/pages/settings/**`, `Web/resources/views/**`, `Web/public/**`, `Web/vite.config.ts` |
| Móvil | `Mobile/**` |
| Legal / docs | `docs/legal/**`, `docs/seguridad/**` |
| Backend API | `Web/app/Http/Controllers/Api/**`, `Web/app/Http/Resources/**`, `Web/routes/api.php`, `Web/tests/Feature/Api/**` |
| Web módulos | `Web/app/Http/Controllers/Web/**`, `Web/resources/js/pages/<modulos>/**`, `Web/routes/web.php` |
