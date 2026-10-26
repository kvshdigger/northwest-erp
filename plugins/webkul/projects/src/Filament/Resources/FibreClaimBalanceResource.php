<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Project\Filament\Resources\FibreClaimBalanceResource\Pages\ListFibreClaimBalances;
use Webkul\Project\Models\FibreWorkPackageItem;
use Webkul\Project\Support\FibreClaimBalance;
use Webkul\Support\Enums\NavigationGroup;

class FibreClaimBalanceResource extends Resource
{
    protected static ?string $model = FibreWorkPackageItem::class;
    protected static ?string $slug = 'project/fibre-claim-balances';
    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Claim Balances';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('workPackage.project.name')->label('Project')->searchable(),
            TextColumn::make('workPackage.name')->label('Work package')->searchable(),
            TextColumn::make('boqItem.item_code')->label('BOQ code')->searchable(),
            TextColumn::make('boqItem.description')->label('BOQ item')->wrap()->searchable(),
            TextColumn::make('boqItem.unit')->label('Unit'),
            TextColumn::make('allocated_quantity')->label('Allocated qty')->numeric(decimalPlaces: 3),
            TextColumn::make('approved_quantity')->label('Final approved qty')->state(fn (FibreWorkPackageItem $record): float => FibreClaimBalance::approvedQuantity($record))->numeric(decimalPlaces: 3),
            TextColumn::make('reserved_quantity')->label('Claim reserved qty')->state(fn (FibreWorkPackageItem $record): float => FibreClaimBalance::reservedQuantity($record))->numeric(decimalPlaces: 3),
            TextColumn::make('paid_quantity')->label('Paid qty')->state(fn (FibreWorkPackageItem $record): float => FibreClaimBalance::paidQuantity($record))->numeric(decimalPlaces: 3),
            TextColumn::make('claimable_quantity')->label('Remaining claimable qty')->state(fn (FibreWorkPackageItem $record): float => max(0, FibreClaimBalance::approvedQuantity($record) - FibreClaimBalance::reservedQuantity($record)))->numeric(decimalPlaces: 3),
            TextColumn::make('subcontractor_rate')->label('Subcontractor rate (RM)')->numeric(decimalPlaces: 2),
            TextColumn::make('claimable_cost')->label('Remaining subcontractor value (RM)')->state(fn (FibreWorkPackageItem $record): float => max(0, FibreClaimBalance::approvedQuantity($record) - FibreClaimBalance::reservedQuantity($record)) * (float) $record->subcontractor_rate)->numeric(decimalPlaces: 2),
            TextColumn::make('boqItem.client_rate')->label('Client rate (RM)')->numeric(decimalPlaces: 2),
            TextColumn::make('approved_client_value')->label('Indicative client value (RM)')->state(fn (FibreWorkPackageItem $record): float => FibreClaimBalance::approvedQuantity($record) * (float) $record->boqItem?->client_rate)->numeric(decimalPlaces: 2),
        ])->defaultSort('id', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreClaimBalances::route('/'),
        ];
    }
}