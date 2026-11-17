<?php

namespace App\Http\Requests\Web\Admin;

use App\Enums\ScheduleType;
use App\Enums\ShiftKind;
use App\Http\Requests\Web\Admin\Concerns\OrganizationRules;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ScheduleRequest extends FormRequest
{
    use OrganizationRules;

    public function authorize(): bool
    {
        $schedule = $this->route('schedule');

        return $schedule instanceof Schedule
            ? ($this->user()?->can('update', $schedule) ?? false)
            : ($this->user()?->can('create', Schedule::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schedule = $this->route('schedule');
        $cycle = max(1, (int) $this->input('cycle_length_days', 7));
        $flexibleTypes = [ScheduleType::Variable->value, ScheduleType::OnCall->value];

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9._-]+$/', $this->uniqueInOrg('schedules', 'code', $schedule instanceof Schedule ? $schedule->id : null)],
            'type' => ['required', Rule::enum(ScheduleType::class)],
            'shift_kind' => ['required', Rule::enum(ShiftKind::class)],
            'cycle_length_days' => ['required', 'integer', 'min:1', 'max:56'],
            'cycle_anchor_date' => [Rule::requiredIf($cycle !== 7), 'nullable', 'date'],
            'tolerance_minutes' => ['required', 'integer', 'min:0', 'max:60'],
            'flex_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'description' => ['nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
            'applies_from' => ['nullable', 'date'],
            'segments' => [Rule::requiredIf(! in_array($this->input('type'), $flexibleTypes, true)), 'array', 'max:'.($cycle * 4)],
            'segments.*.day_index' => ['required', 'integer', 'min:0', 'max:'.($cycle - 1)],
            'segments.*.ordinal' => ['required', 'integer', 'min:1', 'max:4'],
            'segments.*.starts_at' => ['required', 'date_format:H:i'],
            'segments.*.ends_at' => ['required', 'date_format:H:i', 'different:segments.*.starts_at'],
            'segments.*.break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $segments = (array) $this->input('segments', []);
            $byDay = [];
            foreach ($segments as $i => $s) {
                if (! is_array($s) || ! isset($s['day_index'], $s['ordinal'], $s['starts_at'], $s['ends_at'])) {
                    continue;
                }
                $key = $s['day_index'].'-'.$s['ordinal'];
                if (isset($byDay[$key])) {
                    $validator->errors()->add("segments.{$i}.ordinal", 'Hay dos segmentos con el mismo orden en el mismo día.');
                }
                $byDay[$key] = true;
            }

            // Segmentos del mismo día no deben traslaparse (horario partido).
            $grouped = [];
            foreach ($segments as $i => $s) {
                if (is_array($s) && isset($s['day_index'], $s['starts_at'], $s['ends_at']) && preg_match('/^\d{2}:\d{2}$/', (string) $s['starts_at']) && preg_match('/^\d{2}:\d{2}$/', (string) $s['ends_at'])) {
                    $a = CarbonImmutable::parse('2026-01-05 '.$s['starts_at']);
                    $b = CarbonImmutable::parse('2026-01-05 '.$s['ends_at']);
                    if ($b->lte($a)) {
                        $b = $b->addDay();
                    }
                    $grouped[(int) $s['day_index']][] = [$a, $b, $i];
                }
            }
            foreach ($grouped as $items) {
                usort($items, fn ($x, $y) => $x[0] <=> $y[0]);
                for ($k = 1; $k < count($items); $k++) {
                    if ($items[$k][0]->lt($items[$k - 1][1])) {
                        $validator->errors()->add("segments.{$items[$k][2]}.starts_at", 'Los segmentos del mismo día se traslapan.');
                    }
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'code' => 'clave', 'type' => 'tipo', 'shift_kind' => 'tipo de jornada',
            'cycle_length_days' => 'duración del ciclo', 'cycle_anchor_date' => 'fecha de inicio del ciclo',
            'tolerance_minutes' => 'tolerancia', 'flex_minutes' => 'ventana flexible', 'applies_from' => 'vigencia',
            'segments' => 'segmentos', 'segments.*.starts_at' => 'hora de entrada', 'segments.*.ends_at' => 'hora de salida',
            'segments.*.break_minutes' => 'descanso', 'segments.*.day_index' => 'día',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un horario con esa clave.',
            'segments.required' => 'Agrega al menos un segmento de jornada.',
            'segments.*.ends_at.different' => 'La salida debe ser distinta de la entrada.',
        ];
    }

    /**
     * Fechas en que se valida la jornada legal: hoy y la vigencia indicada.
     *
     * @return list<string>
     */
    public function legalDates(): array
    {
        $dates = [CarbonImmutable::today()->toDateString()];
        if ($this->filled('applies_from')) {
            $dates[] = CarbonImmutable::parse((string) $this->input('applies_from'))->toDateString();
        }

        return array_values(array_unique($dates));
    }

    /**
     * @return list<array{day_index:int, ordinal:int, starts_at:string, ends_at:string, break_minutes:int}>
     */
    public function segments(): array
    {
        return array_values(array_map(fn ($s) => [
            'day_index' => (int) $s['day_index'],
            'ordinal' => (int) $s['ordinal'],
            'starts_at' => (string) $s['starts_at'],
            'ends_at' => (string) $s['ends_at'],
            'break_minutes' => (int) $s['break_minutes'],
        ], (array) $this->validated('segments', [])));
    }
}
