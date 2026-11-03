<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Project\Database\Factories\ProgressMembershipFactory;

class ProgressMembership extends Model
{
    use HasFactory;

    protected $table = 'projects_progress_memberships';

    protected $guarded = ['id'];

    protected $attributes = ['can_submit' => true, 'can_configure' => false];

    protected function casts(): array
    {
        return ['can_submit' => 'boolean', 'can_configure' => 'boolean'];
    }

    protected static function newFactory(): ProgressMembershipFactory
    {
        return ProgressMembershipFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
