<?php

namespace Webkul\Project\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Project\Models\ProgressEntry;

class ProgressReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id, 'project_id' => $this->project_id,
            'route'          => ['id' => $this->route->id, 'name' => $this->route->name],
            'report_date'    => $this->report_date->toDateString(), 'team' => $this->team, 'location' => $this->location,
            'submission_key' => $this->submission_key,
            'entries'        => $this->entries->map(fn (ProgressEntry $entry): array => [
                'id'      => $entry->id, 'activity_id' => $entry->version->activity_id,
                'version' => $entry->version->number, 'configuration' => $entry->version->definition,
                'inputs'  => $entry->inputs, 'result_type' => $entry->result_type,
                'result'  => $entry->result_numeric ?? $entry->result_text,
                'team'    => $entry->team, 'location' => $entry->location, 'status' => $entry->status,
            ])->all(),
        ];
    }
}
