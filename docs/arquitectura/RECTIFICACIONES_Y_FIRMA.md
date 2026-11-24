# Rectificaciones con firma del colaborador e incidencias (Revisión RH)

## Flujo de 5 pasos (pantalla 05)

| Etapa (`stage`) | Quién | Permiso | Resultado |
|---|---|---|---|
| `por_autorizar` | Colaborador (app/web) o responsable | propio / `rectifications.create` | Evidencia obligatoria (1–5 archivos JPG/PNG/WEBP/HEIC/PDF ≤ 10 MB, SHA-256) |
| → `por_firmar` | Jefe inmediato | `rectifications.approve` + alcance | Fija `signature_due_at` (N días hábiles) |
| → `por_validar` | Colaborador en la app | propio | `ElectronicSignature` (NIP o biometría HMAC) |
| → `aplicada` | RH | `rectifications.validate` (+ `rectifications.apply_late` si es extemporánea) | `attendance_adjustments` encadenado; el marcaje original no cambia |
| `rechazada` | Cualquiera de las etapas | — | `rejection_stage`: por_autorizar, por_validar, declinada, vencida, cancelada |

Reglas duras (dominio, `App\Domain\Attendance\RectificationService`):

- Nadie autoriza ni valida su propio registro **ni su propia captura**; `is_super_admin` no otorga nada.
- Sin firma válida (sello `HashChain` verificado) no se aplica.
- Cada transición bloquea la fila y exige la etapa esperada (sin saltos ni decisiones dobles).
- `open_key` único mientras la solicitud está abierta: no hay duplicados del mismo día y marcaje.
- Plazo vencido ⇒ `rectificaciones:vencer` (cada 15 min) la rechaza; el día queda como omisión.
- La omisión de checada (incidencia `OMISION_*`) genera una rectificación `origin = incidence`;
  la incidencia sigue el resultado de la rectificación.

## Parámetros (`organization_settings`, Configuración › Incidencias, sólo RH)

- `rectifications.signature` = `{business_days: 3, confirmed_by_hr: false}` — **pendiente de
  confirmar por RH** (decisión pendiente de la Revisión RH).
- `payroll.close` = `{closed_through: 'AAAA-MM-DD' | null}` — fechas ≤ cierre son extemporáneas.

## Migración de datos existentes (2026_11_24_110100)

- `pending` → `por_autorizar` (recorre el flujo completo, incluida la firma; si no tiene
  evidencia, no puede autorizarse: se rechaza y se solicita de nuevo).
- `approved` → `aplicada` con `legacy_without_signature = true` (ya tenían su ajuste; no se
  exige firma retroactiva; la UI muestra «Previa al flujo»).
- `rejected` → `rechazada` (`rejection_stage = por_autorizar`); `cancelled` → `rechazada` (`cancelada`).
- El flujo de rectificación es fijo (Revisión RH, pantalla 05); `approval_flows` de `rectification` ya no aplica.

## Firma electrónica (`electronic_signatures`, append-only + triggers + HashChain)

- Texto firmado exacto (`statement`), `payload_sha256`, usuario, colaborador, dispositivo, IP,
  user-agent, `signed_at` UTC, secuencia y sello.
- Biometría: `device_signature = HMAC_SHA256(secreto_dispositivo, "sign|{purpose}|{id}|{payload_sha256}|{device_uuid}")`.
- NIP (`users.signature_pin_*`): 4–6 dígitos, no triviales, hash bcrypt; 5 fallos ⇒ 15 min.
- Convenio `CONVENIO_REGISTRO` y aviso `AVISO_PRIVACIDAD`: cuenta sólo la versión vigente
  publicada (acuse + firma). El convenio en papel cuenta si hay adjunto del expediente con
  `attachments.category = registration_agreement`.

## Documentos pendientes al marcar (servidor)

La app bloquea el marcaje mientras falten documentos; el servidor aplica la misma regla en
`PunchRecorder` mediante `App\Domain\Signatures\PunchDocumentsGate`, para todos los canales.

| Situación | Resultado |
|---|---|
| Marcaje **en línea** (`POST /api/v1/attendance/punches`, `POST /api/v1/kiosk/punches`, marcaje web `POST /asistencias/marcar`) sin convenio vigente firmado (en la app o en papel) o sin aviso de privacidad vigente aceptado | Se rechaza: HTTP 409, `code = documents_pending`, `legacy_code = policy_violation` (apps anteriores muestran el mensaje). La app recarga `GET /documents/pending` y abre el flujo de documentos. En la web, error de formulario `punch`. |
| Marcaje **sin conexión** (`POST /sync/punches`, `POST /kiosk/sync`, `captured_offline = true`) con `device_time` anterior a la recepción (más la tolerancia de reloj de la política) | Se **registra**: es evidencia de una jornada trabajada y nunca se descarta. Se abre la alerta `punch_without_agreement` (advertencia; una por colaborador y día con los marcajes y documentos faltantes) para que RH regularice. |
| Marcaje sin conexión con `device_time` posterior a la recepción | No acredita que se capturó antes: se rechaza como en línea. |
| La organización no ha publicado el convenio o el aviso | No se exige (no se puede pedir lo que no existe). Se abre la alerta de configuración `registration_documents_unpublished` (sin colaborador; una sola abierta, se actualiza con los documentos faltantes). |

Pendiente de RH/Jurídico: quien sólo marca en kiosco puede acreditar el convenio en papel, pero
el aviso de privacidad hoy sólo se acepta en la app.

## Dónde se opera

| Paso | Web (`/rectificaciones`) | App |
|---|---|---|
| Solicitud | «Nueva rectificación» a nombre del colaborador (`rectifications.create`) | «Corregir este día» en Historial; adjuntar foto, galería o documento |
| Autorización del jefe | Pestaña «Por autorizar» | — |
| Firma | Pestaña «Por firmar colaborador» (sólo consulta; nadie firma por el colaborador) | Bandeja «Firmas»: compara «Registro original» y «Propuesta», firma con NIP o huella, o rechaza con motivo |
| Validación RH | Pestaña «Por validar RH» | — |
| Resultado | Pestañas «Aplicadas» y «Rechazadas»; en Asistencias el día queda «Rectificada*» con enlace | Aviso en la bandeja en cada cambio de etapa (`RectificationStageChanged`) |

API para la app: `GET/POST /api/v1/rectifications`, `GET /rectifications/{id}`, `POST /rectifications/{id}/sign`, `POST /rectifications/{id}/decline`, `POST /profile/signature-pin`. Las evidencias se descargan con URL firmada temporal (`/api/v1/files/evidence/{id}`, 10 min). Detalle en [API](../api/README.md).

## Incidencias (pantalla 06)

Catálogo cerrado (`App\Domain\Incidences\IncidenceCatalog`), sin falta injustificada.
Las organizaciones existentes se ajustan con la migración 2026_11_24_110200 o
`php artisan incidencias:catalogo` (idempotente; desactiva tipos fuera de catálogo sin borrar).
Autorización por tipo: `manager` (`incidences.approve`), `hr` (`incidences.validate`) o
`manager_hr`. Cancelación sólo con motivo (`incidences.cancel`), sin borrado.
