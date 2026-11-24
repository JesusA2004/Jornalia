# Privacidad y protección de datos personales — MR LANA Asistencias

Corte: **17 de noviembre de 2026**. Fundamento principal: **Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP)**, publicada en el DOF el 20-03-2025, vigente desde el 21-03-2025 y con última reforma DOF 14-11-2025. Texto: https://www.diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf. Complementos: LFT (arts. 132 fr. XXXIV, 330-E, 330-I, 804) en https://www.diputados.gob.mx/LeyesBiblio/pdf/LFT.pdf. Matriz general en `docs/legal/MATRIZ_CUMPLIMIENTO.md`.

> No es asesoría jurídica. Las secciones marcadas con **CJ** requieren validación de un abogado.

> **Estado (Revisión RH, nov-2026):** el aviso de privacidad y el convenio de registro se publican como PDF versionado en Configuración › Documentos y se firman en la app; el correo del responsable de datos personales y el texto definitivo del aviso están pendientes de RH y Jurídico. La plantilla de la §5 es un borrador de apoyo.

## 1. Roles

| Rol (LFPDPPP art. 2) | Quién | Notas |
|---|---|---|
| **Responsable** | La empresa empleadora (cada organización en el sistema multiempresa) | Decide finalidades y medios. |
| **Persona encargada** (fr. XII: *"trate datos personales por cuenta del responsable"*) | Quien opere el software o el hosting si es un tercero, el proveedor de verificación facial, el proveedor de nube y de correo o notificaciones push | Requiere contrato con instrucciones, confidencialidad (art. 20) y medidas de seguridad. La comunicación al encargado **no** es "transferencia" (fr. XX). |
| **Tercero receptor (transferencia)** | IMSS, SAT, INFONAVIT, STPS e inspección del trabajo, tribunales laborales | Sin consentimiento cuando lo prevé la ley o un mandato de autoridad (arts. 9 fr. I y VII, 36). |
| **Autoridad garante** | **Secretaría Anticorrupción y Buen Gobierno** (art. 2 fr. XV; transitorios Cuarto y Décimo: asume las funciones del extinto INAI) | Procedimientos de protección de derechos, verificación y sanción (arts. 38-61). |

## 2. Inventario de datos

