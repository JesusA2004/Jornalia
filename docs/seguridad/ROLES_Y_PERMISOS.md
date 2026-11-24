# Roles, permisos, doble factor y separación de funciones

Fuente de verdad: `Web/app/Domain/Security/PermissionCatalog.php` (catálogo y roles por omisión) y `Web/app/Domain/Security/RoleStructure.php` (alineación idempotente). Revisión RH, pantalla 18 y regla general 11.

## Modelo

- Permisos `modulo.accion` con Spatie Permission; *teams* = `organization_id` (los roles son por organización).
- El alcance de datos es independiente del rol: `AccessScope` limita cada consulta a las sucursales asignadas (`branch_user`), a los reportes directos del supervisor y al propio expediente. Sólo `access_all_branches` (o el superadministrador) ve toda la organización. Los filtros de la interfaz son de conveniencia: el backend siempre filtra.
- Los roles se pueden ajustar en **Usuarios y roles › Roles** (matriz de permisos). Nunca se borran: se desactivan.
- `php artisan asistencias:roles [--organizacion=ID]` reaplica la estructura mínima sin perder asignaciones. Cada cambio queda en la bitácora con valor anterior y nuevo.

## Roles mínimos

| Rol | Para quién | Resumen |
|---|---|---|
| Administrador de plataforma | Sistemas | Usuarios, roles, organización, configuración técnica, integraciones, kioscos, revocar dispositivos y verificación de integridad. **Sin** permisos operativos: no autoriza, valida, rectifica, firma ni registra por otro. |
| Administrador RH | Recursos Humanos | Operación completa: valida y aplica rectificaciones (también extemporáneas), normativa, catálogo de incidencias, documentos, horarios, dispositivos. **No** administra usuarios ni roles. |
| Dirección | Dirección general | Consulta de su alcance, reportes y autorización de horas extra. Sin ubicación en tiempo real. |
| Responsable regional | Regionales | Consulta de su alcance (obligatorio por sucursal), ubicación, reportes, comunicados y autorización de horas extra. |
| Gerente de sucursal | Gerentes | Su personal (alcance obligatorio por sucursal): autoriza rectificaciones (paso 3), registra y autoriza incidencias, aprueba vacaciones, ubicación y comunicados. |
| Supervisor | Rol heredado (no está en la lista mínima de RH) | Sus reportes directos: autoriza rectificaciones, incidencias y vacaciones. |
| Contraloría | Contraloría interna | Sólo lectura: consulta, reportes, integridad y bitácora. |
| Autoridad laboral | Inspectores (STPS, centros de conciliación) | Sólo lectura con vigencia obligatoria (máx. 365 días) y revocación; vence sola con `asistencias:revision-usuarios`. |
| Colaborador | Personal | Sin permisos web; usa la app: marcar, solicitar, firmar y consultar su registro. |

Equivalencias aplicadas por la migración `2026_11_24_120000` (y `asistencias:roles`):

| Rol anterior | Rol vigente | Efecto |
|---|---|---|
| Administrador de empresa | Administrador de plataforma | Pierde todo permiso operativo. Quien hacía operación de RH debe recibir «Administrador RH» **en otro usuario**. |
| Recursos Humanos | Administrador RH | Recibe los permisos nuevos (validación, extemporáneas, normativa, catálogo). |
| Auditor de cumplimiento | Contraloría | Queda en sólo lectura. |

## Reglas duras

1. **`is_super_admin` no da autorización operativa.** Aprobar, validar, aplicar o firmar asistencia exige el permiso explícito y el alcance (`RectificationService`, `PermissionCatalog::OPERATIONAL`).
2. **Separación de funciones.** Un mismo usuario no puede tener `users.manage`/`users.roles` y a la vez un permiso operativo (`PermissionCatalog::segregationConflicts`). Se valida al asignar roles y al editar la matriz de permisos.
3. **Sin autoautorización.** Nadie autoriza ni valida su propio registro ni una solicitud que él mismo capturó.
4. **Sin escalamiento.** Quien administra usuarios sin `users.roles` no puede otorgar roles superiores.
5. **Alcance obligatorio.** Responsable regional y Gerente de sucursal no pueden tener «todas las sucursales».
6. **Ubicación en tiempo real** sólo para RH, Regional y Gerente (pantalla 04). `RoleStructure` retira `locations.view` de los demás roles.
7. **Roles de consulta.** `RoleStructure` deja a Contraloría y Autoridad laboral sólo con permisos `*.view`, `*.export` y `settings.legal`.

