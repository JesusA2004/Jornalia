<?php

/**
 * Generador de volumen para pruebas de rendimiento (SÓLO base `jornalia_perf`).
 *
 * No es un seeder de producción: crea datos sintéticos masivos para medir
 * consultas reales con EXPLAIN/ANALYZE. Se niega a correr sobre cualquier otra
 * base. Usa inserciones masivas (no Eloquent) porque crear ~1.5 M de marcajes
 * con modelos y eventos tardaría horas; los valores respetan los formatos del
 * dominio (UTC, fecha civil, cadena HMAC por organización, minutos enteros).
 *
 * Uso (desde Web/):
 *   DB_DATABASE=jornalia_perf php -d memory_limit=2G tests/Performance/generate-volume.php [colaboradores] [días] [sucursales]
 *
 * Valores por defecto: 1500 colaboradores, 365 días, 30 sucursales.
 */

use App\Domain\Attendance\HashChain;
use App\Domain\Organization\OrganizationProvisioner;
use App\Domain\Security\PermissionCatalog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jornalia_perf') {
    fwrite(STDERR, "Este generador sólo corre sobre la base jornalia_perf (DB_DATABASE=jornalia_perf).\n");
    exit(1);
}

$EMPLOYEES = (int) ($argv[1] ?? 1500);
$DAYS = (int) ($argv[2] ?? 365);
$BRANCHES = (int) ($argv[3] ?? 30);
$TZ = 'America/Mexico_City';

mt_srand(20261124);
DB::connection()->disableQueryLog();
$started = microtime(true);
$step = function (string $label) use ($started): void {
    fwrite(STDOUT, sprintf("[%6.1fs] %s\n", microtime(true) - $started, $label));
};

/**
 * Inserción masiva por bloques.
 *
 * @param  list<array<string, mixed>>  $rows
 */
$bulk = function (string $table, array &$rows, int $chunk = 1000): void {
    foreach (array_chunk($rows, $chunk) as $part) {
        DB::table($table)->insert($part);
    }
    $rows = [];
};

$step('Recreando esquema (migrate:fresh) en jornalia_perf');
Artisan::call('migrate:fresh', ['--force' => true]);
(new DatabaseSeeder)->setContainer($app)->run();

/** @var Organization $org */
$org = app(OrganizationProvisioner::class)->provision('Organización de rendimiento', $TZ);
app(CurrentOrganization::class)->set($org);
app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
$orgId = $org->id;
$now = CarbonImmutable::now('UTC');
$nowStr = $now->format('Y-m-d H:i:s');
$today = CarbonImmutable::now($TZ)->startOfDay();
$firstDay = $today->subDays($DAYS - 1);
$chain = app(HashChain::class);
/** UUID v4 rápido (random_bytes) para el volumen masivo. */
$newUuid = function (): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
};

DB::statement('SET foreign_key_checks = 0');

// ---------------------------------------------------------------- Estructura
$step("Estructura: {$BRANCHES} sucursales, departamentos, puestos, horarios");
$branchIds = [];
$geofenceByBranch = [];
$branchCoords = [];
for ($b = 1; $b <= $BRANCHES; $b++) {
    $lat = 18.9 + mt_rand(0, 15000) / 10000;
    $lng = -99.3 + mt_rand(0, 15000) / 10000;
    $id = DB::table('branches')->insertGetId([
        'organization_id' => $orgId, 'name' => sprintf('Sucursal %02d', $b), 'code' => sprintf('S%03d', $b),
        'region' => 'Región '.(1 + intdiv($b - 1, 6)), 'latitude' => $lat, 'longitude' => $lng, 'active' => 1,
        'created_at' => $nowStr, 'updated_at' => $nowStr,
    ]);
    $branchIds[] = $id;
    $branchCoords[$id] = [$lat, $lng];
    $geofenceByBranch[$id] = DB::table('geofences')->insertGetId([
        'organization_id' => $orgId, 'branch_id' => $id, 'name' => sprintf('Geocerca %02d', $b),
        'latitude' => $lat, 'longitude' => $lng, 'radius_meters' => 150, 'active' => 1,
        'created_at' => $nowStr, 'updated_at' => $nowStr,
    ]);
}
$departmentIds = [];
foreach (['Operación', 'Cobranza', 'Crédito', 'Atención a clientes', 'Administración', 'Sistemas', 'Recursos Humanos', 'Dirección'] as $i => $name) {
    $departmentIds[] = DB::table('departments')->insertGetId([
        'organization_id' => $orgId, 'name' => $name, 'code' => 'D'.($i + 1), 'active' => 1, 'created_at' => $nowStr, 'updated_at' => $nowStr,
    ]);
}
$positionIds = [];
for ($p = 1; $p <= 20; $p++) {
    $positionIds[] = DB::table('positions')->insertGetId([
        'organization_id' => $orgId, 'department_id' => $departmentIds[$p % count($departmentIds)], 'name' => "Puesto {$p}", 'code' => "P{$p}",
        'location_tracking' => $p <= 4 ? 'during_shift' : 'on_punch', 'active' => 1, 'created_at' => $nowStr, 'updated_at' => $nowStr,
    ]);
}
/** Horarios: [código, días (0=lunes), inicio, fin, descanso] */
$scheduleDefs = [
    ['LV0918', [0, 1, 2, 3, 4], '09:00', '18:00', 60, 60],
    ['LS0816', [0, 1, 2, 3, 4, 5], '08:00', '16:00', 30, 30],
    ['MS1019', [1, 2, 3, 4, 5], '10:00', '19:00', 60, 10],
];
$schedules = [];
foreach ($scheduleDefs as [$code, $days, $start, $end, $break, $weight]) {
    $sid = DB::table('schedules')->insertGetId([
        'organization_id' => $orgId, 'name' => "Horario {$code}", 'code' => $code, 'type' => 'fixed', 'shift_kind' => 'day',
        'tolerance_minutes' => 10, 'active' => 1, 'created_at' => $nowStr, 'updated_at' => $nowStr,
    ]);
    foreach ($days as $d) {
        DB::table('schedule_segments')->insert([
            'schedule_id' => $sid, 'day_index' => $d, 'ordinal' => 1, 'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $break,
            'created_at' => $nowStr, 'updated_at' => $nowStr,
        ]);
    }
    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $schedules[] = ['id' => $sid, 'days' => $days, 'start' => $sh * 60 + $sm, 'end' => $eh * 60 + $em, 'break' => $break, 'weight' => $weight];
}

