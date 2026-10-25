<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Support\Traits\BelongsToCompany;

class FibreBoqItem extends Model
{
    use BelongsToCompany;

    protected $table = 'projects_fibre_boq_items';

    protected $fillable = [
        'project_id', 'company_id', 'item_code', 'description', 'unit',
        'contract_quantity', 'client_rate', 'sort_order', 'is_active', 'notes',
    ];

    protected $casts = [
        'contract_quantity' => 'decimal:3',
        'client_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public static function autoAssignsCompany(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->project_id) {
                $item->company_id = $item->project?->company_id;
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workPackageItems(): HasMany
    {
        return $this->hasMany(FibreWorkPackageItem::class, 'boq_item_id');
    }
}
