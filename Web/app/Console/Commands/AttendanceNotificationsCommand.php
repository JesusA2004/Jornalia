<?php

namespace App\Console\Commands;

use App\Domain\Notifications\AnnouncementService;
use App\Domain\Notifications\AttendanceReminders;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('asistencias:notificaciones {--solo-comunicados : Sólo envía comunicados programados} {--solo-recordatorios : Sólo avisos automáticos de asistencia}')]
#[Description('Envía comunicados programados y avisos automáticos (entrada / salida pendiente). Idempotente.')]
final class AttendanceNotificationsCommand extends Command
{
    public function handle(CurrentOrganization $current, AnnouncementService $announcements, AttendanceReminders $reminders): int
    {
        $totals = ['announcements' => 0, 'late' => 0, 'clock_out' => 0];

        foreach (Organization::query()->where('status', 'active')->cursor() as $org) {
            $current->runAs($org, function () use ($org, $announcements, $reminders, &$totals) {
                if (! $this->option('solo-recordatorios')) {
                    $totals['announcements'] += $announcements->dispatchDue();
                }
                if (! $this->option('solo-comunicados')) {
                    $sent = $reminders->run($org);
                    $totals['late'] += $sent['late'];
                    $totals['clock_out'] += $sent['clock_out'];
                }
            });
        }

        $this->info("Comunicados: {$totals['announcements']} · Entrada pendiente: {$totals['late']} · Salida pendiente: {$totals['clock_out']}");

        return self::SUCCESS;
    }
}
