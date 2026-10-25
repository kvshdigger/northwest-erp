<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Traits\BelongsToCompany;

class FibreWorkPackage extends Model
{
    use BelongsToCompany;

    protected $table = 'projects_fibre_work_packages';

    protected $fillable = [
        'project_id', 'route_section_id', 'subcontractor_id', 'company_id', 'name',
        'reference', 'start_chainage_m', 'end_chainage_m', 'planned_start_date',
        'planned_end_date', 'status', 'scope_notes',
    ];

    protected $casts = [
        'start_chainage_m' => 'decimal:3',
        'end_chainage_m' => 'decimal:3',
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::saving(function (self $package): void {
            if ($package->project_id) {
                $package->company_id = $package->project?->company_id;
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

    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'subcontractor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FibreWorkPackageItem::class, 'work_package_id');
    }
}
