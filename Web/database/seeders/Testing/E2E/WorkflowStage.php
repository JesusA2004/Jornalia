<?php

namespace Database\Seeders\Testing\E2E;

use App\Domain\Attendance\DeviceSignature;
use App\Domain\Attendance\EffectiveTimeline;
use App\Domain\Attendance\RectificationService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Compliance\IntegrityMonitor;
use App\Domain\Incidences\IncidenceService;
use App\Domain\Notifications\AnnouncementService;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Rectifications\RectificationSettings;
use App\Domain\Signatures\ElectronicSignatureService;
use App\Domain\Vacations\VacationService;
use App\Enums\PunchType;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\Punch;
use App\Models\RectificationRequest;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Flujos de RH sobre los marcajes: rectificaciones en cada etapa (con evidencia
 * y firma electrónica real por NIP o biometría), incidencias del catálogo en
 * todos sus estados, vacaciones, cierre de nómina (extemporáneas), comunicados
 * y anclaje diario de integridad. `prepare()` ajusta el plan de marcajes para que
 * cada flujo tenga su caso real (omisión, retardo, tiempo extra, ausencia…).
 */
final class WorkflowStage
{
    /** @var array<string, RectificationRequest> */
    private array $rects = [];

    /** @var array<string, Incidence> */
    private array $incidences = [];

    /** @var array<string, VacationRequest> */
    private array $vacations = [];

    /** @var array<string, Announcement> */
    private array $announcements = [];

    public function __construct(
        private readonly RectificationService $rectifications,
        private readonly RectificationSettings $settings,
        private readonly IncidenceService $incidenceService,
        private readonly VacationService $vacationService,
        private readonly AnnouncementService $announcementService,
        private readonly IntegrityMonitor $integrity,
        private readonly AuditLogger $audit,
    ) {}

    // ── Plan de marcajes ────────────────────────────────────────────────────

    public function prepare(Context $ctx): void
    {
        $d = fn (int $n) => $ctx->workday($n);
        $mf = fn (int $n) => $ctx->workday($n, true);

        // Rectificaciones (omisiones y retardos reales).
        foreach ([[5, $d(1)], [22, $mf(2)], [2, $d(5)], [3, $d(10)], [9, $d(17)], [1, $d(4)], [10, $d(6)], [4, $d(8)], [6, $mf(10)], [3, $d(14)], [1, $d(13)], [4, $d(15)], [10, $d(16)]] as [$i, $day]) {
            $ctx->plan($i, $day, ['no_out' => true]);
        }
        $ctx->plan(27, $d(2), ['late' => '09:41']);
        $ctx->plan(7, $d(9), ['late' => '09:52']);
        $ctx->plan(5, $d(11), ['keep' => true]);

        // Omisiones sin rectificar (Panel «Sin salida» y alerta de omisión sin rectificar).
        foreach ([[12, $d(1)], [35, $d(1)], [18, $d(8)], [31, $d(10)]] as [$i, $day]) {
            $ctx->plan($i, $day, ['no_out' => true]);
        }

        // Ausencias justificadas (incidencias y vacaciones).
        foreach ([[3, $d(6)], [7, $d(5)], [7, $d(4)], [7, $d(3)], [9, $d(2)], [9, $d(1)], [12, $d(7)]] as [$i, $day]) {
            $ctx->plan($i, $day, ['absent' => true]);
        }
        foreach ($this->incidenceRange($ctx, 36) as $day) {
            $ctx->plan(36, $day, ['absent' => true]);
        }
        foreach ($this->weekDays($ctx, 2, 5, true) as $day) {
            $ctx->plan(11, $day, ['absent' => true]);
        }

        // Hoy: ausentes y un retardo.
        foreach ([25, 37] as $i) {
            $ctx->plan($i, $ctx->today, ['absent' => true]);
        }
        $ctx->plan(21, $ctx->today, ['late' => '09:27']);

        // Tiempo extra: dobles y triples, rechazado, sobre límite y menor de edad.
        foreach ($this->weekDays($ctx, 2, 4) as $day) {
            $ctx->plan(Roster::OVERTIME_HEAVY, $day, ['overtime' => 170]);
        }
        foreach ([$d(6), $d(5)] as $day) {
            $ctx->plan(Roster::OVERTIME_MODERATE, $day, ['overtime' => 60]);
        }
        $ctx->plan(Roster::OVERTIME_REJECTED, $this->weekDays($ctx, 2, 2)[1], ['overtime' => 90]);
        foreach ($this->weekDays($ctx, 3, 5) as $day) {
            $ctx->plan(Roster::OVERTIME_OVER_LIMIT, $day, ['overtime' => 60]);
        }
        $ctx->plan(Roster::MINOR, $mf(4), ['overtime' => 45]);

        // Descanso laborado (domingo de la semana 2).
        $ctx->plan(Roster::REST_DAY_WORKER, $ctx->start->addDays(13), ['work' => true]);

        // Marcajes especiales: ubicación simulada, reloj alterado, sin conexión y fuera de geocerca.
        $ctx->special(Roster::MOCK_LOCATION, $mf(5), 'clock_in', ['mocked' => true, 'fixture' => 'punch_ubicacion_simulada']);
        $ctx->special(Roster::CLOCK_ALTERED, $d(6), 'clock_in', ['skew' => 840, 'fixture' => 'punch_reloj_alterado']);
        foreach ($ctx->days() as $day) {
            if (($day->isTuesday() || $day->isThursday()) && $day->lt($ctx->today)) {
                $ctx->special(Roster::OFFLINE_FIELD, $day, 'clock_out', ['offline_delay' => Context::vary('off|'.$day->toDateString(), 4, 8)]);
            }
        }
        $lateSync = $d(4);
        $next = $d(3);
        $ctx->special(Roster::OFFLINE_FIELD, $lateSync, 'clock_out', [
            'offline_delay' => (int) $ctx->at($lateSync, '18:00')->diffInMinutes($ctx->at($next, '08:40')),
            'fixture' => 'punch_sincronizacion_tardia',
        ]);
        $ctx->special(Roster::OFFLINE_FIELD, $d(9), 'clock_in', ['offline_delay' => 6, 'far' => 2500, 'fixture' => 'punch_fuera_geocerca']);
        $ctx->special(35, $d(3), 'clock_in', ['offline_delay' => 3]);
        $ctx->special(10, $d(2), 'clock_out', ['offline_delay' => 4]);
    }

