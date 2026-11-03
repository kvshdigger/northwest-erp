<?php

namespace Webkul\Project\Policies;

use Illuminate\Database\Eloquent\Builder;
use Webkul\Project\Models\ProgressMembership;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\Scopes\OwnershipScope;
use Webkul\Security\Models\User;

class ProgressAccess
{
    public function projects(User $user): Builder
    {
        $query = Project::withoutGlobalScope(OwnershipScope::class);
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        $query->where(fn (Builder $company) => $company->whereNull('company_id')
            ->orWhereIn('company_id', $user->allowedCompanies()->select('companies.id')));
        if (! $user->can('view_all_project_progress')) {
            $query->where(fn (Builder $assigned) => $assigned->where('user_id', $user->id)
                ->orWhereIn('id', ProgressMembership::query()->where('user_id', $user->id)->select('project_id')));
        }

        return $query;
    }

    public function view(User $user, Project $project): bool
    {
        return $this->projects($user)->whereKey($project->id)->exists();
    }

    public function configure(User $user, Project $project): bool
    {
        return $this->view($user, $project) && ($user->can('manage_project_progress')
            || (int) $project->user_id === (int) $user->id
            || $this->membership($user, $project)?->can_configure);
    }

    public function submit(User $user, Project $project): bool
    {
        return $this->view($user, $project) && ($user->can('manage_project_progress')
            || (int) $project->user_id === (int) $user->id
            || $this->membership($user, $project)?->can_submit);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->view($user, $project) && ($user->can('manage_project_progress') || (int) $project->user_id === (int) $user->id);
    }

    private function membership(User $user, Project $project): ?ProgressMembership
    {
        return ProgressMembership::query()->where('project_id', $project->id)->where('user_id', $user->id)->first();
    }
}
