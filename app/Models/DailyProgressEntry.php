<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyProgressEntry extends Model
{
    public const ACTIVITIES = [
        '1.2m Open Cut' => 'm',
        '0.6m Open Cut' => 'm',
        'Site Clearance' => 'm',
        'Manhole Installation' => 'pcs',
        'Backfill Done' => 'm',
        'Fibre Installation' => 'm',
        'Fibre Splicing' => 'cores',
    ];

    protected $fillable = [
        'daily_progress_report_id', 'activity', 'unit', 'start_chainage', 'end_chainage',
        'start_chainage_m', 'end_chainage_m', 'chainage_length_m', 'planned_quantity',
        'daily_quantity', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'start_chainage_m' => 'decimal:3',
            'end_chainage_m' => 'decimal:3',
            'chainage_length_m' => 'decimal:3',
            'planned_quantity' => 'decimal:3',
            'daily_quantity' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry): void {
            $entry->chainage_length_m = max(0, (float) $entry->end_chainage_m - (float) $entry->start_chainage_m);
            $entry->unit = self::ACTIVITIES[$entry->activity] ?? $entry->unit;
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyProgressReport::class, 'daily_progress_report_id');
    }

    public function getCumulativeQuantityAttribute(): float
    {
        return (float) self::query()
            ->where('activity', $this->activity)
            ->whereHas('report', fn ($query) => $query->where('project_route', $this->report?->project_route))
            ->where('start_chainage_m', '<', $this->end_chainage_m)
            ->where('end_chainage_m', '>', $this->start_chainage_m)
            ->sum('daily_quantity');
    }

    public function getRemainingQuantityAttribute(): ?float
    {
        if ($this->planned_quantity === null) {
            return null;
        }

        return max(0, (float) $this->planned_quantity - $this->cumulative_quantity);
    }

    public static function parseChainage(string $value): float
    {
        $value = trim(strtoupper($value));
        if (preg_match('/^(\d+)\s*\+\s*(\d+(?:\.\d+)?)$/', $value, $matches)) {
            return ((float) $matches[1] * 1000) + (float) $matches[2];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        throw new \InvalidArgumentException('Chainage must be formatted like 0+100 or entered as metres.');
    }
}