    // ── Línea de tiempo de flujos ───────────────────────────────────────────

    public function schedule(Context $ctx): void
    {
        $d = fn (int $n) => $ctx->workday($n);
        $mf = fn (int $n) => $ctx->workday($n, true);
        $golive = $ctx->start->subDays(7);

        // Anclaje externo + verificación automática diaria (03:30, como el programador).
        for ($day = $ctx->start->addDay(); $day->lte($ctx->today); $day = $day->addDay()) {
            $ctx->schedule($ctx->at($day, '03:30'), 'Integridad diaria', function () use ($ctx) {
                $ctx->actAs(null);
                $this->integrity->daily($ctx->organization);
            }, 1);
        }

        // Cierre de nómina capturado por RH: lo anterior es extemporáneo.
        $closedThrough = $d(13);
        $ctx->fixtures['payroll_closed_through'] = $closedThrough->toDateString();
        $ctx->schedule($ctx->at($d(11), '09:00'), 'Cierre de nómina', function () use ($ctx, $closedThrough) {
            $ctx->actAs($ctx->user('rh'));
            $before = $this->settings->all();
            $data = ['business_days' => RectificationSettings::DEFAULT_BUSINESS_DAYS, 'confirmed_by_hr' => false, 'closed_through' => $closedThrough->toDateString()];
            $this->settings->save($data, $ctx->user('rh')->id);
            $this->audit->log('rectifications.settings', null, 'Parámetros de rectificaciones actualizados', ['before' => $before, 'after' => $data]);
        });

        $this->rectificationFlows($ctx, $d, $mf);
        $this->incidenceFlows($ctx, $d, $mf);
        $this->vacationFlows($ctx, $d, $golive);
        $this->announcementFlows($ctx, $d);
    }

