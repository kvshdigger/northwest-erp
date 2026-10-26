<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\BelongsToCompany;

class FibreProgressReview extends Model
{
    use BelongsToCompany;

    public const PIC_CONFIRMED = 'pic_confirmed';
    public const PIC_DISPUTED = 'pic_disputed';
    public const PM_ACCEPTED_REPORT = 'pm_accepted_report';
    public const PM_CORRECTED_REPORT = 'pm_corrected_report';
    public const PM_REJECTED_REPORT = 'pm_rejected_report';

    protected $table = 'projects_fibre_progress_reviews';

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
            'review' => 'Review history is append-only. Add a new event instead of editing an existing event.',
        ]));

        static::deleting(fn () => throw ValidationException::withMessages([
            'review' => 'Review history is append-only and cannot be deleted.',
        ]));
    }

    /**
     * Record a single PIC response. The calling endpoint must authorize that the actor
     * is the assigned subcontractor PIC before invoking this method.
     */
    public static function recordPicResponse(
        FibreDailyProgress $progress,
        int $actorId,
        string $response,
        ?float $quantity = null,
        ?string $reason = null,
        ?string $notes = null,
    ): self {
        if (! in_array($response, ['confirmed', 'disputed'], true)) {
            throw ValidationException::withMessages(['response' => 'Choose confirm or dispute.']);
        }

        if ($response === 'disputed' && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for a dispute.']);
        }

        if ($response === 'disputed' && ($quantity === null || $quantity <= 0)) {
            throw ValidationException::withMessages(['quantity' => 'Enter the quantity proposed by the PIC when disputing.']);
        }

        return DB::transaction(function () use ($progress, $actorId, $response, $quantity, $reason, $notes): self {
            $locked = FibreDailyProgress::query()->lockForUpdate()->findOrFail($progress->getKey());

            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages(['progress' => 'Only submitted progress entries can receive a PIC response.']);
            }

            if ($locked->reviews()->whereIn('event_type', [self::PIC_CONFIRMED, self::PIC_DISPUTED])->exists()) {
                throw ValidationException::withMessages(['progress' => 'A PIC response is already recorded for this entry.']);
            }

            return self::query()->create([
                'progress_id' => $locked->getKey(),
                'company_id' => $locked->company_id,
                'actor_id' => $actorId,
                'event_type' => $response === 'confirmed' ? self::PIC_CONFIRMED : self::PIC_DISPUTED,
                'quantity' => $response === 'confirmed' ? (float) $locked->reported_quantity : $quantity,
                'reason' => $response === 'disputed' ? trim((string) $reason) : null,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Resolve a PIC dispute. The calling endpoint must authorize an assigned,
     * authorized PM/IM before invoking this method. Resolution never edits the report.
     */
    public static function resolveDispute(
        FibreDailyProgress $progress,
        int $actorId,
        string $decision,
        ?float $quantity,
        string $notes,
    ): self {
        $eventType = match ($decision) {
            'accept_report' => self::PM_ACCEPTED_REPORT,
            'correct_report' => self::PM_CORRECTED_REPORT,
            'reject_report' => self::PM_REJECTED_REPORT,
            default => null,
        };

        if ($eventType === null) {
            throw ValidationException::withMessages(['decision' => 'Choose accept, correct, or reject.']);
        }

        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Record the reason for resolving the dispute.']);
        }

        if ($decision === 'correct_report' && ($quantity === null || $quantity <= 0)) {
            throw ValidationException::withMessages(['quantity' => 'Enter the corrected quantity.']);
        }

        if ($decision === 'reject_report') {
            $quantity = 0;
        }

        return DB::transaction(function () use ($progress, $actorId, $eventType, $decision, $quantity, $notes): self {
            $locked = FibreDailyProgress::query()->lockForUpdate()->findOrFail($progress->getKey());
            $latest = $locked->reviews()->latest('created_at')->latest('id')->first();

            if (! $latest || $latest->event_type !== self::PIC_DISPUTED) {
                throw ValidationException::withMessages(['progress' => 'Only an unresolved PIC dispute can be resolved here.']);
            }

            return self::query()->create([
                'progress_id' => $locked->getKey(),
                'company_id' => $locked->company_id,
                'actor_id' => $actorId,
                'event_type' => $eventType,
                'quantity' => $decision === 'accept_report' ? (float) $locked->reported_quantity : $quantity,
                'reason' => null,
                'notes' => trim($notes),
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
