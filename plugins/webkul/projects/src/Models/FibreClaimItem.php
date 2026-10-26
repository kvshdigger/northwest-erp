<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Support\Traits\BelongsToCompany;

class FibreClaimItem extends Model
{
    use BelongsToCompany;

    protected $table = 'projects_fibre_claim_items';

    protected $fillable = [
        'claim_id', 'company_id', 'work_package_item_id', 'quantity_claimed',
        'subcontractor_rate_snapshot', 'amount',
    ];

    protected $casts = [
        'quantity_claimed' => 'decimal:3',
        'subcontractor_rate_snapshot' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $claim = FibreClaim::query()->findOrFail($line->claim_id);
            if ($claim->status !== FibreClaim::DRAFT) {
                throw ValidationException::withMessages(['claim_id' => 'Claim lines can only be added to a draft claim.']);
            }
            $packageItem = FibreWorkPackageItem::query()->findOrFail($line->work_package_item_id);
            if ((int) $packageItem->work_package_id !== (int) $claim->work_package_id) {
                throw ValidationException::withMessages(['work_package_item_id' => 'This BOQ allocation is not part of the claim work package.']);
            }
            $line->company_id = $claim->company_id;
            $line->subcontractor_rate_snapshot = $packageItem->subcontractor_rate;
            $line->amount = round((float) $line->quantity_claimed * (float) $packageItem->subcontractor_rate, 2);
            $line->assertStillClaimable();
        });

        static::updating(fn () => throw ValidationException::withMessages([
            'claim_item' => 'Claim lines are immutable once created. Create a new draft claim line instead.',
        ]));

        static::deleting(fn () => throw ValidationException::withMessages([
            'claim_item' => 'Claim lines cannot be deleted; reject the claim or retain the audit trail.',
        ]));
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(FibreClaim::class, 'claim_id');
    }

    public function workPackageItem(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackageItem::class, 'work_package_item_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FibreClaimPayment::class, 'claim_item_id');
    }

    public function approvedQuantityForAllocation(): float
    {
        return (float) FibreProgressApproval::query()
            ->join('projects_fibre_daily_progress as progress', 'progress.id', '=', 'projects_fibre_progress_approvals.progress_id')
            ->where('progress.work_package_item_id', $this->work_package_item_id)
            ->where('progress.status', 'submitted')
            ->where('projects_fibre_progress_approvals.event_type', FibreProgressApproval::PM_APPROVED)
            ->sum('projects_fibre_progress_approvals.quantity');
    }

    public function reservedQuantityForAllocation(): float
    {
        return (float) self::query()
            ->join('projects_fibre_claims as claims', 'claims.id', '=', 'projects_fibre_claim_items.claim_id')
            ->where('projects_fibre_claim_items.work_package_item_id', $this->work_package_item_id)
            ->whereIn('claims.status', [
                FibreClaim::SUBMITTED, FibreClaim::REVIEWED, FibreClaim::APPROVED,
                FibreClaim::PARTIALLY_PAID, FibreClaim::PAID,
            ])
            ->sum('projects_fibre_claim_items.quantity_claimed');
    }

    public function assertStillClaimable(): void
    {
        if ((float) $this->quantity_claimed <= 0) {
            throw ValidationException::withMessages(['quantity_claimed' => 'Claim quantity must be greater than zero.']);
        }

        $packageItem = FibreWorkPackageItem::query()->lockForUpdate()->findOrFail($this->work_package_item_id);
        $claim = $this->claim ?? FibreClaim::query()->findOrFail($this->claim_id);
        if ((int) $packageItem->work_package_id !== (int) $claim->work_package_id) {
            throw ValidationException::withMessages(['work_package_item_id' => 'The claim line must belong to the claim work package.']);
        }

        $approved = $this->approvedQuantityForAllocation();
        $reservedElsewhere = (float) self::query()
            ->join('projects_fibre_claims as claims', 'claims.id', '=', 'projects_fibre_claim_items.claim_id')
            ->where('projects_fibre_claim_items.work_package_item_id', $this->work_package_item_id)
            ->where('projects_fibre_claim_items.claim_id', '!=', $claim->id)
            ->whereIn('claims.status', [
                FibreClaim::SUBMITTED, FibreClaim::REVIEWED, FibreClaim::APPROVED,
                FibreClaim::PARTIALLY_PAID, FibreClaim::PAID,
            ])
            ->sum('projects_fibre_claim_items.quantity_claimed');

        if ((float) $this->quantity_claimed > $approved - $reservedElsewhere + 0.0005) {
            throw ValidationException::withMessages([
                'quantity_claimed' => 'Claim quantity exceeds the remaining final-approved quantity. Approved: '.number_format($approved, 3).'; reserved in other claims: '.number_format($reservedElsewhere, 3).'.',
            ]);
        }    }
}