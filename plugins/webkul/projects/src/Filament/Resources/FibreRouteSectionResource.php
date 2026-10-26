<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Project\Filament\Resources\FibreRouteSectionResource\Pages\CreateFibreRouteSection;
use Webkul\Project\Filament\Resources\FibreRouteSectionResource\Pages\EditFibreRouteSection;
use Webkul\Project\Filament\Resources\FibreRouteSectionResource\Pages\ListFibreRouteSections;
use Webkul\Project\Models\FibreRouteSection;
use Webkul\Support\Enums\NavigationGroup;

class FibreRouteSectionResource extends Resource
{
    protected static ?string $model = FibreRouteSection::class;
    protected static ?string $slug = 'project/fibre-route-sections';
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Route Sections';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')->relationship('project', 'name')->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->maxLength(100),
            TextInput::make('start_location')->maxLength(255),
            TextInput::make('end_location')->maxLength(255),
            TextInput::make('start_chainage_m')->label('Start chainage (m)')->numeric()->minValue(0),
            TextInput::make('end_chainage_m')->label('End chainage (m)')->numeric()->minValue(0)->gt('start_chainage_m'),
            Select::make('status')->options([
                'planned' => 'Planned',
                'surveyed' => 'Surveyed',
                'active' => 'Active',
                'completed' => 'Completed',
                'on_hold' => 'On hold',
            ])->required()->default('planned'),
            Textarea::make('access_notes')->label('Access notes')->columnSpanFull(),
            Textarea::make('risk_notes')->label('Risk / site notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('start_location')->label('From')->wrap(),
            TextColumn::make('end_location')->label('To')->wrap(),
            TextColumn::make('start_chainage_m')->label('Start m')->numeric(decimalPlaces: 3),
            TextColumn::make('end_chainage_m')->label('End m')->numeric(decimalPlaces: 3),
            TextColumn::make('status')->badge()->sortable(),
        ])->defaultSort('name')->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreRouteSections::route('/'),
            'create' => CreateFibreRouteSection::route('/create'),
            'edit' => EditFibreRouteSection::route('/{record}/edit'),
        ];
    }
}