| Categoría | Datos | ¿Sensible? | Finalidad | Base legal (LFPDPPP) | Conservación propuesta |
|---|---|---|---|---|---|
| Identificación | Nombre, número de empleado, CURP, RFC, NSS, correo, teléfono, fotografía de perfil (opcional) | No | Alta, identificación y obligaciones patronales | Art. 9 fr. I y IV (obligación legal y relación jurídica): no requiere consentimiento | Relación laboral + 5 años |
| Laborales | Puesto, centro de trabajo, horario, turnos, salario base (si se calcula el pago de horas extra), modalidad presencial o teletrabajo, fecha de ingreso, antigüedad | No (financieros si incluye salario: art. 7 exige consentimiento **expreso** para datos financieros o patrimoniales, salvo los casos de los arts. 9 y 36) | Control de jornada, vacaciones, horas extra, cumplimiento de la LFT | Art. 9 fr. I y IV | Relación + 5 años |
| Registro de jornada | Marcajes de entrada, salida y descanso (UTC + zona horaria), dispositivo, origen (app, kiosco, web), rectificaciones y aprobaciones | No | Obligación del art. 132 fr. XXXIV LFT (registro electrónico, exigible con disposiciones STPS desde el 1-ene-2027); prueba (arts. 784, 804) | Art. 9 fr. I | Ver la §6 |
| Ubicación | (a) Coordenadas y precisión **en cada marcaje** y resultado de geocerca. (b) Sólo en puestos con «Seguimiento durante la jornada»: recorrido desde la entrada hasta la salida, en pausa durante descanso y comida (ver `docs/arquitectura/GEOLOCALIZACION.md`) | No por definición legal; alto riesgo en la práctica (CJ) | Verificar el lugar de trabajo durante la jornada | Art. 9 fr. IV para el trabajo presencial (**CJ**). Para teletrabajo: proporcionalidad del art. 330-I LFT. Informar en el aviso y en la app mientras se comparte | (a) Igual que el marcaje (append-only). (b) Plazo configurable, 90 días iniciales pendientes de Jurídico, con depuración automática |
| Biometría del sistema operativo | Huella o rostro validados por Android BiometricPrompt o iOS Face ID/Touch ID | **No se recaba.** El sistema sólo recibe un resultado booleano; la plantilla nunca sale del dispositivo | Confirmar que quien marca es el titular del dispositivo | No hay tratamiento de dato biométrico por el responsable; se menciona en el aviso por transparencia | No aplica |
| Verificación facial con proveedor | Imagen facial capturada, plantilla o vector biométrico, puntuación de coincidencia, prueba de vida | **Sí, tratar como sensible** (la lista del art. 2 es enunciativa; el rostro puede revelar origen étnico o estado de salud y su mal uso genera riesgo grave) — **CJ** | Evitar la suplantación en el marcaje | **Art. 8: consentimiento expreso y por escrito** (firma autógrafa, firma electrónica o mecanismo de autenticación). Debe haber una alternativa no biométrica para quien no consienta. Art. 8 párr. 2: justificar la base de datos sensible | Plantilla: mientras dure la relación; se elimina en ≤30 días tras la baja o la revocación. Imágenes de cada marcaje: ≤30 días salvo incidente (art. 12: *"el mínimo indispensable"*) |
| Evidencias | Fotografías o archivos adjuntos a incidencias, justificantes médicos | Los justificantes médicos son **sensibles** (estado de salud) | Justificar faltas o rectificaciones | Art. 9 fr. IV; para datos de salud: art. 8 (consentimiento expreso y escrito) | Relación + 5 años o lo que exija la ley (CJ) |
| Firma electrónica | NIP de firma (sólo hash bcrypt), texto firmado, SHA-256, fecha UTC, dispositivo, IP y agente; en biometría sólo la firma HMAC del dispositivo (la huella no sale del teléfono) | No | Acreditar la conformidad del colaborador con el convenio, el aviso y cada rectificación (LFT art. 132 fr. XXXIV) | Art. 9 fr. IV | Igual que el registro de jornada (append-only) |
| Técnicos | Identificador de instalación, modelo de dispositivo, versión de la app, IP, bitácoras de seguridad y auditoría | No | Seguridad, antifraude, soporte | Art. 9 fr. IV; art. 18 (medidas de seguridad) | 12 meses (bitácoras de auditoría de marcajes: igual que el marcaje) |

**Minimización:** por omisión todos los puestos usan «Solo ubicación al marcar»; el seguimiento continuo (también en segundo plano, con aviso visible «Ubicación de trabajo activa») sólo aplica a los puestos de campo que RH configure y únicamente con jornada abierta: inicia con la entrada, se pausa en descanso y comida, y termina con la salida o con el corte de contingencia. No se activa la cámara salvo para evidencia o verificación facial; en teletrabajo, cámaras y micrófonos sólo de forma extraordinaria (art. 330-I LFT).

## 3. Obligaciones clave y su implementación

