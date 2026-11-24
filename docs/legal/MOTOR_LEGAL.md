# Motor legal y normativa versionada

Toda regla laboral vive en el backend (`Web/app/Domain/Legal`, `Web/app/Domain/Compliance`). La web y la app sólo muestran resultados. Fundamento jurídico y fuentes primarias: [Matriz de cumplimiento](MATRIZ_CUMPLIMIENTO.md). Esto no es asesoría jurídica.

## Tabla normativa (fuente única)

`legal_versions` guarda una versión por tramo, con parámetros en JSON y `checksum`. Se siembran desde `Web/database/data/legal/parametros_legales.json` con `LegalFrameworkSeeder` (lo ejecuta `asistencias:instalar` o `php artisan db:seed --force`). El seeder no sobrescribe ediciones de RH.

| Versión | Vigencia | Jornada ordinaria semanal | Tiempo extra semanal | Extra diario / días | Triples | Jornada total diaria |
|---|---|---|---|---|---|---|
| LFT-PRE-2026 | 2023-01-01 → 2026-04-30 | 48 h | — (texto anterior reconstruido, sin verificar) | — | — | — |
| LFT-2026 | 2026-05-01 → 2026-12-31 | 48 h | 9 h | 4 h / 4 días (requiere criterio jurídico) | hasta 4 h | 12 h |
| LFT-2027 | 2027 | 46 h | 9 h | 4 h / 4 días | hasta 4 h | 12 h |
| LFT-2028 | 2028 | 44 h | 10 h | 4 h / 4 días | hasta 4 h | 12 h |
| LFT-2029 | 2029 | 42 h | 11 h | 4 h / 4 días | hasta 4 h | 12 h |
| LFT-2030 | 2030 → | 40 h | 12 h | 4 h / 4 días | hasta 4 h | 12 h |

Comunes a todas: máximo diario 8 h diurna, 7 h nocturna y 7 h 30 min mixta; ventana nocturna 20:00–06:00; mixta si el tramo nocturno dura menos de 3 h 30 min (`mixed_max_night_minutes = 209`); descanso mínimo 30 min; 1 día de descanso por cada 6; prima dominical mínima 25 %; descanso o festivo laborado al doble adicional; prima vacacional mínima 25 %. Menores: edad mínima 15 años, máximo 6 h diarias si son menores de 16, sin trabajo nocturno ni tiempo extra si son menores de 18.

### Resolución por fecha

`LegalFramework::for($fecha)` devuelve la versión vigente en la fecha civil del centro de trabajo. El cambio del 1 de enero de cada tramo es automático: no hay que desplegar nada. El cálculo semanal usa la versión vigente al **inicio** de la semana (lunes).

### Estado de verificación

Hay dos niveles distintos:

1. **Investigación con fuente** (`verification_status` del JSON): el texto se leyó en el DOF o en la Cámara de Diputados. Es información técnica, no una validación institucional.
2. **«Verificada por RH»**: sólo cuando un usuario con `settings.legal_manage` la marca. Se guardan `verified_by` y `verified_at` reales. La migración `2026_11_24_130000` regresó a «Sin verificar» toda versión sin usuario y fecha. Cualquier edición posterior la regresa a «Sin verificar».

La leyenda de Horas extra y Cumplimiento sale de `LegalFramework::verificationFor($fecha)`: «Normativa en verificación» o «Verificada por RH».

### Edición (sólo RH)

**Configuración › Normativa** (`settings.legal_manage`):

- Sólo versiones vigentes o futuras; las concluidas no se editan.
- Cada cambio queda en `legal_version_revisions` (append-only, con antes y después) y en la bitácora.
- Consulta para otros roles con `settings.legal`.

Pruebas: `Web/tests/Feature/Web/Admin/LegalVersionsTest.php`, `Web/tests/Feature/Domain/LegalEngineTest.php`.

## Cálculos

| Pieza | Qué hace |
|---|---|
| `ShiftClassifier` | Clasifica la jornada en diurna, nocturna o mixta (art. 60). |
| `WorkdayCalculator` | Proyección diaria (`workday_summaries`): trabajado, descansos, retardo, salida anticipada, omisiones. **Sin hora de corte**: todo el tiempo registrado cuenta. Un descanso no registrado no se descuenta (art. 64). |
| `WeeklyOvertimeCalculator` | Tiempo extra por colaborador y semana (lunes a domingo). Es la fuente única para Panel, Horas extra, Cumplimiento, Reportes y NOI. |
| `ComplianceEvaluator` | Alertas legales por día y semana. |
| `HolidayGenerator` | Días de descanso obligatorio del art. 74 por reglas (`fixed`, `nth_weekday`, `every_six_years`, `manual`). |
| `VacationService` | Saldos con la tabla del art. 76 (desde 2023) y validación de prestaciones. |