    /**
     * @param  \Closure(int): CarbonImmutable  $d
     * @param  \Closure(int): CarbonImmutable  $mf
     */
    private function rectificationFlows(Context $ctx, \Closure $d, \Closure $mf): void
    {
        // R1 · Por autorizar (el colaborador desde la app, con foto).
        $this->submitMissing($ctx, 'rect_por_autorizar', 5, $d(1), '18:07', $ctx->at($d(1), '21:10'), 'Olvidé registrar mi salida; cerré caja con el gerente a las 18:07.');

        // R2 · Por autorizar (RH a nombre del colaborador; aclaración de hora de entrada).
        $ctx->schedule($ctx->clampPast($ctx->at($d(1), '10:15')), 'R2', function () use ($ctx, $d) {
            $ctx->actAs($ctx->user('rh'));
            $punch = $this->punchOf($ctx, 27, $d(2), PunchType::ClockIn);
            $this->rects['rect_por_autorizar_rh'] = $this->rectifications->submit($ctx->user('rh'), $ctx->employee(27), [
                'date' => $d(2)->toDateString(),
                'punch_uuid' => $punch->uuid,
                'kind' => 'time_clarification',
                'proposed_type' => null,
                'proposed_time' => $ctx->at($d(2), '08:57')->toIso8601String(),
                'reason' => 'La app no abrió al llegar; Sistemas confirmó la falla. Entrada real 08:57.',
            ], [Files::pdf('constancia-sistemas.pdf', 'Constancia de falla de la app', ['Reporte de Sistemas'])], 'manager');
            $ctx->fixtures['rect_por_autorizar_rh'] = $this->rects['rect_por_autorizar_rh']->id;
        });

        // R3 · Por autorizar, originada por la incidencia «Omisión de checada · salida».
        $ctx->schedule($ctx->clampPast($ctx->at($d(1), '12:00')), 'R3 (incidencia de omisión)', function () use ($ctx, $mf) {
            $user = $ctx->employeeUser(22);
            $ctx->actAs($user);
            $incidence = $this->incidenceService->submit($user, $ctx->employee(22), [
                'incidence_type_id' => $this->typeId('OMISION_SALIDA'),
                'starts_on' => $mf(2)->toDateString(),
                'ends_on' => $mf(2)->toDateString(),
                'reason' => 'Se descargó el teléfono antes de salir.',
                'proposed_time' => '17:04',
            ], [Files::png('foto-bitacora.png', 'Bitácora de salida de la sucursal')]);
            $ctx->fixtures['incidence_omision'] = $incidence->id;
            $ctx->fixtures['rect_por_autorizar_incidencia'] = (int) $incidence->rectification_request_id;
        });

        // R4 · Por firmar con plazo próximo.
        $this->submitMissing($ctx, 'rect_por_firmar', 2, $d(5), '18:04', $ctx->at($d(5), '20:30'), 'Salí a las 18:04 y la app no registró la salida.');
        $authorizeAt = $this->authorizationForSoonDue($ctx);
        $this->authorize($ctx, 'rect_por_firmar', 'gerente', $authorizeAt, true, 'Confirmado con la bitácora de cierre.');

        // R5 · Por firmar con plazo vencido (el proceso programado aún no lo rechaza).
        $this->submitMissing($ctx, 'rect_por_firmar_vencido', 3, $d(10), '18:02', $ctx->at($d(10), '20:00'), 'Olvidé marcar la salida al terminar el arqueo.');
        $this->authorize($ctx, 'rect_por_firmar_vencido', 'gerente', $ctx->at($d(9), '11:00'), true, null);

        // R6 · Vencida: rechazada automáticamente por plazo de firma.
        $this->submitMissing($ctx, 'rect_vencida', 9, $d(17), '22:03', $ctx->at($d(17), '23:30'), 'Salí a las 22:03; el teléfono no tenía señal.');
        $r6Authorized = $ctx->at($d(16), '10:00');
        $this->authorize($ctx, 'rect_vencida', 'gerente', $r6Authorized, true, null);
        $due = $this->settings->signatureDueAt($ctx->organization, $r6Authorized);
        $ctx->schedule($due->addMinutes(25), 'Vencer rectificaciones', function () use ($ctx) {
            $ctx->actAs(null);
            $ctx->count('rectificaciones_vencidas', $this->rectifications->expireOverdue());
        });

        // R7 · Por validar RH (firmada con NIP).
        $this->submitMissing($ctx, 'rect_por_validar', 1, $d(4), '18:06', $ctx->at($d(4), '19:00'), 'Registré la salida en el teléfono pero no se envió.');
        $this->authorize($ctx, 'rect_por_validar', 'gerente', $ctx->at($d(3), '09:30'), true, null);
        $this->sign($ctx, 'rect_por_validar', 1, $ctx->at($d(3), '13:00'), 'pin');

        // R8 · Por validar RH (firmada con biometría del teléfono).
        $this->submitMissing($ctx, 'rect_por_validar_biometrica', 10, $d(6), '18:10', $ctx->at($d(6), '21:00'), 'Terminé la ruta de cobranza a las 18:10 sin señal.');
        $this->authorize($ctx, 'rect_por_validar_biometrica', 'gerente', $ctx->at($d(5), '09:00'), true, 'Ruta verificada en el reporte de visitas.');
        $this->sign($ctx, 'rect_por_validar_biometrica', 10, $ctx->at($d(5), '12:00'), 'biometric');

        // R9 · Aplicada (alta de la salida omitida).
        $this->submitMissing($ctx, 'rect_aplicada', 4, $d(8), '18:05', $ctx->at($d(8), '20:00'), 'Olvidé marcar la salida al regresar de campo.');
        $this->authorize($ctx, 'rect_aplicada', 'gerente', $ctx->at($d(7), '10:00'), true, null);
        $this->sign($ctx, 'rect_aplicada', 4, $ctx->at($d(7), '18:00'), 'pin');
        $this->validate($ctx, 'rect_aplicada', 'rh', $ctx->at($d(6), '10:30'), true, 'Validado con la bitácora de la sucursal.');

        // R10 · Aplicada (aclaración de hora de entrada: se reconoce 08:58).
        $ctx->schedule($ctx->at($d(9), '12:00'), 'R10', function () use ($ctx, $d) {
            $user = $ctx->employeeUser(7);
            $ctx->actAs($user);
            $punch = $this->punchOf($ctx, 7, $d(9), PunchType::ClockIn);
            $this->rects['rect_aplicada_aclaracion'] = $this->rectifications->submit($user, $ctx->employee(7), [
                'date' => $d(9)->toDateString(),
                'punch_uuid' => $punch->uuid,
                'kind' => 'time_clarification',
                'proposed_type' => null,
                'proposed_time' => $ctx->at($d(9), '08:58')->toIso8601String(),
                'reason' => 'Llegué 08:58; la app tardó en sincronizar la entrada.',
            ], [Files::png('captura-app.png', 'Captura de la app sin conexión')]);
            $ctx->fixtures['rect_aplicada_aclaracion'] = $this->rects['rect_aplicada_aclaracion']->id;
        });
        $this->authorize($ctx, 'rect_aplicada_aclaracion', 'gerente', $ctx->at($d(9), '16:00'), true, null);
        $this->sign($ctx, 'rect_aplicada_aclaracion', 7, $ctx->at($d(8), '09:00'), 'pin');
        $this->validate($ctx, 'rect_aplicada_aclaracion', 'rh', $ctx->at($d(8), '12:00'), true, null);

        // R11 · Rechazada en autorización (el jefe no la reconoce).
        $ctx->schedule($ctx->at($d(11), '20:00'), 'R11', function () use ($ctx, $d) {
            $user = $ctx->employeeUser(5);
            $ctx->actAs($user);
            $punch = $this->punchOf($ctx, 5, $d(11), PunchType::ClockOut);
            $this->rects['rect_rechazada_autorizacion'] = $this->rectifications->submit($user, $ctx->employee(5), [
                'date' => $d(11)->toDateString(),
                'punch_uuid' => $punch->uuid,
                'kind' => 'time_clarification',
                'proposed_type' => null,
                'proposed_time' => EffectiveTimeline::punchTime($punch)->addHours(2)->toIso8601String(),
                'reason' => 'Me quedé dos horas más para terminar expedientes.',
            ], [Files::png('foto-expedientes.png', 'Expedientes pendientes')]);
            $ctx->fixtures['rect_rechazada_autorizacion'] = $this->rects['rect_rechazada_autorizacion']->id;
        });
        $this->authorize($ctx, 'rect_rechazada_autorizacion', 'gerente', $ctx->at($d(10), '10:00'), false, 'La sucursal cerró a las 18:15; no hay registro de actividad posterior.');

        // R12 · Rechazada en validación RH.
        $this->submitMissing($ctx, 'rect_rechazada_validacion', 6, $mf(10), '17:03', $ctx->at($mf(10), '20:00'), 'No registré la salida por una junta.');
        $this->authorize($ctx, 'rect_rechazada_validacion', 'gerente', $ctx->at($mf(9), '10:00'), true, null);
        $this->sign($ctx, 'rect_rechazada_validacion', 6, $ctx->at($mf(9), '15:00'), 'pin');
        $this->validate($ctx, 'rect_rechazada_validacion', 'rh', $ctx->at($mf(8), '11:00'), false, 'La hora propuesta no coincide con el cierre del sistema de la sucursal.');

        // R13 · Rechazada: el colaborador no aceptó firmar.
        $this->submitMissing($ctx, 'rect_declinada', 3, $d(14), '18:00', $ctx->at($d(14), '21:00'), 'Olvidé registrar la salida.');
        $this->authorize($ctx, 'rect_declinada', 'gerente', $ctx->at($d(13), '10:00'), true, null);
        $ctx->schedule($ctx->at($d(12), '09:15'), 'R13 declinar', function () use ($ctx) {
            $user = $ctx->employeeUser(3);
            $ctx->actAs($user);
            $this->rectifications->decline($user, $this->rects['rect_declinada']->refresh(), 'No reconozco la hora propuesta; salí más tarde.');
        });

        // R14 · Cancelada por quien la solicitó.
        $this->submitMissing($ctx, 'rect_cancelada', 1, $d(13), '18:03', $ctx->at($d(13), '20:00'), 'Olvidé registrar la salida.');
        $ctx->schedule($ctx->at($d(12), '08:00'), 'R14 cancelar', function () use ($ctx) {
            $user = $ctx->employeeUser(1);
            $ctx->actAs($user);
            $this->rectifications->cancel($user, $this->rects['rect_cancelada']->refresh());
        });

        // R15 · Aplicada extemporánea (fecha de nómina cerrada; RH con permiso especial).
        $this->submitMissing($ctx, 'rect_aplicada_extemporanea', 4, $d(15), '18:02', $ctx->at($d(9), '19:00'), 'No había notado la omisión hasta revisar mi recibo.');
        $this->authorize($ctx, 'rect_aplicada_extemporanea', 'gerente', $ctx->at($d(8), '10:30'), true, null);
        $this->sign($ctx, 'rect_aplicada_extemporanea', 4, $ctx->at($d(7), '18:20'), 'pin');
        $this->validate($ctx, 'rect_aplicada_extemporanea', 'rh', $ctx->at($d(6), '10:45'), true, 'Aplicada después del cierre; se ajusta en la siguiente nómina.');

        // R16 · Por validar extemporánea.
        $this->submitMissing($ctx, 'rect_por_validar_extemporanea', 10, $d(16), '18:08', $ctx->at($d(7), '19:00'), 'Omití la salida; la ruta terminó a las 18:08.');
        $this->authorize($ctx, 'rect_por_validar_extemporanea', 'gerente', $ctx->at($d(6), '10:00'), true, null);
        $this->sign($ctx, 'rect_por_validar_extemporanea', 10, $ctx->at($d(5), '11:00'), 'pin');
    }

