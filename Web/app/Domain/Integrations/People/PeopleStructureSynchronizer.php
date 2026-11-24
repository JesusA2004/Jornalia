<?php

namespace App\Domain\Integrations\People;

use App\Domain\Audit\AuditLogger;
use App\Domain\Workforce\WorkforceService;
use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IntegrationRun;
use App\Models\Position;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sincroniza desde People la estructura (departamentos, puestos, sucursales) y
 * las bajas, dentro de la organización activa.
 *
 *  - Idempotente: vincula por `external_source = people` + `external_id`; si un
 *    registro local con la misma clave aún no tiene vínculo, se vincula.
 *  - Información incompleta o inválida: no se crea nada; se reporta por registro.
 *  - Datos locales que People no gestiona (coordenadas, geocercas, centro de
 *    trabajo) nunca se sobrescriben.
 *  - Baja en People ⇒ WorkforceService::terminate (revoca acceso el mismo día).
 *  - Cada ejecución queda en `integration_runs` (source `people_structure`).
 */
final class PeopleStructureSynchronizer
{
    public const RUN_SOURCE = 'people_structure';

    public const EXTERNAL_SOURCE = 'people';

    private const CODE_PATTERN = '/^[A-Za-z0-9._-]{1,40}$/';

    /** @var array<string, int> */
    private array $stats = [];

    /** @var list<array<string, mixed>> */
    private array $issues = [];

    public function __construct(
        private readonly WorkforceService $workforce,
        private readonly AuditLogger $audit,
    ) {}