### Imputación del tiempo extra (`WeeklyOvertimeCalculator`)

1. Todo minuto posterior al horario es «tiempo extra registrado» y queda **pendiente** hasta que se autoriza o se justifica. Nunca se descarta.
2. **Dobles**: hasta 4 h por día, en máximo 4 días y hasta el tope semanal de la versión.
3. **Triples**: lo que rebasa el tope semanal, hasta 4 h por semana.
4. **Sobre límite legal**: lo que rebasa cualquier tope (diario, días por semana, triples o jornada total de 12 h).
5. **Menores de 18** (fecha de nacimiento del expediente): todo minuto extra es «sobre límite» y, si se laboró, se paga al triple (art. 178). La salida real siempre se registra; lo que se bloquea es la **autorización** (`OvertimeAuthorizer`), y se crea la alerta `minor_overtime`.
6. Un turno nocturno que cruza la medianoche (y el año) se atribuye al día de inicio.

La autorización cubre primero las dobles y después las triples. Se usa aritmética entera de minutos.

Pruebas: `Web/tests/Feature/Domain/WeeklyOvertimeTest.php`, `NoAutomaticPunchesTest.php`.

## Alertas de cumplimiento

| Código | Regla | Origen |
|---|---|---|
| `daily_total_exceeded` | Jornada total diaria superior al máximo (12 h) | `ComplianceEvaluator` |
| `weekly_ordinary_exceeded` | Jornada ordinaria semanal superior al tope | `ComplianceEvaluator` |
| `overtime_near_limit` / `overtime_over_limit` | Tiempo extra ≥ 80 % del tope o por encima | `ComplianceEvaluator` |
| `minor_overtime` | Menor de 18 con tiempo extra | `ComplianceEvaluator` |
| `minor_night_work` | Menor de 18 con minutos nocturnos | `ComplianceEvaluator` |
| `minor_daily_limit` | Menor de 16 con más de 6 h | `ComplianceEvaluator` |
| `no_weekly_rest` | 7 días trabajados en la semana | `ComplianceEvaluator` |
| `rest_day_worked` | Descanso laborado (informativa) | `ComplianceEvaluator` |
| `unrectified_omission` | Omisión sin rectificar más de 2 días | `cumplimiento:omisiones` (07:00) |
| `clock_skew` | Diferencia de hora del dispositivo > 10 min | `ClockSkewMonitor` |
| `mock_location`, `device_tampered` | Ubicación simulada o teléfono alterado | `DeviceIntegrityMonitor` |
| `integrity_failed`, `integrity_anchor_failed` | Falla de verificación o de anclaje | `IntegrityMonitor` |

Detalle del módulo: [Integridad y conservación](../cumplimiento/INTEGRIDAD_Y_CONSERVACION.md).

## Días de descanso obligatorio (art. 74)

- `asistencias:festivos [--anio=AAAA]` precarga el año en curso y el siguiente. Es idempotente y está programado el día 1 de cada mes a las 00:30.
- Los días oficiales son globales y de sólo lectura en la web. RH agrega días de empresa o locales (por centro de trabajo) en **Configuración › Festivos**, y los desactiva o reactiva.
- El plazo de firma de rectificaciones en días hábiles excluye fines de semana y estos días.

## Menores de edad

| Regla | Implementación | Limitación |
|---|---|---|
| Sin tiempo extra (CPEUM 123-A fr. XI; LFT 178) | Bloqueo de autorización y alerta crítica | Depende de que el expediente tenga la fecha de nacimiento. |
| Edad mínima 15 años (art. 22) | Validada en la importación CSV (`EmployeeImportValidator`, fecha obligatoria) | En el alta y la edición manual la fecha de nacimiento es opcional y no se valida la edad mínima. |
| Trabajo nocturno y jornada de menores de 16 | Alertas `minor_night_work` y `minor_daily_limit` | Son alertas: no bloquean el horario. |

## Pendientes jurídicos

- Validar la tabla 2027 y dar seguimiento a las disposiciones de la STPS del registro electrónico (art. 132 fr. XXXIV; vigentes desde el 1 de enero de 2027, no publicadas al corte).
- Horas extra en 2026 (4 h/4 días frente a la lectura conservadora 3 h/3 días).
- Semana de cómputo, semanas que cruzan el 1 de enero y prima dominical en turnos que cruzan a domingo.
- Plazo de conservación definitivo (hoy 5 años como referencia; art. 804 exige al menos un año después de la baja).

Lista completa: [Matriz de cumplimiento §4](MATRIZ_CUMPLIMIENTO.md#4-requiere-revisión-jurídica).
