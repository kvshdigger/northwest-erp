<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\BelongsToCompany;

class FibreProgressApproval extends Model
{
    use BelongsToCompany;

    public const INSPECTION_PASSED = 'inspection_passed';
    public const INSPECTION_FAILED = 'inspection_failed';
    public const PM_APPROVED = 'pm_approved';
    public const PM_REJECTED = 'pm_rejected';

    protected $table = 'projects_fibre_progress_approvals';

    protected $fillable = [
        'progress_id', 'company_id', 'actor_id', 'event_type', 'quantity', 'reason', 'notes',
    ];

    public $timestamps = false;

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages([
            'approval' => 'Inspection and approval history is append-only. Add a new event instead of editing an existing event.',
        ]));

        static::deleting(fn () => throw ValidationException::withMessages([
            'approval' => 'Inspection and approval history is append-only and cannot be deleted.',
        ]));
    }

    /**
     * Record an inspection result. The caller must authorize the actor as an
     * authorized inspector/PM/IM and verify the required site evidence.
     */
    public static function recordInspection(
        FibreDailyProgress $progress,
        int $actorId,
        bool $passed,
        ?float $acceptedQuantity = null,
        ?string $reason = null,
        ?string $notes = null,
    ): self {
        if (! $passed && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required when inspection fails.']);
        }

        if ($passed && ($acceptedQuantity === null || $acceptedQuantity <= 0)) {
            throw ValidationException::withMessages(['quantity' => 'Enter the inspected and accepted quantity.']);
        }

        return DB::transaction(function () use ($progress, $actorId, $passed, $acceptedQuantity, $reason, $notes): self {
            $locked = FibreDailyProgress::query()->lockForUpdate()->findOrFail($progress->getKey());
            $review = $locked->reviews()->latest('created_at')->latest('id')->first();

            if ($locked->status !== 'submitted' || ! $review) {
                throw ValidationException::withMessages(['progress' => 'Submitted progress with a PIC response or dispute resolution is required before inspection.']);
            }

            if (in_array($review->event_type, [FibreProgressReview::PIC_DISPUTED, FibreProgressReview::PM_REJECTED_REPORT], true)) {
                throw ValidationException::withMessages(['progress' => 'Resolve the PIC dispute and ensure the report is not rejected before inspection.']);
            }

            $measurementQuantity = (float) $review->quantity;
            if ($passed && (float) $acceptedQuantity > $measurementQuantity) {
                throw ValidationException::withMessages(['quantity' => 'Inspected quantity cannot exceed the confirmed or resolved report quantity.']);
            }

            if ($locked->approvals()->whereIn('event_type', [self::PM_APPROVED, self::PM_REJECTED])->exists()) {
                throw ValidationException::withMessages(['progress' => 'A final PM/IM decision is already recorded for this progress entry.']);
            }

            return self::query()->create([
                'progress_id' => $locked->getKey(),
                'company_id' => $locked->company_id,
                'actor_id' => $actorId,
                'event_type' => $passed ? self::INSPECTION_PASSED : self::INSPECTION_FAILED,
                'quantity' => $passed ? $acceptedQuantity : 0,
                'reason' => $passed ? null : trim((string) $reason),
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Final approval is distinct from PIC confirmation and inspection. The caller
     * must authorize the actor as an approver and ensure evidence/inspection checks.
     */
    public static function finalApprove(
        FibreDailyProgress $progress,
        int $actorId,
        float $approvedQuantity,
        string $notes,
    ): self {
        if ($approvedQuantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Approved quantity must be greater than zero.']);
        }

        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Record the final PM/IM approval notes.']);
        }

        return DB::transaction(function () use ($progress, $actorId, $approvedQuantity, $notes): self {
            $locked = FibreDailyProgress::query()->lockForUpdate()->findOrFail($progress->getKey());
            $latestInspection = $locked->approvals()->latest('created_at')->latest('id')->first();
            $latestReview = $locked->reviews()->latest('created_at')->latest('id')->first();

            if (! $latestReview || in_array($latestReview->event_type, [FibreProgressReview::PIC_DISPUTED, FibreProgressReview::PM_REJECTED_REPORT], true)) {
                throw ValidationException::withMessages(['progress' => 'A confirmed or resolved, non-rejected report is required before final approval.']);
            }

            if (! $latestInspection || $latestInspection->event_type !== self::INSPECTION_PASSED) {
                throw ValidationException::withMessages(['progress' => 'A passed inspection is required immediately before final approval.']);
            }

            if ($approvedQuantity > (float) $latestInspection->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Final approved quantity cannot exceed the latest inspected accepted quantity.']);
            }

            if ($locked->approvals()->whereIn('event_type', [self::PM_APPROVED, self::PM_REJECTED])->exists()) {
                throw ValidationException::withMessages(['progress' => 'A final PM/IM decision is already recorded for this progress entry.']);
            }

            return self::query()->create([
                'progress_id' => $locked->getKey(),
                'company_id' => $locked->company_id,
                'actor_id' => $actorId,
                'event_type' => self::PM_APPROVED,
                'quantity' => $approvedQuantity,
                'reason' => null,
                'notes' => trim($notes),
            ]);
        });
    }

    public static function rejectFinalApproval(
        FibreDailyProgress $progress,
        int $actorId,
        string $reason,
    ): self {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for rejecting final approval.']);
        }

        return DB::transaction(function () use ($progress, $actorId, $reason): self {
            $locked = FibreDailyProgress::query()->lockForUpdate()->findOrFail($progress->getKey());

            if ($locked->approvals()->whereIn('event_type', [self::PM_APPROVED, self::PM_REJECTED])->exists()) {
                throw ValidationException::withMessages(['progress' => 'A final PM/IM decision is already recorded for this progress entry.']);
            }

            return self::query()->create([
                'progress_id' => $locked->getKey(),
                'company_id' => $locked->company_id,
                'actor_id' => $actorId,
                'event_type' => self::PM_REJECTED,
                'quantity' => 0,
                'reason' => trim($reason),
                'notes' => null,
            ]);
        });
    }

    public function progress(): BelongsTo
    {
        return $this->belongsTo(FibreDailyProgress::class, 'progress_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
