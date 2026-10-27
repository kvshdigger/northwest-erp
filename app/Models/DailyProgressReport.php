<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyProgressReport extends Model
{
    protected $fillable = [
        'report_date', 'project_route', 'section_name', 'team_or_subcontractor', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return ['report_date' => 'date'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DailyProgressEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