    /**
     * @param  \Closure(int): CarbonImmutable  $d
     * @param  \Closure(int): CarbonImmutable  $mf
     */
    private function incidenceFlows(Context $ctx, \Closure $d, \Closure $mf): void
    {
        $longReason = 'Fallecimiento de mi abuela materna el fin de semana. Debo viajar a Tlaxcala para los servicios funerarios y acompañar a mi madre durante el novenario, por lo que solicito los días que marca la política interna de la empresa. '
            .'Adjunto el acta de defunción expedida por el Registro Civil. Dejo cubierta mi caja con mi compañera del turno matutino, a quien entregué el fondo fijo contado y firmado frente al gerente; también dejé en el sistema las notas de los clientes que tienen pagos programados para esta semana y el teléfono de contacto de cada uno. '
            .'En caso de requerir algún dato adicional estaré disponible por teléfono a partir de las 19:00 h. Agradezco de antemano la atención y quedo atenta a cualquier indicación de Recursos Humanos para completar el trámite a mi regreso.';

        // I1 · Pendiente del jefe inmediato (fecha futura).
        $this->submitIncidence($ctx, 'incidence_pendiente', 5, 'PERMISO_CON_GOCE', $ctx->today->addDays(3), $ctx->today->addDays(3), $ctx->at($d(1), '18:30'), 'Trámite de escrituras en notaría.', Files::pdf('formato-permiso.pdf', 'Formato de permiso autorizado', ['Permiso con goce de sueldo']));

        // I2 · Aprobada por el jefe, pendiente de RH.
        $this->submitIncidence($ctx, 'incidence_pendiente_rh', 3, 'FALTA_JUSTIFICADA', $d(6), $d(6), $ctx->at($d(5), '09:00'), 'Cita médica de mi hijo en el IMSS.', Files::png('constancia-imss.png', 'Constancia de cita médica'));
        $this->decideIncidence($ctx, 'incidence_pendiente_rh', 'gerente', $ctx->at($d(4), '10:00'), true, 'Constancia revisada.');

        // I3 · Aprobada (jefe y RH): incapacidad con folio IMSS.
        $this->submitIncidence($ctx, 'incidence_aprobada', 7, 'INCAPACIDAD_EG', $d(5), $d(3), $ctx->at($d(5), '08:00'), 'Incapacidad por infección respiratoria.', Files::pdf('incapacidad-imss.pdf', 'Certificado de incapacidad IMSS', ['Folio QX482913', 'Días: 3']), 'QX482913');
        $this->decideIncidence($ctx, 'incidence_aprobada', 'gerente', $ctx->at($d(5), '12:00'), true, null);
        $this->decideIncidence($ctx, 'incidence_aprobada', 'rh', $ctx->at($d(4), '09:00'), true, 'Certificado validado.');

        // I4 · Rechazada por el jefe.
        $this->submitIncidence($ctx, 'incidence_rechazada', 6, 'PERMISO_SIN_GOCE', $ctx->today->addDays(5), $ctx->today->addDays(5), $ctx->at($d(2), '10:00'), 'Asunto personal.', Files::pdf('solicitud-permiso.pdf', 'Formato de permiso', ['Sin goce de sueldo']));
        $this->decideIncidence($ctx, 'incidence_rechazada', 'gerente', $ctx->clampPast($ctx->at($d(1), '11:00')), false, 'Coincide con el inventario de fin de mes; reprograma la fecha.');

        // I5 · Cancelada por RH con motivo.
        $this->submitIncidence($ctx, 'incidence_cancelada', 2, 'PERMISO_CON_GOCE', $ctx->today->addDays(7), $ctx->today->addDays(7), $ctx->at($d(3), '17:00'), 'Trámite en el SAT.', Files::pdf('formato-permiso-2.pdf', 'Formato de permiso autorizado', ['Permiso con goce de sueldo']));
        $ctx->schedule($ctx->clampPast($ctx->at($d(1), '16:00')), 'I5 cancelar', function () use ($ctx) {
            $ctx->actAs($ctx->user('rh'));
            $this->incidenceService->cancel($ctx->user('rh'), $this->incidences['incidence_cancelada']->refresh(), 'El colaborador ya no requiere el permiso; lo confirmó por teléfono.');
        });

        // I6 · Pendiente con observaciones muy largas.
        $this->submitIncidence($ctx, 'incidence_observaciones_largas', 9, 'LUTO', $d(2), $d(1), $ctx->at($d(2), '08:00'), $longReason, Files::pdf('acta-defuncion.pdf', 'Acta de defunción (prueba E2E)', ['Registro Civil']));

        // I8 · Aprobada (RH y nómina): incapacidad por riesgo de trabajo.
        $range = $this->incidenceRange($ctx, 36);
        $this->submitIncidence($ctx, 'incidence_riesgo_trabajo', 36, 'INCAPACIDAD_RT', $range[0], $range[count($range) - 1], $ctx->at($range[0], '08:30'), 'Torcedura de tobillo en la bodega de la sucursal.', Files::pdf('incapacidad-rt.pdf', 'Certificado de incapacidad IMSS', ['Riesgo de trabajo', 'Folio RT771045']), 'RT771045');
        $this->decideIncidence($ctx, 'incidence_riesgo_trabajo', 'rh', $ctx->at($range[0], '13:00'), true, null);
        $this->decideIncidence($ctx, 'incidence_riesgo_trabajo', 'rh_nomina', $ctx->at($range[0], '17:00'), true, 'Folio verificado.');

        // I9 · Aprobada: permiso con goce de un día pasado.
        $this->submitIncidence($ctx, 'incidence_permiso_aprobado', 12, 'PERMISO_CON_GOCE', $d(7), $d(7), $ctx->at($d(8), '10:00'), 'Trámite de credencial para votar.', Files::pdf('formato-permiso-3.pdf', 'Formato de permiso autorizado', ['Permiso con goce de sueldo']));
        $this->decideIncidence($ctx, 'incidence_permiso_aprobado', 'rh', $ctx->at($d(8), '12:00'), true, null);
        $this->decideIncidence($ctx, 'incidence_permiso_aprobado', 'rh_nomina', $ctx->at($d(8), '16:00'), true, null);
    }

