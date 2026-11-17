<?php

namespace App\Models;

use App\Enums\ContractType;
use App\Enums\WorkModality;
use App\Support\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employment extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'employments';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'seniority_starts_on' => 'date',
            'contract_type' => ContractType::class,
            'work_modality' => WorkModality::class,
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LegalEntity, $this>
     */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /**
     * @return HasMany<EmploymentAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmploymentAssignment::class);
    }

    public function assignmentOn(CarbonInterface|string $date): ?EmploymentAssignment
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        return $this->assignments()
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();
    }

    public function isActiveOn(CarbonInterface|string $date): bool
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        return $this->starts_on->lte($day) && ($this->ends_on === null || $this->ends_on->gte($day));
    }
}
