<?php

namespace Webkul\Project\Filament\Resources\FibreProgressPurchaseOrderResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Webkul\Project\Filament\Resources\FibreProgressPurchaseOrderResource;
use Webkul\Project\Models\FibreProgressPurchaseOrder;
use Webkul\Project\Models\FibreWorkPackage;

class ListFibreProgressPurchaseOrders extends ListRecords
{
    protected static string $resource = FibreProgressPurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_from_approved_progress')
                ->label('Generate PO from approved progress')
                ->icon('heroicon-o-document-check')
                ->form([
                    Select::make('work_package_id')
                        ->label('Work package / subcontractor')
                        ->options(fn () => FibreWorkPackage::query()
                            ->with('subcontractor')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($package) => [
                                $package->id => $package->name.' — '.($package->subcontractor?->name ?? 'Subcontractor'),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    Textarea::make('notes')->label('Internal notes')->rows(3),
                ])
                ->visible(fn (): bool => Auth::user()?->can('create', FibreProgressPurchaseOrder::class) ?? false)
                ->requiresConfirmation()
                ->modalDescription('This creates a review-ready PO record from unallocated final-approved progress. It is not released until internal review and release are completed.')
                ->action(function (array $data): void {
                    abort_unless(Auth::user()?->can('create', FibreProgressPurchaseOrder::class), 403);
                    FibreProgressPurchaseOrder::generateFromApprovedProgress(
                        (int) $data['work_package_id'],
                        (int) Auth::id(),
                        $data['notes'] ?? null,
                    );
                }),
        ];
    }
}