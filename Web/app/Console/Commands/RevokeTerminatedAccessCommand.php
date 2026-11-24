<?php

namespace App\Console\Commands;

use App\Domain\Workforce\WorkforceService;
use App\Enums\DeviceStatus;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Signature('asistencias:revocar-bajas')]
#[Description('Revoca el acceso a la app y los dispositivos de los colaboradores cuya baja (con fecha programada) ya llegó')]
final class RevokeTerminatedAccessCommand extends Command
{
    /**
     * Bajas con fecha futura: el acceso se revoca el mismo día de la baja (PDF RH
     * pantallas 09 y 11). Sólo actúa sobre colaboradores dados de baja SIN relación
     * laboral vigente que aún conserven membresía activa o dispositivos activos.
     */
    public function handle(CurrentOrganization $current, WorkforceService $workforce): int
    {
        $total = 0;
        foreach (Organization::query()->cursor() as $org) {
            // El último día laboral puede checar su salida: la revocación programada ocurre
            // al cierre (22:00 local) del día de la baja; después de esa hora o en días
            // posteriores, cualquier baja pendiente se revoca de inmediato.
            $now = CarbonImmutable::now($org->timezone ?: config('app.timezone'));
            $today = ($now->hour >= 22 ? $now : $now->subDay())->toDateString();

            $current->runAs($org, function () use ($org, $today, $workforce, &$total) {
                Employee::query()
                    ->where('status', EmployeeStatus::Terminated->value)
                    // La última relación laboral terminó hoy o antes, y no hay otra vigente (reingreso).
                    ->whereHas('employments', fn (Builder $q) => $q->whereNotNull('ends_on')->whereDate('ends_on', '<=', $today))
                    ->whereDoesntHave('employments', fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('ends_on')->orWhereDate('ends_on', '>', $today)))
                    ->where(fn (Builder $q) => $q
                        ->whereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('organization_user as ou')
                            ->whereColumn('ou.user_id', 'employees.user_id')->where('ou.organization_id', $org->id)->where('ou.status', 'active'))
                        ->orWhereExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('devices as d')
                            ->whereColumn('d.employee_id', 'employees.id')->whereNull('d.deleted_at')
                            ->whereIn('d.status', [DeviceStatus::Approved->value, DeviceStatus::Pending->value])))
                    ->chunkById(200, function ($employees) use ($workforce, &$total) {
                        foreach ($employees as $employee) {
                            $workforce->revokeAccess(self::systemActor($employee), $employee, 'Baja del colaborador (fecha programada)');
                            $total++;
                        }
                    });
            });
        }

        $this->info("{$total} accesos revocados por baja.");

        return self::SUCCESS;
    }

    /**
     * Actor de la revocación automática: quien registró la baja en la bitácora o,
     * si no existe, el propio usuario del colaborador (el sistema no inventa usuarios).
     */
    private static function systemActor(Employee $employee): User
    {
        $by = AuditLog::query()->where('action', 'employee.terminated')
            ->where('subject_type', $employee->getMorphClass())->where('subject_id', $employee->id)->latest('id')->value('user_id');

        return User::query()->find($by) ?? $employee->user ?? new User(['name' => 'Sistema']);
    }
}
