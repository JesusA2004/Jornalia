# Cierre técnico — Revisión funcional de RH

| Dato | Valor |
|---|---|
| Sistema | MR LANA Asistencias (`asistencias.mr-lana.com` y app de colaboradores) |
| Empresa | PRODUCTOS Y SERVICIOS MR LANA, S.A.P.I. de C.V. · RFC PSM2311289J2 |
| Requerimientos | «Revisión funcional del sistema de reloj checador», Gerencia de Recursos Humanos, 23-nov-2026 (documento interno) |
| Versión base | `f26d36f` (producción) |
| Rama | `cierre/revision-rh` |
| Trazabilidad | [TRAZABILIDAD_REVISION_RH.md](TRAZABILIDAD_REVISION_RH.md) |
| Actualización de producción | [ACTUALIZACION_PRODUCCION.md](../despliegue/ACTUALIZACION_PRODUCCION.md) |

## 1. Requerimientos

La revisión de RH dictaminó 19 pantallas (9 se modifican, 3 se fusionan en «Cumplimiento» y 7 se conservan, 4 de ellas con ajustes), 12 reglas generales y 6 decisiones pendientes. Sus principios para el sistema:

- **Inalterable**: el marcaje original no se edita ni se borra; toda corrección es un ajuste vinculado, con evidencia y firma.
- **Acordado**: el colaborador acepta el medio de registro (convenio) y firma cada corrección, para que el registro haga prueba plena (LFT art. 132 fr. XXXIV).
- **Completo**: no se generan ni se descartan marcajes automáticamente; las omisiones quedan visibles hasta resolverse.
- **Disponible**: el reporte para la autoridad se genera en cualquier momento, con datos verificables de integridad.

### Resultado de la revisión de código (fase 1)

Conteo de la [matriz de trazabilidad](TRAZABILIDAD_REVISION_RH.md), verificado contra el código. No sustituye los resultados de ejecución de las pruebas (§6).

| Grupo | Total | Implementado | Parcial | Configurable · pendiente RH/Jurídico | Requiere infraestructura externa |
|---|---|---|---|---|---|
| Instrucciones de las 19 pantallas | 87 | 76 | 3 | 5 | 3 |
| Reglas generales | 12 | 9 | — | 2 | 1 |
| Decisiones pendientes de RH | 6 | — | — | 6 | — |

Parciales: 01.3 (etiqueta «Excedente» en el resumen personal del Panel), 08.1 (los festivos oficiales no se editan desde la web) y 08.3 (leyenda común no aplicada en Incidencias). Detalle y pruebas faltantes en la matriz, sección «Requerimientos no implementados o parciales».

## 2. Cambios realizados (por commit)

| Commit | Resumen | Principales piezas |
|---|---|---|
| `d83f48a` | Elimina la generación automática de marcajes y centraliza el cálculo de jornada. | Se borran `AutoClockOut`, `AutoClockOutAdvisor`, `asistencias:cierre-automatico`, listener y notificación. Nuevos `Omissions`, `PunchOrigin`, `ClockSkewMonitor`, `LegalVersionManager`, `WeeklyOvertimeCalculator`, `asistencias:festivos`. Migraciones `…100000` y `…100100`. Panel, Asistencias, Horas extra y Calendario conforme a RH. |
| `ddaa024` | Firma electrónica y flujo firmado de rectificaciones; incidencias con catálogo cerrado. | `ElectronicSignatureService`, `SignaturePinService`, `RegistrationDocuments`, `RectificationService` (5 pasos), `RectificationSettings`, `rectificaciones:vencer`, `IncidenceCatalog`, recepción de incidencias de People. Migraciones `…110000`–`…110200`. |
| `23287d7` | Roles con separación de funciones, colaboradores, horarios y kiosco. | `RoleStructure`, `TwoFactorPolicy`, `RequireTwoFactor`, `UserAccessService`, `RegistrationStatus`, `EmployeeImportValidator`, `ScheduleChangeService`, `KioskService`, `DeviceIntegrityMonitor`, `OfficialCompanyData`, sincronización de estructura de People; comandos `asistencias:roles`, `asistencias:datos-oficiales`, `asistencias:revocar-bajas`, `asistencias:revision-usuarios`. Migraciones `…120000`–`…127000`. |
| `74cc66a` | Reportes institucionales y exportación para NOI. | Dataset único (`ReportDataset`), 27 reportes, PDF/XLSX/CSV con encabezado institucional y folio, protección contra inyección de fórmulas, bitácora, cola para volúmenes grandes, `NoiExport`. Migración `…140000`. |
| `6fff0ab` | Cumplimiento, normativa, configuración, avisos y ubicación. | Pestañas de Cumplimiento, `IntegrityMonitor` (verificación y anclaje diarios con alerta crítica), `OmissionMonitor`, bitácora con valor anterior y nuevo, normativa sólo RH con historial, datos fiscales y paleta, aviso de privacidad en PDF, acuse obligatorio de marcaje, confirmación de lectura, `LocationTracking` por puesto y `ubicacion:depurar`. Migraciones `…130000`–`…130500`. |
| `08faa74` | Integración de rutas, modelos, middleware y contrato API. | `routes/web*.php`, `routes/api.php`, `routes/console.php`, `packages/contracts/src/api.ts` (aditivo; campos de salida automática deprecados), pruebas existentes alineadas. |
| `b954855` | App móvil: firmas, convenio, seguimiento por jornada, kiosco y rediseño. | Firma con NIP o biometría, convenio y aviso bloqueantes, ciclo de seguimiento por jornada, acuses en bandeja, confirmación de lectura, modo kiosco con cola offline, identidad #225C54. |

