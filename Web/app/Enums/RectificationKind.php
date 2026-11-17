<?php

namespace App\Enums;

enum RectificationKind: string
{
    case MissingPunch = 'missing_punch';
    case WrongType = 'wrong_type';
    case TimeClarification = 'time_clarification';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MissingPunch => 'Marcaje omitido',
            self::WrongType => 'Tipo de marcaje erróneo',
            self::TimeClarification => 'Aclaración de hora',
            self::Other => 'Otro',
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
