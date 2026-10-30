<?php

namespace App\Filament\Resources;

use App\Models\ProjectProgressActivity;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Webkul\Project\Models\Project;
use Webkul\Support\Enums\NavigationGroup;

class ProjectProgressActivityResource extends Resource
{
    protected static ?string $model = ProjectProgressActivity::class;
    protected static ?string $slug = 'project-progress-activities';
    protected static ?string $navigationLabel = 'Project Progress Activities';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroup::Project;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Activity Configuration')->columns(2)->schema([
                Select::make('project_id')
                    ->label('Project')
                    ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->preload()->required(),
                TextInput::make('name')->label('Activity name')->required()->maxLength(255),
                Select::make('unit')->options([
                    'm' => 'Metre (m)',
                    'pcs' => 'Pieces (pcs)',
                    'cores' => 'Cores',
                    'km' => 'Kilometres (km)',
                    'lot' => 'Lot',
                    'set' => 'Set',
                ])->required()->default('m'),
                TextInput::make('sort_order')->numeric()->integer()->default(0)->required(),
                Select::make('is_active')->options([1 => 'Active', 0 => 'Inactive'])->default(1)->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('name')->label('Activity')->searchable()->sortable(),
            TextColumn::make('unit')->badge(),
            TextColumn::make('sort_order')->numeric()->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])->filters([
            SelectFilter::make('project_id')->label('Project')->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all()),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->toolbarActions([
            BulkActionGroup::make([DeleteBulkAction::make()]),
        ])->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\ProjectProgressActivityResource\Pages\ListProjectProgressActivities::route('/'),
            'create' => \App\Filament\Resources\ProjectProgressActivityResource\Pages\CreateProjectProgressActivity::route('/create'),
            'edit' => \App\Filament\Resources\ProjectProgressActivityResource\Pages\EditProjectProgressActivity::route('/{record}/edit'),
        ];
    }
}
