<?php

namespace App\Enums;

enum PunchType: string
{
    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';

    public function label(): string
    {
        return match ($this) {
            self::ClockIn => 'Entrada',
            self::ClockOut => 'Salida',
            self::BreakStart => 'Inicio de descanso',
            self::BreakEnd => 'Fin de descanso',
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