| Obligación | Artículo | Implementación |
|---|---|---|
| Principios (licitud, finalidad, lealtad, consentimiento, calidad, proporcionalidad, información, responsabilidad) | 5 | Registro de actividades de tratamiento por organización |
| Consentimiento tácito por regla general; expreso para datos financieros; revocable sin efectos retroactivos | 7 | Aviso `AVISO_PRIVACIDAD` (PDF versionado e inmutable) que el colaborador acepta con firma electrónica en la app (`document_acknowledgements` + `electronic_signatures`: titular, versión, fecha UTC, método, dispositivo y sello). Una versión nueva vuelve a quedar pendiente. La revocación se atiende como solicitud ARCO (sin módulo propio) |
| Sensibles: consentimiento expreso **por escrito** | 8 | Firma electrónica o aceptación autenticada (contraseña + OTP) del consentimiento biométrico, con evidencia inalterable; sin consentimiento, la función facial queda desactivada |
| Aviso de privacidad (contenido mínimo) | 15 | Plantilla de la §5 |
| Aviso **simplificado** al recabar por medios electrónicos (fr. I-IV del art. 15 + enlace al integral) | 16 fr. II | La app bloquea el marcaje hasta firmar el convenio de registro y el aviso vigentes; aviso de ubicación `UBICACION` con acuse |
| Seguridad administrativa, técnica y física | 18 | Cifrado en tránsito (TLS) y en reposo de las plantillas; control de acceso por permisos; bitácora |
| Avisar de inmediato las vulneraciones que afecten de forma significativa | 19 | Procedimiento de respuesta a incidentes con plantilla de notificación al titular |
| Confidencialidad de quien trata datos | 20 | Cláusulas en contratos laborales y con encargados |
| ARCO: respuesta en ≤20 días, hacerla efectiva en los 15 siguientes; gratuito salvo costos de reproducción | 21-34 | Módulo de solicitudes ARCO con contador de plazos y acreditación de identidad |
| Persona o departamento de datos personales | 29 | Campo `privacy_contact_email` en Configuración › Empresa; los correos de ejemplo se rechazan y queda vacío hasta que RH y Jurídico lo capturen |
| Cancelación: bloqueo durante el plazo de prescripción y luego supresión; no procede si hay obligación legal | 24, 25 | Estado "bloqueado" para trabajadores dados de baja; purga programada al vencer la retención |
| Transferencias: comunicar el aviso al receptor; cláusula de aceptación | 35, 36 | Las transferencias legales (IMSS, SAT, autoridades) no requieren consentimiento (art. 36); cualquier otra, sí |
| Sanciones | 58-59 | Hasta 320,000 UMA; pueden duplicarse si hay datos sensibles |

## 4. Encargados (proveedores)

Contrato mínimo con cada encargado (verificación facial, nube, correo o push, mensajería):

1. Tratar sólo conforme a las instrucciones del responsable y para las finalidades del aviso.
2. No subcontratar sin autorización; trasladar las mismas obligaciones a subcontratistas.
3. Confidencialidad permanente (art. 20).
4. Medidas de seguridad equivalentes (art. 18); en verificación facial, sin uso de las imágenes para entrenar modelos del proveedor.
5. Notificación de vulneraciones sin demora.
6. Devolución o supresión al terminar el servicio, con constancia.
7. País de alojamiento declarado; si está fuera de México, informarlo en el aviso (**CJ** sobre la necesidad de consentimiento: la comunicación al encargado no es transferencia, pero conviene transparencia).
8. Derecho de auditoría.

## 5. Aviso de privacidad integral — plantilla para colaboradores

> Los campos `{{...}}` se configuran por organización. No contiene datos de ninguna empresa.

---

**AVISO DE PRIVACIDAD INTEGRAL PARA PERSONAS COLABORADORAS**

**1. Identidad y domicilio del responsable.** `{{razon_social}}`, con domicilio en `{{domicilio_completo}}`, es responsable del tratamiento de sus datos personales. Contacto del área de datos personales: `{{nombre_area_datos}}`, `{{correo_datos}}`, `{{telefono_datos}}`.

