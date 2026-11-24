<?php

namespace App\Domain\Reports\Noi;

use App\Models\OrganizationSetting;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Configuración del layout de exportación para NOI: valores de
 * `config/reports.php` (clave `noi`) sobrescritos por el ajuste de la
 * organización `reports.noi` (organization_settings), si existe.
 *
 * RH lo edita en Configuración › Nómina. Las claves de concepto iniciales son
 * MARCADORES (`FALTA`, `HORAS_EXTRA_DOBLES`, …): mientras una clave activa siga
 * igual al marcador, la pantalla y el reporte la muestran «pendiente de RH».
 */
final class NoiConfig
{
    public const SETTING_KEY = 'reports.noi';

    /**
     * Columnas disponibles del layout (campos de cada movimiento) con su etiqueta inicial.
     *
     * @var array<string, string>
     */
    public const COLUMN_KEYS = [
        'employee_number' => 'Clave empleado',
        'employee_name' => 'Nombre',
        'branch' => 'Sucursal',
        'date' => 'Fecha',
        'concept_code' => 'Clave concepto',
        'concept_name' => 'Concepto',
        'unit' => 'Unidad',
        'quantity' => 'Cantidad',
        'overtime_minutes' => 'Minutos de tiempo extra',
    ];

    /** @var array<string, string> */
    public const DATE_FORMATS = ['d/m/Y' => 'dd/mm/aaaa', 'Y-m-d' => 'aaaa-mm-dd', 'd-m-Y' => 'dd-mm-aaaa', 'm/d/Y' => 'mm/dd/aaaa'];

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

        return self::normalize($base);
    }

    /**
     * Valores iniciales (`config/reports.php`), sin el ajuste de la organización.
     *
     * @return array{date_format:string, decimal_separator:string, csv_institutional_header:bool, columns:list<array{key:string,label:string}>, concepts:array<string, array{code:string,label:string,enabled:bool}>, incidence_types:array<string,string>, only_payroll_incidences:bool}
     */
    public static function defaults(): array
    {
        return self::normalize((array) config('reports.noi', []));
    }

    /**
     * Conceptos activos cuya clave sigue siendo el marcador inicial (pendientes de RH).
     *
     * @param  array{concepts:array<string, array{code:string,label:string,enabled:bool}>}  $config
     * @return list<string>
     */
    public static function pendingConcepts(array $config): array
    {
        $defaults = self::defaults()['concepts'];
        $pending = [];
        foreach ($config['concepts'] as $key => $concept) {
            if ($concept['enabled'] && isset($defaults[$key]) && $concept['code'] === $defaults[$key]['code']) {
                $pending[] = (string) $key;
            }
        }

        return $pending;
    }

    /**
     * Guarda la configuración completa de la organización activa (reemplaza el ajuste).
     *
     * @param  array{date_format:string, decimal_separator:string, csv_institutional_header:bool, columns:list<array{key:string,label:string}>, concepts:array<string, array{code:string,label:string,enabled:bool}>, incidence_types:array<string,string>, only_payroll_incidences:bool}  $values
     */
    public static function save(array $values, ?int $userId): void
    {
        $setting = OrganizationSetting::query()->firstOrNew(['key' => self::SETTING_KEY]);
        $setting->value = self::normalize($values);
        $setting->updated_by = $userId;
        $setting->save();
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array{date_format:string, decimal_separator:string, csv_institutional_header:bool, columns:list<array{key:string,label:string}>, concepts:array<string, array{code:string,label:string,enabled:bool}>, incidence_types:array<string,string>, only_payroll_incidences:bool}
     */
    private static function normalize(array $base): array
    {
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
