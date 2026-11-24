<?php

namespace App\Domain\Integrations\People;

use App\Domain\Audit\AuditLogger;
use App\Domain\Vacations\VacationService;
use App\Enums\ApprovalStatus;
use App\Jobs\RecomputeWorkday;
use App\Models\Employee;
use App\Models\Incidence;
use App\Models\IncidenceType;
use App\Models\IntegrationRun;
use App\Models\User;
use App\Models\VacationRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recepción idempotente de incidencias, permisos, incapacidades y vacaciones
 * APROBADOS en Mr. Lana People (origen único; evita doble captura).
 *
 *  - Identificador externo persistente: (organization_id, external_source=people, external_id).
 *    Un registro ya recibido no se duplica; si People lo cancela, aquí se cancela con motivo.
 *  - Los errores por registro (colaborador o tipo desconocido, fechas inválidas) se
 *    guardan en `integration_runs.conflicts`; la última sincronización exitosa
 *    define el `since` de la siguiente.
 *  - Sin credenciales no se ejecuta nada: estado «sin configurar».
 *
 * Debe ejecutarse dentro del contexto de la organización activa.
 */
final class PeopleIncidenceReceiver
{
    public const RUN_SOURCE = 'people_incidences';

    public const EXTERNAL_SOURCE = 'people';

    /** @var array<string, int> */
    private array $stats = [];

    /** @var list<array{external_id:?string, error:string}> */
    private array $issues = [];

