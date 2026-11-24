<?php

use App\Domain\Notifications\Models\NotificationDispatch;
use App\Domain\Notifications\NotificationSettings;
use App\Enums\PunchType;
use App\Models\Holiday;
use App\Models\OrganizationSetting;
use App\Models\VacationRequest;
use App\Notifications\AttendanceReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/*
| Martes 2026-11-17. Turno 09:00–18:00 CDMX (15:00–24:00 UTC), tolerancia 10 + gracia 10.
*/
beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->employee = $this->employee($this->org, schedule: $this->schedule('09:00', '18:00', 60));
    $this->user = $this->employee->user;
});

function runReminders(string $utc): void
{
    test()->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    Artisan::call('asistencias:notificaciones', ['--solo-recordatorios' => true]);
}

function remindersOf($user, string $kind): int
{
    return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind)->count();
}

it('avisa una sola vez que la hora de entrada ya pasó', function () {
    runReminders('2026-11-17 15:15:00'); // dentro de tolerancia + gracia
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);

    runReminders('2026-11-17 15:21:00');
    runReminders('2026-11-17 15:26:00');
    runReminders('2026-11-17 16:01:00');

    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(1)
        ->and(NotificationDispatch::query()->where('type', AttendanceReminder::LATE_CLOCK_IN)->count())->toBe(1);

    $data = $this->user->notifications()->first()->data;
    expect($data['category'])->toBe('reminder')
        ->and($data['title'])->toBe('Tu hora de entrada ya pasó')
        ->and($data['body'])->toContain('09:00')
        ->and($data['data'])->toBe(['date' => '2026-11-17', 'action' => 'clock_in']);
});

it('no avisa si ya registró su entrada', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 14:55:00');
    runReminders('2026-11-17 15:30:00');

    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);
});

it('no avisa en día de descanso, festivo ni vacaciones', function () {
    // Sábado: descanso.
    runReminders('2026-11-21 15:30:00');
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);

    // Festivo de la organización (miércoles).
    Holiday::query()->create(['organization_id' => $this->org->id, 'date' => '2026-11-18', 'code' => 'EMPRESA', 'name' => 'Aniversario', 'kind' => 'company', 'is_mandatory_rest' => true]);
    runReminders('2026-11-18 15:30:00');
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);

    // Vacaciones aprobadas (jueves).
    VacationRequest::query()->create([
        'employee_id' => $this->employee->id,
        'employment_id' => $this->employee->employments()->value('id'),
        'service_year' => 2, 'starts_on' => '2026-11-19', 'ends_on' => '2026-11-19', 'days' => 1, 'status' => 'approved',
    ]);
    runReminders('2026-11-19 15:30:00');
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);

    // Viernes laborable: sí avisa.
    runReminders('2026-11-20 15:30:00');
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(1);
});

it('respeta la configuración por organización', function () {
    OrganizationSetting::query()->create(['key' => NotificationSettings::LATE_KEY, 'value' => ['enabled' => true, 'grace_minutes' => 30]]);
    runReminders('2026-11-17 15:25:00');
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);
    runReminders('2026-11-17 15:41:00');
    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(1);

    OrganizationSetting::query()->create(['key' => NotificationSettings::CLOCK_OUT_KEY, 'value' => ['enabled' => false, 'minutes_after' => 15]]);
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-18 15:00:00');
    runReminders('2026-11-19 00:30:00');
    expect(remindersOf($this->user, AttendanceReminder::CLOCK_OUT))->toBe(0);
});

it('recuerda marcar la salida tras el fin de turno con jornada abierta, una vez', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');

    runReminders('2026-11-18 00:10:00'); // 18:10 CDMX, antes de los 15 min
    expect(remindersOf($this->user, AttendanceReminder::CLOCK_OUT))->toBe(0);

    runReminders('2026-11-18 00:16:00');
    runReminders('2026-11-18 00:21:00');
    expect(remindersOf($this->user, AttendanceReminder::CLOCK_OUT))->toBe(1)
        ->and(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(0);
});

it('no recuerda la salida si ya la marcó', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 00:01:00');
    runReminders('2026-11-18 00:20:00');

    expect(remindersOf($this->user, AttendanceReminder::CLOCK_OUT))->toBe(0);
});

it('aísla los avisos por organización', function () {
    $orgB = $this->organization('Empresa B');
    $other = $this->employee($orgB, schedule: $this->schedule('09:00', '18:00', 60));
    $this->actingInOrganization($this->org);

    runReminders('2026-11-17 15:30:00');

    expect(remindersOf($this->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(1)
        ->and(remindersOf($other->user, AttendanceReminder::LATE_CLOCK_IN))->toBe(1)
        ->and(NotificationDispatch::query()->withoutGlobalScopes()->where('organization_id', $orgB->id)->where('employee_id', $other->id)->count())->toBe(1)
        ->and(NotificationDispatch::query()->withoutGlobalScopes()->where('organization_id', $orgB->id)->where('employee_id', $this->employee->id)->count())->toBe(0);
});
