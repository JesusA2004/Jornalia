<?php

namespace App\Enums;

enum ScheduleType: string
{
    case Fixed = 'fixed';
    case Rotating = 'rotating';
    case Night = 'night';
    case Mixed = 'mixed';
    case Flexible = 'flexible';
    case Split = 'split';
    case Cycle = 'cycle';
    case OnCall = 'on_call';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fijo',
            self::Rotating => 'Rotativo',
            self::Night => 'Nocturno',
            self::Mixed => 'Mixto',
            self::Flexible => 'Flexible',
            self::Split => 'Partido',
            self::Cycle => 'Por ciclos',
            self::OnCall => 'Por guardias',
            self::Variable => 'Variable',
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
