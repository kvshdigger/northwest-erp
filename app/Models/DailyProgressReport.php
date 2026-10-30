<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Project\Models\Project;
use Webkul\Security\Models\User;
use InvalidArgumentException;

class DailyProgressReport extends Model
{
    protected $fillable = [
        'project_id', 'report_date', 'project_route', 'section_name', 'section_start_chainage',
        'section_end_chainage', 'section_start_chainage_m', 'section_end_chainage_m',
        'team_or_subcontractor', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'section_start_chainage_m' => 'decimal:3',
            'section_end_chainage_m' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $report): void {
            if (blank($report->section_start_chainage) && blank($report->section_end_chainage)) {
                $report->section_start_chainage_m = null;
                $report->section_end_chainage_m = null;

                return;
            }

            if (blank($report->section_start_chainage) || blank($report->section_end_chainage)) {
                throw new InvalidArgumentException('Enter both section start and end chainage to calculate milestone completion.');
            }

            $start = DailyProgressEntry::parseChainage((string) $report->section_start_chainage);
            $end = DailyProgressEntry::parseChainage((string) $report->section_end_chainage);

            if ($end <= $start) {
                throw new InvalidArgumentException('Section end chainage must be greater than section start chainage.');
            }

            $report->section_start_chainage_m = $start;
            $report->section_end_chainage_m = $end;
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DailyProgressEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getOpenCutCompletedAttribute(): ?bool
    {
        return $this->milestoneCoverageComplete(['1.2m Open Cut', '0.6m Open Cut']);
    }

    public function getFibreInstallationCompletedAttribute(): ?bool
    {
        return $this->milestoneCoverageComplete(['Fibre Installation']);
    }

    public function getFibreSplicingCompletedAttribute(): ?bool
    {
        return $this->milestoneCoverageComplete(['Fibre Splicing']);
    }

    private function milestoneCoverageComplete(array $activities): ?bool
    {
        if ($this->section_start_chainage_m === null || $this->section_end_chainage_m === null || blank($this->project_route) || blank($this->section_name)) {
            return null;
        }

        $start = (float) $this->section_start_chainage_m;
        $end = (float) $this->section_end_chainage_m;

        $entries = DailyProgressEntry::query()
            ->whereIn('activity', $activities)
            ->whereHas('report', fn ($query) => $query
                ->where('project_route', $this->project_route)
                ->where('section_name', $this->section_name)
                ->when($this->project_id, fn ($query) => $query->where('project_id', $this->project_id), fn ($query) => $query->whereNull('project_id')))
            ->where('start_chainage_m', '<', $end)
            ->where('end_chainage_m', '>', $start)
            ->get(['start_chainage_m', 'end_chainage_m']);

        $ranges = $entries
            ->map(fn (DailyProgressEntry $entry) => [
                max($start, (float) $entry->start_chainage_m),
                min($end, (float) $entry->end_chainage_m),
            ])
            ->sortBy(fn (array $range) => $range[0])
            ->values();

        $coveredUntil = $start;

        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($rangeStart > $coveredUntil) {
                return false;
            }

            $coveredUntil = max($coveredUntil, $rangeEnd);

            if ($coveredUntil >= $end) {
                return true;
            }
        }

        return false;
    }

    public function getHasChainageOverlapAttribute(): bool
    {
        $entries = $this->entries()->get(['id', 'activity', 'start_chainage_m', 'end_chainage_m']);

        foreach ($entries as $entry) {
            if ($entries->contains(fn (DailyProgressEntry $other) =>
                $other->id !== $entry->id
                && $other->activity === $entry->activity
                && (float) $other->start_chainage_m < (float) $entry->end_chainage_m
                && (float) $other->end_chainage_m > (float) $entry->start_chainage_m
            )) {
                return true;
            }
        }

        if ($entries->isEmpty()) {
            return false;
        }

        $route = $this->project_route;
        $section = $this->section_name;

        return DailyProgressEntry::query()
            ->where('daily_progress_report_id', '!=', $this->getKey())
            ->where(function ($query) use ($entries): void {
                foreach ($entries as $entry) {
                    $query->orWhere(function ($range) use ($entry): void {
                        $range->where('activity', $entry->activity)
                            ->where('start_chainage_m', '<', $entry->end_chainage_m)
                            ->where('end_chainage_m', '>', $entry->start_chainage_m);
                    });
                }
            })
            ->whereHas('report', fn ($query) => $query->where('project_route', $route)->where('section_name', $section)
                ->when($this->project_id, fn ($reportQuery) => $reportQuery->where('project_id', $this->project_id), fn ($reportQuery) => $reportQuery->whereNull('project_id')))
            ->exists();
    }
}
