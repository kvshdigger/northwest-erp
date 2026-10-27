<?php

namespace App\Filament\Resources\DailyProgressReportResource\Pages;

use App\Filament\Resources\DailyProgressReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyProgressReports extends ListRecords
{
    protected static string $resource = DailyProgressReportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
