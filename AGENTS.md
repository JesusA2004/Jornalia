# AGENTS.md — MR LANA Asistencias

Convenciones del proyecto y coordinación entre agentes. Léelo completo antes de editar.

## Estructura

| Carpeta | Contenido | Notas |
|---|---|---|
| `Web/` | Laravel 13 + Inertia 3 + Vue 3 + TS + Tailwind 4 + shadcn-vue. Contiene también la API REST `/api/v1`. | Windows no distingue mayúsculas: `Web/` = `web/`. |
| `Mobile/` | Expo SDK 58 + Expo Router + React Native 0.88 + TS estricto. App del colaborador y modo kiosco. | Lee `Mobile/AGENTS.md` (docs versionadas de Expo). |
| `packages/contracts/` | Contrato API v1 en TypeScript (`src/api.ts`). | Fuente única de verdad web↔móvil. |
| `services/face/` | Microservicio de verificación facial (FastAPI, modelos open source). | Sólo escucha en loopback. |
| `docs/` | Arquitectura, legal, API, seguridad, despliegue, cierre, manual. Índice en `docs/README.md`. | En español. `docs/cambios/` es interno (está en `.gitignore`): no se publica ni se cita en el repositorio. |

## Reglas no negociables

1. **Los marcajes (`attendance_punches`) son append-only.** Ningún modelo, controlador, API ni UI puede actualizarlos o borrarlos. El modelo `Punch` lanza `ImmutableRecordException` en `updating`/`deleting`; existen triggers de BD que bloquean UPDATE/DELETE. Lo mismo aplica a `attendance_adjustments`, `electronic_signatures`, `document_acknowledgements`, `audit_logs`, `integrity_anchors` y `legal_version_revisions`.
2. **Sin marcajes automáticos.** Ningún proceso (scheduler, comando, job, listener, API ni UI) crea, completa ni descarta marcajes: ni salida por horario, ni cierre de jornada, ni corte. Un marcaje faltante queda como **omisión de checada** visible hasta rectificarse. Los recordatorios sólo avisan.
3. **Rectificación = evidencia + firma.** Toda corrección es una rectificación con evidencia obligatoria, autorización del jefe inmediato, **firma electrónica del colaborador** (NIP o biometría del teléfono), validación de RH y aplicación como `attendance_adjustment` vinculado. Sin firma válida no se aplica. Después del cierre de nómina sólo RH aplica (extemporánea).
4. **Separación de funciones.** `is_super_admin` y el rol «Administrador de plataforma» nunca obtienen autorización operativa (aprobar, validar, aplicar, rectificar, firmar). Nadie autoriza su propio registro ni lo que capturó. Administrar usuarios/roles y operar asistencia son incompatibles en un mismo usuario. Los permisos operativos se listan en `PermissionCatalog::OPERATIONAL`.
5. **Toda la lógica laboral/legal vive en el backend** (`Web/app/Domain/**`). Vue y React Native sólo presentan.
6. **La normativa es una tabla versionada** (`legal_versions`) editable sólo por RH (`settings.legal_manage`). Nunca se precarga ni se marca como «Verificada por RH» sin un usuario y una fecha reales. Todas las pantallas toman los parámetros legales de ahí.
7. **Aislamiento multiempresa en backend**: todo modelo con `organization_id` usa el trait `BelongsToOrganization` (scope global por organización activa) y toda consulta de personal pasa por `AccessScope`. Nunca confiar en filtros del cliente.
8. Almacenamiento en **UTC**; conversión explícita a la zona del centro de trabajo para mostrar/calcular la fecha civil. Hora oficial = recepción en servidor; se guardan también la hora del dispositivo y la de sincronización.
9. Duraciones en **minutos enteros**; importes en `decimal` / string. Prohibido `float` para dinero.
10. Sin datos ficticios en seeders de producción. Las factories sólo se usan en pruebas. Datos oficiales permitidos: los de `OfficialCompanyData` (razón social, RFC, domicilio fiscal, nombre comercial). No inventar sucursales, registros patronales, coordenadas, correos ni empleados.
11. Sin credenciales en código. Secretos en `.env` o en el gestor de secretos. Nunca regenerar `APP_KEY` ni cambiar `INTEGRITY_CHAIN_KEY`/`INTEGRITY_ANCHOR_KEY` en una instalación con datos.
12. Nada de rastro visual de "Laravel" en la UI (títulos, logos, favicons, páginas de bienvenida).
13. Textos de UI, documentación y commits en **español de México**. Nombres técnicos en inglés según convención del framework.
14. No declarar algo funcional sin haberlo probado. Registrar el estado real en `docs/PROGRESO_IMPLEMENTACION.md` y, para requerimientos de RH, en `docs/cierre/TRAZABILIDAD_REVISION_RH.md`.
15. **Nada se elimina físicamente.** Sin rutas DELETE ni botones "Eliminar": sólo baja lógica (SoftDeletes), cancelación con motivo o desactivación, con reactivación y auditoría. `App\Support\NoPhysicalDeletes` rechaza cualquier borrado físico de modelos de dominio. Excepciones legales documentadas y únicas: la depuración programada de `location_points` (`ubicacion:depurar`, LFPDPPP) y el vaciado de la plantilla facial por cancelación ARCO.
16. **Poco texto.** Títulos cortos, descripciones de una línea. Las explicaciones van sólo en la ayuda "?" de cada módulo (web y app).
17. La app móvil es asistencia + ubicación de trabajo + firmas y solicitudes del propio colaborador, muy visual, más el modo kiosco de sucursal. La ubicación sigue la regla del puesto: sólo al marcar o durante la jornada (inicia con la entrada, se pausa en descanso/comida, termina con la salida). Modelo de seguimiento: `C:\wamp64\www\auditoria\mobile\src\lib\recorrido-nativo.ts`.
18. Identidad: paleta institucional #225C54 / #AF8B51 (Revisión RH) y `docs/identidad/GUIA_MARCA_DIGITAL.md`. El logotipo nunca se modifica.
19. Existe el rol "Autoridad laboral" (sólo lectura con vigencia) para inspectores.
20. **El contrato API es aditivo.** No se quitan ni renombran campos de `packages/contracts/src/api.ts` usados por apps instaladas; se deprecan (p. ej. `auto_clock_out_at` siempre `null`).

