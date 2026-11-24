# Auditoría de seguridad y del motor de asistencia (P2)

Corte: 24/11/2026, sobre `main` (630f322) con cambios en el árbol de trabajo. Se revisó el código real. Las pruebas son focalizadas y se corrieron en una BD aislada (`jornalia_test_p2`). La suite completa **no** se corrió.

Estados: **Corregido** (con prueba) · **Pendiente** (hallazgo real sin corregir) · **Sin hallazgo** (revisado, sin problema).

Pruebas nuevas:
- `Web/tests/Feature/Security/SecurityAuditTest.php`
- `Web/tests/Feature/Domain/AttendanceEngineEdgeCasesTest.php`

## 1. Hallazgos corregidos

| Id | Sev. | Hallazgo | Corrección | Prueba |
|---|---|---|---|---|
| H1 | Media | El superadministrador obtenía las habilidades de policy con `Gate::before`, porque `approve` no es nombre de permiso. Así podía autorizar dispositivos (`devices.approve`, operativo). | `AppServiceProvider`: el bypass se limita a permisos del catálogo no operativos. Las policies se evalúan normalmente. | `SecurityAuditTest` › H1 |
| M1 | Media | Con un rol asignado (p. ej. Administrador RH), el superadministrador obtenía permisos operativos: Spatie los concedía antes que la regla de la aplicación. | `User::checkPermissionTo` niega lo operativo a `is_super_admin`. `Gate::before` devuelve `false` en lo operativo. | M1 |
| M2 | Media | Desde el expediente («Cuenta») se otorgaba cualquier rol de la organización: sin control de escalamiento ni separación de funciones. También se reactivaba una membresía desactivada. | Sin `users.roles` sólo se otorga «Colaborador»; la Autoridad laboral nunca. Se valida la separación de funciones. Un acceso desactivado no se reactiva desde ahí. | M2 |
| H2 | Media | Quien capturaba vacaciones a nombre de otro podía aprobarlas (regla 3: sin autoautorización). | `ApprovalWorkflow::canDecide` rechaza a quien capturó (`requested_by`). | H2 |
| M3 | Media | En un flujo multinivel, la misma persona resolvía dos niveles seguidos (p. ej. jefe y RH). | `canDecide` rechaza a quien ya decidió algún nivel de la misma solicitud. | M3 |
| M4 | Media | Cambiar o restablecer la contraseña no cerraba los tokens de la app ni las sesiones en otros equipos. | `UserAccessService::closeSessionsAfterPasswordChange`: tokens Sanctum, filas de `sessions` (salvo la actual) y `remember_token`, con registro en bitácora. Se aplica en `SecurityController` y en `ResetUserPassword`. | M4 |
| H3 | Baja | La verificación facial se comprobaba fuera de la transacción y se consumía sin verificar el resultado. Dos marcajes concurrentes podían usar la misma verificación, y una verificación ajena quedaba como evidencia. | Se consume dentro de la transacción del marcaje: sólo la propia, coincidente y vigente. Si la política exige rostro y no se consumió, se revierte. | H3 (2 pruebas) |
| H4 | Baja | Doble toque al firmar el convenio o el aviso: dos firmas por carrera. | Firma con bloqueo del expediente y nueva comprobación. El NIP se verifica fuera de la transacción para que los intentos fallidos cuenten. | H4 (la prueba es secuencial: idempotencia y bloqueo de NIP; la carrera la cubre el bloqueo) |
| B6 | Baja | La exportación de marcajes (con latitud y longitud) sólo exigía `punches.view`. Dirección, sin `locations.view`, obtenía coordenadas. | Ruta con `can:punches.export`; botón «Exportar» condicionado a `can_export`. | B6 |
| B8 | Baja | Un usuario podía autorizar su propio dispositivo o un cambio de su propio horario. | `DeviceService::approve` y `ScheduleChangeService::approve` lo rechazan. Se ajustó `tests/Feature/Api/AuthTest.php`, que aprobaba con el propio usuario. | B8 |
| E1 | Media | `PunchRecorder` resolvía la relación laboral y la asignación con la fecha **UTC**: 1) la baja de hoy rechazaba la salida después de las 18:00 de Ciudad de México; 2) se aceptaban marcajes la víspera del ingreso; 3) **en línea, la hora del dispositivo elegía la asignación y la política** (un reloj atrasado evitaba, p. ej., la prohibición de marcar en web). | Fecha civil del centro de trabajo. En línea manda la hora del servidor; la del dispositivo sólo en capturas sin conexión no futuras. | Motor › baja, alta futura, «la hora del dispositivo no elige…» |
| E2 | Media | `EffectiveTimeline` buscaba marcajes por `server_time` ±4 días. Una captura sin conexión sincronizada con más de 4 días de retraso **no contaba en ningún día**. | También se incluyen las capturas sin conexión por su `device_time`. Quedan en revisión (`late_sync`). | Motor › sincronización tardía |
| E3 | Baja | El kiosco rechazaba a quien tenía una baja con fecha futura (estatus «Baja» anticipado) y usaba la fecha UTC para la sucursal. | Exige relación laboral vigente en la fecha civil y membresía activa (mismo criterio que la app). | Motor › kiosco |

