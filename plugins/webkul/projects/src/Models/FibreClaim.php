<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Support\Traits\BelongsToCompany;

class FibreClaim extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const REVIEWED = 'reviewed';
    public const APPROVED = 'approved';
    public const PARTIALLY_PAID = 'partially_paid';
    public const PAID = 'paid';
    public const REJECTED = 'rejected';

    protected $table = 'projects_fibre_claims';

    protected $fillable = [
        'project_id', 'work_package_id', 'company_id', 'claim_reference', 'status',
        'created_by', 'submitted_by', 'reviewed_by', 'approved_by',
        'submitted_at', 'reviewed_at', 'approved_at', 'notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::creating(function (self $claim): void {
            $package = FibreWorkPackage::query()->findOrFail($claim->work_package_id);
            if ((int) $package->project_id !== (int) $claim->project_id) {
                throw ValidationException::withMessages(['work_package_id' => 'The work package must belong to the selected project.']);
            }
            $claim->company_id = $package->company_id;
            $claim->created_by ??= Auth::id();
        });

        static::updating(function (self $claim): void {
            if ($claim->isDirty(['project_id', 'work_package_id', 'claim_reference']) && $claim->getOriginal('status') !== self::DRAFT) {
                throw ValidationException::withMessages(['claim' => 'A submitted claim identity cannot be changed.']);
            }

            if ($claim->isDirty(['project_id', 'work_package_id']) && $claim->items()->exists()) {
                throw ValidationException::withMessages(['claim' => 'Project and work package cannot be changed after claim lines have been added.']);
            }

            $package = FibreWorkPackage::query()->findOrFail($claim->work_package_id);
            if ((int) $package->project_id !== (int) $claim->project_id) {
                throw ValidationException::withMessages(['work_package_id' => 'The work package must belong to the selected project.']);
            }

            $claim->company_id = $package->company_id;
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workPackage(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackage::class, 'work_package_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FibreClaimItem::class, 'claim_id');
    }

    public function submit(int $actorId): self
    {
        return DB::transaction(function () use ($actorId): self {
            $claim = self::query()->lockForUpdate()->findOrFail($this->getKey());
            if ($claim->status !== self::DRAFT || ! $claim->items()->exists()) {
                throw ValidationException::withMessages(['claim' => 'Only a non-empty draft claim can be submitted.']);
            }
            foreach ($claim->items as $item) {
                $item->assertStillClaimable();
            }
            $claim->status = self::SUBMITTED;
            $claim->submitted_by = $actorId;
            $claim->submitted_at = now();
            $claim->save();

            return $claim;
        });
    }

    public function markReviewed(int $actorId, string $notes): self
    {
        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Review notes are required.']);
        }
        if ($this->status !== self::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'Only submitted claims can be reviewed.']);
        }
        $this->status = self::REVIEWED;
        $this->reviewed_by = $actorId;
        $this->reviewed_at = now();
        $this->notes = trim($notes);
        $this->save();

        return $this;
    }

    public function approve(int $actorId, string $notes): self
    {
        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => 'Approval notes are required.']);
        }

        return DB::transaction(function () use ($actorId, $notes): self {
            $claim = self::query()->lockForUpdate()->findOrFail($this->getKey());
            if (! in_array($claim->status, [self::SUBMITTED, self::REVIEWED], true)) {
                throw ValidationException::withMessages(['status' => 'Only submitted or reviewed claims can be approved.']);
            }
            foreach ($claim->items as $item) {
                $item->assertStillClaimable();
            }
            $claim->status = self::APPROVED;
            $claim->approved_by = $actorId;
            $claim->approved_at = now();
            $claim->notes = trim($notes);
            $claim->save();

            return $claim;
        });
    }

    public function reject(int $actorId, string $reason): self
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }
        if (! in_array($this->status, [self::SUBMITTED, self::REVIEWED], true)) {
            throw ValidationException::withMessages(['status' => 'Only submitted or reviewed claims can be rejected.']);
        }
        $this->status = self::REJECTED;
        $this->reviewed_by = $actorId;
        $this->reviewed_at = now();
        $this->notes = trim($reason);
        $this->save();

        return $this;
    }
}