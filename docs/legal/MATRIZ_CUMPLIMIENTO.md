# Matriz de cumplimiento legal — MR LANA Asistencias

Corte de investigación: **17 de noviembre de 2026**. Ámbito: relaciones de trabajo del Apartado A del art. 123 constitucional (sector privado) regidas por la Ley Federal del Trabajo (LFT).

Parámetros consumibles por el sistema: `Web/database/data/legal/parametros_legales.json`. Este documento es su fundamento. La columna «Implementación en el sistema (propuesta)» es la propuesta de diseño del corte; lo efectivamente implementado está en la §6, en [Motor legal](MOTOR_LEGAL.md) y en la [trazabilidad de la Revisión RH](../cierre/TRAZABILIDAD_REVISION_RH.md). **No es asesoría jurídica**: los puntos marcados como "requiere criterio jurídico" deben validarlos abogados laboralistas antes de producción.

## 0. Fuentes primarias consultadas

| Clave | Documento | Publicación DOF | URL |
|---|---|---|---|
| F1 | Decreto que reforma las fracciones IV y XI del Apartado A del art. 123 constitucional (reducción de jornada) | 2026-03-03 (edición vespertina) | https://dof.gob.mx/nota_detalle.php?codigo=5781417&fecha=03/03/2026 · PDF: https://www.diputados.gob.mx/LeyesBiblio/ref/dof/CPEUM_ref_279_03mar26.pdf |
| F2 | Decreto que reforma, adiciona y deroga diversas disposiciones de la LFT (reducción de jornada) | 2026-05-01 (edición vespertina) | https://dof.gob.mx/nota_detalle.php?codigo=5786537&fecha=01%2F05%2F2026 · PDF: https://www.diputados.gob.mx/LeyesBiblio/ref/lft/LFT_ref52_01may26.pdf |
| F3 | LFT, texto vigente compilado (última reforma DOF 14-05-2026) | — | https://www.diputados.gob.mx/LeyesBiblio/pdf/LFT.pdf · historial: https://www.diputados.gob.mx/LeyesBiblio/ref/lft.htm |
| F4 | Decreto que reforma arts. 76 y 78 LFT (vacaciones dignas) | 2022-12-27 | https://www.diputados.gob.mx/LeyesBiblio/ref/lft/LFT_ref41_27dic22.pdf |
| F5 | Decreto que reforma art. 74 fr. VII LFT (1 de octubre) | 2024-09-30 | https://www.diputados.gob.mx/LeyesBiblio/ref/lft/LFT_ref46_30sep24.pdf |
| F6 | STPS, "Reducción de la jornada laboral a 40 horas: preguntas frecuentes" (25-mar-2026; anterior a F2) | — | https://www.gob.mx/stps/documentos/reduccion-de-la-jornada-laboral-a-40-horas-preguntas-frecuentes |
| F7 | Ley Federal de Protección de Datos Personales en Posesión de los Particulares (nueva ley, DOF 20-03-2025; última reforma DOF 14-11-2025) | 2025-03-20 | https://www.diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf · historial: https://www.diputados.gob.mx/LeyesBiblio/ref/lfpdppp.htm |
| F8 | Ley del Seguro Social, art. 15 fr. II | — | https://www.diputados.gob.mx/LeyesBiblio/pdf/LSS.pdf |
| F9 | Código Fiscal de la Federación, art. 30 | — | https://www.diputados.gob.mx/LeyesBiblio/pdf/CFF.pdf |
| F10 | NOM-037-STPS-2023, Teletrabajo (DOF 08-06-2023) | 2023-06-08 | https://sidof.segob.gob.mx/notas/docFuente/5691672 · guía STPS: https://www.gob.mx/cms/uploads/attachment/file/915613/GUIA_INFORMATIVA_NOM-37_1_1.pdf |
| F11 | PROFEDET, tabla de vacaciones (confirma la interpretación del art. 76) | — | https://www.profedet.gob.mx/micrositio/index.php/vacaciones |

