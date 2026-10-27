<?php

namespace App\Filament\Resources\DailyProgressReportResource\Pages;

use App\Filament\Resources\DailyProgressReportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDailyProgressReport extends EditRecord
{
    protected static string $resource = DailyProgressReportResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
