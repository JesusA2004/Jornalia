<?php

namespace App\Models;

use App\Domain\Incidences\IncidenceCatalog;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncidenceType extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $table = 'incidence_types';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_evidence' => 'boolean',
            'requires_folio' => 'boolean',
            'requires_approval' => 'boolean',
            'affects_payroll' => 'boolean',
            'employee_requestable' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * Niveles de autorización de este tipo (jefe inmediato y/o RH).
     *
     * @return list<array{level:int, permission:string, label:string}>
     */
    public function approvalLevels(): array
    {
        return IncidenceCatalog::levelsFor((string) ($this->approval_route ?: 'manager_hr'));
    }

    public function isOmission(): bool
    {
        return filled($this->omission_punch_type);
    }

    /**
     * @return HasMany<Incidence, $this>
     */
    public function incidences(): HasMany
    {
        return $this->hasMany(Incidence::class);
    }
}
