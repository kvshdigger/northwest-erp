<?php

namespace Webkul\TimeOff\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasScopedPermissions;
use Webkul\TimeOff\Models\Leave;

class LeavePolicy
{
    use HandlesAuthorization, HasScopedPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_time_off_time::off');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Leave $leave): bool
    {
        return $user->can('view_time_off_time::off');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_time_off_time::off');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Leave $leave): bool
    {
        if (! $user->can('update_time_off_time::off')) {
            return false;
        }

        if ($this->hasGlobalAccess($user)) {
            return true;
        }
        if (! in_array($user->resource_permission, [PermissionType::INDIVIDUAL, PermissionType::GROUP], true)) {
            return false;
        }
        $employee = $leave->employee;
        if (! $employee) {
            return false;
        }
        if ((int) $employee->user_id === (int) $user->id || (int) $employee->parent?->user_id === (int) $user->id || $leave->holidayStatus?->notifiedTimeOffOfficers()->whereKey($user->id)->exists()) {
            return true;
        }

        return $user->resource_permission === PermissionType::GROUP
            && $employee->user?->teams()->whereIn('teams.id', $user->teams()->select('teams.id'))->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Leave $leave): bool
    {
        if (! $user->can('delete_time_off_time::off')) {
            return false;
        }

        return $this->hasAccess($user, $leave, 'employee');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_time_off_time::off');
    }
}
