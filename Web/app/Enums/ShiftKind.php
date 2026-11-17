<?php

namespace App\Enums;

enum ShiftKind: string
{
    case Day = 'day';
    case Night = 'night';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Diurna',
            self::Night => 'Nocturna',
            self::Mixed => 'Mixta',
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
