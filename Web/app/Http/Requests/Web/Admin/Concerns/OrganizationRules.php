<?php

namespace App\Http\Requests\Web\Admin\Concerns;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Reglas de validación acotadas a la organización activa (anti-IDOR en
 * identificadores enviados por el cliente).
 */
trait OrganizationRules
{
    protected function orgId(): int
    {
        return app(CurrentOrganization::class)->require()->id;
    }

    protected function existsInOrg(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('organization_id', $this->orgId());
    }

    protected function uniqueInOrg(string $table, string $column, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('organization_id', $this->orgId());

        return $ignoreId !== null ? $rule->ignore($ignoreId) : $rule;
    }
}
