<?php

namespace Webkul\Project\Filament\Resources\FibreDailyProgressResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Webkul\Project\Filament\Resources\FibreDailyProgressResource;

class CreateFibreDailyProgress extends CreateRecord
{
    protected static string $resource = FibreDailyProgressResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['status'] ?? 'draft') === 'submitted') {
            $data['submitted_at'] = now();
        }

        return $data;
    }
}
