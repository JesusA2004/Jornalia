<?php

namespace App\Enums;

enum ContractType: string
{
    case Indefinite = 'indefinite';
    case FixedTerm = 'fixed_term';
    case Seasonal = 'seasonal';
    case Trial = 'trial';
    case Training = 'training';

    public function label(): string
    {
        return match ($this) {
            self::Indefinite => 'Tiempo indeterminado',
            self::FixedTerm => 'Tiempo determinado',
            self::Seasonal => 'Por temporada',
            self::Trial => 'Periodo de prueba',
            self::Training => 'Capacitación inicial',
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
