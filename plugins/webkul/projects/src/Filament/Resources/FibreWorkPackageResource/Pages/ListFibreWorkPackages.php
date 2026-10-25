<?php

namespace Webkul\Project\Filament\Resources\FibreWorkPackageResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource;

class ListFibreWorkPackages extends ListRecords
{
    protected static string $resource = FibreWorkPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
