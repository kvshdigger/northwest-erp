<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Project\Filament\Resources\FibreBoqItemResource\Pages\CreateFibreBoqItem;
use Webkul\Project\Filament\Resources\FibreBoqItemResource\Pages\EditFibreBoqItem;
use Webkul\Project\Filament\Resources\FibreBoqItemResource\Pages\ListFibreBoqItems;
use Webkul\Project\Models\FibreBoqItem;
use Webkul\Support\Enums\NavigationGroup;

class FibreBoqItemResource extends Resource
{
    protected static ?string $model = FibreBoqItem::class;
    protected static ?string $slug = 'project/fibre-boq';
    protected static ?string $recordTitleAttribute = 'description';

    public static function getNavigationLabel(): string
    {
        return 'Fibre BOQ';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')->relationship('project', 'name')->searchable()->preload()->required(),
            TextInput::make('item_code')->maxLength(100),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('unit')->required()->maxLength(24)->placeholder('m, nos, set'),
            TextInput::make('contract_quantity')->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('client_rate')->numeric()->minValue(0)->prefix('RM')->default(0)->required(),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->default(true),
            Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('item_code')->label('Code')->searchable()->sortable(),
            TextColumn::make('description')->searchable()->sortable()->wrap(),
            TextColumn::make('unit')->sortable(),
            TextColumn::make('contract_quantity')->label('BOQ Qty')->numeric(decimalPlaces: 3)->sortable(),
            TextColumn::make('client_rate')->label('Client Rate')->money('MYR')->sortable(),
            TextColumn::make('is_active')->label('Active')->badge(),
        ])->defaultSort('sort_order')->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreBoqItems::route('/'),
            'create' => CreateFibreBoqItem::route('/create'),
            'edit' => EditFibreBoqItem::route('/{record}/edit'),
        ];
    }
}