    private function vacationFlows(Context $ctx, \Closure $d, CarbonImmutable $golive): void
    {
        $week = $this->weekDays($ctx, 2, 5, true);
        $this->requestVacation($ctx, 'vacation_aprobada', 11, $week[0], $week[count($week) - 1], $ctx->at($golive->addDay(), '11:00'), 'Vacaciones familiares.');
        $this->decideVacation($ctx, 'vacation_aprobada', 'rh', $ctx->at($golive->addDay(), '15:30'), true, null);

        $this->requestVacation($ctx, 'vacation_pendiente', 5, $ctx->today->addDays(20), $ctx->today->addDays(24), $ctx->at($d(1), '19:00'), 'Viaje de fin de año.');

        $this->requestVacation($ctx, 'vacation_aprobada_futura', 27, $ctx->today->addDays(27), $ctx->today->addDays(29), $ctx->at($d(3), '10:00'), null);
        $this->decideVacation($ctx, 'vacation_aprobada_futura', 'rh', $ctx->at($d(2), '12:00'), true, null);

        $this->requestVacation($ctx, 'vacation_rechazada', 2, $ctx->today->addDays(10), $ctx->today->addDays(11), $ctx->at($d(4), '10:00'), null);
        $this->decideVacation($ctx, 'vacation_rechazada', 'gerente', $ctx->at($d(3), '12:00'), false, 'Cierre de mes en sucursal; elige otra fecha.');
    }

