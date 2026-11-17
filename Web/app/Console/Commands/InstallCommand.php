<?php

namespace App\Console\Commands;

use App\Domain\Organization\OrganizationProvisioner;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\PermissionRegistrar;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('asistencias:instalar {--organizacion= : Nombre de la organización} {--nombre= : Nombre del administrador} {--email= : Correo del administrador} {--zona=America/Mexico_City : Zona horaria}')]
#[Description('Crea la organización inicial y su primer administrador de forma segura (sin contraseñas en código)')]
final class InstallCommand extends Command
{
    public function handle(OrganizationProvisioner $provisioner): int
    {
        $this->call('db:seed', ['--force' => true]);

        $orgName = $this->option('organizacion') ?: text('Nombre de la organización', required: true);
        $name = $this->option('nombre') ?: text('Nombre del administrador', required: true);
        $email = $this->option('email') ?: text('Correo del administrador', required: true);

        $secret = env('INSTALL_ADMIN_PASSWORD') ?: password('Contraseña (mín. 12, mayúsculas, números y símbolos)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'password' => $secret],
            ['email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($provisioner, $orgName, $name, $email, $secret) {
            $organization = $provisioner->provision($orgName, (string) $this->option('zona'));

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $secret,
                'current_organization_id' => $organization->id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->organizations()->attach($organization->id, ['status' => 'active', 'access_all_branches' => true]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
            $user->assignRole('Administrador de empresa');
        });

        $this->info("Organización «{$orgName}» y administrador {$email} creados.");

        return self::SUCCESS;
    }
}
