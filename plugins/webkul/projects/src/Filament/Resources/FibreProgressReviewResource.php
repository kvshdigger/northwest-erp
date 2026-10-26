<?php

namespace Webkul\\Project\\Filament\\Resources;

use Filament\\Resources\\Resource;
use Filament\\Tables\\Columns\\TextColumn;
use Filament\\Tables\\Table;
use Webkul\\Project\\Filament\\Resources\\FibreProgressReviewResource\\Pages\\ListFibreProgressReviews;
use Webkul\\Project\\Models\\FibreProgressReview;
use Webkul\\Support\\Enums\\NavigationGroup;

class FibreProgressReviewResource extends Resource
{
    protected static ?string $model = FibreProgressReview::class;
    protected static ?string $slug = 'project/fibre-progress-reviews';
    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationLabel(): string
    {
        return 'Fibre PIC Review History';
    }

    public static function getNavigationGroup(): string|\\UnitEnum
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
            TextColumn::make('event_type')->label('Review event')->badge()->sortable(),
            TextColumn::make('quantity')->label('PIC / resolution qty')->numeric(decimalPlaces: 3),
            TextColumn::make('reason')->label('Dispute reason')->wrap()->limit(80),
            TextColumn::make('notes')->label('Resolution / notes')->wrap()->limit(80),
            TextColumn::make('actor.name')->label('Recorded by'),
            TextColumn::make('created_at')->label('Recorded at')->dateTime()->sortable(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFibreProgressReviews::route('/'),
        ];
    }
}
