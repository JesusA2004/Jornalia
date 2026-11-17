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