## 1. Estado real de la reforma de 40 horas (verificado)

**Sí está publicada, en dos decretos:**

1. **Reforma constitucional (F1)**, DOF **3 de marzo de 2026**, vigente **ese mismo día** (transitorio Primero: "entrará en vigor el día de su publicación").
   - Art. 123 A fr. IV: *"La jornada laboral será de cuarenta horas semanales en los términos que establezca la Ley. Por cada seis días de trabajo las personas trabajadoras deberán disfrutar por lo menos de un día de descanso con goce de salario íntegro."*
   - Fr. XI: *"El trabajo extraordinario no excederá de doce horas en una semana, las cuales podrán distribuirse en hasta cuatro horas diarias, en un máximo de cuatro días en ese periodo."* El excedente *"obliga a la persona empleadora a pagar doscientos por ciento más"*. *"Las personas menores de dieciocho años no podrán laborar tiempo extraordinario."*
   - Transitorio Tercero: la jornada se alcanza gradualmente *"a partir del 1 de enero del año correspondiente"*: 2026 = 48, 2027 = 46, 2028 = 44, 2029 = 42, 2030 = 40. La Constitución **no** gradúa las horas extra.
   - Transitorio Cuarto: la reducción no puede disminuir sueldos, salarios ni prestaciones.
2. **Reforma a la LFT (F2)**, DOF **1 de mayo de 2026**, vigente el **1 de mayo de 2026** (transitorio Primero). Reforma arts. 58, 59, 61, 66, 67 (deroga 2.º párrafo), 68, 69, 71, 132 fr. XXXIV y 994 fr. IV Bis.
   - Transitorio Segundo (jornada ordinaria del art. 59, desde el 1 de enero de cada año): **2026 = 48, 2027 = 46, 2028 = 44, 2029 = 42, 2030 = 40**.
   - Transitorio Tercero: *"El periodo que transcurra del 1 de mayo al 31 de diciembre de 2026 permitirá a las personas trabajadoras y empleadoras ajustar los procesos de trabajo a los términos de este Decreto."*
   - Transitorio Cuarto (horas extra del art. 66, desde el 1 de enero de cada año): **2026 = 9, 2027 = 9, 2028 = 10, 2029 = 11, 2030 = 12**.
   - Transitorio Quinto: las disposiciones de carácter general del registro electrónico (art. 132 fr. XXXIV) *"entrarán en vigor a partir del 1 de enero de 2027"*.
   - Transitorio Séptimo: la reducción no puede disminuir sueldos, salarios ni prestaciones.

**Las cifras que dio el usuario coinciden con el texto oficial** (48/46/44/42/40 y 9/9/10/11/12). Cada escalón cambia el **1 de enero** de su año.

**Registro electrónico de jornada.** Nuevo art. 132 fr. XXXIV: *"Registrar de manera electrónica la jornada laboral de cada persona trabajadora, incluyendo el horario de inicio y finalización; así como proporcionarlo a la autoridad cuando se le requiera."* La STPS definirá el ámbito de aplicación y las excepciones. *"El contenido del registro electrónico hará prueba plena si se acredita que fue acordado entre la persona trabajadora y empleadora."* Multa de 250 a 5,000 UMA (art. 994 fr. IV Bis). **Estado al 2026-11-17: no localicé en el DOF las disposiciones generales de la STPS** (búsqueda en dof.gob.mx y gob.mx/stps). Los requisitos técnicos (formato, inalterabilidad, plazos de entrega, firma) siguen **no verificados / pendientes**.

## 2. Matriz

Convenciones: **V** = verificado con URL (texto leído en la fuente primaria en esta sesión); **NV** = no verificado; **CJ** = requiere criterio jurídico.

