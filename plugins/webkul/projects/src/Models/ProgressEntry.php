<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Webkul\Project\Database\Factories\ProgressEntryFactory;

class ProgressEntry extends Model
{
    use HasFactory;

    protected $table = 'projects_progress_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['inputs' => 'array', 'result_numeric' => 'decimal:6'];
    }

    protected static function newFactory(): ProgressEntryFactory
    {
        return ProgressEntryFactory::new();
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ProgressReport::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProgressVersion::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded inputs and results are immutable.'));
        static::deleting(fn () => throw new LogicException('Recorded inputs and results are immutable.'));
    }
}
