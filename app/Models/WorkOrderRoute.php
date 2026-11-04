<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Project\Models\ProgressRoute;

class WorkOrderRoute extends Model
{
    protected $table = 'v2_work_order_routes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['chainage_from' => 'decimal:3', 'chainage_to' => 'decimal:3'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkOrderVersion::class, 'version_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(ProgressRoute::class, 'route_id');
    }
}