| # | Artículo | Norma | Fecha de publicación | Entrada en vigor | Ámbito | Excepciones | Consecuencia operativa | Implementación en el sistema (propuesta) | Prueba sugerida | Estado |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 123 A fr. IV | CPEUM (F1) | 2026-03-03 | 2026-03-03 | Todo trabajo del Apartado A | Calendario gradual (trans. 3.º) | Tope semanal constitucional; 1 descanso por cada 6 días | Versionar `weekly_ordinary_max_minutes` por fecha civil del centro de trabajo | Unit: semana que cruza 31-dic/1-ene toma el tope de cada día según su versión (CJ en semanas partidas) | V (F1) |
| 2 | 123 A fr. XI | CPEUM (F1) | 2026-03-03 | 2026-03-03 | Todo trabajo del Apartado A | — | 12 h extra/semana, ≤4 h/día, ≤4 días; excedente +200 %; prohibido a menores de 18 | Ver filas 9-11 | — | V (F1); tensión con la gradualidad de la LFT: CJ |
| 3 | 58 párr. 2.º | LFT (F2) | 2026-05-01 | 2026-05-01 | General | — | *"Esta podrá ser distribuida de común acuerdo"* | Horarios con distribución semanal libre sujeta a topes diarios y semanales; guardar evidencia del acuerdo | Feature: horario 5×9.2 h válido si cumple los topes (CJ: tope diario de art. 61 vs distribución) | V (F3); CJ |
| 4 | 59 + trans. 2.º | LFT (F2) | 2026-05-01 | Escalones el 1-ene de 2026 a 2030 | General | Trabajos especiales con regulación propia (Título Sexto) | Tope ordinario semanal: 2880/2760/2640/2520/2400 min | `versions[].weekly_ordinary_max_minutes`; minutos que excedan = extra | Unit por cada año con 1 min sobre el tope | V (F2) |
| 5 | 60 | LFT (F3) | 1970-04-01 | — | General | — | Diurna 06:00-20:00; nocturna 20:00-06:00; mixta si el nocturno es **menor** de 3.5 h, con 3.5 h o más se reputa nocturna | `night_window`; `mixed_max_night_minutes = 209` (< 210) | Unit: 210 min nocturnos → nocturna; 209 → mixta | V (F3) |
| 6 | 61 | LFT (F2) | 2026-05-01 | 2026-05-01 | General | — | Máximo diario 8 h diurna / 7 nocturna / 7.5 mixta | `daily_max_minutes` 480/420/450 | Unit: clasificación y exceso diario | V (F3) |
| 7 | 63-64 | LFT (F3) | 1970 | — | Jornada continua | — | Descanso ≥30 min; si no puede salir del lugar, ese tiempo cuenta como efectivo | `min_break_minutes = 30`; marcar si el descanso es "dentro del centro" para computarlo como jornada | Feature: descanso dentro del centro no se descuenta | V (F3) |
| 8 | 65, 67 | LFT (F3) | 1970; 67 reformado 2026-05-01 | — | Siniestro o riesgo inminente | — | Prolongación por siniestro se paga sencilla (art. 67) y no cuenta como extra | Tipo de incidencia "siniestro" con autorización y evidencia | Feature: horas tipo siniestro al 1.00 | V (F3) |
| 9 | 66 + trans. 4.º | LFT (F2) | 2026-05-01 | Escalones el 1-ene; texto vigente desde 2026-05-01 | General | Menores de 18 (art. 178) | Extra al doble; tope semanal 9/9/10/11/12 h; ≤4 h diarias en ≤4 días | `overtime.weekly_max_minutes`, `daily_max_minutes = 240`, `max_days_per_week = 4`, `double_rate_multiplier = 2.00` | Unit por año; 5.º día con extra → alerta | V (F2). **CJ** sobre si 4 h/4 días aplica en 2026 (transitorio de ajuste; antes eran 3 h/3 veces) |
| 10 | 68 | LFT (F2) | 2026-05-01 | 2026-05-01 | General | — | Excedente sobre el art. 66: ≤4 h/semana, pagado con +200 % (triple); ordinaria + extra ≤12 h diarias; el trabajador puede negarse | `excess_rate_multiplier = 3.00`; reglas adicionales: excedente ≤240 min/semana y día total ≤720 min (bloqueo o alerta grave) | Unit: minuto 541 en 2027 → triple; día de 721 min → violación | V (F2) |
| 11 | 784 fr. VIII | LFT (F3) | 1980; reformada 2012 | — | Juicio laboral | — | Carga de la prueba patronal sobre jornada *"cuando ésta no exceda de nueve horas semanales"* (no se armonizó con la reforma) | Conservar el registro completo de marcajes y rectificaciones | — | V (F3); CJ (desfase normativo) |
| 12 | 69-70 | LFT (F2) | 2026-05-01 | 2026-05-01 | General | Labor continua: el día se fija de común acuerdo (70) | 1 día de descanso con goce de salario por cada 6 trabajados | `rest_days_per_week = 1`; alerta al 7.º día consecutivo | Feature: 7 días seguidos → alerta | V (F3) |
| 13 | 71 | LFT (F2) | 2026-05-01 | 2026-05-01 | Quien labore en domingo | — | Prima dominical ≥25 % del salario ordinario | `sunday_premium_min_percent = 25.00`; día domingo según la zona horaria del centro | Unit: turno nocturno que cruza a domingo (CJ: prorrateo) | V (F3) |
| 14 | 73 | LFT (F3) | 1970 | — | Trabajo en día de descanso semanal | Menores: prohibido (178) | Salario doble **adicional** al del descanso | `rest_day_work_multiplier = 2.00` como adicional | Unit: el pago resultante es descanso + 2 × salario | V (F3) |
| 15 | 74 | LFT (F3, F5) | 1970; fr. VII DOF 2024-09-30 | fr. VII: 2024-10-01 | General | — | 1-ene; 1.er lunes de feb.; 3.er lunes de mar.; 1-may; 16-sep; 3.er lunes de nov.; 1-oct cada 6 años (transmisión del Ejecutivo: 2024, 2030…); 25-dic; jornada electoral | `holidays[]` con reglas `fixed`, `nth_weekday`, `every_six_years`, `manual` | Unit: calendario 2027 y 2030 (incluye 1-oct-2030) | V (F3, F5) |
| 16 | 75 | LFT (F3) | 1970 | — | Trabajo en descanso obligatorio | Menores: prohibido (178) | Salario doble adicional; quién trabaja se acuerda (si no hay convenio, resuelve el Tribunal) | `holiday_work_multiplier = 2.00` como adicional | Unit | V (F3) |
| 17 | 76, 78 | LFT (F4) | 2022-12-27 | 2023-01-01 | Más de 1 año de servicios | Discontinuos y de temporada: proporcional (77) | 12 días el 1.er año, +2 hasta 20 (5.º año), luego +2 por cada 5 años; ≥12 días continuos a elección del trabajador | `vacation_table` (desde el 2023-01-01); el último tramo abierto (51+ años = 40) es una aproximación: para más de 55 años aplicar la fórmula | Unit: años 1, 5, 6, 10, 11, 31, 46 | V (F3, F4, F11) |
| 18 | 79, 81 | LFT (F3) | 1970 | — | General | — | No compensables en dinero salvo terminación (proporcional); otorgarse dentro de los 6 meses siguientes; constancia anual de antigüedad | Alerta de vencimiento a 6 meses; generar constancia anual | Feature | V (F3) |
| 19 | 80 | LFT (F3) | 1970 | — | General | — | Prima vacacional ≥25 % | `vacation_premium_min_percent = 25.00` | Unit | V (F3) |
| 20 | 22, 22 Bis, 23 | LFT (F3) | 2015 | — | Menores | — | Prohibido trabajar a menores de 15; de 15 a 16 años requiere autorización; menores de 18 sin educación básica sólo con aprobación de la autoridad | `minors.min_working_age = 15`; bloquear el alta de menores de 15; exigir documento de autorización (15-16) | Feature: fecha de nacimiento < 15 años → rechazo | V (F3) |
| 21 | 174, 175, 176 | LFT (F3) | 2012/2015 | — | Menores de 18 | 175 Bis: actividades artísticas de menores de 15 bajo reglas | Certificado médico; prohibido después de las 22:00 en establecimientos no industriales; labores nocturnas industriales o después de las 22:00 son peligrosas | `night_work_allowed_under_18 = false` (conservador); bloquear turnos de menores que crucen las 22:00 | Feature | V (F3); CJ para la franja 20-22 h |
| 22 | 177 | LFT (F3) | 1970 | — | Menores de 16 | — | ≤6 h diarias en periodos de ≤3 h con reposo ≥1 h | `max_daily_minutes_under_16 = 360` + regla de periodos | Unit | V (F3) |
| 23 | 178, 179, 180 | LFT (F3) | 2015 | — | Menores de 18 | — | Sin horas extra, domingos ni descansos obligatorios (si ocurre: extra +200 %); vacaciones ≥18 días; registros especiales (nombre, nacimiento, clase de trabajo, horario, salario) | `overtime_allowed_under_18 = false`; reporte "menores" para la autoridad | Feature: rechazar asignación en domingo o festivo | V (F3) |
| 24 | 132 fr. XXXIV | LFT (F2) | 2026-05-01 | Ley 2026-05-01; disposiciones STPS 2027-01-01 | Según disposiciones STPS (pendientes) | Las definirá la STPS | Registro electrónico de inicio y fin por trabajador; entregarlo a la autoridad; prueba plena si fue **acordado** | Marcajes append-only con sello de tiempo del servidor, zona horaria, dispositivo y hash encadenado; exportación para autoridad (CSV/PDF firmado); flujo de **aceptación del trabajador** del registro o corte periódico (para la prueba plena) | Feature: exportación íntegra; intento de UPDATE rechazado | V (texto legal); requisitos técnicos **NV** (disposiciones STPS no localizadas al 2026-11-17) |
| 25 | 994 fr. IV Bis | LFT (F2) | 2026-05-01 | 2026-05-01 (exigible con disposiciones de 2027) | Empleador obligado | — | Multa de 250 a 5,000 UMA | Panel de cumplimiento con faltantes de registro | — | V (F3) |
| 26 | 804, 805 | LFT (F3) | 1980/2012 | — | Juicio | — | Conservar controles de asistencia *"durante el último año y un año después de que se extinga la relación laboral"*; la omisión presume ciertos los hechos del actor | `retention.attendance_records_years = 5` (ver fila 27); bloqueo de borrado | Feature: un trabajador dado de baja hace menos de 1 año no es purgable | V (F3) |
| 27 | LSS 15 fr. II; CFF 30 | LSS, CFF (F8, F9) | — | — | Patrones | — | Registros con días trabajados: 5 años (LSS); documentación fiscal: 5 años (CFF) | Retención = el plazo mayor entre 5 años y 1 año después de la baja | Unit de la política de purga | V (F8, F9); CJ sobre la aplicabilidad directa al registro de asistencia |
| 28 | 516, 518 | LFT (F3) | 1970 | — | Prescripción | — | Acciones: 1 año; despido: 2 meses | Mantener el bloqueo mientras exista un juicio | — | V (F3) |
| 29 | 330-A a 330-K | LFT (F3) | 2021-01-11 | 2021-01-12 | Más del 40 % del tiempo en el domicilio del trabajador | Trabajo ocasional o esporádico | Contrato con horarios y mecanismos de supervisión; derecho a la desconexión (330-E VI); supervisión proporcional y respetuosa de la intimidad; cámaras y micrófonos sólo de forma extraordinaria (330-I); protección de datos | Modalidad "teletrabajo" por trabajador; sin geolocalización continua; no activar la cámara salvo verificación puntual con consentimiento; bloquear notificaciones fuera de jornada | Feature: no se envían avisos fuera de horario | V (F3) |
| 30 | NOM-037-STPS-2023 | NOM (F10) | 2023-06-08 | 180 días después (STPS indica 2023-12-05) | Teletrabajo >40 % | — | Lista actualizada de teletrabajadores; política escrita con la desconexión | Reporte de teletrabajadores; texto de política configurable | — | Publicación V (sidof); contenido detallado NV en esta sesión |
| 31 | NOM-035-STPS-2018 | NOM | 2018-10-23 | 2019-10-23 / 2020-10-23 (por etapas) | Todos los centros de trabajo | — | Las jornadas extensas son un factor de riesgo psicosocial que se identifica y evalúa | Reporte opcional de jornadas extensas (insumo para la NOM-035) | — | NV en esta sesión (no se descargó el DOF) |
| 32 | LFPDPPP 7, 8, 15, 16 | LFPDPPP (F7) | 2025-03-20 | 2025-03-21 | Datos de colaboradores | Art. 9 fr. I y IV: sin consentimiento para obligaciones legales o derivadas de la relación jurídica | Aviso de privacidad; datos sensibles con consentimiento **expreso y por escrito** (firma autógrafa, electrónica o mecanismo de autenticación) | Ver `docs/seguridad/PRIVACIDAD_DATOS.md` | Feature: sin consentimiento firmado no se activa la verificación facial | V (F7) |
| 33 | LFPDPPP 2 fr. XV, 38-59 | LFPDPPP (F7) | 2025-03-20 | 2025-03-21 | — | — | Autoridad: **Secretaría Anticorrupción y Buen Gobierno** (sustituye al INAI); multas de hasta 320,000 UMA, que pueden duplicarse si hay datos sensibles | Registro de solicitudes ARCO con plazos de 20 + 15 días | — | V (F7) |

