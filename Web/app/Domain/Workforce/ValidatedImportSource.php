<?php

namespace App\Domain\Workforce;

use App\Domain\Integrations\People\PeopleSource;
use App\Domain\Integrations\People\PersonRecord;

/**
 * Decorador de una fuente de personal (CSV): sólo entrega al sincronizador las filas
 * válidas según EmployeeImportValidator y conserva las rechazadas con sus errores
 * para reportarlas por fila en la corrida de importación.
 */
final class ValidatedImportSource implements PeopleSource
{
    /** @var list<array{line:int, employee_number:?string, external_id:?string, name:string, type:string, message:string}> */
    private array $rejected = [];

    public function __construct(
        private readonly PeopleSource $inner,
        private readonly EmployeeImportValidator $validator,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function externalSource(): string
    {
        return $this->inner->externalSource();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    /**
     * @return iterable<PersonRecord>
     */
    public function records(): iterable
    {
        $this->rejected = [];
        foreach ($this->inner->records() as $record) {
            $errors = $this->validator->validate($record);
            if ($errors !== []) {
                $this->rejected[] = [
                    'line' => $record->line,
                    'employee_number' => $record->employeeNumber,
                    'external_id' => $record->externalId,
                    'name' => $record->displayName(),
                    'type' => 'invalid',
                    'message' => implode(' ', $errors),
                ];

                continue;
            }

            yield $record;
        }
    }

    /**
     * @return list<array{line:int, employee_number:?string, external_id:?string, name:string, type:string, message:string}>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }
}
