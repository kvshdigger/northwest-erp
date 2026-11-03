<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Project\Database\Factories\ProgressActivityFactory;

class ProgressActivity extends Model
{
    use HasFactory;

    protected $table = 'projects_progress_activities';

    protected $guarded = ['id'];

    protected $attributes = ['revision' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function newFactory(): ProgressActivityFactory
    {
        return ProgressActivityFactory::new();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProgressVersion::class, 'activity_id');
    }

    public function published(): HasOne
    {
        return $this->hasOne(ProgressVersion::class, 'activity_id')->ofMany('number', 'max');
    }
}
