<?php

namespace Webkul\Project\Filament\Resources\FibreRouteSectionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Project\Filament\Resources\FibreRouteSectionResource;

class EditFibreRouteSection extends EditRecord
{
    protected static string $resource = FibreRouteSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
