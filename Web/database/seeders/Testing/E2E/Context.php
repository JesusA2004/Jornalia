<?php

namespace Database\Seeders\Testing\E2E;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Kiosk;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Estado compartido del entorno de DEMOSTRACIÓN para pruebas E2E.
 *
 * Sólo se usa desde `E2EDemoSeeder` (nunca desde DatabaseSeeder). Concentra:
 *  - el reloj simulado (viaje en el tiempo con Carbon) para que cada acción
 *    del dominio ocurra en su fecha y hora relativas a «hoy»;
 *  - la línea de tiempo de acciones (marcajes y flujos) ejecutadas en orden
 *    cronológico, para que las cadenas de integridad y la bitácora sean
 *    coherentes con las fechas;
 *  - variación determinista (crc32) en lugar de aleatoriedad, para capturas
 *    reproducibles.
 */
final class Context
{
    public const TZ = 'America/Mexico_City';

    /** Instante real de la corrida (UTC). Ninguna acción se ejecuta después de él. */
    public CarbonImmutable $realNow;

    /** Hoy (fecha civil) en la zona de la organización. */
    public CarbonImmutable $today;

    /** Primer día con marcajes (lunes, ~3 semanas atrás). */
    public CarbonImmutable $start;

    public Organization $organization;

    public ?Organization $emptyOrganization = null;

    /** @var array<string, User> usuarios web por clave del JSON */
    public array $users = [];

    /** @var array<string, string> secretos TOTP (base32) por clave */
    public array $totp = [];

    /** @var array<string, Branch> por código corto (CUE, JIU, CUA, PUE, VER) */
    public array $branches = [];

    /** @var array<string, int> */
    public array $workCenters = [];

    /** @var array<string, int> */
    public array $departments = [];

    /** @var array<string, int> */
    public array $positions = [];

    /** @var array<string, Schedule> S48, S44, MIX, S40 */
    public array $schedules = [];

    public int $legalEntityId = 0;

    public int $strictPolicyId = 0;

    /** @var array<int, Employee> por índice del roster */
    public array $employees = [];

    /** @var array<int, User> cuenta (app) del colaborador por índice */
    public array $employeeUsers = [];

    /** @var array<int, array{device: Device, secret: string}> teléfono vigente por índice */
    public array $devices = [];

    /** @var array<string, Kiosk> */
    public array $kiosks = [];

    /** @var array<string, string> token del kiosco activo (sólo en memoria) */
    public array $kioskTokens = [];

    /** NIP de firma común de los colaboradores (aleatorio por corrida). */
    public string $pin = '';

    /** @var array<string, int|string|array<array-key, int>> ids útiles para las pruebas E2E */
    public array $fixtures = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, int> */
    public array $counters = [];

    /** @var list<array{at: CarbonImmutable, order: int, seq: int, label: string, run: Closure}> */
    private array $timeline = [];

    private int $seq = 0;

    /** @var array<string, bool> fechas de descanso obligatorio (Y-m-d) */
    public array $holidays = [];

    /**
     * Ajustes al plan diario de marcajes: [índice][Y-m-d] => absent|no_out|work|late(H:i)|overtime(min)|early(min).
     *
     * @var array<int, array<string, array<string, mixed>>>
     */
    public array $plan = [];

    /**
     * Opciones especiales por marcaje: [índice][Y-m-d][tipo] => offline_delay(min)|skew(s)|mocked|far(m).
     *
     * @var array<int, array<string, array<string, array<string, mixed>>>>
     */
    public array $special = [];

