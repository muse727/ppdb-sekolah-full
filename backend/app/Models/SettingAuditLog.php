<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingAuditLog extends Model
{
    protected $fillable = [
        'actor_id', 'action', 'setting_key', 'old_value', 'new_value', 'result',
    ];
}
