<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Webkul\Project\Database\Factories\ProgressVersionFactory;

class ProgressVersion extends Model
{
    use HasFactory;

    protected $table = 'projects_progress_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'number' => 'integer'];
    }

    protected static function newFactory(): ProgressVersionFactory
    {
        return ProgressVersionFactory::new();
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(ProgressActivity::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published configurations are immutable.'));
        static::deleting(fn () => throw new LogicException('Published configurations are immutable.'));
    }
}
