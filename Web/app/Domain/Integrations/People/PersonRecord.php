<?php

namespace App\Domain\Integrations\People;

/**
 * Registro de persona normalizado proveniente de una fuente externa
 * (CSV o API de People). Los valores llegan como cadenas; el sincronizador
 * los valida.
 */
final readonly class PersonRecord
{
    /**
     * @param  array<string, string|null>  $raw  fila original (para el reporte de conflictos)
     */
    public function __construct(
        public int $line,
        public ?string $employeeNumber,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $secondLastName,
        public ?string $email,
        public ?string $phone,
        public ?string $birthDate,
        public ?string $curp,
        public ?string $rfc,
        public ?string $nss,
        public ?string $startsOn,
        public ?string $branchCode,
        public ?string $departmentCode,
        public ?string $positionCode,
        public ?string $scheduleCode,
        public ?string $contractType,
        public ?string $workModality,
        public ?string $externalId,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $row  claves normalizadas (ver CsvPeopleSource::ALIASES)
     */
    public static function fromArray(int $line, array $row): self
    {
        $v = function (string $key) use ($row): ?string {
            $value = $row[$key] ?? null;
            if ($value === null || is_array($value)) {
                return null;
            }
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        };

        return new self(
            line: $line,
            employeeNumber: $v('employee_number'),
            firstName: $v('first_name'),
            lastName: $v('last_name'),
            secondLastName: $v('second_last_name'),
            email: $v('email') !== null ? mb_strtolower((string) $v('email')) : null,
            phone: $v('phone'),
            birthDate: $v('birth_date'),
            curp: $v('curp') !== null ? strtoupper((string) $v('curp')) : null,
            rfc: $v('rfc') !== null ? strtoupper((string) $v('rfc')) : null,
            nss: $v('nss'),
            startsOn: $v('starts_on'),
            branchCode: $v('branch_code'),
            departmentCode: $v('department_code'),
            positionCode: $v('position_code'),
            scheduleCode: $v('schedule_code'),
            contractType: $v('contract_type'),
            workModality: $v('work_modality'),
            externalId: $v('external_id'),
            raw: array_map(fn ($x) => $x === null || is_array($x) ? null : (string) $x, $row),
        );
    }

    public function displayName(): string
    {
        return trim(implode(' ', array_filter([$this->firstName, $this->lastName, $this->secondLastName]))) ?: '(sin nombre)';
    }
}
