<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyProgressReportResource\Pages\CreateDailyProgressReport;
use App\Filament\Resources\DailyProgressReportResource\Pages\EditDailyProgressReport;
use App\Filament\Resources\DailyProgressReportResource\Pages\ListDailyProgressReports;
use App\Models\DailyProgressEntry;
use App\Models\DailyProgressReport;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Support\Enums\NavigationGroup;

class DailyProgressReportResource extends Resource
{
    protected static ?string $model = DailyProgressReport::class;

    protected static ?string $slug = 'daily-progress';

    protected static ?string $navigationLabel = 'Daily Progress & Chainage';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroup::Project;
    }

    public static function getModelLabel(): string
    {
        return 'Daily Progress Report';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Daily Progress & Chainage';
    }

    public static function canViewAny(): bool
    {
        // This is an internal project-tracking resource. Any authenticated ERP user
        // may view daily progress; write actions remain restricted by the panel's auth.
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Report Details')
                ->description('Enter the date, route and work area for this site report.')
                ->icon('heroicon-o-clipboard-document-list')
                ->columns(2)
                ->schema([
                    DatePicker::make('report_date')
                        ->label('Report Date')
                        ->required()
                        ->default(now())
                        ->native(false),
                    TextInput::make('project_route')
                        ->label('Project / Route')
                        ->placeholder('e.g. Skudai–PTP')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('section_name')
                        ->label('Work Location')
                        ->placeholder('e.g. KM 0–KM 1')
                        ->required(),
                    TextInput::make('team_or_subcontractor')
                        ->label('Team / Subcontractor')
                        ->placeholder('Enter team or subcontractor name'),
                    TextInput::make('section_start_chainage')
                        ->label('Section Start CH')
                        ->placeholder('0+000')
                        ->helperText('Use chainage format, e.g. 0+000.')
                        ->live(onBlur: true)
                        ->required(),
                    TextInput::make('section_end_chainage')
                        ->label('Section End CH')
                        ->placeholder('1+000')
                        ->helperText('End chainage for this work section.')
                        ->live(onBlur: true)
                        ->required(),
                ]),
            Section::make('Daily Activity Progress')
                ->description('Add each activity completed today. Enter the start and end chainage for every activity; the length is calculated automatically.')
                ->icon('heroicon-o-chart-bar')
                ->schema([
                    Repeater::make('entries')
                        ->relationship()
                        ->label('Activities completed today')
                        ->addActionLabel('Add another activity')
                        ->itemLabel(fn (array $state): ?string => ($state['activity'] ?? null) ?: 'New activity')
                        ->schema([
                            Select::make('activity')
                                ->label('Activity')
                                ->options(array_combine(array_keys(DailyProgressEntry::ACTIVITIES), array_keys(DailyProgressEntry::ACTIVITIES)))
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set): void {
                                    $set('unit', DailyProgressEntry::ACTIVITIES[$state] ?? 'm');
                                }),
                            TextInput::make('daily_quantity')
                                ->label('Quantity completed today')
                                ->numeric()
                                ->minValue(0.001)
                                ->required()
                                ->suffix(fn (Get $get): string => $get('unit') ?: 'unit'),
                            TextInput::make('start_chainage')
                                ->label('Start CH')
                                ->placeholder('0+000')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    static::updateChainageFields($state, $get('end_chainage'), $set);
                                }),
                            TextInput::make('end_chainage')
                                ->label('End CH')
                                ->placeholder('0+100')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, callable $set, Get $get): void {
                                    static::updateChainageFields($get('start_chainage'), $state, $set);
                                }),
                            TextInput::make('chainage_length_m')
                                ->label('Calculated length (m)')
                                ->numeric()
                                ->readOnly()
                                ->dehydrated(),
                            TextInput::make('unit')
                                ->label('Unit')
                                ->readOnly()
                                ->default('m')
                                ->dehydrated(),
                            TextInput::make('planned_quantity')
                                ->label('Planned / BOQ quantity (optional)')
                                ->numeric()
                                ->minValue(0),
                            Textarea::make('remarks')
                                ->label('Activity remarks (optional)')
                                ->rows(2)
                                ->columnSpanFull(),
                            TextInput::make('start_chainage_m')->numeric()->hidden(),
                            TextInput::make('end_chainage_m')->numeric()->hidden(),
                        ])
                        ->columns(2)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->reorderable(false)
                        ->columnSpanFull(),
                ]),
            Section::make('Remarks')
                ->description('Optional notes for the supervisor or project manager.')
                ->schema([
                    Textarea::make('remarks')
                        ->label('Daily site remarks')
                        ->placeholder('Weather, access issues, manpower, delays or other notes.')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ])->columns(1);
    }

    protected static function updateChainageFields(?string $start, ?string $end, callable $set): void
    {
        try {
            $startM = $start !== null && $start !== '' ? DailyProgressEntry::parseChainage($start) : null;
            $endM = $end !== null && $end !== '' ? DailyProgressEntry::parseChainage($end) : null;
            $set('start_chainage_m', $startM);
            $set('end_chainage_m', $endM);
            $set('chainage_length_m', $startM !== null && $endM !== null ? max(0, $endM - $startM) : null);
        } catch (\InvalidArgumentException) {
            $set('chainage_length_m', null);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('report_date')->date()->sortable(),
                TextColumn::make('project_route')->searchable()->sortable(),
                TextColumn::make('section_name')->searchable(),
                TextColumn::make('team_or_subcontractor')->searchable(),
                TextColumn::make('entries_count')->counts('entries')->label('Activities'),
                TextColumn::make('open_cut_completed')->label('Open Cut')->badge()->formatStateUsing(fn ($state): string => $state === null ? 'Set section CH' : ($state ? 'Completed' : 'In progress'))->color(fn ($state): string => $state === null ? 'gray' : ($state ? 'success' : 'warning')),
                TextColumn::make('fibre_installation_completed')->label('Fibre Install')->badge()->formatStateUsing(fn ($state): string => $state === null ? 'Set section CH' : ($state ? 'Completed' : 'In progress'))->color(fn ($state): string => $state === null ? 'gray' : ($state ? 'success' : 'warning')),
                TextColumn::make('fibre_splicing_completed')->label('Fibre Splicing')->badge()->formatStateUsing(fn ($state): string => $state === null ? 'Set section CH' : ($state ? 'Completed' : 'In progress'))->color(fn ($state): string => $state === null ? 'gray' : ($state ? 'success' : 'warning')),
                IconColumn::make('has_chainage_overlap')
                    ->label('Overlap')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->tooltip(fn (DailyProgressReport $record): string => $record->has_chainage_overlap ? 'Overlapping chainage found. Review before approving cumulative progress.' : 'No overlapping chainage detected.'),
                TextColumn::make('created_at')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('project_route')->options(fn () => DailyProgressReport::query()->distinct()->orderBy('project_route')->pluck('project_route', 'project_route')->all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('report_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyProgressReports::route('/'),
            'create' => CreateDailyProgressReport::route('/create'),
            'edit' => EditDailyProgressReport::route('/{record}/edit'),
        ];
    }
}
