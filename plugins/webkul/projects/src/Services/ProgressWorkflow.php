<?php

namespace Webkul\Project\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Webkul\Project\Models\ProgressActivity;
use Webkul\Project\Models\ProgressEntry;
use Webkul\Project\Models\ProgressReport;
use Webkul\Project\Models\ProgressRoute;
use Webkul\Project\Models\Project;
use Webkul\Project\Policies\ProgressAccess;
use Webkul\Security\Models\Scopes\OwnershipScope;
use Webkul\Security\Models\User;

class ProgressWorkflow
{
    public function __construct(private ProgressConfiguration $configuration, private ProgressAccess $access) {}

    public function saveDraft(Project $project, User $actor, array $definition, ?int $id, int $revision): ProgressActivity
    {
        return $project->getConnection()->transaction(function () use ($project, $actor, $definition, $id, $revision): ProgressActivity {
            $this->lock($project);
            abort_unless($this->access->configure($actor, $project), 403);
            $activity = $id ? $this->activity($project, $id) : new ProgressActivity(['project_id' => $project->id]);
            if ($id) {
                abort_if($activity->revision !== $revision, 409, 'The activity changed. Reload before saving.');
            }
            $before = $activity->draft;
            $activity->draft = $definition;
            $activity->revision = $id ? $activity->revision + 1 : 1;
            $activity->save();
            $this->audit($project, $actor, 'draft_saved', 'activity', $activity->id, $before, $definition);

            return $activity->load('published');
        });
    }

    public function publish(Project $project, User $actor, int $id, int $revision): ProgressActivity
    {
        return $project->getConnection()->transaction(function () use ($project, $actor, $id, $revision): ProgressActivity {
            $this->lock($project);
            abort_unless($this->access->configure($actor, $project), 403);
            $activity = $this->activity($project, $id);
            abort_if($activity->revision !== $revision, 409, 'The activity changed. Reload before publishing.');
            if (! $activity->draft) {
                throw ValidationException::withMessages(['configuration' => 'Save a draft before publishing.']);
            }
            $definition = $this->configuration->validate($activity->draft);
            $previous = $activity->published;
            if ($previous && (($previous->definition['unit'] ?? '') !== $definition['unit']
                || $this->configuration->numeric($this->configuration->outputType($previous->definition)) !== $this->configuration->numeric($this->configuration->outputType($definition)))) {
                throw ValidationException::withMessages(['configuration' => 'Use a new activity when changing the unit or numeric/text output category.']);
            }
            $version = $activity->versions()->create([
                'number'       => ($previous?->number ?? 0) + 1,
                'definition'   => $definition,
                'published_by' => $actor->id,
            ]);
            $activity->forceFill(['draft' => null, 'revision' => $activity->revision + 1])->save();
            $this->audit($project, $actor, 'published', 'activity', $activity->id, $previous?->definition, ['version' => $version->number, 'definition' => $definition]);

            return $activity->refresh()->load('published');
        });
    }

