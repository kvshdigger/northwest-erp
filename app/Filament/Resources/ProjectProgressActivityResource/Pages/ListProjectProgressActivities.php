<?php

namespace App\Filament\Resources\ProjectProgressActivityResource\Pages;

use App\Filament\Resources\ProjectProgressActivityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectProgressActivities extends ListRecords
{
    protected static string $resource = ProjectProgressActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