## Doble factor (TOTP)

- Fortify `twoFactorAuthentication` con confirmación (`config/fortify.php`). El usuario lo configura en **Ajustes › Seguridad** con una app autenticadora.
- Es obligatorio para quien tenga cualquier permiso de `PermissionCatalog::TWO_FACTOR_REQUIRED` (columna «2FA» de la tabla) y siempre para el superadministrador. Con los roles por omisión: Administrador de plataforma y Administrador RH.
- Middleware `RequireTwoFactor`: sin TOTP confirmado, el usuario sólo puede entrar a Seguridad. Una vez activo no puede desactivarlo.
- Interruptor de entorno `SECURITY_ADMIN_TWO_FACTOR` (por omisión `true`). Sólo debe apagarse en entornos sin usuarios reales.
- Pruebas: `Web/tests/Feature/Web/Admin/TwoFactorRequiredTest.php`.

## Ciclo de vida de accesos

| Evento | Qué pasa | Dónde |
|---|---|---|
| Baja con fecha de hoy | Se revocan ese mismo día app, dispositivos y sesiones. | `UserAccessService`, `WorkforceService` |
| Baja con fecha futura | `asistencias:revocar-bajas` (cada hora) revoca el día de la baja. | `RevokeTerminatedAccessCommand` |
| Acceso de autoridad laboral vencido | Se desactiva automáticamente. | `asistencias:revision-usuarios` (diario 02:10) |
| Revisión trimestral | Se abre una revisión por trimestre con hallazgos por usuario: inactividad mayor a `SECURITY_INACTIVE_AFTER_DAYS` (90 días por omisión), colaborador dado de baja, autoridad vencida, conflicto de separación de funciones, 2FA faltante y alcance por sucursal faltante. RH/Sistemas registran la decisión por usuario. | **Usuarios y roles › Revisión** |

Nada se borra: desactivar es baja lógica de la membresía y cierre de tokens.

## Matriz de permisos por rol (valores por omisión)

«Op.» = permiso operativo (nunca para Administrador de plataforma ni para el superadministrador). «2FA» = exige doble factor. «·» = sin permiso. El Colaborador no tiene permisos web.

