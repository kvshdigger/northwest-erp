<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinanceConfiguration extends Model
{
    use HasFactory;

    protected $table = 'v2_finance_configurations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array', 'revision' => 'integer'];
    }
}
