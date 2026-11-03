<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Project\Database\Factories\ProgressRouteFactory;

class ProgressRoute extends Model
{
    use HasFactory;

    protected $table = 'projects_progress_routes';

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function newFactory(): ProgressRouteFactory
    {
        return ProgressRouteFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
