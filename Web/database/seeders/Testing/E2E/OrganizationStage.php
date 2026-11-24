<?php

namespace Database\Seeders\Testing\E2E;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\OfficialCompanyData;
use App\Domain\Organization\OrganizationProvisioner;
use App\Domain\Security\PermissionCatalog;
use App\Domain\Security\TwoFactorPolicy;
use App\Domain\Security\UserAccessService;
use App\Domain\Workforce\ScheduleLegalValidator;
use App\Enums\LocationMode;
use App\Enums\ShiftKind;
use App\Models\AttendancePolicy;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Geofence;
use App\Models\Holiday;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Models\WorkCenter;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Spatie\Permission\PermissionRegistrar;

/**
 * Organización principal (datos oficiales permitidos), estructura FICTICIA de
 * prueba (centros de trabajo, sucursales con geocerca, departamentos, puestos),
 * horarios, políticas, usuarios web por rol con su alcance y doble factor, y la
 * segunda organización vacía.
 */
final class OrganizationStage
{
    /** Horarios: código => [nombre, tipo, turno, [día ISO 1..7 => [inicio, fin, descanso]]]. */
    public const SCHEDULES = [
        'S48' => ['Diurno 48 h (lunes a sábado)', 'fixed', 'day', [1 => ['09:00', '18:00', 60], 2 => ['09:00', '18:00', 60], 3 => ['09:00', '18:00', 60], 4 => ['09:00', '18:00', 60], 5 => ['09:00', '18:00', 60], 6 => ['09:00', '18:00', 60]]],
        'S44' => ['Diurno 44 h (sábado medio día)', 'fixed', 'day', [1 => ['09:00', '18:00', 60], 2 => ['09:00', '18:00', 60], 3 => ['09:00', '18:00', 60], 4 => ['09:00', '18:00', 60], 5 => ['09:00', '18:00', 60], 6 => ['09:00', '13:00', 0]]],
        'MIX' => ['Mixto vespertino 42 h', 'mixed', 'mixed', [1 => ['14:30', '22:00', 30], 2 => ['14:30', '22:00', 30], 3 => ['14:30', '22:00', 30], 4 => ['14:30', '22:00', 30], 5 => ['14:30', '22:00', 30], 6 => ['14:30', '22:00', 30]]],
        'S40' => ['Matutino 40 h (lunes a viernes)', 'fixed', 'day', [1 => ['08:00', '17:00', 60], 2 => ['08:00', '17:00', 60], 3 => ['08:00', '17:00', 60], 4 => ['08:00', '17:00', 60], 5 => ['08:00', '17:00', 60]]],
    ];

    /** Sucursales ficticias: código => [nombre, clave, región, centro, domicilio, lat, lng]. */
    public const BRANCHES = [
        'CUE' => ['Cuernavaca Centro', 'CUE-CEN', 'Morelos', 'MOR', 'Av. Morelos 210, Col. Centro, Cuernavaca, Morelos', 18.9218000, -99.2347000],
        'JIU' => ['Jiutepec', 'JIU-001', 'Morelos', 'MOR', 'Blvd. Cuauhnáhuac km 4.5, Jiutepec, Morelos', 18.8812000, -99.1776000],
        'CUA' => ['Sucursal Cuautla Plaza Comercial Oriente, Avenida Reforma esquina Insurgentes, Local 14-B', 'CUA-ORI', 'Morelos', 'MOR', 'Av. Reforma 1450, Col. Emiliano Zapata, Cuautla, Morelos', 18.8124000, -98.9551000],
        'PUE' => ['Puebla Angelópolis', 'PUE-ANG', 'Puebla', 'PUE', 'Blvd. del Niño Poblano 2510, Reserva Territorial Atlixcáyotl, Puebla', 19.0311000, -98.2318000],
        'VER' => ['Veracruz Boca del Río', 'VER-BOC', 'Golfo', 'VER', 'Blvd. Manuel Ávila Camacho 3120, Boca del Río, Veracruz', 19.1062000, -96.1046000],
    ];

    public function __construct(
        private readonly OrganizationProvisioner $provisioner,
        private readonly OfficialCompanyData $official,
        private readonly AuditLogger $audit,
        private readonly ScheduleLegalValidator $validator,
        private readonly UserAccessService $access,
        private readonly TwoFactorPolicy $twoFactor,
    ) {}

