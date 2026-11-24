<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\Listeners\NotifyDeviceStatus;
use App\Domain\Notifications\Listeners\SendPunchReceipt;
use App\Events\PunchRecorded;
use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del módulo de notificaciones: cliente FCM, avisos por eventos y
 * ocultamiento de avisos retirados (nunca se borran).
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FcmClient::class);
    }

    public function boot(): void
    {
        Device::observe(NotifyDeviceStatus::class);

        // Acuse obligatorio de cada marcaje (no desactivable).
        Event::listen(PunchRecorded::class, SendPunchReceipt::class);

        // Un comunicado retirado deja de mostrarse en la bandeja (web y app).
        DatabaseNotification::addGlobalScope('not_withdrawn', function (Builder $query): void {
            $query->whereNull($query->qualifyColumn('withdrawn_at'));
        });
    }
}
