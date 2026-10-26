<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Webkul\Project\Filament\Resources\FibreClaimResource\Pages\CreateFibreClaim;
use Webkul\Project\Filament\Resources\FibreClaimResource\Pages\ListFibreClaims;
use Webkul\Project\Filament\Resources\FibreClaimResource\RelationManagers\ItemsRelationManager;
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

    public static function getNavigationGroup(): string\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->relationship('project', 'name')
                ->searchable()->preload()->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('work_package_id', null)),
            Select::make('work_package_id')
                ->label('Work package / subcontractor')
                ->relationship(
                    'workPackage',
                    'name',
                    modifyQueryUsing: fn (Builder $query, Get $get) => $query->when(
                        $get('project_id'),
                        fn (Builder $query, $projectId) => $query->where('project_id', $projectId),
                    ),
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name.' — '.($record->subcontractor?->name ?? 'Subcontractor'))
                ->searchable()->preload()->required(),
            TextInput::make('claim_reference')->required()->maxLength(100)->unique(ignoreRecord: true),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
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
          ->defaultSort('created_at', 'desc')
          ->recordActions([
              Action::make('submit')
                  ->label('Submit claim')
                  ->requiresConfirmation()
                  ->visible(fn (FibreClaim $record): bool => $record->status === FibreClaim::DRAFT && $record->items()->exists())
                  ->action(fn (FibreClaim $record) => $record->submit((int) Auth::id())),
              Action::make('review')
                  ->form([Textarea::make('notes')->required()])
                  ->visible(fn (FibreClaim $record): bool => $record->status === FibreClaim::SUBMITTED)
                  ->action(fn (FibreClaim $record, array $data) => $record->markReviewed((int) Auth::id(), $data['notes'])),
              Action::make('approve')
                  ->form([Textarea::make('notes')->required()])
                  ->color('success')
                  ->visible(fn (FibreClaim $record): bool => in_array($record->status, [FibreClaim::SUBMITTED, FibreClaim::REVIEWED], true))
                  ->action(fn (FibreClaim $record, array $data) => $record->approve((int) Auth::id(), $data['notes'])),
              Action::make('reject')
                  ->form([Textarea::make('reason')->required()])
                  ->color('danger')
                  ->visible(fn (FibreClaim $record): bool => in_array($record->status, [FibreClaim::SUBMITTED, FibreClaim::REVIEWED], true))
                  ->action(fn (FibreClaim $record, array $data) => $record->reject((int) Auth::id(), $data['reason'])),
          ]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreClaims::route('/'),
            'create' => CreateFibreClaim::route('/create'),
        ];
    }
}