    public function run(Context $ctx, string $password): void
    {
        $ctx->holidays = Holiday::query()->whereNull('organization_id')->where('is_mandatory_rest', true)
            ->whereBetween('date', [$ctx->today->subDays(60)->toDateString(), $ctx->today->addDays(60)->toDateString()])
            ->pluck('date')->mapWithKeys(fn ($d) => [CarbonImmutable::parse($d)->toDateString() => true])->all();

        // Ventana simulada: 3 semanas completas + la semana en curso, cubriendo al menos 17 días laborables.
        $earliest = $ctx->workday(17);
        if ($earliest->lt($ctx->start)) {
            $ctx->start = $earliest->startOfWeek(CarbonImmutable::MONDAY);
        }

        // Alta inicial: una semana antes del primer día con marcajes.
        $ctx->travel($ctx->at($ctx->start->subDays(7), '08:30'));

        $organization = $this->provisioner->provision(OfficialCompanyData::TRADE_NAME, Context::TZ);
        $ctx->organization = $organization;
        app(CurrentOrganization::class)->set($organization);

        // Primer administrador (Sistemas), como `asistencias:instalar`.
        $platform = $this->createUser($ctx, $organization, 'plataforma', 'Ingrid Salcedo Ruiz', 'plataforma@e2e.test', PermissionCatalog::ROLE_PLATFORM_ADMIN, true, [], $password, null);
        $ctx->actAs($platform);

        $result = $this->official->apply($organization);
        if ($result['status'] === 'conflict') {
            $ctx->warn('Datos oficiales con conflicto: '.$result['message']);
        }
        $organization->refresh();
        $organization->forceFill(['brand_primary_color' => '#225C54'])->save();
        $this->audit->log('organization.brand_updated', $organization, 'Color institucional de la marca', ['after' => ['brand_primary_color' => '#225C54']]);
        $ctx->legalEntityId = (int) LegalEntity::query()->where('rfc', OfficialCompanyData::RFC)->value('id');

        $this->structure($ctx);

        $ctx->travel($ctx->at($ctx->start->subDays(7), '09:15'));
        $this->webUsers($ctx, $password);

        $ctx->actAs($ctx->user('rh'));
        $this->schedules($ctx);
        $this->strictPolicy($ctx);

        $this->emptyOrganization($ctx, $password);
        app(CurrentOrganization::class)->set($organization);
        $ctx->actAs($ctx->user('rh'));
    }

    private function structure(Context $ctx): void
    {
        $centers = [
            'MOR' => ['Centro de trabajo Morelos', 'CT-MOR', 'MX-MOR', 'Subida del Club 114, Col. Reforma, C.P. 62260, Cuernavaca, Morelos'],
            'PUE' => ['Centro de trabajo Puebla', 'CT-PUE', 'MX-PUE', 'Blvd. del Niño Poblano 2510, Puebla, Puebla'],
            'VER' => ['Centro de trabajo Veracruz', 'CT-VER', 'MX-VER', 'Blvd. Manuel Ávila Camacho 3120, Boca del Río, Veracruz'],
        ];
        foreach ($centers as $key => [$name, $code, $state, $address]) {
            $center = WorkCenter::query()->create([
                'legal_entity_id' => $ctx->legalEntityId ?: null, 'name' => $name, 'code' => $code,
                'address' => $address, 'state_code' => $state, 'timezone' => Context::TZ, 'active' => true,
            ]);
            $this->audit->log('organization.work-centers.created', $center, "Centro de trabajo registrado(a): {$name}", ['code' => $code]);
            $ctx->workCenters[$key] = $center->id;
        }

        foreach (self::BRANCHES as $key => [$name, $code, $region, $center, $address, $lat, $lng]) {
            $branch = Branch::query()->create([
                'work_center_id' => $ctx->workCenters[$center], 'name' => $name, 'code' => $code, 'region' => $region,
                'address' => $address, 'latitude' => $lat, 'longitude' => $lng, 'active' => true,
            ]);
            $this->audit->log('organization.branches.created', $branch, "Sucursal registrado(a): {$name}", ['code' => $code]);
            $fence = Geofence::query()->create([
                'branch_id' => $branch->id, 'name' => 'Geocerca '.$code, 'latitude' => $lat, 'longitude' => $lng,
                'radius_meters' => $key === 'VER' ? 100 : 120, 'active' => true,
            ]);
            $this->audit->log('organization.geofences.created', $fence, "Geocerca registrado(a): {$fence->name}", ['radius_meters' => $fence->radius_meters]);
            $ctx->branches[$key] = $branch;
        }

        foreach (['OPE' => 'Operaciones', 'VEN' => 'Crédito y ventas', 'COB' => 'Cobranza', 'ADM' => 'Administración y finanzas'] as $code => $name) {
            $dept = Department::query()->create(['name' => $name, 'code' => $code, 'active' => true]);
            $this->audit->log('organization.departments.created', $dept, "Departamento registrado(a): {$name}");
            $ctx->departments[$code] = $dept->id;
        }

        $positions = [
            'ASE' => ['Asesor de crédito', 'VEN', 'on_punch'],
            'CAJ' => ['Cajero(a)', 'OPE', 'on_punch'],
            'COB' => ['Ejecutivo de cobranza en campo', 'COB', 'during_shift'],
            'AUX' => ['Auxiliar administrativo', 'ADM', 'on_punch'],
            'COORD' => ['Coordinadora Regional de Operaciones, Cumplimiento Normativo y Atención Integral a Clientes Empresariales', 'OPE', 'on_punch'],
        ];
        foreach ($positions as $code => [$name, $dept, $tracking]) {
            $position = Position::query()->create([
                'department_id' => $ctx->departments[$dept], 'name' => $name, 'code' => $code,
                'location_tracking' => $tracking, 'active' => true,
            ]);
            $this->audit->log('organization.positions.created', $position, "Puesto registrado(a): {$name}", ['location_tracking' => $tracking]);
            $ctx->positions[$code] = $position->id;
        }
    }

