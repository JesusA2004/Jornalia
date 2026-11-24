<?php

namespace App\Enums;

enum PunchSource: string
{
    case Mobile = 'mobile';
    case MobileOffline = 'mobile_offline';
    case Web = 'web';
    case Kiosk = 'kiosk';
    case Import = 'import';
    /**
     * Sólo histórico: marcajes generados por la extinta salida automática. Ningún
     * proceso actual crea marcajes con este origen; se conservan intactos.
     */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'App móvil',
            self::MobileOffline => 'App móvil (sin conexión)',
            self::Web => 'Web',
            self::Kiosk => 'Kiosco',
            self::Import => 'Importación',
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