    public function __construct(
        private readonly VacationService $vacations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{configured:bool, last_success_at:?string, last_run_status:?string}
     */
    public function status(PeopleIncidenceSource $source): array
    {
        $last = IntegrationRun::query()->where('source', self::RUN_SOURCE)->latest('started_at')->first();
        $success = IntegrationRun::query()->where('source', self::RUN_SOURCE)->whereIn('status', ['completed', 'partial'])
            ->latest('started_at')->value('finished_at');

        return [
            'configured' => $source->isConfigured(),
            'last_success_at' => $success ? CarbonImmutable::parse($success)->toIso8601String() : null,
            'last_run_status' => $last?->status,
        ];
    }

    /**
     * Ejecuta la recepción. Devuelve null si People no está configurado.
     */
    public function run(PeopleIncidenceSource $source, ?User $by = null): ?IntegrationRun
    {
        if (! $source->isConfigured()) {
            return null;
        }

        $this->stats = ['received' => 0, 'created' => 0, 'cancelled' => 0, 'unchanged' => 0, 'errors' => 0];
        $this->issues = [];
        $startedAt = now();
        $status = 'completed';

        $since = IntegrationRun::query()->where('source', self::RUN_SOURCE)->whereIn('status', ['completed', 'partial'])
            ->latest('started_at')->value('started_at');

        try {
            foreach ($source->approvedSince($since ? CarbonImmutable::parse($since)->subDay()->toDateString() : null) as $row) {
                $this->stats['received']++;
                try {
                    DB::transaction(fn () => $this->receive($row));
                } catch (PeopleRecordException $e) {
                    $this->issue($row, $e->getMessage());
                } catch (Throwable $e) {
                    report($e);
                    $this->issue($row, 'Error al registrar: '.class_basename($e));
                }
            }
        } catch (PeopleSourceException $e) {
            $status = 'failed';
            $this->issues[] = ['external_id' => null, 'error' => $e->getMessage()];
        }

        if ($status !== 'failed' && $this->stats['errors'] > 0) {
            $status = 'partial';
        }

        $run = IntegrationRun::query()->create([
            'source' => self::RUN_SOURCE,
            'kind' => 'import',
            'status' => $status,
            'stats' => $this->stats,
            'conflicts' => $this->issues,
            'started_by' => $by?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
        $this->audit->log('integration.people_incidences', $run, "Incidencias de People: {$this->stats['created']} nuevas, {$this->stats['errors']} con error", $this->stats);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function receive(array $row): void
    {
        $externalId = trim((string) ($row['id'] ?? ''));
        if ($externalId === '') {
            throw new PeopleRecordException('Registro sin identificador.');
        }
        $cancelled = ($row['status'] ?? 'approved') === 'cancelled';
        $kind = ($row['kind'] ?? 'incidence') === 'vacation' ? 'vacation' : 'incidence';
        $model = $kind === 'vacation' ? VacationRequest::class : Incidence::class;

        /** @var Incidence|VacationRequest|null $existing */
        $existing = $model::query()->where('external_source', self::EXTERNAL_SOURCE)->where('external_id', $externalId)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($cancelled && $existing->status !== ApprovalStatus::Cancelled) {
                $existing->forceFill(['status' => ApprovalStatus::Cancelled, 'external_synced_at' => now()]
                    + ($existing instanceof Incidence ? ['cancelled_at' => now(), 'cancellation_reason' => 'Cancelada en Mr. Lana People'] : ['review_comment' => 'Cancelada en Mr. Lana People']))->save();
                $this->recompute($existing);
                $this->audit->log('people.cancelled', $existing, 'Cancelada en Mr. Lana People');
                $this->stats['cancelled']++;
            } else {
                $existing->forceFill(['external_synced_at' => now()])->save();
                $this->stats['unchanged']++;
            }

            return;
        }
        if ($cancelled) {
            $this->stats['unchanged']++;

            return;
        }

        $employee = $this->employee($row);
        [$from, $to] = $this->period($row);

        try {
            $created = $kind === 'vacation'
                ? $this->createVacation($employee, $from, $to, $externalId, $row)
                : $this->createIncidence($employee, $from, $to, $externalId, $row);
        } catch (UniqueConstraintViolationException) {
            $this->stats['unchanged']++;

            return;
        }

        $this->recompute($created);
        $this->audit->log('people.received', $created, 'Recibida de Mr. Lana People (autorizada)', ['external_id' => $externalId]);
        $this->stats['created']++;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createIncidence(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, string $externalId, array $row): Incidence
    {
        $code = strtoupper(trim((string) ($row['type_code'] ?? '')));
        $type = IncidenceType::query()->where('active', true)
            ->where(fn ($q) => $q->where('external_code', $code)->orWhere('code', $code))
            ->whereNull('omission_punch_type')
            ->first() ?? throw new PeopleRecordException("Tipo de incidencia «{$code}» sin equivalencia en el catálogo.");

        $folio = isset($row['folio']) ? strtoupper(trim((string) $row['folio'])) : null;
        if ($type->requires_folio && blank($folio)) {
            throw new PeopleRecordException('Incapacidad sin folio IMSS.');
        }

        return Incidence::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'incidence_type_id' => $type->id,
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
            'folio' => $folio,
            'source' => 'people',
            'external_source' => self::EXTERNAL_SOURCE,
            'external_id' => $externalId,
            'external_synced_at' => now(),
            'status' => ApprovalStatus::Approved,
            'reviewed_at' => now(),
            'review_comment' => 'Autorizada en Mr. Lana People',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createVacation(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, string $externalId, array $row): VacationRequest
    {
        $employment = $employee->employmentOn($from) ?? throw new PeopleRecordException('El colaborador no tiene relación laboral vigente en esas fechas.');
        $balance = $this->vacations->balance($employee, $from);

        return VacationRequest::query()->create([
            'organization_id' => $employee->organization_id,
            'employee_id' => $employee->id,
            'employment_id' => $employment->id,
            'service_year' => max(1, (int) $balance['service_year']),
            'starts_on' => $from->toDateString(),
            'ends_on' => $to->toDateString(),
            'days' => max(1, $this->vacations->countWorkingDays($employee, $from, $to)),
            'comment' => isset($row['reason']) ? (string) $row['reason'] : 'Autorizada en Mr. Lana People',
            'external_source' => self::EXTERNAL_SOURCE,
            'external_id' => $externalId,
            'external_synced_at' => now(),
            'status' => ApprovalStatus::Approved,
            'reviewed_at' => now(),
            'review_comment' => 'Autorizada en Mr. Lana People',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function employee(array $row): Employee
    {
        $externalId = trim((string) ($row['employee_external_id'] ?? ''));
        $number = trim((string) ($row['employee_number'] ?? ''));

        $employee = $externalId !== ''
            ? Employee::query()->where('external_source', self::EXTERNAL_SOURCE)->where('external_id', $externalId)->first()
            : null;
        $employee ??= $number !== '' ? Employee::query()->where('employee_number', $number)->first() : null;

        return $employee ?? throw new PeopleRecordException('Colaborador no encontrado ('.($externalId ?: $number ?: 'sin identificador').').');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(array $row): array
    {
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($row['starts_on'] ?? ''));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($row['ends_on'] ?? $row['starts_on'] ?? ''));
        } catch (Throwable) {
            throw new PeopleRecordException('Fechas inválidas.');
        }
        if (! $from || ! $to || $to->lt($from) || $from->diffInDays($to) > 366) {
            throw new PeopleRecordException('Periodo inválido.');
        }

        return [$from, $to];
    }

    private function recompute(Incidence|VacationRequest $model): void
    {
        foreach (CarbonPeriod::create($model->starts_on, $model->ends_on) as $day) {
            RecomputeWorkday::dispatch($model->organization_id, $model->employee_id, $day->toDateString())->afterCommit();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function issue(array $row, string $error): void
    {
        $this->stats['errors']++;
        $this->issues[] = ['external_id' => isset($row['id']) ? (string) $row['id'] : null, 'error' => $error];
    }
}
