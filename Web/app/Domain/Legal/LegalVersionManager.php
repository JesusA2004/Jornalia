<?php

namespace App\Domain\Legal;

use App\Domain\Audit\AuditLogger;
use App\Models\LegalVersion;
use App\Models\LegalVersionRevision;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administración controlada de la normativa (fuente única de los parámetros legales).
 *
 * Reglas:
 *  - Sólo RH (`settings.legal_manage`) edita o verifica; la autorización la impone la ruta.
 *  - Sólo se editan versiones vigentes o futuras: las pasadas sustentan cálculos ya cerrados.
 *  - Toda edición deja la versión «Sin verificar» hasta que RH la valide de nuevo.
 *  - Cada cambio queda en `legal_version_revisions` (append-only) y en la bitácora con antes/después.
 *  - La prohibición de tiempo extra a menores de 18 años no es editable (LFT art. 178; CPEUM 123 A fr. XI).
 */
final class LegalVersionManager
{
    /**
     * Parámetros editables por RH → ruta en `parameters` (minutos enteros).
     */
    public const EDITABLE = [
        'weekly_ordinary_max_minutes' => 'weekly_ordinary_max_minutes',
        'daily_max_day' => 'daily_max_minutes.day',
        'daily_max_night' => 'daily_max_minutes.night',
        'daily_max_mixed' => 'daily_max_minutes.mixed',
        'overtime_daily_max' => 'overtime.daily_max_minutes',
        'overtime_weekly_max' => 'overtime.weekly_max_minutes',
        'overtime_max_days_per_week' => 'overtime.max_days_per_week',
        'overtime_excess_weekly_max' => 'overtime.excess_weekly_max_minutes',
        'daily_total_max' => 'daily_total_max_minutes',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentOrganization $current,
    ) {}

    /**
     * @param  array<string, int>  $values  claves de EDITABLE
     */
    public function update(LegalVersion $version, array $values, User $by, string $note): LegalVersion
    {
        $this->ensureEditable($version);

        return DB::transaction(function () use ($version, $values, $by, $note) {
            $version = LegalVersion::query()->lockForUpdate()->findOrFail($version->id);
            $before = (array) $version->parameters;
            $after = $before;
            foreach (self::EDITABLE as $key => $path) {
                if (array_key_exists($key, $values)) {
                    Arr::set($after, $path, (int) $values[$key]);
                }
            }
            // Regla constitucional: nunca se permite tiempo extra a menores de 18 años.
            Arr::set($after, 'overtime.minors_allowed', false);
            Arr::set($after, 'minors.overtime_allowed_under_18', false);

            if (LegalFramework::checksum($before) === LegalFramework::checksum($after)) {
                return $version;
            }

            $version->forceFill([
                'parameters' => $after,
                'checksum' => LegalFramework::checksum($after),
                'verification_status' => LegalVersion::STATUS_UNVERIFIED,
                'verified_by' => null,
                'verified_at' => null,
                'updated_by' => $by->id,
            ])->save();

            $this->revision($version, 'updated', $by, $before, $after, $note);
            $this->audit->log('settings.legal_updated', $version, "Normativa {$version->code} modificada; queda sin verificar", [
                'before' => $this->editableSnapshot($before),
                'after' => $this->editableSnapshot($after),
                'note' => $note,
            ]);

            return $version;
        });
    }

    /**
     * Marca la versión como «Verificada por RH» con el usuario y la fecha REAL de la acción.
     */
    public function verify(LegalVersion $version, User $by, string $note): LegalVersion
    {
        return DB::transaction(function () use ($version, $by, $note) {
            $version = LegalVersion::query()->lockForUpdate()->findOrFail($version->id);
            if (! hash_equals($version->checksum, LegalFramework::checksum((array) $version->parameters))) {
                throw ValidationException::withMessages(['version' => 'La versión fue alterada fuera del procedimiento controlado; no puede verificarse.']);
            }
            $before = ['verification_status' => $version->verification_status, 'verified_by' => $version->verified_by, 'verified_at' => $version->verified_at?->toIso8601String()];
            $now = CarbonImmutable::now('UTC');
            $version->forceFill([
                'verification_status' => LegalVersion::STATUS_VERIFIED,
                'verified_by' => $by->id,
                'verified_at' => $now,
            ])->save();
            $after = ['verification_status' => LegalVersion::STATUS_VERIFIED, 'verified_by' => $by->id, 'verified_at' => $now->toIso8601String()];

            $this->revision($version, 'verified', $by, $before, $after, $note);
            $this->audit->log('settings.legal_verified', $version, "Normativa {$version->code} verificada por RH", [
                'before' => $before,
                'after' => $after,
                'checksum' => $version->checksum,
                'note' => $note,
            ]);

            return $version;
        });
    }

    public function isEditable(LegalVersion $version): bool
    {
        $today = CarbonImmutable::now($this->current->get()?->timezone ?? 'America/Mexico_City')->toDateString();

        return $version->valid_to === null || $version->valid_to->toDateString() >= $today;
    }

    private function ensureEditable(LegalVersion $version): void
    {
        if (! $this->isEditable($version)) {
            throw ValidationException::withMessages(['version' => 'Las versiones concluidas no se editan: sustentan cálculos ya cerrados.']);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, int|null>
     */
    private function editableSnapshot(array $parameters): array
    {
        $out = [];
        foreach (self::EDITABLE as $key => $path) {
            $value = Arr::get($parameters, $path);
            $out[$key] = $value === null ? null : (int) $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function revision(LegalVersion $version, string $action, User $by, ?array $before, ?array $after, string $note): void
    {
        LegalVersionRevision::query()->create([
            'legal_version_id' => $version->id,
            'organization_id' => $this->current->id(),
            'user_id' => $by->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'before_checksum' => $action === 'updated' && $before !== null ? LegalFramework::checksum($before) : null,
            'after_checksum' => $version->checksum,
            'note' => mb_substr($note, 0, 500),
            'ip_address' => request()->ip(),
            'created_at' => now('UTC'),
        ]);
    }
}