    private function announcementFlows(Context $ctx, \Closure $d): void
    {
        $long = "Les recordamos que el cierre de la quincena es este viernes a las 14:00 h. Antes de esa hora:\n\n"
            ."1. Revisen en la app que todas sus entradas y salidas estén registradas. Si falta alguna, soliciten la rectificación con su evidencia (foto, constancia o documento) y fírmenla en cuanto su jefe la autorice; las solicitudes sin firma no se aplican.\n"
            ."2. Las incapacidades del IMSS deben registrarse con su folio y el certificado escaneado. Sin folio no es posible validarlas.\n"
            ."3. Los permisos con y sin goce de sueldo requieren el formato autorizado por el jefe inmediato.\n"
            ."4. El tiempo extra registrado queda pendiente hasta que su jefe lo autorice o lo justifique; no es necesario hacer ningún trámite adicional.\n\n"
            .'Después del cierre, cualquier corrección se considera extemporánea y se aplica en la siguiente nómina. Para dudas, escriban a Capital Humano por el canal de siempre o acudan con su gerente de sucursal. Gracias por su apoyo para cerrar a tiempo.';

        $this->announce($ctx, 'announcement_importante', $ctx->at($d(3), '09:00'), [
            'title' => 'Confirma la lectura: horario de fin de año',
            'body' => 'Del 24 al 31 de diciembre las sucursales abren de 09:00 a 15:00 h. Confirma que leíste este aviso.',
            'priority' => 'high', 'audience_type' => 'all', 'audience_ids' => [], 'scheduled_at' => null, 'requires_confirmation' => true,
        ]);
        foreach ([1, 2, 3, 4, 5, 6, 10, 11, 12, 19, 21, 27, 34] as $k => $i) {
            $ctx->schedule($ctx->at($d(3), '10:00')->addMinutes($k * 23), "Confirmar lectura {$i}", function () use ($ctx, $i) {
                $user = $ctx->employeeUser($i);
                $ctx->actAs($user);
                $this->announcementService->confirm($this->announcements['announcement_importante']->refresh(), $user);
            });
        }

        $this->announce($ctx, 'announcement_largo', $ctx->at($d(6), '10:00'), [
            'title' => 'Recordatorio de cierre de quincena y entrega de incidencias',
            'body' => $long,
            'priority' => 'normal', 'audience_type' => 'all', 'audience_ids' => [], 'scheduled_at' => null, 'requires_confirmation' => false,
        ]);

        $this->announce($ctx, 'announcement_sucursal', $ctx->at($d(10), '08:30'), [
            'title' => 'Simulacro de evacuación en sucursal',
            'body' => 'El jueves a las 11:00 h habrá simulacro. Sigan las indicaciones del brigadista.',
            'priority' => 'high', 'audience_type' => 'branches', 'audience_ids' => [$ctx->branches['VER']->id], 'scheduled_at' => null, 'requires_confirmation' => false,
        ]);
    }