    public function record(Project $project, User $actor, array $data): ProgressReport
    {
        return $project->getConnection()->transaction(function () use ($project, $actor, $data): ProgressReport {
            $this->lock($project);
            abort_unless($this->access->submit($actor, $project), 403);
            $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));
            $existing = ProgressReport::query()->where('project_id', $project->id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_if((int) $existing->created_by !== (int) $actor->id || ! hash_equals($existing->payload_hash, $hash), 409, 'Submission key was already used for a different report.');

                return $existing->load(['route', 'entries.version']);
            }
            $route = ProgressRoute::query()->where('project_id', $project->id)->where('is_active', true)->find($data['route_id']);
            if (! $route) {
                throw ValidationException::withMessages(['route_id' => 'Select an active route in this project.']);
            }
            $activities = ProgressActivity::query()->where('project_id', $project->id)->where('is_active', true)
                ->whereIn('id', array_column($data['entries'], 'activity_id'))->with('published')->get()->keyBy('id');
            $prepared = [];
            foreach ($data['entries'] as $index => $entry) {
                $version = $activities->get($entry['activity_id'])?->published;
                if (! $version) {
                    throw ValidationException::withMessages(["entries.{$index}.activity_id" => 'Select a published activity in this project.']);
                }
                abort_if($version->number !== (int) $entry['version'], 409, 'Activity configuration changed. Reload the progress form.');
                $prepared[] = [
                    ...$this->configuration->calculate($version->definition, $entry['inputs']),
                    'version_id' => $version->id,
                    'team'       => $entry['team'] ?? $data['team'],
                    'location'   => $entry['location'] ?? $data['location'] ?? null,
                    'status'     => 'pending',
                ];
            }
            $report = ProgressReport::query()->create([
                'project_id'     => $project->id, 'route_id' => $route->id, 'report_date' => $data['report_date'],
                'team'           => $data['team'], 'location' => $data['location'] ?? null,
                'submission_key' => $data['submission_key'], 'payload_hash' => $hash, 'created_by' => $actor->id,
            ]);
            $report->entries()->createMany($prepared);
            $this->audit($project, $actor, 'report_created', 'report', $report->id, null, ['entries' => count($prepared), 'submission_key' => $report->submission_key]);

            return $report->load(['route', 'entries.version']);
        });
    }

    public function activity(Project $project, int $id): ProgressActivity
    {
        return ProgressActivity::query()->where('project_id', $project->id)->with('published')->findOrFail($id);
    }

    public function tracker(Project $project, array $filters): array
    {
        $query = ProgressReport::query()->where('project_id', $project->id)
            ->when($filters['route_id'] ?? null, fn (Builder $query, $route) => $query->where('route_id', $route))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->where('report_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->where('report_date', '<=', $date));
        $page = (clone $query)->select(['route_id', 'report_date'])->groupBy('route_id', 'report_date')
            ->orderByDesc('report_date')->orderBy('route_id')->paginate(30);
        if ($page->isEmpty()) {
            return ['data' => [], 'meta' => ['page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => 30]];
        }
        $dailyFilter = function (Builder $query, string $prefix = '') use ($page): void {
            foreach ($page as $day) {
                $query->orWhere(fn (Builder $query) => $query->where($prefix.'route_id', $day->route_id)->where($prefix.'report_date', $day->report_date));
            }
        };
        $reports = $query->where(fn (Builder $query) => $dailyFilter($query))->with('route')->get(['id', 'route_id', 'report_date']);
        $entryQuery = ProgressEntry::query()
            ->join('projects_progress_reports as reports', 'reports.id', '=', 'projects_progress_entries.report_id')
            ->join('projects_progress_versions as versions', 'versions.id', '=', 'projects_progress_entries.version_id')
            ->where('reports.project_id', $project->id)
            ->where(fn (Builder $query) => $dailyFilter($query, 'reports.'));
        $totals = (clone $entryQuery)->select(['reports.route_id', 'reports.report_date', 'versions.activity_id'])
            ->selectRaw('SUM(result_numeric) AS recorded, SUM(CASE WHEN status = ? THEN result_numeric ELSE 0 END) AS approved', ['approved'])
            ->selectRaw('COUNT(*) AS entry_count, MIN(status) AS first_status, MAX(status) AS last_status')
            ->groupBy('reports.route_id', 'reports.report_date', 'versions.activity_id')->get();
        $text = (clone $entryQuery)->whereNull('result_numeric')
            ->get(['reports.route_id', 'reports.report_date', 'versions.activity_id', 'result_text', 'status']);
        $current = ProgressActivity::query()->where('project_id', $project->id)->with('published')->get()->keyBy('id');
        $data = [];
        foreach ($page as $day) {
            $date = $day->report_date->toDateString();
            $daily = $reports->filter(fn (ProgressReport $report): bool => (int) $report->route_id === (int) $day->route_id && $report->report_date->equalTo($day->report_date));
            $dailyTotals = $totals->filter(fn ($row): bool => (int) $row->route_id === (int) $day->route_id && $row->report_date === $date);
            $statuses = $dailyTotals->flatMap(fn ($row): array => [$row->first_status, $row->last_status])->unique()->values();
            $activities = [];
            foreach ($dailyTotals as $row) {
                $definition = $current->get($row->activity_id)->published->definition;
                $numeric = $this->configuration->numeric($this->configuration->outputType($definition));
                $values = $text->filter(fn ($item): bool => (int) $item->route_id === (int) $day->route_id && $item->report_date === $date && (int) $item->activity_id === (int) $row->activity_id);
                $activities[] = [
                    'activity_id' => (int) $row->activity_id, 'name' => $definition['name'], 'unit' => $definition['unit'],
                    'recorded'    => $numeric ? (string) BigDecimal::of($row->recorded ?? '0')->toScale(6) : $values->pluck('result_text')->unique()->values()->all(),
                    'approved'    => $numeric ? (string) BigDecimal::of($row->approved ?? '0')->toScale(6) : $values->where('status', 'approved')->pluck('result_text')->unique()->values()->all(),
                    'claimable'   => null, 'entry_count' => (int) $row->entry_count,
                ];
            }
            $data[] = [
                'project_id'  => $project->id,
                'route'       => ['id' => $day->route_id, 'name' => $daily->first()->route->name],
                'report_date' => $date,
                'status'      => $statuses->count() === 1 ? $statuses->first() : 'mixed',
                'report_ids'  => $daily->pluck('id')->all(), 'entry_count' => (int) $dailyTotals->sum('entry_count'), 'activities' => $activities,
            ];
        }

        return ['data' => $data, 'meta' => ['page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => 30]];
    }

    public function lock(Project $project): void
    {
        $locked = Project::withoutGlobalScope(OwnershipScope::class)->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $project->setRawAttributes($locked->getAttributes(), true);
    }

    public function audit(Project $project, User $actor, string $event, string $type, int $id, ?array $before, array $after): void
    {
        $project->getConnection()->table('projects_progress_events')->insert([
            'project_id'   => $project->id, 'actor_id' => $actor->id, 'event' => $event,
            'subject_type' => $type, 'subject_id' => $id,
            'before'       => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after'        => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            } elseif (is_float($item) && ! is_finite($item)) {
                throw ValidationException::withMessages(['entries' => 'Numeric inputs must be finite.']);
            }
        }

        return $value;
    }
}
