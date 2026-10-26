<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class FibreProgressPurchaseOrderLine extends Model
{
    protected $table = 'projects_fibre_progress_po_lines';

    protected $fillable = [
        'progress_po_id', 'progress_approval_id', 'progress_id', 'work_package_item_id',
        'approved_quantity', 'subcontractor_rate_snapshot', 'line_amount',
    ];

    protected $casts = [
        'approved_quantity' => 'decimal:3',
        'subcontractor_rate_snapshot' => 'decimal:2',
        'line_amount' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(FibreProgressPurchaseOrder::class, 'progress_po_id');
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(FibreDailyProgress::class, 'progress_id');
    }

    public function workPackageItem(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackageItem::class, 'work_package_item_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $po = FibreProgressPurchaseOrder::query()->findOrFail($line->progress_po_id);
            if ($po->status !== FibreProgressPurchaseOrder::PENDING_REVIEW) {
                throw ValidationException::withMessages(['progress_po_id' => 'PO lines can only be generated while the PO is awaiting internal review.']);
            }

            $approval = FibreProgressApproval::query()->findOrFail($line->progress_approval_id);
            $progress = FibreDailyProgress::query()->findOrFail($line->progress_id);
            $allocation = FibreWorkPackageItem::query()->findOrFail($line->work_package_item_id);

            if (
                $approval->event_type !== FibreProgressApproval::PM_APPROVED
                || (int) $approval->progress_id !== (int) $progress->id
                || (int) $progress->work_package_id !== (int) $po->work_package_id
                || (int) $progress->work_package_item_id !== (int) $allocation->id
                || (int) $allocation->work_package_id !== (int) $po->work_package_id
                || $progress->status !== 'submitted'
                || (float) $approval->quantity <= 0
            ) {
                throw ValidationException::withMessages(['progress' => 'PO lines must match final-approved quantities for the selected work package.']);
            }

            $line->approved_quantity = $approval->quantity;
            $line->subcontractor_rate_snapshot = $allocation->subcontractor_rate;
            $line->line_amount = round((float) $approval->quantity * (float) $allocation->subcontractor_rate, 2);
        });

        static::updating(fn () => throw ValidationException::withMessages([
            'po_line' => 'PO line snapshots are immutable. Use a documented correction or variation.',
        ]));

        static::deleting(fn () => throw ValidationException::withMessages([
            'po_line' => 'PO line snapshots cannot be deleted.',
        ]));
    }
}