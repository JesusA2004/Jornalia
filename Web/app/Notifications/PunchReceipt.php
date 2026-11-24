<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationCategory;
use App\Enums\PunchType;
use App\Http\Resources\V1\PunchResource;
use App\Models\Punch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Acuse automático de marcaje (PDF pantalla 12, punto 2): OBLIGATORIO y no
 * desactivable. Cada marcaje deja en la bandeja de la app la fecha y hora
 * registradas (hora oficial del servidor) y su folio; el push es adicional
 * y sólo se envía si FCM está configurado.
 */
final class PunchReceipt extends AppNotification
{
    public const KIND = 'attendance.punch_receipt';

    public function __construct(
        public readonly string $punchUuid,
        public readonly string $type,
        public readonly string $localDate,
        public readonly string $localTime,
        public readonly string $receiptCode,
    ) {}

    public static function for(Punch $punch, string $timezone): self
    {
        $local = $punch->server_time->setTimezone($timezone);

        return (new self(
            (string) $punch->uuid,
            $punch->type->value,
            $local->toDateString(),
            $local->format('H:i'),
            PunchResource::receiptCode($punch),
        ))->forOrganization((int) $punch->organization_id);
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::Attendance;
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(User $notifiable): string
    {
        return match ($this->type) {
            PunchType::ClockIn->value => 'Entrada registrada',
            PunchType::ClockOut->value => 'Salida registrada',
            PunchType::BreakStart->value => 'Inicio de descanso registrado',
            default => 'Fin de descanso registrado',
        };
    }

    public function body(User $notifiable): string
    {
        $date = CarbonImmutable::parse($this->localDate)->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY');

        return "{$date}, {$this->localTime} h. Folio {$this->receiptCode}.";
    }

    public function data(User $notifiable): array
    {
        return ['punch_uuid' => $this->punchUuid, 'date' => $this->localDate, 'type' => $this->type, 'receipt_code' => $this->receiptCode];
    }
}