**2. Datos personales que tratamos.**
- Identificación y contacto: nombre, número de empleado, CURP, RFC, NSS, correo, teléfono.
- Laborales: puesto, centro de trabajo, horario, modalidad, antigüedad `{{si_aplica: y salario}}`.
- Registro de jornada: fecha y hora de entrada, salida y descansos, dispositivo y medio de registro.
- Ubicación: posición del dispositivo al registrar asistencia `{{si_geocerca_habilitada}}` y, sólo para los puestos `{{puestos_con_seguimiento}}`, durante la jornada (desde la entrada hasta la salida, en pausa durante descansos), con aviso visible en la app.
- Imágenes: fotografía de evidencia al registrar `{{si_aplica}}`.
- **Datos personales sensibles:** `{{si_verificacion_facial}}` rasgos faciales (imagen y plantilla biométrica) para verificar su identidad; `{{si_aplica}}` información de salud contenida en justificantes médicos.
- Biometría de su teléfono: si usa huella o reconocimiento facial de su teléfono para confirmar el registro, **esa información no sale de su dispositivo y no la recibimos**; sólo recibimos si la validación fue exitosa.

**3. Finalidades.**
*Necesarias (derivadas de la relación laboral y de obligaciones legales; no requieren su consentimiento):* registrar y conservar su jornada conforme a la Ley Federal del Trabajo; calcular tiempo extraordinario, descansos, vacaciones y primas; administrar incidencias y justificantes; atender requerimientos de autoridades laborales, fiscales y de seguridad social; seguridad de la información y prevención de fraude en los registros.
*Que requieren su consentimiento:* `{{si_verificacion_facial}}` verificar su identidad mediante reconocimiento facial al registrar asistencia (dato sensible: requiere su consentimiento expreso y por escrito). `{{otras_finalidades_opcionales}}`.
Si no consiente la verificación facial, podrá registrar su asistencia mediante `{{metodo_alternativo}}` sin consecuencia alguna en su relación laboral.

**4. Opciones para limitar el uso o la divulgación.** Puede solicitarlo a `{{correo_datos}}`. `{{si_aplica: registro en listado de exclusión interno}}`.

**5. Transferencias.** Sus datos podrán comunicarse sin su consentimiento cuando lo exija la ley o una autoridad (IMSS, INFONAVIT, SAT, STPS, tribunales laborales), conforme al art. 36 de la LFPDPPP. `{{otras_transferencias_y_clausula_de_aceptacion}}`. Además, proveedores que actúan como encargados por nuestra cuenta (alojamiento en la nube, verificación de identidad, envío de notificaciones) tratan datos sólo conforme a nuestras instrucciones: `{{lista_categorias_encargados_y_paises}}`.

**6. Derechos ARCO y revocación del consentimiento.** Puede acceder a sus datos, rectificarlos, cancelarlos u oponerse a su tratamiento, y revocar su consentimiento, mediante solicitud a `{{correo_datos}}` o en `{{ruta_en_app_o_portal}}`. La solicitud debe incluir su nombre, un medio para recibir la respuesta, documentos que acrediten su identidad (o la de su representante), la descripción clara de los datos y del derecho que ejerce y, para rectificación, la documentación que la sustente. Responderemos en un máximo de **20 días** y, si procede, la haremos efectiva dentro de los **15 días** siguientes. El ejercicio es gratuito, salvo costos de reproducción o envío. Algunos datos no podrán cancelarse mientras exista obligación legal de conservarlos (por ejemplo, los registros de asistencia conforme a la Ley Federal del Trabajo y la Ley del Seguro Social); en ese caso se bloquearán y se suprimirán al vencer el plazo. Si no recibe respuesta o no está de acuerdo, puede acudir ante la **Secretaría Anticorrupción y Buen Gobierno**.

**7. Conservación.** Registros de jornada: `{{anios_retencion, por defecto 5}}` años o 1 año después de terminada la relación laboral, lo que sea mayor. Plantilla biométrica facial: mientras dure la relación laboral; se elimina a más tardar `{{dias, por defecto 30}}` días después de la baja o de la revocación de su consentimiento.

**8. Cambios al aviso.** Le comunicaremos cualquier cambio mediante `{{medio: notificación en la app / correo}}` y, cuando el cambio implique nuevas finalidades que requieran consentimiento, se lo pediremos de nuevo.

