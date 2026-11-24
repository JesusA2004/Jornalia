<?php

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\IntegrityAnchorService;
use App\Domain\Attendance\IntegrityVerifier;
use App\Domain\Attendance\RectificationService;
use App\Domain\Signatures\SignaturePinService;
use App\Enums\ApprovalStatus;
use App\Enums\PunchType;
use App\Enums\ShiftKind;
use App\Enums\WorkdayStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\Punch;
use App\Models\WorkdaySummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->seedLegal();
    $this->org = $this->organization();
    $this->schedule = $this->schedule('09:00', '18:00', 60);
    $this->employee = $this->employee($this->org, schedule: $this->schedule);
});

it('registra entrada y salida con cadena de hash y calcula la jornada', function () {
    // Martes 2026-11-17, Ciudad de México (UTC-6): 09:05 → 18:30 local
    $in = $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:05:00');
    $out = $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 00:30:00');

    expect($in->sequence)->toBe(1)
        ->and($out->sequence)->toBe(2)
        ->and($out->previous_hash)->toBe($in->hash)
        ->and($in->local_date->toDateString())->toBe('2026-11-17');

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->worked_minutes)->toBe(565)       // 9 h 25 min de presencia; descanso no registrado no se descuenta
        ->and($day->late_minutes)->toBe(0)        // dentro de tolerancia (10 min)
        ->and($day->excess_minutes)->toBe(25)     // sobre el lapso programado de 540 min
        ->and($day->status)->toBe(WorkdayStatus::Present);
});

it('marca retardo sin alterar el marcaje original', function () {
    $in = $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:25:00'); // 09:25
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 00:00:00');

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->late_minutes)->toBe(25)->and($day->status)->toBe(WorkdayStatus::Late)
        ->and(Punch::query()->find($in->id)->server_time->format('H:i'))->toBe('15:25');
});

it('rechaza secuencias inválidas en línea (doble entrada)', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:01:00');
})->throws(AttendanceException::class, 'Secuencia de marcaje inválida');

it('impide modificar o eliminar marcajes en el dominio', function () {
    $punch = $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');

    expect(fn () => $punch->update(['type' => PunchType::ClockOut]))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $punch->delete())->toThrow(ImmutableRecordException::class);
});

it('impide modificar o eliminar marcajes a nivel de base de datos (triggers)', function () {
    $punch = $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');

    expect(fn () => DB::table('attendance_punches')->where('id', $punch->id)->update(['server_time' => '2026-11-17 14:00:00']))
        ->toThrow(QueryException::class, 'Registro inmutable')
        ->and(fn () => DB::table('attendance_punches')->where('id', $punch->id)->delete())
        ->toThrow(QueryException::class, 'Registro inmutable');
});

it('detecta alteraciones de la cadena incluso con acceso directo a la base', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->punchAt($this->employee, PunchType::ClockOut, '2026-11-18 00:00:00');
    app(IntegrityAnchorService::class)->anchor($this->org->id);

    $verifier = app(IntegrityVerifier::class);
    expect($verifier->verify($this->org->id)['valid'])->toBeTrue();

    // Alguien con acceso directo a la BD (sin la clave de sellado) inserta un
    // marcaje fabricado encadenado al último, calculando un sha256 plano.
    $last = DB::table('attendance_punches')->where('organization_id', $this->org->id)->orderByDesc('sequence')->first();
    $forged = (array) $last;
    unset($forged['id']);
    $forged = array_merge($forged, [
        'uuid' => (string) Str::uuid(), 'client_uuid' => (string) Str::uuid(),
        'sequence' => $last->sequence + 1, 'previous_hash' => $last->hash,
        'server_time' => '2026-11-18 02:00:00', 'type' => 'clock_out',
        'hash' => hash('sha256', 'falsificado'),
    ]);
    DB::table('attendance_punches')->insert($forged);

    $result = $verifier->verify($this->org->id);
    expect($result['valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('error'))->toContain('Contenido alterado: el hash no coincide');
});

it('rectifica un marcaje omitido conservando el original y recalculando', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    // Omitió la salida. Al día siguiente solicita rectificación.
    $this->travelTo('2026-11-18 16:00:00');

    Storage::fake('local');
    $service = app(RectificationService::class);
    $request = $service->submit($this->employee->user, $this->employee, [
        'date' => '2026-11-17', 'punch_uuid' => null, 'kind' => 'missing_punch',
        'proposed_type' => 'clock_out', 'proposed_time' => '2026-11-18T00:00:00Z', 'reason' => 'Olvidé marcar la salida.',
    ], [UploadedFile::fake()->image('bitacora.jpg')]);

    // Jefe autoriza → colaborador firma con NIP → RH valida y aplica.
    $manager = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $service->authorize($manager, $request, true, 'Confirmado con supervisor.');
    app(SignaturePinService::class)->set($this->employee->user, '4826');
    $service->sign($this->employee->user, $request->refresh(), 'pin', '4826', null);
    $hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);
    $service->validate($hr, $request->refresh(), true, 'Validado.');

    expect($request->refresh()->status)->toBe(ApprovalStatus::Approved)
        ->and(Punch::query()->count())->toBe(1);

    $day = WorkdaySummary::query()->where('employee_id', $this->employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->worked_minutes)->toBe(540)->and($day->has_rectifications)->toBeTrue();
    expect(app(IntegrityVerifier::class)->verify($this->org->id, 'adjustments')['valid'])->toBeTrue();
});

it('no permite autoaprobar una rectificación', function () {
    $this->punchAt($this->employee, PunchType::ClockIn, '2026-11-17 15:00:00');
    $this->travelTo('2026-11-18 16:00:00');
    Storage::fake('local');
    $service = app(RectificationService::class);
    $request = $service->submit($this->employee->user, $this->employee, [
        'date' => '2026-11-17', 'punch_uuid' => null, 'kind' => 'missing_punch',
        'proposed_type' => 'clock_out', 'proposed_time' => '2026-11-18T00:00:00Z', 'reason' => 'x',
    ], [UploadedFile::fake()->image('bitacora.jpg')]);
    $service->authorize($this->employee->user, $request, true, null);
})->throws(AuthorizationException::class);

it('atribuye turnos nocturnos que cruzan medianoche al día de inicio', function () {
    $night = $this->schedule('22:00', '05:00', 0, [0, 1, 2, 3, 4], ShiftKind::Night);
    $employee = $this->employee($this->org, schedule: $night);

    $this->punchAt($employee, PunchType::ClockIn, '2026-11-18 04:00:00');  // 22:00 local del martes 17
    $this->punchAt($employee, PunchType::ClockOut, '2026-11-18 11:00:00'); // 05:00 local del miércoles 18

    $day = WorkdaySummary::query()->where('employee_id', $employee->id)->whereDate('date', '2026-11-17')->firstOrFail();
    expect($day->worked_minutes)->toBe(420)
        ->and($day->night_minutes)->toBe(420)
        ->and($day->excess_minutes)->toBe(0);
});
