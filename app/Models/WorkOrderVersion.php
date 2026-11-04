<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderVersion extends Model
{
    use HasFactory;

    protected $table = 'v2_work_order_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'revision' => 'integer'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(WorkOrderRoute::class, 'version_id');
    }

    protected static function booted(): void
    {
        static::updating(function ($row): void {
            if ($row->getRawOriginal('state') === 'approved') {
                throw new \LogicException('Approved Work Order versions are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Work Order versions cannot be deleted.'));
    }
}
