# Geolocalización

Revisión RH, pantalla 04 y regla general 9. La ubicación del colaborador es un dato personal (LFPDPPP): se usa sólo para verificar el lugar de trabajo durante la jornada y con la finalidad declarada en el aviso de privacidad.

## Dos usos distintos

| Uso | Cuándo | Dónde se guarda | Conservación |
|---|---|---|---|
| **Ubicación del marcaje** (evidencia) | En cada entrada, salida o pausa | Dentro del marcaje (`attendance_punches`, append-only) | Igual que el marcaje: nunca se depura |
| **Seguimiento durante la jornada** | Sólo puestos con `during_shift` y jornada abierta | `location_points` | Plazo configurable (90 días iniciales, pendiente de Jurídico) y depuración automática |

### Ubicación del marcaje

La política de asistencia (`attendance_policies.location_mode`) define qué se exige al marcar:

| Valor | Etiqueta | Efecto |
|---|---|---|
| `free` | Libre | No se pide ubicación. |
| `record` | Registrar ubicación | Se guarda si está disponible (valor inicial de `asistencias:instalar`). |
| `required` | Ubicación obligatoria | Sin ubicación se rechaza con `location_required`. |
| `geofence` | Dentro de geocerca | Fuera del radio de la sucursal se rechaza con `outside_geofence`. |
| `device_only` | Sólo dispositivo corporativo | Exige un teléfono marcado como corporativo. |

La geocerca es por sucursal (radio sugerido de 100 a 150 m; la web advierte fuera de ese rango). La columna «Origen del marcaje» distingue «App · dentro de geocerca» de «App · fuera de geocerca». La app nunca inventa coordenadas y envía el indicador `is_mocked` del sistema operativo.

### Seguimiento durante la jornada

Parámetro **por puesto** (`positions.location_tracking`, en **Configuración › Ubicación**):

- `on_punch` — «Solo ubicación al marcar» (por omisión; oficina y sucursal).
- `during_shift` — «Seguimiento durante la jornada» (puestos de campo).

Reglas del servidor (`Web/app/Domain/Location/LocationTracking.php`):

1. Inicia con la **entrada** y termina con la **salida**.
2. Se **pausa** durante el descanso o la comida registrados (`tracking_paused_states: ['break', 'meal']`) y se reanuda al regresar.
3. **Contingencia** si la salida se omite: se deja de aceptar ubicación al fin del turno programado + margen (60 min por omisión; sin turno, entrada + jornada total máxima + margen). **Nunca se genera una salida**: el día queda como omisión de checada.
4. `POST /api/v1/location/points` rechaza cada punto fuera de regla con su código (`outside_window`, `tracking_disabled`, `on_break`, `contingency_cutoff`, además de los de validación) sin abortar el lote.

Pruebas: `Web/tests/Feature/Api/LocationTrackingTest.php`, `Web/tests/Feature/Api/LocationTest.php`.

## App móvil

- Adaptador nativo único: `Mobile/src/features/location/tracker.ts` (`expo-location` + `expo-task-manager`) con servicio en primer plano y la notificación «Ubicación de trabajo activa». Lógica pura en `workLocation.ts` (filtros, ventana, cola).
- El ciclo lo gobierna la jornada (entrada, pausa, salida) y lo confirma el servidor (`GET /attendance/policy` y `GET /location/state`).
- Inicio muestra el estado real: activa, en pausa o apagada. Cerrar sesión o un `401` detienen el GPS.
- Cadencias sugeridas por el servidor (`LocationSettings`): primer plano ~15 s / 10 m; segundo plano 60 s / 50 m. Con el teléfono quieto en segundo plano Android no entrega muestras (`distanceInterval`).
- Permisos Android declarados en `Mobile/app.config.ts`: ubicación precisa, en segundo plano y servicio en primer plano de ubicación.

Pruebas: `Mobile/src/__tests__/trackingCycle.test.ts`, `location.test.ts`.

## Web: Ubicación en tiempo real

- **Operación › Ubicación en tiempo real** (`/ubicacion`), permiso `locations.view`: RH, Responsable regional y Gerente de sucursal, cada uno en su alcance.
- El mapa se actualiza cada 30 s. «Pausar mapa» sólo detiene la actualización de la vista; la app sigue registrando.
- Recorrido del día con distancia y marcajes.

Pruebas: `Web/tests/Feature/Web/Operacion/LocationTest.php`.

## Privacidad y conservación

- Aviso `UBICACION` (documento de política con acuse) y aviso de privacidad `AVISO_PRIVACIDAD` firmado en la app. `asistencias:aviso-ubicacion` publica el aviso en organizaciones existentes.
- `ubicacion:depurar` (diario 02:45) borra físicamente sólo `location_points` con más días que el plazo configurado. Es la **única excepción** a «nada se elimina»: el trigger rechaza todo DELETE salvo que la sesión active `@jornalia_location_purge = 1`, cosa que sólo hace `LocationRetention`. UPDATE sigue prohibido. Cada depuración queda en la bitácora (`location.purged`).
- El plazo de conservación (90 días iniciales) y los puestos con seguimiento son decisiones pendientes de RH y Jurídico.
- Ubicación simulada reportada por el teléfono ⇒ alerta `mock_location` en Cumplimiento (`DeviceIntegrityMonitor`). La detección no es infalible.

Ver también: [Privacidad](../seguridad/PRIVACIDAD_DATOS.md) e [Integridad y conservación](../cumplimiento/INTEGRIDAD_Y_CONSERVACION.md).
