<?php

namespace Webkul\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function workPackage(): BelongsTo
    {
        return $this->belongsTo(FibreWorkPackage::class, 'work_package_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(FibreBoqItem::class, 'boq_item_id');
    }
}
