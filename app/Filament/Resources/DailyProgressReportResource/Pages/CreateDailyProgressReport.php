<?php

namespace App\Filament\Resources\DailyProgressReportResource\Pages;

use App\Filament\Resources\DailyProgressReportResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDailyProgressReport extends CreateRecord
{
    protected static string $resource = DailyProgressReportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
