<?php

namespace App\Enums;

enum AdjustmentAction: string
{
    case Add = 'add';
    case Void = 'void';
    case Replace = 'replace';

    public function label(): string
    {
        return match ($this) {
            self::Add => 'Agregar evento reconocido',
            self::Void => 'Excluir del cálculo',
            self::Replace => 'Reconocer otra hora',
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
