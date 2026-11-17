<?php

namespace App\Policies;

use App\Domain\Security\AccessScope;
use App\Models\Device;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganization;

class DevicePolicy
{
    use ChecksOrganization;

    public function __construct(private readonly AccessScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('devices.view');
    }

    public function view(User $user, Device $device): bool
    {
        if (! $this->sameOrganization($device) || ! $user->can('devices.view')) {
            return false;
        }
        if ($this->scope->hasFullAccess($user)) {
            return true;
        }

        return $device->employee_id !== null && $this->scope->canAccessEmployee($user, $device->employee_id);
    }

    public function approve(User $user, Device $device): bool
    {
        return $this->view($user, $device) && $user->can('devices.approve');
    }

    public function revoke(User $user, Device $device): bool
    {
        return $this->view($user, $device) && $user->can('devices.revoke');
    }

    public function update(User $user, Device $device): bool
    {
        return $this->view($user, $device) && ($user->can('devices.approve') || $user->can('devices.revoke'));
    }
}
