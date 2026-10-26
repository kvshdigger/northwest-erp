<?php

namespace Webkul\Project\Filament\Resources\FibreBoqItemResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Project\Filament\Resources\FibreBoqItemResource;

class EditFibreBoqItem extends EditRecord
{
    protected static string $resource = FibreBoqItemResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