    /**
     * @param  array<string, mixed>  $values
     */
    public function plan(int $index, CarbonImmutable $day, array $values): void
    {
        $date = $day->toDateString();
        $this->plan[$index][$date] = [...($this->plan[$index][$date] ?? []), ...$values];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function special(int $index, CarbonImmutable $day, string $type, array $values): void
    {
        $date = $day->toDateString();
        $this->special[$index][$date][$type] = [...($this->special[$index][$date][$type] ?? []), ...$values];
        $this->plan($index, $day, ['keep' => true]);
    }

    public function __construct()
    {
        $this->realNow = CarbonImmutable::now('UTC')->startOfMinute();
        $this->today = $this->realNow->setTimezone(self::TZ)->startOfDay();
        $this->start = $this->today->subDays(21)->startOfWeek(CarbonImmutable::MONDAY);
    }

    // ── Reloj simulado ──────────────────────────────────────────────────────

    /**
     * Mueve el reloj de la aplicación (Carbon, CarbonImmutable y Date de Laravel).
     */
    public function travel(CarbonImmutable $at): void
    {
        if ($at->gt($this->realNow)) {
            throw new RuntimeException('Acción del seeder E2E en el futuro: '.$at->toIso8601String());
        }
        $utc = $at->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
        Date::setTestNow($utc);
    }

    public function backToPresent(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Date::setTestNow();
    }

    /**
     * Instante local (zona de la organización) de una fecha y hora «H:i».
     */
    public function at(CarbonImmutable|string $date, string $time): CarbonImmutable
    {
        $day = $date instanceof CarbonImmutable ? $date->setTimezone(self::TZ)->toDateString() : $date;

        return CarbonImmutable::parse("{$day} {$time}", self::TZ);
    }

    /**
     * El instante dado, pero nunca después de «ahora menos $marginMinutes».
     */
    public function clampPast(CarbonImmutable $at, int $marginMinutes = 2): CarbonImmutable
    {
        $limit = $this->realNow->subMinutes($marginMinutes);

        return $at->gt($limit) ? $limit : $at;
    }

    // ── Actor y permisos ────────────────────────────────────────────────────

    /**
     * Fija el usuario autenticado (bitácora con autor real) y el equipo de permisos.
     */
    public function actAs(?User $user, ?Organization $organization = null): void
    {
        $organization ??= $this->organization;
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
        if ($user === null) {
            Auth::guard('web')->forgetUser();

            return;
        }
        $user->unsetRelation('roles')->unsetRelation('permissions');
        Auth::guard('web')->setUser($user);
    }

    public function user(string $key): User
    {
        return $this->users[$key] ?? throw new RuntimeException("Usuario E2E desconocido: {$key}");
    }

    public function employee(int $index): Employee
    {
        return $this->employees[$index] ?? throw new RuntimeException("Colaborador E2E desconocido: {$index}");
    }

    public function employeeUser(int $index): User
    {
        return $this->employeeUsers[$index] ?? throw new RuntimeException("El colaborador {$index} no tiene cuenta.");
    }

    // ── Línea de tiempo ─────────────────────────────────────────────────────

    /**
     * Programa una acción en un instante. Se ejecutan en orden cronológico.
     */
    public function schedule(CarbonImmutable $at, string $label, Closure $run, int $order = 50): void
    {
        $this->timeline[] = ['at' => $at->utc(), 'order' => $order, 'seq' => $this->seq++, 'label' => $label, 'run' => $run];
    }

    /**
     * Ejecuta la línea de tiempo y la vacía.
     *
     * @param  (Closure(int, int): void)|null  $progress
     */
    public function runTimeline(?Closure $progress = null): void
    {
        $items = $this->timeline;
        $this->timeline = [];
        usort($items, fn (array $a, array $b) => [$a['at']->getTimestamp(), $a['order'], $a['seq']] <=> [$b['at']->getTimestamp(), $b['order'], $b['seq']]);

        $total = count($items);
        foreach ($items as $i => $item) {
            if ($item['at']->gt($this->realNow)) {
                $this->count('acciones_futuras_omitidas');

                continue;
            }
            $this->travel($item['at']);
            try {
                ($item['run'])();
            } catch (\Throwable $e) {
                throw new RuntimeException("Falló la acción «{$item['label']}» ({$item['at']->setTimezone(self::TZ)->format('Y-m-d H:i')}): ".$e->getMessage(), 0, $e);
            }
            if ($progress !== null && ($i % 200 === 0 || $i === $total - 1)) {
                $progress($i + 1, $total);
            }
        }
    }

    // ── Variación determinista ──────────────────────────────────────────────

    /**
     * Entero determinista en [min, max] para una clave (sin aleatoriedad).
     */
    public static function vary(string $key, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (crc32('e2e-2026|'.$key) % ($max - $min + 1));
    }

    public function count(string $key, int $by = 1): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Días civiles simulados (desde el inicio hasta hoy, inclusive).
     *
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        $days = [];
        for ($d = $this->start; $d->lte($this->today); $d = $d->addDay()) {
            $days[] = $d;
        }

        return $days;
    }

    public function isHoliday(CarbonImmutable $day): bool
    {
        return isset($this->holidays[$day->toDateString()]);
    }

    /**
     * N-ésimo día laborable (lunes a sábado, sin festivos) antes de hoy.
     */
    public function workday(int $n, bool $mondayToFriday = false): CarbonImmutable
    {
        $day = $this->today;
        $found = 0;
        while ($found < $n) {
            $day = $day->subDay();
            if ($day->isSunday() || ($mondayToFriday && $day->isSaturday()) || $this->isHoliday($day)) {
                continue;
            }
            $found++;
        }

        return $day;
    }
}
