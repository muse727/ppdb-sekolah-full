<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    protected $fillable = ['code', 'is_used', 'user_id'];
}