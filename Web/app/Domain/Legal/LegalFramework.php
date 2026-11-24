<?php

namespace App\Domain\Legal;

use App\Domain\Legal\Exceptions\LegalVersionNotFound;
use App\Models\LegalVersion;
use App\Models\VacationEntitlementRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Punto de entrada al marco legal versionado. Resuelve la versión vigente para
 * una fecha, de modo que cualquier cálculo histórico sea reproducible.
 */
final class LegalFramework
{
    /** @var array<string, LegalParameters> */
    private array $cache = [];

    public function for(CarbonInterface|string $date): LegalParameters
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        if (isset($this->cache[$day])) {
            return $this->cache[$day];
        }

        $version = LegalVersion::query()
            ->whereDate('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->orderByDesc('valid_from')
            ->first();

        if ($version === null) {
            throw new LegalVersionNotFound("No existe una versión legal vigente para {$day}. Ejecute el seeder legal.");
        }

        $this->assertChecksum($version);

        return $this->cache[$day] = LegalParameters::fromVersion($version);
    }

    /**
     * Estado de verificación de la normativa vigente en una fecha, para leyendas como
     * «Normativa en verificación» / «Verificada por RH» (Horas extra, Cumplimiento, Configuración).
     *
     * @return array{code:string|null, name:string|null, valid_from:string|null, valid_to:string|null, verified:bool, label:string, verified_by:string|null, verified_at:string|null}
     */
    public function verificationFor(CarbonInterface|string $date): array
    {
        $day = CarbonImmutable::parse($date)->toDateString();
        $version = LegalVersion::query()->with('verifiedBy:id,name')
            ->whereDate('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->orderByDesc('valid_from')
            ->first();
        $verified = $version?->isVerified() ?? false;

        return [
            'code' => $version?->code,
            'name' => $version?->name,
            'valid_from' => $version?->valid_from?->toDateString(),
            'valid_to' => $version?->valid_to?->toDateString(),
            'verified' => $verified,
            'label' => $verified ? 'Verificada por RH' : 'Normativa en verificación',
            'verified_by' => $verified ? $version->verifiedBy?->name : null,
            'verified_at' => $verified ? $version->verified_at?->utc()->format('Y-m-d\TH:i:s\Z') : null,
        ];
    }

    /**
     * Días mínimos legales de vacaciones para un año de servicio cumplido.
     */
    public function legalVacationDays(int $serviceYear, CarbonInterface|string $onDate): int
    {
        if ($serviceYear < 1) {
            return 0;
        }

        $day = CarbonImmutable::parse($onDate)->toDateString();

        $rule = VacationEntitlementRule::query()
            ->whereDate('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->where('from_year', '<=', $serviceYear)
            ->where(fn ($q) => $q->whereNull('to_year')->orWhere('to_year', '>=', $serviceYear))
            ->orderByDesc('valid_from')
            ->orderByDesc('from_year')
            ->first();

        if ($rule === null) {
            throw new LegalVersionNotFound("No hay tabla de vacaciones vigente para el año de servicio {$serviceYear} en {$day}.");
        }

        return (int) $rule->days;
    }

    public static function checksum(array $parameters): string
    {
        return hash('sha256', (string) json_encode(self::sortRecursive($parameters), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::sortRecursive($v) : $v, $value);
    }

    private function assertChecksum(LegalVersion $version): void
    {
        if (! hash_equals($version->checksum, self::checksum($version->parameters))) {
            throw new LegalVersionNotFound("La versión legal {$version->code} fue alterada fuera del procedimiento controlado (checksum inválido).");
        }
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
