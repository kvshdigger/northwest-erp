<?php

namespace Webkul\Project\Filament\Resources\FibreClaimResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Project\Models\FibreClaim;
use Webkul\Project\Models\FibreClaimItem;
use Webkul\Project\Models\FibreClaimPayment;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Claim Lines and Payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('work_package_item_id')
                ->label('Allocated BOQ item')
                ->relationship(
                    name: 'workPackageItem',
                    titleAttribute: 'id',
                    modifyQueryUsing: fn (Builder $query) => $query->where('work_package_id', $this->getOwnerRecord()->work_package_id),
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => trim(($record->boqItem?->item_code ? $record->boqItem->item_code.' — ' : '').($record->boqItem?->description ?? 'BOQ item').' ('.$record->boqItem?->unit.')'))
                ->searchable()->preload()->required(),
            TextInput::make('quantity_claimed')->label('Claim quantity')->numeric()->minValue(0.001)->required(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('workPackageItem.boqItem.item_code')->label('BOQ code'),
            TextColumn::make('workPackageItem.boqItem.description')->label('Description')->wrap(),
            TextColumn::make('quantity_claimed')->label('Claim qty')->numeric(decimalPlaces: 3),
            TextColumn::make('subcontractor_rate_snapshot')->label('Rate (RM)')->numeric(decimalPlaces: 2),
            TextColumn::make('amount')->label('Claim amount (RM)')->numeric(decimalPlaces: 2),
            TextColumn::make('payments_sum_quantity_paid')->label('Paid qty')->numeric(decimalPlaces: 3),
            TextColumn::make('payments_sum_amount_paid')->label('Paid amount (RM)')->numeric(decimalPlaces: 2),
        ])->modifyQueryUsing(fn ($query) => $query->withSum('payments', 'quantity_paid')->withSum('payments', 'amount_paid'))
          ->headerActions([
              CreateAction::make()
                  ->visible(fn (): bool => $this->getOwnerRecord()->status === FibreClaim::DRAFT),
          ])
          ->recordActions([
              Action::make('record_payment')
                  ->label('Record payment')
                  ->form([
                      TextInput::make('quantity_paid')->numeric()->minValue(0.001)->required(),
                      TextInput::make('amount_paid')->label('Amount paid (RM)')->numeric()->minValue(0.01)->required(),
                      DatePicker::make('paid_on')->required()->default(now())->native(false),
                      TextInput::make('payment_reference')->maxLength(255),
                      Textarea::make('notes'),
                  ])
                  ->visible(fn (FibreClaimItem $record): bool => in_array($record->claim?->status, [FibreClaim::APPROVED, FibreClaim::PARTIALLY_PAID], true))
                  ->action(function (FibreClaimItem $record, array $data): void {
                      abort_unless(Auth::user()?->can('update', $record->claim), 403);
                      FibreClaimPayment::query()->create([
                          'claim_item_id' => $record->getKey(),
                          'recorded_by' => Auth::id(),
                          'quantity_paid' => $data['quantity_paid'],
                          'amount_paid' => $data['amount_paid'],
                          'paid_on' => $data['paid_on'],
                          'payment_reference' => $data['payment_reference'] ?? null,
                          'notes' => $data['notes'] ?? null,
                      ]);
                  }),
          ]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}