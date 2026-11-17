<?php

namespace App\Domain\Integrations\People;

/**
 * Fuente desacoplada de datos de personal. Implementaciones: CSV cargado por
 * un administrador o la API HTTP de People. Nunca se accede a la base de
 * datos de People directamente.
 */
interface PeopleSource
{
    /** Identificador corto para `integration_runs.source` (csv, people_api). */
    public function name(): string;

    /** Valor de `employees.external_source` con el que se vinculan los registros. */
    public function externalSource(): string;

    public function isAvailable(): bool;

    /**
     * @return iterable<PersonRecord>
     *
     * @throws PeopleSourceException
     */
    public function records(): iterable;
}
