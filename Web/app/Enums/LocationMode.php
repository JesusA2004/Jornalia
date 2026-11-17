<?php

namespace App\Enums;

enum LocationMode: string
{
    case Free = 'free';
    case Record = 'record';
    case Required = 'required';
    case Geofence = 'geofence';
    case DeviceOnly = 'device_only';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Libre',
            self::Record => 'Registrar ubicación',
            self::Required => 'Ubicación obligatoria',
            self::Geofence => 'Dentro de geocerca',
            self::DeviceOnly => 'Sólo dispositivo corporativo',
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
