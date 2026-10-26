<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Webkul\Project\Filament\Resources\FibreProgressPurchaseOrderResource\Pages\ListFibreProgressPurchaseOrders;
use Webkul\Project\Models\FibreProgressPurchaseOrder;
use Webkul\Purchase\Filament\Admin\Clusters\Orders\Resources\PurchaseOrderResource;
use Webkul\Support\Enums\NavigationGroup;

class FibreProgressPurchaseOrderResource extends Resource
{
    protected static ?string $model = FibreProgressPurchaseOrder::class;
    protected static ?string $slug = 'project/fibre-progress-pos';
    protected static ?string $recordTitleAttribute = 'po_reference';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Progress POs';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('po_reference')->label('PO reference')->searchable()->sortable()->placeholder('Awaiting release'),
            TextColumn::make('project.name')->label('Project')->searchable(),
            TextColumn::make('workPackage.name')->label('Work package')->searchable(),
            TextColumn::make('workPackage.subcontractor.name')->label('Subcontractor')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('nativeOrder.name')->label('Native PO')->placeholder('Not created')->url(fn (FibreProgressPurchaseOrder $record): ?string => $record->native_order_id ? PurchaseOrderResource::getUrl('view', ['record' => $record->native_order_id], panel: 'admin') : null),
            TextColumn::make('lines_sum_approved_quantity')->label('Approved qty')->numeric(decimalPlaces: 3),
            TextColumn::make('lines_sum_line_amount')->label('PO amount (RM)')->numeric(decimalPlaces: 2),
            TextColumn::make('created_at')->label('Generated')->dateTime()->sortable(),
            TextColumn::make('reviewed_at')->label('Reviewed')->dateTime()->sortable(),
            TextColumn::make('released_at')->label('Released')->dateTime()->sortable(),
        ])
        ->modifyQueryUsing(fn ($query) => $query
            ->with(['project', 'workPackage.subcontractor'])
            ->withSum('lines', 'approved_quantity')
            ->withSum('lines', 'line_amount'))
        ->defaultSort('created_at', 'desc')
        ->recordActions([
            Action::make('view_po_breakdown')
                ->label('View PO breakdown')
                ->requiresConfirmation()
                ->modalHeading('Progress-based PO breakdown')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalDescription(fn (FibreProgressPurchaseOrder $record): string => $record->lines()
                    ->with('workPackageItem.boqItem')
                    ->get()
                    ->map(fn ($line) => ($line->workPackageItem?->boqItem?->item_code ?? 'BOQ')
                        .' — '.($line->workPackageItem?->boqItem?->description ?? 'Item')
                        .' | Qty: '.number_format((float) $line->approved_quantity, 3)
                        .' | Subcon rate: RM '.number_format((float) $line->subcontractor_rate_snapshot, 2)
                        .' | Amount: RM '.number_format((float) $line->line_amount, 2))
                    ->implode("\n"))
                ->action(fn () => null),
            Action::make('approve_for_release')
                ->label('Approve for release')
                ->color('success')
                ->form([Textarea::make('review_notes')->label('Review notes')->required()->rows(3)])
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::PENDING_REVIEW && (Auth::user()?->can('review_fibre_progress_purchase_order') ?? false))
                ->action(function (FibreProgressPurchaseOrder $record, array $data): void {
                    abort_unless(Auth::user()?->can('review_fibre_progress_purchase_order'), 403);
                    $record->approveForRelease((int) Auth::id(), $data['review_notes']);
                }),
            Action::make('return_for_correction')
                ->label('Return for correction')
                ->color('danger')
                ->form([Textarea::make('reason')->label('Reason')->required()->rows(3)])
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::PENDING_REVIEW && (Auth::user()?->can('review_fibre_progress_purchase_order') ?? false))
                ->action(function (FibreProgressPurchaseOrder $record, array $data): void {
                    abort_unless(Auth::user()?->can('review_fibre_progress_purchase_order'), 403);
                    $record->rejectForCorrection((int) Auth::id(), $data['reason']);
                }),
            Action::make('create_native_draft_order')
                ->label('Create native draft PO')
                ->icon('heroicon-o-document-plus')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Create native draft purchase order')
                ->modalDescription('Creates a linked draft in the native Purchases module using the approved subcontractor amount. It will not be sent, emailed, or confirmed automatically.')
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::APPROVED && ! $record->native_order_id && (Auth::user()?->can('create_native_draft_fibre_progress_purchase_order') ?? false))
                ->action(function (FibreProgressPurchaseOrder $record): void {
                    abort_unless(Auth::user()?->can('create_native_draft_fibre_progress_purchase_order'), 403);
                    $record->createNativeDraftOrder((int) Auth::id());
                }),
            Action::make('release_po')
                ->label('Release PO')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Release locks the internal fibre PO record and assigns its reference. If a native Purchases order is linked, it remains Draft until separately reviewed and sent/confirmed in the Purchases module. Confirm the quantities and subcontractor rates before proceeding.')
                ->visible(fn (FibreProgressPurchaseOrder $record): bool => $record->status === FibreProgressPurchaseOrder::APPROVED && (Auth::user()?->can('release_fibre_progress_purchase_order') ?? false))
                ->action(function (FibreProgressPurchaseOrder $record): void {
                    abort_unless(Auth::user()?->can('release_fibre_progress_purchase_order'), 403);
                    $record->release((int) Auth::id());
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreProgressPurchaseOrders::route('/'),
        ];
    }
}