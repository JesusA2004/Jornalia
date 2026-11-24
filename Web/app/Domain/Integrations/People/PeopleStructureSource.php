<?php

namespace App\Domain\Integrations\People;

use Illuminate\Container\Attributes\Bind;

/**
 * Fuente de estructura organizacional y bajas de Mr. Lana People
 * (contrato en docs/integraciones/PEOPLE.md). Separada de PeopleSource
 * (expedientes) y de la parte de incidencias.
 *
 * Cada método devuelve filas asociativas tal como las entrega People; el
 * sincronizador las valida y reporta la información incompleta.
 */
#[Bind(HttpPeopleStructureSource::class)]
interface PeopleStructureSource
{
    public function isConfigured(): bool;

    /**
     * @return iterable<array<string, mixed>> {id, code, name, parent_id?, active?}
     *
     * @throws PeopleSourceException
     */
    public function departments(): iterable;

    /**
     * @return iterable<array<string, mixed>> {id, code, name, department_id?, active?}
     *
     * @throws PeopleSourceException
     */
    public function positions(): iterable;

    /**
     * @return iterable<array<string, mixed>> {id, code, name, region?, address?, employer_registration?, active?}
     *
     * @throws PeopleSourceException
     */
    public function branches(): iterable;

    /**
     * Bajas registradas en People.
     *
     * @return iterable<array<string, mixed>> {employee_id, employee_number?, ends_on, reason?}
     *
     * @throws PeopleSourceException
     */
    public function terminations(?string $since): iterable;
}
