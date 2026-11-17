<?php

use App\Models\AuditLog;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(fn () => $this->seedLegal());

it('cambia la organización activa y lo audita', function () {
    $orgA = $this->organization('Empresa A');
    $user = $this->userIn($orgA);
    $orgB = $this->organization('Empresa B');
    $user->organizations()->attach($orgB->id, ['status' => 'active', 'access_all_branches' => false]);

    $this->actingAs($user->fresh())
        ->put('/organizacion/activa', ['organization_id' => $orgB->id])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_organization_id)->toBe($orgB->id)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'organization.switched_in')->where('organization_id', $orgB->id)->exists())->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'organization.switched_out')->where('organization_id', $orgA->id)->exists())->toBeTrue();
});

it('impide cambiar a una organización ajena', function () {
    $orgA = $this->organization('Empresa A');
    $user = $this->userIn($orgA);
    $foreign = $this->organization('Ajena');

    $this->actingAs($user->fresh())
        ->put('/organizacion/activa', ['organization_id' => $foreign->id])
        ->assertForbidden();

    expect($user->fresh()->current_organization_id)->toBe($orgA->id);
});

it('lista y marca como leídas sólo las notificaciones propias', function () {
    $org = $this->organization();
    $user = $this->userIn($org);
    $other = $this->userIn($org);

    $mine = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\RequestStatusChanged',
        'data' => ['title' => 'Solicitud aprobada', 'kind' => 'request.approved', 'subject' => 'rectification', 'subject_id' => 7],
        'created_at' => now()->subMinute(),
    ]);
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\RequestStatusChanged',
        'data' => ['title' => 'Otra'],
    ]);
    $foreign = $other->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\RequestStatusChanged',
        'data' => ['title' => 'Ajena'],
    ]);

    $this->actingAs($user->fresh())
        ->get('/notificaciones')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('notificaciones/Index', false)
            ->where('unread', 2)
            ->has('notifications.data', 2)
            ->where('notifications.data.1.url', '/rectificaciones/7'));

    $this->actingAs($user->fresh())->patch("/notificaciones/{$mine->id}/leida")->assertRedirect();
    expect($mine->fresh()->read_at)->not->toBeNull();

    $this->actingAs($user->fresh())->patch("/notificaciones/{$foreign->id}/leida")->assertNotFound();
    expect($foreign->fresh()->read_at)->toBeNull();

    $this->actingAs($user->fresh())->post('/notificaciones/leer-todas')->assertRedirect();
    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);
});
