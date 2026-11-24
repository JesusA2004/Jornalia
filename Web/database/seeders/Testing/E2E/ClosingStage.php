<?php

namespace Database\Seeders\Testing\E2E;

use App\Domain\Attendance\WorkdayCalculator;
use App\Domain\Audit\AuditLogger;
use App\Domain\Compliance\IntegrityMonitor;
use App\Domain\Compliance\OmissionMonitor;
use App\Domain\Notifications\AnnouncementService;
use App\Domain\Scheduling\ScheduleChangeService;
use App\Domain\Security\UserAccessService;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Http\Controllers\Web\Operacion\Support\OvertimeAuthorizer;
use App\Models\ComplianceAlert;
use App\Models\OvertimeRecord;
use App\Models\Punch;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Cierre del entorno E2E con el reloj real: recálculo completo de jornadas
 * (ausencias, omisiones, tiempo extra y alertas, como `asistencias:cerrar-dia`),
 * decisiones de tiempo extra, cambios de horario programados, comunicado
 * programado, atención de alertas, omisiones sin rectificar, revisión trimestral
 * de accesos, ubicación en tiempo real de hoy y anclaje/verificación de integridad.
 */
final class ClosingStage
{
    public function __construct(
        private readonly WorkdayCalculator $calculator,
        private readonly OvertimeAuthorizer $overtime,
        private readonly ScheduleChangeService $scheduleChanges,
        private readonly AnnouncementService $announcements,
        private readonly OmissionMonitor $omissions,
        private readonly UserAccessService $access,
        private readonly IntegrityMonitor $integrity,
        private readonly AuditLogger $audit,
        private readonly PeopleStage $people,
        private readonly AttendanceStage $attendance,
        private readonly WorkflowStage $workflows,
    ) {}

    public function run(Context $ctx): void
    {
        $ctx->backToPresent();
        $this->recompute($ctx);
        $this->overtimeDecisions($ctx);
        $this->scheduleChangeRequests($ctx);
        $this->scheduledAnnouncement($ctx);
        $this->alerts($ctx);
        $this->todayLocations($ctx);
        $this->integrityAndFixtures($ctx);
        $ctx->backToPresent();
    }

    /**
     * Recalcula cada jornada del periodo con la hora real (lo que haría el cierre diario).
     */
    private function recompute(Context $ctx): void
    {
        $ctx->actAs(null);
        $terminatedOn = $ctx->fixtures['terminated_on'] ?? '';
        $terminatedOn = is_string($terminatedOn) ? $terminatedOn : '';
        foreach (Roster::all() as $index => $person) {
            $employee = PeopleStage::fresh($ctx, $index);
            $hired = $this->people->date((string) $person['hired'], $ctx);
            foreach ($ctx->days() as $day) {
                $date = $day->toDateString();
                if ($day->lt($hired) || ($index === Roster::TERMINATED && $terminatedOn !== '' && $date > $terminatedOn)) {
                    continue;
                }
                $this->calculator->compute($employee, $date);
                $ctx->count('jornadas_recalculadas');
            }
        }
    }

