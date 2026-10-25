<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Support\Traits\BelongsToCompany;

class FibreRouteSection extends Model
{
    use BelongsToCompany;

    protected $table = 'projects_fibre_route_sections';

    protected $fillable = [
        'project_id', 'company_id', 'name', 'code', 'start_location', 'end_location',
        'start_chainage_m', 'end_chainage_m', 'status', 'access_notes', 'risk_notes',
    ];

    protected $casts = [
        'start_chainage_m' => 'decimal:3',
        'end_chainage_m' => 'decimal:3',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::saving(function (self $section): void {
            if ($section->project_id) {
                $section->company_id = $section->project?->company_id;
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workPackages(): HasMany
    {
        return $this->hasMany(FibreWorkPackage::class, 'route_section_id');
    }
}
