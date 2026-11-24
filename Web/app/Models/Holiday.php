<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Día festivo o de descanso obligatorio.
 *
 *  - Oficial precargado (LFT art. 74): `organization_id` nulo, `kind = official`. Global;
 *    nunca se elimina ni se desactiva.
 *  - Ajuste de la organización a un oficial precargado: `replaces_holiday_id` apunta al
 *    global y lo sustituye sólo para esa organización (nombre, anotación o día movido).
 *  - Descanso obligatorio variable capturado por RH (p. ej. jornada electoral, art. 74
 *    fr. IX): `kind = official` de la organización, sin `replaces_holiday_id`.
 *  - Día de la empresa (`company`) o local de un centro de trabajo (`local`).
 *
 * @property int|null $replaces_holiday_id
 * @property string|null $change_reason
 * @property string|null $notes
 * @property int|null $updated_by
 */
class Holiday extends Model
{
    use SoftDeletes;

    public const KIND_OFFICIAL = 'official';

    public const KIND_COMPANY = 'company';

    public const KIND_LOCAL = 'local';

    protected $table = 'holidays';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_mandatory_rest' => 'boolean',
        ];
    }

    /**
     * Festivos que aplican a una organización: los oficiales globales que la organización
     * no ajustó, más los propios (incluidos sus ajustes a oficiales). Fuente única para
     * cálculo de jornada, calendario, plazos y reportes.
     *
     * @param  Builder<Holiday>  $query
     */
    public function scopeEffectiveFor(Builder $query, ?int $organizationId): void
    {
        $table = $this->getTable();
        $query->where(function (Builder $q) use ($organizationId, $table) {
            $q->where(function (Builder $global) use ($organizationId, $table) {
                $global->whereNull("{$table}.organization_id");
                if ($organizationId !== null) {
                    $global->whereNotExists(fn (QueryBuilder $adjusted) => $adjusted->selectRaw('1')
                        ->from("{$table} as adjusted")
                        ->whereColumn('adjusted.replaces_holiday_id', "{$table}.id")
                        ->where('adjusted.organization_id', $organizationId)
                        ->whereNull('adjusted.deleted_at'));
                }
            });
            if ($organizationId !== null) {
                $q->orWhere("{$table}.organization_id", $organizationId);
            }
        });
    }

    /**
     * Día de descanso obligatorio precargado del art. 74 (global): no se elimina ni se desactiva.
     */
    public function isPreloadedOfficial(): bool
    {
        return $this->organization_id === null && $this->kind === self::KIND_OFFICIAL;
    }

    /**
     * Ajuste de la organización a un oficial precargado (sigue siendo obligatorio).
     */
    public function isOfficialAdjustment(): bool
    {
        return $this->replaces_holiday_id !== null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<WorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(WorkCenter::class);
    }

    /**
     * Día oficial global que este ajuste sustituye para la organización.
     *
     * @return BelongsTo<Holiday, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_holiday_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
