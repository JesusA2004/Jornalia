# Progreso de implementación — estado real

Actualizado: 2026-11-17. Sólo se marca "probado" lo que se ejecutó de verdad.

## Verificaciones globales (última corrida)

| Verificación | Resultado |
|---|---|
| Backend Pest (BD limpia `jornalia_test_full`, MariaDB real con triggers) | **312 pasan**, 0 fallan, 2 omitidas |
| Microservicio facial (pytest) | 26 pasan |
| App móvil (Jest) | 59 pasan · `tsc` y `expo lint` limpios · `expo-doctor` 20/20 |
| Web: `vue-tsc`, `vp check` (formato + lint), `npm run build`, Pint | limpios |
| Web E2E en navegador real (Playwright, 1440 y 390 px, claro/oscuro) | 174 pasan (dev) · 80 pasan (build producción) |

## Módulos

| Módulo | Implementado | Probado | Resultado | Pendientes |
|---|---|---|---|---|
| Base de datos MariaDB (InnoDB forzado) | Sí | Migraciones + suite | Corrige el error 1071 original (MyISAM) | — |
| Multiempresa y alcance por sucursal/supervisor | Sí | Pest (incl. IDOR por URL) | OK | — |
| Roles y permisos (incl. "Autoridad laboral") | Sí | Pest + E2E con cuenta real de autoridad | OK | — |
| Registro inalterable (triggers + HMAC encadenado + anclas) | Sí | Pest (UPDATE/DELETE bloqueados, falsificación detectada) | OK | Anclas a almacenamiento WORM en producción |
| Baja lógica: nada se borra | Sí | Pest | OK | — |
| Motor legal versionado (reforma 40 h, DOF 03-03-2026 y 01-05-2026) | Sí | Pest por año 2026–2030 | OK | Puntos "requiere criterio jurídico" en la matriz |
| Jornadas, retardos, nocturnas, festivos | Sí | Pest + datos reales del teléfono | OK | — |
| Horas extra (doble/triple/sobre límite, multinivel) | Sí | Pest | OK | — |
| Salida automática (persona > puesto > política) | Sí | Pest + configuración web | OK | Ver un cierre real en el teléfono |
| Rectificaciones con selector de marcajes | Sí | Pest + E2E | OK | — |
| Incidencias, vacaciones (tabla LFT art. 76) | Sí | Pest | OK | Detalles sin datos reales aún |
| Calendario, reportes XLSX/PDF/CSV (inspección laboral) | Sí | Pest + descargas E2E | OK | Worker de cola en producción para lotes > 2000 filas |
| Panel, cumplimiento, integridad, auditoría | Sí | Pest + E2E | OK | — |
| Ubicación en tiempo real (modelo de la app Auditoría) | Sí | Teléfono real (primer plano) + Pest | OK | Prueba caminando en segundo plano |
| Dispositivos: 1 teléfono por colaborador, bloqueo de teléfono ajeno, sesión diaria | Sí | Pest + teléfono real (hardware_id y vencimiento verificados en BD) | OK | Probar bloqueo con 2.ª cuenta en el teléfono |
| App móvil: login, huella del SO + firma HMAC, offline y sincronización | Sí | **S20 FE real** contra API real | OK | — |
| Reconocimiento facial open source (YuNet + SFace + MiniFASNet) | Sí | pytest + Pest + imagen pública de muestra | Funciona; no certificado ISO 30107-3 | Enrolar rostro real y calibrar umbrales en el teléfono |
| Notificaciones (bandeja, recordatorios, comunicados web) | Sí | Pest | OK en bandeja | Push real requiere Firebase (docs/notificaciones/PUSH.md) |
| Identidad MR LANA (manual oficial) | Sí | Revisión visual web y teléfono | OK | Licencia de Museo Sans Rounded (opcional) |

## Requiere credenciales o infraestructura externa

- **Firebase** (push): alta de `mx.mrlana.asistencias(.dev)` y `google-services.json`; cuenta de servicio en el servidor.
- **EAS/Play Store**: cuenta de Expo y de Google Play para builds firmados y publicación.
- **Servidor de producción** (Ubuntu + Nginx + PHP-FPM + MariaDB), workers de cola (Supervisor) y cron del scheduler.
- **Claves de integridad** custodiadas fuera del servidor y almacenamiento WORM para anclas.

## Requiere revisión jurídica

Ver `docs/legal/MATRIZ_CUMPLIMIENTO.md` §"Requiere revisión jurídica" (horas extra en el periodo de ajuste 2026,
disposiciones STPS del registro electrónico pendientes de publicación, salida automática por política, retención y
datos biométricos).
