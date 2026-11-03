<?php

namespace Webkul\Project\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgressActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id, 'project_id' => $this->project_id, 'revision' => $this->revision,
            'is_active' => $this->is_active,
            'published' => $this->published ? ['version' => $this->published->number, 'configuration' => $this->published->definition] : null,
            'draft'     => $this->when($request->attributes->get('can_configure_progress', false), $this->draft),
        ];
    }
}