## 3. Notas de cálculo derivadas

- **Fecha civil:** todos los topes se evalúan con la fecha civil de la zona horaria del centro de trabajo (regla 4 de AGENTS.md). Una jornada que cruza la medianoche se atribuye al día de inicio (propuesta, CJ).
- **Semana:** la ley no define si es semana calendario, semana de nómina o ventana móvil de 7 días. Propuesta: semana configurable por organización (por defecto lunes a domingo ISO) y reportar también la ventana móvil. **CJ.**
- **Orden de imputación:** minutos sobre el tope diario o el semanal pasan a extra; dentro de la extra, los primeros `weekly_max_minutes` se pagan al doble, los siguientes (hasta 240) al triple, y más allá hay una violación que se debe bloquear o alertar. Si un minuto excede ambos topes se cuenta una sola vez.
- **Semana que cruza el 1 de enero:** se aplica a cada día la versión vigente en su fecha y el tope semanal se prorratea por días; **CJ** (alternativa: aplicar la versión del día en que termina la semana).

## 4. Requiere revisión jurídica

1. **Horas extra en 2026 (1-may a 31-dic):** el nuevo art. 66 (4 h/día en 4 días) ya está vigente, pero el transitorio Cuarto fija 9 h y el transitorio Tercero llama a 2026 periodo de "ajuste". El JSON aplica 240 min/4 días con `needs_legal_review`; la alternativa conservadora es 180 min/3 días hasta el 31-dic-2026.
2. **Constitución frente a LFT en horas extra:** la fr. XI (12 h, en vigor desde el 03-03-2026) no tiene calendario gradual; la LFT limita a 9-11 h hasta 2029. Que la ley sea más restrictiva para el patrón suele ser compatible, pero conviene un dictamen.
3. **Art. 784 fr. VIII** sigue diciendo "nueve horas semanales" (no se armonizó): define la carga probatoria del excedente.
4. **Registro electrónico:** ámbito, excepciones y requisitos técnicos dependen de disposiciones STPS **no publicadas** al corte; también qué significa "acordado" para que el registro haga prueba plena.
5. **Topes semanales menores que 6 × 8 h** (desde 2027): cómo se combina la distribución pactada (art. 58) con el tope diario del art. 61.
6. **Prima dominical y descanso** cuando un turno nocturno cruza a domingo.
7. **Menores de 16 a 18 entre 20:00 y 22:00:** el JSON lo prohíbe por prudencia.
8. **Retención de 5 años** del registro de asistencia (analogía con LSS y CFF) frente al mínimo de 1 año después de la baja (LFT 804) y el principio de minimización (LFPDPPP art. 12).
9. **Biometría facial como dato sensible:** la LFPDPPP no menciona la biometría expresamente (su lista de sensibles es enunciativa). Se recomienda tratarla como sensible.
10. **Semana de cómputo** y atribución de jornadas que cruzan la medianoche.

