<?php

namespace Webkul\Project\Filament\Resources\FibreWorkPackageResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Allocated BOQ Items';

    public function form(Schema $schema): Schema
    {
        $projectId = $this->getOwnerRecord()->project_id;

        return $schema->components([
            Select::make('boq_item_id')
                ->label('BOQ item')
                ->relationship(
                    name: 'boqItem',
                    titleAttribute: 'description',
                    modifyQueryUsing: fn (Builder $query) => $query->where('project_id', $projectId),
                )
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('allocated_quantity')->numeric()->minValue(0)->required(),
            TextInput::make('subcontractor_rate')->label('Subcontractor rate')->numeric()->minValue(0)->prefix('RM')->required(),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('boqItem.item_code')->label('Code'),
            TextColumn::make('boqItem.description')->label('BOQ item')->wrap(),
            TextColumn::make('boqItem.unit')->label('Unit'),
            TextColumn::make('allocated_quantity')->label('Allocated qty')->numeric(decimalPlaces: 3),
            TextColumn::make('subcontractor_rate')->label('Subcontractor rate')->money('MYR'),
        ])->headerActions([
            CreateAction::make(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
