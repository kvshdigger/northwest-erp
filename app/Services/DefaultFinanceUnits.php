<?php

namespace App\Services;

use Webkul\Support\Enums\UOMType;
use Webkul\Support\Models\UOM;
use Webkul\Support\Models\UOMCategory;

class DefaultFinanceUnits
{
    public function ensure(): void
    {
        (new UOM)->getConnection()->transaction(function (): void {
            foreach (['Units'=>'Unit', 'm'=>'Length / Distance', 'm²'=>'Surface', 'm³'=>'Volume', 'kg'=>'Weight', 'LS'=>'Lump Sum'] as $name => $categoryName) {
                if (UOM::withTrashed()->where('name', $name)->exists()) {
                    continue;
                }
                $category = UOMCategory::firstOrCreate(['name'=>$categoryName]);
                if ($category->uoms()->withTrashed()->where('type', UOMType::REFERENCE->value)->exists()) {
                    $category = UOMCategory::firstOrCreate(['name'=>'Work Order '.$categoryName.' ('.$name.')']);
                }
                UOM::firstOrCreate(['name'=>$name], ['category_id'=>$category->id, 'type'=>UOMType::REFERENCE, 'factor'=>1, 'rounding'=>0.01]);
            }
        });
    }
}
