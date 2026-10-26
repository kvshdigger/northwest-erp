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
                    Textarea::make('notes')
                        ->label('Internal notes')
                        ->rows(3),
                ])
                ->visible(fn (): bool => Auth::user()?->can('create', FibreProgressPurchaseOrder::class) ?? false)
                ->requiresConfirmation()
                ->modalDescription('This creates a PO draft from unallocated final-approved progress. The PO is not released to the subcontractor until internal review and release are completed.')
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

    protected function getTableActions(): array
    {
        return [
            Action::make('approve_for_release')
                ->label('Approve for release')
                ->color('success')
                ->form([Textarea::make('review_notes')->label('Review notes')->required()->rows(3)])
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::PENDING_REVIEW)
                ->action(function (FibreProgressPurchaseOrder $record, array $data): void {
                    abort_unless(Auth::user()?->can('update', $record), 403);
                    $record->approveForRelease((int) Auth::id(), $data['review_notes']);
                }),
            Action::make('reject_for_correction')
                ->label('Return for correction')
                ->color('danger')
                ->form([Textarea::make('reason')->label('Reason')->required()->rows(3)])
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::PENDING_REVIEW)
                ->action(function (FibreProgressPurchaseOrder $record, array $data): void {
                    abort_unless(Auth::user()?->can('update', $record), 403);
                    $record->rejectForCorrection((int) Auth::id(), $data['reason']);
                }),
            Action::make('release_po')
                ->label('Release PO')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Release locks the PO record and assigns its reference. Confirm the quantities and subcontractor rates before proceeding.')
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::APPROVED)
                ->action(function (FibreProgressPurchaseOrder $record): void {
                    abort_unless(Auth::user()?->can('update', $record), 403);
                    $record->release((int) Auth::id());
                }),
        ];
    }
}