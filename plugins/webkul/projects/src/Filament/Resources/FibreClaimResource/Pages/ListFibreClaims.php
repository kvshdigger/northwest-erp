<?php

namespace Webkul\Project\Filament\Resources\FibreClaimResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Project\Filament\Resources\FibreClaimResource;

class ListFibreClaims extends ListRecords
{
    protected static string $resource = FibreClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}