    // ── Ayudantes de rectificación ──────────────────────────────────────────

    private function submitMissing(Context $ctx, string $key, int $index, CarbonImmutable $day, string $time, CarbonImmutable $at, string $reason): void
    {
        $ctx->schedule($at, "Solicitud {$key}", function () use ($ctx, $key, $index, $day, $time, $reason) {
            $user = $ctx->employeeUser($index);
            $ctx->actAs($user);
            $this->rects[$key] = $this->rectifications->submit($user, $ctx->employee($index), [
                'date' => $day->toDateString(),
                'punch_uuid' => null,
                'kind' => 'missing_punch',
                'proposed_type' => 'clock_out',
                'proposed_time' => $ctx->at($day, $time)->toIso8601String(),
                'reason' => $reason,
            ], [Files::png('foto-'.str_replace('_', '-', $key).'.png', $reason)]);
            $ctx->fixtures[$key] = $this->rects[$key]->id;
        });
    }

    private function authorize(Context $ctx, string $key, string $approver, CarbonImmutable $at, bool $approve, ?string $comment): void
    {
        $ctx->schedule($at, "Autorizar {$key}", function () use ($ctx, $key, $approver, $approve, $comment) {
            $user = $ctx->user($approver);
            $ctx->actAs($user);
            $this->rectifications->authorize($user, $this->rect($key), $approve, $comment);
        });
    }

    private function sign(Context $ctx, string $key, int $index, CarbonImmutable $at, string $method): void
    {
        $ctx->schedule($at, "Firmar {$key}", function () use ($ctx, $key, $index, $method) {
            $user = $ctx->employeeUser($index);
            $ctx->actAs($user);
            $r = $this->rect($key);
            if ($method === 'biometric') {
                $phone = $ctx->devices[$index] ?? throw new RuntimeException("Sin teléfono para firmar {$key}.");
                $canonical = ElectronicSignatureService::biometricCanonical('rectification', (int) $r->id, $this->rectifications->payloadSha256($r), (string) $phone['device']->device_uuid);
                $this->rectifications->sign($user, $r, 'biometric', null, $phone['device']->refresh(), null, DeviceSignature::sign($phone['secret'], $canonical));
            } else {
                $this->rectifications->sign($user, $r, 'pin', $ctx->pin, $ctx->devices[$index]['device'] ?? null);
            }
            $ctx->count('firmas_rectificaciones');
        });
    }

    private function validate(Context $ctx, string $key, string $validator, CarbonImmutable $at, bool $approve, ?string $comment): void
    {
        $ctx->schedule($at, "Validar {$key}", function () use ($ctx, $key, $validator, $approve, $comment) {
            $user = $ctx->user($validator);
            $ctx->actAs($user);
            $this->rectifications->validate($user, $this->rect($key), $approve, $comment);
        });
    }

    private function rect(string $key): RectificationRequest
    {
        return ($this->rects[$key] ?? throw new RuntimeException("Rectificación {$key} no creada."))->refresh();
    }

    /**
     * Autorización cuyo plazo de firma vence lo antes posible sin haber vencido aún.
     */
    private function authorizationForSoonDue(Context $ctx): CarbonImmutable
    {
        $choice = $ctx->clampPast($ctx->at($ctx->workday(1), '10:00'), 30);
        for ($n = 1; $n <= 6; $n++) {
            $candidate = $ctx->at($ctx->workday($n), '10:00');
            if ($candidate->gt($ctx->realNow)) {
                continue;
            }
            $due = $this->settings->signatureDueAt($ctx->organization, $candidate);
            if ($due->gt($ctx->realNow->addHours(2)) && $candidate->gt($ctx->at($ctx->workday(5), '20:30'))) {
                $choice = $candidate;
            }
        }

        return $choice;
    }

