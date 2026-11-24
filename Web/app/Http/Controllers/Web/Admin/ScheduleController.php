<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Legal\LegalFramework;
use App\Domain\Workforce\ScheduleLegalValidator;
use App\Enums\ScheduleType;
use App\Enums\ShiftKind;
use App\Http\Requests\Web\Admin\ScheduleRequest;
use App\Models\EmploymentAssignment;
use App\Models\Schedule;
use App\Models\ScheduleSegment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ScheduleController extends AdminController
{
    public function __construct(
        private readonly ScheduleLegalValidator $validator,
        private readonly LegalFramework $law,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Schedule::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $today = CarbonImmutable::today()->toDateString();
        $outlook = $this->outlookDates();

        $counts = EmploymentAssignment::query()
            ->whereNotNull('schedule_id')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->selectRaw('schedule_id, count(*) as total')->groupBy('schedule_id')->pluck('total', 'schedule_id');

        $schedules = Schedule::query()
            ->with(['segments' => fn ($q) => $q->orderBy('day_index')->orderBy('ordinal')])
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('code', 'like', "%{$t}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->onlyTrashed())
            ->orderByDesc('active')->orderBy('name')
            ->get()
            ->map(fn (Schedule $s) => $this->scheduleData($s, $outlook) + ['assigned' => (int) ($counts[$s->id] ?? 0)]);

        $user = $this->actor($request);

        return Inertia::render('horarios/Index', [
            'schedules' => $schedules->values(),
            'filters' => $filters,
            'legal' => $this->legalSummary($outlook),
            'types' => self::typeOptions(),
            'can' => [
                'manage' => $user->can('create', Schedule::class),
                'assign' => $user->can('schedules.assign'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Schedule::class);

        return Inertia::render('horarios/Form', [
            'schedule' => null,
            'types' => self::typeOptions(),
            'legal' => $this->legalSummary($this->outlookDates()),
        ]);
    }

    public function edit(Request $request, Schedule $schedule): Response
    {
        $this->authorize('update', $schedule);
        $schedule->load(['segments' => fn ($q) => $q->orderBy('day_index')->orderBy('ordinal')]);

        return Inertia::render('horarios/Form', [
            'schedule' => $this->scheduleData($schedule, $this->outlookDates()),
            'types' => self::typeOptions(),
            'legal' => $this->legalSummary($this->outlookDates()),
        ]);
    }

    /**
     * Validación legal en vivo para el editor (no guarda nada).
     */
    public function check(Request $request): JsonResponse
    {
        $this->authorize('create', Schedule::class);
        $data = $request->validate([
            'shift_kind' => ['required', 'in:day,night,mixed'],
            'cycle_length_days' => ['required', 'integer', 'min:1', 'max:56'],
            'applies_from' => ['nullable', 'date'],
            'segments' => ['array', 'max:224'],
            'segments.*.day_index' => ['required', 'integer', 'min:0'],
            'segments.*.starts_at' => ['required', 'date_format:H:i'],
            'segments.*.ends_at' => ['required', 'date_format:H:i'],
            'segments.*.break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);

        $segments = array_values($data['segments'] ?? []);
        // Bloquea sólo lo vigente (hoy y fecha de aplicación); los tramos futuros sólo advierten.
        $blocking = array_values(array_unique(array_filter([CarbonImmutable::today()->toDateString(), isset($data['applies_from']) ? CarbonImmutable::parse($data['applies_from'])->toDateString() : null])));
        $result = $this->validator->evaluate($data, $segments, $blocking);
        $futureDates = array_values(array_diff($this->outlookDates(), $blocking));
        $future = $futureDates === [] ? ['checks' => []] : $this->validator->evaluate($data, $segments, $futureDates);
        $result['checks'] = [
            ...array_map(fn (array $c) => [...$c, 'future' => false], $result['checks']),
            ...array_map(fn (array $c) => [...$c, 'future' => true], $future['checks']),
        ];
        $result['outlook'] = $this->validator->outlook($data, $segments, $futureDates);

        return response()->json($result);
    }

    public function store(ScheduleRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $segments = $request->segments();
        $this->validator->assertValid($data, $segments, $request->legalDates());

        $schedule = DB::transaction(function () use ($data, $segments) {
            $schedule = Schedule::query()->create($this->attributes($data));
            $schedule->segments()->createMany($segments);

            return $schedule;
        });

        // Bloquea lo vigente (assertValid) y sólo ADVIERTE por tramos futuros.
        $outlook = $this->validator->outlook($data, $segments);
        $this->audit->log('schedule.created', $schedule, "Horario creado: {$schedule->name}", ['code' => $schedule->code, 'segments' => count($segments), 'adjust_before' => $outlook['adjust_before']]);
        $this->toast(trim("Horario «{$schedule->name}» creado. ".($outlook['warning'] ?? '')), $outlook['warning'] !== null ? 'warning' : 'success');

        return to_route('horarios.index');
    }

    public function update(ScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        $data = $request->validated();
        $segments = $request->segments();
        $dates = $request->legalDates();

        // Si el horario está asignado, también debe cumplir en las vigencias futuras ya programadas.
        $futureDates = EmploymentAssignment::query()->where('schedule_id', $schedule->id)
            ->whereDate('effective_from', '>', CarbonImmutable::today())->distinct()->pluck('effective_from')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();
        $this->validator->assertValid($data, $segments, array_values(array_unique([...$dates, ...$futureDates])));

        $before = $schedule->segments()->get(['day_index', 'ordinal', 'starts_at', 'ends_at', 'break_minutes'])->toArray();

        DB::transaction(function () use ($schedule, $data, $segments) {
            $schedule->update($this->attributes($data));
            $this->syncSegments($schedule, $segments);
        });

        $this->audit->log('schedule.updated', $schedule, "Horario actualizado: {$schedule->name}", [
            'segments_before' => $before,
            'segments_after' => $segments,
        ]);
        $outlook = $this->validator->outlook($data, $segments);
        $this->toast('Horario actualizado. Los cálculos de días pasados ya consolidados no se modifican.'.($outlook['warning'] !== null ? ' '.$outlook['warning'] : ''), $outlook['warning'] !== null ? 'warning' : 'success');

        return to_route('horarios.index');
    }

    public function deactivate(Request $request, Schedule $schedule): RedirectResponse
    {
        $this->authorize('delete', $schedule);
        $today = CarbonImmutable::today()->toDateString();
        $inUse = EmploymentAssignment::query()->where('schedule_id', $schedule->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))->count();
        if ($inUse > 0) {
            throw ValidationException::withMessages(['schedule' => "El horario está asignado a {$inUse} colaboradores vigentes o futuros. Reasígnalos antes de desactivarlo."]);
        }

        $schedule->forceFill(['active' => false])->save();
        $schedule->delete();
        $this->audit->log('schedule.deactivated', $schedule, "Horario desactivado: {$schedule->name}");
        $this->toast('Horario desactivado. Su historial se conserva y puedes reactivarlo.');

        return back();
    }

    public function restore(Request $request, Schedule $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);
        $schedule->restore();
        $schedule->forceFill(['active' => true])->save();
        $this->audit->log('schedule.restored', $schedule, "Horario reactivado: {$schedule->name}");
        $this->toast('Horario reactivado.');

        return back();
    }

    /**
     * Sustituye los segmentos sin borrado físico: actualiza los existentes por
     * (día, orden), reactiva los dados de baja y da de baja lógica los que sobran.
     *
     * @param  list<array{day_index:int, ordinal:int, starts_at:string, ends_at:string, break_minutes:int}>  $segments
     */
    private function syncSegments(Schedule $schedule, array $segments): void
    {
        $existing = $schedule->segments()->withTrashed()->get()->keyBy(fn (ScheduleSegment $x) => $x->day_index.'-'.$x->ordinal);
        $keep = [];
        foreach ($segments as $segment) {
            $key = $segment['day_index'].'-'.$segment['ordinal'];
            $keep[] = $key;
            /** @var ScheduleSegment|null $row */
            $row = $existing->get($key);
            if ($row === null) {
                $schedule->segments()->create($segment);

                continue;
            }
            if ($row->trashed()) {
                $row->restore();
            }
            $row->fill($segment)->save();
        }
        foreach ($existing as $key => $row) {
            if (! in_array($key, $keep, true) && ! $row->trashed()) {
                $row->delete();
            }
        }
    }

    /**
     * @return list<array{value:string, label:string, description:string}>
     */
    public static function typeOptions(): array
    {
        $labels = [
            'fixed' => ['Fijo', 'Mismo horario cada semana'],
            'rotating' => ['Rotativo', 'Turnos que rotan en un ciclo'],
            'night' => ['Nocturno', 'Jornada entre 20:00 y 06:00'],
            'mixed' => ['Mixto', 'Combina periodo diurno y nocturno'],
            'flexible' => ['Flexible', 'Entrada con ventana de flexibilidad'],
            'split' => ['Partido', 'Dos o más segmentos en el día'],
            'cycle' => ['Por ciclos', 'Ciclos de N días (p. ej. 4×3)'],
            'on_call' => ['Guardias', 'Guardias programadas por excepción'],
            'variable' => ['Variable', 'Se programa por día mediante excepciones'],
        ];

        return array_map(fn (ScheduleType $t) => ['value' => $t->value, 'label' => $labels[$t->value][0], 'description' => $labels[$t->value][1]], ScheduleType::cases());
    }

    /**
     * Fechas de entrada en vigor de las próximas versiones legales.
     *
     * @return list<string>
     */
    private function outlookDates(): array
    {
        return $this->validator->futureTramoDates(4);
    }

    /**
     * @param  list<string>  $outlook
     * @return array<string, mixed>
     */
    private function legalSummary(array $outlook): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $current = $this->law->for($today);

        return [
            'today' => $today,
            'version' => $current->versionCode,
            'verification_status' => $current->verificationStatus,
            'weekly_max' => $current->weeklyOrdinaryMaxMinutes,
            'daily_max' => ['day' => $current->dailyMaxDay, 'night' => $current->dailyMaxNight, 'mixed' => $current->dailyMaxMixed],
            'night_window' => ['start' => $current->nightStart, 'end' => $current->nightEnd],
            'upcoming' => array_map(function (string $date) {
                $p = $this->law->for($date);

                return ['date' => $date, 'version' => $p->versionCode, 'weekly_max' => $p->weeklyOrdinaryMaxMinutes];
            }, $outlook),
        ];
    }

    /**
     * @param  list<string>  $outlook
     * @return array<string, mixed>
     */
    private function scheduleData(Schedule $s, array $outlook): array
    {
        $segments = array_values($s->segments->map(fn (ScheduleSegment $x) => [
            'day_index' => (int) $x->day_index,
            'ordinal' => (int) $x->ordinal,
            'starts_at' => substr((string) $x->starts_at, 0, 5),
            'ends_at' => substr((string) $x->ends_at, 0, 5),
            'break_minutes' => (int) $x->break_minutes,
        ])->all());

        $evaluation = $this->validator->evaluate(
            ['cycle_length_days' => $s->cycle_length_days, 'shift_kind' => $s->shift_kind->value],
            $segments,
            [CarbonImmutable::today()->toDateString(), ...$outlook],
        );

        return [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
            'type' => $s->type->value,
            'shift_kind' => $s->shift_kind->value,
            'cycle_length_days' => (int) $s->cycle_length_days,
            'cycle_anchor_date' => $s->cycle_anchor_date?->toDateString(),
            'tolerance_minutes' => (int) $s->tolerance_minutes,
            'flex_minutes' => (int) $s->flex_minutes,
            'description' => $s->description,
            'active' => (bool) $s->active && ! $s->trashed(),
            'deleted_at' => $s->deleted_at?->toIso8601String(),
            'segments' => $segments,
            'weekly_minutes' => $evaluation['weekly_minutes'],
            'checks' => $evaluation['checks'],
            'compliant_today' => $this->validator->evaluate(['cycle_length_days' => $s->cycle_length_days, 'shift_kind' => $s->shift_kind->value], $segments, [CarbonImmutable::today()->toDateString()])['violations'] === [],
            'violations' => $evaluation['violations']['segments'] ?? [],
            'outlook' => $this->validator->outlook(['cycle_length_days' => $s->cycle_length_days, 'shift_kind' => $s->shift_kind->value], $segments, $outlook),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attrs = Arr::only($data, ['name', 'code', 'type', 'shift_kind', 'cycle_length_days', 'cycle_anchor_date', 'tolerance_minutes', 'flex_minutes', 'description']);
        $attrs['active'] = (bool) ($data['active'] ?? true);
        if ((int) $attrs['cycle_length_days'] === 7 && empty($attrs['cycle_anchor_date'])) {
            $attrs['cycle_anchor_date'] = null;
        }
        $attrs['shift_kind'] = ShiftKind::from($attrs['shift_kind']);

        return $attrs;
    }
}
