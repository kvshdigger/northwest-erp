<?php

namespace Webkul\Employee\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasScopedPermissions;

class EmployeePolicy
{
    use HandlesAuthorization, HasScopedPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_employee_employee');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Employee $employee): bool
    {
        return $user->can('view_employee_employee');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_employee_employee');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Employee $employee): bool
    {
        if (! $user->can('update_employee_employee')) {
            return false;
        }

        if ($this->hasGlobalAccess($user)) {
            return true;
        }
        if (! in_array($user->resource_permission, [PermissionType::INDIVIDUAL, PermissionType::GROUP], true)) {
            return false;
        }
        $coach = $employee->coach?->user;
        if (! $coach) {
            return false;
        }
        if ((int) $coach->id === (int) $user->id) {
            return true;
        }

        return $user->resource_permission === PermissionType::GROUP
            && $coach->teams()->whereIn('teams.id', $user->teams()->select('teams.id'))->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Employee $employee): bool
    {
        if (! $user->can('delete_employee_employee')) {
            return false;
        }

        return $this->hasAccess($user, $employee, 'coach');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_employee_employee');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Employee $employee): bool
    {
        if (! $user->can('force_delete_employee_employee')) {
            return false;
        }

        return $this->hasAccess($user, $employee, 'coach');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_employee_employee');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Employee $employee): bool
    {
        if (! $user->can('restore_employee_employee')) {
            return false;
        }

        return $this->hasAccess($user, $employee, 'coach');
    }
}
