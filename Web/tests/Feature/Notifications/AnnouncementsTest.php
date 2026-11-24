<?php

use App\Domain\Notifications\Jobs\DeliverAnnouncement;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\Models\AnnouncementRecipient;
use App\Domain\Notifications\NotificationSettings;
use App\Domain\Notifications\PushSender;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsWorkforce;
use Tests\Feature\Api\Concerns\ContractShapes;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 16:00:00', 'UTC'));
    $this->org = $this->organization();
    $this->hr = $this->userIn($this->org, 'Recursos Humanos', allBranches: true);

    $this->north = Branch::query()->create(['name' => 'Norte', 'code' => 'NTE']);
    $this->south = Branch::query()->create(['name' => 'Sur', 'code' => 'SUR']);
    $this->sales = Department::query()->create(['name' => 'Ventas', 'code' => 'VTA']);
    $this->cashier = Position::query()->create(['name' => 'Cajero', 'code' => 'CAJ']);

    $this->a = $this->employee($this->org, branch: $this->north);
    $this->b = $this->employee($this->org, branch: $this->north);
    $this->c = $this->employee($this->org, branch: $this->south);
    $this->c->employmentOn('2026-11-17')->assignmentOn('2026-11-17')->update(['department_id' => $this->sales->id, 'position_id' => $this->cashier->id]);
});

function announce(array $overrides = []): array
{
    return $overrides + [
        'title' => 'Inventario',
        'body' => 'El viernes cerramos a las 15:00 por inventario.',
        'priority' => 'normal',
        'audience_type' => 'all',
        'audience_ids' => [],
        'send_mode' => 'now',
    ];
}

function recipientsOf(): array
{
    return AnnouncementRecipient::query()->orderBy('employee_id')->pluck('employee_id')->all();
}

it('envía a todo el personal y entrega en la bandeja de la app', function () {
    $this->actingAs($this->hr)->post('/comunicados', announce())->assertRedirect()->assertSessionHasNoErrors();

    $announcement = Announcement::query()->sole();
    expect($announcement->status)->toBe('sent')
        ->and(recipientsOf())->toBe([$this->a->id, $this->b->id, $this->c->id])
        ->and(AnnouncementRecipient::query()->whereNotNull('delivered_at')->count())->toBe(3)
        ->and(AnnouncementRecipient::query()->value('push_status'))->toBe('disabled');

    $n = $this->a->user->notifications()->sole();
    expect($n->data['category'])->toBe('message')
        ->and($n->data['title'])->toBe('Inventario')
        ->and($n->data['data'])->toBe(['announcement_id' => $announcement->uuid, 'requires_confirmation' => '0']);
});

it('segmenta por sucursal, departamento, puesto y selección', function (string $type, string $prop, array $expected) {
    $ids = $type === 'employees' ? [$this->b->id] : [$this->{$prop}->id];
    $this->actingAs($this->hr)->post('/comunicados', announce(['audience_type' => $type, 'audience_ids' => $ids]))->assertSessionHasNoErrors();

    $want = array_map(fn ($k) => $this->{$k}->id, $expected);
    sort($want);
    expect(recipientsOf())->toBe($want);
})->with([
    'sucursal' => ['branches', 'north', ['a', 'b']],
    'departamento' => ['departments', 'sales', ['c']],
    'puesto' => ['positions', 'cashier', ['c']],
    'selección' => ['employees', 'b', ['b']],
]);

it('respeta el alcance del remitente', function () {
    $manager = $this->userIn($this->org, 'Gerente de sucursal');
    $manager->branches()->attach($this->south->id, ['organization_id' => $this->org->id]);

    $this->actingAs($manager)->post('/comunicados', announce())->assertSessionHasNoErrors();
    expect(recipientsOf())->toBe([$this->c->id]);

    $this->actingAs($manager)->post('/comunicados', announce(['audience_type' => 'employees', 'audience_ids' => [$this->a->id]]))
        ->assertSessionHasErrors('audience_ids');
});

it('aísla organizaciones', function () {
    $orgB = $this->organization('Empresa B');
    $branchB = Branch::query()->create(['name' => 'Ajena', 'code' => 'AJE']);
    $foreign = $this->employee($orgB, branch: $branchB);
    $this->actingInOrganization($this->org);

    $this->actingAs($this->hr)->post('/comunicados', announce(['audience_type' => 'branches', 'audience_ids' => [$branchB->id]]))
        ->assertSessionHasErrors('audience_ids.0');
    $this->actingAs($this->hr)->post('/comunicados', announce(['audience_type' => 'employees', 'audience_ids' => [$foreign->id]]))
        ->assertSessionHasErrors('audience_ids.0');

    $this->actingAs($this->hr)->post('/comunicados', announce())->assertSessionHasNoErrors();
    expect(recipientsOf())->not->toContain($foreign->id)
        ->and($foreign->user->notifications()->count())->toBe(0);

    // Un usuario de B no ve los comunicados de A.
    $hrB = $this->userIn($orgB, 'Recursos Humanos', allBranches: true);
    $id = Announcement::query()->value('id');
    $this->actingInOrganization($orgB);
    $this->actingAs($hrB)->get("/comunicados/{$id}")->assertNotFound();
});

it('la entrega es idempotente por destinatario', function () {
    $this->actingAs($this->hr)->post('/comunicados', announce(['audience_type' => 'employees', 'audience_ids' => [$this->a->id]]));
    $recipient = AnnouncementRecipient::query()->sole();

    DeliverAnnouncement::dispatchSync($recipient->id);
    (new DeliverAnnouncement($recipient->id))->handle(app(PushSender::class), app(CurrentOrganization::class));

    expect($this->a->user->notifications()->count())->toBe(1)
        ->and($recipient->fresh()->notification_id)->toBe($this->a->user->notifications()->value('id'));
});