## 5. Limitaciones

- Los textos se leyeron en el DOF, el SIDOF y los compilados de la Cámara de Diputados al 2026-11-17. No se revisaron jurisprudencia de la SCJN, criterios de tribunales laborales, contratos-ley ni reglamentos interiores.
- No se descargó el texto anterior de los arts. 61, 66 y 68 (versión `LFT-PRE-2026`). Se reconstruyó de los decretos y del art. 784 fr. VIII; por eso queda como `unverified`.
- Las disposiciones STPS del art. 132 fr. XXXIV no se localizaron. Puede que se publiquen entre el corte y el 1-ene-2027: revisar el DOF de diciembre de 2026.
- La FAQ de la STPS (F6) es anterior al decreto LFT y no es vinculante; se usa sólo como contexto.
- No se verificó si existe un nuevo Reglamento de la LFPDPPP. Las fuentes secundarias indican que no se ha publicado y que el de 2011 se usa como referencia: **NV**.
- No se verificó el texto de la NOM-035 en esta sesión. De la NOM-037 sólo se verificó la publicación.
- Los trabajos especiales (Título Sexto: transporte, hogar, campo, etc.) pueden tener reglas propias de jornada que este JSON no modela.

## 6. Estado de implementación (24-nov-2026, Revisión RH)

