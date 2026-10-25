<?php

namespace Webkul\Project\Filament\Resources\FibreBoqItemResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Project\Filament\Resources\FibreBoqItemResource;

class ListFibreBoqItems extends ListRecords
{
    protected static string $resource = FibreBoqItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
