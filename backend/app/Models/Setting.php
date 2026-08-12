<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    // TAMBAHIN BARIS INI BRO:
    protected $fillable = ['key', 'value'];
}