    public function run(User $by, PeopleStructureSource $source, bool $dryRun = false): IntegrationRun
    {
        $startedAt = now();
        $this->stats = ['created' => 0, 'updated' => 0, 'linked' => 0, 'unchanged' => 0, 'deactivated' => 0, 'terminated' => 0, 'errors' => 0];
        $this->issues = [];
        $status = 'completed';

        if (! $source->isConfigured()) {
            $status = 'failed';
            $this->issue('source', null, 'not_configured', 'La integración con People no está configurada (PEOPLE_API_URL / PEOPLE_API_TOKEN).');
        } else {
            $since = IntegrationRun::query()->where('source', self::RUN_SOURCE)->whereIn('status', ['completed', 'partial'])
                ->latest('started_at')->value('started_at');

            DB::beginTransaction();
            try {
                $this->syncDepartments($source);
                $this->syncPositions($source);
                $this->syncBranches($source);
                $this->syncTerminations($by, $source, $since ? CarbonImmutable::parse($since)->subDay()->toDateString() : null);
            } catch (PeopleSourceException $e) {
                $status = 'failed';
                $this->issue('source', null, 'source_error', $e->getMessage());
            } catch (Throwable $e) {
                DB::rollBack();
                report($e);
                throw $e;
            }

            if ($dryRun || $status === 'failed') {
                DB::rollBack();
            } else {
                DB::commit();
            }
        }

        $run = IntegrationRun::query()->create([
            'source' => self::RUN_SOURCE,
            'kind' => 'sync',
            'status' => $status === 'failed' ? 'failed' : ($dryRun ? 'simulated' : ($this->stats['errors'] > 0 ? 'partial' : 'completed')),
            'stats' => $this->stats + ['dry_run' => $dryRun],
            'conflicts' => array_slice($this->issues, 0, 500),
            'started_by' => $by->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $this->audit->log('integrations.people_structure_synced', $run, sprintf(
            '%s de estructura y bajas desde People: %d creados, %d actualizados, %d bajas, %d errores',
            $dryRun ? 'Simulación' : 'Sincronización', $this->stats['created'], $this->stats['updated'], $this->stats['terminated'], $this->stats['errors'],
        ), ['run_id' => $run->id, 'status' => $run->status] + $this->stats);

        return $run;
    }

    private function syncDepartments(PeopleStructureSource $source): void
    {
        $parents = [];
        foreach ($source->departments() as $row) {
            $model = $this->upsert(Department::class, 'departamento', $row, []);
            if ($model !== null && filled($row['parent_id'] ?? null)) {
                $parents[$model->getKey()] = (string) $row['parent_id'];
            }
        }

        // Segunda pasada: jerarquía (el superior puede venir después en la lista).
        foreach ($parents as $id => $parentExternal) {
            $parentId = $this->localId(Department::class, $parentExternal);
            if ($parentId === null || $parentId === $id) {
                $this->issue('departamento', $parentExternal, 'unknown_reference', "No existe el departamento superior «{$parentExternal}» en la sincronización.");

                continue;
            }
            $this->savepoint(fn () => Department::query()->whereKey($id)->update(['parent_id' => $parentId]));
        }
    }

    private function syncPositions(PeopleStructureSource $source): void
    {
        foreach ($source->positions() as $row) {
            $extra = [];
            if (filled($row['department_id'] ?? null)) {
                $departmentId = $this->localId(Department::class, (string) $row['department_id']);
                if ($departmentId === null) {
                    $this->issue('puesto', $this->str($row['id'] ?? null), 'unknown_reference', "No existe el departamento «{$row['department_id']}» del puesto.");

                    continue;
                }
                $extra['department_id'] = $departmentId;
            }
            $this->upsert(Position::class, 'puesto', $row, $extra);
        }
    }

    private function syncBranches(PeopleStructureSource $source): void
    {
        foreach ($source->branches() as $row) {
            $extra = array_filter([
                'region' => $this->str($row['region'] ?? null, 80),
                'address' => $this->str($row['address'] ?? null, 255),
            ], fn ($v) => $v !== null);
            $registration = $this->str($row['employer_registration'] ?? null);
            if ($registration !== null) {
                $registration = strtoupper($registration);
                if (preg_match('/^[A-Z0-9]{8,20}$/', $registration) !== 1) {
                    $this->issue('sucursal', $this->str($row['id'] ?? null), 'invalid', 'Registro patronal inválido; se conserva el valor local.');
                } else {
                    $extra['employer_registration'] = $registration;
                }
            }
            $this->upsert(Branch::class, 'sucursal', $row, $extra);
        }
    }

    private function syncTerminations(User $by, PeopleStructureSource $source, ?string $since): void
    {
        foreach ($source->terminations($since) as $row) {
            $externalId = $this->str($row['employee_id'] ?? null, 80);
            $number = $this->str($row['employee_number'] ?? null, 80);
            $endsOn = $this->str($row['ends_on'] ?? null);
            $ref = $externalId ?? $number;

            if ($ref === null || $endsOn === null || ! $this->isDate($endsOn)) {
                $this->issue('baja', $ref, 'incomplete', 'Baja incompleta: requiere identificador del colaborador y fecha (AAAA-MM-DD).');

                continue;
            }

            $employee = $externalId !== null
                ? Employee::query()->where('external_source', self::EXTERNAL_SOURCE)->where('external_id', $externalId)->first()
                : null;
            $employee ??= $number !== null ? Employee::query()->where('employee_number', $number)->first() : null;
            if ($employee === null) {
                $this->issue('baja', $ref, 'unknown_reference', 'No existe el colaborador en Asistencias.');

                continue;
            }
            if ($employee->status === EmployeeStatus::Terminated) {
                $this->stats['unchanged']++;

                continue;
            }

            try {
                $this->savepoint(fn () => $this->workforce->terminate($by, $employee, $endsOn, $this->str($row['reason'] ?? null, 255) ?? 'Baja registrada en People'));
                $this->stats['terminated']++;
            } catch (ValidationException $e) {
                $this->issue('baja', $ref, 'invalid', implode(' ', array_map(fn ($m) => is_array($m) ? implode(' ', $m) : $m, $e->errors())));
            }
        }
    }

    /**
     * Crea, vincula o actualiza un catálogo por identificador externo.
     *
     * @param  class-string<Department|Position|Branch>  $class
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $extra
     */
    private function upsert(string $class, string $label, array $row, array $extra): ?Model
    {
        $externalId = $this->str($row['id'] ?? null, 80);
        $code = $this->str($row['code'] ?? null, 100);
        $name = $this->str($row['name'] ?? null, 160);

        $missing = array_keys(array_filter(['id' => $externalId, 'clave' => $code, 'nombre' => $name], fn ($v) => $v === null));
        if ($missing !== []) {
            $this->issue($label, $externalId, 'incomplete', 'Información incompleta: falta '.implode(', ', $missing).'.');

            return null;
        }
        if (preg_match(self::CODE_PATTERN, (string) $code) !== 1) {
            $this->issue($label, $externalId, 'invalid', "La clave «{$code}» sólo admite letras, números, punto, guion y guion bajo (máx. 40).");

            return null;
        }
        $code = strtoupper((string) $code);

        /** @var Model|null $model */
        $model = $class::query()->withTrashed()->where('external_source', self::EXTERNAL_SOURCE)->where('external_id', $externalId)->first();
        $byCode = $class::query()->withTrashed()->where('code', $code)->first();

        if ($byCode !== null && $model !== null && $byCode->getKey() !== $model->getKey()) {
            $this->issue($label, $externalId, 'duplicate', "La clave «{$code}» ya pertenece a otro registro local.");

            return null;
        }
        if ($model === null && $byCode !== null && $byCode->getAttribute('external_id') !== null) {
            $this->issue($label, $externalId, 'duplicate', "La clave «{$code}» ya está vinculada a otro identificador de People.");

            return null;
        }

        $attributes = ['code' => $code, 'name' => $name] + $extra;
        if (array_key_exists('active', $row) && $row['active'] !== null) {
            $attributes['active'] = filter_var($row['active'], FILTER_VALIDATE_BOOLEAN);
        }

        return $this->savepoint(function () use ($class, $model, $byCode, $attributes, $externalId) {
            $isNew = false;
            if ($model === null && $byCode !== null) {
                $model = $byCode;
                $this->stats['linked']++;
            } elseif ($model === null) {
                $model = new $class;
                $isNew = true;
            }

            $wasActive = (bool) ($model->getAttribute('active') ?? true);
            $model->fill($attributes);
            $model->forceFill(['external_source' => self::EXTERNAL_SOURCE, 'external_id' => $externalId]);
            $dirty = array_diff(array_keys($model->getDirty()), ['external_source', 'external_id']);
            $model->forceFill(['external_synced_at' => now()])->save();

            if ($isNew) {
                $this->stats['created']++;
            } elseif ($dirty !== []) {
                $this->stats['updated']++;
                if ($wasActive && ($attributes['active'] ?? true) === false) {
                    $this->stats['deactivated']++;
                }
            } else {
                $this->stats['unchanged']++;
            }

            return $model;
        });
    }

    /**
     * Identificador local del registro de People, aunque esté dado de baja.
     *
     * @param  class-string<Branch|Department|Position>  $class
     */
    private function localId(string $class, string $externalId): ?int
    {
        $id = $class::query()->withTrashed()->where('external_source', self::EXTERNAL_SOURCE)->where('external_id', $externalId)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Cada registro en su propio savepoint: un error no invalida los demás.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T|null
     */
    private function savepoint(\Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);
            $this->issue('registro', null, 'database', 'No se pudo guardar el registro (dato duplicado o inválido).');

            return null;
        }
    }

    private function issue(string $entity, ?string $externalId, string $type, string $message): void
    {
        $this->stats['errors'] = ($this->stats['errors'] ?? 0) + ($type === 'not_configured' ? 0 : 1);
        $this->issues[] = ['entity' => $entity, 'external_id' => $externalId, 'type' => $type, 'message' => $message];
    }

    private function str(mixed $value, int $max = 40): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
        } catch (Throwable) {
            return false;
        }
    }
}
