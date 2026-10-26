<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource\Pages\CreateFibreWorkPackage;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource\Pages\EditFibreWorkPackage;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource\Pages\ListFibreWorkPackages;
use Webkul\Project\Filament\Resources\FibreWorkPackageResource\RelationManagers\ItemsRelationManager;
use Webkul\Project\Models\FibreWorkPackage;
use Webkul\Support\Enums\NavigationGroup;

class FibreWorkPackageResource extends Resource
{
    protected static ?string $model = FibreWorkPackage::class;
    protected static ?string $slug = 'project/fibre-work-packages';
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Work Packages';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')->relationship('project', 'name')->searchable()->preload()->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('route_section_id', null)),
            Select::make('route_section_id')
                ->label('Route section')
                ->relationship(
                    'routeSection',
                    'name',
                    modifyQueryUsing: fn (Builder $query, Get $get) => $query->when(
                        $get('project_id'),
                        fn (Builder $query, $projectId) => $query->where('project_id', $projectId),
                    ),
                )
                ->searchable()->preload()->required(),
            Select::make('subcontractor_id')
                ->label('Subcontractor')
                ->relationship('subcontractor', 'name')
                ->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('reference')->maxLength(100),
            TextInput::make('start_chainage_m')->label('Start chainage (m)')->numeric()->minValue(0),
            TextInput::make('end_chainage_m')->label('End chainage (m)')->numeric()->minValue(0)->gt('start_chainage_m'),
            DatePicker::make('planned_start_date')->native(false),
            DatePicker::make('planned_end_date')->native(false)->afterOrEqual('planned_start_date'),
            Select::make('status')->options([
                'draft' => 'Draft',
                'assigned' => 'Assigned',
                'in_progress' => 'In progress',
                'completed' => 'Completed',
                'on_hold' => 'On hold',
            ])->required()->default('draft'),
            Textarea::make('scope_notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->label('Reference')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('routeSection.name')->label('Route section')->searchable(),
            TextColumn::make('subcontractor.name')->label('Subcontractor')->searchable(),
            TextColumn::make('start_chainage_m')->label('Start m')->numeric(decimalPlaces: 3),
            TextColumn::make('end_chainage_m')->label('End m')->numeric(decimalPlaces: 3),
            TextColumn::make('planned_start_date')->date()->sortable(),
            TextColumn::make('planned_end_date')->date()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
        ])->defaultSort('created_at', 'desc')->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreWorkPackages::route('/'),
            'create' => CreateFibreWorkPackage::route('/create'),
            'edit' => EditFibreWorkPackage::route('/{record}/edit'),
        ];
    }
}
