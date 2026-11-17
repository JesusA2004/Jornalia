<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use LogicException;

/**
 * Organización activa de la petición en curso. La establece el middleware
 * `SetCurrentOrganization` (web y API). El scope global de
 * `BelongsToOrganization` filtra todas las consultas por este id.
 */
final class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function require(): Organization
    {
        return $this->organization ?? throw new LogicException('No hay organización activa en el contexto.');
    }

    /**
     * Ejecuta un callback con otra organización activa (jobs, comandos).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
