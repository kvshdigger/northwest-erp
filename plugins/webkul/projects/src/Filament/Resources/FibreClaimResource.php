<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Project\Filament\Resources\FibreClaimResource\Pages\ListFibreClaims;
use Webkul\Project\Models\FibreClaim;
use Webkul\Support\Enums\NavigationGroup;

class FibreClaimResource extends Resource
{
    protected static ?string $model = FibreClaim::class;
    protected static ?string $slug = 'project/fibre-claims';
    protected static ?string $recordTitleAttribute = 'claim_reference';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Subcontractor Claims';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('claim_reference')->label('Claim reference')->searchable()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable(),
            TextColumn::make('workPackage.name')->label('Work package')->searchable(),
            TextColumn::make('workPackage.subcontractor.name')->label('Subcontractor')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('items_sum_quantity_claimed')->label('Claim qty')->numeric(decimalPlaces: 3),
            TextColumn::make('items_sum_amount')->label('Claim amount (RM)')->numeric(decimalPlaces: 2),
            TextColumn::make('submitted_at')->dateTime()->sortable(),
            TextColumn::make('approved_at')->dateTime()->sortable(),
        ])->modifyQueryUsing(fn ($query) => $query->withSum('items', 'quantity_claimed')->withSum('items', 'amount'))
          ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreClaims::route('/'),
        ];
    }
}