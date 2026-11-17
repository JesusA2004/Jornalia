<?php

namespace App\Domain\Integrations\People;

use App\Domain\Audit\AuditLogger;
use App\Domain\Workforce\WorkforceService;
use App\Enums\ContractType;
use App\Enums\WorkModality;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\IntegrationRun;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sincroniza registros de una fuente de personal con los expedientes.
 *
 *  - Vincula por `external_source` + `external_id` (actualiza datos de contacto).
 *  - Sin vínculo externo: crea el expediente si el número de empleado no existe.
 *  - Duplicados (en el archivo o contra la base) y referencias desconocidas se
 *    reportan como conflictos; nunca se sobrescriben expedientes ajenos.
 *  - Cada ejecución queda registrada en `integration_runs`.
 */
final class PeopleSynchronizer
{
    private const CONTRACT_ALIASES = ['indeterminado' => 'indefinite', 'tiempo_indeterminado' => 'indefinite', 'determinado' => 'fixed_term', 'obra' => 'fixed_term', 'temporada' => 'seasonal', 'prueba' => 'trial', 'capacitacion' => 'training'];

    private const MODALITY_ALIASES = ['presencial' => 'onsite', 'remoto' => 'remote', 'hibrido' => 'hybrid', 'campo' => 'field'];

    /** @var array<string, array<string, int>> */
    private array $codes = [];

    public function __construct(
        private readonly WorkforceService $workforce,
        private readonly AuditLogger $audit,
    ) {}

    public function run(User $by, PeopleSource $source, bool $dryRun = false): IntegrationRun
    {
        $startedAt = now();
        $stats = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0];
        $conflicts = [];
        $status = 'completed';
        $error = null;
        $this->codes = [];