    private function overtimeDecisions(Context $ctx): void
    {
        $record = fn (int $index, CarbonImmutable $day) => OvertimeRecord::query()
            ->where('employee_id', $ctx->employee($index)->id)->whereDate('date', $day->toDateString())->first();

        $week2 = $this->workflows->weekDays($ctx, 2, 4);
        $reviewAt = $ctx->at($this->workflows->weekDays($ctx, 3, 2)[1], '10:00');
        $decisions = [
            // [colaborador, día, revisor, aprobar, minutos (null = todo), justificación, fixture]
            [Roster::OVERTIME_HEAVY, $week2[0], 'rh', true, null, 'Cierre de colocación de créditos de fin de mes.', 'overtime_autorizado'],
            [Roster::OVERTIME_HEAVY, $week2[1], 'rh', true, null, 'Cierre de colocación de créditos de fin de mes.', null],
            [Roster::OVERTIME_HEAVY, $week2[2], 'direccion', true, 120, 'Se autorizan 2 h; el resto se compensa.', 'overtime_autorizado_parcial'],
            [Roster::OVERTIME_REJECTED, $this->workflows->weekDays($ctx, 2, 2)[1], 'direccion', false, null, 'Tiempo para cuadre de caja; se compensa con salida anticipada.', 'overtime_rechazado'],
            [Roster::OVERTIME_MODERATE, $ctx->workday(6), 'regional', true, null, 'Apertura especial por campaña de cobranza.', null],
            [Roster::OVERTIME_MODERATE, $ctx->workday(5), 'regional', true, null, 'Apertura especial por campaña de cobranza.', null],
        ];
        foreach ($decisions as $k => $decision) {
            [$index, $day, $reviewer, $approve, $minutes, $justification, $fixture] = $decision;
            $model = $record($index, $day);
            if ($model === null || $model->status !== ApprovalStatus::Pending) {
                $ctx->warn("Sin tiempo extra pendiente para {$index} el {$day->toDateString()}.");

                continue;
            }
            $at = $ctx->clampPast($reviewAt->max($ctx->at($day->addDay(), '10:00'))->addMinutes($k * 7), 30);
            $ctx->travel($at);
            $user = $ctx->user($reviewer);
            $ctx->actAs($user);
            $result = $this->overtime->decide($user, $model, $approve, $approve ? ($minutes ?? (int) $model->recorded_excess_minutes) : null, $justification);
            if ($fixture !== null) {
                $ctx->fixtures[$fixture] = $result->id;
            }
        }

        // Menor de edad: autorizarlo está prohibido (sólo puede justificarse); queda pendiente.
        $minor = OvertimeRecord::query()->where('employee_id', $ctx->employee(Roster::MINOR)->id)->where('status', ApprovalStatus::Pending->value)->orderBy('date')->first();
        if ($minor !== null) {
            $ctx->fixtures['overtime_menor'] = $minor->id;
            $ctx->travel($ctx->clampPast($ctx->at($ctx->workday(2), '12:00')));
            $ctx->actAs($ctx->user('rh'));
            try {
                $this->overtime->decide($ctx->user('rh'), $minor, true, (int) $minor->recorded_excess_minutes, 'Intento de autorización');
                $ctx->warn('Se autorizó tiempo extra a un menor de edad.');
            } catch (ValidationException) {
                $ctx->count('autorizacion_menor_bloqueada');
            }
        }
        $limit = OvertimeRecord::query()->where('employee_id', $ctx->employee(Roster::OVERTIME_OVER_LIMIT)->id)->where('over_limit_minutes', '>', 0)->first();
        $ctx->fixtures['overtime_sobre_limite'] = $limit->id ?? 0;
        $pending = OvertimeRecord::query()->where('employee_id', $ctx->employee(Roster::OVERTIME_HEAVY)->id)->where('status', ApprovalStatus::Pending->value)->first();
        $ctx->fixtures['overtime_pendiente'] = $pending->id ?? 0;
        $ctx->backToPresent();
    }

    private function scheduleChangeRequests(Context $ctx): void
    {
        $ctx->travel($ctx->realNow->subMinutes(50));
        $rh = $ctx->user('rh');
        $ctx->actAs($rh);
        $requests = [
            'schedule_change_pendiente' => [1, 2, ['is_rest_day' => false, 'starts_at' => '08:00', 'ends_at' => '16:00', 'break_minutes' => 60, 'reason_code' => 'special_opening', 'reason' => null]],
            'schedule_change_autorizado' => [3, 4, ['is_rest_day' => true, 'reason_code' => 'shift_swap', 'reason' => null]],
            'schedule_change_rechazado' => [6, 3, ['is_rest_day' => false, 'starts_at' => '09:00', 'ends_at' => '15:00', 'break_minutes' => 0, 'reason_code' => 'training', 'reason' => null]],
        ];
        $changes = [];
        foreach ($requests as $key => [$index, $days, $data]) {
            $changes[$key] = $this->scheduleChanges->request($rh, $ctx->employee($index), ['date' => $ctx->today->addDays($days)->toDateString(), ...$data]);
            $ctx->fixtures[$key] = $changes[$key]->id;
        }

        $ctx->travel($ctx->realNow->subMinutes(40));
        $approver = $ctx->user('rh_nomina');
        $ctx->actAs($approver);
        $authorized = $changes['schedule_change_autorizado'] ?? throw new \LogicException('Falta el cambio de horario por autorizar.');
        $rejected = $changes['schedule_change_rechazado'] ?? throw new \LogicException('Falta el cambio de horario por rechazar.');
        $this->scheduleChanges->approve($approver, $authorized->refresh());
        $this->scheduleChanges->reject($approver, $rejected->refresh(), 'Se reprograma la capacitación para enero.');
    }

    private function scheduledAnnouncement(Context $ctx): void
    {
        $ctx->travel($ctx->realNow->subMinutes(30));
        $ctx->actAs($ctx->user('rh'));
        $announcement = $this->announcements->create($ctx->user('rh'), [
            'title' => 'Capacitación de seguridad en sucursal',
            'body' => 'Capacitación obligatoria de 30 minutos al iniciar el turno. Lleva tu gafete.',
            'priority' => 'normal',
            'audience_type' => 'branches',
            'audience_ids' => [$ctx->branches['CUE']->id, $ctx->branches['JIU']->id],
            'scheduled_at' => $ctx->at($ctx->today->addDays(2), '09:00'),
            'requires_confirmation' => false,
        ]);
        $ctx->fixtures['announcement_programado'] = $announcement->id;
    }

