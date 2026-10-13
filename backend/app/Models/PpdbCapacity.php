<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpdbCapacity extends Model
{
    protected $table = 'ppdb_capacities';

    // jenjang is the string primary key ('SD' | 'SMP'), not an auto-increment id.
    protected $primaryKey = 'jenjang';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['jenjang', 'max_seats'];

    protected $casts = [
        'max_seats' => 'integer',
    ];
}
