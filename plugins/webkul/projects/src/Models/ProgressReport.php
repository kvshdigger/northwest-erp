<?php

namespace Webkul\Project\Models;

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
        return ['report_date' => 'date'];
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

    public function entries(): HasMany
    {
        return $this->hasMany(ProgressEntry::class, 'report_id');
    }
}
