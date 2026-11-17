<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganization;

class SchedulePolicy
{
    use ChecksOrganization;

    public function viewAny(User $user): bool
    {
        return $user->can('schedules.view');
    }

    public function view(User $user, Schedule $schedule): bool
    {
        return $this->sameOrganization($schedule) && $user->can('schedules.view');
    }

    public function create(User $user): bool
    {
        return $user->can('schedules.manage');
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $this->sameOrganization($schedule) && $user->can('schedules.manage');
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $this->update($user, $schedule);
    }

    public function assign(User $user, Schedule $schedule): bool
    {
        return $this->sameOrganization($schedule) && $user->can('schedules.assign');
    }
}
