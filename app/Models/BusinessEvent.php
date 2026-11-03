<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BusinessEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'v2_business_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Business audit records are immutable.'));
        static::deleting(fn () => throw new LogicException('Business audit records are immutable.'));
    }
}
