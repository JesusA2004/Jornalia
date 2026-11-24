<?php

namespace App\Enums;

enum AuthMethod: string
{
    case DeviceBiometric = 'device_biometric';
    case DeviceCredential = 'device_credential';
    case FaceVerification = 'face_verification';
    case WebSession = 'web_session';
    case KioskPin = 'kiosk_pin';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::DeviceBiometric => 'Biometría del dispositivo',
            self::DeviceCredential => 'Credencial del dispositivo',
            self::FaceVerification => 'Verificación facial',
            self::WebSession => 'Sesión web',
            self::KioskPin => 'Kiosco (número de empleado y NIP)',
            self::System => 'Generado por sistema (histórico)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