Fecha de última actualización: `{{fecha}}` · Versión `{{version}}`.

---

**Aviso simplificado** (pantalla en la app, art. 16 fr. II): identidad del responsable, datos que se recaban (señalando los sensibles), finalidades distinguiendo las que requieren consentimiento, opciones para limitar el uso y enlace al aviso integral.

**Consentimiento biométrico** (pantalla separada, nunca preseleccionada): *"Otorgo mi consentimiento expreso para que `{{razon_social}}` trate mis rasgos faciales (dato personal sensible) con la única finalidad de verificar mi identidad al registrar asistencia, en los términos del aviso de privacidad versión `{{version}}`. Sé que puedo revocarlo en cualquier momento y usar `{{metodo_alternativo}}`."* La evidencia (titular, versión, fecha UTC y factor de autenticación usado) se guarda append-only.

## 6. Retención y eliminación

| Dato | Plazo | Fundamento |
|---|---|---|
| Marcajes, rectificaciones y su bitácora | El mayor entre 5 años desde la fecha y 1 año después de la baja; más bloqueo mientras exista un juicio | LFT 804 (mínimo); LSS 15 fr. II y CFF 30 (5 años); LFPDPPP 24 (bloqueo = plazo de prescripción) — **CJ** |
| Coordenadas del marcaje | Igual que el marcaje (no se depuran: forman parte del registro inalterable) | LFT 804; LFPDPPP 12 — **CJ** |
| Recorridos durante la jornada (`location_points`) | 90 días iniciales, configurables; depuración automática `ubicacion:depurar` | LFPDPPP 12 (proporcionalidad) — pendiente de Jurídico |
| Plantilla facial | Baja o revocación + ≤30 días | LFPDPPP 8 y 12 |
| Imagen de cada verificación | ≤30 días salvo incidente documentado | LFPDPPP 12 |
| Justificantes médicos | Relación + 5 años (CJ) | Prueba de faltas justificadas |

Los marcajes son append-only (AGENTS.md, regla 1). La purga al vencer la retención debe ser un proceso administrativo **auditado y excepcional**, distinto de cualquier edición de negocio, y coordinado con el Arquitecto (los triggers de base de datos bloquean DELETE).

## 7. Google Play — borrador de la declaración Data Safety

> Borrador para revisión; las respuestas dependen de la configuración final de la app. Según las definiciones de Google, los datos tratados **sólo en el dispositivo** no cuentan como "recopilados", y la comunicación a **proveedores de servicios** que actúan por cuenta del desarrollador no cuenta como "compartir".

**Sección general**
- ¿La app recopila o comparte datos de usuario de los tipos requeridos? **Sí.**
- ¿Los datos se cifran en tránsito? **Sí** (HTTPS/TLS).
- ¿Los usuarios pueden solicitar que se eliminen sus datos? **Sí**, con la salvedad de la conservación legal (ver la §8).

| Tipo de dato (categoría de Play) | ¿Recopilado? | ¿Compartido? | ¿Opcional? | Finalidades (Play) | Comentario |
|---|---|---|---|---|---|
| Ubicación → Ubicación precisa | Sí (al marcar; y durante la jornada en puestos con seguimiento) | No | Según la política de la empresa: obligatorio si exige geocerca | Funcionalidad de la app; prevención de fraude y seguridad | La app declara `ACCESS_BACKGROUND_LOCATION`: Play exige la declaración de ubicación en segundo plano y la divulgación destacada |
| Información personal → Nombre, Correo electrónico, ID de usuario, Teléfono | Sí | No | No | Funcionalidad de la app; administración de la cuenta | |
| Fotos y videos → Fotos | Sí, si la verificación facial o la evidencia fotográfica están habilitadas | No (el proveedor facial es un proveedor de servicios) | Sí (existe un método alternativo) | Funcionalidad de la app; prevención de fraude y seguridad | Declarar la biometría facial en la política de privacidad |
| Archivos y documentos | Sí, si se adjuntan justificantes | No | Sí | Funcionalidad de la app | |
| Actividad en la app → Otras acciones del usuario (marcajes) | Sí | No | No | Funcionalidad de la app | |
| Información y rendimiento de la app → Registros de fallos, Diagnóstico | Sí, si hay telemetría de errores | No | — | Analíticas; funcionalidad | Ajustar al SDK real |
| ID de dispositivo u otros identificadores | Sí (ID de instalación para antifraude y push) | No | No | Prevención de fraude y seguridad; funcionalidad | |
| Biometría del SO (huella o rostro del teléfono) | **No** (se procesa sólo en el dispositivo) | No | — | — | No declarar como recopilada |
| Información financiera (salario) | Sólo si la app móvil la muestra o la envía | No | — | Funcionalidad | Si sólo se muestra desde el servidor, revisar con la guía de Play |