    private function alerts(Context $ctx): void
    {
        // Rutina de omisiones sin rectificar (como `cumplimiento:omisiones`) y revisión trimestral.
        $ctx->travel($ctx->clampPast($ctx->at($ctx->today, '07:00'), 25));
        $ctx->actAs(null);
        $result = $this->omissions->evaluate($ctx->organization);
        $ctx->count('alertas_omision_sin_rectificar', $result['opened']);
        $this->access->openQuarterlyReview($ctx->organization);

        $ctx->travel($ctx->realNow->subMinutes(20));
        $rh = $ctx->user('rh');
        $ctx->actAs($rh);
        $resolutions = [
            [Roster::OFFLINE_FIELD, 'clock_skew', 'resolved', 'Sincronización tardía por falta de cobertura en la ruta; verificado con el colaborador.'],
            [Roster::MOCK_LOCATION, 'mock_location', 'acknowledged', 'Se citó a la colaboradora para revisar su teléfono.'],
            [Roster::REST_DAY_WORKER, 'rest_day_worked', 'resolved', 'Descanso laborado por inventario; se paga conforme al art. 73.'],
        ];
        foreach ($resolutions as [$index, $rule, $status, $note]) {
            $alert = ComplianceAlert::query()->where('employee_id', $ctx->employee($index)->id)->where('rule_code', $rule)->where('status', 'open')->orderBy('date')->first();
            if ($alert === null) {
                $ctx->warn("Sin alerta abierta {$rule} para {$index}.");

                continue;
            }
            $alert->update(['status' => $status, 'resolved_by' => $rh->id, 'resolved_at' => now(), 'resolution_note' => $note]);
            $this->audit->log('compliance.'.$status, $alert, ($status === 'resolved' ? 'Resolvió' : 'Atendió').' alerta de cumplimiento: '.$alert->rule_code, ['note' => $note]);
            $ctx->fixtures['alert_'.$rule] = $alert->id;
        }
    }

    /**
     * Ubicación de trabajo en tiempo real: colaboradores en campo con jornada abierta hoy.
     */
    private function todayLocations(Context $ctx): void
    {
        $ctx->backToPresent();
        foreach (Roster::TRACKED as $index) {
            $employeeId = $ctx->employee($index)->id;
            $in = Punch::query()->where('employee_id', $employeeId)->whereDate('local_date', $ctx->today->toDateString())->where('type', PunchType::ClockIn->value)->orderBy('server_time')->first();
            if ($in === null) {
                continue;
            }
            $out = Punch::query()->where('employee_id', $employeeId)->whereDate('local_date', $ctx->today->toDateString())->where('type', PunchType::ClockOut->value)->orderBy('server_time')->first();
            $until = $out->server_time ?? $ctx->realNow->subMinutes(1);
            $this->attendance->route($ctx, $index, $in->server_time, $until, 10);
        }
    }

    private function integrityAndFixtures(Context $ctx): void
    {
        $ctx->travel($ctx->realNow->subMinutes(5));
        $platform = $ctx->user('plataforma');
        $ctx->actAs($platform);
        $anchor = $this->integrity->anchor($ctx->organization, automatic: false);
        $verification = $this->integrity->verify($ctx->organization, automatic: false);
        $ctx->fixtures['integrity'] = [
            'valid' => $verification['valid'] ? 1 : 0,
            'anchors_created' => (int) $anchor['created'],
            'findings' => (int) $verification['findings'],
        ];

        // Último acceso de los usuarios web (la revisión de accesos marca a quien nunca entró).
        foreach ($ctx->users as $key => $user) {
            if ($key === 'autoridad') {
                continue;
            }
            $user->forceFill(['last_login_at' => $ctx->realNow->subHours(Context::vary("login|{$key}", 2, 40))])->saveQuietly();
        }

        $ids = fn (array $indexes) => array_values(array_map(fn (int $i) => $ctx->employee($i)->id, $indexes));
        $ctx->fixtures += [
            'employee_nombre_largo' => $ctx->employee(Roster::LONG_NAME)->id,
            'employee_menor' => $ctx->employee(Roster::MINOR)->id,
            'employee_baja' => $ctx->employee(Roster::TERMINATED)->id,
            'employee_kiosco' => $ctx->employee(13)->id,
            'employee_campo' => $ctx->employee(4)->id,
            'employees_alta_en_proceso' => $ids([Roster::NO_AGREEMENT, Roster::PENDING_DEVICE, Roster::NO_ACCOUNT]),
            'employee_convenio_papel' => $ctx->employee(Roster::PAPER_AGREEMENT)->id,
            'schedules' => array_map(fn ($s) => $s->id, $ctx->schedules),
            'branches' => array_map(fn ($b) => $b->id, $ctx->branches),
            'kiosks' => array_map(fn ($k) => $k->id, $ctx->kiosks),
            'device_pendiente' => (int) ($ctx->devices[Roster::PENDING_DEVICE]['device']->id ?? 0),
            'organization_principal' => $ctx->organization->id,
            'organization_vacia' => $ctx->emptyOrganization->id ?? 0,
        ];
        $ctx->backToPresent();
    }
}
