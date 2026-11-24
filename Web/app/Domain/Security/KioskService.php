<?php

namespace App\Domain\Security;

use App\Domain\Attendance\AttendanceException;
use App\Domain\Attendance\PolicyResolver;
use App\Domain\Attendance\PunchInput;
use App\Domain\Attendance\PunchRecorder;
use App\Domain\Audit\AuditLogger;
use App\Domain\Signatures\SignaturePinService;
use App\Enums\AuthMethod;
use App\Enums\EmployeeStatus;
use App\Enums\PunchSource;
use App\Enums\PunchType;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FaceVerification;
use App\Models\Kiosk;
use App\Models\Organization;
use App\Models\Punch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kiosco de sucursal (PDF pantalla 11, punto 6) para colaboradores sin celular.
 *
 *  - Alta en la web por sucursal (`devices.kiosk`) con un código de activación de UN solo
 *    uso y vigencia corta; sólo se guarda su hash.
 *  - La tableta canjea el código por un token propio (también sólo hash en BD).
 *  - Cada marcaje identifica al colaborador con número de empleado + NIP de firma
 *    (SignaturePinService: bloqueo tras 5 intentos durante 15 min) y se escribe con
 *    PunchRecorder (misma cadena de integridad, idempotente por client_uuid).
 *  - El colaborador debe estar activo y asignado HOY a la sucursal del kiosco.
 *
 * Limitación declarada: el NIP prueba conocimiento, no presencia; si la política exige
 * verificación facial, el kiosco debe obtener una verificación vigente (endpoints
 * kiosk/face/*). Sin conexión no es posible verificar el rostro: esos marcajes se rechazan.
 */
final class KioskService
{
    public const ACTIVATION_HOURS = 48;

    /**
     * Código de error propio del kiosco cuando la política exige verificación facial y
     * no hay una vigente. Antes se respondía `policy_violation`; el cuerpo conserva
     * `legacy_code: policy_violation` y el mismo mensaje por compatibilidad.
     */
    public const FACE_REQUIRED = 'face_verification_required';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SignaturePinService $pins,
        private readonly PunchRecorder $recorder,
        private readonly PolicyResolver $policies,
    ) {}

    /**
     * @return array{kiosk: Kiosk, activation_code: string}
     */
    public function create(User $by, Branch $branch, string $name): array
    {
        return DB::transaction(function () use ($by, $branch, $name) {
            $kiosk = Kiosk::query()->create([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'status' => Kiosk::STATUS_PENDING,
                'created_by' => $by->id,
            ]);
            $code = $this->issueCode($kiosk);
            $this->audit->log('kiosk.created', $kiosk, "Kiosco creado: {$kiosk->name}", ['branch_id' => $branch->id]);

            return ['kiosk' => $kiosk, 'activation_code' => $code];
        });
    }

    /**
     * Nuevo código de activación. Desactiva el token vigente: la tableta deberá activarse otra vez.
     */
    public function regenerateCode(User $by, Kiosk $kiosk): string
    {
        return DB::transaction(function () use ($by, $kiosk) {
            $kiosk->forceFill([
                'status' => Kiosk::STATUS_PENDING,
                'token_hash' => null,
                'revoked_at' => null, 'revoked_by' => null, 'revoke_reason' => null,
            ])->save();
            $code = $this->issueCode($kiosk);
            $this->audit->log('kiosk.code_issued', $kiosk, "Código de activación generado: {$kiosk->name}", ['by' => $by->id]);

            return $code;
        });
    }

    /**
     * Revoca el kiosco: invalida su token y su código de activación. Queda en la
     * bitácora y no se elimina.
     */
    public function revoke(User $by, Kiosk $kiosk, string $reason): Kiosk
    {
        $kiosk->forceFill([
            'status' => Kiosk::STATUS_REVOKED,
            'token_hash' => null,
            'activation_code_hash' => null,
            'activation_expires_at' => null,
            'revoked_at' => now(),
            'revoked_by' => $by->id,
            'revoke_reason' => $reason,
        ])->save();
        $this->audit->log('kiosk.revoked', $kiosk, "Kiosco revocado: {$kiosk->name}", ['reason' => $reason]);

        return $kiosk;
    }

    /**
     * Canjea el código de un solo uso por el token del kiosco.
     *
     * @param  array{device_uuid:string, platform:string, os_version:?string, app_version:string, model:?string}  $info
     * @return array{kiosk: Kiosk, token: string}
     */
    public function activate(string $code, array $info): array
    {
        $hash = self::hashCode($code);

        return DB::transaction(function () use ($hash, $info) {
            /** @var Kiosk|null $kiosk */
            $kiosk = Kiosk::query()->withoutGlobalScopes()->where('activation_code_hash', $hash)->lockForUpdate()->first();
            if ($kiosk === null || $kiosk->status !== Kiosk::STATUS_PENDING
                || $kiosk->activation_expires_at === null || $kiosk->activation_expires_at->isPast()) {
                throw new AttendanceException('kiosk_activation_invalid', 'Código de activación no válido o vencido.', 422);
            }

            $token = Str::random(64);
            $kiosk->forceFill([
                'status' => Kiosk::STATUS_ACTIVE,
                'activation_code_hash' => null,
                'activation_expires_at' => null,
                'token_hash' => hash('sha256', $token),
                'device_uuid' => $info['device_uuid'],
                'platform' => $info['platform'],
                'os_version' => $info['os_version'],
                'app_version' => $info['app_version'],
                'model' => $info['model'],
                'activated_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            $this->audit->log('kiosk.activated', $kiosk, "Kiosco activado: {$kiosk->name}", ['model' => $info['model'], 'device_uuid' => $info['device_uuid']], $kiosk->organization_id);

            return ['kiosk' => $kiosk, 'token' => $token];
        });
    }

    /**
     * Kiosco activo para el token recibido. Sólo se compara el SHA-256 del token;
     * el valor en claro nunca se guarda.
     */
    public function authenticate(?string $token): ?Kiosk
    {
        if ($token === null || $token === '') {
            return null;
        }

        /** @var Kiosk|null $kiosk */
        $kiosk = Kiosk::query()->withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();

        return $kiosk !== null && $kiosk->isActive() ? $kiosk : null;
    }

    /**
     * Identifica al colaborador (número + NIP). Mensaje genérico para no revelar
     * qué números de empleado existen.
     */
    public function identify(Kiosk $kiosk, string $employeeNumber, string $pin): Employee
    {
        $invalid = fn () => new AttendanceException('kiosk_invalid_credentials', 'Número de empleado o NIP incorrecto.', 401);

        /** @var Employee|null $employee */
        $employee = Employee::query()->withoutGlobalScopes()
            ->where('organization_id', $kiosk->organization_id)
            ->where('employee_number', $employeeNumber)
            ->first();
        if ($employee === null || $employee->user_id === null) {
            throw $invalid();
        }
        $user = User::query()->find($employee->user_id);
        if ($user === null) {
            throw $invalid();
        }

        try {
            $ok = $this->pins->verify($user, $pin);
        } catch (ValidationException $e) {
            $locked = $this->pins->isLocked($user);
            throw new AttendanceException($locked ? 'pin_locked' : 'pin_not_set', $locked
                ? 'NIP bloqueado por intentos fallidos. Intenta más tarde.'
                : 'Aún no defines tu NIP. Defínelo en la app o con Recursos Humanos.', $locked ? 423 : 422);
        }
        if (! $ok) {
            $this->audit->log('kiosk.pin_failed', $kiosk, "NIP incorrecto en kiosco: {$kiosk->name}", ['employee_id' => $employee->id], $kiosk->organization_id);
            throw $invalid();
        }

        // Fecha civil de la sucursal (regla 4): después de las 18:00 de Ciudad de México ya
        // es «mañana» en UTC. Mismo criterio que la app: puede marcar quien tenga relación
        // laboral vigente hoy y acceso activo; una baja con fecha futura (estatus «Baja»
        // anticipado) no bloquea los días que aún trabaja (`asistencias:revocar-bajas`).
        $today = CarbonImmutable::now($this->timezone($kiosk))->toDateString();
        if ($employee->status === EmployeeStatus::Inactive || ! $user->isActive()
            || ! $user->belongsToOrganization((int) $kiosk->organization_id)
            || $employee->employmentOn($today) === null) {
            throw new AttendanceException('kiosk_employee_inactive', 'Tu registro no está activo. Acude con Recursos Humanos.', 403);
        }

        $assigned = DB::table('employment_assignments as ea')
            ->join('employments as em', 'em.id', '=', 'ea.employment_id')
            ->where('em.employee_id', $employee->id)
            ->where('ea.branch_id', $kiosk->branch_id)
            ->whereDate('ea.effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('ea.effective_to')->orWhereDate('ea.effective_to', '>=', $today))
            ->exists();
        if (! $assigned) {
            throw new AttendanceException('kiosk_wrong_branch', 'No estás asignado a esta sucursal.', 403);
        }

        return $employee;
    }

    /**
     * Marcaje desde el kiosco (en línea u offline). Idempotente por client_uuid.
     *
     * @param  array{employee_number:string, pin:string, type:string, client_uuid:string, device_time:string, timezone?:string|null, captured_offline?:bool, face_verification_id?:string|null, location?:array<string,mixed>|null}  $data
     * @return array{punch: Punch, duplicate: bool, employee: Employee}
     */
    public function punch(Kiosk $kiosk, array $data, ?string $ip, ?string $userAgent): array
    {
        $employee = $this->identify($kiosk, (string) $data['employee_number'], (string) $data['pin']);
        $offline = (bool) ($data['captured_offline'] ?? false);
        $location = $data['location'] ?? null;
        $raw = (string) $data['device_time'];

        $input = new PunchInput(
            clientUuid: (string) $data['client_uuid'],
            type: PunchType::from((string) $data['type']),
            source: PunchSource::Kiosk,
            authMethod: AuthMethod::KioskPin,
            deviceTime: CarbonImmutable::parse($raw)->utc(),
            deviceTimeRaw: $raw,
            timezone: (string) ($data['timezone'] ?? ''),
            deviceUuid: $kiosk->device_uuid,
            nonce: null,
            signature: null,
            capturedOffline: $offline,
            latitude: $location !== null ? (float) $location['latitude'] : null,
            longitude: $location !== null ? (float) $location['longitude'] : null,
            accuracyMeters: $location !== null && isset($location['accuracy_meters']) ? (int) round((float) $location['accuracy_meters']) : null,
            locationCapturedAt: $location !== null && isset($location['captured_at']) ? CarbonImmutable::parse($location['captured_at'])->utc() : null,
            locationMocked: $location !== null && isset($location['is_mocked']) ? (bool) $location['is_mocked'] : null,
            faceVerificationId: $data['face_verification_id'] ?? null,
            ipAddress: $ip,
            userAgent: $userAgent,
            kioskId: $kiosk->id,
        );

        /** @var User $user */
        $user = User::query()->findOrFail($employee->user_id);
        try {
            $result = $this->recorder->record($user, $employee, $input, null);
        } catch (AttendanceException $e) {
            // La regla facial vive en PunchRecorder (fuente única); aquí sólo se le da al
            // kiosco un código propio cuando ésa fue la causa del rechazo.
            if ($e->errorCode === 'policy_violation' && $this->missingFaceVerification($employee, $input)) {
                throw new AttendanceException(self::FACE_REQUIRED, $e->getMessage(), $e->status);
            }
            throw $e;
        }
        $this->touch($kiosk);

        return $result + ['employee' => $employee];
    }

    /**
     * Registra la última conexión del kiosco sin disparar eventos ni auditoría.
     */
    public function touch(Kiosk $kiosk): void
    {
        $kiosk->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    /**
     * Hash del código de activación normalizado (sólo letras y números, en
     * mayúsculas). El código en claro no se guarda.
     */
    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
    }

    /**
     * Zona horaria de la sucursal del kiosco (centro de trabajo) o, en su defecto, de la organización.
     */
    private function timezone(Kiosk $kiosk): string
    {
        $branch = Branch::query()->withoutGlobalScopes()->with('workCenter')->find($kiosk->branch_id);

        // `??` tolera nulos intermedios (sucursal o centro de trabajo inexistentes).
        return $branch?->workCenter->timezone
            ?? Organization::query()->find($kiosk->organization_id)->timezone
            ?? (string) config('app.timezone', 'UTC');
    }

    private function issueCode(Kiosk $kiosk): string
    {
        // 10 caracteres sin ambiguos (0/O, 1/I): XXXXX-XXXXX.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $plain = '';
        for ($i = 0; $i < 10; $i++) {
            $plain .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $kiosk->forceFill([
            'activation_code_hash' => self::hashCode($plain),
            'activation_expires_at' => now()->addHours(self::ACTIVATION_HOURS),
        ])->save();

        return substr($plain, 0, 5).'-'.substr($plain, 5);
    }

    /**
     * ¿La política exige verificación facial y el marcaje no trae una vigente (coincidente,
     * sin consumir y sin vencer) del mismo colaborador?
     */
    private function missingFaceVerification(Employee $employee, PunchInput $input): bool
    {
        if (! $this->policies->for($employee)->face_verification_required) {
            return false;
        }

        return $input->faceVerificationId === null || ! FaceVerification::query()
            ->where('uuid', $input->faceVerificationId)
            ->where('employee_id', $employee->id)
            ->where('result', 'match')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