Requisitos asociados: URL pública de la política de privacidad en la ficha y dentro de la app; divulgación destacada antes de pedir el permiso de ubicación y de cámara; las respuestas deben coincidir con la política de privacidad.

## 8. Política de eliminación de cuenta (borrador)

**Requisito de Google Play:** las apps que permiten **crear** cuentas deben ofrecer la eliminación de la cuenta dentro de la app y mediante un enlace web. Si las cuentas sólo las crea la empresa empleadora y la app no permite registrarse, la obligación podría no aplicar (**verificar** con la política vigente de Play). Aun así, se recomienda publicar el enlace web.

**Procedimiento propuesto**
1. **Solicitud:** en la app (Perfil → Privacidad → Solicitar eliminación) o en la página web pública `{{url_eliminacion}}` (formulario con acreditación de identidad).
2. **Efecto inmediato:** se cierran sesiones, se revocan tokens, se elimina del dispositivo la cola offline ya sincronizada, se eliminan la plantilla facial y las imágenes de verificación (≤30 días) y se eliminan las preferencias y datos de notificación.
3. **Conservación legal (bloqueo):** los registros de jornada, rectificaciones y documentos laborales **no se eliminan** mientras corra el plazo legal (§6). Quedan bloqueados (sin uso salvo defensa legal o requerimiento de autoridad, LFPDPPP arts. 24-25) y se suprimen al vencer.
4. **Si la persona sigue trabajando:** la eliminación de la cuenta de la app no termina la relación laboral; el registro de jornada es una obligación legal del empleador (LFT art. 132 fr. XXXIV), así que se ofrecerá un método alternativo de registro.
5. **Respuesta:** en ≤20 días con la determinación y, si procede, ejecución en ≤15 días (LFPDPPP art. 31), con aviso al titular al cancelar (art. 24).
6. **Texto para la ficha de Play:** *"Puedes solicitar la eliminación de tu cuenta en la app o en `{{url_eliminacion}}`. Eliminamos de inmediato tu acceso, tus datos biométricos y tus preferencias. Los registros de asistencia que la ley obliga a conservar a tu empleador se mantienen bloqueados hasta `{{plazo}}` y después se eliminan."*

## 9. Pendientes (CJ y no verificados)

- Calificar como sensibles la plantilla y la imagen facial (recomendación: sí).
- Base legal de la geolocalización: relación jurídica (art. 9 fr. IV) o consentimiento; en teletrabajo, proporcionalidad del art. 330-I LFT.
- Si el alojamiento fuera de México con encargados requiere informarse o consentirse.
- **No verificado:** si existe un nuevo Reglamento de la LFPDPPP; se presume que sigue como referencia el de 2011 (fuentes secundarias).
- **No verificado:** las disposiciones STPS sobre el registro electrónico (art. 132 fr. XXXIV), que pueden imponer campos o conservación adicionales.
- La declaración Data Safety debe ajustarse a los SDK reales (telemetría, push, proveedor facial) antes de publicarse.