// --------------------------------------------------------------- Personal
$step("Personal: {$EMPLOYEES} colaboradores con usuario, empleo, asignación y dispositivo");
$password = Hash::make('password');
$roleId = fn (string $name) => (int) DB::table('roles')->where('organization_id', $orgId)->where('name', $name)->value('id');
$employeeRole = $roleId(PermissionCatalog::ROLE_EMPLOYEE);
$firstNames = ['Ana', 'Luis', 'María', 'José', 'Laura', 'Carlos', 'Sofía', 'Jorge', 'Elena', 'Miguel', 'Paula', 'Raúl', 'Diana', 'Hugo', 'Rosa', 'Iván'];
$lastNames = ['García', 'Hernández', 'López', 'Martínez', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes', 'Jiménez'];

$users = $employees = $employments = $assignments = $devices = $orgUsers = $modelRoles = [];
$staff = [];
$firstUserId = (int) DB::table('users')->max('id') + 1;
for ($i = 0; $i < $EMPLOYEES; $i++) {
    $userId = $firstUserId + $i;
    $employeeId = $i + 1;
    $users[] = ['id' => $userId, 'current_organization_id' => $orgId, 'name' => "Colaborador {$employeeId}", 'email' => "colaborador{$employeeId}@perf.test",
        'email_verified_at' => $nowStr, 'password' => $password, 'status' => 'active', 'is_super_admin' => 0, 'created_at' => $nowStr, 'updated_at' => $nowStr];
    $orgUsers[] = ['organization_id' => $orgId, 'user_id' => $userId, 'status' => 'active', 'access_all_branches' => 0, 'created_at' => $nowStr, 'updated_at' => $nowStr];
    $modelRoles[] = ['role_id' => $employeeRole, 'model_type' => User::class, 'model_id' => $userId, 'organization_id' => $orgId];
    $hired = $firstDay->subDays(mt_rand(0, 2000))->toDateString();
    $employees[] = ['id' => $employeeId, 'organization_id' => $orgId, 'user_id' => $userId, 'employee_number' => sprintf('E%05d', $employeeId),
        'first_name' => $firstNames[$i % 16], 'last_name' => $lastNames[intdiv($i, 16) % 16], 'second_last_name' => $lastNames[($i * 7) % 16],
        'status' => 'active', 'created_at' => $nowStr, 'updated_at' => $nowStr];
    $employments[] = ['id' => $employeeId, 'organization_id' => $orgId, 'employee_id' => $employeeId, 'starts_on' => $hired, 'seniority_starts_on' => $hired,
        'contract_type' => 'indefinite', 'work_modality' => 'onsite', 'status' => 'active', 'created_at' => $nowStr, 'updated_at' => $nowStr];
    $roll = mt_rand(1, 100);
    $schedule = $roll <= 60 ? $schedules[0] : ($roll <= 90 ? $schedules[1] : $schedules[2]);
    $branchId = $branchIds[$i % count($branchIds)];
    $position = $positionIds[$i % count($positionIds)];
    $assignments[] = ['organization_id' => $orgId, 'employment_id' => $employeeId, 'effective_from' => $hired, 'effective_to' => null,
        'branch_id' => $branchId, 'department_id' => $departmentIds[$i % count($departmentIds)], 'position_id' => $position,
        'schedule_id' => $schedule['id'], 'created_at' => $nowStr, 'updated_at' => $nowStr];
    $devices[] = ['id' => $employeeId, 'organization_id' => $orgId, 'user_id' => $userId, 'employee_id' => $employeeId, 'device_uuid' => $newUuid(),
        'name' => 'Teléfono', 'platform' => 'android', 'app_version' => '2.0.0', 'secret' => encrypt('perf-secret-'.$employeeId),
        'status' => 'approved', 'approved_at' => $nowStr, 'last_seen_at' => $nowStr, 'created_at' => $nowStr, 'updated_at' => $nowStr];
    $staff[$employeeId] = ['user' => $userId, 'branch' => $branchId, 'department' => $departmentIds[$i % count($departmentIds)], 'schedule' => $schedule, 'hired' => $hired, 'tracking' => in_array($position, array_slice($positionIds, 0, 4), true)];
}
$bulk('users', $users);
$bulk('organization_user', $orgUsers);
$bulk('model_has_roles', $modelRoles);
$bulk('employees', $employees);
$bulk('employments', $employments);
$bulk('employment_assignments', $assignments);
$bulk('devices', $devices, 500);

// Supervisores: el primer colaborador de cada sucursal supervisa al resto de su sucursal.
foreach ($branchIds as $idx => $branchId) {
    DB::table('employment_assignments')->where('branch_id', $branchId)->where('employment_id', '!=', $idx + 1)
        ->update(['supervisor_employee_id' => $idx + 1]);
}

// Usuarios de operación (acceso completo y por sucursal).
$makeStaffUser = function (string $email, string $name, array $roles, bool $allBranches, array $branches = []) use ($orgId, $password, $nowStr): int {
    $id = DB::table('users')->insertGetId(['current_organization_id' => $orgId, 'name' => $name, 'email' => $email, 'email_verified_at' => $nowStr,
        'password' => $password, 'status' => 'active', 'is_super_admin' => 0, 'two_factor_secret' => encrypt('PERFSECRET'),
        'two_factor_confirmed_at' => $nowStr, 'created_at' => $nowStr, 'updated_at' => $nowStr]);
    DB::table('organization_user')->insert(['organization_id' => $orgId, 'user_id' => $id, 'status' => 'active', 'access_all_branches' => $allBranches, 'created_at' => $nowStr, 'updated_at' => $nowStr]);
    foreach ($branches as $b) {
        DB::table('branch_user')->insert(['organization_id' => $orgId, 'branch_id' => $b, 'user_id' => $id, 'created_at' => $nowStr, 'updated_at' => $nowStr]);
    }
    User::query()->findOrFail($id)->assignRole($roles);

    return $id;
};
$hrUser = $makeStaffUser('rh@perf.test', 'Administrador RH', [PermissionCatalog::ROLE_HR_ADMIN], true);
$makeStaffUser('regional@perf.test', 'Responsable regional', [PermissionCatalog::ROLE_REGIONAL], false, array_slice($branchIds, 0, 6));
$makeStaffUser('gerente@perf.test', 'Gerente de sucursal', [PermissionCatalog::ROLE_BRANCH_MANAGER], false, [$branchIds[0]]);
$makeStaffUser('contraloria@perf.test', 'Contraloría', [PermissionCatalog::ROLE_COMPTROLLER], true);

// ------------------------------------------------------ Marcajes y jornadas
$step("Marcajes y jornadas: {$DAYS} días");
$sequence = 0;
$previousHash = HashChain::GENESIS;
$punches = $summaries = $overtime = $omissionDays = [];
$counts = ['punches' => 0, 'summaries' => 0, 'overtime' => 0];
$fmt = fn (CarbonImmutable|int $t): string => is_int($t) ? gmdate('Y-m-d H:i:s', $t).'.000000' : $t->format('Y-m-d H:i:s.u');
$iso = fn (CarbonImmutable|int $t): string => is_int($t) ? gmdate('Y-m-d\TH:i:s', $t).'.000000Z' : $t->format('Y-m-d\TH:i:s.u\Z');
$dt = fn (int $t): string => gmdate('Y-m-d H:i:s', $t);

for ($day = $firstDay; $day->lte($today); $day = $day->addDay()) {
    $date = $day->toDateString();
    $dayIndex = ($day->dayOfWeekIso - 1);
    $midnightUtc = CarbonImmutable::parse($date.' 00:00:00', $TZ)->getTimestamp();
    $isToday = $date === $today->toDateString();
    $dayRows = [];

    foreach ($staff as $employeeId => $s) {
        $sch = $s['schedule'];
        $scheduled = in_array($dayIndex, $sch['days'], true);
        $base = ['organization_id' => $orgId, 'employee_id' => $employeeId, 'employment_id' => $employeeId, 'branch_id' => $s['branch'],
            'department_id' => $s['department'], 'date' => $date, 'schedule_id' => $sch['id'], 'shift_kind' => 'day', 'legal_version_code' => null,
            'computed_at' => $nowStr, 'created_at' => $nowStr, 'updated_at' => $nowStr, 'has_rectifications' => 0];
        if (! $scheduled) {
            if (! $isToday) {
                $summaries[] = $base + ['scheduled_start' => null, 'scheduled_end' => null, 'scheduled_minutes' => 0, 'first_in' => null, 'last_out' => null,
                    'worked_minutes' => 0, 'break_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'excess_minutes' => 0, 'night_minutes' => 0,
                    'status' => 'rest_day', 'anomalies' => '[]'];
            }

            continue;
        }
        $schStart = $midnightUtc + $sch['start'] * 60;
        $schEnd = $midnightUtc + $sch['end'] * 60;
        $roll = mt_rand(1, 1000);
        if ($roll <= 30 && ! $isToday) {
            $summaries[] = $base + ['scheduled_start' => $dt($schStart), 'scheduled_end' => $dt($schEnd),
                'scheduled_minutes' => $sch['end'] - $sch['start'] - $sch['break'], 'first_in' => null, 'last_out' => null, 'worked_minutes' => 0,
                'break_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'excess_minutes' => 0, 'night_minutes' => 0,
                'status' => $roll <= 10 ? 'vacation' : 'absent', 'anomalies' => '[]'];

            continue;
        }
        $lateRoll = mt_rand(1, 100);
        $inOffset = $lateRoll <= 12 ? mt_rand(11, 45) : mt_rand(-15, 9);
        $excess = mt_rand(1, 100) <= 15 ? mt_rand(30, 150) : mt_rand(-5, 10);
        $missingOut = mt_rand(1, 1000) <= 20;
        $missingIn = ! $missingOut && mt_rand(1, 1000) <= 5;
        $offline = mt_rand(1, 100) <= 4;
        $kiosk = ! $offline && mt_rand(1, 100) <= 5;
        $flagged = mt_rand(1, 100) <= 1;
        $in = $schStart + $inOffset * 60 + mt_rand(0, 59);
        $bs = $schStart + (240 + mt_rand(-15, 15)) * 60 + mt_rand(0, 59);
        $be = $bs + ($sch['break'] + mt_rand(-3, 6)) * 60 + mt_rand(0, 59);
        $out = $schEnd + $excess * 60 + mt_rand(0, 59);
        $plan = [];
        if (! $missingIn) {
            $plan[] = ['clock_in', $in];
        }
        if ($isToday) {
            $plan = array_slice($plan, 0, 1);
        } else {
            $plan[] = ['break_start', $bs];
            $plan[] = ['break_end', $be];
            if (! $missingOut) {
                $plan[] = ['clock_out', $out];
            }
        }
        [$lat, $lng] = $branchCoords[$s['branch']];
        foreach ($plan as [$type, $time]) {
            $received = $offline ? $time + mt_rand(20, 300) * 60 : $time + mt_rand(1, 4);
            $dayRows[] = [
                'employee_id' => $employeeId, 'type' => $type, 'time' => $time, 'received' => $received, 'offline' => $offline, 'kiosk' => $kiosk,
                'flagged' => $flagged && $type === 'clock_in', 'lat' => round($lat + mt_rand(-80, 80) / 100000, 7), 'lng' => round($lng + mt_rand(-80, 80) / 100000, 7),
            ];
        }
        if ($isToday) {
            continue;
        }
        $worked = $missingIn || $missingOut ? 0 : max(0, intdiv($out - $in, 60) - intdiv($be - $bs, 60));
        $late = $inOffset > 10 && ! $missingIn ? $inOffset : 0;
        $excessMin = $excess >= 30 && ! $missingOut && ! $missingIn ? $excess : 0;
        $anomalies = $missingOut ? ['missing_clock_out'] : ($missingIn ? ['missing_clock_in'] : []);
        $status = $missingIn || $missingOut ? 'incomplete' : ($late > 0 ? 'late' : 'present');
        $summaries[] = $base + ['scheduled_start' => $dt($schStart), 'scheduled_end' => $dt($schEnd),
            'scheduled_minutes' => $sch['end'] - $sch['start'] - $sch['break'], 'first_in' => $missingIn ? null : $dt($in),
            'last_out' => $missingOut ? null : $dt($out), 'worked_minutes' => $worked,
            'break_minutes' => intdiv($be - $bs, 60), 'late_minutes' => $late, 'early_leave_minutes' => 0,
            'excess_minutes' => $excessMin, 'night_minutes' => 0, 'status' => $status, 'anomalies' => json_encode($anomalies)];
        if ($anomalies !== []) {
            $omissionDays[] = [$employeeId, $date, $missingOut ? 'clock_out' : 'clock_in', $missingOut ? $out : $in];
        }
        if ($excessMin > 0) {
            $weekStart = $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $st = mt_rand(1, 100);
            $overtime[] = ['organization_id' => $orgId, 'employee_id' => $employeeId, 'date' => $date, 'week_start' => $weekStart,
                'recorded_excess_minutes' => $excessMin, 'authorized_minutes' => $st <= 70 ? $excessMin : 0, 'double_minutes' => $st <= 70 ? min($excessMin, 180) : 0,
                'triple_minutes' => 0, 'over_limit_minutes' => 0, 'status' => $st <= 70 ? 'approved' : ($st <= 85 ? 'pending' : 'rejected'), 'current_level' => 1,
                'authorized_by' => $st <= 85 ? null : $hrUser, 'created_at' => $nowStr, 'updated_at' => $nowStr];
        }
    }

    // Orden cronológico real de llegada al servidor: así la secuencia de la cadena es creciente en el tiempo.
    usort($dayRows, fn ($a, $b) => $a['received'] <=> $b['received']);
    foreach ($dayRows as $r) {
        $sequence++;
        $uuid = $newUuid();
        $client = $newUuid();
        $source = $r['kiosk'] ? 'kiosk' : ($r['offline'] ? 'mobile_offline' : 'mobile');
        $auth = $r['kiosk'] ? 'kiosk_pin' : 'device_biometric';
        $anoms = $r['flagged'] ? ['outside_geofence'] : ($r['offline'] ? ['late_sync'] : []);
        $device = $r['kiosk'] ? null : $r['employee_id'];
        $payload = [
            'uuid' => $uuid, 'organization_id' => $orgId, 'employee_id' => $r['employee_id'], 'employment_id' => $r['employee_id'], 'device_id' => $device,
            'recorded_by' => null, 'client_uuid' => $client, 'type' => $r['type'], 'source' => $source, 'server_time' => $iso($r['received']),
            'device_time' => $iso($r['time']), 'timezone' => $TZ, 'local_date' => $date, 'auth_method' => $auth, 'signature_valid' => ! $r['kiosk'],
            'captured_offline' => $r['offline'], 'status' => $r['flagged'] ? 'flagged' : 'accepted', 'anomalies' => $anoms,
            'latitude' => number_format($r['lat'], 7, '.', ''), 'longitude' => number_format($r['lng'], 7, '.', ''), 'accuracy_meters' => 12,
            'location_mocked' => false, 'sequence' => $sequence,
        ];
        $hash = $chain->hash($previousHash, $payload);
        $punches[] = [
            'uuid' => $uuid, 'organization_id' => $orgId, 'employee_id' => $r['employee_id'], 'employment_id' => $r['employee_id'], 'device_id' => $device,
            'kiosk_id' => null, 'recorded_by' => null, 'client_uuid' => $client, 'type' => $r['type'], 'source' => $source,
            'server_time' => $fmt($r['received']), 'device_time' => $fmt($r['time']), 'received_at' => $fmt($r['received']), 'synced_at' => $fmt($r['received']),
            'clock_skew_seconds' => 0, 'timezone' => $TZ, 'local_date' => $date, 'auth_method' => $auth, 'signature_valid' => (int) ! $r['kiosk'],
            'captured_offline' => (int) $r['offline'], 'status' => $payload['status'], 'anomalies' => json_encode($anoms),
            'latitude' => $payload['latitude'], 'longitude' => $payload['longitude'], 'accuracy_meters' => 12, 'location_captured_at' => $dt($r['time']),
            'location_mocked' => 0, 'geofence_id' => $r['flagged'] ? null : $geofenceByBranch[$staff[$r['employee_id']]['branch']], 'distance_meters' => mt_rand(3, 90),
            'ip_address' => '10.0.0.'.mt_rand(1, 250), 'user_agent' => 'MRLana/2.0 Android', 'sequence' => $sequence, 'previous_hash' => $previousHash,
            'hash' => $hash, 'created_at' => $fmt($r['received']),
        ];
        $previousHash = $hash;
    }
    $counts['punches'] += count($punches);
    $counts['summaries'] += count($summaries);
    $counts['overtime'] += count($overtime);
    $bulk('attendance_punches', $punches, 1000);
    $bulk('workday_summaries', $summaries, 1000);
    $bulk('overtime_records', $overtime, 1000);
    if ($day->day === 1) {
        $step("  {$date}: marcajes={$counts['punches']} jornadas={$counts['summaries']}");
    }
}
$step("  total marcajes={$counts['punches']} jornadas={$counts['summaries']} horas extra={$counts['overtime']}");

// ---------------------------------------------- Rectificaciones y ajustes
$step('Rectificaciones, firmas y ajustes (cadena propia)');
shuffle($omissionDays);
$adjSeq = 0;
$adjPrev = HashChain::GENESIS;
$sigSeq = 0;
$sigPrev = HashChain::GENESIS;
$rects = $adjustments = $signatures = [];
$rectId = 0;
$rectifiedDays = [];
foreach (array_slice($omissionDays, 0, (int) (count($omissionDays) * 0.85)) as [$employeeId, $date, $type, $time]) {
    $rectId++;
    $roll = mt_rand(1, 100);
    $stage = $roll <= 70 ? 'aplicada' : ($roll <= 78 ? 'por_autorizar' : ($roll <= 86 ? 'por_firmar' : ($roll <= 93 ? 'por_validar' : 'rechazada')));
    $created = CarbonImmutable::parse($date.' 23:00:00', 'UTC')->addDays(mt_rand(0, 3));
    $open = in_array($stage, ['por_autorizar', 'por_firmar', 'por_validar'], true);
    $rects[] = ['id' => $rectId, 'organization_id' => $orgId, 'employee_id' => $employeeId, 'requested_by' => $staff[$employeeId]['user'], 'origin' => 'employee',
        'date' => $date, 'kind' => 'missing_punch', 'proposed_type' => $type, 'proposed_time' => $dt($time), 'reason' => 'Olvidé registrar mi checada.',
        'status' => $stage === 'aplicada' ? 'approved' : ($stage === 'rechazada' ? 'rejected' : 'pending'), 'stage' => $stage,
        'open_key' => $open ? $employeeId.'|'.$date.'|'.$type : null, 'current_level' => 1,
        'authorized_by' => in_array($stage, ['por_firmar', 'por_validar', 'aplicada'], true) ? $hrUser : null,
        'signature_due_at' => $stage === 'por_firmar' ? $created->addDays(3)->format('Y-m-d H:i:s') : null,
        'validated_by' => $stage === 'aplicada' ? $hrUser : null, 'applied_at' => $stage === 'aplicada' ? $created->addDays(2)->format('Y-m-d H:i:s') : null,
        'is_late' => 0, 'legacy_without_signature' => 0, 'created_at' => $created->format('Y-m-d H:i:s'), 'updated_at' => $created->format('Y-m-d H:i:s')];
    if (in_array($stage, ['por_validar', 'aplicada'], true)) {
        $sigSeq++;
        $statement = "Firmo la rectificación {$rectId} del {$date}.";
        $sigUuid = $newUuid();
        $signedAt = $created->addDay();
        $sigPayload = ['uuid' => $sigUuid, 'organization_id' => $orgId, 'employee_id' => $employeeId, 'user_id' => $staff[$employeeId]['user'],
            'signable_type' => 'App\\Models\\RectificationRequest', 'signable_id' => $rectId, 'purpose' => 'rectification', 'method' => 'pin',
            'payload_sha256' => hash('sha256', $statement), 'device_id' => $employeeId, 'ip' => '10.0.0.1', 'signed_at' => $iso($signedAt), 'sequence' => $sigSeq];
        $sigHash = $chain->hash($sigPrev, $sigPayload);
        $signatures[] = ['uuid' => $sigUuid, 'organization_id' => $orgId, 'employee_id' => $employeeId, 'user_id' => $staff[$employeeId]['user'],
            'signable_type' => 'App\\Models\\RectificationRequest', 'signable_id' => $rectId, 'purpose' => 'rectification', 'method' => 'pin',
            'statement' => $statement, 'payload_sha256' => $sigPayload['payload_sha256'], 'device_id' => $employeeId, 'ip' => '10.0.0.1', 'user_agent' => 'MRLana/2.0',
            'signed_at' => $fmt($signedAt), 'sequence' => $sigSeq, 'previous_hash' => $sigPrev, 'hash' => $sigHash];
        $sigPrev = $sigHash;
    }
    if ($stage === 'aplicada') {
        $adjSeq++;
        $adjUuid = $newUuid();
        $adjPayload = ['uuid' => $adjUuid, 'organization_id' => $orgId, 'employee_id' => $employeeId, 'rectification_request_id' => $rectId, 'action' => 'add',
            'target_punch_uuid' => null, 'type' => $type, 'recognized_time' => $iso($time), 'local_date' => $date, 'approved_by' => $hrUser,
            'reason' => 'Olvidé registrar mi checada.', 'sequence' => $adjSeq];
        $adjHash = $chain->hash($adjPrev, $adjPayload);
        $adjustments[] = ['uuid' => $adjUuid, 'organization_id' => $orgId, 'employee_id' => $employeeId, 'rectification_request_id' => $rectId, 'action' => 'add',
            'target_punch_uuid' => null, 'type' => $type, 'recognized_time' => $fmt($time), 'local_date' => $date, 'approved_by' => $hrUser,
            'reason' => 'Olvidé registrar mi checada.', 'sequence' => $adjSeq, 'previous_hash' => $adjPrev, 'hash' => $adjHash, 'created_at' => $fmt($created->addDays(2))];
        $adjPrev = $adjHash;
        $rectifiedDays[] = [$employeeId, $date];
    }
}
$nRect = count($rects);
$bulk('rectification_requests', $rects);
$bulk('electronic_signatures', $signatures);
$bulk('attendance_adjustments', $adjustments);
foreach (array_chunk($rectifiedDays, 500) as $part) {
    DB::table('workday_summaries')->where(function ($q) use ($part) {
        foreach ($part as [$e, $d]) {
            $q->orWhere(fn ($w) => $w->where('employee_id', $e)->where('date', $d));
        }
    })->update(['has_rectifications' => 1]);
}
$step("  rectificaciones={$nRect} ajustes={$adjSeq} firmas={$sigSeq}");

// ------------------------------------------------------------- Incidencias
$step('Incidencias');
$types = DB::table('incidence_types')->where('organization_id', $orgId)->pluck('id')->all();
$incidences = [];
for ($k = 0; $k < $EMPLOYEES * 4; $k++) {
    $employeeId = mt_rand(1, $EMPLOYEES);
    $start = $firstDay->addDays(mt_rand(0, $DAYS - 1));
    $st = mt_rand(1, 100);
    $incidences[] = ['organization_id' => $orgId, 'employee_id' => $employeeId, 'incidence_type_id' => $types[array_rand($types)], 'starts_on' => $start->toDateString(),
        'ends_on' => $start->addDays(mt_rand(0, 2))->toDateString(), 'reason' => 'Incidencia de prueba de volumen', 'source' => mt_rand(1, 3) === 1 ? 'manager' : 'employee',
        'status' => $st <= 75 ? 'approved' : ($st <= 90 ? 'pending' : 'rejected'), 'current_level' => 1, 'requested_by' => $staff[$employeeId]['user'],
        'created_at' => $start->format('Y-m-d H:i:s'), 'updated_at' => $start->format('Y-m-d H:i:s')];
}
$nInc = count($incidences);
$bulk('incidences', $incidences);

// ------------------------------------------------- Ubicación en jornada
$trackDays = min(30, $DAYS);
$step("Puntos de ubicación (últimos {$trackDays} días, colaboradores con seguimiento en jornada, cada 5 min)");
$points = [];
$nPoints = 0;
foreach ($staff as $employeeId => $s) {
    if (! $s['tracking']) {
        continue;
    }
    [$lat, $lng] = $branchCoords[$s['branch']];
    for ($d = $trackDays - 1; $d >= 0; $d--) {
        $day = $today->subDays($d);
        if (! in_array($day->dayOfWeekIso - 1, $s['schedule']['days'], true)) {
            continue;
        }
        $start = CarbonImmutable::parse($day->toDateString().' 00:00:00', $TZ)->getTimestamp() + $s['schedule']['start'] * 60;
        $end = $d === 0 ? $now->getTimestamp() : $start + ($s['schedule']['end'] - $s['schedule']['start']) * 60;
        for ($t = $start; $t < $end; $t += 300) {
            $points[] = ['uuid' => $newUuid(), 'organization_id' => $orgId, 'employee_id' => $employeeId, 'user_id' => $s['user'], 'device_id' => $employeeId,
                'latitude' => round($lat + mt_rand(-900, 900) / 100000, 7), 'longitude' => round($lng + mt_rand(-900, 900) / 100000, 7), 'accuracy_m' => mt_rand(5, 40),
                'is_mocked' => 0, 'app_state' => 'background', 'captured_at' => $fmt($t), 'received_at' => $fmt($t + mt_rand(2, 90))];
            if (count($points) >= 5000) {
                $nPoints += count($points);
                $bulk('location_points', $points);
            }
        }
    }
    DB::table('employees')->where('id', $employeeId)->update(['last_location_at' => $now->subMinutes(mt_rand(1, 30))->format('Y-m-d H:i:s')]);
}
$nPoints += count($points);
$bulk('location_points', $points);

// ------------------------------------------------- Bitácora y avisos
$step('Bitácora (audit_logs) y notificaciones');
$actions = ['punch.recorded', 'rectification.created', 'rectification.authorized', 'rectification.validated', 'employee.updated', 'device.approved', 'report.exported', 'auth.login', 'incidence.approved', 'schedule.assigned'];
$logs = [];
$nLogs = 0;
$totalLogs = $EMPLOYEES * 200;
for ($k = 0; $k < $totalLogs; $k++) {
    $at = $firstDay->getTimestamp() + mt_rand(0, $DAYS * 86400 - 1);
    $employeeId = mt_rand(1, $EMPLOYEES);
    $logs[] = ['organization_id' => $orgId, 'user_id' => mt_rand(1, 10) <= 2 ? $hrUser : $staff[$employeeId]['user'], 'action' => $actions[array_rand($actions)],
        'subject_type' => 'App\\Models\\Employee', 'subject_id' => $employeeId, 'description' => 'Evento de prueba de volumen',
        'context' => '{}', 'ip_address' => '10.0.0.'.mt_rand(1, 250), 'user_agent' => 'Mozilla/5.0', 'created_at' => $fmt($at)];
    if (count($logs) >= 5000) {
        $nLogs += count($logs);
        $bulk('audit_logs', $logs);
    }
}
$nLogs += count($logs);
$bulk('audit_logs', $logs);

$notifications = [];
$nNotif = 0;
$recipients = array_merge(array_column($staff, 'user'), array_fill(0, 400, $hrUser));
for ($k = 0; $k < $EMPLOYEES * 120; $k++) {
    $at = $today->getTimestamp() - mt_rand(0, min(180, $DAYS) - 1) * 86400 + mt_rand(0, 86399);
    $notifications[] = ['id' => $newUuid(), 'type' => 'App\\Notifications\\PunchReceipt', 'notifiable_type' => User::class,
        'notifiable_id' => $recipients[array_rand($recipients)], 'data' => json_encode(['kind' => 'punch_receipt', 'category' => 'attendance', 'priority' => 'normal',
            'title' => 'Marcaje recibido', 'body' => 'Tu marcaje quedó registrado.', 'data' => [], 'organization_id' => $orgId]),
        'read_at' => mt_rand(1, 100) <= 60 ? $dt($at + 1800) : null, 'created_at' => $dt($at), 'updated_at' => $dt($at)];
    if (count($notifications) >= 5000) {
        $nNotif += count($notifications);
        $bulk('notifications', $notifications);
    }
}
$nNotif += count($notifications);
$bulk('notifications', $notifications);

// ------------------------------------------------- Alertas de cumplimiento
$step('Alertas de cumplimiento');
$alerts = [];
$rules = ['omission_unrectified', 'weekly_overtime_limit', 'clock_skew', 'rest_between_shifts', 'outside_geofence'];
$seen = [];
for ($k = 0; $k < $EMPLOYEES * 15; $k++) {
    $employeeId = mt_rand(1, $EMPLOYEES);
    $date = $firstDay->addDays(mt_rand(0, $DAYS - 1))->toDateString();
    $rule = $rules[array_rand($rules)];
    if (isset($seen["{$employeeId}|{$date}|{$rule}"])) {
        continue;
    }
    $seen["{$employeeId}|{$date}|{$rule}"] = true;
    $st = mt_rand(1, 100);
    $alerts[] = ['organization_id' => $orgId, 'employee_id' => $employeeId, 'date' => $date, 'rule_code' => $rule, 'severity' => ['info', 'warning', 'critical'][mt_rand(0, 2)],
        'message' => 'Alerta de prueba de volumen', 'data' => '{}', 'status' => $st <= 60 ? 'resolved' : 'open', 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'];
}
$nAlerts = count($alerts);
$bulk('compliance_alerts', $alerts);

DB::statement('SET foreign_key_checks = 1');
$step('ANALYZE TABLE de las tablas principales');
foreach (['attendance_punches', 'workday_summaries', 'overtime_records', 'rectification_requests', 'attendance_adjustments', 'incidences', 'location_points', 'audit_logs', 'notifications', 'compliance_alerts', 'employees', 'employment_assignments', 'employments', 'electronic_signatures', 'devices'] as $t) {
    DB::statement("ANALYZE TABLE {$t}");
}

$step(sprintf(
    'Listo. colaboradores=%d sucursales=%d días=%d marcajes=%d jornadas=%d horas_extra=%d rectificaciones=%d ajustes=%d firmas=%d incidencias=%d ubicaciones=%d bitácora=%d notificaciones=%d alertas=%d',
    $EMPLOYEES, $BRANCHES, $DAYS, $counts['punches'], $counts['summaries'], $counts['overtime'], $nRect, $adjSeq, $sigSeq, $nInc, $nPoints, $nLogs, $nNotif, $nAlerts,
));
