<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class FibreWorkPackageItem extends Model
{
    protected $table = 'projects_fibre_work_package_items';

    protected $fillable = [
        'work_package_id', 'boq_item_id', 'allocated_quantity', 'subcontractor_rate', 'notes',
    ];

    protected $casts = [
        'allocated_quantity' => 'decimal:3',
        'subcontractor_rate' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $package = $item->workPackage;
            $boqItem = $item->boqItem;

            if ($package && $boqItem && $package->project_id !== $boqItem->project_id) {
                throw ValidationException::withMessages([
                    'boq_item_id' => 'The BOQ item must belong to the same project as this work package.',
                ]);
            }
        });
    }

    public function workPackage(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackage::class, 'work_package_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(FibreBoqItem::class, 'boq_item_id');
    }
}
