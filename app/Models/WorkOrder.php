<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Project\Models\Project;

class WorkOrder extends Model
{
    use HasFactory;

    protected $table = 'v2_work_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['current_version_id' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkOrderVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkOrderVersion::class, 'current_version_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(WorkOrderAllocation::class);
    }
}