| Permiso | Op. | 2FA | Plataforma | RH | Dirección | Regional | Gerente | Supervisor | Contraloría | Autoridad |
|---|---|---|---|---|---|---|---|---|---|---|
| `dashboard.view` |  |  | Sí | Sí | Sí | Sí | Sí | Sí | Sí | · |
| `punches.view` |  |  | · | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `punches.export` |  |  | · | Sí | · | · | · | · | Sí | Sí |
| `punches.record_web` | Sí |  | · | Sí | · | · | · | · | · | · |
| `locations.view` |  |  | · | Sí | · | Sí | Sí | · | · | · |
| `rectifications.view` |  |  | · | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `rectifications.create` | Sí |  | · | Sí | · | · | · | · | · | · |
| `rectifications.approve` | Sí |  | · | Sí | · | · | Sí | Sí | · | · |
| `rectifications.validate` | Sí | Sí | · | Sí | · | · | · | · | · | · |
| `rectifications.apply_late` | Sí | Sí | · | Sí | · | · | · | · | · | · |
| `incidences.view` |  |  | · | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `incidences.create` | Sí |  | · | Sí | · | · | Sí | · | · | · |
| `incidences.approve` | Sí |  | · | Sí | · | · | Sí | Sí | · | · |
| `incidences.validate` | Sí |  | · | Sí | · | · | · | · | · | · |
| `incidences.cancel` | Sí |  | · | Sí | · | · | · | · | · | · |
| `vacations.view` |  |  | · | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `vacations.create` | Sí |  | · | Sí | · | · | · | · | · | · |
| `vacations.approve` | Sí |  | · | Sí | · | · | Sí | Sí | · | · |
| `vacations.adjust` | Sí |  | · | Sí | · | · | · | · | · | · |
| `overtime.view` |  |  | · | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `overtime.approve` | Sí |  | · | Sí | Sí | Sí | · | · | · | · |
| `calendar.view` |  |  | · | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `employees.view` |  |  | Sí | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| `employees.create` |  |  | · | Sí | · | · | · | · | · | · |
| `employees.update` |  |  | · | Sí | · | · | · | · | · | · |
| `employees.terminate` |  | Sí | · | Sí | · | · | · | · | · | · |
| `employees.view_sensitive` |  |  | · | Sí | · | · | · | · | · | · |
| `employees.import` |  |  | · | Sí | · | · | · | · | · | · |
| `schedules.view` |  |  | · | Sí | Sí | Sí | Sí | · | Sí | Sí |
| `schedules.manage` |  |  | · | Sí | · | · | · | · | · | · |
| `schedules.assign` |  |  | · | Sí | · | · | · | · | · | · |
| `schedules.approve_changes` | Sí |  | · | Sí | · | · | · | · | · | · |
| `devices.view` |  |  | Sí | Sí | · | · | Sí | · | · | · |
| `devices.approve` | Sí |  | · | Sí | · | · | · | · | · | · |
| `devices.revoke` |  |  | Sí | Sí | · | · | · | · | · | · |
| `devices.kiosk` |  | Sí | Sí | Sí | · | · | · | · | · | · |
| `reports.view` |  |  | Sí | Sí | Sí | Sí | Sí | · | Sí | Sí |
| `reports.export` |  |  | · | Sí | Sí | Sí | Sí | · | Sí | Sí |
| `compliance.view` |  |  | Sí | Sí | Sí | Sí | · | · | Sí | Sí |
| `compliance.resolve` | Sí |  | · | Sí | · | · | · | · | · | · |
| `integrity.view` |  |  | Sí | Sí | · | · | · | · | Sí | Sí |
| `integrity.verify` |  |  | Sí | Sí | · | · | · | · | · | · |
| `communications.view` |  |  | Sí | Sí | Sí | Sí | Sí | · | · | · |
| `communications.send` |  |  | · | Sí | · | Sí | Sí | · | · | · |
| `audit.view` |  |  | Sí | Sí | · | · | · | · | Sí | Sí |
| `organization.view` |  |  | Sí | Sí | · | · | · | · | · | · |
| `organization.manage` |  | Sí | Sí | Sí | · | · | · | · | · | · |
| `users.view` |  |  | Sí | Sí | · | · | · | · | · | · |
| `users.manage` |  | Sí | Sí | · | · | · | · | · | · | · |
| `users.roles` |  | Sí | Sí | · | · | · | · | · | · | · |
| `settings.view` |  |  | Sí | Sí | · | · | · | · | · | · |
| `settings.manage` |  | Sí | Sí | Sí | · | · | · | · | · | · |
| `settings.legal` |  |  | Sí | Sí | · | · | · | · | Sí | · |
| `settings.legal_manage` | Sí | Sí | · | Sí | · | · | · | · | · | · |
| `settings.incidences` | Sí | Sí | · | Sí | · | · | · | · | · | · |
| `settings.documents` |  |  | · | Sí | · | · | · | · | · | · |
| `integrations.view` |  |  | Sí | Sí | · | · | · | · | · | · |
| `integrations.sync` |  | Sí | Sí | Sí | · | · | · | · | · | · |

La tabla se generó a partir de `PermissionCatalog::defaultRoles()`. Si la organización edita la matriz, los valores reales se consultan en **Usuarios y roles › Roles** o con `php artisan permission:show`.

## Pruebas

`Web/tests/Feature/Web/Admin/RolesAndAccessTest.php` (roles mínimos, migración de roles, superadministrador, separación de funciones, alcance obligatorio, escalamiento, IDOR, autoridad con vigencia, revisión trimestral, bitácora), `TwoFactorRequiredTest.php`, `TerminationRevokesAccessTest.php`, `UsersAndImportTest.php`, `Web/tests/Feature/Web/Operacion/AccessTest.php`.
