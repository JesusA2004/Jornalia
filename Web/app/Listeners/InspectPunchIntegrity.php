<?php

namespace App\Listeners;

use App\Domain\Security\DeviceIntegrityMonitor;
use App\Events\PunchRecorded;

/**
 * Tras cada marcaje revisa señales de ubicación simulada o teléfono alterado
 * (cabecera `X-Device-Integrity` enviada por la app) y alerta en Cumplimiento.
 */
final class InspectPunchIntegrity
{
    public function __construct(private readonly DeviceIntegrityMonitor $monitor) {}

    public function handle(PunchRecorded $event): void
    {
        $signals = app()->runningInConsole() && ! app()->runningUnitTests()
            ? []
            : DeviceIntegrityMonitor::parseHeader(request()->header('X-Device-Integrity'));

        $this->monitor->inspectPunch($event->punch, $signals);
    }
}
