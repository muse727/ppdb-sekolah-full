<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class ImmutableDaftarUlangRecord extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical Daftar Ulang records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Historical Daftar Ulang records must be preserved.'));
    }
}
