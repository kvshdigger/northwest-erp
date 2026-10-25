<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Security\Models\User;
use Webkul\Support\Traits\BelongsToCompany;

class FibreDailyProgress extends Model
{
    use BelongsToCompany;

    protected $table = 'projects_fibre_daily_progress';

    protected $fillable = [
        'project_id', 'route_section_id', 'work_package_id', 'work_package_item_id',
        'company_id', 'reported_by', 'work_date', 'start_chainage_m', 'end_chainage_m',
        'reported_quantity', 'status', 'evidence_paths', 'photo_exception_reason',
        'work_notes', 'submitted_at',
    ];

    protected $casts = [
        'work_date' => 'date',
        'start_chainage_m' => 'decimal:3',
        'end_chainage_m' => 'decimal:3',
        'reported_quantity' => 'decimal:3',
        'evidence_paths' => 'array',
        'submitted_at' => 'datetime',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::creating(function (self $progress): void {
            $progress->reported_by ??= Auth::id();
        });

        static::saving(function (self $progress): void {
            if ($progress->exists && $progress->getOriginal('status') === 'submitted' && $progress->isDirty()) {
                throw ValidationException::withMessages([
                    'status' => 'Submitted progress entries are locked. Use the PIC confirmation and correction workflow rather than overwriting the original report.',
                ]);
            }
            $package = $progress->workPackage;
            $packageItem = $progress->workPackageItem;
            $section = $progress->routeSection;

            if ($package) {
                $progress->company_id = $package->company_id;
                if ($progress->project_id !== $package->project_id) {
                    throw ValidationException::withMessages([
                        'work_package_id' => 'The work package must belong to the selected project.',
                    ]);
                }
            }

            if ($section && $progress->project_id !== $section->project_id) {
                throw ValidationException::withMessages([
                    'route_section_id' => 'The route section must belong to the selected project.',
                ]);
            }

            if ($package && $section && $package->route_section_id !== $section->id) {
                throw ValidationException::withMessages([
                    'route_section_id' => 'The route section must match the selected work package.',
                ]);
            }

            if ($packageItem && $packageItem->work_package_id !== $progress->work_package_id) {
                throw ValidationException::withMessages([
                    'work_package_item_id' => 'The BOQ item must be allocated to the selected work package.',
                ]);
            }

            if ($progress->start_chainage_m !== null && $progress->end_chainage_m !== null
                && (float) $progress->end_chainage_m <= (float) $progress->start_chainage_m) {
                throw ValidationException::withMessages([
                    'end_chainage_m' => 'End chainage must be greater than start chainage.',
                ]);
            }

            if ((float) $progress->reported_quantity <= 0) {
                throw ValidationException::withMessages([
                    'reported_quantity' => 'Reported quantity must be greater than zero.',
                ]);
            }

            if ($progress->status === 'submitted' && empty($progress->evidence_paths)) {
                throw ValidationException::withMessages([
                    'evidence_paths' => 'Photo evidence is required before submission.',
                ]);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function routeSection(): BelongsTo
    {
        return $this->belongsTo(FibreRouteSection::class, 'route_section_id');
    }

    public function workPackage(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackage::class, 'work_package_id');
    }

    public function workPackageItem(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackageItem::class, 'work_package_item_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
