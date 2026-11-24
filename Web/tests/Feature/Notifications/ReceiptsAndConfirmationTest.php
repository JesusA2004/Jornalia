<?php

use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementRecipient;
use App\Enums\PunchType;
use App\Models\AuditLog;
use App\Notifications\PunchReceipt;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\MobileApi;

uses(MobileApi::class);

/*
| Comunicados (PDF pantalla 12): acuse automático y obligatorio de cada marcaje
| (bandeja aunque no haya push) y confirmación de lectura de comunicados importantes.
*/

beforeEach(function () {
    $this->setUpMobile();
    $this->withoutVite();
    config(['fcm.enabled' => false]);
});

it('cada marcaje deja un acuse en la bandeja de la app aunque el push no esté configurado', function () {
    $this->punchAt($this->emp, PunchType::ClockIn, '2026-11-17 15:02:00');
    $this->punchAt($this->emp, PunchType::ClockOut, '2026-11-17 23:58:00');

    $items = $this->mobile('GET', '/notifications')->assertOk()->json('data');
    $receipts = array_values(array_filter($items, fn ($n) => $n['kind'] === PunchReceipt::KIND));

    expect($receipts)->toHaveCount(2)
        ->and(collect($receipts)->pluck('title')->sort()->values()->all())->toBe(['Entrada registrada', 'Salida registrada'])
        ->and($receipts[0]['category'])->toBe('attendance')
        ->and($receipts[0]['data'])->toHaveKeys(['punch_uuid', 'date', 'type', 'receipt_code']);

    // Hora oficial (servidor) en la zona del centro de trabajo: 09:02 en CDMX.
    $entry = collect($receipts)->firstWhere('title', 'Entrada registrada');
    expect($entry['body'])->toContain('09:02')->toContain('Folio ML-');
});

it('el comunicado importante pide confirmación de lectura y la registra desde la app', function () {
    $hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $this->actingAs($hr)->post('/comunicados', [
        'title' => 'Cambio de horario',
        'body' => 'A partir del lunes la sucursal abre a las 08:30.',
        'priority' => 'high',
        'requires_confirmation' => true,
        'audience_type' => 'all',
        'audience_ids' => [],
        'send_mode' => 'now',
    ])->assertSessionHasNoErrors();

    $announcement = Announcement::query()->sole();
    expect($announcement->requires_confirmation)->toBeTrue();

    $notice = collect($this->mobile('GET', '/notifications')->json('data'))->firstWhere('kind', 'announcement');
    expect($notice['data']['requires_confirmation'])->toBe('1')
        ->and($notice['data']['announcement_id'])->toBe($announcement->uuid);

    $res = $this->mobile('POST', "/announcements/{$announcement->uuid}/read")->assertOk()->json('data');
    expect($res['confirmed_at'])->not->toBeNull();
    // Idempotente.
    $this->mobile('POST', "/announcements/{$announcement->uuid}/read")->assertOk()->assertJsonPath('data.confirmed_at', $res['confirmed_at']);

    $recipient = AnnouncementRecipient::query()->where('employee_id', $this->emp->id)->sole();
    expect($recipient->confirmed_at)->not->toBeNull()->and($recipient->read_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'announcement.confirmed')->count())->toBe(1);

    $this->actingAs($hr)->get("/comunicados/{$announcement->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('announcement.requires_confirmation', true)
            ->where('announcement.stats.confirmed', 1));
});

it('no confirma comunicados ajenos ni retirados', function () {
    $hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);
    $other = $this->employee($this->org);
    $this->actingAs($hr)->post('/comunicados', [
        'title' => 'Sólo para otra persona', 'body' => 'Mensaje individual.', 'priority' => 'normal', 'requires_confirmation' => true,
        'audience_type' => 'employees', 'audience_ids' => [$other->id], 'send_mode' => 'now',
    ])->assertSessionHasNoErrors();
    $announcement = Announcement::query()->sole();

    $this->mobile('POST', "/announcements/{$announcement->uuid}/read")->assertNotFound();

    $announcement->forceFill(['withdrawn_at' => now()])->save();
    $this->mobile('POST', "/announcements/{$announcement->id}/read")->assertNotFound();
});

it('informa honestamente que el push no está configurado y que el acuse es obligatorio', function () {
    $hr = $this->userIn($this->org, 'Administrador RH', allBranches: true);

    $this->actingAs($hr)->get('/comunicados')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('push.enabled', false)->where('punch_receipt.mandatory', true));
    $this->actingAs($hr)->get('/configuracion/sistema')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('configuracion/System')
            ->where('notifications.push_configured', false)
            ->where('notifications.punch_receipt_mandatory', true));
});