## 2. Pendientes (hallazgos reales sin corregir)

| Id | Sev. | Hallazgo | Recomendación |
|---|---|---|---|
| A1 | Alta si hay varias organizaciones cliente | `legal_versions` es global (sin `organization_id`). El RH de cualquier organización (`settings.legal_manage`) edita o verifica la normativa que usan todas. Además, el historial lista las revisiones de todas las organizaciones con nombre y nota (`LegalVersionController` index). | Decisión del arquitecto: normativa por organización (con *overrides*) o edición sólo por un rol de plataforma con alcance global. Filtrar el historial por `organization_id`. |
| M5 | Media (condicionada) | Pre-vinculación: un usuario cambia su correo sin verificarlo ni confirmar contraseña. Después, «Usuarios» o «Cuenta» vinculan esa cuenta existente sin exigir `email_verified_at`, y el login de la API no exige correo verificado. La autorización del teléfono por RH frena el marcaje. | Vincular sólo cuentas con correo verificado o por invitación aceptada. Pedir la contraseña actual para cambiar el correo. |
| B1 | Baja | `PUT comunicados/ajustes` (sólo `communications.send`, que tienen Gerente y Regional) cambia los avisos de toda la organización. | Exigir `settings.manage` o acceso total. |
| B2 | Baja | Los destinos de alta, reingreso, movimiento, vinculación e importación (`branch_id`, supervisor) sólo se validan contra la organización, no contra el alcance del actor. `existsInOrg` no excluye registros desactivados. | Sin acceso total, limitarlos a las sucursales del actor y agregar `whereNull('deleted_at')`. |
| B3 | Baja | Con `users.manage` sin `users.roles` se puede dar `access_all_branches` o cualquier sucursal, también a uno mismo. | El alcance otorgado debe ser un subconjunto del propio, y no se debe poder editar el propio. |
| B4 | Baja | `DeviceService::approve` revoca los teléfonos del usuario en todas las organizaciones. Desactivar o dar de baja borra todos sus tokens. | Filtrar por `organization_id`. |
| B5 | Baja | Mensajes distintos para un correo existente al crear cuentas (enumeración). | Mensaje genérico. |
| B7 | Baja | Los niveles de `approval_flows` aceptan cualquier permiso del catálogo. El Administrador de plataforma (`settings.manage`) puede rediseñar flujos de operación. | `Rule::in` con los permisos operativos de cada tipo; edición sólo RH. |
| K1 | Baja | El kiosco distingue `pin_not_set` y `pin_locked` del genérico, lo que revela que el número de empleado existe. Rociar 5 NIP por número bloquea a muchos colaboradores (DoS). Hay límite por kiosco + número (10/min) y global (120/min por IP). | Aceptado por usabilidad; vigilar `kiosk.pin_failed` en la bitácora. |
| R1 | Observación | La misma persona con `rectifications.approve` y `rectifications.validate` (Administrador RH) puede autorizar (paso 3) y validar (paso 5) la misma rectificación, siempre que no sea suya ni la haya capturado. | **Pendiente de RH**: decidir si se exigen dos personas distintas. |
| D1 | Info | `npm audit --omit=dev` en Mobile: 22 moderadas (`minimatch` y `glob` vía `jest`, `jest-expo` y `@expo/fingerprint`). Son herramientas de prueba y compilación: no van en la app. `jest` y `jest-expo` están en `dependencies`. | No hay arreglo compatible (npm propone versiones mayores o *downgrade* de Expo). Mover `jest` y `jest-expo` a `devDependencies` (zona Móvil). |
| T1 | Info | Faltan pruebas de triggers para `attendance_adjustments`, `document_acknowledgements` e `integrity_anchors` (UPDATE/DELETE por Query Builder y SQL directo). El trigger existe en las migraciones. | Agregar una prueba de matriz (no alcanzó en este corte). |

