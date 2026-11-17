<?php

namespace App\Enums;

enum PunchStatus: string
{
    case Accepted = 'accepted';
    case Flagged = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Aceptado',
            self::Flagged => 'Requiere revisión',
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
