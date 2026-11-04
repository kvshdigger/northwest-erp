<?php

namespace Webkul\Project\Models;

use App\Models\WorkOrderRoute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Project\Database\Factories\ProgressReportFactory;

class ProgressReport extends Model
{
    use HasFactory;

    protected $table = 'projects_progress_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['report_date' => 'date', 'chainage_from' => 'decimal:3', 'chainage_to' => 'decimal:3'];
    }

    protected static function newFactory(): ProgressReportFactory
    {
        return ProgressReportFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(ProgressRoute::class);
    }

    public function workOrderRoute(): BelongsTo
    {
        return $this->belongsTo(WorkOrderRoute::class, 'work_order_route_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ProgressEntry::class, 'report_id');
    }
}
