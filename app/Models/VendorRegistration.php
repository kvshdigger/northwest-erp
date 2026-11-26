<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorRegistration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['data'=>'encrypted:array', 'expires_at'=>'datetime'];
    }
}
