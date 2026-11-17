<?php

namespace App\Enums;

enum WorkdayStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Incomplete = 'incomplete';
    case Absent = 'absent';
    case RestDay = 'rest_day';
    case RestDayWorked = 'rest_day_worked';
    case Holiday = 'holiday';
    case HolidayWorked = 'holiday_worked';
    case Leave = 'leave';
    case Vacation = 'vacation';
    case Unscheduled = 'unscheduled';
    case Future = 'future';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Presente',
            self::Late => 'Retardo',
            self::Incomplete => 'Incompleta',
            self::Absent => 'Ausente',
            self::RestDay => 'Descanso',
            self::RestDayWorked => 'Descanso trabajado',
            self::Holiday => 'Festivo',
            self::HolidayWorked => 'Festivo trabajado',
            self::Leave => 'Permiso / incapacidad',
            self::Vacation => 'Vacaciones',
            self::Unscheduled => 'Sin horario',
            self::Future => 'Programado',
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
