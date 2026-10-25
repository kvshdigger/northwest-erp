<?php

namespace Webkul\Project\Filament\Resources\FibreWorkPackageResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource;

class EditFibreWorkPackage extends EditRecord
{
    protected static string $resource = FibreWorkPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
