<?php

use App\Domain\Legal\HolidayGenerator;
use App\Domain\Legal\LegalFramework;
use App\Models\AuditLog;
use App\Models\LegalVersion;
use App\Models\LegalVersionRevision;
use Carbon\CarbonImmutable;
use Database\Seeders\LegalFrameworkSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

/*
| Normativa (PDF pantallas 14–16 y 19): tabla por año, fuente única, editable
| sólo por RH, «Verificada por RH» con usuario y fecha reales, historial.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 18:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $this->current = LegalVersion::query()->where('code', 'LFT-2026')->firstOrFail();
});

function legalPayload(array $overrides = []): array
{
    return array_merge([
        'weekly_ordinary_max_minutes' => 2880, 'daily_max_day' => 480, 'daily_max_night' => 420, 'daily_max_mixed' => 450,
        'overtime_daily_max' => 240, 'overtime_weekly_max' => 540, 'overtime_max_days_per_week' => 4,
        'overtime_excess_weekly_max' => 240, 'daily_total_max' => 720, 'note' => 'Ajuste por publicación DOF.',
    ], $overrides);
}

it('nunca se precarga como verificada y el seeder no sobrescribe ediciones de RH', function () {
    expect(LegalVersion::query()->where('verification_status', 'verified')->count())->toBe(0)
        ->and(LegalVersion::query()->whereNotNull('verified_by')->count())->toBe(0);

    // Tabla por año con cambio automático el 1 de enero de cada tramo.
    $law = app(LegalFramework::class);
    expect($law->for('2026-12-31')->weeklyOrdinaryMaxMinutes)->toBe(2880)
        ->and($law->for('2027-01-01')->weeklyOrdinaryMaxMinutes)->toBe(2760)
        ->and($law->for('2028-01-01')->overtimeWeeklyMax)->toBe(600)
        ->and($law->for('2030-06-01')->weeklyOrdinaryMaxMinutes)->toBe(2400)
        ->and($law->for('2027-03-01')->dailyTotalMax)->toBe(720)
        ->and($law->for('2027-03-01')->minorsOvertimeAllowed)->toBeFalse();

    $status = $law->verificationFor('2026-11-24');
    expect($status['verified'])->toBeFalse()->and($status['label'])->toBe('Normativa en verificación');

    $this->actingAs($this->hr)->put("/configuracion/normativa/{$this->current->id}", legalPayload(['overtime_weekly_max' => 500]))->assertSessionHasNoErrors();
    app(LegalFrameworkSeeder::class)->run(app(HolidayGenerator::class));

    expect((int) $this->current->fresh()->parameters['overtime']['weekly_max_minutes'])->toBe(500);
});

it('RH edita la normativa: queda sin verificar, con historial y bitácora (antes y después)', function () {
    $this->actingAs($this->hr)->put("/configuracion/normativa/{$this->current->id}", legalPayload(['overtime_weekly_max' => 480]))
        ->assertSessionHasNoErrors();

    $v = $this->current->fresh();
    expect((int) $v->parameters['overtime']['weekly_max_minutes'])->toBe(480)
        ->and($v->verification_status)->toBe('unverified')
        ->and($v->updated_by)->toBe($this->hr->id)
        ->and(hash_equals($v->checksum, LegalFramework::checksum($v->parameters)))->toBeTrue();

    $revision = LegalVersionRevision::query()->where('legal_version_id', $v->id)->firstOrFail();
    expect($revision->action)->toBe('updated')
        ->and((int) $revision->before['overtime']['weekly_max_minutes'])->toBe(540)
        ->and((int) $revision->after['overtime']['weekly_max_minutes'])->toBe(480);
    expect(fn () => DB::table('legal_version_revisions')->where('id', $revision->id)->delete())->toThrow(QueryException::class);

    $log = AuditLog::query()->where('action', 'settings.legal_updated')->firstOrFail();
    expect($log->old_values['overtime_weekly_max'])->toBe(540)
        ->and($log->new_values['overtime_weekly_max'])->toBe(480)
        ->and($log->user_id)->toBe($this->hr->id);
});

it('RH la marca «Verificada por RH» con su usuario y la fecha real; un cambio posterior la regresa a sin verificar', function () {
    $this->actingAs($this->hr)->post("/configuracion/normativa/{$this->current->id}/verificar", ['note' => 'Dictamen Jurídico 12/2026'])
        ->assertSessionHasNoErrors();

    $v = $this->current->fresh();
    expect($v->isVerified())->toBeTrue()
        ->and($v->verified_by)->toBe($this->hr->id)
        ->and($v->verified_at->toDateTimeString())->toBe('2026-11-24 18:00:00');

    $status = app(LegalFramework::class)->verificationFor('2026-11-24');
    expect($status['verified'])->toBeTrue()
        ->and($status['label'])->toBe('Verificada por RH')
        ->and($status['verified_by'])->toBe($this->hr->name);

    $this->actingAs($this->hr)->get('/configuracion/normativa')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('configuracion/Legal')
            ->where('current.verified', true)
            ->where('can.manage', true)
            ->where('history.0.action', 'verified'));

    $this->actingAs($this->hr)->put("/configuracion/normativa/{$this->current->id}", legalPayload(['daily_total_max' => 700]))->assertSessionHasNoErrors();
    expect($this->current->fresh()->isVerified())->toBeFalse();
});

it('sólo RH edita o verifica la normativa', function () {
    foreach (['Administrador de plataforma', 'Contraloría', 'Gerente de sucursal'] as $role) {
        $user = $this->userIn($this->org, $role, allBranches: true);
        $this->actingAs($user)->put("/configuracion/normativa/{$this->current->id}", legalPayload())->assertForbidden();
        $this->actingAs($user)->post("/configuracion/normativa/{$this->current->id}/verificar", ['note' => 'Intento sin permiso'])->assertForbidden();
    }
    expect($this->current->fresh()->isVerified())->toBeFalse();
});

it('no permite editar versiones concluidas', function () {
    $past = LegalVersion::query()->where('code', 'LFT-PRE-2026')->firstOrFail();

    $this->actingAs($this->hr)->put("/configuracion/normativa/{$past->id}", legalPayload())->assertSessionHasErrors('version');
});
