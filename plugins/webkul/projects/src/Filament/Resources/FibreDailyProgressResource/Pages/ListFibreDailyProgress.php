<?php

namespace Webkul\Project\Filament\Resources\FibreDailyProgressResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Project\Filament\Resources\FibreDailyProgressResource;

class ListFibreDailyProgress extends ListRecords
{
    protected static string $resource = FibreDailyProgressResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