it('programa el envío y lo despacha a su hora', function () {
    $this->actingAs($this->hr)->post('/comunicados', announce(['send_mode' => 'scheduled', 'scheduled_date' => '2026-11-17', 'scheduled_time' => '12:00']))
        ->assertSessionHasNoErrors();

    $announcement = Announcement::query()->sole();
    expect($announcement->status)->toBe('scheduled')
        ->and($announcement->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-17 18:00')
        ->and(AnnouncementRecipient::query()->count())->toBe(0);

    Artisan::call('asistencias:notificaciones', ['--solo-comunicados' => true]);
    expect(AnnouncementRecipient::query()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-17 18:01:00', 'UTC'));
    Artisan::call('asistencias:notificaciones', ['--solo-comunicados' => true]);
    Artisan::call('asistencias:notificaciones', ['--solo-comunicados' => true]);

    expect($announcement->fresh()->status)->toBe('sent')
        ->and(AnnouncementRecipient::query()->count())->toBe(3)
        ->and($this->a->user->notifications()->count())->toBe(1);

    // Hora pasada: error.
    $this->actingAs($this->hr)->post('/comunicados', announce(['send_mode' => 'scheduled', 'scheduled_date' => '2026-11-17', 'scheduled_time' => '08:00']))
        ->assertSessionHasErrors('scheduled_time');
});

it('retirar oculta el comunicado en la app sin borrarlo y se puede reactivar', function () {
    $this->actingAs($this->hr)->post('/comunicados', announce(['audience_type' => 'employees', 'audience_ids' => [$this->a->id]]));
    $announcement = Announcement::query()->sole();
    $user = $this->a->user;

    $token = $user->createToken('device:x', ['mobile'], now()->addDay())->plainTextToken;
    $this->app['auth']->forgetGuards();
    $items = $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()->json('data');
    expect($items)->toHaveCount(1);
    ContractShapes::assert($items[0], 'AppNotification');

    // Lectura en la app → se refleja en el historial.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson("/api/v1/notifications/{$items[0]['id']}/read")->assertNoContent();
    $this->actingAs($this->hr)->get("/comunicados/{$announcement->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('comunicados/Show')->where('announcement.stats.read', 1)->where('announcement.stats.delivered', 1));

    $this->actingAs($this->hr)->post("/comunicados/{$announcement->id}/retirar")->assertRedirect();
    $this->app['auth']->forgetGuards();
    expect($this->withToken($token)->getJson('/api/v1/notifications')->json('data'))->toHaveCount(0)
        ->and(Announcement::query()->count())->toBe(1)
        ->and(DB::table('notifications')->count())->toBe(1);

    $this->actingAs($this->hr)->post("/comunicados/{$announcement->id}/reactivar")->assertRedirect();
    $this->app['auth']->forgetGuards();
    expect($this->withToken($token)->getJson('/api/v1/notifications')->json('data'))->toHaveCount(1);
});

it('exige permisos de comunicados', function () {
    $collaborator = $this->a->user;
    $this->actingAs($collaborator)->get('/comunicados')->assertForbidden();
    $this->actingAs($collaborator)->post('/comunicados', announce())->assertForbidden();

    $supervisor = $this->userIn($this->org, 'Supervisor');
    $this->actingAs($supervisor)->get('/comunicados')->assertForbidden();

    $authority = $this->userIn($this->org, 'Autoridad laboral');
    $this->actingAs($authority)->get('/comunicados')->assertForbidden();

    $manager = $this->userIn($this->org, 'Gerente de sucursal');
    $this->actingAs($manager)->get('/comunicados')->assertOk();
    $this->actingAs($manager)->get('/comunicados/nuevo')->assertOk();

    $this->actingAs($this->hr)->get('/comunicados')->assertOk()->assertInertia(fn ($p) => $p->component('comunicados/Index')->where('push.enabled', false));
    expect(Announcement::query()->count())->toBe(0);
});

it('guarda los ajustes de avisos automáticos', function () {
    $this->actingAs($this->hr)->put('/comunicados/ajustes', [
        'late_enabled' => true, 'late_grace_minutes' => 20, 'clock_out_enabled' => false, 'clock_out_minutes_after' => 30,
    ])->assertSessionHasNoErrors();

    $settings = app(NotificationSettings::class)->all();
    expect($settings['late'])->toBe(['enabled' => true, 'grace_minutes' => 20])
        ->and($settings['clock_out'])->toBe(['enabled' => false, 'minutes_after' => 30]);
});

it('el push del comunicado registra token muerto sin borrarlo', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config(['fcm.enabled' => true, 'fcm.project_id' => 'p', 'fcm.credentials_json' => json_encode(['client_email' => 'x@p.iam.gserviceaccount.com', 'private_key' => $pem])]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    ['device' => $device] = $this->approvedDevice($this->a->user, $this->a);
    $device->forceFill(['push_token' => 'tok_aaaaaaaaaaaaaaaaaaaaaaaa', 'push_provider' => 'fcm'])->save();

    $this->actingAs($this->hr)->post('/comunicados', announce(['audience_type' => 'employees', 'audience_ids' => [$this->a->id], 'priority' => 'high']));

    $recipient = AnnouncementRecipient::query()->sole();
    expect($recipient->delivered_at)->not->toBeNull()
        ->and($recipient->push_status)->toBe('no_device')
        ->and($device->fresh()->push_token)->toBe('tok_aaaaaaaaaaaaaaaaaaaaaaaa')
        ->and($device->fresh()->push_token_invalidated_at)->not->toBeNull();
});