    private function punchOf(Context $ctx, int $index, CarbonImmutable $day, PunchType $type): Punch
    {
        return Punch::query()->where('employee_id', $ctx->employee($index)->id)
            ->whereDate('local_date', $day->toDateString())->where('type', $type->value)
            ->orderBy('server_time')->first()
            ?? throw new RuntimeException("No existe el marcaje {$type->value} del colaborador {$index} el {$day->toDateString()}.");
    }

    // ── Ayudantes de incidencias, vacaciones y comunicados ─────────────────

    private function submitIncidence(Context $ctx, string $key, int $index, string $code, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $at, string $reason, UploadedFile $evidence, ?string $folio = null): void
    {
        $ctx->schedule($ctx->clampPast($at), "Incidencia {$key}", function () use ($ctx, $key, $index, $code, $from, $to, $reason, $evidence, $folio) {
            $user = $ctx->employeeUser($index);
            $ctx->actAs($user);
            $this->incidences[$key] = $this->incidenceService->submit($user, $ctx->employee($index), [
                'incidence_type_id' => $this->typeId($code),
                'starts_on' => $from->toDateString(),
                'ends_on' => $to->toDateString(),
                'reason' => $reason,
                'folio' => $folio,
            ], [$evidence]);
            $ctx->fixtures[$key] = $this->incidences[$key]->id;
        });
    }

    private function decideIncidence(Context $ctx, string $key, string $reviewer, CarbonImmutable $at, bool $approve, ?string $comment): void
    {
        $ctx->schedule($at, "Resolver {$key}", function () use ($ctx, $key, $reviewer, $approve, $comment) {
            $user = $ctx->user($reviewer);
            $ctx->actAs($user);
            $this->incidenceService->decide($user, $this->incidences[$key]->refresh(), $approve, $comment);
        });
    }

    private function requestVacation(Context $ctx, string $key, int $index, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $at, ?string $comment): void
    {
        $ctx->schedule($at, "Vacaciones {$key}", function () use ($ctx, $key, $index, $from, $to, $comment) {
            $user = $ctx->employeeUser($index);
            $ctx->actAs($user);
            $this->vacations[$key] = $this->vacationService->request($user, $ctx->employee($index), $from->toDateString(), $to->toDateString(), $comment);
            $ctx->fixtures[$key] = $this->vacations[$key]->id;
        });
    }

    private function decideVacation(Context $ctx, string $key, string $reviewer, CarbonImmutable $at, bool $approve, ?string $comment): void
    {
        $ctx->schedule($at, "Resolver {$key}", function () use ($ctx, $key, $reviewer, $approve, $comment) {
            $user = $ctx->user($reviewer);
            $ctx->actAs($user);
            $this->vacationService->decide($user, $this->vacations[$key]->refresh(), $approve, $comment);
        });
    }

    /**
     * @param  array{title:string, body:string, priority:string, audience_type:string, audience_ids:list<int>, scheduled_at:?CarbonImmutable, requires_confirmation?:bool}  $data
     */
    private function announce(Context $ctx, string $key, CarbonImmutable $at, array $data): void
    {
        $ctx->schedule($at, "Comunicado {$key}", function () use ($ctx, $key, $data) {
            $ctx->actAs($ctx->user('rh'));
            $this->announcements[$key] = $this->announcementService->create($ctx->user('rh'), $data);
            $ctx->fixtures[$key] = $this->announcements[$key]->id;
        });
    }

    private function typeId(string $code): int
    {
        return (int) (IncidenceType::query()->where('code', $code)->value('id') ?? throw new RuntimeException("Tipo de incidencia {$code} inexistente."));
    }

    /**
     * Días (lunes a sábado sin festivos; o lunes a viernes) de la semana N del periodo (1 = la del inicio).
     *
     * @return list<CarbonImmutable>
     */
    public function weekDays(Context $ctx, int $week, int $count, bool $mondayToFriday = false): array
    {
        $monday = $ctx->today->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(4 - $week);
        $days = [];
        for ($day = $monday; count($days) < $count && $day->lt($monday->addDays(7)); $day = $day->addDay()) {
            if ($day->isSunday() || ($mondayToFriday && $day->isSaturday()) || $ctx->isHoliday($day)) {
                continue;
            }
            $days[] = $day;
        }

        return $days;
    }

    /**
     * Incapacidad por riesgo de trabajo: lunes y martes de la semana 2.
     *
     * @return list<CarbonImmutable>
     */
    public function incidenceRange(Context $ctx, int $index): array
    {
        return array_slice($this->weekDays($ctx, 2, 2), 0, 2);
    }
}
