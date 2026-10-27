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

    public function getHasChainageOverlapAttribute(): bool
    {
        $entries = $this->entries()->get(['id', 'activity', 'start_chainage_m', 'end_chainage_m']);

        foreach ($entries as $entry) {
            $overlapWithinReport = $entries->contains(fn (DailyProgressEntry $other) =>
                $other->id !== $entry->id
                && $other->activity === $entry->activity
                && (float) $other->start_chainage_m < (float) $entry->end_chainage_m
                && (float) $other->end_chainage_m > (float) $entry->start_chainage_m
            );

            if ($overlapWithinReport) {
                return true;
            }
        }

        if ($entries->isEmpty()) {
            return false;
        }

        $route = $this->project_route;
        $section = $this->section_name;

        return DailyProgressEntry::query()
            ->whereIn('activity', $entries->pluck('activity')->unique())
            ->where(function ($query) use ($entries): void {
                foreach ($entries as $entry) {
                    $query->orWhere(function ($range) use ($entry): void {
                        $range->where('start_chainage_m', '<', $entry->end_chainage_m)
                            ->where('end_chainage_m', '>', $entry->start_chainage_m)
                            ->where('activity', $entry->activity);
                    });
                }
            })
            ->where('daily_progress_report_id', '!=', $this->getKey())
            ->whereHas('report', fn ($query) => $query->where('project_route', $route)->where('section_name', $section))
            ->exists();
    }
}
