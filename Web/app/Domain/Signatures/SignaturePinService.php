<?php

namespace App\Domain\Signatures;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * NIP de firma electrónica del colaborador (distinto de su contraseña).
 *
 * Se guarda sólo el hash. Tras 5 intentos fallidos el NIP queda bloqueado
 * 15 minutos; el bloqueo también protege el kiosco (número + NIP).
 */
final class SignaturePinService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(private readonly AuditLogger $audit) {}

    public function hasPin(User $user): bool
    {
        return filled($user->signature_pin_hash);
    }

    /**
     * Define o cambia el NIP. Si ya existe uno, exige el NIP actual.
     *
     * @throws ValidationException
     */
    public function set(User $user, string $pin, ?string $currentPin = null): void
    {
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            throw ValidationException::withMessages(['pin' => 'El NIP debe tener de 4 a 6 dígitos.']);
        }
        if (self::isTrivial($pin)) {
            throw ValidationException::withMessages(['pin' => 'Elige un NIP menos predecible.']);
        }
        if ($this->hasPin($user)) {
            if ($currentPin === null || $currentPin === '') {
                throw ValidationException::withMessages(['current_pin' => 'Indica tu NIP actual.']);
            }
            if (! $this->verify($user, $currentPin)) {
                throw ValidationException::withMessages(['current_pin' => 'El NIP actual no es correcto.']);
            }
        }

        $user->forceFill([
            'signature_pin_hash' => Hash::make($pin),
            'signature_pin_set_at' => now(),
            'signature_pin_failed_attempts' => 0,
            'signature_pin_locked_until' => null,
        ])->save();

        $this->audit->log('signature_pin.set', $user, 'NIP de firma definido');
    }

    /**
     * Verifica el NIP con bloqueo por intentos. Devuelve false si no coincide.
     *
     * @throws ValidationException si el NIP está bloqueado o no se ha definido
     */
    public function verify(User $user, string $pin): bool
    {
        return DB::transaction(function () use ($user, $pin) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! filled($locked->signature_pin_hash)) {
                throw ValidationException::withMessages(['pin' => 'Primero define tu NIP de firma.']);
            }
            $until = $locked->signature_pin_locked_until;
            if ($until !== null && CarbonImmutable::parse($until)->isFuture()) {
                throw ValidationException::withMessages(['pin' => 'NIP bloqueado por intentos fallidos. Intenta en '.self::LOCK_MINUTES.' minutos.']);
            }

            if (Hash::check($pin, (string) $locked->signature_pin_hash)) {
                if ((int) $locked->signature_pin_failed_attempts > 0 || $until !== null) {
                    $locked->forceFill(['signature_pin_failed_attempts' => 0, 'signature_pin_locked_until' => null])->save();
                }
                $user->setRawAttributes($locked->getAttributes(), true);

                return true;
            }

            $attempts = (int) $locked->signature_pin_failed_attempts + 1;
            $lock = $attempts >= self::MAX_ATTEMPTS;
            $locked->forceFill([
                'signature_pin_failed_attempts' => $lock ? 0 : $attempts,
                'signature_pin_locked_until' => $lock ? now()->addMinutes(self::LOCK_MINUTES) : null,
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            if ($lock) {
                $this->audit->log('signature_pin.locked', $locked, 'NIP de firma bloqueado por intentos fallidos');
            }

            return false;
        });
    }

    public function isLocked(User $user): bool
    {
        $until = $user->signature_pin_locked_until;

        return $until !== null && CarbonImmutable::parse($until)->isFuture();
    }

    private static function isTrivial(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }
}
