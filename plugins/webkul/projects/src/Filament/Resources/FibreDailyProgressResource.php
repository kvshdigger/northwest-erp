<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
use Webkul\Project\Filament\Resources\FibreDailyProgressResource\Pages\CreateFibreDailyProgress;
use Webkul\Project\Filament\Resources\FibreDailyProgressResource\Pages\EditFibreDailyProgress;
use Webkul\Project\Filament\Resources\FibreDailyProgressResource\Pages\ListFibreDailyProgress;
use Webkul\Project\Models\FibreDailyProgress;
use Webkul\Support\Enums\NavigationGroup;

class FibreDailyProgressResource extends Resource
{
    protected static ?string $model = FibreDailyProgress::class;
    protected static ?string $slug = 'project/fibre-daily-progress';
    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Daily Progress';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('project_id')
                ->relationship('project', 'name')
                ->searchable()->preload()->required()->live()
                ->afterStateUpdated(fn (Set $set) => [
                    $set('route_section_id', null),
                    $set('work_package_id', null),
                    $set('work_package_item_id', null),
                ]),
            Select::make('route_section_id')
                ->label('Route section')
                ->relationship(
                    'routeSection',
                    'name',
                    modifyQueryUsing: fn (Builder $query, Get $get) => $query->when(
                        $get('project_id'),
                        fn (Builder $query, $id) => $query->where('project_id', $id),
                    ),
                )
                ->searchable()->preload()->required()->live()
                ->afterStateUpdated(fn (Set $set) => [
                    $set('work_package_id', null),
                    $set('work_package_item_id', null),
                ]),
            Select::make('work_package_id')
                ->label('Subcontractor work package')
                ->relationship(
                    'workPackage',
                    'name',
                    modifyQueryUsing: fn (Builder $query, Get $get) => $query
                        ->when($get('project_id'), fn (Builder $query, $id) => $query->where('project_id', $id))
                        ->when($get('route_section_id'), fn (Builder $query, $id) => $query->where('route_section_id', $id)),
                )
                ->searchable()->preload()->required()->live()
                ->afterStateUpdated(fn (Set $set) => $set('work_package_item_id', null)),
            Select::make('work_package_item_id')
                ->label('Activity / BOQ item')
                ->relationship(
                    'workPackageItem',
                    'id',
                    modifyQueryUsing: fn (Builder $query, Get $get) => $query
                        ->where('work_package_id', $get('work_package_id')),
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => trim(($record->boqItem?->item_code ? $record->boqItem->item_code.' — ' : '').($record->boqItem?->description ?? 'BOQ item').' ('.$record->boqItem?->unit.')'))
                ->searchable()
                ->preload()
                ->required(),
            DatePicker::make('work_date')->required()->default(now())->native(false),
            TextInput::make('start_chainage_m')->label('Start chainage (m)')->numeric()->minValue(0),
            TextInput::make('end_chainage_m')->label('End chainage (m)')->numeric()->minValue(0)->gt('start_chainage_m'),
            TextInput::make('reported_quantity')->label('Reported quantity')->numeric()->minValue(0.001)->required(),
            Select::make('status')->options([
                'draft' => 'Draft',
                'submitted' => 'Submitted for PIC confirmation',
            ])->required()->default('draft')->live(),
            FileUpload::make('evidence_paths')
                ->label('Photo evidence')
                ->disk('local')
                ->directory('fibre-progress-evidence')
                ->visibility('private')
                ->multiple()
                ->image()
                ->imageEditor()
                ->maxFiles(10)
                ->maxSize(10240)
                ->required(fn (Get $get): bool => $get('status') === 'submitted')
                ->helperText('Attach site photos for the measured work. Photos are stored on the private local disk; ensure backups and access controls are configured before production use.')
                ->columnSpanFull(),
            Textarea::make('work_notes')->label('Work notes / site conditions')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('work_date')->date()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('routeSection.name')->label('Route section')->searchable(),
            TextColumn::make('workPackage.name')->label('Work package')->searchable(),
            TextColumn::make('workPackage.subcontractor.name')->label('Subcontractor')->searchable(),
            TextColumn::make('workPackageItem.boqItem.description')->label('Activity / BOQ item')->wrap(),
            TextColumn::make('start_chainage_m')->label('Start m')->numeric(decimalPlaces: 3),
            TextColumn::make('end_chainage_m')->label('End m')->numeric(decimalPlaces: 3),
            TextColumn::make('reported_quantity')->label('Reported qty')->numeric(decimalPlaces: 3),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('reporter.name')->label('Reported by')->toggleable(),
        ])->defaultSort('work_date', 'desc')->recordActions([
            \Filament\Actions\EditAction::make()->visible(fn (FibreDailyProgress $record): bool => $record->status === 'draft'),
            \Filament\Actions\DeleteAction::make()->visible(fn (FibreDailyProgress $record): bool => $record->status === 'draft'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreDailyProgress::route('/'),
            'create' => CreateFibreDailyProgress::route('/create'),
            'edit' => EditFibreDailyProgress::route('/{record}/edit'),
        ];
    }
}
