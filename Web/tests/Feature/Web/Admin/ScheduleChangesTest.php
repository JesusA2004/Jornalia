<?php

use App\Domain\Scheduling\ScheduleResolver;
use App\Domain\Security\PermissionCatalog;
use App\Enums\PunchType;
use App\Models\AuditLog;
use App\Models\Punch;
use App\Models\ScheduleException;
use App\Models\WorkdaySummary;
use App\Notifications\ScheduleChangeNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->org = $this->organization();
    $this->employee = $this->employee($this->org, schedule: $this->schedule('09:00', '18:00', 60));
    $this->requester = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    $this->approver = $this->userIn($this->org, PermissionCatalog::ROLE_HR_ADMIN, allBranches: true);
    // Miércoles 2026-11-18 12:00 en Ciudad de México.
    $this->now = CarbonImmutable::parse('2026-11-18 12:00', 'America/Mexico_City');
});

function changePayload($employee, string $date, array $extra = []): array
{
    return [
        'employee_id' => $employee->id, 'date' => $date, 'is_rest_day' => false,
        'starts_at' => '07:00', 'ends_at' => '15:00', 'break_minutes' => 30, 'reason_code' => 'inventory', ...$extra,
    ];
}

it('sólo acepta fechas desde mañana, con motivo de catálogo', function () {
    $this->travelTo($this->now);

    foreach (['2026-11-17', '2026-11-18'] as $date) {
        $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, $date))->assertSessionHasErrors('date');
    }
    $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, '2026-11-19', ['reason_code' => 'inventado']))
        ->assertSessionHasErrors('reason_code');
    $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, '2026-11-19', ['reason_code' => 'other', 'reason' => '']))
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, '2026-11-19'))->assertSessionHasNoErrors();
    expect(ScheduleException::query()->sole()->status)->toBe(ScheduleException::STATUS_PENDING);
});

it('no surte efecto hasta que RH lo autoriza, avisa al colaborador y queda en bitácora', function () {
    Notification::fake();
    $this->travelTo($this->now);
    $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, '2026-11-19'))->assertSessionHasNoErrors();
    $change = ScheduleException::query()->sole();
    $resolver = app(ScheduleResolver::class);

    expect($resolver->shiftFor($this->employee, '2026-11-19')?->fromException)->toBeFalse();

    // Quien solicita no autoriza; sin permiso tampoco.
    $this->actingAs($this->requester)->post("/horarios/cambios/{$change->id}/autorizar")->assertSessionHasErrors('change');
    $manager = $this->userIn($this->org, PermissionCatalog::ROLE_BRANCH_MANAGER, allBranches: true);
    $this->actingAs($manager)->post("/horarios/cambios/{$change->id}/autorizar")->assertForbidden();

    $this->actingAs($this->approver)->post("/horarios/cambios/{$change->id}/autorizar")->assertSessionHasNoErrors();

    $change->refresh();
    expect($change->status)->toBe(ScheduleException::STATUS_APPROVED)
        ->and($change->notified_at)->not->toBeNull()
        ->and($resolver->shiftFor($this->employee, '2026-11-19')?->fromException)->toBeTrue()
        ->and(AuditLog::query()->whereIn('action', ['schedule_change.requested', 'schedule_change.approved'])->count())->toBe(2);
    Notification::assertSentTo($this->employee->user, ScheduleChangeNotice::class);
});

it('no puede autorizarse cuando la fecha ya llegó (sería retroactivo)', function () {
    $this->travelTo($this->now);
    $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, '2026-11-19'))->assertSessionHasNoErrors();
    $change = ScheduleException::query()->sole();

    $this->travelTo(CarbonImmutable::parse('2026-11-19 08:00', 'America/Mexico_City'));
    $this->actingAs($this->approver)->post("/horarios/cambios/{$change->id}/autorizar")->assertSessionHasErrors('date');
    expect($change->fresh()->status)->toBe(ScheduleException::STATUS_PENDING);
});

it('un cambio de horario no modifica marcajes ni cálculos ya generados', function () {
    // Retardo de ayer (martes): entra 09:40 con horario 09:00.
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:40:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 00:05:00');
    $this->artisan('asistencias:cerrar-dia', ['--dias' => 2])->assertSuccessful();

    $this->travelTo($this->now);
    $punches = Punch::query()->orderBy('id')->get()->map->only(['id', 'hash', 'server_time', 'type'])->all();
    $summaries = WorkdaySummary::query()->orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'date', 'late_minutes', 'worked_minutes', 'computed_at']))->all();

    $this->actingAs($this->requester)->post('/horarios/cambios', changePayload($this->employee, '2026-11-19'))->assertSessionHasNoErrors();
    $this->actingAs($this->approver)->post('/horarios/cambios/'.ScheduleException::query()->sole()->id.'/autorizar')->assertSessionHasNoErrors();

    expect(Punch::query()->orderBy('id')->get()->map->only(['id', 'hash', 'server_time', 'type'])->all())->toEqual($punches)
        ->and(WorkdaySummary::query()->orderBy('id')->get()->map(fn ($s) => $s->only(['id', 'date', 'late_minutes', 'worked_minutes', 'computed_at']))->all())->toEqual($summaries);
});

it('la URL anterior de excepciones redirige a cambios programados', function () {
    $this->actingAs($this->requester)->get('/horarios/excepciones')->assertRedirect('/horarios/cambios');
});