    private function webUsers(Context $ctx, string $password): void
    {
        $org = $ctx->organization;
        $branchIds = fn (array $keys) => array_values(array_map(fn (string $k) => $ctx->branches[$k]->id, $keys));

        $this->createUser($ctx, $org, 'rh', 'Laura Méndez Ocampo', 'rh@e2e.test', PermissionCatalog::ROLE_HR_ADMIN, true, [], $password, $ctx->user('plataforma'));
        $this->createUser($ctx, $org, 'rh_nomina', 'Patricio Valle Ordaz', 'nomina@e2e.test', PermissionCatalog::ROLE_HR_ADMIN, true, [], $password, $ctx->user('plataforma'));
        $this->createUser($ctx, $org, 'direccion', 'Alberto Garza Leal', 'direccion@e2e.test', PermissionCatalog::ROLE_DIRECTION, true, [], $password, $ctx->user('plataforma'));
        $this->createUser($ctx, $org, 'regional', 'Mónica Ferrer Islas', 'regional@e2e.test', PermissionCatalog::ROLE_REGIONAL, false, $branchIds(['CUE', 'JIU', 'CUA']), $password, $ctx->user('plataforma'));
        $this->createUser($ctx, $org, 'gerente', 'Sergio Paredes Uribe', 'gerente@e2e.test', PermissionCatalog::ROLE_BRANCH_MANAGER, false, $branchIds(['CUE']), $password, $ctx->user('plataforma'));
        $this->createUser($ctx, $org, 'contraloria', 'Elena Robles Cano', 'contraloria@e2e.test', PermissionCatalog::ROLE_COMPTROLLER, true, [], $password, $ctx->user('plataforma'));

        // Autoridad laboral: sólo lectura con vigencia (flujo «Acceso para autoridad laboral»).
        $authority = $this->createUser($ctx, $org, 'autoridad', 'Inspección laboral (prueba E2E)', 'autoridad@e2e.test', PermissionCatalog::ROLE_AUTHORITY, true, [], $password, $ctx->user('plataforma'));
        $this->access->grantAuthority($authority, $org->id, $ctx->today->addDays(45)->toDateString(), $ctx->user('plataforma'));

        foreach (array_keys($ctx->users) as $key) {
            $this->configureTwoFactor($ctx, $key, $org);
        }
        $ctx->actAs($ctx->user('plataforma'));
    }

