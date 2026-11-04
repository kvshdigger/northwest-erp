<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Employee\Models\Employee;

class HrRecord extends Model
{
    protected $table = 'v2_hr_records';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
