<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Project\Filament\Resources\FibreProductionDashboardResource\Pages\ListFibreProductionDashboard;
use Webkul\Project\Models\FibreWorkPackageItem;
use Webkul\Support\Enums\NavigationGroup;

class FibreProductionDashboardResource extends Resource
{
    protected static ?string $model = FibreWorkPackageItem::class;
    protected static ?string $slug = 'project/fibre-production-dashboard';
    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Production Dashboard';
    }

    public static function getNavigationGroup(): string\|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $reported = DB::table('projects_fibre_daily_progress as progress')
                    ->selectRaw('COALESCE(SUM(progress.reported_quantity), 0)')
                    ->whereColumn('progress.work_package_item_id', 'projects_fibre_work_package_items.id')
                    ->where('progress.status', 'submitted');

                $approved = DB::table('projects_fibre_progress_approvals as approvals')
                    ->join('projects_fibre_daily_progress as progress', 'progress.id', '=', 'approvals.progress_id')
                    ->selectRaw('COALESCE(SUM(approvals.quantity), 0)')
                    ->whereColumn('progress.work_package_item_id', 'projects_fibre_work_package_items.id')
                    ->where('progress.status', 'submitted')
                    ->where('approvals.event_type', 'pm_approved');

                return $query
                    ->addSelect(['reported_quantity_total' => $reported])
                    ->addSelect(['final_approved_quantity_total' => $approved])
                    ->with(['workPackage.project', 'workPackage.routeSection', 'workPackage.subcontractor', 'boqItem']);
            })
            ->columns([
                TextColumn::make('workPackage.project.name')->label('Project')->searchable(),
                TextColumn::make('workPackage.routeSection.name')->label('Route section')->searchable(),
                TextColumn::make('workPackage.name')->label('Work package')->searchable(),
                TextColumn::make('workPackage.subcontractor.name')->label('Subcontractor')->searchable(),
                TextColumn::make('boqItem.item_code')->label('BOQ code')->searchable(),
                TextColumn::make('boqItem.description')->label('Activity / BOQ item')->wrap()->searchable(),
                TextColumn::make('boqItem.unit')->label('Unit'),
                TextColumn::make('allocated_quantity')->label('Allocated qty')->numeric(decimalPlaces: 3),
                TextColumn::make('reported_quantity_total')->label('Reported qty')->numeric(decimalPlaces: 3),
                TextColumn::make('final_approved_quantity_total')->label('Final approved qty')->numeric(decimalPlaces: 3),
                TextColumn::make('completion_percentage')->label('Approved %')->state(fn (FibreWorkPackageItem $record): float => (float) $record->allocated_quantity > 0 ? min(100, (float) $record->final_approved_quantity_total / (float) $record->allocated_quantity * 100) : 0)->numeric(decimalPlaces: 1)->suffix('%'),
                TextColumn::make('workPackage.planned_end_date')->label('Planned finish')->date()->sortable(),
                TextColumn::make('delay_days')->label('Days overdue')->state(function (FibreWorkPackageItem $record): int {
                    $package = $record->workPackage;
                    if (! $package?->planned_end_date || $package->status === 'completed' || $package->planned_end_date->isToday() || $package->planned_end_date->isFuture()) {
                        return 0;
                    }

                    return (int) $package->planned_end_date->diffInDays(now()->startOfDay());
                })->sortable(false),
                TextColumn::make('workPackage.status')->label('Package status')->badge(),
            ])
            ->defaultSort('id', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreProductionDashboard::route('/'),
        ];
    }
}