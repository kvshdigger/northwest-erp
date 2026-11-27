<?php

namespace App\Services;

use Illuminate\Support\Facades\Gate;
use Webkul\Purchase\Models\Partner;
use Webkul\Security\Models\User;

class VendorRegistrationAccess
{
    public function administrator(User $user): bool
    {
        $names = array_filter(['admin', 'super_admin', config('filament-shield.super_admin.name')]);

        return $user->is_active && $user->roles->contains(fn ($role) => in_array(strtolower(trim($role->getRawOriginal('name'))), array_map('strtolower', $names), true));
    }

    public function review(User $user): bool
    {
        return $user->is_active && ($this->administrator($user) || Gate::forUser($user)->allows('create', Partner::class));
    }

    public function view(User $user): bool
    {
        return $this->review($user) || ($user->is_active && Gate::forUser($user)->allows('viewAny', Partner::class));
    }
}
