<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Support\Traits\BelongsToCompany;

class FibreClaimPayment extends Model
{
    use BelongsToCompany;

    protected $table = 'projects_fibre_claim_payments';

    protected $fillable = [
        'claim_item_id', 'company_id', 'recorded_by', 'quantity_paid',
        'amount_paid', 'paid_on', 'payment_reference', 'notes',
    ];

    protected $casts = [
        'quantity_paid' => 'decimal:3',
        'amount_paid' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $line = FibreClaimItem::query()->findOrFail($payment->claim_item_id);
            $claim = FibreClaim::query()->lockForUpdate()->findOrFail($line->claim_id);
            if (! in_array($claim->status, [FibreClaim::APPROVED, FibreClaim::PARTIALLY_PAID], true)) {
                throw ValidationException::withMessages(['claim_item_id' => 'Payments can only be recorded against an approved claim.']);
            }
            if ((float) $payment->quantity_paid <= 0 || (float) $payment->amount_paid <= 0) {
                throw ValidationException::withMessages(['quantity_paid' => 'Paid quantity must be positive and paid amount cannot be negative.']);
            }

            $alreadyPaid = (float) FibreClaimPayment::query()->where('claim_item_id', $line->id)->sum('quantity_paid');
            $alreadyPaidAmount = (float) FibreClaimPayment::query()->where('claim_item_id', $line->id)->sum('amount_paid');
            if ($alreadyPaid + (float) $payment->quantity_paid > (float) $line->quantity_claimed + 0.0005) {
                throw ValidationException::withMessages(['quantity_paid' => 'Paid quantity cannot exceed the claimed quantity.']);
            }

            if ($alreadyPaidAmount + (float) $payment->amount_paid > (float) $line->amount + 0.005) {
                throw ValidationException::withMessages(['amount_paid' => 'Cumulative payment amount cannot exceed the claim line amount.']);
            }

            $payment->company_id = $claim->company_id;
        });

        static::created(function (self $payment): void {
            DB::afterCommit(function () use ($payment): void {
                $claim = $payment->claimItem?->claim;
                if (! $claim) {
                    return;
                }
                $lines = $claim->items()->withSum('payments', 'quantity_paid')->withSum('payments', 'amount_paid')->get();
                $allPaid = $lines->isNotEmpty() && $lines->every(
                    fn ($line) => (float) ($line->payments_sum_quantity_paid ?? 0) + 0.0005 >= (float) $line->quantity_claimed
                        && (float) ($line->payments_sum_amount_paid ?? 0) + 0.005 >= (float) $line->amount
                );
                $somePaid = $lines->contains(fn ($line) => (float) ($line->payments_sum_quantity_paid ?? 0) > 0 || (float) ($line->payments_sum_amount_paid ?? 0) > 0);
                $claim->status = $allPaid ? FibreClaim::PAID : ($somePaid ? FibreClaim::PARTIALLY_PAID : FibreClaim::APPROVED);
                $claim->save();
            });
        });

        static::updating(fn () => throw ValidationException::withMessages([
            'payment' => 'Payment history is append-only. Record an adjustment as a separate payment event.',
        ]));

        static::deleting(fn () => throw ValidationException::withMessages([
            'payment' => 'Payment history cannot be deleted.',
        ]));
    }

    public function claimItem(): BelongsTo
    {
        return $this->belongsTo(FibreClaimItem::class, 'claim_item_id');
    }
}