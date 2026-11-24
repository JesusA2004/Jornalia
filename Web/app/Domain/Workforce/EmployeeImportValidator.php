<?php

namespace App\Domain\Workforce;

use App\Domain\Integrations\People\PersonRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validación por fila de la importación CSV de colaboradores (PDF RH pantalla 09,
 * punto 6). Reporta TODOS los errores de la fila; una fila con errores no se importa.
 *
 *  - Fecha de nacimiento obligatoria, válida, no futura y con edad mínima de 15 años
 *    a la fecha de ingreso (LFT art. 22: prohibido el trabajo de menores de 15).
 *  - Sucursal, puesto y horario obligatorios, existentes y activos en la organización.
 *  - Departamento (opcional) existente y activo.
 *  - Horario legal a la fecha de ingreso (ScheduleLegalValidator).
 */
final class EmployeeImportValidator
{
    public const MIN_AGE = 15;

    /** @var array<class-string, array<string, array{id:int, active:bool}>> */
    private array $codes = [];

    public function __construct(private readonly ScheduleLegalValidator $legal) {}

    /**
     * @return list<string> errores (vacío = fila válida)
     */
    public function validate(PersonRecord $r): array
    {
        $errors = [];
        $startsOn = $r->startsOn !== null ? self::parseDate($r->startsOn) : null;

        if ($r->birthDate === null) {
            $errors[] = 'Falta la fecha de nacimiento.';
        } elseif (($birth = self::parseDate($r->birthDate)) === null) {
            $errors[] = 'Fecha de nacimiento inválida: usa AAAA-MM-DD o DD/MM/AAAA.';
        } else {
            $birthDate = CarbonImmutable::parse($birth);
            $reference = CarbonImmutable::parse($startsOn ?? CarbonImmutable::today()->toDateString());
            if ($birthDate->isFuture()) {
                $errors[] = 'La fecha de nacimiento no puede ser futura.';
            } elseif ($birthDate->diffInYears($reference) < self::MIN_AGE) {
                $errors[] = 'Edad menor a '.self::MIN_AGE.' años a la fecha de ingreso (LFT art. 22).';
            } elseif ($birthDate->diffInYears(CarbonImmutable::today()) > 100) {
                $errors[] = 'Fecha de nacimiento fuera de rango.';
            }
        }

        $this->reference(Branch::class, $r->branchCode, 'sucursal', true, $errors);
        $this->reference(Position::class, $r->positionCode, 'puesto', true, $errors);
        $this->reference(Department::class, $r->departmentCode, 'departamento', false, $errors);
        $scheduleId = $this->reference(Schedule::class, $r->scheduleCode, 'horario', true, $errors);

        if ($scheduleId !== null && $startsOn !== null) {
            $schedule = Schedule::query()->with('segments')->find($scheduleId);
            if ($schedule !== null) {
                try {
                    $this->legal->assertScheduleValidOn($schedule, $startsOn);
                } catch (ValidationException $e) {
                    $errors[] = 'Horario «'.$r->scheduleCode.'» fuera de la jornada legal a la fecha de ingreso.';
                }
            }
        }

        return $errors;
    }

    public static function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }
            if ($date instanceof CarbonImmutable && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $errors
     */
    private function reference(string $model, ?string $code, string $label, bool $required, array &$errors): ?int
    {
        if ($code === null) {
            if ($required) {
                $errors[] = "Falta la clave de {$label}.";
            }

            return null;
        }
        if (! isset($this->codes[$model])) {
            $this->codes[$model] = $model::query()->get(['id', 'code', 'active'])
                ->mapWithKeys(fn (Model $m) => [mb_strtolower((string) $m->getAttribute('code')) => ['id' => (int) $m->getKey(), 'active' => (bool) $m->getAttribute('active')]])
                ->all();
        }
        $found = $this->codes[$model][mb_strtolower($code)] ?? null;
        if ($found === null) {
            $errors[] = "No existe la {$label} con clave «{$code}».";

            return null;
        }
        if (! $found['active']) {
            $errors[] = "La {$label} «{$code}» está inactiva.";

            return null;
        }

        return $found['id'];
    }
}
