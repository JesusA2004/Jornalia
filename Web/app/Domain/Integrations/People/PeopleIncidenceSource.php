<?php

namespace App\Domain\Integrations\People;

use Illuminate\Container\Attributes\Bind;

/**
 * Incidencias APROBADAS en Mr. Lana People (permisos, incapacidades y
 * vacaciones). People es el origen único: autoriza y envía al reloj.
 *
 * Cada registro: {id, kind: incidence|vacation, employee_external_id?,
 * employee_number?, type_code?, starts_on, ends_on, folio?, reason?,
 * status: approved|cancelled, updated_at?}.
 */
#[Bind(HttpPeopleIncidenceSource::class)]
interface PeopleIncidenceSource
{
    public function isConfigured(): bool;

    /**
     * @return iterable<array<string, mixed>>
     *
     * @throws PeopleSourceException
     */
    public function approvedSince(?string $since): iterable;
}
