<?php

namespace App\Policies;

use App\Domain\Security\AccessScope;
use App\Models\Employee;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganization;

class EmployeePolicy
{
    use ChecksOrganization;

    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->sameOrganization($employee)
            && $user->can('employees.view')
            && $this->scope->canAccessEmployee($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('employees.update');
    }

    public function terminate(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('employees.terminate');
    }

    public function rehire(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('employees.create');
    }

    public function move(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && ($user->can('employees.update') || $user->can('schedules.assign'));
    }

    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('employees.view_sensitive');
    }

    public function manageAccount(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('employees.update') && $user->can('users.manage');
    }

    public function manageSchedule(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee) && $user->can('schedules.assign');
    }

    public function import(User $user): bool
    {
        return $user->can('employees.import');
    }
}