    /**
     * Mismo resultado que Usuarios › Nuevo usuario (UserController::store + syncAccess).
     *
     * @param  list<int>  $branchIds
     */
    public function createUser(Context $ctx, Organization $org, string $key, string $name, string $email, string $role, bool $allBranches, array $branchIds, string $password, ?User $by): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'status' => 'active',
            'current_organization_id' => $org->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->organizations()->syncWithoutDetaching([$org->id => ['status' => 'active', 'access_all_branches' => $allBranches]]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles')->syncRoles([$role]);
        foreach ($allBranches ? [] : $branchIds as $branchId) {
            DB::table('branch_user')->insert(['organization_id' => $org->id, 'branch_id' => $branchId, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $registrar->forgetCachedPermissions();

        $this->audit->log('user.linked', $user, 'Usuario creado: '.$user->email, [
            'roles' => [$role], 'access_all_branches' => $allBranches, 'branch_ids' => $branchIds, 'employee_id' => null, 'mode' => 'password', 'by' => $by?->id,
        ], $org->id);

        $ctx->users[$key] = $user;

        return $user;
    }

    /**
     * Doble factor confirmado al estilo Fortify para quien `TwoFactorPolicy` lo exija.
     * El secreto base32 se entrega en el JSON de cuentas para generar códigos TOTP.
     */
    public function configureTwoFactor(Context $ctx, string $key, Organization $org): void
    {
        $user = $ctx->user($key);
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        if (! $this->twoFactor->requiredFor($user)) {
            $ctx->totp[$key] = null;

            return;
        }

        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $encrypter = Fortify::currentEncrypter();
        $user->forceFill([
            'two_factor_secret' => $encrypter->encrypt($secret),
            'two_factor_recovery_codes' => $encrypter->encrypt((string) json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $ctx->totp[$key] = $secret;
    }

    private function schedules(Context $ctx): void
    {
        foreach (self::SCHEDULES as $code => [$name, $type, $kind, $days]) {
            $segments = [];
            foreach ($days as $iso => [$start, $end, $break]) {
                $segments[] = ['day_index' => $iso - 1, 'ordinal' => 1, 'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $break];
            }
            $data = ['name' => $name, 'code' => $code, 'type' => $type, 'shift_kind' => $kind, 'cycle_length_days' => 7, 'tolerance_minutes' => 10, 'flex_minutes' => 0];
            $this->validator->assertValid($data, $segments, [$ctx->today->toDateString()]);

            $schedule = DB::transaction(function () use ($data, $segments, $code) {
                $schedule = Schedule::query()->create([
                    ...$data,
                    'shift_kind' => ShiftKind::from($data['shift_kind']),
                    'cycle_anchor_date' => null,
                    'description' => $code === 'S48' ? 'Cumple la jornada vigente; deberá ajustarse antes del siguiente tramo de reducción.' : null,
                    'active' => true,
                ]);
                $schedule->segments()->createMany($segments);

                return $schedule;
            });
            $outlook = $this->validator->outlook($data, $segments);
            $this->audit->log('schedule.created', $schedule, "Horario creado: {$schedule->name}", ['code' => $code, 'segments' => count($segments), 'adjust_before' => $outlook['adjust_before']]);
            $ctx->schedules[$code] = $schedule;
        }
    }

    private function strictPolicy(Context $ctx): void
    {
        $data = [
            'name' => 'Geocerca estricta (Veracruz)',
            'location_mode' => LocationMode::Geofence,
            'allowed_auth_methods' => ['device_biometric', 'device_credential'],
            'require_strong_biometric' => false,
            'allow_offline' => true,
            'offline_max_hours' => 72,
            'face_verification_required' => false,
            'web_punch_allowed' => false,
            'max_clock_skew_seconds' => 300,
            'min_location_accuracy_meters' => 150,
            'is_default' => false,
        ];
        $policy = AttendancePolicy::query()->create($data);
        $fence = Geofence::query()->where('branch_id', $ctx->branches['VER']->id)->firstOrFail();
        $policy->geofences()->sync([$fence->id]);
        $this->audit->log('attendance_policy.created', $policy, "Política de asistencia creada: {$policy->name}", ['location_mode' => 'geofence', 'geofence_ids' => [$fence->id]]);
        $ctx->strictPolicyId = $policy->id;
    }

    private function emptyOrganization(Context $ctx, string $password): void
    {
        $ctx->actAs(null);
        $empty = $this->provisioner->provision('Organización sin datos (E2E)', Context::TZ);
        $ctx->emptyOrganization = $empty;
        app(CurrentOrganization::class)->set($empty);
        $this->createUser($ctx, $empty, 'rh_vacia', 'Carmen Vidal Soto', 'rh.vacia@e2e.test', PermissionCatalog::ROLE_HR_ADMIN, true, [], $password, null);
        $this->configureTwoFactor($ctx, 'rh_vacia', $empty);
    }
}
