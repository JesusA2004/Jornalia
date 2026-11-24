<?php

use App\Domain\Security\PermissionCatalog;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsWorkforce;

uses(BuildsWorkforce::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seedLegal();
    $this->org = $this->organization();

    // Usuario administrativo SIN doble factor configurado.
    $this->adminWithout = function (string $role = PermissionCatalog::ROLE_PLATFORM_ADMIN) {
        $user = $this->userIn($this->org, PermissionCatalog::ROLE_EMPLOYEE, true);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->org->id);
        $user->syncRoles([$role]);

        return $user->fresh();
    };
});

it('redirige a Seguridad a un rol administrativo sin doble factor', function () {
    $admin = ($this->adminWithout)();

    $this->actingAs($admin)->get('/usuarios')->assertRedirect(route('security.edit'));
    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('security.edit'));

    $this->actingAs($admin)->get(route('security.edit'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Security')
            ->where('twoFactorRequired', true)
            ->where('twoFactorEnabled', false));
});

it('también aplica a RH y no a colaboradores', function () {
    $hr = ($this->adminWithout)(PermissionCatalog::ROLE_HR_ADMIN);
    $this->actingAs($hr)->get('/usuarios')->assertRedirect(route('security.edit'));

    $collaborator = $this->userIn($this->org, PermissionCatalog::ROLE_EMPLOYEE);
    $response = $this->actingAs($collaborator)->get('/dashboard');
    expect((string) $response->headers->get('Location'))->not->toContain('settings/security');
});

it('permite operar una vez configurado y no deja desactivarlo', function () {
    $admin = ($this->adminWithout)();

    $this->actingAs($admin)->post('/user/two-factor-authentication')->assertSessionHasNoErrors();
    $admin->refresh();
    expect($admin->two_factor_secret)->not->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($admin->two_factor_secret));
    $this->actingAs($admin)->post('/user/confirmed-two-factor-authentication', ['code' => $code])->assertSessionHasNoErrors();
    expect($admin->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($admin->fresh())->get('/usuarios')->assertOk();

    $this->actingAs($admin->fresh())->delete('/user/two-factor-authentication')->assertSessionHasErrors('two_factor');
    expect($admin->fresh()->two_factor_secret)->not->toBeNull();
});

it('pide el código al iniciar sesión cuando el doble factor está activo', function () {
    $admin = $this->userIn($this->org, PermissionCatalog::ROLE_PLATFORM_ADMIN, true);

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    $this->get(route('two-factor.login'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorChallenge'));
});
