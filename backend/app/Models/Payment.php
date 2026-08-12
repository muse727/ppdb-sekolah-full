<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    // TAMBAHIN BARIS INI JUGA:
    protected $fillable = ['user_id', 'invoice_number', 'jenjang', 'amount', 'payment_url', 'status'];
}