La fase 2 (integración, calidad, Playwright, Android, CI y documentación) se registra en `docs/PROGRESO_IMPLEMENTACION.md`.

## 3. Pendientes externos

| Pendiente | Qué falta | Responsable sugerido | Efecto mientras tanto |
|---|---|---|---|
| Firebase (Android) | Registrar `mx.mrlana.asistencias` (y variantes) en Firebase, `google-services.json` en la compilación y cuenta de servicio en el servidor (`FCM_*`). Ver [Push](../notificaciones/PUSH.md). | Sistemas | «Push sin configurar»: los avisos y acuses llegan sólo a la bandeja de la app. |
| Push en iOS | Apple Developer, llave APNs en Firebase, `GoogleService-Info.plist` y obtener token FCM en iOS (o envío APNs directo). | Sistemas | Sin push en iOS. |
| Anclaje externo WORM | Bucket S3 (o compatible) con Object Lock en modo *compliance*; disco en `config/filesystems.php`; `INTEGRITY_ANCHOR_DISK`. | Sistemas | Las anclas se guardan en disco local («WORM pendiente»): no se puede declarar anclaje externo. |
| Mr. Lana People | URL, token de servicio de sólo lectura y confirmación del contrato (`/departments`, `/positions`, `/branches`, `/employees`, `/terminations`, `/incidences`). Ver [People](../integraciones/PEOPLE.md). | Sistemas + equipo de People | «Sin configurar»: altas, bajas e incidencias se capturan en Asistencias. |
| Tiendas de aplicaciones | Cuenta de Expo/EAS, Google Play Console (cuenta de servicio para `eas submit`) y Apple Developer / App Store Connect. | Sistemas | La app se distribuye como APK interno. |
| Plantilla de reportes | Formato comercial definitivo que entregará la empresa. Se integra como `ReportLayout` sin tocar datos. Ver [Reportes](../reportes/REPORTES.md). | RH / Dirección | Los reportes usan el diseño institucional. |
| Servidor de producción | Supervisor (workers), cron del scheduler, TLS, respaldos externos cifrados. | Sistemas | Sin workers no se generan reportes grandes ni se recalculan jornadas; sin cron no hay rutinas diarias. |
| Microservicio facial | Despliegue en el servidor (systemd), token compartido y calibración con teléfonos reales. | Sistemas | `available = false`: la política no puede exigir rostro. |
| Correo saliente | `MAIL_*` reales (hoy `MAIL_MAILER=log`). | Sistemas | No llegan correos de restablecimiento de contraseña. |

## 4. Aprobaciones necesarias de RH y Jurídico