## Backend (Web/app)

- `app/Domain/<Contexto>/` — servicios y acciones de dominio: `Attendance` (marcajes, omisiones, integridad, rectificaciones), `Signatures` (firma electrónica, NIP, convenio y aviso), `Rectifications` (plazo de firma y cierre de nómina), `Legal`, `Compliance`, `Scheduling`, `Workforce`, `Incidences`, `Vacations`, `Approvals`, `Location`, `Notifications`, `Reports` (catálogo, dataset, render, NOI), `Organization`, `Integrations/People`, `Biometrics`, `Security`, `Audit`, `Dashboard`.
- `app/Models/` — Eloquent. Relaciones tipadas, `casts()`.
- `app/Http/Controllers/Web/` — controladores Inertia (delgados; delegan al dominio).
- `app/Http/Controllers/Api/V1/` — controladores API; respuestas vía `app/Http/Resources/V1/`.
- `app/Policies/` — autorización por módulo/acción con permisos Spatie (`teams` = `organization_id`).
- Permisos: `modulo.accion` (p. ej. `punches.view`, `rectifications.approve`, `rectifications.validate`). Catálogo y roles mínimos en `App\Domain\Security\PermissionCatalog`; alineación con `php artisan asistencias:roles`. Tabla por rol: `docs/seguridad/ROLES_Y_PERMISOS.md`.
- Tareas programadas en `routes/console.php`. Ninguna crea marcajes.
- Pruebas: Pest en `tests/Feature` y `tests/Unit`. Base de pruebas MariaDB `jornalia_test` en `127.0.0.1:3307` (ver `phpunit.xml`), o una por agente con `DB_DATABASE=jornalia_test_<agente>`. Suite completa: `php -d memory_limit=2G vendor/pestphp/pest/bin/pest --compact`. Nunca usar la base de desarrollo `jornalia` para pruebas ni ejecutar `migrate:fresh`/`db:wipe` sobre ella.
- Migraciones: nunca editar migraciones ya aplicadas en producción; siempre compatibles con datos existentes y con `down()` funcional cuando sea posible.

## Frontend web (Web/resources/js)

- Componentes shadcn-vue en `components/ui/*` (instalar con `npx shadcn-vue@latest add <comp>`). No usar `<select>`, `<input type=date>` ni gráficas/calendarios ajenos a shadcn-vue.
- Componentes propios de producto en `components/app/*`.
- Páginas Inertia en `pages/<modulo>/*.vue`. Tipos de props en `types/*.ts`.
- Tokens de diseño en `resources/css/app.css` (`:root` y `.dark`). Ver `docs/arquitectura/SISTEMA_DISENO.md`. Leyenda única de estados: `lib/attendance-status.ts`.
- Rutas tipadas con Wayfinder (`@/routes`, `@/actions`).
- Verificación: `npm run types:check`, `npm run check`, `npm run build`; E2E con Playwright (`npm run test:e2e`).

## Móvil (Mobile/)

- Rutas en `src/app/`; lógica en `src/{api,features,services,storage,hooks,components,theme,types}`.
- Tipos de API importados de `packages/contracts/src/api.ts` (no redefinir a mano).
- Cola offline persistente y cifrada; idempotencia por `client_uuid`. Nunca mostrar «confirmado» sin respuesta del servidor.
- Verificación: `npm test`, `npm run typecheck`, `npm run lint`, `npm run doctor`.

## Propiedad de archivos (coordinación)

Cada agente edita sólo su zona. Cambios fuera de su zona se coordinan con el arquitecto. En archivos compartidos (`routes/*.php`, `PermissionCatalog.php`, `packages/contracts/src/api.ts`, `Employee.php`, `User.php`, navegación, `routes/console.php`, `bootstrap/app.php`) sólo ediciones pequeñas, releyendo el archivo justo antes.

| Agente | Zona |
|---|---|
| Arquitecto | `packages/contracts/**`, `Web/database/migrations/**`, `Web/app/Models/**`, `Web/app/Domain/{Attendance,Legal,Signatures,Rectifications}/**`, `Web/app/Support/**`, `AGENTS.md` |
| Diseño web | `Web/resources/css/**`, `Web/resources/js/{components,layouts,composables,lib}/**`, `Web/resources/js/pages/auth/**`, `Web/resources/js/pages/settings/**`, `Web/resources/views/**`, `Web/public/**`, `Web/vite.config.ts` |
| Móvil | `Mobile/**` |
| Backend API | `Web/app/Http/Controllers/Api/**`, `Web/app/Http/Resources/**`, `Web/routes/api.php`, `Web/tests/Feature/Api/**` |
| Web módulos | `Web/app/Http/Controllers/Web/**`, `Web/resources/js/pages/<modulos>/**`, `Web/routes/web.php`, `Web/routes/web/**` |
| Reportes | `Web/app/Domain/Reports/**`, `Web/config/reports.php`, `Web/resources/views/reports/**`, `Web/tests/Feature/Reports/**` |
| Integración continua | `.github/**`, `docs/despliegue/CI.md` |
| Documentación / legal | `README.md`, `docs/**` (excepto `docs/cambios/**`, `docs/despliegue/CI.md` y los documentos asignados a otro agente) |
