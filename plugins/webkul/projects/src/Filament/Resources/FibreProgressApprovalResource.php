<?php

namespace Webkul\Project\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\Project\Filament\Resources\FibreProgressApprovalResource\Pages\ListFibreProgressApprovals;
use Webkul\Project\Models\FibreProgressApproval;
use Webkul\Support\Enums\NavigationGroup;

class FibreProgressApprovalResource extends Resource
{
    protected static ?string $model = FibreProgressApproval::class;
    protected static ?string $slug = 'project/fibre-progress-approvals';
    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationLabel(): string
    {
        return 'Fibre Inspection & Approval History';
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Project;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('progress.id')->label('Progress ID')->sortable(),
            TextColumn::make('progress.work_date')->label('Work date')->date()->sortable(),
            TextColumn::make('progress.project.name')->label('Project')->searchable(),
            TextColumn::make('progress.workPackage.name')->label('Work package')->searchable(),
            TextColumn::make('event_type')->label('Inspection / approval event')->badge()->sortable(),
            TextColumn::make('quantity')->label('Accepted / approved qty')->numeric(decimalPlaces: 3),
            TextColumn::make('reason')->label('Failure / rejection reason')->wrap()->limit(80),
            TextColumn::make('notes')->label('Notes')->wrap()->limit(80),
            TextColumn::make('actor.name')->label('Recorded by'),
            TextColumn::make('created_at')->label('Recorded at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreProgressApprovals::route('/'),
        ];
    }
}
