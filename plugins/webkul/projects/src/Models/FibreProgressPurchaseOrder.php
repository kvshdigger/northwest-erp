<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Purchase\Enums\OrderState;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\Models\PurchaseOrder;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\BelongsToCompany;

class FibreProgressPurchaseOrder extends Model
{
    use BelongsToCompany;

    public const PENDING_REVIEW = 'pending_review';
    public const APPROVED = 'approved';
    public const RELEASED = 'released';
    public const REJECTED = 'rejected';

    protected $table = 'projects_fibre_progress_pos';

    protected $fillable = [
        'project_id', 'work_package_id', 'company_id', 'native_order_id', 'po_reference', 'status',
        'created_by', 'reviewed_by', 'released_by', 'reviewed_at', 'released_at',
        'notes', 'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workPackage(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackage::class, 'work_package_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FibreProgressPurchaseOrderLine::class, 'progress_po_id');
    }

    public function nativeOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'native_order_id');
    }

    /**
     * Create a review-ready PO record only from final PM/IM-approved progress.
     * Each final approval can be allocated to one non-rejected PO line at a time.
     */
    public static function generateFromApprovedProgress(int $workPackageId, int $actorId, ?string $notes = null): self
    {
        return DB::transaction(function () use ($workPackageId, $actorId, $notes): self {
            $package = FibreWorkPackage::query()->lockForUpdate()->findOrFail($workPackageId);

            $approved = FibreProgressApproval::query()
                ->join('projects_fibre_daily_progress as progress', 'progress.id', '=', 'projects_fibre_progress_approvals.progress_id')
                ->where('progress.work_package_id', $package->id)
                ->where('progress.project_id', $package->project_id)
                ->where('progress.status', 'submitted')
                ->where('projects_fibre_progress_approvals.event_type', FibreProgressApproval::PM_APPROVED)
                ->where('projects_fibre_progress_approvals.quantity', '>', 0)
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('projects_fibre_progress_po_lines as po_lines')
                        ->join('projects_fibre_progress_pos as existing_pos', 'existing_pos.id', '=', 'po_lines.progress_po_id')
                        ->whereColumn('po_lines.progress_approval_id', 'projects_fibre_progress_approvals.id')
                        ->where('existing_pos.status', '!=', self::REJECTED);
                })
                ->select('projects_fibre_progress_approvals.id')
                ->orderBy('projects_fibre_progress_approvals.id')
                ->lockForUpdate()
                ->get();

            if ($approved->isEmpty()) {
                throw ValidationException::withMessages([
                    'work_package_id' => 'There is no unallocated final-approved progress available to generate a PO.',
                ]);
            }

            $po = self::query()->create([
                'project_id' => $package->project_id,
                'work_package_id' => $package->id,
                'company_id' => $package->company_id,
                'status' => self::PENDING_REVIEW,
                'created_by' => $actorId,
                'notes' => $notes,
            ]);

            foreach ($approved as $approvalRef) {
                $approval = FibreProgressApproval::query()->findOrFail($approvalRef->id);
                $progress = FibreDailyProgress::query()->findOrFail($approval->progress_id);
                $allocation = FibreWorkPackageItem::query()->findOrFail($progress->work_package_item_id);

                if ((int) $allocation->work_package_id !== (int) $package->id) {
                    throw ValidationException::withMessages(['progress' => 'An approved progress entry does not match the selected work package.']);
                }

                $quantity = (float) $approval->quantity;
                $rate = (float) $allocation->subcontractor_rate;

                FibreProgressPurchaseOrderLine::query()->create([
                    'progress_po_id' => $po->id,
                    'progress_approval_id' => $approval->id,
                    'progress_id' => $progress->id,
                    'work_package_item_id' => $allocation->id,
                    'approved_quantity' => $quantity,
                    'subcontractor_rate_snapshot' => $rate,
                    'line_amount' => round($quantity * $rate, 2),
                ]);
            }

            return $po->load('lines');
        });
    }

    /**
     * Create a linked native Purchases-module draft order from an internally approved fibre PO.
     * Native lines are descriptive amount lines so 3-decimal fibre quantities remain exact in
     * the description even though the native purchase line quantity column has 2-decimal precision.
     * This method never sends or confirms the native order.
     */
    public function createNativeDraftOrder(int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($actorId): PurchaseOrder {
            $po = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($po->native_order_id) {
                return PurchaseOrder::query()->findOrFail($po->native_order_id);
            }

            if ($po->status !== self::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Only an internally approved fibre PO can create a native draft purchase order.',
                ]);
            }

            $package = $po->workPackage()->with('subcontractor')->firstOrFail();
            $partnerId = $package->subcontractor_id;
            $company = Company::withTrashed()->find($po->company_id ?: $package->company_id);

            if (! $partnerId || ! $company || ! $company->currency_id) {
                throw ValidationException::withMessages([
                    'native_order' => 'The work package must have a subcontractor and a company with a configured currency before a native purchase order can be created.',
                ]);
            }

            $lines = $po->lines()->with('workPackageItem.boqItem')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['native_order' => 'A native purchase order cannot be created without approved PO lines.']);
            }

            $nativeOrder = PurchaseOrder::query()->create([
                'description' => 'Generated from Northwest ERP fibre progress PO #'.$po->id.'.',
                'origin' => 'Fibre Progress PO #'.$po->id,
                'partner_id' => $partnerId,
                'currency_id' => $company->currency_id,
                'company_id' => $company->id,
                'user_id' => $actorId,
                'ordered_at' => now(),
                'state' => OrderState::DRAFT,
                'untaxed_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'total_cc_amount' => 0,
            ]);

            $untaxedTotal = 0.0;
            $groupedLines = $lines->groupBy(fn ($line) => $line->work_package_item_id.'|'.number_format((float) $line->subcontractor_rate_snapshot, 2, '.', ''));

            foreach ($groupedLines as $group) {
                $first = $group->first();
                $allocation = $first->workPackageItem;
                $boq = $allocation?->boqItem;
                $quantity = (float) $group->sum(fn ($line) => (float) $line->approved_quantity);
                $rate = (float) $first->subcontractor_rate_snapshot;
                $amount = round((float) $group->sum(fn ($line) => (float) $line->line_amount), 2);
                $unit = $boq?->unit ?: 'unit';
                $code = $boq?->item_code ?: 'FIBRE-WORK';
                $description = ($boq?->description ?: 'Fibre installation work')
                    .' ['.$code.'] — Approved quantity: '.number_format($quantity, 3).' '.$unit
                    .' × subcontractor rate RM '.number_format($rate, 2)
                    .' — Source progress PO #'.$po->id;

                OrderLine::query()->create([
                    'order_id' => $nativeOrder->id,
                    'name' => $description,
                    'state' => OrderState::DRAFT->value,
                    'product_qty' => 1,
                    'price_unit' => $amount,
                    'price_subtotal' => $amount,
                    'price_total' => $amount,
                    'price_total_cc' => $amount,
                    'price_tax' => 0,
                    'qty_invoiced' => 0,
                    'qty_received' => 0,
                    'qty_received_manual' => 0,
                    'qty_to_invoice' => 0,
                    'partner_id' => $partnerId,
                    'currency_id' => $company->currency_id,
                    'company_id' => $company->id,
                ]);

                $untaxedTotal += $amount;
            }

            $nativeOrder->forceFill([
                'untaxed_amount' => round($untaxedTotal, 4),
                'tax_amount' => 0,
                'total_amount' => round($untaxedTotal, 4),
                'total_cc_amount' => round($untaxedTotal, 4),
            ])->save();

            $po->native_order_id = $nativeOrder->id;
            $po->save();

            return $nativeOrder->load('lines');
        });
    }

    public function approveForRelease(int $actorId, string $reviewNotes): self
    {
        if (blank($reviewNotes)) {
            throw ValidationException::withMessages(['review_notes' => 'Internal review notes are required before approval.']);
        }

        return DB::transaction(function () use ($actorId, $reviewNotes): self {
            $po = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($po->status !== self::PENDING_REVIEW || ! $po->lines()->exists()) {
                throw ValidationException::withMessages(['status' => 'Only a non-empty PO awaiting review can be approved.']);
            }

            $po->status = self::APPROVED;
            $po->reviewed_by = $actorId;
            $po->reviewed_at = now();
            $po->review_notes = trim($reviewNotes);
            $po->save();

            return $po;
        });
    }

    public function release(int $actorId): self
    {
        return DB::transaction(function () use ($actorId): self {
            $po = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($po->status !== self::APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an internally approved PO can be released.']);
            }

            $po->status = self::RELEASED;
            $po->released_by = $actorId;
            $po->released_at = now();
            $po->po_reference = $po->po_reference ?: 'NW-FPO-'.now()->format('Y').'-'.str_pad((string) $po->id, 6, '0', STR_PAD_LEFT);
            $po->save();

            return $po;
        });
    }

    public function rejectForCorrection(int $actorId, string $reason): self
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['review_notes' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($actorId, $reason): self {
            $po = self::query()->lockForUpdate()->findOrFail($this->getKey());

            if ($po->status !== self::PENDING_REVIEW) {
                throw ValidationException::withMessages(['status' => 'Only a PO awaiting review can be rejected.']);
            }

            $po->status = self::REJECTED;
            $po->reviewed_by = $actorId;
            $po->reviewed_at = now();
            $po->review_notes = trim($reason);
            $po->save();

            return $po;
        });
    }

    protected static function booted(): void
    {
        static::updating(function (self $po): void {
            if ($po->getOriginal('status') === self::RELEASED) {
                throw ValidationException::withMessages(['po' => 'A released PO is immutable. Create a new correction or variation record instead.']);
            }
        });
    }
}