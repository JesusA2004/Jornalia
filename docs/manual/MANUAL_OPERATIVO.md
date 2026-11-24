# Manual operativo por rol

Guía breve de las tareas de cada rol. Cada pantalla tiene su ayuda «?» con la explicación detallada. Permisos exactos: [Roles y permisos](../seguridad/ROLES_Y_PERMISOS.md).

**Reglas que aplican a todos**

- Ningún marcaje original se edita ni se borra. Toda corrección es una **rectificación** con evidencia y firma del colaborador.
- El sistema **nunca marca por nadie**. Si falta una salida, el día queda como omisión de checada hasta rectificarse.
- Nada se elimina: se cancela con motivo o se desactiva, y queda en la bitácora.

## Colaborador (app)

| Tarea | Cómo |
|---|---|
| Primer uso | Inicia sesión. Si el teléfono es nuevo, espera a que RH lo autorice («Dispositivo pendiente»). Define tu **NIP de firma** (Perfil › NIP). Firma el **convenio de registro** y el **aviso de privacidad** cuando la app los presente: sin ellos no puedes marcar. |
| Marcar | Inicio › botón grande › huella o rostro del teléfono. Palomita verde = registrado con folio. Reloj ámbar = guardado sin internet; se envía solo. Descanso y comida: botón de abajo. |
| Acuse | Cada marcaje deja un acuse con fecha y hora en la campana (bandeja), aunque no llegue notificación. |
| Ubicación | Según tu puesto: sólo al marcar o durante la jornada. Si se comparte, verás el aviso «Ubicación de trabajo activa». Se pausa en descanso o comida y se detiene con tu salida. |
| Corregir un día | Historial › día › «Corregir este día». Adjunta evidencia (foto, galería o documento). Tu jefe la autoriza. |
| Firmar una corrección | Firmas › compara «Registro original» con «Propuesta» › firma con NIP o huella, o rechaza con motivo. Tienes el plazo indicado (3 días hábiles por omisión); si vence, se rechaza y el día queda como omisión. |
| Incidencias y vacaciones | Solicitudes › incidencia (con su evidencia) o vacaciones (con tu saldo). |
| Comunicados | Los importantes piden «Confirmar lectura». |

## Kiosco de sucursal

Para quien no tiene celular. En la tableta: número de empleado › NIP de firma › tipo de marcaje (› rostro, si la política lo exige). Sin internet guarda el marcaje y lo envía después. Activación y revocación: RH o Sistemas en **Dispositivos › Kioscos**.

## Gerente de sucursal

| Frecuencia | Tarea | Dónde |
|---|---|---|
| Diario | Revisar ausencias, retardos y «Sin salida» de su sucursal | Panel → Asistencias (`Sólo omisiones de checada`) |
| Diario | Autorizar o rechazar (con motivo) las rectificaciones de su personal | Rectificaciones › Por autorizar |
| Diario | Registrar y autorizar incidencias con su evidencia (incapacidad: certificado IMSS y folio) | Incidencias |
| Según ocurra | Pedir la rectificación de cada omisión: una omisión sin rectificar por más de 2 días genera alerta | Incidencias › Omisión de checada (abre la rectificación) |
| Según ocurra | Aprobar vacaciones | Vacaciones |
| Consulta | Ubicación en tiempo real de su personal; «Pausar mapa» sólo detiene la vista | Ubicación en tiempo real |

No puede autorizar su propio registro ni lo que él mismo capturó.

## Responsable regional y Dirección

- Consultan Panel, Asistencias, Horas extra, Calendario y Reportes de su alcance («Asistencia por sucursal» abre el detalle del día).
- Autorizan o justifican el **tiempo extra pendiente** por colaborador y semana (Horas extra). El de menores de 18 está bloqueado.
- El regional también consulta la ubicación en tiempo real y envía comunicados a su alcance. Dirección no ve ubicación.

## Administrador RH

