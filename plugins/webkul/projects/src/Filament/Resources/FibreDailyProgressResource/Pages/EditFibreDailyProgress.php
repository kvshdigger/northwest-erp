<?php

namespace Webkul\Project\Filament\Resources\FibreDailyProgressResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Webkul\Project\Filament\Resources\FibreDailyProgressResource;

class EditFibreDailyProgress extends EditRecord
{
    protected static string $resource = FibreDailyProgressResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? 'draft') === 'submitted' && ! $this->record->submitted_at) {
            $data['submitted_at'] = now();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (): bool => $this->record->status === 'draft'),
        ];
    }
}