        DB::beginTransaction();
        try {
            $seenNumbers = [];
            $seenExternal = [];

            foreach ($source->records() as $record) {
                $stats['total']++;
                $issue = $this->checkInFileDuplicates($record, $seenNumbers, $seenExternal);
                if ($issue === null) {
                    try {
                        $issue = DB::transaction(function () use ($by, $source, $record, &$stats) {
                            return $this->apply($by, $source, $record, $stats);
                        });
                    } catch (ValidationException $e) {
                        $issue = ['type' => 'invalid', 'message' => implode(' ', array_map(fn ($m) => is_array($m) ? implode(' ', $m) : $m, $e->errors()))];
                    }
                }

                if ($issue !== null) {
                    $stats['conflicts']++;
                    if (count($conflicts) < 500) {
                        $conflicts[] = [
                            'line' => $record->line,
                            'employee_number' => $record->employeeNumber,
                            'external_id' => $record->externalId,
                            'name' => $record->displayName(),
                        ] + $issue;
                    }
                }
            }
        } catch (PeopleSourceException $e) {
            $status = 'failed';
            $error = $e->getMessage();
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

        if ($error !== null) {
            array_unshift($conflicts, ['line' => null, 'employee_number' => null, 'external_id' => null, 'name' => null, 'type' => 'source_error', 'message' => $error]);
        }

        $run = IntegrationRun::query()->create([
            'source' => $source->name(),
            'kind' => $source->name() === 'csv' ? 'import' : 'sync',
            'status' => $status === 'failed' ? 'failed' : ($dryRun ? 'simulated' : ($stats['conflicts'] > 0 ? 'partial' : 'completed')),
            'stats' => $stats + ['dry_run' => $dryRun],
            'conflicts' => $conflicts,
            'started_by' => $by->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $this->audit->log('employees.'.($source->name() === 'csv' ? 'imported' : 'synced'), $run, sprintf(
            '%s de personal (%s): %d creados, %d actualizados, %d conflictos',
            $dryRun ? 'Simulación' : 'Importación', $source->name(), $stats['created'], $stats['updated'], $stats['conflicts'],
        ), ['run_id' => $run->id, 'dry_run' => $dryRun] + $stats);

        return $run;
    }

    /**
     * @param  array<string, int>  $seenNumbers
     * @param  array<string, int>  $seenExternal
     * @return array{type:string, message:string}|null
     */
    private function checkInFileDuplicates(PersonRecord $record, array &$seenNumbers, array &$seenExternal): ?array
    {
        if ($record->employeeNumber !== null) {
            $key = mb_strtolower($record->employeeNumber);
            if (isset($seenNumbers[$key])) {
                return ['type' => 'duplicate_in_file', 'message' => "El número de empleado se repite en el archivo (línea {$seenNumbers[$key]})."];
            }
            $seenNumbers[$key] = $record->line;
        }
        if ($record->externalId !== null) {
            if (isset($seenExternal[$record->externalId])) {
                return ['type' => 'duplicate_in_file', 'message' => "El identificador externo se repite en el archivo (línea {$seenExternal[$record->externalId]})."];
            }
            $seenExternal[$record->externalId] = $record->line;
        }

        return null;
    }

    /**
     * @param  array<string, int>  $stats
     * @return array{type:string, message:string}|null
     */
    private function apply(User $by, PeopleSource $source, PersonRecord $r, array &$stats): ?array
    {
        $missing = array_keys(array_filter([
            'número de empleado' => $r->employeeNumber,
            'nombre' => $r->firstName,
            'apellido paterno' => $r->lastName,
        ], fn ($v) => $v === null));
        if ($missing !== []) {
            return ['type' => 'invalid', 'message' => 'Faltan datos obligatorios: '.implode(', ', $missing).'.'];
        }
        if (mb_strlen((string) $r->employeeNumber) > 40 || mb_strlen((string) $r->firstName) > 100 || mb_strlen((string) $r->lastName) > 100) {
            return ['type' => 'invalid', 'message' => 'Algún dato excede la longitud permitida.'];
        }
        if ($r->email !== null && ! filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
            return ['type' => 'invalid', 'message' => 'Correo electrónico inválido.'];
        }
        if ($r->curp !== null && ! preg_match('/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/', $r->curp)) {
            return ['type' => 'invalid', 'message' => 'La CURP no tiene un formato válido.'];
        }
        if ($r->rfc !== null && ! preg_match('/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/u', $r->rfc)) {
            return ['type' => 'invalid', 'message' => 'El RFC de persona física no tiene un formato válido.'];
        }
        if ($r->nss !== null && ! preg_match('/^\d{11}$/', $r->nss)) {
            return ['type' => 'invalid', 'message' => 'El NSS debe tener 11 dígitos.'];
        }

        $linked = $r->externalId !== null
            ? Employee::query()->where('external_source', $source->externalSource())->where('external_id', $r->externalId)->first()
            : null;

        $byNumber = Employee::query()->where('employee_number', $r->employeeNumber)->first();

        if ($linked !== null) {
            if ($byNumber !== null && $byNumber->id !== $linked->id) {
                return ['type' => 'duplicate', 'message' => "El número de empleado ya pertenece a otro expediente ({$byNumber->fullName()})."];
            }
            $changes = array_filter([
                'employee_number' => $r->employeeNumber,
                'first_name' => $r->firstName,
                'last_name' => $r->lastName,
                'second_last_name' => $r->secondLastName,
                'email' => $r->email,
                'phone' => $r->phone,
            ], fn ($v) => $v !== null);
            $linked->fill($changes);
            if ($linked->isDirty()) {
                $linked->forceFill(['external_synced_at' => now()])->save();
                $stats['updated']++;
            } else {
                $linked->forceFill(['external_synced_at' => now()])->save();
                $stats['unchanged']++;
            }

            return null;
        }

        if ($byNumber !== null) {
            return $byNumber->external_id !== null && $r->externalId !== null
                ? ['type' => 'duplicate', 'message' => 'El número de empleado ya existe vinculado a otro identificador externo.']
                : ['type' => 'duplicate', 'message' => "Ya existe un expediente con este número de empleado ({$byNumber->fullName()})."];
        }

        if ($r->startsOn === null) {
            return ['type' => 'invalid', 'message' => 'Falta la fecha de ingreso.'];
        }
        $startsOn = $this->parseDate($r->startsOn);
        $birth = $r->birthDate !== null ? $this->parseDate($r->birthDate) : null;
        if ($startsOn === null || ($r->birthDate !== null && $birth === null)) {
            return ['type' => 'invalid', 'message' => 'Fecha inválida: usa AAAA-MM-DD o DD/MM/AAAA.'];
        }

        $assignment = [];
        foreach ([
            'branch_id' => [Branch::class, $r->branchCode, 'sucursal'],
            'department_id' => [Department::class, $r->departmentCode, 'departamento'],
            'position_id' => [Position::class, $r->positionCode, 'puesto'],
            'schedule_id' => [Schedule::class, $r->scheduleCode, 'horario'],
        ] as $field => [$model, $code, $label]) {
            if ($code === null) {
                continue;
            }
            $id = $this->resolveCode($model, $code);
            if ($id === null) {
                return ['type' => 'unknown_reference', 'message' => "No existe la {$label} con clave «{$code}»."];
            }
            $assignment[$field] = $id;
        }

        $contract = $this->enumValue($r->contractType, self::CONTRACT_ALIASES, ContractType::class) ?? 'indefinite';
        $modality = $this->enumValue($r->workModality, self::MODALITY_ALIASES, WorkModality::class) ?? 'onsite';

        $this->workforce->hire($by, [
            'employee_number' => $r->employeeNumber,
            'first_name' => $r->firstName,
            'last_name' => $r->lastName,
            'second_last_name' => $r->secondLastName,
            'email' => $r->email,
            'phone' => $r->phone,
            'birth_date' => $birth,
            'curp' => $r->curp,
            'rfc' => $r->rfc,
            'nss' => $r->nss,
            'external_source' => $r->externalId !== null ? $source->externalSource() : null,
            'external_id' => $r->externalId,
            'external_synced_at' => $r->externalId !== null ? now() : null,
        ], ['starts_on' => $startsOn, 'contract_type' => $contract, 'work_modality' => $modality], $assignment);

        $stats['created']++;

        return null;
    }

    private function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }
            if ($date !== false && $date !== null && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function resolveCode(string $model, string $code): ?int
    {
        $key = mb_strtolower($code);
        if (! isset($this->codes[$model])) {
            $this->codes[$model] = $model::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
        }

        return $this->codes[$model][$key] ?? null;
    }

    /**
     * @param  array<string, string>  $aliases
     * @param  class-string<\BackedEnum>  $enum
     */
    private function enumValue(?string $value, array $aliases, string $enum): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = str_replace(' ', '_', mb_strtolower(strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'Í' => 'i'])));
        $key = $aliases[$key] ?? $key;

        return $enum::tryFrom($key)?->value;
    }
}
