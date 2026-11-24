<?php

namespace App\Domain\Security;

use App\Domain\Audit\AuditLogger;
use App\Enums\AlertSeverity;
use App\Models\ComplianceAlert;
use App\Models\Device;
use App\Models\Punch;

/**
 * Señales de integridad del teléfono (PDF pantalla 11, punto 5): ubicación simulada y
 * equipo alterado (root / jailbreak / emulador) ⇒ alerta en Cumplimiento.
 *
 * IMPORTANTE: son señales que REPORTA la app (APIs del sistema operativo). Un teléfono
 * alterado con herramientas de ocultamiento puede no reportarlas: la detección NO es
 * infalible; complementa (no sustituye) la geocerca, el vínculo único y la biometría.
 * Las señales no bloquean el marcaje: el evento se conserva y se revisa.
 */
final class DeviceIntegrityMonitor
{
    /** Señales reconocidas (cabecera `X-Device-Integrity` o `device.integrity` al iniciar sesión). */
    public const SIGNALS = ['rooted', 'jailbroken', 'emulator', 'mock_location'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<string>
     */
    public static function parseHeader(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }
        $values = array_map(fn ($v) => strtolower(trim($v)), explode(',', $header));

        return array_values(array_unique(array_intersect($values, self::SIGNALS)));
    }

    /**
     * Revisa un marcaje recién registrado.
     *
     * @param  list<string>  $signals  señales reportadas por la app en la petición
     */
    public function inspectPunch(Punch $punch, array $signals = []): void
    {
        if ($punch->location_mocked === true || in_array('mock_location', $signals, true)) {
            $this->alert($punch->organization_id, $punch->employee_id, 'mock_location', AlertSeverity::Critical,
                'Marcaje con ubicación simulada reportada por el teléfono.',
                ['punch_uuid' => $punch->uuid, 'device_id' => $punch->device_id, 'kiosk_id' => $punch->getAttribute('kiosk_id')]);
        }

        $tampered = array_values(array_intersect($signals, ['rooted', 'jailbroken', 'emulator']));
        if ($tampered !== []) {
            $this->alert($punch->organization_id, $punch->employee_id, 'device_tampered', AlertSeverity::Critical,
                'Marcaje desde un teléfono alterado o emulador ('.implode(', ', $tampered).').',
                ['punch_uuid' => $punch->uuid, 'device_id' => $punch->device_id, 'signals' => $tampered]);
            if ($punch->device_id !== null) {
                $this->recordOnDevice(Device::query()->withoutGlobalScopes()->find($punch->device_id), $tampered);
            }
        }
    }

    /**
     * Señales reportadas al vincular / iniciar sesión.
     *
     * @param  list<string>  $signals
     */
    public function inspectDevice(Device $device, array $signals): void
    {
        $signals = array_values(array_intersect($signals, self::SIGNALS));
        if ($signals === []) {
            return;
        }
        $this->recordOnDevice($device, $signals);

        $tampered = array_values(array_intersect($signals, ['rooted', 'jailbroken', 'emulator']));
        if ($tampered !== [] && $device->employee_id !== null) {
            $this->alert($device->organization_id, $device->employee_id, 'device_tampered', AlertSeverity::Critical,
                'Teléfono alterado o emulador al iniciar sesión ('.implode(', ', $tampered).').',
                ['device_id' => $device->id, 'model' => $device->model, 'signals' => $tampered]);
        }
    }

    /**
     * @param  list<string>  $signals
     */
    private function recordOnDevice(?Device $device, array $signals): void
    {
        if ($device === null) {
            return;
        }
        $flags = array_values(array_unique([...(array) ($device->integrity_flags ?? []), ...$signals]));
        $device->forceFill(['integrity_flags' => $flags, 'integrity_reported_at' => now()])->saveQuietly();
        $this->audit->log('device.integrity_signal', $device, 'Señal de integridad del teléfono: '.implode(', ', $signals), ['signals' => $signals], $device->organization_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function alert(int $organizationId, int $employeeId, string $rule, AlertSeverity $severity, string $message, array $data): void
    {
        ComplianceAlert::query()->withoutGlobalScopes()->firstOrCreate(
            ['employee_id' => $employeeId, 'date' => now()->toDateString(), 'rule_code' => $rule],
            [
                'organization_id' => $organizationId,
                'severity' => $severity,
                'message' => $message,
                'legal_basis' => 'Integridad del registro electrónico de jornada (LFT art. 132, fr. XXXIV)',
                'data' => $data,
                'status' => 'open',
            ],
        );
    }
}
