<?php

namespace Webkul\Project\Filament\Resources\FibreClaimResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Webkul\Project\Filament\Resources\FibreClaimResource;
use Webkul\Project\Models\FibreClaim;

class EditFibreClaim extends EditRecord
{
    protected static string $resource = FibreClaimResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->status !== FibreClaim::DRAFT) {
            abort(403, 'Only draft claims can be edited.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}