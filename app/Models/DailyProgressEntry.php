<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ProjectProgressActivity;
use InvalidArgumentException;

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
            if (! isset($entry->start_chainage, $entry->end_chainage)) {
                throw new InvalidArgumentException('Start and end chainage are required.');
            }

            $report = $entry->report;
            $configuredActivity = $report?->project_id
                ? ProjectProgressActivity::query()
                    ->where('project_id', $report->project_id)
                    ->where('name', $entry->activity)
                    ->where('is_active', true)
                    ->first()
                : null;

            // Keep legacy records and reports without a project compatible.
            if ($report?->project_id && ! $configuredActivity) {
                throw new InvalidArgumentException('Select an active activity configured for this project.');
            }

            if (! $report?->project_id && ! array_key_exists((string) $entry->activity, self::ACTIVITIES)) {
                throw new InvalidArgumentException('Select a valid progress activity.');
            }

            $start = self::parseChainage((string) $entry->start_chainage);
            $end = self::parseChainage((string) $entry->end_chainage);

            if ($end <= $start) {
                throw new InvalidArgumentException('End chainage must be greater than start chainage.');
            }

            if ($entry->daily_quantity === null || ! is_numeric($entry->daily_quantity) || (float) $entry->daily_quantity <= 0) {
                throw new InvalidArgumentException('Daily quantity must be greater than zero.');
            }

            if ($entry->planned_quantity !== null && (float) $entry->planned_quantity < 0) {
                throw new InvalidArgumentException('Planned quantity cannot be negative.');
            }

            $entry->start_chainage_m = $start;
            $entry->end_chainage_m = $end;
            $entry->chainage_length_m = $end - $start;
            $entry->unit = $configuredActivity?->unit ?? self::ACTIVITIES[$entry->activity];
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyProgressReport::class, 'daily_progress_report_id');
    }

    /**
     * Returns chainage coverage without double-counting overlapping intervals.
     * This is meaningful for metre-based activities only.
     */
    public function getCumulativeQuantityAttribute(): float
    {
        if (($this->unit ?? self::ACTIVITIES[$this->activity] ?? null) !== 'm' || ! $this->report) {
            return (float) self::query()
                ->where('activity', $this->activity)
                ->whereHas('report', fn ($query) => $query
                    ->where('project_route', $this->report?->project_route)
                    ->where('section_name', $this->report?->section_name))
                ->sum('daily_quantity');
        }

        $intervals = self::query()
            ->where('activity', $this->activity)
            ->whereHas('report', fn ($query) => $query
                ->where('project_route', $this->report->project_route)
                ->where('section_name', $this->report->section_name))
            ->orderBy('start_chainage_m')
            ->get(['start_chainage_m', 'end_chainage_m']);

        $ranges = $intervals
            ->map(fn (self $entry) => [(float) $entry->start_chainage_m, (float) $entry->end_chainage_m])
            ->sortBy(fn (array $range) => $range[0])
            ->values();

        $total = 0.0;
        $start = null;
        $end = null;

        foreach ($ranges as [$nextStart, $nextEnd]) {
            if ($start === null) {
                $start = $nextStart;
                $end = $nextEnd;
                continue;
            }

            if ($nextStart <= $end) {
                $end = max($end, $nextEnd);
                continue;
            }

            $total += $end - $start;
            $start = $nextStart;
            $end = $nextEnd;
        }

        return $start === null ? 0.0 : $total + $end - $start;
    }

    public function getHasOverlappingChainageAttribute(): bool
    {
        if (! $this->exists || ! $this->report) {
            return false;
        }

        return self::query()
            ->where($this->getQualifiedKeyName(), '!=', $this->getKey())
            ->where('activity', $this->activity)
            ->where('start_chainage_m', '<', $this->end_chainage_m)
            ->where('end_chainage_m', '>', $this->start_chainage_m)
            ->whereHas('report', fn ($query) => $query
                ->where('project_route', $this->report->project_route)
                ->where('section_name', $this->report->section_name))
            ->exists();
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
            $offset = (float) $matches[2];

            if ($offset >= 1000) {
                throw new InvalidArgumentException('Chainage offset must be less than 1000 metres, e.g. 1+250.');
            }

            return ((float) $matches[1] * 1000) + $offset;
        }

        if (is_numeric($value) && (float) $value >= 0) {
            return (float) $value;
        }

        throw new InvalidArgumentException('Chainage must be formatted like 0+100 or entered as a non-negative metre value.');
    }
}
