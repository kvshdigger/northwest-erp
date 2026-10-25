<?php

namespace Webkul\Project\Filament\Resources\FibreRouteSectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Project\Filament\Resources\FibreRouteSectionResource;

class ListFibreRouteSections extends ListRecords
{
    protected static string $resource = FibreRouteSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
