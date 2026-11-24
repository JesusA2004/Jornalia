<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Incidences\IncidenceCatalog;
use App\Domain\Reports\MealPause;
use App\Domain\Reports\Noi\NoiConfig;
use App\Models\IncidenceType;
use App\Models\OrganizationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración › Nómina: claves de concepto y columnas de la exportación para NOI
 * (ajuste `reports.noi`) y pausa del día que corresponde a la comida (ajuste
 * `reports.meal_pause`). Sólo RH (`settings.incidences`) los edita; cada cambio queda
 * en la bitácora con valor anterior y nuevo. Mientras una clave NOI siga siendo el
 * marcador inicial se muestra «pendiente de RH».
 */
class PayrollSettingsController extends AdminController
{
    /** Columnas que el importador de NOI necesita siempre. */
    private const REQUIRED_COLUMNS = ['employee_number', 'concept_code', 'quantity'];

    /** Clave de concepto de NOI: letras, números, guion, punto o guion bajo. */
    private const CODE_PATTERN = '/^[A-Za-z0-9_.\-]+$/';

    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): Response
    {
        $noi = NoiConfig::current();
        $defaults = NoiConfig::defaults();
        $settings = OrganizationSetting::query()->with('updatedBy:id,name')
            ->whereIn('key', [NoiConfig::SETTING_KEY, MealPause::SETTING_KEY])->get()->keyBy('key');
        $order = array_flip(IncidenceCatalog::codes());

        return Inertia::render('configuracion/Payroll', [
            'noi' => $noi,
            'noi_placeholders' => array_map(fn (array $c) => $c['code'], $defaults['concepts']),
            'noi_pending' => NoiConfig::pendingConcepts($noi),
            'column_options' => collect(NoiConfig::COLUMN_KEYS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'required_columns' => self::REQUIRED_COLUMNS,
            'date_formats' => collect(NoiConfig::DATE_FORMATS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'incidence_types' => IncidenceType::query()->whereIn('code', IncidenceCatalog::codes())->get(['code', 'name', 'affects_payroll'])
                ->sortBy(fn (IncidenceType $t) => $order[$t->code] ?? 99)->values()
                ->map(fn (IncidenceType $t) => ['code' => $t->code, 'name' => $t->name, 'affects_payroll' => (bool) $t->affects_payroll]),
            'meal_pause' => MealPause::settings() + ['max' => MealPause::MAX],
            'updated' => collect([NoiConfig::SETTING_KEY => 'noi', MealPause::SETTING_KEY => 'meal_pause'])
                ->mapWithKeys(fn (string $alias, string $key) => [$alias => isset($settings[$key]) ? [
                    'by' => $settings[$key]->updatedBy?->name,
                    'at' => $settings[$key]->updated_at?->toIso8601String(),
                ] : null]),
            'can' => ['manage' => $request->user()?->can('settings.incidences') ?? false],
        ]);
    }

    public function updateNoi(Request $request): RedirectResponse
    {
        $conceptKeys = array_keys(NoiConfig::defaults()['concepts']);
        $data = $request->validate([
            'concepts' => ['required', 'array:'.implode(',', $conceptKeys)],
            'concepts.*.code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_PATTERN, 'distinct:ignore_case'],
            'concepts.*.label' => ['required', 'string', 'max:80'],
            'concepts.*.enabled' => ['required', 'boolean'],
            'columns' => ['required', 'array', 'min:1', 'max:'.count(NoiConfig::COLUMN_KEYS)],
            'columns.*.key' => ['required', 'string', Rule::in(array_keys(NoiConfig::COLUMN_KEYS)), 'distinct'],
            'columns.*.label' => ['required', 'string', 'max:60'],
            'incidence_types' => ['nullable', 'array:'.implode(',', IncidenceCatalog::codes())],
            'incidence_types.*' => ['nullable', 'string', 'max:30', 'regex:'.self::CODE_PATTERN],
            'date_format' => ['required', Rule::in(array_keys(NoiConfig::DATE_FORMATS))],
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'csv_institutional_header' => ['required', 'boolean'],
            'only_payroll_incidences' => ['required', 'boolean'],
        ], [
            'concepts.*.code.regex' => 'La clave sólo admite letras, números, guion, punto o guion bajo.',
            'concepts.*.code.distinct' => 'Cada concepto necesita una clave distinta.',
            'columns.*.key.distinct' => 'Una columna no puede repetirse.',
        ], [
            'concepts.*.code' => 'clave de concepto', 'concepts.*.label' => 'nombre del concepto', 'columns' => 'columnas',
            'columns.*.label' => 'encabezado', 'incidence_types.*' => 'clave NOI de la incidencia', 'date_format' => 'formato de fecha',
            'decimal_separator' => 'separador decimal',
        ]);

        $missing = array_diff($conceptKeys, array_keys((array) $data['concepts']));
        if ($missing !== []) {
            throw ValidationException::withMessages(['concepts' => 'Faltan conceptos: '.implode(', ', $missing).'.']);
        }
        $columnKeys = array_column((array) $data['columns'], 'key');
        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $columnKeys);
        if ($missingColumns !== []) {
            $labels = array_map(fn ($k) => NoiConfig::COLUMN_KEYS[$k], $missingColumns);
            throw ValidationException::withMessages(['columns' => 'NOI necesita las columnas: '.implode(', ', $labels).'.']);
        }

        $values = [
            'date_format' => (string) $data['date_format'],
            'decimal_separator' => (string) $data['decimal_separator'],
            'csv_institutional_header' => (bool) $data['csv_institutional_header'],
            'columns' => array_values(array_map(fn (array $c) => ['key' => (string) $c['key'], 'label' => trim((string) $c['label'])], (array) $data['columns'])),
            'concepts' => array_map(fn (array $c) => [
                'code' => trim((string) $c['code']),
                'label' => trim((string) $c['label']),
                'enabled' => (bool) $c['enabled'],
            ], (array) $data['concepts']),
            // Sin clave propia, el tipo de incidencia usa su propio código.
            'incidence_types' => array_filter(array_map(fn ($v) => trim((string) $v), (array) ($data['incidence_types'] ?? [])), fn (string $v) => $v !== ''),
            'only_payroll_incidences' => (bool) $data['only_payroll_incidences'],
        ];

        $before = NoiConfig::current();
        NoiConfig::save($values, $request->user()?->id);
        $after = NoiConfig::current();
        $pending = NoiConfig::pendingConcepts($after);
        $this->audit->log('reports.noi_updated', null, 'Configuración de exportación para NOI actualizada', [
            'pending_concepts' => $pending,
        ], before: $before, after: $after);
        $this->toast($pending === [] ? 'Configuración NOI guardada.' : 'Guardado. Hay claves pendientes de RH.', $pending === [] ? 'success' : 'warning');

        return back();
    }

    public function updateMealPause(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pause' => ['required', 'integer', 'min:0', 'max:'.MealPause::MAX],
            'confirmed_by_hr' => ['required', 'boolean'],
        ], [], ['pause' => 'pausa de comida']);

        $before = MealPause::settings();
        MealPause::save((int) $data['pause'], (bool) $data['confirmed_by_hr'], $request->user()?->id);
        $after = MealPause::settings();
        $this->audit->log('reports.meal_pause_updated', null, 'Pausa de comida actualizada', [], before: $before, after: $after);
        $this->toast('Pausa de comida guardada.');

        return back();
    }
}
