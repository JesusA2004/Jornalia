<?php

namespace App\Domain\Reports\Noi;

use App\Models\OrganizationSetting;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Configuración del layout de exportación para NOI: valores de
 * `config/reports.php` (clave `noi`) sobrescritos por el ajuste de la
 * organización `reports.noi` (organization_settings), si existe.
 */
final class NoiConfig
{
    public const SETTING_KEY = 'reports.noi';

    /**
     * @return array{date_format:string, decimal_separator:string, csv_institutional_header:bool, columns:list<array{key:string,label:string}>, concepts:array<string, array{code:string,label:string,enabled:bool}>, incidence_types:array<string,string>, only_payroll_incidences:bool}
     */
    public static function current(): array
    {
        /** @var array<string, mixed> $base */
        $base = (array) config('reports.noi', []);
        // Sin organización activa no se lee ningún ajuste (evita mezclar organizaciones).
        $override = app(CurrentOrganization::class)->id() !== null
            ? OrganizationSetting::query()->where('key', self::SETTING_KEY)->value('value')
            : null;
        if (is_array($override)) {
            // `columns` se reemplaza completo (define orden); lo demás se combina.
            $columns = $override['columns'] ?? null;
            unset($override['columns']);
            $base = array_replace_recursive($base, $override);
            if (is_array($columns) && $columns !== []) {
                $base['columns'] = array_values($columns);
            }
        }

        return [
            'date_format' => (string) ($base['date_format'] ?? 'd/m/Y'),
            'decimal_separator' => (string) ($base['decimal_separator'] ?? '.'),
            'csv_institutional_header' => (bool) ($base['csv_institutional_header'] ?? false),
            'columns' => array_values(array_map(fn ($c) => ['key' => (string) $c['key'], 'label' => (string) $c['label']], (array) ($base['columns'] ?? []))),
            'concepts' => array_map(fn ($c) => [
                'code' => (string) ($c['code'] ?? ''),
                'label' => (string) ($c['label'] ?? ''),
                'enabled' => (bool) ($c['enabled'] ?? true),
            ], (array) ($base['concepts'] ?? [])),
            'incidence_types' => array_map('strval', (array) ($base['incidence_types'] ?? [])),
            'only_payroll_incidences' => (bool) ($base['only_payroll_incidences'] ?? true),
        ];
    }
}