| Decisión | Valor actual en el sistema | Dónde se cambia | Quién |
|---|---|---|---|
| Plazo de firma de rectificaciones y tratamiento del día sin firma | 3 días hábiles (sin fines de semana ni descansos obligatorios), sin confirmar; vencido ⇒ rechazada y el día queda como omisión | Configuración › Incidencias | RH |
| Cierre de nómina (fecha hasta la que una rectificación es extemporánea) | Vacío | Configuración › Incidencias | RH |
| Correo del responsable de datos personales | Vacío (los de ejemplo se eliminaron) | Configuración › Empresa | RH + Jurídico |
| Aviso de privacidad para colaboradores (PDF) | Sin publicar | Configuración › Documentos (`AVISO_PRIVACIDAD`) | RH + Jurídico |
| Convenio o adendum de registro electrónico (uso del celular personal y de la ubicación) | Sin publicar | Configuración › Documentos (`CONVENIO_REGISTRO`) | RH + Jurídico |
| Conservación de recorridos de ubicación | 90 días, sin confirmar | Configuración › Ubicación | Jurídico |
| Conservación del registro de asistencia | Sin purga; referencia de 5 años (mínimo legal: 1 año después de la baja) | Decisión documental | Jurídico |
| Normativa 2027 y disposiciones de la STPS | Todas las versiones «Sin verificar» | Configuración › Normativa | RH con Jurídico |
| Claves de concepto de NOI | Marcadores (`FALTA`, `HORAS_EXTRA_DOBLES`, …) | `config/reports.php` o ajuste `reports.noi` (sin pantalla) | RH + Nómina |
| Pausa de comida en reportes | `0`: todas las pausas se reportan como descanso | `REPORTS_MEAL_PAUSE` en `Web/.env` (global; sin pantalla ni ajuste por organización) | RH (lo aplica Sistemas) |
| Puestos con seguimiento durante la jornada | Ninguno («Solo ubicación al marcar») | Configuración › Ubicación | RH |
| Nombre definitivo del sistema | «MR LANA Asistencias» | `APP_NAME` y `Mobile/app.config.ts` (requiere compilar la app) | RH / Dirección |
| Datos de sucursales (domicilio, registro patronal, coordenadas y geocerca de 100–150 m) | Sin capturar (no se inventan) | Organización | RH |
| Edición de días oficiales del art. 74 (p. ej. jornada electoral) | Sólo lectura; RH agrega días de empresa o locales | Configuración › Festivos | RH (decidir si se requiere) |

## 5. Contradicciones abiertas

1. **Paleta en la web y en los íconos.** RH pidió el color institucional #225C54 con acentos #AF8B51 (hoja membretada). En la fase 1 se aplicó en la configuración de la organización, los reportes y la app móvil; en la fase 2 el agente web está migrando `Web/resources/css/app.css` a esa paleta (cambio aún no integrado al redactar este documento: verificar al integrar). Siguen con colores del manual de identidad el fondo del ícono adaptativo de Android (`Mobile/app.config.ts`, `#A0E04E`) y el ícono/favicon (logotipo sobre el degradado del manual). Falta decidir si también cambian.
2. **`Mobile/README.md` desactualizado** (zona del agente móvil): dice que la verificación facial no está disponible y que el push requiere `EAS_PROJECT_ID`. El código ya tiene la pantalla de rostro y registra el token FCM nativo.
3. **Comentario en el contrato** (`packages/contracts/src/api.ts`, `AuthMethod`): `'system'` se describe como «salida automática por política». Debe decir «sólo histórico».
4. **Alta manual sin fecha de nacimiento obligatoria.** La regla general 10 depende de la fecha de nacimiento del expediente, pero el alta y la edición manual la permiten vacía (la importación CSV sí la exige).

## 6. Pruebas y evidencias

<!-- RESULTADOS -->
<!-- El arquitecto registra aquí los resultados reales de la verificación final: Pest (suite completa, BD y memoria usadas), Jest, pytest, vue-tsc, vp check, PHPStan, Pint, Playwright (escritorio y móvil, claro y oscuro), compilación Android y capturas por pantalla. No escribir números estimados. -->

## 7. Limitaciones conocidas

- La inmutabilidad es detectable, no absoluta: un administrador con control total de servidor, claves y anclas puede alterar datos, pero la verificación lo detecta mientras las claves y las anclas se custodien por separado.
- La detección de root, emulador y ubicación simulada depende de señales del sistema operativo y no es infalible.
- La verificación facial no está certificada (ISO/IEC 30107-3); es un factor adicional.
- La biometría del teléfono acredita a quien desbloquea el teléfono, no identifica al colaborador por sí sola.
- Con el teléfono quieto en segundo plano, Android no entrega puntos de ubicación.
- Las disposiciones de la STPS del registro electrónico no estaban publicadas al corte; los parámetros quedan configurables.