| Frecuencia | Tarea | Dónde |
|---|---|---|
| Diario | Validar las rectificaciones firmadas (paso 5); sólo entonces se aplica el ajuste | Rectificaciones › Por validar RH |
| Diario | Atender alertas legales con nota | Cumplimiento › Alertas legales |
| Diario | Autorizar teléfonos nuevos o cambios de teléfono | Dispositivos |
| Antes de cada nómina | Revisar «Firmas del colaborador pendientes» y «Omisiones de checada sin rectificar» | Panel › Por resolver |
| Antes de cada nómina | Fijar la fecha de **cierre de nómina**; las rectificaciones posteriores quedan «extemporáneas» y sólo RH las aplica | Configuración › Incidencias |
| Antes de cada nómina | Exportar horas dobles, triples e incidencias para NOI | Reportes › Exportación para NOI |
| Altas | Alta del colaborador (fecha de nacimiento, sucursal, puesto y horario). Queda «Alta en proceso» hasta tener la app vinculada y el convenio firmado (en la app o en papel adjunto al expediente) | Colaboradores |
| Bajas | Registrar la baja: ese día se revocan la app, el dispositivo y las sesiones | Colaboradores › Baja |
| Horarios | Crear horarios (se bloquean si exceden la jornada legal vigente; se advierte el tramo futuro). Autorizar **cambios de horario programados** (sólo a futuro, con motivo; se avisa al colaborador) | Horarios y turnos |
| Catálogos | Ajustar evidencia y autorización de cada tipo de incidencia (catálogo cerrado); plazo de firma | Configuración › Incidencias |
| Normativa | Revisar la tabla por año con Jurídico y marcar «Verificada por RH» | Configuración › Normativa |
| Documentos | Publicar el aviso de privacidad (PDF) y el convenio de registro; una versión nueva vuelve a pedir firma | Configuración › Documentos |
| Ubicación | Elegir qué puestos llevan «Seguimiento durante la jornada» y el plazo de conservación de recorridos | Configuración › Ubicación |
| Festivos | Revisar los días de descanso obligatorio precargados y agregar los de empresa o locales | Configuración › Festivos |
| Comunicados | Enviar por sucursal, departamento o puesto; pedir confirmación de lectura en los importantes | Comunicados |
| Inspección | Generar el «Reporte para inspección laboral» con datos de integridad | Reportes |

RH no administra usuarios ni roles (separación de funciones). Debe usar doble factor.

## Administrador de plataforma (Sistemas)

| Tarea | Dónde |
|---|---|
| Configurar el doble factor propio (obligatorio) | Ajustes › Seguridad |
| Alta de usuarios con rol y **alcance por sucursal** (obligatorio para gerentes y regionales); acceso de autoridad laboral con vigencia | Usuarios y roles |
| Revisión trimestral de accesos | Usuarios y roles › Revisión |
| Organización: datos oficiales, sucursales como centros de trabajo, geocercas | Organización |
| Integraciones (People): estado, simular y sincronizar | Integraciones |
| Kioscos de sucursal, revocar dispositivos | Dispositivos |
| Configuración técnica: empresa, sistema, notificaciones | Configuración |
| Vigilar la integridad (la verificación es automática a las 03:30; el botón es opcional) | Cumplimiento › Integridad del registro |

Sistemas **no** autoriza, valida, rectifica ni firma registros de asistencia, ni aprueba dispositivos.

## Contraloría

Sólo lectura: Panel, Asistencias, Rectificaciones, Incidencias, Vacaciones, Horas extra, Calendario, Reportes (con exportación), Cumplimiento, Integridad y Bitácora de cambios.

## Autoridad laboral (inspección)

Acceso de sólo lectura con fecha de vencimiento, creado por Sistemas a solicitud de RH. Consulta marcajes originales, rectificaciones con su evidencia y firma, incidencias, horas extra, integridad y bitácora, y exporta reportes. Al vencer, el acceso se desactiva solo.

## Rutinas automáticas (no requieren acción)

Recalculo diario de jornadas (01:15 y cada 4 h), verificación y anclaje de integridad (03:30 y cada hora), alertas de omisiones sin rectificar (07:00), rechazo de rectificaciones vencidas (cada 15 min), recordatorios de entrada y salida (cada 5 min; sólo avisan, nunca marcan), revocación de bajas programadas (cada hora), depuración de recorridos vencidos (02:45), precarga de festivos (mensual) y revisión de accesos (02:10).