| Fila | Tema | Implementado | Pendiente |
|---|---|---|---|
| 4, 6, 9, 10 | Jornada ordinaria, máximos diarios, tiempo extra y triples por año | Tabla versionada `legal_versions` (cambio automático el 1 de enero) y `WeeklyOvertimeCalculator` por colaborador y semana | Validación institucional («Verificada por RH»); criterio sobre 4 h/4 días en 2026 |
| 12 | Descanso semanal | Alerta `no_weekly_rest` | — |
| 15 | Días de descanso obligatorio | Precarga anual `asistencias:festivos` | Jornada electoral: se captura como día de empresa |
| 20–23 | Menores de edad | Bloqueo de autorización de tiempo extra y alertas `minor_overtime`, `minor_night_work`, `minor_daily_limit`; edad mínima validada en la importación CSV | Fecha de nacimiento opcional en el alta manual |
| 24 | Registro electrónico (art. 132 fr. XXXIV) | Marcajes append-only con hora del servidor, del dispositivo y de sincronización; sin marcajes automáticos; convenio de registro firmado en la app; rectificaciones con evidencia y firma del colaborador; reporte para inspección con verificación de integridad | Disposiciones de la STPS (no publicadas al corte); texto del convenio por RH y Jurídico |
| 26, 27 | Conservación | Sin borrado físico de registros de asistencia; referencia de 5 años | Plazo definitivo por Jurídico |
| 32, 33 | LFPDPPP | Aviso de privacidad en PDF firmado en la app; ubicación sólo al marcar o durante la jornada según el puesto; depuración de recorridos | Correo del responsable de datos, texto del aviso, plazo de conservación de recorridos |
