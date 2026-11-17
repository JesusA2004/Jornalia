<?php

namespace App\Enums;

enum WorkModality: string
{
    case Onsite = 'onsite';
    case Remote = 'remote';
    case Hybrid = 'hybrid';
    case Field = 'field';

    public function label(): string
    {
        return match ($this) {
            self::Onsite => 'Presencial',
            self::Remote => 'Teletrabajo',
            self::Hybrid => 'Híbrido',
            self::Field => 'Campo',
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
