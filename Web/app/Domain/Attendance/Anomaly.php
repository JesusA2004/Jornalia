<?php

namespace App\Domain\Attendance;

/**
 * Catálogo de anomalías de marcajes y jornadas (códigos guardados en `anomalies`).
 */
enum Anomaly: string
{
    case ClockSkew = 'clock_skew';
    case LateSync = 'late_sync';
    case MockedLocation = 'mocked_location';
    case OutsideGeofence = 'outside_geofence';
    case NoLocation = 'no_location';
    case LowAccuracy = 'low_accuracy';
    case OutOfSequence = 'out_of_sequence';
    case OfflineNotAllowed = 'offline_not_allowed';
    case Holiday = 'holiday';
    case RestDay = 'rest_day';
    case OutsideSchedule = 'outside_schedule';
    case MissingClockOut = 'missing_clock_out';
    case BreakNotClosed = 'break_not_closed';
    case AutoClockOut = 'auto_clock_out';

    public function label(): string
    {
        return match ($this) {
            self::ClockSkew => 'Desfase de reloj del dispositivo',
            self::LateSync => 'Sincronización tardía',
            self::MockedLocation => 'Ubicación simulada',
            self::OutsideGeofence => 'Fuera de geocerca',
            self::NoLocation => 'Sin ubicación',
            self::LowAccuracy => 'Precisión de ubicación baja',
            self::OutOfSequence => 'Fuera de secuencia',
            self::OfflineNotAllowed => 'Sin conexión no permitido',
            self::Holiday => 'Marcaje en día festivo',
            self::RestDay => 'Marcaje en día de descanso',
            self::OutsideSchedule => 'Fuera del horario programado',
            self::MissingClockOut => 'Falta registro de salida',
            self::BreakNotClosed => 'Descanso sin cerrar',
            self::AutoClockOut => 'Salida automática',
        };
    }

    /** Etiqueta de un código (los desconocidos se muestran tal cual). */
    public static function labelFor(string $code): string
    {
        return self::tryFrom($code)?->label() ?? $code;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