## 3. Revisado sin hallazgo

| Área | Qué se verificó |
|---|---|
| Rutas web (≈150) y API | La tabla endpoint → control la generó una revisión independiente. Se resume aquí por módulo. Colaboradores, horarios y dispositivos: policy con `sameOrganization` + `AccessScope`. Operación: `can:` por ruta + `employeeOrFail` / `canAccessEmployee`. Administración: `can:` por módulo + `ensureOwned` / `ensureMember`. `withTrashed()` conserva el scope de organización. Notificaciones y cambio de organización: sólo lo propio y membresía activa. API: todo por `employee_id` del token (rectificaciones, incidencias, vacaciones, marcajes, documentos, comunicados con destinatario obligatorio). |
| Rectificaciones | Sin autoautorizar ni autovalidar (`notSelf`: colaborador y capturista). Sin aplicar sin firma con sello verificado. `lockForUpdate` + etapa esperada en cada transición: sin doble decisión ni doble firma. `open_key` único: sin solicitud duplicada. Vencimiento con bloqueo. |
| Firmas biométricas | HMAC ligado a propósito, id, `payload_sha256` y `device_uuid`, con dispositivo autorizado del propio usuario. Una repetición sólo aplica al mismo objeto, que ya cambió de etapa. No hay nonce ni caducidad propios: queda como mejora, no como vulnerabilidad. |
| Marcajes | Nonce de un solo uso (120 s) ligado al dispositivo e incluido en el HMAC. Idempotencia por (`employee_id`, `client_uuid`) con índice único. Secuencia validada. Doble entrada en línea rechazada. |
| NIP | Hash bcrypt; 5 fallos ⇒ 15 min con `lockForUpdate`, también en el kiosco. En las rectificaciones el intento fallido persiste (verificación fuera de la transacción). |
| Rate limiting | Login web: 5/min por correo+IP (Fortify) y 2FA 5/min. Login API: 10/min por IP + 5/min por correo+IP. Activación de kiosco: 10/min. Kiosco: 120/min + 10/min por kiosco y número. Firma: 20/min. Marcajes: 20/min. Facial: 20/min + bloqueo de dominio. |
| Tokens | El token de la app vence al final del día (zona de la organización) o con `MOBILE_TOKEN_TTL`. Un solo token móvil por usuario. Revocar un dispositivo borra sus tokens. La baja y la desactivación borran todos. Cambio de contraseña: corregido (M4). |
| 2FA | `two-factor.admin` cubre todo el grupo web, salvo `settings/*` (necesario para configurarlo). El superadministrador siempre lo requiere. No se desactiva una vez confirmado. |
| Archivos | Evidencias, convenios, documentos y reportes en disco `local` privado. Descarga con autorización y alcance (web) o URL firmada de 10 min (API, ligada al id del adjunto). `mimes` usa el MIME real (finfo) + lista blanca y tamaño. Ruta generada por `store()` (nombre aleatorio). Nombre original truncado y usado sólo en `Content-Disposition`. Logotipo: png/jpg/webp en disco público con nombre aleatorio. |
| Datos personales | Los recursos de la API no exponen CURP, RFC, NSS, biometría ni teléfonos de otros. `AuditLogger` redacta `password`, `secret`, `token`, `curp`, `rfc`, `nss`, `pin`, `signature`. Los `Log::` revisados no registran tokens, NIP ni datos sensibles. |
| Secretos | `git grep` sin claves reales. Sólo `.env.example` y contraseñas de *factory* o E2E (`password`). |
| Dependencias | `composer audit` (Web): 0. `npm audit --omit=dev` (Web): 0. Mobile: ver D1. |
| Inmutabilidad | Triggers `*_no_update`/`*_no_delete` en `attendance_punches`, `attendance_adjustments`, `integrity_anchors`, `electronic_signatures`, `document_acknowledgements`, `audit_logs`, `legal_version_revisions` y `location_points`. Trait `Immutable` en sus modelos. `NoPhysicalDeletes` global. Pruebas existentes: marcajes (Eloquent + QB + inserción falsificada detectada), firmas (triggers + firma fabricada y texto alterado detectados), `audit_logs` (QB) y `legal_version_revisions` (DELETE). Faltantes: T1. |
